#!/usr/bin/env bash
# Certificado TLS del piloto administrativo de solo lectura (UX-07D.4B.1). La clave privada se crea EN el servidor, queda en /etc/apache2/cervesur-react-admin/tls (root:root 600)
# y no viaja jamás por el paquete, por argumentos ni por la salida estándar.
#   sudo bash scripts/tls.sh generar               certificado AUTOFIRMADO de PRUEBA (EC P-256, 365 días) para localhost / 127.0.0.1 (acceso privado por túnel SSH)
#   sudo bash scripts/tls.sh instalar-propio CRT KEY   instala un certificado emitido por una CA (corporativa o pública) tras validarlo; no genera nada
#   sudo bash scripts/tls.sh publico               imprime el certificado PÚBLICO (PEM) para importarlo como de confianza en el equipo del operador
#   sudo bash scripts/tls.sh huella                imprime la huella SHA-256 del certificado, para comprobarla al importarlo
#   sudo bash scripts/tls.sh verificar             comprueba vigencia, nombre, correspondencia con la clave y permisos de la clave
# Un certificado autofirmado solo es aceptable para pruebas privadas por túnel; el acceso corporativo exige un certificado de una CA con el nombre de acceso (docs/HTTPS-PILOTO-USUARIOS.md).
set -uo pipefail
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
requerir_root

preparar_dir() { install -d -m 755 "$DIR_CONF"; install -d -m 700 -o root -g root "$DIR_TLS"; }

case ${1:-} in
  generar)
    [ ! -e "$KEY_FILE" ] && [ ! -e "$CERT_FILE" ] || abortar "Ya existe un certificado o una clave en $DIR_TLS: no se sobrescribe."
    preparar_dir
    ( umask 077; openssl ecparam -name prime256v1 -genkey -noout -out "$KEY_FILE" ) || abortar "No se pudo generar la clave privada."
    chown root:root "$KEY_FILE"; chmod 600 "$KEY_FILE"
    openssl req -new -x509 -key "$KEY_FILE" -sha256 -days 365 -subj "/CN=localhost/O=Cervesur piloto de usuarios (PRUEBA)" \
      -addext "subjectAltName=DNS:localhost,IP:127.0.0.1" -addext "basicConstraints=critical,CA:FALSE" \
      -addext "keyUsage=critical,digitalSignature" -addext "extendedKeyUsage=serverAuth" -out "$CERT_FILE" 2>/dev/null || { rm -f -- "$KEY_FILE" "$CERT_FILE"; abortar "No se pudo generar el certificado."; }
    chown root:root "$CERT_FILE"; chmod 644 "$CERT_FILE"
    ok "certificado autofirmado de PRUEBA generado en $CERT_FILE (clave privada en $KEY_FILE, root 600; no se muestra)" ;;
  instalar-propio)
    crt=${2:-}; key=${3:-}
    [ -r "$crt" ] && [ -r "$key" ] || abortar "Uso: tls.sh instalar-propio CERTIFICADO CLAVE (ambos legibles)."
    tls_clave_protegida "$key" || abortar "La clave privada de origen debe ser de root con modo 600/400."
    tls_certificado_vigente "$crt" 30 localhost || abortar "El certificado caduca en menos de 30 días o no cubre el nombre «localhost»."
    tls_clave_corresponde "$crt" "$key" || abortar "La clave privada no corresponde al certificado."
    [ ! -e "$KEY_FILE" ] && [ ! -e "$CERT_FILE" ] || abortar "Ya existe un certificado o una clave en $DIR_TLS: no se sobrescribe."
    preparar_dir
    install -m 600 -o root -g root "$key" "$KEY_FILE"; install -m 644 -o root -g root "$crt" "$CERT_FILE"
    ok "certificado propio instalado en $CERT_FILE (clave privada en $KEY_FILE, root 600; no se muestra)" ;;
  publico)
    [ -r "$CERT_FILE" ] || abortar "No hay certificado en $CERT_FILE."
    openssl x509 -in "$CERT_FILE" ;;
  huella)
    [ -r "$CERT_FILE" ] || abortar "No hay certificado en $CERT_FILE."
    openssl x509 -in "$CERT_FILE" -noout -fingerprint -sha256 ;;
  verificar)
    [ -r "$CERT_FILE" ] && [ -r "$KEY_FILE" ] || abortar "Falta el certificado o la clave en $DIR_TLS."
    errores=0
    if tls_certificado_vigente "$CERT_FILE" 7 localhost; then ok "certificado vigente (no caduca en los próximos 7 días) y cubre «localhost»"; else fallo "certificado caducado, a punto de caducar o sin el nombre «localhost»"; errores=$((errores + 1)); fi
    if tls_clave_corresponde "$CERT_FILE" "$KEY_FILE"; then ok "la clave privada corresponde al certificado"; else fallo "la clave privada NO corresponde al certificado"; errores=$((errores + 1)); fi
    if tls_clave_protegida "$KEY_FILE"; then ok "la clave privada es de root con modo restrictivo"; else fallo "la clave privada tiene permisos o propietario inseguros"; errores=$((errores + 1)); fi
    [ "$errores" -eq 0 ] ;;
  *) abortar "Uso: tls.sh generar | instalar-propio CRT KEY | publico | huella | verificar" ;;
esac
