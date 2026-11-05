#!/usr/bin/env bash
# Cervesur · UX-08I · Prueba de scripts/privilegios-usuarios.sh contra un MySQL DESECHABLE real (nunca producción). NO va en el paquete (el empaquetador excluye «probar-*»).
# Entorno: SQL_ROOT_CMD (p. ej. «mysql -h db -uroot -pXXX»), DB_PRUEBA (base sintética con audit_log y sus 2 triggers), REPO (raíz del repositorio). Corre como root en una caja Linux desechable.
set -uo pipefail
: "${SQL_ROOT_CMD:?}" "${DB_PRUEBA:?}" "${REPO:?}"
FALLOS=0; TOTAL=0
chk() { TOTAL=$((TOTAL + 1)); if [ "$1" = ok ]; then echo "PASS $2"; else echo "FAIL $2"; FALLOS=$((FALLOS + 1)); fi; }
sql() { $SQL_ROOT_CMD -N -B -e "$1" 2>/dev/null; }

# Paquete simulado: scripts del kit + fase2b/repo con las plantillas reales y su SHA256SUMS
PAQ=$(mktemp -d); trap 'rm -rf "$PAQ"' EXIT
cp -R "$REPO/deploy/produccion-escritura/scripts" "$PAQ/scripts"
mkdir -p "$PAQ/fase2b/repo"
for f in UX-08I-alta-usuarios-vista.up UX-08I-alta-usuarios-vista.down UX-08I-usuarios-no-sensibles.up UX-08I-usuarios-no-sensibles.down UX-08I-usuarios-sensibles.up UX-08I-usuarios-sensibles.down; do cp "$REPO/database/privileges/$f.sql.template" "$PAQ/fase2b/repo/"; done
(cd "$PAQ/fase2b/repo" && sha256sum ./*.template | sed 's|  \./|  |' > SHA256SUMS)
export CERVESUR_MYSQL_ADMIN="$SQL_ROOT_CMD"
mkdir -p /opt/cervesur-react-admin/backend /var/backups/cervesur-react-admin
ENVF=/opt/cervesur-react-admin/backend/.env; printf 'CERVESUR_MODE=admin_escritura\nCERVESUR_REQUIRED_MODE=admin_escritura\n' > "$ENVF"
CUENTA=cervesur_escritura_t2b; HOST=127.0.0.1; VISTA=cervesur_vista_alta_t2b
P=(bash "$PAQ/scripts/privilegios-usuarios.sh" --cuenta "$CUENTA" --host-cuenta "$HOST" --db "$DB_PRUEBA" --cuenta-vista "$VISTA")
# la tabla `user` debe existir (la vista de alta y UPDATE(last_login) la necesitan); si la base sintética no la trae, se crea con las columnas del esquema real
sql "CREATE TABLE IF NOT EXISTS \`$DB_PRUEBA\`.\`user\` (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(100), email VARCHAR(255), first_name VARCHAR(100), last_name VARCHAR(100), phone VARCHAR(50), password VARCHAR(255), role VARCHAR(20) DEFAULT 'user', is_active TINYINT(1) DEFAULT 1, session_version INT DEFAULT 1, avatar VARCHAR(255), last_login DATETIME NULL, created_at DATETIME NULL, updated_at DATETIME NULL)" >/dev/null
sql "CREATE TABLE IF NOT EXISTS \`$DB_PRUEBA\`.empresa (id INT PRIMARY KEY)" >/dev/null
vista_columna() { sql "SELECT $1 FROM information_schema.VIEWS WHERE TABLE_SCHEMA='$DB_PRUEBA' AND TABLE_NAME='v_user_alta'"; }
VISTA_SQL="CREATE OR REPLACE DEFINER = '$VISTA'@'localhost' SQL SECURITY DEFINER VIEW \`$DB_PRUEBA\`.\`v_user_alta\` AS SELECT username, email, first_name, last_name, phone, password, role, is_active, session_version, created_at, updated_at FROM \`$DB_PRUEBA\`.\`user\`"
nueva_cuenta() {
  sql "DROP USER IF EXISTS '$CUENTA'@'$HOST'" >/dev/null
  grep -vE '^[[:space:]]*--' "$REPO/database/privileges/UX-08H1-cuenta-escritura-fase2a.sql.template" | tr '\n' ' ' | sed 's/;/;\n/g' \
    | sed -e "s/{DB}/$DB_PRUEBA/g" -e "s/{USER}/$CUENTA/g" -e "s/{HOST}/$HOST/g" -e "s/{PASS}/Pr0ba-Temporal-Aa1!x/g" | while IFS= read -r s; do [ -n "${s// /}" ] && sql "$s" >/dev/null; done
}
nueva_cuenta
RESP=$(mktemp); echo "-- respaldo ficticio" | gzip > "$RESP"
AUT="Autorizado por el DBA y el negocio (prueba)"

"${P[@]}" --etapa fase2a --verificar >/tmp/o1 2>&1; rc=$?
[ $rc = 0 ] && grep -q 'PASS fase2a' /tmp/o1 && chk ok "cuenta recién creada con la plantilla 2A = estado fase2a (nada que escribir sobre user)" || { chk no "estado fase2a (rc=$rc)"; cat /tmp/o1; }
"${P[@]}" --etapa no-sensibles --verificar >/tmp/o1 2>&1; [ $? = 30 ] && chk ok "--verificar no-sensibles falla (30) si la cuenta aún está en fase2a" || chk no "verificar no-sensibles debía fallar"
"${P[@]}" --etapa no-sensibles --simular >/tmp/o1 2>&1; rc=$?
[ $rc = 0 ] && grep -q 'GRANT INSERT ON' /tmp/o1 && grep -q 'WITH CHECK OPTION' /tmp/o1 && ! grep -qiE "identified by '|Pr0ba" /tmp/o1 && chk ok "--simular: código 0, muestra las sentencias exactas (vista con CHECK OPTION + permisos) y ninguna lleva clave" || { chk no "simular (rc=$rc)"; cat /tmp/o1; }
[ "$(sql "SHOW GRANTS FOR '$CUENTA'@'$HOST'" | grep -c 'v_user_alta')" = 0 ] && [ "$(vista_columna COUNT\(*\))" = 0 ] && [ "$(sql "SELECT COUNT(*) FROM mysql.user WHERE User='$VISTA'")" = 0 ] && chk ok "--simular no escribió nada (sin vista, sin cuenta DEFINER, sin grants nuevos)" || chk no "simular escribió"
"${P[@]}" --etapa sensibles --simular >/tmp/o1 2>&1; [ $? = 10 ] && chk ok "sensibles se bloquea (10) sin no-sensibles aplicada" || chk no "sensibles debía bloquearse"
"${P[@]}" --etapa no-sensibles --aplicar >/tmp/o1 2>&1; [ $? = 10 ] && chk ok "--aplicar sin respaldo ni autorización: bloqueado (10), sin escribir" || chk no "aplicar sin respaldo debía bloquearse"
printf 'ADMIN_WRITES_USUARIOS=1\n' >> "$ENVF"
"${P[@]}" --etapa no-sensibles --aplicar --respaldo "$RESP" --confirmo-autorizacion "$AUT" >/tmp/o1 2>&1; [ $? = 10 ] && chk ok "con ADMIN_WRITES_USUARIOS=1 en el .env no se tocan privilegios (10)" || chk no "con interruptor encendido debía bloquear"
printf 'CERVESUR_MODE=admin_escritura\nCERVESUR_REQUIRED_MODE=admin_escritura\n' > "$ENVF"

# fallo inyectado: GRANT/REVOKE no son transaccionales → el script debe informar y volver al estado previo
CERVESUR_INYECTAR_FALLO=privilegios "${P[@]}" --etapa no-sensibles --aplicar --respaldo "$RESP" --confirmo-autorizacion "$AUT" >/tmp/o1 2>&1; rc=$?
est=$("${P[@]}" --etapa fase2a --verificar 2>&1 | grep -c 'PASS fase2a')
[ $rc = 20 ] && [ "$est" = 1 ] && chk ok "fallo a medias: salida 20 y la cuenta vuelve a fase2a (estado parcial deshecho)" || { chk no "fallo inyectado (rc=$rc, fase2a=$est)"; tail -8 /tmp/o1; }

"${P[@]}" --etapa no-sensibles --aplicar --respaldo "$RESP" --confirmo-autorizacion "$AUT" >/tmp/o1 2>&1; rc=$?
[ $rc = 0 ] && grep -q 'PASS aplicar no-sensibles' /tmp/o1 && chk ok "--aplicar no-sensibles: salida 0 y verificación posterior PASS" || { chk no "aplicar no-sensibles (rc=$rc)"; tail -10 /tmp/o1; }
"${P[@]}" --etapa no-sensibles --verificar >/tmp/o1 2>&1; [ $? = 0 ] && chk ok "--verificar no-sensibles PASS" || chk no "verificar no-sensibles"
G=$(sql "SHOW GRANTS FOR '$CUENTA'@'$HOST'")
! grep -qE 'UPDATE \([^)]*`(role|password)`' <<<"$G" && ! grep -qE 'UPDATE \([^)]*`email`' <<<"$G" && grep -qF "GRANT INSERT ON \`$DB_PRUEBA\`.\`v_user_alta\`" <<<"$G" && ! grep -F "\`$DB_PRUEBA\`.\`user\`" <<<"$G" | grep -q 'INSERT' && chk ok "SHOW GRANTS real: INSERT SOLO sobre la vista (ninguno sobre user), UPDATE sin role, password ni email" || { chk no "grants no sensibles"; echo "$G"; }
[ "$(vista_columna "CONCAT_WS('|', CHECK_OPTION, IS_UPDATABLE, SECURITY_TYPE)")" = "CASCADED|YES|DEFINER" ] && [ "$(sql "SELECT account_locked FROM mysql.user WHERE User='$VISTA'")" = Y ] && chk ok "la vista es insertable, con WITH CHECK OPTION (CASCADED) y SQL SECURITY DEFINER; la cuenta DEFINER está bloqueada" || chk no "propiedades de la vista"
DG=$(sql "SHOW GRANTS FOR '$VISTA'@'localhost'")
! grep -qE '\b(UPDATE|DELETE|ALL PRIVILEGES|GRANT OPTION)\b' <<<"$DG" && grep -qE 'SELECT \(.*\), INSERT \(' <<<"$DG" && chk ok "la cuenta DEFINER tiene solo SELECT e INSERT por columnas sobre user" || { chk no "privilegios del DEFINER"; echo "$DG"; }
! grep -qE '\b(DELETE|ALL PRIVILEGES|TRIGGER|DROP|GRANT OPTION)\b' <<<"$G" && chk ok "sin DELETE, ALL, TRIGGER, DROP ni GRANT OPTION" || chk no "privilegios de más"
"${P[@]}" --etapa no-sensibles --aplicar --respaldo "$RESP" --confirmo-autorizacion "$AUT" >/tmp/o1 2>&1; [ $? = 10 ] && chk ok "reaplicar la misma etapa: bloqueado (10), idempotente por detección de estado" || chk no "reaplicar debía bloquearse"

"${P[@]}" --etapa sensibles --aplicar --respaldo "$RESP" --confirmo-autorizacion "$AUT" >/tmp/o1 2>&1; rc=$?
[ $rc = 0 ] && grep -q 'PASS aplicar sensibles' /tmp/o1 && chk ok "--aplicar sensibles (aparte) PASS" || { chk no "aplicar sensibles (rc=$rc)"; tail -8 /tmp/o1; }
sql "SHOW GRANTS FOR '$CUENTA'@'$HOST'" | grep -qE 'UPDATE \(.*`password`' && chk ok "ahora sí: UPDATE(password, role) concedido" || chk no "sensibles no concedidos"
"${P[@]}" --etapa no-sensibles --revertir --confirmo-autorizacion "$AUT" >/tmp/o1 2>&1; [ $? = 10 ] && chk ok "no se puede revertir no-sensibles estando en sensibles (orden inverso obligatorio)" || chk no "revertir en desorden debía bloquearse"
"${P[@]}" --etapa sensibles --revertir --confirmo-autorizacion "$AUT" >/tmp/o1 2>&1; [ $? = 0 ] && "${P[@]}" --etapa no-sensibles --verificar >/dev/null 2>&1 && chk ok "--revertir sensibles → vuelve a no-sensibles" || { chk no "revertir sensibles"; tail -6 /tmp/o1; }
"${P[@]}" --etapa no-sensibles --revertir --confirmo-autorizacion "$AUT" >/tmp/o1 2>&1; [ $? = 0 ] && "${P[@]}" --etapa fase2a --verificar >/dev/null 2>&1 && chk ok "--revertir no-sensibles → vuelve EXACTAMENTE a la fase 2A" || { chk no "revertir no-sensibles"; tail -6 /tmp/o1; }

[ "$(sql "SELECT COUNT(*) FROM mysql.tables_priv WHERE Db='$DB_PRUEBA' AND Table_name='v_user_alta'")" = 0 ] && [ "$(sql "SELECT COUNT(*) FROM mysql.user WHERE User='$VISTA'")" = 0 ] && [ "$(vista_columna "COUNT(*)")" = 0 ] && chk ok "la reversión no deja vista, cuenta DEFINER ni privilegios huérfanos sobre la vista" || { chk no "residuos tras revertir"; sql "SELECT Table_name,User FROM mysql.tables_priv WHERE Table_name='v_user_alta'"; sql "SELECT User FROM mysql.user WHERE User='$VISTA'"; vista_columna "COUNT(*)"; }

# --- manipulaciones: una vista o un DEFINER distintos de lo previsto deben detectarse (--verificar = 30) y poder revertirse a la fase 2A
aplicar_ns() { "${P[@]}" --etapa no-sensibles --aplicar --respaldo "$RESP" --confirmo-autorizacion "$AUT" >/tmp/o1 2>&1; }
revertir_ns() { "${P[@]}" --etapa no-sensibles --revertir --confirmo-autorizacion "$AUT" >/tmp/o1 2>&1; }
manipular() { # descripción sentencia-SQL
  local desc=$1 rc rr rf
  aplicar_ns || { chk no "($desc) no se pudo aplicar la etapa"; return; }
  sql "$2" >/dev/null
  "${P[@]}" --etapa no-sensibles --verificar >/tmp/o2 2>&1; rc=$?
  revertir_ns; rr=$?
  "${P[@]}" --etapa fase2a --verificar >/dev/null 2>&1; rf=$?
  [ $rc = 30 ] && [ $rr = 0 ] && [ $rf = 0 ] && chk ok "$desc: --verificar FALLA (30) y --revertir deja la cuenta exactamente en la fase 2A" || { chk no "$desc (verificar=$rc revertir=$rr fase2a=$rf)"; tail -4 /tmp/o2; }
}
manipular "vista SIN WITH CHECK OPTION" "$VISTA_SQL WHERE role IN ('user','usuario') AND is_active = 1 AND session_version = 1"
manipular "vista que admite role admin" "$VISTA_SQL WHERE role IN ('user','usuario','admin') AND is_active = 1 AND session_version = 1 WITH CHECK OPTION"
manipular "vista SQL SECURITY INVOKER" "$(sed 's/SQL SECURITY DEFINER/SQL SECURITY INVOKER/' <<<"$VISTA_SQL WHERE role IN ('user','usuario') AND is_active = 1 AND session_version = 1 WITH CHECK OPTION")"
manipular "vista con DEFINER root (identidad no administrada)" "$(sed "s/DEFINER = '$VISTA'@'localhost'/DEFINER = 'root'@'localhost'/" <<<"$VISTA_SQL WHERE role IN ('user','usuario') AND is_active = 1 AND session_version = 1 WITH CHECK OPTION")"
manipular "DEFINER con UPDATE(role) de más" "GRANT UPDATE (role) ON \`$DB_PRUEBA\`.\`user\` TO '$VISTA'@'localhost'"
manipular "DEFINER desbloqueado" "ALTER USER '$VISTA'@'localhost' ACCOUNT UNLOCK"
manipular "vista ausente (DROP VIEW con el INSERT aún concedido)" "DROP VIEW \`$DB_PRUEBA\`.\`v_user_alta\`"
# INSERT directo sobre user: no es un estado «parcial» de la etapa → se detecta y NO se revierte solo (exige intervención del DBA)
aplicar_ns
sql "GRANT INSERT (username) ON \`$DB_PRUEBA\`.\`user\` TO '$CUENTA'@'$HOST'" >/dev/null
"${P[@]}" --etapa no-sensibles --verificar >/tmp/o2 2>&1; rc=$?; revertir_ns; rr=$?
[ $rc = 30 ] && [ $rr = 10 ] && chk ok "INSERT directo sobre user concedido a la cuenta: --verificar FALLA (30) y --revertir se niega (10): no es un estado de la etapa" || chk no "INSERT directo (verificar=$rc revertir=$rr)"
sql "REVOKE INSERT (username) ON \`$DB_PRUEBA\`.\`user\` FROM '$CUENTA'@'$HOST'" >/dev/null; revertir_ns; "${P[@]}" --etapa fase2a --verificar >/dev/null 2>&1 && chk ok "tras quitar el INSERT directo, --revertir devuelve a la fase 2A" || chk no "reparación del INSERT directo"
# UPDATE(email) concedido a mano: también es una violación
aplicar_ns
sql "GRANT UPDATE (email) ON \`$DB_PRUEBA\`.\`user\` TO '$CUENTA'@'$HOST'" >/dev/null
"${P[@]}" --etapa no-sensibles --verificar >/tmp/o2 2>&1; [ $? = 30 ] && chk ok "UPDATE(email) concedido a mano: --verificar FALLA (30)" || chk no "UPDATE(email) debía detectarse"
sql "REVOKE UPDATE (email) ON \`$DB_PRUEBA\`.\`user\` FROM '$CUENTA'@'$HOST'" >/dev/null; revertir_ns
# fallo inyectado con la vista ya creada: la reversión automática elimina vista y DEFINER
CERVESUR_INYECTAR_FALLO=privilegios "${P[@]}" --etapa no-sensibles --aplicar --respaldo "$RESP" --confirmo-autorizacion "$AUT" >/tmp/o1 2>&1; rc=$?
"${P[@]}" --etapa fase2a --verificar >/dev/null 2>&1 && [ $rc = 20 ] && [ "$(vista_columna "COUNT(*)")" = 0 ] && chk ok "fallo a medias con la vista ya creada: salida 20 y se deshacen vista, DEFINER y grants (fase 2A)" || chk no "fallo inyectado tras crear la vista (rc=$rc)"
# una cuenta DEFINER o una vista preexistentes impiden aplicar (la identidad debe ser administrada por el kit)
sql "CREATE USER '$VISTA'@'localhost' IDENTIFIED BY 'x-Temporal-1Aa!' ACCOUNT LOCK" >/dev/null
"${P[@]}" --etapa no-sensibles --simular >/tmp/o1 2>&1; [ $? = 10 ] && chk ok "una cuenta DEFINER preexistente bloquea la etapa (10): no se adopta una identidad ajena" || chk no "DEFINER preexistente debía bloquear"
sql "DROP USER '$VISTA'@'localhost'" >/dev/null

sql "GRANT DELETE ON \`$DB_PRUEBA\`.\`empresa\` TO '$CUENTA'@'$HOST'" >/dev/null
"${P[@]}" --etapa no-sensibles --simular >/tmp/o1 2>&1; [ $? = 10 ] && grep -q 'privilegios de más' /tmp/o1 && chk ok "una cuenta con DELETE en cualquier tabla bloquea todo (10)" || { chk no "DELETE debía bloquear"; tail -12 /tmp/o1; }
nueva_cuenta
echo "-- manipulada" >> "$PAQ/fase2b/repo/UX-08I-usuarios-no-sensibles.up.sql.template"
"${P[@]}" --etapa no-sensibles --simular >/tmp/o1 2>&1; [ $? = 10 ] && chk ok "plantilla alterada: no verifica con SHA256SUMS → bloqueado (10)" || chk no "plantilla alterada debía bloquear"

sql "DROP USER IF EXISTS '$CUENTA'@'$HOST'" >/dev/null; rm -f "$RESP"
echo; echo "$TOTAL comprobaciones, $FALLOS fallos"; [ "$FALLOS" = 0 ] && echo "All privilegios 2B checks passed"
exit "$FALLOS"
