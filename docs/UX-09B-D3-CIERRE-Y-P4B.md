# UX-09B · D3 · cierre, trazabilidad y decisión de P4b

Estado: **ejecutado localmente sobre copias. No se tocó producción, RC1c, Apache, Flutter, bases, configuración ni flags; sin commit, push ni merge.** Complementa `UX-09B-D3-NO-ELUSION.md` (cobertura previa, que se conserva) y actualiza `UX-09B-PUERTAS-DE-SEGURIDAD.md` y el tablero.

## 1. Veredicto

> **P4b = NO-GO.** D3 sigue **sin fallos** (0 FAIL en 2 207 peticiones reales a routers entre las dos corridas del legado (1 872 + 335)), pero **ninguno de los 8 casos INCONCLUSOS pasa a PASS**, porque los archivos reales que los cierran no existen en ninguna copia local. Se avanzó en cada uno con la evidencia real disponible (secciones 3 y 4) y salió **un hallazgo nuevo de fondo** sobre sesiones antiguas y roles obsoletos (sección 4). D4 y K1 `retirar` requieren a Infraestructura.

**Lo que NO se hizo, y por qué:** no se declaró PASS nada que dependa de esqueletos o de reconstrucciones. El `auth_middleware.php` de producción (5079 B, `fbdf6253eec6`) no se pudo reconstruir con huella idéntica: solo 24 de 81 líneas literales coinciden con cualquiera de las 4 versiones del historial git.

## 2. Trazabilidad de archivos del legado (32 registrados en el diagnóstico; 7 exactos)

Huellas = 12 primeros caracteres del SHA-256 (no son secretos). Fuente de tamaños y huellas: `DIAGNOSTICO-FLUTTER-PRODUCCION-20261105.md` (inspecciones de solo lectura de producción). «Exacto» = se reprodujo un archivo con esa huella y tamaño. Búsqueda en **todo** el historial git (206 blobs de `backend/`), en `replica-fuentes/`, en `correctivos-v6/base` y en los paquetes. Los archivos exactos y parciales que se usaron están en `deploy/flutter-heredado/d3/reales/`.

