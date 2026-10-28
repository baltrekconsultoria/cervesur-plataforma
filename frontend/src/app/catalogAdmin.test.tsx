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

/* UX-07B con el proveedor simulado (mismas reglas que el servidor). El flujo contra PHP real se prueba en e2e/run-qa.mjs. */
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
    const field = await screen.findByLabelText('Correo o usuario');
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
  return { user, go, login, logout };
}

type U = ReturnType<typeof userEvent.setup>;
const fill = async (user: U, label: RegExp, value: string) => { const input = screen.getByLabelText(label); await user.clear(input); if (value) await user.type(input, value); };
const rowOf = (text: string) => screen.queryAllByRole('row').find(r => r.textContent?.includes(text));
const submit = async (user: U, name: string) => user.click(within(await screen.findByRole('dialog')).getByRole('button', { name }));
const LINK = 'https://app.powerbi.com/view?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDE';

describe('UX-07B · empresas', () => {
  it('valida, informa el rechazo de PHP (RUC duplicado) sin dar éxito falso, crea y edita', async () => {
    const { user, go, login } = setup();
    await login('elena.torres');
    await screen.findByRole('heading', { level: 1, name: 'Inicio administrativo' });
    await act(async () => { go('/admin/empresas'); });
    await user.click(await screen.findByRole('button', { name: 'Nueva empresa' }));
    const dialog = await screen.findByRole('dialog', { name: 'Nueva empresa' });
    expect(within(dialog).queryByLabelText(/power ?bi|tenant|secret|cliente/i)).toBeNull();

    await submit(user, 'Crear empresa');
    expect(within(dialog).getByText('Ingrese el nombre.')).toBeVisible();
    expect(within(dialog).getByText('El RUC debe tener exactamente 11 dígitos.')).toBeVisible();

    await fill(user, /^Nombre/, 'Empresa C Sintética');
    await fill(user, /^Razón social/, 'Empresa C Sintética S.A.C.');
    await fill(user, /^RUC/, '20987654321'); // ya existe (Andes Logística)
    await submit(user, 'Crear empresa');
    expect(await within(dialog).findByText('El RUC ya está registrado')).toBeVisible();
    expect(screen.getByRole('dialog', { name: 'Nueva empresa' })).toBeInTheDocument();
    expect(screen.queryByText(/creada correctamente/)).toBeNull();

    await fill(user, /^RUC/, '20999999991');
    await fill(user, /^Ciudad/, 'Cusco');
    await submit(user, 'Crear empresa');
    expect(await screen.findByText('Empresa «Empresa C Sintética» creada correctamente.')).toBeInTheDocument();
    await waitFor(() => expect(rowOf('Empresa C Sintética')?.textContent).toContain('Cusco'));

    await user.click(screen.getByRole('button', { name: 'Editar Empresa C Sintética' }));
    const edit = await screen.findByRole('dialog', { name: 'Editar empresa' });
    expect(await within(edit).findByDisplayValue('20999999991')).toBeInTheDocument();
    await fill(user, /^Ciudad/, 'Arequipa');
    await fill(user, /^Nombre/, '');
    await submit(user, 'Guardar cambios');
    expect(within(edit).getByText('Ingrese el nombre.')).toBeVisible();
    await fill(user, /^Nombre/, 'Empresa C Renombrada');
    await submit(user, 'Guardar cambios');
    expect(await screen.findByText('Empresa «Empresa C Renombrada» actualizada correctamente.')).toBeInTheDocument();
    await waitFor(() => expect(rowOf('Empresa C Renombrada')?.textContent).toContain('Arequipa'));
  }, 30000);
});

