import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { MockApi } from '../api/mock/mockApi';
import { createMemoryTokenStore } from '../api/tokenStore';
import { AppProvider } from './AppProvider';
import { AppLayout } from './layout';
import { LoginPage } from '../features/login/LoginPage';
import { isPilotBuild, pilotMode, PILOT_BANNER, PILOT_BANNER_USUARIOS } from './pilot';

function renderIn(ui: React.ReactNode) {
  const tokens = createMemoryTokenStore();
  const mock = new MockApi(tokens);
  return render(<MemoryRouter><AppProvider services={{ tokens, client: mock, mock }}>{ui}</AppProvider></MemoryRouter>);
}

describe('Piloto visual (UX-07D.4)', () => {
  afterEach(() => { vi.unstubAllEnvs(); });

  it('sin VITE_PILOT no hay aviso ni cambia el texto del acceso', () => {
    expect(isPilotBuild()).toBe(false);
    renderIn(<LoginPage />);
    expect(screen.queryByText(/operador autorizado del piloto/)).toBeNull();
    expect(screen.getByText('Use una cuenta autorizada para consultar los informes asignados.')).toBeTruthy();
  });

  it('con VITE_PILOT=true el acceso aclara que es del operador del piloto y no de un empleado', () => {
    vi.stubEnv('VITE_PILOT', 'true');
    expect(isPilotBuild()).toBe(true);
    renderIn(<LoginPage />);
    expect(screen.getByText(/Acceso exclusivo del operador autorizado del piloto/)).toBeTruthy();
    expect(screen.getByText(/no use la contraseña de un empleado/)).toBeTruthy();
  });

  it('el aviso de contexto es visible en el diseño del piloto y declara datos reales, solo consulta y operador autorizado', () => {
    vi.stubEnv('VITE_PILOT', 'true');
    expect(PILOT_BANNER).toMatch(/Datos reales/);
    expect(PILOT_BANNER).toMatch(/Solo consulta/);
    expect(PILOT_BANNER).toMatch(/Operador autorizado/);
    renderIn(<AppLayout />);
    expect(document.querySelector('.pilot-banner')).not.toBeNull();
  });

  it('con VITE_PILOT_MODE=usuarios el acceso pide la cuenta habitual de Cervesur y el aviso declara usuarios autorizados', () => {
    vi.stubEnv('VITE_PILOT', 'true');
    vi.stubEnv('VITE_PILOT_MODE', 'usuarios');
    expect(pilotMode()).toBe('usuarios');
    expect(PILOT_BANNER_USUARIOS).toMatch(/Usuarios autorizados/);
    expect(PILOT_BANNER_USUARIOS).toMatch(/Solo consulta/);
    renderIn(<LoginPage />);
    expect(screen.getByText(/ingrese con su cuenta de Cervesur/)).toBeTruthy();
    expect(screen.queryByText(/operador autorizado del piloto/)).toBeNull();
    renderIn(<AppLayout />);
    expect(document.querySelector('.pilot-banner')?.textContent).toMatch(/Usuarios autorizados/);
  });

  it('sin VITE_PILOT_MODE el piloto sigue siendo el del operador', () => {
    vi.stubEnv('VITE_PILOT', 'true');
    expect(pilotMode()).toBe('operador');
  });
});
