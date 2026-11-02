import { act, render, renderHook, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router';
import { describe, expect, it } from 'vitest';
import { MockApi } from '../api/mock/mockApi';
import { createMemoryTokenStore } from '../api/tokenStore';
import type { Assignment, Company, Report, User } from '../api/types';
import { usePaging } from '../lib/usePaging';
import { App } from './App';
import { AppProvider } from './AppProvider';

/* UX-08A.1: paginación local de 25 filas en Empresas, Reportes y Accesos, combinada con búsqueda y filtros de estado. */
const pad = (n: number) => String(n).padStart(2, '0');
const N = 60;
// Inactivas: múltiplos de 5 (12). Informes inactivos: múltiplos de 4 (15). Accesos vigentes: ni 4 ni 5 (36).
const COMPANIES: Company[] = Array.from({ length: N }, (_, i) => ({ id: `c-${i + 1}`, name: `Empresa ${pad(i + 1)}`, city: 'Lima', taxId: `2000000${pad(i + 1)}`, active: (i + 1) % 5 !== 0 }));
const REPORTS: Report[] = Array.from({ length: N }, (_, i) => ({ id: `r-${i + 1}`, name: `Informe ${pad(i + 1)}`, description: 'Prueba', companyId: `c-${i + 1}`, companyIds: [`c-${i + 1}`], group: 'G', active: (i + 1) % 4 !== 0 }));
const USERS: User[] = [{ id: 'u-1', username: 'ana', displayName: 'Ana Prueba', email: 'ana@ejemplo.test', role: 'user', active: true, phone: '', companyIds: COMPANIES.map(c => c.id) }];
const ASSIGNMENTS: Assignment[] = Array.from({ length: N }, (_, i) => ({ id: `a-${i + 1}`, userId: 'u-1', reportId: `r-${i + 1}`, companyId: `c-${i + 1}` }));

class BigMock extends MockApi {
  override async listCompanies(): Promise<Company[]> { return COMPANIES.map(c => ({ ...c })); }
  override async listReports(): Promise<Report[]> { return REPORTS.map(r => ({ ...r })); }
  override async listUsers(): Promise<User[]> { return USERS.map(u => ({ ...u })); }
  override async listAssignments(): Promise<Assignment[]> { return ASSIGNMENTS.map(a => ({ ...a })); }
}

async function open(path: string, heading: string) {
  const tokens = createMemoryTokenStore();
  const mock = new BigMock(tokens);
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
  // Navegación por el menú (no por URL) para conservar el estado del router en memoria.
  await user.click(within(await screen.findByRole('navigation', { name: 'Secciones' })).getByRole('link', { name: new RegExp(path) }));
  await screen.findByRole('heading', { level: 1, name: heading });
  const rows = () => document.querySelectorAll('tbody tr').length;
  const counter = () => document.querySelector('.resultbar [aria-live]')?.textContent ?? '';
  const pager = () => document.querySelector('nav[aria-label="Paginación"] [aria-live]')?.textContent ?? '';
  const next = () => user.click(screen.getByRole('button', { name: 'Siguiente' }));
  const pick = (label: string, value: string) => user.selectOptions(screen.getByLabelText(label), value);
  return { user, rows, counter, pager, next, pick };
}

describe('UX-08A.1 · paginación de 25 filas', () => {
  it('empresas: 48 activas en 2 páginas (25 + 23); Inactivas y Todas recalculan y vuelven a la página 1', async () => {
    const t = await open('Empresas', 'Empresas');
    await waitFor(() => expect(t.rows()).toBe(25));
    expect(t.counter()).toBe('Mostrando 48 de 60 empresas');
    expect(t.pager()).toBe('Página 1 de 2');
    await t.next();
    await waitFor(() => expect(t.rows()).toBe(23));
    expect(t.pager()).toBe('Página 2 de 2');
    // Cambiar el filtro desde la página 2 regresa a la 1; 12 inactivas caben en una sola página (sin paginador).
    await t.pick('Estado', 'inactive');
    await waitFor(() => expect(t.rows()).toBe(12));
    expect(t.pager()).toBe('');
    expect(t.counter()).toBe('Mostrando 12 de 60 empresas');
    await t.pick('Estado', 'all');
    await waitFor(() => expect(t.rows()).toBe(25));
    expect(t.pager()).toBe('Página 1 de 3');
    await t.next(); await t.next();
    await waitFor(() => expect(t.rows()).toBe(10));
    expect(document.body.textContent).toContain('Empresa 60');
  });

  it('empresas: buscar desde la página 2 vuelve a la 1; la búsqueda respeta el filtro activo y una lista vacía no deja paginador', async () => {
    const t = await open('Empresas', 'Empresas');
    await waitFor(() => expect(t.rows()).toBe(25));
    await t.next();
    await waitFor(() => expect(t.pager()).toBe('Página 2 de 2'));
    await t.user.type(screen.getByLabelText('Buscar empresa'), 'Empresa 31');
    await waitFor(() => expect(t.rows()).toBe(1));
    expect(t.pager()).toBe('');
    expect(t.counter()).toBe('Mostrando 1 de 60 empresas');
    // Empresa 30 está inactiva: con «Activas» no aparece; con «Todas» sí.
    await t.user.clear(screen.getByLabelText('Buscar empresa'));
    await t.user.type(screen.getByLabelText('Buscar empresa'), 'Empresa 30');
    await waitFor(() => expect(t.rows()).toBe(0));
    expect(screen.queryByRole('navigation', { name: 'Paginación' })).toBeNull();
    await t.pick('Estado', 'all');
    await waitFor(() => expect(t.rows()).toBe(1));
  });

  it('reportes: 45 activos (25 + 20) y 15 inactivos; Todos suma 60 en 3 páginas', async () => {
    const t = await open('Reportes', 'Reportes');
    await waitFor(() => expect(t.rows()).toBe(25));
    expect(t.counter()).toBe('Mostrando 45 de 60 reportes');
    expect(t.pager()).toBe('Página 1 de 2');
    await t.next();
    await waitFor(() => expect(t.rows()).toBe(20));
    await t.pick('Estado', 'inactive');
    await waitFor(() => expect(t.rows()).toBe(15));
    expect(t.pager()).toBe('');
    await t.pick('Estado', 'all');
    await waitFor(() => expect(t.rows()).toBe(25));
    expect(t.pager()).toBe('Página 1 de 3');
  });

  it('accesos: 36 vigentes (25 + 11), 24 sin efecto y 60 en total', async () => {
    const t = await open('Accesos', 'Accesos y asignaciones');
    await waitFor(() => expect(t.rows()).toBe(25));
    expect(t.counter()).toBe('Mostrando 36 de 60 asignaciones');
    expect(t.pager()).toBe('Página 1 de 2');
    await t.next();
    await waitFor(() => expect(t.rows()).toBe(11));
    await t.pick('Estado', 'off');
    await waitFor(() => expect(t.rows()).toBe(24));
    expect(t.pager()).toBe('');
    await t.pick('Estado', 'all');
    await waitFor(() => expect(t.rows()).toBe(25));
    expect(t.pager()).toBe('Página 1 de 3');
  });
});

describe('UX-08A.1 · usePaging', () => {
  const rows = (n: number) => Array.from({ length: n }, (_, i) => i);

  it('reinicia a la página 1 al cambiar la clave de filtros', () => {
    const { result, rerender } = renderHook(({ key }) => usePaging(key), { initialProps: { key: 'a' } });
    act(() => result.current.setPage(3));
    expect(result.current.view(rows(80)).page).toBe(3);
    rerender({ key: 'b' });
    expect(result.current.view(rows(80)).page).toBe(1);
  });

  it('ajusta la página si la lista se acorta y no pierde registros', () => {
    const { result } = renderHook(() => usePaging('k'));
    act(() => result.current.setPage(3));
    expect(result.current.view(rows(55)).rows).toHaveLength(5);
    const shorter = result.current.view(rows(30));
    expect(shorter.page).toBe(2);
    expect(shorter.rows).toEqual([25, 26, 27, 28, 29]);
    expect(result.current.view([]).page).toBe(1);
    expect(result.current.view(rows(25)).rows).toHaveLength(25);
    expect(result.current.view(rows(26)).rows).toEqual([25]);
  });
});
