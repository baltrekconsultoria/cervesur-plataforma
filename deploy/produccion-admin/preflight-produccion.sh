#!/usr/bin/env bash
# Cervesur · UX-08G · PREFLIGHT de solo lectura de la liberación «versión 1 · solo lectura» del administrador React (8443). NO modifica nada.
#   sudo bash preflight-produccion.sh [--paquete <carpeta-del-paquete-extraído>]
# Responde, antes de cualquier intervención: ¿el administrador publicado está en el estado que el paquete espera? ¿Hay algo que impida actualizarlo y volver atrás?
# No imprime .env, claves, hashes, contraseñas ni enlaces: solo nombres de clave, estados, recuentos y PASS/FAIL. Variables de entorno de apoyo (solo para pruebas):
#   CERVESUR_ADMIN_BACK (/opt/cervesur-react-admin) · CERVESUR_ADMIN_FRONT (/var/www/cervesur-react-admin) · CERVESUR_ADMIN_RESPALDO (/var/backups/cervesur-react-admin)
#   CERVESUR_SIN_APACHE=1 · CERVESUR_SIN_MYSQL=1 · CERVESUR_SIN_RED=1 (omiten esas comprobaciones; solo para la prueba local, el resultado lo declara)
set -u
BACK=${CERVESUR_ADMIN_BACK:-/opt/cervesur-react-admin}
FRONT=${CERVESUR_ADMIN_FRONT:-/var/www/cervesur-react-admin}
RESPALDO=${CERVESUR_ADMIN_RESPALDO:-/var/backups/cervesur-react-admin}
ENVF=$BACK/backend/.env
PAQ=''
while [ "$#" -gt 0 ]; do case "$1" in --paquete) PAQ=${2:-}; shift 2 ;; *) echo "Opción desconocida: $1"; exit 2 ;; esac; done
pass=0; fail=0; aviso=0
p() { printf 'PASS   %s\n' "$*"; pass=$((pass + 1)); }
f() { printf 'FAIL   %s\n' "$*"; fail=$((fail + 1)); }
a() { printf 'AVISO  %s\n' "$*"; aviso=$((aviso + 1)); }
env_valor() { [ -r "$ENVF" ] && tr -d '\r' < "$ENVF" | awk -F= -v k="$1" '$1 == k { sub(/^[^=]*=/, ""); v = $0 } END { print v }'; }
env_clave_presente() { [ -r "$ENVF" ] && tr -d '\r' < "$ENVF" | grep -qE "^[[:space:]]*$1[[:space:]]*="; }

echo "################ PREFLIGHT DE LIBERACIÓN (solo lectura) ################"
echo "== 1. Instalación actual del administrador"
[ -d "$BACK/backend" ] && p "backend instalado en $BACK/backend" || f "falta $BACK/backend"
[ -d "$FRONT/public" ] && p "aplicación instalada en $FRONT/public" || f "falta $FRONT/public"
[ -r "$ENVF" ] && p "se puede leer el .env (solo se consultan nombres y valores NO sensibles)" || f "no se puede leer $ENVF (use sudo)"
[ "$(env_valor CERVESUR_MODE)" = piloto_admin ] && [ "$(env_valor CERVESUR_REQUIRED_MODE)" = piloto_admin ] && p "modo piloto_admin exigido (CERVESUR_MODE = CERVESUR_REQUIRED_MODE)" || f "el .env NO está en modo piloto_admin exigido"
[ -z "$(env_valor CERVESUR_INSECURE_TESTS)" ] && p "sin CERVESUR_INSECURE_TESTS (HTTPS obligatorio)" || f "CERVESUR_INSECURE_TESTS definido"
[ -z "$(env_valor CERVESUR_ALLOW_WRITABLE_DB_ACCOUNT)" ] && p "sin CERVESUR_ALLOW_WRITABLE_DB_ACCOUNT" || f "CERVESUR_ALLOW_WRITABLE_DB_ACCOUNT definido"
[ "$(env_valor USER_ADMIN_WRITES)" != 1 ] && p "USER_ADMIN_WRITES apagado (ausente o distinto de 1)" || f "USER_ADMIN_WRITES=1 en el .env del administrador publicado"
for k in AUDIT_LOG_MODE NEW_USER_ROLE; do env_clave_presente "$k" && a "$k definida en el .env: la versión 1 no la usa (no tiene efecto en piloto_admin)" || true; done
db=$(env_valor DB_NAME); [ "$db" = cervesur_portalbi ] && p "lee la base cervesur_portalbi" || a "DB_NAME no es cervesur_portalbi (revise)"
du=$(env_valor DB_USER); case "$du" in cervesur_ro_*) p "la cuenta de catálogo es de solo lectura por nombre ($du)" ;; *) f "DB_USER no es una cuenta cervesur_ro_* (¿cuenta con escritura?): $du" ;; esac
[ "$du" != cervesur_portalbi_admin ] && p "no usa la cuenta de Flutter (cervesur_portalbi_admin)" || f "usa la cuenta de Flutter cervesur_portalbi_admin"
[ -n "$(env_valor STATE_DB_NAME)" ] && [ "$(env_valor STATE_DB_NAME)" != "$db" ] && p "base de estado propia distinta de la de lectura" || f "STATE_DB_NAME ausente o igual a la base de lectura"

echo "== 2. Versión instalada frente al paquete"
if [ -r "$BACK/backend/VERSION" ] || [ -r "$BACK/VERSION" ] || [ -r "$FRONT/VERSION" ]; then
  for v in "$BACK/backend/VERSION" "$BACK/VERSION" "$FRONT/VERSION"; do [ -r "$v" ] && { echo "       instalada: $(head -1 "$v")"; break; }; done
