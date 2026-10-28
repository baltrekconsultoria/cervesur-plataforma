# Informe UX-06A · Integración React + PHP + MySQL sintético

Integración técnica QA en un entorno local aislado. Sin producción, VPN, SSH, WinSCP ni Microsoft; sin MySQL real; sin dependencias nuevas; sin commits, push ni publicación. El repositorio del backend (`Revision-Codigo/cervesur`) quedó **sin ningún cambio** (`git status` limpio); se monta de solo lectura en los contenedores.

## 1. Fase 1 · Preparación

| Punto | Resultado |
|---|---|
| Estado de `Frontend-Dev` | UX-05.1 sin errores: 54 unitarias y 84 E2E en verde antes de tocar nada. |
| Referencia recuperable | Copia en `Frontend-Dev/_referencia-UX-05.1/` (136 archivos, sin `node_modules` ni `dist`, con su lockfile y `LEEME.txt`). `Frontend-Dev` no es un repositorio git. |
| Backend publicado | Rama local `security/cervesur-sec-04b` en `5a19ed9`, idéntica a `origin/security/cervesur-sec-04b`, árbol limpio. Verificado con git local, sin consultar la red. No existe la rama local `main`. |
| Versiones | Backend: PHP 8.3 (`php:8.3-cli`, imagen `cervesur-ci-php:local`). MySQL `mysql:8.0` (ambas imágenes ya estaban en caché local; no se descargó nada). Docker 29.7.2 (Docker Desktop). Node 24.20 / npm 11.19. Esquema: el de `tests/isolated/seed.php` (6 tablas) + extensión QA propia. |
| Contrato efectivo | Leído en el código PHP (routers, controladores, modelos, middleware) y **contrastado con respuestas reales** (`qa/probe.mjs`, `qa/contract.mjs`). Difiere de `API_DOCUMENTATION.md` (ver §5). |
| ESLint | **Propuesta pendiente de aprobación**, no instalado (§8). |
| Organización en GitHub | `docs/ORGANIZACION-GITHUB.md`, sin publicar. |

## 2. Fase 2 · Arquitectura local aislada

```
 Edge / navegador ──► Vite (127.0.0.1:4175)  ──/api──►  reenviador TCP (127.0.0.1:8080/8081)
   (mismo origen)       React modo "qa"                    cervesur-qa-proxy
                                                              │  red Docker interna (internal=true)
                                                    ┌─────────┴──────────┐
                                                    ▼                    ▼
                                           cervesur-qa-app       cervesur-qa-db
                                           PHP 8.3 + backend      MySQL 8.0 sintético
                                           (solo lectura)         (tmpfs, efímero)
                                           + cervesur-qa-app-short (JWT de 2 s)
```

- **Aislamiento verificado** (`npm run qa:status`): la API y MySQL viven **únicamente** en `cervesur-qa-db-net` (`internal=true`): salida a Internet `BLOQUEADA` y DNS externo `BLOQUEADO` desde ambos; ni la API ni MySQL publican puertos. Solo el reenviador publica `127.0.0.1:8080` y `127.0.0.1:8081` (nunca `0.0.0.0`, nada hacia la red corporativa).
- **Un primer diseño falló la comprobación y se descartó**: con la API en una red puente sin enmascaramiento, Docker Desktop seguía permitiendo salida a Internet (`1.1.1.1:80` ABIERTA). Se rediseñó con el reenviador `qa/proxy.php` (~60 líneas, solo copia bytes) para que la API no tenga ruta externa.
- Endurecimiento: contenedores `--read-only`, `--cap-drop ALL`, `no-new-privileges`, 256 MB, 100 procesos; MySQL en `tmpfs` (se destruye con `qa:down`).
- Secretos: la contraseña de MySQL y `JWT_SECRET` se generan al azar en cada arranque, existen solo en variables de entorno del proceso y de los contenedores, y no se escriben en ningún archivo. Las cuentas usan la contraseña sintética pública del fixture del repositorio (`synthetic-password`).
- Se reutilizan sin cambios `tests/isolated/Dockerfile`, `router.php` (que reemplaza HTTPS por un stub: el contenedor nunca llama a Microsoft) y `seed.php`.
- **Sin CORS**: el backend no emite cabeceras CORS (comprobado). El navegador habla con el origen de Vite y este reenvía `/api`; el proveedor HTTP acepta `VITE_API_BASE_URL=same-origin` y exige un origen loopback.

