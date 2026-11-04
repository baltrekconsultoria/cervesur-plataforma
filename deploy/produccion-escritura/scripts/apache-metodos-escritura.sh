#!/usr/bin/env bash
# Cervesur · UX-08H.1 · OPCIONAL Y APARTE · permite PUT y DELETE en el Apache del administrador (requisito del Paso B). NINGÚN otro script lo llama.
#   sudo bash scripts/apache-metodos-escritura.sh --simular      muestra qué cambiaría y NO escribe nada
#   sudo bash scripts/apache-metodos-escritura.sh                pide escribir APACHE-PERMITIR-PUT-DELETE; respalda, cambia UNA línea, configtest, recarga SUAVE y verifica
#   sudo bash scripts/apache-metodos-escritura.sh --revertir     vuelve a «GET POST» desde el respaldo y recarga
# POR QUÉ EXISTE: la configuración común del administrador (react-usuarios-common.conf, incluida por el sitio 8093 y por la publicación 8443) tiene
#   <Location "/"> <LimitExcept GET POST> Require all denied </LimitExcept> </Location>
# Con eso PUT y DELETE reciben 403 de Apache ANTES de llegar a PHP; el administrador usa PUT (editar empresas e informes) y DELETE (dar de baja, revocar). Sin este cambio el Paso B no puede
# funcionar (A1 y A2 sí). interruptores.sh --habilitar se NIEGA mientras Apache rechace PUT/DELETE.
# ESTE CAMBIO ES DE APACHE, así que NO forma parte de A1/A2/B y requiere AUTORIZACIÓN EXPRESA Y SEPARADA. Qué hace exactamente:
#   - cambia la línea «<LimitExcept GET POST>» por «<LimitExcept GET POST PUT DELETE>» (solo esa; PATCH, OPTIONS, TRACE y demás siguen rechazados); se niega si la línea no es única;
#   - respaldo SHA-256 verificado en /var/backups/cervesur-react-admin/apache (700/600, no se borra); escritura atómica conservando propietario y modo;
#   - apache2ctl configtest antes de recargar; recarga SUAVE (graceful), nunca reinicio; verifica que el administrador sigue respondiendo y que un PUT a un id inexistente ya llega a PHP
#     (JSON, no el 403 HTML de Apache); si algo falla, restaura el archivo y recarga;
#   - no toca los demás sitios, los módulos, Flutter (80), trabajadores (443) ni la QA (8094), y compara la huella de /etc/apache2 FUERA de ese archivo antes y después.
# La restricción de redes del 8443 («Require ip», con AuthMerging And) sigue aplicándose: el cambio no abre el sitio a otras redes.
# Variables de apoyo (ensayos): CERVESUR_CONFIRMAR_APACHE=SI.
set -uo pipefail
case ${1:-} in --ayuda|-h|--help) sed -n '2,/^set -/{/^set -/!s/^# \{0,1\}//p}' "${BASH_SOURCE[0]}"; exit 0 ;; esac
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
requerir_root

MODO=real
while [ $# -gt 0 ]; do case $1 in --simular) MODO=simular; shift ;; --revertir) MODO=revertir; shift ;; *) abortar "Argumento desconocido: $1 (use --ayuda)" ;; esac; done
COMUN="$DIR_CONF/react-usuarios-common.conf"
DIR_AP="$DIR_RESPALDO/apache"
[ -f "$COMUN" ] || abortar "No existe $COMUN."
huella_resto() { find /etc/apache2 \( -type f -o -type l \) ! -path "$COMUN" -print0 | LC_ALL=C sort -z | xargs -0 sha256sum 2>/dev/null | sha256sum | cut -c1-64; }
recargar() { apache2ctl configtest >/dev/null 2>&1 || return 1; if con_systemd; then systemctl reload apache2; else apache2ctl graceful; fi; }
instalar_archivo() { # origen → COMUN atómico, conservando propietario y modo
  local t; t=$(mktemp "$DIR_CONF/.comun.XXXXXX") || return 1
  if ! { cat "$1" > "$t" && chown --reference="$COMUN" "$t" && chmod --reference="$COMUN" "$t" && mv -f "$t" "$COMUN"; }; then rm -f "$t"; return 1; fi
}
sonda_llega_a_php() { # un PUT a un id inexistente debe devolver JSON del backend (no el 403 HTML de Apache)
  local cuerpo codigo
  codigo=$(curl -sS -m 15 "${CURL_TLS[@]}" -X PUT -H 'Content-Type: application/json' --data '{}' -o /tmp/.sonda-put.$$ -w '%{http_code}' "$URL_ADMIN/api/empresas/999999999" 2>/dev/null) || codigo=000
  cuerpo=$(cat /tmp/.sonda-put.$$ 2>/dev/null); rm -f /tmp/.sonda-put.$$
  [ "$codigo" != 000 ] && grep -q '"code"\|"success"\|"error"' <<<"$cuerpo"
}

if [ "$MODO" = revertir ]; then
  ultimo=""; for cand in "$DIR_AP"/react-usuarios-common.conf.*; do case $cand in *.sha256) ;; *) [ -f "$cand" ] && ultimo=$cand ;; esac; done
  [ -n "$ultimo" ] && [ -f "$ultimo.sha256" ] && (cd "$DIR_AP" && sha256sum -c --quiet "$(basename "$ultimo").sha256") >/dev/null 2>&1 || abortar "No hay un respaldo verificado de la configuración en $DIR_AP."
  if [ "${CERVESUR_CONFIRMAR_APACHE:-}" != SI ]; then read -r -p "Escriba APACHE-REVERTIR-METODOS para restaurar «GET POST»: " c; [ "$c" = "APACHE-REVERTIR-METODOS" ] || abortar "Cancelado. No se modificó nada."; fi
  instalar_archivo "$ultimo" || abortar "No se pudo restaurar el archivo."
  recargar || abortar "configtest/recarga fallaron tras restaurar: revise Apache a mano (respaldo: $ultimo)."
  ok "configuración restaurada desde $ultimo y Apache recargado (suave)"; exit 0
