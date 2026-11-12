#!/usr/bin/env bash
# Cervesur UX-08B3: verificar si reporte.grupo_id puede pasar de NOT NULL a NULL.
# NO ejecuta ALTER, INSERT, UPDATE, DELETE ni toca .env / Apache / Flutter.
# Ejecutar en VPS: sudo bash preflight-ux08b3-solo-lectura.sh [ruta/respaldo.sql.gz]
set -euo pipefail

DB="cervesur_portalbi"
BACKUP="${1:-}"
STATUS=PASS
fail() { echo "$1=NO_GO"; STATUS=NO_GO; }
pass() { echo "$1=PASS"; }
echo "=== CERVESUR UX-08B3: PREFLIGHT SOLO LECTURA ==="

if [ "$(id -u)" -ne 0 ]; then
  echo "UX08B3_ROOT=REQUIERE_SUDO"
  exit 2
fi
if ! command -v mysql >/dev/null 2>&1; then
  echo "UX08B3_MYSQL=NO_GO_SIN_CLIENTE"
  exit 2
fi

# El cliente administrativo del VPS ya se autentica mediante la configuracion local.
# Evitar imprimir usuarios, contrasenas, filas, enlaces o contenido de las tablas.
sql() { mysql --batch --skip-column-names --default-character-set=utf8mb4 -e "$1" 2>/dev/null; }

if ! INFO=$(sql "SELECT CONCAT_WS('|', LOWER(c.COLUMN_TYPE), c.IS_NULLABLE,
       COALESCE(c.CHARACTER_SET_NAME, ''), COALESCE(c.COLLATION_NAME, ''),
       COALESCE(t.TABLE_COLLATION, ''), t.ENGINE)
  FROM information_schema.COLUMNS c
  JOIN information_schema.TABLES t ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME
  WHERE c.TABLE_SCHEMA='$DB' AND c.TABLE_NAME='reporte' AND c.COLUMN_NAME='grupo_id'
    AND t.TABLE_TYPE='BASE TABLE'"); then
  fail UX08B3_METADATA
  echo "UX08B3_PREFLIGHT=$STATUS"
  exit 1
fi
if [ -z "$INFO" ] || [ "$(printf '%s\n' "$INFO" | wc -l)" -ne 1 ]; then
  fail UX08B3_COLUMNA
  echo "UX08B3_PREFLIGHT=$STATUS"
  exit 1
fi
IFS='|' read -r TYPE NULLABLE CHARSET COLLATION TABLE_COLLATION ENGINE <<<"$INFO"
echo "UX08B3_TIPO=$TYPE"
echo "UX08B3_NULLABLE=$NULLABLE"
echo "UX08B3_MOTOR=$ENGINE"
if [ "$TYPE" = varchar\(50\) ] && [ "$CHARSET" = utf8mb4 ] &&
   [ "$COLLATION" = "$TABLE_COLLATION" ] && [ "$ENGINE" = InnoDB ]; then
  pass UX08B3_ESQUEMA_COMPATIBLE
else
  fail UX08B3_ESQUEMA_COMPATIBLE
fi

if [ "$NULLABLE" = NO ]; then
  echo "UX08B3_MIGRACION=PENDIENTE"
elif [ "$NULLABLE" = YES ]; then
  echo "UX08B3_MIGRACION=YA_APLICADA"
else
  fail UX08B3_NULLABLE
fi

if COUNTS=$(sql "SELECT CONCAT(COUNT(*),'|',COALESCE(SUM(grupo_id IS NULL),0)) FROM \`$DB\`.\`reporte\`"); then
  IFS='|' read -r TOTAL NULOS <<<"$COUNTS"
  echo "UX08B3_REPORTES_EXISTENTES=$TOTAL"
  echo "UX08B3_GRUPOS_NULOS=$NULOS"
  pass UX08B3_CONTEO
else
  fail UX08B3_CONTEO
fi

if [ -n "$BACKUP" ]; then
  if [ -s "$BACKUP" ] && gzip -t "$BACKUP" >/dev/null 2>&1; then
    pass UX08B3_BACKUP_GZIP
  else
    fail UX08B3_BACKUP_GZIP
  fi
  if [ -f "$BACKUP" ] && [ -n "$(find "$BACKUP" -mmin -1440 -print)" ]; then
    pass UX08B3_BACKUP_24H
  else
    fail UX08B3_BACKUP_24H
  fi
  if [ -s "$BACKUP" ]; then
    # No se muestra contenido del dump. Leer solo nombres de sentencia, no datos.
    COVERAGE=$(gzip -cd "$BACKUP" 2>/dev/null | awk '
      /^CREATE TABLE `reporte`/ { ddl=1 }
      /^INSERT INTO `reporte`/ { datos=1 }
      END { printf "%d|%d", ddl+0, datos+0 }') || COVERAGE="0|0"
    if [ "$COVERAGE" = "1|1" ]; then
      pass UX08B3_BACKUP_REPORTE_DDL_DATOS
    else
      fail UX08B3_BACKUP_REPORTE_DDL_DATOS
    fi
  fi
else
  echo "UX08B3_BACKUP=NO_EVALUADO"
fi

echo "UX08B3_PREFLIGHT=$STATUS"
echo "UX08B3_ESCRITURAS_REALIZADAS=NO"
echo "UX08B3_FLUTTER_VALIDADO=NO_EVALUADO"
echo "UX08B3_ALTER_EJECUTADO=NO"
[ "$STATUS" = PASS ]
