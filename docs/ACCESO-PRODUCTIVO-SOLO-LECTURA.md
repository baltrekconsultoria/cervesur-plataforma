# UX-07D.3A / 3A.1 · Acceso productivo de solo lectura para React (diseño e implementación local)

Estado: **implementado y probado SOLO en local con datos sintéticos. No se conectó a `cervesur_portalbi`, no se creó ninguna cuenta MySQL real, no se instaló el sitio del puerto 8090 y no se tocó
Flutter, el portal 8088 ni el 8089.** Complementa `PLAN-TRANSICION-DATOS-PRODUCTIVOS.md` (estrategia **C: lectura controlada primero**).
UX-07D.3A.1 endurece la 3A tras su revisión; **este documento refleja el estado vigente** (lo que la 3A afirmaba y la 3A.1 corrigió está señalado).

## 0. Hallazgos de la revisión de la 3A y su corrección (UX-07D.3A.1)

| # | Hallazgo | Corrección | Prueba |
|---|---|---|---|
| 1 | La guardia SQL aceptaba cualquier sentencia que empezara por `WITH` y cualquier `SHOW`. MySQL ejecuta `WITH … UPDATE` y `WITH … DELETE` (comprobado en el motor 8.0.46: **modifican y borran datos**), de modo que la guardia daba una falsa sensación de seguridad. | `WITH` ya no es un verbo admitido ni puede aparecer en ninguna parte (CTE, `WITH ROLLUP`); solo se admiten `SELECT`, `SHOW GRANTS FOR CURRENT_USER()` y `SET SESSION TRANSACTION READ ONLY` exactos. Se rechazan todos los comentarios (`/* */`, `--`, `#`, incluidos los ejecutables `/*!…*/`), cualquier `;` interior, `INTO`, `:=`, `FOR UPDATE/SHARE`, `GET_LOCK`, `SLEEP`, `LOAD_FILE`, `@@global`, caracteres de control o UTF-8 inválido. | `mode_unit.php`: 94 sentencias adversariales + 9 variantes de `WITH` + 27 escrituras clásicas |
| 2 | En diagnóstico, la guardia aceptaba SQL arbitrario de lectura. | **Catálogo cerrado** (`backend/lib/diagnostico_catalogo.php`): la conexión de diagnóstico solo ejecuta esas 31 sentencias (27 medidas, 3 controles, la fijación de solo lectura). Cualquier otra, aunque sea un `SELECT` válido, se rechaza **antes de llegar a MySQL**. El catálogo no puede ampliarse una vez fijado. | `mode_unit.php`, `conexion_solo_lectura_test.php`, estática |
| 3 | La cuenta `cervesur_ro_diag` tenía `id` de `empresa_usuario` y de `user_empresa_reporte`, que ninguna consulta usa. | Lista reducida a las **18 columnas** exactas. Una prueba con MySQL real **quita cada columna una por una** y comprueba que alguna medida falla (necesidad) y que con todas funcionan las 27 (suficiencia). | `privilegios_minimos_test.php` |
| 4 | La ruta con barra final, `HEAD` y otras variantes se normalizaban. Tras el router de diagnóstico, la petición podía llegar a los demás routers. | En diagnóstico la ruta se compara **exacta** y solo por `GET`; lo que no atiende el router de diagnóstico termina en 404 sin pasar por ningún otro router; no se abre sesión PHP. | `mode_unit.php` (33 rutas hostiles), `http_readonly_test.php` (33 más por HTTP) |
| 5 | Un modo ausente equivalía a `read_write`. | `CERVESUR_REQUIRED_MODE=diagnostico` (obligatorio en el sitio 8090): con él, un `CERVESUR_MODE` ausente, vacío, inválido o distinto **impide el arranque**. | 14 configuraciones peligrosas, cada una en un proceso aparte |
| 6 | `DB_NAME` iba al DSN sin validar: `x;dbname=cervesur_portalbi` o un nombre con espacios/mayúsculas podían esquivar la detección de la base productiva. | `DB_NAME` solo admite `[A-Za-z0-9_$-]{1,64}` en todos los modos; además, **tras conectar**, se lee `SELECT DATABASE()`: debe coincidir con `DB_NAME` y, si es productiva, el modo no puede ser `read_write`. | `mode_unit.php` (12 nombres), `conexion_solo_lectura_test.php` |
| 7 | La sesión de solo lectura solo se fijaba con una sentencia de la aplicación. | Se fija además con `MYSQL_ATTR_INIT_COMMAND` en **cada conexión nueva**, y tras conectar se **verifica** `@@session.transaction_read_only = 1`. La prueba lo comprueba **desde otra conexión** (`performance_schema.variables_by_thread`) en tres conexiones independientes. | `conexion_solo_lectura_test.php` |
| 8 | El diagnóstico devolvía recuentos exactos, entre ellos grupos pequeños (4 administradores, 2 asignaciones de inactivos) y cifras de las que otro pequeño se deduce restando (62 activos − 61 con acceso = 1). | Protección de grupos pequeños (ver §2). | `mode_unit.php`, `http_readonly_test.php` |
| 9 | El procedimiento 04 se presentaba como si «0 filas» probara «cero escrituras». | Reescrito: condiciones de validez, límites y qué hacer si fallan (§6). Una prueba **demuestra** que reiniciar los contadores deja la consulta en 0 filas aunque hubo intentos. | `http_readonly_test.php` |
| 10 | El fixture usaba `ON UPDATE CASCADE`; el inventario real dice `NO ACTION`. | Corregido, y se añadieron las claves `created_by/updated_by` (SET NULL) a las tres tablas de relación. Se eliminó toda afirmación de equivalencia exacta con el esquema real (§7). | `http_readonly_test.php` |
| 11 | `mode_unit.php` tenía una comprobación que no podía fallar (precedencia de `??`) y otra vacía (`check(true, …)`). | Reescritas con casos que fallan si se rompen (verificado por mutación: quitar `\bwith\b` o poner SQL propio en el controlador rompe la suite). | mutaciones locales |
| 12 | **Hallazgo nuevo:** cualquier cuenta MySQL puede cambiar su propia contraseña (`ALTER USER CURRENT_USER()`, `SET PASSWORD`) aunque solo tenga `SELECT` por columna (comprobado en 8.0.46). | No se puede impedir con privilegios. Mitigación: el backend nunca lo emite (catálogo cerrado), el procedimiento 04 cuenta `set_password` y `alter_user`, y la clave se rota con `99-revocar-cuenta-diagnostico.sql`. Se documenta como límite conocido y una prueba lo caracteriza. | `privilegios_minimos_test.php` |