fi

n=$(grep -c '^[[:space:]]*<LimitExcept GET POST>[[:space:]]*$' "$COMUN")
if grep -qE '^[[:space:]]*<LimitExcept GET POST PUT DELETE>[[:space:]]*$' "$COMUN"; then ok "Apache ya admite PUT y DELETE: no hay nada que cambiar."; exit 0; fi
[ "$n" = 1 ] || abortar "Se esperaba exactamente UNA línea «<LimitExcept GET POST>» en $COMUN y hay $n: no se toca (la configuración difiere de la fase 1)."
echo "== Cambio previsto en $COMUN"
echo "   <LimitExcept GET POST>  →  <LimitExcept GET POST PUT DELETE>"
echo "   respaldo en $DIR_AP, configtest, recarga suave y verificación; el resto de /etc/apache2 no cambia."
[ "$MODO" != simular ] || { ok "SIMULACIÓN terminada: no se escribió nada."; exit 0; }
apache2ctl configtest >/dev/null 2>&1 || abortar "apache2ctl configtest ya falla antes del cambio: no se toca nada."
if [ "${CERVESUR_CONFIRMAR_APACHE:-}" != SI ]; then read -r -p "Escriba APACHE-PERMITIR-PUT-DELETE para continuar: " c; [ "$c" = "APACHE-PERMITIR-PUT-DELETE" ] || abortar "Cancelado. No se modificó nada."; fi

install -d -m 700 "$DIR_RESPALDO" "$DIR_AP" || abortar "No se pudo crear $DIR_AP."
copia="$DIR_AP/react-usuarios-common.conf.$(sello_utc)"
( umask 077; cp -p "$COMUN" "$copia" && (cd "$DIR_AP" && sha256sum "$(basename "$copia")" > "$copia.sha256") ) && (cd "$DIR_AP" && sha256sum -c --quiet "$(basename "$copia").sha256") >/dev/null 2>&1 || abortar "No se pudo crear un respaldo verificable: no se modificó nada."
chmod 600 "$copia" "$copia.sha256"
ok "respaldo verificado en $copia"
resto_antes=$(huella_resto)
nuevo=$(mktemp) || abortar "No se pudo crear un archivo temporal."
trap 'rm -f "$nuevo"' EXIT
sed 's/^\([[:space:]]*\)<LimitExcept GET POST>\([[:space:]]*\)$/\1<LimitExcept GET POST PUT DELETE>\2/' "$COMUN" > "$nuevo" || abortar "No se pudo preparar el archivo nuevo."
volver() { fallo "$1: se restaura la configuración anterior."; instalar_archivo "$copia" && recargar && ok "configuración anterior restaurada" || fallo "NO se pudo restaurar automáticamente: copie a mano $copia sobre $COMUN y recargue Apache."; exit 1; }
instalar_archivo "$nuevo" || volver "No se pudo instalar el archivo nuevo"
recargar || volver "configtest/recarga falló"
sleep 2
respuesta=$(curl -sS -m 10 "${CURL_TLS[@]}" "$URL_ADMIN/api/ping" 2>/dev/null); grep -q '"pong":true' <<<"$respuesta" || volver "el administrador no responde tras el cambio"
sonda_llega_a_php || volver "un PUT a un id inexistente sigue sin llegar a PHP"
[ "$(huella_resto)" = "$resto_antes" ] || volver "cambió algo más de /etc/apache2"
ok "Apache admite PUT y DELETE en el administrador (un PUT inexistente ya llega a PHP); el resto de /etc/apache2 quedó idéntico. Para deshacer: sudo bash scripts/apache-metodos-escritura.sh --revertir"
