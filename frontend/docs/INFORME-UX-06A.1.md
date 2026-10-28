# Informe UX-06A.1 · ESLint y preparación para la integración administrativa

Alcance: `Frontend-Dev`. Backend PHP solo en **lectura** (el árbol del repositorio sigue limpio). Sin cambios de contrato, JWT, base de datos productiva, escrituras administrativas, Power BI, commits ni publicación.

## 1. Dependencias instaladas

Instaladas con `npm install --save-dev --save-exact --ignore-scripts`, sin `--force` ni `--legacy-peer-deps`:

| Paquete | Versión | Compatibilidad verificada |
|---|---|---|
| `eslint` | 10.13.0 | `engines` `^20.19 \|\| ^22.13 \|\| >=24` (Node 24.20 ✓) |
| `@eslint/js` | 10.0.1 | peer `eslint ^10` ✓ |
| `typescript-eslint` | 8.72.1 | peers `eslint ^8.57–^10`, `typescript >=4.8.4 <6.1` (5.9.3 ✓, deduplicado) |
| `eslint-plugin-react-hooks` | 7.1.1 | peer `eslint` hasta `^10` ✓ |
| `globals` | 17.13.0 | sin peers |

- **Lockfile**: +126 entradas (transitivas del ecosistema ESLint). **Ninguna versión de un paquete existente cambió** y no se eliminó nada; las dependencias previas (`react`, `vite`, `vitest`, `typescript`, etc.) siguen exactamente iguales.
- `npm ls`: sin errores de peers; solo aparecen como «UNMET OPTIONAL» los peers opcionales de Vite (`sass`, `less`, `terser`…) que no usamos.
- Scripts de instalación: solo `fsevents` (macOS, no se instala en Windows); no se requirió autorizar ninguno. Origen único `registry.npmjs.org`.
- `npm ci --ignore-scripts` reproduce el lockfile **byte a byte**. `npm audit`: **0 vulnerabilidades**.

## 2. ESLint

**Configuración** (`eslint.config.js`, Flat Config, comando `npm run lint` = `eslint . --max-warnings 0`):
- Base: `@eslint/js` recomendado + `typescript-eslint` **recommendedTypeChecked** (usa información de tipos: `no-floating-promises`, `no-misused-promises`, `require-await`, `unbound-method`…) + `react-hooks` recomendado (v7, incluye las reglas del compilador de React: `set-state-in-effect`, `refs`, `purity`…).
- Tres entornos: **navegador** (`src/`), **pruebas** (`*.test.*`, `src/test/`; globales de navegador y Node) y **scripts Node** (`e2e/`, `qa/`, `vite.config.ts`, `eslint.config.js`).
- Reglas de proyecto: `consistent-type-imports`, `no-explicit-any`, `no-console` (salvo scripts) y **prohibición de `localStorage`, `sessionStorage`, `indexedDB`, `document.cookie` y `eval`** en el código de la aplicación (la política «token solo en memoria» ahora la hace cumplir el linter).
- `reportUnusedDisableDirectives: error`: un `eslint-disable` innecesario falla.
- Excluidos: `dist`, `dist-qa`, `node_modules`, `coverage`, `_referencia-UX-05.1`, `_respaldo*`, `docs` (incluye capturas), `e2e/.tmp`, `qa/.runtime`.
- Comprobado que las reglas **detectan** lo que deben con un archivo de prueba descartable: `setState` en efecto, dependencia faltante, `localStorage`, `document.cookie`, promesa sin esperar, `any`, `console` y hook condicional (9 avisos; el archivo se eliminó).

**Primera ejecución: 55 errores**, todos tratados; ahora **0 errores y 0 avisos**.

