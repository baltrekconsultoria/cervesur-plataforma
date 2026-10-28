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
  const location = () => screen.getByTestId('location').textContent;
  const go = (to: string) => act(() => { void navigate(to); });
  const login = async (username: string, password = 'cualquiera') => {
    const field = screen.getByLabelText('Correo o usuario');
    await user.clear(field);
    await user.type(field, username);
    await user.type(screen.getByLabelText('Contraseña'), password);
    await user.click(screen.getByRole('button', { name: 'Ingresar al portal' }));
  };
  return { user, tokens, mock, location, go, login };
}

const heading = () => screen.getByRole('heading', { level: 1 }).textContent;
const cards = () => document.querySelectorAll('.report-card');
const countText = () => document.querySelector('.resultbar span')?.textContent;

describe('inicio de sesión simulado', () => {
  it('un usuario entra a Mis reportes y ve solo la navegación de usuario', async () => {
    const { login, location } = setup();
    await login('maria.rios');
    expect(await screen.findByRole('heading', { level: 1, name: 'Mis reportes' })).toBeInTheDocument();
    expect(location()).toBe('/reportes');
    const nav = screen.getByRole('navigation', { name: 'Secciones' });
    expect(within(nav).getAllByRole('link').map(a => a.textContent)).toEqual(['▦Mis reportes', '▤Mis empresas', '◉Mi perfil']);
    expect(screen.getByText('Usuario')).toBeInTheDocument();
    await waitFor(() => expect(cards()).toHaveLength(5));
  });

  it('un administrador entra al inicio administrativo con navegación propia', async () => {
    const { login, location } = setup();
    await login('elena.torres');
    expect(await screen.findByRole('heading', { level: 1, name: 'Inicio administrativo' })).toBeInTheDocument();
    expect(location()).toBe('/admin');
    const nav = screen.getByRole('navigation', { name: 'Secciones' });
    expect(within(nav).getByRole('link', { name: /Usuarios/ })).toBeInTheDocument();
    expect(within(nav).queryByRole('link', { name: /Mis reportes/ })).toBeNull();
    expect(screen.getByText('Modo administrador')).toBeInTheDocument();
  });

  it('credenciales incorrectas: aviso, contraseña limpia y sin sesión', async () => {
    const { login, tokens, user } = setup();
    await login('nadie', 'secreto-falso');
    expect(await screen.findByText('Usuario o contraseña incorrectos.')).toBeInTheDocument();
    expect(screen.getByLabelText('Contraseña')).toHaveValue('');
    expect(tokens.get()).toBeNull();
    expect(screen.getByLabelText('Correo o usuario')).toHaveValue('nadie');
    await user.selectOptions(screen.getByLabelText('Perfil de demostración'), 'elena.torres');
    expect(screen.getByLabelText('Correo o usuario')).toHaveValue('elena.torres');
  });

  it('sin sesión, cualquier ruta protegida lleva al login', async () => {
    const { location } = setup('/admin/usuarios');
    await waitFor(() => expect(location()).toBe('/login'));
    expect(screen.getByRole('heading', { name: 'Bienvenido' })).toBeInTheDocument();
  });

  it('cerrar sesión elimina el token y no recuerda ninguna ruta', async () => {
    const { login, user, tokens, location } = setup();
    await login('maria.rios');
    await screen.findByRole('heading', { level: 1, name: 'Mis reportes' });
    expect(tokens.get()).toBeTruthy();
    await user.click(screen.getByRole('button', { name: 'Cerrar sesión' }));
    await waitFor(() => expect(location()).toBe('/login'));
    expect(tokens.get()).toBeNull();
    expect(screen.queryByText(/sesión venció/)).toBeNull();
  });
});