describe('UX-07B · reportes', () => {
  it('simplifica el alta (nombre, descripción y enlace), detecta el tipo de enlace, rechaza enlaces inseguros, crea, filtra «Sin empresa» y edita', async () => {
    const { user, go, login } = setup();
    await login('elena.torres');
    await screen.findByRole('heading', { level: 1, name: 'Inicio administrativo' });
    await act(async () => { go('/admin/reportes'); });
    await user.click(await screen.findByRole('button', { name: 'Nuevo reporte' }));
    const dialog = await screen.findByRole('dialog', { name: 'Nuevo reporte' });
    expect(within(dialog).getByText(/solo enlaces sintéticos/i)).toBeInTheDocument();
    expect(within(dialog).queryByLabelText(/ID del grupo|ID del informe/i)).toBeNull(); // ya no se piden identificadores de Power BI

    await fill(user, /^Nombre/, 'Informe Sintético C');
    await fill(user, /^Enlace de Power BI/, 'https://example.invalid/informe-c');
    expect(within(dialog).getByTestId('link-detected').textContent).toContain('app.powerbi.com');
    await submit(user, 'Crear reporte');
    expect(within(dialog).getAllByText(/app\.powerbi\.com/).length).toBeGreaterThan(0);
    for (const unsafe of ['http://app.powerbi.com/view?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDE', '<iframe src="x"></iframe>', 'https://app.powerbi.com.evil.example/view?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDE']) {
      await fill(user, /^Enlace de Power BI/, unsafe);
      expect(within(dialog).getByTestId('link-detected').textContent).toContain('Enlace no compatible');
    }

    await fill(user, /^Enlace de Power BI/, LINK);
    expect(within(dialog).getByTestId('link-detected').textContent).toContain('Publicación web');
    await submit(user, 'Crear reporte');
    expect(await screen.findByText(/Reporte «Informe Sintético C» creado correctamente/)).toBeInTheDocument();
    await waitFor(() => expect(rowOf('Informe Sintético C')).toBeTruthy());

    await user.selectOptions(screen.getByLabelText('Estado'), 'unlinked');
    await waitFor(() => expect(screen.queryAllByRole('row').filter(r => r.closest('tbody')).map(r => r.textContent)).toHaveLength(1));
    expect(rowOf('Informe Sintético C')?.textContent).toContain('—');

    await user.click(screen.getByRole('button', { name: 'Editar Informe Sintético C' }));
    const edit = await screen.findByRole('dialog', { name: 'Editar reporte' });
    expect(await within(edit).findByDisplayValue(LINK)).toBeInTheDocument();
    await fill(user, /^Descripción/, 'Descripción editada');
    await submit(user, 'Guardar cambios');
    expect(await screen.findByText(/Reporte «Informe Sintético C» actualizado correctamente/)).toBeInTheDocument();
    await waitFor(() => expect(rowOf('Informe Sintético C')?.textContent).toContain('Descripción editada'));
  }, 40000);
});

describe('UX-07B · asociaciones empresa ↔ informe', () => {
  it('asocia varios informes, evita duplicados, permite compartir entre empresas y desvincula con confirmación', async () => {
    const { user, go, login } = setup();
    await login('elena.torres');
    await screen.findByRole('heading', { level: 1, name: 'Inicio administrativo' });
    await act(async () => { go('/admin/asociaciones?company=emp-4'); });
    await screen.findByRole('heading', { level: 2, name: /Informes asociados/ });
    expect(screen.getByRole('list', { name: 'Informes asociados' }).textContent).toContain('Ocupación de activos'); // ya asociado de origen

    await user.click(screen.getByRole('checkbox', { name: /Ventas consolidadas/ }));
    await user.click(screen.getByRole('checkbox', { name: /Margen por canal/ }));
    await user.click(screen.getByRole('button', { name: 'Asociar seleccionados (2)' }));
    const outcome = await screen.findByLabelText('Resultado de la asociación');
    expect(outcome.textContent).toContain('Asociado');
    const associated = await screen.findByRole('list', { name: 'Informes asociados' });
    expect(associated.textContent).toContain('Ventas consolidadas');
    expect(associated.textContent).toContain('Compartido con 1 más'); // sigue también en Andes Logística: un informe puede pertenecer a varias empresas
    expect(screen.queryByRole('checkbox', { name: /Ventas consolidadas/ })).toBeNull(); // ya no figura como disponible: no se duplica

    await user.click(screen.getByRole('button', { name: 'Desvincular Margen por canal de Inmobiliaria Valle Azul S.A.C.' }));
    const dialog = await screen.findByRole('dialog', { name: 'Desvincular informe' });
    expect(dialog.textContent).toContain('dejarán de verlo');
    await user.click(within(dialog).getByRole('button', { name: 'Cancelar' }));
    expect(screen.getByRole('list', { name: 'Informes asociados' }).textContent).toContain('Margen por canal'); // cancelar no cambia nada
    await user.click(screen.getByRole('button', { name: 'Desvincular Margen por canal de Inmobiliaria Valle Azul S.A.C.' }));
    await user.click(within(await screen.findByRole('dialog', { name: 'Desvincular informe' })).getByRole('button', { name: 'Desvincular' }));
    expect(await screen.findByText(/desvinculado de Inmobiliaria Valle Azul/)).toBeInTheDocument();
    await waitFor(() => expect(screen.getByRole('list', { name: 'Informes asociados' }).textContent).not.toContain('Margen por canal'));
    expect(await screen.findByRole('checkbox', { name: /Margen por canal/ })).toBeEnabled(); // vuelve a estar disponible
  }, 30000);
});

