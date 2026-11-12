#!/usr/bin/env bash
# P5-R · SOLO LECTURA: identifica codigo base 2B real del VPS y U-BASIC encendido.
# NO modifica .env, MySQL, PHP, Apache, permisos ni archivos productivos.
set -uo pipefail
echo "=== CERVESUR P5-R: PREFLIGHT HOST SOLO LECTURA ==="
if [ "$(id -u)" -ne 0 ]; then echo "P5R_ROOT=REQUIERE_SUDO"; exit 2; fi
BE=/opt/cervesur-react-admin/backend
PUB=/var/www/cervesur-react-admin/public
ENV_FILE="$BE/.env"
CERT=/etc/apache2/cervesur-react-admin/tls/servidor.crt
RESULT=PASS
check() { # etiqueta resultado
  echo "$1=$2"
  [ "$2" = PASS ] || RESULT=NO_GO
}
read_flag() { 
  [ -r "$ENV_FILE" ] || return 1
  awk -F= -v key="$1" '$1==key { sub(/^[^=]*=/, ""); x=$0 } END { print x }' "$ENV_FILE" | tr -d '\r'
}
[ -f "$ENV_FILE" ] && [ -d "$PUB" ] && [ -f "$CERT" ] && [ -d "$BE" ] && check P5R_ARCHIVOS PASS || check P5R_ARCHIVOS NO_GO
[ "$(read_flag CERVESUR_MODE)" = admin_escritura ] && [ "$(read_flag CERVESUR_REQUIRED_MODE)" = admin_escritura ] && check P5R_MODO PASS || check P5R_MODO NO_GO
[ "$(read_flag ADMIN_WRITES_USUARIOS)" = 1 ] && check P5R_U_BASIC_ON PASS || check P5R_U_BASIC_ON NO_GO
[ "$(read_flag ADMIN_WRITES_USUARIOS_SENSIBLE)" != 1 ] && [ "$(read_flag ADMIN_WRITES_USUARIOS_PASSWORD_RESET)" != 1 ] && check P5R_P5_SENSIBLE_OFF PASS || check P5R_P5_SENSIBLE_OFF NO_GO
[ "$(read_flag AUDIT_LOG_MODE)" = required ] && check P5R_AUDITORIA_REQUIRED PASS || check P5R_AUDITORIA_REQUIRED NO_GO
if command -v git >/dev/null 2>&1; then
  codeok=1
  while read -r expected path; do
    f="$BE/${path#backend/}"
    if [ ! -f "$f" ] || [ "$(git hash-object "$f" 2>/dev/null)" != "$expected" ]; then
      echo "P5R_BLOB_DIFERENTE=$path"
      codeok=0
    fi
  done <<'BLOBS'
f834b59bf238838070aeb33e68fedebc34f3a30e backend/lib/admin_escritura.php
074fc2473b4ec79db9177815b29fa2faf4855512 backend/lib/mode.php
a681cda87d3e5c266b5d91bce9fd49241a1d45a8 backend/router/AdminWriteRouter.php
6a770161e45580ff722b30edf20594a1866c758f backend/models/UserModel.php
BLOBS
  [ "$codeok" -eq 1 ] && check P5R_BASE_GIT PASS || check P5R_BASE_GIT NO_GO
else
  check P5R_BASE_GIT NO_GO_SIN_GIT
fi
if command -v apache2ctl >/dev/null 2>&1 && apache2ctl configtest >/dev/null 2>&1; then check P5R_APACHE PASS
else check P5R_APACHE NO_GO; fi
if curl -fsS -m 10 --cacert "$CERT" --resolve 'localhost:8443:127.0.0.1' https://localhost:8443/api/ping >/dev/null 2>&1; then check P5R_8443 PASS
else check P5R_8443 NO_GO_TLS_O_HTTP; fi
if command -v mysql >/dev/null 2>&1; then
  grants=$(mysql -N -B -e "SHOW GRANTS FOR 'cervesur_escritura_adm'@'127.0.0.1'" 2>/dev/null) || grants=""
  if [ -n "$grants" ]; then
    # Revisa SOLO UPDATE en user, no SELECT ni grants de otras tablas.
    usergrant=$(grep -F 'user' <<< "$grants" | grep -E '\bUPDATE[[:space:]]*\(' || true)
    if grep -Eiq '\b(role|password)\b' <<< "$usergrant"; then check P5R_MYSQL_SIN_SENSIBLES NO_GO
    else check P5R_MYSQL_SIN_SENSIBLES PASS; fi
  else check P5R_MYSQL_SIN_SENSIBLES NO_GO_SIN_SHOW_GRANTS; fi
else check P5R_MYSQL_SIN_SENSIBLES NO_GO_SIN_MYSQL; fi
echo "P5R_PREFLIGHT_HOST=$RESULT"
echo "P5R_ESCRITURAS_REALIZADAS=NO"
echo "P5R_SECRETOS_PUBLICADOS=NO"
[ "$RESULT" = PASS ]
