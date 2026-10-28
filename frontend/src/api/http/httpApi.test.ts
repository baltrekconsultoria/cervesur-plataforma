import { describe, expect, it, vi } from 'vitest';
import type { ApiError } from '../errors';
import { isDenied } from '../errors';
import { createMemoryTokenStore } from '../tokenStore';
import type { ApiClient } from '../types';
import { composeReportCatalog } from './adapters';
import { HttpApiClient } from './httpApi';

const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
const REAL_USER = { id: 1, username: 'usera', email: 'usera@example.invalid', first_name: 'Demo', last_name: 'User', role: 'user' };
type Handler = (url: URL, init: RequestInit) => Response | Promise<Response>;
const make = (impl: Handler, tokens = createMemoryTokenStore()) => ({
  tokens,
  client: new HttpApiClient({ baseUrl: 'http://127.0.0.1:9/', tokens, fetchImpl: ((url: string, init: RequestInit) => Promise.resolve(impl(new URL(url), init))) as unknown as typeof fetch })
});
const meta = (page: number, total: number, perPage = 100) => ({ page, per_page: perPage, total, total_pages: Math.ceil(total / perPage), has_more: page * perPage < total });

/* Datos del servidor simulado: 3 informes, 3 empresas (una no autorizada para el usuario no aparece en /empresas). */
const REPORTS = [
  { id: 4, reporte_id: 'uuid-4', nombre: 'Ventas regionales', descripcion: 'Comercial por región', activo: 1 },
  { id: 1, reporte_id: 'uuid-1', nombre: 'Reporte A', descripcion: null, activo: 1 },
  { id: 9, reporte_id: 'uuid-9', nombre: 'Sin relación', descripcion: 'Sin pares', activo: 1 }
];
const COMPANIES = [{ id: 2, nombre: 'Empresa B', ruc: '2', ciudad: 'B', activo: 1 }, { id: 1, nombre: 'Empresa A', ruc: '1', ciudad: 'A', activo: 1 }];
const ASSIGNMENTS = [
  { id: 1, empresa_id: 1, reporte_id: 4, activo: 1 }, { id: 2, empresa_id: 2, reporte_id: 4, activo: 1 }, // informe en dos empresas
  { id: 3, empresa_id: 1, reporte_id: 1, activo: 1 }, { id: 4, empresa_id: 1, reporte_id: 1, activo: 1 },  // duplicado
  { id: 5, empresa_id: 3, reporte_id: 1, activo: 1 },                                                      // empresa no autorizada
  { id: 6, empresa_id: 2, reporte_id: 1, activo: 0 }                                                       // relación inactiva
];
const serve = (overrides: Partial<Record<string, Handler>> = {}): Handler => (url, init) => {
  const hit = overrides[url.pathname]; if (hit) return hit(url, init);
  const page = Number(url.searchParams.get('page'));
  const pick = (rows: unknown[], wrap = false) => json(200, { success: true, ...(wrap ? { data: { success: true, data: rows } } : { data: rows }), count: rows.length, pagination: meta(page, rows.length) });
  if (url.pathname === '/api/reportes') return pick(REPORTS);
  if (url.pathname === '/api/asignacion') return pick(ASSIGNMENTS, true);
  if (url.pathname === '/api/empresas') return pick(COMPANIES);
  return json(404, { success: false, message: 'no' });
};