### Datos sintéticos
`seed.php` del repositorio (usera, userb, admin, inactive; Empresas A/B; Reportes A/B/C con asignaciones activas e inactivas) **más** `qa/seed-extra.php`: usuarios `legacy` (rol `usuario`), `weird` (rol `superadmin`) y `nobody` (sin asignaciones); empresa C inactiva vinculada a usera; informes «Ventas regionales», «Inventario norte», «Flujo semanal» y uno retirado; columna `user.created_at` (ver incidencia I-8). `session_version = 1` en todos.

## 3. Fase 3 · Endpoints integrados y respuestas verificadas

| Endpoint | Respuesta real verificada | Uso en React |
|---|---|---|
| `POST /api/login` | OK: `200 {success:true, token, user:{id, username, email, first_name, last_name, role}}`. **Error: `200 {success:false, message:"Credenciales inválidas"}`** (igual para contraseña incorrecta y usuario inactivo). `400` si falta un campo o el JSON es inválido. | Traduce `success:false` a 401 de login; guarda el token solo en memoria. |
| `GET /api/user/profile` | `200 {success, profile:{id, first_name, last_name, username, email, phone, avatar, role, last_login, is_active}}` | Verifica y refresca el perfil (nombre, teléfono, rol) en «Mi perfil». |
| `GET /api/empresas` | `200 {success, data:[{id, nombre, razon_social, ruc, ciudad, …, powerbi_tenant, powerbi_client, activo}], count}`; ya filtrado por usuario (empresa activa + `empresa_usuario` activo). Sin `powerbi_clientsecret`. | «Mis empresas»; el adaptador descarta `powerbi_*`. |
| `GET /api/reportes` | `200 {success, data:[{id, nombre, descripcion, enlace, grupo_id, reporte_id, created_by, created_by_username, activo, …}], count}`; filtrado por la cadena usuario→empresa→empresa_reporte→reporte (todo activo). | «Mis reportes»; usa `reporte_id` como identificador y descarta `enlace`, creador y grupo. |
| `GET /api/reportes/reporteid/{uuid}` | `200 {success, data}`; informe ajeno o inexistente: **`404 {success:false, message:"Reporte no encontrado"}`** idénticos byte a byte. | Visor simulado; 404 se muestra como acceso denegado neutral. |
| `POST /api/logout` | `200 {success:true}`; incrementa `session_version`. | Se llama al cerrar sesión; un 401 previo no se trata como error. |

No se consumen `/api/powerbi/tokens/{id}`, `/api/users`, `/api/asignacion` ni ninguna escritura. Una prueba estática lo impide (`security.test.ts`) y el E2E audita las solicitudes reales del navegador.

## 4. Fase 4 · Compatibilidad y seguridad aplicadas en el frontend

- **Política de roles (documentada en `adapters.ts`)**: `admin` → administrador; `user` → usuario; `usuario` (heredado) → usuario; **cualquier otro valor se rechaza** (`ApiError` `invalid_response`) y la sesión no se inicia. Sin degradación silenciosa. Comparación exacta en minúsculas, igual que la comparación estricta de `/api/users` en PHP.
- **Token solo en memoria**: sin `localStorage`, `sessionStorage` ni cookies (peticiones con `credentials: 'omit'`); verificado en el E2E tras cada 401, rechazo y recarga.
- **401/403/404**: 401 en cualquier petición cierra la sesión local, vacía la contraseña, conserva el usuario y retoma la sección (nunca un informe concreto). 403 y 404 se presentan idénticos y sin nombres.
- **Capacidades**: `ApiCapabilities` indica lo que cada proveedor entrega. Con HTTP real la interfaz **oculta** los filtros de empresa y grupo, la etiqueta de empresa/grupo y las columnas que el backend no proporciona, en vez de inventarlos; los listados de usuarios y asignaciones muestran «se conecta en la siguiente etapa».
- **Nada técnico confidencial en pantalla**: se comprobó que el HTML renderizado no contiene `enlace`, `tenant-*`, `client-*` ni `created_by`.
- **Permisos en PHP, no en React**: las pruebas de contrato llaman al backend directamente, sin navegador (§6).