## 1. Diagnóstico del código existente (sin cambios respecto de la 3A)

Inventario del backend PHP, cerrado por una prueba estática de los archivos con SQL de escritura:

| Dónde escribe | Qué hace | Endpoint |
|---|---|---|
| `AuthController::updateLastLogin` | `UPDATE user SET last_login` | `POST /api/login` |
| `router/auth.php` | `UPDATE user SET session_version = session_version + 1` | `POST /api/logout` (columna que Flutter podría compartir) |
| `RegisterController` | `INSERT INTO user` | `POST /api/register` |
| `UserModel` | `UPDATE user` (perfil, rol, avatar) | `PUT /api/user/profile[/{id}]`, `POST /api/user/avatar`, `PUT /api/user/{id}/role` |
| `EmpresaModel`, `ReporteModel`, `AsignacionModel`, `EmpresaUsuarioModel`, `EmpresaReporteUsuarioModel` | `INSERT` / `UPDATE` (incluye baja lógica) | `POST/PUT/DELETE` de sus rutas |

No hay DDL, procedimientos, transacciones ni GET con efectos secundarios. Había cinco `SELECT *` (ahora columnas explícitas, impedidos por prueba). Columnas sensibles leídas por el código: `user.password` (solo login), `empresa.powerbi_tenant/client` (ya no se devuelven en modos restringidos), `empresa.powerbi_clientsecret` (solo el endpoint heredado, bloqueado).
La base real tiene claves foráneas con CASCADE y SET NULL: un `DELETE` físico sería en cascada; por eso ninguna cuenta del piloto tiene `DELETE`.

