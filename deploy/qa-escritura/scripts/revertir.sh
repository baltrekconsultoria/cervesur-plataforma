#!/usr/bin/env bash
# Revierte por completo el entorno de ESCRITURA ADMINISTRATIVA de QA (UX-08H). Idempotente; tolera instalaciones parciales.
#   sudo bash scripts/revertir.sh                     (pide confirmación escrita)
#   sudo bash scripts/revertir.sh --conservar-base    (deja la base y la cuenta MySQL de este entorno, con su marca)
# El puerto es el que la instalación registró en $DIR_VAR/puerto (un CERVESUR_QAE_PUERTO distinto aborta sin hacer nada).
# Elimina ÚNICAMENTE lo que el instalador creó y registró en $DIR_VAR/estado-instalacion, y SOLO si el recurso conserva la marca de ESA instalación:
#   sitio cervesur-react-escritura-qa, /etc/apache2/cervesur-react-escritura-qa, /opt/cervesur-react-escritura-qa, /var/www/cervesur-react-escritura-qa,
#   /var/lib/cervesur-react-escritura-qa, la base y la cuenta MySQL marcadas.
# Sin marca de instalación NO borra nada. NUNCA toca otros sitios, otras bases, otras cuentas, los MÓDULOS de Apache ni los respaldos ($DIR_RESPALDO),
# que se conservan siempre (con un registro de cada reversión).
set -uo pipefail
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
requerir_root
bloquear_ejecucion "${BASH_SOURCE[0]}"
bloqueo_global || abortar "Hay otra instalación o reversión de este entorno en curso ($LOCK_GLOBAL). No se modificó nada."
verificar_no_colision
CONSERVAR_BASE=0
[ "${1:-}" = "--conservar-base" ] && CONSERVAR_BASE=1

# Registro de la reversión (solo si ya hay carpeta de respaldos: nunca se crea una para revertir).
if [ -d "$DIR_RESPALDO" ]; then
  LOG="$DIR_RESPALDO/revertir-$(date +%Y%m%d-%H%M%S).log"
  : > "$LOG"; chmod 600 "$LOG"
  exec > >(tee -a "$LOG") 2>&1
  echo "Registro de esta reversión: $LOG"
fi

errores=0
refusal() { fallo "$*"; errores=$((errores + 1)); }

if ! TOKEN=$(marca_token) || [ -z "$TOKEN" ]; then
  aviso "No hay una instalación marcada de este entorno ($DIR_VAR/$MARCA_NOMBRE): NO se elimina nada."
  for r in "$DIR_FRONT" "$DIR_BACK" "$DIR_CONF" "$DIR_VAR" "$SITE_CONF" "$SITE_ENABLED"; do
    if [ -e "$r" ] || [ -L "$r" ]; then aviso "existe $r pero no pertenece a una instalación marcada de este paquete: se deja intacto"; fi
  done
  exit 0
fi
if [ -n "${CERVESUR_QAE_TOKEN_ESPERADO:-}" ] && [ "$CERVESUR_QAE_TOKEN_ESPERADO" != "$TOKEN" ]; then
  abortar "La instalación marcada en $DIR_VAR no es la que este proceso creó (otro proceso o intento): NO se revierte nada."
fi
[ -f "$DIR_VAR/$ESTADO_NOMBRE" ] || abortar "Falta $DIR_VAR/$ESTADO_NOMBRE: no se puede saber qué creó la instalación. No se elimina nada."
mapfile -t ITEMS < "$DIR_VAR/$ESTADO_NOMBRE"

if [ "${CERVESUR_QAE_CONFIRMAR:-}" != "SI" ]; then
  printf 'Se eliminarán (solo lo marcado por la instalación): %s%s.\nNo se tocan otros portales, otras bases, otras cuentas, los módulos de Apache ni los respaldos.\n' \
    "${ITEMS[*]}" "$([ "$CONSERVAR_BASE" -eq 1 ] && echo ' (se conservan la base y la cuenta)')"
  read -r -p "Escriba REVERTIR-QA-ESCRITURA para continuar: " respuesta
  [ "$respuesta" = "REVERTIR-QA-ESCRITURA" ] || abortar "Cancelado. No se modificó nada."
fi

# Borrado con nombre fijo y comprobación explícita de la marca: jamás una variable vacía, un comodín ni la ruta de otro portal.
borrar_marcado() { # ruta esperada
  local ruta=$1 esperado=$2 otra
  [ "$ruta" = "$esperado" ] && [ -n "$ruta" ] || { refusal "Ruta inesperada para borrar: $ruta"; return 1; }
  for otra in "${DIRS_AJENOS[@]}" "$DIR_RESPALDO" "/" "/var" "/opt" "/etc" "/var/www" "/var/lib" "/etc/apache2"; do
    [ "$ruta" != "$otra" ] || { refusal "Se negó a borrar $ruta (pertenece a otro portal o es una ruta del sistema)."; return 1; }
  done
  [ -e "$ruta" ] || { ok "$ruta ya no existe"; return 0; }
  directorio_marcado "$ruta" "$TOKEN" || { refusal "NO se borra $ruta: no tiene la marca de esta instalación."; return 1; }
  rm -rf -- "$ruta" && ok "eliminado $ruta"
}

