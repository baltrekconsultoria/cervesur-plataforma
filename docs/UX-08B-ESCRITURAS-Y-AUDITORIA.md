# UX-08B · Capa transaccional, auditoría y vistas estrictas (implementado en local)

Alcance: **Empresas y Reportes**. Verificado solo en Docker/MySQL sintético. **Nada se ha aplicado a producción ni a las bases piloto.** Los accesos (usuario↔empresa↔informe) y los usuarios quedan para el siguiente incremento.

## 1. Qué se implementó

| Pieza | Dónde | Comportamiento |
|---|---|---|
| Transacciones | `backend/lib/write_support.php` (`db_tx`) | `BEGIN` … `COMMIT`; cualquier excepción hace `ROLLBACK`. No anida si ya hay una abierta |
| Conflictos tipados | `WriteConflict`, `WriteNotFound`, `run_write` | Un `UNIQUE` violado (error 1062) se vuelve «duplicado», no un error genérico. Un deadlock (1213/1205) se reintenta una vez |
| Verificación de unicidad | `EmpresaModel::assertRucFree`, `ReporteModel::assertUnique` | Replica `UNIQUE(ruc)`, `UNIQUE(enlace)` y `UNIQUE(reporte_id)` **incluyendo registros inactivos**; sin bloqueo (la carrera la decide el `UNIQUE` real) |
| Bloqueo optimista | `row_version()` + `assert_version()` | Sin cambiar el esquema: huella de los campos editables. `view=form` entrega `version`; si el cliente la devuelve y la fila cambió → **409** |
| Baja lógica y reactivación | `setActive()` | `DELETE` sigue siendo baja lógica. Nuevas rutas `POST /api/empresas/{id}/reactivate` y `POST /api/reportes/{id}/reactivate` (solo admin) |
| Auditoría | `audit_record()` + `audit_log` | Misma transacción que el cambio. Ver §3 |
| Correlación | `request_correlation_id()` | Cabecera `X-Correlation-Id` y campo `correlation_id` en el éxito; se guarda en cada fila de auditoría |
| Vistas por defecto | `REACT_STRICT_VIEWS=1` | La respuesta mínima pasa a ser la predeterminada en la instancia que la define. Ver §4 |

### Códigos HTTP

| Situación | Contrato heredado (sin cabecera) | Contrato 2 (`X-API-Contract: 2`) |
|---|---|---|
| Éxito | 200 | 200 |
| Versión obsoleta (`version` no coincide) | **409** | 409 |
| Reactivar algo ya activo | **409** | 409 |
| Duplicado (RUC, enlace, UUID) | 200 + `success:false` | **409** |
| No encontrado | 200 + `success:false` | **404** |
| Validación | 200 + `success:false` | **400** |
| Fallo interno (incl. auditoría no disponible) | 200 + `success:false` (mensaje genérico) | **500** |
| No admin / sin token | 403 / 401 | 403 / 401 |

Las dos primeras filas con 409 son condiciones **nuevas** (no existían antes), por eso no alteran a Flutter. Todo lo demás conserva su forma anterior salvo que el cliente pida el contrato 2. Los mensajes nunca incluyen SQL ni nombres de tabla (probado).

Diferencias menores de comportamiento (documentadas, no regresiones de contrato): una baja de un informe inexistente ahora responde «no encontrado» (antes `success:true`); un RUC de una empresa **inactiva** ahora cuenta como duplicado, igual que haría el `UNIQUE` productivo.

## 2. Compatibilidad con el esquema productivo

Probado en `fixture_w`, con los tipos productivos y los `UNIQUE` reales del DDL aportado: `user(username,email)`, `empresa(ruc)`, `reporte(enlace,reporte_id)`, y las tres tablas de relación. `grupo_id NOT NULL` se respeta (el controlador ya lo valida). No se usa `DELETE` físico en ninguna ruta; los `ON DELETE CASCADE` no se activan.

