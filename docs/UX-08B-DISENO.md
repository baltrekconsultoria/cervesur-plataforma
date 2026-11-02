# UX-08B · Diseño de la administración operativa

> **Actualización:** empresas y reportes ya tienen transacciones, 409, reactivación, bloqueo optimista y auditoría implementados en local; ver `UX-08B-ESCRITURAS-Y-AUDITORIA.md`. Este documento conserva el diseño completo (accesos y usuarios siguen pendientes).

**Estado: diseño y preparación. No hay escrituras nuevas implementadas ni activadas en producción.** Depende de `docs/UX-08-COMPATIBILIDAD-FLUTTER.md` (bloqueante para desactivar usuarios).

Principio: **una sola fuente de verdad**. Se escribe en las seis tablas existentes; no se crean tablas de negocio paralelas.

## 1. Inventario de endpoints

Todos exigen JWT. «Admin» = rol `admin` leído de MySQL en cada petición (`auth_middleware.php:124`). Baja = lógica (`activo = 0`).

| Entidad | Operación | Endpoint existente | Estado | Falta |
|---|---|---|---|---|
| Usuario | Listar | `GET /api/users?status=` (admin) | Existe | — |
| | Crear | `POST /api/register` (admin) | Existe, **sin transacción** | Mapear 1062 → 409; auditoría |
| | Editar datos | — (`PUT /api/user/profile` solo edita al propio usuario) | **Falta** `PUT /api/users/{id}` (admin) | Campos permitidos: nombre, apellido, teléfono, correo |
| | Cambiar rol | `PUT /api/user/{id}/role` (admin) | Existe | Decidir valor `user`/`usuario`; no auto-degradarse |
| | Desactivar / reactivar | — | **Falta** `POST /api/users/{id}/deactivate` y `/reactivate` | Transacción `is_active` + `session_version`; bloqueado por Tarea C |
| | Ver empresas e informes | `GET /api/empresa-usuario`, `/api/empresa-reporte-usuario/user/{id}` | Existe | — |
| Empresa | Listar | `GET /api/empresas?status=` | Existe | — |
| | Crear / editar | `POST`, `PUT /api/empresas[/{id}]` (admin) | Existe | Mapear 1062 (RUC); bloqueo optimista |
| | Desactivar | `DELETE /api/empresas/{id}` (baja lógica) | Existe | — |
| | **Reactivar** | — | **Falta** | `POST /api/empresas/{id}/reactivate` |
| Reporte | Listar | `GET /api/reportes?status=` | Existe | — |
| | Crear / editar | `POST`, `PUT /api/reportes[/{id}]` (admin) | Existe | Mapear 1062 (`enlace`, `reporte_id`) |
| | Desactivar | `DELETE /api/reportes/{id}` (baja lógica) | Existe | — |
| | **Reactivar** | — | **Falta** | `POST /api/reportes/{id}/reactivate` |
| Usuario↔Empresa | Alta / reactivación | `POST /api/empresa-usuario` | Existe (reactiva) | Transacción |
| | Baja | `DELETE /api/empresa-usuario/{id}` | Existe | Decidir si arrastra los accesos a informes |
| Empresa↔Informe | Alta / reactivación | `POST /api/asignacion` | Existe (reactiva) | — |
| | Baja | `DELETE /api/asignacion/{id}` | Existe | Idem |
| Usuario↔Informe | Alta / reactivación | `POST /api/empresa-reporte-usuario` | Existe (reactiva, valida usuario activo) | — |
| | Baja | `DELETE /api/empresa-reporte-usuario/{id}` | Existe | — |
| | **Reactivar** explícito | — (se logra con `POST`) | Cubierto | UI específica «Reactivar» |
| Historial | Consultar | — | **Falta** | Depende de la auditoría (§4) |

Nota: `POST /api/asignacion`, `/api/empresa-usuario` y `/api/empresa-reporte-usuario` ya reactivan una relación inactiva en vez de duplicarla (compatible con los UNIQUE productivos).

