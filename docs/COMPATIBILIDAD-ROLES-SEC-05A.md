# SEC-05A — Política de roles y compatibilidad

## Comportamiento final
| Valor almacenado en `user.role` | Login | Rol emitido (respuesta y JWT) | Privilegios |
|---|---|---|---|
| `admin` (también `ADMIN`, con espacios) | permitido | `admin` | administrador |
| `user` | permitido | `user` | usuario |
| `usuario` (heredado) | permitido | **`user`** | solo los de `user`, nunca administrativos |
| cualquier otro valor, vacío o `NULL` (p. ej. `superadmin`) | **rechazado antes de emitir JWT** | — | ninguno |

- Punto central: `normalize_role()` en `backend/lib/roles.php`, usado por `AuthController` (login) y `authenticate_user()` (cada petición).
- Rol desconocido en el login: HTTP 200 con `{"success":false,"message":"Credenciales inválidas"}` (contrato de errores sin cambios, mensaje genérico, sin JWT).
- Sesión existente cuyo rol pasa a ser desconocido: `authenticate_user()` la rechaza (401) porque el rol se relee de la BD en cada petición.
- `/api/register` exige administrador autenticado; el rol nunca proviene de la petición (rol fijo `user`, `is_active=1`, `session_version=1`).
- No hay migración de roles: `usuario` permanece almacenado.

## Diferencias frente al comportamiento anterior
1. El login devuelve `user` (antes `usuario`) para el rol heredado.
2. Un rol desconocido ya no obtiene JWT (antes obtenía token sin privilegios y React lo rechazaba).
3. Los demás endpoints (`/api/user/profile`, `/api/users`, …) **siguen devolviendo el valor almacenado**; no se modifican sin revisión de compatibilidad.

## Antes de desplegar a producción
- Inventariar los valores reales de `user.role` (consulta de solo lectura, con autorización) y corregir los que no sean `admin`/`user`/`usuario`: esos usuarios quedarían sin acceso.
- Validar la compatibilidad con el cliente Flutter (rol `user` en vez de `usuario`; el bundle histórico es inconcluyente).
- El limitador de intentos de login (SEC-05A.3) sigue **solo diseñado**, no implementado: ver `DISENO-LIMITE-LOGIN-SEC-05A3.md`.
