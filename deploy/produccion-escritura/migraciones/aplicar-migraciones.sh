#!/usr/bin/env bash
# shellcheck disable=SC2034
# Cervesur · UX-08H · Migraciones de la FASE 2A (M1 audit_log, M2 triggers de solo inserción, cuenta MySQL de escritura mínima).
# Lo ejecuta el DBA con sudo por PuTTY. NADIE lo ha ejecutado todavía en producción.
#
#   --simular (por omisión)  solo comprueba e imprime EXACTAMENTE qué se ejecutaría; no escribe nada (ni bitácora)
#   --aplicar                aplica M1 → M2 → cuenta; exige --respaldo <archivo.sql.gz> y --confirmo-autorizacion "<texto>"
#   --verificar              solo verificaciones posteriores (incluye una transacción de prueba que SIEMPRE termina en ROLLBACK)
#   --revertir-cuenta        DROP USER de la cuenta de escritura (no toca datos). Exige --confirmo-autorizacion
#   --revertir-auditoria     quita SOLO los triggers (M2.down). audit_log es EVIDENCIA: su tabla NO se borra salvo con
#                            --tambien-borrar-tabla-auditoria --segundo-confirmo "BORRAR LA EVIDENCIA DE AUDITORIA" (y tras volcarla a un archivo)
#
# Opciones: --db NOMBRE (cervesur_portalbi) · --host-cuenta HOST (127.0.0.1) · --usuario NOMBRE (cervesur_escritura_adm) · --rotar-clave
#           --archivo-clave RUTA (por omisión <bitacora-base>/secretos/<usuario>.clave) · --respaldo ARCHIVO · --tolerancia-tablas-cambiadas N (0)
#           --repo DIR (archivos del repositorio; por omisión ./repo) · --bitacora-base DIR (/var/backups/cervesur-react-admin) · --host-prueba HOST (127.0.0.1)
#           --ayuda
# Variables: CERVESUR_MYSQL_CMD / CERVESUR_MYSQLDUMP_CMD (por omisión «sudo mysql» / «sudo mysqldump»).
#            CERVESUR_INYECTAR_FALLO=respaldo-corrupto|sin-super|esquema-distinto|cuenta-existe  (SOLO ensayo: provoca el fallo de UN paso, jamás relaja una comprobación)
# Códigos de salida: 0 bien · 2 uso · 10 bloqueos antes de escribir · 20 fallo al aplicar (estado parcial: ver RECUPERACION.txt) · 30 verificación fallida
set -u
set -o pipefail
AQUI="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib-comun.sh
. "$AQUI/lib-comun.sh"

MODO="simular"
DB="cervesur_portalbi"
HOST_CUENTA="127.0.0.1"
USUARIO="cervesur_escritura_adm"
ARCHIVO_CLAVE=""
ROTAR=0
RESPALDO=""
CONFIRMO=""
TOLERANCIA=0
REPO="$AQUI/repo"
BITACORA_BASE="$BITACORA_BASE_DEFECTO"
HOST_PRUEBA="127.0.0.1"
BORRAR_TABLA=0
SEGUNDO=""
FALLO="${CERVESUR_INYECTAR_FALLO:-}"

ayuda() { sed -n '2,19p' "$0" | sed 's/^# \{0,1\}//'; }

while [ $# -gt 0 ]; do
  case "$1" in
    --ayuda|-h|--help) ayuda; exit 0 ;;
    --simular) MODO="simular"; shift ;;
    --aplicar) MODO="aplicar"; shift ;;
    --verificar) MODO="verificar"; shift ;;
    --revertir-cuenta) MODO="revertir-cuenta"; shift ;;
    --revertir-auditoria) MODO="revertir-auditoria"; shift ;;
    --db) DB="${2:-}"; shift 2 ;;
    --host-cuenta) HOST_CUENTA="${2:-}"; shift 2 ;;
    --usuario) USUARIO="${2:-}"; shift 2 ;;
    --archivo-clave) ARCHIVO_CLAVE="${2:-}"; shift 2 ;;
    --rotar-clave) ROTAR=1; shift ;;
    --respaldo) RESPALDO="${2:-}"; shift 2 ;;
    --confirmo-autorizacion) CONFIRMO="${2:-}"; shift 2 ;;
    --tolerancia-tablas-cambiadas) TOLERANCIA="${2:-}"; shift 2 ;;
    --repo) REPO="${2:-}"; shift 2 ;;
    --bitacora-base) BITACORA_BASE="${2:-}"; shift 2 ;;
    --host-prueba) HOST_PRUEBA="${2:-}"; shift 2 ;;
    --tambien-borrar-tabla-auditoria) BORRAR_TABLA=1; shift ;;
    --segundo-confirmo) SEGUNDO="${2:-}"; shift 2 ;;
    *) echo "Opción desconocida: $1 (use --ayuda)" >&2; exit 2 ;;
  esac
done

# ---- validación de argumentos (todo lo que acaba dentro de SQL se valida con listas cerradas) ----
[[ "$DB" =~ ^[A-Za-z0-9_]{1,64}$ ]] || { echo "--db inválido" >&2; exit 2; }
[[ "$USUARIO" =~ ^cervesur_escritura[a-z0-9_]{0,20}$ ]] || { echo "--usuario debe empezar por cervesur_escritura (solo minúsculas, dígitos y _)" >&2; exit 2; }
[[ "$HOST_CUENTA" =~ ^[A-Za-z0-9_.%:-]{1,60}$ ]] || { echo "--host-cuenta inválido" >&2; exit 2; }
[[ "$HOST_PRUEBA" =~ ^[A-Za-z0-9_.:-]{1,60}$ ]] || { echo "--host-prueba inválido" >&2; exit 2; }
[[ "$TOLERANCIA" =~ ^[0-9]{1,2}$ ]] || { echo "--tolerancia-tablas-cambiadas inválido" >&2; exit 2; }
for p in $CUENTAS_PROTEGIDAS; do [ "$USUARIO" = "$p" ] && { echo "--usuario no puede ser una cuenta protegida" >&2; exit 2; }; done
case "$FALLO" in ""|respaldo-corrupto|sin-super|esquema-distinto|cuenta-existe) ;; *) echo "CERVESUR_INYECTAR_FALLO desconocido" >&2; exit 2 ;; esac
[ -n "$ARCHIVO_CLAVE" ] || ARCHIVO_CLAVE="$BITACORA_BASE/secretos/$USUARIO.clave"
cm_preparar_comandos
umask 077
SELLO="$(date +%Y%m%d-%H%M%S)"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
CUENTA_SQL="'$USUARIO'@'$HOST_CUENTA'"
M1_UP="$REPO/UX-08B-audit-log.up.sql"; M1_DOWN="$REPO/UX-08B-audit-log.down.sql"
M2_UP="$REPO/UX-08B-audit-log-immutable.up.sql"; M2_DOWN="$REPO/UX-08B-audit-log-immutable.down.sql"
PLANTILLA="$REPO/UX-08H1-cuenta-escritura-fase2a.sql.template"

if [ "$MODO" = "simular" ] && [ -n "$FALLO" ]; then decir "(ensayo) CERVESUR_INYECTAR_FALLO=$FALLO"; fi

