#!/usr/bin/env bash
# PUBLICACIÓN DE LOS DOS PORTALES POR IP INTERNA EN UNA SOLA INTERVENCIÓN (UX-07E.D): trabajadores en https://IP (443) y administrador en https://IP:8443, sin túneles SSH para los usuarios.
# Orquesta, en este orden y con vuelta atrás automática si algo falla, los scripts ya probados de cada portal:
#   1) lectura: verifica los dos paquetes, simula la actualización de cada portal y ejecuta el preflight de la publicación de cada uno (con --previo: acepta la aplicación del paquete nuevo)
#   2) pide escribir PUBLICAR-IP
#   3) actualiza los dos portales (actualizar.sh: respaldo, verificación y vuelta atrás propios) para que admitan la IP
#   4) publica el de trabajadores y luego el administrador (corporativo.sh instalar: VirtualHost, recarga suave, verificación)
#   5) verificación final de ambos y huella de Flutter y del resto
#
#   sudo bash publicar-ip.sh --trabajadores DIR_PAQUETE_TRABAJADORES --administrador DIR_PAQUETE_ADMIN --ip 192.168.200.12 --redes "192.168.200.0/24 10.8.0.0/24" --cert fullchain.pem --clave servidor.key [--simular]
#   sudo bash publicar-ip.sh revertir --trabajadores DIR --administrador DIR --ip IP [--respaldos "RESP_TRAB RESP_ADMIN"]    retira las dos publicaciones (y, si se indican, restaura las versiones anteriores)
# --simular hace solo el paso 1 (no escribe nada). No modifica Flutter (80), MySQL, DNS ni el cortafuegos; no reinicia Apache; la clave privada no se muestra ni se guarda fuera de /etc/apache2/…/tls-corp.
set -uo pipefail
ok() { printf 'OK     %s\n' "$*"; }
aviso() { printf 'AVISO  %s\n' "$*"; }
fallo() { printf 'FALLO  %s\n' "$*" >&2; }
abortar() { fallo "$*"; exit 1; }
[ "$(id -u)" -eq 0 ] || abortar "Ejecute este script con sudo."

ACCION=publicar; if [ "${1:-}" = revertir ]; then ACCION=revertir; shift; fi
TRAB=""; ADM=""; IP=""; REDES=""; CERT=""; CLAVE=""; SIMULAR=0; RESPALDOS=""
while [ $# -gt 0 ]; do
  case $1 in
    --trabajadores) TRAB=${2:-}; shift 2 ;;
    --administrador) ADM=${2:-}; shift 2 ;;
    --ip) IP=${2:-}; shift 2 ;;
    --redes) REDES=${2:-}; shift 2 ;;
    --cert) CERT=${2:-}; shift 2 ;;
    --clave) CLAVE=${2:-}; shift 2 ;;
    --respaldos) RESPALDOS=${2:-}; shift 2 ;;
    --simular) SIMULAR=1; shift ;;
    *) abortar "Argumento desconocido: $1" ;;
  esac
done
[ -d "$TRAB/scripts" ] && [ -d "$ADM/scripts" ] || abortar "Indique --trabajadores y --administrador con las carpetas de los paquetes ya extraídos."
[ -n "$IP" ] || abortar "Falta --ip."
ARGS_PUB=(--ip "$IP" --redes "$REDES" --cert "$CERT" --clave "$CLAVE")
CONF=(env CERVESUR_USUARIOS_CONFIRMAR=SI)

revertir_publicaciones() {
  echo "== Retirando las dos publicaciones (administrador primero)"
  "${CONF[@]}" bash "$ADM/scripts/corporativo.sh" revertir || true
  "${CONF[@]}" bash "$TRAB/scripts/corporativo.sh" revertir || true
}

if [ "$ACCION" = revertir ]; then
  if [ "${CERVESUR_USUARIOS_CONFIRMAR:-}" != "SI" ]; then read -r -p "Escriba REVERTIR-IP para retirar la publicación por IP de AMBOS portales: " r || r=""; [ "$r" = "REVERTIR-IP" ] || abortar "Cancelado. No se modificó nada."; fi
  revertir_publicaciones
  if [ -n "$RESPALDOS" ]; then
    set -- $RESPALDOS
    [ -n "${1:-}" ] && { CERVESUR_USUARIOS_CONFIRMAR=SI bash "$TRAB/scripts/actualizar.sh" --revertir "$1" || fallo "no se pudo restaurar el portal de trabajadores desde $1"; }
    [ -n "${2:-}" ] && { CERVESUR_USUARIOS_CONFIRMAR=SI bash "$ADM/scripts/actualizar.sh" --revertir "$2" || fallo "no se pudo restaurar el portal administrativo desde $2"; }
  fi
  ok "publicación por IP retirada. El acceso por túnel de ambos portales sigue como antes."
  exit 0
