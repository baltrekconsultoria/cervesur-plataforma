#!/usr/bin/env bash
# Cervesur · UX-08H · E0 · Inspección de SOLO LECTURA del servidor antes de la fase 2A (escrituras de empresas, informes y accesos).
#
# QUÉ HACE:  lee y mide. No cambia archivos del servidor, permisos, servicios, cuentas ni datos. Solo escribe SU PROPIA evidencia en la carpeta que usted elija.
# QUÉ NO HACE: no imprime valores de .env (solo nombres de claves y una lista blanca de modos), contraseñas, hashes, tokens ni enlaces de Power BI;
#              no envía peticiones HTTP; no ejecuta INSERT/UPDATE/DELETE/DDL/GRANT (el guardia de SQL y la autoverificación lo impiden).
# CÓMO:      sudo bash e0-auditoria-fase2a.sh --esperado-sha 3f859210c5c4 [--paquete /ruta/al/backend-candidato] [--evidencia DIR]
# VARIABLES: CERVESUR_MYSQL_CMD  orden para abrir MySQL (por omisión «sudo mysql»; como root, «mysql»). Permite ensayar contra un MySQL en Docker.
# Cada archivo de evidencia termina en una línea EXIT_CODE=n (0 = la recolección terminó; no significa «todo bien»: eso lo dice 99-resumen-E0.txt).
set -u

AQUI="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib-e0.sh
. "$AQUI/lib-e0.sh"

DB="cervesur_portalbi"
ESPERADO_SHA=""
PAQUETE=""
UMBRAL_MB=1024
ADMIN_WEB="/var/www/cervesur-react-admin"
ADMIN_APP="/opt/cervesur-react-admin"
ENV_ARCHIVO=""
FLUTTER_DIR="/var/www/cervesur/backend"
BACKUPS_DIR="/var/backups"
CUENTAS_ESCRITURA_ESPERADAS="cervesur_portalbi_admin,root"
CUENTA_PLANEADA="cervesur_escritura_adm"
EVID=""
SOLO_AUTOVERIFICAR=0

ayuda() {
  cat <<'AYUDA'
E0 · inspección de SOLO LECTURA previa a la fase 2A.

Uso:  sudo bash e0-auditoria-fase2a.sh [opciones]

  --esperado-sha PREFIJO   identidad esperada del paquete instalado en 8443 (12 hex, p. ej. 3f859210c5c4); se compara con el prefijo del
                           sha256 de SHA256SUMS y con el contenido de VERSION
  --evidencia DIR          carpeta de evidencia (por omisión ~/cervesur-qa-evidencia/<fecha>-E0, en el HOME del usuario que invocó sudo)
  --db NOMBRE              base de negocio (por omisión cervesur_portalbi)
  --paquete DIR            backend del paquete candidato (opcional): se comprueba con «php -l» archivo por archivo
  --umbral-mb N            espacio mínimo libre en /var/backups para decir GO (por omisión 1024)
  --admin-web DIR          carpeta pública del admin 8443 (por omisión /var/www/cervesur-react-admin)
  --admin-app DIR          carpeta de la aplicación del admin 8443 (por omisión /opt/cervesur-react-admin)
  --env ARCHIVO            .env del backend admin (por omisión se busca bajo --admin-app)
  --flutter DIR            backend de Flutter (por omisión /var/www/cervesur/backend)
  --respaldos DIR          carpeta de respaldos (por omisión /var/backups)
  --cuentas-escritura-esperadas LISTA   cuentas MySQL que HOY pueden escribir (coma; por omisión cervesur_portalbi_admin,root)
  --cuenta-planeada NOMBRE nombre de la futura cuenta de escritura (por omisión cervesur_escritura_adm)
  --autoverificar          solo ejecuta la autoverificación de solo lectura del kit y termina
  --ayuda                  esta ayuda

Variable: CERVESUR_MYSQL_CMD (orden para abrir MySQL; por omisión «sudo mysql»).
Resultado: archivos NN-*.txt (cada uno termina en EXIT_CODE=) y 99-resumen-E0.txt con PASS/WARN/FAIL/NO-GO por ítem y una línea RESULTADO.
AYUDA
}

while [ $# -gt 0 ]; do
  case "$1" in
    --ayuda|-h|--help) ayuda; exit 0 ;;
    --esperado-sha) ESPERADO_SHA="${2:-}"; shift 2 ;;
    --evidencia) EVID="${2:-}"; shift 2 ;;
    --db) DB="${2:-}"; shift 2 ;;
    --paquete) PAQUETE="${2:-}"; shift 2 ;;
    --umbral-mb) UMBRAL_MB="${2:-}"; shift 2 ;;
    --admin-web) ADMIN_WEB="${2:-}"; shift 2 ;;
    --admin-app) ADMIN_APP="${2:-}"; shift 2 ;;
    --env) ENV_ARCHIVO="${2:-}"; shift 2 ;;
    --flutter) FLUTTER_DIR="${2:-}"; shift 2 ;;
    --respaldos) BACKUPS_DIR="${2:-}"; shift 2 ;;
    --cuentas-escritura-esperadas) CUENTAS_ESCRITURA_ESPERADAS="${2:-}"; shift 2 ;;
    --cuenta-planeada) CUENTA_PLANEADA="${2:-}"; shift 2 ;;
    --autoverificar) SOLO_AUTOVERIFICAR=1; shift ;;
    *) echo "Opción desconocida: $1 (use --ayuda)" >&2; exit 2 ;;
  esac
done

[[ "$DB" =~ ^[A-Za-z0-9_]{1,64}$ ]] || { echo "--db inválido" >&2; exit 2; }
[[ "$UMBRAL_MB" =~ ^[0-9]{1,9}$ ]] || { echo "--umbral-mb inválido" >&2; exit 2; }
[[ -z "$ESPERADO_SHA" || "$ESPERADO_SHA" =~ ^[0-9a-fA-F]{6,64}$ ]] || { echo "--esperado-sha inválido (hexadecimal)" >&2; exit 2; }
[[ "$CUENTA_PLANEADA" =~ ^[A-Za-z0-9_]{1,32}$ ]] || { echo "--cuenta-planeada inválida" >&2; exit 2; }
[[ "$CUENTAS_ESCRITURA_ESPERADAS" =~ ^[A-Za-z0-9_,]*$ ]] || { echo "--cuentas-escritura-esperadas inválido" >&2; exit 2; }

