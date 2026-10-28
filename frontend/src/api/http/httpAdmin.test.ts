import { describe, expect, it } from 'vitest';
import { createMemoryTokenStore } from '../tokenStore';
import { HttpApiClient } from './httpApi';

/* UX-07A: administración (usuarios, empresas, accesos) contra un servidor simulado en memoria que imita los contratos reales. */
const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
const meta = (page: number, total: number, perPage = 100) => ({ page, per_page: perPage, total, total_pages: Math.max(1, Math.ceil(total / perPage)), has_more: page * perPage < total });

interface Call { method: string; path: string; query: URLSearchParams; body: Record<string, unknown> | null }

function server(state: { links: Array<{ id: number; user_id: number; empresa_id: number }>; rejectGrant?: (body: Record<string, unknown>) => string | null; users?: unknown[] }) {
  const calls: Call[] = [];
  const REPORTS = [{ id: 11, reporte_id: 'uuid-a', nombre: 'Reporte A', activo: 1 }, { id: 12, reporte_id: 'uuid-b', nombre: 'Reporte B', activo: 1 }, { id: 13, reporte_id: 'uuid-c', nombre: 'Reporte C', activo: 1 }];
  // id de relación empresa_reporte (7, 8, 9) deliberadamente distinto del id del informe (11, 12, 13) y de su referencia histórica.
  const RELATIONS = [{ id: 7, empresa_id: 1, reporte_id: 11, activo: 1 }, { id: 8, empresa_id: 1, reporte_id: 12, activo: 1 }, { id: 9, empresa_id: 2, reporte_id: 13, activo: 1 }];
  const fetchImpl = ((url: string, init: RequestInit) => {
    const u = new URL(url); const method = init.method ?? 'GET';
    const body = init.body ? (JSON.parse(init.body as string) as Record<string, unknown>) : null;
    calls.push({ method, path: u.pathname, query: u.searchParams, body });
    const page = Number(u.searchParams.get('page') ?? 1);
    const list = (rows: unknown[], key = 'data', wrap = false) => json(200, { success: true, [key]: wrap ? { success: true, data: rows } : rows, pagination: meta(page, rows.length) });
    if (method === 'GET') {
      if (u.pathname === '/api/users') return Promise.resolve(list(state.users ?? [], 'users'));
      if (u.pathname === '/api/reportes') return Promise.resolve(list(REPORTS));
      if (u.pathname === '/api/asignacion') return Promise.resolve(list(RELATIONS, 'data', true));
      if (u.pathname === '/api/empresa-usuario') return Promise.resolve(list(state.links));
      if (u.pathname === '/api/empresa-reporte-usuario') return Promise.resolve(list([{ id: 40, user_id: 5, empresa_id: 1, empresa_reporte_id: 7, reporte_id: 'uuid-a', activo: true }, { id: 41, user_id: 5, empresa_id: 1, empresa_reporte_id: 8, reporte_id: 'uuid-a', activo: true }, { id: 42, user_id: 5, empresa_id: 1, empresa_reporte_id: 999, reporte_id: 'uuid-z', activo: true }]));
    }
    if (method === 'POST' && u.pathname === '/api/empresa-usuario') { state.links.push({ id: 99, user_id: Number(body?.user_id), empresa_id: Number(body?.empresa_id) }); return Promise.resolve(json(200, { success: true, InsertedId: 99 })); }
    if (method === 'POST' && u.pathname === '/api/empresa-reporte-usuario') {
      const reason = state.rejectGrant?.(body ?? {});
      return Promise.resolve(json(200, reason ? { success: false, message: reason } : { success: true, InsertedId: 500 }));
    }
    if (method === 'POST' && u.pathname === '/api/register') return Promise.resolve(json(200, { success: true, message: 'Usuario registrado exitosamente' }));
    if (method === 'DELETE') return Promise.resolve(json(200, { success: true, message: 'Relación eliminada' }));
    return Promise.resolve(json(404, { success: false, message: 'no' }));
  }) as unknown as typeof fetch;
  const tokens = createMemoryTokenStore(); tokens.set('tok');
  return { calls, client: new HttpApiClient({ baseUrl: 'http://127.0.0.1:9', tokens, fetchImpl }) };
}

