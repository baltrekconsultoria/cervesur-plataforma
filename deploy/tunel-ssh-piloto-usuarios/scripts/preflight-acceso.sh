#!/usr/bin/env bash
# PREFLIGHT B · ACCESO del piloto con usuarios reales (UX-07D.4B.1): las TRES cuentas MySQL y la base de estado. SOLO LECTURA: no crea ni modifica cuentas,
# privilegios ni datos (no escribe ni siquiera en la base de estado).
#   sudo bash scripts/preflight-acceso.sh --claves-archivo /root/cervesur-usuarios.claves
# El archivo de claves (root, modo 600/400) tiene tres líneas CLAVE=valor: CERVESUR_RO_AUTH, CERVESUR_RO_CATALOGO y CERVESUR_REACT_ESTADO. Lo genera
# admin-mysql/preparar-sql-cuentas.sh. Las claves JAMÁS se pasan por argumentos ni se imprimen.
# Códigos de salida:  0 = PASS · 1 = FAIL (alguna cuenta existe pero no cumple) · 3 = PENDIENTE (faltan cuentas, base de estado o claves: NO es un PASS)
set -uo pipefail
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
requerir_root

ARCHIVO_CLAVES=${CERVESUR_USUARIOS_CLAVES_FILE:-}
BACKEND_PHP=$PAQ/backend
while [ $# -gt 0 ]; do
  case $1 in
    --claves-archivo) ARCHIVO_CLAVES=${2:-}; shift 2 ;;
    --backend) BACKEND_PHP=${2:-}; shift 2 ;;
    *) abortar "Argumento desconocido: $1" ;;
  esac
done

echo "== Preflight B · acceso con las cuentas $DB_USUARIO_AUTH, $DB_USUARIO_CATALOGO y $DB_USUARIO_ESTADO (solo lectura)"
faltan=""
for cuenta in "$DB_USUARIO_AUTH" "$DB_USUARIO_CATALOGO" "$DB_USUARIO_ESTADO"; do
  # shellcheck disable=SC2086
  n=$($MYSQL_ADMIN -N -e "SELECT COUNT(*) FROM mysql.user WHERE user = '$cuenta'" 2>/dev/null) || { fallo "No se pudo leer la lista de cuentas de MySQL (metadatos)."; exit 1; }
  if [ "$n" = "0" ]; then faltan="$faltan $cuenta"; else ok "la cuenta $cuenta existe ($n entrada(s) en mysql.user; solo metadatos)"; fi
done
# shellcheck disable=SC2086
existe_estado=$($MYSQL_ADMIN -N -e "SHOW DATABASES LIKE '$DB_ESTADO'" 2>/dev/null)
[ -n "$existe_estado" ] || faltan="$faltan base:$DB_ESTADO"
if [ -n "$faltan" ]; then
  echo "PENDIENTE  faltan (deben crearlos un administrador con el procedimiento independiente admin-mysql/):$faltan. No se declara ningún PASS."
  echo "RESULTADO preflight B (acceso): PENDIENTE"
  exit 3
fi

if [ -z "$ARCHIVO_CLAVES" ] || [ ! -f "$ARCHIVO_CLAVES" ]; then
  echo "PENDIENTE  falta el archivo con las claves de las cuentas (--claves-archivo): no se puede comprobar el acceso. No se declara ningún PASS."
  echo "RESULTADO preflight B (acceso): PENDIENTE"
  exit 3
fi
# El archivo debe ser de root y no legible por otros: las claves no pueden quedar expuestas.
perm=$(stat -c '%a %U' "$ARCHIVO_CLAVES")
case $perm in "600 root"|"400 root") ok "archivo de claves con permisos restrictivos ($perm)" ;; *) fallo "El archivo de claves debe ser de root con modo 600 o 400 (es: $perm)."; exit 1 ;; esac
CLAVE_AUTH=$(valor_de_archivo "$ARCHIVO_CLAVES" CERVESUR_RO_AUTH | tr -d '\r\n')
CLAVE_CATALOGO=$(valor_de_archivo "$ARCHIVO_CLAVES" CERVESUR_RO_CATALOGO | tr -d '\r\n')
CLAVE_ESTADO=$(valor_de_archivo "$ARCHIVO_CLAVES" CERVESUR_REACT_ESTADO | tr -d '\r\n')
if [ -z "$CLAVE_AUTH" ] || [ -z "$CLAVE_CATALOGO" ] || [ -z "$CLAVE_ESTADO" ]; then fallo "El archivo de claves no contiene las tres claves."; exit 1; fi
if [ "$CLAVE_AUTH" = "$CLAVE_CATALOGO" ] || [ "$CLAVE_AUTH" = "$CLAVE_ESTADO" ] || [ "$CLAVE_CATALOGO" = "$CLAVE_ESTADO" ]; then fallo "Las tres claves deben ser distintas."; exit 1; fi