# ---- bitácora (solo en modos que escriben o prueban) ----
iniciar_bitacora() {
  local dir="$BITACORA_BASE/migraciones-$SELLO"
  mkdir -p "$dir" && chmod 700 "$dir" || { echo "No pude crear la bitácora $dir" >&2; exit 2; }
  LOG="$dir/bitacora.log"; : >"$LOG"; chmod 600 "$LOG"
  LOGDIR="$dir"
  solo_log "inicio modo=$MODO db=$DB cuenta=$CUENTA_SQL operador=${SUDO_USER:-$(id -un)} script_sha256=$(sha256_archivo "$0" | cut -c1-16)"
  solo_log "autorizacion=[$CONFIRMO]"
}

BLOQUEOS=0
AVISOS=0
ok() { decir "  PASS  $*"; }
aviso() { decir "  WARN  $*"; AVISOS=$((AVISOS + 1)); }
bloqueo() { decir "  FAIL  $*"; BLOQUEOS=$((BLOQUEOS + 1)); }
titulo() { decir ""; decir "== $*"; }

# ======================================================================================================================
# Comprobaciones previas (solo lectura)
# ======================================================================================================================
pre_conexion() {
  titulo "1. Conexión e identidad"
  local fila
  fila="$(leer_texto "SELECT CURRENT_USER(), @@version, @@port, @@log_bin, @@log_bin_trust_function_creators, @@read_only" 2>&1)"
  if [ "$?" -ne 0 ] || [ -z "$fila" ]; then bloqueo "no conecto a MySQL con «${MYSQL_A[*]}»: $fila"; return 1; fi
  IFS=$'\t' read -r CUR_USER SRV_VERSION SRV_PORT LOG_BIN TRUST RO <<<"$fila"
  ok "conectado como $CUR_USER · MySQL $SRV_VERSION · puerto $SRV_PORT · log_bin=$LOG_BIN · log_bin_trust_function_creators=$TRUST"
  case "$SRV_VERSION" in 8.0.*) ;; *) aviso "versión de MySQL distinta de 8.0.x ($SRV_VERSION): no ensayada" ;; esac
  [ "$RO" = "0" ] || bloqueo "MySQL está en solo lectura (@@read_only=$RO)"
  [ "$(leer_texto "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name = '$DB'")" = "1" ] && ok "la base $DB existe" || bloqueo "la base $DB no existe"
}

pre_esquema() {
  titulo "2. Esquema real contra lo que necesitan los permisos 2A y los modelos"
  leer_texto "SELECT table_name, column_name, column_type, is_nullable FROM information_schema.columns WHERE table_schema = '$DB' AND table_name IN ('user','empresa','reporte','empresa_usuario','empresa_reporte','user_empresa_reporte') ORDER BY table_name, ordinal_position" >"$TMP/real.tsv"
  cp "$AQUI/esquema-esperado.tsv" "$TMP/esperado.tsv"
  if [ "$FALLO" = "esquema-distinto" ]; then
    printf 'negocio\tempresa\t__columna_inyectada__\tvarchar(10)\tYES\t0\n' >>"$TMP/esperado.tsv"; decir "  (ensayo) esquema-distinto: se exige una columna inexistente"
  fi
  local salida rc
  salida="$(esquema_comparar "$TMP/real.tsv" "$TMP/esperado.tsv" negocio)"; rc=$?
  printf '%s\n' "$salida" | sort | while IFS= read -r l; do decir "     $l"; done
  case "$rc" in
    0) ok "el esquema coincide exactamente" ;;
    1) bloqueo "el esquema real NO coincide (MISSING/críticos): $(printf '%s\n' "$salida" | grep -E '^(FALTA|TIPO_CRITICO|NULO_CRITICO)' | tr '\n' ';' | cut -c1-300)" ;;
    *) aviso "esquema compatible con avisos: $(printf '%s\n' "$salida" | tail -1)" ;;
  esac
}

# Evalúa privilegios del que ejecuta (c): SUPER o log_bin_trust_function_creators para los triggers; CREATE, TRIGGER, CREATE USER y GRANT OPTION.
pre_privilegios() {
  titulo "3. Privilegios del DBA (triggers con log_bin) — NO se cambia ninguna variable global"
  local g tiene_super=0 falta="" p
  g="$(leer_texto "SHOW GRANTS FOR CURRENT_USER()" 2>/dev/null)"
  if grep -Eq 'ALL PRIVILEGES ON \*\.\*|[ ,]SUPER[ ,]' <<<"$g"; then tiene_super=1; fi
  local lb="$LOG_BIN" tru="$TRUST"
  if [ "$FALLO" = "sin-super" ]; then tiene_super=0; lb="1"; tru="0"; decir "  (ensayo) sin-super: se evalúa como si log_bin=1, trust=0 y sin SUPER"; fi
  p_tiene() { grep -Eiq "ALL PRIVILEGES ON (\*\.\*|\`$DB\`\.\*)|[ ,]$1(,| ON )" <<<"$g"; }
  for p in "CREATE" "TRIGGER" "CREATE USER"; do p_tiene "$p" || falta="$falta $p"; done
  grep -Eiq 'WITH GRANT OPTION' <<<"$g" || falta="$falta GRANT_OPTION"
  if [ -n "$falta" ]; then bloqueo "a la cuenta $CUR_USER le faltan privilegios:$falta"; else ok "privilegios suficientes (CREATE, TRIGGER, CREATE USER, GRANT OPTION)"; fi
  if [ "$lb" = "1" ] && [ "$tru" = "0" ] && [ "$tiene_super" = "0" ]; then
    bloqueo "log_bin=1, log_bin_trust_function_creators=0 y $CUR_USER SIN SUPER: CREATE TRIGGER fallaría con el error 1419."
    decir "        Alternativa (decisión del DBA; este script NO la ejecuta):"
    decir "          a) ejecutar el script como root del sistema (sudo mysql usa auth_socket y root tiene SUPER), o"
    decir "          b) conceder SUPER temporalmente a la cuenta del DBA, o"
    decir "          c) SET GLOBAL log_bin_trust_function_creators = 1 solo durante M2 y volver a 0 al terminar (afecta a todo el servidor; documentar)."
  elif [ "$lb" = "1" ] && [ "$tru" = "0" ]; then ok "CREATE TRIGGER permitido: la cuenta tiene SUPER"
  else ok "CREATE TRIGGER no depende de SUPER (log_bin=$lb, trust=$tru)"; fi
}

