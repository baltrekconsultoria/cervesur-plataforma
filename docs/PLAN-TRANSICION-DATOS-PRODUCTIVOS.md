# UX-07D.3A · Plan de transición a los datos productivos de Cervesur

Estado: **documento de planificación. No se ejecutó ninguna consulta, migración, copia ni conexión a producción.** Todo lo que aquí se llama «confirmado» proviene del inventario de **solo lectura**
(agregados) que el equipo de Cervesur entregó; lo demás está marcado como **por confirmar** y tiene una consulta de solo lectura preparada para revisión humana.

## 1. Hechos confirmados (inventario agregado de `cervesur_portalbi`)

| Elemento | Dato |
|---|---|
| Usuarios | 76 registrados, 62 activos; roles `usuario` (62; 61 activos), `user` (10; 0 activos), `admin` (4; 1 activo); 61 con acceso efectivo |
| Empresas | 26 registradas, 6 activas |
| Informes | 95 registrados, 82 activos. Los 82 activos tienen `reporte_id` **no UUID** y `grupo_id` **no UUID** (sin vacíos); 0 referencias repetidas entre activos; 57 `reporte_id` > 36 caracteres (56 activos); 3 UUID canónicos, todos inactivos |
| Enlaces | los 82 activos usan el prefijo «Publicar en web» (`https://app.powerbi.com/view?r=`); ninguno con parámetros adicionales detectados (`&`), `#` ni espacios; longitud máx. 151 |
| Longitudes | `reporte_id` máx. 50 · `grupo_id` máx. 26 según el último inventario (36 en el inventario anterior: **por reconciliar** con V3) · `nombre` máx. 45 (ninguno activo > 100) · `enlace` máx. 151 |
| Relaciones | `empresa_usuario` 82/79 activas · `empresa_reporte` 103/100 · `user_empresa_reporte` 102/93 |
| Conciliación | 84 asociaciones empresa–informe vigentes · 82 informes vinculados · 5 empresas con informes · **91 permisos efectivos** · 61 usuarios con acceso · 79 informes con usuarios autorizados · 2 asignaciones activas excluidas por usuarios inactivos |
| Esquema (parcial) | `reporte.id INT UNSIGNED`; `enlace VARCHAR(500) NOT NULL`; `grupo_id VARCHAR(50) NOT NULL`; `reporte_id VARCHAR(50) NOT NULL`; `activo TINYINT(1) NOT NULL`; `user.username VARCHAR(50)`; `user.role VARCHAR(30)`; `user.session_version INT UNSIGNED` |

**Estado de los tres portales** (servidor): Flutter (puerto 80, `cervesur_portalbi`) · React 8088 (`cervesur_react_prueba`) · React 8089 de compatibilidad (`cervesur_react_compat_qa`, datos sintéticos).
El portal 8089 está instalado (`verificar.sh` 27 PASS / 0 FAIL, reportado por el operador) y su **QA manual fue confirmado como aprobado por el usuario en conversación**; ese resultado **no es un ensayo independiente ejecutado por Codex**
(el ensayo propio de Codex es el de Docker, §5 de `ENTORNO-COMPATIBILIDAD-8089.md`; los registros de la instalación real están en su §8).

## 2. Hechos del backend de React que condicionan la transición (verificados en el código)

1. **Autenticación:** `password_verify()` → los hashes de `user.password` deben ser de `password_hash()` de PHP (p. ej. bcrypt `$2y$`). Si Flutter usa otro formato, el login de React falla y **no existe migración de hashes** (tampoco debe haberla sin decisión explícita).
2. **`session_version`:** el login exige un entero ≥ 1 y todo token se contrasta con la base en cada petición. **El cierre de sesión lo incrementa** y el login escribe `last_login`: React **escribe en `user`** incluso en uso de solo lectura.
   Si el backend de Flutter también valida `session_version`, un cierre de sesión en React invalidaría las sesiones de Flutter del mismo usuario (**por confirmar leyendo el código del servidor**).
