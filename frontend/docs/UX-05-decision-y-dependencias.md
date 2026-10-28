# UX-05 · Decisión tecnológica y dependencias propuestas

Estado: **pendiente de aprobación de dependencias**. No se ha instalado nada. Versiones consultadas al registro de npm el 2026-10-27 (solo lectura).

## 1. Hallazgos previos que condicionan la decisión

- **No existe el proyecto Dart original.** Solo hay el bundle compilado (`Referencia-Frontend/.../main.dart.js`), que no se reutiliza, y el SDK de Flutter no está instalado en este equipo.
- **El backend PHP usa nombres propios, no los del prototipo.** Según `API_DOCUMENTATION.md` y los routers: `user_id`, `username`, `role` (`"usuario"`), `nombre`, `descripcion`, `enlace`, `reporte_id` y `grupo_id` (UUID), con respuesta `{ success, message, ... }`. Hace falta una capa de adaptadores (DTO → modelo del frontend) y normalizar roles.
- **La documentación solo lista 400/401/404/500; no menciona 403.** El frontend ya trata 403, pero el contrato debe confirmarse con el backend (UX-06).
- **El listado de reportes expone `enlace`** (URL de Power BI). La interfaz no debe mostrarlo ni usarlo; la incrustación irá por `/api/powerbi/tokens/{id}` cuando se defina el token limitado.
- **El logout descrito es del lado cliente.** SEC-04B añadió revocación de sesión: hay que verificar en UX-06 cuál es el comportamiento real del backend aislado.
- Endpoints relevantes ya existentes: `/api/login`, `/api/user`, `/api/users`, `/api/reportes`, `/api/empresas`, `/api/asignacion`, `/api/empresa-reporte-usuario`, `/api/empresa-usuario`, `/api/powerbi/tokens/{id}`, `/api/stats`.

## 2. Comparación

| Criterio | Flutter Web | **React + TypeScript + Vite** | Vue + TypeScript |
|---|---|---|---|
| Fuente disponible | **No** (solo bundle compilado): habría que reescribir todo | Parte del prototipo (HTML/CSS/lógica) | Igual que React |
| API PHP (REST + JSON + Bearer) | Viable | Viable (`fetch`, sin librerías) | Viable |
| Power BI (SDK oficial) | Sin SDK oficial: interop JS / iframe | `powerbi-client` + wrapper oficial `powerbi-client-react` | `powerbi-client` directo; wrapper solo comunitario |
| JWT y 401/403 | Viable | Cliente único con interceptor propio; control sencillo de rutas protegidas | Viable |
| Rendimiento y peso | CanvasKit pesado, arranque lento | Ligero, división por rutas | Ligero |
| Accesibilidad y teclado | Más costosa (canvas) | HTML semántico, reutiliza lo ya probado | Igual |
| Mantenimiento | Equipo Dart/Flutter | Ecosistema amplio y fácil de contratar | Bueno |
| Despliegue en Apache | Estático, pero con service worker y caché agresiva | Estático (`dist/` + reescritura a `index.html`) | Igual |
| Aprovechamiento del prototipo | Bajo (otro lenguaje) | **Alto**: tokens CSS, textos, recorridos, pruebas | Medio-alto |

**Decisión: React + TypeScript + Vite.** No encontré una razón técnica en contra. Flutter queda descartado por no haber fuente y por la accesibilidad; Vue es una alternativa válida, pero no mejora a React en Power BI ni en reutilización.

Riesgos a vigilar: Vite 8 y TypeScript 7 son versiones mayores recientes (ver §3); el wrapper `powerbi-client-react` es pequeño y puede sustituirse por `powerbi-client` directo si deja de mantenerse.

## 3. Dependencias que propongo instalar en UX-05

Comprobé las compatibilidades declaradas: Node instalado v24.20.0 cumple los requisitos de Vite 8 (`^20.19 || >=22.12`) y Vitest 5 (`^22.12 || ^24`); `@vitejs/plugin-react` 6 exige Vite 8.

**Ejecución (3):**