fi
[ -n "$REDES" ] && [ -n "$CERT" ] && [ -n "$CLAVE" ] || abortar "Faltan --redes, --cert y --clave."

echo "== 1/5 Verificación (SOLO LECTURA)"
for d in "$TRAB" "$ADM"; do (cd "$d" && sha256sum -c SHA256SUMS --quiet) || abortar "La integridad de $d (SHA256SUMS) no se verifica."; done
ok "los dos paquetes son íntegros"
bash "$TRAB/scripts/actualizar.sh" --simular >/dev/null 2>&1 && ok "simulación de actualización: trabajadores PASS" || abortar "La simulación de actualización de los trabajadores falló (ejecute actualizar.sh --simular para ver el motivo)."
bash "$ADM/scripts/actualizar.sh" --simular >/dev/null 2>&1 && ok "simulación de actualización: administrador PASS" || abortar "La simulación de actualización del administrador falló."
bash "$TRAB/scripts/corporativo.sh" preflight "${ARGS_PUB[@]}" --previo || abortar "El preflight de la publicación de trabajadores falló: no se cambió nada."
bash "$ADM/scripts/corporativo.sh" preflight "${ARGS_PUB[@]}" --previo || abortar "El preflight de la publicación del administrador falló: no se cambió nada."
ok "PREVIO: todo en orden. Trabajadores → https://$IP · Administrador → https://$IP:8443 (nada se modificó)"
[ "$SIMULAR" -eq 0 ] || { ok "SIMULACIÓN terminada: no se escribió nada. Código 0 = se puede publicar."; exit 0; }

if [ "${CERVESUR_USUARIOS_CONFIRMAR:-}" != "SI" ]; then read -r -p "Escriba PUBLICAR-IP para actualizar y publicar AMBOS portales en $IP (redes: $REDES): " r || r=""; [ "$r" = "PUBLICAR-IP" ] || abortar "Cancelado. No se modificó nada."; fi

echo "== 2/5 Actualizando los portales para que admitan la IP"
RESP_T=""; RESP_A=""
sal=$("${CONF[@]}" bash "$TRAB/scripts/actualizar.sh" 2>&1) || { echo "$sal" | tail -5; abortar "La actualización de trabajadores falló (volvió sola a la versión anterior): no se publicó nada."; }
RESP_T=$(sed -n 's/.*--revertir \([^ ]*\).*/\1/p' <<<"$sal" | tail -1); ok "trabajadores actualizado (respaldo: ${RESP_T:-?})"
sal=$("${CONF[@]}" bash "$ADM/scripts/actualizar.sh" 2>&1) || { echo "$sal" | tail -5; fallo "La actualización del administrador falló: se restaura trabajadores."; [ -n "$RESP_T" ] && CERVESUR_USUARIOS_CONFIRMAR=SI bash "$TRAB/scripts/actualizar.sh" --revertir "$RESP_T"; exit 1; }
RESP_A=$(sed -n 's/.*--revertir \([^ ]*\).*/\1/p' <<<"$sal" | tail -1); ok "administrador actualizado (respaldo: ${RESP_A:-?})"

deshacer() {
  fallo "$1"
  revertir_publicaciones
  [ -n "$RESP_T" ] && CERVESUR_USUARIOS_CONFIRMAR=SI bash "$TRAB/scripts/actualizar.sh" --revertir "$RESP_T" || true
  [ -n "$RESP_A" ] && CERVESUR_USUARIOS_CONFIRMAR=SI bash "$ADM/scripts/actualizar.sh" --revertir "$RESP_A" || true
  fallo "Todo quedó como antes de la intervención."
  exit 1
}

echo "== 3/5 Publicando trabajadores (https://$IP)"
"${CONF[@]}" bash "$TRAB/scripts/corporativo.sh" instalar "${ARGS_PUB[@]}" || deshacer "La publicación de trabajadores falló."
echo "== 4/5 Publicando administrador (https://$IP:8443)"
"${CONF[@]}" bash "$ADM/scripts/corporativo.sh" instalar "${ARGS_PUB[@]}" || deshacer "La publicación del administrador falló."
echo "== 5/5 Verificación final (solo lectura)"
bash "$TRAB/scripts/corporativo.sh" verificar "${ARGS_PUB[@]}" >/dev/null 2>&1 || deshacer "La verificación final de trabajadores falló."
bash "$ADM/scripts/corporativo.sh" verificar "${ARGS_PUB[@]}" >/dev/null 2>&1 || deshacer "La verificación final del administrador falló."
ok "PUBLICADO: trabajadores https://$IP · administrador https://$IP:8443 (redes autorizadas: $REDES)"
echo "Respaldos de la actualización (para revertir): $RESP_T $RESP_A"
echo "Revertir TODO:  sudo bash publicar-ip.sh revertir --trabajadores $TRAB --administrador $ADM --ip $IP --respaldos \"$RESP_T $RESP_A\""
