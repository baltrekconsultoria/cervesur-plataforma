import { HttpApiClient } from './http/httpApi';
import type { ApiClient, TokenStore } from './types';

/**
 * Configuración de la compilación destinada al SERVIDOR (`VITE_DEPLOY_TARGET=server`, generada por `npm run build:production`).
 * Solo contiene valores públicos: nunca secretos. Reglas:
 *  - el proveedor es SIEMPRE el HTTP contra PHP; no existe ningún camino a datos simulados;
 *  - la API se consume del MISMO ORIGEN que sirve la aplicación (`/api`); no se admite ninguna otra URL de API;
 *  - el dominio debe estar en la lista autorizada de la compilación (`VITE_ALLOWED_HOSTS`) y usar HTTPS
 *    (excepción: loopback, solo para verificar la compilación en la propia máquina);
 *  - cualquier incoherencia FALLA con un mensaje claro (la aplicación muestra una pantalla de configuración incorrecta).
 */
export interface ServerEnv {
  VITE_API_PROVIDER?: string;
  VITE_API_BASE_URL?: string;
  VITE_ALLOWED_HOSTS?: string;
}

export interface ServerLocation { protocol: string; hostname: string; origin: string }

const LOOPBACK = new Set(['localhost', '127.0.0.1', '[::1]']);
const HOSTNAME = /^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/;

export class ConfigurationError extends Error {
  constructor(message: string) {
    super(message);
    this.name = 'ConfigurationError';
  }
}

export function parseAllowedHosts(value: string | undefined): string[] {
  const hosts = (value ?? '').split(',').map(h => h.trim().toLowerCase()).filter(Boolean);
  for (const host of hosts) {
    if (!HOSTNAME.test(host) || host.includes('*')) throw new ConfigurationError(`Dominio autorizado no válido en la compilación: «${host}».`);
  }
  return hosts;
}

export function createServerServices(env: ServerEnv, tokens: TokenStore, location: ServerLocation | null = globalThis.location ?? null): { provider: 'http'; client: ApiClient } {
  if (env.VITE_API_PROVIDER !== undefined && env.VITE_API_PROVIDER !== 'http') throw new ConfigurationError('La compilación de servidor solo admite el proveedor HTTP (PHP); no se permiten datos simulados.');
  if (env.VITE_API_BASE_URL !== undefined && env.VITE_API_BASE_URL !== 'same-origin') throw new ConfigurationError('La compilación de servidor solo consume /api del mismo origen; no se admiten otros servidores de API.');
  const allowed = parseAllowedHosts(env.VITE_ALLOWED_HOSTS);
  if (allowed.length === 0) throw new ConfigurationError('La compilación de servidor no declara ningún dominio autorizado (VITE_ALLOWED_HOSTS).');
  if (!location) throw new ConfigurationError('No se pudo determinar el origen de la aplicación.');
  const host = location.hostname.toLowerCase();
  const loopback = LOOPBACK.has(host);
  if (!loopback && !allowed.includes(host)) throw new ConfigurationError(`Este dominio (${host}) no está autorizado para esta compilación.`);
  if (!loopback && location.protocol !== 'https:') throw new ConfigurationError('El portal solo puede usarse por HTTPS.');
  return { provider: 'http', client: new HttpApiClient({ baseUrl: location.origin, tokens }) };
}