describe('biblioteca: búsqueda y filtros', () => {
  it('busca por descripción, combina empresa y grupo, y limpia', async () => {
    const { login, user } = setup();
    await login('maria.rios');
    await screen.findByText('Mostrando', { exact: false });
    expect(countText()).toBe('Mostrando 5 de 5 informes');

    await user.type(screen.getByLabelText('Buscar informe'), 'rentabilidad');
    expect(cards()).toHaveLength(1);
    expect(screen.getByLabelText('Buscar informe')).toHaveFocus();
    expect(countText()).toBe('Mostrando 1 de 5 informes');
    await user.clear(screen.getByLabelText('Buscar informe'));

    await user.selectOptions(screen.getByLabelText('Empresa'), 'emp-1');
    expect(cards()).toHaveLength(2);
    const groups = within(screen.getByLabelText('Grupo')).getAllByRole('option').map(o => o.textContent);
    expect(groups).toEqual(['Todos los grupos', 'Comercial', 'Finanzas']);
    await user.selectOptions(screen.getByLabelText('Grupo'), 'Finanzas');
    expect(cards()).toHaveLength(1);
    expect(screen.getByRole('heading', { name: 'Margen por canal' })).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Limpiar filtros' }));
    expect(cards()).toHaveLength(5);
    expect(screen.getByRole('button', { name: 'Limpiar filtros' })).toBeDisabled();
  });

  it('estado sin resultados con salida para limpiar', async () => {
    const { login, user } = setup();
    await login('maria.rios');
    await user.type(await screen.findByLabelText('Buscar informe'), 'zzz');
    expect(screen.getByText('Sin resultados')).toBeInTheDocument();
    expect(countText()).toBe('Mostrando 0 de 5 informes');
    const clear = screen.getAllByRole('button', { name: 'Limpiar filtros' });
    await user.click(clear[clear.length - 1] as HTMLElement);
    expect(cards()).toHaveLength(5);
  });

  it('el escenario "sin informes" muestra el estado vacío', async () => {
    const { login, user } = setup();
    await login('maria.rios');
    await screen.findByLabelText('Buscar informe');
    await user.selectOptions(screen.getByLabelText('Escenario de prueba'), 'empty');
    expect(await screen.findByText('Aún no tiene informes disponibles')).toBeInTheDocument();
  });
});

describe('visor y retorno', () => {
  it('abre un informe y vuelve conservando filtros y foco', async () => {
    const { login, user, location } = setup();
    await login('maria.rios');
    await user.selectOptions(await screen.findByLabelText('Empresa'), 'emp-2');
    await user.type(screen.getByLabelText('Buscar informe'), 'entregas');
    await user.click(screen.getByRole('link', { name: 'Abrir informe Cumplimiento de entregas' }));
    expect(await screen.findByRole('heading', { level: 1, name: 'Cumplimiento de entregas' })).toBeInTheDocument();
    expect(location()).toBe('/reportes/rep-4');
    expect(screen.getByText('Visor Power BI simulado')).toBeInTheDocument();
    const back = screen.getByRole('link', { name: /Volver a Mis reportes \(filtros conservados\)/ });
    await user.click(back);
    expect(await screen.findByLabelText('Buscar informe')).toHaveValue('entregas');
    expect(screen.getByLabelText('Empresa')).toHaveValue('emp-2');
    expect(location()).toBe('/reportes?company=emp-2&q=entregas');
    await waitFor(() => expect(screen.getByRole('link', { name: 'Abrir informe Cumplimiento de entregas' })).toHaveFocus());
  });

  it('"Ver informes" de una empresa salta a la biblioteca filtrada', async () => {
    const { login, user, location } = setup();
    await login('maria.rios');
    await user.click(await screen.findByRole('link', { name: /Mis empresas/ }));
    expect(await screen.findAllByText(/informe(s)? disponible/)).toHaveLength(3);
    const links = screen.getAllByRole('link', { name: 'Ver informes' });
    await user.click(links[2] as HTMLElement);
    await waitFor(() => expect(location()).toBe('/reportes?company=emp-3'));
    await waitFor(() => expect(cards()).toHaveLength(1));
  });
});

