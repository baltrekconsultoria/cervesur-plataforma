import { describe, expect, it } from 'vitest';
import { createMemoryTokenStore } from '../tokenStore';
import { HttpApiClient } from './httpApi';

/* UX-08B: la paginación local de Usuarios solo se aplica sobre el conjunto COMPLETO. /api/users trae `total` heredado (= filas de la página, B-12)
 * y el total real en `pagination.total`: el cliente debe leer todas las páginas y fiarse solo del bloque `pagination`. */
const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
const user = (n: number) => ({ id: n, username: `u${n}`, email: `u${n}@example.invalid`, first_name: 'U', last_name: String(n), role: 'usuario', is_active: n % 10 === 0 ? 0 : 1 });

function server(total: number, declared = total) {
  const calls: string[] = [];
  const fetchImpl = ((url: string) => {
    const u = new URL(url);
    calls.push(u.pathname + u.search);
    if (u.pathname === '/api/users') {
      const page = Number(u.searchParams.get('page') ?? 1);
      const perPage = Number(u.searchParams.get('per_page') ?? 100);
      const rows = Array.from({ length: total }, (_, i) => user(i + 1)).slice((page - 1) * perPage, page * perPage);
      // `total` de nivel superior = filas de ESTA página (contrato heredado); el total real está en `pagination`.
      return Promise.resolve(json(200, { success: true, users: rows, total: rows.length, pagination: { page, per_page: perPage, total: declared, total_pages: Math.ceil(declared / perPage), has_more: page * perPage < declared } }));
    }
    if (u.pathname === '/api/empresa-usuario') return Promise.resolve(json(200, { success: true, data: [], pagination: { page: 1, per_page: 100, total: 0, total_pages: 1, has_more: false } }));
    return Promise.resolve(json(404, { success: false, message: 'no' }));
  }) as unknown as typeof fetch;
  const tokens = createMemoryTokenStore(); tokens.set('tok');
  return { calls, client: new HttpApiClient({ baseUrl: 'http://127.0.0.1:9', tokens, fetchImpl }) };
}

describe('UX-08B · Usuarios: paginación local sobre el conjunto completo', () => {
  it('lee todas las páginas del servidor (230 usuarios = 3 páginas) y pide status=all', async () => {
    const { client, calls } = server(230);
    const users = await client.listUsers();
    expect(users).toHaveLength(230);
    expect(new Set(users.map(u => u.id)).size).toBe(230);
    const pages = calls.filter(c => c.startsWith('/api/users')).map(c => new URL(`http://x${c}`).searchParams);
    expect(pages.map(p => p.get('page'))).toEqual(['1', '2', '3']);
    expect(pages.every(p => p.get('status') === 'all' && p.get('per_page') === '100')).toBe(true);
  });

  it('el filtro Activos cuenta sobre los 230, no sobre la primera página', async () => {
    const { client } = server(230);
    const users = await client.listUsers();
    expect(users.filter(u => u.active)).toHaveLength(207);
    expect(users.filter(u => !u.active)).toHaveLength(23);
  });

  it('rechaza el listado si las filas recibidas no coinciden con el total declarado (nunca una lista parcial)', async () => {
    const { client } = server(150, 230);
    await expect(client.listUsers()).rejects.toMatchObject({ kind: 'invalid_response' });
  });
});
