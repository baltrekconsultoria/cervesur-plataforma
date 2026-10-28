# UX-07D.2B · Compatibilidad de React con identificadores y permisos productivos

Estado: **implementado y probado en local con datos sintéticos; NO desplegado.** No se accedió al servidor ni a la base productiva, no se ejecutó ninguna
migración y Flutter no se modificó. El inventario productivo usado es el aportado como resultado de consultas `SELECT` de solo lectura (agregados, sin datos personales).

## 1. Diagnóstico de incompatibilidades

| # | Hallazgo | Impacto en producción | Resolución |
|---|---|---|---|
| 1 | React usaba `reporte_id` (UUID de 36 caracteres) como identidad del informe en catálogo, rutas, permisos y visor. El backend solo aceptaba `/api/reportes/reporteid/{uuid}/visor` con 36 caracteres hexadecimales. | **Ninguno de los 82 informes activos** tiene un UUID canónico (56 tienen más de 36 caracteres y 26 otro formato): no se podía abrir ninguno. | Identidad = `reporte.id` numérico; ruta nueva `GET /api/reportes/id/{id}/visor`. |
| 2 | La interfaz mantenía tablas de conversión UUID ↔ numérico para asociaciones y concesión de accesos. | Con referencias no únicas o no UUID habría uniones erróneas. | Se eliminaron: `reporte_id` de `/api/asignacion` ya es el id numérico. Los accesos se unen por `empresa_reporte_id`. |
| 3 | «Publicar en web» nuevo inserta `grupo_id = NULL`. | En producción `grupo_id` es `VARCHAR(50) NOT NULL`: la creación fallaría con un error genérico. | Se detecta el esquema real y se rechaza con un mensaje claro (sin inventar valores). **Requiere decisión** (ver §5). |
| 4 | `enlace` real es `VARCHAR(500)`. | El código ya consulta la longitud real; no hay truncamientos. | Sin cambio (probado: un enlace de 531 caracteres se rechaza citando 500). |
| 5 | Editar un informe histórico no UUID podía alterar `reporte_id`/`grupo_id`. | Pérdida de la referencia histórica. | Comprobado y probado: la edición los conserva byte a byte; el formulario solo envía nombre, descripción y enlace. |
| 6 | Dos asignaciones activas pertenecen a usuarios inactivos. | Ninguno: el middleware rechaza a los usuarios inactivos en cada petición. | Probado (`401` aunque la asignación siga activa). |
| 7 | `user.username VARCHAR(50)`, `user.role VARCHAR(30)`, `session_version INT UNSIGNED`, `id INT UNSIGNED`. | Ids hasta 4 294 967 295 (10 dígitos). | La ruta numérica acepta enteros canónicos de hasta 10 dígitos y ≤ 4294967295. `usuario` (alias de `user`) ya estaba soportado (SEC-05A). |
| 8 | `RegisterController` no limita `username` a 50 caracteres. | Un alta con más de 50 caracteres fallaría en la base con error genérico. | **No corregido** (fuera del alcance); anotado como riesgo menor. |
| 9 | Columnas no precisadas por el inventario (`reporte.nombre`, `descripcion`, etc.) y parámetros de los enlaces reales. | Posibles rechazos al editar o enlaces mostrados como «no compatible». | SQL de solo lectura listo: `database/schema/UX-07D2B-compatibilidad-solo-lectura.sql` (no ejecutado). |

## 2. Arquitectura de identidad elegida

- **`reporte.id`** (entero positivo, como cadena en React) es la identidad operativa y estable: rutas (`/reportes/{id}`), selección, permisos, asociaciones y visor.
- **`reporte_id`** es la **referencia histórica**: se conserva intacta y se muestra solo como `Report.ref` / `ReportDetail.ref`. Nunca se usa para enrutar ni para unir datos, y no se asume que sea único, UUID ni de 36 caracteres.
- **`empresa_reporte.id`** es el id de la relación empresa–informe (`ReportLink.id`, y `empresa_reporte_id` en los accesos). Es distinto del id del informe.
- Cada identificador viaja con su nombre y su tipo; no hay conversiones silenciosas. Un id de informe que no sea un entero positivo canónico (sin signo, decimales, ceros a la izquierda, espacios ni más de `4294967295`) se trata como informe inexistente **sin consultar al servidor**; un `id` ausente o no válido en una respuesta es una violación del contrato (`invalid_response`), no se descarta en silencio.