| Regla | Nº | Causa | Corrección (sin cambiar el comportamiento) |
|---|---|---|---|
| `require-await` | 27 | métodos `async` sin `await`: stubs «no integrados» del cliente HTTP (3), proveedor simulado (9), dobles `async () => json()` en pruebas (15) | Stubs HTTP → `return Promise.reject(...)` (mismo resultado). **Dos excepciones acotadas y comentadas en la configuración**: directorio `src/api/mock/` (los métodos son `async` a propósito para que cada `throw` sea un rechazo, como en el cliente real) y archivos de prueba. |
| `unbound-method` | 18 | firmas declaradas como método (`login(): …`, `onChange(v): void`) y luego desestructuradas | Solo tipos: `login: (…) => …`, `onChange: (v) => void`, etc. |
| `react-hooks/set-state-in-effect` | 3 | `useAsync`, cierre del menú móvil y formulario de perfil llamaban a `setState` dentro de efectos | Ajuste de estado durante el render (patrón documentado de React) en `useAsync` y menú; el perfil pasa a un componente `ProfileForm` que se remonta con los datos del servidor. |
| `react-hooks/refs` | 2 | `userRef.current = user` durante el render | Sesión como **reductor puro** (`sessionReducer`): sin ref. |
| `no-empty` | 3 | `catch {}` vacíos en `e2e/cdp.mjs` y `qa/probe.mjs` | Comentario que explica por qué se ignora el error. |
| `no-base-to-string` | 1 | `String(valor)` sobre un objeto en una prueba | `JSON.stringify`. |
| `no-unused-vars` | 1 | parámetro `_update` sin uso en un stub | Se elimina el parámetro. |

**Cambios de comportamiento reales (dos, ambos mejoras, cubiertos por pruebas)**
1. `useAsync` vuelve a «cargando» en el mismo render en que cambian las dependencias; antes pintaba un render con el dato anterior antes del efecto.
2. `updateUser` ya no puede «resucitar» una sesión cerrada (el reductor ignora la actualización si no hay usuario).

Además, se retiraron tres desactivados preventivos que había escrito para las pruebas (`no-unsafe-*`, `unbound-method`) porque el lint pasaba sin ellos. **Ninguna regla se desactiva globalmente.** No fue necesaria ninguna refactorización funcional mayor.

## 3. Regresión

| Comprobación | Antes (UX-06A) | Ahora |
|---|---|---|
| `npm run lint` | — | **0 errores / 0 avisos** |
| Typecheck estricto | OK | OK |
| Compilación producción y modo `qa` | OK | OK (JS ≈ 307 kB / 96 kB gzip) |
| Unitarias y de componentes | 68 | **68/68** |
| E2E modo simulado | 84 | **84/84** |
| E2E navegador → React → PHP real | 66 | **66/66** |
| Contrato y permisos contra PHP real | 56 PASS · 5 hallazgos | **56 PASS · 0 FAIL · 5 hallazgos** (idénticos) |
| CI del backend (PHP lint 38 archivos · 15 unitarias · 9 estáticas · HTTP aisladas) | 0 fallos | **0 fallos** (50 casos HTTP) |
| `npm audit` · `npm ci --ignore-scripts` | 0 · idéntico | **0 · idéntico** |
| Aislamiento QA | API y MySQL solo en red interna | **igual**: salida a Internet BLOQUEADA, DNS BLOQUEADO, db sin puertos, puertos solo en 127.0.0.1 |

**Sobre «50 vs 51 pruebas HTTP»**: `tests/isolated/http_test.php` contiene 49 apariciones textuales de `expectCase(` (1 es la definición de la función y 1 está dentro de `login()`), 47 llamadas de nivel superior y 3 invocaciones de `login()` en una sola línea; en ejecución se emiten **47 + 3 = 50 casos**, reproducidos en las dos ejecuciones de este informe y en `qa:backend-ci`. El historial del archivo muestra 28 → 41 → 49 apariciones textuales en tres commits; **no encuentro ninguna cifra de 51** en el repositorio ni en los documentos. Si procede de un informe anterior, probablemente sumó una línea de resumen (`Failures: 0`) o contó mal; no hay un caso perdido. Recomiendo que el CI imprima el total (`PASS n/n`) para evitar la ambigüedad.