## 2. Estrategia de escritura segura

1. **Autorización**: cada ruta de escritura llama a `require_auth` y exige `role === 'admin'` (ya es el patrón). Nunca se confía en el rol del JWT: se lee de MySQL en cada petición.
2. **Transacciones** (hoy no hay ninguna): toda operación de más de una tabla va en `beginTransaction` / `commit` / `rollBack`. Casos: desactivar usuario (`is_active` + `session_version` + auditoría); «asignar informe a usuario» desde el asistente (relación usuario↔empresa + acceso); cualquier escritura + su fila de auditoría.
3. **Integridad**: validar existencia y estado de las entidades relacionadas **dentro** de la transacción (`SELECT … FOR UPDATE` sobre las filas padre) y capturar el error 1062 de los UNIQUE como 409, no como 500.
4. **Concurrencia**: bloqueo optimista con `updated_at`. El cliente envía el `updated_at` que leyó; `UPDATE … WHERE id = ? AND updated_at = ?`; si `rowCount() = 0` → 409 «El registro cambió, recargue». Requiere que `updated_at` esté en la respuesta del formulario (`view=form`).
5. **Bajas**: solo lógicas. Ningún `DELETE` físico (ver `ON DELETE CASCADE` en el documento de compatibilidad).
6. **Campos prohibidos en la entrada**: `id`, `is_active`, `session_version`, `role` (salvo su endpoint propio), `password` en ediciones, `powerbi_clientsecret`. Ya hay una lista de rechazo en `router/auth.php:86`; se reutiliza.
7. **Sin segunda fuente de verdad**: el frontend no guarda copia; tras cada escritura recarga (`bump()`), como hoy.
8. **Interruptor**: las escrituras se anuncian en `capabilities.adminWrites`; si el backend no las soporta, la interfaz no muestra el botón (regla 9 del encargo).

## 3. Diseño de interfaz

- **Usuarios**: «Nuevo usuario» (existe); por fila: «Editar», «Desactivar» / «Reactivar» (con diálogo que indica cuántos accesos vigentes perderá y que cerrará sus sesiones), «Ver accesos». Un administrador no ve «Desactivar» sobre sí mismo ni sobre el último administrador activo.
- **Empresas / Reportes**: «Editar» (existe), «Desactivar» / «Reactivar»; en Reportes, el campo enlace se muestra solo en el formulario del administrador y se valida con `classify_powerbi_link`.
- **Accesos**: asistente existente (usuario activo → empresa vinculada → informes activos de esa empresa); fila con «Retirar» (existe) o «Reactivar» si está revocada y sus padres siguen activos.
- **Confirmaciones**: toda desactivación pide confirmación con el efecto concreto; mensajes de error 409 con «Recargar».
- Los filtros activos por defecto y la paginación de UX-08A/A.1 se conservan: un registro desactivado desaparece de la vista inicial y está en «Inactivos».

## 4. Plan de auditoría

Hoy solo existen `created_by`, `updated_by` y `updated_at` en las tablas, que **no** guardan acción ni valores anteriores. Propuesta (requiere migración → **necesita autorización**):

```
audit_log(
  id BIGINT UNSIGNED PK AUTO_INCREMENT,
  at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actor_user_id INT UNSIGNED NOT NULL,   -- FK user.id
  action VARCHAR(40) NOT NULL,           -- p. ej. user.deactivate, access.grant
  entity VARCHAR(40) NOT NULL, entity_id INT UNSIGNED NOT NULL,
  before_json JSON NULL, after_json JSON NULL,   -- solo campos de la lista blanca
  request_ip VARBINARY(16) NULL
) ENGINE=InnoDB utf8mb4_unicode_ci
```