e0_preparar_comandos

echo "E0: autoverificación de solo lectura del kit..."
if ! kit_autoverificar; then echo "E0: el kit NO pasa la autoverificación de solo lectura. NO SE EJECUTA NADA." >&2; exit 3; fi
echo "E0: autoverificación OK (todo SQL es SELECT/SHOW/EXPLAIN/USE)."
[ "$SOLO_AUTOVERIFICAR" = 1 ] && exit 0

if [ -z "$EVID" ]; then
  _usuario="${SUDO_USER:-${USER:-$(id -un)}}"
  _home="$(getent passwd "$_usuario" 2>/dev/null | cut -d: -f6)"
  EVID="${_home:-$HOME}/cervesur-qa-evidencia/$(date +%Y-%m-%d)-E0"
fi
umask 077
mkdir -p "$EVID" || { echo "No pude crear $EVID" >&2; exit 2; }   # E0-ESCRIBE-EVIDENCIA
TMP="$(mktemp -d)"                                                    # E0-ESCRIBE-EVIDENCIA
RES_FILE="$TMP/resultados.txt"
: >"$RES_FILE"
trap 'rm -rf "$TMP"' EXIT                                             # E0-ESCRIBE-EVIDENCIA
echo "E0: evidencia en $EVID"

# ============================================================================================================================
item_00() {
  sec "Contexto"
  date -u +%Y-%m-%dT%H:%M:%SZ
  echo "usuario=$(id -un) uid=$(id -u) host=$(hostname)"
  echo "base=$DB esperado_sha=${ESPERADO_SHA:-<no indicado>} paquete=${PAQUETE:-<no indicado>} umbral_mb=$UMBRAL_MB"
  echo "mysql_cmd=${MYSQL_A[0]} (argumentos ocultos)"
  sec "Huellas del kit (sha256 de cada archivo, para saber qué versión del kit corrió)"
  (cd "$AQUI" && find . -type f \( -name '*.sh' -o -name '*.sql' -o -name '*.tsv' -o -name '*.sed' -o -name '*.awk' -o -name '*.regex' \) | sort | while read -r f; do printf '%s  %s\n' "$(sha256sum "$f" | cut -c1-16)" "$f"; done)
  sec "Conexión a MySQL (SELECT 1)"
  sql_archivo 00-conexion.sql; echo "rc=$?"
  sql_archivo 00-existe-base.sql | sed 's/^/bases_con_ese_nombre=/'
  sql_archivo 08-usuario-actual.sql | sed 's/^/cuenta_actual=/'
  if sql_archivo 00-conexion.sql >/dev/null 2>&1; then res 0 PASS "conexión a MySQL OK"; else res 0 FAIL "no conecta a MySQL con «${MYSQL_A[*]}»"; fi
  if [ "$(sql_archivo 00-existe-base.sql 2>/dev/null)" = "1" ]; then res 0 PASS "la base $DB existe"; else res 0 FAIL "la base $DB no existe o no es visible"; fi
}

# ---------- 1 · Estado del admin 8443 ---------------------------------------------------------------------------------------
buscar_env() {
  if [ -n "$ENV_ARCHIVO" ]; then echo "$ENV_ARCHIVO"; return; fi
  for c in "$ADMIN_APP/backend/.env" "$ADMIN_APP/.env"; do [ -e "$c" ] && { echo "$c"; return; }; done
  find "$ADMIN_APP" -maxdepth 3 -name .env -not -path '*/node_modules/*' 2>/dev/null | head -1
}
item_01() {
  sec "Apache: sitios y puertos (solo directivas de estructura; los SetEnv no se muestran)"
  if command -v apachectl >/dev/null 2>&1; then sdo apachectl -S 2>&1 | head -60; else echo "(apachectl no disponible)"; fi
  sdo grep -rnEs '^[[:space:]]*(Listen|<VirtualHost|ServerName|DocumentRoot|ProxyPass|ProxyPassReverse|Alias)[[:space:]]' /etc/apache2/ports.conf /etc/apache2/sites-enabled/ 2>&1 | cut -c1-200
  sec "Puertos en escucha (80, 443, 3306, 8093, 8094, 8443)"
  local escucha=""
  if command -v ss >/dev/null 2>&1; then escucha="$(sdo ss -ltnH 2>/dev/null | awk '{print $4}')"; elif command -v netstat >/dev/null 2>&1; then escucha="$(sdo netstat -ltn 2>/dev/null | awk 'NR>2 {print $4}')"; fi
  if [ -z "$escucha" ]; then echo "(ni ss ni netstat disponibles)"; res 1 WARN "no pude listar puertos"; else
    printf '%s\n' "$escucha" | grep -E ':(80|443|3306|8093|8094|8443)$' | sort -u
    if printf '%s\n' "$escucha" | grep -Eq ':8443$'; then res 1 PASS "8443 escucha"; else res 1 FAIL "8443 NO escucha"; fi
    if printf '%s\n' "$escucha" | grep -Eq '^(127\.0\.0\.1|\[::1\]):8093$'; then
      if printf '%s\n' "$escucha" | grep -Eq '^(0\.0\.0\.0|\*|\[::\]):8093$'; then res 1 FAIL "8093 escucha también en todas las interfaces"; else res 1 PASS "8093 escucha solo en loopback"; fi
    elif printf '%s\n' "$escucha" | grep -Eq ':8093$'; then res 1 FAIL "8093 escucha fuera de loopback"; else res 1 FAIL "8093 NO escucha (backend del admin caído)"; fi
    if printf '%s\n' "$escucha" | grep -Eq '^(0\.0\.0\.0|\*|\[::\]):3306$'; then res 1 WARN "MySQL escucha en todas las interfaces"; fi
    printf '%s\n' "$escucha" | grep -Eq ':8094$' && echo "(8094 = QA; no se toca)"
  fi
  sec "Identidad del paquete instalado"
  local d f p coincide=0 hallados=0
  for d in "$ADMIN_WEB" "$ADMIN_APP"; do
    if [ ! -d "$d" ]; then echo "(no existe $d)"; continue; fi
    sdo ls -ld "$d"
    while IFS= read -r f; do
      [ -z "$f" ] && continue
      hallados=$((hallados + 1))
      case "$f" in
        */VERSION) echo "$f:"; sdo head -n 5 "$f" | cut -c1-120
          if [ -n "$ESPERADO_SHA" ] && sdo grep -qiF "$ESPERADO_SHA" "$f"; then coincide=1; echo "  (VERSION contiene $ESPERADO_SHA)"; fi ;;
        */SHA256SUMS) p="$(sdo sha256sum "$f" | cut -c1-12)"; echo "$f: sha256[0:12]=$p líneas=$(sdo wc -l <"$f")"
          if [ -n "$ESPERADO_SHA" ] && [ "${p:0:${#ESPERADO_SHA}}" = "${ESPERADO_SHA,,}" ]; then coincide=1; echo "  (coincide con el esperado)"; fi ;;
      esac
    done < <(find "$d" -maxdepth 3 \( -name VERSION -o -name SHA256SUMS \) 2>/dev/null | sort)
  done
  if [ "$hallados" -eq 0 ]; then res 1 FAIL "no hay VERSION ni SHA256SUMS en el admin instalado"
  elif [ -z "$ESPERADO_SHA" ]; then res 1 WARN "no se indicó --esperado-sha: identidad no comparada"
  elif [ "$coincide" = 1 ]; then res 1 PASS "identidad del paquete = $ESPERADO_SHA"
  else res 1 FAIL "la identidad instalada NO coincide con $ESPERADO_SHA"; fi
  sec ".env del backend admin (modo, nombres de claves y lista blanca de valores no secretos)"
  local envf; envf="$(buscar_env)"
  if [ -z "$envf" ] || [ ! -e "$envf" ]; then echo "(no se encontró .env)"; res 1 WARN "no se encontró el .env del admin"; return 0; fi
  echo "archivo: $envf"
  sdo stat -c 'modo=%a dueño=%U:%G' "$envf"
  echo "claves definidas (solo nombres):"; env_nombres "$envf" | sort
  echo "valores permitidos:"; env_valores_permitidos "$envf" | tr -d '"'"'" | sort
  local nombres vals; nombres="$(env_nombres "$envf")"; vals="$(env_valores_permitidos "$envf" | tr -d '"'"'")"
  if printf '%s\n' "$nombres" | grep -qx 'USER_ADMIN_WRITES'; then res 1 FAIL "USER_ADMIN_WRITES está definida (debe estar ausente)"; else res 1 PASS "USER_ADMIN_WRITES ausente"; fi
  if printf '%s\n' "$vals" | grep -Eq '^ADMIN_WRITES_[A-Z_]+=1$'; then res 1 WARN "hay ADMIN_WRITES_*=1 ANTES de la fase 2A"; else res 1 PASS "ningún ADMIN_WRITES_*=1 hoy"; fi
  local modo req; modo="$(printf '%s\n' "$vals" | sed -n 's/^CERVESUR_MODE=//p' | head -1)"; req="$(printf '%s\n' "$vals" | sed -n 's/^CERVESUR_REQUIRED_MODE=//p' | head -1)"
  echo "CERVESUR_MODE=${modo:-<ausente>} CERVESUR_REQUIRED_MODE=${req:-<ausente>}"
  if [ "$modo" = "piloto_admin" ] && { [ -z "$req" ] || [ "$req" = "piloto_admin" ]; }; then res 1 PASS "modo actual piloto_admin"; else res 1 WARN "modo actual no es piloto_admin (modo=${modo:-?} requerido=${req:-?})"; fi
}

