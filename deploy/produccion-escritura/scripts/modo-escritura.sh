#!/usr/bin/env bash
# Cervesur · UX-08H.1 · PASO A2 · CAMBIA el .env del administrador al modo admin_escritura con TODAS las escrituras APAGADAS. Reversible y automático si falla.
#   sudo bash scripts/modo-escritura.sh --simular        --archivo-clave ARCHIVO [--cuenta NOMBRE] [--host-cuenta HOST]   (comprueba todo y NO escribe nada)
#   sudo bash scripts/modo-escritura.sh                  --archivo-clave ARCHIVO [--cuenta NOMBRE] [--host-cuenta HOST]   (pide escribir MODO-ESCRITURA-APAGADO)
#   sudo bash scripts/modo-escritura.sh --revertir-env [RESPALDO-DE-ENV]    restaura el .env anterior a A2 (modo piloto_admin) con el código nuevo
#   Opción: --sin-credenciales  la verificación no hace login (PARCIAL, queda declarada). Credenciales por entorno: CERVESUR_E_USUARIO / CERVESUR_E_CLAVE.
# PRECONDICIONES (todas se comprueban antes de tocar nada; si una falla, no se modifica nada):
#   - A1 hecho: el código instalado es EXACTAMENTE el del paquete; el .env sigue en piloto_admin y no tiene ADMIN_WRITES_USUARIOS*, USER_ADMIN_WRITES ni CERVESUR_INSECURE_TESTS;
#   - migraciones aplicadas por el DBA (aparte): audit_log existe con sus 2 triggers de solo inserción;
#   - la cuenta de escritura de la fase 2A existe, se autentica con la clave del archivo y SU SHOW GRANTS pasa las MISMAS reglas que el backend aplica al arrancar (solo SELECT/INSERT/UPDATE
#     por tabla o columna; sin DELETE/ALL/GRANT OPTION/TRIGGER/DDL; ningún privilegio de base completa; nada de INSERT/UPDATE sobre user salvo UPDATE(last_login); audit_log solo SELECT+INSERT).
# LA CLAVE de la cuenta se lee SOLO de --archivo-clave (regular, de root, sin acceso para grupo/otros; admite CERVESUR_ESCRITURA_PASS=…, CLAVE=…, DB_PASS=… o una línea sola).
#   Nunca va en argumentos ni se imprime; llega a mysql por un archivo temporal 600 y a la edición del .env por el entorno de un proceso.
# QUÉ ESCRIBE EN EL .env (atómicamente; antes, respaldo 600 con sello en /var/backups/cervesur-react-admin/env, que jamás se borra):
#   CERVESUR_MODE = CERVESUR_REQUIRED_MODE = admin_escritura · DB_USER/DB_PASS = la cuenta de escritura · AUDIT_LOG_MODE=required · REACT_STRICT_VIEWS=1
#   ADMIN_WRITES_EMPRESAS=0 · ADMIN_WRITES_REPORTES=0 · ADMIN_WRITES_ACCESOS=0 · JWT_SECRET propio (se conserva si es válido y único; si no, se genera; se compara por huella con los .env de
#   /var/www y /opt que se puedan leer; nunca se imprime). ELIMINA ADMIN_WRITES_USUARIOS, ADMIN_WRITES_USUARIOS_SENSIBLE, USER_ADMIN_WRITES, CERVESUR_INSECURE_TESTS y
#   CERVESUR_ALLOW_WRITABLE_DB_ACCOUNT (el backend rechaza el modo con ellas sobre la base productiva). NO TOCA AUTH_DB_*, STATE_DB_*, CERVESUR_PILOT_* ni CERVESUR_PRODUCTION_DBS.
#   No reinicia ni recarga Apache (mod_php vuelve a leer el .env en cada petición).
# VERIFICA después (verificar-posdespliegue.sh --etapa A2): arranque correcto, login, capacidades todas false, escrituras 403 «escritura_desactivada», usuarios 403 «fase_2b_bloqueada»,
#   lecturas intactas, autorización del visor sin cambios. Si algo falla restaura automáticamente el .env anterior y termina con error.
# Inyección de fallos SOLO PARA ENSAYOS: CERVESUR_INYECTAR_FALLO=env-invalido|cuenta-sobreprivilegiada|verificacion (solo hace que el paso FALLE).
set -uo pipefail
case ${1:-} in --ayuda|-h|--help) sed -n '2,/^set -/{/^set -/!s/^# \{0,1\}//p}' "${BASH_SOURCE[0]}"; exit 0 ;; esac
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
requerir_root

