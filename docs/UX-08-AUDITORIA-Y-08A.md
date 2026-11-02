# UX-08 · Auditoría técnica y UX-08A (filtros activos por defecto)

Rama: `feat/cervesur-ux-08a-active-filters` (base `origin/main` en `703e92d`). Sin migraciones, sin cambios de infraestructura.

## 1. Arquitectura encontrada

| Pieza | Dónde | Notas |
|---|---|---|
| Backend PHP | `backend/` (router → controller → model, PDO) | Una conexión por proceso (`backend/lib/db.php`, variables `DB_*`). |
| React trabajadores y administrador | `frontend/` (misma SPA, rol decide la vista) | `features/admin/*` es la parte administrativa. |
| Flutter | **No está en el repositorio** | Solo hay evidencia indirecta (`docs/BACKLOG-SEGURIDAD-UX-06B0.md`, B-06). |
| Bases | Flutter (80): `cervesur_portalbi`. React 8088: `cervesur_react_prueba`. React compat 8089: `cervesur_react_compat_qa` | `docs/ENTORNO-COMPATIBILIDAD-8089.md`. React no se conecta a `cervesur_portalbi` (NO-GO vigente en `docs/PLAN-TRANSICION-DATOS-PRODUCTIVOS.md`). |

## 2. Tablas reales

El repositorio **no contiene el DDL productivo**. Lo siguiente sale de los fixtures (`tests/isolated/seed.php`, `productive_fixture.php`) y del inventario de `docs/PLAN-TRANSICION-DATOS-PRODUCTIVOS.md`. FK, UNIQUE y triggers de `cervesur_portalbi` están **por confirmar** (consultas V3 en `database/schema/UX-07D3A-*.sql`).

| Tabla | PK | Estado | Relación |
|---|---|---|---|
| `user` | `id` | `is_active` (1/0) | `role` = `admin` / `user` / `usuario` (heredado) |
| `empresa` | `id` | `activo` | — |
| `reporte` | `id` | `activo` | `reporte_id` es referencia histórica (no UUID, no único) |
| `empresa_usuario` | `id` | `activo` | `user_id`, `empresa_id` |
| `empresa_reporte` | `id` | `activo` | `empresa_id`, `reporte_id` (= `reporte.id`) |
| `user_empresa_reporte` | `id` | `activo` | `user_id`, `empresa_reporte_id` (acceso efectivo) |

`empresa_reporte_usuario` y `asignacion` son nombres de **rutas**, no de tablas. Todas las bajas son lógicas (`activo = 0`). La unicidad se aplica en PHP, no en la base.

**Acceso vigente** (definición del servidor): `user_empresa_reporte.activo = 1` y `empresa_reporte.activo = 1` y `empresa.activo = 1` y `reporte.activo = 1` y existe `empresa_usuario` activo para ese usuario y esa empresa. El servidor **no** exige `user.is_active = 1` en el listado; la interfaz sí lo aplica (`frontend/src/domain/access.ts`).

## 3. UX-08A: qué cambia

### Backend (aditivo, sin migraciones)

Parámetro `status=active|inactive|all` en:

- `GET /api/empresas`
- `GET /api/reportes`
- `GET /api/asignacion`
- `GET /api/empresa-reporte-usuario`

| Caso | Resultado |
|---|---|
| Sin `status` | Idéntico a antes (solo activos). Flutter no cambia. |
| `status=active` | Igual que sin parámetro. |
| `status=inactive` / `status=all` | Solo administradores. Otros roles: **403**. |
| Valor desconocido o arreglo (`status[]=`) | **400**. |
| Sin sesión | **401** (la autenticación va antes). |

En `/api/empresa-reporte-usuario` y `/api/asignacion`, `inactive` es el complemento de «vigente» según la definición del servidor (arriba). Código: `backend/lib/status_filter.php`.

`/api/users` ya tenía `status` y no se toca. `GET /api/reportes/activos` sigue devolviendo solo activos.

### Frontend

- Valores iniciales: Usuarios **Activos**, Empresas **Activas**, Reportes **Activos**, Accesos **Vigentes**. Opciones por entidad: Activos/Inactivos/Todos y Vigentes/Sin efecto/Todos.
- Las pantallas administrativas piden `status=all`; las de trabajadores siguen sin enviarlo.
- Los contadores muestran «Mostrando *filtrados* de *total*», y el total incluye inactivos.
- Un acceso revocado se muestra como «Sin efecto · Acceso revocado».
- Filtro, búsqueda y paginación (solo Usuarios pagina en pantalla) se combinan; cambiar un filtro vuelve a la página 1.

## 4. Pruebas

| Suite | Resultado |
|---|---|
| Backend `php -l` (todo `backend/` y `tests/`) | OK |
| `tests/security_unit.php` y `python -m unittest discover -s tests` | OK (22 tests Python) |
| HTTP aislado: `http_test` / paginación / auth / admin / empresa-informe / visor | 50 / 228 / 45 / 36 / 67 / 56 PASS, 0 FAIL |
| HTTP aislado nuevo: `http_status_filter_test.php` | 49 PASS, 0 FAIL |
| Frontend: `tsc`, `eslint --max-warnings 0`, `vitest` | OK · 216 tests |
| E2E simulado `test:e2e` | 89 PASS / 0 FAIL |
| `test:contract` contra PHP real | 206 PASS / 0 FAIL / 4 hallazgos informativos del script (no analizados en esta entrega) |
| E2E contra PHP real `test:e2e:qa` | 186 PASS / 0 FAIL |
| `scan:secrets` | Sin hallazgos |

No ejecutado: `build:production` (exige `CERVESUR_ALLOWED_HOSTS`, paso de despliegue), CI en GitHub, Flutter.

## 5. Reversión

Sin migraciones ni datos modificados. Revertir los dos commits de la rama (`git revert`) restaura el comportamiento anterior. Los clientes que no envían `status` no se ven afectados en ningún caso.

## 6. Estrategia recomendada para UX-08B y UX-08C

**UX-08B (escrituras).** Hoy el backend ya expone `POST/PUT/DELETE` de empresas, reportes, asignaciones y accesos (todas exigen admin y hacen baja lógica), y `POST /api/register`. Lo que **no** existe: desactivar/reactivar usuarios, reactivar empresas e informes, editar usuarios, historial de cambios (auditoría) y transacciones explícitas. Antes de escribir sobre `cervesur_portalbi` hace falta (a) los privilegios reales de la cuenta MySQL, (b) el DDL real, (c) saber si Flutter depende de `session_version` y de `is_active`. Propuesta: empezar en las bases aisladas de los pilotos, añadir una tabla `audit_log` propia (actor, acción, entidad, antes/después sin secretos), y no tocar la base productiva hasta cerrar (a)–(c).

**UX-08C (vista previa administrativa).** Hoy el administrador ve cualquier informe activo sin lista de aprobación (`ReporteModel::authorizedReportSql` devuelve `''` para admin) y el servidor solo entrega URL si el enlace es «Publicar en la web». Para cumplir «no acceso universal» hace falta una lista explícita de informes aprobados para el piloto administrativo (tabla o configuración), decidida por el negocio. **DB031 / ID 72 no aparece en el repositorio**; no se aprueba nada automáticamente.
