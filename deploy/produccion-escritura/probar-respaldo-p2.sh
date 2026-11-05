#!/usr/bin/env bash
# Cervesur · UX-08I · P2 · Ensayo del PROCEDIMIENTO DE RESPALDO que se usará en producción, contra un MySQL DESECHABLE (nunca producción). NO va en el paquete (el empaquetador excluye «probar-*»).
# Ejecuta exactamente lo que el DBA ejecutará: migraciones/crear-respaldo.sh --probar-restauracion (seis tablas) + volcado de audit_log (con sus triggers) + verificación SHA-256 + restauración
# temporal de ambos + comprobación de que los datos vivos NO cambiaron + comprobación de que un respaldo alterado se detecta.
# Entorno: SQL_ROOT_CMD («mysql -h db -uroot -pXXX»), DUMP_ROOT_CMD («mysqldump -h db -uroot -pXXX»), DB_PRUEBA, REPO.
set -uo pipefail
: "${SQL_ROOT_CMD:?}" "${DUMP_ROOT_CMD:?}" "${DB_PRUEBA:?}" "${REPO:?}"
FALLOS=0; TOTAL=0
chk() { TOTAL=$((TOTAL + 1)); if [ "$1" = ok ]; then echo "PASS $2"; else echo "FAIL $2"; FALLOS=$((FALLOS + 1)); fi; }
sql() { $SQL_ROOT_CMD -N -B -e "$1" 2>/dev/null; }
export CERVESUR_MYSQL_CMD="$SQL_ROOT_CMD" CERVESUR_MYSQLDUMP_CMD="$DUMP_ROOT_CMD"
DEST=$(mktemp -d); trap 'rm -rf "$DEST"' EXIT
KIT="$REPO/deploy/produccion-escritura/migraciones"
TABLAS="user empresa reporte empresa_usuario empresa_reporte user_empresa_reporte audit_log"
vivo() { for t in $TABLAS; do sql "CHECKSUM TABLE \`$DB_PRUEBA\`.\`$t\`" | awk -F'\t' '{print $2}'; done | sha256sum | cut -c1-32; }
filas_audit() { sql "SELECT COUNT(*) FROM \`$DB_PRUEBA\`.audit_log"; }
# datos de partida: algunas filas de auditoría reales (la tabla es de solo inserción)
sql "INSERT INTO \`$DB_PRUEBA\`.audit_log (actor_user_id, action, entity, entity_id, correlation_id) VALUES (1,'ensayo.respaldo','empresa',1,'corr-p2-1'),(1,'ensayo.respaldo','empresa',2,'corr-p2-2')" >/dev/null
V0=$(vivo); A0=$(filas_audit)

echo "== 1. Respaldo de las seis tablas con prueba de restauración temporal"
bash "$KIT/crear-respaldo.sh" --db "$DB_PRUEBA" --destino "$DEST/respaldos" --probar-restauracion >"$DEST/o1" 2>&1; rc=$?
ARCH=$(ls "$DEST"/respaldos/negocio6-*.sql.gz 2>/dev/null | head -1)
[ $rc = 0 ] && [ -s "$ARCH" ] && grep -q 'restauracion_probada=1' "$ARCH.manifest" && chk ok "crear-respaldo --probar-restauracion: salida 0, archivo, manifiesto con restauracion_probada=1" || { chk no "crear-respaldo (rc=$rc)"; tail -8 "$DEST/o1"; }
(cd "$DEST/respaldos" && sha256sum -c "$(basename "$ARCH").sha256" --quiet) && chk ok "SHA-256 del respaldo verifica contra el .sha256 y el manifiesto" || chk no "sha256"
M=$(sed -n 's/^archivo_sha256=//p' "$ARCH.manifest"); [ "$M" = "$(sha256sum "$ARCH" | cut -c1-64)" ] && chk ok "archivo_sha256 del manifiesto = SHA-256 calculado" || chk no "manifiesto"
[ "$(stat -c %a "$ARCH" "$ARCH.manifest" "$DEST/respaldos" | sort -u | tr '\n' ' ')" = "600 700 " ] && chk ok "permisos: carpeta 700, archivos 600" || chk no "permisos"
[ "$(sql "SHOW DATABASES LIKE 'cervesur_verif_%'" | wc -l)" = 0 ] && chk ok "el esquema temporal de la prueba de restauración fue ELIMINADO" || chk no "esquema temporal residual"
[ "$(vivo)" = "$V0" ] && chk ok "los datos vivos (seis tablas + audit_log) no cambiaron durante el respaldo" || chk no "datos vivos cambiaron"

