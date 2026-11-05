# UX-08I · Fase 2B (Usuarios) — auditoría de solo lectura y GO/NO-GO técnico

Rama `feat/cervesur-ux-08i-fase-2b-usuarios`, creada desde `f539c9f` (RC2 de la Fase 2A, la publicada). Diferencias frente a `f539c9f` al momento de la auditoría: **ninguna** (árbol limpio).
Esta auditoría no ejecutó nada en producción: sin SSH, Apache, MySQL real, `.env` real, push ni merge.

## 1. Veredicto sobre el código existente

**NO-GO para liberar el código actual como Fase 2B. GO para construir el candidato 2B sobre él.**

La lógica de negocio de Usuarios existe y está bien construida (transacción + auditoría, último administrador, `session_version`, espera de reactivación). Lo que falta es todo lo que rodea a una liberación segura:

| # | Hallazgo | Severidad | Evidencia |
|---|---|---|---|
| H1 | El bloqueo 2B es **por nombre de base** y no tiene desbloqueo: con `cervesur_portalbi`, `admin_users_locked()` es siempre `true`. No existe el «interruptor explícito por servidor» pedido. | Bloqueante | `backend/lib/admin_escritura.php:39-42` |
| H2 | No hay plantilla de privilegios para Usuarios en producción. La única con escritura sobre `user` (`UX-08H-cuenta-escritura-produccion.sql.template`) es la amplia de QA y el empaquetador la **prohíbe** a propósito. | Bloqueante | `lib-empaquetado.mjs:22`, `database/privileges/` |
| H3 | No hay separación de privilegios entre operaciones comunes y sensibles: rol y contraseña dependen solo de un flag de entorno, no de la base. | Alto | plantilla UX-08H |
| H4 | `setPassword` no usa `lockTargetWithAdminGuard`: no comprueba que el actor siga siendo administrador activo dentro de la transacción ni evita que un administrador cambie su propia contraseña por este endpoint. | Medio | `UserModel.php:536-550` |
| H5 | No hay kit de despliegue 2B (simular/verificar de esquema y permisos, instalador con respaldo/rollback, interruptores de Usuarios). | Bloqueante | `deploy/produccion-escritura/` solo cubre 2A |
| H6 | La aserción de arranque `SHOW GRANTS` no cruza privilegios con interruptores (una cuenta con `UPDATE(password)` y `SENSIBLE` apagado arranca sin quejarse). | Alto | `admin_write_grants_violations()` |
| H7 | El menú lateral muestra «Usuarios» siempre (es el listado de solo lectura de la Fase 1). Los controles de escritura sí están condicionados a la capacidad. | Bajo | `layout.tsx:22`, `AdminPages.tsx:168-189` |
| H8 | Flutter no se puede probar con su código real; el arnés es una reconstrucción (R1–R5 de `UX-08H-PLAN-FASE-2.md` siguen vigentes). | Riesgo residual | `tests/isolated/flutter_compat_test.php` |

### Flujos ocultos por los que un administrador podría escribir `user`
Revisados: la tabla cerrada de rutas (`admin_write_routes`), el guardia de SQL y los grants de la cuenta 2A.
- `PUT /api/user/profile`, `POST /api/user/avatar`, `PUT /api/user/profile/{id}`, DELETE de usuarios, estadísticas y rutas heredadas: **no están en la tabla** → 403 antes de cualquier controlador (probado en `admin_write_unit.php:70`).
- Con 2A, la cuenta MySQL solo tiene `UPDATE(last_login)` sobre `user`; el guardia de SQL rechaza `INSERT`/`UPDATE` de `user` en la base productiva.
- **No se encontró ningún flujo oculto.** El `UPDATE(last_login)` existe solo para que `SELECT … FOR UPDATE` funcione en los modelos de accesos; el código no lo escribe.
- Flutter escribe las mismas tablas con su propia cuenta amplia y sin transacciones: carrera real, no controlable desde este código (se mitiga con `FOR UPDATE` y `row_version` de nuestro lado).

## 2. Matriz de operaciones de Usuarios

Estado verificado = código leído + prueba existente que lo ejercita. «Falta» = a construir en este encargo.

