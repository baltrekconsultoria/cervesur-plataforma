import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, useLocation, useNavigate, type NavigateFunction } from 'react-router';
import { describe, expect, it } from 'vitest';
import { MockApi } from '../api/mock/mockApi';
import { createMemoryTokenStore } from '../api/tokenStore';
import { App } from './App';
import { AppProvider } from './AppProvider';

let navigate: NavigateFunction = () => undefined;
function Probe() {
  navigate = useNavigate();
  const location = useLocation();
  return <output data-testid="location">{location.pathname + location.search}</output>;
}

/* UX-08A con el proveedor simulado: filtros activos por defecto en las cuatro listas administrativas. */
function setup() {
  const tokens = createMemoryTokenStore();
  const mock = new MockApi(tokens);
  render(
    <MemoryRouter initialEntries={['/login']}>
      <AppProvider services={{ tokens, client: mock, mock }}>
        <Probe />
        <App />
      </AppProvider>
    </MemoryRouter>
  );
  const user = userEvent.setup();
  const go = (to: string) => act(() => { void navigate(to); });
  const rows = () => document.querySelectorAll('tbody tr').length;
  const counter = () => document.querySelector('.resultbar [aria-live]')?.textContent ?? '';
  const open = async (path: string, heading: string) => {
    const field = await screen.findByLabelText('Correo o usuario');
    await user.clear(field);
    await user.type(field, 'elena.torres');
    await user.type(screen.getByLabelText('Contraseña'), 'cualquiera');
    await user.click(screen.getByRole('button', { name: 'Ingresar al portal' }));
    await screen.findByRole('heading', { level: 1, name: 'Inicio administrativo' });
    await act(async () => { go(path); });
    await screen.findByRole('heading', { level: 1, name: heading });
  };
  const pick = (label: string, value: string) => user.selectOptions(screen.getByLabelText(label), value);
  return { user, open, rows, counter, pick, location: () => screen.getByTestId('location').textContent };
}

describe('UX-08A · filtros activos por defecto', () => {
  it('usuarios: «Activos» por defecto, contadores del conjunto filtrado y del total, y búsqueda sobre el filtro', async () => {
    const t = setup();
    await t.open('/admin/usuarios', 'Usuarios');
    await waitFor(() => expect(t.rows()).toBe(3));
    expect(t.counter()).toBe('Mostrando 3 de 4 usuarios');
    expect(screen.getByLabelText<HTMLSelectElement>('Estado').value).toBe('active');
    expect(t.location()).toBe('/admin/usuarios');
    // Un inactivo no aparece por búsqueda mientras el filtro es «Activos»…
    await t.user.type(screen.getByLabelText('Buscar usuario'), 'jorge');
    await waitFor(() => expect(t.rows()).toBe(0));
    // …y sí al elegir «Todos» o «Inactivos».
    await t.pick('Estado', 'all');
    await waitFor(() => expect(t.rows()).toBe(1));
    expect(t.counter()).toBe('Mostrando 1 de 4 usuarios');
    await t.pick('Estado', 'inactive');
    await waitFor(() => expect(t.rows()).toBe(1));
    expect(t.location()).toBe('/admin/usuarios?q=jorge&status=inactive');
  });

  it('empresas: «Activas» por defecto; «Inactivas» y «Todas» siguen disponibles', async () => {
    const t = setup();
    await t.open('/admin/empresas', 'Empresas');
    await waitFor(() => expect(t.rows()).toBe(4));
    expect(t.counter()).toBe('Mostrando 4 de 5 empresas');
    await t.pick('Estado', 'inactive');
    await waitFor(() => expect(t.rows()).toBe(1));
    expect(document.body.textContent).toContain('Servicios Pacífico');
    await t.pick('Estado', 'all');
    await waitFor(() => expect(t.rows()).toBe(5));
    expect(t.counter()).toBe('Mostrando 5 de 5 empresas');
  });

  it('reportes: «Activos» por defecto; el informe retirado aparece solo en «Inactivos» y «Todos»', async () => {
    const t = setup();
    await t.open('/admin/reportes', 'Reportes');
    await waitFor(() => expect(t.rows()).toBe(6));
    expect(t.counter()).toBe('Mostrando 6 de 7 reportes');
    expect(document.body.textContent).not.toContain('Presupuesto anual');
    await t.pick('Estado', 'inactive');
    await waitFor(() => expect(t.rows()).toBe(1));
    expect(document.body.textContent).toContain('Presupuesto anual');
    await t.pick('Estado', 'all');
    await waitFor(() => expect(t.rows()).toBe(7));
  });

  it('accesos: «Vigentes» por defecto; una asignación de un usuario inactivo queda en «Sin efecto»', async () => {
    const t = setup();
    await t.open('/admin/accesos', 'Accesos y asignaciones');
    await waitFor(() => expect(t.rows()).toBeGreaterThan(0));
    const vigentes = t.rows();
    expect(screen.getByLabelText<HTMLSelectElement>('Estado').value).toBe('ok');
    expect(t.counter()).toBe(`Mostrando ${vigentes} de 8 asignaciones`);
    expect(document.body.textContent).not.toContain('Jorge Peña');
    await t.pick('Estado', 'off');
    await waitFor(() => expect(document.body.textContent).toContain('Jorge Peña'));
    expect(document.body.textContent).toContain('Usuario inactivo');
    const sinEfecto = t.rows();
    await t.pick('Estado', 'all');
    await waitFor(() => expect(t.rows()).toBe(8));
    expect(vigentes + sinEfecto).toBe(8);
  });
});
