#!/usr/bin/env bash
# UX-08B4: instalar SOLO el controlador PHP de reportes en React Admin 8443.
# NO altera MySQL, .env, Apache, Flutter, otros PHP, frontend, P5-R ni U-BASIC.
set -euo pipefail

MODE="${1:---preflight}"
if [ "$#" -ne 1 ] || { [ "$MODE" != "--preflight" ] && [ "$MODE" != "--aplicar" ]; }; then
  echo "USO: sudo bash desplegar-ux08b4.sh --preflight|--aplicar" >&2
  exit 2
fi
[ "$(id -u)" -eq 0 ] || { echo "UX08B4_ROOT=NO_GO"; exit 2; }

readonly TARGET="/opt/cervesur-react-admin/backend/controllers/ReporteController.php"
readonly ENV="/opt/cervesur-react-admin/backend/.env"
readonly OLD_BLOB="d51c89361ae5dda52435b224ad8e58f5de0fc82b"
readonly NEW_BLOB="3a120727252cea924354716e8de125acafbda4c2"
readonly CONTROLLER_COMMIT="cbb4c1860708a9ae9be0305e3f7ffb1947fc50b8"
readonly URL="https://raw.githubusercontent.com/baltrekconsultoria/cervesur-plataforma/${CONTROLLER_COMMIT}/backend/controllers/ReporteController.php"
readonly PING="https://192.168.200.12:8443/api/ping"
readonly CA="/etc/ssl/certs/ca-certificates.crt"

echo "=== CERVESUR UX-08B4: ${MODE} ==="
for cmd in git curl php sha256sum; do command -v "$cmd" >/dev/null || { echo "UX08B4_DEPENDENCIA=NO_GO:$cmd"; exit 1; }; done
[ -f "$TARGET" ] && [ -r "$TARGET" ] && [ -f "$ENV" ] && [ -r "$ENV" ] || { echo "UX08B4_ARCHIVOS=NO_GO"; exit 1; }
[ -r "$CA" ] || { echo "UX08B4_CA=NO_GO"; exit 1; }

# Solo lee el valor de cuatro flags. Nunca imprime otras lineas de .env ni secretos.
env_value() {
  awk -F= -v key="$1" '$1 == key { print $2; exit }' "$ENV" | tr -d '\r'
}
if [ "$(env_value CERVESUR_MODE)" != "admin_escritura" ] ||
   [ "$(env_value ADMIN_WRITES_REPORTES)" != "1" ] ||
   [ "$(env_value ADMIN_WRITES_USUARIOS)" != "1" ] ||
   [ "$(env_value ADMIN_WRITES_USUARIOS_PASSWORD_RESET)" != "1" ] ||
   [ "$(env_value ADMIN_WRITES_USUARIOS_SENSIBLE)" = "1" ]; then
  echo "UX08B4_INTERRUPTORES=NO_GO"
  exit 1
fi
echo "UX08B4_INTERRUPTORES=PASS_REPORTES_Y_P5R_ON_SENSIBLE_OFF"

WORK=$(mktemp -d)
TMP_TARGET=""
trap 'if [ -n "$TMP_TARGET" ]; then rm -f "$TMP_TARGET"; fi; rm -rf "$WORK"' EXIT
CANDIDATE="$WORK/ReporteController.php"
curl --fail --silent --show-error --location --max-time 30 \
  --proto '=https' --tlsv1.2 "$URL" -o "$CANDIDATE"
[ "$(git hash-object "$CANDIDATE")" = "$NEW_BLOB" ] || { echo "UX08B4_GIT_CANDIDATO=NO_GO"; exit 1; }
php -l "$CANDIDATE" >/dev/null || { echo "UX08B4_PHP_LINT=NO_GO"; exit 1; }
echo "UX08B4_CANDIDATO_GIT=PASS"
echo "UX08B4_PHP_LINT=PASS"

CURRENT_BLOB=$(git hash-object "$TARGET")
if [ "$CURRENT_BLOB" = "$OLD_BLOB" ]; then
  echo "UX08B4_BASE_PRODUCTIVA=PASS"
elif [ "$CURRENT_BLOB" = "$NEW_BLOB" ]; then
  echo "UX08B4_ESTADO=YA_INSTALADO"
  echo "UX08B4_ESCRITURAS=NO"
  exit 0
else
  echo "UX08B4_BASE_PRODUCTIVA=NO_GO_DIVERGENTE"
  echo "UX08B4_SHA_ACTUAL=$CURRENT_BLOB"
  exit 1
fi