3. **Sin transacciones ni bloqueos:** el backend no usa `beginTransaction`, `FOR UPDATE` ni `LOCK TABLES`; las escrituras simultáneas desde dos clientes no se protegen entre sí.
4. **Escrituras administrativas de React** (alta/edición de usuarios, empresas, informes; asociaciones; asignaciones; retiros) usan bajas lógicas (`activo`) y registran `created_by`/`updated_by`.
5. **Identidad de informes:** React usa `reporte.id`; `reporte_id` es solo referencia histórica (UX-07D.2B). Las validaciones de escritura rechazan `reporte_id`/`grupo_id` **explícitos** que no sean UUID.
6. **`grupo_id NOT NULL`:** crear un «Publicar en web» nuevo se rechaza con un mensaje claro mientras la columna no admita NULL; editar/leer los 82 informes existentes no se ve afectado. La migración mínima (`VARCHAR(50) NULL`) solo se ha aplicado al **esquema sintético**.
7. **Extensiones PHP:** el backend necesita `pdo_mysql`, `mbstring`, `json` y `openssl` (el QA de 8089 detectó la falta de `mbstring` en la réplica; el instalador ya la exige).

## 3. Matriz de compatibilidad

Leyenda de estado: ✅ compatible (probado en sintético o inventario coincide) · ⚠️ por confirmar (consulta de solo lectura preparada) · ❓ decisión pendiente.

| # | Aspecto | Flutter real (producción) | Sintético UX-07D.2C | Requisito del backend React | Estado | Verificación |
|---|---|---|---|---|---|---|
| 1 | Motor y versión | MySQL 8.0.46 (cliente confirmado; servidor por confirmar) | MySQL 8.0 | MySQL 8.0 | ⚠️ | 3A §1 |
| 2 | `sql_mode` | desconocido | estricto (por defecto de 8.0) | estricto: un valor largo falla, no se trunca | ⚠️ | 3A §1 |
| 3 | `reporte.id` | `INT UNSIGNED` | `INT UNSIGNED` | entero ≤ 4294967295 (ruta numérica) | ✅ | — |
| 4 | `reporte.reporte_id` | `VARCHAR(50) NOT NULL`, no UUID | igual | opaco; no se usa para enrutar ni unir | ✅ | — |
| 5 | `reporte.grupo_id` | `VARCHAR(50) NOT NULL`, no UUID | `NOT NULL` → `NULL` (migración mínima, solo sintético) | admite NULL o valor; «Publicar en web» nuevo no trae grupo | ❓ | decisión (§6) |
| 6 | `reporte.enlace` | `VARCHAR(500) NOT NULL`, máx. 151, `/view?r=` sin parámetros | igual | ≥ longitud real; validador acepta `r` (y `pageName`, `navContentPaneEnabled`, `filterPaneEnabled`, `ctid`) | ✅ (⚠️ parámetros) | 2B §1–2 |
| 7 | `reporte.nombre` / `descripcion` | máx. 45 real; tipo/longitud de columna sin inventariar | `VARCHAR(100)` / `TEXT` | React limita a 100; la columna debe admitirlo | ⚠️ | 3A §4 |
| 8 | `activo` | `TINYINT(1) NOT NULL` | igual | 0/1 (baja lógica) | ✅ | — |
| 9 | `user.username` / `role` / `session_version` | `VARCHAR(50)` / `VARCHAR(30)` / `INT UNSIGNED` | igual | roles `admin`, `user`, `usuario` (otros no inician sesión); `session_version` ≥ 1 | ✅ roles · ⚠️ `session_version` | 3A §10–11 |
| 10 | `user.password` | formato **desconocido** | bcrypt `$2y$`, 60 | hash de `password_hash()` | ⚠️ | 3A §9 |
| 11 | Claves primarias, foráneas, únicas e índices | desconocidos | solo PK, sin FK ni únicas | no depende de FK; la unicidad «ya existe» es lógica (en código) | ⚠️ | 3A §6–7, §13 |
| 12 | Disparadores, rutinas, vistas, eventos | desconocidos | ninguno | asume que no hay efectos laterales al escribir | ⚠️ | 3A §8 |
| 13 | Auditoría `created_by` / `updated_by` | existencia y tipo sin inventariar | `INT UNSIGNED NULL` sin FK | escribe `created_by` (alta) y `updated_by` (edición/baja) | ⚠️ | 3A §3, §12 |
| 14 | `created_at` / `updated_at` | sin inventariar (defaults, `ON UPDATE`) | `DATETIME DEFAULT CURRENT_TIMESTAMP` | el alta de usuarios usa `NOW()`; el resto se apoya en el valor por defecto | ⚠️ | 3A §4 |
| 15 | Juego de caracteres y collation | desconocidos | `utf8mb4_0900_ai_ci` | `utf8mb4` (tildes/ñ); búsquedas insensibles a mayúsculas | ⚠️ | 3A §1, §4 |
| 16 | Columnas de `empresa` (`powerbi_*`, `logo`, `website`, …) | sin inventariar | presentes | el modelo lee/escribe columnas concretas; una ausente rompe alta/edición | ⚠️ | 3A §3 |
| 17 | Columnas que React no conoce | posibles | no aplica | no deben perderse: React no las toca, Flutter sí | ⚠️ | 3A §5 |
| 18 | Estados y relaciones (82/79, 103/100, 102/93) y permisos efectivos (91) | confirmados | reproducidos exactamente | autorización: usuario + empresa + relaciones + asignación + informe activos | ✅ | — |
| 19 | Duplicados activos y huérfanos | 0 referencias duplicadas; resto sin confirmar | 0 | «ya existe» se decide por código | ⚠️ | 3A §12–13 |
| 20 | Privilegios del usuario de aplicación | desconocidos | usuario dedicado: SELECT, INSERT, UPDATE, DELETE | un usuario propio de React con permisos mínimos | ❓ | 3A §14 + decisión |
| 21 | Efecto de cambios de esquema sobre Flutter | desconocido | no aplica | cualquier `ALTER` sobre tablas compartidas afecta a Flutter | ❓ | leer código de Flutter |

