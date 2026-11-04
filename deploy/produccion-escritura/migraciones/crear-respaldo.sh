#!/usr/bin/env bash
# shellcheck disable=SC2034
# Cervesur · UX-08H · Respaldo VERIFICABLE de las seis tablas de negocio, previo a las migraciones de la fase 2A.
#
# QUÉ HACE:   mysqldump (--single-transaction --routines=false) de user, empresa, reporte, empresa_usuario, empresa_reporte y user_empresa_reporte,
#             comprimido con gzip, más un MANIFIESTO (<archivo>.manifest) con SHA-256 del archivo, huella de la estructura, filas y CHECKSUM TABLE de cada tabla.
#             aplicar-migraciones.sh --aplicar exige ese manifiesto y compara los CHECKSUM con las tablas vivas (tolerancia cero).
# QUÉ NO HACE: no borra NUNCA respaldos anteriores; no modifica datos de negocio; no imprime contraseñas.
# CÓMO:       sudo bash crear-respaldo.sh [--db cervesur_portalbi] [--destino /var/backups/cervesur-react-admin/respaldos-fase2a] [--probar-restauracion]
#             --probar-restauracion carga el volcado en un esquema TEMPORAL (cervesur_verif_<sello>), compara CHECKSUM TABLE con las tablas vivas y lo elimina.
#             Eso exige CREATE/DROP sobre ese esquema (root) y deja constancia en el manifiesto (restauracion_probada=1).
set -u
set -o pipefail
AQUI="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib-comun.sh
. "$AQUI/lib-comun.sh"

DB="cervesur_portalbi"
DESTINO="$BITACORA_BASE_DEFECTO/respaldos-fase2a"
PROBAR_RESTAURACION=0

while [ $# -gt 0 ]; do
  case "$1" in
    --ayuda|-h|--help) sed -n '2,11p' "$0" | sed 's/^# \{0,1\}//'; exit 0 ;;
    --db) DB="${2:-}"; shift 2 ;;
    --destino) DESTINO="${2:-}"; shift 2 ;;
    --probar-restauracion) PROBAR_RESTAURACION=1; shift ;;
    *) echo "Opción desconocida: $1 (use --ayuda)" >&2; exit 2 ;;
  esac
done
[[ "$DB" =~ ^[A-Za-z0-9_]{1,64}$ ]] || { echo "--db inválido" >&2; exit 2; }
[ -n "$DESTINO" ] || { echo "--destino vacío" >&2; exit 2; }
cm_preparar_comandos
umask 077
SELLO="$(date +%Y%m%d-%H%M%S)"
mkdir -p "$DESTINO" && chmod 700 "$DESTINO" || { echo "No pude crear $DESTINO" >&2; exit 2; }
ARCHIVO="$DESTINO/negocio6-$DB-$SELLO.sql.gz"
if [ -e "$ARCHIVO" ] || [ -e "$ARCHIVO.manifest" ]; then echo "Ya existe $ARCHIVO: no se sobrescribe nunca." >&2; exit 2; fi
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