describe('administración HTTP · usuarios', () => {
  const USERS = [
    { id: 5, first_name: 'Ana', last_name: 'Prueba', username: 'ana', email: 'ana@example.invalid', phone: null, role: 'user', is_active: true },
    { id: 6, first_name: 'Luz', last_name: 'Vieja', username: 'luz', email: 'luz@example.invalid', phone: null, role: 'usuario', is_active: true },
    { id: 7, first_name: 'Rol', last_name: 'Raro', username: 'raro', email: 'raro@example.invalid', phone: null, role: 'superadmin', is_active: true },
    { id: 8, first_name: 'Ada', last_name: 'Admin', username: 'ada', email: 'ada@example.invalid', phone: null, role: 'admin', is_active: false }
  ];

  it('lista todos los usuarios (activos e inactivos) con sus empresas y marca los roles no reconocidos', async () => {
    const { client, calls } = server({ links: [{ id: 1, user_id: 5, empresa_id: 1 }, { id: 2, user_id: 5, empresa_id: 2 }], users: USERS });
    const users = await client.listUsers();
    expect(users.map(u => [u.id, u.role, u.active, u.loginBlocked ?? false])).toEqual([['5', 'user', true, false], ['6', 'user', true, false], ['7', 'user', true, true], ['8', 'admin', false, false]]);
    expect(users[0]?.companyIds).toEqual(['1', '2']);
    const list = calls.find(c => c.path === '/api/users');
    expect(list?.query.get('status')).toBe('all');
    expect(list?.query.get('per_page')).toBe('100');
  });

  it('lee todas las páginas y falla si el total no coincide', async () => {
    const rows = Array.from({ length: 230 }, (_, i) => ({ id: i + 1, first_name: 'U', last_name: String(i), username: `u${i}`, email: `u${i}@example.invalid`, role: 'user', is_active: true }));
    const pages: number[] = [];
    const tokens = createMemoryTokenStore();
    const fetchImpl = ((url: string) => {
      const u = new URL(url); const page = Number(u.searchParams.get('page'));
      if (u.pathname === '/api/users') { pages.push(page); return Promise.resolve(json(200, { success: true, users: rows.slice((page - 1) * 100, page * 100), pagination: meta(page, rows.length) })); }
      return Promise.resolve(json(200, { success: true, data: [], pagination: meta(page, 0) }));
    }) as unknown as typeof fetch;
    const users = await new HttpApiClient({ baseUrl: 'http://127.0.0.1:9', tokens, fetchImpl }).listUsers();
    expect(users).toHaveLength(230);
    expect(pages).toEqual([1, 2, 3]);
    const broken = (() => json(200, { success: true, users: rows.slice(0, 10), pagination: meta(1, 230, 10) }));
    await expect(new HttpApiClient({ baseUrl: 'http://127.0.0.1:9', tokens, fetchImpl: ((url: string) => (new URL(url).pathname === '/api/users' ? Promise.resolve(broken()) : Promise.resolve(json(200, { success: true, data: [], pagination: meta(1, 0) })))) as unknown as typeof fetch }).listUsers()).rejects.toMatchObject({ kind: 'invalid_response' });
  });

  it('alta: envía solo datos personales; nunca role, is_active ni session_version', async () => {
    const { client, calls } = server({ links: [] });
    await client.createUser({ firstName: ' Operador ', lastName: 'Prueba', username: 'operador.prueba', email: 'operador.prueba@example.invalid', phone: '', password: 'synthetic-password' });
    const post = calls.find(c => c.method === 'POST' && c.path === '/api/register');
    expect(post?.body).toEqual({ first_name: 'Operador', last_name: 'Prueba', username: 'operador.prueba', email: 'operador.prueba@example.invalid', password: 'synthetic-password' });
    expect(Object.keys(post?.body ?? {})).not.toEqual(expect.arrayContaining(['role']));
  });

  it('alta rechazada por PHP (HTTP 200 con success:false): el error llega a la interfaz y no se da por creado', async () => {
    const tokens = createMemoryTokenStore();
    const fetchImpl = (() => Promise.resolve(json(200, { success: false, message: 'El email ya está registrado' }))) as unknown as typeof fetch;
    await expect(new HttpApiClient({ baseUrl: 'http://127.0.0.1:9', tokens, fetchImpl }).createUser({ firstName: 'A', lastName: 'B', username: 'abc', email: 'a@example.invalid', phone: '', password: 'synthetic-password' }))
      .rejects.toMatchObject({ kind: 'validation', message: 'El email ya está registrado' });
  });
});