**Lo que no sabemos hoy** (y por tanto no se asume): formato de las contraseñas, `sql_mode`, claves/índices/disparadores reales, longitud real de `nombre`, existencia de columnas de auditoría y de `empresa`, collation, privilegios de la aplicación actual y
si el backend de Flutter usa `session_version`/`last_login`. Las consultas de §11 los resuelven sin tocar datos.

## 4. Estrategias de transición

| | **A. React sobre la misma base productiva** | **B. Nueva base operativa con migración y corte** | **C. Lectura controlada primero, escritura después** |
|---|---|---|---|
| Idea | backend React (copia propia) apuntando a `cervesur_portalbi` con un usuario MySQL propio | copiar/migrar a una base nueva, apuntar React (y quizá Flutter) a ella tras un corte | fase de solo lectura sobre la base real; la escritura administrativa se habilita después, por pasos |
| Beneficios | una sola fuente de verdad; sin divergencia; permisos y asignaciones siempre actuales | aísla el riesgo de escribir sobre la base viva; permite limpieza y migración de esquema | detecta incompatibilidades sin riesgo de escritura; reversible apagando un sitio |
| Riesgos | cualquier escritura de React afecta a Flutter en vivo; `ALTER` compartido; dos clientes escriben sin bloqueos | **divergencia**: tras el corte lo que se escriba en un lado no existe en el otro; Flutter queda obsoleto o hay que migrarlo; migración de hashes y formatos | `login` y `logout` escriben (`last_login`, `session_version`) aun en lectura; requiere un modo de lectura real y un usuario con permisos acotados |
| Cambios de esquema | posibles (`grupo_id`); afectan a Flutter | libres, pero hay que reproducir todo lo que Flutter usa | ninguno |
| Impacto en Flutter | alto (escritura compartida) | alto si se repunta; nulo si Flutter sigue en la base vieja (entonces hay dos verdades) | **nulo** (React solo lee) salvo `session_version`/`last_login` (a confirmar) |
| Respaldo | `mysqldump` verificado antes de cada ventana de escritura | respaldo de origen + de destino; base vieja intacta | respaldo previo por precaución; sin escrituras que revertir |
| Reversión | restaurar las tablas tocadas desde respaldo/registro de cambios | volver el apuntador a la base vieja (intacta) | apagar el sitio de React |
| Consistencia de permisos | total (misma base) | solo hasta el corte; luego hay que reconciliar | total (misma base, lectura) |
| Escrituras simultáneas | sin protección en el backend → exigen **un único escritor** (ventanas) | evitadas durante el corte con congelamiento; después, un solo sistema escribe | inexistentes |
| Seguridad | usuario MySQL propio y mínimo; HTTPS; acceso corporativo | además: protección y destrucción de las copias | usuario de solo lectura (+ UPDATE acotado), HTTPS, acceso corporativo |

