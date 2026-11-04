#!/usr/bin/env bash
# Cervesur · UX-08H · E0 · Pruebas estáticas del kit (no necesitan MySQL ni sudo):
#   1) autoverificación de solo lectura del kit (todo SQL = SELECT/SHOW/EXPLAIN/USE; ningún script con escritura);
#   2) el guardia de SQL rechaza INSERT/UPDATE/DELETE/DDL/GRANT y las formas de colarlos;
#   3) el kit se NIEGA a correr si alguien le añade una sentencia de escritura;
#   4) el sanitizador enmascara enlaces de Power BI, hashes bcrypt, JWT, claves y secretos, y no destruye el texto normal;
#   5) la comparación de esquema informa MISSING / EXTRA / tipos.
# Uso: bash probar-e0.sh      (código de salida 0 = todo bien)
set -u
AQUI="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib-e0.sh
. "$AQUI/lib-e0.sh"
e0_preparar_comandos

PASADAS=0; FALLADAS=0
ok() { PASADAS=$((PASADAS + 1)); printf 'OK    %s\n' "$1"; }
mal() { FALLADAS=$((FALLADAS + 1)); printf 'FALLO %s\n' "$1"; }
espera_rechazo() { if printf '%s\n' "$2" | sql_guard 2>/dev/null; then mal "el guardia ACEPTÓ: $1"; else ok "el guardia rechaza: $1"; fi; }
espera_acepta() { if printf '%s\n' "$2" | sql_guard 2>/dev/null; then ok "el guardia acepta: $1"; else mal "el guardia RECHAZÓ: $1"; fi; }

echo "== 1. autoverificación del kit"
if kit_autoverificar 2>/dev/null; then ok "kit_autoverificar"; else mal "kit_autoverificar"; kit_autoverificar; fi

echo "== 2. guardia de SQL"
espera_acepta "SELECT simple" "SELECT 1"
espera_acepta "SHOW GRANTS" "SHOW GRANTS FOR 'a'@'b'"
espera_acepta "EXPLAIN SELECT" "EXPLAIN SELECT id FROM t"
espera_acepta "USE" "USE cervesur_portalbi"
espera_acepta "SELECT con literal «DELETE» y «;»" "SELECT 'DELETE FROM x; DROP TABLE y' AS texto"
espera_acepta "SELECT con comentario" "SELECT 1 -- DROP TABLE x"
espera_acepta "columna updated_at y character_set_name" "SELECT updated_at, character_set_name FROM t"
espera_rechazo "INSERT" "INSERT INTO t VALUES (1)"
espera_rechazo "UPDATE" "UPDATE t SET a = 1"
espera_rechazo "DELETE" "DELETE FROM t"
espera_rechazo "REPLACE" "REPLACE INTO t VALUES (1)"
espera_rechazo "DROP" "DROP TABLE t"
espera_rechazo "CREATE" "CREATE TABLE t (a INT)"
espera_rechazo "ALTER" "ALTER TABLE t ADD c INT"
espera_rechazo "TRUNCATE" "TRUNCATE TABLE t"
espera_rechazo "GRANT" "GRANT SELECT ON t TO u"
espera_rechazo "SET GLOBAL" "SET GLOBAL log_bin_trust_function_creators = 1"
espera_rechazo "CALL" "CALL p()"
espera_rechazo "LOAD DATA" "LOAD DATA INFILE '/x' INTO TABLE t"
espera_rechazo "SELECT … INTO OUTFILE" "SELECT * FROM t INTO OUTFILE '/tmp/x'"
espera_rechazo "SELECT … FOR UPDATE" "SELECT * FROM t FOR UPDATE"
espera_rechazo "dos sentencias (SELECT; DELETE)" "SELECT 1; DELETE FROM t"
espera_rechazo "comentario que oculta la segunda sentencia" $'SELECT 1 -- x\n; DROP TABLE y'
espera_rechazo "comentario ejecutable /*! */" "SELECT 1 /*!50000 ; DROP TABLE y */"
espera_rechazo "SLEEP" "SELECT SLEEP(10)"
espera_rechazo "sentencia vacía de verbo desconocido" "FLUSH PRIVILEGES"