## 3. Auditoría

`database/migrations/UX-08B-audit-log.up.sql` / `.down.sql` (**no aplicadas a ninguna base real**).

| Columna | Contenido |
|---|---|
| `id`, `occurred_at` | Identificador y fecha (milisegundos) |
| `correlation_id` | La misma de la respuesta HTTP; agrupa todas las filas de una operación |
| `actor_user_id` | Administrador que ejecutó la acción (sin clave foránea: la auditoría no se borra en cascada) |
| `action`, `entity`, `entity_id` | Por ejemplo `empresa.update`, `empresa`, `7` |
| `before_json`, `after_json` | Instantánea **de una lista blanca de campos** |
| `request_ip` | IP del cliente |

- **Atómica con el negocio**: si el `INSERT` en `audit_log` falla, el cambio se revierte (probado con un trigger que bloquea la tabla).
- **`AUDIT_LOG_MODE=required`** exige poder auditar: sin la tabla, la escritura se rechaza y no deja nada a medias (probado). Por defecto (`off`) no se audita, para que desplegar este código antes de la migración no rompa las escrituras actuales.
- **Nunca se guarda**: contraseñas, tokens, `powerbi_*` (solo los **nombres** de campos Power BI que se enviaron), ni el enlace del informe (solo un resumen `enlace_sha256_12`). Probado: ningún `audit_log` contiene el valor de un secreto ni `powerbi.com`.
- **No crea una segunda fuente de verdad**: no copia usuarios, empresas ni asignaciones; es un registro de eventos de soporte, de solo inserción.
- Limitación: la aplicación inserta con la misma cuenta que escribe el negocio, así que un administrador de la base podría alterar la tabla; para inmutabilidad real haría falta una cuenta con `INSERT` únicamente (ver matriz de privilegios en `UX-08B-DISENO.md`).

## 4. Exposición de enlaces (H2 y H3): diagnóstico

Pruebas (`http_writes_test.php`, `contract.mjs`, `http_pagination_test.php`):

| Pregunta | Resultado |
|---|---|
| ¿Un trabajador puede obtener el `enlace` de un informe **que no tiene asignado**? | **No.** `/api/reportes` y `/api/reportes/{id}` filtran por asignación vigente en la consulta; un id ajeno da «no encontrado» sin enlace. Probado también con `?role=admin&user_id=1`, que no tiene efecto |
| ¿Puede elevar su rol por token o parámetros? | **No.** Un token con el rol manipulado → 401; `role`/`user_id` en la URL se ignoran; `view=form` → 403 |
| ¿Qué recibe entonces con una llamada directa sin `view=minimal`? | El `enlace`, `created_by` y `powerbi_tenant/client` de **sus propios** informes y empresas. El secreto de cliente nunca sale |
| ¿Es un problema? | De bajo riesgo para informes de «Publicar en la web» (el enlace ya es público) y mayor para enlaces de inserción autenticada. Es un dato que **Flutter necesita** para mostrar el informe (B-06) |

**Solución sin romper compatibilidad:** Flutter y React se sirven desde backends distintos. `REACT_STRICT_VIEWS=1` en las instancias React hace que la respuesta mínima sea la predeterminada: **sin `enlace`, sin `created_by*` y sin `powerbi_*` incluso en una llamada directa**. La instancia de Flutter no define la variable y conserva su contrato.

Evidencia de que React no depende de esos campos: con el backend QA en modo estricto, el E2E de React contra PHP real da **186 PASS / 0 FAIL** y el contrato deja de reportar H2 y H3 (2 hallazgos en vez de 4). Las 3 aserciones que fallan en modo estricto son precisamente las de «sin `view` conserva los campos completos para clientes anteriores»: ese es el comportamiento que el modo estricto retira a propósito.

Activación (futura, con autorización): añadir `REACT_STRICT_VIEWS=1` al `.env` de los backends React; no tocar el de Flutter. Reversión: quitar la línea.