AUDIT_ESTADO="ausente"; TRIG_ESTADO="ninguno"; CUENTA_EXISTE=0
pre_estado() {
  titulo "4. Estado actual de audit_log, triggers y cuenta"
  local filas; filas="$(leer_texto "SELECT table_name, column_name, column_type, is_nullable FROM information_schema.columns WHERE table_schema = '$DB' AND table_name = 'audit_log' ORDER BY ordinal_position")"
  if [ -z "$filas" ]; then AUDIT_ESTADO="ausente"; ok "audit_log no existe: M1 la creará"
  else
    printf '%s\n' "$filas" >"$TMP/audit.tsv"
    if esquema_comparar "$TMP/audit.tsv" "$AQUI/esquema-esperado.tsv" auditoria >"$TMP/audit.cmp"; then AUDIT_ESTADO="igual"; ok "audit_log ya existe con la estructura de M1: M1 se omite"
    else AUDIT_ESTADO="distinto"; bloqueo "audit_log existe con una estructura DISTINTA de M1: $(sort "$TMP/audit.cmp" | tr '\n' ';' | cut -c1-300)"; fi
  fi
  leer_texto "SELECT trigger_name, action_timing, event_manipulation, action_statement FROM information_schema.triggers WHERE trigger_schema = '$DB' AND event_object_table = 'audit_log' ORDER BY trigger_name" >"$TMP/trg.tsv"
  local total=0 iguales=0 n m e h
  if [ -f "$M2_UP" ]; then
    while IFS='|' read -r n m e h; do
      [ -z "$n" ] && continue; total=$((total + 1))
      local fila vm ve vs; fila="$(awk -F'\t' -v n="$n" '$1 == n {print}' "$TMP/trg.tsv")"
      [ -z "$fila" ] && continue
      vm="$(printf '%s' "$fila" | cut -f2)"; ve="$(printf '%s' "$fila" | cut -f3)"; vs="$(printf '%s' "$fila" | cut -f4)"
      if [ "$vm" = "$m" ] && [ "$ve" = "$e" ] && [ "$(huella_normalizada "$vs")" = "$h" ]; then iguales=$((iguales + 1)); fi
    done < <(triggers_repo "$M2_UP")
  fi
  local vivos; vivos="$(wc -l <"$TMP/trg.tsv")"
  if [ "$vivos" -eq 0 ]; then TRIG_ESTADO="ninguno"; ok "no hay triggers sobre audit_log: M2 los creará"
  elif [ "$total" -gt 0 ] && [ "$iguales" -eq "$total" ] && [ "$vivos" -eq "$total" ]; then TRIG_ESTADO="iguales"; ok "los $total triggers de M2 ya existen idénticos: M2 se omite (idempotente)"
  else TRIG_ESTADO="distintos"; bloqueo "hay $vivos trigger/s sobre audit_log que NO son los de M2 (idénticos: $iguales de $total): no se sobrescriben"; fi
  local ex; ex="$(leer_texto "SELECT COUNT(*) FROM mysql.user WHERE user = '$USUARIO' AND host = '$HOST_CUENTA'" 2>/dev/null)"
  if [ -z "$ex" ]; then bloqueo "no puedo leer mysql.user para saber si existe $CUENTA_SQL"; ex=0; fi
  CUENTA_EXISTE="$ex"
  if [ "$FALLO" = "cuenta-existe" ]; then CUENTA_EXISTE=1; decir "  (ensayo) cuenta-existe: se evalúa como si $CUENTA_SQL ya existiera"; fi
  if [ "$CUENTA_EXISTE" = "1" ]; then
    if [ "$ROTAR" = 1 ]; then aviso "la cuenta $CUENTA_SQL ya existe: --rotar-clave cambiará SOLO su contraseña"; else bloqueo "la cuenta $CUENTA_SQL YA EXISTE; use --rotar-clave si lo que desea es rotar su contraseña (no se recrea)"; fi
  else
    [ "$ROTAR" = 1 ] && bloqueo "--rotar-clave pero la cuenta $CUENTA_SQL no existe"
    ok "la cuenta $CUENTA_SQL no existe: se creará"
  fi
  if [ -e "$ARCHIVO_CLAVE" ] && [ "$CUENTA_EXISTE" != "1" ]; then bloqueo "ya existe el archivo de clave $ARCHIVO_CLAVE sin cuenta: muévalo (puede ser de un intento anterior)"; fi
}

pre_archivos_repo() {
  titulo "5. Archivos del repositorio copiados al paquete ($REPO)"
  local f
  for f in "$M1_UP" "$M1_DOWN" "$M2_UP" "$M2_DOWN" "$PLANTILLA"; do
    if [ -f "$f" ]; then ok "$(basename "$f")  sha256=$(sha256_archivo "$f" | cut -c1-16)"; else bloqueo "falta $(basename "$f") en $REPO"; fi
  done
  if [ -f "$REPO/SHA256SUMS" ]; then
    if (cd "$REPO" && sha256sum -c --quiet SHA256SUMS >/dev/null 2>&1); then ok "SHA256SUMS del repo verifica"; else bloqueo "SHA256SUMS de $REPO NO verifica: los archivos cambiaron desde que se empaquetaron"; fi
  elif [ "$MODO" = "aplicar" ]; then bloqueo "falta $REPO/SHA256SUMS (el empaquetador debe generarlo)"; else aviso "falta $REPO/SHA256SUMS"; fi
  [ -f "$PLANTILLA" ] || return 0
  # La plantilla solo puede contener CREATE USER + GRANT SELECT/INSERT/UPDATE; nada más.
  local stmt mal=0
  while IFS= read -r stmt; do
    case "$stmt" in
      "CREATE USER IF NOT EXISTS "*) ;;
      "GRANT SELECT "*|"GRANT INSERT "*|"GRANT UPDATE "*) grep -Eqi 'WITH GRANT OPTION|ALL PRIVILEGES|DELETE|TRIGGER' <<<"$stmt" && mal=1 ;;
      *) mal=1; decir "     sentencia no admitida en la plantilla: ${stmt:0:80}" ;;
    esac
  done < <(sentencias_de_archivo "$PLANTILLA")
  [ "$mal" = 0 ] && ok "la plantilla solo contiene CREATE USER y GRANT SELECT/INSERT/UPDATE" || bloqueo "la plantilla contiene sentencias fuera de lo permitido"
  grants_esperados "$PLANTILLA" >"$TMP/esperados.tsv"
  uw="$(awk -F'\t' '$2 == "user" && (($4 == "INSERT") || ($4 == "UPDATE" && $3 != "last_login") || ($4 == "UPDATE" && $1 == "T"))' "$TMP/esperados.tsv")"; if [ -n "$uw" ]; then bloqueo "la plantilla concede INSERT/UPDATE sobre user más allá de UPDATE(last_login): no es la plantilla de la fase 2A"; else ok "la plantilla no concede INSERT sobre user; de UPDATE solo last_login"; fi
  uw="$(awk -F'\t' '$4 == "DELETE"' "$TMP/esperados.tsv")"; if [ -n "$uw" ]; then bloqueo "la plantilla concede DELETE"; fi
}

