#!/usr/bin/env bash
# Conciliación de las 27 medidas agregadas con la línea base del inventario (UX-07D.3B). SOLO LECTURA: consulta GET /api/diagnostico/conciliacion y clasifica.
#   sudo bash scripts/conciliar.sh [--json]
# Las cantidades del inventario son una LÍNEA BASE histórica, no el estado actual: Flutter sigue operativo y sus datos cambian. Una diferencia NO se interpreta como
# corrupción ni se corrige: este procedimiento nunca actualiza datos ni muestra registros individuales (el endpoint solo devuelve números agregados).
# Código de salida: 0 = coincide · 10 = hay diferencias (clasificadas) · 11 = error de consulta/acceso.
set -uo pipefail
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
requerir_root
[ -f "$ENV_FILE" ] || abortar "El diagnóstico no está instalado."
respuesta=$(mktemp); trap 'rm -f "$respuesta"' EXIT
codigo=$(curl_con_token -sS -m 30 -o "$respuesta" -w '%{http_code}' "http://127.0.0.1:$PUERTO/api/diagnostico/conciliacion") || codigo=000
php "$PAQ/scripts/conciliar.php" "$codigo" "$respuesta" "${1:-}"
