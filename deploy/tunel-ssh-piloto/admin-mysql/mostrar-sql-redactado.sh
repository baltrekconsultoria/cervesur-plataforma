#!/usr/bin/env bash
# Muestra el SQL generado por preparar-sql-cuenta.sh con la clave REDACTADA (********), para revisarlo por pantalla sin exponerla (UX-07D.3B.1).
#   bash admin-mysql/mostrar-sql-redactado.sh [/root/cervesur-ro-visor.sql] [/root/cervesur-ro-visor.clave]
# Si no puede leer la clave para redactarla NO imprime nada (jamás muestra el SQL sin redactar). Solo lee; no escribe archivos ni se conecta a MySQL.
set -euo pipefail
ARCHIVO=${1:-/root/cervesur-ro-visor.sql}
CLAVE_ARCHIVO=${2:-${ARCHIVO%.sql}.clave}
[ -r "$ARCHIVO" ] || { echo "No se puede leer $ARCHIVO" >&2; exit 1; }
[ -r "$CLAVE_ARCHIVO" ] || { echo "No se puede leer $CLAVE_ARCHIVO: se niega a mostrar el SQL sin poder redactarlo." >&2; exit 1; }
CLAVE=$(head -n 1 "$CLAVE_ARCHIVO" | tr -d '\r\n')
[ ${#CLAVE} -ge 8 ] || { echo "La clave del archivo no es válida: se niega a mostrar el SQL." >&2; exit 1; }
salida=$(CLAVE="$CLAVE" awk '{ c = ENVIRON["CLAVE"]; while ((i = index($0, c)) > 0) { $0 = substr($0, 1, i - 1) "********" substr($0, i + length(c)) } print }' "$ARCHIVO")
if grep -qF -- "$CLAVE" <<<"$salida"; then echo "La redacción falló: no se muestra nada." >&2; exit 1; fi
printf '%s\n' "$salida"
