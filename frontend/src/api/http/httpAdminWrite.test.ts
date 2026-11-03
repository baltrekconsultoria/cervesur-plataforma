import { afterEach, describe, expect, it, vi } from 'vitest';
import { canWrite, type ApiCapabilities } from '../types';
import { createMemoryTokenStore } from '../tokenStore';
import { toReportDetail, reportBody } from './adapters';
import { HttpApiClient } from './httpApi';

/* UX-08H: la compilación `admin-write` ofrece solo las escrituras que el servidor declara; el enlace de un informe es de solo escritura. */
const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });

function client(capsResponse: () => Response) {
  const tokens = createMemoryTokenStore();
  tokens.set('tok');
  const fetchImpl = ((url: string) => {
    const path = new URL(url).pathname;
    return Promise.resolve(path === '/api/admin/capacidades' ? capsResponse() : json(404, { success: false }));
  }) as unknown as typeof fetch;
  return new HttpApiClient({ baseUrl: 'http://127.0.0.1:9', tokens, fetchImpl });
}

afterEach(() => vi.unstubAllEnvs());

describe('UX-08H · capacidades de escritura del servidor', () => {
  const base: ApiCapabilities = { adminDirectory: true, reportCompany: true, reportGroups: false, profileEdit: false, adminWrites: true, liveViewer: true };

  it('sin detalle por grupo rige adminWrites para todo (compatibilidad con el mock y la fase 1)', () => {
    expect(canWrite(base, 'empresas')).toBe(true);
    expect(canWrite({ ...base, adminWrites: false }, 'usuarios')).toBe(false);
  });

  it('con detalle por grupo solo se ofrece lo que el servidor habilitó', () => {
    const caps = { ...base, writes: { empresas: true, reportes: false, accesos: true, usuarios: false } };
    expect([canWrite(caps, 'empresas'), canWrite(caps, 'reportes'), canWrite(caps, 'accesos'), canWrite(caps, 'usuarios')]).toEqual([true, false, true, false]);
  });

  it('en la compilación admin-write el cliente arranca en solo consulta y enciende lo que declara el servidor', async () => {
    vi.stubEnv('VITE_PILOT_MODE', 'admin-write');
    const api = client(() => json(200, { success: true, data: { writes: { empresas: true, reportes: true, accesos: false, usuarios: false }, audit: true, mode: 'admin_escritura' } }));
    expect(api.capabilities.adminWrites).toBe(false);
    await api.loadCapabilities();
    expect(api.capabilities.adminWrites).toBe(true);
    expect(api.capabilities.writes).toEqual({ empresas: true, reportes: true, accesos: false, usuarios: false });
  });

  it('un valor que no es exactamente true cuenta como apagado', async () => {
    vi.stubEnv('VITE_PILOT_MODE', 'admin-write');
    const api = client(() => json(200, { success: true, data: { writes: { empresas: 'true', reportes: 1, accesos: 'yes', usuarios: null } } }));
    await api.loadCapabilities();
    expect(api.capabilities.adminWrites).toBe(false);
  });

  it.each([
    ['403', () => json(403, { success: false, code: 'admin_escritura' })],
    ['respuesta sin writes', () => json(200, { success: true, data: {} })],
    ['error de red', () => { throw new Error('red caída'); }]
  ])('ante %s la interfaz queda en solo consulta', async (_label, response) => {
    vi.stubEnv('VITE_PILOT_MODE', 'admin-write');
    const api = client(response);
    api.capabilities.adminWrites = true;
    await api.loadCapabilities();
    expect(api.capabilities.adminWrites).toBe(false);
    expect(api.capabilities.writes).toBeUndefined();
  });

  it('fuera de la compilación admin-write no consulta nada (piloto de solo lectura intacto)', async () => {
    vi.stubEnv('VITE_PILOT_MODE', 'admin');
    let called = false;
    const api = client(() => { called = true; return json(200, {}); });
    expect(api.capabilities.adminWrites).toBe(false);
    await api.loadCapabilities();
    expect(called).toBe(false);
    expect(api.capabilities.adminWrites).toBe(false);
  });
});

describe('UX-08H · el enlace de un informe es de solo escritura', () => {
  const form = { id: 9, version: '0011223344556677', nombre: 'Informe', descripcion: 'd', enlace_configurado: true, reporte_id: 'r-1' };

  it('el formulario sin enlace queda marcado como «enlace configurado» y con el campo vacío', () => {
    const detail = toReportDetail(form);
    expect(detail.link).toBe('');
    expect(detail.linkConfigured).toBe(true);
  });

  it('el formulario de la fase 1 (con enlace) no cambia', () => {
    const detail = toReportDetail({ ...form, enlace: 'https://app.powerbi.com/view?r=abc', enlace_configurado: undefined });
    expect(detail.link).toBe('https://app.powerbi.com/view?r=abc');
    expect(detail.linkConfigured).toBeUndefined();
  });

  it('un enlace vacío no viaja: el servidor conserva el vigente; uno nuevo sí viaja', () => {
    expect('enlace' in reportBody({ name: 'N', description: 'd', link: '  ', version: 'v' })).toBe(false);
    expect(reportBody({ name: 'N', description: 'd', link: ' https://app.powerbi.com/view?r=abc ' }).enlace).toBe('https://app.powerbi.com/view?r=abc');
  });
});
