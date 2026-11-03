#!/usr/bin/env bash
# Verifica el entorno ADMINISTRATIVO DE QA (UX-08F) y emite GO / NO-GO.
#   sudo bash scripts/verificar.sh
# Consultas HTTP a 127.0.0.1:<puerto registrado por la instalación (o CERVESUR_QA_PUERTO)>, metadatos de MySQL y comparación con la línea base. ESCRIBE un único cambio sintético (editar el nombre del usuario
# 40 de la base de QA) para demostrar que cada escritura deja una fila en audit_log; nunca toca nada fuera de este entorno.
# Para las pruebas autenticadas defina (instalar.sh lo hace por usted; no se guardan en ningún sitio):
#   CERVESUR_QA_ADMIN_PASSWORD (syn001, rol admin) · CERVESUR_QA_USUARIO_PASSWORD (syn005, rol usuario) · CERVESUR_QA_USER_PASSWORD (syn007, rol user)
# Sin esas variables las pruebas autenticadas se OMITEN y el resultado es NO-GO (verificación incompleta).
# shellcheck disable=SC2329  # las funciones de prueba se invocan de forma indirecta con «prueba»
set -uo pipefail
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
requerir_root
validar_puerto
BASE="http://127.0.0.1:$PUERTO"
BACK_ENV="$DIR_BACK/backend/.env"
pass=0; fail=0; omitidas=0
GRUPO=''; GRUPOS=(); declare -A GRUPO_FALLOS=()
grupo() { GRUPO=$1; GRUPOS+=("$1"); GRUPO_FALLOS["$1"]=0; printf '\n-- %s\n' "$1"; }
prueba() { local nombre=$1; shift; if "$@" >/dev/null 2>&1; then printf 'PASS  %s\n' "$nombre"; pass=$((pass + 1)); else printf 'FAIL  %s\n' "$nombre"; fail=$((fail + 1)); GRUPO_FALLOS["$GRUPO"]=$((GRUPO_FALLOS["$GRUPO"] + 1)); fi; }
omitir() { printf 'OMIT  %s (faltan credenciales de prueba)\n' "$1"; omitidas=$((omitidas + 1)); GRUPO_FALLOS["$GRUPO"]=$((GRUPO_FALLOS["$GRUPO"] + 1)); }
http() { curl -sS -m 10 -o /dev/null -w '%{http_code}' "$@"; }
cuerpo() { curl -sS -m 10 "$@"; }
cabeceras() { curl -sS -m 10 -o /dev/null -D - "$@" | tr -d '\r'; }
# shellcheck disable=SC2016  # el código PHP va entre comillas simples a propósito
json() { php -r '$d = json_decode(stream_get_contents(STDIN), true); $v = $d; foreach (explode(".", $argv[1]) as $k) { if (!is_array($v) || !array_key_exists($k, $v)) { exit(1); } $v = $v[$k]; } echo is_bool($v) ? ($v ? "true" : "false") : (is_scalar($v) ? $v : json_encode($v));' -- "$1"; }
sql() { mysql_admin -N -B "$DB_NOMBRE" -e "$1"; }
login() { # usuario clave -> token
  local r; r=$(printf '{"login":"%s","password":"%s"}' "$1" "$2" | curl -sS -m 10 -H 'Content-Type: application/json' --data @- "$BASE/api/login") || return 1
  json token <<<"$r"
}
codigo_con() { # token método ruta [cuerpo]
  local t=$1 m=$2 r=$3 b=${4:-}
  if [ -n "$b" ]; then curl -sS -m 10 -o /dev/null -w '%{http_code}' -X "$m" -H "Authorization: Bearer $t" -H 'Content-Type: application/json' --data "$b" "$BASE$r"
  else curl -sS -m 10 -o /dev/null -w '%{http_code}' -X "$m" -H "Authorization: Bearer $t" "$BASE$r"; fi
}
env_tiene() { grep -qxF "$1" "$BACK_ENV"; }

