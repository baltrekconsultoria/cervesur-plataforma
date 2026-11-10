# UX-09B · Propuesta para habilitar administración completa de usuarios (Fase 2B + P5)

**Fecha:** 10/11/2026. **Estado:** propuesta técnica para revisión, sin autorización de despliegue. **No modifica** el código del servidor, la configuración, las cuentas, los permisos, ni registros. **K1/P4b/P5 siguen NO-GO**.

## 1. Petición y situación comprobada

Se solicita habilitar (i) creación y edición de Usuarios y (ii) cambio de roles y contraseñas de cuentas existentes.

- Evidencia productiva del operador: `CERVESUR_MODE=CERVESUR_REQUIRED_MODE=admin_escritura`; Empresas, Reportes y Accesos ON; `ADMIN_WRITES_USUARIOS` y `ADMIN_WRITES_USUARIOS_SENSIBLE` **no definidos**; auditoría `required`; vistas estrictas activas; no claves repetidas.
- Cinco archivos cotejados con GitHub: `lib/admin_escritura.php` coincide con RC2 de Fase 2A, no con RC1c de Fase 2B. No se hizo comprobación de todos los archivos del release.
- QA de lectura en navegador administrador 8443: 8/8 PASS, reportado por el usuario; no se ensayaron escrituras ni seguridad HTTP de roles/contraseñas.
- El candidato RC1c ya contiene código y pruebas para **altas de rol fijo `user`**, edición de nombre/apellido/teléfono y alta/baja lógica. Permisos mínimos P4a, interruptor P4b, escrituras P4c, todos **pendientes en producción**.
- **P5 no está preparado para producción**: cambio de rol y contraseña de usuarios existentes se mantiene expresamente bloqueado incluso cuando P4b se active. Fase 2A tiene prohibición adicional de escritura de `user` en puerta de rutas, guardia SQL y permisos del motor. **No resolver por `.env` manual**.

## 2. Habilitación por dos liberaciones independientes

### Liberación U-BASIC (Fase 2B — P3/P4a/P4b/P4c)

**Funciones:** crear usuarios normales con contraseña inicial definida por operador autorizado (almacenada sólo como hash), editar nombre/apellido/teléfono, desactivar y reactivar bajo la espera aprobada. Correo existente, rol y contraseña existente no se editan; username existente no se modifica. El rol del alta permanece `user`, no selectable.

**Precondiciones heredadas ya acordadas (no se omiten):** C-5 sistema+SQL; respaldos P2 restaurables; P3 publicado con Usuarios OFF; P4a vista/GRANT mínimos verificados por DBA; cierre de puertas K2, K1, A1, A2, A3/F-10; D3 inconclusos resueltos, D4/JWT atestado, alternativa F-10 firmada/ensayada; autorizaciones A-L1/A-L2/A-L3/A-L4 y D, E por cada operación P4c; ventanas y rollback por lote.

**QA crítico:** no escalamiento de rol por el alta; idempotencia/conflictos, no autodesactivación ni desactivación del último admin, revocación de sesión, auditoría atómica una vez por operación, vistas mínimas de DB y compatibilidad con Flutter. P4b no hace escrituras, P4c exige autorización por acción. No conectar P5 a la liberación U-BASIC.

### Liberación U-SENSITIVE (P5 separado, posterior a U-BASIC)

**Funciones solicitadas:** cambio de `user` ↔ `admin` sólo conforme a permisos y política aprobada, y reasignación de contraseña de una cuenta existente. No se presume soporte de perfiles adicionales ni recuperación por correo.

**Bloqueos que deben resolverse en desarrollo y QA antes de solicitar ventana:**

