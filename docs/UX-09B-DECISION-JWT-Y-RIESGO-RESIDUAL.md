# UX-09B · Decisión `JWT_EXPIRATION`, sesiones y riesgo residual

Estado: **decisión del responsable registrada; nada se modificó en producción, en el middleware, en RC1c ni en Flutter.** P4b sigue en **NO-GO**. Complementa `UX-09B-D3-CIERRE-Y-P4B.md`.

## 1. Decisión registrada

> **`JWT_EXPIRATION = 86400` s (24 h).** Se mantiene la configuración actual para no cambiar la experiencia en Flutter. Se acepta autenticarse aproximadamente una vez al día. No ampliar a varios días. El middleware heredado **no se modifica todavía**.

Consecuencias: el valor previsto de D4-07 es **86400** (la atestación ya se invoca con `--expiracion-prevista 86400`); el código ya usa 86 400 s por defecto cuando la variable falta, así que D4-07 distinguirá «definida con ese valor» de «no definida». Queda cerrada la única decisión que bloqueaba el paquete D4.

## 2. Criterio 3 · Cómo responde Flutter al token caducado

**Estado: NO DEMOSTRADO; la evidencia estática indica que probablemente NO redirige al inicio de sesión por sí solo.** Hace falta una prueba dinámica del responsable de Flutter.

Evidencia (paquete `main.dart.js`, huella `7a997e86f234e06f…` = producción; modelo de cliente transcrito en `correctivos-v6/tests/cadena/modelo_cliente.js`):

| Hecho | Evidencia |
|---|---|
| El backend responde 401 `«No autorizado. Token inválido o expirado.»` ante un token vencido | `require_auth` (RC1c y texto del legado) |
| El cliente decide por código HTTP: 2xx = éxito; cualquier otro → mapa con `statusCode` | `em()`/`gfn()` |
| En las pantallas de edición, un no-2xx abre el diálogo **«Observación»** con el cuerpo de la respuesta | `A.aIG`, `A.aGt` en el bundle |
| **No hay ningún manejador de 401 ni de «sesión expirada»**: no aparece `401` como código de estado, ni textos de sesión caducada, ni `signOut`/cierre de sesión por error de API | búsqueda en el bundle (solo el almacén de autenticación genérico de FlutterFlow: `_auth_token_expiration_`) |
| La ruta `LoginPage` existe y se alcanza desde el estado de autenticación local, no desde un error de la API | enrutador del bundle |

Lectura probable: al caducar el token, la app local sigue «con sesión»; las lecturas pueden quedar vacías o con error y las ediciones muestran «Observación» con el mensaje de 401, hasta que el usuario cierre sesión y vuelva a entrar. **Esto ya ocurre hoy con las 24 h actuales; mantener 86400 no lo empeora.** Sí importa para P4b: la desactivación de un usuario desde React (U19/U27) se verá en Flutter de la misma forma, no como una redirección al login.

### Prueba T-EXP (la hace el responsable de Flutter, sin tocar producción)
1. Con una **cuenta de prueba existente** de Flutter, iniciar sesión y anotar la hora.
2. Pasadas más de 24 h, sin cerrar sesión, abrir la lista de empresas y editar un campo permitido.
3. Registrar con capturas: ¿redirige al login? ¿qué texto aparece? ¿se queda con listas vacías? ¿vuelve a funcionar tras cerrar sesión y entrar?
4. Criterio de aceptación pedido: pide iniciar sesión de nuevo **sin errores inesperados**. Si no lo cumple, decidir si se acepta el comportamiento (documentado) o se pide un ajuste al cliente; **no se resuelve en el backend**.

## 3. Criterio 4 · Desactivación, revocación y cambio de rol invalidan tokens

| Evento | Legado (texto literal de `authenticate_user` + `jwt.php` real; INDICATIVO) | RC1c (código real) |
|---|---|---|
| Usuario **desactivado** | 401 inmediato (línea 107 literal) ✔ | 401 ✔ (matriz de sesión 3, D01) |
| Usuario **eliminado** | 401 ✔ | 401 ✔ |
| **Revocación** por `session_version` (token que lo trae) | 401 ✔ | 401 ✔ (4, 4b, D02) |
| **Reactivación** | el token anterior vuelve a valer si no cambió `session_version` (igual en RC1c, 3b) | idem; la reactivación de React incrementa `session_version` (+1, probado) |
| **Cambio de rol** por sí solo | ✘ **no invalida**: el rol sale del token y se conserva hasta caducar (HALLAZGO) | ✔ el rol sale de MySQL en cada petición (6, 6b, 6c) |
| **Cambio de rol + `session_version` +1 en la misma sentencia** | ✔ 401 (nueva prueba R3, INDICATIVO) | ✔ |
| Token **sin** `session_version` | ✘ aceptado «por compatibilidad» (HALLAZGO) | ✔ rechazado (5) |