echo "== 3. el kit se niega a correr con una sentencia de escritura añadida"
T="$(mktemp -d)"
cp -R "$AQUI" "$T/kit"
printf '\nDELETE FROM empresa;\n' >>"$T/kit/sql/00-conexion.sql"
bash "$T/kit/e0-auditoria-fase2a.sh" --autoverificar >/dev/null 2>&1; rc=$?
[ "$rc" -eq 3 ] && ok "se niega (rc=3) con DELETE añadido a un .sql" || mal "no se negó con DELETE (rc=$rc)"
cp "$AQUI/sql/00-conexion.sql" "$T/kit/sql/00-conexion.sql"
printf '\nsudo rm -rf /tmp/x\n' >>"$T/kit/e0-auditoria-fase2a.sh"
bash "$T/kit/e0-auditoria-fase2a.sh" --autoverificar >/dev/null 2>&1; rc=$?
[ "$rc" -eq 3 ] && ok "se niega (rc=3) con «sudo rm» añadido al script" || mal "no se negó con sudo rm (rc=$rc)"
cp "$AQUI/e0-auditoria-fase2a.sh" "$T/kit/e0-auditoria-fase2a.sh"
bash "$T/kit/e0-auditoria-fase2a.sh" --autoverificar >/dev/null 2>&1; rc=$?
[ "$rc" -eq 0 ] && ok "el kit restaurado pasa la autoverificación (rc=0)" || mal "kit restaurado no pasa (rc=$rc)"
rm -rf "$T"

echo "== 4. sanitizador"
ENTRADA="$(cat <<'MUESTRA'
informe https://app.powerbi.com/view?r=eyJrIjoiYWJjZGVmZ2hpamtsbW5vcHFyc3R1dnd4eXowMTIzNDU2Nzg5IiwidCI6Ijk5In0%3D&pageName=ReportSection
enlace app.powerbi.com/reportEmbed?reportId=aaaaaaaa-0000-4000-8000-000000000001&groupId=bbbbbbbb-0000-4000-8000-000000000001
hash $2y$10$abcdefghijklmnopqrstuvABCDEFGHIJKLMNOPQRSTUVWXYZ0123456
mysql *6BB4837EB74329105EE4568DDA7DC67ED2CA2AD9 y $A$005$abcdefghijklmnopqrst0123456789ABCDEFGHIJ
jwt eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.SflKxwRJSMeKKF2QT4fwpMeJf36POk6yJV_adQssw5c
JWT_SECRET=supersecretovalue1234567890abcdefghij
DB_PASS: "hunter2hunter2"
Authorization: Bearer abcdef0123456789abcdef0123456789
CREATE USER 'x'@'y' IDENTIFIED BY 'MiClaveSecreta123';
token opaco QWxhZGRpbjpvcGVuIHNlc2FtZVRva2VuMTIzNDU2Nzg5MEFCQ0RFRg y url http://example.org/x?token=abc123def456
-----BEGIN RSA PRIVATE KEY-----
MUESTRA
)"
SALIDA="$(printf '%s\n' "$ENTRADA" | bash "$AQUI/sanear.sh")"
for secreto in "powerbi.com" "eyJrIjoi" "aaaaaaaa-0000-4000" "abcdefghijklmnopqrstuvABCDEF" "6BB4837EB74329105EE4568DDA7DC67ED2CA2AD9" '$A$005$' "eyJhbGciOiJIUzI1NiIs" "supersecretovalue" "hunter2" "abcdef0123456789abcdef0123456789" "MiClaveSecreta123" "QWxhZGRpbjpvcGVuIHNlc2FtZVRva2Vu" "abc123def456" "BEGIN RSA"; do
  if printf '%s' "$SALIDA" | grep -qF -- "$secreto"; then mal "el sanitizador dejó pasar: $secreto"; else ok "enmascarado: ${secreto:0:24}"; fi
