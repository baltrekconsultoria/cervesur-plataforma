#!/usr/bin/env bash
# Verifica el entorno de COMPATIBILIDAD (solo lectura; consultas HTTP a 127.0.0.1:8089, metadatos de MySQL y comparación con la línea base).
#   sudo bash scripts/verificar.sh
# Si CERVESUR_PRUEBA_ADMIN_PASSWORD está definida (la define instalar.sh) también prueba el inicio de sesión y los datos sintéticos.
set -uo pipefail
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
BASE="http://127.0.0.1:$PUERTO"
pass=0; fail=0
prueba() { local nombre=$1; shift; if "$@" >/dev/null 2>&1; then printf 'PASS  %s\n' "$nombre"; pass=$((pass + 1)); else printf 'FAIL  %s\n' "$nombre"; fail=$((fail + 1)); fi; }
http() { curl -sS -m 10 -o /dev/null -w '%{http_code}' "$@"; }
cuerpo() { curl -sS -m 10 "$@"; }
cabeceras() { curl -sS -m 10 -o /dev/null -D - "$@" | tr -d '\r'; }
token_admin() {
  local r; r=$(printf '{"login":"syn001","password":"%s"}' "$CERVESUR_PRUEBA_ADMIN_PASSWORD" | curl -sS -m 10 -H 'Content-Type: application/json' --data @- "$BASE/api/login") || return 1
  sed -n 's/.*"token":"\([^"]*\)".*/\1/p' <<<"$r"
}