else a "no hay archivo VERSION instalado: la identidad de lo instalado solo se conoce por el inventario de divergencia"; fi
if [ -n "$PAQ" ]; then
  [ -d "$PAQ/public" ] && [ -d "$PAQ/backend" ] && [ -f "$PAQ/SHA256SUMS" ] && p "el paquete tiene public/, backend/ y SHA256SUMS" || f "paquete incompleto en $PAQ"
  (cd "$PAQ" && sha256sum -c SHA256SUMS --quiet) >/dev/null 2>&1 && p "integridad del paquete (SHA256SUMS)" || f "integridad del paquete NO verificada"
  [ -f "$PAQ/VERSION" ] && echo "       paquete:   $(head -1 "$PAQ/VERSION")"
  ! find "$PAQ" \( -name '.env*' -o -name '*.key' -o -name '*.pem' -o -name '*.crt' \) -print -quit | grep -q . && p "el paquete no contiene .env, claves ni certificados" || f "el paquete contiene .env, claves o certificados"
  ! grep -rIlE '\bsyn[0-9]{3}\b' "$PAQ/backend" "$PAQ/public" 2>/dev/null | grep -q . && p "el paquete no contiene cuentas sintéticas de la QA (syn###)" || f "el paquete contiene referencias syn###"
  grep -q '"pilotMode": *"admin"' "$PAQ/public/build-info.json" 2>/dev/null && p "la aplicación del paquete es la compilación administrativa de solo lectura" || f "la aplicación del paquete no es pilotMode=admin"
  [ ! -e "$PAQ/database/migrations" ] && p "el paquete NO incluye migraciones (la versión 1 no cambia la base)" || f "el paquete incluye migraciones"
else a "sin --paquete: no se comprueba el paquete"; fi

echo "== 3. Servicios (solo lectura)"
if [ "${CERVESUR_SIN_RED:-0}" = 1 ]; then a "comprobación de red omitida (CERVESUR_SIN_RED=1)"; else
  for pt in 80 443 8443 8093; do [ -n "$(ss -ltnH "sport = :$pt" 2>/dev/null)" ] && p "escucha el puerto $pt" || a "nada escucha en $pt"; done
  ss -ltnH "sport = :8443" 2>/dev/null | awk '{print $4}' | grep -qE '^(0\.0\.0\.0|\*|\[::\]):8443$' && f "8443 escucha en todas las interfaces (debe ser la IP interna)" || p "8443 no escucha en 0.0.0.0"
  curl -sk -m 8 https://127.0.0.1:8093/api/ping 2>/dev/null | grep -q '"pong":true' && p "el administrador responde por HTTPS en 8093 (túnel)" || a "8093 no responde por 127.0.0.1 (¿solo expuesto por 8443?)"
fi
if [ "${CERVESUR_SIN_APACHE:-0}" = 1 ]; then a "comprobación de Apache omitida"; else
  apache2ctl configtest >/dev/null 2>&1 && p "apache2ctl configtest correcto" || f "apache2ctl configtest falla"
  salida=$(apache2ctl -M 2>/dev/null) || true; grep -q ' headers_module ' <<<"$salida" && p "mod_headers cargado" || f "mod_headers no cargado"
fi

echo "== 4. Cuentas MySQL del administrador: solo SELECT/USAGE (nada que pueda escribir)"
if [ "${CERVESUR_SIN_MYSQL:-0}" = 1 ]; then a "comprobación de MySQL omitida"; else

  for cta in cervesur_ro_catalogo_adm cervesur_ro_auth_adm; do
    g=$(mysql -N -e "SHOW GRANTS FOR '$cta'@'localhost'" 2>/dev/null) || g=$(mysql -N -e "SHOW GRANTS FOR '$cta'@'127.0.0.1'" 2>/dev/null) || g=''
    if [ -z "$g" ]; then a "no se pudieron leer los privilegios de $cta (¿otro host o sin acceso?)"; continue; fi
    if grep -qiE 'GRANT .*(INSERT|UPDATE|DELETE|DROP|ALTER|CREATE|ALL PRIVILEGES|GRANT OPTION|FILE|SUPER|PROCESS)' <<<"$g"; then f "$cta tiene privilegios de escritura/administración"; else p "$cta: solo lectura"; fi
  done
  g=$(mysql -N -e "SELECT COUNT(*) FROM mysql.user WHERE user LIKE 'cervesur_qa_%'" 2>/dev/null) && echo "       cuentas de la QA 8094 presentes: $g (no se tocan)"
fi

echo "== 5. Respaldo y espacio"
[ -d "$RESPALDO" ] && a "ya existe $RESPALDO (los respaldos previos se conservan)" || a "no existe $RESPALDO (actualizar.sh lo crea con permisos 700)"
libre=$(df --output=avail -k "$FRONT" 2>/dev/null | tail -1 | tr -d ' '); [ "${libre:-0}" -ge 512000 ] && p "espacio libre suficiente (>= 500 MB)" || f "espacio libre insuficiente en $FRONT"

echo; echo "RESUMEN: $pass PASS · $fail FAIL · $aviso AVISO"
if [ "$fail" -eq 0 ]; then echo "RESULTADO: GO para pasar a la simulación de la actualización (actualizar.sh --simular). No autoriza la actualización."; exit 0; fi
echo "RESULTADO: NO-GO: resuelva los FAIL antes de cualquier intervención."; exit 1
