#!/usr/bin/env bash
# shellcheck disable=SC2034
# Cervesur · UX-08H · Migraciones · Pruebas estáticas (NO necesitan MySQL ni sudo, no escriben nada fuera de un directorio temporal):
#   · el guardia de lectura acepta SELECT/SHOW/CHECKSUM y rechaza cualquier escritura;
#   · sql_escribir se niega mientras PERMITIR_ESCRITURA=0;
#   · la lectura de la plantilla (grants_esperados) produce el conjunto de privilegios correcto;
#   · el runner valida argumentos (usuario fuera de patrón, cuenta protegida, fallo inyectado desconocido) con código 2 y SIN tocar MySQL;
#   · lib-esquema.sh y esquema-esperado.tsv son idénticos a los de e0/ cuando ambas carpetas están juntas.
# Uso: bash probar-migraciones.sh      (código de salida 0 = todo bien)
set -u
AQUI="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib-comun.sh
. "$AQUI/lib-comun.sh"
cm_preparar_comandos
PASADAS=0; FALLADAS=0
ok() { PASADAS=$((PASADAS + 1)); printf 'OK    %s\n' "$1"; }
mal() { FALLADAS=$((FALLADAS + 1)); printf 'FALLO %s\n' "$1"; }
acepta() { if printf '%s\n' "$2" | sql_guard 2>/dev/null; then ok "lectura aceptada: $1"; else mal "lectura RECHAZADA: $1"; fi; }
rechaza() { if printf '%s\n' "$2" | sql_guard 2>/dev/null; then mal "escritura ACEPTADA: $1"; else ok "rechazada: $1"; fi; }

echo "== guardia de lectura"
acepta "SELECT" "SELECT COUNT(*) FROM t"
acepta "SHOW GRANTS" "SHOW GRANTS FOR 'a'@'b'"
acepta "CHECKSUM TABLE" "CHECKSUM TABLE \`db\`.\`t\`"
acepta "SELECT con 'DELETE' en un literal" "SELECT 'DELETE FROM x' AS t"
rechaza "INSERT" "INSERT INTO t VALUES (1)"
rechaza "UPDATE" "UPDATE t SET a = 1"
rechaza "DELETE" "DELETE FROM t"
rechaza "DROP USER" "DROP USER 'a'@'b'"
rechaza "CREATE USER" "CREATE USER 'a'@'b'"
rechaza "GRANT" "GRANT SELECT ON t TO u"
rechaza "ALTER USER" "ALTER USER 'a'@'b' IDENTIFIED BY 'x'"
rechaza "SET GLOBAL" "SET GLOBAL log_bin_trust_function_creators = 1"
rechaza "dos sentencias" "SELECT 1; DROP TABLE t"
rechaza "SELECT … FOR UPDATE" "SELECT * FROM t FOR UPDATE"
rechaza "SELECT … INTO OUTFILE" "SELECT 1 INTO OUTFILE '/tmp/x'"

echo "== protección de escritura"
PERMITIR_ESCRITURA=0
if sql_escribir -e "SELECT 1" 2>/dev/null; then mal "sql_escribir corrió con PERMITIR_ESCRITURA=0"; else ok "sql_escribir se niega con PERMITIR_ESCRITURA=0 (rc=$?)"; fi

echo "== plantilla → privilegios esperados"
T="$(mktemp -d)"
cat >"$T/p.template" <<'EOF'
-- comentario con ; punto y coma
CREATE USER IF NOT EXISTS '{USER}'@'{HOST}' IDENTIFIED BY '{PASS}';
GRANT SELECT (id, nombre)
    ON `{DB}`.`empresa` TO '{USER}'@'{HOST}';
