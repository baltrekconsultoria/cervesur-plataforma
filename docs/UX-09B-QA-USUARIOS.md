# UX-09B · QA funcional de Administración de Usuarios (RC1c `2f88cfb`)

Estado: **matriz preparada. Las columnas L ya tienen evidencia local; R y W no se han ejecutado. Ninguna escritura productiva está autorizada por este documento.** No duplica los ensayos existentes: cita la evidencia de `paquetes-qa/produccion-fase2b-rc1c/ENSAYO-DOCKER-2f88cfb.log` (235 comprobaciones, mismas descripciones) y de las suites del repositorio.

## 1. Capas, cuentas y reglas

| Capa | Qué es | ¿Autorizada? |
|---|---|---|
| **L** · local aislado | Docker/réplica con datos sintéticos; sin tocar producción | Sí |
| **R** · lectura productiva | Solo `GET` y sondeos que el servidor rechaza **antes** del controlador (id inexistente, cuerpo vacío): no escriben datos de negocio ni de auditoría | Solo cuando exista su etapa (P3/P4) **y** autorización específica de lectura |
| **W** · escritura productiva | Altas, ediciones, desactivaciones reales | **NO autorizada.** Cada operación exige autorización propia (P4c) |

| Cuenta | Quién | Uso |
|---|---|---|
| **ADM-T** | Administrador nominal autorizado por el funcional | Inicio de sesión administrativo y operaciones permitidas |
| **NOR-T** | Cuenta normal de prueba existente, designada por el funcional | Pruebas de rechazo (rol `user`) |
| **USR-T** | Usuario de prueba **ficticio** (nombre y correo de prueba), creado en P4c-1 | Sujeto de edición, desactivación y reactivación |
| **SIN** | Petición sin sesión | Rechazo 401 |

## 2. Qué corresponde a P4b y qué a P4c

| Etapa | Qué habilita o comprueba | Operaciones de Usuarios |
|---|---|---|
| **P4a** | Privilegios y vista de alta en la base; **no** cambia la capacidad efectiva (Usuarios sigue 403) | Ninguna |
| **P4b** | Interruptor `ADMIN_WRITES_USUARIOS=1`: capacidades `usuarios=true`, `sensible=false`, `reactivate_after_hours=24`. **No escribe datos** | Habilita (sin ejecutar) alta, edición, desactivar y reactivar; los botones aparecen. Sensibles (rol, contraseña) siguen en 403 |
| **P4c-1** | Primera escritura real: **alta** de USR-T | Alta (1 fila + 1 auditoría `user.create`) |
| **P4c-2** | **Edición** de datos permitidos de USR-T | Edición (1 auditoría `user.update`) |
| **P4c-3** | **Desactivación** de USR-T | Desactivar (1 auditoría `user.deactivate`; sesiones cerradas) |
| **P4c-4** | **Reactivación** de USR-T, **≥ 24 h después** de P4c-3 | Reactivar (1 auditoría) |
| Ya disponibles sin P4b | Listado, búsqueda, filtros, detalle y consulta de accesos (lecturas de la Fase 1/2A) | Lecturas |
| **P5 (bloqueado)** | Cambio de rol, cambio de contraseña | **No incluidos.** El servidor los rechaza aunque se encienda P4b |

P4c-1 a P4c-4 son una **propuesta de secuencia**: cada operación es independiente y requiere la autorización E del funcional (usuario y operación exactos). Nada de esto se ejecuta sin esa autorización.

## 3. Matriz de pruebas

Columnas: **Capa** donde se prueba · **Resultado esperado** · **Evidencia** · **Reversión**. Los números `L:` remiten a las descripciones del ensayo Docker 2B.

### 3.1 Acceso, listado y consulta

