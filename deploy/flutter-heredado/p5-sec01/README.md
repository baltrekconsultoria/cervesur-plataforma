# Cervesur P5-SEC01 — Candidato de autenticación estricta

**10/11/2026 — preparado en GitHub, no instalado.**
**Estado:** código aislado pendiente de QA. **NO-GO para fusionar/desplegar/activar P5**.

## Qué está listo

- `candidato/p5_strict_auth.php`: helper independiente que verifica JWT HS256 firmado, `user_id`, `session_version`, `exp`, `username`, `email`; exige 24 h máximas (D4: 86400 s) con un margen de reloj de 60 s; lee `is_active`, `session_version` y **rol actual de la base** por cada solicitud, normaliza `usuario` → `user` y falla cerrado ante inactivos, cambios de sesión, errores de BD o roles no reconocidos.
- `tests/p5_strict_auth_test.php`: matriz con usuarios **sintéticos** y SQLite en memoria; NO usa credenciales, JWT_SECRET, servidor ni datos productivos.
- Los métodos tienen prefijo `p5_strict_` para evitar colisiones con las funciones heredadas.

**Seguridad de alcance:** este helper no se invoca desde ninguna ruta existente. No cambia `auth_middleware.php`, `jwt.php`, los permisos SQL, el administrador 8443, Flutter, el puerto 80 o la base productiva. No confundir un QA PASS de este helper con la seguridad de todo el sistema.

## Por qué no reemplazamos el middleware directamente

En D3 se reconstruyeron parcialmente solo 71 líneas literales de `lib/auth_middleware.php` heredado (real: **5079 bytes / SHA-256 prefijo `fbdf6253eec6`**). P5-A4 confirmó **5/5 huellas** frente a ese inventario, pero el código literal completo **no se encuentra todavía en GitHub**.

La integración exige obtener **una copia autorizada exacta y saneada** de `/var/www/cervesur/backend/lib/auth_middleware.php`, junto con el `jwt.php` (2842 bytes, prefijo `b2fe4af95848`) y el contexto del bootstrap `router/index.php`, sin `.env`, credenciales, claves ni JWT reales. Registrar SHA-256 de los archivos **originales** en el host, sin publicar los contenidos sensibles. Conservar ambas versiones pre/post y preparar un parche mínimo que invoque el helper **dentro de `authenticate_user()`**, no sustituir el middleware por el de RC1c.

El helper requiere PDO válido y privilegio de **SELECT** a la tabla `user`, con columnas `id,username,email,role,is_active,session_version`. En producción debe mantener el manejo actual de `require_auth()` y el 401 esperado por Flutter. Antes de modificarlo, revisar la distribución de roles de C-5, D4 (JWT_SECRET y expiración real de 86400), el tipo JSON de `user_id` del login real y la reacción de Flutter a 401, todavía no demostrada en T-EXP.

## Matriz mínima de integración (pendiente)

| Escenario | Resultado esperado |
| --- | --- |
| Token login actual, rol `user` / `admin` / `usuario` | Aceptado y normalizado |
| Token viejo sin `session_version`, `user_id` o `exp` | 401 |
| Firma incorrecta, algoritmo no HS256, token malformado | 401 |
| Contraseña restablecida: `session_version` + 1 | Token anterior 401 |
| Administrador degradado: `role=user` en DB | Rol efectivo `user`; si aumenta versión, token anterior 401 |
| Cuenta inactiva, inexistente o rol desconocido | 401 |
| Error de acceso DB | 401, sin fuga de información |
| Login Flutter, pantalla normal, perfil y Power BI | Sin regresión |
| Flutter recibe 401 y permite recuperar la sesión | Comportamiento documentado y validado por responsable |

## Gate y despliegue

**GO solo para ejecución de QA aislado del helper.** No se solicita despliegue, modificación de roles reales, rotación de JWT_SECRET ni habilitación de P5.

Para preparar la futura liberación: copia exacta autorizada, D3+P5 con PHP/MySQL aislados, C-5, D4, verificación de F-10/K1/K2, prueba de 401 Flutter, respaldos y rollback, firmas/ventana de Infraestructura y autorización explícita.
