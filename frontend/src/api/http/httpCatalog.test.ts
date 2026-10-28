import { describe, expect, it } from 'vitest';
import { createMemoryTokenStore } from '../tokenStore';
import { HttpApiClient } from './httpApi';

/* UX-07B: empresas, informes y asociaciones empresa ↔ informe contra un servidor simulado que imita los contratos reales. */
const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
const meta = (page: number, total: number) => ({ page, per_page: 100, total, total_pages: Math.max(1, Math.ceil(total / 100)), has_more: page * 100 < total });

interface Call { method: string; path: string; query: URLSearchParams; body: Record<string, unknown> | null }
type Reply = (call: Call) => Response | null;

function server(reply: Reply = () => null) {
  const calls: Call[] = [];
  const REPORTS = [{ id: 21, reporte_id: 'aaaaaaaa-0000-0000-0000-000000000021', nombre: 'Informe uno', activo: 1 }, { id: 22, reporte_id: 'aaaaaaaa-0000-0000-0000-000000000022', nombre: 'Informe dos', activo: 1 }];
  const RELATIONS = [{ id: 71, empresa_id: 5, reporte_id: 21, activo: 1 }, { id: 72, empresa_id: 6, reporte_id: 21, activo: 1 }, { id: 73, empresa_id: 5, reporte_id: 22, activo: 0 }, { id: 74, empresa_id: 5, reporte_id: 999, activo: 1 }];
  const fetchImpl = ((url: string, init: RequestInit) => {
    const u = new URL(url); const method = init.method ?? 'GET';
    const call: Call = { method, path: u.pathname, query: u.searchParams, body: init.body ? (JSON.parse(init.body as string) as Record<string, unknown>) : null };
    calls.push(call);
    const custom = reply(call); if (custom) return Promise.resolve(custom);
    const page = Number(u.searchParams.get('page') ?? 1);
    if (method === 'GET' && u.pathname === '/api/reportes') return Promise.resolve(json(200, { success: true, data: REPORTS, pagination: meta(page, REPORTS.length) }));
    if (method === 'GET' && u.pathname === '/api/asignacion') return Promise.resolve(json(200, { success: true, data: { success: true, data: RELATIONS }, pagination: meta(page, RELATIONS.length) }));
    return Promise.resolve(json(200, { success: true }));
  }) as unknown as typeof fetch;
  const tokens = createMemoryTokenStore(); tokens.set('tok');
  return { calls, client: new HttpApiClient({ baseUrl: 'http://127.0.0.1:9', tokens, fetchImpl }) };
}

const COMPANY = { name: ' Empresa C ', legalName: 'Empresa C S.A.C.', taxId: '20999999991', city: '', country: '', email: '', phone: '', address: '' };
const REPORT = { name: ' Informe C ', description: '', link: 'https://app.powerbi.com/view?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDE' };

