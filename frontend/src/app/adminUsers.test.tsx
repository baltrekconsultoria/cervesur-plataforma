import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router';
import { describe, expect, it } from 'vitest';
import { ApiError } from '../api/errors';
import { MockApi } from '../api/mock/mockApi';
import { createMemoryTokenStore } from '../api/tokenStore';
import { App } from './App';
import { AppProvider } from './AppProvider';

/* UX-08B.3 con el proveedor simulado: botones de Usuarios (editar, estado, rol, accesos) y su dependencia del interruptor del servidor. */
class UsersMock extends MockApi {
  listUsersCalls = 0;
  deactivateError: ApiError | null = null;
  override async listUsers(...args: Parameters<MockApi['listUsers']>) { this.listUsersCalls++; return super.listUsers(...args); }
  override async deactivateUser(id: string): Promise<void> {
    if (this.deactivateError) { const error = this.deactivateError; this.deactivateError = null; throw error; }
    return super.deactivateUser(id);
  }
}

async function setup(enabled = true) {
  const tokens = createMemoryTokenStore();
  const mock = new UsersMock(tokens);
  mock.userAdminEnabled = enabled;
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
  await nav(/Usuarios/, 'Usuarios');
  const rows = () => document.querySelectorAll('tbody tr').length;
  const dialog = () => screen.findByRole('dialog');
  return { user, mock, nav, rows, dialog };
}

