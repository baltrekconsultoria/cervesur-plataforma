#!/usr/bin/env bash
# Verifica el piloto con usuarios reales (UX-07D.4B.1). SOLO LECTURA: consultas HTTPS a localhost:8092 (validadas contra el certificado del propio sitio), comprobaciones de
# archivos/permisos y de las cuentas MySQL, y comparación con la línea base. No necesita ninguna contraseña de usuario: no inicia sesión como nadie, solo prueba lo que
# debe rechazarse. No imprime claves, tokens, enlaces ni cifras de las tablas.
#   sudo bash scripts/verificar.sh [--claves-archivo RUTA]
# Sin --claves-archivo, las claves de las cuentas se leen del .env del sitio.
set -uo pipefail
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
requerir_root
ARCHIVO_CLAVES=""
while [ $# -gt 0 ]; do
  case $1 in
    --claves-archivo) ARCHIVO_CLAVES=${2:-}; shift 2 ;;
    *) abortar "Argumento desconocido: $1" ;;
  esac
done
BASE="https://localhost:$PUERTO"
HTTP_BASE="http://127.0.0.1:$PUERTO"
CURL_TLS=(--cacert "$CERT_FILE" --resolve "localhost:$PUERTO:127.0.0.1")
pass=0; fail=0
prueba() { local nombre=$1; shift; if "$@" >/dev/null 2>&1; then printf 'PASS  %s\n' "$nombre"; pass=$((pass + 1)); else printf 'FAIL  %s\n' "$nombre"; fail=$((fail + 1)); fi; }
http() { curl -sS -m 10 "${CURL_TLS[@]}" -o /dev/null -w '%{http_code}' "$@"; }
cuerpo() { curl -sS -m 10 "${CURL_TLS[@]}" "$@"; }
cabeceras() { curl -sS -m 10 "${CURL_TLS[@]}" -o /dev/null -D - "$@" | tr -d '\r'; }

