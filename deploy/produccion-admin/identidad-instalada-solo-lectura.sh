#!/usr/bin/env bash
# Cervesur · UX-08G.2 · ¿Lo INSTALADO en el administrador es exactamente el piloto de referencia (paquete cef58da900e8)? SOLO LECTURA.
#   bash identidad-instalada-solo-lectura.sh [--back /opt/cervesur-react-admin/backend] [--public /var/www/cervesur-react-admin/public]
#                                           [--ref-backend <manifiesto>] [--ref-public <manifiesto>]
# Compara hash a hash (a) todos los .php del backend (contenido sin CR) y (b) todos los archivos de la aplicación (bytes) con los manifiestos de referencia
# que acompañan a este script. Imprime SOLO nombres de archivo y recuentos: ni contenido, ni .env (se ignora), ni URLs. No modifica nada.
# Clasificación de cada diferencia (la decide la persona responsable, no este script):
#   DISTINTO  el servidor tiene otra versión del archivo  → cambio local o deriva: NO se actualiza in situ hasta explicarlo y, si es legítimo, conservarlo
#   FALTA     está en la referencia y no en el servidor    → instalación incompleta o archivo borrado
#   SOBRA     está en el servidor y no en la referencia    → componente local (p. ej. un parche): se perdería al reemplazar el directorio
# Código de salida: 0 = idéntico; 1 = hay diferencias (NO-GO para actualizar in situ hasta clasificarlas).
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
BACK=/opt/cervesur-react-admin/backend; PUB=/var/www/cervesur-react-admin/public
RB="$HERE/manifiesto-piloto-cef58da900e8.txt"; RP="$HERE/manifiesto-piloto-public-cef58da900e8.txt"
while [ "$#" -gt 0 ]; do case "$1" in --back) BACK=${2:-}; shift 2 ;; --public) PUB=${2:-}; shift 2 ;; --ref-backend) RB=${2:-}; shift 2 ;; --ref-public) RP=${2:-}; shift 2 ;; *) echo "Opción desconocida: $1"; exit 2 ;; esac; done
[ -f "$RB" ] && [ -f "$RP" ] || { echo "Faltan los manifiestos de referencia junto al script ($RB, $RP)"; exit 2; }
[ -d "$BACK" ] && [ -d "$PUB" ] || { echo "No existe $BACK o $PUB"; exit 2; }
T=$(mktemp -d); trap 'rm -rf "$T"' EXIT
dif=0

echo "################ IDENTIDAD DE LO INSTALADO frente al piloto de referencia (solo lectura) ################"
echo "backend:     $BACK"; echo "aplicación:  $PUB"; echo "referencia:  $(head -1 "$RB" | cut -c1-110)"

# --- backend: .php, contenido sin CR; rutas relativas «backend/…»
grep -v '^#' "$RB" | LC_ALL=C sort -k2 > "$T/ref-b"
( cd "$BACK/.." && find "$(basename "$BACK")" -type f -name '*.php' -not -path '*/vendor/*' | LC_ALL=C sort | while read -r f; do printf '%s  %s\n' "$(tr -d '\r' < "$f" | sha256sum | cut -c1-64)" "$f"; done ) > "$T/srv-b"
sed -i "s#  $(basename "$BACK")/#  backend/#" "$T/srv-b"; LC_ALL=C sort -k2 -o "$T/srv-b" "$T/srv-b"
# --- aplicación: todos los archivos, bytes; rutas «public/…»
grep -v '^#' "$RP" | LC_ALL=C sort -k2 > "$T/ref-p"
( cd "$PUB/.." && find "$(basename "$PUB")" -type f | LC_ALL=C sort | while read -r f; do printf '%s  %s\n' "$(sha256sum "$f" | cut -c1-64)" "$f"; done ) > "$T/srv-p"
sed -i "s#  $(basename "$PUB")/#  public/#" "$T/srv-p"; LC_ALL=C sort -k2 -o "$T/srv-p" "$T/srv-p"

clasificar() { # $1 = ref, $2 = servidor, $3 = etiqueta
  local r=$1 s=$2 n=$3 ident distinto falta sobra
  ident=$(join -j 2 -o 0,1.1,2.1 "$r" "$s" | awk '$2 == $3' | wc -l)
  distinto=$(join -j 2 -o 0,1.1,2.1 "$r" "$s" | awk '$2 != $3 {print $1}')
  falta=$(comm -23 <(awk '{print $2}' "$r" | LC_ALL=C sort) <(awk '{print $2}' "$s" | LC_ALL=C sort))
  sobra=$(comm -13 <(awk '{print $2}' "$r" | LC_ALL=C sort) <(awk '{print $2}' "$s" | LC_ALL=C sort))
  echo; echo "== $n: referencia $(wc -l < "$r") · servidor $(wc -l < "$s") · idénticos $ident"
  [ -n "$distinto" ] && { echo "DISTINTO:"; sed 's/^/    /' <<<"$distinto"; dif=1; }
  [ -n "$falta" ] && { echo "FALTA:"; sed 's/^/    /' <<<"$falta"; dif=1; }
  [ -n "$sobra" ] && { echo "SOBRA:"; sed 's/^/    /' <<<"$sobra"; dif=1; }
  [ -z "$distinto$falta$sobra" ] && echo "(sin diferencias)"
}
clasificar "$T/ref-b" "$T/srv-b" "BACKEND (.php)"
clasificar "$T/ref-p" "$T/srv-p" "APLICACIÓN (public/)"
echo
if [ "$dif" -eq 0 ]; then echo "RESULTADO: IDÉNTICO al piloto de referencia: la actualización in situ no pisa nada que no esté en el paquete original."; echo "(nada fue modificado)"; exit 0; fi
echo "RESULTADO: HAY DIFERENCIAS → NO-GO para actualizar in situ hasta clasificar cada una (DISTINTO/FALTA/SOBRA)."; echo "(nada fue modificado)"; exit 1