describe('administración HTTP · accesos', () => {
  it('lista accesos con los identificadores separados: el informe se resuelve por la relación empresa–informe, no por el reporte_id histórico', async () => {
    const { client } = server({ links: [] });
    // Las filas 40 y 41 traen la MISMA referencia histórica ('uuid-a'): por eso no sirve para unir. La 42 apunta a una relación que no está vigente.
    expect(await client.listAssignments()).toEqual([
      { id: '40', userId: '5', reportId: '11', companyId: '1' },
      { id: '41', userId: '5', reportId: '12', companyId: '1' }
    ]);
  });

  it('concede acceso: crea la relación con la empresa si falta y usa el id empresa_reporte (no el id del informe ni su referencia histórica)', async () => {
    const { client, calls } = server({ links: [] });
    const results = await client.grantAccess({ userId: '5', companyId: '1', reportIds: ['11', '12'] });
    expect(results.map(r => r.status)).toEqual(['granted', 'granted']);
    const writes = calls.filter(c => c.method === 'POST').map(c => [c.path, c.body]);
    expect(writes).toEqual([
      ['/api/empresa-usuario', { user_id: 5, empresa_id: 1 }],
      ['/api/empresa-reporte-usuario', { user_id: 5, empresa_reporte_id: 7 }],
      ['/api/empresa-reporte-usuario', { user_id: 5, empresa_reporte_id: 8 }]
    ]);
  });

  it('no duplica la relación con la empresa si ya existe', async () => {
    const { client, calls } = server({ links: [{ id: 1, user_id: 5, empresa_id: 1 }] });
    await client.grantAccess({ userId: '5', companyId: '1', reportIds: ['11'] });
    expect(calls.filter(c => c.method === 'POST' && c.path === '/api/empresa-usuario')).toHaveLength(0);
  });

  it('un informe que no pertenece a la empresa no se envía al servidor', async () => {
    const { client, calls } = server({ links: [{ id: 1, user_id: 5, empresa_id: 1 }] });
    const [result] = await client.grantAccess({ userId: '5', companyId: '1', reportIds: ['13'] }); // el informe 13 es de la empresa 2
    expect(result).toMatchObject({ reportId: '13', status: 'failed' });
    expect(calls.filter(c => c.method === 'POST')).toHaveLength(0);
  });

  it('un id de informe que no es numérico canónico (p. ej. una referencia histórica) no se envía nunca', async () => {
    const { client, calls } = server({ links: [{ id: 1, user_id: 5, empresa_id: 1 }] });
    const results = await client.grantAccess({ userId: '5', companyId: '1', reportIds: ['uuid-a', '011', '11 ', '1e1'] });
    expect(results.map(r => r.status)).toEqual(['failed', 'failed', 'failed', 'failed']);
    expect(calls.filter(c => c.method === 'POST')).toHaveLength(0);
  });

  it('distingue concedido, ya existente y rechazado por PHP; un rechazo nunca figura como guardado', async () => {
    const { client } = server({ links: [{ id: 1, user_id: 5, empresa_id: 1 }], rejectGrant: body => (body.empresa_reporte_id === 7 ? 'La relación (user_id, empresa_reporte_id) ya existe' : body.empresa_reporte_id === 8 ? 'El usuario no está asignado a la empresa de este informe' : null) });
    const results = await client.grantAccess({ userId: '5', companyId: '1', reportIds: ['11', '12'] });
    expect(results.map(r => r.status)).toEqual(['already', 'failed']);
    expect(results[1]?.message).toContain('no está asignado');
  });

  it('si falla el alta de la relación con la empresa no se concede nada y se propaga el error', async () => {
    const tokens = createMemoryTokenStore(); const posts: string[] = [];
    const fetchImpl = ((url: string, init: RequestInit) => {
      const u = new URL(url);
      if (init.method === 'POST') { posts.push(u.pathname); return Promise.resolve(json(200, { success: false, message: 'La empresa o el usuario especificado no existe o está inactivo' })); }
      return Promise.resolve(json(200, { success: true, data: [], pagination: meta(1, 0) }));
    }) as unknown as typeof fetch;
    await expect(new HttpApiClient({ baseUrl: 'http://127.0.0.1:9', tokens, fetchImpl }).grantAccess({ userId: '5', companyId: '1', reportIds: ['11'] })).rejects.toMatchObject({ message: expect.stringContaining('inactivo') as string });
    expect(posts).toEqual(['/api/empresa-usuario']);
  });

  it('identificadores no numéricos se rechazan antes de llamar al servidor', async () => {
    const { client, calls } = server({ links: [] });
    await expect(client.grantAccess({ userId: '5; DROP', companyId: '1', reportIds: [] })).rejects.toMatchObject({ kind: 'validation' });
    await expect(client.revokeAssignment('../1')).rejects.toMatchObject({ kind: 'validation' });
    expect(calls).toHaveLength(0);
  });

  it('retira un acceso con DELETE por el id de la relación user_empresa_reporte', async () => {
    const { client, calls } = server({ links: [] });
    await client.revokeAssignment('40');
    expect(calls.at(-1)).toMatchObject({ method: 'DELETE', path: '/api/empresa-reporte-usuario/40' });
  });

  it('retirada rechazada por PHP: se informa y no se da por hecha', async () => {
    const tokens = createMemoryTokenStore();
    const fetchImpl = (() => Promise.resolve(json(200, { success: false, message: 'Relación no encontrada o ya inactiva' }))) as unknown as typeof fetch;
    await expect(new HttpApiClient({ baseUrl: 'http://127.0.0.1:9', tokens, fetchImpl }).revokeAssignment('40')).rejects.toMatchObject({ message: 'Relación no encontrada o ya inactiva' });
  });

  it('403 al escribir: el error se propaga (sin reintentos)', async () => {
    const tokens = createMemoryTokenStore();
    const fetchImpl = (() => Promise.resolve(json(403, { success: false, message: 'Acceso denegado' }))) as unknown as typeof fetch;
    await expect(new HttpApiClient({ baseUrl: 'http://127.0.0.1:9', tokens, fetchImpl }).revokeAssignment('40')).rejects.toMatchObject({ kind: 'forbidden' });
  });
});
