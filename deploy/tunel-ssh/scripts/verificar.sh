#!/usr/bin/env bash
# Verifica la instalación de PRUEBA de React (solo lectura; consultas HTTP a 127.0.0.1:8088 y comparación con la línea base).
#   sudo bash scripts/verificar.sh
# Si la variable CERVESUR_PRUEBA_ADMIN_PASSWORD está definida (la define instalar.sh), también prueba un inicio de sesión sintético.
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

solo_loopback() {
  local lineas; lineas=$(ss -ltnH "sport = :$PUERTO" | awk '{print $4}')
  [ -n "$lineas" ] && ! grep -qvE '^127\.0\.0\.1:'"$PUERTO"'$' <<<"$lineas"
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
login_sintetico() {
  local r
  r=$(printf '{"login":"admin","password":"%s"}' "$CERVESUR_PRUEBA_ADMIN_PASSWORD" |
    curl -sS -m 10 -H 'Content-Type: application/json' --data @- "$BASE/api/login") || return 1
  grep -q '"success":true' <<<"$r"
}
flutter_intacto() {
  [ -f "$DIR_RESPALDO/linea-base-flutter.txt" ] && [ "$(huella_flutter)" = "$(cat "$DIR_RESPALDO/linea-base-flutter.txt")" ]
}

echo "== Verificación de la instalación de prueba ($BASE)"
prueba "Apache escucha el puerto $PUERTO SOLO en 127.0.0.1 (ninguna interfaz de red)" solo_loopback
prueba "Apache: configuración válida" apache2ctl configtest
prueba "/ entrega la aplicación React" indice_html
prueba "una ruta interna (/admin/usuarios) entrega index.html al recargar" ruta_interna_igual
prueba "un asset inexistente responde 404 real" codigo_es 404 "$BASE/assets/no-existe.js"
prueba "/api/ping lo atiende PHP (JSON)" ping_json
prueba "401 de la API llega como JSON" json_401
prueba "el endpoint heredado de tokens OAuth (/api/powerbi/...) responde 403" codigo_es 403 "$BASE/api/powerbi/tokens/1"
for ruta in /.env /.htaccess /build-info.json /api.php /controllers/AuthController.php; do
  prueba "$ruta no es accesible" codigo_en "403 404" "$BASE$ruta"
done
prueba "CSP con frame-src https://app.powerbi.com" csp_powerbi
prueba "X-Frame-Options: DENY" marco_denegado
prueba "sin HSTS (el sitio va por HTTP de loopback; HSTS solo se emite por HTTPS)" sin_hsts
[ -n "${CERVESUR_PRUEBA_ADMIN_PASSWORD:-}" ] && prueba "inicio de sesión sintético (admin) contra PHP y MySQL de prueba" login_sintetico
prueba "el portal Flutter está intacto (configuración, archivos y cabeceras HTTP idénticos a la línea base)" flutter_intacto

printf '\n%s PASS / %s FAIL\n' "$pass" "$fail"
[ "$fail" -eq 0 ]