## 5. Incidencias de compatibilidad encontradas

| # | Incidencia | Impacto | Tratamiento |
|---|---|---|---|
| I-1 | **Login fallido responde HTTP 200 con `success:false`** (no 401), contra lo que documenta `API_DOCUMENTATION.md`. | Un cliente que mire solo el estado HTTP daría por bueno el login. | El cliente HTTP lo traduce a 401 de login. Recomendable que PHP devuelva 401. |
| I-2 | El usuario del login trae `id`, `first_name`, `last_name` (no `user_id`/`nombre` como suponía UX-05.1). | Adaptador de UX-05.1 incorrecto. | Reescrito y probado con las formas reales. |
| I-3 | **`GET /api/reportes` no devuelve empresa ni nombre de grupo** (solo `grupo_id` UUID). | No se puede filtrar por empresa/grupo ni mostrar la empresa del informe con los 5 endpoints autorizados. | La interfaz se degrada (§4). Propuesta UX-06B: `GET /api/empresa-reporte-usuario/user/{id}` (solo lectura, ya aislado en PHP) devuelve `empresa_nombre`, `reporte_nombre`, `reporte_id` y `grupo_id`; falta un nombre de grupo. |
| I-4 | **`GET /api/reportes` envía `enlace`** (URL de Power BI), `created_by` y `created_by_username` a todo usuario autorizado (`SELECT r.*`). | Exposición innecesaria; riesgo si el enlace es público o permite acceso directo. | El frontend lo descarta. Corregir en PHP con columnas explícitas. |
| I-5 | **`GET /api/empresas` envía `powerbi_tenant` y `powerbi_client`** a usuarios no administradores (el secreto sí está excluido). | Datos técnicos de la integración Power BI. | El frontend los descarta. Quitarlos del `SELECT` para no administradores. *Corrige el informe UX-05.1, que decía «secreto de cliente expuesto» basándose en la documentación.* |
| I-6 | El backend emite roles **no normalizados** (`usuario`, `superadmin`) y acepta el login. Los controles `strtolower(role) === 'admin'` (la mayoría) y `role !== 'admin'` (`/api/users`) no lo elevan; verificado: ninguno obtiene privilegios. | Inconsistencia latente (mayúsculas distintas se tratarían distinto según el endpoint). | El frontend rechaza lo inesperado. Normalizar roles en PHP/BD. |
| I-7 | `GET /api/user/profile` devuelve `phone: null`, `avatar: null`. | Ninguno. | Adaptador tolera nulos. |
| I-8 | El esquema del **fixture** del repositorio no tiene `user.created_at`, que `UserModel::getAllUsers()` consulta: `GET /api/users` falla (`200 {success:false}`, otra vez error con HTTP 200). | `/api/users` inutilizable en el entorno aislado. | Solo se añade la columna en `qa/seed-extra.php`; el fixture del repositorio no se tocó. Alinear el esquema de prueba con producción. |
| I-9 | La documentación no menciona 403 en reportes (se usa 404) y describe formas de respuesta distintas. | Confusión. | Documentado aquí; el contrato real queda congelado en `qa/contract.mjs`. |
| I-10 | `docker run` con red puente sin enmascaramiento **no** bloquea la salida en Docker Desktop. | Falsa sensación de aislamiento. | Se rediseñó con red `internal` + reenviador (§2). |

## 6. Resultados de pruebas

| Suite | Resultado |
|---|---|
| Typecheck (`tsc --noEmit`, estricto) | sin errores |
| Compilación de producción y modo QA | OK (307 kB JS / 96 kB gzip) |
| Unitarias y de componentes (Vitest) | **68/68** (las 54 de UX-05.1 más 14 nuevas; las pruebas de adaptadores/HTTP se reescribieron porque el contrato cambió; el resto se mantiene) |
| E2E modo simulado (UX-05.1, sin cambios) | **84/84** |
| **Backend CI local** (PHP lint 38 archivos, 15 unitarias de seguridad, 9 estáticas Python, 50 HTTP aisladas) | **0 fallos** |
| **Contrato y permisos contra PHP real** (`npm run test:contract`) | **56 PASS / 0 FAIL / 5 hallazgos** |
| **E2E navegador → React → PHP real** (`npm run test:e2e:qa`) | **66/66** |
| `npm ci --ignore-scripts` y `npm audit` | lockfile idéntico, **0 vulnerabilidades** (no se añadieron dependencias) |

