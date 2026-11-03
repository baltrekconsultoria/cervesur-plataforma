#!/usr/bin/env bash
# Verifica el piloto visual (UX-07D.4). SOLO LECTURA: consultas HTTP a 127.0.0.1:8091, comprobaciones de archivos/permisos y de la cuenta MySQL, y comparación con
# la línea base. No imprime claves, tokens, enlaces ni cifras de las tablas.
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
TOKEN=$(token_operador)
api() { curl_con_token "$TOKEN" -sS -m 15 "$@"; }
api_codigo() { curl_con_token "$TOKEN" -sS -m 15 -o /dev/null -w '%{http_code}' "$@"; }

solo_loopback() { local l; l=$(ss -ltnH "sport = :$1" | awk '{print $4}'); [ -n "$l" ] && ! grep -qvE '^127\.0\.0\.1:'"$1"'$' <<<"$l"; }
ping_json() { grep -q '"pong":true' <<<"$(cuerpo "$BASE/api/ping")"; }
indice_html() { grep -q '<div id="root">' <<<"$(cuerpo "$BASE/")"; }
csp_powerbi() { local csp; csp=$(cabeceras "$BASE/" | grep -i '^content-security-policy:') || return 1; grep -q "frame-src https://app.powerbi.com;" <<<"$csp"; }
sin_pantallas_admin() { ! cat "$DIR_PUBLIC"/assets/*.js | grep -q 'Nuevo usuario\|Crear usuario\|Nueva empresa\|Nuevo reporte\|Asignar'; }
operador_y_duracion() { login_operador && duracion_token; }
indice_y_compilacion() { indice_html && compilacion_piloto; }
sin_cookie_y_cabeceras() { sin_cookie && cabeceras_api; }
compilacion_piloto() { grep -q '"pilot": true' "$DIR_PUBLIC/build-info.json" && grep -q '"target": "server"' "$DIR_PUBLIC/build-info.json"; }
sin_sesion_401() { local r; for r in /api/user/profile /api/reportes /api/reportes/1 /api/reportes/id/1/visor /api/empresas /api/asignacion; do [ "$(http "$BASE$r")" = "401" ] || return 1; done; [ "$(http -H 'Authorization: Bearer a.b.c' "$BASE/api/reportes")" = "401" ]; }
sin_sesion_sin_datos() { ! grep -q '"data"' <<<"$(cuerpo "$BASE/api/reportes")"; }
login_incorrecto() { local c; c=$(printf '{"login":"%s","password":"incorrecta-xyz"}' "$OPERADOR" | curl -sS -m 15 -H 'Content-Type: application/json' --data @- "$BASE/api/login"); grep -q '"success":false' <<<"$c" && ! grep -q '"token"' <<<"$c"; }
usuarios_del_sistema_rechazados() { local c; c=$(printf '{"login":"admin","password":"admin"}' | curl -sS -m 15 -H 'Content-Type: application/json' --data @- "$BASE/api/login"); ! grep -q '"token"' <<<"$c"; }
login_operador() { [ -n "$TOKEN" ]; }
perfil() { grep -q '"profile"' <<<"$(api "$BASE/api/user/profile")" && ! grep -qi 'password' <<<"$(api "$BASE/api/user/profile")"; }
listados() { local r c; for r in reportes empresas asignacion; do c=$(api "$BASE/api/$r?view=minimal&per_page=100&page=1"); grep -q '"success":true' <<<"$c" && grep -q '"pagination"' <<<"$c" || return 1; done; }
sin_enlaces() { local c; c=$(api "$BASE/api/reportes?per_page=100"; api "$BASE/api/empresas?per_page=100"; api "$BASE/api/asignacion?per_page=100"); ! grep -qiE 'enlace|https?:|powerbi|\$2y\$|password|secret' <<<"$c"; }
visor_coherente() { # con autorización vigente el visor entrega; sin ella, 403. Nunca se imprime el enlace.
  local primero cfg c; primero=$(api "$BASE/api/reportes?per_page=1" | sed -n 's/.*"data":\[{"id":\([0-9]*\).*/\1/p'); [ -n "$primero" ] || return 0 # catálogo vacío: nada que comprobar
  cfg=$(valor_env CERVESUR_PILOT_VISOR_IDS); c=$(api_codigo "$BASE/api/reportes/id/$primero/visor")
  if [ -z "$cfg" ]; then [ "$c" = "403" ]; elif [ "$cfg" = "TODOS" ]; then [ "$c" = "200" ] || [ "$c" = "404" ]; else case ",$cfg," in *",$primero,"*) [ "$c" = "200" ] ;; *) [ "$c" = "403" ] ;; esac; fi
}
rutas_cerradas() {
  local r
  for r in /api/users /api/user /api/stats /api/empresa-usuario /api/empresa-reporte-usuario /api/reportes/activos /api/empresas/1 /api/asignacion/1 /api/powerbi/tokens/1 /api/wiki /api/reportes/ /api/ping/ /api/reportes/id/abc/visor; do
    [ "$(api_codigo "$BASE$r")" = "403" ] || { echo "no 403: $r" >&2; return 1; }
  done
  for r in POST PUT DELETE; do [ "$(api_codigo -X $r -H 'Content-Type: application/json' -d '{}' "$BASE/api/reportes")" = "403" ] || return 1; done
  [ "$(api_codigo -X POST -d '{}' "$BASE/api/register")" = "403" ] && [ "$(api_codigo -X DELETE "$BASE/api/empresa-reporte-usuario/1")" = "403" ]
}
metodos_rechazados() { local m c; for m in PUT PATCH DELETE OPTIONS TRACE; do c=$(http -X "$m" "$BASE/api/reportes"); [ "$c" = "403" ] || [ "$c" = "405" ] || return 1; done; }
archivos_internos_no_servidos() { local r c; for r in /.env /backend/.env /lib/db.php /api.php /operador.clave; do c=$(cuerpo "$BASE$r"); ! grep -qE 'DB_PASS|JWT_SECRET|OPERATOR_HASH|<\?php' <<<"$c" || return 1; done; }
sin_cookie() { ! cabeceras "$BASE/api/ping" | grep -qi '^set-cookie:'; }
cabeceras_api() { local h; h=$(cabeceras "$BASE/api/ping"); grep -qi '^cache-control: no-store' <<<"$h" && grep -qi '^x-content-type-options: nosniff' <<<"$h"; }
duracion_token() { local p exp now; p=$(cut -d. -f2 <<<"$TOKEN" | tr '_-' '/+'); while [ $(( ${#p} % 4 )) -ne 0 ]; do p="$p="; done; exp=$(printf '%s' "$p" | base64 -d 2>/dev/null | sed -n 's/.*"exp":\([0-9]*\).*/\1/p'); now=$(date +%s); [ -n "$exp" ] && [ $((exp - now)) -le 28800 ] && [ $((exp - now)) -gt 60 ]; }
permisos() {
  [ "$(stat -c '%a %U:%G' "$ENV_FILE")" = "640 root:www-data" ] && [ "$(stat -c '%a %U:%G' "$CLAVE_OPERADOR_FILE")" = "600 root:root" ] \
    && [ "$(stat -c '%a %U' "$DIR_ESTADO")" = "700 www-data" ] && [ "$(stat -c '%a %U' "$DIR_SESIONES")" = "700 www-data" ] && [ "$(stat -c %U "$DIR_PUBLIC")" = "root" ] \
    && ! find "$DIR_BACKEND" "$DIR_PUBLIC" \( -perm /o+w -o -user www-data \) | grep -q . && [ -z "$(find "$DIR_PUBLIC" -name '.env*' -o -name 'operador*')" ]
}
env_correcto() {
  grep -qx 'CERVESUR_MODE=piloto_visual' "$ENV_FILE" && grep -qx 'CERVESUR_REQUIRED_MODE=piloto_visual' "$ENV_FILE" && grep -q '^CERVESUR_PILOT_OPERATOR_HASH=\$2y\$' "$ENV_FILE" \
    && ! grep -q 'CERVESUR_DIAG_TOKEN' "$ENV_FILE" && [[ $(valor_env JWT_SECRET) =~ ^[0-9a-f]{64}$ ]] && ! grep -qF -- "$(head -n 1 "$CLAVE_OPERADOR_FILE")" "$ENV_FILE"
}
secretos_no_en_logs() {
  local j c p; j=$(valor_env JWT_SECRET); c=$(head -n 1 "$CLAVE_OPERADOR_FILE"); p=$(valor_env DB_PASS)
  # los patrones llegan por un archivo (sustitución de procesos): ningún secreto aparece en la lista de procesos
  [ -n "$j" ] && [ -n "$c" ] && [ -n "$p" ] && ! grep -rqsF -f <(printf '%s\n' "$j" "$c" "$p" "$TOKEN") /var/log "$DIR_RESPALDO" "$PAQ" --exclude=mysql.err 2>/dev/null
}
secretos_distintos() { local j f o; j=$(valor_env JWT_SECRET); for f in "$DIR_BACK_PRUEBA/backend/.env" "$DIR_BACK_COMPAT/backend/.env" "$DIR_BACK_DIAG/backend/.env"; do [ -r "$f" ] || continue; while IFS= read -r o; do [ "${o#*=}" != "$j" ] || return 1; done < "$f"; done; }
acceso_mysql() { local p; p=$(valor_env DB_PASS); CERVESUR_RO_VISOR_PASS=$p DB_HOST=127.0.0.1 DB_NAME=$DB_NOMBRE DB_USER=$DB_USUARIO php "$PAQ/scripts/preflight-acceso.php" "$DIR_BACKEND" >/dev/null; }
portales_intactos() { huella_igual_a_linea_base; }

echo "== Verificación del piloto visual (127.0.0.1:$PUERTO)"
prueba "sitio Apache $SITIO habilitado, backend y aplicación presentes" test -e "$SITE_ENABLED" -a -f "$DIR_BACKEND/api.php" -a -f "$DIR_PUBLIC/index.html"
prueba "$PUERTO escucha SOLO en 127.0.0.1" solo_loopback "$PUERTO"
prueba "GET /api/ping responde {\"pong\":true}" ping_json
prueba "la aplicación se sirve (index con #root) y es la compilación del piloto (pilot=true, target=server)" indice_y_compilacion
prueba "CSP con frame-src https://app.powerbi.com (para el visor)" csp_powerbi
prueba "la aplicación NO incluye las pantallas de administración, alta, edición ni asignación" sin_pantallas_admin
prueba "ningún endpoint del catálogo responde sin sesión (401): perfil, informes, detalle, visor, empresas y relaciones; tampoco con un token inventado" sin_sesion_401
prueba "sin sesión no se entrega ningún dato" sin_sesion_sin_datos
prueba "contraseña incorrecta del operador → rechazada, sin token" login_incorrecto
prueba "credenciales de usuarios del sistema (admin/admin) → rechazadas: no se simula ni se acepta ninguna identidad productiva" usuarios_del_sistema_rechazados
prueba "el operador inicia sesión con su clave propia (token de corta duración, ≤ 8 h)" operador_y_duracion
prueba "perfil sintético del operador, sin contraseñas" perfil
prueba "listados de informes, empresas y relaciones con paginación válida" listados
prueba "los listados NO contienen enlaces, URL de Power BI, hashes ni credenciales" sin_enlaces
prueba "el visor respeta la autorización del operador (sin autorización: 403; con ella: 200) sin mostrar el enlace" visor_coherente
prueba "usuarios, estadísticas, asignaciones de usuarios, Power BI heredado, variantes de ruta y escrituras (POST/PUT/DELETE) → 403 con sesión válida" rutas_cerradas
prueba "PUT/PATCH/DELETE/OPTIONS/TRACE → 403/405" metodos_rechazados
prueba "los archivos internos (.env, db.php, api.php, clave) no se sirven" archivos_internos_no_servidos
prueba "ninguna respuesta de la API emite cookie; Cache-Control no-store y nosniff" sin_cookie_y_cabeceras
prueba "permisos: .env 640 root:www-data, clave del operador 600 root:root, estado y sesiones 700 www-data, aplicación y backend sin escritura para www-data ni otros" permisos
prueba ".env: modo piloto exigido, hash bcrypt del operador, JWT de 256 bits, sin token de diagnóstico y sin la clave en claro" env_correcto
prueba "ni el JWT, ni la clave del operador, ni la de la cuenta, ni el token de sesión aparecen en logs, respaldos ni paquete" secretos_no_en_logs
prueba "el secreto JWT es distinto de los de 8088, 8089 y 8090" secretos_distintos
prueba "la cuenta $DB_USUARIO cumple el preflight de acceso (15 columnas, solo lectura, sin tabla user, catálogo funcional)" acceso_mysql
prueba "Flutter, 8088, 8089, 8090, módulos, sitios, puertos, bases y cuentas MySQL idénticos a la línea base" portales_intactos

echo
echo "$pass PASS / $fail FAIL"
[ "$fail" -eq 0 ]