describe('catálogo HTTP · empresas', () => {
  it('lee el detalle editable con view=form y descarta cualquier otro campo', async () => {
    const { client, calls } = server(c => (c.path === '/api/empresas/5' ? json(200, { success: true, data: { id: 5, nombre: 'Empresa A', razon_social: 'A SAC', ruc: '20111111111', email: 'a@example.invalid', telefono: null, direccion: 'Calle 1', ciudad: 'Lima', pais: 'Perú', powerbi_tenant: 'oculto', powerbi_clientsecret: 'oculto' } }) : null));
    const detail = await client.getCompanyDetail('5');
    expect(calls[0]?.query.get('view')).toBe('form');
    expect(detail).toEqual({ id: '5', name: 'Empresa A', legalName: 'A SAC', taxId: '20111111111', city: 'Lima', country: 'Perú', email: 'a@example.invalid', phone: '', address: 'Calle 1' });
    expect(JSON.stringify(detail)).not.toMatch(/tenant|secret|no-debe/);
  });

  it('el alta envía solo los datos obligatorios y los opcionales completados; jamás credenciales de Power BI', async () => {
    const { client, calls } = server();
    await client.createCompany({ ...COMPANY, city: ' Lima ' });
    const post = calls.find(c => c.method === 'POST' && c.path === '/api/empresas');
    expect(post?.body).toEqual({ nombre: 'Empresa C', razon_social: 'Empresa C S.A.C.', ruc: '20999999991', ciudad: 'Lima' });
    expect(JSON.stringify(post?.body)).not.toMatch(/powerbi|secret|tenant|client/i);
  });

  it('la edición envía todos los campos editables (vaciar un campo lo borra) y respeta el id numérico', async () => {
    const { client, calls } = server();
    await client.updateCompany('7', COMPANY);
    const put = calls.find(c => c.method === 'PUT');
    expect(put?.path).toBe('/api/empresas/7');
    expect(Object.keys(put?.body ?? {}).sort()).toEqual(['ciudad', 'direccion', 'email', 'nombre', 'pais', 'razon_social', 'ruc', 'telefono']);
    await expect(client.updateCompany('7; DROP', COMPANY)).rejects.toMatchObject({ kind: 'validation' });
  });

  it('un rechazo de PHP (HTTP 200 con success:false o HTTP 400) llega como error y no como éxito', async () => {
    const dup = server(() => json(200, { success: false, message: 'El RUC ya está registrado' }));
    await expect(dup.client.createCompany(COMPANY)).rejects.toMatchObject({ kind: 'validation', message: 'El RUC ya está registrado' });
    const forbidden = server(() => json(403, { success: false, message: 'Acceso denegado' }));
    await expect(forbidden.client.updateCompany('5', COMPANY)).rejects.toMatchObject({ kind: 'forbidden' });
  });
});

describe('catálogo HTTP · informes', () => {
  it('lee el detalle por UUID con view=form y conserva el id interno', async () => {
    const { client, calls } = server(c => (c.path.startsWith('/api/reportes/reporteid/') ? json(200, { success: true, data: { id: 21, nombre: 'Informe uno', descripcion: null, enlace: 'https://example.invalid/1', grupo_id: 'g', reporte_id: 'r' } }) : null));
    const detail = await client.getReportDetail('aaaaaaaa-0000-0000-0000-000000000021');
    expect(calls[0]?.query.get('view')).toBe('form');
    expect(detail).toEqual({ internalId: '21', name: 'Informe uno', description: '', link: 'https://example.invalid/1', ref: 'r' });
  });

  it('el alta y la edición envían solo nombre, descripción y vínculo (nunca ids de Power BI); la edición usa el id numérico interno', async () => {
    const { client, calls } = server();
    await client.createReport(REPORT);
    await client.updateReport('21', REPORT);
    const [post, put] = calls.filter(c => c.method === 'POST' || c.method === 'PUT');
    expect(post?.path).toBe('/api/reportes');
    expect(put?.path).toBe('/api/reportes/21');
    for (const call of [post, put]) expect(call?.body).toEqual({ nombre: 'Informe C', descripcion: '', enlace: REPORT.link });
    await expect(client.updateReport('aaaaaaaa-0000-0000-0000-000000000021', REPORT)).rejects.toMatchObject({ kind: 'validation' }); // el UUID no sirve como id interno
  });

  it('un rechazo de PHP (enlace no compatible, id repetido) no se da por guardado', async () => {
    const { client } = server(() => json(200, { success: false, message: 'Ya existe un informe con ese ID de Power BI' }));
    await expect(client.createReport(REPORT)).rejects.toMatchObject({ message: 'Ya existe un informe con ese ID de Power BI' });
  });

  it('la configuración del visor se pide al endpoint autorizado y traduce los tres modos', async () => {
    const modes: Record<string, unknown> = { a: { modo: 'publico', url: 'https://app.powerbi.com/view?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDE' }, b: { modo: 'privado' }, c: { modo: 'no_compatible' } };
    const { client, calls } = server(c => { const key = c.path.split('/').at(-2) ?? ''; return c.path.endsWith('/visor') ? json(200, { success: true, data: modes[key.slice(-1)] }) : null; });
    expect(await client.getViewerConfig('00000000-0000-4000-8000-00000000000a')).toEqual({ mode: 'public', url: 'https://app.powerbi.com/view?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDE' });
    expect(await client.getViewerConfig('00000000-0000-4000-8000-00000000000b')).toEqual({ mode: 'private' });
    expect(await client.getViewerConfig('00000000-0000-4000-8000-00000000000c')).toEqual({ mode: 'unsupported' });
    expect(calls[0]?.path).toBe('/api/reportes/reporteid/00000000-0000-4000-8000-00000000000a/visor');
    expect(calls.every(c => c.method === 'GET')).toBe(true);
  });

  it('una respuesta del visor desconocida o sin enlace se rechaza (no se muestra nada)', async () => {
    for (const data of [{ modo: 'publico' }, { modo: 'otro' }, null]) {
      const { client } = server(() => json(200, { success: true, data }));
      await expect(client.getViewerConfig('00000000-0000-4000-8000-00000000000a')).rejects.toMatchObject({ kind: 'invalid_response' });
    }
  });

  it('un informe sin autorización responde igual que uno inexistente (404 → acceso denegado)', async () => {
    const { client } = server(() => json(404, { success: false, message: 'Reporte no encontrado' }));
    await expect(client.getViewerConfig('00000000-0000-4000-8000-00000000000a')).rejects.toMatchObject({ kind: 'not_found' });
  });
});