quitar_sitio() {
  if [ -e "$SITE_CONF" ] || [ -L "$SITE_CONF" ]; then
    if grep -qxF "# TOKEN-INSTALACION: $TOKEN" "$SITE_CONF" 2>/dev/null; then
      if [ -e "$SITE_ENABLED" ] || [ -L "$SITE_ENABLED" ]; then a2dissite "$SITIO" >/dev/null && ok "sitio $SITIO deshabilitado"; fi
      rm -f -- "$SITE_CONF" && ok "eliminado $SITE_CONF"
    else
      refusal "NO se borra $SITE_CONF: no tiene la marca de esta instalación."
    fi
  else
    ok "$SITE_CONF ya no existe"
  fi
}

mapfile -t REVERSA < <(printf '%s\n' "${ITEMS[@]}" | tac)
echo "== Elementos registrados por la instalación (se revierten en orden inverso): ${REVERSA[*]}"
restantes=()
for item in "${REVERSA[@]}"; do
  case "$item" in
    sitio) quitar_sitio ;;
    conf)
      borrar_marcado "$DIR_CONF" /etc/apache2/cervesur-react-escritura-qa
      if apache2ctl configtest >/dev/null 2>&1; then apache_recargar && ok "configuración válida y recarga suave de Apache (sin reinicio)"; else refusal "configtest falló tras revertir: NO se recargó Apache; revise /etc/apache2 (hay respaldo en $DIR_RESPALDO)"; fi ;;
    usuario)
      if [ "$CONSERVAR_BASE" -eq 1 ]; then aviso "se conservan las cuentas ${USUARIOS_QA[*]}"; restantes+=(usuario)
      elif ! mysql_activo; then refusal "MySQL no está activo: no se eliminaron las cuentas"
      else
        for cuenta in "${USUARIOS_QA[@]}"; do
          atributo=$(mysql_admin -N -e "SELECT ATTRIBUTE FROM information_schema.USER_ATTRIBUTES WHERE USER = '$cuenta' AND HOST = '$DB_HOST_USUARIO'" 2>/dev/null)
          if [ -z "$atributo" ]; then ok "la cuenta $cuenta@$DB_HOST_USUARIO ya no existe (o no tiene marca)"
          elif grep -qF "cervesur-react-escritura-qa:$TOKEN" <<<"$atributo"; then mysql_admin -e "DROP USER '$cuenta'@'$DB_HOST_USUARIO'" && ok "cuenta $cuenta@$DB_HOST_USUARIO eliminada"
          else refusal "NO se elimina la cuenta $cuenta@$DB_HOST_USUARIO: no tiene la marca de esta instalación."; fi
        done
      fi ;;
    db|estado)
      if [ "$item" = db ]; then bd=$DB_NOMBRE; else bd=$DB_ESTADO; fi
      if [ "$CONSERVAR_BASE" -eq 1 ]; then aviso "se conserva la base $bd"; restantes+=("$item")
      elif ! mysql_activo; then refusal "MySQL no está activo: no se eliminó la base $bd"
      elif [ -z "$(mysql_admin -N -e "SHOW DATABASES LIKE '$bd'")" ]; then ok "la base $bd ya no existe"
      elif [ "$(mysql_admin -N -e "SELECT token FROM \`$bd\`.$TABLA_MARCA" 2>/dev/null)" = "$TOKEN" ]; then mysql_admin -e "DROP DATABASE \`$bd\`" && ok "base $bd eliminada"
      else refusal "NO se elimina la base $bd: no tiene la marca de esta instalación."; fi ;;

    back) borrar_marcado "$DIR_BACK" /opt/cervesur-react-escritura-qa ;;
    front) borrar_marcado "$DIR_FRONT" /var/www/cervesur-react-escritura-qa ;;
    var) : ;;   # se procesa al final: contiene la marca y el estado
    *) refusal "elemento desconocido en el estado de la instalación: $item (no se toca)" ;;
  esac
done

echo "== Estado y sesiones"
if [ "$errores" -eq 0 ]; then
  if [ "${#restantes[@]}" -gt 0 ]; then
    rm -rf -- "$DIR_SESIONES"
    printf 'var\n%s\n' "${restantes[@]}" > "$DIR_VAR/$ESTADO_NOMBRE"
    ok "se conservan solo la marca y el estado ($DIR_VAR) para poder revertir la base y la cuenta más adelante"
  else
    borrar_marcado "$DIR_VAR" /var/lib/cervesur-react-escritura-qa
  fi
else
  aviso "hubo rechazos: se conserva $DIR_VAR (marca y estado) para diagnóstico"
fi

echo "== Comprobación final"
if [ -z "$PUERTO" ]; then aviso "la instalación no registró su puerto: no se puede comprobar que haya dejado de escuchar (indique CERVESUR_QAE_PUERTO=N para comprobarlo)"
elif [ -z "$(ss -ltnH "sport = :$PUERTO")" ]; then ok "el puerto $PUERTO (el registrado por la instalación) ya no está en escucha"; else fallo "el puerto $PUERTO sigue en escucha"; errores=$((errores + 1)); fi
if [ "$errores" -eq 0 ] && [ "${#restantes[@]}" -eq 0 ] && [ -f "$DIR_RESPALDO/linea-base-portales.txt" ]; then
  if [ "$(huella_servidor)" = "$(cat "$DIR_RESPALDO/linea-base-portales.txt")" ]; then ok "el servidor (Flutter, 443, 8443, 8088, 8089, Apache byte a byte, módulos, bases y cuentas MySQL) es IDÉNTICO a la línea base"; else fallo "la huella del servidor difiere de la línea base: revise"; errores=$((errores + 1)); fi
fi
ok "los respaldos y este registro se conservan en $DIR_RESPALDO"
[ "$errores" -eq 0 ]
