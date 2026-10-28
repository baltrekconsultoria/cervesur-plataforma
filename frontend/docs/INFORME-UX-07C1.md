# UX-07C.1 · Visualización real de Power BI en QA y revisión del sandbox

Rama `feat/cervesur-ux-07c-powerbi-viewer` (sin commit). Cambios solo de frontend y herramientas de QA; **el backend de producción no se modifica**.

## 1. Causa raíz exacta de «Informe de Power BI simulado»
No es un error: es el comportamiento de QA que introdujo UX-07C, activo por defecto.
1. `frontend/.env.qa.example:12` define `VITE_POWERBI_VIEW_BASE=/qa/powerbi-view.html` y `build:qa` lo copia a `.env.qa` (línea 13 de la copia local, que **no se sobrescribe** si ya existe).
2. `frontend/src/lib/viewerBase.ts` (`resolveViewer`, antes `resolveViewerBase`, líneas ~16-28): en la compilación `qa`, con esa variable, devuelve como base la página local `${origen}/qa/powerbi-view.html`.
3. `frontend/src/features/viewer/ViewerPanel.tsx:~96-98`: `buildPublicViewerUrl(value.url, base)` **reconstruye** la URL con esa base (conserva solo `r` y los parámetros permitidos) y `isAllowedFrameUrl` solo admite ese origen. Por eso, aunque el enlace guardado sea el real de `app.powerbi.com`, el iframe cargaba la página local.
4. La página local la emite el plugin de `frontend/vite.config.ts` solo en modo `qa` (`/qa/powerbi-view.html`).
- La sustitución es **intencional** y depende de `VITE_POWERBI_VIEW_BASE`.
- El frontend sí recompilaba: no era un problema de caché. Tampoco hay CSP en la aplicación ni en `vite preview` (no hay `<meta>` ni cabecera `Content-Security-Policy`/`X-Frame-Options`) que impidiera mostrar el enlace real; las únicas restricciones eran las propias (clasificación del enlace, `isAllowedFrameUrl` y el `sandbox`).
- Faltaba una opción explícita para usar el Power BI público real: ahora existe.

## 2. Dos modos de visor (solo compilación `qa`; producción es siempre oficial)
| Modo | Cómo se activa | iframe | Aviso en pantalla |
|---|---|---|---|
| **QA simulado** (predeterminado) | `build:qa` / `preview:qa` (puerto 4175) | página local `/qa/powerbi-view.html` | «QA · visor simulado: …no se contacta con Power BI» |
| **QA con Power BI público real** (opt-in) | `npm run build:qa:real` + `npm run preview:qa:real` (puerto 4177, carpeta aparte `dist-qa-real/`) | `https://app.powerbi.com/view?r=…` reconstruido | «QA · Power BI público REAL: este navegador se conecta con app.powerbi.com. El backend, la base de datos y la autorización siguen siendo locales y sintéticos» |
| Producción / otras compilaciones | — | oficial | ninguno |

- La activación es la variable `VITE_POWERBI_VIEWER_MODE=real-public`, que solo `build:qa:real` establece, en un proceso aparte: **no toca `.env.qa` ni `dist-qa/`**, de modo que el simulador sigue disponible y las pruebas automáticas siguen sin Internet. Cualquier otro valor se ignora; en una compilación que no sea `qa` se ignora siempre.
- Se mantiene todo lo demás: el enlace solo llega tras la autorización del servidor (`/visor`), debe estar clasificado como **publicación web** (HTTPS, host `app.powerbi.com`), no se usan OAuth ni tokens, los informes privados (inserción/normal) siguen mostrando «requiere configuración», el catálogo no lleva el enlace, y el enlace real **solo existe en la base de datos sintética local** (no en Git, fixtures ni capturas).
- `dist-qa-real/` está en `.gitignore` y el escáner de secretos lo revisa.

## 3. Advertencia del navegador sobre `sandbox`
> *An iframe which has both allow-scripts and allow-same-origin for its sandbox attribute can escape its sandboxing.*

