# Informe UX-05.1 · Primera implementación React

Complementa `UX-05-decision-y-dependencias.md` (decisión y comparación tecnológica). Alcance: solo `Frontend-Dev`. `UX-Prototipo`, backend PHP y producción no se tocaron; sin commits ni publicación.

## 1. Versiones instaladas (fijadas, sin `^` ni `~`)

Ejecución: `react` 19.3.1 · `react-dom` 19.3.1 · `react-router` 7.18.5 (API declarativa; no se instaló `react-router-dom`).
Desarrollo: `typescript` 5.9.3 · `vite` 8.3.5 · `@vitejs/plugin-react` 6.1.2 · `vitest` 5.0.5 · `jsdom` 30.1.3 · `@testing-library/react` 16.3.3 · `@testing-library/dom` 10.4.2 · `@testing-library/user-event` 14.6.8 · `@testing-library/jest-dom` 7.0.1 · `@types/react` 19.3.0 · `@types/react-dom` 19.3.0 · `@types/node` 24.19.2.

`.npmrc` fija `ignore-scripts=true` y `save-exact=true`. No se instaló Power BI SDK, Playwright ni ESLint.

## 2. Instalación y auditoría

- Instalación inicial: `npm install --ignore-scripts` → 107 paquetes (132 entradas en el lock, 27 opcionales por plataforma).
- Reproducibilidad: `npm ci --ignore-scripts` reinstala y **`package-lock.json` queda byte a byte idéntico**.
- Scripts de instalación: solo `fsevents` (exclusivo de macOS, no se instala en Windows). **No hubo que autorizar ningún script.**
- Origen: todos los paquetes se resuelven en `registry.npmjs.org` y tienen `integrity`.
- **`npm audit`: 0 vulnerabilidades** (info 0, low 0, moderate 0, high 0, critical 0). No se aplicó ninguna corrección automática.
- Compatibilidad: Node 24.20 cumple los `engines` de Vite 8 y Vitest 5; `@vitejs/plugin-react` 6 exige Vite 8 (cumplido); `react-router` 7.18.5 admite React ≥ 18.

## 3. Pruebas y resultados

| Prueba | Resultado |
|---|---|
| `tsc --noEmit` (strict, `noUncheckedIndexedAccess`, `noUnused*`) | sin errores |
| Compilación de producción | OK · 45 módulos · JS 303 kB (95 kB gzip) · CSS 16 kB (4,3 kB gzip) |
| Unitarias y de componentes (Vitest) | **54/54** |
| E2E en Edge contra la compilación | **84/84** |

Cobertura de las pruebas unitarias: adaptadores (roles, ids, descarte de `enlace` y `powerbi_*`), fábrica (HTTP bloqueado salvo bandera + localhost), proveedor simulado como "servidor" (401, 403 idéntico para inexistente y no asignado, rutas admin), proveedor HTTP con `fetch` falso (Bearer, `credentials: omit`, traducción de errores), guard de 401, saneado de la ruta de retorno, inicio de sesión, navegación por roles, búsqueda/filtros/limpiar, estados sin resultados y vacío, abrir y volver de un informe, 401/403 de usuario y administrador, administración, y análisis estático del código (sin JWT, sin almacenamiento del navegador, sin URLs externas, sin Power BI ni Flutter, versiones fijadas).

Cobertura E2E (escritorio, teclado y móvil de 390 px): login y logotipos originales; navegación por rol; búsqueda, filtros combinados, limpiar y foco; visor y retorno con filtros, foco y botón Atrás; empresas → informes en un paso; perfil; 403 (informe no asignado, inexistente, simulado, ruta administrativa, administrador); 401 de usuario, administrador, móvil y dentro de un informe, con contraseña vacía, usuario conservado y sin datos en el almacenamiento del navegador; estados vacíos; administración (filtros, vista previa y retorno, pendientes); teclado y nombres accesibles; menú móvil; tablas como tarjetas; 0 errores de consola; **0 solicitudes fuera de `127.0.0.1:4174` y 0 a `/api`**; la compilación no contiene JWT, claves privadas, secretos de cliente, enlaces de Power BI ni restos de Flutter, y sí los dos logotipos originales.

### Comparación visual con UX-04B (píxeles distintos, tolerancia 40/255 por canal)

| Pantalla | Escritorio | Móvil |
|---|---|---|
| Biblioteca / filtrada | 0 % / 0 % | 0 % / 0 % |
| Visor | 0,4 % | 4,8 % |
| 403 usuario / 403 admin | 0,5 % / 0,4 % | 1,9 % / 1,5 % |
| Login / 401 | 0,2 % / 0,2 % | 11,5 % / 8,0 % |
| Admin: inicio | 1,5 % | 0 % |
| Admin: usuarios | 2,1 % | 12,3 % |
| Admin: reportes | 7,2 % | 11,2 % |