1. **Identidad y revocación**: el middleware heredado actual documentado toma el rol del JWT, y no necesariamente de la base. Se ha observado que un JWT antiguo puede conservar privilegios tras degradar a un admin; algunos formatos antiguos de token aceptan falta de `session_version`/`exp`. Antes de habilitar P5, corregir/autenticar el rol vigente en la base por solicitud, comprobar actividad, versión y expiración, y coordinar el comportamiento de Flutter (401 y renovación de sesión). Sin esto, **P5 NO-GO absoluto**.
2. **Privilegios SQL separados**: la cuenta de Fase 2B no puede actualizar `role` ni `password`; preparar una vía mínima, segregada y revisada por DBA, sin ampliar de forma implícita la cuenta de la gestión básica. Todas las escrituras requieren control de autorización y auditoría dentro de una transacción.
3. **Política de roles**: sólo administradores autorizados, prohibido autoelevarse, degradarse indebidamente o degradar/eliminar el último administrador; controlar concurrencia y cambio de versión; revocar sesiones de la cuenta afectada de forma verificable y consistente en React/Flutter.
4. **Contraseñas**: administrador no visualiza hashes ni contraseñas existentes; nueva contraseña por canal seguro, hash robusto, no registrar el secreto ni enviarlo por logs/correo sin autorización, invalidar sesiones de la cuenta afectada, política de longitud y recuperación/primer ingreso acordados. Definir si el cambio por administrador exige reautenticación.
5. **Seguridad y regresión**: pruebas HTTP con administrador, usuario normal, usuario inactivo y tokens obsoletos; intentos de escalamiento y de rehacer sesiones; prohibición de parámetros no autorizados; concurrencia, último admin, atomicidad y reversión. Flutter/Power BI y módulos Empresas/Reportes/Accesos sin regresiones.
6. **Liberación controlada**: paquete P5 independiente, con hash, CI, ensayos aislados MySQL + PHP HTTP, auditoría de diffs frente a producción, scripts `--simular`, respaldo/restauración, ventana, verificación sin datos personales expuestos y autorización **específica adicional** para P5.

**Estado de P5:** puede planificarse/desarrollarse y probarse en aislado **en paralelo** al cierre de K1/K2; **no habilitar ni fusionar ni desplegar en producción** con la evidencia actual.

## 3. Decisiones funcionales a documentar antes de diseñar interfaces P5

- Qué personas/cuentas tienen autorización para promover/degradar a `admin`; no equiparar automáticamente cualquier cuenta `admin` a superadministrador.
- Política de reemisión de contraseña: contraseña definida por administrador y entrega fuera de banda, o flujo propio de cambio; fecha y expiración/rotación opcional.
- Impacto en Flutter/portal: si una promoción o degradación debe cerrar sesiones inmediatamente (recomendación de seguridad: sí).
- Si los roles vigentes son únicamente `user` y `admin`, o existe clasificación funcional adicional que necesite otro proyecto. No inferir perfiles múltiples a partir de asignaciones.

## 4. Dictamen

**GO para iniciar preparación, auditoría y QA aislado de las dos liberaciones.**  
**NO-GO para ejecutar P4b/P4c o P5 en producción en este momento.**  
**No cambiar manualmente `ADMIN_WRITES_USUARIOS` ni `ADMIN_WRITES_USUARIOS_SENSIBLE`**: el código RC2 instalado tiene bloqueos adicionales y P5 carece de controles de identidad suficientes.

## 5. Próximos entregables

1. Preflight P5 de solo lectura: identificar versión efectiva de middleware y la lista de cuentas/roles **agregada**, sin datos personales ni JWT.
2. Preparar diseño de P5 y regresiones aisladas que resuelvan revocación inmediata de rol, control de último administrador y gestión segura de contraseñas.
3. Continuar dependencias C-5, K2, K1, F-10, D3, D4 y firmas para U-BASIC.
4. Liberar primero U-BASIC con P4b y P4c verificadas, después U-SENSITIVE en ventana/PR independiente.

**Referencia:** `UX-09B-TABLERO-EJECUCION.md`, `UX-09B-PUERTAS-DE-SEGURIDAD.md`, `UX-09B-QA-USUARIOS.md`, `UX-09B-D3-CIERRE-Y-P4B.md`, `UX-08I-FASE-2B-VISTA-CANDIDATO.md`.

## 6. Auditoría P5-A1 sobre código candidato (10/11/2026, solo lectura de GitHub)

