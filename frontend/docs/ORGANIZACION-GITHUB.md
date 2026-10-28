# Organización futura de frontend y backend en GitHub

Propuesta para decisión. **No se publica nada** ni se crean commits o ramas en esta fase.

## Situación actual
- Repositorio `baltrekconsultoria/cervesur-plataforma` (privado). Contiene `backend/` (PHP), `tests/` (unitarias, estáticas y HTTP aisladas), `docs/SEC-*.md` y `.github/workflows/backend-ci.yml`. Rama de trabajo publicada: `security/cervesur-sec-04b`; ramas anteriores `ci/…` y `security/…` ya integradas por Pull Request.
- El frontend nuevo vive fuera del repositorio (`Frontend-Dev`, sin git). El prototipo `UX-Prototipo` y `Referencia-Frontend` (Flutter compilado) tampoco están versionados.

## Recomendación: un solo repositorio (monorepo) con tres áreas

```
cervesur-plataforma/
  backend/                 (existente, sin moverlo)
  frontend/                contenido de Frontend-Dev: src, e2e, public, package*.json, configs
  qa/                      orquestador Docker y pruebas de contrato (hoy Frontend-Dev/qa)
  tests/                   (existente: pruebas del backend)
  docs/                    SEC-*, UX-*, INFORME-*, QA-MANUAL-WINDOWS, ORGANIZACION-GITHUB
  .github/workflows/       backend-ci.yml (existente) + frontend-ci.yml + integration-ci.yml
```

**Por qué un monorepo**: el contrato `frontend ↔ API` cambia en conjunto (este informe encontró 10 desajustes entre ambos); un único Pull Request puede modificar PHP, adaptadores y pruebas de contrato de forma atómica; el CI de integración necesita ambas mitades; y la política de seguridad (revisión, secretos) queda en un solo lugar. La alternativa de dos repositorios solo compensa si equipos o ciclos de despliegue distintos exigen permisos separados; costaría sincronizar versiones del contrato.

## Qué entra y qué no

| Entra | No entra |
|---|---|
| `src/`, `e2e/`, `public/`, `index.html`, `package.json`, `package-lock.json`, `tsconfig.json`, `vite.config.ts`, `.npmrc`, `.env.example`, `.env.qa.example` (sin credenciales), `qa/`, documentación, capturas finales seleccionadas | `node_modules/`, `dist/`, `dist-qa/`, `.env`/`.env.*` con valores, `_referencia-UX-05.1/` (es una copia local), `UX-Prototipo/`, `Referencia-Frontend/` (Flutter compilado y posibles JWT históricos), hojas de cálculo con usuarios o credenciales de la carpeta `Cervesur/` |

`.gitignore` ya preparado en `Frontend-Dev`. Antes del primer push: búsqueda de secretos (patrones JWT, claves) sobre el árbol y el historial.

## Ramas y Pull Requests

- Se mantiene la convención existente (`ci/…`, `security/…`) y se añade `feat/frontend-…`. `main` protegida: Pull Request, revisión y CI verde obligatorios.
- Secuencia sugerida de PR pequeños y revisables:
  1. `docs/ux-06a`: informes, guía de QA y esta organización.
  2. `ci/integration-qa`: `qa/` (Docker aislado + pruebas de contrato) y su workflow; sin tocar `backend/`.
  3. `feat/frontend-base`: frontend (React/TS/Vite) con **ESLint ya incorporado** (requisito cumplido en UX-06A.1: `npm run lint` en 0 errores).
  4. Correcciones de backend (rama `security/…`): login 401 real, columnas explícitas en `/api/reportes` y `/api/empresas`, normalización de roles, empresa/grupo por informe (ver incidencias I-1, I-3 a I-6).

## CI propuesto

| Workflow | Disparador | Pasos |
|---|---|---|
| `backend-ci.yml` (existe) | `backend/**`, `tests/**` | lint PHP, seguridad, HTTP aislado |
| `frontend-ci.yml` | `frontend/**` | `npm ci --ignore-scripts`, `npm audit`, typecheck, ESLint, Vitest, build, E2E simulado (Edge) |
| `integration-ci.yml` | cambios en cualquiera | levantar `qa/` con redes internas, `test:contract`, build `--mode qa`, E2E real |

Permisos mínimos (`contents: read`), `persist-credentials: false`, versiones de acciones fijadas, sin secretos de producción en ningún job (el CI actual ya genera credenciales aleatorias por ejecución). Dependabot/`npm audit` semanal, y revisión manual de cualquier cambio en `package-lock.json`.

## Despliegue (para más adelante)
Apache sirve `frontend/dist` como estático (`public/.htaccess` ya incluye la reescritura a `index.html`) y reenvía `/api` al PHP del mismo host, de modo que el navegador usa mismo origen, sin CORS, igual que en el entorno QA. Cabeceras de seguridad (CSP, `X-Content-Type-Options`, `Referrer-Policy`, `Cache-Control` para `index.html`) en la configuración de Apache.
