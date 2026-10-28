# QA de integración (React ↔ PHP real, aislado)

Orquestador Docker y pruebas de contrato que levantan el backend PHP del **mismo repositorio** con un MySQL sintético desechable
(red interna sin salida a Internet; puertos solo en `127.0.0.1`). Ver `../docs/QA-MANUAL-WINDOWS.md`.

**Requisito**: el backend debe incluir la paginación y las vistas mínimas del PR #4 (`backend/lib/pagination.php`). En una rama que
no las tenga, `node qa/backend.mjs up` se detiene con un mensaje explícito. Estas pruebas **no se ejecutan todavía en GitHub Actions**:
se validan localmente hasta que exista un job de integración. Las pruebas simuladas de `Frontend CI` no son pruebas de integración real.