describe('UX-08B.3 · Usuarios', () => {
  it('sin el interruptor del servidor no hay botones de edición, estado ni rol (solo lectura de accesos)', async () => {
    const t = await setup(false);
    await waitFor(() => expect(t.rows()).toBe(3));
    expect(screen.queryByRole('button', { name: /^Editar / })).toBeNull();
    expect(screen.queryByRole('button', { name: /^Desactivar a / })).toBeNull();
    expect(screen.queryByRole('button', { name: /^Cambiar rol de / })).toBeNull();
    expect(screen.getByRole('button', { name: 'Ver accesos de María Ríos' })).toBeInTheDocument();
  });

  it('desactivar a otro usuario pide confirmación, lo saca de «Activos» y se puede reactivar; uno mismo no tiene el botón', async () => {
    const t = await setup();
    await waitFor(() => expect(screen.getByRole('button', { name: 'Desactivar a María Ríos' })).toBeInTheDocument());
    expect(screen.queryByRole('button', { name: 'Desactivar a Elena Torres' })).toBeNull();
    expect(screen.queryByRole('button', { name: 'Cambiar rol de Elena Torres' })).toBeNull();
    await t.user.click(screen.getByRole('button', { name: 'Desactivar a María Ríos' }));
    expect(within(await t.dialog()).getByText(/Se cerrarán sus sesiones/)).toBeInTheDocument();
    expect(within(await t.dialog()).getByText(/NO es una revocación inmediata de acceso en todos los sistemas/)).toBeInTheDocument(); // UX-08I: no se promete revocación en Flutter sin validarla
    await t.user.click(within(await t.dialog()).getByRole('button', { name: 'Desactivar usuario' }));
    await waitFor(() => expect(t.rows()).toBe(2));
    expect(screen.getByRole('status')).toHaveTextContent('Usuario «María Ríos» desactivado correctamente.');
    await t.user.selectOptions(screen.getByLabelText('Estado'), 'inactive');
    await waitFor(() => expect(t.rows()).toBe(2));
    await t.user.click(screen.getByRole('button', { name: 'Reactivar a María Ríos' }));
    await t.user.click(within(await t.dialog()).getByRole('button', { name: 'Reactivar usuario' }));
    await waitFor(() => expect(t.rows()).toBe(1));
  });

  it('un 409 del servidor (por ejemplo, último administrador) se muestra tal cual y, al cerrar, recarga la lista', async () => {
    const t = await setup();
    await waitFor(() => expect(screen.getByRole('button', { name: 'Desactivar a Carlos Vega' })).toBeInTheDocument());
    t.mock.deactivateError = new ApiError(409, 'No se puede desactivar al último administrador activo');
    const before = t.mock.listUsersCalls;
    await t.user.click(screen.getByRole('button', { name: 'Desactivar a Carlos Vega' }));
    await t.user.click(within(await t.dialog()).getByRole('button', { name: 'Desactivar usuario' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('último administrador activo');
    expect(t.mock.listUsersCalls).toBe(before);
    await t.user.click(within(await t.dialog()).getByRole('button', { name: 'Cancelar' }));
    await waitFor(() => expect(t.mock.listUsersCalls).toBeGreaterThan(before));
  });

  it('cambiar el rol pide confirmación y deja al usuario como administrador', async () => {
    const t = await setup();
    await waitFor(() => expect(screen.getByRole('button', { name: 'Cambiar rol de María Ríos' })).toBeInTheDocument());
    await t.user.click(screen.getByRole('button', { name: 'Cambiar rol de María Ríos' }));
    expect(within(await t.dialog()).getByText(/Convertir a/)).toBeInTheDocument();
    await t.user.click(within(await t.dialog()).getByRole('button', { name: 'Hacer administrador' }));
    await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent('ahora es administrador'));
    const row = screen.getAllByRole('row').find(r => r.textContent?.includes('María Ríos'));
    expect(row?.textContent).toContain('Administrador');
  });

  it('editar carga el detalle, valida, guarda y avisa; una edición con versión obsoleta da conflicto', async () => {
    const t = await setup();
    await waitFor(() => expect(screen.getByRole('button', { name: 'Editar María Ríos' })).toBeInTheDocument());
    await t.user.click(screen.getByRole('button', { name: 'Editar María Ríos' }));
    const dialog = await t.dialog();
    const email = await within(dialog).findByLabelText('Correo *');
    expect(within(dialog).getByLabelText('Nombres *')).toHaveValue('María');
    await t.user.clear(email);
    await t.user.type(email, 'no-es-correo');
    await t.user.click(within(dialog).getByRole('button', { name: 'Guardar cambios' }));
    expect(await within(dialog).findByText('Ingrese un correo válido.')).toBeVisible();
    await t.user.clear(email);
    await t.user.type(email, 'maria.nueva@ejemplo.test');
    // Otro administrador edita el mismo usuario mientras el formulario está abierto.
    await t.mock.updateUser('usr-1', { ...(await t.mock.getUserDetail('usr-1')), phone: '999 111 222' });
    await t.user.click(within(dialog).getByRole('button', { name: 'Guardar cambios' }));
    expect(await screen.findByRole('alert')).toHaveTextContent('cambió mientras lo editaba');
    await t.user.click(within(await t.dialog()).getByRole('button', { name: 'Cancelar' }));
    // Reabrir carga la versión nueva y ahora sí guarda.
    await t.user.click(await screen.findByRole('button', { name: 'Editar María Ríos' }));
    const again = await t.dialog();
    const email2 = await within(again).findByLabelText('Correo *');
    await t.user.clear(email2);
    await t.user.type(email2, 'maria.nueva@ejemplo.test');
    await t.user.click(within(again).getByRole('button', { name: 'Guardar cambios' }));
    await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent('Usuario «María Ríos» actualizado correctamente.'));
    await waitFor(() => expect(document.body.textContent).toContain('maria.nueva@ejemplo.test'));
  });

  it('«Accesos» lista empresas e informes con su efectividad y el motivo cuando no surten efecto', async () => {
    const t = await setup();
    await t.user.selectOptions(screen.getByLabelText('Estado'), 'inactive');
    await waitFor(() => expect(t.rows()).toBe(1));
    await t.user.click(screen.getByRole('button', { name: 'Ver accesos de Jorge Peña' }));
    const dialog = await t.dialog();
    await within(dialog).findByText(/Empresas \(/);
    expect(within(dialog).getAllByText(/Sin efecto · Usuario inactivo/).length).toBeGreaterThan(0);
    expect(dialog.textContent).not.toMatch(/https?:\/\/|powerbi/i);
  });

  it('el historial incluye «Usuarios» con la operación y sin datos personales', async () => {
    const t = await setup();
    await waitFor(() => expect(screen.getByRole('button', { name: 'Desactivar a María Ríos' })).toBeInTheDocument());
    await t.user.click(screen.getByRole('button', { name: 'Editar María Ríos' }));
    await t.user.click(await within(await t.dialog()).findByRole('button', { name: 'Guardar cambios' }));
    await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent('actualizado correctamente'));
    await t.user.click(screen.getByRole('button', { name: 'Desactivar a Carlos Vega' }));
    await t.user.click(within(await t.dialog()).getByRole('button', { name: 'Desactivar usuario' }));
    await waitFor(() => expect(screen.getByRole('status')).toHaveTextContent('desactivado'));
    await t.nav(/Historial/, 'Historial');
    await t.user.selectOptions(screen.getByLabelText('Ver'), 'user');
    await waitFor(() => expect(document.body.textContent).toContain('Usuario desactivado'));
    expect(document.body.textContent).toContain('Estado: activo → inactivo');
    expect(document.body.textContent).toContain('Usuario editado');
    expect(document.body.textContent).toContain('Modificó: nombres, apellidos, correo, teléfono');
    expect(document.body.textContent).not.toContain('maria.rios@ejemplo.test');
  });
});
