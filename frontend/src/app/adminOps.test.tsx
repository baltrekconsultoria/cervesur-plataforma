import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router';
import { describe, expect, it } from 'vitest';
import { ApiError } from '../api/errors';
import { MockApi } from '../api/mock/mockApi';
import { createMemoryTokenStore } from '../api/tokenStore';
import { App } from './App';
import { AppProvider } from './AppProvider';

/* UX-08B.2 con el proveedor simulado: desactivar/reactivar empresas e informes, retirar/reactivar accesos, conflictos 409 e historial. */
class FlakyMock extends MockApi {
  listCompaniesCalls = 0;
  failReactivateOnce = false;
  override async listCompanies(...args: Parameters<MockApi['listCompanies']>) { this.listCompaniesCalls++; return super.listCompanies(...args); }
  override async reactivateCompany(id: string): Promise<void> {
    if (this.failReactivateOnce) { this.failReactivateOnce = false; throw new ApiError(409, 'La empresa ya está activa'); }
    return super.reactivateCompany(id);
  }
}

async function setup(prepare?: (mock: FlakyMock) => Promise<void>) {
  const tokens = createMemoryTokenStore();
  const mock = new FlakyMock(tokens);
  if (prepare) { tokens.set((await mock.login({ login: 'elena.torres', password: 'x' })).token); await prepare(mock); tokens.set(null); }
  render(
    <MemoryRouter initialEntries={['/login']}>
      <AppProvider services={{ tokens, client: mock, mock }}>
        <App />
      </AppProvider>
    </MemoryRouter>
  );
  const user = userEvent.setup();
  const field = await screen.findByLabelText('Correo o usuario');
  await user.clear(field);
  await user.type(field, 'elena.torres');
  await user.type(screen.getByLabelText('Contraseña'), 'cualquiera');
  await user.click(screen.getByRole('button', { name: 'Ingresar al portal' }));
  await screen.findByRole('heading', { level: 1, name: 'Inicio administrativo' });
  const nav = async (label: RegExp, heading: string) => {
    await user.click(within(await screen.findByRole('navigation', { name: 'Secciones' })).getByRole('link', { name: label }));
    await screen.findByRole('heading', { level: 1, name: heading });
  };
  const rows = () => document.querySelectorAll('tbody tr').length;
  const counter = () => document.querySelector('.resultbar [aria-live]')?.textContent ?? '';
  const dialog = () => screen.findByRole('dialog');
  return { user, mock, nav, rows, counter, dialog };
}

