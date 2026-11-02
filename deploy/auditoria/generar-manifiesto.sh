#!/usr/bin/env bash
# Genera, desde el repositorio, la referencia contra la que se compara el servidor:
#   manifiesto-backend.txt  → «sha256  ruta» de cada archivo PHP de backend/ (contenido SIN retornos de carro)
#   rutas-repo.txt          → patrones de ruta que declaran los routers
# Se ejecuta en la raíz del repositorio. No contiene secretos. Debe regenerarse en cada entrega (la primera línea registra la versión).
set -eu
cd "$(dirname "$0")/../.."
OUT="deploy/auditoria"
VERSION="$(git rev-parse --short HEAD 2>/dev/null || echo desconocida)"

{
  echo "# Manifiesto del backend del repositorio · commit ${VERSION}"
  git ls-files 'backend/*.php' | sort | while read -r f; do
    printf '%s  %s\n' "$(tr -d '\r' < "$f" | sha256sum | cut -c1-64)" "$f"
  done
} > "$OUT/manifiesto-backend.txt"

{
  echo "# Rutas declaradas por los routers del repositorio · commit ${VERSION}"
  git ls-files 'backend/router/*.php' | sort | while read -r f; do tr -d '\r' < "$f"; done \
    | grep -oE "\\\$uri *(===|==) *'/api[^']*'|preg_match\\('[^']*/api[^']*'" | sed -E 's/ +/ /g' | sort -u
} > "$OUT/rutas-repo.txt"

echo "Manifiesto: $(grep -vc '^#' "$OUT/manifiesto-backend.txt") archivos · Rutas: $(grep -vc '^#' "$OUT/rutas-repo.txt") patrones · commit ${VERSION}"
