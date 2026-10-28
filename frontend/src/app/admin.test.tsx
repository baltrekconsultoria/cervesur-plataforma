import { act, render, screen, waitFor, within } from '@testing-library/react';
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

/* UX-07A con el proveedor simulado: mismas reglas que el servidor (alta, concesión, retirada). El flujo contra PHP real se prueba en e2e/run-qa.mjs. */
function setup(initial = '/login') {
  const tokens = createMemoryTokenStore();
  const mock = new MockApi(tokens);
  render(
    <MemoryRouter initialEntries={[initial]}>
      <AppProvider services={{ tokens, client: mock, mock }}>
        <Probe />
        <App />
      </AppProvider>
    </MemoryRouter>
  );
  const user = userEvent.setup();
  const go = (to: string) => act(() => { void navigate(to); });
  const login = async (username: string) => {
    const field = screen.getByLabelText('Correo o usuario');
    await user.clear(field);
    await user.type(field, username);
    await user.type(screen.getByLabelText('Contraseña'), 'cualquiera');
    await user.click(screen.getByRole('button', { name: 'Ingresar al portal' }));
  };
  const logout = async () => {
    await act(async () => { go('/perfil'); });
    await user.click((await screen.findAllByRole('button', { name: 'Cerrar sesión' }))[0] as HTMLElement);
    await screen.findByLabelText('Correo o usuario');
  };
  return { user, mock, go, login, logout };
}

const fill = async (user: ReturnType<typeof userEvent.setup>, label: string, value: string) => { await user.type(screen.getByLabelText(new RegExp(`^${label}`)), value); };
const rowOf = (text: string) => screen.queryAllByRole('row').find(r => r.textContent?.includes(text));

describe('UX-07A · alta de usuarios', () => {
  it('valida el formulario, informa el rechazo del servidor y refresca el listado al guardar', async () => {
    const { user, login } = setup();
    await login('elena.torres');
    await screen.findByRole('heading', { level: 1, name: 'Inicio administrativo' });
    await user.click(within(screen.getByRole('navigation', { name: 'Secciones' })).getByRole('link', { name: /Usuarios/ }));
    await user.click(await screen.findByRole('button', { name: 'Nuevo usuario' }));
    const dialog = await screen.findByRole('dialog', { name: 'Nuevo usuario' });

    await user.click(within(dialog).getByRole('button', { name: 'Crear usuario' }));
    expect(within(dialog).getByText('Ingrese los nombres.')).toBeVisible();
    expect(within(dialog).getByText('Ingrese un correo válido.')).toBeVisible();
    expect(within(dialog).getByText(/al menos 8 caracteres/)).toBeVisible();

    await fill(user, 'Nombres', 'Operador');
    await fill(user, 'Apellidos', 'Prueba');
    await fill(user, 'Usuario', 'operador.prueba');
    await fill(user, 'Correo', 'maria.rios@ejemplo.test'); // ya existe en los datos de demostración
    await fill(user, 'Contraseña inicial', 'synthetic-password');
    await user.click(within(dialog).getByRole('button', { name: 'Crear usuario' }));
    expect(await within(dialog).findByText('El email ya está registrado')).toBeVisible();
    expect(screen.getByRole('dialog', { name: 'Nuevo usuario' })).toBeInTheDocument(); // sigue abierto: no se dio por guardado

    const email = within(dialog).getByLabelText(/^Correo/);
    await user.clear(email);
    await user.type(email, 'operador.prueba@ejemplo.test');
    await user.click(within(dialog).getByRole('button', { name: 'Crear usuario' }));
    expect(await screen.findByText('Usuario «operador.prueba» creado correctamente.')).toBeInTheDocument();
    expect(screen.queryByRole('dialog')).toBeNull();
    await waitFor(() => expect(rowOf('Operador Prueba')).toBeTruthy());
    expect(rowOf('Operador Prueba')?.textContent).toContain('Usuario');
  }, 20000);

  it('el formulario no ofrece rol ni estado: los decide el servidor', async () => {
    const { user, login } = setup('/login');
    await login('elena.torres');
    await user.click(within(await screen.findByRole('navigation', { name: 'Secciones' })).getByRole('link', { name: /Usuarios/ }));
    await user.click(await screen.findByRole('button', { name: 'Nuevo usuario' }));
    const dialog = await screen.findByRole('dialog', { name: 'Nuevo usuario' });
    expect(within(dialog).queryByLabelText(/rol|estado|activo/i)).toBeNull();
    expect(within(dialog).getByText(/El usuario se crea activo/)).toBeInTheDocument();
  });
});

