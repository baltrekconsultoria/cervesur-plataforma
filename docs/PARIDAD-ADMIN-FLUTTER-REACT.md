# Matriz de paridad Flutter → React · módulo Administrador (UX-07E.B, bloque B1)

Fuente: el código del backend PHP del repositorio (`backend/router/*.php`, controladores y modelos) y las pantallas React existentes (`frontend/src/features/admin`). **El código del cliente Flutter no está en el repositorio ni en las carpetas del proyecto**, por lo tanto lo que sigue es lo que el *backend* permite a un administrador; no se afirma nada sobre pantallas de Flutter que no se pudieron ver. No se inventó ninguna funcionalidad.

Leyenda de la columna React: **L** = consulta (lectura) implementada en el piloto administrativo (`piloto_admin`, producción en solo lectura) · **Q** = acción de escritura implementada en la interfaz React y probada solo contra el backend sintético de QA aislado (`read_write`) · **—** = no existe / no se desarrolla.


> **Actualización UX-09A.** La limitación «el código del cliente Flutter no está disponible» queda **parcialmente resuelta**: se analizó el paquete web compilado del Flutter desplegado (`main.dart.js` idéntico al de producción, C-4). No es el código fuente, pero permite listar las llamadas al API, las pantallas y las dependencias externas. La matriz por funcionalidad, con la evidencia del cliente, está en la sección 4.

## 1. Funciones administrativas del backend y su equivalente

| # | Función (Flutter/PHP) | Ruta(s) y comprobación admin | Estado en React |
|---|---|---|---|
| 1 | Listar usuarios (activos, inactivos o todos) | `GET /api/users?status=active\|inactive\|all` · `UserRouter.php:361` (rol `admin`) | **L** pantalla «Usuarios» en producción (solo lectura; sin contraseña ni `session_version`). **Q** en QA |
| 2 | Ver el perfil de otro usuario | `GET /api/user/profile/{id}` · `UserRouter.php:54` (admin o el propio) | — (no hay pantalla de detalle de otro usuario en React; el listado trae los datos de la ficha) |
| 3 | Alta de usuario | `POST /api/register` · `auth.php:62` (admin; no puede fijar `role`, `is_active`, `session_version`) | **Q** «Nuevo usuario» (QA). En producción: **bloqueado** (403) |
| 4 | Cambiar el rol de un usuario | `PUT /api/user/{id}/role` · `UserRouter.php:291` (admin) | — (React no lo ofrece; se decidió no exponerlo) |
| 5 | Activar / desactivar usuarios | **No existe endpoint** en el backend (solo `is_active` por SQL/otra herramienta) | — (no se inventa) |
| 6 | Cambiar la propia contraseña / editar el propio perfil / avatar | `PUT /api/user/profile`, `POST /api/user/avatar` (el propio usuario) | — (React: perfil solo lectura; `profileEdit=false`) |
| 7 | Listar empresas (todas) | `GET /api/empresas` · `EmpresaRouter.php:20` | **L** «Empresas» (activas e inactivas). **Q** en QA |
| 8 | Alta/edición de empresa | `POST/PUT /api/empresas` · `EmpresaRouter.php:32,86` (admin) | **Q** «Nueva empresa» / «Editar» (QA). Producción: **403** |
| 9 | Baja de empresa | `DELETE /api/empresas/{id}` · `EmpresaRouter.php:100` (admin) | — (React no lo ofrece) |
| 10 | Buscar empresas por nombre / ciudad | `GET /api/empresas/buscar/{x}`, `/ciudad/{x}` | **L** (búsqueda en el cliente sobre el listado; las rutas dedicadas no se exponen en producción) |
| 11 | Listar informes (todos) | `GET /api/reportes` · `ReporteRouter.php:75` | **L** «Reportes» (activos e inactivos, **sin enlace**). **Q** en QA |
| 12 | Alta/edición de informe (incluye `enlace` de Power BI) | `POST/PUT /api/reportes` · `ReporteRouter.php:25,44` (admin) | **Q** «Nuevo reporte» / «Editar» (QA). Producción: **403** |
| 13 | Baja de informe | `DELETE /api/reportes/{id}` · `ReporteRouter.php:62` | — |
| 14 | Visor del informe (abrir el Power BI) | `GET /api/reportes/id/{id}/visor` | **L** solo para informes autorizados en el piloto (`CERVESUR_PILOT_VISOR_IDS`); el administrador no recibe enlaces que no se autorizaron |
| 15 | Relaciones empresa ↔ informe | `GET/POST/PUT/DELETE /api/asignacion…` · `AsignacionRouter.php:25–131` (escritura admin) | **L** «Accesos y asignaciones» usa `GET /api/asignacion`. **Q** «Informes por empresa» (QA). Producción: pantalla **retirada** del piloto |
| 16 | Relaciones usuario ↔ empresa | `GET/POST/PUT/DELETE /api/empresa-usuario…` · `EmpresaUsuarioRouter.php:22–82` | **L** (se usa para mostrar las empresas de cada usuario). **Q** en QA (el asistente las crea) |
| 17 | Asignaciones usuario ↔ empresa ↔ informe (permiso individual) | `GET/POST/PUT/DELETE /api/empresa-reporte-usuario…` · `EmpresaReporteUsuarioRouter.php:19–106` | **L** «Accesos y asignaciones» (solo vigentes). **Q** «Nueva asignación» y «Retirar» (QA) |
| 18 | Consultar asignaciones de un usuario concreto | `GET /api/empresa-reporte-usuario/user/{id}` (admin o el propio) | **L** (el listado se filtra en el cliente por usuario) |
| 19 | Estadísticas | `GET /api/stats` · `StatsRouter.php:12` | — (no se expone en producción; el «Inicio» del administrador calcula sus contadores desde los listados) |
| 20 | Token OAuth general de Power BI | `GET /api/powerbi/tokens/{id}` | — **bloqueado a propósito** (devuelve credenciales de empresa): 403 en todos los pilotos |
| 21 | Documentación de la API | `GET /api/wiki` | — (403 en el piloto) |

