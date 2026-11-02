#!/usr/bin/env bash
# Cervesur · UX-08 · Inventario de DIVERGENCIA entre el código del repositorio y un backend DESPLEGADO. SOLO LECTURA.
#
# Para qué: antes de publicar UX-08 hay que saber qué componentes existen en el servidor y no en el repositorio (por ejemplo, los del piloto:
# piloto_catalogo.php, estado_piloto.php, admin_catalogo.php, mode.php, pilot_sesion.php, AdminRouter.php, PilotoRouter.php), porque un despliegue
# que reemplace el directorio los BORRARÍA junto con los controles de seguridad que contienen.
#
# QUÉ HACE: lee archivos PHP del directorio indicado y los compara por huella (contenido sin CR) con deploy/auditoria/manifiesto-backend.txt.
#           NO modifica nada, NO imprime contenido de archivos (solo nombres, tamaños, conteos y nombres de función), enmascara URLs.
# USO:      bash inventario-divergencia.sh /opt/cervesur-react-admin/backend  [manifiesto] [rutas-repo]  > divergencia-admin.txt
set -u
DIR="${1:?Indique el directorio backend a inventariar, p. ej. /opt/cervesur-react-admin/backend}"
HERE="$(cd "$(dirname "$0")" && pwd)"
MANIFEST="${2:-$HERE/manifiesto-backend.txt}"
RUTAS_REPO="${3:-$HERE/rutas-repo.txt}"
[ -d "$DIR" ] || { echo "No existe $DIR"; exit 2; }
[ -f "$MANIFEST" ] || { echo "Falta $MANIFEST (genérelo con generar-manifiesto.sh)"; exit 2; }

mask() { sed -E 's#https?://[^" )]+#<url-oculta>#g'; }
TMP="$(mktemp -d)"; trap 'rm -rf "$TMP"' EXIT

# Huellas del servidor (rutas relativas como en el manifiesto: backend/...)
find "$DIR" -type f -name '*.php' -not -path '*/vendor/*' 2>/dev/null | sort | while read -r f; do
  printf '%s  backend/%s\n' "$(tr -d '\r' < "$f" | sha256sum | cut -c1-64)" "${f#"$DIR"/}"
done > "$TMP/servidor.txt"
grep -v '^#' "$MANIFEST" | sort -k2 > "$TMP/repo.txt"
sort -k2 "$TMP/servidor.txt" > "$TMP/servidor.s"

echo "################ INVENTARIO DE DIVERGENCIA (solo lectura) ################"
echo "Directorio: $DIR"; echo "Referencia: $(head -1 "$MANIFEST")"
echo "Archivos PHP en el servidor: $(wc -l < "$TMP/servidor.s")   en el repositorio: $(wc -l < "$TMP/repo.txt")"

echo; echo "== 1. SOLO EN EL SERVIDOR (componentes que un despliegue destructivo borraría) =="
awk '{print $2}' "$TMP/repo.txt" | sort > "$TMP/repo.paths"
awk '{print $2}' "$TMP/servidor.s" | sort > "$TMP/srv.paths"
comm -13 "$TMP/repo.paths" "$TMP/srv.paths" > "$TMP/solo-srv"
if [ -s "$TMP/solo-srv" ]; then
  while read -r rel; do
    f="$DIR/${rel#backend/}"
    printf '%s  (%s bytes)\n' "$rel" "$(wc -c < "$f" | tr -d ' ')"
  done < "$TMP/solo-srv"
else echo "(ninguno)"; fi

echo; echo "== 2. MISMA RUTA, CONTENIDO DISTINTO (cambios del despliegue sobre el código del repositorio) =="
join -j 2 -o 0,1.1,2.1 "$TMP/repo.txt" "$TMP/servidor.s" 2>/dev/null | awk '$2 != $3 {print $1}' > "$TMP/distintos"
if [ -s "$TMP/distintos" ]; then cat "$TMP/distintos"; else echo "(ninguno)"; fi

echo; echo "== 3. SOLO EN EL REPOSITORIO (archivos nuevos que el despliegue añadiría) =="
comm -23 "$TMP/repo.paths" "$TMP/srv.paths" > "$TMP/solo-repo"
if [ -s "$TMP/solo-repo" ]; then cat "$TMP/solo-repo"; else echo "(ninguno)"; fi

