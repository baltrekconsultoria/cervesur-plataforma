# UX-08B.3 · Informe: Usuarios, informes sin grupo, privilegios mínimos y preparación del piloto

Todo verificado en Docker/MySQL sintético. **Nada se aplicó a producción ni a las bases piloto; no hay push, merge ni deploy.** Documentos relacionados: `UX-08-COMPATIBILIDAD-FLUTTER.md` (§0 con la evidencia de PuTTY), `UX-08C-COMPATIBILIDAD-DESPLEGADA.md` (§7), `UX-08E-PRIVILEGIOS-MINIMOS.md`, `UX-08H-USUARIOS-DISENO.md`, `UX-08B2-PLAN-DESPLIEGUE.md`.

## 1. Veredicto

| Pregunta | Respuesta |
|---|---|
| ¿El código está listo para un piloto administrativo **aislado** (base de pruebas propia, no `cervesur_portalbi`)? | **Sí, técnicamente.** Todas las funciones están implementadas y probadas de extremo a extremo (interfaz real contra PHP real), con la API conectada incluso como la cuenta de privilegios mínimos |
| ¿Está listo para **producción** o para escribir en `cervesur_portalbi`? | **No.** Quedan los bloqueos de §9 |
| ¿Qué impide hoy desplegarlo en el servidor, aunque sea aislado? | (1) La decisión de arquitectura de escritura (§8-D1), porque el piloto desplegado parece de solo lectura; (2) autorizar las migraciones y la cuenta de escritura en la base piloto; (3) leer el mecanismo de aprobación de informes del piloto para DB031 |

## 2. Gestión de Usuarios (local; `USER_ADMIN_WRITES` apagado por defecto)

Admin solo: 401 anónimo, 403 trabajador; el rol se lee de MySQL en cada petición. Con el interruptor apagado, las rutas «Interruptor: sí» responden **404 «No encontrado»** incluso a anónimos.

| Método y ruta | Cuerpo | Interruptor | Notas |
|---|---|---|---|
| `GET /api/users/capabilities` | — | no | `{user_admin_writes: bool}`; la interfaz solo muestra botones si es `true` |
| `GET /api/users/{id}` | — | no | Datos editables + `version`. **Nunca** contraseña, hash ni `session_version` |
| `GET /api/users/{id}/access` | — | no | Empresas e informes con `effective` y motivo. Sin enlaces ni campos de Power BI |
| `POST /api/register` | nombres, `username`, `email`, `phone?`, `password` | no | Transaccional y auditado. `username` ≤ 50, correo ≤ 100, contraseña 12–72 **bytes**, hash `password_hash` (compatible con el `password_verify` original). Rechaza `role`, `is_active`, `session_version`, `id` |
| `PUT /api/users/{id}` | nombres, apellidos, teléfono, correo, `version` | **sí** | Versión obsoleta → 409; correo duplicado → 409 |
| `POST /api/users/{id}/deactivate` · `/reactivate` | — | **sí** | `is_active` **y** `session_version + 1` en una transacción |
| `PUT /api/users/{id}/password` | `password` | **sí** | Hash + `session_version + 1`. La auditoría solo marca `password_changed` |
| `PUT /api/user/{id}/role` (existente) | `role` | no (endurecida) | Solo `admin`/`user`; no el propio rol; no degradar al último administrador; `session_version + 1` |

Reglas: sin `DELETE` físico; el último administrador activo y la propia cuenta no se pueden desactivar ni degradar (se bloquean las filas de administradores activos en orden fijo, lo que además serializa dos administradores que se desactivan entre sí: 5 de 5 rondas concurrentes dejaron exactamente uno activo); los roles heredados **no se normalizan** (un `usuario` almacenado se conserva; `usuario` no se puede asignar a otro usuario). Auditoría de usuarios mínima: solo `role`, `is_active` y marcas `<campo>_modificado`; sin datos personales, hashes ni contraseñas.

### Sesiones: qué ocurre con cada operación

