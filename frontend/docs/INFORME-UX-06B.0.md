# Informe UX-06B.0 · Contratos, catálogo y paginación

Alcance: `Frontend-Dev` y el backend local autorizado (`Revision-Codigo/cervesur`, rama local **`feat/cervesur-ux-06b0-api-contracts`**, creada desde `5a19ed9`). Solo MySQL y usuarios sintéticos. Sin producción, VPN, Microsoft ni dependencias nuevas (`package.json` solo cambió en UX-06A.1). **Sin commits, push, PR ni despliegue.** Power BI sigue simulado.

Documentos complementarios en el backend: `docs/UX-06B0-PAGINACION-Y-VISTAS.md` (diseño y contratos) y `docs/BACKLOG-SEGURIDAD-UX-06B0.md` (backlog de la Fase F).

## 1. Fase A · Preparación

| Punto | Resultado |
|---|---|
| Estado inicial | Frontend: lint 0, 68 unitarias, 84 E2E simuladas, 66 E2E HTTP. Backend: árbol limpio en `security/cervesur-sec-04b` (`5a19ed9`, publicada). |
| Referencia recuperable | `Frontend-Dev/_referencia-UX-06A.1/` (167 archivos, sin `node_modules`/`dist`); además sigue `_referencia-UX-05.1/`. |
| Rama del backend | `feat/cervesur-ux-06b0-api-contracts`. No existe `main` local; `origin/main` no se tocó. |
| Datos | Solo sintéticos (`example.invalid`, contraseña pública del fixture). |

Incidente propio resuelto: al revertir un parche fallido ejecuté `git checkout backend/`, lo que también descartó cambios aún sin confirmar de la misma fase; se rehicieron desde los parches y se verificaron con la batería completa. No hubo pérdida de trabajo previo confirmado.

## 2. Fase B · Diseño final de paginación (servidor)

- **Parámetros**: `page` ≥ 1 y `per_page` 1–100 (máximo 100). Inválidos → **400**, nunca se ignoran.
- **Respuesta**: se conservan `success`, `data`, `count`, `message` y se añade `pagination { page, per_page, total, total_pages, has_more }`.
- **Seguridad**: filtros de identidad, pertenencia y estado activo dentro de la consulta **antes** de contar y paginar; `total` = filas autorizadas. `LIMIT`/`OFFSET` enlazados como enteros; `ORDER BY` determinista con desempate por id (`created_at DESC, id DESC`).
- **Compatibilidad**: sin parámetros, los listados con `LIMIT 20` devuelven **las mismas 20 filas** (ya con `pagination.total` real) y los que no tenían límite siguen sin límite. El límite de 20 no se eliminó: se sustituyó por un mecanismo controlado.
- **Alcance aplicado (11 listados)**: `empresa-usuario` (general, por nombre, por RUC), `empresa-reporte-usuario` (general, por nombre, por RUC, por usuario), `reportes` (y `activos`), `empresas`, `asignacion` y `users`. Una sola implementación: `backend/lib/pagination.php`.
- **Cliente React**: el proveedor HTTP lee **todas** las páginas (`per_page=100`), exige el bloque `pagination`, un total estable entre páginas y que las filas recibidas coincidan con el total; si algo falla, el catálogo falla completo (no hay resultados parciales presentados como definitivos).

## 3. Fase C · Datos mínimos (`?view=minimal`)

Opt-in: sin el parámetro, la respuesta es idéntica a la actual. Con él, lista blanca tras la autorización (`/api/reportes`: `id, reporte_id, nombre, descripcion, activo`; `/api/empresas`: `id, nombre, razon_social, ruc, ciudad, pais, logo, activo`; `/api/asignacion`: `id, empresa_id, empresa_nombre, reporte_id, reporte_nombre, activo`). Se evita enviar `enlace`, `created_by*`, `powerbi_tenant/client`, contacto y notas a la biblioteca. **El mecanismo de Power BI no se tocó.** Evaluación de compatibilidad (bundle de Flutter, solo lectura y solo conteo de nombres de campo): usa `enlace`, `powerbi_*`, `created_by`, `reporte_id`, `grupo_id`, `empresa_nombre`, `reporte_nombre`; **no** usa `page`, `per_page` ni `view` → por eso la vista es opt-in y no se eliminó ningún campo existente. Contrato objetivo y cambios futuros: ver `docs/UX-06B0-PAGINACION-Y-VISTAS.md` §4.