## 5. Pruebas (resultados)

| Suite | Resultado |
|---|---|
| `php -l` (backend y tests) | OK |
| `security_unit.php` | OK |
| Python `unittest` | 22 OK |
| HTTP aislado previo (`http_test`, paginación, auth, admin, empresa-informe, visor, filtro de estado, lista de visor) | 50 / 228 / 45 / 36 / 67 / 56 / 49 / 15 PASS, 0 FAIL |
| HTTP aislado nuevo `http_writes_test.php` | **76 PASS, 0 FAIL** (rollback atómico, auditoría, 409, duplicados, reactivación, concurrencia real con `curl_multi`, enlaces/secretos fuera de la bitácora, vistas estrictas, token manipulado, migración up/down) |
| Frontend `tsc`, `eslint`, `vitest` | OK · 229 tests |
| E2E simulado | 89 PASS / 0 FAIL |
| Contrato contra PHP real (modo normal) | 206 PASS / 0 FAIL / 4 hallazgos (previos) |
| E2E contra PHP real (modo normal y estricto) | 186 PASS / 0 FAIL en ambos |

**Regresiones previas frente a fallos nuevos.** Durante el desarrollo aparecieron 3 fallos en suites existentes, todos causados por este cambio y corregidos a propósito: dos suites comparaban el conjunto exacto de claves de `view=form` (ahora incluye `version`: se actualizaron esas expectativas y `contract.mjs`), y una editaba un informe a un enlace ya usado por otro (datos de prueba inválidos frente al `UNIQUE(enlace)` productivo: ahora usa un enlace propio). No quedan fallos abiertos.

## 6. Despliegue y reversión (futuros, con autorización)

Orden: (1) respaldo; (2) aplicar `UX-08B-audit-log.up.sql` en la base piloto; (3) desplegar el código; (4) `AUDIT_LOG_MODE=required`; (5) `REACT_STRICT_VIEWS=1`. Reversión inversa: quitar las variables → desplegar el código anterior → `UX-08B-audit-log.down.sql` (tras exportar la tabla si se quiere conservar el historial). Sin variables, el código nuevo se comporta como el anterior salvo los detalles menores de §1.

## 7. Riesgos pendientes

| Riesgo | Estado |
|---|---|
| Backend original (Flutter) sin auditar | Bloquea desactivar usuarios (`UX-08-AUDITORIA-SERVIDOR.md`) |
| Mecanismo desplegado de aprobación del visor administrativo desconocido | Bloquea aplicar `ADMIN_VIEWER_ALLOWED_REPORT_IDS` |
| Privilegios MySQL reales de las cuentas PHP desconocidos | Las escrituras no se activan; la matriz de `UX-08B-DISENO.md` es una propuesta |
| Sin `UNIQUE` en una base, la carrera de dos altas iguales no se puede impedir | Producción los tiene (DDL aportado); las bases piloto creadas con el fixture sin `UNIQUE` quedan expuestas hasta añadirlos |
| `version` solo se exige si el cliente la envía | React ya la envía; Flutter conserva «última escritura gana» |

## 8. Siguiente incremento propuesto

1. **Accesos** (usuario↔empresa↔informe): mismas garantías (transacción, reactivar en vez de duplicar, `UNIQUE` de las tres relaciones, auditoría) y la reactivación explícita en la interfaz. No depende de Flutter.
2. **Interfaz de desactivar/reactivar** empresas e informes, con confirmación que muestre el efecto (accesos que dejarán de ser vigentes) y manejo del 409 («Recargar»).
3. Enviar `X-API-Contract: 2` desde React para recibir 404/409/400/500 reales y mostrar mensajes específicos.
4. **Usuarios** (editar, desactivar/reactivar con `is_active` + `session_version` en una transacción) **solo después** de completar la auditoría del backend original.
