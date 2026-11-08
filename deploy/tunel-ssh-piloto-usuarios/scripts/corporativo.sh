#!/usr/bin/env bash
# PUBLICACIÓN INTERNA POR HTTPS (UX-07E.C/D): el portal deja de depender del túnel SSH y se publica en una IP PRIVADA del servidor, con un certificado que los equipos autorizados YA confían.
# Dos modos (el mismo script sirve a los dos portales: el de trabajadores y el administrativo; cada paquete trae su copia con sus rutas, su sitio y su puerto):
#   MODO IP  (sin DNS)   --ip 192.168.200.12            https://192.168.200.12[:puerto]   el certificado debe llevar la IP en el SAN (tipo «IP Address»); no hay HSTS (los navegadores lo ignoran en una IP)
#   MODO DNS             --ip IP --host nombre.interno  https://nombre.interno[:puerto]   el certificado debe llevar el nombre en el SAN; HSTS activo
#
#   sudo bash scripts/corporativo.sh preflight  --ip IP [--host H] [--puerto P] --redes "CIDR [CIDR…]" --cert FULLCHAIN.pem --clave CLAVE.pem [--previo]   SOLO LECTURA
#   sudo bash scripts/corporativo.sh instalar   (mismos argumentos)   pide escribir INSTALAR-CORP; respaldo, VirtualHost nuevo, recarga suave, verificación; si falla, vuelve solo
#   sudo bash scripts/corporativo.sh verificar  (mismos argumentos)   comprobaciones de solo lectura de lo instalado
#   sudo bash scripts/corporativo.sh renovar    (mismos argumentos)   cambia SOLO el certificado y la clave (renovación); si la verificación falla, restaura los anteriores
#   sudo bash scripts/corporativo.sh revertir                         retira SOLO el VirtualHost de esta publicación, su certificado y (modo DNS) HSTS; el acceso por túnel no se toca
#
# --redes es OBLIGATORIO: las únicas redes cliente que pueden usar el portal (LAN/Wi-Fi corporativa y VPN). Cualquier otra dirección recibe 403 de Apache (REMOTE_ADDR real: se descartan los X-Forwarded-*).
#   No sustituye al cortafuegos (UFW está inactivo hoy): es la restricción que SÍ controla esta instalación; el cortafuegos de red es un refuerzo que decide el administrador.
# Qué NO hace: no abre puertos a Internet (rechaza IP públicas y redes públicas/0.0.0.0/0), no toca DNS ni cortafuegos, no modifica Flutter (80) ni otros portales, no habilita módulos de Apache,
# no reinicia Apache (recarga suave tras configtest) y no asume que el puerto esté libre: lo inspecciona (otro proceso que no sea Apache lo ocupa → se detiene). No debilita ninguna validación TLS
# (nunca usa -k ni --insecure). La clave privada NUNCA va en el paquete: la entrega el administrador y se copia a /etc/apache2/…/tls-corp (root:root 600).
set -uo pipefail
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
requerir_root

PUERTO_LOOP=$PUERTO                       # el puerto de loopback del sitio (lib.sh); «PUERTO» pasa a ser el de la publicación interna
PUERTO_PUB_DEFECTO=443                    # trabajadores: 443 · administrador: 8443 (la transformación del paquete administrativo lo cambia)
SITIO_CORP="${SITIO}-corp"
CONF_CORP="/etc/apache2/sites-available/${SITIO_CORP}.conf"
ENLACE_CORP="/etc/apache2/sites-enabled/${SITIO_CORP}.conf"
DIR_TLS_CORP="$DIR_CONF/tls-corp"
LINEA_BASE_CORP="$DIR_RESPALDO/corp-linea-base-portales.txt"
ENV_PREVIO_CORP="$DIR_RESPALDO/corp-env-previo.txt"
DIAS_MIN_CERT=30

