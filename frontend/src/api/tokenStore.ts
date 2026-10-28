import type { TokenStore } from './types';

/** El token nunca se escribe en el almacenamiento del navegador, en cookies ni en archivos. */
export function createMemoryTokenStore(): TokenStore {
  let token: string | null = null;
  return {
    get: () => token,
    set: value => {
      token = value;
    }
  };
}
