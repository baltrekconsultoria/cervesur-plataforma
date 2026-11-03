#!/usr/bin/env bash
# Prueba LOCAL de preflight-produccion.sh con un «servidor» simulado en una carpeta temporal. No toca ningún servidor ni MySQL ni Apache.
#   bash deploy/produccion-admin/probar-preflight.sh
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
pass=0; fail=0
chk() { local d=$1; shift; if "$@"; then echo "PASS  $d"; pass=$((pass + 1)); else echo "FAIL  $d"; fail=$((fail + 1)); fi; }

montar() { # $1 = contenido del .env
  rm -rf "$T/srv" "$T/paq"; mkdir -p "$T/srv/back/backend" "$T/srv/front/public" "$T/paq/public" "$T/paq/backend"
  printf '%s\n' "$1" > "$T/srv/back/backend/.env"
  echo '{"pilotMode": "admin"}' > "$T/paq/public/build-info.json"; echo '<?php' > "$T/paq/backend/a.php"; echo 'commit: prueba' > "$T/paq/VERSION"
  (cd "$T/paq" && find . -type f ! -name SHA256SUMS -print0 | xargs -0 sha256sum > SHA256SUMS)
}
correr() { CERVESUR_ADMIN_BACK="$T/srv/back" CERVESUR_ADMIN_FRONT="$T/srv/front" CERVESUR_ADMIN_RESPALDO="$T/srv/resp" CERVESUR_SIN_APACHE=1 CERVESUR_SIN_MYSQL=1 CERVESUR_SIN_RED=1 bash "$HERE/preflight-produccion.sh" "$@" 2>&1; }
BUENO=$'CERVESUR_MODE=piloto_admin\nCERVESUR_REQUIRED_MODE=piloto_admin\nDB_NAME=cervesur_portalbi\nDB_USER=cervesur_ro_catalogo_adm\nDB_PASS=CLAVE-SECRETA-DE-MENTIRA-123\nSTATE_DB_NAME=cervesur_react_estado_admin\nJWT_SECRET=OTRO-SECRETO-DE-MENTIRA-456'

montar "$BUENO"; out=$(correr --paquete "$T/paq"); rc=$?
chk "estado correcto: GO (rc 0) y el resultado no autoriza la actualización" bash -c '[ "$1" -eq 0 ] && grep -q "RESULTADO: GO" <<<"$2" && grep -q "No autoriza la actualización" <<<"$2"' _ "$rc" "$out"
chk "no imprime ningún valor del .env" bash -c '! grep -qE "SECRETA-DE-MENTIRA|SECRETO-DE-MENTIRA" <<<"$1"' _ "$out"

declare -A MALOS=(
  ["USER_ADMIN_WRITES=1 en el administrador publicado"]="$BUENO"$'\nUSER_ADMIN_WRITES=1'
  ["modo distinto de piloto_admin"]="${BUENO//CERVESUR_MODE=piloto_admin/CERVESUR_MODE=read_only}"
  ["usa la cuenta de Flutter"]="${BUENO//cervesur_ro_catalogo_adm/cervesur_portalbi_admin}"
  ["cuenta que no es cervesur_ro_*"]="${BUENO//cervesur_ro_catalogo_adm/otra_cuenta}"
  ["CERVESUR_INSECURE_TESTS definido"]="$BUENO"$'\nCERVESUR_INSECURE_TESTS=SI'
  ["base de estado igual a la de lectura"]="${BUENO//cervesur_react_estado_admin/cervesur_portalbi}"
  ["cuenta con escritura permitida"]="$BUENO"$'\nCERVESUR_ALLOW_WRITABLE_DB_ACCOUNT=SI'
)
for caso in "${!MALOS[@]}"; do
  montar "${MALOS[$caso]}"; out=$(correr --paquete "$T/paq"); rc=$?
  chk "NO-GO: $caso" bash -c '[ "$1" -ne 0 ] && grep -q "RESULTADO: NO-GO" <<<"$2" && grep -q "^FAIL" <<<"$2"' _ "$rc" "$out"
done

montar "$BUENO"; echo 'X=1' > "$T/paq/.env"; (cd "$T/paq" && find . -type f ! -name SHA256SUMS -print0 | xargs -0 sha256sum > SHA256SUMS); out=$(correr --paquete "$T/paq"); rc=$?
chk "NO-GO: el paquete contiene un .env" bash -c '[ "$1" -ne 0 ] && grep -q "contiene .env" <<<"$2"' _ "$rc" "$out"
montar "$BUENO"; echo 'cuenta syn001' >> "$T/paq/backend/a.php"; (cd "$T/paq" && find . -type f ! -name SHA256SUMS -print0 | xargs -0 sha256sum > SHA256SUMS); out=$(correr --paquete "$T/paq"); rc=$?
chk "NO-GO: el paquete contiene cuentas sintéticas syn###" bash -c '[ "$1" -ne 0 ] && grep -q "syn###" <<<"$2"' _ "$rc" "$out"
montar "$BUENO"; mkdir -p "$T/paq/database/migrations"; echo 'ALTER' > "$T/paq/database/migrations/x.sql"; (cd "$T/paq" && find . -type f ! -name SHA256SUMS -print0 | xargs -0 sha256sum > SHA256SUMS); out=$(correr --paquete "$T/paq"); rc=$?
chk "NO-GO: el paquete incluye migraciones (la versión 1 no cambia la base)" bash -c '[ "$1" -ne 0 ] && grep -q "incluye migraciones" <<<"$2"' _ "$rc" "$out"
montar "$BUENO"; echo 'alterado' >> "$T/paq/backend/a.php"; out=$(correr --paquete "$T/paq"); rc=$?
chk "NO-GO: paquete alterado (SHA256SUMS no coincide)" bash -c '[ "$1" -ne 0 ] && grep -q "integridad del paquete NO verificada" <<<"$2"' _ "$rc" "$out"
chk "el preflight no creó ni modificó nada en el servidor simulado" bash -c '[ ! -e "$1/srv/resp" ]' _ "$T"
echo; echo "$pass PASS / $fail FAIL"; [ "$fail" -eq 0 ]
