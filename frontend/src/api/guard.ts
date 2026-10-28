import { isApiError } from './errors';
import type { ApiClient } from './types';

/**
 * Envuelve un cliente para que cualquier 401 (salvo el de `login`, que significa
 * "credenciales incorrectas") invoque `onUnauthorized` una sola vez por petición.
 * El error se vuelve a lanzar para que cada pantalla deje de pintar datos.
 */
export function guardUnauthorized(client: ApiClient, onUnauthorized: () => void): ApiClient {
  return new Proxy(client, {
    get(target, prop, receiver) {
      const value = Reflect.get(target, prop, receiver) as unknown;
      if (typeof value !== 'function' || prop === 'login') return value;
      return async (...args: unknown[]) => {
        try {
          return await (value as (...a: unknown[]) => unknown).apply(target, args);
        } catch (error) {
          if (isApiError(error) && error.kind === 'unauthorized') onUnauthorized();
          throw error;
        }
      };
    }
  });
}