solo_loopback() {
  local lineas; lineas=$(ss -ltnH "sport = :$PUERTO" | awk '{print $4}')
  [ -n "$lineas" ] && ! grep -qvxF "127.0.0.1:$PUERTO" <<<"$lineas"
}
sin_0000() { local l; l=$(ss -ltnH | awk '{print $4}'); ! grep -Eq "^(0\.0\.0\.0|\*|\[::\]|::):$PUERTO$" <<<"$l"; }
codigo_es() { [ "$(http "${@:2}")" = "$1" ]; }
codigo_en() { local c; c=$(http "${@:2}"); case " $1 " in *" $c "*) return 0 ;; *) return 1 ;; esac; }
indice_html() { grep -q '<div id="root">' <<<"$(cuerpo "$BASE/")"; }
ruta_interna_igual() { [ "$(cuerpo "$BASE/")" = "$(cuerpo "$BASE/admin/usuarios")" ]; }
ping_json() { grep -q '"pong":true' <<<"$(cuerpo "$BASE/api/ping")"; }
csp_powerbi() { local csp; csp=$(cabeceras "$BASE/" | grep -i '^content-security-policy:') || return 1; grep -q "frame-src https://app.powerbi.com;" <<<"$csp"; }
sin_hsts() { local h; h=$(cabeceras "$BASE/"); ! grep -qi '^strict-transport-security:' <<<"$h"; }
marco_denegado() { local h; h=$(cabeceras "$BASE/"); grep -qi '^x-frame-options: DENY' <<<"$h"; }
cookie_propia() {
  local c; c=$(cabeceras "$BASE/api/ping" | grep -i '^set-cookie:') || return 1
  grep -q "^[Ss]et-[Cc]ookie: $COOKIE_SESION=" <<<"$c" && grep -qi 'httponly' <<<"$c" && grep -qi 'samesite=strict' <<<"$c" &&
    ! grep -qi ';[[:space:]]*secure' <<<"$c" && ! grep -q 'PHPSESSID' <<<"$c" && ! grep -qE "[Cc]ookie: (CERVESUR_REACT|CERVESUR_REACT_COMPAT)=" <<<"$c"
}
sesiones_en_directorio_propio() { cuerpo "$BASE/api/ping" >/dev/null; compgen -G "$DIR_SESIONES/sess_*" >/dev/null; }
permisos_archivos() {
  [ "$(stat -c '%a %U:%G' "$BACK_ENV")" = '640 root:www-data' ] && [ "$(stat -c '%a %U:%G' "$DIR_SESIONES")" = '700 www-data:www-data' ] && [ "$(stat -c %U "$DIR_FRONT/public")" = root ]
}
flags_env() {
  env_tiene 'AUDIT_LOG_MODE=required' && env_tiene 'REACT_STRICT_VIEWS=1' && env_tiene 'USER_ADMIN_WRITES=1' && env_tiene "ADMIN_VIEWER_ALLOWED_REPORT_IDS=$VISOR_IDS" &&
    env_tiene "DB_HOST=127.0.0.1" && env_tiene "DB_NAME=$DB_NOMBRE" && env_tiene "DB_USER=$DB_USUARIO" && ! env_tiene "DB_NAME=$BASE_PRODUCTIVA"
}
jwt_propio() { local j; j=$(grep '^JWT_SECRET=' "$BACK_ENV" | cut -d= -f2-); [ "${#j}" -eq 64 ]; }
marca_instalacion() { local t; t=$(marca_token) && [ -n "$t" ] && directorio_marcado "$DIR_FRONT" "$t" && directorio_marcado "$DIR_BACK" "$t" && directorio_marcado "$DIR_CONF" "$t" && [ "$(sql "SELECT token FROM $TABLA_MARCA")" = "$t" ]; }

