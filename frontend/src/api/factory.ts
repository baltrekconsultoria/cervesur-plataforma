import { HttpApiClient } from './http/httpApi';
import { MockApi } from './mock/mockApi';
import type { ApiClient, TokenStore } from './types';

export interface ApiEnv {
  VITE_API_PROVIDER?: string;
  VITE_API_BASE_URL?: string;
  VITE_ENABLE_HTTP_PROVIDER?: string;
}

export interface ApiServices {
  provider: 'mock' | 'http';
  client: ApiClient;
  /** Solo existe con el proveedor simulado; habilita los controles de demostración. */
  mock?: MockApi;
}

const LOOPBACK = new Set(['localhost', '127.0.0.1', '[::1]']);

/** En esta fase el proveedor HTTP solo puede apuntar a un backend local aislado. */
export function assertIsolatedBackend(baseUrl: string): void {
  let host: string;
  try { host = new URL(baseUrl).hostname; } catch { throw new Error('URL base del API inválida.'); }
  if (!LOOPBACK.has(host)) throw new Error(`El proveedor HTTP solo acepta backends locales aislados (recibido: ${host}).`);
}

export function createApiServices(env: ApiEnv, tokens: TokenStore): ApiServices {
  const provider = env.VITE_API_PROVIDER ?? 'mock';
  if (provider === 'mock') {
    const mock = new MockApi(tokens);
    return { provider: 'mock', client: mock, mock };
  }
  if (provider !== 'http') throw new Error(`Proveedor de datos desconocido: ${provider}`);
  if (env.VITE_ENABLE_HTTP_PROVIDER !== 'true') throw new Error('El proveedor HTTP está desactivado en esta fase (VITE_ENABLE_HTTP_PROVIDER).');
  // 'same-origin': el navegador habla con el origen que sirve la app (loopback) y el servidor de Vite reenvía /api al backend local.
  const configured = env.VITE_API_BASE_URL ?? '';
  const baseUrl = configured === 'same-origin' ? globalThis.location?.origin ?? '' : configured;
  assertIsolatedBackend(baseUrl);
  return { provider: 'http', client: new HttpApiClient({ baseUrl, tokens }) };
}
