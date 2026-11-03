#!/usr/bin/env bash
# Verifica el diagnóstico de solo lectura (UX-07D.3B). SOLO LECTURA: consultas HTTP a 127.0.0.1:8090, comprobaciones de archivos/permisos y de la cuenta MySQL, y
# comparación con la línea base. No imprime el token ni cifras de las tablas.
#   sudo bash scripts/verificar.sh
set -uo pipefail
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
requerir_root
BASE="http://127.0.0.1:$PUERTO"
pass=0; fail=0
prueba() { local nombre=$1; shift; if "$@" >/dev/null 2>&1; then printf 'PASS  %s\n' "$nombre"; pass=$((pass + 1)); else printf 'FAIL  %s\n' "$nombre"; fail=$((fail + 1)); fi; }
http() { curl -sS -m 10 -o /dev/null -w '%{http_code}' "$@"; }
cuerpo() { curl -sS -m 10 "$@"; }
cabeceras() { curl -sS -m 10 -o /dev/null -D - "$@" | tr -d '\r'; }
codigo_es() { [ "$(http "${@:2}")" = "$1" ]; }
codigo_con_token_es() { local c; c=$(curl_con_token -sS -m 10 -o /dev/null -w '%{http_code}' "${@:2}"); [ "$c" = "$1" ]; }
cuerpo_token() { curl_con_token -sS -m 10 "$@"; }

