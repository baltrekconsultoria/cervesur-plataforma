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

## 7. P5-A2 — Paridad real de archivos del servidor (10/11/2026)

**Fuente:** salida del script de lectura ejecutado por el operador en `presupuestos` (no se accedió al servidor desde el conector). **Sin modificaciones de producción.**

| Archivo | Admin 8443 | Backend heredado Flutter |
| --- | --- | --- |
| `lib/admin_escritura.php` | Coincide RC2 Fase 2A | Ausente — esperado por separación de plataformas |
| `lib/auth_middleware.php` | Coincide repositorio auditado | **Difiere** |
| `lib/jwt.php` | Coincide repositorio auditado | **Difiere** |
| `lib/pilot_sesion.php` | Presente, no comparado | Ausente — propio del piloto |
| `models/UserModel.php` | **Difiere** de referencia RC1c | **Difiere** |
| `controllers/UserController.php` | **Difiere** de referencia RC1c | **Difiere** |
| `router/UserRouter.php` | Presente, sin referencia contrastada | Presente, sin referencia |
| `router/AdminWriteRouter.php` | Coincide repositorio auditado | Ausente — propio del administrador |

**Importante:** el script P5-A2 comparaba `UserModel.php` y `UserController.php` **contra RC1c (Fase 2B)**, no contra la versión RC2 instalada. Por lo tanto, esas dos diferencias **no prueban anomalía**: pueden ser normales para Fase 2A o cambios de servidor. Se consultaron además las referencias Git de Fase 2A: `UserModel.php` blob `97cc4593a57fd449213af62a7b266096b7be4e36`; `UserController.php` blob `8f10ef25603726d06997597feb6ebc4cc56eef0b`. **Aún no se comprobó si los dos archivos productivos del administrador coinciden con ellas.**

El punto de atención crítico para **P5** es el legado: `auth_middleware.php` y `jwt.php` no son idénticos al repositorio candidato, por lo que las garantías de revocación de roles y expiración de tokens del repositorio **no se pueden atribuir al servidor heredado**. Las diferencias podrían ser esperadas, pero requieren evaluación de comportamiento.

**Próximo control P5-A3, exclusivamente solo lectura:**
1. Comparar contra los **hashes RC2 correctos** de `UserModel.php` y `UserController.php` en Admin 8443.
2. Inspeccionar **sólo indicadores estructurales** del middleware/JWT heredados: comprobación de identidad, sesión, estado, rol actual en base y expiración. No imprimir archivos, valores de JWT ni `.env`. El resultado de indicadores **no demuestra seguridad efectiva**; confirma qué aspectos exigen contraste técnico.
3. No ejecutar solicitudes de cambio de rol/contraseña ni pruebas con cuentas productivas.

**Veredicto:** Admin Fase 2A se mantiene operativa; **P5 NO-GO**. Siguiente diseño de permisos y UI puede avanzar en aislado en paralelo, pero el uso productivo requiere cierre de legado.

## 8. P5-A3 — Verificación de versiones y señales de autenticación heredada (10/11/2026)

**Fuente:** salida del operador desde `presupuestos` de `CERVESUR P5-A3: CONTROL DE VERSION Y SESIONES`. La inspección leyó archivos PHP sin imprimir su contenido, sin consultar base de datos y sin modificar producción.

### Confirmación de Fase 2A

- `/opt/cervesur-react-admin/backend/models/UserModel.php` → `COINCIDE_RC2`.
- `/opt/cervesur-react-admin/backend/controllers/UserController.php` → `COINCIDE_RC2`.

**Rectificación de P5-A2:** las diferencias frente a RC1c provenían de una referencia incorrecta para la versión desplegada; **no son anomalías**. Los cinco archivos principales comparados en P5-A2 son coherentes con Fase 2A. Este alcance no sustituye un inventario de release completo.

### Indicadores del legado Flutter

| Indicador del script P5-A3 | Resultado |
| --- | --- |
| `MIDDLEWARE` y `JWT` | PRESENTE |
| Consulta a tabla `user` | PATRON_PRESENTE |
| Menciones `session_version`, `is_active`, `role` en middleware | PATRON_PRESENTE |
| `AUTH_ROLE_DESDE_PAYLOAD` | **PATRON_PRESENTE** |
| `AUTH_ROLE_DESDE_FILA_DB` | **PATRON_NO_ENCONTRADO** |
| `JWT_REFERENCIA_EXP` | PATRON_PRESENTE |
| `JWT_REFERENCIA_SESSION_VERSION` | **PATRON_NO_ENCONTRADO** |
| `JWT_REFERENCIA_USER_ID`, `RELOJ`, `FIRMA` | PATRON_PRESENTE |