describe('proveedor HTTP · sesión y perfil (sin red real)', () => {
  it('login correcto: mapea usuario y token, sin enviar Authorization', async () => {
    const calls: Array<[URL, RequestInit]> = [];
    const { tokens, client } = make((url, init) => { calls.push([url, init]); return json(200, { success: true, message: 'Login exitoso', token: 'tok', user: REAL_USER }); });
    tokens.set('viejo');
    const res = await client.login({ login: 'usera', password: 'p' });
    expect(res).toMatchObject({ token: 'tok', user: { id: '1', displayName: 'Demo User', role: 'user' } });
    expect(calls[0]?.[0].pathname).toBe('/api/login');
    expect((calls[0]?.[1].headers as Record<string, string>).Authorization).toBeUndefined();
    expect(calls[0]?.[1].credentials).toBe('omit');
  });

  it('login fallido: HTTP 200 con success:false se traduce a 401', async () => {
    const { client } = make(() => json(200, { success: false, message: 'Credenciales inválidas' }));
    await expect(client.login({ login: 'usera', password: 'mala' })).rejects.toMatchObject({ status: 401, kind: 'unauthorized', message: 'Credenciales inválidas' });
  });

  it('login con rol inesperado o respuesta malformada se rechaza', async () => {
    await expect(make(() => json(200, { success: true, token: 't', user: { ...REAL_USER, role: 'superadmin' } })).client.login({ login: 'w', password: 'p' })).rejects.toMatchObject({ kind: 'invalid_response' });
    await expect(make(() => json(200, { success: true, user: REAL_USER })).client.login({ login: 'a', password: 'b' })).rejects.toMatchObject({ kind: 'invalid_response' });
    await expect(make(() => new Response('<html>', { status: 200 })).client.login({ login: 'a', password: 'b' })).rejects.toMatchObject({ kind: 'invalid_response' });
  });

  it('me() usa /api/user/profile con Bearer y lee "profile"', async () => {
    const calls: Array<[URL, RequestInit]> = [];
    const { tokens, client } = make((url, init) => { calls.push([url, init]); return json(200, { success: true, profile: { ...REAL_USER, phone: '999', is_active: true } }); });
    tokens.set('tok-1');
    expect(await client.me()).toMatchObject({ id: '1', phone: '999', active: true });
    expect(calls[0]?.[0].pathname).toBe('/api/user/profile');
    expect((calls[0]?.[1].headers as Record<string, string>).Authorization).toBe('Bearer tok-1');
  });

  it('logout: POST /api/logout con Bearer; un 401 previo no es error; otros fallos sí', async () => {
    const calls: Array<[URL, RequestInit]> = [];
    const ok = make((url, init) => { calls.push([url, init]); return json(200, { success: true }); });
    ok.tokens.set('tok');
    await ok.client.logout();
    expect(calls[0]?.[0].pathname).toBe('/api/logout');
    expect((calls[0]?.[1].headers as Record<string, string>).Authorization).toBe('Bearer tok');
    await expect(make(() => json(401, { success: false, message: 'No autorizado' })).client.logout()).resolves.toBeUndefined();
    await expect(make(() => json(500, { success: false, message: 'Error interno del servidor' })).client.logout()).rejects.toMatchObject({ kind: 'server' });
  });

  it('traduce 401/403 y fallos de red a ApiError tipados', async () => {
    await expect(make(() => json(401, { success: false, message: 'No autorizado' })).client.me()).rejects.toMatchObject({ kind: 'unauthorized', status: 401 });
    await expect(make(() => json(403, {})).client.me()).rejects.toMatchObject({ kind: 'forbidden' });
    await expect(make(() => { throw new TypeError('fallo'); }).client.me()).rejects.toMatchObject({ kind: 'network', status: 0 });
  });

  it('lo no integrado falla de forma explícita y sin tocar la red', async () => {
    const fetchImpl = vi.fn();
    const { client } = make(fetchImpl as never);
    await expect((client as ApiClient).updateProfile({ displayName: 'x', phone: '' })).rejects.toMatchObject({ kind: 'not_integrated' });
    expect(fetchImpl).not.toHaveBeenCalled();
  });
});

