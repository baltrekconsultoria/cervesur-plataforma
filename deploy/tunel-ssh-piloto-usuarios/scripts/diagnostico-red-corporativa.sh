#!/usr/bin/env bash
# DIAGNÓSTICO DE SOLO LECTURA para preparar la publicación corporativa HTTPS (UX-07E.C). No modifica NADA: no escribe archivos (salvo la salida estándar), no recarga Apache, no abre puertos,
# no toca DNS, cortafuegos ni certificados. No imprime contraseñas, claves privadas (no abre ningún *.key), tokens, enlaces ni el contenido de ningún .env.
#   sudo bash diagnostico-red-corporativa.sh [nombre-candidato.interno] > diagnostico-red.txt
# Con el nombre candidato también comprueba si el DNS ya lo resuelve. Compártalo con el administrador de red para decidir nombre, IP, puerto y certificado.
set -uo pipefail
CAND=${1:-}
sec() { printf '\n== %s\n' "$*"; }
run() { "$@" 2>&1 | head -${LIM:-60}; }

sec "fecha y servidor"; date -u +%FT%TZ; hostname; uname -sr; (. /etc/os-release 2>/dev/null && echo "$PRETTY_NAME")
sec "interfaces e IP (candidata a la publicación: una IP PRIVADA propia del servidor)"; run ip -4 -o addr show
sec "ruta por defecto y puerta de enlace"; run ip route show default
sec "Apache: versión, módulos relevantes y sitios (VirtualHosts y puertos)"; run apache2ctl -v
apache2ctl -M 2>/dev/null | grep -E 'ssl_module|headers_module|rewrite_module|alias_module|php' || echo "(módulos relevantes no encontrados)"
LIM=80 run apache2ctl -S
sec "directivas Listen declaradas"; grep -rnE '^[[:space:]]*Listen[[:space:]]' /etc/apache2/ports.conf /etc/apache2/sites-enabled /etc/apache2/conf-enabled /etc/apache2/mods-enabled 2>/dev/null | head -40
sec "puertos TCP en escucha y quién los atiende (80, 443 y los de los portales 8088–8093)"; LIM=60 run ss -ltnp
sec "¿quién usa el 443?"; ss -ltnpH 'sport = :443' 2>/dev/null | head -5 || true; [ -z "$(ss -ltnH 'sport = :443' 2>/dev/null)" ] && echo "el 443 está LIBRE"
sec "Flutter (puerto 80) responde"; curl -sS -m 6 -o /dev/null -w 'HTTP %{http_code}\n' http://127.0.0.1/ 2>&1 | head -2
sec "DNS configurado en el servidor (servidores y dominio de búsqueda)"; grep -E '^(nameserver|search|domain)' /etc/resolv.conf 2>/dev/null; (resolvectl status 2>/dev/null | grep -E 'DNS Servers|DNS Domain|Current DNS' | head -8) || true
if [ -n "$CAND" ]; then sec "¿el DNS ya resuelve «$CAND»?"; getent hosts "$CAND" || echo "no resuelve (lo debe registrar el administrador de red)"; fi
sec "autoridades certificadoras corporativas instaladas en el servidor (nombres de archivo)"; ls -1 /usr/local/share/ca-certificates 2>/dev/null | head -20 || true; echo "(vacío = el servidor no tiene CA corporativa propia; no es un problema: los EQUIPOS de los usuarios son los que deben confiar en la CA)"
sec "certificados públicos ya presentes (SOLO partes públicas: sujeto, emisor y vencimiento; no se abre ninguna clave privada)"
for f in $(find /etc/apache2 /etc/ssl/certs /etc/letsencrypt/live -maxdepth 3 \( -name '*.crt' -o -name 'fullchain*.pem' -o -name 'cert*.pem' \) 2>/dev/null | grep -v '/ca-certificates' | grep -vE '^/etc/ssl/certs/[^/]+\.pem$' | head -20); do
  printf '%s: ' "$f"; openssl x509 -in "$f" -noout -subject -issuer -enddate 2>/dev/null | tr '\n' ' '; echo
done
sec "OpenSSL y curl"; openssl version; curl --version | head -1
sec "cortafuegos (solo lectura; requiere root para ver reglas)"; (ufw status verbose 2>/dev/null || echo "ufw no instalado o inactivo") | head -25; (iptables -S 2>/dev/null | head -25) || true; (nft list ruleset 2>/dev/null | head -40) || true
sec "servicios de red que NO deben exponerse: MySQL debe escuchar solo en loopback"; ss -ltnH 'sport = :3306' 2>/dev/null | awk '{print "MySQL escucha en: " $4}'
sec "resumen para la decisión"
echo "1) IP privada propia (arriba)  2) nombre interno aprobado + registro DNS (A) hacia esa IP  3) certificado de la CA corporativa con ese SAN (fullchain + clave)  4) puerto: 443 si está libre o lo atiende Apache"
echo "5) regla de cortafuegos: permitir TCP/<puerto> solo desde LAN/VPN  6) sin NAT ni publicación a Internet"