| ID | Capa | Actor | Operación | Resultado esperado | Evidencia | Reversión |
|---|---|---|---|---|---|---|
| U01 | L · R | SIN | `GET /api/users` | 401 | L: suites HTTP; R: salida del sondeo | — |
| U02 | L · R | NOR-T | `GET /api/users` | 403 (puerta de rutas; el rol sale de la base) | idem | — |
| U03 | L · R | ADM-T | Inicio de sesión administrativo | 200 y sesión de administrador | R: `verificar-posdespliegue --etapa accesos` | Cerrar sesión |
| U04 | L · R | ADM-T | Listar, buscar y filtrar (texto, rol, estado) | 200 paginado; **sin** contraseña, hash ni `session_version` | L: vitest `adminUsers`/`adminPaging` | — |
| U05 | R | ADM-T | `GET /api/users/capabilities` | Antes de P4b `usuarios=false`; después `usuarios=true`, `sensible=false` | L: «P4b · capacidades efectivas…» | — |
| U06 | L · R | ADM-T | `GET /api/users/{id}` | Detalle con versión y **sin** campo de contraseña | L: «P4c · detalle con versión y sin campo de contraseña» | — |
| U07 | L · R | ADM-T / NOR-T | `GET /api/users/{id}/access` | ADM-T: accesos vigentes del usuario; NOR-T: 403 | L: vitest `adminUsers` (confirmar el caso concreto en la corrida) | — |

### 3.2 Rechazos (el servidor decide; ocultar botones no cuenta)

| ID | Capa | Actor | Operación | Resultado esperado | Evidencia | Reversión |
|---|---|---|---|---|---|---|
| U08 | L · R | ADM-T | Con Usuarios **apagado**: `POST /api/register`, `PUT /api/users/{id}`, `POST …/deactivate` y `…/reactivate` (id inexistente, cuerpo vacío) | 403 `fase_2b_bloqueada` **antes** del controlador | L: «P3 · las 6 rutas de usuarios responden 403» | — |
| U09 | L · R | NOR-T | Las mismas rutas con Usuarios **encendido** | 403 (rol) | L: suites HTTP; R: sondeo con id inexistente | — |
| U10 | L · R | ADM-T | `PUT /api/users/{id}/password` y `PUT /api/user/{id}/role` **con P4b encendido** | 403 `escritura_desactivada`; ningún rol ni contraseña cambia | L: «P4b · DENEGACIÓN de lo sensible» | — |
| U11 | L | ADM-T | Interfaz: sin capacidad no hay botones de edición/estado; con P4b no aparece «Cambiar rol» | Botones ausentes **y** (U08–U10) el servidor rechaza igual | L: vitest `adminReadOnlyUi`, `adminUsers` | — |
| U12 | L | ADM-T | Alta con `role` en el cuerpo | 400 y no se crea nada | L: «P4c · ALTA con “role” en el cuerpo: 400» | — |
| U13 | L · R | — | Escalamiento por SQL directo con la cuenta de React (rol admin por la vista, `INSERT` directo, `UPDATE` de correo, rol o contraseña) | **El motor** lo rechaza | L: «P4a · ESCALAMIENTO…»; R: `privilegios-usuarios.sh --verificar` PASS tras P4a | — |

### 3.3 Operaciones permitidas (capa W solo con autorización P4c)