describe('403 y 401', () => {
  it('informe no asignado: 403 compacto que no revela nada del recurso', async () => {
    const { login, go, location } = setup();
    await login('maria.rios');
    await screen.findByRole('heading', { level: 1, name: 'Mis reportes' });
    go('/reportes/rep-6');
    expect(await screen.findByRole('heading', { level: 1, name: 'Acceso denegado' })).toBeInTheDocument();
    expect(location()).toBe('/reportes/rep-6');
    const body = document.body.textContent ?? '';
    const view = document.getElementById('view')?.textContent ?? '';
    expect(body).not.toMatch(/Ocupación|Valle Azul/);
    expect(document.querySelectorAll('.denied')).toHaveLength(1);
    expect(document.querySelectorAll('.alert, .empty-state')).toHaveLength(0);
    expect(view.match(/403/g)).toHaveLength(1);
    expect(screen.getByText(/pida una asignación a un administrador/)).toBeInTheDocument();
    expect(screen.getByRole('link', { name: '← Volver a Mis reportes' })).toBeInTheDocument();
  });

  it('inexistente y 403 simulado reciben el mismo estado neutral', async () => {
    const { login, go, user, mock } = setup();
    await login('maria.rios');
    await screen.findByRole('heading', { level: 1, name: 'Mis reportes' });
    go('/reportes/no-existe');
    expect(await screen.findByRole('heading', { level: 1, name: 'Acceso denegado' })).toBeInTheDocument();
    go('/reportes');
    await user.selectOptions(await screen.findByLabelText('Escenario de prueba'), 'forbidden');
    expect(mock.getScenario()).toBe('forbidden');
    await user.click(await screen.findByRole('link', { name: 'Abrir informe Ventas consolidadas' }));
    expect(await screen.findByRole('heading', { level: 1, name: 'Acceso denegado' })).toBeInTheDocument();
    expect(document.body.textContent).not.toMatch(/Ventas consolidadas|Andes Log/);
  });

  it('un usuario en una ruta administrativa recibe un 403 de sección', async () => {
    const { login, go } = setup();
    await login('maria.rios');
    await screen.findByRole('heading', { level: 1, name: 'Mis reportes' });
    go('/admin/usuarios');
    expect(await screen.findByRole('heading', { level: 1, name: 'Acceso denegado' })).toBeInTheDocument();
    expect(screen.getByText(/solo para administradores/)).toBeInTheDocument();
    expect(document.querySelectorAll('.denied')).toHaveLength(1);
  });

  it('el administrador en rutas de usuario vuelve a su inicio', async () => {
    const { login, go, location } = setup();
    await login('elena.torres');
    await screen.findByRole('heading', { level: 1, name: 'Inicio administrativo' });
    go('/reportes');
    await waitFor(() => expect(location()).toBe('/admin'));
  });

  it('un 401 limpia el token, conserva el usuario, vacía la contraseña y retoma la sección', async () => {
    const { login, go, mock, tokens, location, user } = setup();
    await login('maria.rios', 'mi-clave-de-prueba');
    await screen.findByRole('heading', { level: 1, name: 'Mis reportes' });
    go('/empresas');
    await screen.findByRole('heading', { level: 1, name: 'Mis empresas' });
    mock.expireSessions();
    go('/reportes');
    await waitFor(() => expect(location()).toBe('/login'));
    expect(await screen.findByText(/Su sesión venció \(401\)/)).toBeInTheDocument();
    expect(tokens.get()).toBeNull();
    expect(screen.getByLabelText('Contraseña')).toHaveValue('');
    expect(screen.getByLabelText('Correo o usuario')).toHaveValue('maria.rios');
    expect(document.body.textContent).not.toMatch(/Mis reportes/);
    await user.type(screen.getByLabelText('Contraseña'), 'otra');
    await user.click(screen.getByRole('button', { name: 'Ingresar al portal' }));
    expect(await screen.findByRole('heading', { level: 1, name: 'Mis reportes' })).toBeInTheDocument();
  });

  it('un 401 dentro de un informe retoma el listado, no el informe', async () => {
    const { login, user, go, location } = setup();
    await login('maria.rios');
    await user.click(await screen.findByRole('link', { name: 'Abrir informe Ventas consolidadas' }));
    await screen.findByRole('heading', { level: 1, name: 'Ventas consolidadas' });
    await user.selectOptions(screen.getByLabelText('Escenario de prueba'), 'expired');
    await waitFor(() => expect(location()).toBe('/login'));
    go('/login');
    await user.type(screen.getByLabelText('Contraseña'), 'x');
    await user.click(screen.getByRole('button', { name: 'Ingresar al portal' }));
    expect(await screen.findByRole('heading', { level: 1, name: 'Mis reportes' })).toBeInTheDocument();
    expect(location()).toBe('/reportes');
  });

  it('otro usuario que reingresa tras un 401 no hereda la ruta anterior', async () => {
    const { login, user, go, mock, location } = setup();
    await login('maria.rios');
    await screen.findByRole('heading', { level: 1, name: 'Mis reportes' });
    go('/empresas');
    await screen.findByRole('heading', { level: 1, name: 'Mis empresas' });
    mock.expireSessions();
    go('/reportes');
    await waitFor(() => expect(location()).toBe('/login'));
    const field = screen.getByLabelText('Correo o usuario');
    await user.clear(field);
    await user.type(field, 'elena.torres');
    await user.type(screen.getByLabelText('Contraseña'), 'x');
    await user.click(screen.getByRole('button', { name: 'Ingresar al portal' }));
    await waitFor(() => expect(location()).toBe('/admin'));
  });
});