# ---------- 2 · Versión instalada ----------------------------------------------------------------------------------------------
item_02() {
  sec "VERSION del admin instalado"
  local d f n=0
  for d in "$ADMIN_WEB" "$ADMIN_APP"; do
    [ -d "$d" ] || continue
    while IFS= read -r f; do [ -n "$f" ] && { echo "$f:"; sdo head -n 8 "$f" | cut -c1-120; n=$((n + 1)); }; done < <(find "$d" -maxdepth 3 -name VERSION 2>/dev/null | sort)
  done
  [ "$n" -gt 0 ] && res 2 PASS "VERSION leída ($n archivo/s)" || res 2 WARN "no se encontró VERSION del admin"
  sec "Software del servidor"
  . /etc/os-release 2>/dev/null && echo "SO: ${PRETTY_NAME:-?}"; uname -sr
  command -v php >/dev/null 2>&1 && php -v | head -1
  command -v apache2 >/dev/null 2>&1 && apache2 -v 2>/dev/null | head -1
  sql_texto "SELECT @@version, @@version_comment"
  if command -v dpkg-query >/dev/null 2>&1; then dpkg-query -W -f='${Package} ${Version}\n' 'apache2' 'php*-cli' 'mysql-server*' 2>/dev/null | head -10; fi
}

# ---------- 3 · Esquema -------------------------------------------------------------------------------------------------------
item_03() {
  sec "Columnas (tabla, columna, tipo, nulable, default, charset, collation, extra)"
  sql_archivo 03-columnas.sql >"$TMP/real.tsv"; local rc=$?
  cat "$TMP/real.tsv"
  [ "$rc" -ne 0 ] && { res 3 FAIL "no pude leer information_schema.columns (rc=$rc)"; return 0; }
  sec "Tablas (motor, collation, formato de fila, filas aprox.)"
  sql_archivo 03-tablas.sql >"$TMP/tablas.tsv"; cat "$TMP/tablas.tsv"
  if awk -F'\t' '$1 != "audit_log" && $2 != "InnoDB" {f=1} END {exit !f}' "$TMP/tablas.tsv"; then res 3 WARN "alguna tabla de negocio no es InnoDB"; else res 3 PASS "tablas de negocio en InnoDB"; fi
  sec "Comparación con las columnas que necesitan los permisos 2A y los modelos (esquema-esperado.tsv, grupo negocio)"
  local salida; salida="$(esquema_comparar "$TMP/real.tsv" "$AQUI/esquema-esperado.tsv" negocio)"; rc=$?
  printf '%s\n' "$salida" | sort
  case "$rc" in
    0) res 3 PASS "el esquema coincide con lo esperado (sin MISSING ni EXTRA)" ;;
    1) res 3 FAIL "esquema NO compatible: $(printf '%s\n' "$salida" | grep -c -E '^(FALTA|TIPO_CRITICO|NULO_CRITICO)') hallazgo/s MISSING/críticos" ;;
    *) res 3 WARN "esquema compatible con avisos (tipos no críticos distintos o columnas EXTRA): $(printf '%s\n' "$salida" | tail -1)" ;;
  esac
}