**Interpretación correcta:** estas búsquedas de expresiones son **indicadores estáticos**, no la prueba completa de una vulnerabilidad; `role` puede obtenerse de una variable de nombre distinto y `session_version` puede comprobarse en middleware aunque no se mencione en `jwt.php`. La prueba de comportamiento más fuerte es **D3** (`UX-09B-D3-CIERRE-Y-P4B.md`, sección 4), que usando el texto literal auditado del legado y el `jwt.php` real reportó: (a) rol anterior retenido tras degradación, (b) aceptación de tokens firmados que omiten `session_version`, y (c) falta de caducidad de un token firmado sin `exp`. D3 sigue teniendo casos inconclusos por falta de determinados archivos reales para réplica completa y no es una prueba extremo a extremo del despliegue.

**Impacto en P5:** **no habilitar cambio de rol ni contraseña** hasta disponer de una ruta de autenticación y revocación cerrada en Flutter/legado. No basta confiar en que un cambio de rol incremente `session_version` porque el legado puede aceptar tokens antiguos que no llevan ese campo. La documentación D3 recomendó exigir identidad/versión y obtener el rol de la base; para P5 debe además validarse la exigencia estricta de expiración y firma, manteniendo el contrato funcional de 24 h.

### P5-A4 — Correctivo propuesto para desarrollo aislado

1. **Legado:** basar un parche mínimo en la copia exacta autorizada del `auth_middleware.php` y `jwt.php` productivos, nunca reemplazarlos ciegamente por archivos de RC1c. Exigir `user_id`, `session_version`, `exp` válidos, comprobar el rol actual del usuario activo contra BD en cada petición y rechazar token obsoleto; preservar errores HTTP y contrato de Flutter.
2. **React:** actualizar controles del frontend para mostrar cambios de rol/contraseña únicamente con capacidades sensibles afirmativas, crear formulario de reemisión de contraseña (no existe actualmente como flujo de UI administrativo) y garantizar autorización de backend independiente de botones.
3. **DBA:** permisos mínimos de `UPDATE(role, password, session_version, updated_at)` para una vía sensible segregada; mantener bloqueos de autoedición, último admin, concurrencia y auditoría transaccional. Sin ampliar permisos de Fase 2A o de 2B básica anticipadamente.
4. **Pruebas aisladas:** validar denegación a usuario no admin, tokens antiguos sin campos, rol degradado, administrador promovido, cambio de contraseña, revocación de sesiones, regresión de login Flutter, auditoría 1 por operación, caída segura ante error de auditoría, y restauración.
5. **Dependencias externas:** ejecutar C-5/D4, obtener distribución real de roles, confirmar comportamiento de Flutter ante 401, preservar `JWT_EXPIRATION=86400` decidido, definir firmas y ventanas. **No ejecutar pruebas con modificaciones de usuarios reales sin autorización específica**.

**Veredicto P5-A3:** **PASS de paridad RC2, hallazgo de seguridad heredada confirmado como riesgo para P5 por la evidencia D3, P5 NO-GO**. Continuar diseño/QA aislado; no habilitar interruptores ni modificar producción.

## 9. P5-A4 — Huellas de la línea base heredada (10/11/2026)

La comprobación de solo lectura realizada por el operador en `presupuestos` comparó **tamaño y prefijo SHA-256 (12 caracteres)** de cinco archivos instalados con el inventario D3 del 05/11/2026.

| Archivo heredado | Resultado |
| --- | --- |
| `lib/auth_middleware.php` | COINCIDE_HUELLA_D3 |
| `lib/jwt.php` | COINCIDE_HUELLA_D3 |
| `models/UserModel.php` | COINCIDE_HUELLA_D3 |
| `controllers/UserController.php` | COINCIDE_HUELLA_D3 |
| `router/UserRouter.php` | COINCIDE_HUELLA_D3 |

**Resultado:** `ARCHIVOS_COINCIDENTES=5/5`, `ARCHIVOS_MODIFICADOS=NO`, `SECRETOS_MOSTRADOS=NO`. Esta coincidencia permite utilizar D3 como línea base documentada y descartar, respecto a estos cinco archivos, una deriva de versión desde aquel inventario. No acredita pruebas end-to-end ni ausencia de riesgos en sesiones antiguas.

**Decisión:** cerrar auditoría de huellas P5-A4; preparar cambios seguros **solamente en rama independiente** (frontend con capacidad sensible explícita) y mantener un correctivo específico del backend heredado condicionado a una copia autorizada exacta y pruebas aisladas. No se habilitan flags, permisos SQL ni endpoints productivos. **P5 NO-GO**.
