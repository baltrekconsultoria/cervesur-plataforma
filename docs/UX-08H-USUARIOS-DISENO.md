# UX-08H · Administración de usuarios: diseño

**Estado: diseño. Nada de esto está habilitado ni debe habilitarse en producción** hasta cerrar `UX-08-COMPATIBILIDAD-FLUTTER.md` (P1, P2, P5, P6 y la QA sobre copia). Se puede implementar y probar en Docker.

## 1. Alcance y principio

Crear, editar, activar/desactivar, asignar rol y consultar permisos de usuarios **sobre la tabla `user` existente**, con las mismas garantías que Empresas y Reportes (transacción, auditoría atómica, 409, bloqueo optimista) y sin crear otra fuente de verdad. No se tocan cuentas reales.

## 2. Inventario

| Operación | Existe | Propuesta |
|---|---|---|
| Listar | `GET /api/users?status=` (admin) | — |
| Crear | `POST /api/register` (admin): rol `user`, activo, `session_version=1` | Envolver en transacción + auditoría; mapear 1062 (`username`, `email` UNIQUE) a 409 |
| Editar datos | Solo el propio perfil (`PUT /api/user/profile`) | `PUT /api/users/{id}` (admin): nombre, apellido, teléfono, correo; `version` obligatoria desde React |
| Desactivar / reactivar | **No existe** | `POST /api/users/{id}/deactivate` y `/reactivate` |
| Cambiar rol | `PUT /api/user/{id}/role` (admin) | Endurecer: transacción, auditoría, protección de administradores, revocación de sesión |
| Ver permisos | Relaciones por separado | `GET /api/users/{id}/access`: empresas y informes vigentes, con el motivo cuando no surten efecto |

## 3. Reglas

| Regla | Detalle |
|---|---|
| **Último administrador** | Dentro de la transacción, con las filas de administradores activos bloqueadas (`FOR UPDATE`): no se puede desactivar ni degradar al último administrador activo → 409 |
| **Auto-protección** | Un administrador no puede desactivarse ni degradarse a sí mismo → 409 |
| **Desactivar** | Una sola transacción: `is_active = 0` **y** `session_version = session_version + 1`, más la fila de auditoría. Efecto: el API rechaza cualquier token del usuario en ambos backends (`is_active`, O1 del documento de compatibilidad). Los accesos y relaciones **no se tocan** (quedan sin efecto por vigencia y se recuperan al reactivar) |
| **Reactivar** | `is_active = 1` y `session_version + 1` (los tokens previos no reviven). Limitación conocida: un token heredado sin `session_version` (R1) no se invalida; su vida la fija la caducidad del JWT |
| **Rol** | Valores admitidos: los ya presentes en producción (`admin`, `user`, `usuario`). **No se normaliza ni se reescribe ningún rol existente.** Un cambio incrementa `session_version`. Limitación conocida (R3): Flutter conserva el rol del token hasta su caducidad |
| **Contraseñas** | Solo se establece al crear (`password_hash`). No hay edición ni restablecimiento en esta etapa. Nunca se audita ni se devuelve |
| **Unicidad** | `username` y `email` UNIQUE: el conflicto se informa como 409 (también ante una carrera) |
| **Concurrencia** | Huella (`version`) de nombre, apellido, teléfono, correo, rol y estado; una edición con huella obsoleta → 409 |
| **Campos prohibidos en la entrada** | `id`, `session_version`, `password`, `last_login`, `created_at` se ignoran o rechazan; `is_active` y `role` solo por sus rutas |

### Auditoría de usuarios (datos personales)

`audit_log` conserva `before/after` de una lista blanca. Para usuarios la lista blanca es **mínima**: `role`, `is_active`, y para `first_name`, `last_name`, `email`, `phone` solo un indicador `*_modificado: true` (más un resumen corto `sha256_12` del valor nuevo para detectar repeticiones). Así no se duplican datos personales en la bitácora. Nunca `password`, `session_version` ni tokens.

## 4. Interruptor de seguridad

Las rutas nuevas de escritura de usuarios responden **404** salvo que el servidor defina `USER_ADMIN_WRITES=1` (por defecto, ausente). Permite desplegar el código sin exponer la función y activarla solo tras la QA. La interfaz solo muestra los botones si el backend lo anuncia (`capabilities.adminUsers`).

## 5. Interfaz (cuando se habilite)

- Fila: **Editar**, **Desactivar/Reactivar**, **Cambiar rol**, **Ver accesos**.
- Confirmación con el efecto concreto: cuántos accesos vigentes dejará sin efecto, que sus sesiones se cerrarán, y el motivo si la operación se rechaza (último administrador, uno mismo).
- Filtros activos por defecto y paginación de 25 se conservan; el 409 recarga la lista.

## 6. Pruebas (locales)

Autorización (no admin → 403, anónimo → 401, rol/params manipulados); último administrador (desactivar y degradar, con concurrencia: dos administradores que se desactivan mutuamente a la vez → al menos uno queda activo); auto-protección; token del usuario desactivado → 401 en la siguiente petición; token anterior tras reactivar → sigue 401; duplicados `username`/`email` → 409; versión obsoleta → 409; rollback si falla la auditoría; la auditoría no contiene contraseñas, correos ni teléfonos en claro; interruptor apagado → 404; contratos antiguos intactos.

## 7. Reversión

Quitar `USER_ADMIN_WRITES` deshabilita las rutas sin tocar datos. Desactivar/reactivar son inversas entre sí; la auditoría permite reconstruir el estado previo. No hay migraciones propias (usa `audit_log` de UX-08B).