# --- Base de datos ---
claves_unique() {
  local n; n=$(sql "SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND NON_UNIQUE = 0 AND INDEX_NAME IN ('uq_user_username','uq_user_email','uq_empresa_ruc','uq_reporte_enlace','uq_reporte_reporte_id','uq_eu','uq_er','uq_uer')")
  [ "$n" = 8 ]
}
esquema_productivo() { tipos_productivos && claves_unique; }
tipos_productivos() {
  [ "$(sql "SELECT GROUP_CONCAT(CONCAT(COLUMN_NAME, ':', COLUMN_TYPE, ':', IS_NULLABLE) ORDER BY COLUMN_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reporte' AND COLUMN_NAME IN ('enlace','reporte_id')")" = 'enlace:varchar(500):NO,reporte_id:varchar(50):NO' ]
}
estado_grupo_id() { # informa el estado (NOT NULL por defecto) y comprueba que es uno de los dos esperados
  local e; e=$(sql "SELECT CONCAT(COLUMN_TYPE, '|', IS_NULLABLE) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reporte' AND COLUMN_NAME = 'grupo_id'")
  case "$e" in 'varchar(50)|NO') echo "grupo_id VARCHAR(50) NOT NULL (estado productivo, UX-08B3 NO aplicada)" ;; 'varchar(50)|YES') echo "grupo_id VARCHAR(50) NULL (UX-08B3 aplicada)" ;; *) return 1 ;; esac
}
migraciones_aplicadas() {
  [ "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log'")" = 1 ] &&
    [ "$(sql "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'audit_log' AND TRIGGER_NAME IN ('audit_log_no_update','audit_log_no_delete')")" = 2 ]
}
tres_roles() { [ "$(sql "SELECT COUNT(DISTINCT role) FROM user WHERE role IN ('admin','user','usuario')")" = 3 ]; }
datos_sinteticos() {
  if [ "${CERVESUR_QA_VERIF_INICIAL:-0}" = 1 ]; then
    [ "$(sql 'SELECT COUNT(*) FROM user')" = 76 ] && [ "$(sql 'SELECT COUNT(*) FROM user WHERE is_active = 1')" = 62 ]
  else
    [ "$(sql 'SELECT COUNT(*) FROM user')" -ge 76 ]
  fi
}
informe_sintetico() { [ "$(sql "SELECT nombre FROM reporte WHERE id = $INFORME_SINTETICO_ID AND activo = 1")" = 'DB031-SINTETICO (QA)' ]; }