Conclusión: **sí hay invalidación efectiva por `session_version`** para toda sesión emitida por el login vigente (que siempre incluye `user_id`, `session_version` y `exp`). El cambio de rol solo invalida en el legado si se incrementa `session_version` en el mismo `UPDATE`. Se desconoce si `UserModel::updateUserRole` del legado lo hace (el modelo no está disponible): **INCONCLUSO**. Hasta verificarlo, la regla operativa es: **todo cambio de rol por SQL incluye `session_version = session_version + 1`**, y no se hacen cambios de rol durante P4c. Verificación en producción de que un token anterior queda invalidado: requiere una escritura (incrementar `session_version` de una cuenta de prueba) y por tanto **autorización específica**; no se hizo.

## 4. Criterio 5 · Riesgo residual de tokens antiguos sin `session_version`

**Qué es.** El legado acepta un token firmado sin `session_version` (y, sin `user_id`, ni siquiera consulta la base). Un token así saltaría desactivaciones y revocaciones.

**Quién puede tenerlo.**
- *Tokens legítimos antiguos:* los que emitió un login anterior a la función `session_version`. El `AuthController` y el middleware desplegados tienen fecha de archivo **2025-12-04** [indicio, no prueba: es la fecha del archivo en el servidor]; hoy es 2026-11-06, es decir, más de once meses, y con 24 h de vigencia **cualquier token legítimo de entonces caducó**, *salvo* que aquel login no emitiera `exp` (desconocido: no tenemos esa versión) [P].
- *Tokens forjados:* solo quien tenga `JWT_SECRET`. Cubierto por D4.

**Impacto sobre P4b.** P4b crea cuentas de rol `user` y permite desactivarlas; ambas cosas funcionan con los tokens del login actual. No cambia roles (P5 bloqueado). El riesgo residual depende de dos incógnitas que D4 y el archivo real resuelven: la fortaleza de `JWT_SECRET` y la existencia de tokens sin `exp`.

**¿Se necesita una medida adicional antes de P4b?**

| Escenario | Medida |
|---|---|
| D4 pasa (secreto fuerte, distinto de los literales, `JWT_EXPIRATION=86400`) | **Ninguna obligatoria.** Aplican los controles compensatorios: cambio de rol siempre con `session_version`+1; sin cambios de rol en P4c |
| D4 falla en `JWT_SECRET` (corto, débil o igual a un literal) | **Obligatoria: rotar `JWT_SECRET`** antes de P4b, y retirar los literales por defecto de los routers (cambio de código) |
| Se confirma que existieron logins sin `exp` | **Obligatoria: rotar `JWT_SECRET`** (único modo de invalidar tokens que no caducan sin tocar el middleware) |
| Ninguna de las anteriores | **Recomendada, no obligatoria:** rotar `JWT_SECRET` en la ventana del primer lote del legado; cierra por completo (i) tokens sin `exp`/`session_version` y (ii) cualquier exposición pasada del secreto |

Costo de rotar: cada usuario de Flutter reinicia sesión **una vez** (equivale a una caducidad diaria). Es un cambio de producción: **requiere autorización específica** y antes confirmar qué otros componentes comparten el secreto (React usa sus propios secretos de piloto; D4 no lo demuestra). **No se ejecuta ni se prepara en producción.** Cualquier endurecimiento del middleware (propuesta de la sección 4 del cierre) queda para después de recibir el archivo real, y no antes de P4b salvo decisión expresa.

## 5. Estado consolidado y siguientes acciones

| Acción | Estado |
|---|---|
| P4b | **NO-GO** (sin cambios) |
| A-C5 y A (P2) | Textos y paquete verificados (3/3 huellas). Hoja de revisión: `inspeccion-flutter/paquete-c5/5-HOJA-DE-REVISION.md` |
| Adendo A-C5b (D4 + entrega de fuentes) | Redactado, **sin firma**. `inspeccion-flutter/paquete-d4/SOLICITUD-INFRAESTRUCTURA.md` |
| D4 | Script 17/17; entrega de fuentes 14/14; **ejecución pendiente** (Infraestructura, tras A-C5b) |
| C-5 | **No ejecutado** |
| D3 sobre archivos reales | **Pendiente** de los archivos; la suite ya admite sustituirlos |
| T-EXP (Flutter) | **Pendiente** del responsable de Flutter |
| Presentar resultado consolidado | Tras C-5, D4 y D3 real; **antes** de pedir autorización de P4b |
