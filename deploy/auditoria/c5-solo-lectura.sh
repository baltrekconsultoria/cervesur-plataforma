#!/usr/bin/env bash
# C-5 · Inspección de SOLO LECTURA del servidor (parte del sistema y de los registros de Apache).
# NO se ejecutó en producción; la ejecuta el operador con la autorización específica `A-C5` (destino, operación, sesión).
#
# Qué hace: huellas del frontend Flutter (cierra P-1 para todos los archivos de arranque), entorno (PHP, opcache, módulos de Apache,
# puertos que escuchan, espacio libre) y AGREGADOS de los registros de acceso de Apache por categoría de ruta.
# Qué NO hace: no escribe archivos, no usa sudo, no ejecuta PHP de la aplicación, no toca MySQL, no lee `.env`, no imprime líneas de
# registro, rutas completas, cadenas de consulta, cabeceras, cookies, referentes ni direcciones IP completas (solo /24).
#
# Uso:  bash c5-solo-lectura.sh /var/log/apache2/access.log /var/log/apache2/access.log.1 /var/log/apache2/access.log.2.gz ...
#       (con `sudo` solo si la cuenta no puede leer los registros; el propio script no lo invoca)
# Compatible con `bash -s` pegado en PuTTY:  bash -s -- /var/log/apache2/access.log <<'EOS' ... EOS
# Variables (solo para pruebas locales): C5_LOG_DIR (por defecto /var/log/apache2), C5_FRONT (por defecto /var/www/cervesur/frontend).
LC_ALL=C
export LC_ALL
LOGDIR="${C5_LOG_DIR:-/var/log/apache2}"
FRONT="${C5_FRONT:-/var/www/cervesur/frontend}"

sec() { printf '\n######## %s\n' "$1"; }

sec "0. Identidad (solo lectura)"
date -u +%Y-%m-%dT%H:%M:%SZ; id -un; hostname

sec "1. Huellas del frontend Flutter contra el paquete de referencia (cierra P-1)"
cmp1() {  # archivo sha_esperado
  local f="$FRONT/$1" h
  if [ ! -r "$f" ]; then printf 'NO LEGIBLE  %s\n' "$1"; return; fi
  h=$(sha256sum "$f" | cut -d' ' -f1)
  if [ "$h" = "$2" ]; then printf 'OK          %s\n' "$1"; else printf 'DIFERENTE   %s  (hay %s)\n' "$1" "${h:0:16}"; fi
}
cmp1 main.dart.js            7a997e86f234e06f66c2ed5bf5ca6207d4a8eda89b1b3c18ffe5dd153ccd0d16
cmp1 version.json            d98dfd4f91bc0e78543415309c80cd9b1de24c98333b43a34f73b3542c5a58f9
cmp1 index.html              71f2c06f127592f4ffd9f33283771f20f6b539d594c736f63c5b226454c71e03
cmp1 flutter_bootstrap.js    45c8cc1ae4fbd67796538ce464a93189efb281a41a770c97568e4f24ad06d19e
cmp1 flutter_service_worker.js dbfbe64ae5be5b462abb97338eb9af783ed4ed57a8c73a53c2a44488a26f6416
cmp1 flutter.js              a483fd28f51ed2fadd0da3fade5b672eba56310d549d736ce62eabf624a6a578
echo "Nota: canvaskit/ y assets/ no se comparan."

