#!/usr/bin/env bash
# Cervesur · UX-08H.1 · PREFLIGHT de solo lectura de la FASE 2A (go/no-go ANTES de tocar nada). NO modifica nada.
#   sudo bash scripts/preflight-fase2a.sh [--paquete CARPETA]
# Responde: ¿lo instalado es exactamente la fase 1 (3f859210c5c4)? ¿hay espacio, respaldo posible, puertos y Apache como se espera? ¿el paquete candidato es íntegro y
# sintácticamente correcto? ¿responden Flutter (80), trabajadores (443) y la QA (8094)? ¿el .env está en piloto_admin y SIN ADMIN_WRITES_USUARIOS* ni USER_ADMIN_WRITES?
# No imprime .env, claves, hashes de usuarios ni enlaces: solo nombres de clave, estados, recuentos y PASS / FAIL / AVISO. Código 0 = GO; 1 = NO-GO.
# AVISO no bloquea: indica algo que el operador debe conocer (p. ej. que faltan las migraciones, que se harán antes de A2, o que Apache rechaza PUT/DELETE: ver README, «Apache»).
set -uo pipefail
case ${1:-} in --ayuda|-h|--help) sed -n '2,/^set -/{/^set -/!s/^# \{0,1\}//p}' "${BASH_SOURCE[0]}"; exit 0 ;; esac
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
while [ $# -gt 0 ]; do case $1 in --paquete) PAQ=${2:-}; shift 2 ;; *) echo "Opción desconocida: $1 (use --ayuda)"; exit 2 ;; esac; done
# shellcheck source=lib.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib.sh"
requerir_root
pass=0; fail=0; avisos=0
p() { printf 'PASS   %s\n' "$*"; pass=$((pass + 1)); }
f() { printf 'FAIL   %s\n' "$*"; fail=$((fail + 1)); }
a() { printf 'AVISO  %s\n' "$*"; avisos=$((avisos + 1)); }
manifiesto="$PAQ/base/MANIFIESTO-FASE1-$BASE_FASE1_COMMIT.sha256"

echo "################ PREFLIGHT FASE 2A (solo lectura) ################"
echo "== 1. Identidad de lo instalado (debe ser la fase 1, $BASE_FASE1_COMMIT, o ya el paquete candidato)"
[ -d "$DIR_PUBLIC" ] && [ -d "$DIR_BACKEND" ] && [ -f "$ENV_FILE" ] && p "instalado: $DIR_PUBLIC, $DIR_BACKEND y .env" || f "falta $DIR_PUBLIC, $DIR_BACKEND o el .env"
identidad() { # manifiesto → 0 si TODO backend/ y public/ listados coinciden y no sobra ni falta ningún archivo (el .env se ignora)
  local m=$1 lb lp nb np
  lb=$(grep -E '^[0-9a-f]{64} [ *](./)?backend/' "$m") && lp=$(grep -E '^[0-9a-f]{64} [ *](./)?public/' "$m") || return 1
  (cd "$DIR_BACK" && sha256sum -c --quiet <<<"$lb") >/dev/null 2>&1 && (cd "$DIR_FRONT" && sha256sum -c --quiet <<<"$lp") >/dev/null 2>&1 || return 1
  nb=$(find "$DIR_BACKEND" -type f ! -path "$ENV_FILE" | wc -l); np=$(find "$DIR_PUBLIC" -type f | wc -l)
  [ "$nb" = "$(wc -l <<<"$lb")" ] && [ "$np" = "$(wc -l <<<"$lp")" ]
}
if [ -f "$manifiesto" ] && identidad "$manifiesto"; then p "lo instalado coincide archivo por archivo con la fase 1 ($BASE_FASE1_COMMIT)"
elif [ -f "$PAQ/SHA256SUMS" ] && identidad "$PAQ/SHA256SUMS"; then a "lo instalado YA es el paquete candidato: actualizar.sh no tiene nada que reemplazar"
else f "lo instalado NO coincide con la fase 1 ($BASE_FASE1_COMMIT) ni con el candidato: hay deriva o cambios locales; no se actualiza hasta clasificarlos"; fi
[ -f "$manifiesto" ] && p "el paquete trae el manifiesto de referencia de la fase 1" || f "falta $manifiesto"

echo "== 2. .env (solo nombres de clave y estados; nunca valores)"
[ -r "$ENV_FILE" ] && p "se puede leer el .env" || f "no se puede leer $ENV_FILE"
[ "$(env_valor_actual CERVESUR_MODE)" = piloto_admin ] && [ "$(env_valor_actual CERVESUR_REQUIRED_MODE)" = piloto_admin ] && p "modo piloto_admin exigido (el A1 NO lo cambia)" || a "el .env no está en piloto_admin ($(modo_env | cut -c1-20)): solo es normal si A2 ya se hizo"
prohibidas=$(env_claves_prohibidas | paste -sd, -)
[ -z "$prohibidas" ] && p "sin ADMIN_WRITES_USUARIOS*, USER_ADMIN_WRITES, CERVESUR_INSECURE_TESTS ni CERVESUR_ALLOW_WRITABLE_DB_ACCOUNT" || f "claves prohibidas en el .env: $prohibidas (la fase 2B sigue bloqueada)"
for m in EMPRESAS REPORTES ACCESOS; do [ "$(env_valor_actual "ADMIN_WRITES_$m")" != 1 ] && p "ADMIN_WRITES_$m no está encendido" || f "ADMIN_WRITES_$m=1 antes de tiempo"; done
du_=$(env_valor_actual DB_USER); case "$du_" in cervesur_ro_*) p "DB_USER es una cuenta de solo lectura ($du_)" ;; *) a "DB_USER no es cervesur_ro_* ($du_): esperado solo si A2 ya se hizo" ;; esac
[ "$(env_valor_actual DB_NAME)" = cervesur_portalbi ] && p "base de negocio cervesur_portalbi" || a "DB_NAME no es cervesur_portalbi"

