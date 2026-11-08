import { act, fireEvent, render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, useLocation, useNavigate, type NavigateFunction } from 'react-router';
import { describe, expect, it } from 'vitest';
import { MockApi } from '../api/mock/mockApi';
import { createMemoryTokenStore } from '../api/tokenStore';
import type { Company, Report } from '../api/types';
import { OtherReportsDialog } from '../components/OtherReportsDialog';
import { App } from './App';
import { AppProvider } from './AppProvider';

/* UX-07E.C · «Otros informes»: ventana con buscador en lugar de decenas de etiquetas encima del visor. */
let navigate: NavigateFunction = () => undefined;
function Probe() { navigate = useNavigate(); const l = useLocation(); return <output data-testid="location">{l.pathname}</output>; }

function setup(initial = '/login') {
  const tokens = createMemoryTokenStore();
  const mock = new MockApi(tokens);
  render(<MemoryRouter initialEntries={[initial]}><AppProvider services={{ tokens, client: mock, mock }}><Probe /><App /></AppProvider></MemoryRouter>);
  const user = userEvent.setup();
  const login = async (username: string) => {
    const field = screen.getByLabelText('Correo o usuario');
    await user.clear(field); await user.type(field, username);
    await user.type(screen.getByLabelText('Contraseña'), 'cualquiera');
    await user.click(screen.getByRole('button', { name: 'Ingresar al portal' }));
  };
  return { user, login };
}