MODO=real; ARCHIVO_CLAVE=""; CUENTA=$CUENTA_ESCRITURA_DEFECTO; HOST_CUENTA=$HOST_CUENTA_DEFECTO; SIN_CRED=0; REVERTIR_ARCHIVO=""
while [ $# -gt 0 ]; do
  case $1 in
    --simular) MODO=simular; shift ;;
    --archivo-clave) ARCHIVO_CLAVE=${2:-}; shift 2 ;;
    --cuenta) CUENTA=${2:-}; shift 2 ;;
    --host-cuenta) HOST_CUENTA=${2:-}; shift 2 ;;
    --sin-credenciales) SIN_CRED=1; shift ;;
    --revertir-env) MODO=revertir; if [ $# -ge 2 ] && [[ $2 != --* ]]; then REVERTIR_ARCHIVO=$2; shift 2; else shift; fi ;;
    *) abortar "Argumento desconocido: $1 (use --ayuda)" ;;
  esac
done
POSTVERIF=(bash "$PAQ/scripts/verificar-posdespliegue.sh")
[ "$SIN_CRED" = 0 ] || POSTVERIF+=(--sin-credenciales)

# ------------------------------------------------------------------------------------------------ --revertir-env
if [ "$MODO" = revertir ]; then
  [ -f "$ENV_FILE" ] || abortar "No existe $ENV_FILE."
  if [ -z "$REVERTIR_ARCHIVO" ]; then
    puntero="$DIR_ENV_RESPALDO/ultimo-A2-previo"
    [ -f "$puntero" ] || abortar "No hay un respaldo de A2 registrado ($puntero): indique el archivo con --revertir-env RESPALDO."
    REVERTIR_ARCHIVO="$DIR_ENV_RESPALDO/$(head -1 "$puntero")"
  fi
  case $REVERTIR_ARCHIVO in "$DIR_ENV_RESPALDO"/env-*) ;; *) abortar "El respaldo debe estar en $DIR_ENV_RESPALDO (env-*)." ;; esac
  [ -f "$REVERTIR_ARCHIVO" ] && [ ! -L "$REVERTIR_ARCHIVO" ] || abortar "No existe el respaldo $REVERTIR_ARCHIVO."
  env_respaldo_valido "$REVERTIR_ARCHIVO" || abortar "El respaldo no figura en MANIFIESTO.txt o su SHA-256 no coincide: no se usa."
  [ "$(env_valor_de "$REVERTIR_ARCHIVO" CERVESUR_MODE)" = piloto_admin ] || abortar "El respaldo no está en modo piloto_admin: no es el .env anterior a A2."
  if [ "${CERVESUR_CONFIRMAR_A2:-}" != "SI" ]; then read -r -p "Escriba REVERTIR-ENV-A2 para restaurar el .env de piloto_admin: " c; [ "$c" = "REVERTIR-ENV-A2" ] || abortar "Cancelado. No se modificó nada."; fi
  env_respaldar "antes-de-revertir-A2" || abortar "No se pudo respaldar el .env actual: no se modificó nada."
  ok "el .env actual quedó respaldado en $ENV_RESPALDO_RUTA"
  env_instalar "$REVERTIR_ARCHIVO" || abortar "No se pudo instalar el .env anterior (se conserva el actual)."
  ok ".env anterior restaurado (modo piloto_admin, 640 root:www-data)"
  if "${POSTVERIF[@]}" --etapa A1 >/dev/null 2>&1; then ok "verificación A1 correcta: el administrador volvió al comportamiento de solo lectura (código nuevo, piloto_admin)"; exit 0; fi
  fallo "El .env se restauró pero la verificación A1 FALLA: revise a mano. Respaldos conservados en $DIR_ENV_RESPALDO."; exit 1
fi

# ------------------------------------------------------------------------------------------------ preconditions
[[ $CUENTA =~ ^[A-Za-z0-9_]{1,32}$ ]] || abortar "--cuenta: nombre no válido."
[[ $HOST_CUENTA =~ ^[A-Za-z0-9_.%:-]{1,60}$ ]] || abortar "--host-cuenta: host no válido."
[ -n "$ARCHIVO_CLAVE" ] || abortar "Falta --archivo-clave (archivo de root con la clave de la cuenta de escritura; nunca por argumentos)."
tmp=$(mktemp -d) || abortar "No se pudo crear la carpeta temporal."
chmod 700 "$tmp"
trap 'rm -rf "$tmp"; api_limpiar' EXIT
CLAVE=""