# La huella del servidor de lib.sh excluye el sitio de loopback; aquí también excluye la CAPA DE PUBLICACIÓN (los VirtualHost «*-corp» y los puertos 443/8443, de este portal y del otro) para que publicar uno no cuente como cambio ajeno al publicar el otro.
huella_servidor() {
  echo "puertos:"; ss -ltnH 2>/dev/null | awk '{print $4}' | grep -v -E ":(${PUERTO}|443|8443)\$" | LC_ALL=C sort -u
  echo "modulos-apache:"; apache2ctl -M 2>/dev/null | LC_ALL=C sort | sha256sum | cut -c1-64
  echo "sitios-habilitados:"; local sitio b; for sitio in /etc/apache2/sites-enabled/*; do b=${sitio##*/}; [ "$b" = "${SITIO}.conf" ] || [[ $b == *-corp.conf ]] || echo "$b"; done | LC_ALL=C sort
  echo "bases (metadatos):"; ($MYSQL_ADMIN -N -e 'SHOW DATABASES' 2>/dev/null || echo 'mysql-no-disponible') | LC_ALL=C sort
  echo "usuarios mysql (metadatos):"; ($MYSQL_ADMIN -N -e 'SELECT user, host FROM mysql.user' 2>/dev/null || echo 'mysql-no-disponible') | LC_ALL=C sort
}

ORDEN=${1:-}; if [ $# -gt 0 ]; then shift; fi
HOST=""; IP=""; PUERTO=$PUERTO_PUB_DEFECTO; CERT=""; CLAVE=""; REDES=""; PREVIO=0
while [ $# -gt 0 ]; do
  case $1 in
    --host) HOST=${2:-}; shift 2 ;;
    --ip) IP=${2:-}; shift 2 ;;
    --puerto) PUERTO=${2:-}; shift 2 ;;
    --cert) CERT=${2:-}; shift 2 ;;
    --clave) CLAVE=${2:-}; shift 2 ;;
    --redes) REDES=${2:-}; shift 2 ;;
    --previo) PREVIO=1; shift ;;
    *) abortar "Argumento desconocido: $1" ;;
  esac
done
REDES=${REDES//,/ }
NOMBRE=${HOST:-$IP}                        # lo que el navegador escribe y lo que el certificado debe cubrir
MODO=$([ -n "$HOST" ] && echo dns || echo ip)
URL_BASE="https://$NOMBRE$([ "$PUERTO" = 443 ] || echo ":$PUERTO")"

fallos=0
chk() { local nombre=$1; shift; if "$@" >/dev/null 2>&1; then ok "$nombre"; else fallo "$nombre"; fallos=$((fallos + 1)); fi; }
web() { if [ "$MODO" = dns ]; then curl -sS -m 12 --resolve "$HOST:$PUERTO:$IP" "$@"; else curl -sS -m 12 "$@"; fi; }
loop() { curl -sS -m 10 --cacert "$CERT_FILE" --resolve "localhost:$PUERTO_LOOP:127.0.0.1" "$@"; }

es_ipv4() { local n; [[ $1 =~ ^([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})\.([0-9]{1,3})$ ]] || return 1; for n in "${BASH_REMATCH[@]:1}"; do [ "$n" -le 255 ] || return 1; done; }
es_ip_privada() { local a b; IFS=. read -r a b _ _ <<<"$1"; [ "$a" = 10 ] || { [ "$a" = 172 ] && [ "$b" -ge 16 ] && [ "$b" -le 31 ]; } || { [ "$a" = 192 ] && [ "$b" = 168 ]; }; }
es_fqdn() { [[ $1 =~ ^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)+$ ]] && [ "$1" != localhost ] && ! es_ipv4 "$1"; }
ip_local() { ip -4 -o addr show 2>/dev/null | awk '{print $4}' | cut -d/ -f1 | grep -qx "$1"; }
ip_valida_privada_local() { es_ipv4 "$IP" && es_ip_privada "$IP" && ip_local "$IP"; }
nombre_valido() { if [ "$MODO" = dns ]; then es_fqdn "$HOST"; else true; fi; }
puerto_valido() { [[ $PUERTO =~ ^[0-9]+$ ]] && [ "$PUERTO" -ge 1 ] && [ "$PUERTO" -le 65535 ] && ! [[ $PUERTO =~ ^(22|80|3306|8088|8089|8090|8091|809[23])$ ]]; }
# redes cliente: CIDR IPv4 válidas, privadas (o loopback), nunca 0.0.0.0/0 ni públicas, con prefijo ≥ 8
cidr_valido() { local red ip pref; red=$1; [[ $red =~ ^([0-9.]+)/([0-9]{1,2})$ ]] || return 1; ip=${BASH_REMATCH[1]}; pref=${BASH_REMATCH[2]}; es_ipv4 "$ip" && [ "$pref" -ge 8 ] && [ "$pref" -le 32 ] && { es_ip_privada "$ip" || [ "${ip%%.*}" = 127 ]; }; }
redes_validas() { local r; [ -n "$REDES" ] || return 1; for r in $REDES; do cidr_valido "$r" || return 1; done; }
primer_certificado() { awk '/BEGIN CERTIFICATE/{f=1} f{print} /END CERTIFICATE/{exit}' "$1"; }
numero_certificados() { grep -c 'BEGIN CERTIFICATE' "$1"; }
# El certificado cubre lo que el navegador escribe: la IP (SAN «IP Address») en modo IP, el nombre en modo DNS
cert_nombre() { if [ "$MODO" = dns ]; then primer_certificado "$CERT" | openssl x509 -noout -checkhost "$HOST" 2>/dev/null | grep -q 'does match'; else primer_certificado "$CERT" | openssl x509 -noout -checkip "$IP" 2>/dev/null | grep -q 'does match'; fi; }
cert_vigente() { primer_certificado "$CERT" | openssl x509 -noout -checkend $(( DIAS_MIN_CERT * 86400 )) >/dev/null 2>&1; }
cert_no_autofirmado() { local s i; s=$(primer_certificado "$CERT" | openssl x509 -noout -subject 2>/dev/null | sed 's/^subject= *//'); i=$(primer_certificado "$CERT" | openssl x509 -noout -issuer 2>/dev/null | sed 's/^issuer= *//'); [ -n "$s" ] && [ "$s" != "$i" ]; }
cert_servidor() { local t; t=$(primer_certificado "$CERT" | openssl x509 -noout -ext extendedKeyUsage 2>/dev/null); [ -z "$t" ] || grep -q 'TLS Web Server Authentication' <<<"$t"; }
cert_no_ca() { ! primer_certificado "$CERT" | openssl x509 -noout -ext basicConstraints 2>/dev/null | grep -q 'CA:TRUE'; }
clave_corresponde() { [ "$(primer_certificado "$CERT" | openssl x509 -noout -pubkey 2>/dev/null | sha256sum)" = "$(openssl pkey -in "$CLAVE" -pubout 2>/dev/null | sha256sum)" ]; }
dias_restantes() { local fin; fin=$(primer_certificado "$1" | openssl x509 -noout -enddate 2>/dev/null | cut -d= -f2); if [ -n "$fin" ]; then echo $(( ( $(date -d "$fin" +%s) - $(date +%s) ) / 86400 )); else echo '?'; fi; }

