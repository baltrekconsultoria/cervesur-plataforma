# UX-08A · Cierre de los 4 hallazgos del contrato

`npm run test:contract` (frontend/qa/contract.mjs) termina con **206 PASS / 0 FAIL / 4 HALLAZGOS**. Un hallazgo no falla la suite: documenta un riesgo conocido del contrato.

## Verificación de origen

El mismo contrato se ejecutó contra el backend de `origin/main` (`703e92d`, sin ningún cambio de UX-08) y produjo **los mismos 4 hallazgos y 206 PASS / 0 FAIL**. Ninguno lo introduce UX-08A. Los cuatro están catalogados en `docs/BACKLOG-SEGURIDAD-UX-06B0.md`.

## Matriz

| ID | Endpoint | Esperado | Observado | Causa | Riesgo de regresión al corregir | Corrección o justificación | Prueba de verificación |
|---|---|---|---|---|---|---|---|
| H1 · B-08 | `POST /api/login` | HTTP 401 ante credenciales incorrectas | HTTP 200 `{"success":false,"message":"Credenciales inválidas"}` | Contrato heredado: el cliente distingue éxito por `success`, no por el código HTTP | **Alto con Flutter**: no hay evidencia de que Flutter trate un 401 como «credenciales inválidas»; podría mostrar un error genérico o cerrar sesión | **Sin cambio.** React ya maneja ambos (`httpApi.login` lanza `ApiError(401)` si `success !== true`). Normalizar solo por versión del contrato y tras verificar Flutter (Tarea C) | `contract.mjs` («Sesión · credenciales incorrectas…»); `http_auth_hardening_test.php` fija el mensaje uniforme |
| H2 · B-10 | `GET /api/reportes` (sin `view`) | Sin `enlace` ni `created_by*` para usuarios no administradores | Devuelve `enlace` (URL de Power BI), `created_by`, `created_by_username` | El valor por defecto es la respuesta completa que consume Flutter; `view=minimal` es opt-in | **Alto con Flutter**: el bundle histórico usa `enlace` (B-06). Quitarlo rompe el visor de Flutter | **Mitigado, no cerrado.** React siempre pide `view=minimal` (sin `enlace`); el adaptador además lo descarta. Cerrar exige que Flutter pase a la vista mínima o que se versione el contrato | `contract.mjs` (hallazgo); `http_pagination_test.php` (`view=minimal` sin `enlace`); `http_status_filter_test.php` usa `view=minimal` |
| H3 · B-10 | `GET /api/empresas` | Sin `powerbi_tenant`/`powerbi_client` para no administradores | Se envían ambos (el secreto `powerbi_clientsecret` **sí** se excluye: `EmpresaModel::withoutSecrets`) | Mismo motivo que H2 | Medio-alto con Flutter (usa `powerbi_tenant/client`) | **Mitigado, no cerrado.** `view=minimal` los omite. El secreto nunca sale. Cerrar junto con H2 | `contract.mjs`; `security_unit.php` (secreto excluido) |
| H4 · B-11 | `GET /api/reportes` | Empresa y nombre de grupo por informe | Solo `grupo_id` (UUID); sin empresa | No existe tabla ni campo con el nombre de grupo; la empresa está en `empresa_reporte` | Bajo (es una carencia, no un riesgo) | **Resuelto en el cliente para la empresa** (`composeReportCatalog` une `/api/reportes` + `/api/asignacion` + `/api/empresas`). El **nombre de grupo no existe** en la base: limitación funcional, no se inventa | `httpApi.test.ts`, `httpStatusScope.test.ts`; e2e `run-qa.mjs` («reportes… sin Grupo (no existe)») |

## Estado

- Los 4 quedan **clasificados**; ninguno es regresión ni bloquea UX-08A.
- H2 y H3 son la única exposición real: un usuario autenticado **no administrador** que llame directamente al API sin `view=minimal` recibe `enlace`. Solo se cierra con una decisión sobre Flutter (ver `docs/UX-08-COMPATIBILIDAD-FLUTTER.md`).
- H1 y H4 no tienen riesgo de seguridad.

## UX-08A.1 · paginación

| Lista | Pantalla | Por defecto | Páginas |
|---|---|---|---|
| Usuarios, Empresas, Reportes, Accesos | `AdminPages.tsx` | Activos / Activas / Activos / Vigentes | 25 filas |

Paginación local sobre el conjunto ya cargado (el cliente ya descarga todas las páginas del servidor: `httpApi.listAll`), de modo que ningún registro desaparece por un tope. Hook `frontend/src/lib/usePaging.ts`: vuelve a la página 1 al cambiar búsqueda o filtros y ajusta la página si la lista se acorta. Pruebas: `frontend/src/app/adminPaging.test.tsx` (60 registros por lista, con búsqueda, estado y cambio de página).