identidad_paquete() {
  local lb lp
  lb=$(grep -E '^[0-9a-f]{64} [ *](./)?backend/' "$PAQ/SHA256SUMS") && lp=$(grep -E '^[0-9a-f]{64} [ *](./)?public/' "$PAQ/SHA256SUMS") || return 1
  (cd "$DIR_BACK" && sha256sum -c --quiet <<<"$lb") >/dev/null 2>&1 && (cd "$DIR_FRONT" && sha256sum -c --quiet <<<"$lp") >/dev/null 2>&1
}
cnf_cuenta() { # escribe un my.cnf 600 con la cuenta (la clave sin imprimir)
  local c; c=$(printf '%s' "$CLAVE" | sed 's/\\/\\\\/g; s/"/\\"/g')
  ( umask 077; printf '[client]\nuser=%s\npassword="%s"\nhost=127.0.0.1\n' "$CUENTA" "$c" > "$tmp/cuenta.cnf" )
}

precondiciones() {
  echo "== Precondiciones (solo lectura)"
  [ -f "$ENV_FILE" ] || abortar "No existe $ENV_FILE: el administrador no está instalado."
  [ "$(modo_env)" = piloto_admin ] || abortar "El .env no está en piloto_admin (está en «$(modo_env | cut -c1-20)»). A2 parte de A1. Si ya está en admin_escritura, use interruptores.sh o --revertir-env."
  [ -f "$PAQ/SHA256SUMS" ] && (cd "$PAQ" && sha256sum -c --quiet SHA256SUMS) >/dev/null 2>&1 || abortar "La integridad del paquete (SHA256SUMS) no se verifica."
  identidad_paquete || abortar "El código instalado NO es el de este paquete: ejecute antes actualizar.sh (A1)."
  ok "el código instalado es exactamente el del paquete (A1 hecho)"
  prohibidas=$(env_claves_prohibidas | paste -sd, -)
  [ -z "$prohibidas" ] || abortar "El .env contiene claves prohibidas ($prohibidas): la fase 2B sigue bloqueada. Corríjalas antes."
  command -v php >/dev/null 2>&1 || abortar "Se necesita php (CLI) para validar los privilegios de la cuenta con las reglas del propio backend."
  mysql_activo || abortar "MySQL no está activo."
  # --- migraciones
  [ "$(dba -D "$DB_NOMBRE" -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB_NOMBRE' AND TABLE_NAME='audit_log'")" = 1 ] || abortar "audit_log no existe: aplique antes las migraciones (las ejecuta el DBA, no este kit)."
  [ "$(dba -D "$DB_NOMBRE" -e "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='$DB_NOMBRE' AND EVENT_OBJECT_TABLE='audit_log'")" = 2 ] || abortar "audit_log no tiene exactamente sus 2 triggers de solo inserción: aplique la migración M2."
  ok "audit_log existe con sus 2 triggers de solo inserción"
  # --- cuenta
  archivo_root_privado "$ARCHIVO_CLAVE" || abortar "$ARCHIVO_CLAVE debe ser un archivo regular de root, sin acceso para grupo ni otros (chmod 600)."
  CLAVE=$(clave_de_archivo "$ARCHIVO_CLAVE"); [ -n "$CLAVE" ] || abortar "El archivo de clave no contiene la clave (CERVESUR_ESCRITURA_PASS=…, CLAVE=…, DB_PASS=… o una línea sola)."
  [ "$(dba -e "SELECT COUNT(*) FROM mysql.user WHERE user='$CUENTA' AND host='$HOST_CUENTA'")" = 1 ] || abortar "La cuenta '$CUENTA'@'$HOST_CUENTA' no existe: la crea el DBA con la plantilla UX-08H1-cuenta-escritura-fase2a."
  case $CUENTA in cervesur_portalbi_admin|cervesur_ro_*) abortar "La cuenta de escritura no puede ser la de Flutter ni una cervesur_ro_*." ;; esac
  cnf_cuenta
  mysql --defaults-extra-file="$tmp/cuenta.cnf" -N -B -e 'SELECT 1' "$DB_NOMBRE" >/dev/null 2>&1 || abortar "La cuenta '$CUENTA' no se autentica con la clave del archivo (host 127.0.0.1, base $DB_NOMBRE)."
  ok "la cuenta '$CUENTA' existe y se autentica con la clave del archivo (la clave no se muestra)"
  mysql --defaults-extra-file="$tmp/cuenta.cnf" -N -B -e 'SHOW GRANTS FOR CURRENT_USER()' "$DB_NOMBRE" > "$tmp/grants.txt" 2>/dev/null || abortar "No se pudieron leer los privilegios de la cuenta."
  if inyectar cuenta-sobreprivilegiada; then printf 'GRANT DELETE ON `%s`.`empresa` TO `%s`@`%s`\n' "$DB_NOMBRE" "$CUENTA" "$HOST_CUENTA" >> "$tmp/grants.txt"; fi
  if violaciones=$(php "$PAQ/scripts/verificar-cuenta-escritura.php" "$DIR_BACKEND" "$DB_NOMBRE" < "$tmp/grants.txt" 2>&1); then ok "SHOW GRANTS de la cuenta: cumple las reglas del backend y de la fase 2A (sin DELETE/ALL/GRANT OPTION/TRIGGER/DDL; user solo UPDATE(last_login); audit_log SELECT+INSERT)"
  else fallo "La cuenta de escritura tiene privilegios NO permitidos (el backend se negaría a arrancar):"; sed 's/^/       - /' <<<"$violaciones" >&2; abortar "Corríjala (el DBA) y repita. No se modificó nada."; fi
  if [ "$SIN_CRED" = 0 ]; then credenciales_presentes || abortar "Faltan CERVESUR_E_USUARIO / CERVESUR_E_CLAVE para la verificación con login (o use --sin-credenciales: verificación PARCIAL)."
  else aviso "--sin-credenciales: la verificación NO hará login."; fi
  bash "$PAQ/scripts/comprobar-otros-portales.sh" >/dev/null 2>&1 || abortar "Flutter (80), trabajadores (443) o la QA (8094) no responden: se detiene antes de tocar nada."
  ok "Flutter, trabajadores y QA responden"
}