# El puerto lo ocupa Apache (se agrega un VirtualHost sobre su Listen) o está libre (se agrega Listen IP:puerto). Cualquier otro proceso → no se continúa.
estado_puerto() { # imprime: libre | apache | otro
  local lineas procesos
  lineas=$(ss -ltnpH "sport = :$PUERTO" 2>/dev/null) || lineas=""
  [ -n "$lineas" ] || { echo libre; return; }
  procesos=$(grep -o 'users:(("[^"]*"' <<<"$lineas" || true)
  if [ -n "$procesos" ] && ! grep -qv 'apache2' <<<"$procesos"; then echo apache; else echo otro; fi
}
vhost_ajeno() { grep -rEl "<VirtualHost[[:space:]]+(\*|${IP//./\\.}|_default_):${PUERTO}[[:space:]]*>" /etc/apache2/sites-enabled/ /etc/apache2/conf-enabled/ /etc/apache2/apache2.conf 2>/dev/null | grep -v "/${SITIO_CORP}\.conf$" | head -1; }
sin_vhost_ajeno() { [ -z "$(vhost_ajeno)" ]; }
hay_listen_apache() { grep -rhE "^[[:space:]]*Listen[[:space:]]+(([0-9.]+|\*|\[::\]):)?${PUERTO}[[:space:]]*$" /etc/apache2/ports.conf /etc/apache2/sites-enabled /etc/apache2/conf-enabled /etc/apache2/mods-enabled 2>/dev/null | grep -q .; }

