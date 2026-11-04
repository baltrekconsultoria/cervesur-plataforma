#!/usr/bin/env bash
# Cervesur · UX-08H · E0 · Filtro sanitizador autónomo: lee stdin y escribe stdout con enlaces, hashes, tokens y secretos enmascarados.
# Uso:  cat salida.txt | bash sanear.sh > salida-saneada.txt
set -u
AQUI="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
sed -E -f "$AQUI/sanear.sed" | awk -f "$AQUI/sanear.awk"
