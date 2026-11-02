import { describe, expect, it } from 'vitest';
import { ApiError } from '../errors';
import { createMemoryTokenStore } from '../tokenStore';
import { HttpApiClient } from './httpApi';

/* UX-08B.2: contrato V2 solo en escrituras, rutas de baja/reactivación, 409 como conflicto y lectura del historial. */
const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

interface Call { method: string; path: string; contract: string | null; auth: string | null }

function server(handler: (method: string, path: string) => Response | null = () => null) {
  const calls: Call[] = [];
  const fetchImpl = ((url: string, init: RequestInit) => {
    const u = new URL(url);
    const method = init.method ?? 'GET';
    const headers = (init.headers ?? {}) as Record<string, string>;
    calls.push({ method, path: u.pathname + u.search, contract: headers['X-API-Contract'] ?? null, auth: headers.Authorization ?? null });
    return Promise.resolve(handler(method, u.pathname) ?? json(200, { success: true }));
  }) as unknown as typeof fetch;
  const tokens = createMemoryTokenStore(); tokens.set('tok');
  return { calls, client: new HttpApiClient({ baseUrl: 'http://127.0.0.1:9', tokens, fetchImpl }) };
}

describe('UX-08B.2 · contrato V2', () => {
  it('las escrituras autenticadas piden el contrato 2; las lecturas y el login no', async () => {
    const { client, calls } = server((method, path) => (path === '/api/login' ? json(200, { success: true, token: 't', user: { id: 1, username: 'a', email: 'a@x.test', role: 'user' } }) : method === 'GET' ? json(200, { success: true, data: [], pagination: { page: 1, per_page: 100, total: 0, total_pages: 1, has_more: false } }) : null));
    await client.login({ login: 'a', password: 'b' });
    await client.listCompanies();
    await client.deactivateCompany('7');
    expect(calls.find(c => c.path === '/api/login')?.contract).toBeNull();
    expect(calls.find(c => c.method === 'GET')?.contract).toBeNull();
    expect(calls.find(c => c.method === 'DELETE')?.contract).toBe('2');
  });

  it('baja y reactivación usan las rutas del servidor con ids numéricos', async () => {
    const { client, calls } = server();
    await client.deactivateCompany('7');
    await client.reactivateCompany('7');
    await client.deactivateReport('9');
    await client.reactivateReport('9');
    await client.reactivateAssignment('40');
    expect(calls.map(c => `${c.method} ${c.path}`)).toEqual([
      'DELETE /api/empresas/7', 'POST /api/empresas/7/reactivate', 'DELETE /api/reportes/9', 'POST /api/reportes/9/reactivate', 'POST /api/empresa-reporte-usuario/40/reactivate'
    ]);
  });

  it('un id malformado no llega al servidor', async () => {
    const { client, calls } = server();
    for (const bad of ['', '0', '-1', '1; DROP', '01', '7/reactivate']) {
      await expect(client.reactivateCompany(bad)).rejects.toMatchObject({ status: 400 });
      await expect(client.reactivateAssignment(bad)).rejects.toMatchObject({ status: 400 });
    }
    expect(calls).toHaveLength(0);
  });

  it('409 llega como conflicto con el mensaje del servidor; 404, 403 y 500 conservan su tipo', async () => {
    const cases: Array<[number, string, string]> = [[409, 'La empresa ya está activa', 'conflict'], [404, 'Empresa no encontrada', 'not_found'], [403, 'Acceso denegado', 'forbidden'], [500, 'Error al cambiar el estado de la empresa', 'server']];
    for (const [status, message, kind] of cases) {
      const { client } = server(() => json(status, { success: false, message }));
      const error = await client.reactivateCompany('7').then(() => null, (e: unknown) => e);
      expect(error).toBeInstanceOf(ApiError);
      expect(error).toMatchObject({ status, kind, message });
    }
  });

  it('conceder un acceso que ya existe (409) se informa por informe, sin romper el asistente', async () => {
    const { client } = server((method, path) => {
      if (method === 'GET' && path === '/api/empresa-usuario') return json(200, { success: true, data: [{ id: 1, user_id: 5, empresa_id: 1 }], pagination: { page: 1, per_page: 100, total: 1, total_pages: 1, has_more: false } });
      if (method === 'GET' && path === '/api/asignacion') return json(200, { success: true, data: { success: true, data: [{ id: 7, empresa_id: 1, reporte_id: 11, activo: 1 }] }, pagination: { page: 1, per_page: 100, total: 1, total_pages: 1, has_more: false } });
      if (method === 'POST') return json(409, { success: false, message: 'La relación ya existe' });
      return null;
    });
    const results = await client.grantAccess({ userId: '5', companyId: '1', reportIds: ['11'] });
    expect(results).toEqual([{ reportId: '11', status: 'already', message: 'El usuario ya tenía este acceso.' }]);
  });
});

describe('UX-08B.2 · historial', () => {
  const page = { page: 1, per_page: 25, total: 1, total_pages: 1, has_more: false };

  it('lee la bitácora del servidor y no depende de campos sensibles', async () => {
    const row = { id: 3, occurred_at: '2026-11-02 10:00:00.123', correlation_id: 'a'.repeat(32), actor_user_id: 1, actor: ' Elena Torres ', action: 'empresa.update', entity: 'empresa', entity_id: 7, before: { ciudad: 'Lima' }, after: { ciudad: 'Cusco', powerbi_campos: ['powerbi_tenant'] } };
    const { client, calls } = server(() => json(200, { success: true, data: [row], pagination: page, audit_available: true }));
    const result = await client.listAudit({ entity: 'empresa', page: 2 });
    expect(calls[0]?.path).toBe('/api/auditoria?entity=empresa&per_page=25&page=2');
    expect(result.available).toBe(true);
    expect(result.total).toBe(1);
    expect(result.entries[0]).toMatchObject({ id: '3', actor: 'Elena Torres', action: 'empresa.update', entityId: '7', before: { ciudad: 'Lima' } });
  });

  it('indica cuando el servidor todavía no tiene la bitácora', async () => {
    const { client } = server(() => json(200, { success: true, data: [], pagination: { ...page, total: 0 }, audit_available: false }));
    expect(await client.listAudit({ entity: 'access', page: 1 })).toEqual({ entries: [], total: 0, available: false });
  });

  it('rechaza una respuesta sin paginación o con filas incompletas', async () => {
    await expect(server(() => json(200, { success: true, data: [] })).client.listAudit({ entity: 'access', page: 1 })).rejects.toMatchObject({ kind: 'invalid_response' });
    await expect(server(() => json(200, { success: true, data: [{ id: 1 }], pagination: page })).client.listAudit({ entity: 'access', page: 1 })).rejects.toMatchObject({ kind: 'invalid_response' });
  });

  it('un no administrador recibe 403 y no se muestran datos', async () => {
    const { client } = server(() => json(403, { success: false, message: 'Acceso denegado' }));
    await expect(client.listAudit({ entity: 'access', page: 1 })).rejects.toMatchObject({ kind: 'forbidden' });
  });
});
