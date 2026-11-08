#!/usr/bin/env bash
# Pasos de instalación del piloto visual (los invoca scripts/instalar.sh tras los preflights y las confirmaciones).
# Corre como proceso aparte con «set -e»: cualquier fallo devuelve error y instalar.sh revierte. No toca Flutter, 8088, 8089, 8090, los módulos de Apache
# ni ningún usuario, base o privilegio de MySQL.
set -euo pipefail
PAQ=${1:?paquete}
ARCHIVO_CLAVE=${2:?archivo con la clave de la cuenta del piloto}
INFORMES=${3:-NINGUNO}
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
copiar_solo_lectura "$PAQ/public" "$DIR_PUBLIC"
copiar_solo_lectura "$PAQ/backend" "$DIR_BACKEND"
ok "aplicación del piloto en $DIR_PUBLIC (sin pantallas de administración) y backend en $DIR_BACKEND (fuera del DocumentRoot)"

echo "== 3. Directorios de trabajo exclusivos"
install -d -m 755 -o root -g root "$DIR_VAR"
install -d -m 700 -o www-data -g www-data "$DIR_ESTADO" "$DIR_SESIONES"
ok "estado de intentos $DIR_ESTADO y sesiones $DIR_SESIONES (modo 700, www-data)"

echo "== 4. Credenciales del operador y .env (640 root:www-data; secretos propios generados al azar, no se muestran)"
CLAVE_DB=$(head -n 1 "$ARCHIVO_CLAVE" | tr -d '\r\n')
[ -n "$CLAVE_DB" ] || abortar "La clave de la cuenta está vacía."
CLAVE_OP=$(generar_clave_operador); clave_operador_valida <<<"$CLAVE_OP" || abortar "La clave del operador generada no es válida (error interno)."
HASH_OP=$(hash_bcrypt "$CLAVE_OP"); [[ $HASH_OP == '$2y$'* ]] || abortar "No se pudo generar el hash bcrypt (error interno)."
JWT=$(generar_secreto); secreto_valido <<<"$JWT" || abortar "El secreto JWT generado no es válido (error interno)."
IDS=$INFORMES; [ "$IDS" != "NINGUNO" ] || IDS=""
(
  umask 077
  printf 'DB_HOST=127.0.0.1\nDB_NAME=%s\nDB_USER=%s\nDB_PASS=%s\nDB_CHARSET=utf8mb4\nCERVESUR_MODE=piloto_visual\nCERVESUR_REQUIRED_MODE=piloto_visual\nCERVESUR_PRODUCTION_DBS=%s\nJWT_SECRET=%s\nCERVESUR_PILOT_OPERATOR_USER=%s\nCERVESUR_PILOT_OPERATOR_HASH=%s\nCERVESUR_PILOT_STATE_DIR=%s\nCERVESUR_PILOT_VISOR_IDS=%s\nCERVESUR_PILOT_SESSION_SECONDS=3600\n' \
    "$DB_NOMBRE" "$DB_USUARIO" "$CLAVE_DB" "$DB_NOMBRE" "$JWT" "$OPERADOR" "$HASH_OP" "$DIR_ESTADO" "$IDS" > "$ENV_FILE"
  printf '%s\n' "$CLAVE_OP" > "$CLAVE_OPERADOR_FILE"
)
chown root:www-data "$ENV_FILE"; chmod 640 "$ENV_FILE"
chown root:root "$CLAVE_OPERADOR_FILE"; chmod 600 "$CLAVE_OPERADOR_FILE"
unset CLAVE_DB CLAVE_OP HASH_OP JWT
ok ".env creado en $ENV_FILE y clave del operador en $CLAVE_OPERADOR_FILE (no se muestran valores)"

echo "== 5. Apache: sitio independiente en 127.0.0.1:$PUERTO (sin habilitar ni deshabilitar módulos)"
install -d -m 755 "$DIR_CONF"
install -m 644 -o root -g root "$PAQ/apache/react-piloto-common.conf" "$DIR_CONF/react-piloto-common.conf"
install -m 644 -o root -g root "$PAQ/apache/cervesur-react-piloto.conf" "$SITE_CONF"
a2ensite "$SITIO" >/dev/null
apache_recargar
ok "configtest correcto y recarga suave (graceful) de Apache; no se reinició el servicio ni se tocaron módulos"
