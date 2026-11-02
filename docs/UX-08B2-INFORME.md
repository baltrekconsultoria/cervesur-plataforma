# UX-08B.2 · Informe: Accesos, botones administrativos, auditoría y compatibilidad

Todo verificado en Docker/MySQL sintético. **Nada se aplicó a producción ni a las bases piloto; no hay push, merge ni deploy.** Documentos relacionados: `UX-08-COMPATIBILIDAD-FLUTTER.md`, `UX-08C-COMPATIBILIDAD-DESPLEGADA.md`, `UX-08H-USUARIOS-DISENO.md`, `UX-08B2-PLAN-DESPLIEGUE.md`.

## 1. Terminado y probado en local

| Funcionalidad | Dónde |
|---|---|
| Accesos: crear, revocar, reactivar y editar las tres relaciones, transaccionales y auditadas | `backend/models/{EmpresaUsuario,Asignacion,EmpresaReporteUsuario}Model.php` |
| Reactivar en vez de duplicar (misma fila); `UNIQUE`/`FK` respetados; sin `DELETE` físico | ídem + `write_support.php` (`relation_*`) |
| Cadena de integridad al conceder: usuario activo, empresa activa, informe activo, relaciones intermedias activas | ídem |
| Historial de operaciones (solo administradores) | `GET /api/auditoria` |
| Empresas y Reportes: desactivar y reactivar con confirmación; conflictos 409 con recarga | `frontend/src/features/admin/` |
| Accesos: «Reactivar» un acceso revocado y «Ver historial» | `AdminPages.tsx`, `HistoryPage.tsx` |
| Contrato 2 (`X-API-Contract: 2`) en todas las escrituras de React | `httpApi.ts` |
| Bitácora de solo inserción (triggers) | `database/migrations/UX-08B-audit-log-immutable.*.sql` |
| Matriz de sesiones y tokens | `tests/isolated/http_session_matrix_test.php` |

### Endpoints (todos solo administrador: 401 anónimo, 403 trabajador; el rol se lee de MySQL)

| Método | Ruta | Cuerpo | Éxito |
|---|---|---|---|
| POST | `/api/empresa-usuario` | `user_id`, `empresa_id`, `notas?` | `InsertedId`, `correlation_id` |
| PUT / DELETE | `/api/empresa-usuario/{id}` | `user_id`, `empresa_id`, `notas` / — | `correlation_id` |
| POST | `/api/empresa-usuario/{id}/reactivate` | — | `correlation_id` |
| POST | `/api/asignacion` | `empresa_id`, `reporte_id`, `notas?` | HTTP 201, `data.id` |
| PUT / DELETE | `/api/asignacion/{id}` | idem / — | `correlation_id` |
| POST | `/api/asignacion/{id}/reactivate` | — | `correlation_id` |
| POST | `/api/empresa-reporte-usuario` | `user_id`, `empresa_reporte_id`, `notas?` | `InsertedId` |
| PUT / DELETE | `/api/empresa-reporte-usuario/{id}` | idem / — | `correlation_id` |
| POST | `/api/empresa-reporte-usuario/{id}/reactivate` | — | `correlation_id` |
| POST | `/api/empresas/{id}/reactivate`, `/api/reportes/{id}/reactivate` | — | `correlation_id` |
| GET | `/api/auditoria?entity=&entity_id=&correlation_id=&page=&per_page=` | — | `data[]`, `pagination`, `audit_available` |

`entity`: `empresa`, `reporte`, `empresa_usuario`, `empresa_reporte`, `user_empresa_reporte` o `access` (las tres relaciones). La respuesta no incluye IP ni secretos. Si `audit_log` no existe, devuelve `audit_available:false` con lista vacía.

### Códigos de error

| Fallo | Contrato heredado | Contrato 2 |
|---|---|---|
| Validación | 200 + `success:false` | 400 |
| Duplicado | 200 + `success:false` | 409 |
| No encontrado | 200 + `success:false` | 404 |
| Interno (incluido «auditoría no disponible») | 200 + `success:false` | 500 |
| Dependencia inactiva / ya está en ese estado / versión obsoleta | 409 | 409 |