# ---------- 4 · Datos: conteos y duplicados ------------------------------------------------------------------------------------
item_04() {
  sec "Conteos (tabla, total, activos, inactivos, nulos)"
  sql_archivo 04-conteos.sql
  sec "Claves naturales: duplicados (solo cantidades; sin valores)"
  sql_archivo 04-duplicados.sql >"$TMP/dup.tsv"; cat "$TMP/dup.tsv"
  sec "Huellas SHA-256[0:8] de grupos duplicados (clave, huella, veces)"
  sql_archivo 04-duplicados-huellas.sql
  local d
  d="$(awk -F'\t' '/grupos duplicados/ {s += $2} END {print s + 0}' "$TMP/dup.tsv")"
  if [ -s "$TMP/dup.tsv" ]; then
    [ "$d" -gt 0 ] && res 4 WARN "hay $d grupo/s duplicado/s en ruc, reporte_id o enlace (revisar antes de depender de unicidad)" || res 4 PASS "sin duplicados en ruc, reporte_id ni enlace"
    d="$(awk -F'\t' '/repetidos/ {s += $2} END {print s + 0}' "$TMP/dup.tsv")"
    [ "$d" -gt 0 ] && res 4 WARN "hay $d par/es repetido/s en tablas de relación" || res 4 PASS "sin pares repetidos en las tablas de relación"
  else res 4 FAIL "no pude leer las tablas empresa/reporte/asignaciones"; fi
}

# ---------- 5 · Claves foráneas e índices ---------------------------------------------------------------------------------------
item_05() {
  sec "Claves foráneas (tabla, restricción, columna, tabla ref., columna ref., ON DELETE, ON UPDATE)"
  sql_archivo 05-claves-foraneas.sql >"$TMP/fk.tsv"; cat "$TMP/fk.tsv"
  sec "Índices (tabla, índice, único?, columnas)"
  sql_archivo 05-indices.sql >"$TMP/idx.tsv"; cat "$TMP/idx.tsv"
  [ -s "$TMP/idx.tsv" ] || { res 5 FAIL "no pude leer los índices"; return 0; }
  if [ -s "$TMP/fk.tsv" ]; then res 5 PASS "$(wc -l <"$TMP/fk.tsv") columna/s con clave foránea; reglas ON DELETE: $(cut -f6 "$TMP/fk.tsv" | sort | uniq -c | tr '\n' ' ')"; else res 5 WARN "no hay claves foráneas en las seis tablas"; fi
  local par
  for par in "empresa:ruc" "reporte:reporte_id" "reporte:enlace"; do
    if awk -F'\t' -v t="${par%%:*}" -v c="${par##*:}" '$1 == t && $3 == "UNICO" && $4 == c {f=1} END {exit !f}' "$TMP/idx.tsv"; then res 5 PASS "índice ÚNICO sobre ${par/:/.}"; else res 5 WARN "sin índice ÚNICO sobre ${par/:/.} (la unicidad solo la hace cumplir la aplicación)"; fi
  done
}

# ---------- 6 · audit_log ---------------------------------------------------------------------------------------------------------
triggers_repo() {   # nombre|momento|evento|huella  según la migración inmutable del repositorio
  local f="$AQUI/repo/UX-08B-audit-log-immutable.up.sql" linea
  [ -f "$f" ] || return 1
  grep -Ei '^[[:space:]]*CREATE[[:space:]]+TRIGGER' "$f" | while IFS= read -r linea; do
    local n m e s
    n="$(printf '%s' "$linea" | sed -E 's/^[[:space:]]*CREATE[[:space:]]+TRIGGER[[:space:]]+([A-Za-z0-9_]+).*/\1/I')"
    m="$(printf '%s' "$linea" | sed -E 's/.*TRIGGER[[:space:]]+[A-Za-z0-9_]+[[:space:]]+(BEFORE|AFTER).*/\1/I' | tr 'a-z' 'A-Z')"
    e="$(printf '%s' "$linea" | sed -E 's/.*(BEFORE|AFTER)[[:space:]]+(INSERT|UPDATE|DELETE).*/\2/I' | tr 'a-z' 'A-Z')"
    s="$(printf '%s' "$linea" | sed -E 's/.*FOR[[:space:]]+EACH[[:space:]]+ROW[[:space:]]+//I')"
    printf '%s|%s|%s|%s\n' "$n" "$m" "$e" "$(huella_normalizada "$s")"
  done
}
item_06() {
  sec "audit_log: columnas"
  sql_archivo 06-audit-tabla.sql >"$TMP/audit.tsv"; cat "$TMP/audit.tsv"
  sec "Triggers de la base (nombre, tabla, momento, evento, definidor, definición)"
  sql_archivo 06-audit-triggers.sql >"$TMP/trg.tsv"; cut -f1-5,6 "$TMP/trg.tsv"
  local existe=0 ok_cols=1
  [ -s "$TMP/audit.tsv" ] && existe=1
  if [ "$existe" = 0 ]; then
    echo "audit_log NO existe (esperado antes de la migración M1)."
    if [ -s "$TMP/trg.tsv" ] && awk -F'\t' '$2 == "audit_log" {f=1} END {exit !f}' "$TMP/trg.tsv"; then res 6 FAIL "hay triggers sobre audit_log pero no la tabla"; else res 6 PASS "audit_log no existe todavía (M1/M2 por aplicar)"; fi
    return 0
  fi
  sec "audit_log: SHOW CREATE TABLE"
  sql_texto "SHOW CREATE TABLE \`$DB\`.\`audit_log\`" | cut -c1-1500
  local salida rc; salida="$(esquema_comparar "$TMP/audit.tsv" "$AQUI/esquema-esperado.tsv" auditoria)"; rc=$?
  printf '%s\n' "$salida" | sort
  [ "$rc" -ne 0 ] && ok_cols=0
  [ "$ok_cols" = 1 ] && res 6 PASS "audit_log existe con la estructura de M1" || res 6 FAIL "audit_log existe pero su estructura difiere de M1"
  sec "Triggers de audit_log: definición normalizada (sha256[0:12]) contra la migración del repositorio"
  local esp; esp="$(triggers_repo)" || { echo "(falta repo/UX-08B-audit-log-immutable.up.sql: copiarlo al empaquetar)"; res 6 WARN "sin archivo de referencia de M2 para comparar triggers"; return 0; }
  local vivo; vivo="$(awk -F'\t' '$2 == "audit_log" {print $1}' "$TMP/trg.tsv" | sort | tr '\n' ' ')"
  echo "triggers vivos sobre audit_log: ${vivo:-<ninguno>}"
  local linea n m e h ok=0 total=0
  while IFS='|' read -r n m e h; do
    [ -z "$n" ] && continue
    total=$((total + 1))
    local fila; fila="$(awk -F'\t' -v n="$n" '$1 == n && $2 == "audit_log" {print}' "$TMP/trg.tsv")"
    if [ -z "$fila" ]; then echo "$n: AUSENTE (repo $m $e huella $h)"; continue; fi
    local vm ve vs vh; vm="$(printf '%s' "$fila" | cut -f3)"; ve="$(printf '%s' "$fila" | cut -f4)"; vs="$(printf '%s' "$fila" | cut -f6)"; vh="$(huella_normalizada "$vs")"
    if [ "$vm" = "$m" ] && [ "$ve" = "$e" ] && [ "$vh" = "$h" ]; then echo "$n: COINCIDE ($m $e huella $h)"; ok=$((ok + 1)); else echo "$n: DIFERENTE (vivo $vm $ve $vh; repo $m $e $h)"; fi
  done <<<"$esp"
  linea="$(awk -F'\t' '$2 == "audit_log"' "$TMP/trg.tsv" | wc -l)"
  if [ "$total" -gt 0 ] && [ "$ok" -eq "$total" ] && [ "$linea" -eq "$total" ]; then res 6 PASS "los $total triggers de audit_log coinciden con M2"
  elif [ "$ok" -eq 0 ] && [ "$linea" -eq 0 ]; then res 6 WARN "audit_log existe SIN triggers (M2 pendiente)"
  else res 6 FAIL "triggers de audit_log distintos de M2 (coinciden $ok de $total; vivos $linea)"; fi
}

