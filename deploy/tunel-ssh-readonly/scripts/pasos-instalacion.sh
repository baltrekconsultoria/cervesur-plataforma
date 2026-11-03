#!/usr/bin/env bash
# Pasos de instalación del diagnóstico de solo lectura (los invoca scripts/instalar.sh tras los preflights y la confirmación).
# Corre como proceso aparte con «set -e»: cualquier fallo devuelve error y instalar.sh revierte. No toca Flutter, 8088, 8089, los módulos de Apache
# ni ningún usuario, base o privilegio de MySQL.
set -euo pipefail
PAQ=${1:?paquete}
ARCHIVO_CLAVE=${2:?archivo con la clave de la cuenta de diagnóstico}
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
requerir_root

copiar_solo_lectura() { # origen destino: root:root, directorios 755 y archivos 644
  cp -R --no-preserve=all "$1" "$2"
  chown -R root:root "$2"
  find "$2" -type d -exec chmod 755 {} +
  find "$2" -type f -exec chmod 644 {} +
}

echo "== 2. Archivos (carpetas nuevas y exclusivas)"
install -d -m 755 "$DIR_BACK"
copiar_solo_lectura "$PAQ/backend" "$DIR_BACKEND"
ok "backend en $DIR_BACKEND (sin interfaz web: no se publica ninguna aplicación)"

echo "== 3. Directorios de trabajo exclusivos"
install -d -m 755 -o root -g root "$DIR_VAR" "$DIR_DOCROOT"
install -d -m 700 -o www-data -g www-data "$DIR_SESIONES"
ok "docroot VACÍO $DIR_DOCROOT (root:root) y sesiones $DIR_SESIONES (modo 700, www-data)"

echo "== 4. .env (640 root:www-data; token propio generado al azar; la clave de la cuenta se copia sin mostrarse)"
CLAVE=$(head -n 1 "$ARCHIVO_CLAVE" | tr -d '\r\n')
[ -n "$CLAVE" ] || abortar "La clave de la cuenta está vacía."
TOKEN=$(generar_token)
token_valido <<<"$TOKEN" || abortar "El token generado no es válido (error interno)."
(
  umask 077
  printf 'DB_HOST=127.0.0.1\nDB_NAME=%s\nDB_USER=%s\nDB_PASS=%s\nDB_CHARSET=utf8mb4\nCERVESUR_MODE=diagnostico\nCERVESUR_REQUIRED_MODE=diagnostico\nCERVESUR_PRODUCTION_DBS=%s\nCERVESUR_DIAG_TOKEN=%s\n' \
    "$DB_NOMBRE" "$DB_USUARIO" "$CLAVE" "$DB_NOMBRE" "$TOKEN" > "$ENV_FILE"
)
chown root:www-data "$ENV_FILE"
chmod 640 "$ENV_FILE"
unset CLAVE TOKEN
ok ".env creado en $ENV_FILE (no se muestran valores)"

echo "== 5. Apache: sitio independiente en 127.0.0.1:$PUERTO (sin habilitar ni deshabilitar módulos)"
install -d -m 755 "$DIR_CONF"
install -m 644 -o root -g root "$PAQ/apache/react-ro-common.conf" "$DIR_CONF/react-ro-common.conf"
install -m 644 -o root -g root "$PAQ/apache/cervesur-react-ro.conf" "$SITE_CONF"
a2ensite "$SITIO" >/dev/null
apache_recargar
ok "configtest correcto y recarga suave (graceful) de Apache; no se reinició el servicio ni se tocaron módulos"
