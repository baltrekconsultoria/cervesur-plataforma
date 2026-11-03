#!/usr/bin/env bash
# Cervesur · UX-08F.1 · ¿Qué produce el 403 del administrador y dónde vive la lista de informes aprobados? SOLO LECTURA.
#
# El inventario por huellas (inventario-divergencia.sh) dice QUÉ archivos difieren, no CÓMO se decide el 403. Este script lee el backend
# desplegado y entrega solo la evidencia mínima para reconstruir esa regla, sin enlaces ni secretos:
#   1. por archivo: cuántas veces aparecen los marcadores de autorización (403, aprobados, allow, piloto…);
#   2. las líneas que emiten el 403 o comparan con una lista (recortadas a 140 caracteres);
#   3. de dónde sale la lista: nombres de variables de entorno, archivos leídos, constantes y TABLAS consultadas (solo nombres);
#   4. nombre de la base a la que conecta (solo «dbname=»).
# Con --mysql añade (por sudo mysql, solo information_schema) los nombres de tablas y columnas candidatas a guardar la aprobación:
# NUNCA filas ni valores. Con eso se decide la segunda consulta (los ids aprobados) sin adivinar nombres.
#
# NO imprime: .env, contraseñas, claves, tokens, hashes, valores de datos, URLs (se reemplazan por <url-oculta>), GUID (<guid>) ni cadenas
# largas (<largo-oculto>). Las líneas que contienen secret/password/token/bearer/authorization/apikey/private/jwt se CUENTAN y no se muestran.
# NO modifica nada. Revise la salida antes de compartirla.
# USO:  bash autorizacion-403-solo-lectura.sh /opt/cervesur-react-admin/backend [--mysql]  > autorizacion-admin.txt
set -u
DIR="${1:?Indique el directorio backend desplegado, p. ej. /opt/cervesur-react-admin/backend}"
CON_MYSQL=0; [ "${2:-}" = "--mysql" ] && CON_MYSQL=1
[ -d "$DIR" ] || { echo "No existe $DIR"; exit 2; }
SENSIBLE='secret|password|passwd|pass_|token|bearer|authorization|api[_-]?key|private|jwt'

san() { # enmascara URLs, GUID y cadenas largas y recorta
  sed -E 's#https?://[^" )'"'"']+#<url-oculta>#g; s#[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}#<guid>#g; s#[A-Za-z0-9+/=_-]{32,}#<largo-oculto>#g' | cut -c1-140
}
archivos() { find "$DIR" -type f -name '*.php' -not -path '*/vendor/*' 2>/dev/null | LC_ALL=C sort; }

echo "################ AUTORIZACIÓN DEL ADMINISTRADOR (solo lectura) ################"
echo "Directorio: $DIR   (archivos PHP: $(archivos | wc -l))"

echo; echo "== 1. Marcadores por archivo (solo conteos; los archivos sin ninguno no se listan) =="
printf '%-52s %s\n' "archivo" "403 aprob allow piloto in_array reporte_id"
archivos | while read -r f; do
  c1=$(grep -c "403" "$f"); c2=$(grep -ciE "aprob|autoriz" "$f"); c3=$(grep -ciE "allow|permit|whitelist|lista_blanca" "$f"); c4=$(grep -ciE "piloto|pilot" "$f"); c5=$(grep -c "in_array" "$f"); c6=$(grep -ciE "reporte_id|report_id|reporteId" "$f")
  [ $((c1 + c2 + c3 + c4)) -gt 0 ] && printf '%-52s %s %s %s %s %s %s\n' "${f#"$DIR"/}" "$c1" "$c2" "$c3" "$c4" "$c5" "$c6"
done

echo; echo "== 2. Líneas que emiten el 403 o deciden con una lista (enmascaradas; máx. 14 por archivo) =="
archivos | while read -r f; do
  grep -qE "403|aprob|autoriz|piloto" "$f" || continue
  ocultas=$(grep -nE "403|aprob|autoriz|in_array|piloto|allow" "$f" | grep -ciE "$SENSIBLE")
  echo "--- ${f#"$DIR"/}  (líneas con palabras sensibles no mostradas: $ocultas)"
  grep -nE "403|aprob|autoriz|in_array|piloto|allow" "$f" | grep -viE "$SENSIBLE" | san | head -14 | sed 's/^/    /'
done

echo; echo "== 3. De dónde sale la lista (solo NOMBRES) =="
echo "-- Variables de entorno leídas:"
archivos | xargs -r grep -hoE "(getenv|\\\$_ENV\\[|\\\$_SERVER\\[)['\"(]*[A-Za-z_][A-Za-z0-9_]*" 2>/dev/null | sed -E "s/.*['\"(\\[]//" | LC_ALL=C sort | uniq -c | sort -rn | head -40 | sed 's/^/    /'
echo "-- Archivos de datos o configuración leídos (argumento recortado, sin URLs):"
archivos | xargs -r grep -nE "file_get_contents|parse_ini_file|json_decode\(file|include(_once)? *\\\$|require(_once)? *\\\$|simplexml_load_file" 2>/dev/null | grep -viE "$SENSIBLE|php://input" | san | head -30 | sed 's/^/    /'
echo "-- Constantes y arreglos de ids cerca de aprobación/lista (los ids de informe no son secretos):"
archivos | xargs -r grep -nE "(define\(|const +)[A-Z_]*(APROB|ALLOW|PILOT|REPORT)[A-Z_]*|(aprob|allow|piloto)[A-Za-z_]* *(=|=>) *\[" 2>/dev/null | grep -viE "$SENSIBLE" | san | head -30 | sed 's/^/    /'
echo "-- Tablas consultadas por los archivos con marcadores de autorización:"
archivos | xargs -r grep -lE "403|aprob|autoriz|piloto" 2>/dev/null | xargs -r grep -hoiE "(FROM|JOIN|INTO|UPDATE) +\`?[A-Za-z0-9_.]+" 2>/dev/null | sed -E 's/^[A-Za-z]+ +`?//' | LC_ALL=C sort | uniq -c | sort -rn | head -30 | sed 's/^/    /'

echo; echo "== 4. Base a la que conecta el código (solo el nombre de la base) =="
archivos | xargs -r grep -hoE "dbname=[A-Za-z0-9_]+" 2>/dev/null | LC_ALL=C sort -u | sed 's/^/    /'
echo "    (el backend de React lee la base de getenv('DB_NAME'); el valor está en el .env y no se muestra)"

if [ "$CON_MYSQL" -eq 1 ]; then
  echo; echo "== 5. Tablas y columnas candidatas a guardar la aprobación (information_schema; sin filas ni valores) =="
  SQL="SELECT TABLE_SCHEMA, TABLE_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY ORDINAL_POSITION) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA NOT IN ('mysql','sys','information_schema','performance_schema') AND (TABLE_NAME REGEXP 'piloto|aprob|autoriz|auditoria' OR COLUMN_NAME REGEXP 'aprob|autoriz|piloto|habilitad') GROUP BY TABLE_SCHEMA, TABLE_NAME ORDER BY TABLE_SCHEMA, TABLE_NAME LIMIT 60"
  sudo mysql -N -e "$SQL" 2>&1 | head -60 | sed 's/^/    /' || echo "    (sin acceso: ejecute con un usuario que pueda leer information_schema)"
  echo "    Siguiente paso (lo decido yo con esta salida): un SELECT de solo los ids de informe aprobados en la tabla que resulte ser."
fi

echo; echo "################ FIN (nada fue modificado) ################"
