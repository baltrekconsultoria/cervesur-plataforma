#!/usr/bin/env bash
# Ejecuta la prueba de reactivación con reloj simulado: MySQL 8.0 y PHP en contenedores desechables, sin red hacia fuera, datos sintéticos.
# Uso (desde la raíz del repositorio):  bash tests/isolated/probar-reactivacion-reloj.sh
set -u
export MSYS_NO_PATHCONV=1
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
ruta() { if command -v cygpath >/dev/null 2>&1; then cygpath -w "$1"; else echo "$1"; fi; }
NET=reloj-red-prueba; DB=reloj-mysql-prueba
cleanup() { docker rm -f "$DB" >/dev/null 2>&1; docker network rm "$NET" >/dev/null 2>&1; }
trap cleanup EXIT; cleanup
docker network create --internal "$NET" >/dev/null
docker run -d --name "$DB" --network "$NET" --network-alias db -e MYSQL_ROOT_PASSWORD=ficticia-prueba mysql:8.0 >/dev/null
for i in $(seq 1 90); do [ "$(docker logs "$DB" 2>&1 | grep -c 'ready for connections')" -ge 2 ] && docker exec "$DB" mysqladmin ping -uroot -pficticia-prueba --silent >/dev/null 2>&1 && break; sleep 2; done
docker run --rm --network "$NET" -e TEST_DB_PASS=ficticia-prueba -e REPO=/w -v "$(ruta "$REPO"):/w:ro" cervesur-ci-php:local php /w/tests/isolated/reactivacion_reloj_test.php