echo "== 2. Respaldo de audit_log (no incluido en el de las seis tablas) con sus triggers"
$DUMP_ROOT_CMD --single-transaction --no-tablespaces --set-gtid-purged=OFF --triggers --skip-extended-insert --complete-insert "$DB_PRUEBA" audit_log 2>/dev/null | gzip -n > "$DEST/respaldos/audit_log-$DB_PRUEBA.sql.gz"
(cd "$DEST/respaldos" && sha256sum "audit_log-$DB_PRUEBA.sql.gz" > "audit_log-$DB_PRUEBA.sql.gz.sha256" && sha256sum -c "audit_log-$DB_PRUEBA.sql.gz.sha256" --quiet) && gzip -t "$DEST/respaldos/audit_log-$DB_PRUEBA.sql.gz" && zcat "$DEST/respaldos/audit_log-$DB_PRUEBA.sql.gz" | grep -q -- '-- Dump completed' && chk ok "audit_log: volcado íntegro (gzip -t, 'Dump completed', SHA-256)" || chk no "volcado audit_log"
zcat "$DEST/respaldos/audit_log-$DB_PRUEBA.sql.gz" | grep -qi 'CREATE.*TRIGGER' && chk ok "el volcado de audit_log incluye sus triggers de solo inserción" || chk no "sin triggers en el volcado"
sql "DROP DATABASE IF EXISTS cervesur_verif_audit" >/dev/null; sql "CREATE DATABASE cervesur_verif_audit CHARACTER SET utf8mb4" >/dev/null
zcat "$DEST/respaldos/audit_log-$DB_PRUEBA.sql.gz" | $SQL_ROOT_CMD -D cervesur_verif_audit 2>/dev/null
[ "$(sql 'SELECT COUNT(*) FROM cervesur_verif_audit.audit_log')" = "$A0" ] && [ "$(sql "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='cervesur_verif_audit'")" = 2 ] && chk ok "restauración temporal de audit_log: mismas filas ($A0) y 2 triggers" || chk no "restauración audit_log"
a=$(sql "CHECKSUM TABLE cervesur_verif_audit.audit_log" | awk -F'\t' '{print $2}'); b=$(sql "CHECKSUM TABLE \`$DB_PRUEBA\`.audit_log" | awk -F'\t' '{print $2}')
[ "$a" = "$b" ] && chk ok "CHECKSUM TABLE de audit_log restaurado = vivo" || chk no "checksum audit_log ($a vs $b)"
sql "DROP DATABASE cervesur_verif_audit" >/dev/null
[ "$(sql "SHOW DATABASES LIKE 'cervesur_verif_%'" | wc -l)" = 0 ] && chk ok "esquemas temporales eliminados" || chk no "residuo"

echo "== 3. Detección de un respaldo alterado o truncado"
cp "$ARCH" "$DEST/alterado.sql.gz"; printf 'x' >> "$DEST/alterado.sql.gz"
gzip -t "$DEST/alterado.sql.gz" 2>/dev/null; r1=$?
[ "$(sha256sum "$DEST/alterado.sql.gz" | cut -c1-64)" != "$M" ] && chk ok "un byte añadido cambia el SHA-256 (se detecta contra el manifiesto)" || chk no "alteración no detectada"
head -c 200 "$ARCH" > "$DEST/truncado.sql.gz"; gzip -t "$DEST/truncado.sql.gz" 2>/dev/null; [ $? -ne 0 ] && chk ok "un respaldo truncado falla gzip -t" || chk no "truncado no detectado"
[ "$r1" -ne 0 ] || echo "   (nota: gzip tolera basura al final; por eso el SHA-256 del manifiesto es obligatorio)"
[ "$(vivo)" = "$V0" ] && [ "$(filas_audit)" = "$A0" ] && chk ok "al terminar, los datos vivos siguen idénticos (el ensayo no escribió datos de negocio)" || chk no "datos vivos cambiaron"

echo; echo "$TOTAL comprobaciones, $FALLOS fallos"; [ "$FALLOS" = 0 ] && echo "All respaldo P2 checks passed"
exit "$FALLOS"
