# Matriz de paridad Flutter → React · módulo Administrador (UX-07E.B, bloque B1)

Fuente: el código del backend PHP del repositorio (`backend/router/*.php`, controladores y modelos) y las pantallas React existentes (`frontend/src/features/admin`). **El código del cliente Flutter no está en el repositorio ni en las carpetas del proyecto**, por lo tanto lo que sigue es lo que el *backend* permite a un administrador; no se afirma nada sobre pantallas de Flutter que no se pudieron ver. No se inventó ninguna funcionalidad.

Leyenda de la columna React: **L** = consulta (lectura) implementada en el piloto administrativo (`piloto_admin`, producción en solo lectura) · **Q** = acción de escritura implementada en la interfaz React y probada solo contra el backend sintético de QA aislado (`read_write`) · **—** = no existe / no se desarrolla.

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
| Pantallas de Flutter no visibles | El código del cliente Flutter no está disponible; si el cliente quiere paridad pantalla por pantalla, hace falta acceso a ese código o capturas |
