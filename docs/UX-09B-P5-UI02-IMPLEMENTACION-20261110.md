# Cervesur P5-UI02 · Reasignación administrativa de contraseña (10/11/2026)

**Estado:** código y pruebas unitarias preparados en GitHub; **CI/compilación/QA aislado pendientes de ejecución**. PR #14 **DRAFT**, sin merge ni despliegue. No modificar producción ni activar `ADMIN_WRITES_USUARIOS`/`ADMIN_WRITES_USUARIOS_SENSIBLE`.

## Evidencia previa

- Fase 2A productiva (8443) auditada con QA de lectura 8/8 **reportado por el operador**.
- P5-A4: los cinco archivos críticos del backend Flutter heredado coinciden por tamaño y prefijo SHA-256 con la línea base D3 (5/5), por lo cual no se detectaron diferencias de versiones en esos archivos.
- D3 documenta el riesgo de que tokens JWT antiguos mantengan rol anterior o no sean revocados cuando omiten `session_version`; **P5 sigue NO-GO** y los archivos del legado no fueron modificados.

## Alcance de implementación P5-UI02

1. **Contrato tipado** `ApiClient.resetUserPassword(id,password)`, implementado en `HttpApiClient` y `MockApi`.
2. **Transporte HTTP:** `PUT /api/users/{id}/password`, cuerpo **exclusivo** `{ "password": "<secreto>" }`; no se envían ni contraseña anterior, ni confirmación, ni rol, ni correo. El cliente exige `adminWrites===true`, `writes.usuarios===true`, `usersSensitive===true` antes de llamar; el backend debe autorizar independientemente.
3. **Diálogo de React** `ResetPasswordDialog`: usuario objetivo claramente identificado, dos campos tipo `password` (nueva y confirmación), regla de **12–72 bytes UTF-8** de acuerdo con `UserModel::passwordError`, nunca autoadministración, advertencia de revocación y entrega por canal seguro. No persiste la contraseña en storage, URL, logs, avisos ni auditoría desde el cliente. Limpia campos al concluir, cerrar o fallar. No refleja mensajes de error del backend que pudieran contener datos sensibles.
4. **Botón del administrador:** «Asignar contraseña», visible solo si la capacidad sensible es afirmativa, usuario objetivo está activo, no tiene rol desconocido y **no es el actor actual**. La apertura del diálogo revalida estas condiciones.
5. **Simulador:** no guarda contraseñas; simula la revocación de sesiones del usuario objetivo y registra un evento de auditoría sin secretos.
6. **Sin afectación:** creación de usuarios con contraseña inicial, formularios de empresas/reportes/accesos, endpoints del backend, Flutter, Apache, MySQL y `.env` sin cambios.

## Evidencia de revisión del código

**Verificación estática de conexiones:** 12/12 condiciones comprobadas mediante lectura de archivos de GitHub (contrato, HTTP, guardas, simulador, UI, backend existente y presencia de escenarios de prueba). **No equivale a Vitest, tsc, ESLint ni a QA extremo a extremo**.

Pruebas añadidas pero pendientes de ejecutar:
- `frontend/src/api/http/httpPasswordReset.test.ts` (seis casos): 403 antes de red cuando faltan capacidades, endpoint y payload exactos, ID inválido, 403 del servidor y 200 con `success:false`.
- `frontend/src/features/admin/ResetPasswordDialog.test.tsx` (seis casos): límites de bytes, confirmación, ausencia de formulario sin permiso o autoedición, limpieza después de éxito/error, rechazo 403 y ausencia de solicitudes para contraseñas distintas.
- `frontend/src/api/http/httpAdminWrite.test.ts` (P5-UI01): capacidades sensibles explícitas.

## Validación requerida antes de marcar PASS

En el checkout **aislado**, nunca directamente en producción:

```bash
cd frontend
npm ci
npm run typecheck
npm run lint
npx vitest run src/api/http/httpAdminWrite.test.ts src/api/http/httpPasswordReset.test.ts src/features/admin/ResetPasswordDialog.test.tsx
npm run test
npm run build:admin-write
npm run verify:admin-write
```

Comprobar que el build no introduce referencias a credenciales embebidas. Registrar el commit exacto y resultados. Después ejecutar QA integrado con backend y MySQL **aislados**, incluyendo: permisos ausentes y 403 reales, usuario normal, autoedición, último administrador, errores de auditoría y `session_version`/Flutter tras cambio. **No afirmar revocación real en Flutter hasta que exista evidencia.**

## Dependencias y decisión

- **UI02:** listo para revisión, no validado por CI todavía.
- **P5 completo:** requiere permisos SQL sensibles segregados, endurecimiento del middleware/JWT heredado conforme a D3, confirmación de regresión en Flutter y cierre de puertas/autorizaciones.
- **GO:** revisión estática del código; **NO-GO:** merge, despliegue y habilitación productiva.