## 2. Modo de operación fail-closed

`CERVESUR_MODE` ∈ `read_write` · `read_only` · `diagnostico`. Sin `CERVESUR_REQUIRED_MODE`, un valor ausente equivale a `read_write` (los entornos de prueba 8088/8089 y Flutter no cambian). **El sitio 8090 se despliega con `CERVESUR_REQUIRED_MODE=diagnostico`**, con lo que cualquier otra cosa impide el arranque (503 genérico, sin tocar la base).

| Capa | Mecanismo | Qué impide |
|---|---|---|
| 0. Arranque | `app_mode_evaluate()` antes de conectar | modo desconocido/ausente con modo exigido, `DB_NAME` con caracteres de inyección, base productiva en `read_write`, token de diagnóstico corto o igual al JWT, cuenta con escritura sobre base productiva |
| 1. Puerta de rutas | `app_mode_route_gate()` antes de cualquier controlador | todo método de escritura, el endpoint heredado y, en diagnóstico, todo salvo `GET` de tres rutas exactas |
| 2. Guardia de SQL | `app_sql_guard()` | todo lo que no sea un `SELECT` (o dos sentencias de control exactas) sin comentarios, `WITH`, `;`, `INTO`… |
| 2b. Catálogo cerrado (solo diagnóstico) | `GuardedPDO::useCatalog()` | cualquier SQL fuera de las 31 sentencias del catálogo |
| 3. Sesión de solo lectura | `INIT_COMMAND` + `SET SESSION TRANSACTION READ ONLY` + verificación | MySQL rechaza (1792) toda escritura **incluso con una cuenta con todos los privilegios** |
| 4. Auditoría de la cuenta | `SHOW GRANTS FOR CURRENT_USER()` al arrancar | cualquier privilegio distinto de `SELECT`/`USAGE` (o `GRANT OPTION`, o concesión no auditable) ⇒ 503 |
| 5. La propia cuenta | permisos `SELECT` por columna | leer columnas no concedidas y toda escritura/DDL |

Las capas 2 y 2b son **léxicas**: ayudan, pero la garantía real son las capas 3 a 5 (MySQL), que no dependen de analizar texto.
Efectos de sesión: en `read_only` el login **no actualiza `last_login`** y el logout **no modifica `session_version`** (responde `revoked_server_side:false`; el JWT debe ser corto). `diagnostico` no admite login ni lee hashes.

### Protección frente a grupos pequeños (diagnóstico)
- Un recuento de **1 a 4** nunca se publica (`APP_DIAG_MIN_CELL = 5`; el 0 sí, porque no identifica a nadie).
- Las magnitudes de grupos pequeños por naturaleza (**roles, anomalías, asignaciones de inactivos**) son `solo_coincidencia`: se informa únicamente `ok: true/false` frente al inventario, nunca el valor.
- Si la diferencia entre un conjunto y su subconjunto declarado (13 parejas, p. ej. usuarios activos − usuarios con acceso efectivo) es un grupo de 1 a 4, **se reservan ambos números**, de modo que no se deduce restando.
- **Límite declarado:** la protección cubre las parejas del catálogo, no cualquier combinación de cifras externas; el token y el túnel acotan quién consulta.

## 3. Autenticación del operador técnico (sin hashes de usuarios)
- El diagnóstico se autentica con **`X-Diagnostic-Token`**, un secreto de 32+ caracteres **distinto de `JWT_SECRET`** (el arranque lo exige), comparado con `hash_equals`. No hay usuarios, ni cookie, ni sesión PHP, ni JWT.
- El token solo viaja por cabecera (nunca en la URL), cada fallo cuesta 300 ms y se registra «token rechazado» (sin el valor) en el log interno. Se entrega al operador por un canal aparte y vive en el `.env` del sitio 8090 (0600).
- La cuenta de diagnóstico **no puede leer `user.password`**: el diagnóstico no necesita ningún hash. Un login real (modo `read_only`) queda fuera de esta etapa.

