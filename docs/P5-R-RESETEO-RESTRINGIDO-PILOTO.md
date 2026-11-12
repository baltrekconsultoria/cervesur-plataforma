# Cervesur P5-R — Restablecimiento restringido de contraseña

**Estado:** incremento en PR #33 **DRAFT**, sin merge ni despliegue. Se construye sobre `feat/cervesur-ux-09a-cierre-usuarios`, no sobre `main` (que no contiene la fase2B completa). El piloto U-BASIC del portal 8443 continúa funcionando con `ADMIN_WRITES_USUARIOS=1`, y P5 sensible permanece apagado.

## Alcance y comportamiento

- **Solo** administrador de la lista blanca del 8443, mediante su sesión opaca revalidada a cada petición.
- Botón **Restablecer contraseña** solo para cuentas `user`/estándar **activas**. Nunca aparece para administradores, cuentas inactivas o el propio actor.
- El servidor **vuelve a bloquear bajo transacción** tanto la identidad de quien ejecuta (debe seguir siendo admin activo) como el usuario destino (debe ser estándar activo). UI oculta ≠ autorización suficiente.
- Contraseña nueva y confirmación, 12–72 bytes. No se lee ni se muestra el hash anterior. Se almacena `password_hash()`.
- `UPDATE user SET password = :p, session_version=session_version+1` con auditoría atómica `user.password`; el evento no contiene clave nueva, ni anterior, ni hash.
- La sesión del usuario afectado con `session_version` antigua deja de ser válida para los flujos que comprueban la versión. **Riesgo residual conocido**: tokens heredados de Flutter sin esa versión pueden seguir vigentes hasta su expiración (configuración prevista 24 horas). **No prometer revocación inmediata universal**.
- Rol, username, correo, accesos y estado no cambian. Nunca se habilita P5 completo desde P5-R.

## Separación técnica de permisos

| Capa | Operación P5-R | Comportamiento |
|---|---|---|
| Feature flag | `ADMIN_WRITES_USUARIOS_PASSWORD_RESET` | Ausente/OFF por defecto; requiere U-BASIC y excluye `ADMIN_WRITES_USUARIOS_SENSIBLE` |
| Router | `PUT /api/users/{id}/password` | Solo bajo P5-R; `PUT /api/user/{id}/role` sigue bloqueado |
| Guardia SQL | `UPDATE password` | Permitido con P5-R; `UPDATE role` siempre denegado sin P5 completo |
| Grants MySQL | `UPDATE(password)` exclusivamente | SQL aditivo, no confiere `UPDATE(role)`, `UPDATE(email)`, `SELECT(password)` ni privilegios globales |
| Auditoría | `user.password` | Una entrada atómica, sin contenido secreto |
| Frontend | `users_password_reset: true` | Botón solo si el servidor lo declara explícitamente; por defecto oculto |

## Entregables

- `backend/lib/admin_escritura.php`, `backend/lib/mode.php`, `backend/router/AdminWriteRouter.php`, `backend/models/UserModel.php`.
- `frontend/src/features/admin/UserResetPasswordDialog.tsx`, `AdminPages.tsx`, API HTTP y simulada, tipos y tests.
- SQL `database/privileges/UX-09C-p5r-password-reset.up.sql.template` y `.down.sql.template`.
- Kit de privilegios ampliado con `--etapa password-reset` (`--simular`, `--verificar`, `--aplicar`, `--revertir`) y `interruptor-p5r.sh` (`estado`, `encender`, `apagar`), empaquetado con manifiestos.
- Tests independientes: `tests/p5r_unit.php` y suite React con botón/confirmación/campos bloqueados.

## Pasos de liberación **posterior**, no ejecutados por este PR

1. **GO específico P5-R firmado** por DBA y funcional: alcance, administrador designado, respaldo verificable y retorno probado. Regularizar la referencia de aprobación previa del piloto U-BASIC (tenía marcadores genéricos).
2. **QA aislado completo** de PHP, SQL, frontend, empaquetado y rollback. Además, prueba de integración con MySQL desechable y sesión realista, sin credenciales productivas.
3. Confirmar cuenta/esquema productivo igual a Fase 2B y U-BASIC ON. Crear respaldo `sql.gz` de menos de 24 h. **En ventana autorizada**, copiar paquete candidato, verificando hashes antes de tocar nada.
4. Con el flag P5-R apagado, el DBA aplica **solo** `--etapa password-reset` y verifica `RESULTADO-PRIVILEGIOS: PASS password-reset`. El comando no debe ser usado con la etapa `sensibles` (que concede también `UPDATE(role)`).
5. Verificar `users_password_reset=false` antes de activar. Solo bajo autorización distinta, operador enciende mediante `interruptor-p5r.sh` y comprueba `users_password_reset=true`, `users_sensitive=false`, `usuarios=true`; las otras capacidades quedan intactas.
6. Una única cuenta **user** sintética/QA autorizada: restablecer contraseña, reingresar con la nueva, comprobar que antigua deja de autenticar y que existe exactamente una auditoría `user.password`. Negativos: `admin` destinatario 403/409 sin escritura, rol bloqueado, otra ruta/método, conflicto y rollback por fallo de auditoría.
7. Finalizar con evidencia sin contraseñas, hashes, tokens, correos ni identificadores reales. Mantener monitoreo de sesiones Flutter y un plan de respuesta si el cambio no invalida su token heredado.

**Orden de reversión:** `interruptor-p5r.sh apagar` (solo borra flag, no apaga U-BASIC) → `privilegios-usuarios.sh --etapa password-reset --revertir` (se revoca solo `UPDATE(password)`) → revertir exclusivamente cambios de código respaldados. No usar `interruptor-usuarios.sh apagar` salvo emergencia para cortar *todas* las escrituras de usuarios; este último ahora elimina también el flag P5-R para evitar configuración incoherente. Un rollback de software **no revierte contraseñas que ya se hayan actualizado**.

## Riesgos que impiden GO

- El backend Flutter heredado no garantiza revocación inmediata de tokens emitidos sin `session_version`; la rotación no es sustituto de revocar sesiones activas.
- La cuenta MySQL o un deploy con privilegios de más (en especial `UPDATE(role)`) bloquea liberación.
- Fallo de auditoría, error de 500, fuga de hash o exposición de credenciales bloquean liberación.
- No abrir con referencias de autorización de marcador `[Nombre]`, sin DBA/funcional realmente identificados.
- P5-R es **incorporación nueva**, necesita respaldo/instalación compatible; no ejecutar sus scripts contra el kit 2B anterior que no contiene las plantillas nuevas.
