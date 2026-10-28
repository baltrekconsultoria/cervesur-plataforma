#!/usr/bin/env bash
# Revierte por completo la instalación de PRUEBA de React. Es idempotente y tolera instalaciones parciales.
#   sudo bash scripts/revertir.sh                     (pide confirmación escrita)
#   sudo bash scripts/revertir.sh --conservar-base    (deja la base y el usuario MySQL de prueba)
# Solo elimina las rutas, la base y el usuario de PRUEBA con nombre fijo. NUNCA toca /var/www/cervesur, cervesur.conf ni el
# directorio de respaldos ($DIR_RESPALDO), que se conserva siempre.
set -uo pipefail
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
requerir_root
CONSERVAR_BASE=0
[ "${1:-}" = "--conservar-base" ] && CONSERVAR_BASE=1

if [ "${CERVESUR_CONFIRMAR:-}" != "SI" ]; then
  printf 'Se eliminarán el sitio %s, %s, %s, la configuración de Apache de la prueba%s.\nNo se tocan Flutter ni los respaldos.\n' \
    "$SITIO" "$DIR_FRONT" "$DIR_BACK" "$([ "$CONSERVAR_BASE" -eq 0 ] && echo ', la base y el usuario MySQL de prueba')"
  read -r -p "Escriba REVERTIR para continuar: " respuesta
  [ "$respuesta" = "REVERTIR" ] || abortar "Cancelado. No se modificó nada."
fi

echo "== Apache"
if [ -e "/etc/apache2/sites-enabled/$SITIO.conf" ]; then a2dissite "$SITIO" >/dev/null && ok "sitio $SITIO deshabilitado"; fi
rm -f -- "$SITE_CONF"
rm -rf -- "${DIR_CONF:?}"
if [ -e "$DIR_RESPALDO/headers-habilitado-por-instalador" ]; then
  a2dismod headers >/dev/null && ok "mod_headers deshabilitado (lo había habilitado este instalador)"
  rm -f -- "$DIR_RESPALDO/headers-habilitado-por-instalador"
fi
if apache2ctl configtest >/dev/null 2>&1; then apache_recargar && ok "configuración válida y recarga suave de Apache"; else fallo "configtest falló tras revertir: NO se recargó Apache; revise /etc/apache2 (hay respaldo en $DIR_RESPALDO)"; fi

echo "== Archivos"
rm -rf -- "${DIR_FRONT:?}" "${DIR_BACK:?}"
ok "eliminados $DIR_FRONT y $DIR_BACK"

echo "== MySQL (solo la base y el usuario de PRUEBA)"
if [ "$CONSERVAR_BASE" -eq 1 ]; then
  aviso "se conservan la base $DB_NOMBRE y el usuario $DB_USUARIO"
elif mysql_activo; then
  # shellcheck disable=SC2086
  $MYSQL_ADMIN <<SQL && ok "base $DB_NOMBRE y usuario $DB_USUARIO eliminados"
DROP DATABASE IF EXISTS \`$DB_NOMBRE\`;
DROP USER IF EXISTS '$DB_USUARIO'@'localhost';
DROP USER IF EXISTS '$DB_USUARIO'@'127.0.0.1';
SQL
else
  aviso "MySQL no está activo: no se eliminó la base de prueba"
fi

echo "== Comprobación final"
if [ -z "$(ss -ltnH "sport = :$PUERTO")" ]; then ok "el puerto $PUERTO ya no está en escucha"; else fallo "el puerto $PUERTO sigue en escucha"; fi
if [ -f "$DIR_RESPALDO/linea-base-flutter.txt" ]; then
  if [ "$(huella_flutter)" = "$(cat "$DIR_RESPALDO/linea-base-flutter.txt")" ]; then ok "Flutter idéntico a la línea base (configuración, archivos y cabeceras)"; else fallo "la huella de Flutter difiere de la línea base: revise"; fi
fi
ok "los respaldos se conservan en $DIR_RESPALDO"