describe('proveedor HTTP · catálogo compuesto y paginación', () => {
  it('compone informes + asignaciones + empresas respetando solo lo autorizado', async () => {
    const { client } = make(serve());
    const reports = await client.listReports();
    expect(reports.map(r => r.name)).toEqual(['Ventas regionales', 'Reporte A', 'Sin relación']);
    const byName = Object.fromEntries(reports.map(r => [r.name, r.companyIds]));
    expect(byName['Ventas regionales']).toEqual(['1', '2']);          // dos empresas, orden alfabético por nombre
    expect(byName['Reporte A']).toEqual(['1']);                        // duplicado colapsado; empresa 3 (no autorizada) e inactiva descartadas
    expect(byName['Sin relación']).toEqual([]);                        // nunca se inventa empresa
    expect(reports.every(r => r.group === '')).toBe(true);             // nunca se inventa grupo
    expect(reports.find(r => r.name === 'Ventas regionales')?.companyId).toBe('1');
  });

  it('pide view=minimal con per_page=100 y Bearer; no usa enlaces ni datos técnicos', async () => {
    const urls: Array<{ path: string; q: string; auth?: string }> = [];
    const { tokens, client } = make(serve({}));
    tokens.set('tok-x');
    const spy = make((url, init) => { urls.push({ path: url.pathname, q: url.search, auth: (init.headers as Record<string, string>).Authorization }); return serve()(url, init); }, tokens);
    await spy.client.listReports();
    expect(client).toBeDefined();
    expect(new Set(urls.map(u => u.path))).toEqual(new Set(['/api/reportes', '/api/asignacion', '/api/empresas']));
    expect(urls.every(u => u.q === '?view=minimal&per_page=100&page=1' && u.auth === 'Bearer tok-x')).toBe(true);
  });

  it('lee todas las páginas y valida que las filas coincidan con el total declarado', async () => {
    const rows = Array.from({ length: 5 }, (_, i) => ({ id: i + 1, reporte_id: `u-${i + 1}`, nombre: `R${i + 1}`, activo: 1 }));
    const seenPages: number[] = [];
    const paged: Handler = url => {
      const page = Number(url.searchParams.get('page')); seenPages.push(page);
      const slice = rows.slice((page - 1) * 2, page * 2);
      return json(200, { success: true, data: slice, pagination: { page, per_page: 2, total: 5, total_pages: 3, has_more: page * 2 < 5 } });
    };
    const { client } = make(serve({ '/api/reportes': paged }));
    expect((await client.listReports()).map(r => r.name)).toEqual(['R1', 'R2', 'R3', 'R4', 'R5']);
    expect(seenPages).toEqual([1, 2, 3]);
  });

  it('rechaza un listado incompleto, inestable o sin bloque de paginación (nunca resultados parciales)', async () => {
    const short: Handler = () => json(200, { success: true, data: [REPORTS[0]], pagination: { page: 1, per_page: 100, total: 5, has_more: false } });
    await expect(make(serve({ '/api/reportes': short })).client.listReports()).rejects.toMatchObject({ kind: 'invalid_response', message: expect.stringContaining('incompleto') as string });
    const unstable: Handler = url => { const page = Number(url.searchParams.get('page')); return json(200, { success: true, data: [REPORTS[page - 1]], pagination: { page, per_page: 1, total: page === 1 ? 2 : 3, has_more: page < 2 } }); };
    await expect(make(serve({ '/api/reportes': unstable })).client.listReports()).rejects.toMatchObject({ kind: 'invalid_response' });
    const legacy: Handler = () => json(200, { success: true, data: REPORTS });
    await expect(make(serve({ '/api/reportes': legacy })).client.listReports()).rejects.toMatchObject({ kind: 'invalid_response' });
    const endless: Handler = () => json(200, { success: true, data: [REPORTS[0]], pagination: { page: 1, per_page: 1, total: 99999, has_more: true } });
    await expect(make(serve({ '/api/empresas': endless })).client.listCompanies()).rejects.toMatchObject({ kind: 'invalid_response' });
  });

  it('un error parcial en cualquiera de las tres lecturas hace fallar todo el catálogo', async () => {
    for (const path of ['/api/reportes', '/api/asignacion', '/api/empresas']) {
      const failing: Handler = () => json(500, { success: false, message: 'Error interno del servidor' });
      await expect(make(serve({ [path]: failing })).client.listReports(), path).rejects.toMatchObject({ kind: 'server' });
    }
    const expired: Handler = () => json(401, { success: false, message: 'No autorizado' });
    await expect(make(serve({ '/api/asignacion': expired })).client.listReports()).rejects.toMatchObject({ kind: 'unauthorized' });
  });

  it('listCompanies descarta powerbi_* aunque el servidor los enviara', async () => {
    const leaky: Handler = url => json(200, { success: true, data: [{ id: 1, nombre: 'A', ruc: '1', ciudad: 'X', activo: 1, powerbi_tenant: 'T-NO-SALE', powerbi_client: 'C-NO-SALE', email: 'x@example.invalid' }], pagination: meta(Number(url.searchParams.get('page')), 1) });
    const companies = await make(serve({ '/api/empresas': leaky })).client.listCompanies();
    expect(JSON.stringify(companies)).not.toMatch(/NO-SALE|example\.invalid/);
  });

  it('getReport: view=minimal; 404 (ajeno o inexistente) se trata como acceso denegado neutral', async () => {
    const calls: string[] = [];
    const ok = make(url => { calls.push(url.pathname + url.search); return json(200, { success: true, data: { id: 4, reporte_id: 'uuid-4', nombre: 'Ventas regionales', descripcion: 'x', activo: 1 } }); });
    expect((await ok.client.getReport('uuid-4')).name).toBe('Ventas regionales');
    expect(calls[0]).toBe('/api/reportes/reporteid/uuid-4?view=minimal');
    const error = await make(() => json(404, { success: false, message: 'Reporte no encontrado' })).client.getReport('x').catch(e => e as ApiError);
    expect(error).toMatchObject({ kind: 'not_found', status: 404 });
    expect(isDenied(error)).toBe(true);
  });

  it('nunca llama a Power BI, a escrituras ni a rutas administrativas', async () => {
    const urls: string[] = [];
    const { client } = make((url, init) => { urls.push(`${init.method} ${url.pathname}`); return url.pathname === '/api/login' ? json(200, { success: true, token: 't', user: REAL_USER }) : url.pathname === '/api/user/profile' ? json(200, { success: true, profile: REAL_USER }) : url.pathname === '/api/logout' ? json(200, { success: true }) : serve()(url, init); });
    await client.login({ login: 'a', password: 'b' }); await client.me(); await client.listReports(); await client.listCompanies(); await client.logout();
    expect(urls.every(u => /^(GET|POST) \/api\/(login|user\/profile|reportes|asignacion|empresas|logout)$/.test(u))).toBe(true);
    expect(urls.filter(u => u.startsWith('POST'))).toEqual(['POST /api/login', 'POST /api/logout']);
  });

  it('exige una URL base', () => {
    expect(() => new HttpApiClient({ baseUrl: '', tokens: createMemoryTokenStore() })).toThrow();
  });
});

describe('composeReportCatalog (adaptador puro)', () => {
  const companies = [{ id: '1', name: 'Zeta', city: '', taxId: '', active: true }, { id: '2', name: 'Alfa', city: '', taxId: '', active: true }];
  it('ordena las empresas por nombre y no duplica informes', () => {
    const out = composeReportCatalog(
      [{ id: 1, reporte_id: 'u1', nombre: 'R' }, { id: 1, reporte_id: 'u1', nombre: 'R (duplicado)' }, { id: 2, nombre: 'Sin UUID' }],
      [{ empresa_id: 1, reporte_id: 1 }, { empresa_id: 2, reporte_id: 1 }], companies);
    expect(out).toHaveLength(1);
    expect(out[0]).toMatchObject({ name: 'R', companyIds: ['2', '1'], companyId: '2' });
  });
  it('ignora relaciones con informes no autorizados', () => {
    expect(composeReportCatalog([{ id: 1, reporte_id: 'u1', nombre: 'R' }], [{ empresa_id: 1, reporte_id: 77 }], companies)[0]?.companyIds).toEqual([]);
  });
});