| Operación | React (este código) | Flutter (backend original, evidencia de PuTTY) |
|---|---|---|
| **Desactivar** | `is_active=0` y `session_version+1`: toda sesión cae en la siguiente petición | Cae también: el middleware rechaza `is_active != 1` en cada petición, **con cualquier token, incluso uno sin versión** |
| **Reactivar** | `session_version+1`: los tokens anteriores **no** reviven; hay que iniciar sesión | Un token **con** versión antigua no revive; un token heredado **sin** versión **sí** vuelve a servir (R1) hasta caducar |
| **Cambiar contraseña** | `session_version+1`: las sesiones previas caen | Igual para tokens con versión; un token heredado sin versión sigue válido (R1) |
| **Cambiar rol** | `session_version+1` y el rol se lee de MySQL en cada petición: efecto inmediato | El rol del token **no cambia** hasta que caduque (R3): un administrador degradado conserva poder en Flutter |
| **Logout** | `session_version+1` (invalida todas las sesiones de ese usuario) | Igual (`UPDATE … session_version + 1`) |
| **Sesiones simultáneas** | Cualquier logout, baja, cambio de rol o de contraseña cierra todas las del usuario | Ídem para tokens con versión. Si comparten la tabla `user`, un logout en una app cierra las de la otra (R4) |
| **Token heredado sin `session_version`** | Rechazado (401) | Aceptado (O2): no se revoca por versión |

Las filas «Flutter» están **simuladas** en la suite (`ORIGINAL BACKEND SEMANTICS (simulated)`, casos a–d) a partir de la evidencia, no ejecutadas contra el original. Debe confirmarse con QA sobre una copia (P1, P2).

## 3. Informes sin grupo (migración mínima)

```sql
-- up (UX-08B3-reporte-grupo-nullable.up.sql)
ALTER TABLE reporte MODIFY grupo_id VARCHAR(50) NULL;

-- down (…down.sql): rechaza si hay informes sin grupo; nunca inventa valores ni borra
SELECT IF(COUNT(*) = 0, 1, (SELECT 1 UNION ALL SELECT 2)) AS guarda_sin_grupo_id_null FROM reporte WHERE grupo_id IS NULL;
SET SESSION sql_mode = CONCAT_WS(',', @@SESSION.sql_mode, 'STRICT_ALL_TABLES', 'STRICT_TRANS_TABLES');
ALTER TABLE reporte MODIFY grupo_id VARCHAR(50) NOT NULL;
```

**Por qué no UX-07C:** esa migración, además, reduce `grupo_id` a `VARCHAR(36)` (producción: 50), vuelve `enlace` NULL y lo ensancha a 1024. Esta toca únicamente `grupo_id`; `enlace`, `reporte_id` y sus `UNIQUE` quedan igual. Los dos mensajes del backend que sugerían UX-07C ahora apuntan a UX-08B3.

Probado (57 aserciones): esquema antiguo rechaza el alta con el mensaje explicativo y no inserta nada; la migración deja `grupo_id` nulo y todo lo demás (columnas, `UNIQUE`, FK) idéntico; las filas históricas no cambian (huella por fila); alta sin grupo con `reporte_id` UUID v4 interno, nunca un grupo inventado; enlace repetido → 409; enlace > 500 → error claro; edición, baja y reactivación conservan el grupo nulo; visor, listados y vista de formulario funcionan; la reversión **se rechaza** (error 1242) con datos intactos mientras existan informes sin grupo, y funciona cuando no los hay; reaplicar es idempotente.

**Sin verificar (Flutter):** cómo trata Flutter un `grupo_id` nulo (por ejemplo, un modelo JSON con `String` no nulable), la ruta `/api/reportes/grupoid/{uuid}` y `/api/powerbi/*`. QA propuesta: aplicar solo en un entorno de prueba, crear un informe sin grupo desde React, abrirlo en Flutter con un usuario asignado, revisar errores nulos. Si falla, **no** aplicar en producción.

## 4. Privilegios mínimos (detalle en `UX-08E-PRIVILEGIOS-MINIMOS.md`)

Plantilla `database/privileges/UX-08E-cuenta-admin-minima.sql.template` (no ejecutada en ningún servidor): `SELECT` general; `INSERT` en las 6 tablas y `audit_log`; `UPDATE` por columnas (sin `username`, sin `powerbi_*`); **sin `DELETE`, sin DDL, sin `TRIGGER`**; `audit_log` solo `INSERT`/`SELECT`.

Dos pruebas: (a) `privileges_matrix_test.php`, 32 aserciones sobre lo que la cuenta puede y no puede hacer; (b) `http_min_privileges_test.php`, la **API completa ejecutada conectada como esa cuenta** (26 aserciones: empresas, informes, las tres relaciones, usuarios, roles, contraseñas, historial, auditoría). (b) encontró dos defectos reales que (a) no veía: la plantilla no concedía `user.updated_at` (el código la fija explícitamente) y el historial rechazaba `entity=user`. Ambos corregidos.

## 5. Autorización de DB031 y piloto desplegado