## 4. Matriz definitiva del modo `diagnostico`

| Petición | Resultado |
|---|---|
| `GET /api/ping` | 200 `{"pong":true}` |
| `GET /api/diagnostico/resumen` (con token) | 200: controles + medidas publicables + nombres de las reservadas |
| `GET /api/diagnostico/conciliacion` (con token) | 200: `coincide`, `desviaciones`, `ok` por métrica; valores solo de las publicables |
| Los dos anteriores sin token / con token incorrecto / con un JWT | 401 |
| `POST /api/login`, `/api/register`, `/api/logout` | 403 `diagnostico` |
| `GET /api/user*`, `/api/users`, `/api/empresas*`, `/api/reportes*` (incluido `/id/{id}/visor`, `/grupoid/…`), `/api/asignacion*`, `/api/empresa-usuario*`, `/api/empresa-reporte-usuario*`, `/api/stats`, `/api/wiki` | 403 |
| Cualquier `POST/PUT/PATCH/DELETE`, `HEAD`, `OPTIONS` | 403 |
| `GET /api/powerbi/*` (URLs y tokens de Power BI, endpoint heredado) | 403 `legacy_blocked` |
| `/api/ping/`, `/api/diagnostico/resumen/`, `//api/ping`, mayúsculas, `..`, `%2f`, `%00`, ruta vacía | 403 (la ruta se compara exacta) |
| Cualquier otra ruta | 403 por la puerta; si alguna llegara, 404 sin pasar por otros routers |

Para `read_only` (login autenticado, futuro) rige la matriz de la 3A: lecturas autorizadas por empresa e informe, escrituras 403 `read_only`, endpoint heredado bloqueado; la UI aún muestra botones de escritura (el servidor responde 403; ocultarlos según el modo es UX-07D.3B). **Los botones ocultos no son un control: la barrera es el servidor.**

## 5. Mínimo privilegio de MySQL

### `cervesur_ro_diag` — exactamente 18 columnas (probadas necesarias y suficientes)
| Tabla | Columnas con `SELECT` | Para qué |
|---|---|---|
| `user` | `id`, `role`, `is_active`, `session_version` | recuentos por rol/estado, `session_version` válida, unión |
| `empresa` | `id`, `activo` | recuentos y unión |
| `reporte` | `id`, `activo` | recuentos y unión |
| `empresa_usuario` | `empresa_id`, `user_id`, `activo` | unión empresa–usuario |
| `empresa_reporte` | `id`, `empresa_id`, `reporte_id` (**id numérico**), `activo` | relación empresa–informe |
| `user_empresa_reporte` | `user_id`, `empresa_reporte_id`, `activo` | permisos efectivos |

Sin acceso a `user.password`, correos, teléfonos, nombres, `reporte.enlace`, `reporte.reporte_id`/`grupo_id`, `empresa.powerbi_*`, ni a ninguna otra columna o base. Sin `INSERT/UPDATE/DELETE/DDL/EXECUTE/GRANT OPTION/FILE/PROCESS`; `MAX_USER_CONNECTIONS 5`.
`cervesur_ro_pilot` (futuro, **no crear hasta aprobar HTTPS, datos y autenticación**) añade las columnas del backend, incluidas `user.password` y `reporte.enlace`, y nunca `empresa.powerbi_*`.
Se usan permisos por columna (no vistas) porque las vistas exigirían DDL en producción.