## 2. Lo que el piloto administrativo de producción permite

Solo los **GET** de la columna **L** (más inicio/cierre de sesión). Todo lo demás —alta, edición, baja, asignación, cambio de rol— devuelve 403 desde la puerta de rutas, y además las tres cuentas MySQL de la instancia carecen de INSERT/UPDATE/DELETE sobre la base de Flutter. Las acciones **Q** existen en la interfaz React y funcionan contra el backend sintético; **no están activadas en producción** y requieren una autorización específica (ver `PILOTO-ADMIN-UX-07EB.md` §5).

## 3. Pendientes y motivo

| Pendiente | Motivo |
|---|---|
| Escrituras administrativas en producción (2–4, 8, 12, 15–17) | No se autorizaron escrituras productivas. Necesitan privilegios nuevos (INSERT/UPDATE sobre tablas concretas) y una cuenta distinta; plan de mínimo privilegio en `PILOTO-ADMIN-UX-07EB.md` §5, **sin activar** |
| Activar/desactivar usuarios (5) | No existe en el backend de Flutter |
| Cambio de rol, baja de empresa/informe (4, 9, 13) | Existen en el backend pero React nunca los expuso; fuera del MVP |
| Detalle de otro usuario (2), estadísticas (19) | Sin pantalla equivalente en React; sin aprobar su exposición |
| Pantallas de Flutter no visibles | Resuelto en lo esencial por el análisis del paquete compilado (sección 4); la relación llamada → pantalla sigue siendo inferida |

## 4. Matriz por funcionalidad con evidencia del cliente Flutter desplegado (UX-09A)

Leyenda: **C** ya cubierta en React · **M** requiere migración o decisión · **T** conservar temporalmente · **O** obsoleta o prescindible, previa validación funcional. La relación llamada → pantalla es **inferida por nombres** [I]; el paquete no es el código fuente.

