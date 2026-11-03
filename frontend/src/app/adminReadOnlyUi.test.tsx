import { act, render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, useNavigate, type NavigateFunction } from 'react-router';
import { describe, expect, it } from 'vitest';
import type { ApiCapabilities } from '../api/types';
import { MockApi } from '../api/mock/mockApi';
import { createMemoryTokenStore } from '../api/tokenStore';
import { App } from './App';
import { AppProvider } from './AppProvider';

let navigate: NavigateFunction = () => undefined;
function Probe() { navigate = useNavigate(); return null; }

/** Proveedor que declara lo mismo que `HttpApiClient` en la compilación administrativa de solo lectura (UX-07E.B / UX-08G): sin escrituras administrativas. */
class ReadOnlyAdminMock extends MockApi {
  override readonly capabilities: ApiCapabilities = { adminDirectory: true, reportCompany: true, reportGroups: true, profileEdit: false, adminWrites: false, liveViewer: false };
}

const WRITE_CONTROLS = /^(Nuevo usuario|Nueva empresa|Nuevo reporte|Nueva asignación|Ver historial|Editar|Desactivar|Reactivar|Dar de baja|Quitar acceso|Revocar|Asignar|Crear|Guardar)/i;

async function openPage(client: MockApi, path: string, heading: string) {
  const tokens = createMemoryTokenStore();
  const mock = new (client.constructor as typeof MockApi)(tokens);
  render(<MemoryRouter initialEntries={['/login']}><AppProvider services={{ tokens, client: mock, mock }}><Probe /><App /></AppProvider></MemoryRouter>);
  const user = userEvent.setup();
  const field = await screen.findByLabelText('Correo o usuario');
  await user.clear(field);
  await user.type(field, 'elena.torres');
  await user.type(screen.getByLabelText('Contraseña'), 'cualquiera');
  await user.click(screen.getByRole('button', { name: 'Ingresar al portal' }));
  await screen.findByRole('heading', { level: 1, name: 'Inicio administrativo' });
  await act(async () => { void navigate(path); });
  await screen.findByRole('heading', { level: 1, name: heading });
  await screen.findAllByRole('row');
}
const controls = () => [...document.querySelectorAll('button, a[href]')].map(e => (e.textContent ?? '').trim()).filter(t => WRITE_CONTROLS.test(t));

describe('UX-08G · fase 1 de solo lectura: ninguna operación de escritura se presenta como disponible', () => {
  for (const [path, heading] of [['/admin/usuarios', 'Usuarios'], ['/admin/empresas', 'Empresas'], ['/admin/reportes', 'Reportes'], ['/admin/accesos', 'Accesos y asignaciones']] as const) {
    it(`${path}: sin botones ni enlaces de crear, editar, desactivar, reactivar, asignar ni historial, y sin columna «Acciones»`, async () => {
      await openPage(new ReadOnlyAdminMock(createMemoryTokenStore()), path, heading);
      expect(controls()).toEqual([]);
      // En Reportes la columna «Acciones» existe siempre porque contiene la apertura del visor (lectura); sus controles de escritura ya los excluye `controls()`.
      if (path !== '/admin/reportes') expect(screen.queryByRole('columnheader', { name: 'Acciones' })).toBeNull();
      expect(screen.getAllByRole('row').length).toBeGreaterThan(1);
    });
  }

  it('control positivo: con escrituras habilitadas (QA) los mismos controles SÍ aparecen, de modo que la prueba detectaría una regresión', async () => {
    await openPage(new MockApi(createMemoryTokenStore()), '/admin/usuarios', 'Usuarios');
    expect(controls().length).toBeGreaterThan(0);
  });
});