**Backend (aditivo):** `GET /api/reportes/id/{id}/visor` reutiliza la misma autorización que el resto de lecturas (`ReporteModel::getById`: informe activo, empresa activa, usuario↔empresa activo,
empresa↔informe activo y asignación individual activa; el administrador ve los informes activos). Un id malformado, fuera de rango, inexistente o no autorizado devuelve el mismo `404`. La respuesta
añade `id` y conserva `reporte_id`, `nombre`, `descripcion`, `modo` y `url` (solo en modo público); nunca credenciales ni tokens. Las rutas históricas por UUID (`/reporteid/{uuid}` y `/reporteid/{uuid}/visor`)
**no se modificaron** (su respuesta es idéntica; la nueva no se añade a ellas). El endpoint heredado de tokens OAuth no se usa ni se toca.

**Frontend:** tipos (`Report.ref`), adaptadores (`numericId`, `toReport`, `toAssignment`, `toReportLink`) y cliente HTTP (`getReport`, `getReportDetail`, `getViewerConfig`, `grantAccess`, `linkReports`, `listAssignments`,
`listReportLinks`). El proveedor simulado y la interfaz no cambian (la interfaz ya trataba el id como una cadena opaca).

## 3. Autorización

La autorización no cambia y sigue decidiéndola el servidor en listados **y** en la apertura directa por URL. Pruebas obligatorias cubiertas contra el esquema productivo sintético
(`tests/isolated/http_productive_ids_test.php`, 54 comprobaciones): administrador autorizado; usuario con permiso efectivo; usuario sin permiso (respuesta idéntica a un informe inexistente y sin enlace);
usuario inactivo con asignación todavía activa (no puede iniciar sesión; un token vigente deja de servir en cuanto se desactiva); informe inactivo; empresa inactiva; vínculo usuario–empresa revocado;
relación empresa–informe revocada; asignación individual revocada por la API y **acceso directo por URL después de la revocación** (404 y sin enlace; también desaparece del catálogo y del detalle);
ids inexistentes o malformados (14 variantes, incluida inyección). Nota: los enlaces «Publicar en web» son públicos por naturaleza; la autorización del portal controla quién los recibe de React,
pero **no revoca el acceso externo** de quien ya conozca la URL.

## 4. Edición y creación

- **Editar un informe histórico** (nombre, descripción o enlace) conserva `reporte_id` (hasta 50 caracteres, no UUID) y `grupo_id` byte a byte; no hay regeneración ni truncamiento (probado con referencias de 50 y ≤ 36 caracteres).
- Las validaciones **no se relajaron**: un `reporte_id`/`grupo_id` explícito que no sea UUID se sigue rechazando, un nombre de más de 100 caracteres se rechaza, y un enlace mayor que la columna se rechaza citando su límite real.
  Ningún formulario de React envía `reporte_id` ni `grupo_id`.
- **Enlaces de inserción** (`reportEmbed`, traen `reportId` y `groupId`): se crean con los identificadores del enlace (probado en el esquema `NOT NULL`).

## 5. Decisión pendiente: `grupo_id NOT NULL` y «Publicar en web» nuevo

Un enlace «Publicar en web» (`.../view?r=...`) no contiene identificadores de grupo. Con `grupo_id NOT NULL` y sin migración, **crear un informe nuevo de este tipo se rechaza** con
«Esta base de datos exige el ID del grupo del informe… aplique la migración UX-07C (pendiente de autorización) o registre el informe con un enlace que incluya el grupo» (nada se inserta). **Editar los 82 informes
existentes no se ve afectado.** Opciones para UX-07D.2C (a decidir; ninguna se aplicó):