# ---------- 7 · Privilegios ----------------------------------------------------------------------------------------------------
item_07() {
  sec "Cuentas MySQL (usuario, host, plugin, bloqueada, clave caducada) — sin hashes"
  sql_archivo 07-cuentas.sql >"$TMP/cuentas.tsv"; cat "$TMP/cuentas.tsv"
  [ -s "$TMP/cuentas.tsv" ] || { res 7 FAIL "no pude leer mysql.user (¿falta sudo/SUPER?)"; return 0; }
  sec "SHOW GRANTS por cuenta"
  local u h
  while IFS=$'\t' read -r u h _; do
    if [[ "$u" =~ ^[A-Za-z0-9_.-]+$ && "$h" =~ ^[A-Za-z0-9_.%:-]+$ ]]; then
      echo "## '$u'@'$h'"; sql_texto "SHOW GRANTS FOR '$u'@'$h'" 2>&1 | cut -c1-600
    else echo "## cuenta con nombre inusual omitida (revisar a mano)"; fi
  done <"$TMP/cuentas.tsv"
  sec "Privilegios de escritura / administración sobre la base y las seis tablas (grantee, nivel, objeto, privilegio)"
  sql_archivo 07-privilegios-escritura.sql >"$TMP/priv.tsv"; cat "$TMP/priv.tsv"
  local esperadas=",$CUENTAS_ESCRITURA_ESPERADAS," malas="" g usr
  while IFS=$'\t' read -r g nivel _ priv; do
    usr="$(printf '%s' "$g" | sed -E "s/^'([^']*)'.*/\1/")"
    case "$priv" in INSERT|UPDATE|DELETE) ;; *) continue ;; esac
    case "$esperadas" in *",$usr,"*) continue ;; esac
    malas="$malas $usr($nivel,$priv)"
  done <"$TMP/priv.tsv"
  if [ -n "$malas" ]; then res 7 FAIL "cuentas con escritura sobre las tablas que NO están en la lista esperada:$malas"; else res 7 PASS "solo escriben las cuentas esperadas ($CUENTAS_ESCRITURA_ESPERADAS)"; fi
  if awk -F'\t' '{print $1}' "$TMP/priv.tsv" | grep -E "^'(cervesur_portalbi_admin)'" >/dev/null 2>&1; then res 7 INFO "la cuenta amplia de Flutter (cervesur_portalbi_admin) escribe: es lo esperado hoy"; fi
  sec "¿Existe ya una cuenta con el nombre de la futura cuenta de escritura?"
  if awk -F'\t' -v c="$CUENTA_PLANEADA" '$1 == c || index($1, "cervesur_escritura") == 1 {print $1 "@" $2; f=1} END {exit !f}' "$TMP/cuentas.tsv"; then res 7 WARN "ya existe una cuenta tipo $CUENTA_PLANEADA (la migración exigirá --rotar-clave)"; else echo "(ninguna)"; res 7 PASS "no existe cuenta llamada como la planeada ($CUENTA_PLANEADA)"; fi
}

# ---------- 8 · Configuración de MySQL ----------------------------------------------------------------------------------------
item_08() {
  sec "Variables (nombre, valor)"
  sql_archivo 08-variables.sql >"$TMP/vars.tsv"; cat "$TMP/vars.tsv"
  sec "validate_password"
  sql_archivo 08-validate-password.sql; sql_archivo 08-componentes.sql | sed 's/^/componente /'
  sec "Privilegios de la cuenta actual"
  sql_archivo 08-grants-actual.sql >"$TMP/grants.txt"; cat "$TMP/grants.txt"
  v() { awk -F'\t' -v k="$1" '$1 == k {print $2}' "$TMP/vars.tsv"; }
  local logbin trust super=0 ver
  logbin="$(v log_bin)"; trust="$(v log_bin_trust_function_creators)"; ver="$(v version)"
  [ -s "$TMP/vars.tsv" ] || { res 8 FAIL "no pude leer las variables de MySQL"; return 0; }
  grep -Eq 'SUPER|ALL PRIVILEGES ON \*\.\*' "$TMP/grants.txt" && super=1
  echo "resumen: version=$ver log_bin=$logbin trust_function_creators=$trust super=$super binlog_format=$(v binlog_format) tz=$(v time_zone)/$(v system_time_zone) isolation=$(v transaction_isolation) lock_wait=$(v innodb_lock_wait_timeout) max_conn=$(v max_connections) charset=$(v character_set_server)"
  case "$ver" in 8.0.*) res 8 PASS "MySQL $ver" ;; *) res 8 WARN "versión de MySQL distinta de 8.0.x: $ver" ;; esac
  if [ "$logbin" = "ON" ] && [ "$trust" = "OFF" ] && [ "$super" = 0 ]; then
    res 8 NO-GO "log_bin=ON, log_bin_trust_function_creators=OFF y la cuenta actual NO tiene SUPER: crear los triggers fallaría (error 1419). Use root (sudo mysql) o la alternativa documentada en RECUPERACION.txt"
  elif [ "$logbin" = "ON" ] && [ "$trust" = "OFF" ]; then res 8 PASS "crear triggers es posible: la cuenta actual tiene SUPER (log_bin_trust_function_creators=OFF no estorba)"
  else res 8 PASS "crear triggers no depende de SUPER (log_bin=$logbin, trust=$trust)"; fi
  if awk -F'\t' '/validate_password/ {f=1} END {exit !f}' <(sql_archivo 08-validate-password.sql 2>/dev/null); then res 8 INFO "validate_password activo: la clave generada (mayúscula, minúscula, dígito y símbolo, 29 car.) debe cumplir la política"; else res 8 INFO "validate_password no instalado"; fi
  [ "$(v read_only)" = "ON" ] && res 8 WARN "MySQL está en read_only=ON: las escrituras fallarán"
  [ "$(v transaction_isolation)" != "REPEATABLE-READ" ] && res 8 INFO "aislamiento distinto de REPEATABLE-READ: $(v transaction_isolation)"
}