echo "== Respaldo de las seis tablas de negocio de $DB"
leer_texto "SELECT 1" >/dev/null 2>&1 || { echo "No conecto a MySQL con «${MYSQL_A[*]}»" >&2; exit 3; }
for t in $TABLAS_NEGOCIO; do
  [ "$(leer_texto "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$DB' AND table_name = '$t'")" = "1" ] || { echo "Falta la tabla $DB.$t" >&2; exit 3; }
done
VERSION_SRV="$(leer_texto "SELECT @@version")"

estado_tablas "$DB" >"$TMP/antes.tsv" || { echo "No pude leer filas/CHECKSUM" >&2; exit 3; }
ESTRUCTURA="$(estructura_sha256 "$DB")"
[ -n "$ESTRUCTURA" ] || { echo "No pude calcular la huella de la estructura (mysqldump)" >&2; exit 3; }

echo "-- volcado..."
# shellcheck disable=SC2086
if ! "${MYSQLDUMP_A[@]}" --single-transaction --routines=false --events=false --skip-triggers --skip-extended-insert --complete-insert \
      --no-tablespaces --set-gtid-purged=OFF --default-character-set=utf8mb4 "$DB" $TABLAS_NEGOCIO | gzip -n >"$ARCHIVO.parcial"; then
  mv "$ARCHIVO.parcial" "$ARCHIVO.descartado"
  echo "El volcado falló; el parcial quedó como $ARCHIVO.descartado (no se borra nada)." >&2; exit 4
fi

estado_tablas "$DB" >"$TMP/despues.tsv" || { echo "No pude releer filas/CHECKSUM" >&2; exit 3; }
if ! cmp -s "$TMP/antes.tsv" "$TMP/despues.tsv"; then
  mv "$ARCHIVO.parcial" "$ARCHIVO.descartado"
  echo "Las tablas CAMBIARON mientras se respaldaba (alguien escribió). Respaldo DESCARTADO: $ARCHIVO.descartado. Reintente en un momento sin escrituras." >&2
  diff "$TMP/antes.tsv" "$TMP/despues.tsv" | sed 's/^/   /' >&2
  exit 5
fi
mv "$ARCHIVO.parcial" "$ARCHIVO"

echo "-- verificación del archivo..."
gzip -t "$ARCHIVO" || { echo "gzip -t falla: respaldo corrupto" >&2; exit 6; }
zcat "$ARCHIVO" >"$TMP/volcado.sql"
grep -q -- '-- Dump completed' "$TMP/volcado.sql" || { echo "El volcado no termina con «Dump completed» (truncado)" >&2; exit 6; }
while IFS=$'\t' read -r tabla filas _; do
  grep -q "^CREATE TABLE \`$tabla\` " "$TMP/volcado.sql" || { echo "Falta CREATE TABLE de $tabla en el volcado" >&2; exit 6; }
  n="$(grep -c "^INSERT INTO \`$tabla\` " "$TMP/volcado.sql")"
  [ "$n" = "$filas" ] || { echo "Filas en el volcado de $tabla: $n; filas vivas: $filas" >&2; exit 6; }
done <"$TMP/despues.tsv"

RESTAURADO=0
if [ "$PROBAR_RESTAURACION" = 1 ]; then
  echo "-- prueba de restauración en un esquema temporal..."
  PERMITIR_ESCRITURA=1
  VER="cervesur_verif_${SELLO//-/_}"
  sql_escribir -e "CREATE DATABASE \`$VER\` CHARACTER SET utf8mb4" || { echo "No pude crear el esquema temporal" >&2; exit 7; }
  if sql_escribir -D "$VER" <"$TMP/volcado.sql" && for t in $TABLAS_NEGOCIO; do
       esp="$(awk -F'\t' -v t="$t" '$1 == t {print $3}' "$TMP/despues.tsv")"
       got="$(leer_texto "CHECKSUM TABLE \`$VER\`.\`$t\`" | awk -F'\t' '{print $2}')"
       [ "$esp" = "$got" ] || { echo "CHECKSUM distinto tras restaurar $t (vivo $esp, restaurado $got)" >&2; false; }
     done; then RESTAURADO=1; echo "   restauración verificada (CHECKSUM TABLE idéntico en las seis tablas)"; else echo "   la restauración de prueba FALLÓ" >&2; fi
  sql_escribir -e "DROP DATABASE IF EXISTS \`$VER\`" || echo "AVISO: elimine a mano el esquema temporal $VER" >&2
  PERMITIR_ESCRITURA=0
  [ "$RESTAURADO" = 1 ] || exit 7
fi

SHA="$(sha256_archivo "$ARCHIVO")"
{
  echo "formato=cervesur-respaldo-v1"
  echo "base=$DB"
  echo "creado_utc=$(date -u +%Y-%m-%dT%H:%M:%SZ)"
  echo "servidor_version=$VERSION_SRV"
  echo "archivo=$(basename "$ARCHIVO")"
  echo "archivo_sha256=$SHA"
  echo "estructura_sha256=$ESTRUCTURA"
  echo "restauracion_probada=$RESTAURADO"
  while IFS=$'\t' read -r tabla filas chk; do echo "tabla.$tabla.filas=$filas"; echo "tabla.$tabla.checksum=$chk"; done <"$TMP/despues.tsv"
} >"$ARCHIVO.manifest"
printf '%s  %s\n' "$SHA" "$(basename "$ARCHIVO")" >"$ARCHIVO.sha256"
chmod 600 "$ARCHIVO" "$ARCHIVO.manifest" "$ARCHIVO.sha256"

echo "== Respaldo creado y verificado"
echo "archivo:   $ARCHIVO ($(wc -c <"$ARCHIVO") bytes)"
echo "manifiesto: $ARCHIVO.manifest"
echo "sha256:    ${SHA:0:16}…"
while IFS=$'\t' read -r tabla filas chk; do printf '   %-22s filas=%-6s checksum=%s\n' "$tabla" "$filas" "$chk"; done <"$TMP/despues.tsv"
echo "restauracion_probada=$RESTAURADO"
echo "Use este archivo con:  aplicar-migraciones.sh --aplicar --respaldo $ARCHIVO --confirmo-autorizacion \"<texto>\""