### Separación de SQL
| Quién lo ejecuta | Archivos | Cuenta |
|---|---|---|
| **Operador autorizado** (previo al piloto) | `UX-07D3A-preflight-produccion-solo-lectura.sql`, `UX-07D3A-comparacion-estructural-solo-lectura.sql` | la suya; lee `user.password` solo para **contar** formatos |
| **Administrador, con autorización explícita** | `admin-requiere-autorizacion/01` (crear cuenta de diagnóstico), `03` (piloto), `99` (revocar) | administración MySQL |
| **Operador autorizado** (verificación) | `admin-requiere-autorizacion/02` (privilegios), `04` (actividad) | la suya; **nunca** la cuenta de diagnóstico (no puede leer `mysql.*` ni `performance_schema`) |
| **La cuenta de diagnóstico, desde el backend** | `UX-07D3A1-consultas-cuenta-diagnostica.sql` (generado del catálogo; una prueba comprueba que es exactamente el catálogo) | `cervesur_ro_diag` |

## 6. Auditoría de escrituras con `performance_schema` — qué prueba y qué no

`04-verificar-ausencia-de-escrituras.sql` es **evidencia, no una prueba**. Documentado y comprobado en las pruebas:
- **Instrumentación requerida:** `performance_schema = ON`, instrumentos `statement/%` habilitados, consumidor `thread_instrumentation` y `setup_actors` que incluya a la cuenta (consulta 1).
- **Qué se observa:** `events_statements_summary_by_user_by_event_name` agrega por **nombre de cuenta** (`cervesur_ro_diag`, `cervesur_ro_pilot`) desde cualquier host. No ve a otras cuentas (p. ej. Flutter) ni atribuye cambios en tablas.
- **Limitaciones de los contadores:** se reinician con el arranque de MySQL y con `TRUNCATE`; no cubren el pasado; una cuenta recreada empieza de cero; las rutinas almacenadas salen como `statement/sp/*`.
- **Condiciones que impiden interpretar un resultado vacío:** performance_schema desactivado, instrumentos o consumidores deshabilitados, cuenta fuera de `setup_actors`, contadores reiniciados tras el uso, cuenta recreada, uso desde otro nombre de cuenta. **Una prueba demuestra el caso:** tras `TRUNCATE` de los contadores, la consulta de escrituras devuelve 0 filas aunque hubo intentos.
- **Intentos rechazados:** una escritura denegada por permisos **sí** incrementa el contador de su tipo y `SUM_ERRORS` (la prueba lo verifica), de modo que `veces = con_error` indica intentos fallidos y `con_error < veces` indicaría una escritura lograda.
- **Privilegios efectivos** (lo que sí vale como evidencia fuerte): `SHOW GRANTS` de ambas cuentas, `information_schema.*_PRIVILEGES`, `mysql.role_edges`, cuentas homónimas/anónimas y `mysql.proxies_priv` (consultas 5 del 04 y archivo 02), más los controles de `/api/diagnostico/resumen`.
- **Regla:** no se declara «cero escrituras» porque la consulta 3 devuelva 0 filas; se declara cuando (a) las condiciones de validez se cumplen, (b) los privilegios efectivos no incluyen ninguna escritura y (c) los contadores no muestran escrituras ni `alter_user/set_password` (§0 #12).

## 6b. Correcciones de la revisión del SQL administrativo (UX-07D.3B.1)
- **Clave en comentarios:** el generador sustituía el marcador de la clave también dentro de comentarios. Ahora la sustituye solo en líneas que no son comentario, comprueba que la clave aparece **exactamente una vez** (en `CREATE USER`), que ningún comentario la contiene y que no quedan marcadores; si algo falla borra los archivos y aborta. Para revisar el SQL se usa `admin-mysql/mostrar-sql-redactado.sh` (clave como `********`; se niega a imprimir si no puede redactar). No usar `cat`/`less`/`sed` sobre el `.sql`.
- **Cuántas columnas (17 o 18):** son **18 = 4+2+2+3+4+3**. `user_empresa_reporte` lleva **3** (`user_id`, `empresa_reporte_id`, `activo`), no 2. Se auditó contra `diagnostico_catalogo.php`: las 18 son necesarias (la prueba con MySQL real quita cada una y alguna medida falla) y ninguna sobra; no se amplió ni se redujo ningún privilegio. Ahora la verificación 02 imprime el recuento por tabla y el total.
- **Reversión acotada:** `99-revocar-cuenta-diagnostico.sql` contiene una sola sentencia, `DROP USER IF EXISTS 'cervesur_ro_diag'@'127.0.0.1'`, sin marcadores. La del piloto es otro archivo (`99-revocar-cuenta-piloto.sql`) y no viaja en el paquete de diagnóstico.
- **Verificación sin piloto:** `02-verificar-privilegios-diagnostico.sql` solo toca la cuenta de diagnóstico (no falla si el piloto no existe); la del piloto es `02b-…`. El procedimiento 04 tampoco ejecuta ya `SHOW GRANTS` del piloto.

## 7. Fixture y claves foráneas — lo que está y lo que no está respaldado

Contrastado con el inventario real ya entregado (no se consultó producción):
| Afirmación | Fixture | Respaldo |
|---|---|---|
| `empresa_reporte` → `empresa`/`reporte`: `ON DELETE CASCADE` | sí | inventario |
| `empresa_usuario` → `empresa`/`user`: `CASCADE` | sí | inventario |
| `user_empresa_reporte` → `empresa_reporte`/`user`: `CASCADE` | sí | inventario |
| `created_by`/`updated_by`: `ON DELETE SET NULL` | sí, en las 5 tablas que tienen esas columnas | el inventario dice «campos created_by y updated_by»; **qué tablas exactas las llevan no está confirmado** |
| Reglas de actualización: `NO ACTION` | sí (antes era `CASCADE`: corregido) | inventario |
| Claves primarias | `id` en las seis tablas | **no contrastado** con el real |
| Restricciones `UNIQUE` | **ninguna** (el fixture solo tiene PK) | **el inventario entregado no las describe**; no se afirma nada |
| Nombres de restricciones, índices, disparadores | nombres propios del fixture | sin respaldo |

Por tanto el fixture sirve para demostrar que **ninguna ruta del modo de solo lectura dispara un borrado en cascada**, pero **no prueba equivalencia exacta con el esquema real**. Eso lo comprueba, con un operador autorizado, la sección 7 de `UX-07D3A-comparacion-estructural-solo-lectura.sql`; hasta entonces las UNIQUE/PK/índices reales son un **riesgo abierto**.

## 8. Resultados de las pruebas (todas locales, datos sintéticos)

| Suite | Resultado |
|---|---|
| `mode_unit.php` (sin red ni BD) | **49** comprobaciones (config, modo exigido, 12 nombres de base hostiles, 33 rutas de diagnóstico denegadas, **94 sentencias adversariales**, 9 variantes `WITH`, 27 escrituras clásicas, catálogo, grupos pequeños) |
| Estáticas (`test_security_static.py`) | **36** (31 previas, 5 nuevas) + mutaciones verificadas |
| `http_readonly_test.php` | **56** (config inválida ⇒ 503; 23 escrituras HTTP ⇒ 403 con tablas idénticas bit a bit; diagnóstico: agregados, reservadas, 33 endpoints denegados, login ⇒ 403; 04 con límites) |
| `privilegios_minimos_test.php` | **19** (18 columnas exactas; suficiencia; **necesidad una a una**; 32 lecturas sensibles denegadas; 96 intentos de escritura/DDL/GRANT/etc. denegados; otras bases, `mysql`, `performance_schema`, `sys`; **premisa `WITH … UPDATE/DELETE` demostrada en el motor** y bloqueada por sesión y cuenta; límite de contraseña propia) |
| `conexion_solo_lectura_test.php` | **34** (3 conexiones nuevas en `ON` vistas desde otra conexión; 13 sentencias fuera del catálogo rechazadas antes de MySQL; 14 arranques peligrosos rechazados; comprobaciones posteriores a la conexión) |
| Suites PHP existentes | 50 · 228 · 45 · 36 · 67 · 56 · 54 · `security_unit` 73 |
| Lint PHP / frontend | limpio · ESLint y `tsc` limpios · 207 unitarias · 85 E2E simuladas |
| Contratos React–PHP / E2E real | **206 PASS / 0 FAIL / 4 hallazgos conocidos** · **185 / 0** |
| Docker 8089 / 8088 | **112/112** · **67/67** (Flutter y portales intactos) |
| Apache | 40/40 · escaneo de secretos: solo 2 SHA-256 públicos de paquetes |

## 9. Riesgos pendientes
1. **Estructura real sin contrastar** (UNIQUE, PK, índices, disparadores, nombres de FK, `sql_mode`): el preflight y la comparación estructural deben ejecutarse primero. Si la versión de MySQL no expone `@@session.transaction_read_only` (anterior a 5.7.20) el sitio **no arranca** (falla cerrado, pero inutilizable); la versión real no se conoce.
2. **Cuenta con contraseña propia modificable** (§0 #12): mitigación operativa con `ACCOUNT LOCK`/rotación.
3. **Protección de grupos pequeños limitada** a las parejas del catálogo.
4. **Token de diagnóstico estático**: viaja por el túnel SSH (cifrado) pero no hay protección contra su reutilización; mitigable con rotación y túnel exclusivo. No hay límite real de peticiones más allá del retardo por fallo.
5. **Procedimientos/funciones almacenados en la base real** no se conocen: la guardia y la sesión de solo lectura los neutralizan, pero conviene listarlos en el preflight.
6. **`performance_schema` en producción** puede estar desactivado: entonces 04 no aporta evidencia y solo valen los privilegios efectivos.
7. **Flutter y `session_version`/`last_login`:** irrelevante para diagnóstico; decisivo antes de habilitar login (`read_only`).
8. **HTTPS y login real** siguen fuera de alcance; la UI aún muestra botones de escritura en `read_only` (UX-07D.3B).
9. El QA manual del 8089 fue confirmado verbalmente por la persona responsable; **no se presenta como una ejecución independiente**.

## 10. Arquitectura propuesta del sitio 8090 (NO instalado)
`127.0.0.1:8090` (solo loopback), túnel `localhost:18091`; carpetas y `.env` propios; sitio Apache `cervesur-react-ro` con el patrón del 8089 (recarga suave, sin habilitar módulos); sin cookie ni sesión en este modo; `CERVESUR_DIAG_TOKEN` propio (≠ otros sitios); `CERVESUR_REQUIRED_MODE=diagnostico`, `CERVESUR_MODE=diagnostico`, `DB_NAME=cervesur_portalbi` con la cuenta `cervesur_ro_diag`; **sin interfaz**, solo los dos endpoints por `curl`/CLI; ninguna exposición en la red corporativa. El instalador **no se ha escrito**.

## 11. Recomendación GO / NO-GO para preparar el instalador 8090
**GO a preparar el instalador (solo preparar; no instalar ni ejecutar nada contra producción)**, porque la capa de código y de cuenta ya está endurecida y probada con MySQL real. Condiciones **antes** de cualquier ejecución real:
1. Revisión humana de `01-cuenta-diagnostico.sql` y del catálogo (`UX-07D3A1-consultas-cuenta-diagnostica.sql`).
2. Preflight sin desviaciones y comparación estructural revisada (cierra el riesgo 1).
3. Autorización explícita para crear **solo** `cervesur_ro_diag`; verificación con 02 y 04; plan de rotación/bloqueo.
4. El instalador reutiliza las salvaguardas del 8089 (colisiones, línea base, reversión) y se ensaya en Docker con Flutter/8088/8089 presentes.

**NO-GO** para: login real, devolver registros personales/enlaces/hashes al navegador, cualquier cuenta con escritura, `read_write` sobre `cervesur_portalbi`, copiar datos productivos, hasta tener HTTPS, tratamiento de datos aprobado y decisión sobre autenticación.
