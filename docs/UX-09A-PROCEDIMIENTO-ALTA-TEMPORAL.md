# UX-09A · Procedimiento temporal de altas de usuarios (puente entre F-10 y P4c)

Estado: **v2, con la decisión sobre el ejecutor. Propuesta para aprobación del DBA y del responsable funcional. No se ejecutó nada.** Condición de la decisión sobre F-10: no se cierra el registro público sin una alternativa segura para las altas legítimas. Es un **puente**, no una función nueva: caduca cuando el alta de React (P4c) esté verificada.

**Decisión aprobada:** el **DBA de Cervesur es el único ejecutor SQL**. **No se crean cuentas nuevas con acceso de escritura a la base de datos** para este procedimiento. El DBA recibe únicamente el SQL con el hash bcrypt y **no conoce la contraseña**; quien conoce la contraseña (el custodio) **no tiene acceso a la base**.

## 1. Alcance

- **Sí:** crear cuentas nuevas de rol `usuario` por solicitud autorizada, mientras `POST /api/register` del backend heredado esté cerrado.
- **No:** cambiar roles ni contraseñas de cuentas existentes (P5 sigue bloqueado), crear administradores, ni usarlo para algo distinto de un alta.
- **Modelo coherente con RC1c:** en el alta de React (2B) también hay una contraseña inicial definida por el administrador y solo se guarda su hash. El procedimiento produce el mismo tipo de cuenta que `RegisterController` (mismo hash `PASSWORD_DEFAULT`, mismo rol `usuario`).

## 2. Roles y matriz de responsabilidades

| Rol | Quién | Tiene | **No** tiene |
|---|---|---|---|
| **Solicitante** | Área de Cervesur que requiere el usuario | La necesidad y los datos del usuario | Acceso a la base ni a la contraseña |
| **Autorizador** | Responsable funcional designado | La decisión de cada alta | Acceso a la base |
| **Custodio** | Persona designada y autorizada, **sin permisos de base de datos** | El generador, la contraseña temporal (solo en pantalla, una vez) y el SQL con hash | Acceso a MySQL; no ejecuta SQL |
| **Ejecutor SQL** | **DBA de Cervesur** (único), cuenta nominal | El SQL con el hash y los permisos de escritura | **La contraseña** |
| **Entrega** | El custodio autorizado, por canal seguro | La contraseña, hasta entregarla | — |
| **Verificador** | Responsable funcional **distinto del ejecutor** (puede ser el autorizador) | La salida del DBA y el registro de altas | Acceso a la contraseña |

**Separación de funciones:** nadie reúne contraseña y escritura en la base. El custodio no puede ser el DBA; el verificador no puede ser el ejecutor. Antes de activar el procedimiento, el DBA confirma por escrito que el custodio designado no tiene cuenta de base de datos con privilegios.

| Actividad | Solicitante | Autorizador | Custodio | DBA | Verificador |
|---|---|---|---|---|---|
| Pedir el alta (datos y justificación) | **R** | I | — | — | — |
| Aprobar la solicitud | I | **R/A** | — | — | — |
| Generar contraseña y SQL (`generar-alta.php`) | — | I | **R/A** | — | — |
| Registrar la huella del SQL | — | — | **R** | I | — |
| Enviar el SQL (sin contraseña) al DBA | — | — | **R** | I | — |
| Revisar y ejecutar el SQL | — | I | — | **R/A** | I |
| Entregar la contraseña al usuario | — | I | **R/A** | — | I |
| Verificar el alta y el acceso | — | I | C | C | **R/A** |
| Anotar en el registro de altas | C | C | **R** | C | **A** |

R = ejecuta · A = responde · C = consultado · I = informado.

## 3. Flujo

