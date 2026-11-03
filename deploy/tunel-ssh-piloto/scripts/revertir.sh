#!/usr/bin/env bash
# Revierte por completo el piloto visual (UX-07D.4). Idempotente; tolera instalaciones parciales.
#   sudo bash scripts/revertir.sh        (pide confirmación escrita)
# Elimina ÚNICAMENTE los recursos creados por este entorno, con nombres fijos:
#   sitio cervesur-react-piloto, /var/www/cervesur-react-piloto, /opt/cervesur-react-piloto (backend, .env, clave del operador),
#   /etc/apache2/cervesur-react-piloto, /var/lib/cervesur-react-piloto.
# NUNCA toca Flutter, 8088, 8089 ni 8090, los MÓDULOS de Apache (mod_headers lo habilitó el instalador de 8088), los respaldos ($DIR_RESPALDO), NI MySQL:
# no elimina la base cervesur_portalbi ni ninguna cuenta (tampoco cervesur_ro_visor: su retirada es un procedimiento administrativo aparte, 99-revocar-cuenta-visor.sql,
# que solo puede afectar a cervesur_ro_visor@127.0.0.1).
set -uo pipefail
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
requerir_root
verificar_no_colision

if [ "${CERVESUR_PILOTO_CONFIRMAR:-}" != "SI" ]; then
  printf 'Se eliminarán el sitio %s, %s, %s, %s y %s.\nNo se tocan Flutter, 8088, 8089, 8090, los módulos de Apache, los respaldos ni MySQL (ninguna base ni cuenta).\n' "$SITIO" "$DIR_FRONT" "$DIR_BACK" "$DIR_CONF" "$DIR_VAR"
  read -r -p "Escriba REVERTIR-8091 para continuar: " respuesta
  [ "$respuesta" = "REVERTIR-8091" ] || abortar "Cancelado. No se modificó nada."
fi

# Borrado con nombre fijo y comprobación explícita: jamás una variable vacía, un comodín ni la ruta de otro portal.
borrar_exacto() {
  local ruta=$1 esperado=$2 otra
  [ "$ruta" = "$esperado" ] && [ -n "$ruta" ] || abortar "Ruta inesperada para borrar: $ruta"
  for otra in "$FLUTTER_DIR" "$DIR_FRONT_PRUEBA" "$DIR_BACK_PRUEBA" "$DIR_CONF_PRUEBA" "$DIR_RESPALDO_PRUEBA" "$DIR_FRONT_COMPAT" "$DIR_BACK_COMPAT" "$DIR_CONF_COMPAT" "$DIR_VAR_COMPAT" "$DIR_RESPALDO_COMPAT" \
    "$DIR_BACK_DIAG" "$DIR_CONF_DIAG" "$DIR_VAR_DIAG" "$DIR_RESPALDO_DIAG" "$DIR_RESPALDO" "/" "/var" "/opt" "/etc" "/etc/apache2" "/var/www" "/var/lib" "/var/backups"; do
    [ "$ruta" != "$otra" ] || abortar "Se negó a borrar $ruta (pertenece a otro portal o es una ruta del sistema)."
  done
  rm -rf -- "$ruta"
}

echo "== Apache (solo el sitio de este entorno; los módulos no se tocan)"
if [ -e "$SITE_ENABLED" ] || [ -L "$SITE_ENABLED" ]; then a2dissite "$SITIO" >/dev/null && ok "sitio $SITIO deshabilitado"; fi
rm -f -- "$SITE_CONF"
borrar_exacto "$DIR_CONF" /etc/apache2/cervesur-react-piloto
if apache2ctl configtest >/dev/null 2>&1; then apache_recargar && ok "configuración válida y recarga suave de Apache (sin reinicio)"; else fallo "configtest falló tras revertir: NO se recargó Apache; revise /etc/apache2 (hay respaldo en $DIR_RESPALDO)"; fi

echo "== Archivos (incluye el .env, el secreto JWT y la clave del operador)"
borrar_exacto "$DIR_FRONT" /var/www/cervesur-react-piloto
borrar_exacto "$DIR_BACK" /opt/cervesur-react-piloto
borrar_exacto "$DIR_VAR" /var/lib/cervesur-react-piloto
ok "eliminados $DIR_FRONT, $DIR_BACK y $DIR_VAR"

echo "== MySQL: sin cambios"
ok "no se elimina la base $DB_NOMBRE ni la cuenta $DB_USUARIO (su retirada, si procede, es un procedimiento administrativo independiente)"

echo "== Comprobación final"
if [ -z "$(ss -ltnH "sport = :$PUERTO")" ]; then ok "el puerto $PUERTO ya no está en escucha"; else fallo "el puerto $PUERTO sigue en escucha"; fi
if [ -f "$DIR_RESPALDO/linea-base-portales.txt" ]; then
  if huella_igual_a_linea_base; then ok "Flutter, 8088, 8089, 8090, módulos de Apache, bases y cuentas MySQL idénticos a la línea base"; else fallo "la huella de los demás portales difiere de la línea base: revise"; fi
fi
ok "los respaldos se conservan en $DIR_RESPALDO"