### Recomendación: **C sobre A, por fases** (descartar B para el MVP)

- **B** introduce el mayor riesgo (divergencia y doble fuente de verdad) y exigiría decidir ya el destino de Flutter; solo se justificaría para retirar Flutter, que no es el objetivo actual.
- **A directo** concentra el riesgo en la primera escritura: React y Flutter modificarían las mismas tablas sin bloqueos y sin haber demostrado la compatibilidad real de esquema, hashes y `session_version`.
- **C** permite validar sin riesgo (lectura), obtiene la evidencia que hoy falta y deja la escritura como una decisión posterior y reversible. Es una **trayectoria hacia A**, no una alternativa distinta.

Fases propuestas (cada una requiere autorización y su GO):

| Fase | Contenido | Datos reales | Salida |
|---|---|---|---|
| **F0** | Verificaciones **de solo lectura** (§11): estructura, formatos, privilegios, backups, código de Flutter | solo metadatos y agregados | informe de compatibilidad real; cierra la matriz |
| **F1** | Entorno aislado con **estructura real y datos anonimizados** (solo si F0 muestra diferencias que exijan probar con datos reales de forma) | anonimizados (§7) | pruebas de compatibilidad con la forma real |
| **F2** | React en **modo lectura** sobre la base real: usuario MySQL propio (SELECT; UPDATE acotado a `last_login` y, si Flutter no lo usa, `session_version`), backend con indicador de «solo lectura» (a implementar y probar) | lectura de producción por React | comparación React vs Flutter: mismos informes por usuario (91 permisos) |
| **F3** | **Escritura administrativa** por pasos y con ventanas: primero asociaciones/asignaciones, luego empresas/informes, al final usuarios; **administración de Flutter congelada** durante la ventana | escritura controlada | cambios auditados (`created_by`/`updated_by`) y reconciliación |
| **F4** | Decisión sobre el futuro de Flutter (convivencia, retiro o convergencia de esquema) | — | decisión de producto |

## 5. Cómo evitar escrituras simultáneas conflictivas

1. **Regla de un único escritor** por ventana: mientras React escribe, la administración de Flutter se congela (acuerdo operativo, no técnico).
2. **Ventanas y respaldo previo verificado** antes de cada tipo de escritura.
3. **Registro de cambios:** `created_by`/`updated_by` y marcas de tiempo permiten listar lo que React tocó en una ventana (y revertir con precisión).
4. **Mejora técnica propuesta (no implementada):** comprobación de modificación concurrente en las ediciones (comparar `updated_at` leído vs actual) y un indicador de solo lectura en el backend.
5. **Conciliación diaria** en F3: los agregados del §1 (84, 91, 61, 79…) deben coincidir con lo esperado tras cada ventana.

## 6. Decisión pendiente: `grupo_id` y «Publicar en web» nuevos

El estado actual del código es seguro: con `NOT NULL` se rechaza con un mensaje claro y no se inventa ningún dato. Opciones: **(a)** relajar `grupo_id` a `NULL` (`VARCHAR(50)` se conserva) — cambia una tabla compartida con Flutter y exige
confirmar que Flutter tolera NULL; **(b)** pedir el grupo explícitamente en el formulario; **(c)** no habilitar la creación de «Publicar en web» nuevos durante F2–F3 (editar y leer los 82 existentes sí funciona). **No se afirma** que la base productiva deba cambiar:
la recomendación es **(c) hasta cerrar F0** y decidir con la evidencia de Flutter.

