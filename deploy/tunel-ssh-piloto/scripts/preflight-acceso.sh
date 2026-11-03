#!/usr/bin/env bash
# PREFLIGHT B · ACCESO del piloto visual con la cuenta cervesur_ro_visor (UX-07D.4). SOLO LECTURA: no crea ni modifica cuentas, privilegios ni datos.
#   sudo bash scripts/preflight-acceso.sh --clave-archivo /root/cervesur-ro-visor.clave
# La clave de la cuenta cervesur_ro_visor se lee de un archivo (root, modo 600) o de CERVESUR_RO_VISOR_PASS_FILE; JAMÁS se pasa por argumentos ni se imprime.
# Códigos de salida:  0 = PASS · 1 = FAIL (la cuenta existe pero no cumple) · 3 = PENDIENTE (no hay cuenta o falta su clave: NO es un PASS)
set -uo pipefail
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
requerir_root

ARCHIVO_CLAVE=${CERVESUR_RO_VISOR_PASS_FILE:-}
BACKEND_PHP=$PAQ/backend
while [ $# -gt 0 ]; do
  case $1 in
    --clave-archivo) ARCHIVO_CLAVE=${2:-}; shift 2 ;;
    --backend) BACKEND_PHP=${2:-}; shift 2 ;;
    *) abortar "Argumento desconocido: $1" ;;
  esac
done

echo "== Preflight B · acceso con la cuenta $DB_USUARIO (solo lectura)"
# shellcheck disable=SC2086
cuentas=$($MYSQL_ADMIN -N -e "SELECT COUNT(*) FROM mysql.user WHERE user = '$DB_USUARIO'" 2>/dev/null) || { fallo "No se pudo leer la lista de cuentas de MySQL (metadatos)."; exit 1; }
if [ "$cuentas" = "0" ]; then
  echo "PENDIENTE  la cuenta $DB_USUARIO NO existe: debe crearla un administrador con el procedimiento independiente (admin-mysql/). No se declara ningún PASS."
  echo "RESULTADO preflight B (acceso): PENDIENTE"
  exit 3
fi
ok "la cuenta $DB_USUARIO existe ($cuentas entrada(s) en mysql.user; solo metadatos)"

if [ -z "$ARCHIVO_CLAVE" ] || [ ! -f "$ARCHIVO_CLAVE" ]; then
  echo "PENDIENTE  falta el archivo con la clave de $DB_USUARIO (--clave-archivo): no se puede comprobar el acceso. No se declara ningún PASS."
  echo "RESULTADO preflight B (acceso): PENDIENTE"
  exit 3
fi
# El archivo debe ser de root y no legible por otros: la clave no puede quedar expuesta.
perm=$(stat -c '%a %U' "$ARCHIVO_CLAVE")
case $perm in "600 root"|"400 root") ok "archivo de clave con permisos restrictivos ($perm)" ;; *) fallo "El archivo de clave debe ser de root con modo 600 o 400 (es: $perm)."; exit 1 ;; esac
CLAVE=$(head -n 1 "$ARCHIVO_CLAVE" | tr -d '\r\n')
[ -n "$CLAVE" ] || { fallo "El archivo de clave está vacío."; exit 1; }

errores=0
# Cuentas homónimas o con roles: cada entrada de mysql.user con este nombre debe ser de solo lectura (se auditan TODAS las que existan).
# shellcheck disable=SC2086
while IFS=$'\t' read -r usuario host; do
  [ -n "$usuario" ] || continue
  concesiones=$($MYSQL_ADMIN -N -e "SHOW GRANTS FOR '$usuario'@'$host'" 2>/dev/null) || { fallo "No se pudieron leer las concesiones de '$usuario'@'$host'"; errores=$((errores + 1)); continue; }
  if printf '%s\n' "$concesiones" | CERVESUR_DIR="$BACKEND_PHP" php -r 'require getenv("CERVESUR_DIR") . "/lib/mode.php"; $g = array_filter(explode("\n", stream_get_contents(STDIN))); $v = app_grants_violations($g); if ($v) { fwrite(STDERR, implode(", ", $v) . "\n"); exit(1); }' 2>/dev/null; then
    ok "'$usuario'@'$host': solo SELECT/USAGE (sin escritura, administración, roles ni GRANT OPTION)"
  else
    fallo "'$usuario'@'$host' tiene privilegios que NO son de solo lectura"; errores=$((errores + 1))
  fi
done < <($MYSQL_ADMIN -N -e "SELECT user, host FROM mysql.user WHERE user = '$DB_USUARIO'")
# shellcheck disable=SC2086
roles=$($MYSQL_ADMIN -N -e "SELECT COUNT(*) FROM mysql.role_edges WHERE to_user = '$DB_USUARIO'" 2>/dev/null || echo 0)
if [ "$roles" = "0" ]; then ok "sin roles concedidos a la cuenta"; else fallo "la cuenta tiene roles concedidos ($roles)"; errores=$((errores + 1)); fi

# Comprobaciones hechas CON la cuenta (autenticación, 18 columnas, sesión de solo lectura, catálogo…). La clave viaja solo en el entorno del proceso hijo.
if CERVESUR_RO_VISOR_PASS=$CLAVE DB_HOST=127.0.0.1 DB_NAME=$DB_NOMBRE DB_USER=$DB_USUARIO php "$PAQ/scripts/preflight-acceso.php" "$BACKEND_PHP"; then :; else errores=$((errores + 1)); fi
unset CLAVE

if [ "$errores" -eq 0 ]; then echo "RESULTADO preflight B (acceso): PASS"; exit 0; fi
echo "RESULTADO preflight B (acceso): FAIL" >&2
exit 1