describe('catálogo HTTP · asociaciones empresa ↔ informe', () => {
  it('lista solo asociaciones vigentes y traduce el id numérico del informe a su UUID', async () => {
    const { client } = server();
    expect(await client.listReportLinks()).toEqual([
      { id: '71', companyId: '5', reportId: 'aaaaaaaa-0000-0000-0000-000000000021' },
      { id: '72', companyId: '6', reportId: 'aaaaaaaa-0000-0000-0000-000000000021' } // el mismo informe en dos empresas; 73 inactiva y 74 sin informe se descartan
    ]);
  });

  it('asocia con los ids numéricos (empresa.id y reporte.id), nunca con el UUID', async () => {
    const { client, calls } = server();
    const results = await client.linkReports({ companyId: '5', reportIds: ['aaaaaaaa-0000-0000-0000-000000000022'] });
    expect(results).toEqual([{ reportId: 'aaaaaaaa-0000-0000-0000-000000000022', status: 'granted', message: 'Informe asociado.' }]);
    expect(calls.find(c => c.method === 'POST')?.body).toEqual({ empresa_id: 5, reporte_id: 22 });
  });

  it('distingue asociado, ya existente y rechazado; un UUID desconocido ni se envía', async () => {
    const { client, calls } = server(c => {
      if (c.method !== 'POST') return null;
      return c.body?.reporte_id === 21 ? json(400, { success: false, message: 'Ya existe una asignación para esta empresa y reporte' }) : json(400, { success: false, message: 'Empresa no encontrada' });
    });
    const results = await client.linkReports({ companyId: '5', reportIds: ['aaaaaaaa-0000-0000-0000-000000000021', 'aaaaaaaa-0000-0000-0000-000000000022', 'no-existe'] });
    expect(results.map(r => r.status)).toEqual(['already', 'failed', 'failed']);
    expect(results[1]?.message).toBe('Empresa no encontrada');
    expect(calls.filter(c => c.method === 'POST')).toHaveLength(2);
  });

  it('desvincula con DELETE por el id de la asociación y rechaza ids no numéricos', async () => {
    const { client, calls } = server();
    await client.unlinkReport('71');
    expect(calls.at(-1)).toMatchObject({ method: 'DELETE', path: '/api/asignacion/71' });
    await expect(client.unlinkReport('../1')).rejects.toMatchObject({ kind: 'validation' });
  });

  it('una desvinculación rechazada por PHP se informa', async () => {
    const { client } = server(() => json(400, { success: false, message: 'Asignación no encontrada o no se pudo eliminar' }));
    await expect(client.unlinkReport('71')).rejects.toMatchObject({ message: 'Asignación no encontrada o no se pudo eliminar' });
  });
});