echo "== 3. Paquete candidato"
[ -f "$PAQ/SHA256SUMS" ] && (cd "$PAQ" && sha256sum -c SHA256SUMS --quiet) >/dev/null 2>&1 && p "integridad del paquete (SHA256SUMS)" || f "integridad del paquete NO verificada"
[ -d "$PAQ/public" ] && [ -d "$PAQ/backend" ] && p "el paquete trae public/ y backend/" || f "paquete incompleto"
grep -q '"pilotMode": *"admin-write"' "$PAQ/public/build-info.json" 2>/dev/null && p "la aplicación del paquete es la compilación admin-write (no depende de nada hasta que el backend ofrezca /api/admin/capacidades)" || f "la aplicación del paquete no es admin-write"
if [ -n "$(find "$PAQ" \( -name '.env*' -o -name '*.key' -o -name '*.pem' -o -name '*.crt' \) -print -quit)" ]; then f "el paquete contiene .env, claves o certificados"; else p "el paquete no contiene .env, claves ni certificados"; fi
if [ -n "$(grep -rIlE '\bsyn[0-9]{3}\b' "$PAQ/backend" "$PAQ/public" 2>/dev/null)" ]; then f "el paquete contiene cuentas sintéticas syn###"; else p "el paquete no contiene cuentas sintéticas de QA (syn###)"; fi
if command -v php >/dev/null 2>&1; then
  malos=0; while IFS= read -r archivo; do php -l "$archivo" >/dev/null 2>&1 || { f "sintaxis PHP incorrecta: ${archivo#"$PAQ"/}"; malos=1; }; done < <(find "$PAQ/backend" -name '*.php' -not -path '*/vendor/*' | LC_ALL=C sort)
  [ "$malos" = 0 ] && p "php -l correcto en todo el backend del candidato"
else a "php (CLI) no disponible: no se pudo comprobar la sintaxis del candidato (los pasos A2 lo necesitan para verificar la cuenta)"; fi
[ -d "$PAQ/migraciones" ] && p "el paquete trae migraciones/ (las ejecuta el DBA aparte, no estos scripts)" || a "el paquete no trae migraciones/ (E3 se hace con otro kit)"
[ -d "$PAQ/e0" ] && p "el paquete trae e0/ (auditoría de solo lectura)" || a "el paquete no trae e0/"