describe('UX-08B.2 · empresas', () => {
  it('desactivar pide confirmación, actualiza listas y contadores, y la empresa se puede reactivar', async () => {
    const t = await setup();
    await t.nav(/Empresas/, 'Empresas');
    await waitFor(() => expect(t.rows()).toBe(4));
    await t.user.click(screen.getByRole('button', { name: 'Desactivar Andes Logística S.A.C.' }));
    const confirm = await t.dialog();
    expect(within(confirm).getByText(/sus accesos quedarán sin efecto/i)).toBeInTheDocument();
    // Cancelar no cambia nada.
    await t.user.click(within(confirm).getByRole('button', { name: 'Cancelar' }));
    expect(t.rows()).toBe(4);
    await t.user.click(screen.getByRole('button', { name: 'Desactivar Andes Logística S.A.C.' }));
    await t.user.click(within(await t.dialog()).getByRole('button', { name: 'Desactivar empresa' }));
    await waitFor(() => expect(t.rows()).toBe(3));
    expect(t.counter()).toBe('Mostrando 3 de 5 empresas');
    expect(screen.getByRole('status')).toHaveTextContent('Empresa «Andes Logística S.A.C.» desactivada correctamente.');
    // Sigue disponible en «Inactivas», solo con «Reactivar» (no se edita una empresa inactiva).
    await t.user.selectOptions(screen.getByLabelText('Estado'), 'inactive');
    await waitFor(() => expect(t.rows()).toBe(2));
    expect(screen.queryByRole('button', { name: 'Editar Andes Logística S.A.C.' })).toBeNull();
    await t.user.click(screen.getByRole('button', { name: 'Reactivar Andes Logística S.A.C.' }));
    await t.user.click(within(await t.dialog()).getByRole('button', { name: 'Reactivar empresa' }));
    await waitFor(() => expect(t.rows()).toBe(1));
    await t.user.selectOptions(screen.getByLabelText('Estado'), 'active');
    await waitFor(() => expect(t.rows()).toBe(4));
  });

  it('un conflicto 409 muestra el mensaje del servidor y, al cerrar, recarga los datos', async () => {
    const t = await setup();
    await t.nav(/Empresas/, 'Empresas');
    await t.user.selectOptions(screen.getByLabelText('Estado'), 'inactive');
    await waitFor(() => expect(t.rows()).toBe(1));
    t.mock.failReactivateOnce = true;
    const callsBefore = t.mock.listCompaniesCalls;
    await t.user.click(screen.getByRole('button', { name: 'Reactivar Servicios Pacífico S.A.C.' }));
    await t.user.click(within(await t.dialog()).getByRole('button', { name: 'Reactivar empresa' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('La empresa ya está activa');
    expect(t.mock.listCompaniesCalls).toBe(callsBefore);
    await t.user.click(within(await t.dialog()).getByRole('button', { name: 'Cancelar' }));
    await waitFor(() => expect(t.mock.listCompaniesCalls).toBeGreaterThan(callsBefore));
  });
});

describe('UX-08B.2 · reportes', () => {
  it('desactivar y reactivar un informe; el inactivo no ofrece Ver ni Editar', async () => {
    const t = await setup();
    await t.nav(/Reportes/, 'Reportes');
    await waitFor(() => expect(t.rows()).toBe(6));
    await t.user.click(screen.getByRole('button', { name: 'Desactivar Flujo de caja' }));
    await t.user.click(within(await t.dialog()).getByRole('button', { name: 'Desactivar reporte' }));
    await waitFor(() => expect(t.rows()).toBe(5));
    expect(t.counter()).toBe('Mostrando 5 de 7 reportes');
    await t.user.selectOptions(screen.getByLabelText('Estado'), 'inactive');
    await waitFor(() => expect(t.rows()).toBe(2));
    expect(screen.queryByRole('link', { name: 'Vista previa de Flujo de caja' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Editar Flujo de caja' })).toBeNull();
    await t.user.click(screen.getByRole('button', { name: 'Reactivar Flujo de caja' }));
    await t.user.click(within(await t.dialog()).getByRole('button', { name: 'Reactivar reporte' }));
    await waitFor(() => expect(t.rows()).toBe(1));
  });
});

describe('UX-08B.2 · accesos e historial', () => {
  const grantFresh = async (mock: FlakyMock) => {
    const [users, reports, assignments] = await Promise.all([mock.listUsers(), mock.listReports(), mock.listAssignments()]);
    const pairs = users.filter(u => u.active && u.role === 'user').flatMap(user => reports
      .filter(r => r.active && r.companyIds.some(c => user.companyIds.includes(c)) && !assignments.some(a => a.userId === user.id && a.reportId === r.id))
      .map(report => ({ user, report })));
    const { user, report } = pairs[0]!;
    const companyId = report.companyIds.find(c => user.companyIds.includes(c))!;
    const [result] = await mock.grantAccess({ userId: user.id, companyId, reportIds: [report.id] });
    expect(result?.status).toBe('granted');
    return { user, report };
  };

  it('retirar un acceso lo deja «Sin efecto · Acceso revocado» y se puede reactivar', async () => {
    let target = { name: '', report: '' };
    const t = await setup(async mock => { const { user, report } = await grantFresh(mock); target = { name: user.displayName, report: report.name }; });
    await t.nav(/Accesos/, 'Accesos y asignaciones');
    await waitFor(() => expect(t.rows()).toBeGreaterThan(0));
    const vigentes = t.rows();
    await t.user.click(screen.getByRole('button', { name: `Retirar acceso de ${target.name} a ${target.report}` }));
    await t.user.click(within(await t.dialog()).getByRole('button', { name: 'Retirar acceso' }));
    await waitFor(() => expect(t.rows()).toBe(vigentes - 1));
    await t.user.selectOptions(screen.getByLabelText('Estado'), 'off');
    await waitFor(() => expect(document.body.textContent).toContain('Acceso revocado'));
    expect(screen.queryByRole('button', { name: `Retirar acceso de ${target.name} a ${target.report}` })).toBeNull();
    await t.user.click(screen.getByRole('button', { name: `Reactivar acceso de ${target.name} a ${target.report}` }));
    await t.user.click(within(await t.dialog()).getByRole('button', { name: 'Reactivar acceso' }));
    await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent('reactivado'));
    await t.user.selectOptions(screen.getByLabelText('Estado'), 'ok');
    await waitFor(() => expect(t.rows()).toBe(vigentes));
  });

  it('el historial muestra las operaciones recientes, más nuevas primero, filtrables por categoría', async () => {
    const t = await setup();
    await t.nav(/Empresas/, 'Empresas');
    await t.user.click(screen.getByRole('button', { name: 'Desactivar Andes Logística S.A.C.' }));
    await t.user.click(within(await t.dialog()).getByRole('button', { name: 'Desactivar empresa' }));
    await waitFor(() => expect(t.rows()).toBe(3));
    await t.nav(/Historial/, 'Historial');
    // La categoría inicial es «Accesos»: todavía no hay movimientos.
    expect(await screen.findByText('Sin operaciones registradas')).toBeInTheDocument();
    await t.user.selectOptions(screen.getByLabelText('Ver'), 'empresa');
    await waitFor(() => expect(document.body.textContent).toContain('Empresa desactivada'));
    expect(document.body.textContent).toContain('Estado: activo → inactivo');
    expect(document.body.textContent).toContain('Elena Torres');
    expect(t.rows()).toBe(1);
  });
});