### Contratos antes y después

| Consumidor / endpoint | Antes | Después |
|---|---|---|
| `GET /api/empresa-usuario` (sin parámetros) | ≤ 20 filas, sin total, orden no determinista | las mismas 20 filas, orden determinista, `pagination.total` real |
| `GET /api/empresa-usuario?per_page=100&page=2` | (ignorado) | página 2 de 100, `has_more` |
| `GET /api/reportes` | todos los autorizados, con `enlace`, `created_by*`, `grupo_id` | igual por defecto; con `?view=minimal&per_page=100` solo la lista blanca y paginado |
| `GET /api/empresas` | con `powerbi_tenant/client` y contacto | igual por defecto; con `view=minimal` lista blanca |
| `GET /api/asignacion` | doble envoltura con `created_by`, `notas` | igual por defecto; con `view=minimal` lista blanca + `pagination` |
| **React (proveedor HTTP)** | `/api/reportes` completo, sin empresa por informe; filtro de empresa oculto | `/api/reportes`+`/api/asignacion`+`/api/empresas` con `view=minimal`, todas las páginas; catálogo con empresa(s) por informe; filtro de empresa activo |

## 4. Fase D · Catálogo por empresa en React

- `composeReportCatalog`: une `/api/reportes` (id numérico + UUID) con `/api/asignacion` y `/api/empresas`. Solo cuentan relaciones **activas** cuyo informe y cuya empresa fueron autorizados por PHP en sus propios listados; un informe en **varias empresas** aparece **una vez** con todas (`companyIds`, orden alfabético); pares y UUID duplicados se colapsan; un informe sin relación visible se muestra sin empresa y queda fuera de los filtros de empresa; **nunca se inventa empresa, grupo ni categoría**.
- Interfaz: filtro de empresa (con búsqueda combinada) y etiqueta «Empresa A · Empresa B» en tarjetas, visor y «Mis empresas» (conteo correcto de informes compartidos). **El filtro de grupo permanece oculto con el proveedor HTTP y se conserva en el simulado.**
- Administrador y usuario reciben exactamente el conjunto que PHP autoriza a cada uno (6 informes / 2 empresas vs. los propios).
- Errores: cualquiera de las tres lecturas que falle → pantalla de error con «Reintentar», sin lista parcial; un 401 sigue cerrando la sesión.

## 5. Fase E · Pruebas ejecutadas

| Suite | Resultado |
|---|---|
| `npm run lint` | **0 errores / 0 avisos** |
| Typecheck · build producción · build `qa` | OK |
| Unitarias y de componentes | **81/81** (68 previas ajustadas + 13 nuevas: adaptador de catálogo, paginación, errores parciales, varias empresas) |
| E2E modo simulado | **84/84** (sin cambios) |
| **Backend** · lint PHP | 42 archivos |
| **Backend** · seguridad PHP y estáticas | 15 + 9, todas OK |
| **Backend** · HTTP base | **50/50** (sin cambios) |
| **Backend** · HTTP paginación, vistas y compatibilidad | **228/228** (nuevas) |
| Contrato contra PHP real (`qa/contract.mjs`) | **99 PASS / 0 FAIL / 5 hallazgos** (56 previos + 43 nuevos) |
| E2E navegador → React → PHP real | **78/78** (66 previos + 12 nuevos) |
| `npm audit` · `npm ci --ignore-scripts` | 0 vulnerabilidades · lockfile idéntico |
| Aislamiento QA | API y MySQL solo en red interna; Internet y DNS bloqueados; sin puertos propios |

