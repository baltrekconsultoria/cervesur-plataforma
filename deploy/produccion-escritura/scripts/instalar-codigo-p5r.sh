#!/usr/bin/env bash
# P5-R: actualizacion incremental del admin 8443 con U-BASIC ENCENDIDO.
# Solo 4 PHP y contenido de public/. NO toca el .env, MySQL, Apache ni Flutter.
# Modo: --preflight | --aplicar --autorizacion "DBA, Negocio, fecha" | --revertir RUTA_RESPALDO
set -uo pipefail
PAQ=$(cd "$(dirname "$0")/.." && pwd)
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
URL_ADMIN="https://localhost:8443"
CURL_TLS=(--cacert "$CERT_FILE" --resolve "localhost:8443:192.168.200.12")
trap api_limpiar EXIT

MODE=""
APPROVAL=""
BACKUP=""
while [ $# -gt 0 ]; do
  case "$1" in
    --preflight) MODE=preflight; shift ;;
    --aplicar) MODE=aplicar; shift ;;
    --revertir) MODE=revertir; BACKUP="${2:-}"; shift 2 ;;
    --autorizacion) APPROVAL="${2:-}"; shift 2 ;;
    *) echo "P5R_INSTALL=NO_GO_ARG"; exit 2 ;;
  esac
done
case "$MODE" in preflight|aplicar|revertir) ;; *) echo "Uso: --preflight | --aplicar --autorizacion TEXTO | --revertir RUTA"; exit 2 ;; esac
requerir_root

P5R_FILES=(lib/admin_escritura.php lib/mode.php router/AdminWriteRouter.php models/UserModel.php)
validate_package() {
  [ -f "$PAQ/SHA256SUMS" ] && [ -d "$PAQ/public" ] && [ -f "$PAQ/public/index.html" ] || return 1
  ( cd "$PAQ" && sha256sum -c SHA256SUMS --quiet ) >/dev/null 2>&1 || return 1
  [ -f "$PAQ/base-git-blobs.txt" ] || return 1
  local f
  for f in "${P5R_FILES[@]}"; do
    [ -f "$PAQ/backend/$f" ] && php -l "$PAQ/backend/$f" >/dev/null 2>&1 || return 1
  done
}
baseline_matches() {
  local blob path actual
  while IFS=' ' read -r blob path; do
    [ -n "$blob" ] || continue
    case "$path" in
      backend/lib/admin_escritura.php|backend/lib/mode.php|backend/router/AdminWriteRouter.php|backend/models/UserModel.php) ;;
      *) return 1 ;;
    esac
    [ -f "$DIR_BACKEND/${path#backend/}" ] || return 1
    actual=$(git hash-object "$DIR_BACKEND/${path#backend/}") || return 1
    [ "$actual" = "$blob" ] || { echo "P5R_BASE=NO_GO_HUELLA_DISTINTA:$path"; return 1; }
  done < "$PAQ/base-git-blobs.txt"
}
flags_ok() {
  [ "$(env_valor_actual CERVESUR_MODE)" = admin_escritura ] \
    && [ "$(env_valor_actual CERVESUR_REQUIRED_MODE)" = admin_escritura ] \
    && [ "$(env_valor_actual ADMIN_WRITES_USUARIOS)" = 1 ] \
    && [ "$(env_valor_actual ADMIN_WRITES_USUARIOS_SENSIBLE)" != 1 ] \
    && [ "$(env_valor_actual ADMIN_WRITES_USUARIOS_PASSWORD_RESET)" != 1 ] \
    && [ "$(env_valor_actual AUDIT_LOG_MODE)" = required ]
}
capabilities_ok() {
  credenciales_presentes || return 1
  api_login || return 1
  capacidades_leer || return 1
  [ "$CAP_usuarios" = true ] && [ "$CAP_SENSIBLE" = false ] || return 1
}
preflight() {
  echo "=== P5R INCREMENTAL: PREFLIGHT ==="
  validate_package || { echo "P5R_PAQUETE=FAIL"; return 1; }
  echo "P5R_PAQUETE=PASS"
  flags_ok || { echo "P5R_U_BASIC=NO_GO_ENV"; return 1; }
  echo "P5R_U_BASIC=PASS_ON"
  [ -d "$DIR_PUBLIC" ] && [ -d "$DIR_BACKEND" ] && [ -f "$ENV_FILE" ] || return 1
  baseline_matches || { echo "P5R_ORIGEN=NO_GO_DIVERGENCIA"; return 1; }
  echo "P5R_ORIGEN=PASS_GIT_BLOBS"
  apache2ctl configtest >/dev/null 2>&1 || { echo "P5R_APACHE=NO_GO"; return 1; }
  curl -fsS --max-time 10 "${CURL_TLS[@]}" "$URL_ADMIN/api/ping" >/dev/null 2>&1 || { echo "P5R_8443=NO_GO_TLS_O_HTTP"; return 1; }
  echo "P5R_8443=PASS"
  echo "P5R_PREFLIGHT=PASS_SOLO_LECTURA"
}

restore() { # backup creada por este mismo instalador, preserva .env
  local dir=$1 f
  [ -f "$dir/backend.tgz" ] && [ -f "$dir/public.tgz" ] && [ -f "$dir/env.sha256" ] || return 1
  gzip -t "$dir/backend.tgz" "$dir/public.tgz" >/dev/null 2>&1 || return 1
  # No introducir rutas arbitrarias, ni extraer otros archivos distintos de los cuatro permitidos.
  [ "$(tar -tzf "$dir/backend.tgz" | LC_ALL=C sort)" = "$(printf '%s\n' "${P5R_FILES[@]}" | LC_ALL=C sort)" ] || return 1
  tar -xzf "$dir/backend.tgz" -C "$DIR_BACKEND" || return 1
  tar -xzf "$dir/public.tgz" -C "$DIR_FRONT" || return 1
  [ "$(sha256sum < "$ENV_FILE" | cut -d' ' -f1)" = "$(cat "$dir/env.sha256")" ] || return 1
  for f in "${P5R_FILES[@]}"; do php -l "$DIR_BACKEND/$f" >/dev/null 2>&1 || return 1; done
  return 0
}

