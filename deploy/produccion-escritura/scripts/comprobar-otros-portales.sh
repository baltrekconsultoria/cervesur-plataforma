#!/usr/bin/env bash
# Cervesur · UX-08H.1 · CONTINUIDAD de los otros portales (SOLO LECTURA): Flutter (80), React de trabajadores (443) y la QA aislada (8094).
#   bash comprobar-otros-portales.sh                      muestra, por portal, el código HTTP y el SHA-256 de su página estática de entrada
#   bash comprobar-otros-portales.sh --guardar ARCHIVO     guarda esa huella (ANTES de una etapa)
#   bash comprobar-otros-portales.sh --comparar ARCHIVO    la compara con la guardada (DESPUÉS); código 1 si algo cambió o dejó de responder
# Solo hace GET a la raíz «/» de cada portal; no imprime el contenido. Flutter (80) y la QA (8094) se consultan por loopback; el React de TRABAJADORES escucha en la IP CORPORATIVA
# (192.168.200.12:443), NO en 127.0.0.1:443, así que se consulta por esa IP. Variables (ensayos u otra IP): CERVESUR_IP_CORPORATIVA, CERVESUR_URL_FLUTTER, CERVESUR_URL_TRABAJADORES, CERVESUR_URL_QA.
# Una página de entrada que cambie por sí sola (por ejemplo, un nonce dinámico) se verá como diferencia: revísela a mano, este script no lo oculta.
set -uo pipefail
case ${1:-} in --ayuda|-h|--help) sed -n '2,/^set -/{/^set -/!s/^# \{0,1\}//p}' "${BASH_SOURCE[0]}"; exit 0 ;; esac

URL_FLUTTER=${CERVESUR_URL_FLUTTER:-http://127.0.0.1:80/}
IP_CORPORATIVA=${CERVESUR_IP_CORPORATIVA:-192.168.200.12}
URL_TRABAJADORES=${CERVESUR_URL_TRABAJADORES:-https://${IP_CORPORATIVA}:443/}
URL_QA=${CERVESUR_URL_QA:-http://127.0.0.1:8094/}
MODO=mostrar; ARCHIVO=""
while [ $# -gt 0 ]; do
  case $1 in
    --guardar) MODO=guardar; ARCHIVO=${2:-}; shift 2 ;;
    --comparar) MODO=comparar; ARCHIVO=${2:-}; shift 2 ;;
    *) echo "Argumento desconocido: $1 (use --ayuda)" >&2; exit 2 ;;
  esac
done
[ "$MODO" = mostrar ] || [ -n "$ARCHIVO" ] || { echo "Falta el archivo (use --ayuda)." >&2; exit 2; }

tmp=$(mktemp -d) || exit 2
trap 'rm -rf "$tmp"' EXIT
huella_de() { # nombre url
  local codigo hash
  codigo=$(curl -sk -m 8 -o "$tmp/cuerpo" -w '%{http_code}' "$2" 2>/dev/null) || codigo=000
  if [ "$codigo" = 000 ]; then hash=sin-respuesta; else hash=$(sha256sum < "$tmp/cuerpo" | cut -c1-64); fi
  printf '%s\t%s\t%s\n' "$1" "$codigo" "$hash"
}
actual=$({ huella_de flutter-80 "$URL_FLUTTER"; huella_de trabajadores-443 "$URL_TRABAJADORES"; huella_de qa-8094 "$URL_QA"; })

sin_respuesta=0
while IFS=$'\t' read -r nombre codigo hash; do
  printf '%-18s HTTP %-4s sha256(/)=%s\n' "$nombre" "$codigo" "${hash:0:16}"
  [ "$codigo" != 000 ] || sin_respuesta=1
done <<<"$actual"

case $MODO in
  mostrar) [ "$sin_respuesta" -eq 0 ] && { echo "RESULTADO: los tres portales responden."; exit 0; }; echo "RESULTADO: algún portal NO responde."; exit 1 ;;
  guardar)
    [ "$sin_respuesta" -eq 0 ] || { echo "RESULTADO: algún portal no responde: no se guarda la huella."; exit 1; }
    ( umask 077; printf '%s\n' "$actual" > "$ARCHIVO" ) || { echo "No se pudo escribir $ARCHIVO" >&2; exit 1; }
    echo "RESULTADO: huella guardada en $ARCHIVO."; exit 0 ;;
  comparar)
    [ -f "$ARCHIVO" ] || { echo "No existe $ARCHIVO" >&2; exit 2; }
    if [ "$actual" = "$(cat "$ARCHIVO")" ]; then echo "RESULTADO: PASS · Flutter 80, trabajadores 443 y QA 8094 idénticos a la huella guardada."; exit 0; fi
    echo "RESULTADO: FAIL · la huella de otros portales CAMBIÓ:"; diff <(cat "$ARCHIVO") <(printf '%s\n' "$actual") | cut -c1-120; exit 1 ;;
esac