| Archivo (producción) | Bytes | Huella | Disponible localmente | ¿Lo necesita Infraestructura? | Casos D3 |
|---|---|---|---|---|---|
| `controllers/AsignacionController.php` | 9694 | `9ca1dc1c7d3c` | LITERAL: 9610 B frente a 9694 B | SI | - |
| `controllers/AuthController.php` | 2813 | `d73fc132cbae` | AUSENTE | SI | 1, 2 (emision del token) |
| `controllers/EmpresaController.php` | 8644 | `f18078cca300` | ESQUELETO / literal sin huella | SI | 5, 8 |
| `controllers/EmpresaReporteUsuarioController.php` | 6001 | `cbac3ca046d8` | ESQUELETO con las lineas impresas por C-3 | SI | 5 |
| `controllers/EmpresaUsuarioController.php` | 10978 | `380f42e30731` | EXACTO (git f9233a6) | su modelo no esta | - |
| `controllers/PowerBIController.php` | 3531 | `2411638d3dee` | LITERAL: 3525 B frente a 3531 B | SI | - |
| `controllers/RegisterController.php` | 3257 | `5bb2e6f4d0af` | STUB de prueba (11 lineas) | SI | 7 |
| `controllers/ReporteController.php` | 6629 | `880d799275ca` | ESQUELETO con las lineas impresas por C-3 | SI | 5 |
| `controllers/StatsController.php` | 1378 | `11dc95f25f43` | AUSENTE | SI | - |
| `controllers/UserController.php` | 6890 | `27e1b3c130d6` | EXACTO (git 3129664) | su modelo no esta | - |
| `lib/auth_middleware.php` | 5079 | `fbdf6253eec6` | PARCIAL: 71 de 154 lineas literales (63-133); el resto no se imprimio | SI | 1, 2 |
| `lib/db.php` | 1286 | `50a1ddd477cc` | AUSENTE | SI | 1, 2 (opciones de PDO) |
| `lib/env.php` | 583 | `ba6b3544b497` | AUSENTE | SI | - |
| `models/AsignacionModel.php` | 8166 | `9641f96703ee` | AUSENTE | SI | 5 |
| `models/EmpresaModel.php` | 10578 | `a645a5e864e9` | ESQUELETO con las lineas impresas por C-3 | SI | 5, 8 |
| `models/EmpresaReporteUsuarioModel.php` | 22236 | `2aa5b626a9fa` | ESQUELETO / literal sin huella | SI | 5 |
| `models/EmpresaUsuarioModel.php` | 21044 | `1d0512ec07b1` | AUSENTE | SI | 5, 6 |
| `models/PowerBIModel.php` | 1973 | `085a342ff36b` | LITERAL: 1972 B frente a 1973 B | SI | - |
| `models/ReporteModel.php` | 10405 | `18b2e253a955` | ESQUELETO / literal sin huella | SI | 5 |
| `models/UserModel.php` | 14125 | `44aa41c5810a` | AUSENTE | SI | 6 |
| `router/EmpresaReporteUsuarioRouter.php` | 3438 | `b854eaa4d40f` | LITERAL con lineas enmascaradas (sin huella) | SI | 5 |
| `router/ReporteRouter.php` | 4201 | `14406e81fdf3` | LITERAL con lineas enmascaradas (sin huella) | SI | 5 |
| `router/StatsRouter.php` | 1048 | `fe6bca7920db` | AUSENTE | SI | 7 |
| `router/UserRouter.php` | 13424 | `60b011d54265` | LITERAL: 13386 B frente a 13424 B (secreto por defecto enmascarado) | SI | 6, 7 |
| `router/auth.php` | 4598 | `a4f063a53db4` | FRAGMENTOS (lineas 40-135) sobre el esqueleto | SI | - |
| `router/index.php` | 1520 | `7add7e450c6a` | LITERAL casi completo: 1519 B frente a 1520 B (no exacto) | SI | 3 |
| `router/wiki.php` | 21470 | `a014201cbd4b` | AUSENTE | SI | 7 |
| `lib/jwt.php` | 2842 | `b2fe4af95848` | EXACTO (git 1d9cd9b) | no | - |
| `router/AsignacionRouter.php` | 3645 | `4b4962ced9f0` | EXACTO (reconstruccion con SHA identico) | no | - |
| `router/EmpresaRouter.php` | 3240 | `a50a3c1c2a78` | EXACTO (reconstruccion con SHA identico) | no | - |
| `router/EmpresaUsuarioRouter.php` | 3039 | `c4433d63c213` | EXACTO (reconstruccion con SHA identico) | no | - |
| `router/PowerBIRouter.php` | 1265 | `8ee4f7ee7529` | EXACTO (reconstruccion con SHA identico) | no | - |

**Corrección de un supuesto previo:** el `UserController.php` de producción **sí** está disponible exacto (coincide con el de la rama principal), igual que `EmpresaUsuarioController.php` y `jwt.php`. Pero ninguno puede ejecutarse sin su modelo (`UserModel`, `EmpresaUsuarioModel`), que no está.

### Qué debe entregar Infraestructura (conjunto mínimo para cerrar los 8 casos)

Copia de solo lectura, **con el literal por defecto de `JWT_SECRET` y cualquier credencial enmascarados** (reemplazar el texto entre comillas por `<MASK>`; D4 compara el secreto real en el servidor, sin mostrarlo). Con `sha256sum` de **cada archivo antes de enmascarar**, para poder contrastarlo con la tabla.