# ---------- 9 · Integridad y permisos de carpetas ----------------------------------------------------------------------------
item_09() {
  local d f envf fallos=0 hallados=0
  for d in "$ADMIN_WEB" "$ADMIN_APP"; do
    sec "Carpeta $d"
    if [ ! -d "$d" ]; then echo "(no existe)"; res 9 FAIL "no existe $d"; continue; fi
    sdo stat -c '%a %U:%G %n' "$d"
    sdo find "$d" -maxdepth 1 -mindepth 1 -exec stat -c '%a %U:%G %n' {} + 2>/dev/null | sort -k3 | head -40
    echo "-- archivos y carpetas escribibles por TODOS (sin enlaces simbólicos):"
    local ww; ww="$(sdo find "$d" -xdev ! -type l \( -type f -o -type d \) -perm -0002 2>/dev/null)"
    printf '%s\n' "$ww" | sed '/^$/d' | head -30
    if [ -n "$ww" ]; then res 9 FAIL "$(printf '%s\n' "$ww" | wc -l) elemento/s escribible/s por todos en $d"; else res 9 PASS "nada escribible por todos en $d"; fi
    echo "-- .env bajo public/ o en la raíz web:"
    local pub; pub="$(sdo find "$d" \( -path '*/public/*' -o -path '*/public_html/*' \) -name '.env*' 2>/dev/null; [ "$d" = "$ADMIN_WEB" ] && sdo find "$d" -name '.env*' 2>/dev/null)"
    printf '%s\n' "$pub" | sort -u | sed '/^$/d'
    if [ -n "$(printf '%s' "$pub" | tr -d '[:space:]')" ]; then res 9 FAIL ".env servible por la web en $d"; else res 9 PASS "ningún .env bajo public/ ni en la raíz web de $d"; fi
    echo "-- sobrantes peligrosos (*.sql, *.bak, *.orig, *.zip, *.tar*, *.log) bajo la raíz:"
    sdo find "$d" -xdev -type f \( -name '*.sql' -o -name '*.bak' -o -name '*.orig' -o -name '*.zip' -o -name '*.tar*' -o -name '*.log' \) 2>/dev/null | head -20
  done
  sec ".env: dueño y modo (esperado 640 root:www-data)"
  envf="$(buscar_env)"
  if [ -n "$envf" ] && [ -e "$envf" ]; then
    local st; st="$(sdo stat -c '%a %U:%G' "$envf")"; echo "$envf  $st"
    case "$st" in
      "640 root:www-data") res 9 PASS ".env 640 root:www-data" ;;
      *) [ "${st:2:1}" = "0" ] 2>/dev/null && res 9 WARN ".env con modo/dueño distinto de 640 root:www-data: $st" || res 9 FAIL ".env legible por otros o con modo no esperado: $st" ;;
    esac
  else echo "(no se encontró .env)"; res 9 WARN "no se encontró .env"; fi
  sec "sha256sum -c de los SHA256SUMS instalados (solo lectura)"
  for d in "$ADMIN_WEB" "$ADMIN_APP"; do
    [ -d "$d" ] || continue
    while IFS= read -r f; do
      [ -z "$f" ] && continue
      hallados=$((hallados + 1))
      local out rc
      out="$(cd "$(dirname "$f")" && sdo sha256sum -c --quiet "$(basename "$f")" 2>&1)"; rc=$?
      echo "$f → rc=$rc"; printf '%s\n' "$out" | sed 's/^/   /' | head -15
      [ "$rc" -ne 0 ] && fallos=$((fallos + 1))
    done < <(find "$d" -maxdepth 3 -name SHA256SUMS 2>/dev/null | sort)
  done
  if [ "$hallados" -eq 0 ]; then res 9 WARN "no hay SHA256SUMS instalado que verificar"
  elif [ "$fallos" -eq 0 ]; then res 9 PASS "sha256sum -c OK en $hallados SHA256SUMS"
  else res 9 FAIL "sha256sum -c falla en $fallos de $hallados SHA256SUMS (archivos modificados, faltantes o rutas relativas distintas)"; fi
}