- Se escribe **en la misma transacción** que el cambio (si falla la auditoría, no hay cambio).
- Lista blanca de campos por entidad; **nunca** `password`, `session_version`, `powerbi_*`, `enlace` completo (se guarda solo su clasificación y un hash corto) ni tokens.
- Es una tabla **de soporte**, no de negocio; no duplica usuarios, empresas ni asignaciones.
- Alternativa sin migración (limitada): conservar solo `updated_by`/`updated_at`; no cumple «antes/después».

## 5. Matriz de privilegios mínimos

Usar una cuenta MySQL **distinta por aplicación**. Permisos propuestos (a confirmar contra `SHOW GRANTS` real, pendiente):

| Cuenta | `user` | `empresa`, `reporte` | `empresa_usuario`, `empresa_reporte`, `user_empresa_reporte` | `audit_log` |
|---|---|---|---|---|
| React trabajadores | `SELECT`; `UPDATE(last_login, session_version, first_name, last_name, phone, avatar, updated_at)` | `SELECT` | `SELECT` | — |
| React administrador | `SELECT`, `INSERT`; `UPDATE(first_name, last_name, phone, email, role, is_active, session_version, last_login, updated_at, updated_by)` | `SELECT`, `INSERT`, `UPDATE` | `SELECT`, `INSERT`, `UPDATE` | `INSERT` |
| Ambas | Sin `DELETE`, sin DDL, sin `GRANT` | | | |

- Los instaladores de los pilotos conceden `SELECT, INSERT, UPDATE, DELETE` sobre toda la base de prueba (`pasos-instalacion.sh`). Es aceptable en bases aisladas; **no** debe replicarse en `cervesur_portalbi`.
- `UPDATE` por columna evita que una inyección o un error modifique `password`.
- No se asume ningún privilegio productivo: se verifica con `SHOW GRANTS FOR <cuenta>` (comando en el documento de compatibilidad).

## 6. Plan de pruebas

| Capa | Pruebas |
|---|---|
| Autorización | No admin → 403 en cada escritura; sin token → 401; rol cambiado a inválido → 401; usuario desactivado → 401 |
| Integridad | Duplicado → 409 (no 500); entidad padre inactiva → rechazo; reactivar en lugar de duplicar; campos prohibidos ignorados/rechazados |
| Transacciones | Fallo simulado a mitad de la operación → ningún cambio parcial (`snapshot` de tablas igual que antes) |
| Concurrencia | Dos `PUT` con el mismo `updated_at` → el segundo recibe 409 |
| Auditoría | Cada escritura produce exactamente una fila; sin `password`/secretos; el fallo de auditoría revierte la escritura |
| Compatibilidad | Suites existentes sin cambios (contratos de Flutter y trabajadores); la respuesta por defecto no cambia |
| UI | Botones solo con `adminWrites`; diálogos de confirmación; filtros y paginación se conservan |
| Entorno | Solo Docker + MySQL sintético; después, copia de la base productiva. Nunca la productiva |

## 7. Plan de reversión

- **Código**: cada slice va en su commit; `git revert`. Las rutas nuevas son aditivas; no cambian las existentes.
- **Datos**: bajas y reactivaciones son lógicas y reversibles con la acción inversa; la auditoría guarda el estado anterior de cada cambio.
- **Migración de `audit_log`**: `DROP TABLE audit_log` (solo contiene registros de soporte). Antes de aplicarla: respaldo (`mysqldump`) y ventana acordada.
- **Interruptor**: retirar `adminWrites` oculta toda la gestión sin tocar datos.

## 8. Orden de implementación sugerido

1. Capa común: transacción, mapeo de 1062 → 409, bloqueo optimista. Pruebas en Docker.
2. Empresas y reportes (desactivar/reactivar, editar): sin dependencia de Flutter.
3. Accesos (reactivar, transacción del asistente).
4. `audit_log` (requiere autorización de migración).
5. Usuarios: editar y desactivar/reactivar — **solo tras cerrar la compatibilidad con Flutter**.