# --- Privilegios de la cuenta de la aplicación (SHOW GRANTS) ---
grants_cuenta() { mysql_admin -N -e "SHOW GRANTS FOR '$DB_USUARIO'@'$DB_HOST_USUARIO'"; }
privilegios_minimos() {
  local g linea privs p; g=$(grants_cuenta) || return 1
  grep -qiE '\bDELETE\b' <<<"$g" && return 1
  grep -qiE 'ALL PRIVILEGES' <<<"$g" && return 1
  grep -qiE 'GRANT OPTION' <<<"$g" && return 1
  grep -qi "$BASE_PRODUCTIVA" <<<"$g" && return 1
  grep -qi 'portalbi' <<<"$g" && return 1
  while IFS= read -r linea; do
    [[ $linea == GRANT\ * ]] || return 1
    privs=${linea#GRANT }; privs=${privs%% ON *}
    privs=$(sed -E 's/\([^)]*\)//g' <<<"$privs")
    for p in ${privs//,/ }; do case "$p" in SELECT|INSERT|UPDATE|USAGE) ;; *) return 1 ;; esac; done
    # todo lo que no sea USAGE global debe estar acotado a la base de este entorno
    [[ $linea == *' ON *.* '* ]] && { [[ $linea == "GRANT USAGE ON *.* "* ]] || return 1; continue; }
    [[ $linea == *" ON \`$DB_NOMBRE\`."* ]] || return 1
  done <<<"$g"
  # audit_log: SOLO INSERT (más el SELECT de nivel de base)
  while IFS= read -r linea; do [[ $linea == "GRANT INSERT ON \`$DB_NOMBRE\`.\`audit_log\` "* ]] || return 1; done < <(grep "\`audit_log\`" <<<"$g")
  grep -q "GRANT SELECT ON \`$DB_NOMBRE\`\\.\\* TO" <<<"$g"
}
solo_un_host() { [ "$(mysql_admin -N -e "SELECT COUNT(*) FROM mysql.user WHERE user = '$DB_USUARIO'")" = 1 ]; }

# --- La cuenta de la aplicación NO puede alterar el historial; ni siquiera el administrador (triggers) ---
con_cuenta_app() { # ejecuta SQL con la cuenta de la aplicación sin poner la contraseña en argv
  local f rc u p; f=$(mktemp); chmod 600 "$f"
  u=$(grep '^DB_USER=' "$BACK_ENV" | cut -d= -f2-); p=$(grep '^DB_PASS=' "$BACK_ENV" | cut -d= -f2-)
  printf '[client]\nuser=%s\npassword=%s\nhost=127.0.0.1\n' "$u" "$p" > "$f"
  mysql --defaults-extra-file="$f" -N -B "$DB_NOMBRE" -e "$1" 2>&1; rc=$?
  rm -f "$f"; return "$rc"
}
cuenta_app_no_altera_historial() {
  local n antes; antes=$(sql 'SELECT COUNT(*) FROM audit_log')
  for n in "UPDATE audit_log SET action = 'x'" "DELETE FROM audit_log" "TRUNCATE TABLE audit_log" "DROP TABLE audit_log"; do
    con_cuenta_app "$n" >/dev/null && return 1
  done
  [ "$(con_cuenta_app 'SELECT COUNT(*) FROM audit_log')" = "$antes" ]
}
cuenta_app_sin_delete() { local t; for t in user empresa reporte empresa_usuario empresa_reporte user_empresa_reporte; do con_cuenta_app "DELETE FROM $t WHERE id = 0" >/dev/null && return 1; done; return 0; }
admin_bloqueado_por_triggers() {
  local antes r
  # Con la bitácora vacía los triggers (por fila) no se ejercen: se deja una fila de verificación, que es una entrada legítima de la propia auditoría.
  [ "$(sql 'SELECT COUNT(*) FROM audit_log')" != 0 ] || sql "INSERT INTO audit_log (correlation_id, actor_user_id, action, entity, entity_id) VALUES (MD5(UUID()), 0, 'verificacion.instalacion', 'sistema', 0)" || return 1
  antes=$(sql 'SELECT COUNT(*) FROM audit_log')
  r=$(sql 'UPDATE audit_log SET action = action LIMIT 1' 2>&1) && return 1; grep -q 'solo insercion' <<<"$r" || return 1
  r=$(sql 'DELETE FROM audit_log LIMIT 1' 2>&1) && return 1; grep -q 'solo insercion' <<<"$r" || return 1
  [ "$(sql 'SELECT COUNT(*) FROM audit_log')" = "$antes" ]
}

# --- API con credenciales sintéticas ---
anonimo_401() { local r; for r in /api/users/capabilities /api/auditoria '/api/users?per_page=1' /api/user/profile; do [ "$(http "$BASE$r")" = 401 ] || return 1; done; }
trabajador_403() { # $1 = token de un trabajador
  local r; for r in /api/users/capabilities /api/auditoria '/api/users?per_page=1' /api/users/40; do [ "$(codigo_con "$1" GET "$r")" = 403 ] || return 1; done
  [ "$(codigo_con "$1" PUT /api/users/40 '{"first_name":"x","version":"x"}')" = 403 ]
}
capabilities_true() { local r; r=$(cuerpo -H "Authorization: Bearer $TOKEN_ADMIN" "$BASE/api/users/capabilities"); [ "$(json data.user_admin_writes <<<"$r")" = true ]; }
historial_disponible() { local r; r=$(cuerpo -H "Authorization: Bearer $TOKEN_ADMIN" "$BASE/api/auditoria?per_page=1"); [ "$(json success <<<"$r")" = true ]; }
escritura_deja_auditoria() {
  local r ver antes despues cod
  r=$(cuerpo -H "Authorization: Bearer $TOKEN_ADMIN" "$BASE/api/users/40") || return 1
  ver=$(json data.version <<<"$r") || return 1
  antes=$(sql 'SELECT COUNT(*) FROM audit_log')
  cod=$(codigo_con "$TOKEN_ADMIN" PUT /api/users/40 "{\"first_name\":\"QA$(date +%s)\",\"version\":\"$ver\"}")
  despues=$(sql 'SELECT COUNT(*) FROM audit_log')
  [ "$cod" = 200 ] && [ "$despues" = "$((antes + 1))" ] && [ "$(sql "SELECT CONCAT(action, ':', entity_id, ':', actor_user_id) FROM audit_log ORDER BY id DESC LIMIT 1")" = 'user.update:40:1' ]
}
visor_lista_blanca() { # el administrador abre el informe sintético autorizado (DB031 sintético) y NO otro
  local permitido=${VISOR_IDS%%,*} otro=1
  [ "$permitido" != 1 ] || otro=2
  [ "$(codigo_con "$TOKEN_ADMIN" GET "/api/reportes/id/$permitido/visor")" = 200 ] && [ "$(codigo_con "$TOKEN_ADMIN" GET "/api/reportes/id/$otro/visor")" = 403 ]
}
vistas_estrictas() { local r; r=$(cuerpo -H "Authorization: Bearer $TOKEN_ADMIN" "$BASE/api/reportes?view=minimal&per_page=1"); grep -q '"total":82' <<<"$r" && ! grep -q 'enlace' <<<"$r"; }
portales_intactos() {
  [ -f "$DIR_RESPALDO/linea-base-portales.txt" ] && [ "$(huella_servidor)" = "$(cat "$DIR_RESPALDO/linea-base-portales.txt")" ]
}
apache_configtest() { apache2ctl configtest; }

echo "== Verificación del entorno administrativo de QA ($BASE)"
grupo "Red: solo loopback"
prueba "Apache escucha el puerto $PUERTO SOLO en 127.0.0.1 (ninguna interfaz de red)" solo_loopback
prueba "ningún socket 0.0.0.0/[::] en el puerto $PUERTO" sin_0000
prueba "Apache: configuración válida" apache_configtest

grupo "Frontend y Apache"
prueba "PHP responde por /api (JSON) y la base MySQL contesta" ping_json
prueba "/ entrega la aplicación React" indice_html
prueba "una ruta interna (/admin/usuarios) entrega index.html al recargar" ruta_interna_igual
prueba "un asset inexistente responde 404 real" codigo_es 404 "$BASE/assets/no-existe.js"
prueba "el endpoint heredado de tokens OAuth (/api/powerbi/...) responde 403" codigo_es 403 "$BASE/api/powerbi/tokens/1"
for ruta in /.env /.htaccess /build-info.json /api.php /controllers/AuthController.php; do prueba "$ruta no es accesible" codigo_en "403 404" "$BASE$ruta"; done
prueba "CSP con frame-src https://app.powerbi.com" csp_powerbi
prueba "X-Frame-Options: DENY" marco_denegado
prueba "sin HSTS (HTTP de loopback)" sin_hsts
prueba "cookie de sesión propia ($COOKIE_SESION): HttpOnly, SameSite=Strict, sin Secure, sin PHPSESSID ni cookies de otros portales" cookie_propia
prueba "las sesiones se guardan en el directorio exclusivo $DIR_SESIONES" sesiones_en_directorio_propio
prueba ".env 640 root:www-data, sesiones 700 www-data, carpeta pública propiedad de root" permisos_archivos

grupo "Configuración del backend"
prueba "banderas del .env: AUDIT_LOG_MODE=required, REACT_STRICT_VIEWS=1, USER_ADMIN_WRITES=1 (SOLO QA), lista del visor, base y cuenta propias" flags_env
prueba "JWT_SECRET propio de 64 caracteres" jwt_propio
prueba "marca de instalación presente y coherente (carpetas, base)" marca_instalacion

grupo "Base de datos"
prueba "tipos productivos (enlace VARCHAR(500) NOT NULL, reporte_id VARCHAR(50) NOT NULL) y las 8 claves UNIQUE productivas" esquema_productivo
if estado=$(estado_grupo_id); then echo "INFO  $estado"; fi
prueba "estado de grupo_id coherente con la migración UX-08B3 (NOT NULL por defecto)" estado_grupo_id
prueba "migraciones UX-08B (audit_log) y UX-08B.2 (triggers de solo inserción) aplicadas" migraciones_aplicadas
prueba "los tres valores de rol (admin, user, usuario) existen en la base" tres_roles
prueba "datos sintéticos: 76 usuarios$([ "${CERVESUR_QA_VERIF_INICIAL:-0}" = 1 ] && echo ' (62 activos)')" datos_sinteticos
prueba "el informe sintético $INFORME_SINTETICO_ID (DB031 sintético) existe y está activo" informe_sintetico

grupo "Privilegios de la cuenta MySQL"
prueba "existe una sola cuenta $DB_USUARIO (solo @$DB_HOST_USUARIO)" solo_un_host
prueba "SHOW GRANTS: solo SELECT/INSERT/UPDATE sobre $DB_NOMBRE; sin DELETE, sin ALL PRIVILEGES, sin GRANT OPTION y sin mención de $BASE_PRODUCTIVA" privilegios_minimos
prueba "la cuenta de la aplicación no puede UPDATE/DELETE/TRUNCATE/DROP sobre audit_log" cuenta_app_no_altera_historial
prueba "la cuenta de la aplicación no puede DELETE en ninguna tabla de negocio" cuenta_app_sin_delete
prueba "ni siquiera el administrador de MySQL puede UPDATE/DELETE en audit_log (triggers de solo inserción)" admin_bloqueado_por_triggers

grupo "API: autenticación y autorización"
prueba "anónimo: 401 en /api/users/capabilities, /api/auditoria, /api/users y /api/user/profile" anonimo_401
TOKEN_ADMIN=''; TOKEN_USUARIO=''; TOKEN_USER=''
if [ -n "${CERVESUR_QA_ADMIN_PASSWORD:-}" ]; then TOKEN_ADMIN=$(login syn001 "$CERVESUR_QA_ADMIN_PASSWORD") || TOKEN_ADMIN=''; fi
if [ -n "${CERVESUR_QA_USUARIO_PASSWORD:-}" ]; then TOKEN_USUARIO=$(login syn005 "$CERVESUR_QA_USUARIO_PASSWORD") || TOKEN_USUARIO=''; fi
if [ -n "${CERVESUR_QA_USER_PASSWORD:-}" ]; then TOKEN_USER=$(login syn007 "$CERVESUR_QA_USER_PASSWORD") || TOKEN_USER=''; fi
if [ -n "${CERVESUR_QA_USUARIO_PASSWORD:-}" ]; then
  prueba "inicio de sesión de un trabajador con rol «usuario» (syn005)" test -n "$TOKEN_USUARIO"
  prueba "trabajador (rol usuario): 403 en las rutas de administración (capabilities, historial, usuarios, edición)" trabajador_403 "$TOKEN_USUARIO"
else omitir "trabajador con rol «usuario»: 403 en rutas de administración"; fi
if [ -n "${CERVESUR_QA_USER_PASSWORD:-}" ]; then
  prueba "inicio de sesión de un trabajador con rol heredado «user» (syn007)" test -n "$TOKEN_USER"
  prueba "trabajador (rol user): 403 en las rutas de administración" trabajador_403 "$TOKEN_USER"
else omitir "trabajador con rol «user»: 403 en rutas de administración"; fi

grupo "API: capacidades administrativas, historial y auditoría"
if [ -n "${CERVESUR_QA_ADMIN_PASSWORD:-}" ]; then
  prueba "inicio de sesión del administrador (syn001)" test -n "$TOKEN_ADMIN"
  prueba "/api/users/capabilities → user_admin_writes = true" capabilities_true
  prueba "endpoint de historial /api/auditoria disponible para el administrador" historial_disponible
  prueba "una escritura administrativa (editar un usuario) deja una fila user.update en audit_log" escritura_deja_auditoria
  prueba "vistas estrictas: el listado de informes no expone el enlace" vistas_estrictas
  prueba "visor del administrador: 200 en el informe sintético autorizado ($VISOR_IDS) y 403 en otro" visor_lista_blanca
else
  omitir "administrador: capabilities, historial, escritura auditada, vistas y visor"
fi

grupo "Los demás portales y el servidor"
prueba "Flutter (80), React 443/8443/8088/8089, Apache byte a byte, módulos, puertos, bases y cuentas MySQL idénticos a la línea base" portales_intactos

printf '\n%s PASS / %s FAIL / %s omitidas\n' "$pass" "$fail" "$omitidas"
echo
echo "== Lista GO / NO-GO"
todo_go=1
for g in "${GRUPOS[@]}"; do
  if [ "${GRUPO_FALLOS[$g]}" -eq 0 ]; then printf '  [GO]     %s\n' "$g"; else printf '  [NO-GO]  %s\n' "$g"; todo_go=0; fi
done
echo
echo "*** USER_ADMIN_WRITES=1 es SOLO de este entorno de QA: debe seguir APAGADO en los portales publicados 443/8443. ***"
if [ "$todo_go" -eq 1 ] && [ "$fail" -eq 0 ] && [ "$omitidas" -eq 0 ]; then echo "RESULTADO: GO"; exit 0; fi
echo "RESULTADO: NO-GO"
exit 1