solo_loopback() {
  local lineas; lineas=$(ss -ltnH "sport = :$1" | awk '{print $4}')
  [ -n "$lineas" ] && ! grep -qvE '^127\.0\.0\.1:'"$1"'$' <<<"$lineas"
}
ping_json() { local c; c=$(cuerpo "$BASE/api/ping"); grep -q '"pong":true' <<<"$c"; }
resumen_forma() { local c; c=$(cuerpo_token "$BASE/api/diagnostico/resumen"); grep -q '"success":true' <<<"$c" && grep -q '"medidas"' <<<"$c" && grep -q '"modo":"diagnostico"' <<<"$c"; }
controles_ok() { local c; c=$(cuerpo_token "$BASE/api/diagnostico/resumen"); grep -q '"sesion_de_base_de_datos_en_solo_lectura":true' <<<"$c" && grep -q '"cuenta_de_base_de_datos_sin_privilegios_de_escritura":true' <<<"$c"; }
conciliacion_forma() { local c; c=$(cuerpo_token "$BASE/api/diagnostico/conciliacion"); grep -q '"success":true' <<<"$c" && grep -q '"detalle"' <<<"$c" && grep -q '"controles"' <<<"$c"; }
sin_datos_sensibles() { # las respuestas autorizadas no contienen hashes, enlaces, correos, URLs ni credenciales
  local c; c=$(cuerpo_token "$BASE/api/diagnostico/resumen"; cuerpo_token "$BASE/api/diagnostico/conciliacion")
  ! grep -qiE '\$2y\$|password|powerbi|https?:|enlace|@|token|secret|username|email' <<<"$c"
}
sin_cookie() { ! cabeceras "$BASE/api/ping" | grep -qi '^set-cookie:' && ! cabeceras -H "Accept: */*" "$BASE/api/diagnostico/resumen" | grep -qi '^set-cookie:'; }
cabeceras_seguras() { local h; h=$(cabeceras "$BASE/api/ping"); grep -qi '^cache-control: no-store' <<<"$h" && grep -qi '^x-content-type-options: nosniff' <<<"$h" && grep -qi '^x-frame-options: DENY' <<<"$h" && ! grep -qi '^strict-transport-security' <<<"$h"; }
metodos_rechazados() { local m c; for m in POST PUT PATCH DELETE OPTIONS TRACE; do c=$(http -X "$m" "$BASE/api/diagnostico/resumen"); [ "$c" = "403" ] || [ "$c" = "405" ] || return 1; done; }
head_rechazado() { [ "$(http -I "$BASE/api/ping")" = "403" ]; }
rutas_cerradas() { # lo demás: 403 (incluidas variantes de la ruta y archivos internos)
  local r
  for r in /api/login /api/register /api/logout /api/users /api/user/profile /api/empresas /api/reportes /api/reportes/id/1/visor /api/stats /api/asignacion /api/empresa-usuario \
    /api/empresa-reporte-usuario /api/wiki /api/powerbi/tokens/1 / /index.html /.env /backend/.env /.git/config /lib/db.php /api.php /router/index.php /uploads/ /api/ping/ /api/diagnostico/resumen/ \
    /api/diagnostico/otro //api/ping /API/PING; do
    [ "$(http --path-as-is "$BASE$r")" = "403" ] || { echo "no 403: $r" >&2; return 1; }
  done
  # saltos de directorio: Apache puede rechazarlos él mismo (400/404) o entregarlos a PHP (403); NUNCA 200 ni un archivo
  for r in '/api/diagnostico/resumen/../../etc/passwd' /../../etc/passwd /%2e%2e/%2e%2e/etc/passwd /api/%2e%2e/%2e%2e/.env; do
    case $(http --path-as-is "$BASE$r") in 400|403|404) ;; *) echo "traversal no rechazado: $r" >&2; return 1 ;; esac
  done
  [ "$(http -X POST -d '{"login":"syn001","password":"x"}' "$BASE/api/login")" = "403" ]
}
privados_sin_token() { # sin token o con uno incorrecto: 401 y ningún dato
  local ruta c
  for ruta in /api/diagnostico/resumen /api/diagnostico/conciliacion; do
    [ "$(http "$BASE$ruta")" = "401" ] || return 1
    [ "$(http -H 'X-Diagnostic-Token: incorrecto' "$BASE$ruta")" = "401" ] || return 1
    [ "$(http -H "X-Diagnostic-Token: $(printf '0%.0s' $(seq 1 64))" "$BASE$ruta")" = "401" ] || return 1
    [ "$(http -H 'Authorization: Bearer abc.def.ghi' "$BASE$ruta")" = "401" ] || return 1
    c=$(cuerpo "$BASE$ruta"); ! grep -q 'medidas' <<<"$c" || return 1
  done
}
env_permisos() { [ "$(stat -c '%a %U:%G' "$ENV_FILE")" = "640 root:www-data" ]; }
env_fuera_de_docroot() { case "$ENV_FILE" in "$DIR_DOCROOT"/*) return 1 ;; esac; [ -z "$(ls -A "$DIR_DOCROOT")" ]; }
backend_no_escribible() { ! find "$DIR_BACKEND" -type f -perm /o+w -o -type f -user www-data | grep -q .; }
sesiones_aisladas() { [ "$(stat -c '%a %U' "$DIR_SESIONES")" = "700 www-data" ] && ! compgen -G "$DIR_SESIONES/sess_*" >/dev/null; }
modo_en_env() { grep -qx 'CERVESUR_MODE=diagnostico' "$ENV_FILE" && grep -qx 'CERVESUR_REQUIRED_MODE=diagnostico' "$ENV_FILE" && ! grep -q '^JWT_SECRET=' "$ENV_FILE"; }
token_no_en_logs() { # ni el token ni la clave de la cuenta aparecen en los logs, respaldos ni en el paquete
  local t p; t=$(valor_env CERVESUR_DIAG_TOKEN); p=$(valor_env DB_PASS)
  [ -n "$t" ] && [ -n "$p" ] || return 1
  ! grep -rqsF -e "$t" -e "$p" /var/log "$DIR_RESPALDO" "$PAQ" --exclude=mysql.err 2>/dev/null
}
token_distinto_de_otros() { # el token no coincide con ningún secreto de los otros portales
  local t o f; t=$(valor_env CERVESUR_DIAG_TOKEN)
  for f in "$DIR_BACK_PRUEBA/backend/.env" "$DIR_BACK_COMPAT/backend/.env"; do
    [ -r "$f" ] || continue
    while IFS= read -r o; do [ "${o#*=}" != "$t" ] || return 1; done < "$f"
  done
}
acceso_mysql() { # la cuenta cumple (mismo preflight B), con la clave que ya está en el .env; no se muestra
  local p; p=$(valor_env DB_PASS)
  CERVESUR_RO_DIAG_PASS=$p DB_HOST=127.0.0.1 DB_NAME=$DB_NOMBRE DB_USER=$DB_USUARIO php "$PAQ/scripts/preflight-acceso.php" "$DIR_BACKEND" >/dev/null
}
portales_intactos() {
  [ -f "$DIR_RESPALDO/linea-base-portales.txt" ] || return 1
  [ "$(huella_portales)" = "$(cat "$DIR_RESPALDO/linea-base-portales.txt")" ]
}

echo "== Verificación del diagnóstico de solo lectura (127.0.0.1:$PUERTO)"
prueba "sitio Apache $SITIO habilitado y backend presente" test -e "$SITE_ENABLED" -a -f "$DIR_BACKEND/api.php"
prueba "$PUERTO escucha SOLO en 127.0.0.1" solo_loopback "$PUERTO"
prueba "GET /api/ping responde {\"pong\":true}" ping_json
prueba "los dos endpoints protegidos rechazan (401) la ausencia de token, un token incorrecto, uno de ceros y un Bearer JWT" privados_sin_token
prueba "GET /api/diagnostico/resumen con token: forma correcta y modo diagnóstico" resumen_forma
prueba "los controles confirman sesión MySQL de solo lectura y cuenta sin privilegios de escritura" controles_ok
prueba "GET /api/diagnostico/conciliacion con token: forma correcta" conciliacion_forma
prueba "las respuestas no contienen hashes, correos, enlaces, URLs, credenciales ni nombres de usuario" sin_datos_sensibles
prueba "los métodos de escritura y TRACE se rechazan (403/405)" metodos_rechazados
prueba "HEAD se rechaza (403)" head_rechazado
prueba "login, catálogos, visor, wiki, Power BI, archivos internos, variantes de ruta y saltos de directorio → 403" rutas_cerradas
prueba "ninguna respuesta emite cookie de sesión" sin_cookie
prueba "cabeceras: Cache-Control no-store, nosniff, X-Frame-Options DENY y sin HSTS" cabeceras_seguras
prueba ".env: modo 640 root:www-data y fuera de cualquier DocumentRoot (docroot vacío)" bash -c "$(declare -f env_permisos env_fuera_de_docroot); ENV_FILE=$ENV_FILE DIR_DOCROOT=$DIR_DOCROOT; env_permisos && env_fuera_de_docroot"
prueba "el backend no es escribible por www-data ni por otros" backend_no_escribible
prueba "sesiones PHP aisladas (700 www-data) y sin ninguna sesión creada por el diagnóstico" sesiones_aisladas
prueba ".env: modo diagnóstico exigido y sin JWT_SECRET (no hay inicio de sesión)" modo_en_env
prueba "el token y la clave de la cuenta NO aparecen en logs, respaldos ni paquete" token_no_en_logs
prueba "el token es distinto de cualquier secreto de 8088 y 8089" token_distinto_de_otros
prueba "la cuenta $DB_USUARIO cumple el preflight de acceso (18 columnas, solo lectura, catálogo funcional)" acceso_mysql
prueba "Flutter, 8088, 8089, módulos, sitios, puertos, bases y cuentas MySQL idénticos a la línea base" portales_intactos

echo
echo "$pass PASS / $fail FAIL"
[ "$fail" -eq 0 ]