echo "== 4. Espacio, respaldo, puertos y Apache (solo lectura)"
usado=$(du -sk "$DIR_PUBLIC" "$DIR_BACKEND" 2>/dev/null | awk '{s += $1} END {print s + 0}')
libre=$(df --output=avail -k "$DIR_FRONT" 2>/dev/null | tail -1 | tr -d ' ')
libre_resp=$(df --output=avail -k "$(dirname "$DIR_RESPALDO")" 2>/dev/null | tail -1 | tr -d ' ')
minimo=$(( usado * 3 > 512000 ? usado * 3 : 512000 ))
[ "${libre:-0}" -ge "$minimo" ] && p "espacio libre suficiente en $DIR_FRONT (${libre} KB >= ${minimo} KB)" || f "espacio insuficiente en $DIR_FRONT (${libre:-0} KB < ${minimo} KB)"
[ "${libre_resp:-0}" -ge "$minimo" ] && p "espacio libre suficiente para los respaldos (${libre_resp} KB)" || f "espacio insuficiente para los respaldos"
if [ -d "$DIR_RESPALDO" ]; then [ -w "$DIR_RESPALDO" ] && p "$DIR_RESPALDO existe y se puede escribir (los respaldos previos no se tocan)" || f "$DIR_RESPALDO no es escribible"
else [ -w "$(dirname "$DIR_RESPALDO")" ] && a "$DIR_RESPALDO no existe; actualizar.sh lo crea con permisos 700" || f "no se puede crear $DIR_RESPALDO"; fi
for pt in 80 443 8093; do [ -n "$(ss -ltnH "sport = :$pt" 2>/dev/null)" ] && p "escucha el puerto $pt" || f "nada escucha en $pt"; done
[ -n "$(ss -ltnH "sport = :8443" 2>/dev/null)" ] && p "escucha el puerto 8443 (administrador publicado)" || a "nada escucha en 8443 (¿la publicación corporativa no está activa?)"
[ -n "$(ss -ltnH "sport = :8094" 2>/dev/null)" ] && p "escucha el puerto 8094 (QA aislada)" || a "nada escucha en 8094"
escuchas=$(ss -ltnH "sport = :$PUERTO" | awk '{print $4}')
if grep -qvE "^127\.0\.0\.1:$PUERTO\$" <<<"$escuchas"; then f "$PUERTO escucha fuera de loopback"; else p "$PUERTO escucha solo en 127.0.0.1"; fi
apache2ctl configtest >/dev/null 2>&1 && p "apache2ctl configtest correcto (este kit no modifica Apache)" || f "apache2ctl configtest falla"
comun="/etc/apache2/cervesur-react-admin/react-usuarios-common.conf"
if [ -f "$comun" ]; then
  echo "       huella de la configuración de Apache del administrador (informativa): $(sha256sum "$comun" | cut -c1-16)"
  if grep -qE '<LimitExcept GET POST>' "$comun"; then a "Apache solo deja pasar GET y POST (<LimitExcept GET POST>): PUT/DELETE reciben 403 antes de llegar a PHP. A1 y A2 funcionan igual, pero el Paso B (empresas, informes y accesos usan PUT/DELETE) NO podrá funcionar hasta un cambio de Apache aparte, con su propia autorización (scripts/apache-metodos-escritura.sh)"
  else p "Apache admite PUT/DELETE en el administrador"; fi
else a "no se encontró $comun"; fi
# Respuesta del propio administrador
respuesta=$(curl -sS -m 10 "${CURL_TLS[@]}" "$URL_ADMIN/api/ping" 2>/dev/null) && grep -q '"pong":true' <<<"$respuesta" && p "el administrador responde por HTTPS en $PUERTO" || f "el administrador no responde por HTTPS en $PUERTO"

echo "== 5. Otros portales (Flutter 80 · trabajadores 443 · QA 8094)"
if salida=$(bash "$(dirname "${BASH_SOURCE[0]}")/comprobar-otros-portales.sh" 2>&1); then p "los tres portales responden"; else f "algún portal no responde"; fi
sed 's/^/       /' <<<"$salida"

echo "== 6. Requisitos de A2 (informativo: A1 no los necesita)"
if mysql_activo; then
  tabla=$(dba -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='cervesur_portalbi' AND TABLE_NAME='audit_log'" 2>/dev/null) || tabla=""
  trig=$(dba -e "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='cervesur_portalbi' AND EVENT_OBJECT_TABLE='audit_log'" 2>/dev/null) || trig=""
  if [ "$tabla" = 1 ] && [ "$trig" = 2 ]; then p "audit_log existe con sus 2 triggers (migraciones M1 y M2 aplicadas)"; else a "audit_log/triggers todavía no están (tabla=${tabla:-?}, triggers=${trig:-?}): necesarios ANTES de A2, no de A1"; fi
  cuenta=$(dba -e "SELECT COUNT(*) FROM mysql.user WHERE user='$CUENTA_ESCRITURA_DEFECTO'" 2>/dev/null) || cuenta=""
  [ "${cuenta:-0}" -ge 1 ] 2>/dev/null && p "existe la cuenta de escritura $CUENTA_ESCRITURA_DEFECTO (se valida en A2)" || a "la cuenta de escritura $CUENTA_ESCRITURA_DEFECTO todavía no existe (necesaria ANTES de A2)"
else a "MySQL no responde al DBA local: no se pudieron comprobar migraciones ni cuenta"; fi
credenciales_presentes && p "CERVESUR_E_USUARIO / CERVESUR_E_CLAVE definidas (verificación con login)" || a "CERVESUR_E_USUARIO / CERVESUR_E_CLAVE sin definir: actualizar.sh real las exige para comprobar el login (o --sin-credenciales, verificación parcial)"

echo; echo "RESUMEN: $pass PASS · $fail FAIL · $avisos AVISO"
if [ "$fail" -eq 0 ]; then echo "RESULTADO: GO para ejecutar «actualizar.sh --simular». No autoriza la actualización."; exit 0; fi
echo "RESULTADO: NO-GO: resuelva los FAIL antes de cualquier intervención."; exit 1
