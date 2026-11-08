#!/usr/bin/env bash
# CA PRIVADA DE CERVESUR + CERTIFICADO PARA LA IP DEL SERVIDOR (UX-07E.D). Se ejecuta en un equipo de CONFIANZA del operador (no en el servidor): Linux, WSL o Git Bash con OpenSSL 1.1.1+.
#   bash emitir-certificado-ip.sh --ip 192.168.200.12 --red 192.168.200.0/24 [--red 10.8.0.0/24] [--dias 397] [--salida ./pki-cervesur] [--sin-clave-ca]
# Qué produce en la carpeta de salida:
#   ca/ca.key        clave de la CA, CIFRADA con una frase de paso. NO sale de este equipo: guárdela fuera de línea (USB cifrado). Quien la tenga puede emitir certificados.
#   ca/ca.crt        certificado de la CA (PÚBLICO): es lo único que se distribuye a los equipos para que confíen. Restringido por «name constraints» a las redes indicadas.
#   servidor/servidor.crt, servidor/servidor.key   certificado de la IP (SAN = IP Address) y su clave: van al servidor (scripts/corporativo.sh). La clave del servidor NO lleva frase (Apache debe poder arrancar).
#   servidor/fullchain.pem  = servidor.crt (la raíz no se envía: los clientes ya la tienen)
#   LEEME-confianza.txt     huella de la CA para verificarla al instalarla y los comandos de instalación.
# Seguridad de la CA: «basicConstraints CA:TRUE, pathlen:0» (no puede crear CAs subordinadas) y «nameConstraints» permitiendo SOLO direcciones IP de las redes indicadas: aunque su clave se filtrara,
# un navegador rechazaría certificados para cualquier otra IP o nombre (los nombres DNS quedan limitados a un dominio «.invalid» inexistente). Validez de la CA: 5 años. Validez del certificado: 397 días (renovación anual; ver docs/PUBLICACION-IP-INTERNA.md).
set -euo pipefail
IP=""; REDES=(); DIAS=397; SALIDA="./pki-cervesur"; CIFRAR=1
while [ $# -gt 0 ]; do
  case $1 in
    --ip) IP=${2:-}; shift 2 ;;
    --red) REDES+=("${2:-}"); shift 2 ;;
    --dias) DIAS=${2:-}; shift 2 ;;
    --salida) SALIDA=${2:-}; shift 2 ;;
    --sin-clave-ca) CIFRAR=0; shift ;;   # SOLO para pruebas automatizadas: la clave de la CA queda sin frase
    *) echo "Argumento desconocido: $1" >&2; exit 2 ;;
  esac