probe() {
  curl --noproxy '*' --cacert "$CA" --connect-timeout 5 --max-time 15 \
    --silent --show-error -o /dev/null -w '%{http_code} %{ssl_verify_result}' "$1"
}
BEFORE=$(probe "$PING") || { echo "UX08B4_HTTPS_PRE=NO_GO"; exit 1; }
[ "$BEFORE" = "200 0" ] || { echo "UX08B4_HTTPS_PRE=NO_GO:$BEFORE"; exit 1; }
echo "UX08B4_HTTPS_PRE=PASS"

if [ "$MODE" = "--preflight" ]; then
  echo "UX08B4_PREFLIGHT=PASS_SOLO_LECTURA"
  echo "UX08B4_ESCRITURAS=NO"
  exit 0
fi

read -r -p "Autorizacion REAL (DBA/funcional, fecha, alcance): " APPROVAL </dev/tty
if [ "${#APPROVAL}" -lt 20 ] || [[ "$APPROVAL" == *"["* ]]; then
  echo "UX08B4_AUTORIZACION=NO_GO"
  exit 1
fi
read -r -p "Para instalar SOLO ReporteController.php, escriba APLICAR-UX08B4: " CONFIRM </dev/tty
[ "$CONFIRM" = "APLICAR-UX08B4" ] || { echo "UX08B4_CONFIRMACION=NO_GO"; exit 1; }

BACKUP_ROOT="/var/backups/cervesur-react-admin"
BACKUP="$BACKUP_ROOT/ux08b4-controller-$(date -u +%Y%m%dT%H%M%SZ)-$$"
install -d -m 700 "$BACKUP_ROOT" "$BACKUP"
cp -p "$TARGET" "$BACKUP/ReporteController.php"
[ "$(git hash-object "$BACKUP/ReporteController.php")" = "$OLD_BLOB" ] || { echo "UX08B4_BACKUP=NO_GO"; exit 1; }
sha256sum "$BACKUP/ReporteController.php" > "$BACKUP/SHA256SUMS"
chmod 600 "$BACKUP/SHA256SUMS"
echo "UX08B4_BACKUP=PASS:$BACKUP"

atomic_copy() {
  local source="$1"
  TMP_TARGET=$(mktemp "$(dirname "$TARGET")/.ux08b4-php.XXXXXXXX")
  cat "$source" > "$TMP_TARGET"
  chown --reference="$TARGET" "$TMP_TARGET"
  chmod --reference="$TARGET" "$TMP_TARGET"
  php -l "$TMP_TARGET" >/dev/null
  mv -f "$TMP_TARGET" "$TARGET"
  TMP_TARGET=""
}
rollback() {
  echo "UX08B4_ROLLBACK=INICIADO"
  atomic_copy "$BACKUP/ReporteController.php"
  if [ "$(git hash-object "$TARGET")" = "$OLD_BLOB" ]; then
    echo "UX08B4_ROLLBACK=PASS"
  else
    echo "UX08B4_ROLLBACK=FAIL" >&2
  fi
}
# Dado que PHP opcache puede cachear el controlador, no reiniciar Apache ni otros servicios.
atomic_copy "$CANDIDATE"
sleep 4
AFTER_BLOB=$(git hash-object "$TARGET")
AFTER_HTTP=$(probe "$PING") || AFTER_HTTP="ERROR"
AFTER_ANON=$(probe "https://192.168.200.12:8443/api/reportes") || AFTER_ANON="ERROR"
if [ "$AFTER_BLOB" != "$NEW_BLOB" ] || [ "$AFTER_HTTP" != "200 0" ] ||
   [ "$AFTER_ANON" != "401 0" ] ||
   [ "$(env_value ADMIN_WRITES_USUARIOS_PASSWORD_RESET)" != "1" ] ||
   [ "$(env_value ADMIN_WRITES_USUARIOS_SENSIBLE)" = "1" ]; then
  echo "UX08B4_POSTCHECK=NO_GO"
  rollback
  exit 1
fi
echo "UX08B4_POSTCHECK=PASS"
echo "UX08B4_CODIGO=INSTALADO"
echo "UX08B4_REVERSION=RESPALDO_DE_CODIGO"
echo "UX08B4_MYSQL_MODIFICADO=NO"
echo "UX08B4_FLUTTER_MODIFICADO=NO"
echo "UX08B4_P5R=ON"
echo "UX08B4_CAMBIO_ROLES=OFF"
echo "UX08B4_AUTORIZACION=REFERENCIA_REGISTRADA_NO_VERIFICADA_AUTOMATICAMENTE"
echo "UX08B4_INSTALACION=PASS"