if [ "$MODE" = revertir ]; then
  case "$BACKUP" in "$DIR_RESPALDO"/p5r-code-*) ;; *) echo "P5R_ROLLBACK=NO_GO_RUTA"; exit 2 ;; esac
  [ ! -L "$BACKUP" ] && [ -d "$BACKUP" ] || { echo "P5R_ROLLBACK=NO_GO_BACKUP"; exit 1; }
  [ "$(env_valor_actual ADMIN_WRITES_USUARIOS_PASSWORD_RESET)" != 1 ] || { echo "P5R_ROLLBACK=NO_GO_APAGUE_P5R_PRIMERO"; exit 1; }
  restore "$BACKUP" && echo "P5R_ROLLBACK=PASS_U_BASIC_INTACTO" && exit 0
  echo "P5R_ROLLBACK=FAIL_REVISION_MANUAL"; exit 1
fi

preflight || exit 10
[ "$MODE" = preflight ] && exit 0
[ "${CERVESUR_CONFIRMAR_P5R:-}" = SI ] && [ "${#APPROVAL}" -ge 10 ] || { echo "P5R_INSTALL=NO_GO_AUTORIZACION"; exit 10; }
capabilities_ok || { echo "P5R_INSTALL=NO_GO_API_CAPACIDADES"; exit 10; }
OLD_EM="$CAP_empresas"; OLD_RE="$CAP_reportes"; OLD_AC="$CAP_accesos"
ENV_SHA=$(sha256sum < "$ENV_FILE" | cut -d' ' -f1)
BACKUP="$DIR_RESPALDO/p5r-code-$(sello_utc)-$$"
install -d -m 700 "$BACKUP" || exit 10
tar -czf "$BACKUP/backend.tgz" -C "$DIR_BACKEND" "${P5R_FILES[@]}" || exit 10
tar -czf "$BACKUP/public.tgz" -C "$DIR_FRONT" public || exit 10
printf '%s\n' "$ENV_SHA" > "$BACKUP/env.sha256"
chmod 600 "$BACKUP"/* || exit 10
gzip -t "$BACKUP/backend.tgz" "$BACKUP/public.tgz" || exit 10
echo "P5R_BACKUP=PASS:$BACKUP"
printf '%s\n' "$(date -u +%FT%TZ) :: $APPROVAL" > "$BACKUP/AUTORIZACION.txt"
chmod 600 "$BACKUP/AUTORIZACION.txt"

# Copiar los PHP via rename atomico, sin escribir en el .env.
for f in "${P5R_FILES[@]}"; do
  target="$DIR_BACKEND/$f"
  temp=$(mktemp "$target.p5r.XXXXXX") || { echo "P5R_INSTALL=FAIL_TMP"; restore "$BACKUP"; exit 20; }
  if ! install -m 640 -o root -g www-data "$PAQ/backend/$f" "$temp" || ! mv -f "$temp" "$target"; then
    echo "P5R_INSTALL=FAIL_COPIA:$f"; restore "$BACKUP"; exit 20
  fi
done
# Frontend: copiar activos ANTES de index, sin borrar bundles anteriores.
if ! (cd "$PAQ/public" && tar -cf - --exclude='./index.html' .) | tar -xf - -C "$DIR_PUBLIC"; then
  echo "P5R_INSTALL=FAIL_PUBLIC"; restore "$BACKUP"; exit 20
fi
temp=$(mktemp "$DIR_PUBLIC/.index-p5r.XXXXXX") || { restore "$BACKUP"; exit 20; }
if ! install -m 644 -o root -g www-data "$PAQ/public/index.html" "$temp" || ! mv -f "$temp" "$DIR_PUBLIC/index.html"; then
  echo "P5R_INSTALL=FAIL_INDEX"; restore "$BACKUP"; exit 20
fi
espr=0
while [ "$espr" -lt 3 ]; do
  sleep 3
  if flags_ok && [ "$(sha256sum < "$ENV_FILE" | cut -d' ' -f1)" = "$ENV_SHA" ] \
       && curl -fsS --max-time 10 "${CURL_TLS[@]}" "$URL_ADMIN/api/ping" >/dev/null 2>&1 \
       && capabilities_ok \
       && [ "$CAP_empresas" = "$OLD_EM" ] && [ "$CAP_reportes" = "$OLD_RE" ] && [ "$CAP_accesos" = "$OLD_AC" ] \
       && grep -Eq '"users_password_reset"[[:space:]]*:[[:space:]]*false' <<< "$API_CUERPO"; then
    echo "P5R_INSTALL=PASS_CODIGO_FLAG_OFF_U_BASIC_ON"
    echo "P5R_ROLLBACK_DISPONIBLE=$BACKUP"
    exit 0
  fi
  espr=$((espr+1))
done
echo "P5R_INSTALL=NO_GO_VERIFICACION_RESTAURANDO"
if restore "$BACKUP"; then echo "P5R_AUTO_ROLLBACK=PASS"; else echo "P5R_AUTO_ROLLBACK=FAIL_URGENTE"; fi
exit 20