## 7. Datos personales, copias y entornos de QA

Una copia de datos productivos **no** puede usarse directamente en QA. Si F1 la requiere:

- **Autorización previa y documentada**; propósito, propietario y fecha de eliminación.
- **Solo estructura por defecto.** Si se necesitan datos, se genera un **clon anonimizado dentro de un entorno aislado** (equipo autorizado, sin red hacia fuera) y **nunca** se exporta el volcado original fuera de ese entorno.
- **Transformaciones obligatorias:** usuarios (`username`, correo, teléfono, nombres, avatar → valores ficticios; **contraseñas reemplazadas por hashes de prueba**, jamás copiadas), empresas (nombre, razón social, **RUC**, correo, teléfono, dirección → ficticios; `powerbi_*` → vacíos), informes (nombre y descripción pseudonimizados; **`enlace` → enlace ficticio**; identificadores históricos → sustitutos que conserven longitud y formato), registros de auditoría (autores → ids sustitutos).
  Las relaciones y los estados se conservan para reproducir los 91 permisos efectivos.
- **Verificación:** una consulta de control confirma que no quedan correos, RUC, enlaces `powerbi.com` ni prefijos de hash originales; se registra el resultado.
- **Acceso y credenciales:** cuentas propias del entorno, secretos nuevos, sin reutilizar los de otros entornos; acceso solo por VPN + SSH.
- **Eliminación:** borrado verificable del clon, de los volcados intermedios y de los respaldos temporales, con acta.
- **Los enlaces «Publicar en web» son públicos por naturaleza:** quien conozca la URL los ve sin pasar por el portal. React solo controla quién la recibe; **no puede revocar** el acceso externo. Política recomendada: clasificar los 82 informes por sensibilidad con sus propietarios
  de negocio (sin transcribir URLs); para los sensibles, regenerar el enlace o migrar a incrustación autenticada (fuera de alcance del MVP); documentar la excepción aceptada para el resto.

## 8. Respaldo y recuperación (requisitos)

- Respaldo lógico completo y **probado** de `cervesur_portalbi` antes de F3 (y por ventana), con restauración ensayada en un entorno aislado; registro de fecha, tamaño y suma de verificación.
- Respaldo de la configuración de Apache y de los directorios de los tres portales (ya parte de los instaladores de 8088/8089).
- Procedimiento de recuperación por tabla (las que React escribe: `user`, `empresa`, `reporte`, `empresa_usuario`, `empresa_reporte`, `user_empresa_reporte`) y plan de comunicación.

## 9. Acceso corporativo y HTTPS

Hoy no existe dominio ni certificado: la compilación de producción de React **se niega a funcionar por HTTP fuera de loopback** (protección intencional), por lo que el acceso es solo por túnel SSH. Antes de cualquier uso real hace falta:
nombre interno + certificado de una CA corporativa (o equivalente), HTTP→HTTPS, HSTS, y un mecanismo de acceso corporativo (VPN/segmentación). No se habilita HTTP corporativo para React.

## 10. Controles de GO/NO-GO para el paso productivo

El go-live **no** se declara listo hasta que **todos** estén en «Cumplido» con evidencia.