Sin cambios de código en el servidor. El mecanismo desplegado es distinto del local y aún no se ha leído; la evidencia nueva sugiere un piloto de **lectura** con base de estado propia (`auditoria`, `usuarios_piloto`). `ADMIN_VIEWER_ALLOWED_REPORT_IDS` no se aplica. Comandos acotados a las cinco preguntas y árbol de decisión A–E: `UX-08C-COMPATIBILIDAD-DESPLEGADA.md` §7.

## 6. Resultados de pruebas

| Suite | Resultado | Frente a la línea base anterior |
|---|---|---|
| `php -l`, `security_unit`, Python (22) | OK | igual |
| `http_test` | 54 PASS | 2 aserciones se **reescribieron a propósito** (ver abajo) |
| paginación / auth / admin / empresa-informe / visor / filtro de estado / visor admin | 228 / 45 / 36 / 67 / 56 / 49 / 15 | idénticos |
| escrituras / accesos / sesiones | 76 / 181 / 69 | idénticos |
| **Nuevas:** informes sin grupo / usuarios / inmutabilidad / privilegios / API con cuenta mínima | 57 / 149 / 8 / 32 / 26 | +272 |
| IDs productivos (`fixture_prod`) | 54 | ahora incluida en mi batería (no reportada antes) |
| `tsc`, `eslint`, `vitest` | OK · 256 tests | +13 |
| E2E simulado | 89/0 | igual |
| E2E PHP real: interruptor apagado / encendido / estricto + encendido | 193 / 203 / 203, 0 FAIL | +2 / +12 |
| Contrato modo normal | 206 PASS, 0 FAIL, 4 hallazgos previos | igual |
| Contrato modo estricto | 205 PASS, 3 «FAIL» | las 3 son «sin `view` conserva campos completos»: retiradas a propósito |
| `build:production`, `verify:production`, `scan:secrets`, `git diff --check` | OK | igual |

**Fallos nuevos durante el desarrollo, todos resueltos (ninguno previo reapareció):** (1) `http_test` reutilizaba un token tras cambiar el rol; ahora el cambio de rol cierra la sesión, así que verifica que el token anterior se rechaza y que un login nuevo refleja el rol (intención original conservada); (2) la plantilla de privilegios sin `updated_at`; (3) el historial sin la entidad `user`; (4) política de contraseña de la interfaz (8) frente a la del servidor (12); (5) mensajes del backend que recomendaban UX-07C; (6) guardas de seguridad del frontend y del E2E que exigían declarar las rutas nuevas.

## 7. Funciones terminadas frente a pendientes de autorización

| Terminado y probado en local | Pendiente de autorización productiva |
|---|---|
| Filtros, paginación, historial, contrato V2 | Aplicar `audit_log` y el endurecimiento en cualquier base |
| Empresas, informes, accesos: transacciones, 409, reactivación, auditoría | Crear la cuenta de escritura / cambiar privilegios |
| Usuarios: alta, edición, baja/alta, rol, contraseña, accesos (apagado por defecto) | Activar `USER_ADMIN_WRITES` |
| Informes sin grupo (migración + reversión segura) | Aplicar la migración y probar Flutter |
| Visor de DB031 por lista local | Integrar con el mecanismo del piloto desplegado |
| Bitácora de solo inserción (triggers) | Aplicarla; limitar privilegios reales |

## 8. Decisiones que necesito del responsable

| # | Decisión | Recomendación |
|---|---|---|
| D1 | **Camino de escritura del piloto**: cuenta mínima de escritura sobre `cervesur_portalbi` (A), escribir en la base de estado (B, segunda fuente de verdad), o piloto de solo lectura (C) | **A**, con la plantilla y sin `DELETE` |
| D2 | `audit_log` junto a la tabla `auditoria` propia del piloto: convivir o reutilizar | Decidir tras leer `estado_piloto.php` |
| D3 | Rol que se escribe al crear usuarios (`user` hoy; producción mezcla `usuario`/`user`) | Mantener `user` si la QA de Flutter lo acepta |
| D4 | Retirar `DELETE` de las cuentas de estado y reducir `cervesur_portalbi_admin` | En una ventana posterior |

## 9. Bloqueos productivos restantes

1. Inspección pendiente del backend original: P1 (¿el login excluye inactivos?), P2 (vigencia del JWT), P5/P6 (altas, ediciones, activación en el original), P7 (transacciones), P8 (`SHOW GRANTS` de las cuentas efectivas, incluidas `ro_*`).
2. Mecanismo de aprobación del piloto administrativo (DB031).
3. QA de Flutter: `grupo_id` nulo, rol `user`, sesiones cruzadas.
4. Decisión D1.

## 10. Estado del repositorio

Rama `feat/cervesur-ux-08a-active-filters`, sin push. Checkout de SEC-05A intacto (20 archivos pendientes).
