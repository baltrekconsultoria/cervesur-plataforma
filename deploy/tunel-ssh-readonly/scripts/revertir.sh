#!/usr/bin/env bash
# Revierte por completo el diagnóstico de solo lectura (UX-07D.3B). Idempotente; tolera instalaciones parciales.
#   sudo bash scripts/revertir.sh        (pide confirmación escrita)
# Elimina ÚNICAMENTE los recursos creados por este entorno, con nombres fijos:
#   sitio cervesur-react-ro, /opt/cervesur-react-ro (backend y .env con el token), /etc/apache2/cervesur-react-ro, /var/lib/cervesur-react-ro.
# NUNCA toca Flutter, 8088 ni 8089, los MÓDULOS de Apache (mod_headers lo habilitó el instalador de 8088), los respaldos ($DIR_RESPALDO), NI MySQL:
# no elimina la base cervesur_portalbi ni ninguna cuenta (tampoco cervesur_ro_diag: su retirada es un procedimiento administrativo aparte, 99-revocar-cuenta-diagnostico.sql, que solo puede afectar a cervesur_ro_diag@127.0.0.1).
set -uo pipefail
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
requerir_root
verificar_no_colision

if [ "${CERVESUR_RO_CONFIRMAR:-}" != "SI" ]; then
  printf 'Se eliminarán el sitio %s, %s, %s y %s.\nNo se tocan Flutter, 8088, 8089, los módulos de Apache, los respaldos ni MySQL (ninguna base ni cuenta).\n' "$SITIO" "$DIR_BACK" "$DIR_CONF" "$DIR_VAR"
  read -r -p "Escriba REVERTIR-8090 para continuar: " respuesta
  [ "$respuesta" = "REVERTIR-8090" ] || abortar "Cancelado. No se modificó nada."
fi

# Borrado con nombre fijo y comprobación explícita: jamás una variable vacía, un comodín ni la ruta de otro portal.
borrar_exacto() {
  local ruta=$1 esperado=$2 otra
  [ "$ruta" = "$esperado" ] && [ -n "$ruta" ] || abortar "Ruta inesperada para borrar: $ruta"
  for otra in "$FLUTTER_DIR" "$DIR_FRONT_PRUEBA" "$DIR_BACK_PRUEBA" "$DIR_CONF_PRUEBA" "$DIR_RESPALDO_PRUEBA" "$DIR_FRONT_COMPAT" "$DIR_BACK_COMPAT" "$DIR_CONF_COMPAT" "$DIR_VAR_COMPAT" "$DIR_RESPALDO_COMPAT" "$DIR_RESPALDO" "/" "/var" "/opt" "/etc" "/etc/apache2" "/var/www" "/var/lib" "/var/backups"; do
    [ "$ruta" != "$otra" ] || abortar "Se negó a borrar $ruta (pertenece a otro portal o es una ruta del sistema)."
  done
  rm -rf -- "$ruta"
}

echo "== Apache (solo el sitio de este entorno; los módulos no se tocan)"
if [ -e "$SITE_ENABLED" ] || [ -L "$SITE_ENABLED" ]; then a2dissite "$SITIO" >/dev/null && ok "sitio $SITIO deshabilitado"; fi
rm -f -- "$SITE_CONF"
borrar_exacto "$DIR_CONF" /etc/apache2/cervesur-react-ro
if apache2ctl configtest >/dev/null 2>&1; then apache_recargar && ok "configuración válida y recarga suave de Apache (sin reinicio)"; else fallo "configtest falló tras revertir: NO se recargó Apache; revise /etc/apache2 (hay respaldo en $DIR_RESPALDO)"; fi

echo "== Archivos (incluye el .env con el token de diagnóstico)"
borrar_exacto "$DIR_BACK" /opt/cervesur-react-ro
borrar_exacto "$DIR_VAR" /var/lib/cervesur-react-ro
ok "eliminados $DIR_BACK y $DIR_VAR"

echo "== MySQL: sin cambios"
ok "no se elimina la base $DB_NOMBRE ni la cuenta $DB_USUARIO (su retirada, si procede, es un procedimiento administrativo independiente)"

echo "== Comprobación final"
if [ -z "$(ss -ltnH "sport = :$PUERTO")" ]; then ok "el puerto $PUERTO ya no está en escucha"; else fallo "el puerto $PUERTO sigue en escucha"; fi
if [ -f "$DIR_RESPALDO/linea-base-portales.txt" ]; then
  if [ "$(huella_portales)" = "$(cat "$DIR_RESPALDO/linea-base-portales.txt")" ]; then ok "Flutter, 8088, 8089, módulos de Apache, bases y cuentas MySQL idénticos a la línea base"; else fallo "la huella de los demás portales difiere de la línea base: revise"; fi
fi
ok "los respaldos se conservan en $DIR_RESPALDO"