errores=0
# Cuentas homónimas o con roles: cada entrada de mysql.user con estos nombres se audita. Las dos de lectura deben ser SOLO SELECT; la de estado, DML solo en su base.
# shellcheck disable=SC2086
while IFS=$'\t' read -r usuario host; do
  [ -n "$usuario" ] || continue
  concesiones=$($MYSQL_ADMIN -N -e "SHOW GRANTS FOR '$usuario'@'$host'" 2>/dev/null) || { fallo "No se pudieron leer las concesiones de '$usuario'@'$host'"; errores=$((errores + 1)); continue; }
  if [ "$usuario" = "$DB_USUARIO_ESTADO" ]; then
    if printf '%s\n' "$concesiones" | CERVESUR_DIR="$BACKEND_PHP" CERVESUR_ESTADO_DB="$DB_ESTADO" php -r 'require getenv("CERVESUR_DIR") . "/lib/estado_piloto.php"; $g = array_filter(explode("\n", stream_get_contents(STDIN))); $v = estado_grants_violations($g, getenv("CERVESUR_ESTADO_DB")); if ($v) { fwrite(STDERR, implode(", ", $v) . "\n"); exit(1); }' 2>/dev/null; then
      ok "'$usuario'@'$host': solo SELECT/INSERT/UPDATE/DELETE en $DB_ESTADO (nada en la base de lectura, sin DDL, administración, roles ni GRANT OPTION)"
    else
      fallo "'$usuario'@'$host' tiene privilegios fuera de $DB_ESTADO o distintos de DML"; errores=$((errores + 1))
    fi
  elif printf '%s\n' "$concesiones" | CERVESUR_DIR="$BACKEND_PHP" php -r 'require getenv("CERVESUR_DIR") . "/lib/mode.php"; $g = array_filter(explode("\n", stream_get_contents(STDIN))); $v = app_grants_violations($g); if ($v) { fwrite(STDERR, implode(", ", $v) . "\n"); exit(1); }' 2>/dev/null; then
    ok "'$usuario'@'$host': solo SELECT/USAGE (sin escritura, administración, roles ni GRANT OPTION)"
  else
    fallo "'$usuario'@'$host' tiene privilegios que NO son de solo lectura"; errores=$((errores + 1))
  fi
done < <($MYSQL_ADMIN -N -e "SELECT user, host FROM mysql.user WHERE user IN ('$DB_USUARIO_AUTH', '$DB_USUARIO_CATALOGO', '$DB_USUARIO_ESTADO')")
# shellcheck disable=SC2086
roles=$($MYSQL_ADMIN -N -e "SELECT COUNT(*) FROM mysql.role_edges WHERE to_user IN ('$DB_USUARIO_AUTH', '$DB_USUARIO_CATALOGO', '$DB_USUARIO_ESTADO')" 2>/dev/null || echo 0)
if [ "$roles" = "0" ]; then ok "sin roles concedidos a las cuentas"; else fallo "alguna cuenta tiene roles concedidos ($roles)"; errores=$((errores + 1)); fi

# Comprobaciones hechas CON las cuentas (autenticación, columnas exactas, sesión de solo lectura, catálogos…). Las claves viajan solo en el entorno del proceso hijo.
if CERVESUR_RO_AUTH_PASS=$CLAVE_AUTH CERVESUR_RO_CATALOGO_PASS=$CLAVE_CATALOGO CERVESUR_REACT_ESTADO_PASS=$CLAVE_ESTADO DB_HOST=127.0.0.1 DB_NAME=$DB_NOMBRE STATE_DB_NAME=$DB_ESTADO \
   AUTH_DB_USER=$DB_USUARIO_AUTH CATALOGO_DB_USER=$DB_USUARIO_CATALOGO STATE_DB_USER=$DB_USUARIO_ESTADO php "$PAQ/scripts/preflight-acceso.php" "$BACKEND_PHP"; then :; else errores=$((errores + 1)); fi
unset CLAVE_AUTH CLAVE_CATALOGO CLAVE_ESTADO

if [ "$errores" -eq 0 ]; then echo "RESULTADO preflight B (acceso): PASS"; exit 0; fi
echo "RESULTADO preflight B (acceso): FAIL" >&2
exit 1