solo_loopback() { local l; l=$(ss -ltnH "sport = :$1" | awk '{print $4}'); [ -n "$l" ] && ! grep -qvE '^127\.0\.0\.1:'"$1"'$' <<<"$l"; }
ping_https() { grep -q '"pong":true' <<<"$(cuerpo "$BASE/api/ping")"; }
certificado_confiable() { openssl s_client -connect "127.0.0.1:$PUERTO" -servername localhost -CAfile "$CERT_FILE" -verify_hostname localhost -verify_return_error </dev/null 2>&1 | grep -q 'Verification: OK'; }
http_plano_rechazado() { local c r; c=$(curl -sS -m 10 -o /dev/null -w '%{http_code}' "$HTTP_BASE/api/ping" 2>/dev/null); r=$(curl -sS -m 10 "$HTTP_BASE/api/ping" 2>/dev/null); [ "$c" != "200" ] && ! grep -q 'pong' <<<"$r"; }
login_http_rechazado() { local r; r=$(printf '{"login":"nadie","password":"x"}' | curl -sS -m 10 -H 'Content-Type: application/json' --data @- "$HTTP_BASE/api/login" 2>/dev/null); ! grep -qE '"success"|"token"' <<<"$r"; }
x_forwarded_proto_no_vale() { # forjar «ya es HTTPS» por HTTP plano no debe hacer que se acepte ni el ping ni el login
  local r1 r2
  r1=$(curl -sS -m 10 -H 'X-Forwarded-Proto: https' "$HTTP_BASE/api/ping" 2>/dev/null)
  r2=$(printf '{"login":"nadie","password":"x"}' | curl -sS -m 10 -H 'X-Forwarded-Proto: https' -H 'Forwarded: proto=https' -H 'Content-Type: application/json' --data @- "$HTTP_BASE/api/login" 2>/dev/null)
  ! grep -q 'pong' <<<"$r1" && ! grep -qE '"success"|"token"' <<<"$r2"
}
solo_tls_moderno() { tls_solo_moderno "$PUERTO"; }
indice_html() { grep -q '<div id="root">' <<<"$(cuerpo "$BASE/")"; }
compilacion_usuarios() { grep -q '"pilot": true' "$DIR_PUBLIC/build-info.json" && grep -q '"pilotMode": "usuarios"' "$DIR_PUBLIC/build-info.json" && grep -q '"target": "server"' "$DIR_PUBLIC/build-info.json"; }
indice_y_compilacion() { indice_html && compilacion_usuarios; }
csp_powerbi() { local csp; csp=$(cabeceras "$BASE/" | grep -i '^content-security-policy:') || return 1; grep -q "frame-src https://app.powerbi.com;" <<<"$csp"; }
sin_pantallas_admin() { ! cat "$DIR_PUBLIC"/assets/*.js | grep -q 'Nuevo usuario\|Crear usuario\|Nueva empresa\|Nuevo reporte\|Asignar'; }
sin_sesion_401() { local r; for r in /api/user/profile /api/reportes /api/reportes/1 /api/reportes/id/1/visor /api/empresas /api/asignacion; do [ "$(http "$BASE$r")" = "401" ] || return 1; done; [ "$(http -H 'Authorization: Bearer a.b.c' "$BASE/api/reportes")" = "401" ] && [ "$(http -H "Authorization: Bearer $(printf '%064d' 0)" "$BASE/api/reportes")" = "401" ]; }
sin_sesion_sin_datos() { ! grep -q '"data"' <<<"$(cuerpo "$BASE/api/reportes")"; }
login_generico() { # un acceso inexistente recibe la respuesta genérica, sin token y sin revelar si el usuario existe
  local a b
  a=$(printf '{"login":"verificacion-inexistente","password":"incorrecta-xyz"}' | curl -sS -m 15 "${CURL_TLS[@]}" -H 'Content-Type: application/json' --data @- "$BASE/api/login")
  b=$(printf '{"login":"admin","password":"admin"}' | curl -sS -m 15 "${CURL_TLS[@]}" -H 'Content-Type: application/json' --data @- "$BASE/api/login")
  grep -q '"success":false' <<<"$a" && grep -q '"success":false' <<<"$b" && ! grep -q '"token"' <<<"$a$b"
}
rutas_cerradas() {
  local r
  for r in /api/users /api/user /api/stats /api/empresa-usuario /api/empresa-reporte-usuario /api/reportes/activos /api/empresas/1 /api/asignacion/1 /api/powerbi/tokens/1 /api/wiki /api/reportes/ /api/ping/ /api/reportes/id/abc/visor /api/register; do
    [ "$(http "$BASE$r")" = "403" ] || { echo "no 403: $r" >&2; return 1; }
  done
  for r in POST PUT DELETE; do [ "$(http -X $r -H 'Content-Type: application/json' -d '{}' "$BASE/api/reportes")" = "403" ] || return 1; done
}
metodos_rechazados() { local m c; for m in PUT PATCH DELETE OPTIONS TRACE; do c=$(http -X "$m" "$BASE/api/reportes"); [ "$c" = "403" ] || [ "$c" = "405" ] || return 1; done; }
archivos_internos_no_servidos() { local r c; for r in /.env /backend/.env /lib/db.php /api.php /cli/piloto-usuarios.php /tls/servidor.key; do c=$(cuerpo "$BASE$r"); ! grep -qE 'DB_PASS|JWT_SECRET|PILOT_SECRET|<\?php|PRIVATE KEY' <<<"$c" || return 1; done; }
sin_cookie() { ! cabeceras "$BASE/api/ping" | grep -qi '^set-cookie:'; }
cabeceras_api() { local h; h=$(cabeceras "$BASE/api/ping"); grep -qi '^cache-control: no-store' <<<"$h" && grep -qi '^x-content-type-options: nosniff' <<<"$h"; }
sin_hsts_en_tunel() { { ! cabeceras "$BASE/api/ping" | grep -qi '^strict-transport-security:' && ! cabeceras "$BASE/" | grep -qi '^strict-transport-security:'; } || [ "$(valor_env CERVESUR_PILOT_HSTS)" = "SI" ]; }
tls_valido() { bash "$PAQ/scripts/tls.sh" verificar; }
permisos() {
  [ "$(stat -c '%a %U:%G' "$ENV_FILE")" = "640 root:www-data" ] && [ "$(stat -c '%a %U' "$DIR_SESIONES")" = "700 www-data" ] && [ "$(stat -c %U "$DIR_PUBLIC")" = "root" ] \
    && tls_clave_protegida "$KEY_FILE" && [ "$(stat -c '%a' "$DIR_TLS")" = "700" ] \
    && ! find "$DIR_BACKEND" "$DIR_PUBLIC" \( -perm /o+w -o -user www-data \) | grep -q . && [ -z "$(find "$DIR_PUBLIC" "$DIR_BACKEND" -name '.env*' ! -path "$ENV_FILE" -o -name '*.key' -o -name '*.pem')" ]
}
env_correcto() {
  grep -qx 'CERVESUR_MODE=piloto_usuarios' "$ENV_FILE" && grep -qx 'CERVESUR_REQUIRED_MODE=piloto_usuarios' "$ENV_FILE" \
    && ! grep -q 'CERVESUR_INSECURE_TESTS' "$ENV_FILE" && ! grep -q 'CERVESUR_DIAG_TOKEN\|OPERATOR' "$ENV_FILE" \
    && [[ $(valor_env CERVESUR_PILOT_SECRET) =~ ^[0-9a-f]{64}$ ]] && [[ $(valor_env JWT_SECRET) =~ ^[0-9a-f]{64}$ ]] \
    && [ "$(valor_env STATE_DB_NAME)" = "$DB_ESTADO" ] && [ "$(valor_env DB_USER)" = "$DB_USUARIO_CATALOGO" ] && [ "$(valor_env AUTH_DB_USER)" = "$DB_USUARIO_AUTH" ] && [ "$(valor_env STATE_DB_USER)" = "$DB_USUARIO_ESTADO" ]
}
secretos_no_en_logs() {
  local p a c e s; p=$(valor_env CERVESUR_PILOT_SECRET); a=$(valor_env AUTH_DB_PASS); c=$(valor_env DB_PASS); e=$(valor_env STATE_DB_PASS); s=$(valor_env JWT_SECRET)
  # los patrones llegan por un archivo (sustitución de procesos): ningún secreto aparece en la lista de procesos
  [ -n "$p" ] && [ -n "$a" ] && [ -n "$c" ] && [ -n "$e" ] && ! grep -rqsF -f <(printf '%s\n' "$p" "$a" "$c" "$e" "$s") /var/log "$DIR_RESPALDO" "$PAQ" --exclude=mysql.err 2>/dev/null
}
secretos_distintos() { local j p f o; j=$(valor_env JWT_SECRET); p=$(valor_env CERVESUR_PILOT_SECRET); [ "$j" != "$p" ] || return 1
  for f in "$DIR_BACK_PRUEBA/backend/.env" "$DIR_BACK_COMPAT/backend/.env" "$DIR_BACK_DIAG/backend/.env" "$DIR_BACK_VISUAL/backend/.env"; do [ -r "$f" ] || continue; while IFS= read -r o; do [ "${o#*=}" != "$j" ] && [ "${o#*=}" != "$p" ] || return 1; done < "$f"; done; }
acceso_mysql() {
  local a c e
  if [ -n "$ARCHIVO_CLAVES" ]; then a=$(valor_de_archivo "$ARCHIVO_CLAVES" CERVESUR_RO_AUTH); c=$(valor_de_archivo "$ARCHIVO_CLAVES" CERVESUR_RO_CATALOGO); e=$(valor_de_archivo "$ARCHIVO_CLAVES" CERVESUR_REACT_ESTADO)
  else a=$(valor_env AUTH_DB_PASS); c=$(valor_env DB_PASS); e=$(valor_env STATE_DB_PASS); fi
  CERVESUR_RO_AUTH_PASS=$a CERVESUR_RO_CATALOGO_PASS=$c CERVESUR_REACT_ESTADO_PASS=$e DB_HOST=127.0.0.1 DB_NAME=$DB_NOMBRE STATE_DB_NAME=$DB_ESTADO AUTH_DB_USER=$DB_USUARIO_AUTH CATALOGO_DB_USER=$DB_USUARIO_CATALOGO STATE_DB_USER=$DB_USUARIO_ESTADO \
    php "$PAQ/scripts/preflight-acceso.php" "$DIR_BACKEND" >/dev/null
}
lista_blanca_consultable() { php "$DIR_BACKEND/cli/piloto-usuarios.php" listar >/dev/null; }
# La línea base se tomó al INSTALAR este portal. Si después se instaló otro portal (p. ej. el administrativo) o una publicación interna, la huella legítimamente cambió: actualizar.sh y
# publicar-ip.sh ya comparan ANTES y DESPUÉS de cada operación y piden omitir esta comparación (CERVESUR_OMITIR_LINEA_BASE=1). Ejecutada a mano, sigue siendo estricta.
portales_intactos() { huella_igual_a_linea_base; }

echo "== Verificación del piloto con usuarios (https://localhost:$PUERTO, solo por túnel SSH)"
prueba "sitio Apache $SITIO habilitado, backend y aplicación presentes" test -e "$SITE_ENABLED" -a -f "$DIR_BACKEND/api.php" -a -f "$DIR_PUBLIC/index.html"
prueba "$PUERTO escucha SOLO en 127.0.0.1" solo_loopback "$PUERTO"
prueba "TLS: el certificado es confiable para localhost y la cadena se verifica contra el propio certificado del sitio" certificado_confiable
prueba "TLS: certificado vigente, que cubre localhost, con su clave privada correspondiente y protegida" tls_valido
prueba "TLS: solo TLS 1.2 y 1.3 (TLS 1.0/1.1 rechazados)" solo_tls_moderno
prueba "GET /api/ping por HTTPS responde {\"pong\":true}" ping_https
prueba "HTTP SIN cifrar al puerto $PUERTO es rechazado (nunca llega a PHP)" http_plano_rechazado
prueba "iniciar sesión por HTTP sin cifrar es rechazado: ninguna respuesta de la API, ningún token" login_http_rechazado
prueba "forjar X-Forwarded-Proto: https / Forwarded: proto=https por HTTP plano NO hace que se acepte ni el ping ni el inicio de sesión" x_forwarded_proto_no_vale
prueba "la aplicación se sirve (index con #root) y es la compilación del piloto de usuarios (pilot=true, pilotMode=usuarios, target=server)" indice_y_compilacion
prueba "CSP con frame-src https://app.powerbi.com (para el visor)" csp_powerbi
prueba "la aplicación NO incluye las pantallas de administración, alta, edición ni asignación" sin_pantallas_admin
prueba "ningún endpoint del catálogo responde sin sesión (401); tampoco con tokens inventados" sin_sesion_401
prueba "sin sesión no se entrega ningún dato" sin_sesion_sin_datos
prueba "credenciales inexistentes o de administrador → respuesta genérica, sin token" login_generico
prueba "usuarios, estadísticas, asignaciones de usuarios, Power BI heredado, registro, variantes de ruta y escrituras → 403" rutas_cerradas
prueba "PUT/PATCH/DELETE/OPTIONS/TRACE → 403/405" metodos_rechazados
prueba "los archivos internos (.env, db.php, api.php, CLI, clave TLS) no se sirven" archivos_internos_no_servidos
prueba "ninguna respuesta de la API emite cookie; Cache-Control no-store y nosniff" sin_cookie
prueba "cabeceras de la API: no-store y nosniff" cabeceras_api
prueba "sin HSTS en el acceso por túnel (HSTS afectaría a todos los puertos de localhost)" sin_hsts_en_tunel
prueba "permisos: .env 640 root:www-data, carpeta TLS 700 y clave 600 root, sesiones 700 www-data, aplicación y backend sin escritura para www-data ni otros, sin .env/claves en el DocumentRoot" permisos
prueba ".env: modo piloto_usuarios exigido, sin HTTP de pruebas, secretos de 256 bits, cuentas y base de estado correctas" env_correcto
prueba "ninguna clave ni secreto aparece en logs, respaldos ni paquete" secretos_no_en_logs
prueba "los secretos son distintos entre sí y de los de 8088, 8089, 8090 y 8091" secretos_distintos
prueba "las tres cuentas cumplen el preflight de acceso (9 columnas · 21 columnas · DML solo en la base de estado)" acceso_mysql
prueba "la CLI de la lista blanca puede consultar la base de estado" lista_blanca_consultable
if [ "${CERVESUR_OMITIR_LINEA_BASE:-}" = "1" ]; then printf 'OMIT  %s\n' "comparación con la línea base de la INSTALACIÓN omitida: la operación en curso compara la huella de los demás portales antes y después"
else prueba "Flutter, 8088, 8089, 8090, 8091, módulos, sitios, puertos, bases y cuentas MySQL idénticos a la línea base" portales_intactos; fi

echo
echo "$pass PASS / $fail FAIL"
[ "$fail" -eq 0 ]