| Operación | Endpoint | Interruptor | Validación / permiso | Auditoría | Privilegio SQL necesario | Estado |
|---|---|---|---|---|---|---|
| Listar / detalle / accesos | `GET /api/users`, `/{id}`, `/{id}/access` | ninguno (Fase 1) | admin, sin `password` | — | `SELECT` por columnas | Verificado (2A en prod) |
| Crear | `POST /api/register` | `USUARIOS` | rol fijo `user`, rechaza `role/is_active/session_version/id`, contraseña 12–72 bytes, email/username únicos | `user.create` | `INSERT` por columnas (incluye `password`) | Código y pruebas HTTP existen; falta grant y bloqueo por servidor |
| Editar datos | `PUT /api/users/{id}` | `USUARIOS` | solo nombre/apellido/teléfono/email, `row_version` (409) | `user.update` | `UPDATE(first_name,last_name,phone,email,updated_at)` | Existe; falta grant |
| Desactivar | `POST /api/users/{id}/deactivate` | `USUARIOS` | no a uno mismo, no al último admin activo, bloquea admins | `user.deactivate` | `UPDATE(is_active,session_version,updated_at)` | Existe; falta grant |
| Reactivar | `POST /api/users/{id}/reactivate` | `USUARIOS` | espera `ADMIN_USER_REACTIVATE_HOURS` desde la desactivación auditada (defecto 24 h) | `user.reactivate` | idem | Existe; falta grant |
| Cambiar rol | `PUT /api/user/{id}/role` | `USUARIOS_SENSIBLE` | solo `admin`/`user`, no el propio, no degradar al último admin | `user.role` | `UPDATE(role)` | Existe; **debe separarse en la base** |
| Cambiar contraseña | `PUT /api/users/{id}/password` | `USUARIOS_SENSIBLE` | 12–72 bytes; sin hash ni texto en la bitácora | `user.password` | `UPDATE(password)` | Existe; **H4** y separación en la base |
| Sesiones | automático | — | `session_version + 1` en desactivar/rol/contraseña; React revalida en cada petición; Flutter **no** (R1–R4) | banderas `session_revocada` | `UPDATE(session_version)` | React verificado; Flutter = riesgo residual |

## 3. Cómo queda el desbloqueo (diseño del candidato)

El bloqueo por nombre de base se sustituye por **política efectiva por servidor, fail-closed**, en cuatro capas independientes:

1. **Puerta de rutas**: `usuarios` y `usuarios_sensible` devuelven `403 fase_2b_bloqueada` salvo que el servidor tenga exactamente `ADMIN_WRITES_USUARIOS=1` (y, para rol/contraseña, además `ADMIN_WRITES_USUARIOS_SENSIBLE=1`). Por defecto, bloqueado.
2. **Guardia de SQL**: `INSERT`/`UPDATE` sobre `user` solo con el flag de usuarios; cualquier sentencia que escriba `password` o `role` solo con el flag sensible.
3. **Cuenta MySQL**: dos plantillas aditivas y reversibles. La de **no sensibles** concede escritura por columnas sin `role` ni `password` en `UPDATE`. La **sensible** concede solo `UPDATE(role)` y `UPDATE(password)` y se aplica por separado, con su propia autorización.
4. **Aserción de arranque**: cruza `SHOW GRANTS` con los flags. Privilegio de escritura sobre `user` con el flag apagado, o `role/password` sin el flag sensible → el backend se niega a servir (503).

## 4. Condiciones de liberación (resumen; detalle en el documento de liberación 2B)
P0 esta auditoría · P1 RC 2B con flags OFF y regresión B1/B2/B3 · P2 autorización DBA y negocio + respaldo verificable · P3 publicar con Usuarios OFF y verificar 2A · P4 solo no sensibles, con una operación real autorizada y evidencia en `audit_log` · P5 rol/contraseña siguen OFF.

## 5. Decisiones tomadas (supuestos)
- «Sin mostrar la opción de Usuarios cuando esté bloqueada» se interpreta como: **no se muestran controles de escritura de Usuarios**. El listado de solo lectura de la Fase 1 ya está en producción y ocultarlo sería una regresión. Si se quiere ocultar también el listado, es un cambio aparte.
- Crear un usuario exige `INSERT(password, role)` en la base: es inherente al alta. El rol lo fija el código (`new_user_role()`), nunca la petición; queda documentado como riesgo aceptado.