solo_loopback() { # puerto, dirección esperada
  local lineas; lineas=$(ss -ltnH "sport = :$1" | awk '{print $4}')
  [ -n "$lineas" ] && ! grep -qvE '^127\.0\.0\.1:'"$1"'$' <<<"$lineas"
}
codigo_es() { [ "$(http "${@:2}")" = "$1" ]; }
codigo_en() { local c; c=$(http "${@:2}"); case " $1 " in *" $c "*) return 0 ;; *) return 1 ;; esac; }
indice_html() { local c; c=$(cuerpo "$BASE/"); grep -q '<div id="root">' <<<"$c"; }
ruta_interna_igual() { [ "$(cuerpo "$BASE/")" = "$(cuerpo "$BASE/admin/usuarios")" ]; }
ping_json() { local c; c=$(cuerpo "$BASE/api/ping"); grep -q '"pong":true' <<<"$c"; }
json_401() { local c; c=$(cuerpo "$BASE/api/user/profile"); grep -q '"success":false' <<<"$c"; }
csp_powerbi() { local h csp; h=$(cabeceras "$BASE/"); csp=$(grep -i '^content-security-policy:' <<<"$h") || return 1; grep -q "frame-src https://app.powerbi.com;" <<<"$csp"; }
sin_hsts() { local h; h=$(cabeceras "$BASE/"); ! grep -qi '^strict-transport-security:' <<<"$h"; }
marco_denegado() { local h; h=$(cabeceras "$BASE/"); grep -qi '^x-frame-options: DENY' <<<"$h"; }
cookie_propia() { # cabecera Set-Cookie de /api/ping de ESTE sitio
  local h c; h=$(cabeceras "$BASE/api/ping"); c=$(grep -i '^set-cookie:' <<<"$h") || return 1
  grep -q "^[Ss]et-[Cc]ookie: $COOKIE_SESION=" <<<"$c" && grep -qi 'httponly' <<<"$c" && grep -qi 'samesite=strict' <<<"$c" &&
    ! grep -qi ';[[:space:]]*secure' <<<"$c" && ! grep -q 'PHPSESSID' <<<"$c" && ! grep -q "[Cc]ookie: $COOKIE_SESION_PRUEBA=" <<<"$c"
}
sesiones_en_directorio_propio() { cuerpo "$BASE/api/ping" >/dev/null; compgen -G "$DIR_SESIONES/sess_*" >/dev/null; }
cookie_8088_intacta() { # el React de 8088 sigue usando su propia cookie
  local h c; h=$(cabeceras "http://127.0.0.1:$PUERTO_PRUEBA/api/ping"); c=$(grep -i '^set-cookie:' <<<"$h") || return 1
  grep -q "^[Ss]et-[Cc]ookie: $COOKIE_SESION_PRUEBA=" <<<"$c"
}
login_sintetico() { local t; t=$(token_admin) && [ -n "$t" ]; }
informes_activos_82() { # el administrador ve exactamente 82 informes activos (agregado productivo)
  local t r; t=$(token_admin) || return 1; r=$(curl -sS -m 10 -H "Authorization: Bearer $t" "$BASE/api/reportes?view=minimal&per_page=1") || return 1
  grep -q '"total":82' <<<"$r"
}
visor_numerico() { # visor por id numérico: modo público para el informe 1; ids malformados → 404
  local t r; t=$(token_admin) || return 1
  r=$(curl -sS -m 10 -H "Authorization: Bearer $t" "$BASE/api/reportes/id/1/visor") || return 1
  grep -q '"modo":"publico"' <<<"$r" && grep -q '"id":1' <<<"$r" &&
    [ "$(curl -sS -m 10 -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $t" "$BASE/api/reportes/id/007/visor")" = 404 ] &&
    [ "$(curl -sS -m 10 -o /dev/null -w '%{http_code}' -H "Authorization: Bearer $t" "$BASE/api/reportes/id/4294967296/visor")" = 404 ]
}
validacion_informes() { # POST de un informe incompleto: PHP debe validar (mbstring) y responder JSON success:false con HTTP 200, no un fatal 500
  local t r c; t=$(token_admin) || return 1
  r=$(curl -sS -m 10 -w '
%{http_code}' -H "Authorization: Bearer $t" -H 'Content-Type: application/json' --data '{"nombre":"x","enlace":"x"}' "$BASE/api/reportes") || return 1
  c=${r##*$'
'}
  [ "$c" = 200 ] && grep -q '"success":false' <<<"$r"
}
privilegios_minimos() {
  local h g
  for h in localhost 127.0.0.1; do
    # shellcheck disable=SC2086
    g=$($MYSQL_ADMIN -N -e "SHOW GRANTS FOR '$DB_USUARIO'@'$h'") || return 1
    grep -q "GRANT SELECT, INSERT, UPDATE, DELETE ON \`$DB_NOMBRE\`\\.\\* TO" <<<"$g" || return 1
    ! grep -qE 'ALL PRIVILEGES|GRANT OPTION|DROP|ALTER|CREATE' <<<"$g" || return 1
    [ "$(grep -c '^GRANT' <<<"$g")" = 2 ] || return 1 # USAGE global + los permisos de esta base; nada más
  done
}
esquema_compatible() {
  local r
  # shellcheck disable=SC2086
  r=$($MYSQL_ADMIN -N -e "SELECT CONCAT(COLUMN_NAME, ':', COLUMN_TYPE, ':', IS_NULLABLE) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = '$DB_NOMBRE' AND TABLE_NAME = 'reporte' AND COLUMN_NAME IN ('grupo_id', 'reporte_id', 'enlace') ORDER BY COLUMN_NAME") || return 1
  [ "$r" = "$(printf 'enlace:varchar(500):NO\ngrupo_id:varchar(50):YES\nreporte_id:varchar(50):NO')" ]
}
portales_intactos() {
  [ -f "$DIR_RESPALDO/linea-base-portales.txt" ] && [ "$(huella_portales)" = "$(cat "$DIR_RESPALDO/linea-base-portales.txt")" ]
}
flutter_en_puerto_80() { local escucha; escucha=$(ss -ltnH "sport = :$PUERTO_FLUTTER"); [ -n "$escucha" ] && [ "$(http "http://127.0.0.1:$PUERTO_FLUTTER/")" != "000" ]; }

echo "== Verificación del entorno de compatibilidad ($BASE)"
prueba "Apache escucha el puerto $PUERTO SOLO en 127.0.0.1 (ninguna interfaz de red)" solo_loopback "$PUERTO"
prueba "Apache: configuración válida" apache2ctl configtest
prueba "PHP responde por /api (JSON) y la base MySQL contesta" ping_json
prueba "/ entrega la aplicación React" indice_html
prueba "una ruta interna (/admin/usuarios) entrega index.html al recargar" ruta_interna_igual
prueba "un asset inexistente responde 404 real" codigo_es 404 "$BASE/assets/no-existe.js"
prueba "401 de la API llega como JSON" json_401
prueba "el endpoint heredado de tokens OAuth (/api/powerbi/...) responde 403" codigo_es 403 "$BASE/api/powerbi/tokens/1"
for ruta in /.env /.htaccess /build-info.json /api.php /controllers/AuthController.php; do
  prueba "$ruta no es accesible" codigo_en "403 404" "$BASE$ruta"
done
prueba "CSP con frame-src https://app.powerbi.com" csp_powerbi
prueba "X-Frame-Options: DENY" marco_denegado
prueba "sin HSTS (el sitio va por HTTP de loopback; HSTS solo se emite por HTTPS)" sin_hsts
prueba "cookie de sesión propia ($COOKIE_SESION): HttpOnly, SameSite=Strict, sin Secure y sin la cookie de 8088 ni PHPSESSID" cookie_propia
prueba "las sesiones se guardan en el directorio exclusivo $DIR_SESIONES" sesiones_en_directorio_propio
[ -e "$SITE_ENABLED_PRUEBA" ] && prueba "el React de 8088 conserva su propia cookie de sesión ($COOKIE_SESION_PRUEBA)" cookie_8088_intacta
prueba "esquema compatible: reporte_id VARCHAR(50) NOT NULL, grupo_id VARCHAR(50) NULL, enlace VARCHAR(500) NOT NULL" esquema_compatible
prueba "el usuario MySQL solo tiene SELECT, INSERT, UPDATE, DELETE sobre $DB_NOMBRE (localhost y 127.0.0.1)" privilegios_minimos
if [ -n "${CERVESUR_PRUEBA_ADMIN_PASSWORD:-}" ]; then
  prueba "inicio de sesión sintético (syn001, administrador) contra PHP y MySQL de compatibilidad" login_sintetico
  prueba "el administrador ve exactamente 82 informes activos (agregado productivo reproducido)" informes_activos_82
  prueba "visor por id numérico: modo público para el informe 1; ids malformados → 404" visor_numerico
  prueba "la validación de informes por Apache/PHP responde JSON (mbstring disponible en el PHP del sitio)" validacion_informes
fi
prueba "Flutter sigue en el puerto 80" flutter_en_puerto_80
prueba "los demás portales están intactos (Flutter y React 8088: configuración, archivos, cabeceras, respuestas; módulos de Apache; bases y usuarios MySQL)" portales_intactos

printf '\n%s PASS / %s FAIL\n' "$pass" "$fail"
[ "$fail" -eq 0 ]