1. `lib/auth_middleware.php` (5079 B) — casos 1 y 2.
2. `router/index.php` (1520 B) — caso 3.
3. `controllers/EmpresaController.php` y `models/EmpresaModel.php` — casos 5 y 8.
4. `models/UserModel.php` (14125 B) — caso 6 (el controlador ya está).
5. `router/StatsRouter.php` y `router/wiki.php` — caso 7 (son los dos únicos routers incluidos por `index.php` que no tenemos).
6. `lib/db.php` (1286 B) — opciones de PDO (tipos de `user_id`); enmascarar credenciales si las hubiera.

Conjunto completo (para dejar la réplica sin esqueletos): además `ReporteController`, `ReporteModel`, `EmpresaReporteUsuarioController`, `EmpresaReporteUsuarioModel`, `AsignacionModel`, `EmpresaUsuarioModel`, `RegisterController`, `AuthController`, y los routers `ReporteRouter`, `EmpresaReporteUsuarioRouter`, `UserRouter`, `auth.php`. El script D4 (sección 5) imprime las huellas de los más relevantes sin copiar nada.

## 3. Estado de los 8 casos inconclusos

Leyenda: **PASS** solo con archivo real; **INDICATIVO** con reconstrucción parcial o literal; **HALLAZGO** = comportamiento real que hay que decidir; **PENDIENTE** = depende de Infraestructura/DBA.

| # | Caso | Estado ahora | Evidencia y ubicación | Qué falta para PASS |
|---|---|---|---|---|
| 1 | Authorization ausente/deformada/duplicada/inválida (legado) | **INCONCLUSO** (avance real) | `jwt.php` **real**: 15 casos **PASS** (firma ajena, payload alterado, `alg=none`, HS512, sin alg, firma vacía, 2 y 4 partes, caducado, `exp=0`, 70 KB, vacío) y 3 HALLAZGOS (sección 4). Extracción de la cabecera: INDICATIVO (líneas 64-73 literales: `Bearer\s+(.*)$`) | `auth_middleware.php` real |
| 2 | Inactivos y sesiones revocadas (legado) | **INCONCLUSO** (avance real, con hallazgo) | `matriz-d3-cierre-legado.csv` grupo R3: inactivo 401, revocado 401, eliminado 401, firmado con otro secreto 401, usuario 403 (INDICATIVO) | `auth_middleware.php` real |
| 3 | Normalización de URL y override en `index.php` | **INCONCLUSO** (avance real) | `index.php` literal (1519/1520 B): método solo de `REQUEST_METHOD`, ruta de `parse_url(REQUEST_URI)`, sin `HTTP_X_*` ni `$_GET`. 17 escrituras × variantes de URL tras `parse_url`, 322 peticiones con token real: **0 elusiones** (INDICATIVO) | `index.php` real (1 byte de diferencia) |
| 4 | `JWT_SECRET` no predeterminado y `JWT_EXPIRATION` | **PENDIENTE (Infraestructura)** | Paquete `inspeccion-flutter/paquete-d4/`: script de solo lectura, **17/17** con casos buenos y malos y sin imprimir valores | Ejecutarlo en el servidor (sección 5) |
| 5 | Controladores/modelos reales de Reporte, ERU, Empresa | **INCONCLUSO** (sin avance) | No existen localmente | Archivos de la sección 2 |
| 6 | `UserController` real: perfil propio no cambia correo/rol/contraseña | **Parcial: PASS estático del controlador exacto, INCONCLUSO en el modelo** | `UserController.php` exacto (huella `27e1b3c130d6`): `updateProfile` solo admite `first_name, last_name, email, phone, avatar`; rol, contraseña, estado y usuario **no pasan**. Ojo: **`email` sí es editable** en el perfil propio | `UserModel.php` real (¿valida unicidad del correo?) |
| 7 | Rutas alias hacia el alta | **INCONCLUSO** (avance real) | `index.php` literal incluye 10 routers; 8 los tenemos. El alta de cuentas solo existe en `POST /api/register` (cerrada por F-10, 4 PASS en D3). | `StatsRouter.php`, `wiki.php`, `RegisterController` real |
| 8 | `EmpresaController` real: otra salida del secreto | **INCONCLUSO** (sin avance) | No existe | `EmpresaController`, `EmpresaModel` reales |

