import type { Role } from '../api/types';

export const homePath = (role: Role): string => (role === 'admin' ? '/admin' : '/reportes');

/**
 * Devuelve una ruta interna segura para retomar tras un 401, o null.
 * - Solo rutas internas (nada de URLs absolutas ni `//host`).
 * - Nunca se retoma un informe concreto: se vuelve al listado y el acceso se reevalúa.
 * - La ruta debe corresponder al rol de quien reingresa.
 */
export function sanitizeReturnTo(raw: unknown, role: Role): string | null {
  if (typeof raw !== 'string' || !raw.startsWith('/') || raw.startsWith('//') || raw.includes('\\') || /^\/[a-z][a-z0-9+.-]*:/i.test(raw)) return null;
  const [pathname = '', search = ''] = raw.split('?');
  const suffix = search ? `?${search}` : '';
  if (pathname.startsWith('/login')) return null;
  if (/^\/reportes\/[^/]+/.test(pathname)) return role === 'admin' ? '/admin/reportes' : '/reportes';
  const isAdminPath = pathname === '/admin' || pathname.startsWith('/admin/');
  if (isAdminPath !== (role === 'admin')) return null;
  return `${pathname}${suffix}`;
}