Menú, barra superior, tarjetas, tablas y estados coinciden. Las diferencias restantes son intencionales: el texto del login es más largo (desplaza el pie en móvil), y en administración faltan los botones Editar/Desactivar/Asignar/«Nuevo…» que aún no se portan. Imágenes en `docs/capturas/diferencias/`.

## 4. Capturas

`docs/capturas/react/` (23): login, biblioteca, filtros, sin resultados, visor, empresas, perfil, 403 usuario y administrador, sin informes, 401 usuario y administrador, inicio/usuarios/empresas/reportes/accesos de administración y 6 de móvil (login, menú, biblioteca, 403, 401, usuarios). Pares con el prototipo en `docs/capturas/prototipo/` y `docs/capturas/react-comparacion/`.

## 5. Diferencias frente a UX-04B

**Arquitectura**: React con rutas reales (`/reportes/:id`, `/admin/usuarios`…) en vez de `#/…`; TypeScript con contratos; capa de API con proveedores intercambiables; filtros en la URL (se conservan al abrir un informe, con Atrás y al recargar); sin `innerHTML` (el resaltado se genera con elementos).

**Seguridad (mejoras)**: el proveedor simulado ahora actúa de servidor (token, rol, autorización); el estado de la interfaz no contiene contraseñas ni tokens fuera de memoria; la ruta tras un 401 se sanea y solo se retoma si reingresa la misma persona.

**Funcionalidad aún no portada** (documentado, no eliminada): altas/ediciones/activar-desactivar de usuarios, empresas y reportes, asistente de asignación y revocar accesos, actividad de sesión del panel, restauración de la posición de scroll al volver (se mantiene foco y filtros). Las pantallas administrativas muestran listados, filtros y estados en solo lectura con un aviso visible.

**Cambios menores**: el escenario «403» se llama «403 al abrir informes» sin cambios de comportamiento; «Mis empresas → Ver informes» usa un enlace real; el login ya no precarga la contraseña.

## 6. Hallazgos para el equipo de backend (no se actuó sobre ellos)

1. **CORRECCIÓN (UX-06A):** este punto se redactó con `API_DOCUMENTATION.md` y era inexacto. El código actual del backend (SEC-04A) **ya excluye `powerbi_clientsecret`** de `GET /api/empresas`; lo verificado contra el backend real es que sí se siguen enviando `powerbi_tenant` y `powerbi_client` a usuarios no administradores. Ver `INFORME-UX-06A.md`.
2. `GET /api/reportes` devuelve `enlace` (URL de Power BI) para todos los reportes listados.
3. El contrato de errores no documenta 403; la interfaz lo trata igual que 404.
4. `/api/register` queda fuera del frontend, según lo indicado.

## 7. Pendientes antes del primer Pull Request

ESLint (+ `typescript-eslint`) según lo acordado; cabeceras de seguridad en Apache (CSP, `X-Content-Type-Options`, `Referrer-Policy`) — no se pudo añadir una CSP en `index.html` porque el servidor de desarrollo de Vite inyecta scripts en línea; revisión de accesibilidad con lector de pantalla; probar en Firefox y Safari (solo se validó Edge/Chromium).

## 8. Plan UX-06 · Integración con PHP/MySQL sintéticos

1. **Backend aislado**: usar `tests/isolated` del repositorio PHP (router + seed) con una base MySQL/SQLite sintética en `127.0.0.1`; sin VPN, sin Microsoft, sin producción.
2. **Contrato**: congelar con pruebas el JSON real de login, `/api/user`, reportes, empresas, asignaciones y errores (incluido 403); ajustar adaptadores. Definir cómo obtener «informes autorizados del usuario» y su empresa/grupo (hoy `grupo_id` es UUID y el reporte no trae empresa en el listado documentado).
3. **Proveedor HTTP**: activar `VITE_ENABLE_HTTP_PROVIDER` solo contra localhost; pruebas de contrato con el mismo conjunto que el proveedor simulado (la suite debe pasar con ambos).
4. **Sesión**: confirmar duración, `session_version` y logout con revocación (SEC-04B); decidir si el token seguirá solo en memoria (se pierde al recargar) o se pasa a cookie HttpOnly con cambios de backend.
5. **Power BI**: definir el token de inserción limitado (`/api/powerbi/tokens/{id}`), instalar `powerbi-client` + `powerbi-client-react` con aprobación, y sustituir el visor simulado sin exponer `enlace` ni tokens de aplicación.
6. **Administración**: portar altas/ediciones/asignaciones desde UX-04B usando los endpoints reales; normalizar roles en el servidor; resolver la corrección de `/api/register` antes de conectar «Nuevo usuario».
7. **Calidad**: ESLint, pruebas de contrato, E2E contra el backend aislado, cabeceras Apache y revisión del build estático con la regla de reescritura incluida (`public/.htaccess`).
