#!/usr/bin/env bash
# Pasos de instalación del entorno de ESCRITURA ADMINISTRATIVA de QA (los invoca scripts/instalar.sh tras las comprobaciones y la confirmación).
# Corre como proceso aparte con «set -e»: cualquier fallo devuelve error y instalar.sh revierte. No toca ningún otro portal, los módulos
# de Apache ni ninguna base o usuario MySQL existente. Cada recurso creado se registra en el estado de la instalación y lleva una marca.
#   pasos-instalacion.sh <paquete> <archivo-de-cuentas> [--con-grupo-nullable]
set -euo pipefail
PAQ=${1:?paquete}
CUENTAS_OUT=${2:?archivo de salida de cuentas}
MIGRACION_B3=0
[ "${3:-}" = "--con-grupo-nullable" ] && MIGRACION_B3=1
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
requerir_root
nombre_base_valido "$DB_NOMBRE"
validar_puerto

TOKEN=${CERVESUR_QAE_TOKEN:-$(openssl rand -hex 16)}
registrar() { printf '%s\n' "$1" >> "$DIR_VAR/$ESTADO_NOMBRE"; }   # el estado lista SOLO lo que este instalador creó
marcar_dir() { printf 'cervesur-react-escritura-qa:%s\n' "$TOKEN" > "$1/$MARCA_NOMBRE"; chmod 600 "$1/$MARCA_NOMBRE"; }
copiar_solo_lectura() { # origen destino: root:root, directorios 755 y archivos 644
  cp -R --no-preserve=all "$1" "$2"
  chown -R root:root "$2"
  find "$2" -type d -exec chmod 755 {} +
  find "$2" -type f -exec chmod 644 {} +
}
# Aplica un archivo SQL con el administrador de MySQL sobre la base de este entorno.
sql_archivo() { mysql_admin "$DB_NOMBRE" < "$1"; }
sql_valor() { mysql_admin -N -B "$DB_NOMBRE" -e "$1"; }

echo "== 2. Estado y sesiones PHP exclusivas (primer recurso: lleva el estado de la instalación)"
# mkdir (no install -d): si la carpeta ya existe falla SIN tocar nada y sale con 3, y el instalador no revierte lo que es de otro proceso.
mkdir -m 755 "$DIR_VAR" 2>/dev/null || { fallo "Ya existe $DIR_VAR: no se crea nada."; exit 3; }
marcar_dir "$DIR_VAR"
: > "$DIR_VAR/$ESTADO_NOMBRE"; chmod 600 "$DIR_VAR/$ESTADO_NOMBRE"
printf '%s\n' "$PUERTO" > "$PUERTO_REGISTRO"; chmod 644 "$PUERTO_REGISTRO"   # lo primero tras la marca: verificar.sh y revertir.sh leen de aquí el puerto exacto
registrar var
install -d -m 700 -o www-data -g www-data "$DIR_SESIONES"
ok "estado en $DIR_VAR y sesiones en $DIR_SESIONES (modo 700, propietario www-data); no se comparten con ningún otro portal"

echo "== 3. Archivos (carpetas nuevas y exclusivas, con marca)"
install -d -m 755 "$DIR_FRONT"; marcar_dir "$DIR_FRONT"; registrar front
install -d -m 755 "$DIR_BACK";  marcar_dir "$DIR_BACK";  registrar back
copiar_solo_lectura "$PAQ/public" "$DIR_FRONT/public"
copiar_solo_lectura "$PAQ/backend" "$DIR_BACK/backend"
ok "frontend en $DIR_FRONT/public y backend en $DIR_BACK/backend (fuera del DocumentRoot)"

echo "== 4. Base de datos DEDICADA, esquema productivo sintético y migraciones (sin tocar ninguna base existente)"
mysql_admin -e "CREATE DATABASE \`$DB_NOMBRE\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
registrar db
mysql_admin "$DB_NOMBRE" <<SQL
CREATE TABLE $TABLA_MARCA (token VARCHAR(64) NOT NULL) ENGINE=InnoDB;
INSERT INTO $TABLA_MARCA (token) VALUES ('$TOKEN');
SQL
ok "base $DB_NOMBRE creada y marcada"

SEMILLA=$(mktemp -d); chmod 700 "$SEMILLA"; trap 'rm -rf "$SEMILLA"' EXIT
( umask 077; php "$PAQ/scripts/sembrar-qa-escritura.php" sql "$SEMILLA/semilla.sql" "$INFORME_SINTETICO_ID" > "$CUENTAS_OUT" )
sql_archivo "$SEMILLA/semilla.sql"
rm -f "$SEMILLA/semilla.sql"
ok "esquema productivo (tipos + UNIQUE productivos) y datos sintéticos cargados"

