#!/usr/bin/env bash
# shellcheck disable=SC2034
# Cervesur · UX-08H · Migraciones · Biblioteca común (se «source»-a; no hace nada por sí sola).
# Autónoma: no importa nada de ../e0. Comparte con E0 la lógica de esquema mediante su PROPIA copia de lib-esquema.sh (idéntica; un ensayo lo comprueba).

MIG_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib-esquema.sh
. "$MIG_DIR/lib-esquema.sh"

TABLAS_NEGOCIO="user empresa reporte empresa_usuario empresa_reporte user_empresa_reporte"
CUENTAS_PROTEGIDAS="root cervesur_portalbi_admin mysql.sys mysql.session mysql.infoschema debian-sys-maint"
BITACORA_BASE_DEFECTO="/var/backups/cervesur-react-admin"

cm_preparar_comandos() {
  SUDO_A=()
  if [ "$(id -u)" -ne 0 ]; then SUDO_A=(sudo); fi
  local cmd="${CERVESUR_MYSQL_CMD:-}" dump="${CERVESUR_MYSQLDUMP_CMD:-}"
  if [ -z "$cmd" ]; then if [ "$(id -u)" -eq 0 ]; then cmd="mysql"; else cmd="sudo mysql"; fi; fi
  if [ -z "$dump" ]; then if [ "$(id -u)" -eq 0 ]; then dump="mysqldump"; else dump="sudo mysqldump"; fi; fi
  read -r -a MYSQL_A <<<"$cmd"
  read -r -a MYSQLDUMP_A <<<"$dump"
  CLIENTE_MYSQL="${CERVESUR_MYSQL_CLIENT:-mysql}"
}

# ------------------------------------------------------------------------------------------------------------------------------
# Salida con bitácora. LOG vacío = solo pantalla (modo --simular no escribe nada en disco).
LOG=""
decir() { printf '%s\n' "$*"; if [ -n "$LOG" ]; then printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*" >>"$LOG"; fi; }
solo_log() { if [ -n "$LOG" ]; then printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$*" >>"$LOG"; fi; }

# ------------------------------------------------------------------------------------------------------------------------------
# Guardia de lectura (mismo diseño que E0, más CHECKSUM, que solo lee). Todo lo que NO es escritura pasa por sql_leer.
sql_guard() {
  local txt stmt first bad
  txt="$(cat)"
  if printf '%s' "$txt" | grep -q '/\*!'; then echo "guardia: comentario ejecutable no admitido" >&2; return 1; fi
  txt="$(printf '%s\n' "$txt" | sed -E \
    -e "s/'([^'\\\\]|\\\\.|'')*'/0/g" \
    -e 's/"([^"\\]|\\.|"")*"/0/g' \
    -e 's/`[^`]*`/x/g' \
    -e 's/(^|[[:space:]])--([[:space:]]|$).*$//' \
    -e 's/#.*$//' | tr '\n' ' ' | sed -E 's:/\*([^*]|\*+[^*/])*\*+/: :g')"
  if printf '%s' "$txt" | grep -q '/\*'; then echo "guardia: comentario sin cerrar" >&2; return 1; fi
  if printf '%s' "$txt" | grep -q "[\"']"; then echo "guardia: literal sin cerrar" >&2; return 1; fi
  while IFS= read -r stmt; do
    stmt="$(printf '%s' "$stmt" | sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//')"
    [ -z "$stmt" ] && continue
    first="$(printf '%s' "$stmt" | awk '{print toupper($1)}' | tr -d '(')"
    case "$first" in
      SELECT|EXPLAIN)
        bad="$(printf '%s' "$stmt" | grep -Eiow 'INTO|INSERT|UPDATE|DELETE|REPLACE|SET|DROP|ALTER|CREATE|TRUNCATE|GRANT|REVOKE|RENAME|LOAD|LOAD_FILE|CALL|DO|HANDLER|LOCK|UNLOCK|FLUSH|KILL|INSTALL|UNINSTALL|SHUTDOWN|SLEEP|BENCHMARK|GET_LOCK|RELEASE_LOCK|ANALYZE|OUTFILE|DUMPFILE' | head -1)"
        if [ -n "$bad" ]; then echo "guardia: palabra no admitida en lectura: $bad" >&2; return 1; fi ;;
      SHOW|USE|CHECKSUM) ;;
      *) echo "guardia: sentencia no admitida como lectura («$first»)" >&2; return 1 ;;
    esac
  done < <(printf '%s\n' "$txt" | tr ';' '\n')
  return 0
}

# Lectura: SQL por stdin → guardia → sesión READ ONLY. Filas separadas por TAB, sin cabecera.
sql_leer() {
  local sql
  sql="$(cat)"
  if ! printf '%s\n' "$sql" | sql_guard; then echo "SQL rechazado por el guardia de lectura" >&2; return 99; fi
  printf '%s\n' "$sql" | "${MYSQL_A[@]}" --batch --skip-column-names --init-command='SET SESSION TRANSACTION READ ONLY'
}
leer_texto() { printf '%s\n' "$1" | sql_leer; }

# Escritura: SOLO con PERMITIR_ESCRITURA=1 (lo activan únicamente --aplicar, --verificar [prueba con ROLLBACK] y los --revertir-*).
PERMITIR_ESCRITURA=0
sql_escribir() {
  if [ "$PERMITIR_ESCRITURA" != "1" ]; then echo "ERROR INTERNO: intento de escritura con PERMITIR_ESCRITURA=0 (modo de solo lectura)" >&2; return 97; fi
  "${MYSQL_A[@]}" --batch "$@"
}