## 4. Hallazgos de contrato PHP (diagnóstico de solo lectura)

Evidencia: lectura del código, `qa/diagnose.mjs` (22 endpoints × 2 perfiles + 18 casos de error), `qa/join-check.mjs` y `qa/contract.mjs`.

| # | Tema | Hallazgo | Severidad |
|---|---|---|---|
| 1 | **Campos técnicos en `/api/empresas`** | Para cualquier usuario autenticado devuelve 20 campos, incluidos `powerbi_tenant`, `powerbi_client`, `created_by/updated_by`, y datos de contacto de la empresa (`email`, `telefono`, `website`, `direccion`, `contacto_principal`). El secreto de cliente sí está excluido. `PowerBIModel` lo lee solo en el servidor. | Media |
| 2 | **Campos de `/api/reportes`** | `SELECT r.*` + `created_by_username`: expone `enlace` (URL de Power BI), `created_by`, `updated_by`, fechas y `grupo_id`. `/api/empresa-reporte-usuario` (lista general) también devuelve `reporte_enlace` y nombres de quien creó cada relación; `/api/asignacion` devuelve `created_by`, `updated_by` (nombre completo) y `notas`. | **Alta** (`enlace`) |
| 3 | **Relación empresa–informe y grupo** | `/api/reportes` no trae empresa ni nombre de grupo. **Se puede componer ya, sin cambios de backend**: `GET /api/reportes` (`id` numérico + `reporte_id` UUID) + `GET /api/asignacion` (`empresa_id`, `empresa_nombre`, `reporte_id` numérico) + `GET /api/empresas`. Verificado para 5 perfiles: los pares coinciden exactamente con lo autorizado (usera: 4 pares en Empresa A; userb: 1 en B; nobody: 0; admin: 6), sin ningún informe huérfano ni empresa no visible. **El nombre del grupo no existe en ninguna tabla ni endpoint** (solo `grupo_id`, UUID). | Alta (funcional) |
| 4 | **Esquema sintético vs esperado** | Comparados INSERT/UPDATE del código con el DDL del fixture: faltan **`user.created_at` y `user.updated_at`** (usados por `UserModel::getAllUsers`, que falla sin ellos, y por el `INSERT` de `/api/register`, que fallaría). Los demás avisos de una revisión automática eran falsos positivos de SELECT con joins; el barrido en ejecución lo confirma: **22/22 endpoints de lectura responden una vez añadida `created_at`**. Las escrituras no se ejercitaron. El fixture tampoco refleja `ON UPDATE CURRENT_TIMESTAMP` que el código da por existente (`EmpresaUsuarioModel`). | Media |
| 5 | **Roles** | `RegisterController` crea siempre `role = 'usuario'` (origen del rol heredado) y `UserModel` solo permite asignar `admin`/`user`. Los controles son inconsistentes: casi todos usan `strtolower(role) === 'admin'`, `UserRouter` línea 299 acepta `'admin'` y `'ADMIN'`, y `/api/users` (línea 368) solo `'admin'`. La columna es `VARCHAR(20)` sin restricción. Verificado: `usuario` y `superadmin` no obtienen privilegios, pero el login los acepta. | Media |
| 6 | **Login fallido y estados HTTP** | Login incorrecto/inactivo → **HTTP 200 `success:false`** (mismo mensaje: bien, no revela estado). Otros «errores» con HTTP 200: empresa ajena, relaciones ajenas, validación al crear empresa/reporte (`'nombre' es obligatorio`). Mezcla de 404 (informe, asignación, Power BI) y 200 para el mismo tipo de recurso ajeno. Sin 403 para recursos ajenos (se usa 404/200). **Sin limitación de intentos de login** en el código. `/api/user` y `/api/user/profile` duplican datos. `/api/stats` devuelve totales globales (`total_reportes`, `total_empresas`) a cualquier usuario. | Media |
| 7 | **Paginación y necesidades de administración** | **No existe paginación.** Hay `LIMIT 20` fijo en `GET /api/empresa-reporte-usuario` y `GET /api/empresa-usuario` (incluso para administradores): los listados se **truncan en silencio a 20 filas**. `/api/users`, `/api/asignacion` y `/api/reportes` no tienen límite. `/api/asignacion` responde `data.data` (doble envoltura). Sin parámetros de orden ni búsqueda en servidor. | **Alta** para UX-06B |
| 8 | **50 vs 51** | Ver §3: 50 emitidos, reproducible. | Informativa |

