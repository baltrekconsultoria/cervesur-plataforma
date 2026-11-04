#!/usr/bin/env bash
# shellcheck disable=SC2034
# Cervesur · UX-08H · E0 · Biblioteca del kit de inspección de SOLO LECTURA (se «source»-a; no hace nada por sí sola).
# Garantías que implementa esta biblioteca:
#   · TODA sentencia SQL pasa por sql_guard (solo SELECT / SHOW / EXPLAIN / USE) y la sesión se abre con SET SESSION TRANSACTION READ ONLY.
#   · TODA salida pasa por sanear (sanear.sed + sanear.awk) ANTES de escribirse en disco.
#   · El .env nunca se lee entero: solo nombres de claves y una lista blanca de claves no secretas (env_valores_permitidos).

E0_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib-esquema.sh
. "$E0_DIR/lib-esquema.sh"

TABLAS_NEGOCIO="user empresa reporte empresa_usuario empresa_reporte user_empresa_reporte"
# Claves del .env cuyo VALOR puede imprimirse (modos y nombres; jamás secretos).
ENV_CLAVES_PERMITIDAS="CERVESUR_MODE CERVESUR_REQUIRED_MODE ADMIN_WRITES_EMPRESAS ADMIN_WRITES_REPORTES ADMIN_WRITES_ACCESOS ADMIN_WRITES_USUARIOS ADMIN_WRITES_USUARIOS_SENSIBLE AUDIT_LOG_MODE REACT_STRICT_VIEWS DB_HOST DB_PORT DB_NAME DB_USER"

e0_preparar_comandos() {
  local cmd="${CERVESUR_MYSQL_CMD:-}"
  SUDO_A=()
  if [ "$(id -u)" -ne 0 ]; then SUDO_A=(sudo); fi
  if [ -z "$cmd" ]; then
    if [ "$(id -u)" -eq 0 ]; then cmd="mysql"; else cmd="sudo mysql"; fi
  fi
  read -r -a MYSQL_A <<<"$cmd"
}

# Ejecuta un comando de LECTURA con sudo cuando no se es root.
sdo() { "${SUDO_A[@]}" "$@"; }

# ------------------------------------------------------------------------------------------------------------------------------
# Guardia de SQL: lee SQL por stdin; devuelve 0 solo si TODAS las sentencias empiezan por SELECT / SHOW / EXPLAIN / USE y
# (en SELECT / EXPLAIN) no contienen palabras de escritura ni funciones con efectos. Los literales y los comentarios se eliminan antes de analizar.
sql_guard() {
  local txt stmt first rest bad
  txt="$(cat)"
  if printf '%s' "$txt" | grep -q '/\*!'; then echo "guardia: comentario ejecutable /*! no admitido" >&2; return 1; fi
  txt="$(printf '%s\n' "$txt" | sed -E \
    -e "s/'([^'\\\\]|\\\\.|'')*'/0/g" \
    -e 's/"([^"\\]|\\.|"")*"/0/g' \
    -e 's/`[^`]*`/x/g' \
    -e 's/(^|[[:space:]])--([[:space:]]|$).*$//' \
    -e 's/#.*$//' | tr '\n' ' ' | sed -E 's:/\*([^*]|\*+[^*/])*\*+/: :g')"
  if printf '%s' "$txt" | grep -q '/\*'; then echo "guardia: comentario de bloque sin cerrar" >&2; return 1; fi
  if printf '%s' "$txt" | grep -q "[\"']"; then echo "guardia: literal sin cerrar" >&2; return 1; fi
  while IFS= read -r stmt; do
    stmt="$(printf '%s' "$stmt" | sed -E 's/^[[:space:]]+//; s/[[:space:]]+$//')"
    [ -z "$stmt" ] && continue
    first="$(printf '%s' "$stmt" | awk '{print toupper($1)}' | tr -d '(')"
    case "$first" in
      SELECT|EXPLAIN)
        rest="$stmt"
        bad="$(printf '%s' "$rest" | grep -Eiow 'INTO|INSERT|UPDATE|DELETE|REPLACE|SET|DROP|ALTER|CREATE|TRUNCATE|GRANT|REVOKE|RENAME|LOAD|LOAD_FILE|CALL|DO|HANDLER|LOCK|UNLOCK|FLUSH|KILL|INSTALL|UNINSTALL|SHUTDOWN|SLEEP|BENCHMARK|GET_LOCK|RELEASE_LOCK|ANALYZE|OUTFILE|DUMPFILE' | head -1)"
        if [ -n "$bad" ]; then echo "guardia: palabra no admitida en consulta de solo lectura: $bad" >&2; return 1; fi ;;
      SHOW|USE) ;;
      *) echo "guardia: sentencia no admitida (empieza por «$first»)" >&2; return 1 ;;
    esac
  done < <(printf '%s\n' "$txt" | tr ';' '\n')
  return 0
}