# ------------------------------------------------------------------------------------------------------------------------------
# Utilidades
sha256_archivo() { sha256sum "$1" | cut -d' ' -f1; }
huella_normalizada() { printf '%s' "$1" | tr '\n\t' '  ' | sed -E 's/[[:space:]]+/ /g; s/^ //; s/ ?;? ?$//' | tr 'A-Z' 'a-z' | sha256sum | cut -c1-12; }

lista_tablas_sql() { local t out=""; for t in $TABLAS_NEGOCIO; do out="${out:+$out, }\`$1\`.\`$t\`"; done; printf '%s' "$out"; }

# Filas y CHECKSUM TABLE de las seis tablas → "tabla<TAB>filas<TAB>checksum" (una línea por tabla)
estado_tablas() {
  local db="$1" t n c
  for t in $TABLAS_NEGOCIO; do
    n="$(leer_texto "SELECT COUNT(*) FROM \`$db\`.\`$t\`")" || return 1
    c="$(leer_texto "CHECKSUM TABLE \`$db\`.\`$t\`" | awk -F'\t' '{print $2}')" || return 1
    printf '%s\t%s\t%s\n' "$t" "$n" "$c"
  done
}

# Huella (sha256) de la estructura de las seis tablas según un mysqldump --no-data reciente (sin AUTO_INCREMENT=n).
estructura_sha256() {
  local db="$1"
  # shellcheck disable=SC2046
  "${MYSQLDUMP_A[@]}" --no-data --skip-comments --skip-add-drop-table --skip-triggers --no-tablespaces --set-gtid-purged=OFF "$db" $TABLAS_NEGOCIO 2>/dev/null \
    | sed -E 's/ AUTO_INCREMENT=[0-9]+//' | sha256sum | cut -d' ' -f1
}

# Contraseña aleatoria generada con openssl: 24 alfanuméricos + sufijo con mayúscula, minúscula, dígito y símbolos (cumple validate_password MEDIUM).
generar_clave() {
  local base=""
  while [ "${#base}" -lt 24 ]; do base="$base$(openssl rand -base64 48 | tr -dc 'A-Za-z0-9')"; done
  printf '%s' "${base:0:24}aZ9_-"
}

# Triggers de la migración inmutable del repositorio: nombre|momento|evento|huella
triggers_repo() {
  local f="$1" linea n m e s
  [ -f "$f" ] || return 1
  grep -Ei '^[[:space:]]*CREATE[[:space:]]+TRIGGER' "$f" | while IFS= read -r linea; do
    n="$(printf '%s' "$linea" | sed -E 's/^[[:space:]]*CREATE[[:space:]]+TRIGGER[[:space:]]+([A-Za-z0-9_]+).*/\1/I')"
    m="$(printf '%s' "$linea" | sed -E 's/.*TRIGGER[[:space:]]+[A-Za-z0-9_]+[[:space:]]+(BEFORE|AFTER).*/\1/I' | tr 'a-z' 'A-Z')"
    e="$(printf '%s' "$linea" | sed -E 's/.*(BEFORE|AFTER)[[:space:]]+(INSERT|UPDATE|DELETE).*/\2/I' | tr 'a-z' 'A-Z')"
    s="$(printf '%s' "$linea" | sed -E 's/.*FOR[[:space:]]+EACH[[:space:]]+ROW[[:space:]]+//I')"
    printf '%s|%s|%s|%s\n' "$n" "$m" "$e" "$(huella_normalizada "$s")"
  done
}

# Sentencias de un archivo SQL (sin comentarios de línea completa ni líneas vacías), una por línea de salida.
sentencias_de_archivo() {
  awk '
    /^[[:space:]]*--/ { next }
    /^[[:space:]]*$/ { next }
    { sub(/\r$/, ""); acum = (acum == "" ? $0 : acum " " $0)
      if ($0 ~ /;[[:space:]]*$/) { print acum; acum = "" } }
    END { if (acum != "") print acum }
  ' "$1"
}

# Privilegios esperados de la plantilla 2A, ya con {DB} sustituido: líneas «T<TAB>tabla<TAB>-<TAB>PRIV» y «C<TAB>tabla<TAB>columna<TAB>PRIV».
grants_esperados() {
  local plantilla="$1" stmt priv tabla cols c
  while IFS= read -r stmt; do
    case "$stmt" in GRANT\ *) ;; *) continue ;; esac
    priv="$(printf '%s' "$stmt" | sed -E 's/^GRANT +([A-Z]+).*/\1/')"
    tabla="$(printf '%s' "$stmt" | sed -E 's/.* ON +`[^`]*`\.`([a-z_]+)`.*/\1/')"
    cols="$(printf '%s' "$stmt" | sed -nE 's/^GRANT +[A-Z]+ *\(([^)]*)\).*/\1/p')"
    if [ -n "$cols" ]; then
      for c in $(printf '%s' "$cols" | tr ',' ' ' | tr -d '`'); do printf 'C\t%s\t%s\t%s\n' "$tabla" "$c" "$priv"; done
    else
      printf 'T\t%s\t-\t%s\n' "$tabla" "$priv"
    fi
  done < <(sentencias_de_archivo "$plantilla")
}