| # | Paso | Responsable | Control |
|---|---|---|---|
| 1 | Solicitud escrita: nombre, apellido, usuario, correo, teléfono (opcional), justificación | Solicitante | Fila nueva en el registro de altas |
| 2 | Aprobación de la solicitud | Autorizador | Nombre y fecha |
| 3 | **Generación** en el equipo corporativo del custodio (PHP CLI, o el contenedor `php:8.3-cli` con el script montado): `php generar-alta.php > alta-<id>.sql`. Los datos se escriben en pantalla (no en la línea de comandos); la contraseña aparece **una sola vez**, en pantalla; el archivo contiene el SQL con el hash y **no** la contraseña | Custodio | Huella del generador verificada |
| 4 | El custodio calcula `sha256sum alta-<id>.sql` y lo anota en el registro | Custodio | Huella del SQL |
| 5 | Envío del `.sql` al DBA por el canal interno acordado y, **por otro medio**, la huella del paso 4 | Custodio | Dos medios distintos |
| 6 | El DBA recalcula la huella, revisa el SQL con la lista de la sección 5 y lo ejecuta con su cuenta nominal, en transacción: `filas_insertadas = 1` y fila esperada → `COMMIT`; cualquier otro resultado → `ROLLBACK` | DBA | Salida conservada, sin contraseña |
| 7 | El DBA informa al verificador y al custodio: id asignado y resultado (nunca contraseña) | DBA | — |
| 8 | **Entrega** de la contraseña al usuario con los controles de la sección 4 | Custodio | Acuse de recepción sin valor |
| 9 | **Verificación:** el verificador contrasta la salida del DBA con el registro y comprueba que el usuario inicia sesión (con el usuario presente) | Verificador | Resultado en el registro |
| 10 | Anotar en el registro de altas (sin contraseña) y custodiar el `.sql` como evidencia | Custodio + Verificador | Dos firmas |

**Corrección de un alta confirmada por error:** `UPDATE user SET is_active = 0 WHERE id = <id>` ejecutado por el DBA con autorización del funcional (no se borran filas). En el backend heredado un usuario inactivo no puede usar el token en rutas protegidas (diagnóstico I-06).

## 4. Riesgo R-PWD: la contraseña inicial no puede cambiarla el usuario

**Registro explícito del riesgo.**

| Campo | Contenido |
|---|---|
| **Qué** | La contraseña temporal es **la contraseña de la cuenta** hasta que exista un cambio autorizado. El Flutter desplegado no tiene llamada de cambio de contraseña y P5 sigue bloqueado |
| **Causa** | Ausencia de función de cambio (Flutter) y bloqueo de P5 (React) |
| **Impacto** | Quien conozca la contraseña (el custodio, o quien la intercepte en la entrega) puede iniciar sesión como ese usuario durante un tiempo indefinido |
| **Quién la conoce** | El custodio hasta entregarla; el usuario. **No** el DBA ni el verificador |
| **Probabilidad / gravedad** | Probabilidad baja si se cumplen los controles; gravedad media (acceso a los informes de las empresas asignadas a esa cuenta) |
| **Riesgo residual** | **Medio**, mientras no exista cambio de contraseña propio |
| **Aceptación** | Explícita y firmada por el funcional y el DBA (sección 9) |

**Control mínimo para una entrega segura (todos obligatorios):**

| # | Control |
|---|---|
| C1 | **Identidad:** se entrega solo a la persona solicitada, verificada en persona o con llamada de retorno a un número ya conocido |
| C2 | **Canal permitido:** en persona (la contraseña se muestra y se descarta) o gestor de secretos corporativo con enlace de **un solo uso** y caducidad ≤ 24 h. **Prohibidos:** correo, chat, SMS, tickets, documentos compartidos y voz en áreas abiertas |
| C3 | **Una sola vez:** la contraseña no se copia a archivos ni al portapapeles persistente; si se pierde, no se recupera (ver contingencias) |
| C4 | **Equipo del custodio:** corporativo, sin registro de sesión; limpiar la pantalla tras la entrega; sin capturas |
| C5 | **Acuse:** el usuario confirma la recepción (sin valor) y guarda la clave en su gestor personal o la memoriza; no la deja a la vista |
| C6 | **Separación de funciones:** el DBA no la conoce; el custodio no tiene acceso a la base |
| C7 | **Plazo:** entregar dentro de 1 día hábil tras el `COMMIT`; si no se entrega en 3 días hábiles, el DBA desactiva la cuenta hasta entregarla |
| C8 | **Sospecha de exposición:** desactivar la cuenta (`is_active = 0`) con autorización del funcional |

