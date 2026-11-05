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
for f in UX-08I-usuarios-no-sensibles.up UX-08I-usuarios-no-sensibles.down UX-08I-usuarios-sensibles.up UX-08I-usuarios-sensibles.down; do cp "$REPO/database/privileges/$f.sql.template" "$PAQ/fase2b/repo/"; done
(cd "$PAQ/fase2b/repo" && sha256sum ./*.template | sed 's|  \./|  |' > SHA256SUMS)
export CERVESUR_MYSQL_ADMIN="$SQL_ROOT_CMD"
mkdir -p /opt/cervesur-react-admin/backend /var/backups/cervesur-react-admin
ENVF=/opt/cervesur-react-admin/backend/.env; printf 'CERVESUR_MODE=admin_escritura\nCERVESUR_REQUIRED_MODE=admin_escritura\n' > "$ENVF"
CUENTA=cervesur_escritura_t2b; HOST=127.0.0.1
P=(bash "$PAQ/scripts/privilegios-usuarios.sh" --cuenta "$CUENTA" --host-cuenta "$HOST" --db "$DB_PRUEBA")
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
[ $rc = 0 ] && grep -q 'GRANT INSERT' /tmp/o1 && ! grep -qi 'identified by\|Pr0ba' /tmp/o1 && chk ok "--simular: código 0, muestra las sentencias exactas y ninguna lleva clave" || { chk no "simular (rc=$rc)"; cat /tmp/o1; }
[ "$(sql "SHOW GRANTS FOR '$CUENTA'@'$HOST'" | grep -F '.`user`' | grep -c 'INSERT')" = 0 ] && chk ok "--simular no escribió nada" || chk no "simular escribió"
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
! grep -qE 'UPDATE \([^)]*`(role|password)`' <<<"$G" && grep -q 'INSERT (' <<<"$G" && chk ok "SHOW GRANTS real: INSERT por columnas y UPDATE sin role ni password" || { chk no "grants no sensibles"; echo "$G"; }
! grep -qE '\b(DELETE|ALL PRIVILEGES|TRIGGER|DROP|GRANT OPTION)\b' <<<"$G" && chk ok "sin DELETE, ALL, TRIGGER, DROP ni GRANT OPTION" || chk no "privilegios de más"
"${P[@]}" --etapa no-sensibles --aplicar --respaldo "$RESP" --confirmo-autorizacion "$AUT" >/tmp/o1 2>&1; [ $? = 10 ] && chk ok "reaplicar la misma etapa: bloqueado (10), idempotente por detección de estado" || chk no "reaplicar debía bloquearse"

"${P[@]}" --etapa sensibles --aplicar --respaldo "$RESP" --confirmo-autorizacion "$AUT" >/tmp/o1 2>&1; rc=$?
[ $rc = 0 ] && grep -q 'PASS aplicar sensibles' /tmp/o1 && chk ok "--aplicar sensibles (aparte) PASS" || { chk no "aplicar sensibles (rc=$rc)"; tail -8 /tmp/o1; }
sql "SHOW GRANTS FOR '$CUENTA'@'$HOST'" | grep -qE 'UPDATE \(.*`password`' && chk ok "ahora sí: UPDATE(password, role) concedido" || chk no "sensibles no concedidos"
"${P[@]}" --etapa no-sensibles --revertir --confirmo-autorizacion "$AUT" >/tmp/o1 2>&1; [ $? = 10 ] && chk ok "no se puede revertir no-sensibles estando en sensibles (orden inverso obligatorio)" || chk no "revertir en desorden debía bloquearse"
"${P[@]}" --etapa sensibles --revertir --confirmo-autorizacion "$AUT" >/tmp/o1 2>&1; [ $? = 0 ] && "${P[@]}" --etapa no-sensibles --verificar >/dev/null 2>&1 && chk ok "--revertir sensibles → vuelve a no-sensibles" || { chk no "revertir sensibles"; tail -6 /tmp/o1; }
"${P[@]}" --etapa no-sensibles --revertir --confirmo-autorizacion "$AUT" >/tmp/o1 2>&1; [ $? = 0 ] && "${P[@]}" --etapa fase2a --verificar >/dev/null 2>&1 && chk ok "--revertir no-sensibles → vuelve EXACTAMENTE a la fase 2A" || { chk no "revertir no-sensibles"; tail -6 /tmp/o1; }

sql "GRANT DELETE ON \`$DB_PRUEBA\`.\`empresa\` TO '$CUENTA'@'$HOST'" >/dev/null
"${P[@]}" --etapa no-sensibles --simular >/tmp/o1 2>&1; [ $? = 10 ] && grep -q 'privilegios de más' /tmp/o1 && chk ok "una cuenta con DELETE en cualquier tabla bloquea todo (10)" || chk no "DELETE debía bloquear"
nueva_cuenta
echo "-- manipulada" >> "$PAQ/fase2b/repo/UX-08I-usuarios-no-sensibles.up.sql.template"
"${P[@]}" --etapa no-sensibles --simular >/tmp/o1 2>&1; [ $? = 10 ] && chk ok "plantilla alterada: no verifica con SHA256SUMS → bloqueado (10)" || chk no "plantilla alterada debía bloquear"

sql "DROP USER IF EXISTS '$CUENTA'@'$HOST'" >/dev/null; rm -f "$RESP"
echo; echo "$TOTAL comprobaciones, $FALLOS fallos"; [ "$FALLOS" = 0 ] && echo "All privilegios 2B checks passed"
exit "$FALLOS"
