#!/usr/bin/env bash
# Pasos de instalación del entorno de COMPATIBILIDAD (los invoca scripts/instalar.sh tras las comprobaciones y la confirmación).
# Corre como proceso aparte con «set -e»: cualquier fallo devuelve error y instalar.sh revierte. No toca Flutter, el React de 8088,
# los módulos de Apache ni ninguna base o usuario MySQL existente.
set -euo pipefail
PAQ=${1:?paquete}
CUENTAS_OUT=${2:?archivo de salida de cuentas}
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
install -d -m 755 "$DIR_FRONT" "$DIR_BACK"
copiar_solo_lectura "$PAQ/public" "$DIR_FRONT/public"
copiar_solo_lectura "$PAQ/backend" "$DIR_BACK/backend"
ok "frontend en $DIR_FRONT/public y backend de compatibilidad en $DIR_BACK/backend (fuera del DocumentRoot)"

echo "== 3. Sesiones PHP exclusivas (no se comparten con ningún otro portal)"
install -d -m 755 -o root -g root "$DIR_VAR"
install -d -m 700 -o www-data -g www-data "$DIR_SESIONES"
ok "directorio de sesiones $DIR_SESIONES (modo 700, propietario www-data)"

echo "== 4. Base de datos y usuario DEDICADOS (sin tocar ninguna base existente)"
DB_PASS=$(generar_clave_mysql)
JWT=$(openssl rand -hex 32)
clave_cumple_politica_medium <<<"$DB_PASS" || abortar "La contraseña generada no cumple la política MEDIUM de MySQL (error interno)."
# shellcheck disable=SC2086  # MYSQL_ADMIN puede contener opciones
$MYSQL_ADMIN <<SQL
CREATE DATABASE \`$DB_NOMBRE\` CHARACTER SET utf8mb4;
CREATE USER '$DB_USUARIO'@'localhost' IDENTIFIED BY '$DB_PASS';
CREATE USER '$DB_USUARIO'@'127.0.0.1' IDENTIFIED BY '$DB_PASS';
GRANT ALL PRIVILEGES ON \`$DB_NOMBRE\`.* TO '$DB_USUARIO'@'localhost';
GRANT ALL PRIVILEGES ON \`$DB_NOMBRE\`.* TO '$DB_USUARIO'@'127.0.0.1';
SQL
ok "base $DB_NOMBRE y usuario $DB_USUARIO (@localhost y @127.0.0.1) creados; permisos solo sobre esa base"

echo "== 5. .env del backend de compatibilidad (640 root:www-data; secretos propios generados al azar, no se muestran)"
(
  umask 077
  printf 'DB_HOST=127.0.0.1\nDB_NAME=%s\nDB_USER=%s\nDB_PASS=%s\nDB_CHARSET=utf8mb4\nJWT_SECRET=%s\nJWT_EXPIRATION=3600\n' \
    "$DB_NOMBRE" "$DB_USUARIO" "$DB_PASS" "$JWT" > "$DIR_BACK/backend/.env"
)
chown root:www-data "$DIR_BACK/backend/.env"
chmod 640 "$DIR_BACK/backend/.env"
unset DB_PASS JWT
ok ".env creado en $DIR_BACK/backend/.env"

echo "== 6. Esquema productivo SINTÉTICO, datos ficticios y migración mínima de grupo_id (solo en esta base)"
php "$PAQ/scripts/sembrar-compat.php" "$DIR_BACK/backend" "$PAQ/scripts/productive_fixture.php" > "$CUENTAS_OUT"
# El backend no ejecuta DDL: tras sembrar, el usuario de la aplicación queda solo con permisos de datos.
# shellcheck disable=SC2086
$MYSQL_ADMIN <<SQL
REVOKE ALL PRIVILEGES ON \`$DB_NOMBRE\`.* FROM '$DB_USUARIO'@'localhost';
REVOKE ALL PRIVILEGES ON \`$DB_NOMBRE\`.* FROM '$DB_USUARIO'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`$DB_NOMBRE\`.* TO '$DB_USUARIO'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON \`$DB_NOMBRE\`.* TO '$DB_USUARIO'@'127.0.0.1';
SQL
ok "base sembrada; permisos del usuario reducidos a SELECT, INSERT, UPDATE, DELETE"

echo "== 7. Apache: sitio independiente en 127.0.0.1:$PUERTO (sin habilitar ni deshabilitar módulos)"
install -d -m 755 "$DIR_CONF"
install -m 644 -o root -g root "$PAQ/apache/react-compat-common.conf" "$DIR_CONF/react-compat-common.conf"
install -m 644 -o root -g root "$PAQ/apache/cervesur-react-compat.conf" "$SITE_CONF"
a2ensite "$SITIO" >/dev/null
apache_recargar
ok "configtest correcto y recarga suave (graceful) de Apache; no se reinició el servicio ni se tocaron módulos"
