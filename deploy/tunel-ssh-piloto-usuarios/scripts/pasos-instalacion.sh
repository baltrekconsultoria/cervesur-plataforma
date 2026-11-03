#!/usr/bin/env bash
# Pasos de instalación del piloto con usuarios reales (los invoca scripts/instalar.sh tras los preflights y las confirmaciones).
# Corre como proceso aparte con «set -e»: cualquier fallo devuelve error y instalar.sh revierte. No toca Flutter, 8088, 8089, 8090, 8091, los módulos de Apache
# ni ningún usuario, base o privilegio de MySQL.
set -euo pipefail
PAQ=${1:?paquete}
ARCHIVO_CLAVES=${2:?archivo con las claves de las tres cuentas}
INFORMES=${3:-NINGUNO}
TLS_CERT=${4:-}
TLS_CLAVE=${5:-}
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

echo "== 3. Directorio de trabajo exclusivo"
install -d -m 755 -o root -g root "$DIR_VAR"
install -d -m 700 -o www-data -g www-data "$DIR_SESIONES"
ok "carpeta de sesiones PHP $DIR_SESIONES (modo 700, www-data; el modo no abre sesiones PHP: solo aísla open_basedir)"

echo "== 4. TLS: el certificado debe existir y ser válido ANTES de levantar el sitio"
if [ -n "$TLS_CERT" ]; then bash "$PAQ/scripts/tls.sh" instalar-propio "$TLS_CERT" "$TLS_CLAVE"; else bash "$PAQ/scripts/tls.sh" generar; fi
bash "$PAQ/scripts/tls.sh" verificar

echo "== 5. .env (640 root:www-data; secretos propios generados al azar, no se muestran)"
CLAVE_AUTH=$(valor_de_archivo "$ARCHIVO_CLAVES" CERVESUR_RO_AUTH | tr -d '\r\n')
CLAVE_CAT=$(valor_de_archivo "$ARCHIVO_CLAVES" CERVESUR_RO_CATALOGO | tr -d '\r\n')
CLAVE_EST=$(valor_de_archivo "$ARCHIVO_CLAVES" CERVESUR_REACT_ESTADO | tr -d '\r\n')
[ -n "$CLAVE_AUTH" ] && [ -n "$CLAVE_CAT" ] && [ -n "$CLAVE_EST" ] || abortar "El archivo de claves no contiene las tres claves."
JWT=$(generar_secreto); secreto_valido <<<"$JWT" || abortar "El secreto generado no es válido (error interno)."
PILOT=$(generar_secreto); secreto_valido <<<"$PILOT" || abortar "El secreto del piloto generado no es válido (error interno)."
IDS=$INFORMES; [ "$IDS" != "NINGUNO" ] || IDS=""
(
  umask 077
  printf 'DB_HOST=127.0.0.1\nDB_NAME=%s\nDB_USER=%s\nDB_PASS=%s\nDB_CHARSET=utf8mb4\nAUTH_DB_USER=%s\nAUTH_DB_PASS=%s\nSTATE_DB_NAME=%s\nSTATE_DB_USER=%s\nSTATE_DB_PASS=%s\nCERVESUR_MODE=piloto_usuarios\nCERVESUR_REQUIRED_MODE=piloto_usuarios\nCERVESUR_PRODUCTION_DBS=%s\nJWT_SECRET=%s\nCERVESUR_PILOT_SECRET=%s\nCERVESUR_PILOT_VISOR_IDS=%s\nCERVESUR_PILOT_SESSION_SECONDS=3600\nCERVESUR_PILOT_MAX_FALLOS_IP=100\n' \
    "$DB_NOMBRE" "$DB_USUARIO_CATALOGO" "$CLAVE_CAT" "$DB_USUARIO_AUTH" "$CLAVE_AUTH" "$DB_ESTADO" "$DB_USUARIO_ESTADO" "$CLAVE_EST" "$DB_NOMBRE" "$JWT" "$PILOT" "$IDS" > "$ENV_FILE"
)
chown root:www-data "$ENV_FILE"; chmod 640 "$ENV_FILE"
unset CLAVE_AUTH CLAVE_CAT CLAVE_EST JWT PILOT
ok ".env creado en $ENV_FILE (modo piloto_usuarios exigido; sin CERVESUR_INSECURE_TESTS; sin HSTS; no se muestran valores)"

echo "== 6. Apache: sitio independiente con TLS en 127.0.0.1:$PUERTO (sin habilitar ni deshabilitar módulos)"
install -d -m 755 "$DIR_CONF"
install -m 644 -o root -g root "$PAQ/apache/react-usuarios-common.conf" "$DIR_CONF/react-usuarios-common.conf"
install -m 644 -o root -g root "$PAQ/apache/cervesur-react-usuarios.conf" "$SITE_CONF"
a2ensite "$SITIO" >/dev/null
apache_recargar
ok "configtest correcto y recarga suave (graceful) de Apache; no se reinició el servicio ni se tocaron módulos"
