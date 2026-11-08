#!/usr/bin/env bash
# UX-09B · D3 sobre la pila real de RC1c: MySQL 8.0 + servidor PHP con el router y el middleware reales, en una red Docker interna.
# Ejecuta la matriz de sesion existente y las pruebas D3 nuevas. Datos sinteticos; sin red hacia fuera; nada de produccion.
# Uso (desde la raiz del repositorio):  bash tests/isolated/probar-d3-rc1c.sh
set -u
export MSYS_NO_PATHCONV=1
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
ruta() { if command -v cygpath >/dev/null 2>&1; then cygpath -w "$1"; else echo "$1"; fi; }
NET=d3-rc1c-red; DB=d3-rc1c-mysql; APP=d3-rc1c-appw
PASS="sintetica-$(date +%s)-d3"; JWT="sintetico-d3-$(date +%s)-0123456789abcdef0123456789abcdef"
cleanup() { docker rm -f "$APP" "$DB" >/dev/null 2>&1; docker network rm "$NET" >/dev/null 2>&1; }
trap cleanup EXIT; cleanup
W="$(ruta "$REPO")"
docker network create --internal "$NET" >/dev/null
docker run -d --name "$DB" --network "$NET" --network-alias db -e MYSQL_ROOT_PASSWORD="$PASS" mysql:8.0 >/dev/null
for i in $(seq 1 90); do [ "$(docker logs "$DB" 2>&1 | grep -c 'ready for connections')" -ge 2 ] && docker exec "$DB" mysqladmin ping -uroot -p"$PASS" --silent >/dev/null 2>&1 && break; sleep 2; done
run() { docker run --rm --network "$NET" -v "$W:/work:ro" -w /work -e TEST_DB_PASS="$PASS" -e JWT_SECRET="$JWT" cervesur-ci-php:local php "$@"; }
run tests/isolated/seed_writes.php >/dev/null || { echo "FALLA: no se pudo preparar el fixture"; exit 2; }
docker run -d --name "$APP" --network "$NET" --network-alias appw --read-only --tmpfs /tmp -v "$W:/work:ro" -w /work \
  -e DB_HOST=db -e DB_NAME=fixture_w -e DB_USER=root -e DB_PASS="$PASS" -e DB_CHARSET=utf8mb4 -e JWT_SECRET="$JWT" -e JWT_EXPIRATION=3600 \
  -e PHP_CLI_SERVER_WORKERS=4 -e AUDIT_LOG_MODE=required -e REACT_STRICT_VIEWS=1 \
  cervesur-ci-php:local php -S 0.0.0.0:8080 tests/isolated/router.php >/dev/null
sleep 2
echo "=== matriz de sesion existente (http_session_matrix_test.php) ==="
run tests/isolated/http_session_matrix_test.php | tail -n 40; RC1=${PIPESTATUS[0]}
echo "=== D3 nuevas ==="
run tests/isolated/d3_rc1c_test.php; RC2=$?
echo "codigos de salida: matriz=$RC1 d3=$RC2"
[ "$RC1" = 0 ] && [ "$RC2" = 0 ]