Hechos adicionales útiles: `/api/register` es **público** (sin autenticación), crea usuarios `usuario` activos y depende de que la BD aplique valores por defecto a `is_active` y `session_version` (el fixture sí los tiene); no se probó contra producción.

## 5. Esquema mínimo requerido para UX-06B (administración de solo lectura)

**A. Sin cambiar el backend (puede iniciar ya):**
- Catálogo de usuario: unir `/api/reportes` + `/api/asignacion` + `/api/empresas` en el cliente (adaptador `reportCatalog`) para tener `empresa` por informe y reactivar el filtro de empresa. Grupo: seguir oculto.
- Administración de lectura: `/api/users?status=all`, `/api/empresas`, `/api/reportes`, `/api/asignacion` funcionan. **Bloqueo**: `/api/empresa-usuario` y `/api/empresa-reporte-usuario` truncan a 20; sin ellos no se pueden mostrar «empresas por usuario» ni «usuarios con acceso por informe» de forma fiable.

**B. Cambio mínimo recomendado en el backend (aditivo, solo lectura):**

1. `GET /api/reportes` con columnas explícitas (sin `r.*`): `reporte_id`, `nombre`, `descripcion`, `grupo_id`, `activo`, y **`empresas: [{ id, nombre }]` solo con los pares que el usuario puede usar** (misma condición de autorización que hoy, proyectando `er`/`e`). Sin `enlace`, `created_by*`, `id` interno.
2. `GET /api/empresas`: para no administradores solo `id, nombre, razon_social, ruc, ciudad, activo, logo`; administrador conserva el detalle pero **sin** `powerbi_*` (solo el servidor los necesita).
3. `GET /api/grupos` o campo `grupo: { id, nombre }`: requiere un origen para el nombre (tabla `grupo(id UUID, nombre)` o equivalente). Hasta entonces el filtro de grupo permanece oculto.
4. Paginación aditiva en los listados administrativos: `?page=1&per_page=50` (máximo 200), orden estable (`created_at DESC, id DESC`) y envoltura `{ success, data, count, total, page, per_page }`; **eliminar el `LIMIT 20` silencioso**.
5. Error y roles: normalizar `usuario → user` (migración y valor por defecto de `/api/register`), `CHECK`/catálogo de roles, y una sola función de comprobación de rol en todos los routers.

**C. Pruebas que deben añadirse a `tests/isolated/http_test.php`**
- Forma del DTO (lista blanca de claves) para `/api/reportes` y `/api/empresas` en usuario y administrador; ausencia de `enlace`, `created_by*`, `powerbi_*`.
- `empresas[]` por informe: usuario ve solo sus pares; informe en dos empresas con acceso solo a una → un par; relación inactiva → ausente; administrador ve todos.
- Paginación: fixture con > 20 relaciones; `per_page`, `page`, `total` y orden estable; máximo de `per_page`.
- Roles: `usuario`/mayúsculas/desconocido no elevan privilegios en **todos** los endpoints administrativos (matriz endpoint × rol).
- Estados HTTP: matriz de errores acordada (login, validación, recurso ajeno).
- Esquema: la suite de fixtures debe incluir `user.created_at/updated_at` y recorrer todos los GET con un administrador (el barrido de `qa/diagnose.mjs` pasaría a ser un test).

