/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** 'mock' (por defecto) o 'http'. */
  readonly VITE_API_PROVIDER?: string;
  readonly VITE_API_BASE_URL?: string;
  /** El proveedor HTTP exige además ser habilitado explícitamente. */
  readonly VITE_ENABLE_HTTP_PROVIDER?: string;
  /** Solo modo `qa`: página local que sustituye al endpoint de «Publicar en web» para probar el visor sin acceso externo. */
  readonly VITE_POWERBI_VIEW_BASE?: string;
  /** Solo modo `qa`: `real-public` activa el visor con un informe público real de Power BI (opt-in explícito). */
  readonly VITE_POWERBI_VIEWER_MODE?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