| Paquete | Versión | Propósito |
|---|---|---|
| react | 19.3.1 | Biblioteca de interfaz |
| react-dom | 19.3.1 | Renderizado en navegador |
| react-router-dom | 7.18.5 | Rutas, rutas protegidas por rol, retorno desde el visor |

**Desarrollo (12):**

| Paquete | Versión | Propósito |
|---|---|---|
| typescript | 5.9.3 *(ver nota)* | Tipado estricto de contratos |
| vite | 8.3.5 | Servidor local y empaquetado estático |
| @vitejs/plugin-react | 6.1.2 | Soporte JSX/React en Vite |
| @types/react | 19.3.0 | Tipos de React |
| @types/react-dom | 19.3.0 | Tipos de React DOM |
| @types/node | 24.19.2 | Tipos requeridos por Vite/Vitest (alineado con Node 24) |
| vitest | 5.0.5 | Pruebas unitarias y de componentes |
| jsdom | 30.1.3 | Entorno DOM para las pruebas |
| @testing-library/react | 16.3.3 | Pruebas de componentes por comportamiento |
| @testing-library/dom | 10.4.2 | Requerido como peer por testing-library/react |
| @testing-library/user-event | 14.6.8 | Simula teclado y clic reales |
| @testing-library/jest-dom | 7.0.1 | Aserciones de accesibilidad y DOM |

*Nota TypeScript:* la última es 7.0.2 (compilador nativo, muy reciente). Propongo **5.9.3** porque es la serie estable con mayor compatibilidad con el resto de herramientas; puedo usar 7.0.2 si lo prefiere y se asume el riesgo de incompatibilidades.

**No se instalará:** ninguna librería de UI, de estado, de formularios ni de peticiones HTTP (se usa `fetch` nativo). Tampoco CSS externo: se migran los tokens de `styles.css`. Sin fuentes ni recursos de CDN.

**Diferidos a UX-06, cuando se defina el token de inserción:** `powerbi-client` 2.25.0 y `powerbi-client-react` 2.0.2 (compatible con React ≥ 18).

**Opcionales (indique si los quiere):**
- `eslint` 10.13.0 (+ `typescript-eslint`, versión por confirmar): análisis estático.
- `@playwright/test` 1.64.1: pruebas E2E y responsive. Alternativa sin dependencia nueva: reutilizar el arnés CDP con Edge ya existente en `UX-Prototipo/_pruebas`, que ya cubre 401/403, móvil y ausencia de solicitudes externas. **Recomiendo esta alternativa** para esta fase.

Las dependencias instalarán además sus dependencias transitivas desde el registro público de npm. Propongo ejecutar `npm ci --ignore-scripts` con `package-lock.json` y revisar `npm audit` antes de continuar.

## 4. Arquitectura que se construirá tras la aprobación

```
Frontend-Dev/
  public/branding/            logotipos originales (copiados, no modificados)
  src/
    app/                      rutas, guardas por rol, layout
    api/
      types.ts                contratos: User, Company, Report, Assignment, AuthResponse, ApiError
      client.ts               interfaz ApiClient + manejo común de 401/403
      mock/                   proveedor simulado (datos sintéticos, en memoria)
      http/                   proveedor HTTP + adaptadores snake_case → modelo (sin conectar a nada real)
    auth/                     sesión en memoria, token solo en memoria, 401 limpia estado
    features/{library,viewer,admin,profile}/
    components/               Button, Field, Table, Tag, Dialog, EmptyState, DeniedState…
    styles/                   tokens y estilos migrados del prototipo
  tests/                      unitarias, componentes y E2E
```

Decisiones de seguridad ya fijadas: token solo en memoria (nunca en archivos ni `localStorage`); proveedor HTTP deshabilitado por defecto y con URL base vacía hasta que se configure un backend aislado; ningún JWT del bundle histórico ni credenciales reales; el frontend oculta opciones por rol solo como conveniencia, nunca como control de acceso.

## 5. Lo que necesito de usted

1. Aprobar (o ajustar) las 15 dependencias (3 de ejecución y 12 de desarrollo) de la sección 3, incluyendo TypeScript 5.9.3 frente a 7.0.2.
2. Elegir E2E: arnés CDP existente (recomendado) o Playwright.
3. Indicar si quiere ESLint ahora.