# Migraciones, en orden y VERIFICADAS una a una (las aplica el administrador; la cuenta de la aplicación nunca tiene DDL).
[ "$(sql_valor "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log'")" = 0 ] || abortar "audit_log ya existía antes de la migración."
sql_archivo "$PAQ/database/migrations/UX-08B-audit-log.up.sql"
[ "$(sql_valor "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log' AND COLUMN_NAME IN ('id','correlation_id','occurred_at','actor_user_id','action','entity','entity_id','before_json','after_json','request_ip')")" = 10 ] || abortar "UX-08B-audit-log no dejó la tabla esperada."
ok "migración UX-08B-audit-log aplicada y verificada (tabla audit_log con sus 10 columnas)"
sql_archivo "$PAQ/database/migrations/UX-08B-audit-log-immutable.up.sql"
[ "$(sql_valor "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'audit_log' AND TRIGGER_NAME IN ('audit_log_no_update','audit_log_no_delete')")" = 2 ] || abortar "UX-08B-audit-log-immutable no dejó los 2 triggers."
ok "migración UX-08B-audit-log-immutable aplicada y verificada (triggers de solo inserción)"
[ "$(sql_valor "SELECT CONCAT(COLUMN_TYPE, '|', IS_NULLABLE) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reporte' AND COLUMN_NAME = 'grupo_id'")" = 'varchar(50)|NO' ] || abortar "grupo_id no está en el estado productivo (VARCHAR(50) NOT NULL) antes de UX-08B3."
if [ "$MIGRACION_B3" -eq 1 ]; then
  sql_archivo "$PAQ/database/migrations/UX-08B3-reporte-grupo-nullable.up.sql"
  [ "$(sql_valor "SELECT CONCAT(COLUMN_TYPE, '|', IS_NULLABLE) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reporte' AND COLUMN_NAME = 'grupo_id'")" = 'varchar(50)|YES' ] || abortar "UX-08B3 no dejó grupo_id VARCHAR(50) NULL."
  ok "migración UX-08B3 aplicada (pedida): reporte.grupo_id VARCHAR(50) NULL"
else
  ok "migración UX-08B3 NO aplicada (por defecto): reporte.grupo_id sigue VARCHAR(50) NOT NULL, como en producción"
fi

echo "== 5. Cuenta MySQL de privilegios mínimos (plantilla UX-08E; contraseña generada aquí, sin mostrarse)"
DB_PASS=$(generar_clave_mysql)
JWT=$(openssl rand -hex 32)
clave_cumple_politica_medium <<<"$DB_PASS" || abortar "La contraseña generada no cumple la política MEDIUM de MySQL (error interno)."
plantilla=$(grep -v '^[[:space:]]*--' "$PAQ/$PLANTILLA_CUENTA")
plantilla=${plantilla//\{DB\}/$DB_NOMBRE}
plantilla=${plantilla//\{USER\}/$DB_USUARIO}
plantilla=${plantilla//\{HOST\}/$DB_HOST_USUARIO}
plantilla=${plantilla//\{PASS\}/$DB_PASS}
case "$plantilla" in *'{'*'}'*) abortar "Quedaron marcadores sin sustituir en la plantilla de privilegios." ;; esac
case "$plantilla" in *"ALL PRIVILEGES"*|*"GRANT OPTION"*|*DELETE*|*portalbi*) abortar "La plantilla de privilegios contiene una concesión no permitida." ;; esac
mysql_admin <<<"$plantilla"
registrar usuario
mysql_admin -e "ALTER USER '$DB_USUARIO'@'$DB_HOST_USUARIO' COMMENT 'cervesur-react-escritura-qa:$TOKEN'"
unset plantilla
ok "cuenta $DB_USUARIO@$DB_HOST_USUARIO creada y marcada (privilegios mínimos; solo sobre $DB_NOMBRE)"

echo "== 6. .env del backend (640 root:www-data; secretos propios generados al azar, no se muestran)"
(
  umask 077
  printf 'DB_HOST=127.0.0.1\nDB_NAME=%s\nDB_USER=%s\nDB_PASS=%s\nDB_CHARSET=utf8mb4\nJWT_SECRET=%s\nJWT_EXPIRATION=3600\nAUDIT_LOG_MODE=required\nREACT_STRICT_VIEWS=1\nUSER_ADMIN_WRITES=1\nADMIN_VIEWER_ALLOWED_REPORT_IDS=%s\n' \
    "$DB_NOMBRE" "$DB_USUARIO" "$DB_PASS" "$JWT" "$VISOR_IDS" > "$DIR_BACK/backend/.env"
)
chown root:www-data "$DIR_BACK/backend/.env"
chmod 640 "$DIR_BACK/backend/.env"
unset DB_PASS JWT
ok ".env creado en $DIR_BACK/backend/.env (AUDIT_LOG_MODE=required, REACT_STRICT_VIEWS=1, USER_ADMIN_WRITES=1 SOLO EN ESTE ENTORNO DE QA)"
php "$PAQ/scripts/sembrar-qa-escritura.php" verificar "$DIR_BACK/backend" >/dev/null
ok "los agregados productivos sintéticos se reproducen leyendo con la cuenta de la aplicación"

echo "== 7. Apache: sitio independiente en 127.0.0.1:$PUERTO (sin habilitar ni deshabilitar módulos)"
install -d -m 755 "$DIR_CONF"; marcar_dir "$DIR_CONF"; registrar conf
install -m 644 -o root -g root "$PAQ/apache/react-escritura-qa-common.conf" "$DIR_CONF/react-escritura-qa-common.conf"
sed "s/@PUERTO@/$PUERTO/g" "$PAQ/apache/cervesur-react-escritura-qa.conf" > "$SITE_CONF"   # plantilla: el único valor que se sustituye es el puerto, ya validado
chmod 644 "$SITE_CONF"; chown root:root "$SITE_CONF"
printf '# TOKEN-INSTALACION: %s\n' "$TOKEN" >> "$SITE_CONF"
registrar sitio
a2ensite "$SITIO" >/dev/null
apache_recargar
ok "configtest correcto y recarga suave (graceful) de Apache; no se reinició el servicio ni se tocaron módulos"
for _ in $(seq 1 20); do escucha_puerto "$PUERTO" && break; sleep 0.5; done