### Cobertura de la lista de validaciones solicitada

| # | Validación | Dónde | Resultado |
|---|---|---|---|
| 1 | Login real usuario | contrato + E2E | PASS |
| 2 | Login real administrador | contrato + E2E | PASS (admin → `/admin`, métricas desde PHP) |
| 3 | Credenciales incorrectas | contrato + E2E | PASS; mensaje del backend, contraseña vacía, sin sesión |
| 4 | Usuario inactivo | contrato + E2E | PASS; mismo mensaje que credenciales incorrectas (no revela estado) |
| 5 | JWT expirado | contrato (8081, 2 s) + E2E | PASS; 401 → login con aviso, contraseña vacía, usuario conservado, reingreso retoma la sección |
| 6 | Logout y revocación | contrato + E2E | PASS; `POST /api/logout`, el token y los previos del mismo usuario → 401, otros usuarios intactos; sesión revocada desde «otro dispositivo» → 401 en el navegador |
| 7 | Reportes propios y ajenos | contrato + E2E | PASS (ver §7) |
| 8 | Empresas autorizadas y no | contrato + E2E | PASS (ver §7) |
| 9 | Filtros de búsqueda sobre datos de PHP | E2E | PASS: nombre, descripción, mayúsculas, tildes, sin resultados, limpiar |
| 10 | Listado ↔ visor simulado | E2E | PASS: abrir, volver con filtro y foco, Atrás del navegador |
| 11 | Recarga y sesión en memoria | E2E | PASS: recargar vuelve a `/login`, sin restaurar nada y sin aviso 401 |
| 12 | Errores HTTP y recuperación | E2E | PASS: backend detenido → error recuperable → backend vuelve → «Reintentar» sin reingresar |
| 13 | Ausencia de llamadas productivas | E2E + estado de red | PASS: todas las solicitudes a `127.0.0.1:4175`; solo 6 rutas `/api` autorizadas; ninguna a `/api/powerbi`; contenedores sin salida |

## 7. Evidencia de aislamiento entre usuarios

Comprobada en PHP con tokens reales (`qa/contract.mjs`) y reflejada en la interfaz (E2E):

| Quién | Informes (`/api/reportes`) | Empresas (`/api/empresas`) | Perfil ajeno |
|---|---|---|---|
| usera | Flujo semanal, Inventario norte, Reporte A, Ventas regionales (4) | Empresa A (no B; la empresa C inactiva no aparece) | `GET/PUT /api/user/profile/2` → **403** |
| userb | Reporte B (1) | Empresa B | — |
| nobody (sin asignaciones) | lista vacía (200, no error) | lista vacía | — |
| legacy (rol `usuario`) | Reporte A (1); `/api/users` → 403 | — | — |
| weird (rol `superadmin`) | — ; `/api/users` → 403 (sin privilegios) | — | — |
| admin | los 6 informes activos | A y B (activas) | `GET /api/user/profile/2` → 200 |

Además: informe de otra empresa por UUID o por id numérico → 404, **indistinguible** de uno inexistente (mismo estado y mismo cuerpo); empresa ajena por id o búsqueda → sin datos; crear empresa o reporte y cambiar roles siendo usuario → 403; sin token, token basura, firma con otra clave, `alg:none` y un JWT que se auto-asigna `role:admin` → 401. Ninguna respuesta contiene `powerbi_clientsecret`, valores privados del fixture, hashes de contraseña ni rutas internas.

## 8. Diferencias entre el proveedor simulado y el HTTP

