import { act, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, useLocation, useNavigate, type NavigateFunction } from 'react-router';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { HttpApiClient } from '../api/http/httpApi';
import { MockApi } from '../api/mock/mockApi';
import { createMemoryTokenStore } from '../api/tokenStore';
import { LoginPage } from '../features/login/LoginPage';
import { AppProvider } from './AppProvider';
import { AppLayout } from './layout';
import { isAdminPilot, pilotMode, PILOT_BANNER_ADMIN, PILOT_NOTE_ADMIN } from './pilot';

/** UX-07E.B · piloto ADMINISTRATIVO de solo lectura (`build:pilot-admin`). */
function renderIn(ui: React.ReactNode, route = '/') {
  const tokens = createMemoryTokenStore();
  const mock = new MockApi(tokens);
  return render(<MemoryRouter initialEntries={[route]}><AppProvider services={{ tokens, client: mock, mock }}>{ui}</AppProvider></MemoryRouter>);
}

describe('Piloto administrativo de solo lectura (UX-07E.B)', () => {
  afterEach(() => { vi.unstubAllEnvs(); });

  it('VITE_PILOT_MODE=admin se reconoce como piloto administrativo; sin él no', () => {
    expect(isAdminPilot()).toBe(false);
    vi.stubEnv('VITE_PILOT', 'true');
    vi.stubEnv('VITE_PILOT_MODE', 'admin');
    expect(pilotMode()).toBe('admin');
    expect(isAdminPilot()).toBe(true);
  });

  it('el proveedor HTTP declara que NO hay escrituras administrativas solo en el piloto administrativo', () => {
    const tokens = createMemoryTokenStore();
    expect(new HttpApiClient({ baseUrl: 'https://localhost', tokens }).capabilities.adminWrites).toBe(true);
    vi.stubEnv('VITE_PILOT', 'true');
    vi.stubEnv('VITE_PILOT_MODE', 'admin');
    const readOnly = new HttpApiClient({ baseUrl: 'https://localhost', tokens }).capabilities;
    expect(readOnly.adminWrites).toBe(false);
    expect(readOnly.adminDirectory).toBe(true);
  });

  it('el aviso declara datos reales, solo consulta y administradores autorizados, y la nota aclara que no se modifica nada', () => {
    vi.stubEnv('VITE_PILOT', 'true');
    vi.stubEnv('VITE_PILOT_MODE', 'admin');
    expect(PILOT_BANNER_ADMIN).toMatch(/ADMINISTRATIVO/);
    expect(PILOT_BANNER_ADMIN).toMatch(/Solo consulta/);
    expect(PILOT_BANNER_ADMIN).toMatch(/Administradores autorizados/);
    expect(PILOT_NOTE_ADMIN).toMatch(/no crea, edita, activa, desactiva ni asigna nada/);
    renderIn(<AppLayout />);
    expect(document.querySelector('.pilot-banner')?.textContent).toMatch(/PILOTO ADMINISTRATIVO/);
  });

  it('el acceso pide la cuenta de administrador y no menciona al operador ni al trabajador', () => {
    vi.stubEnv('VITE_PILOT', 'true');
    vi.stubEnv('VITE_PILOT_MODE', 'admin');
    renderIn(<LoginPage />);
    expect(screen.getByText(/cuenta de administrador de Cervesur/)).toBeTruthy();
    expect(screen.queryByText(/operador autorizado del piloto/)).toBeNull();
  });

  it('en la compilación administrativa NO existen las rutas de escritura (asistente de accesos y asociaciones empresa–informe) ni su enlace de menú', async () => {
    vi.stubEnv('VITE_PILOT', 'true');
    vi.stubEnv('VITE_PILOT_MODE', 'admin');
    // App.tsx fija las rutas al cargarse: este archivo no la importa antes, así que aquí se evalúa con el entorno del piloto administrativo.
    const { App } = await import('./App');
    const tokens = createMemoryTokenStore();
    const mock = new MockApi(tokens);
    let navigate: NavigateFunction = () => undefined;
    function Probe() { navigate = useNavigate(); const l = useLocation(); return <output data-testid="location">{l.pathname}</output>; }
    render(<MemoryRouter initialEntries={['/login']}><AppProvider services={{ tokens, client: mock, mock }}><Probe /><App /></AppProvider></MemoryRouter>);
    const user = userEvent.setup();
    const field = screen.getByLabelText('Correo o usuario');
    await user.clear(field);
    await user.type(field, 'elena.torres');
    await user.type(screen.getByLabelText('Contraseña'), 'cualquiera');
    await user.click(screen.getByRole('button', { name: 'Ingresar al portal' }));
    await waitFor(() => expect(screen.getByTestId('location').textContent).toBe('/admin'));
    // el menú administrativo conserva la consulta pero no las asociaciones (que incluyen acciones de escritura)
    const menu = screen.getAllByRole('link').map(b => b.textContent ?? '').join('|');
    expect(menu).toMatch(/Usuarios/);
    expect(menu).toMatch(/Accesos y asignaciones/);
    expect(menu).not.toMatch(/Informes por empresa/);
    for (const prohibida of ['/admin/accesos/nueva', '/admin/asociaciones']) {
      await act(async () => { void navigate(prohibida); });
      await waitFor(() => expect(screen.getByTestId('location').textContent).toBe('/admin'));
      expect(screen.queryByRole('heading', { name: /Nueva asignación|Informes por empresa|Asociar/i })).toBeNull();
    }
    await act(async () => { void navigate('/admin/accesos'); });
    await waitFor(() => expect(screen.getByTestId('location').textContent).toBe('/admin/accesos'));
  });
});