| ID | Control | Evidencia requerida | Hoy |
|---|---|---|---|
| G1 | Los **95 informes** y sus identificadores (`id`, `reporte_id`, `grupo_id`) se leen y editan sin alterarlos | informe F2/F3 con comparación antes/después | Probado solo en sintético |
| G2 | Conciliación de **26 empresas** (6 activas, 5 con informes) | agregados idénticos en React y en el inventario | Pendiente |
| G3 | Conciliación de **76 usuarios** (62 activos; roles `usuario`/`user`/`admin`) | idem; roles y estados | Pendiente |
| G4 | Preservación de asignaciones: 82/79, 103/100, 102/93, **84 vigentes, 91 efectivas, 61 usuarios, 79 informes** | consultas de conciliación iguales antes y después | Reproducido en sintético |
| G5 | Inicio de sesión de usuarios reales de prueba autorizados (formato de contraseña compatible, `session_version` válido) | login exitoso sin extraer hashes | Pendiente (3A §9–10) |
| G6 | **Revocación efectiva** por API y por URL directa (retiro individual, de relación, de empresa, usuario inactivo) | pruebas repetidas sobre datos reales de prueba | Probado en sintético |
| G7 | Protección de datos personales: minimización, accesos, registros, retención | revisión del responsable de datos | Pendiente |
| G8 | Política de enlaces públicos aprobada (§7) | acta con propietarios de negocio | Pendiente |
| G9 | HTTPS + mecanismo de acceso corporativo (§9) | certificado válido y pruebas | Pendiente |
| G10 | Respaldos verificados y restauración ensayada (§8) | acta de restauración | Pendiente |
| G11 | Procedimiento de recuperación y reversión aprobado | documento + simulacro | Parcial (instaladores 8088/8089) |
| G12 | **Compatibilidad con Flutter demostrada** (lectura y escritura; `session_version`, `last_login`, esquema, validaciones de escritura) | lectura del código del servidor + pruebas con Flutter durante la ventana | Pendiente |
| G13 | Decisión sobre `grupo_id` (§6) | decisión registrada | Pendiente |
| G14 | Usuario MySQL propio de React con permisos mínimos | `SHOW GRANTS` | Pendiente |

## 11. Verificaciones productivas pendientes (todas de solo lectura; requieren autorización y revisión humana antes de ejecutarse)

| ID | Qué | Cómo | Quién |
|---|---|---|---|
| V1 | Inventario base (roles exactos, estados, longitudes, nulos) | `database/schema/UX-07D0-inventario-solo-lectura.sql` y `…-precondiciones-migracion.sql` | operador DBA |
| V2 | Enlaces con parámetros no permitidos, columnas no inventariadas, nombres largos | `database/schema/UX-07D2B-compatibilidad-solo-lectura.sql` | operador DBA |
| V3 | **Comparación estructural completa** (motor, `sql_mode`, tablas, columnas ausentes/extra, índices, FK, disparadores, algoritmo de contraseñas, `session_version`, huérfanos, duplicados, privilegios) | `database/schema/UX-07D3A-comparacion-estructural-solo-lectura.sql` (solo `information_schema` y agregados) | operador DBA |
| V4 | Código del backend que usa Flutter: ¿usa `session_version` y `last_login`? ¿qué diferencias hay con el del repositorio? | comparar hashes de los `.php` (sin `.env`) y `grep` de `session_version`/`last_login` en `/var/www/cervesur/backend` | operador (lectura de archivos) |
| V5 | Endpoints que realmente usa Flutter | agregado de rutas del registro de acceso de Apache (sin IP ni cadenas de consulta) | operador |
| V6 | Privilegios actuales de la cuenta de la aplicación Flutter | `SHOW GRANTS` de esa cuenta (sin contraseñas) | operador DBA |
| V7 | PHP de Apache en el servidor: versión y extensiones (`pdo_mysql`, `mbstring`, `json`, `openssl`) | consulta de módulos; sin `phpinfo` público | operador |
| V8 | Estado de respaldos: último `mysqldump`, tamaño, ubicación y espacio libre; prueba de restauración | revisión de tareas programadas y archivos (nombres, no contenido) | operador DBA |
| V9 | Disponibilidad de nombre interno y certificado corporativo | consulta a TI | TI |
| V10 | Clasificación de sensibilidad de los 82 dashboards publicados | revisión con propietarios de negocio (sin URLs en el documento) | negocio |

## 12. GO / NO-GO para iniciar la etapa de transición de datos

- **GO** para iniciar **F0** (V1–V10, solo lectura), con autorización explícita y revisión humana de cada consulta antes de ejecutarla, y para diseñar el modo de solo lectura del backend (F2) sin tocar producción.
- **NO-GO** para: conectar React a `cervesur_portalbi`, copiar datos reales a cualquier entorno, ejecutar migraciones, habilitar escrituras administrativas o declarar listo el go-live — hasta cumplir los controles G1–G14.
