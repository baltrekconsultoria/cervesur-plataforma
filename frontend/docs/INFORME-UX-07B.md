# UX-07B · Administración funcional de empresas y reportes

Rama `feat/cervesur-ux-07b-company-report-admin` (desde `main` = `d9836b8`). Solo entorno local aislado con MySQL sintético; sin Power BI real.

## Qué puede hacer ahora el administrador
1. **Crear y editar empresas** (Empresas): Nombre, Razón social y RUC de 11 dígitos obligatorios; ciudad, país, correo, teléfono y dirección opcionales. Sin credenciales de Power BI. RUC duplicado y errores de PHP se muestran sin éxito falso; la lista se refresca desde el servidor.
2. **Crear y editar reportes** (Reportes): nombre, descripción, enlace del informe, ID del grupo e ID del informe de Power BI (UUID), con ayuda en cada campo técnico y aviso de usar solo datos sintéticos. Filtro nuevo «Sin empresa».
3. **Asociar informes a empresas** (menú «Informes por empresa»): elegir empresa → ver asociados → marcar disponibles → «Asociar seleccionados» → resultado por informe; **desvincular con confirmación**. Un informe puede pertenecer a varias empresas.
4. **Concederlo a un usuario** con el asistente de UX-07A: el informe recién registrado aparece en el paso 3 de la empresa elegida.

## Contratos utilizados (verificados contra el código)
| Operación | Endpoint | Notas |
|---|---|---|
| Empresas | `GET/POST /api/empresas`, `GET/PUT /api/empresas/{id}` | POST responde `id`; PUT actualiza solo campos enviados (`isset`) |
| Informes | `GET /api/reportes[?…]`, `POST /api/reportes`, `PUT /api/reportes/{id}` | POST responde `data.id` (id interno) y `reporte_id` (UUID) |
| Asociaciones | `GET/POST /api/asignacion`, `DELETE /api/asignacion/{id}` | `reporte_id` y `empresa_id` son los **ids numéricos internos**; DELETE es baja lógica |

### Identificadores (nunca se mezclan)
`empresa.id` (numérico) · `reporte.id` (numérico interno, solo para PUT y para asociar) · `reporte.reporte_id` (UUID de Power BI: identificador del informe en React) · `empresa_reporte.id` (id de la asociación, solo para desvincular). El cliente HTTP traduce UUID ↔ id numérico con lecturas frescas del catálogo y el adaptador es el único lugar que conoce los nombres técnicos del backend.

## Correcciones y extensiones mínimas de PHP (compatibles con Flutter)
- **Defecto confirmado y corregido — `ReporteModel::update` / `ReporteController::update`**: el controlador no pasaba `updated_by` (el modelo lo exige) → quedaba **siempre NULL** y, con un cuerpo parcial, fallaba con un error genérico. Ahora el controlador valida y completa `updated_by`; hay prueba que lo verifica en MySQL.
- **Validación de informes (alta y edición)**: campos obligatorios, URL válida (≤255), UUID válidos (se guardan en minúsculas, como los espera la ruta `/reporteid/{uuid}`), nombre ≤100, **UUID de Power BI único** entre informes activos, edición de un id inexistente → «Reporte no encontrado».
- **Empresas**: al editar no se pueden vaciar `nombre` ni `razon_social`.
- **Asociaciones** (`AsignacionModel::create`): (a) el mensaje «Ya existe una asignación…» se perdía tras SEC-05A (la excepción se reemplazaba por «Error al procesar la solicitud»): ahora una regla de negocio (`DomainException`) conserva su texto sin exponer detalle de base de datos; (b) una asociación desvinculada se **reactiva** en lugar de crear otra fila (con clave única, el `INSERT` fallaba).
- **Vista `?view=form` (solo administradores, 403 al resto)** en `GET /api/empresas/{id}` y `GET /api/reportes/{id|reporteid/{uuid}}`: devuelve únicamente los campos editables. En empresas **nunca** incluye `powerbi_*`; en informes incluye el enlace porque el administrador debe poder corregirlo. Sin `view`, las respuestas históricas no cambian.
- Efecto de desvincular: los usuarios que veían el informe por esa empresa dejan de verlo (su acceso `user_empresa_reporte` se conserva, pero deja de ser efectivo); si se vuelve a asociar lo recuperan. El diálogo de confirmación lo explica.

