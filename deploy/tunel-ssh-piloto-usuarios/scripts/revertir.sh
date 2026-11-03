#!/usr/bin/env bash
# Revierte por completo el piloto con usuarios reales (UX-07D.4B.1). Idempotente; tolera instalaciones parciales.
#   sudo bash scripts/revertir.sh        (pide confirmación escrita)
# Elimina ÚNICAMENTE los recursos creados por este entorno, con nombres fijos:
#   sitio cervesur-react-usuarios, /var/www/cervesur-react-usuarios, /opt/cervesur-react-usuarios (backend y .env),
#   /etc/apache2/cervesur-react-usuarios (configuración, certificado y CLAVE PRIVADA TLS), /var/lib/cervesur-react-usuarios.
# NUNCA toca Flutter, 8088, 8089, 8090 ni 8091, los MÓDULOS de Apache (mod_ssl y mod_headers los habilitaron otros), los respaldos ($DIR_RESPALDO), NI MySQL:
# no elimina la base cervesur_portalbi, ni la base de estado (sus sesiones y su auditoría pueden ser necesarias), ni ninguna cuenta. La retirada de las cuentas es el
# procedimiento administrativo aparte 99-revocar-cuentas.sql, que solo puede afectar a las tres cuentas del piloto en 127.0.0.1.
# Revertir deja a TODOS fuera: sin sitio no hay inicio de sesión posible. Las sesiones guardadas en la base de estado quedan inertes; para revocarlas explícitamente
# antes de revertir use  usuarios.sh revocar todos.
set -uo pipefail
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
requerir_root
verificar_no_colision

if [ "${CERVESUR_USUARIOS_CONFIRMAR:-}" != "SI" ]; then
  printf 'Se eliminarán el sitio %s, %s, %s, %s (incluida la clave privada TLS) y %s.\nNo se tocan Flutter, 8088, 8089, 8090, 8091, los módulos de Apache, los respaldos ni MySQL (ninguna base ni cuenta).\n' "$SITIO" "$DIR_FRONT" "$DIR_BACK" "$DIR_CONF" "$DIR_VAR"
  read -r -p "Escriba REVERTIR-8092 para continuar: " respuesta
  [ "$respuesta" = "REVERTIR-8092" ] || abortar "Cancelado. No se modificó nada."
fi

# Borrado con nombre fijo y comprobación explícita: jamás una variable vacía, un comodín ni la ruta de otro portal.
borrar_exacto() {
  local ruta=$1 esperado=$2 otra
  [ "$ruta" = "$esperado" ] && [ -n "$ruta" ] || abortar "Ruta inesperada para borrar: $ruta"
  for otra in "$FLUTTER_DIR" "$DIR_FRONT_PRUEBA" "$DIR_BACK_PRUEBA" "$DIR_CONF_PRUEBA" "$DIR_RESPALDO_PRUEBA" "$DIR_FRONT_COMPAT" "$DIR_BACK_COMPAT" "$DIR_CONF_COMPAT" "$DIR_VAR_COMPAT" "$DIR_RESPALDO_COMPAT" \
    "$DIR_BACK_DIAG" "$DIR_CONF_DIAG" "$DIR_VAR_DIAG" "$DIR_RESPALDO_DIAG" "$DIR_FRONT_VISUAL" "$DIR_BACK_VISUAL" "$DIR_CONF_VISUAL" "$DIR_VAR_VISUAL" "$DIR_RESPALDO_VISUAL" "$DIR_RESPALDO" \
    "/" "/var" "/opt" "/etc" "/etc/apache2" "/var/www" "/var/lib" "/var/backups"; do
    [ "$ruta" != "$otra" ] || abortar "Se negó a borrar $ruta (pertenece a otro portal o es una ruta del sistema)."
  done
  rm -rf -- "$ruta"
}

echo "== Apache (solo el sitio de este entorno; los módulos no se tocan)"
if [ -e "$SITE_ENABLED" ] || [ -L "$SITE_ENABLED" ]; then a2dissite "$SITIO" >/dev/null && ok "sitio $SITIO deshabilitado"; fi
rm -f -- "$SITE_CONF"
borrar_exacto "$DIR_CONF" /etc/apache2/cervesur-react-usuarios
if apache2ctl configtest >/dev/null 2>&1; then apache_recargar && ok "configuración válida y recarga suave de Apache (sin reinicio)"; else fallo "configtest falló tras revertir: NO se recargó Apache; revise /etc/apache2 (hay respaldo en $DIR_RESPALDO)"; fi

echo "== Archivos (incluye el .env, los secretos y la clave privada TLS)"
borrar_exacto "$DIR_FRONT" /var/www/cervesur-react-usuarios
borrar_exacto "$DIR_BACK" /opt/cervesur-react-usuarios
borrar_exacto "$DIR_VAR" /var/lib/cervesur-react-usuarios
ok "eliminados $DIR_FRONT, $DIR_BACK, $DIR_VAR y $DIR_CONF"

echo "== MySQL: sin cambios"
ok "no se elimina la base $DB_NOMBRE, ni la base de estado $DB_ESTADO, ni las cuentas $DB_USUARIO_AUTH, $DB_USUARIO_CATALOGO y $DB_USUARIO_ESTADO (su retirada, si procede, es un procedimiento administrativo independiente)"

echo "== Comprobación final"
if [ -z "$(ss -ltnH "sport = :$PUERTO")" ]; then ok "el puerto $PUERTO ya no está en escucha"; else fallo "el puerto $PUERTO sigue en escucha"; fi
if [ -f "$DIR_RESPALDO/linea-base-portales.txt" ]; then
  if huella_igual_a_linea_base; then ok "Flutter, 8088, 8089, 8090, 8091, módulos de Apache, bases y cuentas MySQL idénticos a la línea base"; else fallo "la huella de los demás portales difiere de la línea base: revise"; fi
fi
ok "los respaldos se conservan en $DIR_RESPALDO"
