# SEC-03H: CI del backend

El workflow `.github/workflows/backend-ci.yml` se ejecuta en pull requests con destino a `main` y en cambios subidos a ramas `ci/**` o `security/**`.

## Cobertura

El flujo construye una imagen local de PHP 8.3 con `pdo_mysql` y ejecuta:

1. Sintaxis de todos los archivos PHP de `backend/` y `tests/`.
2. `tests/security_unit.php` sin red.
3. `tests/test_security_static.py`.
4. `tests/isolated/http_test.php` contra MySQL 8.0, usuarios y reportes sintéticos.

La prueba HTTP crea una red Docker interna sin puertos publicados. El proveedor Power BI se simula mediante `tests/isolated/mock_https.php`. Las contraseñas de la base sintética y el secreto JWT se generan por ejecución, se ocultan en el registro y se eliminan junto con los contenedores temporales.

## Ejecución local

Requisitos: Docker operativo, Python 3 y una terminal Bash compatible (Git Bash o WSL en Windows).

```bash
set -euo pipefail
docker build --tag cervesur-ci-php:local --file tests/isolated/Dockerfile .
docker run --rm --network none -v "$PWD:/work:ro" -w /work cervesur-ci-php:local sh -lc '
  find backend tests -type f -name "*.php" -print0 | sort -z | xargs -0 -n1 php -l
'
docker run --rm --network none -v "$PWD:/work:ro" -w /work cervesur-ci-php:local php tests/security_unit.php
python -m unittest discover -s tests -v
```

Para la prueba HTTP, use la misma secuencia del paso `Run HTTP tests with synthetic MySQL` del workflow. No sustituya sus variables sintéticas por valores de producción ni publique puertos de los contenedores.

## Diagnóstico de fallos

- **Sintaxis:** el archivo indicado por `php -l` debe corregirse antes de integrar.
- **Prueba unitaria o estática:** revise la aserción fallida y mantenga las restricciones de autorización y sanitización.
- **HTTP:** conserve la red interna, inspeccione únicamente los registros de los contenedores sintéticos y elimínelos al terminar. No use una base de datos real para reproducir el fallo.

El workflow falla inmediatamente ante un comando no satisfactorio (`set -euo pipefail`) y siempre limpia sus contenedores y red temporal mediante `trap`.