## Resultados de pruebas (local, aislado)
| Suite | Resultado |
|---|---|
| PHP lint / estáticas / unitarias de seguridad | 47 archivos · 12 · OK |
| HTTP base · paginación · SEC-05A · UX-07A | 50 · 228 · 45 · 36 (todas) |
| HTTP UX-07B (nueva) | 67 de 67 |
| Contratos React–PHP | 172 PASS / 0 FAIL (4 hallazgos informativos previos) |
| E2E React–PHP real (incluye el circuito UX-07B) | 164 PASS / 0 FAIL |
| E2E simulados | 85 PASS / 0 FAIL |
| Frontend: ESLint, typecheck, unitarias | 0 avisos · OK · 117 de 117 |
| Escaneo de secretos | limpio (122 archivos y compilaciones) |

Notas de calidad: no se eliminó ninguna prueba. Se ajustaron dos guardas existentes con motivo documentado: (1) la compilación puede contener el campo técnico `enlace` **solo** en el mapeo administrativo del adaptador (máx. 2 apariciones; `app.powerbi.com` y `powerbi_*` siguen prohibidos); (2) `E2E_PORT` permite ejecutar el E2E real sin chocar con una vista previa abierta para QA manual.

## Limitaciones pendientes para el MVP
- Sin gestión de credenciales de Power BI (tenant, cliente, secreto) ni de logo/contacto de empresas; sin desactivar/reactivar empresas ni informes desde la interfaz (existe `DELETE` en el backend, sin pantalla a propósito).
- Sin edición de usuarios, cambio de rol, desactivación ni restablecimiento de contraseña (UX-07A).
- El visor sigue simulado; el enlace del informe no se usa todavía para visualizar.
- La unicidad del UUID se aplica a informes activos en el backend; el esquema real podría necesitar una restricción única.
- Las validaciones nuevas (UUID/URL, unicidad) pueden rechazar datos que hoy existan en producción al editarlos: conviene revisar el inventario real antes de desplegar.
- Sin auditoría administrativa, sin limitador de login (solo diseñado) y sin pruebas con el Flutter real.
- Las suites contra PHP real siguen sin ejecutarse en GitHub Actions (sí las HTTP del backend).

## Cierre del QA manual (realizado por el responsable del proyecto, entorno local aislado)
Se distingue la evidencia: **[captura]** = hay captura aportada; **[declarado]** = el responsable informó haberlo completado, sin captura; **[auto]** = verificado por las pruebas automatizadas (no sustituye al QA manual).

| Verificación | Evidencia |
|---|---|
| Registro de Empresa C | [captura] |
| Rechazo de RUC duplicado | [captura] |
| Edición y actualización de Empresa C | [captura] |
| Creación de «Reporte QA Cervesur» | [captura] |
| Edición del reporte | [captura] |
| Asociación del reporte a Empresa C | [captura] |
| Creación de «Operador Empresa C» | [captura] |
| Asignación del reporte al usuario y confirmación del acceso guardado | [captura] |
| Empresa C muestra un usuario asociado | [captura] |
| El usuario inicia sesión y ve únicamente «Reporte QA Cervesur» | [captura] |
| El usuario abre el visor simulado | [captura] |
| Retiro de permisos | [declarado] sin captura · [auto] E2E real |
| Desvinculación de la asociación empresa ↔ informe | [declarado] sin captura · [auto] E2E real |
| Persistencia tras recargar | [declarado] sin captura · [auto] E2E real |

## Mejora registrada para la siguiente fase (UX-07C, no iniciada)
- Simplificar el alta de reportes: que el administrador no tenga que escribir a mano los IDs de grupo (workspace) e informe de Power BI.
- Evaluar la **extracción automática** de esos identificadores cuando la URL del informe los contenga.
- Diferenciar **enlaces públicos** de **mecanismos de inserción autenticada**.
- No eliminar columnas ni modificar el contrato del backend en esa fase sin revisión.
