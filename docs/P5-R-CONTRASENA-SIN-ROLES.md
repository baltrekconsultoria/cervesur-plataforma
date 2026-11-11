# Cervesur · P5-R — Restablecimiento de contraseñas sin cambios de rol
**Estado:** PR de preparación/QA aislado. **NO-GO productivo**. 11/11/2026.

## Alcance exacto
- Admin React 8443: formulario para asignar una nueva contraseña a otra cuenta activa, sin consultar ni recuperar la anterior.
- Backend PHP: `PUT /api/users/{id}/password` es la única ruta de modificación sensible contemplada por P5-R, protegida por **usuarios_password_reset**.
- La edición de nombre/apellido/teléfono y el estado de usuario son U-BASIC, no dependen del reset.
- **Sin cambio de rol**: la ruta `PUT /api/user/{id}/role` queda fuera de la lista de rutas del administrador, el cliente HTTP rechaza el método, el botón desaparece y el guardia SQL impide `UPDATE(role)`.
- No cambia correo ni username existente. No modifica empresas, reportes o asignaciones.

## Puertas de seguridad
La nueva capacidad está apagada por defecto. Solo se considera cuando se cumplen simultáneamente:
1. `ADMIN_WRITES_USUARIOS=1`, autorizado bajo P4b (U-BASIC).
2. `ADMIN_WRITES_USUARIOS_PASSWORD_RESET=1`, permiso exclusivo nuevo; no se usa `ADMIN_WRITES_USUARIOS_SENSIBLE`.
3. `ADMIN_P5R_FLUTTER_401_VERIFIED=1`, marcador de control que **solo debe configurarse tras QA de sesión y revocación real de Flutter**.
4. `ADMIN_WRITES_USUARIOS_SENSIBLE` ausente o 0. Si vale 1, la configuración es rechazada.

**Estos indicadores no sustituyen el QA ni la autorización humana. No configurarlos todavía.**

## Persistencia y SQL (para revisión del DBA, NO aplicar)
La cuenta actual `cervesur_escritura_adm@127.0.0.1` superó P4a de privilegios `no-sensibles` en producción el 11/11/2026. **No contiene UPDATE(password) ni UPDATE(role)**.

El futuro permiso adicional de escritura debe limitarse estrictamente a la columna **password** de `cervesur_portalbi.user`. La cuenta ya tiene permisos P4a para `session_version` y `updated_at`. El conjunto resultante debe revisarse con `SHOW GRANTS` y una nueva verificación específica P5-R; el verificador actual `--etapa no-sensibles --verificar` no es un certificador de la etapa P5-R.

No usar `privilegios-usuarios.sh --etapa sensibles --aplicar`: ese kit incorpora también `UPDATE(role)`, contrario al alcance aprobado. La concesión y su revocación requieren script separado, respaldo, autorización del DBA y pruebas de idempotencia/rollback. El arranque de Admin valida ausencia de concesiones de rol.

## Autenticación Flutter — bloqueador principal
El middleware literal real (`/var/www/cervesur/backend/lib/auth_middleware.php`) fue comparado contra D3 5/5, y una copia saneada fue aportada para revisión. No se versiona la copia productiva en este PR.

D3 reveló que el backend legado acepta determinados JWT antiguos sin `session_version` y toma el rol del payload. Además, su `jwt.php` acepta `exp` ausente si está correctamente firmado. El helper P5-SEC01 del PR #15 no está integrado en el backend productivo.

Requisito de P5-R: para cualquier petición protegida, firma HS256 válida, `user_id`, `session_version` y `exp` obligatorios, usuario activo y rol actual desde MySQL. Tras el reset, `session_version` aumenta en la misma transacción que el hash y la auditoría; JWT anterior debe dar HTTP 401 y el relogin permitir acceso.

**Duración JWT:** en producción se observó `JWT_EXPIRATION=10800` (3 h), mientras el helper P5-SEC01 usa un máximo de 86400 s. Conservar el valor real de producción hasta obtener decisión formal; probar ambos contratos y registrar la política final. No editar `.env` por este PR.

## QA y dependencia de liberación
- [x] P2 integridad GZIP y SHA-256 contra manifiesto PASS; restauración de prueba PASS histórica reportada.
- [x] P3 código RC1c cotejado 4/4.
- [x] P4a verificación real `RESULTADO-PRIVILEGIOS: PASS no-sensibles`, dos triggers y vista `WITH CHECK OPTION`.
- [x] Flutter 5/5 huellas D3, fuente local revisada; sin secreto literal detectado en `auth_middleware.php`.
- [ ] CI y revisión formal del PR P5-R (PHP, TS, React).
- [ ] Integración P5-SEC01 con middleware legado exacto en entorno aislado y prueba HTTP.
- [ ] QA de 401 Flutter en reinicio de sesión, login normal y recuperación tras reset.
- [ ] Cierre de K1/K2/F-10/D4 y las puertas exigidas para P4b.
- [ ] Nuevo kit MySQL P5-R password-only, verificador y reversión con permisos DBA autorizados.
- [ ] Respaldo vigente de ventana + QA de restauración y rollback de código/SQL.
- [ ] Autorizaciones de negocio e Infra; primera cuenta de prueba expresamente autorizada, luego cohortes.

## Estado
Ningún script productivo, privilegio, usuario o bandera de la plataforma fue modificado por la creación de esta rama. PR de código aislado **NO es permiso de despliegue**. La gestión de roles se excluye hasta otro proyecto y autorización.
