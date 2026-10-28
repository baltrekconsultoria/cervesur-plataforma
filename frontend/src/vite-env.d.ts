/// <reference types="vite/client" />

interface ImportMetaEnv {
  /** 'mock' (por defecto) o 'http'. */
  readonly VITE_API_PROVIDER?: string;
  readonly VITE_API_BASE_URL?: string;
  /** El proveedor HTTP exige además ser habilitado explícitamente. */
  readonly VITE_ENABLE_HTTP_PROVIDER?: string;
}

interface ImportMeta {
  readonly env: ImportMetaEnv;
}
