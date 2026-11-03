#!/usr/bin/env bash
# Cervesur · UX-08G.2 · ¿Garantiza la configuración REAL de PHP/Apache que, tras sustituir el código, se sirve el código NUEVO? SOLO LECTURA.
#   bash opcache-solo-lectura.sh [--espera 4] [--paquete <carpeta del paquete extraído>] [--url-local https://localhost:8093]
# Contexto: mod_php usa opcache. Si valida marcas de tiempo, revalida un archivo como mucho cada `opcache.revalidate_freq` segundos (y no cachea archivos modificados en los
# últimos `opcache.file_update_protection` segundos, lo que no alarga el desfase); si NO valida (`validate_timestamps=0`) el código nuevo no se ve hasta recargar Apache. actualizar.sh espera
# CERVESUR_ESPERA_OPCACHE (4 s por defecto) antes de verificar. Este script comprueba si esa espera basta con la configuración EFECTIVA del PHP que usa Apache.
# Lee: /etc/php/<v>/apache2/php.ini y conf.d/*.ini (el último valor manda, como PHP), directivas php_value/php_admin_value en /etc/apache2 y .htaccess, y el módulo cargado.
# NO reinicia ni recarga Apache, NO escribe nada, NO imprime valores sensibles. NO usa `php -i` de la CLI (su configuración es otra que la de Apache).
# Con --paquete y --url-local compara además la aplicación SERVIDA (nombre del bundle JS que entrega Apache) con la del paquete.
# Resultado: GO / NO-GO con el mecanismo seguro a usar si no se puede garantizar.
set -u
ESPERA=4; PAQ=''; URL=''
PHP_ETC=${CERVESUR_PHP_ETC:-/etc/php}; APACHE_ETC=${CERVESUR_APACHE_ETC:-/etc/apache2}; FRONT=${CERVESUR_ADMIN_FRONT:-/var/www/cervesur-react-admin}; BACK=${CERVESUR_ADMIN_BACK:-/opt/cervesur-react-admin}
CACERT=${CERVESUR_ADMIN_CACERT:-/etc/apache2/cervesur-react-admin/tls/servidor.crt}
while [ "$#" -gt 0 ]; do case "$1" in --espera) ESPERA=${2:-4}; shift 2 ;; --paquete) PAQ=${2:-}; shift 2 ;; --url-local) URL=${2:-}; shift 2 ;; *) echo "Opción desconocida: $1"; exit 2 ;; esac; done
[[ $ESPERA =~ ^[0-9]+$ ]] || { echo "--espera debe ser un entero de segundos"; exit 2; }
nogo=0; aviso=0
echo "################ OPCACHE DEL PHP DE APACHE (solo lectura) ################"

echo "== 1. Módulo PHP de Apache y directorios de configuración"
modulos=$(ls "$APACHE_ETC/mods-enabled" 2>/dev/null | grep -E '^php[0-9.]*\.(load|conf)$' | tr '\n' ' ')
echo "   módulos habilitados: ${modulos:-(ninguno detectado)}"
vers=$(ls "$PHP_ETC" 2>/dev/null | grep -E '^[0-9]+\.[0-9]+$' | sort -V)
[ -n "$vers" ] || { echo "   NO se encuentra $PHP_ETC/<versión>: no se puede determinar la configuración efectiva → NO-GO"; echo "RESULTADO: NO-GO"; exit 1; }
v=$(printf '%s\n' "$vers" | tail -1); echo "   versiones de PHP instaladas: $(echo $vers | tr '\n' ' ')· se evalúa la más reciente con directorio apache2: "
for cand in $(printf '%s\n' "$vers" | sort -rV); do [ -d "$PHP_ETC/$cand/apache2" ] && { v=$cand; break; }; done
INI="$PHP_ETC/$v/apache2"; echo "   usada: $v ($INI)"
[ -d "$INI" ] || { echo "   no existe $INI → NO-GO"; echo "RESULTADO: NO-GO"; exit 1; }

