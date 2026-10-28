import { POWERBI_VIEW_URL } from '../domain/powerbiLink';

interface ViewerEnv { MODE?: string; VITE_POWERBI_VIEW_BASE?: string; VITE_POWERBI_VIEWER_MODE?: string }

/**
 * Cómo se resuelve el visor de informes publicados en web:
 *  - official     : endpoint oficial de Microsoft (producción y cualquier compilación que no sea `qa`).
 *  - qa-simulated : SOLO en la compilación `qa` y por defecto: página LOCAL de pruebas (ruta relativa o loopback). No contacta con Microsoft.
 *  - qa-real      : SOLO en la compilación `qa` y de forma EXPLÍCITA (`VITE_POWERBI_VIEWER_MODE=real-public`): el navegador carga el endpoint
 *                   oficial con un enlace público autorizado. El backend, MySQL y la autorización siguen siendo los locales.
 * Cualquier otro valor se ignora: una variable mal configurada nunca apunta el iframe a un dominio arbitrario.
 */
export type ViewerMode = 'official' | 'qa-simulated' | 'qa-real';
export interface ViewerSource { base: string; mode: ViewerMode }

export function resolveViewer(env: ViewerEnv, origin: string): ViewerSource {
  const official: ViewerSource = { base: POWERBI_VIEW_URL, mode: 'official' };
  if (env.MODE !== 'qa') return official;
  if (env.VITE_POWERBI_VIEWER_MODE === 'real-public') return { base: POWERBI_VIEW_URL, mode: 'qa-real' };
  const configured = env.VITE_POWERBI_VIEW_BASE;
  if (!configured) return official;
  if (/^\/(?!\/)[A-Za-z0-9._~/-]*$/.test(configured)) return { base: `${origin}${configured}`, mode: 'qa-simulated' };
  try {
    const url = new URL(configured);
    if (url.protocol === 'http:' && ['127.0.0.1', 'localhost'].includes(url.hostname) && !url.search && !url.hash && !url.username) return { base: `${url.origin}${url.pathname}`, mode: 'qa-simulated' };
  } catch { /* valor inválido: se usa el oficial */ }
  return official;
}

/**
 * Atributo `sandbox` del iframe según el origen del contenido:
 *  - Página LOCAL de pruebas (mismo origen que la aplicación): `allow-scripts` SIN `allow-same-origin`. Con ambos, un documento del mismo
 *    origen podría quitarse el sandbox; así el marco queda con origen opaco y no puede tocar la aplicación.
 *  - Power BI (origen distinto: app.powerbi.com): `allow-scripts allow-same-origin allow-popups`. Power BI necesita su propio origen
 *    (almacenamiento y cookies) y abrir enlaces; al ser de otro origen no puede alcanzar la aplicación. Chrome muestra de todos modos su
 *    aviso genérico sobre esa combinación: es una advertencia residual justificada (ver docs/INFORME-UX-07C1.md).
 *  Nunca se concede `allow-forms`, `allow-top-navigation`, `allow-modals`, `allow-downloads` ni `allow-popups-to-escape-sandbox`.
 */
export function frameSandbox(mode: ViewerMode): string {
  return mode === 'qa-simulated' ? 'allow-scripts' : 'allow-scripts allow-same-origin allow-popups';
}

/** Hosts desde los que se permite insertar un iframe: Power BI oficial y, solo si la base lo indica, el origen local de pruebas. */
export function isAllowedFrameUrl(src: string, viewBase: string): boolean {
  try {
    const url = new URL(src);
    const base = new URL(viewBase);
    return url.origin === base.origin && url.pathname === base.pathname && (url.protocol === 'https:' ? url.hostname === 'app.powerbi.com' : ['127.0.0.1', 'localhost'].includes(url.hostname));
  } catch {
    return false;
  }
}