done
fallo() { echo "ERROR: $*" >&2; exit 1; }
[[ $IP =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || fallo "Indique --ip con una dirección IPv4."
IFS=. read -r a b _ _ <<<"$IP"
{ [ "$a" = 10 ] || { [ "$a" = 172 ] && [ "$b" -ge 16 ] && [ "$b" -le 31 ]; } || { [ "$a" = 192 ] && [ "$b" = 168 ]; }; } || fallo "La IP $IP no es privada: este procedimiento no emite certificados para direcciones públicas."
[ ${#REDES[@]} -gt 0 ] || fallo "Indique al menos una --red (CIDR) que la CA podrá cubrir; incluya la del servidor (p. ej. 192.168.200.0/24)."
[[ $DIAS =~ ^[0-9]+$ ]] && [ "$DIAS" -ge 30 ] && [ "$DIAS" -le 825 ] || fallo "--dias debe estar entre 30 y 825 (se recomienda 397)."
cidr_a_mascara() { # 192.168.200.0/24 → 192.168.200.0/255.255.255.0
  local red=${1%/*} pref=${1#*/} m="" i bits
  [[ $1 =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}/([0-9]|[12][0-9]|3[0-2])$ ]] || fallo "CIDR no válido: $1"
  for i in 1 2 3 4; do if [ "$pref" -ge 8 ]; then bits=255; pref=$((pref - 8)); elif [ "$pref" -gt 0 ]; then bits=$(( 256 - (1 << (8 - pref)) )); pref=0; else bits=0; fi; m="$m${m:+.}$bits"; done
  echo "$red/$m"
}
ip_en_red() { # ip red-cidr
  local ip=$1 red=${2%/*} pref=${2#*/} ia ra n i
  ia=0; ra=0; for n in ${ip//./ }; do ia=$(( ia * 256 + n )); done; for n in ${red//./ }; do ra=$(( ra * 256 + n )); done
  [ "$pref" -eq 0 ] && return 0; i=$(( (0xFFFFFFFF << (32 - pref)) & 0xFFFFFFFF )); [ $(( ia & i )) -eq $(( ra & i )) ]
}
cubierta=0; for r in "${REDES[@]}"; do ip_en_red "$IP" "$r" && cubierta=1; done
[ "$cubierta" -eq 1 ] || fallo "Ninguna de las --red indicadas contiene la IP $IP: la CA no podría certificar al servidor."
command -v openssl >/dev/null || fallo "Se necesita OpenSSL."

umask 077
mkdir -p "$SALIDA/ca" "$SALIDA/servidor"
CA_KEY="$SALIDA/ca/ca.key"; CA_CRT="$SALIDA/ca/ca.crt"

if [ ! -f "$CA_KEY" ]; then
  echo "== Creando la CA privada de Cervesur (una sola vez)"
  cat > "$SALIDA/ca/ca.cnf" <<EOF
[req]
distinguished_name = dn
prompt = no
x509_extensions = v3_ca
[dn]
CN = Cervesur CA interna (IP privada)
O = Corporacion Cervesur
[v3_ca]
basicConstraints = critical,CA:TRUE,pathlen:0
keyUsage = critical,keyCertSign,cRLSign
subjectKeyIdentifier = hash
nameConstraints = critical,@nc
[nc]
$(i=0; for r in "${REDES[@]}"; do i=$((i + 1)); echo "permitted;IP.$i = $(cidr_a_mascara "$r")"; done)
permitted;DNS.1 = cervesur-interno.invalid
EOF
  if [ "$CIFRAR" -eq 1 ]; then
    echo "Elija una FRASE DE PASO larga para la clave de la CA (se pedirá al emitir o renovar certificados):"
    openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-256 -aes-256-cbc -out "$CA_KEY"
  else
    openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-256 -out "$CA_KEY"
  fi
  openssl req -x509 -new -key "$CA_KEY" -days 1825 -config "$SALIDA/ca/ca.cnf" -out "$CA_CRT"
  chmod 600 "$CA_KEY"; chmod 644 "$CA_CRT"
else
  echo "== Usando la CA existente en $SALIDA/ca (renovación del certificado del servidor)"
fi

echo "== Emitiendo el certificado de la IP $IP (SAN = IP Address)"
openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-256 -out "$SALIDA/servidor/servidor.key"
openssl req -new -key "$SALIDA/servidor/servidor.key" -subj "/O=Corporacion Cervesur/OU=Servidor $IP" -out "$SALIDA/servidor/servidor.csr"
cat > "$SALIDA/servidor/servidor.ext" <<EOF
subjectAltName = IP:$IP
basicConstraints = critical,CA:FALSE
keyUsage = critical,digitalSignature
extendedKeyUsage = serverAuth
subjectKeyIdentifier = hash
authorityKeyIdentifier = keyid,issuer
EOF
openssl x509 -req -in "$SALIDA/servidor/servidor.csr" -CA "$CA_CRT" -CAkey "$CA_KEY" -CAcreateserial -CAserial "$SALIDA/ca/ca.srl" -days "$DIAS" -extfile "$SALIDA/servidor/servidor.ext" -out "$SALIDA/servidor/servidor.crt"
cp "$SALIDA/servidor/servidor.crt" "$SALIDA/servidor/fullchain.pem"; chmod 600 "$SALIDA/servidor/servidor.key"; chmod 644 "$SALIDA/servidor/servidor.crt" "$SALIDA/servidor/fullchain.pem"
rm -f "$SALIDA/servidor/servidor.csr" "$SALIDA/servidor/servidor.ext"

echo "== Autocomprobación"
openssl verify -CAfile "$CA_CRT" "$SALIDA/servidor/servidor.crt" >/dev/null || fallo "El certificado emitido no valida contra la CA."
openssl x509 -in "$SALIDA/servidor/servidor.crt" -noout -checkip "$IP" | grep -q 'does match' || fallo "El certificado no cubre la IP $IP."
openssl x509 -in "$SALIDA/servidor/servidor.crt" -noout -ext subjectAltName | grep -q "IP Address:$IP" || fallo "El SAN no contiene la IP como «IP Address»."
[ "$(openssl x509 -in "$SALIDA/servidor/servidor.crt" -noout -pubkey | sha256sum)" = "$(openssl pkey -in "$SALIDA/servidor/servidor.key" -pubout | sha256sum)" ] || fallo "La clave no corresponde al certificado."
HUELLA=$(openssl x509 -in "$CA_CRT" -noout -fingerprint -sha256 | cut -d= -f2)
VENCE=$(openssl x509 -in "$SALIDA/servidor/servidor.crt" -noout -enddate | cut -d= -f2)
cat > "$SALIDA/LEEME-confianza.txt" <<EOF
CA interna de Cervesur — huella SHA-256 (verifíquela contra esta línea ANTES de confiar):
$HUELLA

Instalar la CA en un equipo Windows (como administrador; Brave/Chrome/Edge usan este almacén):
  certutil -addstore -f Root ca\ca.crt
  (o por equipo/usuario sin permisos de administrador:  certutil -user -addstore Root ca\ca.crt)
  Comprobar:  certutil -store Root "Cervesur CA interna"   → la huella debe ser la de arriba.
Distribución a muchos equipos: directiva de grupo (GPO) «Entidades de certificación raíz de confianza» o Intune. Firefox: política security.enterprise_roots.enabled=true (usa el almacén de Windows).
Android/iOS: instalar ca.crt como certificado de CA y confiar en él (iOS: Ajustes ▸ General ▸ Información ▸ Confianza de certificados).
El certificado del servidor vence: $VENCE. Renovar antes de 30 días (mismo comando; usa la CA existente) y ejecutar scripts/corporativo.sh renovar en el servidor.
NUNCA copie ca.key al servidor ni la envíe por correo. Solo ca.crt se distribuye.
EOF
echo
echo "Listo en $SALIDA"
echo "  CA pública (distribuir):    $CA_CRT"
echo "  Huella SHA-256 de la CA:    $HUELLA"
echo "  Para el servidor:           $SALIDA/servidor/fullchain.pem  y  $SALIDA/servidor/servidor.key"
echo "  El certificado vence:       $VENCE"
