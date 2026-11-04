#!/usr/bin/env bash
# Verifica el entorno de ESCRITURA ADMINISTRATIVA de QA (UX-08H, backend en modo admin_escritura) y emite GO / NO-GO.
#   sudo bash scripts/verificar.sh
# Consultas HTTP a 127.0.0.1:<puerto registrado por la instalación (o CERVESUR_QAE_PUERTO)>, metadatos de MySQL y comparación con la línea base. ESCRIBE un único cambio sintético (editar el nombre del
# usuario 40 de la base de QA, solo si el interruptor «usuarios» está encendido) para demostrar que cada escritura deja una fila en audit_log; nunca toca nada fuera de este entorno.
# Para las pruebas autenticadas defina (instalar.sh lo hace por usted; no se guardan en ningún sitio):
#   CERVESUR_QAE_ADMIN_PASSWORD (syn001, rol admin, único usuario de la lista blanca)    [obligatoria]
#   CERVESUR_QAE_USUARIO_PASSWORD (syn005) · CERVESUR_QAE_USER_PASSWORD (syn007)         [opcionales: comprueban además que un trabajador con clave correcta NO puede entrar]
# Sin la del administrador las pruebas autenticadas se OMITEN y el resultado es NO-GO (verificación incompleta, nunca un GO falso).
# shellcheck disable=SC2329  # las funciones de prueba se invocan de forma indirecta con «prueba»
set -uo pipefail
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
requerir_root
validar_puerto
BASE="http://127.0.0.1:$PUERTO"
BACK_ENV="$ENV_FILE"
pass=0; fail=0; omitidas=0
GRUPO=''; GRUPOS=(); declare -A GRUPO_FALLOS=()
grupo() { GRUPO=$1; GRUPOS+=("$1"); GRUPO_FALLOS["$1"]=0; printf '\n-- %s\n' "$1"; }
prueba() { local nombre=$1; shift; if "$@" >/dev/null 2>&1; then printf 'PASS  %s\n' "$nombre"; pass=$((pass + 1)); else printf 'FAIL  %s\n' "$nombre"; fail=$((fail + 1)); GRUPO_FALLOS["$GRUPO"]=$((GRUPO_FALLOS["$GRUPO"] + 1)); fi; }
omitir() { printf 'OMIT  %s (%s)\n' "$1" "${2:-faltan credenciales de prueba}"; omitidas=$((omitidas + 1)); GRUPO_FALLOS["$GRUPO"]=$((GRUPO_FALLOS["$GRUPO"] + 1)); }
http() { curl -sS -m 10 -o /dev/null -w '%{http_code}' "$@"; }
cuerpo() { curl -sS -m 10 "$@"; }
cabeceras() { curl -sS -m 10 -o /dev/null -D - "$@" | tr -d '\r'; }
# shellcheck disable=SC2016  # el código PHP va entre comillas simples a propósito
json() { php -r '$d = json_decode(stream_get_contents(STDIN), true); $v = $d; foreach (explode(".", $argv[1]) as $k) { if (!is_array($v) || !array_key_exists($k, $v)) { exit(1); } $v = $v[$k]; } echo is_bool($v) ? ($v ? "true" : "false") : (is_scalar($v) ? $v : json_encode($v));' -- "$1"; }
sql() { mysql_admin -N -B "$DB_NOMBRE" -e "$1"; }
sql_est() { mysql_admin -N -B "$DB_ESTADO" -e "$1"; }
login() { # usuario clave -> token (vacío y error si el inicio de sesión no prospera)
  local r; r=$(printf '{"login":"%s","password":"%s"}' "$1" "$2" | curl -sS -m 10 -H 'Content-Type: application/json' --data @- "$BASE/api/login") || return 1
  json token <<<"$r"
}
codigo_con() { # token método ruta [cuerpo]
  local t=$1 m=$2 r=$3 b=${4:-}
  if [ -n "$b" ]; then curl -sS -m 10 -o /dev/null -w '%{http_code}' -X "$m" -H "Authorization: Bearer $t" -H 'Content-Type: application/json' --data "$b" "$BASE$r"
  else curl -sS -m 10 -o /dev/null -w '%{http_code}' -X "$m" -H "Authorization: Bearer $t" "$BASE$r"; fi
}
env_tiene() { grep -qxF "$1" "$BACK_ENV"; }
env_val() { grep -E "^$1=" "$BACK_ENV" | tail -1 | cut -d= -f2-; }