echo; echo "== 4. IDÉNTICOS al repositorio: $(join -j 2 -o 0,1.1,2.1 "$TMP/repo.txt" "$TMP/servidor.s" 2>/dev/null | awk '$2 == $3' | wc -l) archivos =="

echo; echo "== 5. RUTAS declaradas por los routers del servidor frente al repositorio =="
find "$DIR/router" -type f -name '*.php' 2>/dev/null | sort | while read -r f; do tr -d '\r' < "$f"; done \
  | grep -oE "\\\$uri *(===|==) *'/api[^']*'|preg_match\\('[^']*/api[^']*'" | sed -E 's/ +/ /g' | sort -u > "$TMP/rutas-srv"
if [ -f "$RUTAS_REPO" ]; then
  grep -v '^#' "$RUTAS_REPO" | sort -u > "$TMP/rutas-repo"
  echo "-- Solo en el servidor:"; comm -13 "$TMP/rutas-repo" "$TMP/rutas-srv" | mask | cut -c1-160; echo "-- Solo en el repositorio:"; comm -23 "$TMP/rutas-repo" "$TMP/rutas-srv" | mask | cut -c1-160
else echo "(sin referencia de rutas)"; cat "$TMP/rutas-srv" | mask | cut -c1-160; fi

echo; echo "== 6. DEPENDENCIAS de los componentes exclusivos del servidor (solo nombres de archivo y de función) =="
if [ -s "$TMP/solo-srv" ]; then
  while read -r rel; do
    f="$DIR/${rel#backend/}"
    echo "--- $rel"
    echo "  requiere/incluye:"; grep -oE "(require|include)(_once)? *[^;]*;" "$f" 2>/dev/null | mask | sed 's/^/    /' | cut -c1-150 | head -12
    echo "  funciones/clases:"; grep -oE "^(function|class) +[A-Za-z0-9_]+" "$f" 2>/dev/null | sed 's/^/    /' | head -25
  done < "$TMP/solo-srv"
else echo "(sin componentes exclusivos)"; fi

echo; echo "== 7. MARCADORES DE SEGURIDAD por archivo (solo conteos; compara controles entre el servidor y el repositorio) =="
printf '%-52s %s\n' "archivo" "403 auth_req session_v is_active allow/aprob"
for f in $(find "$DIR" -type f -name '*.php' -not -path '*/vendor/*' 2>/dev/null | sort); do
  c403=$(grep -c "403" "$f"); cau=$(grep -c "require_auth" "$f"); csv=$(grep -c "session_version" "$f"); cia=$(grep -c "is_active" "$f"); cal=$(grep -ciE "allow|aprob|autoriz" "$f")
  [ $((c403 + cau + csv + cia + cal)) -gt 0 ] && printf '%-52s %s %s %s %s %s\n' "backend/${f#"$DIR"/}" "$c403" "$cau" "$csv" "$cia" "$cal"
done

echo; echo "== 8. INTERRUPTORES DE UX-08 en la configuración (valores NO sensibles; ninguna otra clave se muestra) =="
ENVF="$DIR/.env"
if [ -r "$ENVF" ] || sudo test -r "$ENVF" 2>/dev/null; then
  sel="$(sudo cat "$ENVF" 2>/dev/null || cat "$ENVF" 2>/dev/null)"
  for k in USER_ADMIN_WRITES AUDIT_LOG_MODE REACT_STRICT_VIEWS ADMIN_VIEWER_ALLOWED_REPORT_IDS; do
    v="$(printf '%s
' "$sel" | grep -E "^${k}=" | head -1 | cut -d= -f2- | tr -d "\"' ")"
    printf '%-34s %s
' "$k" "${v:-(no definida: valor por defecto)}"
  done
  echo "(esperado en los portales PUBLICADOS: USER_ADMIN_WRITES sin definir o distinta de 1)"
else echo "(no se puede leer $ENVF; compruebe esos cuatro valores a mano)"; fi

echo; echo "################ FIN (nada fue modificado) ################"