`/api/asignacion` conserva su particularidad heredada (fallos HTTP 400, alta HTTP 201). Los mensajes nunca contienen SQL (probado; además una guarda estática impide `getMessage()` en respuestas).

### Defecto real encontrado y corregido
`EmpresaUsuarioController` validaba ids con `FILTER_VALIDATE_INT`, que convierte el JSON `true` en `1`: `{"user_id": true, "empresa_id": N}` concedía la empresa al usuario 1 (el administrador). Ahora usa `positive_int()`, que rechaza booleanos y arreglos; queda fijado por pruebas.

## 2. Auditoría

`audit_log` registra actor, acción, entidad, id, fecha (ms), antes/después de una lista blanca, correlación y IP interna (no se devuelve). Se escribe **en la misma transacción** que el cambio y, con `AUDIT_LOG_MODE=required`, la falta de la tabla o un fallo al insertar revierte la operación (probado con un trigger que bloquea la tabla). Nunca contiene contraseñas, tokens, valores `powerbi_*` ni enlaces. No duplica usuarios, empresas ni asignaciones.

### Estrategia de auditoría resistente (a la propia cuenta de la aplicación)

| Capa | Qué hace | Estado |
|---|---|---|
| **1. Privilegios por tabla** | La cuenta de la aplicación recibe `SELECT` e `INSERT` sobre `audit_log` y **nada más** (sin `UPDATE`, `DELETE`, `DROP`, `TRUNCATE`, `ALTER`, `TRIGGER`). Es posible con la misma cuenta porque los privilegios de MySQL son por tabla | Propuesta (requiere decidir las cuentas: bloqueada por `SHOW GRANTS`) |
| **2. Triggers de solo inserción** | `UPDATE` y `DELETE` sobre `audit_log` fallan para cualquier cuenta. No impiden `DROP`/`TRUNCATE`: por eso depende de la capa 1 | **Implementado y probado** (`audit_immutability_test.php`) |
| **3. Cadena de huellas** (opcional) | Cada fila guarda `sha256(fila anterior + contenido)`; una verificación periódica detecta cualquier alteración o hueco. Exige serializar la inserción (coste de concurrencia) | Diseño; no implementado |
| **4. Copia externa** | Exportación periódica a un destino de solo anexado fuera del alcance de la aplicación | Operativa; a decidir |

Conclusión: capas 1+2 dan inmutabilidad frente a un error o una inyección en la aplicación; la 3 y la 4 protegerían además frente a un administrador de la base.

## 3. Compatibilidad Flutter/React (resumen; detalle en el documento propio)

Con la evidencia de PuTTY: el original **no revoca tokens sin `session_version`** (R1), **toma el rol del token** (R3), y **no excluye inactivos en la condición de login observada** (R2, efecto pendiente); sí rechaza `is_active != 1` en cada petición. Por tanto desactivar un usuario (`is_active = 0` + `session_version + 1`) es efectivo para el API en ambos backends, mientras que un cambio de rol no lo es en Flutter hasta que caduque el token. Un logout en una app invalida los tokens con versión de la otra si comparten tabla `user` (R4).

**Los backends React ya son más estrictos y lo prueba la matriz local:** token sin `session_version` → 401; versión antigua → 401; rol cambiado en MySQL → efecto inmediato; rol inesperado → 401; firma o rol manipulados, `alg:none`, algoritmo distinto (HS512), token expirado o sin `exp`, `session_version` como texto → 401.

## 4. Autorización de DB031

El control desplegado en `/opt/cervesur-react-admin` usa archivos que no existen en este repositorio (`piloto_catalogo.php`, `admin_catalogo.php`, `pilot_sesion.php`, `AdminRouter.php`, `PilotoRouter.php`). `ADMIN_VIEWER_ALLOWED_REPORT_IDS` **no lo sustituye**: primero hay que leer el mecanismo (comandos con enlaces enmascarados en `UX-08C-COMPATIBILIDAD-DESPLEGADA.md` §2) y aplicar la opción A–E de su árbol de decisión. La intención de seguridad (solo el 72, ningún comodín, inactivos denegados, sin enlace en el 403, trabajadores intactos) ya está cubierta por pruebas locales.