## 4. Hallazgo nuevo: sesiones antiguas y roles obsoletos (texto literal de producción + `jwt.php` real)

Las líneas 63-133 de `authenticate_user()` son **literales de producción** y `jwt.php` es el real. Con ellos, ejercitando los routers reales:

| Escenario | Resultado | Lectura |
|---|---|---|
| Token vigente sin `session_version`, tras revocar la sesión | **ACEPTADO** | El código lo permite «por compatibilidad con tokens antiguos» (líneas 111-118). La revocación **no alcanza** a esos tokens |
| Administrador **degradado** a `user`, con su token anterior | **CONSERVA los permisos de administrador** | El rol sale del **token** (línea 131), no de la base. Dura hasta que caduque |
| Usuario **promovido**, token anterior | No se eleva | Seguro, pero hasta reiniciar sesión |
| Token firmado **sin `user_id`** | **ACEPTADO** sin consultar la base, aunque el usuario esté inactivo | Línea 84. Exige `JWT_SECRET` |
| Token **sin `exp`** / `exp` nulo o no numérico | **No caduca nunca** (`jwt.php` real) | Exige `JWT_SECRET`. El login siempre emite `exp` numérico |
| Usuario **desactivado**, **eliminado** o sesión **revocada** (token con `session_version`) | 401, sin cambios | La baja funciona de inmediato para los tokens que emite el login actual |

**Alcance real para P4b.** El login actual emite siempre `user_id`, `session_version` y `exp` (texto literal de `AuthController.php:52-60`), de modo que **desactivar o revocar funciona hoy para toda sesión emitida por el login vigente**. Las brechas afectan a (a) tokens anteriores a `session_version`, que caducan con `JWT_EXPIRATION`; (b) cambios de rol, que React **no** hace (P5 bloqueado) y que hoy solo hace el DBA por SQL; y (c) quien tenga `JWT_SECRET`. Por eso **no se exige parchear el middleware para P4b**, sí estas condiciones:

1. D4 atestado (`JWT_SECRET` fuerte y `JWT_EXPIRATION` acotado: determina cuánto dura la brecha (a)).
2. Todo cambio de rol por SQL incrementa `session_version` **en la misma sentencia** (control compensatorio para tokens que lo traen).
3. Ningún cambio de rol durante la ventana de P4c.

### Si se decide endurecer el middleware: impacto sobre Flutter (propuesta, no implementada)

Medido con el middleware **estricto real** de RC1c frente a tokens con la forma exacta del login del legado (`matriz-d3-compatibilidad.csv`):

| Efecto del endurecimiento | Resultado | Impacto en Flutter |
|---|---|---|
| Tokens del login actual (`user`, `admin`, rol heredado `usuario`) | Aceptados | **Ninguno** |
| `session_version` desfasado, usuario inactivo | Rechazados (objetivo) | Ninguno (ya ocurre) |
| Rol degradado | Se aplica el rol de la base | Pierde permisos al instante (objetivo) |
| Token antiguo sin `session_version` | Rechazado | Un reinicio de sesión, una sola vez, para sesiones anteriores a esa función |
| `user_id` o `session_version` como **texto** | **Rechazado** | **Riesgo**: rompería el login si el servidor devolviera ids como texto. PHP del servidor: 8.3.6 (inspección), que devuelve enteros: **riesgo bajo, por confirmar** |
| Rol de base distinto de `admin`/`user`/`usuario` | **Rechazado** | **Pierde el acceso** esa cuenta: se verifica con la distribución de roles de C-5 SQL antes |