describe('UX-07B + UX-07A · circuito completo (simulado)', () => {
  it('empresa → reporte → asociación → usuario → acceso → el usuario ve solo ese informe → desvincular → ya no lo ve', async () => {
    const { user, go, login, logout } = setup();
    await login('elena.torres');
    await screen.findByRole('heading', { level: 1, name: 'Inicio administrativo' });

    await act(async () => { go('/admin/empresas'); });
    await user.click(await screen.findByRole('button', { name: 'Nueva empresa' }));
    await fill(user, /^Nombre/, 'Empresa C Sintética');
    await fill(user, /^Razón social/, 'Empresa C Sintética S.A.C.');
    await fill(user, /^RUC/, '20999999991');
    await submit(user, 'Crear empresa');
    await screen.findByText(/creada correctamente/);

    await act(async () => { go('/admin/reportes'); });
    await user.click(await screen.findByRole('button', { name: 'Nuevo reporte' }));
    await fill(user, /^Nombre/, 'Informe Sintético C');
    await fill(user, /^Enlace de Power BI/, LINK);
    await submit(user, 'Crear reporte');
    await screen.findByText(/creado correctamente/);

    await act(async () => { go('/admin/asociaciones?company=emp-6'); });
    await user.click(await screen.findByRole('checkbox', { name: /Informe Sintético C/ }));
    await user.click(screen.getByRole('button', { name: 'Asociar seleccionados (1)' }));
    expect((await screen.findByRole('list', { name: 'Informes asociados' })).textContent).toContain('Informe Sintético C');

    await act(async () => { go('/admin/usuarios'); });
    await user.click(await screen.findByRole('button', { name: 'Nuevo usuario' }));
    await user.type(screen.getByLabelText(/^Nombres/), 'Operador');
    await user.type(screen.getByLabelText(/^Apellidos/), 'Prueba');
    await user.type(screen.getByLabelText(/^Usuario/), 'operador.c');
    await user.type(screen.getByLabelText(/^Correo/), 'operador.c@ejemplo.test');
    await user.type(screen.getByLabelText(/^Contraseña inicial/), 'synthetic-password');
    await submit(user, 'Crear usuario');
    await screen.findByText(/creado correctamente/);

    await user.click(await screen.findByRole('link', { name: 'Asignar accesos a Operador Prueba' }));
    await user.click(await screen.findByRole('button', { name: 'Continuar' }));
    await user.click(await screen.findByRole('radio', { name: /Empresa C Sintética/ }));
    await user.click(screen.getByRole('button', { name: 'Continuar' }));
    await user.click(await screen.findByRole('checkbox', { name: /Informe Sintético C/ }));
    await user.click(screen.getByRole('button', { name: 'Continuar' }));
    await user.click(await screen.findByRole('button', { name: 'Guardar accesos' }));
    expect(await screen.findByRole('heading', { level: 2, name: 'Accesos guardados' })).toBeInTheDocument();

    await logout();
    await login('operador.c');
    expect(await screen.findByRole('heading', { level: 1, name: 'Mis reportes' })).toBeInTheDocument();
    await waitFor(() => expect(document.querySelectorAll('.report-card')).toHaveLength(1));
    expect(document.querySelector('.report-card')?.textContent).toContain('Informe Sintético C');
    await act(async () => { go('/reportes/rep-1'); });
    expect(await screen.findByRole('heading', { level: 1, name: 'Acceso denegado' })).toBeInTheDocument();

    await logout();
    await login('elena.torres');
    await act(async () => { go('/admin/asociaciones?company=emp-6'); });
    await user.click(await screen.findByRole('button', { name: 'Desvincular Informe Sintético C de Empresa C Sintética' }));
    await user.click(within(await screen.findByRole('dialog', { name: 'Desvincular informe' })).getByRole('button', { name: 'Desvincular' }));
    await screen.findByText(/desvinculado de Empresa C Sintética/);

    await logout();
    await login('operador.c');
    await screen.findByRole('heading', { level: 1, name: 'Mis reportes' });
    await waitFor(() => expect(document.querySelectorAll('.report-card')).toHaveLength(0));
  }, 60000);
});