# JWT_SECRET propio: se conserva si es válido (>= 32) y no coincide con ningún otro secreto de los .env legibles de /var/www y /opt; si no, se genera (nunca se imprime).
decidir_jwt() {
  # shellcheck disable=SC2034
  local actual otro f otros="$tmp/otros-secretos.txt"
  actual=$(env_valor_actual JWT_SECRET); : > "$otros"; chmod 600 "$otros"
  while IFS= read -r f; do
    [ "$f" = "$ENV_FILE" ] && continue
    [ -r "$f" ] || continue
    tr -d '\r' < "$f" | awk -F= '$1 ~ /(SECRET|JWT)/ { sub(/^[^=]*=/, ""); if (length($0) >= 16) print }' >> "$otros"
  done < <(find /var/www /opt -maxdepth 4 -name '.env' -type f 2>/dev/null)
  JWT_NUEVO=""
  if [ "${#actual}" -ge 32 ] && ! grep -qxF -- "$actual" "$otros" && [ "$actual" != "$(env_valor_actual CERVESUR_PILOT_SECRET)" ]; then JWT_ESTADO=conservado
  else JWT_NUEVO=$(generar_secreto); secreto_valido <<<"$JWT_NUEVO" || abortar "No se pudo generar un JWT_SECRET válido."; JWT_ESTADO=generado; fi
}

precondiciones
decidir_jwt
echo "== Qué se escribiría en el .env (valores no mostrados)"
echo "   CERVESUR_MODE = CERVESUR_REQUIRED_MODE = admin_escritura · DB_USER = $CUENTA · DB_PASS = (del archivo de clave) · AUDIT_LOG_MODE=required · REACT_STRICT_VIEWS=1"
echo "   ADMIN_WRITES_EMPRESAS=0 · ADMIN_WRITES_REPORTES=0 · ADMIN_WRITES_ACCESOS=0 · JWT_SECRET propio ($JWT_ESTADO) · sin claves de usuarios (fase 2B) ni CERVESUR_INSECURE_TESTS"
echo "   Se conservan AUTH_DB_*, STATE_DB_*, CERVESUR_PILOT_* y CERVESUR_PRODUCTION_DBS. Respaldo previo en $DIR_ENV_RESPALDO."
if [ "$MODO" = simular ]; then ok "SIMULACIÓN terminada: no se escribió nada. Código 0 = se puede pasar a A2."; exit 0; fi

