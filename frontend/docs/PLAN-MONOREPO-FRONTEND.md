# Plan de incorporación del frontend al monorepo `baltrekconsultoria/cervesur-plataforma`

Estado: **plan, sin ejecutar**. `Frontend-Dev` permanece intacto y no se publica en esta intervención. Se incorporará mediante un Pull Request separado, posterior a la revisión de este plan.

## 1. Inventario de lo que existe hoy (solo lectura, `inventory.mjs`)

| Origen | Contenido | Decisión |
|---|---|---|
| `src/`, `public/` | 39 archivos, 441 KB (incluye los 2 logotipos originales, 278 KB) | **Incorporar** → `frontend/src`, `frontend/public` |
| `e2e/` | 4 archivos (`cdp.mjs`, `run.mjs`, `run-qa.mjs`, `compare.mjs`) | **Incorporar** (ajustes en §3) |
| Configuración | `package.json`, `package-lock.json`, `tsconfig.json`, `vite.config.ts`, `eslint.config.js`, `index.html`, `.npmrc`, `.gitignore`, `.env.example`, `.env.qa.example` (sin credenciales), `README.md` | **Incorporar** → `frontend/` |
| `qa/` | 7 archivos: orquestador Docker, proxy, semillas, contrato | **Incorporar en la raíz** → `qa/` (lo usa también el CI del backend) |
| `docs/*.md` | 7 documentos (informes UX-05…06B.0, QA, monorepo) | **Incorporar** → `docs/frontend/` (curados) |
| `docs/capturas*` | 102 PNG, 8,5 MB | **Solo una selección** (§2) |
| `node_modules/`, `dist/`, `dist-qa/` | 139,6 MB / 0,6 MB / 0,6 MB | **Excluir** |
| `_referencia-UX-05.1/`, `_referencia-UX-06A.1/` | copias de respaldo locales (7 MB y 9 MB) | **Excluir** (el historial de git las sustituye) |

Escaneo sobre los 170 archivos candidatos (9,1 MB): **sin `.env` con valores, certificados, claves, JWT, tokens, URLs con credenciales ni secretos asignados**. El único nombre «sensible» es `src/api/tokenStore.ts` (almacén en memoria, sin datos). No hay archivos mayores de 500 KB.

## 2. Lista de exclusiones (`.gitignore` raíz y comprobación previa)

- `node_modules/`, `dist/`, `dist-qa/`, `coverage/`, `*.log`, `e2e/.tmp/`, `qa/.runtime/`.
- `.env` y `.env.*` salvo `.env.example` y `.env.qa.example` (ya contemplado).
- Copias `_referencia-*` y cualquier `_respaldo*`.
- **Flutter compilado histórico** (`Referencia-Frontend/`) y `UX-Prototipo/`: nunca entran (el primero puede contener JWT históricos; el segundo es el prototipo ya sustituido).
- Hojas de cálculo con usuarios o credenciales de la carpeta `Cervesur/` (`*.xlsx`).
- Contenedores y volúmenes QA: no se versiona nada (la base es `tmpfs`, las redes y contenedores se crean al ejecutar `qa:up`).
- **Capturas**: las carpetas `docs/capturas/prototipo`, `react-comparacion` y `diferencias` (60 PNG) son **derivadas y regenerables** con `compare.mjs` contra el prototipo, que no se incorpora → excluir. De `docs/capturas/react` y `docs/capturas-qa` (41 PNG) conservar una **selección curada de ≤ 12** (login, biblioteca, visor, 403, 401, catálogo multiempresa, admin) para la documentación. Todas son sintéticas (dominio `example.invalid`, usuarios ficticios), pero se revisan una a una antes de confirmar.
- Antes de cada commit: `git diff --cached --name-only` revisado y un escaneo de secretos (el mismo `inventory.mjs`, más `git secrets`/`gitleaks` en CI).

## 3. Estructura destino y cambios de rutas

