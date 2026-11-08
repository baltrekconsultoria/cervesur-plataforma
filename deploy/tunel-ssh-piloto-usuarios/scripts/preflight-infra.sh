#!/usr/bin/env bash
# PREFLIGHT A · INFRAESTRUCTURA del piloto con usuarios reales (UX-07D.4B.1). SOLO LECTURA: no modifica nada y no necesita las cuentas MySQL del piloto.
#   sudo bash scripts/preflight-infra.sh [--tls-cert RUTA --tls-clave RUTA]
# Código de salida: 0 = todas las comprobaciones pasan · 1 = alguna falla.
# Comprueba: Apache y módulos (incluido mod_ssl, que este instalador NO habilita), PHP y extensiones, puerto 8092 libre (se comprueba, no se supone), ausencia de
# colisiones, integridad del paquete, Flutter/8088/8089/8090/8091, MySQL (activo, TCP local, versión, base de lectura existente: solo metadatos), espacio en disco
# y —si se aporta un certificado propio— su validez, su correspondencia con la clave y los permisos de la clave privada.
set -uo pipefail
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
TLS_CERT=""; TLS_CLAVE=""
while [ $# -gt 0 ]; do
  case $1 in
    --tls-cert) TLS_CERT=${2:-}; shift 2 ;;
    --tls-clave) TLS_CLAVE=${2:-}; shift 2 ;;
    *) abortar "Argumento desconocido: $1" ;;
  esac
done
requerir_root
verificar_no_colision

errores=0
chk() { local descripcion=$1; shift; if "$@" >/dev/null 2>&1; then ok "$descripcion"; else fallo "$descripcion"; errores=$((errores + 1)); fi; }
sin_nada_en_puerto() { [ -z "$(ss -ltnH "sport = :$PUERTO")" ]; }
no_existe() { [ ! -e "$1" ] && [ ! -L "$1" ]; }
mysql_tcp_local() { local escuchas; escuchas=$(ss -ltnH 'sport = :3306' | awk '{print $4}'); grep -qE '^(127\.0\.0\.1|0\.0\.0\.0|\*|\[::\]):3306$' <<<"$escuchas"; }
espacio_libre() { [ "$(df --output=avail -k "$1" | tail -1 | tr -d ' ')" -ge 512000 ]; }
responde() { local c; c=$(curl -sS -m 6 -o /dev/null -w '%{http_code}' "$1" 2>/dev/null) || return 1; [ "$c" -ge 200 ] && [ "$c" -lt 500 ]; }
version_mysql_ok() { # >= 5.7.20 (primer lanzamiento con @@session.transaction_read_only); recomendado 8.0
  local v; v=$($MYSQL_ADMIN -N -e 'SELECT VERSION()') || return 1
  printf '%s\n5.7.20\n' "${v%%-*}" | sort -V -C -r
}
base_existe() { [ -n "$($MYSQL_ADMIN -N -e "SHOW DATABASES LIKE '$1'")" ]; }