Análisis por separado:
1. **Página local de pruebas (mismo origen que React).** Era un riesgo real: un documento del mismo origen con ambos permisos puede quitarse el sandbox. **Corregido:** ahora su iframe usa `sandbox="allow-scripts"` **sin** `allow-same-origin` (origen opaco; no puede leer ni modificar la aplicación). La página avisa del token con `postMessage`, y la prueba E2E comprueba que el padre ya no puede leer su documento (`contentDocument === null`).
2. **Power BI (`app.powerbi.com`, otro origen).** Power BI necesita su propio origen (almacenamiento/cookies) y abrir enlaces, así que usa `sandbox="allow-scripts allow-same-origin allow-popups"`. Al ser de **otro origen** no puede alcanzar la aplicación, de modo que el escape descrito en la advertencia no aplica; Chrome la muestra igualmente de forma genérica ante esa combinación. **Advertencia residual justificada, no silenciada.**
- Permisos **retirados** respecto a UX-07C: `allow-forms`. **Nunca concedidos**: `allow-top-navigation`, `allow-modals`, `allow-downloads`, `allow-popups-to-escape-sandbox`. Además `referrerpolicy="no-referrer"`.
- Si la prueba visual con Power BI real revelara que falta un permiso concreto, se añadirá de forma puntual y documentada (no por defecto).
- Recomendación pendiente: una cabecera `Content-Security-Policy: frame-src https://app.powerbi.com` en el servidor web de producción (no existe hoy).

## 4. Cambios mínimos aplicados
`src/lib/viewerBase.ts` (modo y sandbox por origen), `ViewerPanel.tsx` (aviso de modo, sandbox por modo), `vite.config.ts` (página local con `postMessage`), `.env.qa.example` y `vite-env.d.ts` (variable opt-in), `scripts/build-qa-real.mjs`, `package.json` (`build:qa:real`, `preview:qa:real`), `.gitignore`, `scan-secrets.mjs`, estilos del aviso. Para no pisar el entorno del QA manual, `qa/backend.mjs`, `qa/contract.mjs` y `e2e/run-qa.mjs` aceptan `QA_PREFIX` y `QA_API_PORT` (segundo entorno independiente); sin ellos todo funciona como antes.

## 5. Regresión (local, aislada)
| Suite | Resultado |
|---|---|
| ESLint · typecheck · unitarias (modos, sandbox, aviso, enlaces no permitidos) | 0 avisos · OK · 185 de 185 |
| E2E simulados | 85 PASS / 0 FAIL |
| Contratos React–PHP (incluye autorización del visor) | 199 PASS / 0 FAIL |
| E2E React–PHP real (incluye aislamiento del iframe y aviso de modo) | 185 PASS / 0 FAIL |
| PHP lint · estáticas · HTTP 50 / 228 / 45 / 36 / 67 / 56 (visor) | OK · 15 · todas |
| Escaneo de secretos (incluye `dist-qa-real`) | limpio |
Los contratos y el E2E real se ejecutaron en un **segundo entorno** (contenedores `cervesur-qa2-*`, puerto 18080, ya eliminado), nunca contra el MySQL del QA manual. Ninguna prueba automática llama a Microsoft.

## 6. Datos del QA manual
El entorno `cervesur-qa-*` (MySQL, API y proxy) **no se reinició ni se tocó**: contadores antes/después idénticos (8 usuarios, 8 informes, 3 empresas, 10 asociaciones, 12 accesos) y los contenedores siguen «Up». Se reconstruyó `dist-qa/` (la vista previa del 4175 muestra la corrección al recargar). Para conservar los datos: **no ejecute `qa:up`, `qa:down` ni `test:contract` / `test:e2e:qa` sobre el entorno 8080**; para pruebas automáticas use `QA_PREFIX`/`QA_API_PORT` y apague solo ese segundo entorno.

## 7. Riesgos pendientes
- **Sin comprobar contra Power BI real** hasta su prueba visual: que el iframe cargue no demuestra que el informe se muestre. Posibles causas de un marco en blanco: `no-referrer`, cookies de terceros bloqueadas, bloqueadores de contenido, o que el enlace no sea de «Publicar en web».
- Un informe publicado en web lo puede ver cualquiera que tenga su enlace.
- Advertencia del sandbox residual en el modo real (justificada arriba); falta CSP de servidor.
- Migración UX-07C no aplicada en ninguna base real; sin commit todavía; el Flutter sigue sin validar.

## Cierre del QA manual de UX-07C.1
Aprobado por el responsable: **visualización real** de un informe público de Power BI dentro de React y **filtros/gráficos interactivos** (confirmación manual; sin pruebas automáticas posibles porque estas no se conectan a Microsoft). Ver el detalle, las advertencias residuales, la distinción entre evidencia manual y automatizada y la mejora registrada (tamaño inicial del dashboard ≈ 31 %) en `INFORME-UX-07C.md`, sección «Cierre del QA manual (UX-07C y UX-07C.1)».