**Solución propuesta (3 cambios mínimos en `authenticate_user()`):** leer `role` de la base junto a `session_version`; exigir `user_id` y `session_version` enteros; devolver el rol de la base. Se prepararía como parche versionado sobre el texto literal (base [R], igual que los demás lotes) y se verificaría en copia antes de cualquier ventana. **Requisitos previos:** (i) el archivo real, (ii) la distribución de roles de C-5 SQL, (iii) confirmar con un token real el tipo de `user_id` y (iv) confirmación del responsable de Flutter de que la app maneja un 401 con reinicio de sesión. **No se implementa sin su aprobación.**

## 5. D4 · atestación segura

Paquete `inspeccion-flutter/paquete-d4/` (huella del script en su `SHA256SUMS`): `d4-atestacion-solo-lectura.sh --expiracion-prevista <segundos>`. Solo lectura; no imprime secretos, valores de `.env`, JWT ni contraseñas; compara el secreto **en memoria** con **todos** los literales por defecto de los routers desplegados (el historial git no tiene ninguno, de modo que solo se puede comparar en el servidor). Prueba local `probar-d4.sh`: **17/17** (secreto corto, igual al literal, valor de ejemplo, ausente, expiración distinta o no numérica, modo `observar`, F-10 ausente, `.env` legible por otros; nunca imprime valores; no modifica archivos).

| Comprobación | Estado |
|---|---|
| D4-01 permisos del `.env` | **PENDIENTE** (Infraestructura) |
| D4-02/03/04/05 `JWT_SECRET` definido, longitud, no es valor de ejemplo, variedad | **PENDIENTE** |
| D4-06 distinto de los literales por defecto de los routers (en memoria) | **PENDIENTE**. Además: **9 rutas del legado traen un literal por defecto** (`ReporteRouter` ×8, `UserRouter:172`); recomendado quitarlo (cambio de código) |
| D4-07 `JWT_EXPIRATION` = valor previsto | **PENDIENTE (Infraestructura)**. Valor previsto **decidido: 86400 s (24 h)** (`UX-09B-DECISION-JWT-Y-RIESGO-RESIDUAL.md`). Se invoca con `--expiracion-prevista 86400` |
| D4-08 K1: `POWERBI_AUTH_MODE` | **PENDIENTE**: el script solo puede declarar el valor del código; el modo **efectivo** exige la prueba funcional D4-08b (`GET /api/powerbi/tokens/0` sin Authorization → **410**; con id 0 no se emite token en ningún modo) |
| D4-09 F-10 instalado y `REGISTRO_ABIERTO` no es `true` | **PENDIENTE** |
| Invalidación de sesiones en los componentes pertinentes | **RC1c**: PASS (matriz de sesión + 51/51). **Legado**: INDICATIVO sobre el texto literal; la confirmación real exige el middleware real y un usuario de prueba (escritura: requiere autorización) |

## 6. Estado de los controles heredados

| Control | Estado | Evidencia | Falta |
|---|---|---|---|
| **K2** secretos de empresa | Listo y probado en copia (354/354, 196/196, D3 G6 9 PASS) | Lote 1 | Firmas P-2/P-3, ventana; `EmpresaController` real (caso 8) |
| **K1** token anónimo | Parche listo; en `observar` **no cierra** | D3 G5: `retirar` 410 en 7 métodos × 17 URL × 3 identidades; modos mal escritos fallan cerrado | **Atestación de `retirar` (D4-08/08b)** y C-5 sin consumidores |
| **A2** escrituras | Listo y probado | D3 G1/G2/G3 + R3/R5 con token real (INDICATIVO) | A-L4; casos 1, 2, 3, 5 con archivos reales |
| **A1** perfiles ajenos | Listo y probado | D3 G4 + `UserController` exacto | A-L3; `UserModel` real |
| **A3 / F-10** | Parche listo; **el procedimiento alternativo NO está firmado ni ensayado por el DBA** | `generar-alta.php` 23/23 local | Firma A-ALTA, custodio, ensayo del DBA: **F-10 no se despliega hasta entonces** |
| P5 (rol y contraseña de React) | **Bloqueado**, sin cambios | — | — |