| ID | Capa | Actor | Operación | Resultado esperado | Evidencia | Reversión |
|---|---|---|---|---|---|---|
| U14 | L · **W (P4c-1)** | ADM-T | **Alta** de USR-T | 200 con id; rol `user`, activo, `session_version` 1; contraseña solo como hash; **exactamente 1** auditoría `user.create` sin contraseña, hash, correo ni teléfono | L: «P4c · ALTA…» (2 comprobaciones) | Desactivar USR-T (no se borra) |
| U15 | L · W | ADM-T | Alta repetida (reintento) | 409; una fila; una auditoría | L: «ALTA repetida: 409» | — |
| U16 | L · **W (P4c-2)** | ADM-T | **Edición** de nombre/teléfono de USR-T | 200; `role`, `username`, `is_active` y `password` del cuerpo se ignoran; **1** auditoría `user.update` | L: «P4c · EDICIÓN…» | Nueva edición auditada |
| U17 | L · W | ADM-T | Cambiar el **correo** | 400 con mensaje claro; sin tocar fila ni auditoría; reenviar el mismo correo no es un cambio | L: «P4c · CORREO (UX-08I)» | — |
| U18 | L | ADM-T | Conflicto de versión (otra aplicación escribió entre lectura y guardado) | 409; nada cambia; sin auditoría extra | L: «CONFLICTO 409» | — |
| U19 | L · **W (P4c-3)** | ADM-T | **Desactivar** a USR-T | 200; `is_active=0`, `session_version` +1 (cierra sesiones); **1** auditoría `user.deactivate` | L: «P4c · DESACTIVAR…» | Reactivar tras 24 h |
| U20 | L · W | ADM-T | Desactivar de nuevo; reactivar antes de 24 h | 409 sin cambio ni auditoría | L: «desactivar de nuevo: 409…» | — |
| U21 | L | ADM-T | Desactivarse a sí mismo; dejar sin el último administrador; dos administradores a la vez | 409; no queda sin administrador; exactamente uno cae en la carrera | L: «el administrador NO puede desactivarse a sí mismo (409)»; suite `p4a_concurrencia` | — |
| U22 | L · **W (P4c-4)** | ADM-T | **Reactivar** USR-T ≥ 24 h después | 200; **1** auditoría `user.reactivate`; `session_version` +1 | L: `tests/isolated/probar-reactivacion-reloj.sh` (MySQL 8 real, reloj simulado insertando filas fechadas hacia atrás en `audit_log`): **15/15** (23 h y 23 h 59 min rechazan con 409; 24 h 1 s y 25 h aceptan; cuenta la última desactivación; sin historial acepta; `ADMIN_USER_REACTIVATE_HOURS` 0 y 48; una sola auditoría). Limitación: prueba el modelo, no el HTTP completo | Desactivar de nuevo |

### 3.4 Auditoría, persistencia y no regresión

| ID | Capa | Actor | Operación | Resultado esperado | Evidencia | Reversión |
|---|---|---|---|---|---|---|
| U23 | L | — | Si la auditoría falla, edición y alta | 5xx genérico y **ningún** cambio (atomicidad) | L: «P4c · ATOMICIDAD…» | — |
| U24 | L · R | — | `audit_log` solo inserción | `UPDATE`/`DELETE` rechazados; 2 triggers presentes | L: suite `audit_immutability_test`; R: C-5 SQL §9 | — |
| U25 | L · W | ADM-T | Persistencia | El dato creado o editado sigue tras recargar y tras reiniciar el servicio; la lista lo muestra | L: ensayo P4c + recuperación; W: tras cada P4c | — |
| U26 | L · R | ADM-T | Empresas, Reportes y Accesos siguen respondiendo | `verificar-posdespliegue --etapa accesos` PASS; sin 503 | L: «P4a → NO HAY 503…»; R: el verificador no escribe datos de negocio ni de auditoría | — |
| U27 | L | — | Alcance de la desactivación | Cierra la sesión de React y deja a Flutter sin acceso en su **siguiente consulta autenticada**; no es revocación inmediata en todos los sistemas | Texto del diálogo y diagnóstico §8 | — |

## 4. Criterio de aceptación de «administración básica de Usuarios finalizada»

1. Todas las pruebas **L** pasan en el RC1c **empaquetado** (repetición del ensayo Docker con el paquete de huella `52418fee…a703e6`).
2. Las pruebas **R** pasan tras P3 (puertas de rutas con 403) y tras P4a/P4b (capacidades).
3. **U14 (alta) pasa en producción en P4c-1**: es la prueba que demuestra que la creación de usuarios de React funciona de verdad. Hasta entonces la evidencia es sintética (ensayo).
4. U16, U19 y U22 pasan en P4c-2, P4c-3 y P4c-4.
5. Cada escritura deja exactamente una fila de auditoría y ninguna contraseña, hash, correo ni teléfono en la bitácora.

## 5. Evidencia y custodia

Cada ejecución guarda su salida (sin credenciales ni tokens) en una carpeta de permisos 700 con fecha, persona y huella del paquete. La columna **Reversión** nunca borra filas: desactiva, y el DBA conserva el respaldo de P2 de menos de 24 h para cualquier recuperación excepcional.