echo "== Preflight A · infraestructura (solo lectura)"
if grep -q 'VERSION_ID="24.04"' /etc/os-release; then ok "Ubuntu 24.04"; else aviso "No es Ubuntu 24.04: revise antes de continuar"; fi
ok "sin colisión de nombres, rutas, puertos, cuentas ni cookie con Flutter (80), 8088, 8089, 8090 y 8091"
chk "Apache: configuración actual válida (configtest)" apache2ctl configtest
chk "mod_alias activo" modulo_apache alias
chk "PHP integrado en Apache (mod_php)" modulo_apache 'php[0-9]*'
chk "mod_headers YA cargado (lo habilitó el instalador de 8088; este instalador no lo toca jamás)" modulo_apache headers
chk "mod_ssl YA cargado (TLS real en el propio socket; este instalador NO lo habilita: si falta, lo habilita un administrador con autorización expresa)" modulo_apache ssl
# shellcheck disable=SC2016  # el código PHP va entre comillas simples a propósito
chk "PHP con las extensiones del backend (pdo_mysql, mbstring, json, openssl)" php -r 'foreach (["pdo_mysql", "mbstring", "json", "openssl"] as $e) { if (!extension_loaded($e)) { exit(1); } }'
chk "PHP >= 8.0 (el backend usa tipos de unión)" php -r 'exit(version_compare(PHP_VERSION, "8.0.0", ">=") ? 0 : 1);'
chk "openssl, curl y sha256sum disponibles" bash -c "command -v openssl && command -v curl && command -v sha256sum"
chk "MySQL local en ejecución" mysql_activo
chk "MySQL escucha en 127.0.0.1:3306 (el backend conecta por TCP local)" mysql_tcp_local
# shellcheck disable=SC2086
chk "acceso de LECTURA de metadatos a MySQL (CERVESUR_MYSQL_ADMIN si no es root por socket)" $MYSQL_ADMIN -N -e 'SELECT 1'
chk "versión de MySQL >= 5.7.20 (necesaria para la sesión de solo lectura verificable)" version_mysql_ok
chk "la base de lectura $DB_NOMBRE existe (solo se comprueba su existencia; no se consulta)" base_existe "$DB_NOMBRE"
for ruta in "$DIR_FRONT" "$DIR_BACK" "$DIR_CONF" "$DIR_VAR" "$SITE_CONF" "$SITE_ENABLED"; do chk "no existe $ruta" no_existe "$ruta"; done
# El puerto se COMPRUEBA en este servidor (no se asume): ni un socket a la escucha ni una mención en la configuración de Apache.
chk "puerto $PUERTO libre (ningún socket a la escucha)" sin_nada_en_puerto
chk "ninguna configuración de Apache menciona el puerto $PUERTO" bash -c "! grep -RqsE '(^|[^0-9])$PUERTO([^0-9]|\$)' /etc/apache2/ports.conf /etc/apache2/sites-enabled /etc/apache2/conf-enabled"
chk "espacio libre suficiente en /var y /opt (>= 500 MB)" bash -c "$(declare -f espacio_libre); espacio_libre /var && espacio_libre /opt"
chk "el paquete trae la compilación del piloto de usuarios (public/index.html y build-info.json con pilot=true, pilotMode=usuarios, target=server y host localhost)" bash -c "[ -f '$PAQ/public/index.html' ] && grep -q '\"pilot\": true' '$PAQ/public/build-info.json' && grep -q '\"pilotMode\": \"usuarios\"' '$PAQ/public/build-info.json' && grep -q '\"target\": \"server\"' '$PAQ/public/build-info.json' && grep -q localhost '$PAQ/public/build-info.json'"
chk "integridad del paquete (SHA256SUMS)" bash -c "cd '$PAQ' && sha256sum -c SHA256SUMS --quiet"
chk "el paquete no trae .env, certificados ni claves privadas" bash -c "! find '$PAQ' \( -name '.env*' -o -name '*.key' -o -name '*.pem' -o -name '*.crt' \) | grep -q . && ! grep -rIl -e '-----BEGIN [A-Z ]*PRIVATE KEY-----' '$PAQ' | grep -q ."

echo "-- Certificado TLS"
if [ -n "$TLS_CERT" ] || [ -n "$TLS_CLAVE" ]; then
  chk "certificado propio legible y clave privada legible" bash -c "[ -r '$TLS_CERT' ] && [ -r '$TLS_CLAVE' ]"
  chk "el certificado no caduca en los próximos 30 días y cubre el nombre «localhost»" tls_certificado_vigente "$TLS_CERT" 30 localhost
  chk "la clave privada corresponde al certificado" tls_clave_corresponde "$TLS_CERT" "$TLS_CLAVE"
  chk "la clave privada es de root con modo 600/400" tls_clave_protegida "$TLS_CLAVE"
else
  ok "certificado de PRUEBA: el instalador generará uno autofirmado para localhost (scripts/tls.sh); para un certificado propio use --tls-cert y --tls-clave"
fi
chk "openssl puede generar/validar certificados (x509, pkey)" bash -c "openssl x509 -help 2>&1 | grep -q -- -checkend && openssl pkey -help 2>&1 | grep -q -- -pubout"

echo "-- Portales existentes (solo se comprueba que responden; nunca se escriben)"
chk "Flutter (puerto $PUERTO_FLUTTER) existe y responde" bash -c "[ -d '$FLUTTER_DIR' ] && $(declare -f responde); responde http://127.0.0.1:$PUERTO_FLUTTER/"
if [ -e "$SITE_ENABLED_PRUEBA" ]; then chk "React de prueba (8088) instalado y responde" bash -c "$(declare -f responde); responde http://127.0.0.1:$PUERTO_PRUEBA/api/ping"; else aviso "El React de prueba (8088) no está instalado: se omite su huella"; fi
if [ -e "$SITE_ENABLED_COMPAT" ]; then chk "React de compatibilidad (8089) instalado y responde" bash -c "$(declare -f responde); responde http://127.0.0.1:$PUERTO_COMPAT/api/ping"; else aviso "El React de compatibilidad (8089) no está instalado: se omite su huella"; fi
if [ -e "$SITE_ENABLED_DIAG" ]; then chk "Diagnóstico (8090) instalado y responde" bash -c "$(declare -f responde); responde http://127.0.0.1:$PUERTO_DIAG/api/ping"; else aviso "El diagnóstico (8090) no está instalado: se omite su huella"; fi
if [ -e "$SITE_ENABLED_VISUAL" ]; then chk "Piloto visual (8091) instalado y responde" bash -c "$(declare -f responde); responde http://127.0.0.1:$PUERTO_VISUAL/api/ping"; else aviso "El piloto visual (8091) no está instalado: se omite su huella"; fi

if [ "$errores" -eq 0 ]; then echo "RESULTADO preflight A (infraestructura): PASS"; exit 0; fi
echo "RESULTADO preflight A (infraestructura): FAIL ($errores comprobación(es)). No se modificó nada." >&2
exit 1
