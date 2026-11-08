/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** 'mock' (por defecto) o 'http'. */
  readonly VITE_API_PROVIDER?: string;
  readonly VITE_API_BASE_URL?: string;
  /** El proveedor HTTP exige además ser habilitado explícitamente. */
  readonly VITE_ENABLE_HTTP_PROVIDER?: string;
  /** Solo modo `qa`: página local que sustituye al endpoint de «Publicar en web» para probar el visor sin acceso externo. */
  readonly VITE_POWERBI_VIEW_BASE?: string;
  /** `server`: compilación para el servidor (proveedor HTTP obligatorio, mismo origen, dominios autorizados). La fija `npm run build:production`. */
  readonly VITE_DEPLOY_TARGET?: string;
  /** Lista pública de dominios autorizados (separados por comas) para la compilación de servidor. */
  readonly VITE_ALLOWED_HOSTS?: string;
  /** `true`: compilación del piloto visual (UX-07D.4): aviso de contexto; la fija `npm run build:pilot`. */
  readonly VITE_PILOT?: string;
  /** `operador` (un operador autorizado) o `usuarios` (usuarios reales con permisos individuales); lo fija `npm run build:pilot` / `build:pilot-usuarios`. */
  readonly VITE_PILOT_MODE?: string;
  /** Solo modo `qa`: `real-public` activa el visor con un informe público real de Power BI (opt-in explícito). */
  readonly VITE_POWERBI_VIEWER_MODE?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