| Opción | Efecto | Riesgo |
|---|---|---|
| A. Aplicar la migración UX-07C (`grupo_id` NULL, `enlace` 1024) tras respaldo y precondiciones | Permite crear informes públicos sin inventar datos | Cambia el esquema compartido con Flutter (DDL); requiere autorización y ventana |
| B. Pedir un `grupo_id` explícito en el formulario | Sin DDL | Añade un campo administrativo que el usuario no tiene en el enlace; riesgo de datos inventados |
| C. Almacenar un valor de referencia interno en `grupo_id` | Sin DDL ni campo nuevo | Es un valor inventado en una columna con significado histórico: **no recomendado** |

## 6. Datos sintéticos y cobertura

`tests/isolated/seed_productive.php` crea la base desechable `fixture_prod` con los **tipos reales** (`INT UNSIGNED`, `VARCHAR(500/50)`, `TINYINT(1)`, `grupo_id NOT NULL`, …) y reproduce **todos** los
agregados del inventario (el propio sembrador verifica 36 agregados y falla si alguno se desvía): 76 usuarios (62 activos; `usuario` 62/61, `user` 10/0, `admin` 4/1), 26 empresas (6 activas), 95 informes (82 activos;
57 referencias > 36 caracteres, 56 activas; 35 de otro formato, 26 activas; 3 UUID canónicos inactivos; 0 duplicadas; máximos de enlace 151, `grupo_id` 36, `reporte_id` 50), `empresa_usuario` 82/79,
`empresa_reporte` 103/100 (84 vigentes, 82 informes vinculados, 5 empresas), `user_empresa_reporte` 102/93 (91 efectivas, 61 usuarios, 79 informes, 2 de usuarios inactivos). **No hubo diferencias**.
Todos los valores son inventados (sin usuarios, correos, RUC, enlaces, hashes ni identificadores reales). Supuestos: longitudes de columnas que el inventario no precisó (p. ej. `reporte.nombre VARCHAR(100)`) iguales al fixture general.

## 7. Resultados de pruebas

PHP: lint y estáticas OK · unit OK · HTTP base, paginación (228), hardening, administración, empresa/informe y visor sin cambios en sus aserciones · **productivo nuevo: 54 PASS** ·
frontend: lint y typecheck OK, **207 pruebas unitarias** (198 previas + nuevas; 13 se reescribieron porque codificaban la identidad por UUID, sin relajar lo que verifican) ·
E2E simulada 85 · **contratos React–PHP 206/0** (199 + 7 nuevos de la ruta numérica) · **E2E real 185/0** (URLs de informe ahora numéricas) · Apache en Docker 40/40 ·
la suite se añadió al CI de backend (`backend-ci.yml`).

## 8. Compatibilidad con Flutter

- Las rutas y respuestas históricas por UUID no cambian; la ruta numérica es aditiva. No se modificó ningún archivo de Flutter ni de la instalación React ya operativa en el servidor de pruebas (que usa el paquete de UX-07D.1 y **no** incluye estos cambios).
- **Riesgo si en el futuro ambos clientes comparten backend:** las validaciones de escritura de informes (UX-07B/07C) rechazan `reporte_id`/`grupo_id` explícitos que no sean UUID; si el formulario de Flutter envía identificadores con otro formato,
  esa escritura fallaría. Debe evaluarse antes de compartir el backend (hoy el backend de prueba de React es una copia separada).

## 9. Riesgos pendientes

1. Decisión de §5 (creación de «Publicar en web» nuevo) — bloquea solo la **creación**, no la lectura ni la edición.
2. Parámetros de los 82 enlaces reales (¿alguno fuera de la lista permitida?) y columnas no inventariadas: SQL de solo lectura listo, **sin ejecutar** (requiere autorización).
3. `username` > 50 caracteres no se valida en el alta (error genérico, menor).
4. Compartir backend con Flutter (§8).
5. Los enlaces públicos no se pueden revocar desde el portal.
6. La validación final contra la base productiva real sigue pendiente (solo se probó con datos sintéticos de la misma forma).

## 10. GO / NO-GO para UX-07D.2C

**GO**, condicionado a: (1) autorización para ejecutar el SQL de solo lectura de §9.2 (o aceptar el riesgo), (2) decisión sobre §5 antes de habilitar la creación de informes públicos nuevos,
y (3) un despliegue de **prueba** (no producción) con una base de prueba de la misma forma que la productiva, para validar con el navegador. **NO-GO** para exponer React sobre la base productiva sin esos pasos.