if [ "${CERVESUR_CONFIRMAR_A2:-}" != "SI" ]; then
  read -r -p "Escriba MODO-ESCRITURA-APAGADO para continuar: " conf; [ "$conf" = "MODO-ESCRITURA-APAGADO" ] || abortar "Cancelado. No se modificó nada."
fi
bash "$PAQ/scripts/comprobar-otros-portales.sh" --guardar "$tmp/otros.txt" >/dev/null 2>&1 || abortar "No se pudo guardar la huella de los otros portales."
env_respaldar "A2-previo" || abortar "No se pudo respaldar el .env: no se modificó nada."
PREVIO=$ENV_RESPALDO_RUTA
( umask 077; basename "$PREVIO" > "$DIR_ENV_RESPALDO/ultimo-A2-previo" ) || abortar "No se pudo registrar el respaldo."
ok "respaldo del .env previo en $PREVIO (600; no se borra jamás)"
# Línea base de lo que NO debe cambiar (solo huellas): la usa verificar-posdespliegue --etapa A2
( umask 077; {
  printf 'visor-ids-huella=%s\n' "$(env_huella_valor CERVESUR_PILOT_VISOR_IDS)"
  for k in AUTH_DB_USER STATE_DB_NAME STATE_DB_USER CERVESUR_PILOT_SECRET AUTH_DB_PASS STATE_DB_PASS; do printf '%s-huella=%s\n' "$k" "$(env_huella_valor "$k")"; done
} > "$DIR_VAR/a2-linea-base.txt" ) || abortar "No se pudo guardar la línea base de A2."

restaurar() {
  fallo "A2 falló: se restaura el .env anterior (modo piloto_admin)."
  env_instalar "$PREVIO" || fallo "NO se pudo restaurar el .env automáticamente: copie a mano $PREVIO sobre $ENV_FILE (640 root:www-data)."
  if "${POSTVERIF[@]}" --etapa A1 >/dev/null 2>&1; then ok ".env anterior restaurado y comportamiento de la fase 1 verificado (A1)"; else fallo ".env anterior restaurado, pero la verificación A1 falla: revise a mano ($PREVIO)."; fi
  exit 1
}

PARES="CERVESUR_MODE=admin_escritura
CERVESUR_REQUIRED_MODE=admin_escritura
DB_USER=$CUENTA
DB_PASS=$CLAVE
AUDIT_LOG_MODE=required
REACT_STRICT_VIEWS=1
ADMIN_WRITES_EMPRESAS=0
ADMIN_WRITES_REPORTES=0
ADMIN_WRITES_ACCESOS=0"
if inyectar env-invalido; then PARES="${PARES/AUDIT_LOG_MODE=required/AUDIT_LOG_MODE=apagada}"; fi
[ -z "$JWT_NUEVO" ] || PARES="$PARES
JWT_SECRET=$JWT_NUEVO"
env_aplicar "$PARES" "ADMIN_WRITES_USUARIOS ADMIN_WRITES_USUARIOS_SENSIBLE USER_ADMIN_WRITES CERVESUR_INSECURE_TESTS CERVESUR_ALLOW_WRITABLE_DB_ACCOUNT" || { fallo "No se pudo escribir el .env nuevo."; restaurar; }
unset PARES CLAVE JWT_NUEVO
ok ".env escrito de forma atómica (modo admin_escritura, escrituras apagadas, cuenta de escritura propia, JWT $JWT_ESTADO)"

echo "== Verificación (etapa A2)"
sleep 1
if inyectar verificacion; then false; else "${POSTVERIF[@]}" --etapa A2 | grep -E '^(FAIL|AVISO|RESULTADO|OMIT|[0-9]+ PASS)'; [ "${PIPESTATUS[0]}" -eq 0 ]; fi || restaurar
ok "verificación A2 sin fallos"
bash "$PAQ/scripts/comprobar-otros-portales.sh" --comparar "$tmp/otros.txt" || { fallo "la huella de los otros portales cambió"; restaurar; }
ok "A2 COMPLETO: modo admin_escritura con todas las escrituras APAGADAS. Siguiente: Paso B con interruptores.sh (uno por uno). Para volver: sudo bash scripts/modo-escritura.sh --revertir-env"
