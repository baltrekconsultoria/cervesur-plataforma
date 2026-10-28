# UX-07C · Visor de Power BI y registro de informes por enlace

Rama `feat/cervesur-ux-07c-powerbi-viewer` (desde `main` = `b80113a`). Solo entorno local aislado con MySQL sintético, enlaces sintéticos y una página local que hace de «Power BI». **No se ha probado Power BI real.**

## Decisiones aplicadas
1. Se priorizan los informes con enlace de **«Publicar en web»** ya autorizado para difundirse públicamente. No se crean enlaces públicos nuevos.
2. Power BI privado (inserción autenticada) queda para una etapa posterior: el visor lo reconoce y muestra un aviso, sin tokens.
3. El endpoint antiguo `/api/powerbi/tokens/{id}` **no se usa** en el visor (devuelve un token OAuth general). Sigue intacto para el Flutter y ahora se niega a actuar sobre informes sin identificadores de Power BI.
4. El alta de reportes pide solo **nombre, descripción y enlace**.
5. **No se inventan UUID de Power BI.**

## Clasificación de enlaces (idéntica en PHP y React; 40 vectores compartidos)
| Tipo | Forma | Qué hace |
|---|---|---|
| **Publicar en web** | `https://app.powerbi.com/view?r=…` (+ `pageName`, `navContentPaneEnabled`, `filterPaneEnabled`, `ctid`) | Informe público: se inserta en un iframe. No trae ids de Power BI. |
| **Inserción autenticada** | `https://app.powerbi.com/reportEmbed?reportId=…[&groupId=…]` | Se extraen `reportId`/`groupId` solo si están y son UUID válidos. El visor muestra «requiere configuración de acceso a Power BI». |
| **Enlace normal del informe** | `…/groups/{g}/reports/{r}` o `…/reports/{r}` | No apto para iframe; se reconoce para extraer ids. El visor muestra el mismo aviso. |
| **Otros** | — | **Rechazados**: HTTP, otros hosts (incluido `app.powerbi.com.evil…`, `usuario@host`, puertos), fragmentos, espacios/barras, parámetros desconocidos o repetidos (p. ej. `redirect=`), rutas desconocidas, `javascript:`/`data:`, **HTML o iframe pegado**. |

La clasificación es léxica: no hace peticiones ni sigue redirecciones. Sin dependencias nuevas.

## Identificadores internos y externos
- **Identidad estable:** `reporte.id` (numérico). No cambia al editar el enlace; las asociaciones (`empresa_reporte`) y los accesos (`user_empresa_reporte`) la usan.
- **`reporte.reporte_id` (UUID)** conserva su papel histórico de referencia única y estable para las rutas `/reporteid/{uuid}`:
  - enlace de inserción o normal → es el `reportId` extraído del enlace (id real de Power BI);
  - publicación web → **referencia interna de Cervesur (UUID v4 aleatorio)** y `grupo_id` queda **NULL**. Nunca se envía a Microsoft; no es un id de Power BI.
- Editar el enlace **conserva** la referencia y el id (rutas y asociaciones siguen válidas). Si el nuevo enlace es de inserción/normal, los ids se toman de él.
- Rutas históricas por UUID: sin cambios (los datos antiguos siguen funcionando). Los datos antiguos con enlaces no compatibles siguen existiendo; su visor muestra «enlace no compatible».

## Migración propuesta (opcional y reversible; no ejecutada en ninguna base real)
`database/migrations/UX-07C-reporte-sin-ids-externos.up.sql` / `.down.sql`:
1. `reporte.grupo_id` admite NULL (un informe público no tiene grupo).
2. `reporte.enlace` pasa a 1024 caracteres (los enlaces públicos pueden superar 255).
- **Los registros existentes no cambian.** Sin la migración la aplicación sigue funcionando, pero rechaza con un mensaje claro los informes sin grupo y los enlaces demasiado largos (consulta el tamaño real de la columna).
- **Reversa segura:** en modo estricto cada `ALTER` falla sin tocar datos si ya hay informes sin grupo o enlaces > 255; nunca inventa ni trunca.
- Probada en MySQL sintético (`http_viewer_test.php`): esquema «de producción» → rechazo → migración → registro → reversa rechazada con datos → limpieza → reversa → re-migración.
- **Efecto en producción:** operación breve sobre una tabla pequeña; requiere copia de seguridad y autorización. Hasta que se aplique, los informes por enlace público sin ids no se pueden registrar si la columna es obligatoria.