instancia_sana() { [ -d "$DIR_PUBLIC" ] && [ -f "$ENV_FILE" ] && loop https://localhost:$PUERTO_LOOP/api/ping | grep -q '"pong":true'; }
modulos_ssl_headers() { modulo_apache ssl && modulo_apache headers; }
archivos_legibles() { [ -r "$CERT" ] && [ -r "$CLAVE" ]; }
# La aplicación publicada (o, con --previo, la del paquete que se va a instalar) debe admitir ese nombre/IP en su lista de hosts autorizados
app_admite_nombre() { grep -q "\"$NOMBRE\"" "$DIR_PUBLIC/build-info.json" 2>/dev/null || { [ "$PREVIO" = 1 ] && grep -q "\"$NOMBRE\"" "$PAQ/public/build-info.json" 2>/dev/null; }; }

argumentos_requeridos() { [ -n "$IP" ] && [ -n "$CERT" ] && [ -n "$CLAVE" ] && [ -n "$REDES" ] || abortar "Faltan argumentos: --ip, --redes, --cert y --clave son obligatorios (--host solo en modo DNS)."; }

preflight_corp() {
  fallos=0
  echo "== Preflight de la publicación interna (SOLO LECTURA) · modo $MODO · $URL_BASE"
  chk "la instancia está instalada y responde por HTTPS en loopback ($PUERTO_LOOP)" instancia_sana
  if [ "$MODO" = dns ]; then chk "el nombre «$HOST» es un nombre de dominio completo (no localhost ni una IP)" nombre_valido; fi
  chk "la IP $IP es IPv4 válida, PRIVADA (10/8, 172.16/12 o 192.168/16) y está asignada a una interfaz de este servidor" ip_valida_privada_local
  chk "las redes cliente autorizadas ($REDES) son CIDR privados válidos (nunca 0.0.0.0/0 ni redes públicas)" redes_validas
  chk "el puerto $PUERTO es válido y no es el de SSH (22), Flutter (80), MySQL (3306) ni 8088–8093" puerto_valido
  case $(estado_puerto) in
    libre) ok "el puerto $PUERTO está libre: se agregará «Listen $IP:$PUERTO» (solo esa IP, nunca 0.0.0.0)" ;;
    apache) ok "el puerto $PUERTO ya lo atiende Apache: se agrega un VirtualHost sobre su Listen existente (no se añade otro Listen)" ;;
    *) fallo "el puerto $PUERTO lo ocupa OTRO proceso distinto de Apache: no se asume libre ni se toca. Elija otro puerto o resuélvalo con el administrador."; fallos=$((fallos + 1)) ;;
  esac
  chk "ningún otro VirtualHost de Apache usa ya $IP:$PUERTO" sin_vhost_ajeno
  chk "mod_ssl y mod_headers están cargados (el instalador no habilita módulos)" modulos_ssl_headers
  chk "el certificado ($CERT) y la clave ($CLAVE) existen y se pueden leer" archivos_legibles
  chk "el certificado cubre «$NOMBRE» ($([ "$MODO" = ip ] && echo 'SAN de tipo IP Address' || echo 'SAN/CN'))" cert_nombre
  chk "el certificado NO es autofirmado (lo emitió una autoridad certificadora)" cert_no_autofirmado
  chk "el certificado no es de una CA (basicConstraints CA:FALSE): no sirve para firmar otros" cert_no_ca
  chk "el certificado vigente al menos $DIAS_MIN_CERT días más (hoy quedan: $(dias_restantes "$CERT"))" cert_vigente
  chk "el certificado sirve para autenticar servidores TLS (extendedKeyUsage serverAuth)" cert_servidor
  chk "la clave privada corresponde al certificado" clave_corresponde
  if [ "$(numero_certificados "$CERT" 2>/dev/null || echo 0)" -ge 2 ]; then ok "el archivo trae la cadena completa (hoja + intermedios)"; else aviso "el archivo trae un solo certificado: correcto si la CA es raíz directa (CA privada de la empresa); si usa intermedios, incluya la cadena completa."; fi
  chk "la aplicación admite «$NOMBRE» en su lista de hosts autorizados (build-info.json allowedHosts)$([ "$PREVIO" = 1 ] && echo ' [o el paquete nuevo, que la traerá al actualizar]')" app_admite_nombre
  if [ "$MODO" = dns ]; then
    if getent hosts "$HOST" 2>/dev/null | awk '{print $1}' | grep -qx "$IP"; then ok "el DNS resuelve $HOST a $IP"; else aviso "el DNS aún no resuelve $HOST a $IP (lo registra el administrador de red); la verificación usa --resolve."; fi
  fi
  return "$fallos"
}