```
cervesur-plataforma/
  backend/            (sin cambios)
  frontend/           src, public, e2e, package*.json, tsconfig, vite.config.ts, eslint.config.js, index.html, .npmrc, .env.example, .env.qa
  qa/                 backend.mjs, proxy.php, seed-extra.php, contract.mjs, probe.mjs, diagnose.mjs, join-check.mjs
  tests/              (existente)
  docs/               SEC-*, UX-06B0-*, BACKLOG-*, frontend/ (informes y guías curados)
  .github/workflows/  backend-ci.yml (existente) + frontend-ci.yml + integration-ci.yml
```

Ajustes necesarios al mover (se harán y probarán en el PR de incorporación, no ahora):
1. `qa/backend.mjs`: `REPO = join(here, '..')` (hoy apunta a `../../Revision-Codigo/cervesur`) y `QA = here`.
2. `e2e/compare.mjs` depende de `UX-Prototipo` → **no se incorpora**; `e2e/run.mjs` y `run-qa.mjs` sí.
3. `e2e/cdp.mjs`: la ruta de Edge está fija (Windows). Parametrizar con `BROWSER_PATH` (en el runner de Ubuntu, Chrome headless).
4. `package.json` scripts de QA: `qa:*` y `test:contract` apuntan a `../qa/*.mjs`.
5. `docs/` internos: reescribir rutas absolutas de Windows.
6. `.gitattributes` (PR aparte, previo): normalizar finales de línea (el repositorio mezcla CRLF y LF).

## 4. Pull Requests propuestos (cada uno pequeño y revisable)

| # | Rama | Contenido | Condición de entrada |
|---|---|---|---|
| 0 | `chore/gitattributes` | `.gitattributes` y normalización de finales de línea | — |
| 1 | `ci/integration-qa` | `qa/` + `integration-ci.yml` (levanta MySQL/PHP aislados y ejecuta `test:contract`) | PR #4 (paginación/vistas) fusionado |
| 2 | `feat/frontend-base` | `frontend/` completo + `frontend-ci.yml` | PR 1 en verde |
| 3 | `docs/frontend` | informes y guías curados + capturas seleccionadas | PR 2 |

Cada PR en borrador hasta pasar CI; el PR 2 no se fusiona antes de resolver el bloqueo de Flutter del PR #4 (el frontend usa `view=minimal` y paginación).

## 5. GitHub Actions propuestas (borrador)

**`frontend-ci.yml`** — `on: pull_request: paths: ['frontend/**', '.github/workflows/frontend-ci.yml']`; `permissions: contents: read`; `runs-on: ubuntu-24.04`; `actions/checkout@v4` con `persist-credentials: false`; `actions/setup-node@v4` (Node 24, caché npm); pasos en `frontend/`:
1. `npm ci --ignore-scripts`
2. `npm audit --audit-level=high`
3. `npm run lint` (0 avisos)
4. `npm run typecheck`
5. `npm test`
6. `npm run build` y `npm run build:qa`
7. `npm run test:e2e` (Chrome headless, `BROWSER_PATH`) contra la compilación
8. Escaneo de secretos del árbol y de `dist/`

**`integration-ci.yml`** — `paths: ['backend/**', 'frontend/**', 'qa/**', 'tests/**']`: construye la imagen PHP, `node qa/backend.mjs up`, `npm run test:contract`, `npm run build:qa`, `npm run test:e2e:qa`, verifica el aislamiento (`qa:status` debe mostrar «BLOQUEADA»), `qa:down` en `always()`.

Versiones de acciones fijadas, sin secretos de producción en ningún job, concurrencia por rama y `timeout-minutes`. Protección de `main`: PR obligatorio, 1 revisión, checks `Backend CI`, `Frontend CI` e `Integration CI` requeridos, sin force push.

## 6. Riesgos del traslado
- Rutas absolutas y `Edge` fijo en los scripts (cubierto en §3).
- Las pruebas E2E y de contrato dependen de Docker en el runner (disponible en `ubuntu-24.04`; imágenes `mysql:8.0` y `php:8.3-cli` se descargan en CI, no hay credenciales).
- El paso de captura de pantallas no debe ejecutarse en CI.
- Tamaño: ≈ 9 MB con todas las capturas; ≈ 1,5 MB con la selección curada.