## Endpoint seguro del visor
`GET /api/reportes/reporteid/{uuid}/visor` (JWT):
- Informe activo + usuario activo; usuario normal con asignación vigente a través de una empresa activa y su relación empresa–informe; administrador según sus permisos.
- Sin autorización → **404 idéntico al de un informe inexistente** (no revela su existencia).
- Asignación retirada, asociación desactivada, empresa/usuario/informe inactivos → 404/401.
- Respuesta mínima: `{reporte_id, nombre, descripcion, modo: publico|privado|no_compatible, url?}`. **`url` solo en `publico`** y solo si el enlace guardado se vuelve a validar. Nunca `powerbi_clientsecret`, credenciales de empresas, tokens OAuth ni contraseñas. `Cache-Control: no-store, no-cache, must-revalidate, private`, `Pragma: no-cache`.
- El catálogo normal (`?view=minimal`) no entrega el enlace. *(Las respuestas sin `view` conservan sus campos históricos para el Flutter.)*

## Visor React
- Solo con el proveedor HTTP (`liveViewer`); el simulado conserva su panel y no pide nada.
- **Público:** iframe con título, `sandbox="allow-scripts allow-same-origin allow-popups allow-forms"`, `referrerpolicy="no-referrer"`. La URL se **reconstruye** desde el token y los parámetros validados (nunca se copia la cadena recibida) y debe pertenecer al origen permitido (`app.powerbi.com`; en modo `qa`, una página local).
- Estado de carga, aviso si tarda (12 s) y la ayuda «¿No ve el informe?» con **«Abrir en una pestaña nueva»** siempre visibles. **El evento `load` solo oculta el indicador de carga: no se declara éxito** (no demuestra que Power BI haya mostrado el informe).
- **Privado:** «Este informe requiere configuración de acceso a Power BI» (sin tokens generales). **No compatible:** aviso con Reintentar. **Denegado:** estado 403 neutral.
- Escritorio y móvil; mantiene título, empresa, descripción y Volver.
- Para QA sin acceso externo, la compilación `qa` incluye una página local (`/qa/powerbi-view.html`) y `VITE_POWERBI_VIEW_BASE`, que **se ignora en producción** y solo admite una ruta local o loopback.

## Formulario simplificado
Nombre, Descripción opcional y **Enlace de Power BI** con ayuda y detección del tipo en vivo («Publicación web…», «Inserción autenticada: se detectó ID del informe…», «Enlace no compatible: …»). Ya no se piden ni se muestran ids de Power BI. Las validaciones se hacen en React y, de forma autoritativa, en PHP.

## Cambios
- **Backend:** `lib/powerbi_link.php` (clasificador y referencia interna), `ReporteController` (validación, extracción de ids, `getViewer`), `ReporteModel` (grupo opcional, ids conservados, tamaño de columna), `ReporteRouter` (ruta del visor + `no-store`), `PowerBIRouter` (guarda para informes sin ids).
- **Frontend:** `domain/powerbiLink.ts`, `lib/viewerBase.ts`, `features/viewer/ViewerPanel.tsx`, `ReportDialog` simplificado, `ApiClient.getViewerConfig`, adaptadores, simulador, plugin QA en `vite.config.ts`, estilos.
- **Pruebas/QA:** vectores compartidos (`tests/fixtures/link_vectors.json`), suite HTTP del visor y la migración, contratos, E2E real, `seed-extra.php` con enlaces sintéticos.

## Resultados de pruebas (local, aislado)
| Suite | Resultado |
|---|---|
| PHP lint · estáticas · unitarias (incl. 40 vectores de enlaces) | 49 archivos · 15 · OK |
| HTTP base · paginación · SEC-05A · UX-07A · UX-07B | 50 · 228 · 45 · 36 · 67 |
| HTTP UX-07C (visor, enlaces, migración) | 56 de 56 |
| Contratos React–PHP | 199 PASS / 0 FAIL (4 hallazgos informativos previos) |
| E2E React–PHP real (incluye el circuito UX-07C) | 183 PASS / 0 FAIL |
| E2E simulados | 85 PASS / 0 FAIL |
| Frontend: ESLint · typecheck · unitarias | 0 avisos · OK · 180 de 180 |
| Escaneo de secretos | limpio (141 archivos y compilaciones) |

Ajustes a pruebas existentes (sin eliminar aserciones): los informes de las pruebas HTTP/contrato/E2E usan enlaces **compatibles y sintéticos** (el alta ahora los exige); las guardas de compilación pasan de «ninguna mención de la palabra enlace» a «ningún enlace de publicación concreto, ninguna credencial y el campo técnico solo como clave en el adaptador».

