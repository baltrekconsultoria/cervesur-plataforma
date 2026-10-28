#!/usr/bin/env bash
# Revierte por completo el entorno de COMPATIBILIDAD (UX-07D.2C). Idempotente; tolera instalaciones parciales.
#   sudo bash scripts/revertir.sh                     (pide confirmación escrita)
#   sudo bash scripts/revertir.sh --conservar-base    (deja la base y el usuario MySQL de compatibilidad)
# Elimina ÚNICAMENTE los recursos creados por este entorno, con nombres fijos:
#   sitio cervesur-react-compat, /var/www/cervesur-react-compat, /opt/cervesur-react-compat, /etc/apache2/cervesur-react-compat,
#   /var/lib/cervesur-react-compat, base cervesur_react_compat_qa y usuario cervesur_react_compat.
# NUNCA toca Flutter, el React de 8088 (ni su base), los MÓDULOS de Apache (mod_headers lo habilitó el instalador de 8088 y aquí se queda como está)
# ni los respaldos ($DIR_RESPALDO), que se conservan siempre.
set -uo pipefail
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
requerir_root
verificar_no_colision
CONSERVAR_BASE=0
[ "${1:-}" = "--conservar-base" ] && CONSERVAR_BASE=1

if [ "${CERVESUR_COMPAT_CONFIRMAR:-}" != "SI" ]; then
  printf 'Se eliminarán el sitio %s, %s, %s, %s, %s%s.\nNo se tocan Flutter, el React de 8088, los módulos de Apache ni los respaldos.\n' \
    "$SITIO" "$DIR_FRONT" "$DIR_BACK" "$DIR_CONF" "$DIR_VAR" "$([ "$CONSERVAR_BASE" -eq 0 ] && echo ", la base $DB_NOMBRE y el usuario $DB_USUARIO")"
  read -r -p "Escriba REVERTIR-COMPAT para continuar: " respuesta
  [ "$respuesta" = "REVERTIR-COMPAT" ] || abortar "Cancelado. No se modificó nada."
fi

# Borrado con nombre fijo y comprobación explícita: jamás una variable vacía, un comodín ni la ruta de otro portal.
borrar_exacto() {
  local ruta=$1 esperado=$2 otra
  [ "$ruta" = "$esperado" ] && [ -n "$ruta" ] || abortar "Ruta inesperada para borrar: $ruta"
  for otra in "$FLUTTER_DIR" "$DIR_FRONT_PRUEBA" "$DIR_BACK_PRUEBA" "$DIR_CONF_PRUEBA" "$DIR_RESPALDO_PRUEBA" "$DIR_RESPALDO" "/" "/var" "/opt" "/etc" "/var/www" "/var/lib"; do
    [ "$ruta" != "$otra" ] || abortar "Se negó a borrar $ruta (pertenece a otro portal o es una ruta del sistema)."
  done
  rm -rf -- "$ruta"
}

echo "== Apache (solo el sitio de este entorno; los módulos no se tocan)"
if [ -e "$SITE_ENABLED" ] || [ -L "$SITE_ENABLED" ]; then a2dissite "$SITIO" >/dev/null && ok "sitio $SITIO deshabilitado"; fi
rm -f -- "$SITE_CONF"
borrar_exacto "$DIR_CONF" /etc/apache2/cervesur-react-compat
if apache2ctl configtest >/dev/null 2>&1; then apache_recargar && ok "configuración válida y recarga suave de Apache (sin reinicio)"; else fallo "configtest falló tras revertir: NO se recargó Apache; revise /etc/apache2 (hay respaldo en $DIR_RESPALDO)"; fi

echo "== Archivos y sesiones"
borrar_exacto "$DIR_FRONT" /var/www/cervesur-react-compat
borrar_exacto "$DIR_BACK" /opt/cervesur-react-compat
borrar_exacto "$DIR_VAR" /var/lib/cervesur-react-compat
ok "eliminados $DIR_FRONT, $DIR_BACK y $DIR_VAR"

echo "== MySQL (solo la base y el usuario de este entorno)"
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
  aviso "MySQL no está activo: no se eliminó la base de compatibilidad"
fi

echo "== Comprobación final"
if [ -z "$(ss -ltnH "sport = :$PUERTO")" ]; then ok "el puerto $PUERTO ya no está en escucha"; else fallo "el puerto $PUERTO sigue en escucha"; fi
if [ -f "$DIR_RESPALDO/linea-base-portales.txt" ]; then
  if [ "$(huella_portales)" = "$(cat "$DIR_RESPALDO/linea-base-portales.txt")" ]; then ok "Flutter, React 8088, módulos de Apache, bases y usuarios MySQL idénticos a la línea base"; else fallo "la huella de los demás portales difiere de la línea base: revise"; fi
fi
ok "los respaldos se conservan en $DIR_RESPALDO"