## 6. Cambios recomendados

**Backend** (en este orden de valor/riesgo): ① dejar de enviar `enlace`/`created_by*` y `powerbi_*`; ② quitar el `LIMIT 20` o paginar; ③ añadir `empresas[]` a `/api/reportes`; ④ normalizar roles; ⑤ login fallido con 401 (cambio incompatible: mantener el cuerpo `success:false`; confirmar antes con el cliente Flutter aún en producción, y el frontend React ya acepta ambos); ⑥ validaciones con 400/422; ⑦ limitar intentos de login; ⑧ restringir `/api/stats` a administradores; ⑨ alinear el esquema del fixture con el esperado.

**Frontend**: (UX-06B) adaptador `reportCatalog` (unión de tres endpoints); reactivar filtro de empresa y etiqueta; páginas administrativas de lectura (usuarios, empresas, informes) con paginación del lado cliente mientras el servidor no pagine; `ApiCapabilities` granular (`reportCompany` ya existe); mantener el rechazo de roles inesperados; añadir las pruebas de contrato de §5.C a `qa/contract.mjs`.

## 7. Riesgos y criterios GO / NO-GO para UX-06B

**Riesgos**
1. `enlace` y datos de Power BI siguen viajando al navegador (el frontend los descarta, pero el tráfico los contiene).
2. Truncado silencioso a 20: una administración construida sobre esos endpoints mostraría datos incompletos **sin error visible**.
3. Sin grupo no hay filtro de grupo; sin acuerdo de API se corre el riesgo de inventar datos en la interfaz.
4. Cambiar el login a 401 puede romper el cliente Flutter aún desplegado.
5. Registro público y sin límite de intentos de login.
6. Las pruebas de contrato validan el modelo con datos sintéticos, no con el volumen real.

**GO para iniciar UX-06B (solo lectura)** si se cumplen:
- [x] Lint, typecheck, build y las cuatro suites del frontend en verde (cumplido).
- [x] Backend CI y contrato en verde; aislamiento QA verificado (cumplido).
- [ ] Decisión de producto sobre **grupo** (ocultar, o proveer nombre) y sobre el **catálogo por unión de endpoints** como solución provisional.
- [ ] Decisión sobre paginación: aceptar paginación del lado cliente provisional **o** aprobar el cambio de backend de §5.B-4 antes de construir las pantallas de relaciones.
- [ ] Aprobación para añadir las pruebas de §5.C en `qa/contract.mjs` (frontend) sin tocar el backend.

**NO-GO (detener y escalar) si:**
- cualquier prueba de aislamiento entre usuarios falla o un usuario obtiene datos de otro;
- aparece un secreto (`powerbi_clientsecret`, JWT, hash) en una respuesta;
- un rol no reconocido obtiene privilegios;
- el entorno QA deja de estar aislado (`qa:status` ≠ BLOQUEADA) o se necesita acceso a producción/VPN/Microsoft;
- se pretende habilitar escrituras administrativas o `/api/register` antes de corregir su seguridad.

## 8. Archivos

**Nuevos**: `eslint.config.js`, `qa/diagnose.mjs`, `qa/join-check.mjs`, `docs/INFORME-UX-06A.1.md`.
**Modificados**: `package.json` (scripts `lint`, `lint:fix`; 5 devDependencies), `package-lock.json`; `src/app/{AppProvider,layout}.tsx`, `src/components/ui.tsx`, `src/lib/useAsync.ts`, `src/features/{profile/ProfilePage,admin/AdminPages}.tsx`, `src/api/http/httpApi.ts`, `src/api/api.test.ts`, `e2e/cdp.mjs`, `qa/probe.mjs`, `README.md`.

Detenido al finalizar la revisión local. Sin commits ni Pull Requests.