## 7. C-5

Sigue **sin ejecutarse** (esperando A-C5 y A). Aporta lo que necesitan K1 (consumidores de `tokens`), F-10 (consumidores de `register`), la distribución de roles (sección 4) y P4a (esquema, privilegios).

## 8. Veredicto P4b

| Condición de la puerta | Estado | Para pasar a GO |
|---|---|---|
| K2, A2, A1 desplegados y verificados | **No** (preparados) | A-L1, A-L4, A-L3 y ventanas |
| K1 `retirar` **efectivamente atestado** | **No** | Lote 2 + D4-08/08b |
| A3/F-10 con procedimiento alternativo firmado y ensayado | **No** | A-ALTA + ensayo del DBA |
| D3 con archivos reales (8 casos) | **No** (0 de 8 cerrados) | Conjunto mínimo de la sección 2 |
| D4 (`JWT_SECRET`, `JWT_EXPIRATION` previsto) | **No** | Ejecutar el paquete D4 y decidir el valor previsto |
| P4a verificado | **No** | C-5 SQL, firmas B/C, P2/P3 |
| Decisión expresa de P4b (firma D) | **No** | Todo lo anterior |

**P4b = NO-GO.** Ninguna condición bloqueante es de código: todas son firmas, ventanas, evidencia de Infraestructura y del DBA.

## 9. Plan de activación gradual P4c y reversión (solo propuesta)

Nada de esto se ejecuta ni se autoriza por este documento. Cada paso exige su autorización E (usuario y operación exactos).

| Paso | Qué | Criterio de continuar | Reversión |
|---|---|---|---|
| P4c-0 | Respaldo < 24 h verificado; D4 y K1 atestados; vigilancia de C-5 parte 1 activa | Todas las puertas verificadas | No se continúa |
| P4c-1 | Alta del usuario ficticio USR-T | 1 fila + 1 auditoría `user.create`; sin contraseña ni correo en la bitácora | Desactivar USR-T |
| P4c-2 | Edición de datos permitidos de USR-T | 1 auditoría `user.update` | Nueva edición auditada |
| P4c-3 | Desactivación de USR-T | 1 auditoría; sesión cerrada; Flutter sin acceso en su siguiente consulta | Reactivar tras 24 h |
| P4c-4 | Reactivación **≥ 24 h después** de P4c-3 | 1 auditoría; la lógica ya se probó 15/15 con reloj simulado | Desactivar de nuevo |
| P4c-5 | Cohorte 1: **una** alta real, observada una jornada | Sin 5xx; C-5 parte 1 sin anomalías | Desactivar; interruptor |
| P4c-6 | Cohorte 2: hasta 5 altas | idem durante 3 días | idem |
| Parada global | `interruptor-usuarios.sh apagar` (inmediato, no escribe datos) | — | — |

Reversión por componente: lotes del legado → restaurar el archivo del respaldo de su ventana (huella de vuelta a la original); F-10 → `REGISTRO_ABIERTO = true`; K1 → constante de modo; React → interruptor de Usuarios.

## 10. Siguiente acción concreta

1. **Funcional/Infraestructura:** firmar A-C5 y A, y ejecutar **C-5** (solo lectura, ya probado 33/33).
2. **Infraestructura:** ejecutar el **paquete D4** con `--expiracion-prevista 86400` (decidido) y entregar la salida, más el **conjunto mínimo de archivos** de la sección 2 con las huellas.
3. **Yo, al recibirlo:** repetir D3 con los archivos reales (la suite ya admite sustituirlos), cerrar los 8 casos como PASS/FAIL, y, si se aprueba, preparar el parche del middleware sobre el archivo real.