| Aspecto | Simulado (`mock`) | HTTP (modo `qa`) |
|---|---|---|
| Fuente | datos en memoria del navegador | PHP + MySQL sintético |
| Login fallido | 401 | HTTP 200 `success:false` → traducido a 401 |
| Roles | `user`/`admin` | `user`/`admin`/`usuario`; el resto se rechaza |
| Identificador del informe | `rep-N` | `reporte_id` (UUID) |
| Empresa y grupo por informe | sí (filtros y etiquetas) | **no** (se ocultan) |
| Acceso denegado | 403 | 404 (mismo tratamiento visual) |
| Perfil | editable | solo lectura (escritura no integrada) |
| Admin: usuarios/asignaciones | listados completos | «se conecta en la siguiente etapa» |
| Admin: empresas/reportes | listados completos | listados reales con las columnas disponibles |
| Controles de demostración | escenarios 401/403/vacío y «Restablecer» | ninguno; insignia «QA · backend local aislado» |
| Sesión vencida | simulada | real (JWT vencido o `session_version` revocada) |
| Activación | por defecto | `--mode qa`; la fábrica exige `VITE_ENABLE_HTTP_PROVIDER=true` y un origen localhost |

## 9. Archivos

**Modificados** (`src/`): `api/types.ts`, `api/errors.ts` (sin cambios de lógica), `api/factory.ts`, `api/mock/mockApi.ts`, `api/http/adapters.ts`, `api/http/httpApi.ts`, `app/AppProvider.tsx`, `app/DemoControls.tsx`, `features/{login,library,viewer,companies,profile,admin}/*`, `api/api.test.ts`, `test/security.test.ts`; `vite.config.ts` (proxy solo loopback), `package.json` (scripts), `.gitignore`, `README.md`, `docs/INFORME-UX-05.1.md` (corrección).
**Nuevos**: `qa/{backend.mjs,proxy.php,seed-extra.php,contract.mjs,probe.mjs}`, `e2e/run-qa.mjs`, `.env.qa.example`, `docs/{INFORME-UX-06A.md,QA-MANUAL-WINDOWS.md,ORGANIZACION-GITHUB.md}`, `docs/capturas-qa/` (17), `_referencia-UX-05.1/`. `e2e/cdp.mjs` amplía el registro de solicitudes.

## 10. Riesgos pendientes

1. **Backend sin corregir** (I-1, I-3, I-4, I-5, I-6): el frontend se defiende, pero el servidor sigue enviando datos innecesarios.
2. Sin empresa/grupo por informe no se pueden ofrecer los filtros aprobados en UX-04B/05.1 con el backend actual (decisión de producto/API para UX-06B).
3. Token solo en memoria: recargar cierra la sesión (decisión pendiente: cookie HttpOnly requiere cambios de backend y CSRF).
4. Sin CSP ni cabeceras de seguridad en Apache todavía.
5. Power BI real y escrituras administrativas siguen pendientes; `/api/register` fuera de alcance por lo indicado.
6. Los datos sintéticos validan el modelo de autorización, no el volumen ni el rendimiento (sin paginación en los listados).
7. El entorno QA depende de Docker Desktop y de imágenes locales; la verificación de aislamiento de red es específica de esta instalación y debe repetirse en cualquier otro equipo (`npm run qa:status`).
8. `logout()` del cliente ignora un fallo de red al revocar en el servidor (el token local se elimina siempre); la sesión del servidor quedaría viva hasta expirar.

## 11. ESLint · propuesta pendiente de aprobación (no instalado)

| Paquete | Versión | Propósito |
|---|---|---|
| `eslint` | 10.13.0 | Motor de análisis (Node ≥ 24 cumple `^20.19 \|\| ^22.13 \|\| >=24`) |
| `@eslint/js` | 10.0.1 | Reglas base recomendadas |
| `typescript-eslint` | 8.72.1 | Reglas TypeScript (admite TS `>=4.8.4 <6.1`, nuestro 5.9.3 ✓; ESLint 8–10) |
| `eslint-plugin-react-hooks` | 7.1.1 | Reglas de hooks (ya hay dos comentarios `eslint-disable` que lo anticipan) |
| `globals` | 17.13.0 | Variables globales de navegador/Node |

Configuración prevista (`eslint.config.js`): `recommended` + `strictTypeChecked` parcial, `no-floating-promises`, `no-explicit-any`, `consistent-type-imports`, `react-hooks/exhaustive-deps`, y una regla local que prohíba `localStorage`/`sessionStorage`. Instalación con `npm install --ignore-scripts --save-exact`, `npm ci --ignore-scripts` y `npm audit` posterior. **Indique si lo aprueba.**

## 12. Pendiente de autorización

No se han creado commits ni Pull Requests. Detenido tras la validación local, a la espera de revisión.
