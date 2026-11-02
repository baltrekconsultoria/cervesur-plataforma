import { describe, expect, it } from 'vitest';
import { assignmentStatus } from '../../domain/access';
import { createMemoryTokenStore } from '../tokenStore';
import { HttpApiClient } from './httpApi';

/* UX-08A: el alcance `all` pide también los inactivos (status=all); el alcance por defecto conserva las peticiones de siempre. */
const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
const meta = (total: number) => ({ page: 1, per_page: 100, total, total_pages: 1, has_more: false });

function server() {
  const calls: URL[] = [];
  const fetchImpl = ((url: string) => {
    const u = new URL(url); calls.push(u);
    const all = u.searchParams.get('status') === 'all';
    const list = (rows: unknown[], wrap = false) => json(200, { success: true, data: wrap ? { success: true, data: rows } : rows, pagination: meta(rows.length) });
    if (u.pathname === '/api/empresas') return Promise.resolve(list(all ? [{ id: 1, nombre: 'Activa', activo: 1 }, { id: 2, nombre: 'Inactiva', activo: 0 }] : [{ id: 1, nombre: 'Activa', activo: 1 }]));
    if (u.pathname === '/api/reportes') return Promise.resolve(list(all ? [{ id: 11, nombre: 'A', activo: 1 }, { id: 12, nombre: 'B', activo: 0 }] : [{ id: 11, nombre: 'A', activo: 1 }]));
    if (u.pathname === '/api/asignacion') return Promise.resolve(list(all ? [{ id: 7, empresa_id: 1, reporte_id: 11, activo: 1 }, { id: 8, empresa_id: 1, reporte_id: 12, activo: 0 }] : [{ id: 7, empresa_id: 1, reporte_id: 11, activo: 1 }], true));
    if (u.pathname === '/api/empresa-reporte-usuario') {
      return Promise.resolve(list(all
        ? [{ id: 40, user_id: 5, empresa_id: 1, empresa_reporte_id: 7, activo: 1 }, { id: 41, user_id: 5, empresa_id: 1, empresa_reporte_id: 7, activo: 0 }, { id: 42, user_id: 5, empresa_id: 1, empresa_reporte_id: 8, activo: 1 }]
        : [{ id: 40, user_id: 5, empresa_id: 1, empresa_reporte_id: 7, activo: 1 }]));
    }
    return Promise.resolve(json(404, { success: false, message: 'no' }));
  }) as unknown as typeof fetch;
  const tokens = createMemoryTokenStore(); tokens.set('tok');
  return { calls, client: new HttpApiClient({ baseUrl: 'http://127.0.0.1:9', tokens, fetchImpl }) };
}

describe('UX-08A · alcance de los listados HTTP', () => {
  it('por defecto no envía status: las peticiones de trabajadores no cambian', async () => {
    const { client, calls } = server();
    await client.listCompanies(); await client.listReports(); await client.listAssignments();
    expect(calls.length).toBeGreaterThan(0);
    expect(calls.every(u => !u.searchParams.has('status'))).toBe(true);
  });

  it('con alcance all pide status=all a empresas, reportes, asignaciones y accesos', async () => {
    const { client, calls } = server();
    await client.listCompanies('all'); await client.listReports('all'); await client.listAssignments('all');
    const paths = new Set(calls.filter(u => u.searchParams.get('status') === 'all').map(u => u.pathname));
    expect(paths).toEqual(new Set(['/api/empresas', '/api/reportes', '/api/asignacion', '/api/empresa-reporte-usuario']));
    expect(calls.every(u => u.searchParams.get('status') === 'all')).toBe(true);
  });

  it('empresas e informes inactivos llegan con active:false', async () => {
    const { client } = server();
    expect((await client.listCompanies('all')).map(c => [c.name, c.active])).toEqual([['Activa', true], ['Inactiva', false]]);
    expect((await client.listReports('all')).map(r => [r.name, r.active])).toEqual([['A', true], ['B', false]]);
  });

  it('accesos: conserva los revocados (active:false) y los de relaciones inactivas; sin all solo los vigentes', async () => {
    const { client } = server();
    expect(await client.listAssignments()).toEqual([{ id: '40', userId: '5', reportId: '11', companyId: '1' }]);
    expect(await client.listAssignments('all')).toEqual([
      { id: '40', userId: '5', reportId: '11', companyId: '1' },
      { id: '41', userId: '5', reportId: '11', companyId: '1', active: false },
      { id: '42', userId: '5', reportId: '12', companyId: '1' }
    ]);
  });

  it('un acceso revocado, o cuyo usuario, empresa o informe está inactivo, no está vigente', async () => {
    const { client } = server();
    const [companies, reports, assignments] = await Promise.all([client.listCompanies('all'), client.listReports('all'), client.listAssignments('all')]);
    const users = [{ id: '5', username: 'u', displayName: 'U', email: '', role: 'user' as const, active: true, phone: '', companyIds: ['1'] }];
    const byId = (id: string) => assignmentStatus(assignments.find(a => a.id === id)!, users, reports, companies);
    expect(byId('40')).toEqual({ ok: true, reason: 'Vigente' });
    expect(byId('41')).toEqual({ ok: false, reason: 'Acceso revocado' });
    expect(byId('42')).toEqual({ ok: false, reason: 'Informe inactivo' });
    expect(assignmentStatus(assignments[0]!, [{ ...users[0]!, active: false }], reports, companies).reason).toBe('Usuario inactivo');
    expect(assignmentStatus(assignments[0]!, users, reports, companies.map(c => ({ ...c, active: false }))).reason).toBe('Empresa inactiva');
  });
});