echo "== 2. Valores efectivos (php.ini + conf.d/*.ini en orden; el último gana)"
declare -A VAL=(); declare -A FUENTE=()
cargar() { local f=$1 l k x; while IFS= read -r l; do l=${l%%;*}; l=$(sed -E 's/^[[:space:]]+|[[:space:]]+$//g' <<<"$l"); [[ $l =~ ^(opcache\.[a-z_.]+|zend_extension)[[:space:]]*=[[:space:]]*(.*)$ ]] || continue; k=${BASH_REMATCH[1]}; x=$(sed -E 's/^"|"$//g' <<<"${BASH_REMATCH[2]}"); if [ "$k" = zend_extension ]; then [[ $x == *opcache* ]] && { VAL[extension]=cargada; FUENTE[extension]=$f; }; else VAL[$k]=$x; FUENTE[$k]=$f; fi; done < "$f"; }
[ -r "$INI/php.ini" ] && cargar "$INI/php.ini"
for f in $(ls "$INI"/conf.d/*.ini 2>/dev/null | sort); do cargar "$f"; done
mostrar() { printf '   %-34s %-12s %s\n' "$1" "${VAL[$1]:-(sin definir → valor por defecto: $2)}" "${FUENTE[$1]:+[$(basename "${FUENTE[$1]}")]}"; }
printf '   %-34s %s\n' "extensión opcache" "${VAL[extension]:-no cargada por zend_extension en esta configuración}"
mostrar opcache.enable 1; mostrar opcache.validate_timestamps 1; mostrar opcache.revalidate_freq 2; mostrar opcache.file_update_protection 2; mostrar opcache.revalidate_path 0
echo "   (php.ini de la CLI NO es el de Apache; aquí solo cuenta $INI)"

echo "== 3. Directivas de Apache o .htaccess que sobrescriban opcache"
sobr=$(grep -rhoE 'php_(admin_)?(value|flag)[[:space:]]+opcache\.[a-z_.]+[[:space:]]+[^[:space:]]+' "$APACHE_ETC" "$FRONT/public/.htaccess" "$BACK/backend/.htaccess" 2>/dev/null | sort -u)
if [ -n "$sobr" ]; then echo "$sobr" | sed 's/^/   /'; while read -r _ k x; do [ -n "${k:-}" ] && VAL[$k]=$x; done <<<"$sobr"; aviso=1; echo "   AVISO: hay sobrescrituras; se aplican al cálculo (revíselas a mano)"; else echo "   ninguna"; fi

echo "== 4. Evaluación"
ext=${VAL[extension]:-}; en=${VAL[opcache.enable]:-1}; vt=${VAL[opcache.validate_timestamps]:-1}; fr=${VAL[opcache.revalidate_freq]:-2}; pr=${VAL[opcache.file_update_protection]:-2}
for n in "$fr" "$pr"; do [[ $n =~ ^[0-9]+$ ]] || { echo "   valor no numérico (revalidate_freq/file_update_protection): $n → NO-GO"; nogo=1; }; done
if [ -z "$ext" ]; then echo "   opcache no figura como cargado en la configuración de Apache → no hay caché de código: PASS (el código nuevo se lee en cada petición)"
elif [ "$en" = 0 ] || [ "$en" = Off ] || [ "$en" = off ]; then echo "   opcache.enable=0 → sin caché de código: PASS"
elif [ "$vt" = 0 ] || [ "$vt" = Off ] || [ "$vt" = off ]; then
  nogo=1; echo "   opcache.validate_timestamps=0 → opcache NO revalida: tras reemplazar el código se sigue sirviendo el ANTIGUO hasta recargar Apache. NO-GO."
  echo "   Mecanismo seguro: incluir en la ventana una recarga SUAVE aprobada aparte (apache2ctl configtest && systemctl reload apache2; no reinicia el servicio) y verificar DESPUÉS."
elif [[ $fr =~ ^[0-9]+$ ]] && [[ $pr =~ ^[0-9]+$ ]]; then
  req=$((fr + 2)); echo "   validate_timestamps activo: opcache revalida cada ≤ ${fr}s (un archivo recién modificado ni siquiera se cachea durante ${pr}s) → espera mínima segura = ${fr}s + 2s de margen = ${req}s; espera configurada = ${ESPERA}s"
  if [ "$ESPERA" -ge "$req" ]; then echo "   PASS: la espera de ${ESPERA}s cubre la revalidación"; else nogo=1; echo "   NO-GO con la espera por defecto: ejecute actualizar.sh con CERVESUR_ESPERA_OPCACHE=$req (variable de entorno de la orden; no cambia Apache ni PHP)."; fi
fi
echo "   Nota: la espera garantiza que la verificación vea el código nuevo EN ESTE servidor Apache; con varios procesos/hilos la caché es compartida (memoria de Apache), por lo que no hay réplicas desfasadas."

echo "== 5. Identidad de la versión SERVIDA (aplicación)"
if [ -n "$PAQ" ] && [ -n "$URL" ]; then
  esperado=$(grep -oE 'assets/index-[A-Za-z0-9_-]+\.js' "$PAQ/public/index.html" 2>/dev/null | head -1)
  host=$(sed -E 's#https?://([^/:]+).*#\1#' <<<"$URL"); port=$(sed -nE 's#https?://[^/:]+:([0-9]+).*#\1#p' <<<"$URL")
  servido=$(curl -sS -m 10 --cacert "$CACERT" ${port:+--resolve "$host:$port:127.0.0.1"} "$URL/" 2>/dev/null | grep -oE 'assets/index-[A-Za-z0-9_-]+\.js' | head -1)
  echo "   bundle esperado (paquete): ${esperado:-?} · bundle servido: ${servido:-(sin respuesta)}"
  if [ -n "$esperado" ] && [ "$esperado" = "$servido" ]; then echo "   PASS: Apache sirve la aplicación del paquete"; else echo "   (distinto o sin respuesta: antes de actualizar es lo esperado; tras actualizar debe coincidir)"; fi
else echo "   (omitido: indique --paquete y --url-local para comparar el bundle servido; antes de actualizar no coincide, tras actualizar debe coincidir)"; fi
echo "   Identidad del backend: se demuestra en disco con identidad-instalada-solo-lectura.sh y en ejecución con la espera de §4 + verificar.sh; PHP no expone su versión de código por HTTP."

echo
if [ "$nogo" -eq 0 ]; then echo "RESULTADO: GO (con la espera de ${ESPERA}s el código nuevo es el que se verifica y se sirve)$([ "$aviso" -eq 1 ] && echo ' · revise los AVISO')"; echo "(nada fue modificado)"; exit 0; fi
echo "RESULTADO: NO-GO (no se puede garantizar que se use el código nuevo con esta configuración; ver §4)"; echo "(nada fue modificado)"; exit 1