**Alcance revisado:** `backend/models/UserModel.php`, `backend/controllers/UserController.php`, `backend/router/AdminWriteRouter.php`, `backend/lib/admin_escritura.php`, `backend/lib/auth_middleware.php`, `backend/lib/jwt.php`, `backend/lib/pilot_sesion.php`, `frontend/src/features/admin/{AdminPages,UserDialogs,NewUserDialog}.tsx` y `frontend/src/api/types.ts`. Referencia de la rama `feat/cervesur-ux-09a-cierre-usuarios`. **No se inspeccionó esta vez el código real del backend heredado desplegado**, ni se ejecutaron operaciones contra producción.

### A1. Backend y controles

1. `UserModel::updateUserRole()` (aprox. líneas 501-532) comprueba actor administrador activo bajo bloqueo transaccional, impide editar el propio rol o degradar al último administrador, permite cambiar sólo a `admin` o `user`, incrementa `session_version` y escribe `user.role` en auditoría. El valor histórico `usuario` puede permanecer si no se cambia; no se debe asumir que no existe en la base real.
2. `UserModel::setPassword()` (aprox. líneas 536-552) aplica validación del actor mediante `lockTargetWithAdminGuard()`, rechaza auto-cambio desde el panel, guarda `password_hash(PASSWORD_DEFAULT)`, incrementa `session_version` y audita `user.password` sin exponer contraseña ni hash en el evento. Falta verificar el flujo de entrega/reemisión de contraseña y la interfaz.
3. `AdminWriteRouter.php` exige sesión opaca del administrador y fija identidad de actor; `admin_escritura.php` distingue las capacidades `usuarios` y `usuarios_sensible` y separa las rutas `PUT /api/users/{id}/password` y `PUT /api/user/{id}/role`.
4. La implementación de `auth_middleware.php` en **esta rama de GitHub** exige consultar `is_active`, `session_version` y `role` actuales en BD; `jwt.php` exige campos obligatorios y expiración. **Esto no demuestra que el legado productivo use esos mismos archivos**. La auditoría D3 documenta comportamiento anterior de JWT/rol en los archivos del servidor, que debe contrastarse mediante hashes y pruebas aisladas antes de P5.
5. La cuenta mínima de Fase 2B **no** concede `UPDATE(role)` o `UPDATE(password)`; diseñar privilegios sensibles separados con el DBA, auditables y revocables, sin ampliar implícitamente la cuenta existente.

### A2. Frontend y funcionalidad

1. `AdminPages.tsx` contiene diálogo de **Cambiar rol**, conectado a `api.changeUserRole`.
2. El botón usa `capabilities.usersSensitive !== false` (aprox. línea 175), que acepta un valor ausente (`undefined`). **Recomendación de defensa en profundidad:** cambiarlo por `=== true` junto con prueba de ausencia/error de capacidades, de modo que el frontend falle cerrado. La API seguirá siendo control de seguridad principal.
3. `NewUserDialog.tsx` sí tiene campo de **contraseña inicial** para altas (P4c); **no equivale a la reemisión de contraseña de usuarios existentes**.
4. En los componentes administrativos y el contrato `ApiService` revisados no se halló una acción/formulario de **cambiar contraseña existente**; crear componente y contrato explícito para P5, con confirmación, manejo seguro del secreto, control de habilitación y mensajes sobre cierre de sesiones.
5. Debe comprobarse que las capacidades `usuarios_sensible` y `usersSensitive` se mapean correctamente en el adaptador HTTP. Un indicador de interfaz nunca constituye autorización.

### A3. Dictamen y siguiente verificación

**P5-A1: auditoría estática inicial completada, CON HALLAZGOS; P5 sigue NO-GO**. El código candidato incorpora reglas relevantes de seguridad; resta cerrar paridad con producción, UI de contraseña, privilegios mínimos y pruebas adversariales.

**P5-A2 propuesta:** comparar **solo huellas Git blob SHA-1** de archivos de autorización y Usuarios instalados en el administrador 8443 y en el legado `/var/www/cervesur/backend`, sin abrir `.env`, leer tokens, contraseñas ni imprimir archivos PHP. Reportar únicamente `COINCIDE_REFERENCIA` / `DIFIERE` / `AUSENTE`. Un hash coincidente demuestra igualdad de archivo, no funcionamiento correcto del despliegue completo. No efectuar `PUT`, `POST`, `DELETE`, `GRANT` ni migraciones.