# Ejecuta SQL de solo lectura recibido por stdin. Salida: filas separadas por TAB, sin cabecera.
sql_ro() {
  local sql
  sql="$(cat)"
  if ! printf '%s\n' "$sql" | sql_guard; then echo "E0: sentencia RECHAZADA por el guardia de solo lectura" >&2; return 99; fi
  printf '%s\n' "$sql" | "${MYSQL_A[@]}" --batch --skip-column-names --init-command='SET SESSION TRANSACTION READ ONLY'
}

# Ejecuta un archivo sql/NN-nombre.sql sustituyendo {DB}.
sql_archivo() {
  local f="$E0_DIR/sql/$1"
  [ -f "$f" ] || { echo "E0: falta $f" >&2; return 98; }
  sed "s/{DB}/$DB/g" "$f" | sql_ro
}

# Ejecuta una sentencia literal (pasa por el mismo guardia).
sql_texto() { printf '%s\n' "$1" | sql_ro; }

# ------------------------------------------------------------------------------------------------------------------------------
# Sanitizador y evidencia
sanear() { sed -E -f "$E0_DIR/sanear.sed" | awk -f "$E0_DIR/sanear.awk"; }

sec() { printf '\n--- %s ---\n' "$1"; }

# res <item> <PASS|WARN|FAIL|NO-GO|INFO> <texto>  → deja constancia para 99-resumen-E0.txt y la imprime en la evidencia
res() {
  printf '%s|%s|%s\n' "$1" "$2" "$3" >>"$RES_FILE"
  printf 'RESULTADO item %s: %s %s\n' "$1" "$2" "$3"
}

# run_item <n> <nombre> <función>: ejecuta la función, sanea su salida y la guarda como NN-nombre.txt terminada en EXIT_CODE=
run_item() {
  local n="$1" name="$2" fn="$3" f
  f="$EVID/$(printf '%02d' "$n")-$name.txt"
  {
    printf '# E0 item %s · %s · %s\n' "$n" "$name" "$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    "$fn"
    printf 'EXIT_CODE=%s\n' "$?"
  } 2>&1 | sanear >"$f"
  echo "  [$n] $name → $f"
}

# ------------------------------------------------------------------------------------------------------------------------------
# .env: solo nombres de claves y valores de la lista blanca. Nunca imprime el resto.
env_nombres() { sdo awk -F= '/^[[:space:]]*[A-Za-z_][A-Za-z0-9_]*=/ { k=$1; gsub(/[[:space:]]/, "", k); print k }' "$1"; }
env_valores_permitidos() {
  sdo awk -F= -v keys="$ENV_CLAVES_PERMITIDAS" 'BEGIN { n = split(keys, a, " "); for (i = 1; i <= n; i++) ok[a[i]] = 1 } { k=$1; gsub(/[[:space:]]/, "", k); if (k in ok) print $0 }' "$1"
}

# Huella normalizada (espacios colapsados, sin «;» final, minúsculas) de una definición de trigger.
huella_normalizada() { printf '%s' "$1" | tr '\n\t' '  ' | sed -E 's/[[:space:]]+/ /g; s/^ //; s/ ?;? ?$//' | tr 'A-Z' 'a-z' | sha256sum | cut -c1-12; }

# Autoverificación del kit: todo SQL del kit pasa el guardia y ningún script contiene sentencias de escritura ni órdenes que cambien el servidor.
kit_autoverificar() {
  local f bad=0 n=0 re
  re="$(cat "$E0_DIR/prohibidas.regex")"
  for f in "$E0_DIR"/sql/*.sql; do
    n=$((n + 1))
    if ! sed "s/{DB}/prueba/g" "$f" | sql_guard 2>/dev/null; then echo "AUTOVERIFICACION: el guardia rechaza $f" >&2; bad=1; fi
    if grep -Ev '^[[:space:]]*--' "$f" | grep -Eq "$re"; then echo "AUTOVERIFICACION: sentencia de escritura en $f" >&2; bad=1; fi
  done
  for f in "$E0_DIR"/e0-auditoria-fase2a.sh "$E0_DIR"/lib-e0.sh "$E0_DIR"/sanear.sh; do
    if grep -Ev '^[[:space:]]*#' "$f" | sed -E 's/SHOW CREATE (TABLE|TRIGGER)//g' | grep -Eq "$re"; then echo "AUTOVERIFICACION: patrón de escritura SQL en $f" >&2; bad=1; fi
    if grep -Ev '^[[:space:]]*#|E0-ESCRIBE-EVIDENCIA|^[[:space:]]*(echo|printf)[[:space:]]' "$f" | grep -Eq '(^|[;&|(`]|\$\(|sdo |sudo |then |do |else )[[:space:]]*(rm|mv|cp|chmod|chgrp|chown|dd|mkfs|truncate|touch|tee|kill|pkill|systemctl|service|a2ensite|a2dissite|a2enmod|apt|apt-get|useradd|usermod|passwd|crontab)([[:space:]]|$)'; then
      echo "AUTOVERIFICACION: orden que modifica el sistema en $f (solo se admite con la marca E0-ESCRIBE-EVIDENCIA)" >&2; bad=1
    fi
  done
  [ "$n" -gt 0 ] || { echo "AUTOVERIFICACION: no hay archivos SQL" >&2; bad=1; }
  return "$bad"
}
