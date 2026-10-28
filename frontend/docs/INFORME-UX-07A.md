# UX-07A · Administración funcional de usuarios y accesos

Rama `feat/cervesur-ux-07a-functional-admin` (desde `main` = `fd0e32a`). Solo entorno local aislado con MySQL sintético.

## Qué puede hacer ahora el administrador (React ↔ PHP real)
1. Consultar **usuarios reales** (activos e inactivos): nombre, correo, rol, empresas, nº de informes y estado; búsqueda y filtros por rol/estado; paginación de presentación (25 por página) sobre una carga **completa** del servidor.
2. **Crear un usuario** (`POST /api/register`): validaciones por campo, indicador de guardado, rechazo de PHP visible dentro del diálogo y listado refrescado al guardar. No se envían `role`, `is_active` ni `session_version`.
3. Consultar empresas e informes (ya integrados) y **asociar al usuario a una empresa** y **asignarle uno o varios informes de esa empresa** con un asistente de 4 pasos + resultado por informe.
4. Consultar los **accesos** vigentes y **retirarlos** con confirmación (baja lógica).
5. Iniciar sesión con el usuario creado: solo ve los informes autorizados.

React nunca indica «guardado» si PHP respondió `success:false`; cada escritura se valida contra `success:true` y los listados se vuelven a pedir al servidor tras cada cambio.

## Endpoints utilizados
| Operación | Endpoint |
|---|---|
| Usuarios | `GET /api/users?status=all&per_page=100&page=n` (todas las páginas) |
| Relación usuario ↔ empresa | `GET /api/empresa-usuario?view=minimal`, `POST /api/empresa-usuario` |
| Accesos efectivos | `GET /api/empresa-reporte-usuario?view=minimal`, `POST` y `DELETE /api/empresa-reporte-usuario[/{id}]` |
| Catálogo | `GET /api/empresas`, `/api/reportes`, `/api/asignacion` (`view=minimal`) |
| Alta | `POST /api/register` (solo administradores, SEC-05A) |

### Los tres identificadores (no se confunden)
| Id | De dónde sale | Para qué se usa |
|---|---|---|
| `id` numérico de `reporte` | `/api/reportes` | solo para relacionar el UUID con `/api/asignacion` |
| `reporte_id` (UUID de Power BI) | `/api/reportes` | identifica al informe en React y en el visor |
| `empresa_reporte_id` | `id` de `/api/asignacion` | **único valor que acepta `POST /api/empresa-reporte-usuario`** |
| `id` de `user_empresa_reporte` | `/api/empresa-reporte-usuario` | **único valor que acepta `DELETE /api/empresa-reporte-usuario/{id}`** |

## Extensiones mínimas de PHP (aditivas, compatibles con Flutter)
- `GET /api/empresa-usuario` y `GET /api/empresa-reporte-usuario`: nuevos campos `user_id`, `empresa_id` (y `empresa_reporte_id`). Sin ellos no se podían relacionar las filas con usuarios, empresas ni con el alta. Las claves históricas no cambian.
- Dos vistas opt-in `?view=minimal` para esos listados (solo ids y estado: sin `reporte_enlace`, `created_by*` ni notas). Sin `view` la respuesta conserva todos los campos.
- `POST /api/empresa-usuario`: exige empresa y usuario **activos**; una relación vigente no se duplica y una retirada se **reactiva**.
- `POST /api/empresa-reporte-usuario`: exige usuario activo, relación empresa↔informe vigente (empresa e informe activos) y que el usuario esté relacionado con esa empresa; no duplica y reactiva una retirada. Con un esquema real con claves únicas, el `INSERT` fallaría tras una retirada; la reactivación lo evita.
- Sin N+1: cada pantalla usa un número fijo de listados paginados, nunca una consulta por usuario o informe.
- Fixture de pruebas: `AUTO_INCREMENT` en los `id` de `tests/isolated/seed.php` (el esquema real ya lo tiene) para poder crear filas.

## Resultados de pruebas (local, aislado)
| Suite | Resultado |
|---|---|
| PHP lint / estáticas / unitarias de seguridad | 46 archivos · 12 · OK |
| HTTP base | 50 de 50 |
| HTTP paginación (PR #4) | 228 de 228 |
| HTTP SEC-05A | 45 de 45 |
| HTTP UX-07A (nuevo) | 36 de 36 |
| Contratos React–PHP | 136 PASS / 0 FAIL (4 hallazgos informativos previos) |
| E2E React–PHP real | 118 PASS / 0 FAIL (incluye el escenario de 14 pasos) |
| E2E simulados | 84 PASS / 0 FAIL |
| Frontend: ESLint, typecheck, unitarias | 0 avisos · OK · 101 de 101 |
| Escaneo de secretos | limpio |

## Limitaciones para completar el MVP
- Sin edición de usuarios (nombre, correo, teléfono), cambio de rol, desactivación/reactivación de usuarios ni restablecimiento de contraseña. La contraseña inicial la define quien crea el usuario.
- Un usuario con rol administrador no necesita asignaciones; no hay forma de promover a administrador desde la interfaz (existe `PUT /api/user/{id}/role`, sin pantalla a propósito).
- Retirar un acceso no retira la relación usuario↔empresa (se conserva). No hay pantalla para quitar empresas.
- Los accesos que existen en MySQL pero no son efectivos (p. ej. el usuario ya no pertenece a la empresa) no aparecen en la lista de accesos del backend, así que no pueden retirarse desde aquí.
- No hay creación/edición de empresas ni informes ni de la relación empresa↔informe (la asignación de informes a empresas debe existir).
- La paginación es de presentación sobre carga completa; con decenas de miles de usuarios convendría filtrar y paginar en el servidor.
- Power BI sigue simulado. Sin auditoría administrativa, sin limitador de login (SEC-05A.3 solo diseñado) y sin pruebas con el Flutter real.
- Las suites contra PHP real siguen sin ejecutarse en GitHub Actions (el CI del backend sí ejecuta las HTTP).

## Cierre del QA manual (realizado por el responsable del proyecto, entorno local aislado)
| Verificación | Resultado |
|---|---|
| Administración React accesible | Verificado |
| Listado inicial | 8 usuarios |
| Alta de `operador.prueba` | Verificado: el listado pasa a 9 usuarios |
| Acceso a Empresa A / Reporte A | Verificado: asignaciones de 9 a 10 |
| Inicio de sesión de `operador.prueba` | Verificado: solo ve Reporte A |
| Visor simulado | Accesible |
| Retirada con confirmación | Verificado: mensaje de éxito, asignaciones de 10 a 9 |
| Nuevo inicio de sesión tras el retiro | Verificado: sin informes disponibles |
| Recarga manual (F5) tras el retiro | **Sin confirmación manual registrada.** La persistencia tras recargar solo está verificada por la prueba E2E automatizada (React ↔ PHP ↔ MySQL sintético), no por el QA manual. |
