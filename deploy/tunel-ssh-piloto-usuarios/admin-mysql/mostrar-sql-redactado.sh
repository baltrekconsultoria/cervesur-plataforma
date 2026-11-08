#!/usr/bin/env bash
# Muestra el SQL generado por preparar-sql-cuentas.sh con las TRES claves REDACTADAS (********), para revisarlo por pantalla sin exponerlas (UX-07D.3B.1).
#   bash admin-mysql/mostrar-sql-redactado.sh [/root/cervesur-usuarios.sql] [/root/cervesur-usuarios.claves]
# Si no puede leer las claves para redactarlas NO imprime nada (jamás muestra el SQL sin redactar). Solo lee; no escribe archivos ni se conecta a MySQL.
set -euo pipefail
ARCHIVO=${1:-/root/cervesur-usuarios.sql}
CLAVES_ARCHIVO=${2:-${ARCHIVO%.sql}.claves}
[ -r "$ARCHIVO" ] || { echo "No se puede leer $ARCHIVO" >&2; exit 1; }
[ -r "$CLAVES_ARCHIVO" ] || { echo "No se puede leer $CLAVES_ARCHIVO: se niega a mostrar el SQL sin poder redactarlo." >&2; exit 1; }
salida=$(cat "$ARCHIVO")
n=0
while IFS= read -r linea; do
  [[ $linea == CERVESUR_*=* ]] || continue
  clave=${linea#*=}
  [ ${#clave} -ge 8 ] || { echo "Una clave del archivo no es válida: se niega a mostrar el SQL." >&2; exit 1; }
  salida=$(CLAVE="$clave" awk '{ c = ENVIRON["CLAVE"]; while ((i = index($0, c)) > 0) { $0 = substr($0, 1, i - 1) "********" substr($0, i + length(c)) } print }' <<<"$salida")
  n=$((n + 1))
done < "$CLAVES_ARCHIVO"
[ "$n" -eq 3 ] || { echo "El archivo de claves debe tener las tres claves: se niega a mostrar el SQL." >&2; exit 1; }
# Comprobación final: ninguna de las claves debe sobrevivir en lo que se va a imprimir.
while IFS= read -r linea; do
  [[ $linea == CERVESUR_*=* ]] || continue
  if grep -qF -- "${linea#*=}" <<<"$salida"; then echo "La redacción falló: no se muestra nada." >&2; exit 1; fi
done < "$CLAVES_ARCHIVO"
printf '%s\n' "$salida"