describe('UX-07E.C · detalle del informe con «Otros informes»', () => {
  it('el administrador ve UN botón compacto (no una fila de etiquetas) y el visor conserva volver y migas', async () => {
    const { login } = setup();
    await login('elena.torres');
    await waitFor(() => expect(screen.getByTestId('location').textContent).toBe('/admin'));
    await act(async () => { void navigate('/reportes/rep-1'); });
    const button = await screen.findByRole('button', { name: /Otros informes \(\d+\)/ });
    expect(button.getAttribute('aria-haspopup')).toBe('dialog');
    expect(document.querySelector('.sibling-nav')).toBeNull(); // sin la lista extensa de etiquetas encima del visor
    expect(screen.getAllByText(/Volver a/).length).toBeGreaterThan(0);
    expect(document.querySelector('.breadcrumb')).not.toBeNull();
    expect(screen.queryByRole('dialog')).toBeNull(); // la lista no se carga hasta que se pide
  });

  it('abre la ventana, agrupa por empresa, busca por nombre o código, cierra con Escape y devuelve el foco al botón', async () => {
    const { login, user } = setup();
    await login('elena.torres');
    await waitFor(() => expect(screen.getByTestId('location').textContent).toBe('/admin'));
    await act(async () => { void navigate('/reportes/rep-1'); });
    const button = await screen.findByRole('button', { name: /Otros informes/ });
    button.focus();
    await user.click(button);
    const dialog = await screen.findByRole('dialog', { name: 'Otros informes' });
    const groups = within(dialog).getAllByRole('heading', { level: 3 }).map(h => h.textContent);
    expect(groups.length).toBeGreaterThan(1);
    const links = () => within(dialog).getAllByRole('link');
    const total = links().length;
    expect(total).toBeGreaterThan(3);
    await waitFor(() => expect(document.activeElement).toBe(within(dialog).getByLabelText('Buscar por nombre o código')));
    await user.type(within(dialog).getByLabelText('Buscar por nombre o código'), 'flujo');
    expect(links().length).toBeLessThan(total);
    expect(within(dialog).getByRole('status').textContent).toMatch(/de \d+ informes/);
    await user.clear(within(dialog).getByLabelText('Buscar por nombre o código'));
    await user.type(within(dialog).getByLabelText('Buscar por nombre o código'), 'zzz-no-existe');
    expect(within(dialog).getByText(/Ningún informe coincide/)).toBeTruthy();
    // Escape: el navegador lo entrega al <dialog> como el evento «cancel» (jsdom no lo sintetiza desde el teclado); la verificación con el navegador real está en e2e/run-layout.mjs
    fireEvent(dialog, new Event('cancel', { cancelable: true }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    expect(document.activeElement).toBe(button);
  });

  it('elegir un informe navega a su detalle y cierra la ventana; el botón «Cerrar» también la cierra', async () => {
    const { login, user } = setup();
    await login('elena.torres');
    await waitFor(() => expect(screen.getByTestId('location').textContent).toBe('/admin'));
    await act(async () => { void navigate('/reportes/rep-1'); });
    await user.click(await screen.findByRole('button', { name: /Otros informes/ }));
    let dialog = await screen.findByRole('dialog', { name: 'Otros informes' });
    await user.click(within(dialog).getByRole('button', { name: 'Cerrar' }));
    await waitFor(() => expect(screen.queryByRole('dialog')).toBeNull());
    await user.click(screen.getByRole('button', { name: /Otros informes/ }));
    dialog = await screen.findByRole('dialog', { name: 'Otros informes' });
    const first = within(dialog).getAllByRole('link')[0] as HTMLAnchorElement;
    const target = new URL(first.href, 'http://x').pathname;
    await user.click(first);
    await waitFor(() => expect(screen.getByTestId('location').textContent).toBe(target));
    expect(screen.queryByRole('dialog')).toBeNull();
  });

  it('un trabajador solo ve en la ventana los otros informes de sus empresas', async () => {
    const { login, user } = setup();
    await login('maria.rios');
    await waitFor(() => expect(screen.getByTestId('location').textContent).toBe('/reportes'));
    await act(async () => { void navigate('/reportes/rep-1'); });
    await user.click(await screen.findByRole('button', { name: /Otros informes/ }));
    const dialog = await screen.findByRole('dialog', { name: 'Otros informes' });
    const names = within(dialog).getAllByRole('link').map(a => a.textContent ?? '');
    expect(names.join('|')).not.toMatch(/Ocupación de activos/); // rep-6 es de una empresa que maría no tiene
    expect(names.length).toBeGreaterThan(0);
  });
});

describe('OtherReportsDialog (componente reutilizable)', () => {
  const companies: Company[] = [{ id: 'c1', name: 'Empresa Uno', city: '', taxId: '', active: true }, { id: 'c2', name: 'Empresa Dos', city: '', taxId: '', active: true }];
  const mk = (id: string, name: string, ref: string, companyIds: string[], extra: Partial<Report> = {}): Report => ({ id, name, ref, description: '', companyId: companyIds[0] ?? '', companyIds, group: '', active: true, ...extra });
  const reports: Report[] = [
    mk('1', 'DASHBOARD_PRESUPUESTAL_CERVESUR_031_CON_UN_NOMBRE_MUY_LARGO_SIN_ESPACIOS_PARA_PROBAR_EL_AJUSTE', 'REF-AAA', ['c1'], { linkAuthorized: false }),
    mk('2', 'Margen por canal', 'REF-BBB', ['c1', 'c2'], { linkAuthorized: true }),
    mk('3', 'Informe huérfano', 'REF-CCC', [], { active: false })
  ];

  it('muestra nombre completo y código, marca «Sin enlace autorizado» solo cuando el servidor lo indica y no incluye enlaces', () => {
    render(<MemoryRouter><AppProvider services={{ tokens: createMemoryTokenStore(), client: new MockApi(createMemoryTokenStore()), mock: new MockApi(createMemoryTokenStore()) }}><OtherReportsDialog reports={reports} companies={companies} from="/admin/reportes" onClose={() => undefined} /></AppProvider></MemoryRouter>);
    const dialog = screen.getByRole('dialog', { name: 'Otros informes' });
    expect(within(dialog).getByText(/DASHBOARD_PRESUPUESTAL_CERVESUR_031_CON_UN_NOMBRE_MUY_LARGO/)).toBeTruthy();
    expect(within(dialog).getByText('REF-AAA')).toBeTruthy();
    expect(within(dialog).getAllByText('Sin enlace autorizado')).toHaveLength(1); // solo el informe 1; el 2 está autorizado y el 3 no informa
    expect(within(dialog).getByText('Inactivo')).toBeTruthy();
    expect(within(dialog).getByRole('heading', { name: 'Sin empresa asociada' })).toBeTruthy();
    expect(within(dialog).getAllByText('Margen por canal')).toHaveLength(2); // aparece bajo cada una de sus dos empresas
    expect(dialog.innerHTML).not.toMatch(/https?:|powerbi/i);
  });

  it('el clic en el fondo cierra la ventana (y un clic dentro no)', async () => {
    let closed = 0;
    render(<MemoryRouter><AppProvider services={{ tokens: createMemoryTokenStore(), client: new MockApi(createMemoryTokenStore()), mock: new MockApi(createMemoryTokenStore()) }}><OtherReportsDialog reports={reports} companies={companies} from="/" onClose={() => { closed += 1; }} /></AppProvider></MemoryRouter>);
    const dialog = screen.getByRole('dialog', { name: 'Otros informes' });
    await userEvent.click(within(dialog).getByLabelText('Buscar por nombre o código'));
    expect(closed).toBe(0);
    await userEvent.click(dialog); // el fondo (::backdrop) entrega el clic al propio <dialog>
    expect(closed).toBe(1);
  });
});