describe('UX-07A · accesos de extremo a extremo (simulado)', () => {
  it('crear usuario → asignar empresa e informe → el usuario ve solo ese informe → retirar → ya no lo ve', async () => {
    const { user, go, login, logout } = setup();
    await login('elena.torres');
    await screen.findByRole('heading', { level: 1, name: 'Inicio administrativo' });
    await act(async () => { go('/admin/usuarios'); });
    await user.click(await screen.findByRole('button', { name: 'Nuevo usuario' }));
    await fill(user, 'Nombres', 'Operador');
    await fill(user, 'Apellidos', 'Prueba');
    await fill(user, 'Usuario', 'operador.prueba');
    await fill(user, 'Correo', 'operador.prueba@ejemplo.test');
    await fill(user, 'Contraseña inicial', 'synthetic-password');
    await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Crear usuario' }));
    await screen.findByText(/creado correctamente/);

    // Asistente desde la fila del usuario
    await user.click(await screen.findByRole('link', { name: 'Asignar accesos a Operador Prueba' }));
    await screen.findByRole('heading', { level: 2, name: '1. Seleccione el usuario' });
    expect(screen.getByRole('radio', { name: /Operador Prueba/ })).toBeChecked();
    await user.click(screen.getByRole('button', { name: 'Continuar' }));
    await user.click(await screen.findByRole('radio', { name: /Textiles del Sur/ }));
    await user.click(screen.getByRole('button', { name: 'Continuar' }));
    expect(await screen.findByRole('checkbox', { name: /Inventario estratégico/ })).toBeInTheDocument();
    expect(screen.queryByRole('checkbox', { name: /Ventas consolidadas/ })).toBeNull(); // informe de otra empresa
    await user.click(screen.getByRole('checkbox', { name: /Inventario estratégico/ }));
    await user.click(screen.getByRole('button', { name: 'Continuar' }));
    expect(await screen.findByText('Inventario estratégico')).toBeInTheDocument();
    await user.click(screen.getByRole('button', { name: 'Guardar accesos' }));
    expect(await screen.findByRole('heading', { level: 2, name: 'Accesos guardados' })).toBeInTheDocument();
    expect(screen.getByLabelText('Resultado por informe').textContent).toContain('Concedido');

    // El usuario entra y solo ve el informe autorizado
    await logout();
    await login('operador.prueba');
    expect(await screen.findByRole('heading', { level: 1, name: 'Mis reportes' })).toBeInTheDocument();
    await waitFor(() => expect(document.querySelectorAll('.report-card')).toHaveLength(1));
    expect(document.querySelector('.report-card')?.textContent).toContain('Inventario estratégico');

    // El administrador retira el acceso con confirmación
    await logout();
    await login('elena.torres');
    await act(async () => { go('/admin/accesos?q=operador'); });
    await user.click(await screen.findByRole('button', { name: /Retirar acceso de Operador Prueba a Inventario estratégico/ }));
    const confirmation = await screen.findByRole('dialog', { name: 'Retirar acceso' });
    await user.click(within(confirmation).getByRole('button', { name: 'Cancelar' }));
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(rowOf('Inventario estratégico')).toBeTruthy(); // cancelar no retira nada
    await user.click(screen.getByRole('button', { name: /Retirar acceso de Operador Prueba a Inventario estratégico/ }));
    await user.click(within(await screen.findByRole('dialog', { name: 'Retirar acceso' })).getByRole('button', { name: 'Retirar acceso' }));
    expect(await screen.findByText(/retirado/)).toBeInTheDocument();
    await waitFor(() => expect(rowOf('Inventario estratégico') && rowOf('Operador Prueba')).toBeFalsy());

    await logout();
    await login('operador.prueba');
    expect(await screen.findByRole('heading', { level: 1, name: 'Mis reportes' })).toBeInTheDocument();
    await waitFor(() => expect(document.querySelectorAll('.report-card')).toHaveLength(0));
  }, 30000);

  it('los informes que el usuario ya tiene aparecen deshabilitados y no se duplican', async () => {
    const { user, login, go } = setup();
    await login('elena.torres');
    await screen.findByRole('heading', { level: 1, name: 'Inicio administrativo' });
    await act(async () => { go('/admin/accesos/nueva'); });
    await user.click(await screen.findByRole('radio', { name: /Carlos Vega/ }));
    await user.click(screen.getByRole('button', { name: 'Continuar' }));
    await user.click(await screen.findByRole('radio', { name: /Andes Logística/ }));
    await user.click(screen.getByRole('button', { name: 'Continuar' }));
    const assigned = await screen.findByRole('checkbox', { name: /Ventas consolidadas/ });
    expect(assigned).toBeDisabled();
    expect(assigned).toBeChecked();
    expect(screen.getByRole('checkbox', { name: /Margen por canal/ })).toBeEnabled();
  }, 20000);

  it('un administrador y un usuario inactivo no se ofrecen como destino de asignaciones', async () => {
    const { login, go } = setup();
    await login('elena.torres');
    await screen.findByRole('heading', { level: 1, name: 'Inicio administrativo' });
    await act(async () => { go('/admin/accesos/nueva'); });
    await screen.findByRole('heading', { level: 2, name: '1. Seleccione el usuario' });
    expect(screen.queryByRole('radio', { name: /Elena Torres/ })).toBeNull();
    expect(screen.queryByRole('radio', { name: /Jorge Peña/ })).toBeNull();
  });

  it('un usuario sin rol de administrador no accede al asistente', async () => {
    const { login, go } = setup();
    await login('maria.rios');
    await screen.findByRole('heading', { level: 1, name: 'Mis reportes' });
    await act(async () => { go('/admin/accesos/nueva'); });
    expect(await screen.findByRole('heading', { level: 1, name: 'Acceso denegado' })).toBeInTheDocument();
  });
});