solo_loopback() {
  local lineas; lineas=$(ss -ltnH "sport = :$PUERTO" | awk '{print $4}')
  [ -n "$lineas" ] && ! grep -qvxF "127.0.0.1:$PUERTO" <<<"$lineas"
}
sin_0000() { local l; l=$(ss -ltnH | awk '{print $4}'); ! grep -Eq "^(0\.0\.0\.0|\*|\[::\]|::):$PUERTO$" <<<"$l"; }
codigo_es() { [ "$(http "${@:2}")" = "$1" ]; }
codigo_en() { local c; c=$(http "${@:2}"); case " $1 " in *" $c "*) return 0 ;; *) return 1 ;; esac; }
indice_html() { grep -q '<div id="root">' <<<"$(cuerpo "$BASE/")"; }
ruta_interna_igual() { [ "$(cuerpo "$BASE/")" = "$(cuerpo "$BASE/admin/usuarios")" ]; }
# /api/ping solo responde si la configuración del modo es válida (si no: 503 «mode_config») y si la petición pasa la exigencia de HTTPS (aquí, por CERVESUR_INSECURE_TESTS=SI de la QA).
ping_modo() { grep -q '"pong":true' <<<"$(cuerpo "$BASE/api/ping")"; }
csp_powerbi() { local csp; csp=$(cabeceras "$BASE/" | grep -i '^content-security-policy:') || return 1; grep -q "frame-src https://app.powerbi.com;" <<<"$csp"; }
sin_hsts() { local h; h=$(cabeceras "$BASE/"); ! grep -qi '^strict-transport-security:' <<<"$h"; }
marco_denegado() { local h; h=$(cabeceras "$BASE/"); grep -qi '^x-frame-options: DENY' <<<"$h"; }
# El modo no usa sesión PHP (token opaco en la base de estado): /api/ping no debe poner ninguna cookie (ni PHPSESSID ni las de otros portales).
sin_cookies() { local h; h=$(cabeceras "$BASE/api/ping"); ! grep -qi '^set-cookie:' <<<"$h"; }

