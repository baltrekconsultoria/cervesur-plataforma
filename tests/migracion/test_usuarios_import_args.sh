#!/bin/bash
# QA sintetico del parser CLI: no DB ni archivos con PII.
set -euo pipefail
SCRIPT="ops/migracion/usuarios_import_controlado.php"

expect_error() {
  local expected="$1"
  shift
  local got status
  set +e
  got="$(php "$SCRIPT" "$@" 2>&1)"
  status=$?
  set -e
  if [ "$status" -ne 2 ] || [ "$got" != "CERVESUR_IMPORT_USUARIOS=ERROR_$expected" ]; then
    echo "CERVESUR_IMPORT_ARGS_QA=FAIL" >&2
    echo "CASO=$expected EXIT=$status" >&2
    exit 1
  fi
}

# El parametro real contiene el numero 256. Debe llegar hasta la
# validacion de archivos, sin conectarse a DB ni efectuar cambios.
expect_error ARCHIVO_PRIVADO \
  --mode=apply \
  --loader=/ruta/sintetica/no-existe \
  --database=cervesur_portalbi \
  --plan=/ruta/sintetica/no-existe \
  --snapshot=/ruta/sintetica/no-existe \
  --backup=/ruta/sintetica/no-existe \
  --backup-sha256=0000000000000000000000000000000000000000000000000000000000000000 \
  --confirm=IMPORTAR_114_USUARIOS_INACTIVOS

# Nombre de parametro duplicado: nunca aceptar.
expect_error ARGUMENTOS \
  --mode=apply \
  --loader=/ruta/sintetica/no-existe \
  --database=cervesur_portalbi \
  --plan=/ruta/sintetica/no-existe \
  --snapshot=/ruta/sintetica/no-existe \
  --backup=/ruta/sintetica/no-existe \
  --backup-sha256=0000000000000000000000000000000000000000000000000000000000000000 \
  --backup-sha256=0000000000000000000000000000000000000000000000000000000000000000 \
  --confirm=IMPORTAR_114_USUARIOS_INACTIVOS

# No permitir confirmaciones y hashes de APPLY en modo check.
expect_error ARGUMENTOS \
  --mode=check \
  --loader=/ruta/sintetica/no-existe \
  --database=cervesur_portalbi \
  --plan=/ruta/sintetica/no-existe \
  --snapshot=/ruta/sintetica/no-existe \
  --backup-sha256=0000000000000000000000000000000000000000000000000000000000000000

# No aceptar flags inesperados aun con digitos permitidos.
expect_error ARGUMENTOS \
  --mode=check \
  --loader=/ruta/sintetica/no-existe \
  --database=cervesur_portalbi \
  --plan=/ruta/sintetica/no-existe \
  --snapshot=/ruta/sintetica/no-existe \
  --version2=abc

echo "CERVESUR_IMPORT_ARGS_QA=PASS"
echo "CASOS=4"
echo "BASE_CONSULTADA=NO"
echo "MYSQL_MODIFICADO=NO"