**Contingencias.** Mientras P5 esté bloqueado **no existe reemisión de contraseña** dentro de este procedimiento. Decisión pendiente **D-PWD1**: ¿autorizar una *reemisión excepcional* manual por el DBA (mismo custodio, mismos controles, autorización escrita por caso)? Recomendación: sí, porque desactivar y recrear la cuenta no es viable (el correo es único). No está desarrollada.

### 4.1 Caducidad del procedimiento ≠ caducidad de las contraseñas

La caducidad del procedimiento (sección 8) **no invalida, no vence ni cambia ninguna contraseña emitida**. Las contraseñas temporales **siguen vigentes** hasta que su titular las cambie por un mecanismo autorizado. Para no perderlas de vista, el registro de altas guarda `fecha_emision` y `contrasena_inicial_vigente` (sí/no): es la lista que se usará para una campaña de rotación cuando exista el cambio de contraseña.

### 4.2 Cómo incorporar después el cambio de contraseña inicial (evaluación; **sin desarrollar ni liberar P5**)

| Opción | Qué es | Compatibilidad | Esfuerzo (estimado) | Evaluación |
|---|---|---|---|---|
| **1 · Cambio propio («P5-lite»)** | El usuario cambia **su** contraseña indicando la actual. Escritura mediante un objeto `DEFINER` bloqueado que solo actualiza `password` de la fila propia **si la contraseña vigente coincide** (compara y reemplaza), incrementa `session_version` y deja fila en `audit_log` | Sigue el patrón de `v_user_alta` (la cuenta de React no obtiene `UPDATE(password)` ni lee `password`; la verificación usa la cuenta de lectura de autenticación). No incluye restablecer la de otros ni cambiar roles: eso es P5 completo | 5 a 8 días de desarrollo y pruebas, más una ventana de privilegios | **Recomendada como primer incremento tras P4c.** Solo beneficia a quien use React, por eso encaja con el retiro de Flutter |
| 2 · Cambio obligatorio en el primer inicio | Una marca en `user` que obliga a cambiar la clave inicial | Exige una columna nueva en la tabla compartida; Flutter la ignoraría | 3 a 5 días + DDL | Viable **después** de retirar Flutter |
| 3 · Activación por enlace de un solo uso | El usuario define su propia contraseña; el custodio nunca la conoce | Exige tabla de tokens, correo saliente (no hay evidencia de un servicio de correo) y pantalla | Mayor (más de 2 semanas) | Es el control más fuerte; evaluar tras el HTTPS corporativo |
| 4 · Reemisión manual por el DBA | Contingencia de la sección 4 | Sin desarrollo | — | Solo puente |

**Decisión pendiente D-PWD2:** autorizar el *diseño* (no la implementación) de la opción 1 para después de P4c. P5 sigue bloqueado hasta autorización expresa.

## 5. Lista de revisión de la SQL (el DBA la firma por alta)

- [ ] La huella del archivo coincide con la comunicada por el custodio por otro medio.
- [ ] Exactamente un `INSERT INTO user (...)`, sin otras sentencias de escritura.
- [ ] Rol `'usuario'`; sin `is_active`, `session_version` ni `id` explícitos (valores por defecto de la tabla).
- [ ] El hash empieza por `$2y$`; el archivo **no** contiene ninguna contraseña (el DBA no la conoce y no debe aparecer).
- [ ] El `WHERE NOT EXISTS` cubre correo **y** usuario.
- [ ] El `SELECT` de verificación no muestra el hash.
- [ ] Tras ejecutar: `filas_insertadas = 1`; la fila tiene `role = usuario`, `is_active = 1`, `session_version = 1`.

## 6. Permisos

- El generador **no necesita credenciales de base de datos** y no se conecta a nada; lo ejecuta el custodio, que no tiene acceso a la base.
- Solo el **DBA** ejecuta SQL, con su cuenta nominal. **No se crea ninguna cuenta nueva con escritura**, no se concede ningún privilegio, no se usan las cuentas de aplicación de Flutter ni de React.
- Quién puede solicitar: lista nominal aprobada por el funcional (sección 9).

## 7. Auditoría