| # | Funcionalidad en Flutter | Evidencia en el paquete | Equivalente en React | Clase |
|---|---|---|---|---|
| 1 | Inicio de sesión | `LoginCall`, `/loginPage` | `/login` (`password_verify` sobre el hash existente; usuarios por lista blanca en 8092) | **C** (apertura a todos: HTTPS corporativo y R-PUB) |
| 2 | Cierre de sesión | estado local | Cierre que revoca la sesión en el servidor | **C** |
| 3 | Inicio: dashboards asignados y «Buscar Dashboards…» | `QueryReportsCall`, `GetRUCperAsigmentCall`, `/Home` | Inicio y «Mis informes» con búsqueda y filtros | **C** |
| 4 | Visor del informe | iframe con `enlace` (formato «publicar en la web») | `/reportes/:id` con visor que valida permiso y audita | **C**; R-PUB independiente |
| 5 | Mis empresas | `QueryBusinessCall(es)` | `/empresas` | **C** |
| 6 | Ver perfil propio | `ProfileCall`, `/profile` | `/perfil` (solo lectura) | **C** |
| 7 | Editar perfil propio (nombre, apellido, teléfono, correo) | `UpdateUserProfileCall`, `/configProfile` | Perfil de solo lectura; correo bloqueado por diseño | **M** |
| 8 | Cambiar foto | «Cambiar Foto»; `avatar` vacío; sin Firebase Storage | Sin equivalente | **O/M** |
| 9 | Registro público «Crear Cuenta» | `RegisterCall` (sin token) | No existe; el alta es administrativa | **O** (decisión F-10) |
| 10 | Admin · Empresas | `Create/Update/DeleteBusinessCall`, `QueryBusinessesCall`, `QueryCityCall` | `/admin/empresas` (2A) | **C** |
| 11 | Admin · Reportes | `Create/UpdatetReport/DeleteReportCall`, `GetAllReportsCall` | `/admin/reportes` (2A; el enlace es de solo escritura) | **C** |
| 12 | Admin · Reporte ↔ Empresa | `Create/Delete Assignment/Asigment` | `/admin/asociaciones` | **C** |
| 13 | Admin · Usuario ↔ Empresa | `QueryAssignmentsBusinessCall` | `/admin/accesos/nueva` | **C** |
| 14 | Admin · Reporte → Usuario | `CrearAssigmentReportToUserBusinessIDCall`, `DeleteAssigmentReportUserCall` | `/admin/accesos` | **C** |
| 15 | Admin · Lista de usuarios | `QueryUsersCall`, `/usersManagment` | `/admin/usuarios` (lectura) | **C** |
| 16 | Admin · Crear usuario | `RegisterCall` | 2B (vista `v_user_alta`) | **M** (P3 a P4b) |
| 17 | Admin · Editar usuario ajeno | `UpdateUserProfileCall` con `id` | 2B (nombre y teléfono; correo bloqueado) | **M** (P4b) |
| 18 | Admin · Desactivar/activar | **no existe en Flutter** | 2B | capacidad nueva |
| 19 | Admin · Cambiar rol o contraseña | **sin llamada en el paquete** | P5 (bloqueado) | requisito nuevo |
| 20 | Admin · Panel de indicadores («Accesos Hoy», «Accesos este Mes», «Sesiones», «Registros», «Uso por Empresa», «Usuarios Activos», «Total Asignaciones») | cadenas sin llamada de estadísticas | `/admin` (contadores desde listados) y `/admin/historial` | **M/O**: validar con el responsable |
| 21 | Token OAuth de Power BI | sin consumidor | bloqueado (403) | **O** (K1: retirar) |
| 22 | Intermediario Firebase `baltrek` | `e_("baltrek", …)` | No aplica | **O** al retirar Flutter |
| 23 | Recursos externos | CanvasKit y fuentes en `gstatic`, `powerbi-client` en `cdnjs`, proxies CORS de terceros | React compilado para servidor no los necesita [I] | **O** al retirar Flutter |

Dependencias que realmente deben migrarse o decidirse: acceso de empleados (HTTPS + R-PUB + lista blanca), Usuarios (P3 a P4b), edición del propio perfil, sustitución del registro público y validación del panel de indicadores y de la foto. No hay datos que migrar: la base es la misma.