# --- comprobaciones de verificar_corp (cada una usa el almacén de confianza DEL SISTEMA: sin -k ni certificados importados a mano) ---
v_cadena_sistema() { web -o /dev/null "$URL_BASE/"; }
v_openssl() { if [ "$MODO" = dns ]; then openssl s_client -connect "$IP:$PUERTO" -servername "$HOST" -verify_hostname "$HOST" -verify_return_error </dev/null 2>&1 | grep -q 'Verify return code: 0'; else openssl s_client -connect "$IP:$PUERTO" -verify_ip "$IP" -verify_return_error </dev/null 2>&1 | grep -q 'Verify return code: 0'; fi; }
v_app_y_api() { web "$URL_BASE/" | grep -q 'id="root"' && web "$URL_BASE/api/ping" | grep -q '"pong":true'; }
v_tls_moderno() { local s=(); [ "$MODO" = dns ] && s=(-servername "$HOST"); openssl s_client -connect "$IP:$PUERTO" "${s[@]}" -tls1_2 </dev/null >/dev/null 2>&1 && ! openssl s_client -connect "$IP:$PUERTO" "${s[@]}" -tls1_1 </dev/null >/dev/null 2>&1 && ! openssl s_client -connect "$IP:$PUERTO" "${s[@]}" -tls1 </dev/null >/dev/null 2>&1; }
v_hsts() { local r; for r in / /api/ping; do web -o /dev/null -D - "$URL_BASE$r" | tr -d '\r' | grep -qiE '^strict-transport-security: max-age=([3-9][0-9]{7}|[1-9][0-9]{8,})' || return 1; done; }
v_sin_hsts_en_ip() { local r; for r in / /api/ping; do web -o /dev/null -D - "$URL_BASE$r" | grep -qi '^strict-transport-security:' && return 1; done; return 0; }
v_http_plano() { [ "$(curl -sS -m 8 -o /dev/null -w '%{http_code}' "http://$IP:$PUERTO/api/ping")" = 400 ] && [ "$(curl -sS -m 8 -o /dev/null -w '%{http_code}' -H 'X-Forwarded-Proto: https' -H 'Content-Type: application/json' -d '{}' "http://$IP:$PUERTO/api/login")" = 400 ]; }
v_sin_sesion() { [ "$(web -o /dev/null -w '%{http_code}' "$URL_BASE/api/user/profile")" = 401 ] && [ "$(web -o /dev/null -w '%{http_code}' -X POST -d '{}' "$URL_BASE/api/register")" = 403 ] && ! web -o /dev/null -D - "$URL_BASE/api/ping" | grep -qi '^set-cookie:' && web -o /dev/null -D - "$URL_BASE/api/ping" | grep -qi '^cache-control: no-store'; }
v_csp() { web -o /dev/null -D - "$URL_BASE/" | tr -d '\r' | grep -qi '^content-security-policy:.*frame-src https://app.powerbi.com' && web -o /dev/null -D - "$URL_BASE/" | grep -qi '^x-frame-options: deny'; }
v_redes() { grep -q "Require ip $REDES" "$CONF_CORP" && grep -q 'AuthMerging And' "$CONF_CORP" && [ "$(grep -n 'Include ' "$CONF_CORP" | head -1 | cut -d: -f1)" -lt "$(grep -n 'Require ip' "$CONF_CORP" | head -1 | cut -d: -f1)" ]; }
v_cert_vigente() { openssl x509 -in "$DIR_TLS_CORP/servidor.crt" -noout -checkend $(( DIAS_MIN_CERT * 86400 )); }
v_clave_protegida() { tls_clave_protegida "$DIR_TLS_CORP/servidor.key" && [ -z "$(find "$PAQ" \( -name '*.key' -o -name '*.pem' \) -print -quit)" ]; }
v_tunel_vivo() { loop https://localhost:$PUERTO_LOOP/api/ping | grep -q '"pong":true'; }
v_tunel_sin_hsts() { local r; for r in / /api/ping; do loop -o /dev/null -D - "https://localhost:$PUERTO_LOOP$r" | grep -qi '^strict-transport-security:' && return 1; done; return 0; }
v_vecinos_iguales() { local i; for i in 1 2 3 4 5 6 7 8; do [ "$(huella_portales)" = "$(cat "$LINEA_BASE_CORP")" ] && return 0; sleep 1; done; return 1; }

verificar_corp() {
  fallos=0
  echo "== Verificación de la publicación interna (SOLO LECTURA) · $URL_BASE · modo $MODO"
  chk "la conexión HTTPS se valida con el almacén de confianza del sistema (cadena y $([ "$MODO" = ip ] && echo 'IP' || echo 'nombre')), sin -k ni certificados importados a mano" v_cadena_sistema
  chk "openssl valida cadena y $([ "$MODO" = ip ] && echo "IP ($IP)" || echo "nombre ($HOST)")" v_openssl
  chk "la aplicación se sirve (index con #root) y la API responde pong" v_app_y_api
  chk "solo TLS 1.2/1.3 (TLS 1.0 y 1.1 fallan)" v_tls_moderno
  if [ "$MODO" = dns ]; then chk "HSTS se emite en la aplicación y en la API (nombre propio): max-age ≥ 1 año" v_hsts; else chk "no se emite HSTS en una IP (los navegadores lo ignoran; no se simula una garantía que no existe)" v_sin_hsts_en_ip; fi
  chk "una petición HTTP sin cifrar a $IP:$PUERTO se rechaza (400) y un X-Forwarded-Proto falsificado tampoco permite iniciar sesión" v_http_plano
  chk "sin sesión el perfil da 401 y las escrituras 403; sin cookies; Cache-Control no-store" v_sin_sesion
  chk "CSP (frame-src solo Power BI) y X-Frame-Options en la aplicación" v_csp
  chk "la restricción por redes cliente está en la configuración: Require ip $REDES" v_redes
  chk "el certificado vigente al menos $DIAS_MIN_CERT días más (quedan: $(dias_restantes "$DIR_TLS_CORP/servidor.crt"))" v_cert_vigente
  chk "la clave privada es de root y no legible por otros; el paquete no la contiene" v_clave_protegida
  chk "el acceso por túnel (loopback $PUERTO_LOOP) sigue funcionando con su certificado de prueba" v_tunel_vivo
  chk "el acceso por túnel NO emite HSTS (afectaría a los demás túneles de localhost), ni en la aplicación ni en la API" v_tunel_sin_hsts
  if [ -f "$LINEA_BASE_CORP" ]; then chk "Flutter, los demás portales y el resto del servidor siguen idénticos a antes de la publicación" v_vecinos_iguales
  else aviso "no hay línea base previa (se crea al instalar); no se compara el resto del servidor."; fi
  echo "verificación de la publicación: $([ "$fallos" -eq 0 ] && echo PASS || echo "$fallos FALLO(S)")"
  return "$fallos"
}

generar_conf() {
  local listen="# (el puerto $PUERTO ya lo atiende Apache: no se agrega otro Listen)"
  if [ "$(estado_puerto)" = libre ] && ! hay_listen_apache; then listen="Listen $IP:$PUERTO"; fi
  cat <<EOF
# Publicación INTERNA del portal ($SITIO) · $URL_BASE → IP privada $IP (modo $MODO). Generado por scripts/corporativo.sh (no editar a mano).
# TLS real en el propio socket (SSLEngine on, solo TLS 1.2/1.3). Misma configuración común y mismo backend que el sitio de loopback. Retirar con:  sudo bash scripts/corporativo.sh revertir
$listen

<VirtualHost $IP:$PUERTO>
    ServerName $NOMBRE
    Define CERVESUR_USUARIOS_FRONT    $DIR_PUBLIC
    Define CERVESUR_USUARIOS_BACKEND  $DIR_BACKEND
    Define CERVESUR_USUARIOS_SESSIONS $DIR_SESIONES

    SSLEngine on
    SSLCertificateFile    $DIR_TLS_CORP/servidor.crt
    SSLCertificateKeyFile $DIR_TLS_CORP/servidor.key
    SSLProtocol -all +TLSv1.2 +TLSv1.3
    SSLCompression off

    Include $DIR_CONF/react-usuarios-common.conf

    # Solo las redes cliente autorizadas (LAN/Wi-Fi corporativa y VPN). REMOTE_ADDR es la dirección real de la conexión: Apache descarta los X-Forwarded-*. Cualquier otra → 403 en toda ruta y método.
    # Va DESPUÉS del Include y con «AuthMerging And»: las secciones <Location "/"> se fusionan en orden y, sin esto, la política común de métodos (<LimitExcept>) REEMPLAZARÍA esta restricción
    # y dejaría pasar a cualquier red (comprobado en el ensayo). Con «And» deben cumplirse las dos: la red autorizada Y la política común (solo GET/POST).
    <Location "/">
        AuthMerging And
        Require ip $REDES
    </Location>

    ErrorLog  \${APACHE_LOG_DIR}/${SITIO_CORP}-error.log
    CustomLog \${APACHE_LOG_DIR}/${SITIO_CORP}-access.log combined
</VirtualHost>
EOF
}

quitar_variable_env() { local tmp; tmp=$(mktemp "$DIR_BACKEND/.env.nuevo.XXXXXX"); grep -v "^$1=" "$ENV_FILE" > "$tmp" || true; chown root:www-data "$tmp"; chmod 640 "$tmp"; mv -f "$tmp" "$ENV_FILE"; }

revertir_corp() {
  echo "== Retirando SOLO esta publicación"
  a2dissite "$SITIO_CORP" >/dev/null 2>&1 || true
  rm -f "$CONF_CORP" "$ENLACE_CORP"
  rm -rf "$DIR_TLS_CORP"
  # al retirar la publicación no deben quedar claves privadas: tampoco las de renovaciones anteriores guardadas en los respaldos (se destruyen con shred)
  local d; for d in "$DIR_RESPALDO"/tls-corp-anterior-*; do if [ -d "$d" ]; then find "$d" -type f -exec shred -u {} + 2>/dev/null || true; rm -rf "$d"; fi; done
  # El .env solo se toca si esta publicación cambió HSTS (modo DNS). En modo IP no se modificó: reescribirlo cambiaría su fecha sin cambiar su contenido y ensuciaría la comparación de huellas.
  if [ -f "$ENV_PREVIO_CORP" ]; then
    local previo actual; previo=$(cat "$ENV_PREVIO_CORP"); actual=$(sed -n 's/^CERVESUR_PILOT_HSTS=//p' "$ENV_FILE" | head -1)
    if [ "$previo" = "__AUSENTE__" ]; then [ -z "$actual" ] || quitar_variable_env CERVESUR_PILOT_HSTS
    else [ "$actual" = "$previo" ] || reemplazar_env CERVESUR_PILOT_HSTS "$previo"; fi
  fi
  apache_recargar && ok "VirtualHost, certificado y (modo DNS) HSTS retirados; recarga suave (sin reinicio)"
}

case $ORDEN in
  preflight)
    argumentos_requeridos
    preflight_corp; rc=$?
    if [ "$rc" -eq 0 ]; then ok "PREFLIGHT de la publicación: PASS (nada se modificó)"; exit 0; else fallo "PREFLIGHT de la publicación: $rc FALLO(S) (nada se modificó)"; exit 1; fi ;;
  verificar)
    argumentos_requeridos
    verificar_corp; rc=$?; [ "$rc" -eq 0 ] && exit 0 || exit 1 ;;
  instalar)
    argumentos_requeridos
    preflight_corp; rc=$?; [ "$rc" -eq 0 ] || abortar "El preflight de la publicación falló ($rc): no se instaló nada."
    { [ ! -e "$CONF_CORP" ] && [ ! -d "$DIR_TLS_CORP" ]; } || abortar "Ya existe una publicación de este portal (use revertir primero)."
    if [ "${CERVESUR_USUARIOS_CONFIRMAR:-}" != "SI" ]; then
      read -r -p "Escriba INSTALAR-CORP para publicar $URL_BASE (redes: $REDES): " r || r=""; [ "$r" = "INSTALAR-CORP" ] || abortar "Cancelado. No se modificó nada."
    fi
    install -d -m 700 "$DIR_RESPALDO"
    tar czf "$DIR_RESPALDO/apache2-etc-corp-$(date -u +%Y%m%d-%H%M%SZ).tgz" -C / etc/apache2 2>/dev/null
    huella_portales > "$LINEA_BASE_CORP"; chmod 600 "$LINEA_BASE_CORP"
    previo=$(sed -n 's/^CERVESUR_PILOT_HSTS=//p' "$ENV_FILE" | head -1); printf '%s\n' "${previo:-__AUSENTE__}" > "$ENV_PREVIO_CORP"; chmod 600 "$ENV_PREVIO_CORP"
    echo "== Instalando"
    install -d -m 755 -o root -g root "$DIR_TLS_CORP"
    install -m 644 -o root -g root "$CERT" "$DIR_TLS_CORP/servidor.crt"
    ( umask 077; cat "$CLAVE" > "$DIR_TLS_CORP/servidor.key" ); chown root:root "$DIR_TLS_CORP/servidor.key"; chmod 600 "$DIR_TLS_CORP/servidor.key"
    generar_conf > "$CONF_CORP"; chmod 644 "$CONF_CORP"
    a2ensite "$SITIO_CORP" >/dev/null
    [ "$MODO" = dns ] && reemplazar_env CERVESUR_PILOT_HSTS SI
    if ! apache2ctl configtest >/dev/null 2>&1; then fallo "apache2ctl configtest falló: se revierte (Apache no se recargó)."; revertir_corp; exit 1; fi
    apache_recargar
    ok "VirtualHost activo (recarga suave; Apache no se reinició)"
    if ! verificar_corp; then fallo "La verificación falló: se revierte lo instalado."; revertir_corp; exit 1; fi
    ok "PUBLICACIÓN COMPLETA: $URL_BASE. Revertir: sudo bash scripts/corporativo.sh revertir" ;;
  renovar)
    argumentos_requeridos
    [ -f "$CONF_CORP" ] && [ -d "$DIR_TLS_CORP" ] || abortar "No hay una publicación instalada que renovar."
    PREVIO=0; preflight_corp; rc=$?; [ "$rc" -eq 0 ] || abortar "El certificado nuevo no pasa el preflight ($rc): no se cambió nada."
    if [ "${CERVESUR_USUARIOS_CONFIRMAR:-}" != "SI" ]; then read -r -p "Escriba RENOVAR-CORP para cambiar el certificado de $URL_BASE: " r || r=""; [ "$r" = "RENOVAR-CORP" ] || abortar "Cancelado. No se modificó nada."; fi
    ant="$DIR_RESPALDO/tls-corp-anterior-$(date -u +%Y%m%d-%H%M%SZ)"; install -d -m 700 "$ant"; cp -p "$DIR_TLS_CORP/servidor.crt" "$DIR_TLS_CORP/servidor.key" "$ant/"
    install -m 644 -o root -g root "$CERT" "$DIR_TLS_CORP/servidor.crt"; ( umask 077; cat "$CLAVE" > "$DIR_TLS_CORP/servidor.key" ); chown root:root "$DIR_TLS_CORP/servidor.key"; chmod 600 "$DIR_TLS_CORP/servidor.key"
    if apache2ctl configtest >/dev/null 2>&1 && apache_recargar && verificar_corp; then ok "CERTIFICADO RENOVADO (el anterior queda en $ant, root 700). Sin reinicio de Apache."
    else fallo "La renovación falló: se restauran el certificado y la clave anteriores."; cp -p "$ant/servidor.crt" "$ant/servidor.key" "$DIR_TLS_CORP/"; chmod 600 "$DIR_TLS_CORP/servidor.key"; apache_recargar; exit 1; fi ;;
  revertir)
    if [ "${CERVESUR_USUARIOS_CONFIRMAR:-}" != "SI" ]; then read -r -p "Escriba REVERTIR-CORP para retirar la publicación: " r || r=""; [ "$r" = "REVERTIR-CORP" ] || abortar "Cancelado. No se modificó nada."; fi
    revertir_corp
    if [ -f "$LINEA_BASE_CORP" ]; then if v_vecinos_iguales; then ok "Flutter, los demás portales y el resto del servidor, idénticos a antes"; else aviso "la huella del servidor difiere de la previa a la publicación: revise (no la causa este script)."; fi; fi ;;
  *) abortar "Uso: corporativo.sh preflight|instalar|verificar|renovar|revertir --ip IP [--host H] [--puerto P] --redes \"CIDR…\" --cert FULLCHAIN --clave CLAVE [--previo]" ;;
esac