| Registro | Contenido | Dónde |
|---|---|---|
| **Registro de altas** (`registro-altas.plantilla.csv`) | fecha-hora, id de solicitud, solicitante, autorizador, custodio, ejecutor (DBA), verificador, username, id asignado, rol, huella del SQL, canal de entrega (sin valor), `fecha_emision`, `contrasena_inicial_vigente`, verificación de acceso, resultado | Custodia del funcional; conservar 12 meses (o hasta el fin de la vida de la cuenta, lo que ocurra después) |
| Salida de la sesión SQL | La SQL ejecutada y sus resultados, sin contraseña | Con el registro |
| Conciliación mensual | Altas del mes en la base (`c5-dba-solo-lectura.sql.template`, sección 6) frente al registro de altas | Una diferencia es un **incidente** |

## 8. Caducidad y retirada del procedimiento

- Vigencia: desde el despliegue de F-10 hasta **P4c verificado + 5 días hábiles**, con tope de **60 días** (renovable solo con firma del DBA y del funcional).
- Al caducar: retirar el generador de los equipos, archivar el registro, confirmar que el alta de React funciona y cerrar la autorización por escrito.
- **Las contraseñas emitidas no caducan** (sección 4.1).
- El procedimiento **no se activa** hasta cumplir la sección 9.

## 9. Aprobación y condiciones previas (todas, antes de cerrar el registro)

| # | Condición | Estado |
|---|---|---|
| 1 | Aprobación firmada del DBA y del responsable funcional, incluida la **aceptación explícita del riesgo R-PWD** | PENDIENTE |
| 2 | Personas nombradas: solicitantes autorizados, autorizador, **custodio** (sin acceso a la base, confirmado por el DBA), DBA ejecutor, verificador (distinto del ejecutor) y canal seguro de entrega | PENDIENTE |
| 3 | **Ensayo** del DBA con una SQL generada por el custodio en un entorno de prueba (el paquete trae la prueba en Docker) | PENDIENTE |
| 4 | C-5 ejecutado: volumen de altas y consumidores de `/api/register` conocidos; si hay consumidores legítimos, evaluar su continuidad antes del cierre | PENDIENTE |
| 5 | Longitudes reales de las columnas de `user` (C-5 SQL, sección 3) frente a los límites del generador (nombres 50, correo 100) | PENDIENTE |
| 6 | Tiempo máximo de atención acordado (p. ej. 1 día hábil) | PENDIENTE |
| 7 | Decisiones D-PWD1 y D-PWD2 tomadas (aunque sea «aplazar») | PENDIENTE |

Firmas: DBA ______________________ · Funcional ______________________ · Custodio (enterado) ______________________ · Fecha ____ / ____ / ________

## 10. Verificación local realizada (23/23, `deploy/alta-temporal/probar-alta-temporal.sh`)

Datos sintéticos, Docker sin red hacia fuera, MySQL 8.0: la contraseña sale solo por la salida de errores y no está en la SQL (por eso el DBA puede recibir el archivo sin conocerla); dos corridas dan contraseñas y hashes distintos; la alta inserta una fila con rol `usuario` y valores por defecto; tildes y apóstrofes se guardan intactos; la contraseña verifica con `password_verify` contra el hash guardado y otra no; un usuario o correo repetido no inserta nada; un texto con comillas y `DROP TABLE` se guarda como dato; `ROLLBACK` deja la base como estaba; los datos inválidos se rechazan sin generar SQL; el generador funciona con la raíz del contenedor en solo lectura.
Huella del generador: `dd39c08da1b90a3e7e32bb6c94b0da17d8fa8f62df5a3dae541a5ff6a34983bb`.

## 11. Riesgos y límites

- Dependencia de personas: el DBA es cuello de botella y único ejecutor; el tiempo de atención se acuerda (condición 6).
- Las longitudes máximas del generador son supuestos hasta ver C-5.
- R-PWD (sección 4): residual medio hasta que exista el cambio de contraseña propio.
- El custodio conoce la contraseña hasta entregarla: confianza + controles C1 a C8.
- El procedimiento no sustituye a React: caduca con P4c; las contraseñas no.