sec "2. Entorno (P-8 y espacio para P2)"
php -v 2>/dev/null | head -1 || echo "php (CLI) no disponible"
echo "-- opcache.validate_timestamps en la configuracion de Apache (solo esa directiva):"
grep -rhsE '^[[:space:]]*opcache\.validate_timestamps' /etc/php/*/apache2/ 2>/dev/null | head -5 || true
echo "-- modulos de Apache habilitados de interes (solo se lista el directorio mods-enabled):"
ls /etc/apache2/mods-enabled/ 2>/dev/null | grep -E '^(ssl|headers|rewrite|php)' || echo "(no se pudo listar)"
echo "-- puertos en escucha (direccion:puerto):"
ss -ltn 2>/dev/null | awk 'NR>1 {print $4}' | grep -E ':(80|443|8088|8089|809[0-9]|8443|3306)$' | sort -u || true
echo "-- espacio libre:"
df -h /var/backups /var/www /tmp 2>/dev/null | awk 'NR==1 || NR>1'

sec "3. Registros de acceso de Apache: agregados por categoria (sin lineas, sin rutas completas, sin IP completas)"
if [ "$#" -eq 0 ]; then echo "Sin archivos de registro indicados: nada que agregar."; exit 0; fi
OK_FILES=()
for f in "$@"; do
  real=$(readlink -f -- "$f" 2>/dev/null)
  case "$real" in
    "$LOGDIR"/*access*) ;;
    *) printf 'RECHAZADO (fuera de %s o no es un registro de acceso): %s\n' "$LOGDIR" "$f"; continue;;
  esac
  if [ ! -r "$real" ]; then printf 'NO LEGIBLE: %s\n' "$f"; continue; fi
  OK_FILES+=("$real")
done
[ "${#OK_FILES[@]}" -gt 0 ] || { echo "Ningun archivo valido."; exit 3; }
printf 'Archivos analizados: %s\n' "${#OK_FILES[@]}"

leer() { for f in "${OK_FILES[@]}"; do case "$f" in *.gz) gzip -dc -- "$f" 2>/dev/null;; *) cat -- "$f";; esac; done; }

# Agregacion compatible con mawk (el awk por defecto de Ubuntu): una linea por hecho, `sort | uniq -c` y presentacion final.
leer | awk -F'"' '
function mes(m) { return (index("JanFebMarAprMayJunJulAugSepOctNovDec", m) + 2) / 3 }
function cat_(m, p) {
  if (p ~ /^\/api\/powerbi\/tokens\/[0-9]+$/) return "K1_tokens_powerbi"
  if (p == "/api/register") return (m == "POST") ? "A3_registro_POST" : "A3_registro_otro_metodo"
  if (p ~ /^\/api\/(login|auth)/) return "LOGIN"
  if (p ~ /^\/api\/user\/profile\/[0-9]+$/) return "A1_perfil_" m
  if (p ~ /^\/api\/user(s)?(\/|$)/) return "USUARIOS_" ((m == "GET") ? "lectura" : "escritura")
  if (p ~ /^\/api\/empresas/) return "EMPRESAS_" ((m == "GET") ? "lectura" : "escritura")
  if (p ~ /^\/api\/reportes/) return "REPORTES_" ((m == "GET") ? "lectura" : "escritura")
  if (p ~ /^\/api\/(asignacion|empresa-usuario|empresa-reporte-usuario)/) return "ACCESOS_" ((m == "GET") ? "lectura" : "escritura")
  if (p ~ /^\/api\//) return "API_otras"
  return ""
}
function fam(u,   l) {
  l = tolower(u)
  if (l == "-" || l == "") return "vacio"
  if (l ~ /axios|node-fetch|undici/) return "axios_o_node"
  if (l ~ /dart|okhttp|cfnetwork/) return "cliente_dart_movil"
  if (l ~ /curl|wget|python|go-http|java|libwww|powershell/) return "herramienta"
  if (l ~ /nmap|zgrab|masscan|nikto|sqlmap|scanner/) return "escaner"
  if (l ~ /mozilla/) return "navegador"
  return "otro"
}
function orig(ip) {
  if (ip ~ /^(10\.|192\.168\.|127\.)/ || ip ~ /^172\.(1[6-9]|2[0-9]|3[01])\./ || ip == "::1") return "privada"
  return "publica"
}
function red24(ip,   a) { if (ip ~ /:/) return "ipv6"; split(ip, a, "."); return a[1] "." a[2] "." a[3] ".0/24" }
{
  if (NF < 6 || index($1, "[") == 0) { malformadas++; next }
  total++
  split($1, h, " "); ip = h[1]
  d = $1; i = index(d, "["); d = substr(d, i + 1); j = index(d, "]"); d = substr(d, 1, j - 1)
  split(d, t, "[/:]"); f = t[3] sprintf("%02d", mes(t[2])); ym = t[3] "-" sprintf("%02d", mes(t[2]))
  dia = f t[1]
  if (min == "" || dia < min) min = dia
  if (dia > max) max = dia
  split($2, r, " "); m = r[1]; p = r[2]; q = index(p, "?"); if (q) p = substr(p, 1, q - 1)
  split($3, s, " "); st = s[1]
  c = cat_(m, p)
  if (c == "") { otros++; next }
  print "N|" c; print "M|" c "|" ym; print "S|" c "|" st; print "U|" c "|" fam($6); print "O|" c "|" orig(ip); print "R|" c "|" red24(ip)
}
END { printf "T|%d|%d|%s|%s|%d\n", total, otros, min, max, malformadas }' 2>/dev/null | sort | uniq -c | awk '
{
  n = $1; $1 = ""; sub(/^ /, "", $0); split($0, a, "|")
  if (a[1] == "T") { printf "Lineas validas: %d | fuera de /api: %d | malformadas (descartadas): %d | periodo (AAAAMMDD): %s a %s\n", a[2], a[3], a[6], a[4], a[5]; next }
  tipo = a[1]; c = a[2]
  if (tipo == "N") { tot[c] = n; orden[c] = 1; next }
  if (tipo == "M") { det[c] = det[c] "  mes " a[3] ": " n "\n" }
  if (tipo == "S") { st[c] = st[c] "  estado " a[3] ": " n "\n" }
  if (tipo == "U") { ua[c] = ua[c] "  cliente " a[3] ": " n "\n" }
  if (tipo == "O") { og[c] = og[c] "  origen " a[3] ": " n "\n" }
  if (tipo == "R") { nr[c]++; rn[c, nr[c]] = a[3]; rc[c, nr[c]] = n }
}
END {
  for (c in orden) {
    printf "\n[%s] total %d\n%s%s%s%s  redes /24 distintas: %d (las 5 con mas llamadas)\n", c, tot[c], det[c], st[c], ua[c], og[c], nr[c]
    for (k = 1; k <= 5; k++) {
      mx = 0; mi = 0
      for (i = 1; i <= nr[c]; i++) if (rc[c, i] > mx) { mx = rc[c, i]; mi = i }
      if (mi == 0) break
      printf "    %s: %d\n", rn[c, mi], mx; rc[c, mi] = -1
    }
  }
}' | sed -e '/^$/N;/^\n$/D'

sec "FIN: nada fue modificado. La salida contiene solo agregados."