GRANT INSERT ON `{DB}`.`audit_log` TO '{USER}'@'{HOST}';
GRANT UPDATE (last_login) ON `{DB}`.`user` TO '{USER}'@'{HOST}';
EOF
printf 'C\tempresa\tid\tSELECT\nC\tempresa\tnombre\tSELECT\nC\tuser\tlast_login\tUPDATE\nT\taudit_log\t-\tINSERT\n' | sort >"$T/esp.tsv"
grants_esperados "$T/p.template" | sort >"$T/got.tsv"
cmp -s "$T/esp.tsv" "$T/got.tsv" && ok "grants_esperados interpreta columnas, tablas completas y comentarios" || { mal "grants_esperados difiere"; diff "$T/esp.tsv" "$T/got.tsv"; }
[ "$(sentencias_de_archivo "$T/p.template" | wc -l)" = "4" ] && ok "sentencias_de_archivo: 4 sentencias (comentarios ignorados, sentencia multilínea unida)" || mal "sentencias_de_archivo no devolvió 4 sentencias"
c="$(generar_clave)"; [ "${#c}" -ge 29 ] && [[ "$c" =~ [A-Z] && "$c" =~ [a-z] && "$c" =~ [0-9] && "$c" =~ [_-] ]] && ok "generar_clave: 29 caracteres con mayúscula, minúscula, dígito y símbolo" || mal "generar_clave no cumple"
[ "$(generar_clave)" != "$(generar_clave)" ] && ok "generar_clave: dos claves consecutivas distintas" || mal "generar_clave repite"

echo "== validación de argumentos del runner (no deben llegar a MySQL)"
runner() { bash "$AQUI/aplicar-migraciones.sh" "$@" >/dev/null 2>&1; echo $?; }
[ "$(runner --usuario root)" = "2" ] && ok "--usuario root → rc=2" || mal "--usuario root no fue rechazado"
[ "$(runner --usuario cervesur_portalbi_admin)" = "2" ] && ok "--usuario cervesur_portalbi_admin → rc=2" || mal "usuario protegido aceptado"
[ "$(runner --usuario "x'; DROP USER y; --")" = "2" ] && ok "--usuario con inyección → rc=2" || mal "inyección en --usuario aceptada"
[ "$(runner --db "a'b")" = "2" ] && ok "--db con comilla → rc=2" || mal "--db inválido aceptado"
[ "$(runner --host-cuenta "a b")" = "2" ] && ok "--host-cuenta con espacio → rc=2" || mal "host inválido aceptado"
[ "$(CERVESUR_INYECTAR_FALLO=otra-cosa runner --simular)" = "2" ] && ok "CERVESUR_INYECTAR_FALLO desconocido → rc=2" || mal "fallo inyectado desconocido aceptado"
[ "$(runner --opcion-inexistente)" = "2" ] && ok "opción desconocida → rc=2" || mal "opción desconocida aceptada"
[ "$(runner --ayuda)" = "0" ] && ok "--ayuda → rc=0" || mal "--ayuda falla"
# Sin MySQL alcanzable el modo --aplicar debe terminar con bloqueo (rc=10) y SIN crear bitácora ni archivos de clave
R="$T/bit"; CERVESUR_MYSQL_CMD="false" bash "$AQUI/aplicar-migraciones.sh" --aplicar --bitacora-base "$R" --respaldo /nada --confirmo-autorizacion "prueba estatica 123" >/dev/null 2>&1; rc=$?
[ "$rc" = "10" ] && ok "--aplicar sin MySQL → rc=10 (bloqueo antes de escribir)" || mal "--aplicar sin MySQL devolvió rc=$rc"
[ ! -e "$R/secretos" ] && ok "no se creó carpeta de secretos al bloquearse" || mal "se creó la carpeta de secretos pese al bloqueo"

echo "== copias compartidas con e0/"
if [ -f "$AQUI/../e0/lib-esquema.sh" ]; then
  cmp -s "$AQUI/lib-esquema.sh" "$AQUI/../e0/lib-esquema.sh" && cmp -s "$AQUI/esquema-esperado.tsv" "$AQUI/../e0/esquema-esperado.tsv" && ok "lib-esquema.sh y esquema-esperado.tsv idénticos a los de e0/" || mal "las copias compartidas difieren de e0/"
else echo "(e0/ no está junto a migraciones/: se omite)"; fi

rm -rf "$T"
echo
echo "RESUMEN pasadas=$PASADAS falladas=$FALLADAS"
[ "$FALLADAS" -eq 0 ]