## 5. Estado de Usuarios

Solo diseño (`UX-08H-USUARIOS-DISENO.md`): edición, desactivar/reactivar, rol, último administrador, interruptor `USER_ADMIN_WRITES` (apagado por defecto). **No se implementó** el backend de usuarios: depende de la inspección P1, P2, P5 y P6 del servidor, y por eso se priorizó terminar y verificar Accesos.

## 6. Reportes: crear

La creación existe y funciona. Para un enlace «Publicar en la web» (sin `groupId`), el controlador **rechaza el alta** porque `reporte.grupo_id` es `NOT NULL` en producción y no se inventa un valor; el mensaje lo explica. La salida es una decisión de negocio (aceptar enlaces con grupo, o aplicar la migración UX-07C). Se documenta como bloqueo y no impide las demás operaciones.

## 7. Resultados de pruebas

| Suite | Resultado | Frente a la línea base |
|---|---|---|
| `php -l` backend y tests | OK | igual |
| `security_unit.php`, Python `unittest` | OK, 22 | igual |
| HTTP aislado previo (`http_test`, paginación, auth, admin, empresa-informe, visor, filtro de estado, visor admin, escrituras) | 50 / 228 / 45 / 36 / 67 / 56 / 49 / 15 / 76 PASS, 0 FAIL | idénticos |
| **Nueva** `http_access_writes_test.php` | 181 PASS | +181 |
| **Nueva** `http_session_matrix_test.php` | 69 PASS | +69 |
| **Nueva** `audit_immutability_test.php` | 8 PASS | +8 |
| `tsc`, `eslint`, `vitest` | OK · 243 tests | +14 |
| E2E simulado | 89 PASS / 0 FAIL | igual |
| E2E contra PHP real (modo normal y estricto) | 191 PASS / 0 FAIL en ambos | +5 (baja/reactivación reales con contrato 2, historial sin bitácora) |
| Contrato contra PHP real, modo normal | 206 PASS / 0 FAIL / 4 hallazgos | igual (hallazgos previos) |
| Contrato modo estricto | 205 PASS / 3 FAIL / 2 hallazgos | las 3 son «sin `view` conserva campos completos para clientes anteriores»: retiradas a propósito |
| `build:production` (hosts de prueba), `verify:production`, `scan:secrets` | OK | igual |
| `git diff --check` | limpio | — |

**Fallos nuevos durante el desarrollo, todos corregidos:** una cadena PHP rota por un apóstrofo en una prueba mía (el lint lo detectó); una guarda estática que impide `getMessage()` en respuestas (obligó a las excepciones tipadas a usar `clientMessage`); dos guardas del frontend (lista de endpoints y menciones a campos `powerbi_*`), ampliadas de forma explícita. Ningún fallo previo reapareció.

## 8. Riesgos y bloqueos pendientes

| Riesgo / bloqueo | Origen |
|---|---|
| Auditoría del backend original incompleta (P1, P2, P5, P6, P7) | Bloquea Usuarios |
| Mecanismo desplegado de aprobación del piloto administrativo sin leer | Bloquea DB031 real |
| `SHOW GRANTS` de las cuentas efectivas; las conocidas tienen `DELETE` a nivel de base y las FK usan `ON DELETE CASCADE` | Bloquea cualquier escritura productiva |
| Alta de informes con «Publicar en la web» sin grupo | Decisión de negocio |
| R1/R3/R4 son propios del backend original; mitigaciones requieren cambiar Flutter | Fuera de alcance |
| Bases piloto sin `UNIQUE` (si se crearon con el fixture) no pueden impedir una carrera de altas iguales | Verificar G6 |
| Sin migración aplicada la auditoría está en `off` por defecto: las operaciones funcionan pero no quedan registradas | Orden de despliegue en `UX-08B2-PLAN-DESPLIEGUE.md` |