permisos_archivos() {
  [ "$(stat -c '%a %U:%G' "$BACK_ENV")" = '640 root:www-data' ] && [ "$(stat -c '%a %U:%G' "$DIR_SESIONES")" = '700 www-data:www-data' ] && [ "$(stat -c %U "$DIR_FRONT/public")" = root ]
}
# --- Configuración del backend ---
modo_y_base() {
  env_tiene 'CERVESUR_MODE=admin_escritura' && env_tiene 'CERVESUR_REQUIRED_MODE=admin_escritura' && env_tiene 'AUDIT_LOG_MODE=required' && env_tiene 'REACT_STRICT_VIEWS=1' &&
    env_tiene "CERVESUR_PILOT_VISOR_IDS=$VISOR_IDS" && env_tiene 'DB_HOST=127.0.0.1' && env_tiene "DB_NAME=$DB_NOMBRE" && env_tiene "DB_USER=$DB_USUARIO" && env_tiene "AUTH_DB_USER=$DB_USUARIO_AUTH" &&
    env_tiene "STATE_DB_NAME=$DB_ESTADO" && env_tiene "STATE_DB_USER=$DB_USUARIO_ESTADO" && ! env_tiene "DB_NAME=$BASE_PRODUCTIVA" && ! grep -qi 'portalbi' "$BACK_ENV"
}
# QA por túnel SSH (HTTP de loopback): el modo solo admite CERVESUR_INSECURE_TESTS=SI con una base NO protegida. Esta bandera es exclusiva de la QA.
bandera_prueba() { env_tiene 'CERVESUR_INSECURE_TESTS=SI' && [ "$(env_val DB_NAME)" != "$BASE_PRODUCTIVA" ]; }
sin_user_admin_writes() { ! grep -qE '^[[:space:]]*USER_ADMIN_WRITES' "$BACK_ENV" && ! grep -qE '^[[:space:]]*CERVESUR_ALLOW_WRITABLE_DB_ACCOUNT' "$BACK_ENV"; }
escrituras_solo_en_env() { ! grep -rqsE 'USER_ADMIN_WRITES|ADMIN_WRITES_|admin_escritura' "$SITE_CONF" "$DIR_CONF"; }
interruptores_validos() {
  local c v h
  for c in EMPRESAS REPORTES ACCESOS USUARIOS USUARIOS_SENSIBLE; do v=$(env_val "ADMIN_WRITES_$c"); case "$v" in 0|1) ;; *) return 1 ;; esac; done
  if [ "$(env_val ADMIN_WRITES_USUARIOS_SENSIBLE)" = 1 ] && [ "$(env_val ADMIN_WRITES_USUARIOS)" != 1 ]; then return 1; fi
  h=$(env_val ADMIN_USER_REACTIVATE_HOURS); [[ $h =~ ^[0-9]{1,3}$ ]] && [ "$((10#$h))" -le 720 ]
}
secretos_propios() {
  local j p; j=$(env_val JWT_SECRET); p=$(env_val CERVESUR_PILOT_SECRET)
  [ "${#j}" -eq 64 ] && [ "${#p}" -ge 32 ] && [ "$j" != "$p" ]
}
marca_instalacion() {
  local t; t=$(marca_token) && [ -n "$t" ] && directorio_marcado "$DIR_FRONT" "$t" && directorio_marcado "$DIR_BACK" "$t" && directorio_marcado "$DIR_CONF" "$t" &&
    [ "$(sql "SELECT token FROM $TABLA_MARCA")" = "$t" ] && [ "$(sql_est "SELECT token FROM $TABLA_MARCA")" = "$t" ]
}

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
# audit_log con sus DOS triggers de solo inserción (nombres exactos): un trigger ausente es NO-GO.
migraciones_aplicadas() {
  [ "$(sql "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log'")" = 1 ] &&
    [ "$(sql "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'audit_log' AND TRIGGER_NAME IN ('audit_log_no_update','audit_log_no_delete')")" = 2 ]
}
tres_roles() { [ "$(sql "SELECT COUNT(DISTINCT role) FROM user WHERE role IN ('admin','user','usuario')")" = 3 ]; }
datos_sinteticos() {
  if [ "${CERVESUR_QAE_VERIF_INICIAL:-0}" = 1 ]; then
    [ "$(sql 'SELECT COUNT(*) FROM user')" = 76 ] && [ "$(sql 'SELECT COUNT(*) FROM user WHERE is_active = 1')" = 62 ]
  else
    [ "$(sql 'SELECT COUNT(*) FROM user')" -ge 76 ]
  fi
}
informe_sintetico() { [ "$(sql "SELECT nombre FROM reporte WHERE id = $INFORME_SINTETICO_ID AND activo = 1")" = 'DB031-SINTETICO (QA)' ]; }
estado_propio() { # base de estado con sus 4 tablas y la lista blanca = SOLO el administrador id 1
  [ "$(sql_est "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('usuarios_piloto','sesiones','intentos_login','auditoria')")" = 4 ] &&
    [ "$(sql_est 'SELECT GROUP_CONCAT(user_id) FROM usuarios_piloto WHERE activo = 1')" = 1 ]
}

# --- Privilegios (SHOW GRANTS) ---
grants_de() { mysql_admin -N -e "SHOW GRANTS FOR '$1'@'$DB_HOST_USUARIO'"; }
sin_columnas() { sed -E 's/\([^)]*\)//g'; }   # quita las listas de columnas «SELECT (a, b)» para analizar solo los privilegios
solo_un_host() { local u; for u in "${USUARIOS_QA[@]}"; do [ "$(mysql_admin -N -e "SELECT COUNT(*) FROM mysql.user WHERE user = '$u'")" = 1 ] || return 1; done; }
# CUENTA DE ESCRITURA (misma regla que admin_write_grants_violations del backend): solo SELECT/INSERT/UPDATE (+USAGE); sin DELETE, ALL, GRANT OPTION, TRIGGER, DDL ni nada fuera de la base de
# negocio; sobre audit_log solo SELECT e INSERT; nada sobre cervesur_portalbi.
privilegios_escritura() {
  local g linea privs p objeto
  g=$(grants_de "$DB_USUARIO") || return 1
  grep -qiE '\b(DELETE|ALL PRIVILEGES|GRANT OPTION|TRIGGER|DROP|ALTER|CREATE|TRUNCATE|EXECUTE|SUPER|FILE|REFERENCES|INDEX)\b' <<<"$(sin_columnas <<<"$g")" && return 1
  grep -qi 'portalbi' <<<"$g" && return 1
  while IFS= read -r linea; do
    [[ $linea == GRANT\ * ]] || return 1
    privs=$(sin_columnas <<<"$linea"); privs=${privs#GRANT }; privs=${privs%% ON *}
    for p in ${privs//,/ }; do case "$p" in SELECT|INSERT|UPDATE|USAGE) ;; *) return 1 ;; esac; done
    objeto=${linea#* ON }; objeto=${objeto%% TO *}
    if [ "$objeto" = '*.*' ]; then [ "$privs" = USAGE ] || return 1; continue; fi
    [[ $objeto == \`$DB_NOMBRE\`.\`* ]] || return 1          # solo tablas concretas de la base de negocio (nunca «base.*»)
    if [ "$objeto" = "\`$DB_NOMBRE\`.\`audit_log\`" ]; then for p in ${privs//,/ }; do case "$p" in SELECT|INSERT) ;; *) return 1 ;; esac; done; fi
  done <<<"$g"
  grep -q "INSERT ON \`$DB_NOMBRE\`.\`audit_log\`" <<<"$g"
}
# Cuentas de LECTURA: solo SELECT por columnas en la base de negocio (auth: solo la tabla user); la de estado: solo la base de estado.
privilegios_lectura() {
  local g linea u
  for u in "$DB_USUARIO_AUTH" "$DB_USUARIO_CAT"; do
    g=$(grants_de "$u") || return 1
    grep -qiE '\b(INSERT|UPDATE|DELETE|ALL PRIVILEGES|GRANT OPTION)\b' <<<"$g" && return 1
    grep -qi 'portalbi' <<<"$g" && return 1
    while IFS= read -r linea; do
      [[ $linea == "GRANT USAGE ON *.* "* ]] && continue
      [[ $linea == "GRANT SELECT ("*") ON \`$DB_NOMBRE\`."* ]] || return 1
      if [ "$u" = "$DB_USUARIO_AUTH" ]; then [[ $linea == *"ON \`$DB_NOMBRE\`.\`user\` "* ]] || return 1; fi
    done <<<"$g"
  done
}
privilegios_estado() {
  local g linea
  g=$(grants_de "$DB_USUARIO_ESTADO") || return 1
  grep -qiE '\b(ALL PRIVILEGES|GRANT OPTION|DROP|ALTER|CREATE|TRIGGER)\b' <<<"$g" && return 1
  grep -qi 'portalbi' <<<"$g" && return 1
  while IFS= read -r linea; do
    [[ $linea == "GRANT USAGE ON *.* "* ]] && continue
    [[ $linea == "GRANT SELECT, INSERT, UPDATE, DELETE ON \`$DB_ESTADO\`.* TO "* ]] || return 1
  done <<<"$g"
}

# --- La cuenta de ESCRITURA de la aplicación no puede alterar el historial ni borrar; ni siquiera el administrador puede tocar audit_log (triggers) ---
con_cuenta_app() { # ejecuta SQL con la cuenta de escritura de la aplicación sin poner la contraseña en argv
  local f rc u p; f=$(mktemp); chmod 600 "$f"
  u=$(env_val DB_USER); p=$(env_val DB_PASS)
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
# El diseño UX-08H concede SELECT por columnas: la cuenta de escritura no lee el hash de contraseña ni las credenciales de Power BI de empresa.
cuenta_app_sin_secretos() { con_cuenta_app 'SELECT password FROM user LIMIT 1' >/dev/null && return 1; con_cuenta_app 'SELECT powerbi_clientsecret FROM empresa LIMIT 1' >/dev/null && return 1; return 0; }
cuenta_app_solo_su_base() {
  local dbs; dbs=$(con_cuenta_app 'SHOW DATABASES') || return 1
  [ "$(grep -vxE 'information_schema|performance_schema' <<<"$dbs")" = "$DB_NOMBRE" ] && ! con_cuenta_app "SELECT * FROM $BASE_PRODUCTIVA.clientes_senuelo" >/dev/null 2>&1
}
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
anonimo_401() { local r; for r in /api/admin/capacidades /api/auditoria '/api/users?per_page=1' /api/user/profile; do [ "$(http "$BASE$r")" = 401 ] || return 1; done; }
fuera_de_lista_403() { # DELETE de usuarios no existe en la tabla cerrada de rutas: 403 «admin_escritura» siempre, con o sin sesión
  local r; r=$(curl -sS -m 10 -w '\n%{http_code}' -X DELETE -H "Authorization: Bearer $TOKEN_ADMIN" "$BASE/api/users/40")
  [ "$(tail -n1 <<<"$r")" = 403 ] && grep -q '"code":"admin_escritura"' <<<"$r"
}
trabajador_no_entra() { # $1 usuario $2 clave CORRECTA: la lista blanca solo admite a syn001 → el inicio de sesión no prospera
  local r; r=$(printf '{"login":"%s","password":"%s"}' "$1" "$2" | curl -sS -m 10 -H 'Content-Type: application/json' --data @- "$BASE/api/login") || return 1
  [ "$(json success <<<"$r")" = false ] && ! grep -q '"token"' <<<"$r"
}
capacidades_coinciden() { # /api/admin/capacidades refleja EXACTAMENTE los interruptores del .env
  local r c; r=$(cuerpo -H "Authorization: Bearer $TOKEN_ADMIN" "$BASE/api/admin/capacidades") || return 1
  [ "$(json data.mode <<<"$r")" = admin_escritura ] && [ "$(json data.audit <<<"$r")" = true ] || return 1
  for c in empresas reportes accesos usuarios; do
    [ "$(json "data.writes.$c" <<<"$r")" = "$([ "$(env_val "ADMIN_WRITES_${c^^}")" = 1 ] && echo true || echo false)" ] || return 1
  done
  [ "$(json data.users_sensitive <<<"$r")" = "$([ "$(env_val ADMIN_WRITES_USUARIOS_SENSIBLE)" = 1 ] && [ "$(env_val ADMIN_WRITES_USUARIOS)" = 1 ] && echo true || echo false)" ] &&
    [ "$(json data.reactivate_after_hours <<<"$r")" = "$((10#$(env_val ADMIN_USER_REACTIVATE_HOURS)))" ]
}
# Una escritura cuyo interruptor está APAGADO se rechaza ANTES de cualquier controlador: 403 con code «escritura_desactivada» y sin tocar la base.
rechazada_por_interruptor() { # método ruta
  local r; r=$(curl -sS -m 10 -w '\n%{http_code}' -X "$1" -H "Authorization: Bearer $TOKEN_ADMIN" -H 'Content-Type: application/json' --data '{}' "$BASE$2")
  [ "$(tail -n1 <<<"$r")" = 403 ] && grep -q '"code":"escritura_desactivada"' <<<"$r"
}
apagados_rechazados() {
  local c ruta alguno=0 sen=''
  declare -A RUTAS=([EMPRESAS]="POST /api/empresas" [REPORTES]="POST /api/reportes" [ACCESOS]="POST /api/empresa-usuario" [USUARIOS]="PUT /api/users/40")
  for c in EMPRESAS REPORTES ACCESOS USUARIOS; do
    [ "$(env_val "ADMIN_WRITES_$c")" = 1 ] && continue
    ruta=${RUTAS[$c]}; alguno=1
    # shellcheck disable=SC2086  # «método ruta» se separa a propósito
    rechazada_por_interruptor $ruta || { echo "no se rechazó $c ($ruta)" >&2; return 1; }
  done
  if [ "$(env_val ADMIN_WRITES_USUARIOS_SENSIBLE)" != 1 ] || [ "$(env_val ADMIN_WRITES_USUARIOS)" != 1 ]; then
    alguno=1; sen=1
    rechazada_por_interruptor PUT /api/users/40/password && rechazada_por_interruptor PUT /api/user/40/role || return 1
  fi
  echo "interruptores apagados comprobados (alguno=$alguno sensible=${sen:-no})"
}
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

echo "== Verificación del entorno de escritura administrativa de QA ($BASE)"
grupo "Red: solo loopback"
prueba "Apache escucha el puerto $PUERTO SOLO en 127.0.0.1 (ninguna interfaz de red)" solo_loopback
prueba "ningún socket 0.0.0.0/[::] en el puerto $PUERTO" sin_0000
prueba "Apache: configuración válida" apache_configtest

grupo "Frontend y Apache"
prueba "PHP responde por /api/ping (el modo admin_escritura arranca con su configuración válida y la bandera de QA sin HTTPS)" ping_modo
prueba "/ entrega la aplicación React" indice_html
prueba "una ruta interna (/admin/usuarios) entrega index.html al recargar" ruta_interna_igual
prueba "un asset inexistente responde 404 real" codigo_es 404 "$BASE/assets/no-existe.js"
prueba "el endpoint heredado de tokens OAuth (/api/powerbi/...) responde 403" codigo_es 403 "$BASE/api/powerbi/tokens/1"
for ruta in /.env /.htaccess /build-info.json /api.php /controllers/AuthController.php; do prueba "$ruta no es accesible" codigo_en "403 404" "$BASE$ruta"; done
prueba "CSP con frame-src https://app.powerbi.com" csp_powerbi
prueba "X-Frame-Options: DENY" marco_denegado
prueba "sin HSTS (HTTP de loopback)" sin_hsts
prueba "sin cookies de sesión PHP: ni PHPSESSID ni las de otros portales (identidad por token opaco en la base de estado)" sin_cookies
prueba ".env 640 root:www-data, sesiones 700 www-data, carpeta pública propiedad de root" permisos_archivos

grupo "Configuración del backend"
prueba "modo admin_escritura exigido (CERVESUR_MODE y CERVESUR_REQUIRED_MODE), AUDIT_LOG_MODE=required, REACT_STRICT_VIEWS=1, lista del visor, bases y cuentas propias (sin portalbi)" modo_y_base
prueba "CERVESUR_INSECURE_TESTS=SI (bandera exclusiva de la QA, HTTP de loopback) con una base NO protegida" bandera_prueba
prueba "USER_ADMIN_WRITES ausente del .env (y sin CERVESUR_ALLOW_WRITABLE_DB_ACCOUNT)" sin_user_admin_writes
prueba "los interruptores ADMIN_WRITES_* solo viven en el .env (ninguna mención en la configuración de Apache de este sitio)" escrituras_solo_en_env
prueba "interruptores válidos (0/1), lo sensible exige usuarios=1 y ADMIN_USER_REACTIVATE_HOURS entre 0 y 720" interruptores_validos
prueba "JWT_SECRET propio de 64 caracteres, CERVESUR_PILOT_SECRET distinto de 32 o más" secretos_propios
prueba "marca de instalación presente y coherente (carpetas y las dos bases)" marca_instalacion

grupo "Base de datos"
prueba "tipos productivos (enlace VARCHAR(500) NOT NULL, reporte_id VARCHAR(50) NOT NULL) y las 8 claves UNIQUE productivas" esquema_productivo
if estado=$(estado_grupo_id); then echo "INFO  $estado"; fi
prueba "estado de grupo_id coherente con la migración UX-08B3 (NOT NULL por defecto)" estado_grupo_id
prueba "audit_log (UX-08B) con sus 2 triggers de solo inserción audit_log_no_update y audit_log_no_delete (UX-08B.2)" migraciones_aplicadas
prueba "los tres valores de rol (admin, user, usuario) existen en la base" tres_roles
prueba "datos sintéticos: 76 usuarios$([ "${CERVESUR_QAE_VERIF_INICIAL:-0}" = 1 ] && echo ' (62 activos)')" datos_sinteticos
prueba "el informe sintético $INFORME_SINTETICO_ID (DB031 sintético) existe y está activo" informe_sintetico
prueba "base de estado propia $DB_ESTADO con sus 4 tablas y la lista blanca = solo el administrador id 1" estado_propio

grupo "Privilegios de las cuentas MySQL"
prueba "existe una sola cuenta por cada nombre (${USUARIOS_QA[*]}), solo @$DB_HOST_USUARIO" solo_un_host
prueba "SHOW GRANTS de $DB_USUARIO: solo SELECT/INSERT/UPDATE por tabla de $DB_NOMBRE; sin DELETE, ALL PRIVILEGES, GRANT OPTION, TRIGGER ni DDL; audit_log solo SELECT/INSERT; sin portalbi" privilegios_escritura
prueba "cuentas de lectura ($DB_USUARIO_AUTH, $DB_USUARIO_CAT): solo SELECT por columnas en $DB_NOMBRE (auth: solo la tabla user)" privilegios_lectura
prueba "cuenta de estado ($DB_USUARIO_ESTADO): privilegios solo sobre $DB_ESTADO" privilegios_estado
prueba "la cuenta de escritura no puede UPDATE/DELETE/TRUNCATE/DROP sobre audit_log" cuenta_app_no_altera_historial
prueba "la cuenta de escritura no puede DELETE en ninguna tabla de negocio" cuenta_app_sin_delete
prueba "la cuenta de escritura no lee user.password ni empresa.powerbi_clientsecret (SELECT por columnas)" cuenta_app_sin_secretos
prueba "la cuenta de escritura solo ve su base (cervesur_portalbi y las demás, inaccesibles)" cuenta_app_solo_su_base
prueba "ni siquiera el administrador de MySQL puede UPDATE/DELETE en audit_log (triggers de solo inserción)" admin_bloqueado_por_triggers

grupo "API: autenticación, lista blanca y tabla cerrada de rutas"
prueba "anónimo: 401 en /api/admin/capacidades, /api/auditoria, /api/users y /api/user/profile" anonimo_401
TOKEN_ADMIN=''
if [ -n "${CERVESUR_QAE_ADMIN_PASSWORD:-}" ]; then TOKEN_ADMIN=$(login syn001 "$CERVESUR_QAE_ADMIN_PASSWORD") || TOKEN_ADMIN=''; fi
if [ -n "${CERVESUR_QAE_ADMIN_PASSWORD:-}" ]; then
  prueba "inicio de sesión del administrador de la lista blanca (syn001)" test -n "$TOKEN_ADMIN"
  prueba "una ruta fuera de la tabla cerrada (DELETE /api/users/40) responde 403 con code admin_escritura" fuera_de_lista_403
else omitir "administrador: inicio de sesión y rutas fuera de la tabla cerrada"; fi
if [ -n "${CERVESUR_QAE_USUARIO_PASSWORD:-}" ]; then prueba "un trabajador con clave correcta (syn005, rol usuario) NO puede entrar: no está en la lista blanca" trabajador_no_entra syn005 "$CERVESUR_QAE_USUARIO_PASSWORD"; else echo "INFO  trabajador syn005 no probado (CERVESUR_QAE_USUARIO_PASSWORD no definida; la lista blanca de un solo usuario se comprueba en la base)"; fi
if [ -n "${CERVESUR_QAE_USER_PASSWORD:-}" ]; then prueba "un trabajador con clave correcta (syn007, rol heredado user) NO puede entrar" trabajador_no_entra syn007 "$CERVESUR_QAE_USER_PASSWORD"; fi

grupo "API: interruptores de escritura, historial y auditoría"
if [ -n "$TOKEN_ADMIN" ]; then
  prueba "/api/admin/capacidades devuelve los interruptores esperados (modo admin_escritura, auditoría, escrituras, lo sensible y las horas de reactivación)" capacidades_coinciden
  prueba "toda escritura con su interruptor APAGADO se rechaza con 403 code escritura_desactivada (empresas, reportes, accesos, usuarios y lo sensible, según el .env)" apagados_rechazados
  prueba "endpoint de historial /api/auditoria disponible para el administrador" historial_disponible
  if [ "$(env_val ADMIN_WRITES_USUARIOS)" = 1 ]; then
    prueba "una escritura administrativa (editar un usuario) deja una fila user.update en audit_log" escritura_deja_auditoria
  else
    omitir "una escritura auditada (editar un usuario)" "el interruptor «usuarios» está apagado: enciéndalo con scripts/interruptor-escrituras.sh --encender usuarios para poder probar la auditoría"
  fi
  prueba "vistas estrictas: el listado de informes no expone el enlace" vistas_estrictas
  prueba "visor del administrador: 200 en el informe sintético autorizado ($VISOR_IDS) y 403 en otro" visor_lista_blanca
else
  omitir "administrador: capacidades, interruptores apagados, historial, escritura auditada, vistas y visor"
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
echo "*** admin_escritura y las escrituras son SOLO de este entorno de QA: deben seguir APAGADOS/ausentes en los portales publicados 443/8443. ***"
if [ "$todo_go" -eq 1 ] && [ "$fail" -eq 0 ] && [ "$omitidas" -eq 0 ]; then echo "RESULTADO: GO"; exit 0; fi
echo "RESULTADO: NO-GO"
exit 1
