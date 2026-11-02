#!/usr/bin/env bash
# Prueba local (sin servidor) del inventario de divergencia: simula un «servidor» a partir de backend/ con tres diferencias conocidas
# (un componente exclusivo con dependencias y una URL, un archivo modificado y un archivo ausente) y comprueba que se detectan, que el
# contenido de los archivos y las URL NO se imprimen y que nada del «servidor» se modifica.
# shellcheck disable=SC2016,SC2034  # check evalúa expresiones pasadas entre comillas simples a propósito; before/after/IGUALES se usan dentro de ellas
set -eu
cd "$(dirname "$0")/../.."
bash deploy/auditoria/generar-manifiesto.sh >/dev/null
SRV="$(mktemp -d)"; trap 'rm -rf "$SRV"' EXIT
cp -R backend/. "$SRV/"
cat > "$SRV/lib/piloto_catalogo.php" <<'PHP'
<?php
require_once __DIR__ . '/estado_piloto.php';
function piloto_informe_aprobado($id) { return $id === 72; }
$enlace = 'https://app.powerbi.com/view?r=SECRETO-QUE-NO-DEBE-SALIR';
PHP
printf '\n// divergencia del despliegue\n' >> "$SRV/lib/views.php"
rm "$SRV/lib/errors.php"
before="$(find "$SRV" -type f -exec sha256sum {} + | sort | sha256sum)"
OUT="$(bash deploy/auditoria/inventario-divergencia.sh "$SRV")"
after="$(find "$SRV" -type f -exec sha256sum {} + | sort | sha256sum)"
fail=0; check() { if eval "$2"; then echo "PASS $1"; else echo "FAIL $1"; fail=1; fi; }
check "detecta el componente exclusivo del servidor" 'echo "$OUT" | sed -n "/== 1\./,/== 2\./p" | grep -q "backend/lib/piloto_catalogo.php"'
check "detecta el archivo modificado" 'echo "$OUT" | sed -n "/== 2\./,/== 3\./p" | grep -q "backend/lib/views.php"'
check "detecta el archivo que falta en el servidor" 'echo "$OUT" | sed -n "/== 3\./,/== 4\./p" | grep -q "backend/lib/errors.php"'
check "muestra sus dependencias por nombre" 'echo "$OUT" | grep -q "estado_piloto.php" && echo "$OUT" | grep -q "piloto_informe_aprobado"'
check "no imprime la URL ni su valor" '! echo "$OUT" | grep -q "SECRETO-QUE-NO-DEBE-SALIR"'
check "el servidor simulado no se modificó" '[ "$before" = "$after" ]'
IGUALES="$(echo "$OUT" | grep -o "IDÉNTICOS al repositorio: [0-9]*" | grep -o "[0-9]*")"
check "el resto de archivos se reconoce como idéntico" '[ "$IGUALES" -gt 20 ]'
exit $fail
