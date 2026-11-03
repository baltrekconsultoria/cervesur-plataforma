# Guía de QA manual del módulo Administrador · A1–A10 (UX-07E.B)

Para el operador, desde **Brave en Windows**. Ningún caso se da por **PASS** sin que una persona lo haya visto; anote resultado, fecha, hora y captura **sin** contraseñas ni enlaces visibles.

**Dos entornos, no se mezclan:**

| Entorno | URL | Datos | Qué se prueba |
|---|---|---|---|
| **PROD-RO** (lectura productiva) | `https://localhost:18094` (túnel al 8093) | Reales, solo lectura | A1–A7, A9, A10 |
| **QA-SINTÉTICO** (escritura aislada) | `http://127.0.0.1:4175` (`npm run qa:up` + `npm run build:qa` + `npm run preview:qa`; ver `frontend/docs/QA-MANUAL-WINDOWS.md`) | 100 % ficticios, Docker sin Internet | A8 (altas, ediciones, asignaciones) |

Preparación PROD-RO: túnel `ssh -N -L 18094:127.0.0.1:8093 usr_cervesur@192.168.200.12`, certificado de la instancia importado tras comprobar su huella, y el administrador de QA agregado con `usuarios.sh agregar <id>`. La contraseña es la propia del administrador; nadie más debe conocerla.

Si Brave fuerza HTTPS en algún túnel HTTP (HSTS heredado de `localhost`): `brave://net-internals/#hsts` → «Delete domain security policies» → `localhost`.

| ID | Prueba | Entorno | Pantalla / URL | Acción del operador | Resultado esperado | Evidencia | PASS si… / FAIL si… |
|---|---|---|---|---|---|---|---|
| **A1** | Inicio de sesión con administrador | PROD-RO | `/login` | Usuario y contraseña propios del administrador autorizado. Luego probar (a) una contraseña mala y (b) una cuenta de trabajador. | Entra a «Inicio administrativo» con el aviso «PILOTO ADMINISTRATIVO · Solo consulta». (a) y (b): «Credenciales inválidas» idéntico, sin indicar el motivo. | Captura del panel y de la respuesta genérica | PASS: entra el administrador y el trabajador no. FAIL: entra un trabajador o el mensaje distingue casos |
| **A2** | Panel administrativo | PROD-RO | `/admin` | Revisar contadores y menú. | Menú: Inicio, Usuarios, Empresas, Reportes, Accesos y asignaciones. **No** aparecen «Informes por empresa», «Nuevo…» ni botones de escritura. Cifras plausibles. | Captura | PASS: solo consulta y cifras coherentes con A3–A6. FAIL: aparece una acción de escritura |
| **A3** | Consulta y búsqueda de usuarios | PROD-RO | `/admin/usuarios` | Revisar el listado; buscar por nombre, usuario y correo; filtrar activos/inactivos; paginar. | Aparecen activos e inactivos con rol y estado; la búsqueda filtra; sin contraseñas ni teléfono; sin botón «Nuevo usuario». | Captura de listado y búsqueda | PASS: los datos coinciden con lo esperado por el administrador (contraste con Flutter). FAIL: faltan usuarios, aparecen datos sensibles |
| **A4** | Consulta de empresas | PROD-RO | `/admin/empresas` | Listar y buscar empresas (activas e inactivas). | Listado completo, sin «Nueva empresa» ni «Editar». | Captura | PASS/FAIL como A3 |
| **A5** | Consulta de informes | PROD-RO | `/admin/reportes` | Listar y buscar informes; abrir uno **autorizado** (si el operador autorizó alguno) y uno **no autorizado**. | Listado completo **sin enlaces**. Autorizado: carga el visor Power BI. No autorizado: «Acceso denegado» sin enlace. Títulos largos sin desbordar. | Captura de ambos casos | PASS: se cumple. FAIL: se ve un enlace en el listado o un informe no autorizado abre |
| **A6** | Consulta de asignaciones | PROD-RO | `/admin/accesos` | Revisar las asignaciones usuario–empresa–informe; buscar por usuario. | Solo vigentes; sin «Nueva asignación» ni «Retirar». Contrastar 3 usuarios con la realidad de Flutter. | Captura + 3 contrastes anotados | PASS: coinciden los 3 contrastes. FAIL: alguno no coincide |
| **A7** | Restricciones de permisos | PROD-RO | Barra de direcciones | (a) Pegar `/admin/accesos/nueva` y `/admin/asociaciones`. (b) Con el túnel abierto, cerrar sesión y pegar `/admin/usuarios`. (c) Con una cuenta de trabajador, intentar entrar (A1-b). | (a) vuelve a `/admin` (la ruta no existe en esta versión). (b) pide iniciar sesión. (c) rechazado. | Capturas | PASS: las tres. FAIL: alguna ruta de escritura aparece o se ven datos sin sesión |
| **A8** | Formularios administrativos | QA-SINTÉTICO | `/admin/usuarios`, `/admin/empresas`, `/admin/reportes`, `/admin/accesos/nueva` | Con `admin` del entorno sintético: crear usuario (primero vacío → mensajes por campo), crear empresa, crear y editar reporte, asignar accesos con el asistente, retirar un acceso (probar «Cancelar» y «Confirmar»), ingresar como el usuario nuevo y ver solo lo asignado. | Validaciones junto a cada campo; resultados por informe («Concedido»/rechazo); el usuario nuevo ve solo lo asignado; un informe ajeno da «Acceso denegado». | Capturas de cada paso | PASS: todo según `QA-MANUAL-WINDOWS.md` §3b. FAIL: algo se marca como éxito sin haberse ejecutado. **Este caso NO toca producción** |
| **A9** | Registro de auditoría | PROD-RO (servidor) | `sudo bash scripts/usuarios.sh auditoria` | Tras A1–A7, revisar los últimos eventos. | Aparecen `login_ok`, `login_fallido_*` (motivos internos), `admin_usuarios`, `admin_empresas`, `admin_informes`, `admin_asignaciones`, `admin_accesos`, `visor_abierto`/`visor_no_autorizado_piloto`; **sin** contraseñas, enlaces, nombres ni IP en claro. | Salida (puede tachar ids) | PASS: eventos presentes y sin datos sensibles. FAIL: falta algún evento o aparece un dato sensible |
| **A10** | Cierre y revocación de sesión | PROD-RO | «Cerrar sesión»; servidor | (a) Cerrar sesión, botón «Atrás» y pegar `/admin`. (b) Con sesión abierta, en el servidor `usuarios.sh quitar <id>` y hacer cualquier acción en el navegador. (c) Volver a `agregar`. | (a) login, sin datos. (b) la siguiente acción devuelve al login (sesión revocada). (c) puede entrar de nuevo con sesión nueva. | Capturas + salida del CLI | PASS: las tres. FAIL: la sesión sigue válida tras quitar al administrador |

## Condiciones de cierre

* La fase **no se declara cerrada** hasta que el responsable funcional firme A1–A10. Esta guía no autoriza escrituras productivas ni la apertura general.
* Si A3–A6 no coinciden con Flutter, registre el caso y detenga las pruebas: puede haber una diferencia de datos o de esquema que investigar (sin modificar nada).