# (b) Verificación defendible del respaldo
RESPALDO_OK=0
pre_respaldo() {
  titulo "6. Respaldo verificado"
  if [ -z "$RESPALDO" ]; then
    if [ "$MODO" = "aplicar" ]; then bloqueo "--aplicar exige --respaldo <archivo.sql.gz>"; else aviso "no se indicó --respaldo (en --aplicar es obligatorio; créelo con crear-respaldo.sh)"; fi
    return 0
  fi
  local man="$RESPALDO.manifest" fallos=0
  [ -f "$RESPALDO" ] && [ -s "$RESPALDO" ] && ok "el archivo existe y no está vacío ($(wc -c <"$RESPALDO") bytes)" || { bloqueo "el respaldo $RESPALDO no existe o está vacío"; return 0; }
  [ -f "$man" ] && ok "manifiesto presente" || { bloqueo "falta el manifiesto $man (créelo con crear-respaldo.sh)"; return 0; }
  mf() { sed -n "s/^$1=//p" "$man" | head -1; }
  [ "$(mf formato)" = "cervesur-respaldo-v1" ] || { bloqueo "formato de manifiesto desconocido"; return 0; }
  [ "$(mf base)" = "$DB" ] && ok "el respaldo es de la base $DB" || { bloqueo "el respaldo es de otra base ($(mf base))"; fallos=1; }
  # integridad del archivo
  if [ "$(sha256_archivo "$RESPALDO")" = "$(mf archivo_sha256)" ]; then ok "SHA-256 del archivo = manifiesto"; else bloqueo "el SHA-256 del archivo NO coincide con el manifiesto (archivo alterado o dañado)"; fallos=1; fi
  if [ "$FALLO" = "respaldo-corrupto" ]; then
    decir "  (ensayo) respaldo-corrupto: gzip -t se ejecuta sobre los primeros 64 bytes del archivo"
    if head -c 64 "$RESPALDO" | gzip -t 2>/dev/null; then ok "gzip -t"; else bloqueo "gzip -t falla: archivo gzip corrupto o truncado"; fallos=1; fi
  elif gzip -t "$RESPALDO" 2>/dev/null; then ok "gzip -t (archivo gzip válido)"; else bloqueo "gzip -t falla: archivo gzip corrupto o truncado"; fallos=1; fi
  [ "$fallos" = 0 ] || return 0
  zcat "$RESPALDO" >"$TMP/volcado.sql" 2>/dev/null
  grep -q -- '-- Dump completed' "$TMP/volcado.sql" && ok "el volcado termina con «Dump completed» (no truncado)" || { bloqueo "el volcado no está completo"; fallos=1; }
  # contenido: una sentencia INSERT por fila (volcado --skip-extended-insert) = filas del manifiesto = filas vivas
  local t fm fv chkm chkv cambiadas=0 tabla_fila
  estado_tablas "$DB" >"$TMP/vivo.tsv" || { bloqueo "no pude leer filas/CHECKSUM vivos"; return 0; }
  for t in $TABLAS_NEGOCIO; do
    grep -q "^CREATE TABLE \`$t\` " "$TMP/volcado.sql" || { bloqueo "el volcado no contiene CREATE TABLE de $t"; fallos=1; continue; }
    fm="$(mf "tabla.$t.filas")"; chkm="$(mf "tabla.$t.checksum")"
    tabla_fila="$(awk -F'\t' -v t="$t" '$1 == t' "$TMP/vivo.tsv")"; fv="$(printf '%s' "$tabla_fila" | cut -f2)"; chkv="$(printf '%s' "$tabla_fila" | cut -f3)"
    local ni; ni="$(grep -c "^INSERT INTO \`$t\` " "$TMP/volcado.sql")"
    if [ "$ni" != "$fm" ]; then bloqueo "$t: el volcado trae $ni filas; el manifiesto dice $fm"; fallos=1; fi
    if [ "$fm" != "$fv" ] || [ "$chkm" != "$chkv" ]; then cambiadas=$((cambiadas + 1)); decir "     $t CAMBIÓ desde el respaldo: filas $fm→$fv · checksum $chkm→$chkv"; else decir "     $t: volcado=$ni filas · vivo=$fv filas · checksum idéntico"; fi
  done
  # estructura: huella fresca de mysqldump --no-data
  local est; est="$(estructura_sha256 "$DB")"
  if [ -n "$est" ] && [ "$est" = "$(mf estructura_sha256)" ]; then ok "la estructura viva (mysqldump --no-data) = la del respaldo"; else bloqueo "la estructura de las tablas CAMBIÓ desde el respaldo (o no pude calcularla)"; fallos=1; fi
  if [ "$cambiadas" -gt "$TOLERANCIA" ]; then bloqueo "$cambiadas tabla/s cambiaron desde el respaldo (tolerancia permitida: $TOLERANCIA). Cree un respaldo NUEVO con crear-respaldo.sh"; fallos=1
  else ok "ninguna tabla cambió desde el respaldo (CHECKSUM TABLE idéntico; tolerancia $TOLERANCIA)"; fi
  [ "$(mf restauracion_probada)" = "1" ] && ok "el respaldo fue probado restaurando (restauracion_probada=1)" || aviso "el respaldo NO se probó restaurando (crear-respaldo.sh --probar-restauracion)"
  [ "$fallos" = 0 ] && RESPALDO_OK=1
}

pre_confirmacion() {
  [ "$MODO" = "aplicar" ] || [ "$MODO" = "revertir-cuenta" ] || [ "$MODO" = "revertir-auditoria" ] || return 0
  titulo "7. Autorización expresa"
  local t; t="$(printf '%s' "$CONFIRMO" | sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//')"
  if [ "${#t}" -ge 10 ] && [ "$t" != "<texto>" ]; then ok "--confirmo-autorizacion presente (${#t} caracteres; se registra en la bitácora)"; else bloqueo "falta --confirmo-autorizacion \"<texto>\" (referencia de quién autorizó y cuándo; mínimo 10 caracteres)"; fi
}

# ======================================================================================================================
# Plan (lo que se ejecutaría)
# ======================================================================================================================
mostrar_sentencias() {   # mostrar_sentencias <archivo> [enmascarar]
  local s i=0
  while IFS= read -r s; do i=$((i + 1)); decir "     [$i] ${s:0:230}"; done < <(sentencias_de_archivo "$1")
}
mostrar_plan() {
  titulo "PLAN — esto es EXACTAMENTE lo que ejecutaría --aplicar (base $DB)"
  if [ "$AUDIT_ESTADO" = "ausente" ]; then decir "  M1 UX-08B-audit-log.up.sql (se ejecuta):"; mostrar_sentencias "$M1_UP"; else decir "  M1 se OMITE (audit_log ya existe idéntica)"; fi
  if [ "$TRIG_ESTADO" = "ninguno" ]; then decir "  M2 UX-08B-audit-log-immutable.up.sql (se ejecuta):"; mostrar_sentencias "$M2_UP"; else decir "  M2 se OMITE (triggers idénticos ya presentes)"; fi
  if [ "$CUENTA_EXISTE" != "1" ]; then
    decir "  CUENTA: se genera una clave aleatoria con openssl, se guarda SOLO en $ARCHIVO_CLAVE (modo 600) y se ejecutan las sentencias de la plantilla con {DB}=$DB {USER}=$USUARIO {HOST}=$HOST_CUENTA {PASS}=<CLAVE>:"
    sed -e "s/{DB}/$DB/g" -e "s/{USER}/$USUARIO/g" -e "s/{HOST}/$HOST_CUENTA/g" -e "s/{PASS}/<CLAVE>/g" "$PLANTILLA" >"$TMP/plantilla-vista.sql" 2>/dev/null
    mostrar_sentencias "$TMP/plantilla-vista.sql"
  elif [ "$ROTAR" = 1 ]; then decir "  CUENTA: ALTER USER $CUENTA_SQL IDENTIFIED BY <CLAVE nueva>; la clave nueva se guarda SOLO en $ARCHIVO_CLAVE (la anterior se renombra .anterior-<sello>)"
  else decir "  CUENTA: BLOQUEADO (ya existe)"; fi
  decir "  VERIFICACIÓN: esquema, estructura de audit_log, 2 triggers idénticos, SHOW GRANTS (sin DELETE/ALL/GRANT OPTION/TRIGGER/DDL/FILE/SUPER), conjunto EXACTO de privilegios = plantilla,"
  decir "                sin SELECT en user.password ni empresa.powerbi_*, audit_log solo SELECT+INSERT, y una sesión COMO la cuenta nueva dentro de START TRANSACTION … ROLLBACK."
  decir "  BITÁCORA: $BITACORA_BASE/migraciones-<sello>/bitacora.log (700/600) con cada sentencia y su resultado."
}

# ======================================================================================================================
# Ejecución de sentencias con registro
# ======================================================================================================================
EJECUTADAS=0
ejecutar_sentencias() {   # ejecutar_sentencias <archivo> <etiqueta> [sustituciones desde stdin no]
  local archivo="$1" etiqueta="$2" s i=0 vista salida rc total
  total="$(sentencias_de_archivo "$archivo" | wc -l)"
  while IFS= read -r s; do
    i=$((i + 1))
    vista="$(printf '%s' "$s" | sed -E "s/(IDENTIFIED BY )'[^']*'/\1<CLAVE>/")"
    solo_log "$etiqueta sentencia $i/$total: $vista"
    salida="$(printf '%s\n' "$s" | sql_escribir -D "$DB" 2>&1)"; rc=$?
    if [ "$rc" -ne 0 ]; then decir "  FALLÓ $etiqueta [$i/$total] rc=$rc: $(printf '%s' "$salida" | head -3 | tr '\n' ' ')"; return 1; fi
    decir "  ok    $etiqueta [$i/$total] ${vista:0:110}"
    EJECUTADAS=$((EJECUTADAS + 1))
  done < <(sentencias_de_archivo "$archivo")
  return 0
}

fallo_aplicando() {
  decir ""
  decir "ABORTADO durante la aplicación: $1"
  decir "ESTADO PARCIAL posible. NO reintente a ciegas: revise la bitácora ($LOG) y siga RECUPERACION.txt."
  exit 20
}

# ======================================================================================================================
# Verificación posterior (f)
# ======================================================================================================================
leer_clave_archivo() { sed -n 's/^DB_PASS=//p' "$ARCHIVO_CLAVE" | head -1; }

verificar_posterior() {
  titulo "VERIFICACIÓN POSTERIOR"
  local falla_antes=$BLOQUEOS
  # 1 · esquema de negocio y de audit_log
  leer_texto "SELECT table_name, column_name, column_type, is_nullable FROM information_schema.columns WHERE table_schema = '$DB' AND table_name IN ('user','empresa','reporte','empresa_usuario','empresa_reporte','user_empresa_reporte') ORDER BY table_name, ordinal_position" >"$TMP/real2.tsv"
  local rc_esq; esquema_comparar "$TMP/real2.tsv" "$AQUI/esquema-esperado.tsv" negocio >/dev/null; rc_esq=$?
  if [ "$rc_esq" -ne 1 ]; then ok "esquema de negocio compatible"; else bloqueo "esquema de negocio NO compatible"; fi
  leer_texto "SELECT table_name, column_name, column_type, is_nullable FROM information_schema.columns WHERE table_schema = '$DB' AND table_name = 'audit_log' ORDER BY ordinal_position" >"$TMP/audit2.tsv"
  if [ -s "$TMP/audit2.tsv" ] && esquema_comparar "$TMP/audit2.tsv" "$AQUI/esquema-esperado.tsv" auditoria >/dev/null; then ok "audit_log existe con la estructura de M1"; else bloqueo "audit_log no existe o su estructura difiere de M1"; fi
  # 2 · triggers
  leer_texto "SELECT trigger_name, action_timing, event_manipulation, action_statement FROM information_schema.triggers WHERE trigger_schema = '$DB' AND event_object_table = 'audit_log' ORDER BY trigger_name" >"$TMP/trg2.tsv"
  local vivos; vivos="$(wc -l <"$TMP/trg2.tsv")"
  [ "$vivos" = "2" ] && ok "exactamente 2 triggers sobre audit_log" || bloqueo "hay $vivos triggers sobre audit_log (se esperaban exactamente 2)"
  local n m e h fila vm ve vs
  while IFS='|' read -r n m e h; do
    [ -z "$n" ] && continue
    fila="$(awk -F'\t' -v n="$n" '$1 == n {print}' "$TMP/trg2.tsv")"
    if [ -z "$fila" ]; then bloqueo "falta el trigger $n"; continue; fi
    vm="$(printf '%s' "$fila" | cut -f2)"; ve="$(printf '%s' "$fila" | cut -f3)"; vs="$(printf '%s' "$fila" | cut -f4)"
    if [ "$vm" = "$m" ] && [ "$ve" = "$e" ] && [ "$(huella_normalizada "$vs")" = "$h" ]; then ok "trigger $n ($m $e) idéntico a M2 (huella $h)"; else bloqueo "trigger $n difiere de M2"; fi
  done < <(triggers_repo "$M2_UP")
  # 3 · la cuenta
  local ex; ex="$(leer_texto "SELECT COUNT(*) FROM mysql.user WHERE user = '$USUARIO' AND host = '$HOST_CUENTA'")"
  if [ "$ex" != "1" ]; then bloqueo "la cuenta $CUENTA_SQL no existe"; return 1; fi
  ok "la cuenta $CUENTA_SQL existe"
  local lit="'''$USUARIO''@''$HOST_CUENTA'''"
  local grants; grants="$(leer_texto "SHOW GRANTS FOR $CUENTA_SQL")"
  printf '%s\n' "$grants" | sed -E 's/IDENTIFIED BY .*//' | cut -c1-400 | while IFS= read -r l; do solo_log "GRANT> $l"; done
  # 3a · solo SELECT/INSERT/UPDATE/USAGE y sin GRANT OPTION (análisis de SHOW GRANTS)
  local malos
  malos="$(printf '%s\n' "$grants" | awk '
    { l = $0; sub(/^GRANT /, "", l); privs = l; sub(/ ON .*/, "", privs); gsub(/\([^)]*\)/, "", privs)
      n = split(privs, a, ","); for (i = 1; i <= n; i++) { p = a[i]; gsub(/^ +| +$/, "", p); if (p != "USAGE" && p != "SELECT" && p != "INSERT" && p != "UPDATE") print "privilegio:" p }
      if ($0 ~ /WITH GRANT OPTION/) print "GRANT OPTION" }' | sort -u | tr '\n' ' ')"
  if [ -z "$malos" ]; then ok "SHOW GRANTS: solo SELECT, INSERT y UPDATE (sin DELETE, ALL PRIVILEGES, GRANT OPTION, TRIGGER, DDL, FILE ni SUPER)"; else bloqueo "SHOW GRANTS contiene privilegios no permitidos: $malos"; fi
  # 3b · privilegios globales y de base: ninguno
  local g; g="$(leer_texto "SELECT privilege_type FROM information_schema.user_privileges WHERE grantee = $lit AND privilege_type <> 'USAGE'")"
  [ -z "$g" ] && ok "sin privilegios globales" || bloqueo "privilegios globales inesperados: $(printf '%s' "$g" | tr '\n' ',')"
  g="$(leer_texto "SELECT table_schema, privilege_type FROM information_schema.schema_privileges WHERE grantee = $lit")"
  [ -z "$g" ] && ok "sin privilegios a nivel de base de datos" || bloqueo "privilegios a nivel de base inesperados: $(printf '%s' "$g" | tr '\n' ',')"
  # 3c · conjunto EXACTO = plantilla
  { leer_texto "SELECT 'T', table_name, '-', privilege_type FROM information_schema.table_privileges WHERE grantee = $lit AND table_schema = '$DB'";
    leer_texto "SELECT 'C', table_name, column_name, privilege_type FROM information_schema.column_privileges WHERE grantee = $lit AND table_schema = '$DB'"; } | sort >"$TMP/vivos.tsv"
  grants_esperados "$PLANTILLA" | sort >"$TMP/esp-sort.tsv"
  if cmp -s "$TMP/vivos.tsv" "$TMP/esp-sort.tsv"; then ok "conjunto de privilegios = plantilla 2A exacta ($(wc -l <"$TMP/vivos.tsv") privilegios tabla/columna)"; else
    bloqueo "los privilegios reales difieren de la plantilla: faltan [$(comm -13 "$TMP/vivos.tsv" "$TMP/esp-sort.tsv" | tr '\t\n' ' :' | cut -c1-200)] sobran [$(comm -23 "$TMP/vivos.tsv" "$TMP/esp-sort.tsv" | tr '\t\n' ' :' | cut -c1-200)]"; fi
  # 3d · reglas de oro, de forma independiente de la plantilla
  local uw; uw="$(awk -F'\t' '$2 == "user" && ($4 == "INSERT" || $4 == "UPDATE") && !($1 == "C" && $3 == "last_login" && $4 == "UPDATE")' "$TMP/vivos.tsv")"
  [ -z "$uw" ] && ok "user: ni INSERT ni UPDATE salvo UPDATE(last_login)" || bloqueo "user tiene escritura no permitida: $(printf '%s' "$uw" | tr '\t\n' ' ,')"
  uw="$(awk -F'\t' '($2 == "user" && $4 == "SELECT" && ($1 == "T" || $3 == "password")) || ($2 == "empresa" && $4 == "SELECT" && ($1 == "T" || $3 ~ /^powerbi_/))' "$TMP/vivos.tsv")"
  [ -z "$uw" ] && ok "sin SELECT en user.password ni en empresa.powerbi_*" || bloqueo "SELECT prohibido: $(printf '%s' "$uw" | tr '\t\n' ' ,')"
  uw="$(awk -F'\t' '$2 == "audit_log" {print $1 ":" $4}' "$TMP/vivos.tsv" | sort | tr '\n' ' ')"
  [ "$uw" = "T:INSERT T:SELECT " ] && ok "audit_log: solo SELECT + INSERT (a nivel de tabla)" || bloqueo "audit_log con privilegios distintos de SELECT+INSERT: $uw"
  # 4 · pruebas transaccionales
  probar_transaccional
  [ "$BLOQUEOS" -eq "$falla_antes" ]
}

probar_transaccional() {
  titulo "Pruebas transaccionales (todo dentro de START TRANSACTION … ROLLBACK; no deben quedar filas)"
  [ -f "$ARCHIVO_CLAVE" ] || { bloqueo "no existe $ARCHIVO_CLAVE: no puedo probar como la cuenta nueva"; return 0; }
  PERMITIR_ESCRITURA=1
  estado_tablas "$DB" >"$TMP/t-antes.tsv"; local aud_antes; aud_antes="$(leer_texto "SELECT COUNT(*) FROM \`$DB\`.\`audit_log\`")"
  local corr; corr="e0prueba$(openssl rand -hex 12)"
  # (i) como administrador: los triggers rechazan UPDATE y DELETE incluso dentro de la transacción
  cat >"$TMP/sesion-admin.sql" <<EOF
START TRANSACTION;
INSERT INTO audit_log (correlation_id, actor_user_id, action, entity, entity_id) VALUES ('$corr', 0, 'prueba_migracion', 'prueba', 0);
UPDATE audit_log SET action = 'x' WHERE correlation_id = '$corr';
DELETE FROM audit_log WHERE correlation_id = '$corr';
ROLLBACK;
EOF
  local out; out="$(sql_escribir -D "$DB" --force <"$TMP/sesion-admin.sql" 2>&1)"
  solo_log "sesion admin: $(printf '%s' "$out" | tr '\n' ' ' | cut -c1-400)"
  if grep -Eq 'ERROR 1644 .* at line 3' <<<"$out" && grep -Eq 'ERROR 1644 .* at line 4' <<<"$out" && ! grep -Eq 'at line 2' <<<"$out"; then ok "el trigger rechaza UPDATE y DELETE sobre audit_log (ERROR 1644 'audit_log es de solo insercion') aun dentro de la transacción"; else bloqueo "los triggers NO rechazaron UPDATE/DELETE de audit_log: $(printf '%s' "$out" | head -3 | tr '\n' ' ')"; fi
  # (ii) COMO la cuenta nueva
  local clave cnf port
  clave="$(leer_clave_archivo)"; [ -n "$clave" ] || { bloqueo "el archivo de clave no tiene DB_PASS"; PERMITIR_ESCRITURA=0; return 0; }
  port="$SRV_PORT"
  cnf="$TMP/cuenta.cnf"; ( umask 077; printf '[client]\nuser=%s\npassword=%s\nhost=%s\nport=%s\nprotocol=TCP\n' "$USUARIO" "$clave" "$HOST_PRUEBA" "$port" >"$cnf" )
  clave=""
  cat >"$TMP/sesion-cuenta.sql" <<EOF
START TRANSACTION;
SELECT COUNT(*) FROM audit_log;
INSERT INTO audit_log (correlation_id, actor_user_id, action, entity, entity_id) VALUES ('$corr', 0, 'prueba_migracion', 'prueba', 0);
SELECT COUNT(*) FROM audit_log;
UPDATE audit_log SET action = 'x' WHERE correlation_id = '$corr';
DELETE FROM audit_log WHERE correlation_id = '$corr';
UPDATE user SET phone = 'x' WHERE id = -1;
DELETE FROM empresa WHERE id = -1;
SELECT id FROM user WHERE id = -1 FOR UPDATE;
UPDATE user SET last_login = last_login WHERE id = -1;
ROLLBACK;
SELECT COUNT(*) FROM audit_log;
EOF
  out="$("$CLIENTE_MYSQL" --defaults-extra-file="$cnf" -D "$DB" --batch --skip-column-names --force <"$TMP/sesion-cuenta.sql" 2>&1)"
  solo_log "sesion cuenta nueva: $(printf '%s' "$out" | sed -E 's/password=[^ ]*/password=<oculto>/' | tr '\n' ' ' | cut -c1-600)"
  if grep -q 'Access denied for user.*using password' <<<"$out"; then
    bloqueo "no pude conectar COMO $USUARIO desde $HOST_PRUEBA: $(printf '%s' "$out" | head -1 | cut -c1-160)"
  else
    local errs nums
    errs="$(printf '%s\n' "$out" | sed -nE 's/^ERROR ([0-9]+) .* at line ([0-9]+).*/\2:\1/p' | sort -n | tr '\n' ' ')"
    nums="$(printf '%s\n' "$out" | grep -E '^[0-9]+$' | tr '\n' ' ')"
    solo_log "errores por línea: $errs · contadores: $nums"
    local ln okl=1
    for ln in 5 6 7 8; do grep -Eq "(^| )$ln:(1142|1143|1644)( |$)" <<<"$errs" || okl=0; done
    grep -Eq '(^| )(1|2|3|4|9|10|11|12):' <<<"$errs" && okl=0
    if [ "$okl" = 1 ]; then ok "como $USUARIO: UPDATE/DELETE sobre audit_log, UPDATE user SET phone y DELETE sobre empresa fueron RECHAZADOS (errores por línea: $errs)"; else bloqueo "la cuenta nueva no se comportó como se esperaba. Errores por línea: ${errs:-ninguno}"; fi
    local c1 c2 c3; read -r c1 c2 c3 _ <<<"$nums"
    if [ -n "$c3" ] && [ "$c2" = "$((c1 + 1))" ] && [ "$c3" = "$c1" ]; then ok "INSERT en audit_log funcionó dentro de la transacción (contador $c1→$c2) y el ROLLBACK lo deshizo ($c3)"; else bloqueo "contadores inesperados de audit_log durante la prueba: ${nums:-ninguno}"; fi
  fi
  rm -f "$cnf"
  # (iii) nada cambió
  estado_tablas "$DB" >"$TMP/t-despues.tsv"
  local aud_despues; aud_despues="$(leer_texto "SELECT COUNT(*) FROM \`$DB\`.\`audit_log\`")"
  if cmp -s "$TMP/t-antes.tsv" "$TMP/t-despues.tsv" && [ "$aud_antes" = "$aud_despues" ]; then ok "tras las pruebas: filas y CHECKSUM de las seis tablas idénticos y audit_log con $aud_despues filas (sin cambios)"; else bloqueo "LAS PRUEBAS DEJARON CAMBIOS (revisar bitácora)"; fi
  PERMITIR_ESCRITURA=0
}

# ======================================================================================================================
# Modos
# ======================================================================================================================
modo_prechequeos() {
  pre_conexion || return 1
  pre_archivos_repo
  pre_esquema
  pre_privilegios
  pre_estado
  pre_respaldo
  pre_confirmacion
}

resumen_bloqueos() {
  decir ""
  if [ "$BLOQUEOS" -gt 0 ]; then decir "RESULTADO: $BLOQUEOS bloqueo/s y $AVISOS aviso/s — NO se puede aplicar."; else decir "RESULTADO: sin bloqueos ($AVISOS aviso/s)."; fi
}

crear_o_rotar_cuenta() {
  titulo "Cuenta de escritura $CUENTA_SQL"
  local clave pend dir
  clave="$(generar_clave)"
  [ "${#clave}" -ge 29 ] || fallo_aplicando "no se pudo generar la clave con openssl"
  dir="$(dirname "$ARCHIVO_CLAVE")"; mkdir -p "$dir" && chmod 700 "$dir" || fallo_aplicando "no pude crear $dir"
  pend="$ARCHIVO_CLAVE.pendiente-$SELLO"
  ( umask 077; printf '# Cervesur fase 2A · cuenta de escritura · generado %s · SOLO root · no compartir ni pegar en chats\nDB_USER=%s\nDB_PASS=%s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$USUARIO" "$clave" >"$pend" )
  chmod 600 "$pend"
  solo_log "clave generada y guardada en $pend (600); contenido NO registrado"
  if [ "$CUENTA_EXISTE" != "1" ]; then
    sed -e "s/{DB}/$DB/g" -e "s/{USER}/$USUARIO/g" -e "s/{HOST}/$HOST_CUENTA/g" -e "s/{PASS}/$clave/g" "$PLANTILLA" >"$TMP/plantilla-real.sql"
    if ! ejecutar_sentencias "$TMP/plantilla-real.sql" "cuenta"; then rm -f "$pend"; rm -f "$TMP/plantilla-real.sql"; fallo_aplicando "falló la creación de la cuenta (la clave pendiente se eliminó)"; fi
    rm -f "$TMP/plantilla-real.sql"
  else
    printf 'ALTER USER %s IDENTIFIED BY '"'%s'"';\n' "$CUENTA_SQL" "$clave" >"$TMP/rotar.sql"
    if ! ejecutar_sentencias "$TMP/rotar.sql" "rotar"; then rm -f "$pend" "$TMP/rotar.sql"; fallo_aplicando "falló la rotación de la clave"; fi
    rm -f "$TMP/rotar.sql"
    [ -e "$ARCHIVO_CLAVE" ] && mv "$ARCHIVO_CLAVE" "$ARCHIVO_CLAVE.anterior-$SELLO"
  fi
  clave=""
  mv "$pend" "$ARCHIVO_CLAVE" && chmod 600 "$ARCHIVO_CLAVE"
  decir "  clave guardada en: $ARCHIVO_CLAVE (modo $(stat -c %a "$ARCHIVO_CLAVE") dueño $(stat -c %U:%G "$ARCHIVO_CLAVE")). El contenido NO se imprime: cópielo al .env del admin como DB_PASS."
}

modo_aplicar() {
  modo_prechequeos || { resumen_bloqueos; exit 10; }
  mostrar_plan
  resumen_bloqueos
  [ "$BLOQUEOS" -eq 0 ] || exit 10
  [ "$RESPALDO_OK" = 1 ] || { decir "El respaldo no quedó verificado."; exit 10; }
  PERMITIR_ESCRITURA=1
  titulo "APLICANDO (cada sentencia queda en la bitácora)"
  decir "  tablas de negocio antes: $(estado_tablas "$DB" | awk -F'\t' '{printf "%s=%s/%s ", $1, $2, $3}')"
  if [ "$AUDIT_ESTADO" = "ausente" ]; then ejecutar_sentencias "$M1_UP" "M1" || fallo_aplicando "falló M1 (audit_log)"; else decir "  M1 omitida (ya aplicada)"; fi
  if [ "$TRIG_ESTADO" = "ninguno" ]; then ejecutar_sentencias "$M2_UP" "M2" || fallo_aplicando "falló M2 (triggers). Si fue el error 1419: ver RECUPERACION.txt, sección 5"; else decir "  M2 omitida (ya aplicada)"; fi
  crear_o_rotar_cuenta
  PERMITIR_ESCRITURA=0
  verificar_posterior || { decir "VERIFICACIÓN POSTERIOR FALLIDA: siga RECUPERACION.txt"; exit 30; }
  decir "  tablas de negocio después: $(estado_tablas "$DB" | awk -F'\t' '{printf "%s=%s/%s ", $1, $2, $3}')"
  decir ""; decir "APLICADO Y VERIFICADO. Bitácora: $LOG"
  decir "Siguiente paso (fuera de este script): poner DB_USER/DB_PASS de $ARCHIVO_CLAVE en el .env del admin 8443 y activar los interruptores de la fase 2A SIN tocar usuarios."
}

modo_verificar() {
  pre_conexion || exit 10
  pre_archivos_repo
  [ "$BLOQUEOS" -eq 0 ] || { resumen_bloqueos; exit 10; }
  verificar_posterior || { decir "VERIFICACIÓN FALLIDA"; exit 30; }
  decir ""; decir "VERIFICACIÓN OK. Bitácora: $LOG"
}

modo_revertir_cuenta() {
  pre_conexion || exit 10
  pre_confirmacion
  titulo "Reversión de la cuenta $CUENTA_SQL (DROP USER; no toca datos de negocio ni audit_log)"
  local ex; ex="$(leer_texto "SELECT COUNT(*) FROM mysql.user WHERE user = '$USUARIO' AND host = '$HOST_CUENTA'")"
  [ "$ex" = "1" ] || { decir "  la cuenta no existe: nada que revertir."; exit 0; }
  [ "$BLOQUEOS" -eq 0 ] || { resumen_bloqueos; exit 10; }
  PERMITIR_ESCRITURA=1
  printf 'DROP USER %s;\n' "$CUENTA_SQL" >"$TMP/drop.sql"
  ejecutar_sentencias "$TMP/drop.sql" "revertir-cuenta" || fallo_aplicando "DROP USER falló"
  PERMITIR_ESCRITURA=0
  [ "$(leer_texto "SELECT COUNT(*) FROM mysql.user WHERE user = '$USUARIO' AND host = '$HOST_CUENTA'")" = "0" ] && ok "la cuenta ya no existe" || bloqueo "la cuenta sigue existiendo"
  if [ -e "$ARCHIVO_CLAVE" ]; then mv "$ARCHIVO_CLAVE" "$ARCHIVO_CLAVE.revocada-$SELLO"; decir "  el archivo de clave quedó como $ARCHIVO_CLAVE.revocada-$SELLO (clave de una cuenta ya inexistente; elimínelo cuando lo desee)"; fi
  [ "$BLOQUEOS" -eq 0 ] || exit 30
  decir "REVERSIÓN DE CUENTA COMPLETA. Bitácora: $LOG"
}

modo_revertir_auditoria() {
  pre_conexion || exit 10
  pre_archivos_repo
  pre_confirmacion
  titulo "Reversión de la auditoría"
  decir "  AVISO: audit_log es EVIDENCIA. Por omisión solo se quitan los TRIGGERS (M2.down); las filas y la tabla se conservan."
  if [ "$(leer_texto "SELECT COUNT(*) FROM mysql.user WHERE user = '$USUARIO' AND host = '$HOST_CUENTA'")" = "1" ]; then bloqueo "la cuenta $CUENTA_SQL existe: quitar los triggers dejaría una cuenta de escritura sin bitácora inmutable. Ejecute antes --revertir-cuenta"; fi
  if [ "$BORRAR_TABLA" = 1 ] && [ "$SEGUNDO" != "BORRAR LA EVIDENCIA DE AUDITORIA" ]; then bloqueo "--tambien-borrar-tabla-auditoria exige --segundo-confirmo \"BORRAR LA EVIDENCIA DE AUDITORIA\" (texto exacto)"; fi
  if [ "$BORRAR_TABLA" = 0 ] && [ -n "$SEGUNDO" ]; then aviso "--segundo-confirmo sin --tambien-borrar-tabla-auditoria: no se borrará la tabla"; fi
  [ "$BLOQUEOS" -eq 0 ] || { resumen_bloqueos; exit 10; }
  PERMITIR_ESCRITURA=1
  local filas; filas="$(leer_texto "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = '$DB' AND table_name = 'audit_log'")"
  local n_antes=0; [ "$filas" = "1" ] && n_antes="$(leer_texto "SELECT COUNT(*) FROM \`$DB\`.\`audit_log\`")"
  decir "  filas de audit_log antes: $n_antes"
  ejecutar_sentencias "$M2_DOWN" "M2.down" || fallo_aplicando "M2.down falló"
  if [ "$BORRAR_TABLA" = 1 ]; then
    if [ "$filas" = "1" ]; then
      local dump="$LOGDIR/audit_log-antes-de-borrar-$SELLO.sql.gz"
      "${MYSQLDUMP_A[@]}" --single-transaction --routines=false --skip-triggers --no-tablespaces --set-gtid-purged=OFF "$DB" audit_log | gzip -n >"$dump" || fallo_aplicando "no pude volcar audit_log antes de borrarla; NO se borra"
      chmod 600 "$dump"; gzip -t "$dump" || fallo_aplicando "el volcado de audit_log es inválido; NO se borra"
      decir "  volcado de la evidencia: $dump (sha256 $(sha256_archivo "$dump" | cut -c1-16)…)"
    fi
    ejecutar_sentencias "$M1_DOWN" "M1.down" || fallo_aplicando "M1.down falló"
  fi
  PERMITIR_ESCRITURA=0
  local t; t="$(leer_texto "SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema = '$DB' AND event_object_table = 'audit_log'")"
  [ "$t" = "0" ] && ok "sin triggers sobre audit_log" || bloqueo "siguen $t triggers"
  if [ "$BORRAR_TABLA" = 0 ] && [ "$filas" = "1" ]; then
    [ "$(leer_texto "SELECT COUNT(*) FROM \`$DB\`.\`audit_log\`")" = "$n_antes" ] && ok "audit_log conserva sus $n_antes filas" || bloqueo "audit_log cambió de tamaño"
  fi
  [ "$BLOQUEOS" -eq 0 ] || exit 30
  decir "REVERSIÓN DE AUDITORÍA COMPLETA. Bitácora: $LOG"
}

# ---- despacho ----
case "$MODO" in
  simular)
    decir "== MODO SIMULAR: solo lectura; no se escribe nada (ni bitácora)"
    modo_prechequeos || { resumen_bloqueos; exit 10; }
    mostrar_plan
    resumen_bloqueos
    [ "$BLOQUEOS" -eq 0 ] || exit 10 ;;
  aplicar) iniciar_bitacora; decir "== MODO APLICAR · bitácora $LOG"; modo_aplicar ;;
  verificar) iniciar_bitacora; decir "== MODO VERIFICAR · bitácora $LOG"; modo_verificar ;;
  revertir-cuenta) iniciar_bitacora; decir "== MODO REVERTIR-CUENTA · bitácora $LOG"; modo_revertir_cuenta ;;
  revertir-auditoria) iniciar_bitacora; decir "== MODO REVERTIR-AUDITORIA · bitácora $LOG"; modo_revertir_auditoria ;;
esac
exit 0