describe('administración inicial', () => {
  it('lista usuarios con filtros y sin exponer identificadores técnicos', async () => {
    const { login, user } = setup();
    await login('elena.torres');
    await user.click(await screen.findByRole('link', { name: /Usuarios/ }));
    expect(await screen.findByRole('heading', { level: 1, name: 'Usuarios' })).toBeInTheDocument();
    // El título aparece mientras carga: se espera a la tabla en lugar de depender del tiempo.
    await waitFor(() => expect(document.querySelectorAll('tbody tr')).toHaveLength(4));
    await user.selectOptions(screen.getByLabelText('Estado'), 'inactive');
    expect(document.querySelectorAll('tbody tr')).toHaveLength(1);
    expect(document.body.textContent).toContain('Jorge Peña');
    expect(document.body.textContent).not.toMatch(/usr-\d|emp-\d|rep-\d/);
  });

  it('el panel muestra pendientes calculados y enlaza a listas filtradas', async () => {
    const { login, user, location } = setup();
    await login('elena.torres');
    const review = await screen.findAllByRole('link', { name: /Revisar \(/ });
    expect(review.length).toBe(3);
    await user.click(review[0] as HTMLElement);
    await waitFor(() => expect(location()).toBe('/admin/reportes?status=unassigned'));
    expect(await screen.findByRole('heading', { level: 1, name: 'Reportes' })).toBeInTheDocument();
    expect(document.querySelectorAll('tbody tr')).toHaveLength(1);
  });

  it('la vista previa vuelve a Reportes conservando los filtros', async () => {
    const { login, user, go, location } = setup();
    await login('elena.torres');
    await screen.findByRole('heading', { level: 1, name: 'Inicio administrativo' });
    go('/admin/reportes?q=flujo');
    await user.click(await screen.findByRole('link', { name: 'Vista previa de Flujo de caja' }));
    expect(await screen.findByRole('heading', { level: 1, name: 'Flujo de caja' })).toBeInTheDocument();
    await user.click(screen.getByRole('link', { name: /Volver a Reportes \(filtros conservados\)/ }));
    await waitFor(() => expect(location()).toBe('/admin/reportes?q=flujo'));
    expect(await screen.findByLabelText('Buscar reporte')).toHaveValue('flujo');
    expect(heading()).toBe('Reportes');
  });

  it('403 simulado en la vista previa usa el mensaje de administrador', async () => {
    const { login, user, go } = setup();
    await login('elena.torres');
    await screen.findByRole('heading', { level: 1, name: 'Inicio administrativo' });
    await user.selectOptions(screen.getByLabelText('Escenario de prueba'), 'forbidden');
    go('/reportes/rep-5');
    expect(await screen.findByRole('heading', { level: 1, name: 'Acceso denegado' })).toBeInTheDocument();
    expect(screen.getByText(/Su cuenta no puede abrir la vista previa/)).toBeInTheDocument();
    expect(document.body.textContent).not.toMatch(/Flujo de caja|Agroexportadora/);
  });
});