**Matriz de volumen pedida (suite PHP)**: usuarios con **0, 1, 20, 21, 45 y 105** relaciones (+ uno con relaciones inactivas y sobre recursos inactivos; el administrador ve ≥ 199). Para cada uno, en `empresa-usuario` y `empresa-reporte-usuario`: tamaño por defecto heredado, recorridos con `per_page` 10, 20 y 100, **primera, intermedia, última y fuera de rango**, total constante, **sin duplicados** entre páginas, **orden estable** (incluidas marcas de tiempo repetidas), número exacto de páginas, 11 variantes de parámetros inválidos por endpoint, búsquedas por nombre y RUC, y listados sin límite previo (el total paginado = lista completa). El volumen mayor de 100 relaciones corresponde a los 105 del usuario `pg105` y a las ~199 del administrador. El entorno QA pequeño (E2E) usa datos reducidos por diseño.

### Conciliación 50 vs 51 pruebas HTTP
`http_test.php` contiene 49 apariciones textuales de `expectCase(` (1 es la definición de la función y 1 está dentro de `login()`), 47 llamadas de nivel superior y 3 invocaciones de `login()` en una sola línea → **50 casos ejecutados**, reproducidos en cuatro corridas. Con esta fase, el CI ejecuta **278** (50 + 228). La cifra de «51» no aparece en el repositorio ni en el historial del archivo (28 → 41 → 49 apariciones textuales en tres commits): es **documentación anterior**, no un caso perdido. Cantidad real ejecutada: 50 (antes) y 278 (ahora).

## 6. Evidencia de aislamiento por usuario

Comprobada en tres niveles (PHP directo, contrato contra la API real y navegador):

| Quién | Informes | Empresas | Pares empresa–informe (catálogo) |
|---|---|---|---|
| usera | 4 (Flujo semanal, Inventario norte, Reporte A, Ventas regionales) | Empresa A | los 4 solo en Empresa A |
| multi | 2 (Reporte A, Ventas regionales) | A y B | «Ventas regionales» en **A y B**, una sola vez |
| userb | Reporte B | Empresa B | Reporte B @ B |
| nobody | 0 | 0 | vacío (200, no error) |
| admin | 6 | A y B (activas) | 6 informes, sin duplicados |
| pg0 / pg1 / pg20 / pg21 / pg45 / pg105 | 0 / 1 / 20 / 21 / 45 / 105 | ídem | cada informe con su par y ninguno ajeno |

Además: los conjuntos de filas de los usuarios sintéticos son **disjuntos** en ambos listados de relaciones; el administrador contiene a todos; informe, empresa o relación ajena por id/UUID/nombre/RUC → sin datos (404, `success:false` o lista vacía) también con `view=minimal`; perfil ajeno → 403; `/api/users` con paginación sigue siendo solo administrador; autenticación obligatoria con cualquier parámetro. Ninguna respuesta mínima contiene `enlace`, `created_by*`, `powerbi_*`, contacto, notas, secretos ni `example.invalid`; el HTML renderizado tampoco.

## 7. Compatibilidad con consumidores anteriores

Aditiva y opt-in (detalle en `docs/UX-06B0-PAGINACION-Y-VISTAS.md` §3). Comprobado con pruebas: sin parámetros se conservan claves y campos (incluido `enlace` y el secreto de cliente **ausente** como antes); `LIMIT 20` conservado como tamaño por defecto; las 50 pruebas HTTP originales pasan sin modificar ni una aserción. **Diferencias a conocer**: (1) orden determinista donde antes no estaba definido; (2) bloque `pagination` nuevo; (3) 400 solo si el cliente envía parámetros inválidos; (4) `tests/isolated/seed.php` ahora incluye `user.created_at/updated_at` (alineación de fixture; una línea). **No verificado**: el cliente Flutter real (solo evidencia indirecta del bundle).

## 8. Fase F · Backlog registrado (no resuelto)

13 elementos con evidencia, severidad, propuesta y prueba asociada en `docs/BACKLOG-SEGURIDAD-UX-06B0.md`. Los pedidos: **B-01** registro público y roles; **B-02** normalización de roles; **B-03** sin límite de intentos de login; **B-04** 24 respuestas que devuelven mensajes internos de SQL; **B-05** `/api/stats` global; **B-06** validación con el Flutter productivo; **B-07** cabeceras de Apache. Además: errores con HTTP 200, empresa inactiva listada, datos innecesarios por defecto, grupo sin nombre y formas heterogéneas.