## Limitaciones del visor respecto de Power BI real
- **No se ha probado con Power BI real.** Los iframes de las pruebas apuntan a una página local. Antes de afirmar que funciona hay que probar un enlace público **explícitamente autorizado** y comprobar visualmente su contenido.
- `load` del iframe no demuestra que Power BI haya cargado el informe; Power BI o el navegador pueden bloquear la inserción (cabeceras, cookies de terceros, bloqueadores). Por eso se ofrece «Abrir en una pestaña nueva».
- Un informe público lo puede ver cualquiera que conozca su enlace: la autorización del portal protege el enlace en el catálogo, no el informe en Power BI. Solo usar para informes autorizados a difundirse.
- Inserción autenticada: solo se reconoce y se avisa; requiere permisos y licencias de Microsoft y un mecanismo de tokens limitados (etapa posterior).
- La migración no se ha aplicado en ninguna base real; sin ella, informes sin grupo y enlaces largos se rechazan.
- Sin auditoría, sin limitador de login y sin validación con el Flutter real. Las suites contra PHP real siguen sin ejecutarse en GitHub Actions.

## Cierre del QA manual (UX-07C y UX-07C.1)

Se distingue el tipo de evidencia. **No se versiona ninguna captura ni enlace del informe real**: el enlace de publicación y el panel real usados en el QA existen solo en la base de datos sintética local del responsable; las capturas de este repositorio son sintéticas y proceden de las pruebas automatizadas.

| Verificación | Confirmación manual del responsable | Prueba automatizada |
|---|---|---|
| Registro de un informe con el formulario simplificado (nombre, descripción y enlace) | Sí | Sí (React, HTTP, contratos, E2E) |
| Reconocimiento del enlace de publicación web | Sí | Sí (40 vectores PHP/React) |
| Edición del informe | Sí | Sí |
| Asociación del informe con Empresa A | Sí | Sí |
| **Visualización real de un informe público de Power BI dentro de React** (QA con Power BI público real) | **Sí** | **No** (las automáticas no se conectan a Microsoft: usan la página local) |
| **Interacción con filtros y gráficos del informe real** | **Sí** | **No** |
| Asignación del informe a «Demo Nobody» | Sí | Sí |
| Inicio de sesión de «Demo Nobody»: ve únicamente el informe asignado | Sí | Sí |
| **Desvinculación del informe de Empresa A** (asociación empresa ↔ informe) | **Sí** | Sí |
| El informe desaparece del catálogo de «Demo Nobody» tras desvincular | Sí | Sí |
| Acceso directo por la ruta local del portal bloqueado tras desvincular | Sí | Sí (HTTP/contrato/E2E: 404 neutral y «Acceso denegado») |
| **Retiro individual de la asignación usuario ↔ informe** | **No se probó manualmente en esta fase** (lo que se probó fue la desvinculación empresa ↔ informe) | Sí (HTTP UX-07C, contratos y E2E real) |

Modos de QA validados: **QA simulado** (predeterminado, página local, sin Internet) y **QA con Power BI público real** (opt-in; el navegador se conecta con `app.powerbi.com`; PHP, MySQL y autorización siguen locales).

### Advertencias residuales y limitaciones
- **Sandbox del iframe.** En el modo real, Chrome puede mostrar su advertencia genérica por `allow-scripts` + `allow-same-origin`. Se mantiene porque Power BI necesita su propio origen y es de **otro origen** (no alcanza la aplicación); la página local de pruebas ya no usa `allow-same-origin`. Residual justificada, no silenciada.
- **Informe público = cualquiera con el enlace.** La autorización del portal protege el enlace en el catálogo, no el informe en Power BI. Solo debe usarse con informes autorizados para difusión pública.
- Inserción autenticada (Microsoft) solo reconocida y avisada; no implementada.
- Que el iframe cargue no demuestra que el informe se muestre; por eso se mantienen la ayuda y «Abrir en una pestaña nueva».
- Falta una cabecera `Content-Security-Policy: frame-src https://app.powerbi.com` en el servidor web de producción.

### Mejora posterior registrada (no incluida en esta fase)
- **Tamaño inicial del dashboard dentro del visor:** el responsable observó que el informe se ve inicialmente a ≈ 31 % de su tamaño. Se estudiará un ajuste (relación de aspecto, altura del marco, parámetros de página) en una fase posterior; no se corrige aquí.

### Migración
`database/migrations/UX-07C-reporte-sin-ids-externos.up.sql` / `.down.sql` (solo DDL, sin datos). Probadas únicamente en MySQL sintético (esquema «de producción» → rechazo → migración → registro → reversa rechazada con datos → limpieza → reversa → re-migración). **No se ha ejecutado ninguna migración en producción ni en ninguna base real.** Reversible bajo las condiciones documentadas: la reversa falla sin tocar datos si ya existen informes sin grupo o enlaces de más de 255 caracteres.
