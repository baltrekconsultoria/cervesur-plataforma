# Cervesur · Frontend (UX-05.1)

Base técnica del frontend definitivo: React 19 + TypeScript 5.9 + Vite 8. Solo datos sintéticos; **no se conecta a ningún backend**. Referencia visual y funcional: prototipo UX-04B (`../UX-Prototipo`, sin modificar).

## Abrir localmente

Requiere Node ≥ 22.12 (probado con 24.20). Las dependencias ya están instaladas; para reinstalar de forma reproducible:

```powershell
cd frontend
npm ci --ignore-scripts
```

| Qué | Comando | URL |
|---|---|---|
| Desarrollo (recarga en caliente) | `npm run dev` | http://127.0.0.1:5173 |
| Versión de producción compilada | `npm run build` y luego `npm run preview` | http://127.0.0.1:4174 |

Cuentas de demostración (no se validan contraseñas; basta escribir cualquiera): `maria.rios` (usuario) y `elena.torres` (administrador). La barra superior trae el **Escenario de prueba** (sin informes · 403 · 401) y **Restablecer datos**; solo aparecen con el proveedor simulado.

## Pruebas

| Comando | Qué ejecuta |
|---|---|
| `npm run lint` | ESLint 10 (Flat Config, TypeScript con tipos, React Hooks); 0 errores y 0 avisos |
| `npm run typecheck` | TypeScript estricto |
| `npm run build` | typecheck + compilación de producción |
| `npm test` | 81 pruebas unitarias y de componentes (Vitest + Testing Library) |
| `npm run test:e2e` | 84 comprobaciones en Edge headless contra la compilación (requiere `npm run build`) |
| `node e2e/compare.mjs` | capturas emparejadas y diferencia de píxeles contra UX-04B |

## Estructura

```
src/
  api/        contratos (types.ts), errores, ApiClient, guard de 401, fábrica de proveedores
    mock/     proveedor simulado: hace de "servidor" (valida token, rol y autorización)
    http/     proveedor HTTP + adaptadores del API PHP (desactivado)
  app/        rutas y guardas, AppProvider (sesión en memoria), layout, controles de demostración
  auth/       saneado de la ruta de retorno tras un 401
  components/ ui.tsx (Button, Tag, DataTable, ResultBar…), DeniedState
  domain/     reglas de asignación efectiva
  features/   login, library, viewer, companies, profile, admin
  lib/        texto, useAsync, useUrlFilters (filtros en la URL)
  styles/     app.css (tokens y estilos de UX-04B)
e2e/          arnés Edge/CDP y comparación visual
docs/         decisión tecnológica, informe UX-05.1, capturas
```

## Proveedores de datos

`VITE_API_PROVIDER=mock` (por defecto). El proveedor HTTP existe pero la fábrica lo rechaza salvo que se active `VITE_ENABLE_HTTP_PROVIDER=true` **y** la URL apunte a `localhost`/`127.0.0.1`. Ver `.env.example`.

## Reglas de seguridad aplicadas

- El token vive solo en memoria; no hay `localStorage`, `sessionStorage` ni cookies (verificado por pruebas).
- Un 401 en cualquier petición cierra la sesión local, borra el token, vacía la contraseña y conserva el usuario.
- 403 y 404 se muestran igual y sin nombres de informes o empresas.
- El frontend oculta opciones por rol solo como comodidad: la autorización la decide el backend.
- Los adaptadores descartan `enlace` de los reportes y todos los campos `powerbi_*` de las empresas.
- Sin SDK de Power BI, sin Flutter compilado, sin JWT ni credenciales reales.

## Integración con el backend PHP local aislado (UX-06A)

El proveedor HTTP solo funciona contra un backend local en Docker (MySQL sintético, sin Internet, puertos únicamente en `127.0.0.1`). Guía paso a paso en [`docs/QA-MANUAL-WINDOWS.md`](docs/QA-MANUAL-WINDOWS.md) e informe en [`docs/INFORME-UX-06A.md`](docs/INFORME-UX-06A.md).

| Comando | Qué hace |
|---|---|
| `npm run qa:up` / `qa:down` / `qa:status` | levanta, elimina y verifica el aislamiento del entorno QA |
| `npm run build:qa` y `npm run preview:qa` | compilación en modo `qa` y servidor en http://127.0.0.1:4175 |
| `npm run test:contract` | 99 comprobaciones de contrato, permisos, paginación, vistas y catálogo contra PHP real |
| `npm run test:e2e:qa` | 78 comprobaciones de navegador → React → PHP real |
| `npm run qa:backend-ci` | repite el CI del backend (lint PHP, seguridad, 50 HTTP base + 228 de paginación/vistas) |

`_referencia-UX-05.1/` conserva la versión UX-05.1 aprobada.

Informe de la fase ESLint y diagnóstico de contratos: [`docs/INFORME-UX-06A.1.md`](docs/INFORME-UX-06A.1.md).

UX-06B.0 (paginación de servidor, vista mínima y catálogo por empresa): [`docs/INFORME-UX-06B.0.md`](docs/INFORME-UX-06B.0.md).