# ---------- 10 · Espacio para respaldos ---------------------------------------------------------------------------------------
item_10() {
  sec "Espacio libre"
  local destino="$BACKUPS_DIR" datadir libre_mb datadir_mb
  [ -d "$destino" ] || destino="/"
  df -h "$destino" 2>&1 | sed 's/^/respaldos: /'
  libre_mb="$(df -Pk "$destino" 2>/dev/null | awk 'NR == 2 {printf "%d", $4 / 1024}')"
  datadir="$(sql_archivo 10-datadir.sql 2>/dev/null | head -1)"
  if [ -n "$datadir" ] && [ -d "$datadir" ]; then df -h "$datadir" 2>&1 | sed 's/^/datadir: /'; datadir_mb="$(df -Pk "$datadir" 2>/dev/null | awk 'NR == 2 {printf "%d", $4 / 1024}')"
  else echo "datadir=${datadir:-<desconocido>} (no visible desde aquí: MySQL puede estar en otro contenedor)"; datadir_mb=""; fi
  sec "Tamaño de las seis tablas (tabla, filas aprox., bytes de datos, bytes de índices)"
  sql_archivo 10-tamano-tablas.sql >"$TMP/tam.tsv"; cat "$TMP/tam.tsv"
  local datos est_mb need_mb
  datos="$(awk -F'\t' '{s += $3} END {printf "%d", s}' "$TMP/tam.tsv")"
  est_mb="$(awk -v b="$datos" 'BEGIN {m = b * 1.5 / 1048576; printf "%d", (m < 1 ? 1 : int(m) + 1)}')"   # el volcado SQL en texto ≈ 1,5 × los datos
  need_mb="$(awk -v e="$est_mb" -v u="$UMBRAL_MB" 'BEGIN {n = e * 3; print (n > u ? n : u)}')"
  echo "datos_bytes=$datos volcado_estimado_mb=$est_mb (sin comprimir; gzip lo reduce ~5×) exigido_libre_mb=$need_mb (máx. de umbral=$UMBRAL_MB y 3× estimado) libre_mb=${libre_mb:-?}"
  if [ ! -s "$TMP/tam.tsv" ] || [ -z "${libre_mb:-}" ]; then res 10 FAIL "no pude medir tamaño o espacio libre"
  elif [ "$libre_mb" -ge "$need_mb" ]; then res 10 PASS "GO: libre ${libre_mb} MB ≥ exigido ${need_mb} MB (estimado de volcado ${est_mb} MB)"
  else res 10 NO-GO "NO-GO: libre ${libre_mb} MB < exigido ${need_mb} MB"; fi
  if [ -n "$datadir_mb" ]; then [ "$datadir_mb" -ge $((est_mb * 2)) ] && res 10 PASS "datadir con ${datadir_mb} MB libres (binlog/InnoDB)" || res 10 WARN "poco espacio en el datadir de MySQL (${datadir_mb} MB)"; fi
}