## 9. Archivos modificados

**Backend (rama local, sin commits)** — nuevos: `backend/lib/pagination.php`, `backend/lib/views.php`, `tests/isolated/seed_pagination.php`, `tests/isolated/http_pagination_test.php`, `docs/UX-06B0-PAGINACION-Y-VISTAS.md`, `docs/BACKLOG-SEGURIDAD-UX-06B0.md`. Modificados (20 archivos, +144/−133 líneas): controladores y modelos de `EmpresaUsuario`, `EmpresaReporteUsuario`, `Reporte`, `Empresa`, `Asignacion`, `User`; routers correspondientes; `tests/isolated/seed.php`; `.github/workflows/backend-ci.yml` (2 pasos nuevos).
**Frontend** — `src/api/{types,errors}.ts`, `src/api/http/{adapters,httpApi}.ts`, `src/api/mock/seed.ts`, `src/features/{library,viewer,companies,admin}/*`, pruebas (`httpApi.test.ts` nuevo, `catalog.test.tsx` nuevo, `api.test.ts`, `security.test.ts`), `qa/{backend,contract,seed-extra}`, `e2e/run-qa.mjs`, documentación y `_referencia-UX-06A.1/`.

## 10. Riesgos y decisiones pendientes

1. **Flutter productivo no probado** (B-06): hasta una prueba controlada, la vista mínima debe seguir siendo opt-in.
2. **Grupo**: sigue sin fuente real (B-11). Decisión de producto: tabla/campo de nombre o definitivamente ocultar.
3. **Catálogo provisional** = 3 peticiones × N páginas por carga: aceptable con los volúmenes sintéticos; medir con datos reales y considerar `empresas[]` en `/api/reportes`.
4. **Lecturas no atómicas**: si los datos cambian entre las tres lecturas, el cliente detecta totales inestables y reintenta; no hay transacción.
5. `enlace`/`powerbi_*` siguen enviándose **por defecto** a clientes que no piden la vista mínima (B-10).
6. Administración: listados completos requieren aún `/api/users` y relaciones paginadas en la UI (la paginación de servidor ya existe); CRUD sigue fuera de alcance.
7. Finales de línea CRLF: los archivos nuevos se normalizaron; falta `.gitattributes`.
8. Decisión sobre commits: dos repositorios/ramas (backend y frontend) o monorepo (ver `ORGANIZACION-GITHUB.md`).

## 11. Recomendación GO / NO-GO

| Acción | Recomendación | Condiciones |
|---|---|---|
| **Commit local** de los cambios del backend en la rama `feat/cervesur-ux-06b0-api-contracts` | **GO** | Revisión humana del diff de 20 archivos (en especial `pagination.php`, `views.php` y los 11 listados) y confirmar finales de línea. |
| **Commit local** del frontend (cuando exista su repositorio/carpeta versionada) | **GO** | Mantener `_referencia-*` y `dist*` fuera (ya en `.gitignore`/`ORGANIZACION-GITHUB.md`). |
| **Pull Request** (borrador, sin fusionar), backend y frontend por separado | **GO condicionado** | CI del backend en verde en GitHub con los dos pasos nuevos; aprobación del diseño de paginación/vistas; B-06 (Flutter) anotado como bloqueo de fusión. |
| **Fusión a `main` / despliegue a producción** | **NO-GO por ahora** | Faltan: prueba con el Flutter productivo en un entorno aislado (B-06), decisión sobre grupo (B-11), revisión de seguridad de B-01/B-03/B-04 y medición con datos de volumen real. |
| **Habilitar administración completa** | **NO-GO hasta** | UI de administración sobre los listados paginados (UX-06B.1) y mantener las escrituras fuera de alcance. |

Detenido tras las pruebas locales, a la espera de revisión. Sin publicación.