done
for marca in "<ENLACE-ENMASCARADO>" "<HASH-BCRYPT>" "<HASH-MYSQL>" "<JWT-ENMASCARADO>" "JWT_SECRET=<oculto>" "IDENTIFIED BY <oculto>" "<TOKEN-ENMASCARADO>" "<CLAVE-PRIVADA-ENMASCARADA>"; do
  printf '%s' "$SALIDA" | grep -qF -- "$marca" && ok "marca presente: $marca" || mal "falta la marca: $marca"
done
NORMAL="$(cat <<'MUESTRA'
CERVESUR_MODE=piloto_admin
LISTEN 127.0.0.1:8093
aaaaaaaa0000400080000000000000010000000000000000000000000000000f  SHA256SUMS
validate_password.policy	MEDIUM
user_empresa_reporte	activo	tinyint(1)
/var/www/cervesur-react-admin/backend/lib/AbCdEf0123456789AbCdEf0123456789AbCd.php
MUESTRA
)"
if [ "$(printf '%s\n' "$NORMAL" | bash "$AQUI/sanear.sh")" = "$NORMAL" ]; then ok "el texto normal (modos, puertos, sha256, nombres de objetos, rutas) no se altera"; else mal "el sanitizador alteró texto normal"; printf '%s\n' "$NORMAL" | bash "$AQUI/sanear.sh"; fi

echo "== 5. comparación de esquema"
R="$(mktemp -d)"
{ printf 'empresa\tid\tint unsigned\tNO\nempresa\tnombre\tvarchar(200)\tYES\nempresa\textra_col\ttext\tYES\nempresa\truc\tvarchar(20)\tYES\n'; } >"$R/real.tsv"
printf 'negocio\tempresa\tid\tint unsigned\tNO\t1\nnegocio\tempresa\tnombre\tvarchar(100)\tYES\t0\nnegocio\tempresa\truc\tvarchar(20)\tYES\t1\nnegocio\tempresa\tcolfalta\ttext\tYES\t0\nnegocio\treporte\tid\tint unsigned\tNO\t1\n' >"$R/esp.tsv"
SAL="$(esquema_comparar "$R/real.tsv" "$R/esp.tsv" negocio)"; rc=$?
[ "$rc" -eq 1 ] && ok "rc=1 cuando falta una columna/tabla" || mal "rc esperado 1, fue $rc"
for l in "FALTA empresa.colfalta" "FALTA_TABLA reporte" "SOBRA empresa.extra_col" "TIPO empresa.nombre esperado=varchar(100) real=varchar(200)"; do
  printf '%s\n' "$SAL" | grep -qF "$l" && ok "informa: $l" || mal "no informa: $l"
done
printf 'empresa\tid\tbigint\tNO\n' >"$R/real2.tsv"; printf 'negocio\tempresa\tid\tint unsigned\tNO\t1\n' >"$R/esp2.tsv"
esquema_comparar "$R/real2.tsv" "$R/esp2.tsv" negocio | grep -q TIPO_CRITICO && ok "tipo distinto en columna crítica = TIPO_CRITICO" || mal "no detectó TIPO_CRITICO"
printf 'empresa\tid\tint unsigned\tNO\n' >"$R/real3.tsv"
esquema_comparar "$R/real3.tsv" "$R/esp2.tsv" negocio >/dev/null; [ "$?" -eq 0 ] && ok "rc=0 cuando coincide" || mal "rc esperado 0"
rm -rf "$R"
if [ -f "$AQUI/../migraciones/lib-esquema.sh" ]; then
  cmp -s "$AQUI/lib-esquema.sh" "$AQUI/../migraciones/lib-esquema.sh" && cmp -s "$AQUI/esquema-esperado.tsv" "$AQUI/../migraciones/esquema-esperado.tsv" && ok "lib-esquema.sh y esquema-esperado.tsv idénticos en e0/ y migraciones/" || mal "las copias de lib-esquema.sh / esquema-esperado.tsv difieren entre e0/ y migraciones/"
fi

echo
echo "RESUMEN pasadas=$PASADAS falladas=$FALLADAS"
[ "$FALLADAS" -eq 0 ]