# ---------- 11 · Compatibilidad estática del paquete candidato -------------------------------------------------------------------
item_11() {
  sec "PHP del servidor"
  if ! command -v php >/dev/null 2>&1; then echo "(php no disponible en el PATH)"; res 11 FAIL "php no está instalado en el PATH"; return 0; fi
  php -v | head -1
  local mods m falta=""
  mods="$(php -m 2>/dev/null | tr 'A-Z' 'a-z')"
  for m in pdo_mysql mbstring json openssl; do
    if printf '%s\n' "$mods" | grep -qx "$m"; then echo "extensión $m: presente"; else echo "extensión $m: AUSENTE"; falta="$falta $m"; fi
  done
  if [ -n "$falta" ]; then res 11 FAIL "faltan extensiones PHP (CLI):$falta"; else res 11 PASS "extensiones pdo_mysql, mbstring, json y openssl presentes (CLI)"; fi
  sec "PHP de Apache (informativo; el CLI y Apache pueden tener extensiones distintas)"
  for _c in /etc/php/*/apache2/conf.d/*; do case "$_c" in *pdo_mysql*|*mbstring*|*openssl*|*json*) echo "$_c" ;; esac; done
  command -v apachectl >/dev/null 2>&1 && sdo apachectl -M 2>/dev/null | grep -i php
  sec "php -l sobre el paquete candidato (solo sintaxis; no ejecuta nada)"
  if [ -z "$PAQUETE" ]; then echo "(no se indicó --paquete)"; res 11 WARN "no se indicó --paquete: sintaxis del candidato NO verificada"; return 0; fi
  if [ ! -d "$PAQUETE" ]; then res 11 FAIL "--paquete $PAQUETE no existe"; return 0; fi
  local n=0 malos=0 f out
  while IFS= read -r -d '' f; do
    n=$((n + 1))
    out="$(php -l "$f" 2>&1)" || { malos=$((malos + 1)); echo "ERROR $f: $(printf '%s' "$out" | head -1)"; }
  done < <(find "$PAQUETE" -type f -name '*.php' -print0)
  echo "archivos .php=$n con error=$malos"
  find "$PAQUETE" -type f -name '.env*' 2>/dev/null | sed 's/^/AVISO .env en el paquete: /'
  if [ "$n" -eq 0 ]; then res 11 FAIL "el paquete no contiene .php"; elif [ "$malos" -gt 0 ]; then res 11 FAIL "$malos de $n archivos PHP con error de sintaxis"; else res 11 PASS "php -l OK en $n archivos"; fi
  [ -n "$(find "$PAQUETE" -type f -name '.env*' 2>/dev/null | head -1)" ] && res 11 FAIL "el paquete candidato contiene un .env"
}

# ---------- 12 · Impacto de las escrituras de Flutter ---------------------------------------------------------------------------
item_12() {
  local bt='`' t op pat hallados=0 escritores="$TMP/escritores.txt"
  : >"$escritores"
  sec "Flutter: $FLUTTER_DIR"
  if [ ! -d "$FLUTTER_DIR" ]; then echo "(no existe)"; res 12 FAIL "no se puede inspeccionar $FLUTTER_DIR"; return 0; fi
  sec "Sentencias INSERT/UPDATE/DELETE sobre las seis tablas (operación, tabla, archivo, cantidad; sin valores)"
  for t in $TABLAS_NEGOCIO; do
    for op in INSERT UPDATE DELETE; do
      case "$op" in
        INSERT) pat="INSERT[[:space:]]+(IGNORE[[:space:]]+)?INTO[[:space:]]+${bt}?${t}${bt}?([[:space:](]|\$)" ;;
        UPDATE) pat="UPDATE[[:space:]]+${bt}?${t}${bt}?[[:space:]]+SET" ;;
        DELETE) pat="DELETE[[:space:]]+FROM[[:space:]]+${bt}?${t}${bt}?([[:space:]]|\$)" ;;
      esac
      sdo grep -rzoiHE --include='*.php' -e "$pat" "$FLUTTER_DIR" 2>/dev/null | tr '\0' '\n' | cut -d: -f1 | sed '/^$/d' | sort | uniq -c | while read -r cnt file; do
        printf '%s\t%s\t%s\t%s\n' "$op" "$t" "${file#"$FLUTTER_DIR"/}" "$cnt"
      done
    done
  done >"$TMP/flutter-escrituras.tsv"; cat "$TMP/flutter-escrituras.tsv"
  hallados="$(wc -l <"$TMP/flutter-escrituras.tsv")"
  cut -f3 "$TMP/flutter-escrituras.tsv" | sort -u >"$escritores"
  sec "Resumen por tabla y operación (total de sentencias)"
  awk -F'\t' '{k[$2 "\t" $1] += $4} END {for (x in k) print x "\t" k[x]}' "$TMP/flutter-escrituras.tsv" | sort
  sec "Transacciones en el backend de Flutter (archivo, beginTransaction, commit, rollBack)"
  sdo grep -rcE --include='*.php' -e 'beginTransaction' "$FLUTTER_DIR" 2>/dev/null | awk -F: '$NF > 0 {print $1 "\t" $NF}' | sed "s#$FLUTTER_DIR/##" | sort >"$TMP/tx.tsv"
  cat "$TMP/tx.tsv"
  echo "total beginTransaction=$(awk -F'\t' '{s += $2} END {print s + 0}' "$TMP/tx.tsv") commit=$(sdo grep -rhoE --include='*.php' -e '->commit\(' "$FLUTTER_DIR" 2>/dev/null | wc -l) rollBack=$(sdo grep -rhoE --include='*.php' -e 'rollBack\(' "$FLUTTER_DIR" 2>/dev/null | wc -l)"
  sec "Archivos que escriben las seis tablas SIN beginTransaction"
  local sin=0 f
  while IFS= read -r f; do
    [ -z "$f" ] && continue
    if ! cut -f1 "$TMP/tx.tsv" | grep -qxF "$f"; then echo "$f"; sin=$((sin + 1)); fi
  done <"$escritores"
  sec "Rutas de escritura de Flutter relacionadas con empresas, informes, asignaciones y usuarios (primeras 60 líneas del router)"
  sdo grep -rnE --include='*.php' -e "(POST|PUT|PATCH|DELETE)" "$FLUTTER_DIR/router" 2>/dev/null | grep -iE 'empresa|reporte|asignac|usuario|user' | sed "s#$FLUTTER_DIR/##" | cut -c1-170 | head -60
  sec "MySQL: escrituras reales acumuladas desde el arranque (tabla, inserts, updates, deletes)"
  sql_archivo 12-escrituras-mysql.sql
  sec "MySQL: conexiones abiertas ahora por cuenta"
  sql_archivo 12-conexiones.sql
  local nesc; nesc="$(wc -l <"$escritores")"
  echo "escritores_flutter_archivos=$nesc sentencias_distintas=$hallados archivos_sin_transaccion=$sin"
  if [ "$nesc" -eq 0 ]; then res 12 PASS "Flutter no escribe las seis tablas (según grep de una línea)"; else
    res 12 WARN "Flutter tiene $nesc archivo/s que escriben las seis tablas ($sin sin beginTransaction): pueden competir con React; React usa transacciones y SELECT … FOR UPDATE"
  fi
  res 12 INFO "limitación: grep no ve SQL construido dinámicamente ni sentencias partidas en varias líneas con interpolaciones; contrastar con performance_schema (arriba)"
}

# ============================================================================================================================
echo "E0: recolectando..."
run_item 0 contexto item_00
run_item 1 admin-8443 item_01
run_item 2 version item_02
run_item 3 esquema item_03
run_item 4 datos-conteos-duplicados item_04
run_item 5 fk-indices item_05
run_item 6 audit-log item_06
run_item 7 privilegios item_07
run_item 8 mysql-config item_08
run_item 9 carpetas-integridad item_09
run_item 10 espacio-respaldos item_10
run_item 11 compat-endpoints item_11
run_item 12 flutter-escrituras item_12

# 98 · el sanitizador no debe dejar pasar nada sospechoso
{
  echo "# E0 · verificación de residuos sensibles en la evidencia ya saneada"
  hits="$(grep -rEn --exclude='98-*' --exclude='99-*' -e 'powerbi\.com' -e '\$2[abxy]\$[0-9]{2}\$' -e 'eyJ[A-Za-z0-9_-]{10,}\.' -e 'BEGIN [A-Z ]*PRIVATE KEY' -e '://[^[:space:]]*\?[^<[:space:]]' "$EVID" 2>/dev/null | cut -d: -f1,2 | head -20)"
  if [ -z "$hits" ]; then echo "SIN_RESIDUOS"; else echo "RESIDUOS_ENCONTRADOS (no comparta la evidencia):"; echo "$hits"; fi
  echo "EXIT_CODE=0"
} >"$EVID/98-residuos-sensibles.txt"
if grep -q '^RESIDUOS' "$EVID/98-residuos-sensibles.txt"; then res 98 FAIL "el sanitizador dejó pasar algo sospechoso (ver 98-residuos-sensibles.txt)"; else res 98 PASS "sin residuos sensibles en la evidencia"; fi

# 99 · resumen
{
  echo "# E0 · resumen · $(date -u +%Y-%m-%dT%H:%M:%SZ) · base=$DB"
  echo "# Leyenda (nivel y significado): PASS correcto · INFO dato · WARN revisar, no bloquea · FAIL corregir antes de la fase 2A · NO-GO no continuar"
  for n in 0 1 2 3 4 5 6 7 8 9 10 11 12 98; do
    awk -F'|' -v n="$n" '$1 == n {printf "ITEM %-3s %-6s %s\n", $1, $2, $3}' "$RES_FILE"
  done
  nf="$(awk -F'|' '$2 == "FAIL"' "$RES_FILE" | wc -l)"; ng="$(awk -F'|' '$2 == "NO-GO"' "$RES_FILE" | wc -l)"; nw="$(awk -F'|' '$2 == "WARN"' "$RES_FILE" | wc -l)"
  if [ "$ng" -gt 0 ]; then r="NO-GO"; elif [ "$nf" -gt 0 ]; then r="NO-GO (hay FAIL)"; elif [ "$nw" -gt 0 ]; then r="GO CON ADVERTENCIAS"; else r="GO"; fi
  echo "TOTALES FAIL=$nf NO-GO=$ng WARN=$nw"
  echo "RESULTADO=$r"
  echo "EXIT_CODE=0"
} | sanear >"$EVID/99-resumen-E0.txt"

if [ "$(id -u)" -eq 0 ] && [ -n "${SUDO_USER:-}" ]; then chown -R "$SUDO_USER" "$EVID" 2>/dev/null; fi   # E0-ESCRIBE-EVIDENCIA
echo
cat "$EVID/99-resumen-E0.txt"
echo "E0: revise los archivos antes de compartirlos. Evidencia: $EVID"
