import { describe, expect, it } from 'vitest';
import { ApiError } from '../errors';
import { createMemoryTokenStore } from '../tokenStore';
import { HttpApiClient } from './httpApi';

/* UX-08B: el formulario devuelve al servidor la versión que leyó (bloqueo optimista); un 409 llega al usuario con su mensaje. */
const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

interface Call { method: string; path: string; body: Record<string, unknown> | null }

function server(putStatus = 200) {
  const calls: Call[] = [];
  const fetchImpl = ((url: string, init: RequestInit) => {
    const u = new URL(url);
    const method = init.method ?? 'GET';
    calls.push({ method, path: u.pathname, body: init.body ? (JSON.parse(init.body as string) as Record<string, unknown>) : null });
    if (method === 'GET' && u.pathname === '/api/empresas/7') return Promise.resolve(json(200, { success: true, data: { id: 7, version: 'a1b2c3d4e5f60718', nombre: 'Empresa', razon_social: 'Empresa SAC', ruc: '20123456789' } }));
    if (method === 'GET' && u.pathname === '/api/reportes/9') return Promise.resolve(json(200, { success: true, data: { id: 9, version: '0011223344556677', nombre: 'Informe', descripcion: '', enlace: 'https://app.powerbi.com/view?r=X', reporte_id: 'ref' } }));
    if (method === 'PUT') return Promise.resolve(putStatus === 409 ? json(409, { success: false, message: 'El registro cambió mientras lo editaba. Recargue e intente de nuevo.' }) : json(200, { success: true }));
    if (method === 'POST') return Promise.resolve(json(200, { success: true }));
    return Promise.resolve(json(404, { success: false, message: 'no' }));
  }) as unknown as typeof fetch;
  const tokens = createMemoryTokenStore(); tokens.set('tok');
  return { calls, client: new HttpApiClient({ baseUrl: 'http://127.0.0.1:9', tokens, fetchImpl }) };
}

describe('UX-08B · bloqueo optimista en el cliente HTTP', () => {
  it('la empresa devuelve al servidor la versión que leyó al editar', async () => {
    const { client, calls } = server();
    const detail = await client.getCompanyDetail('7');
    expect(detail.version).toBe('a1b2c3d4e5f60718');
    await client.updateCompany('7', detail);
    const put = calls.find(c => c.method === 'PUT');
    expect(put?.body?.version).toBe('a1b2c3d4e5f60718');
  });

  it('el informe devuelve al servidor la versión que leyó al editar', async () => {
    const { client, calls } = server();
    const detail = await client.getReportDetail('9');
    expect(detail.version).toBe('0011223344556677');
    await client.updateReport('9', detail);
    expect(calls.find(c => c.method === 'PUT')?.body?.version).toBe('0011223344556677');
  });

  it('las altas no envían versión', async () => {
    const { client, calls } = server();
    await client.createCompany({ name: 'N', legalName: 'N', taxId: '20123456789', city: '', country: '', email: '', phone: '', address: '', version: 'no-debe-viajar' });
    await client.createReport({ name: 'R', description: '', link: 'https://app.powerbi.com/view?r=Y' });
    expect(calls.filter(c => c.method === 'POST').every(c => !('version' in (c.body ?? {})))).toBe(true);
  });

  it('un 409 por edición concurrente llega como error con el mensaje del servidor', async () => {
    const { client } = server(409);
    const detail = await client.getCompanyDetail('7');
    const error = await client.updateCompany('7', detail).then(() => null, (e: unknown) => e);
    expect(error).toBeInstanceOf(ApiError);
    expect((error as ApiError).message).toContain('cambió mientras lo editaba');
  });
});
