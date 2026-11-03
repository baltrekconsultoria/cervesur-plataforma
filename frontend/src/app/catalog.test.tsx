import { render, screen, waitFor, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router';
import { describe, expect, it } from 'vitest';
import { ApiError } from '../api/errors';
import { createMemoryTokenStore } from '../api/tokenStore';
import type { ApiCapabilities, ApiClient, Company, Report, User } from '../api/types';
import { App } from './App';
import { AppProvider } from './AppProvider';

const company = (id: string, name: string): Company => ({ id, name, city: '', taxId: '', active: true });
const report = (id: string, name: string, companyIds: string[], description = ''): Report => ({ id, name, description, companyId: companyIds[0] ?? '', companyIds, group: '', active: true });
const USER: User = { id: '1', username: 'multi', displayName: 'Usuario Multi', email: 'multi@example.invalid', role: 'user', active: true, phone: '', companyIds: [] };

/** Cliente que imita el proveedor HTTP: empresa por informe, sin grupos. */
class CatalogApi implements ApiClient {
  readonly capabilities: ApiCapabilities = { adminDirectory: false, reportCompany: true, reportGroups: false, profileEdit: false, adminWrites: false, liveViewer: false };
  reportsError: ApiError | null = null;
  constructor(private readonly reports: Report[], private readonly companies: Company[]) {}
  login() { return Promise.resolve({ token: 't', user: USER }); }
  logout() { return Promise.resolve(); }
  me() { return Promise.resolve(USER); }
  listReports() { return this.reportsError ? Promise.reject(this.reportsError) : Promise.resolve(this.reports); }
  getReport(id: string) { const found = this.reports.find(r => r.id === id); return found ? Promise.resolve(found) : Promise.reject(new ApiError(404, 'no')); }
  listCompanies() { return Promise.resolve(this.companies); }
  listUsers() { return Promise.reject(new ApiError(501, 'x', 'not_integrated')); }
  listAssignments() { return Promise.reject(new ApiError(501, 'x', 'not_integrated')); }
  updateProfile() { return Promise.reject(new ApiError(501, 'x', 'not_integrated')); }
  createUser() { return Promise.reject(new ApiError(501, 'x', 'not_integrated')); }
  grantAccess() { return Promise.reject(new ApiError(501, 'x', 'not_integrated')); }
  revokeAssignment() { return Promise.reject(new ApiError(501, 'x', 'not_integrated')); }
  getCompanyDetail() { return Promise.reject(new ApiError(501, 'x', 'not_integrated')); }
  createCompany() { return Promise.reject(new ApiError(501, 'x', 'not_integrated')); }
  updateCompany() { return Promise.reject(new ApiError(501, 'x', 'not_integrated')); }
  getReportDetail() { return Promise.reject(new ApiError(501, 'x', 'not_integrated')); }
  getViewerConfig() { return Promise.resolve({ mode: 'simulated' as const }); }
  createReport() { return Promise.reject(new ApiError(501, 'x', 'not_integrated')); }
  updateReport() { return Promise.reject(new ApiError(501, 'x', 'not_integrated')); }
  listReportLinks() { return Promise.reject(new ApiError(501, 'x', 'not_integrated')); }
  linkReports() { return Promise.reject(new ApiError(501, 'x', 'not_integrated')); }
  unlinkReport() { return Promise.reject(new ApiError(501, 'x', 'not_integrated')); }
  deactivateCompany() { return Promise.reject(new ApiError(501, 'x', 'not_integrated')); }
  reactivateCompany() { return Promise.reject(new ApiError(501, 'x', 'not_integrated')); }
  deactivateReport() { return Promise.reject(new ApiError(501, 'x', 'not_integrated')); }
  reactivateReport() { return Promise.reject(new ApiError(501, 'x', 'not_integrated')); }
  reactivateAssignment() { return Promise.reject(new ApiError(501, 'x', 'not_integrated')); }
  listAudit() { return Promise.reject(new ApiError(501, 'x', 'not_integrated')); }
  getUserAdminEnabled() { return Promise.resolve(false); }
  getUserDetail() { return Promise.reject(new ApiError(501, 'x', 'not_integrated')); }
  updateUser() { return Promise.reject(new ApiError(501, 'x', 'not_integrated')); }
  deactivateUser() { return Promise.reject(new ApiError(501, 'x', 'not_integrated')); }
  reactivateUser() { return Promise.reject(new ApiError(501, 'x', 'not_integrated')); }
  changeUserRole() { return Promise.reject(new ApiError(501, 'x', 'not_integrated')); }
  getUserAccess() { return Promise.reject(new ApiError(501, 'x', 'not_integrated')); }
}

async function setup(api: CatalogApi, entry = '/login') {
  const tokens = createMemoryTokenStore();
  render(<MemoryRouter initialEntries={[entry]}><AppProvider services={{ tokens, client: api }}><App /></AppProvider></MemoryRouter>);
  const user = userEvent.setup();
  expect(screen.getByLabelText('Correo o usuario')).toHaveValue(''); // con un proveedor real no se precarga ninguna cuenta de demostración
  await user.type(screen.getByLabelText('Correo o usuario'), 'multi');
  await user.type(screen.getByLabelText('Contraseña'), 'x');
  await user.click(screen.getByRole('button', { name: 'Ingresar al portal' }));
  await screen.findByRole('heading', { level: 1, name: 'Mis reportes' });
  return user;
}
const names = () => [...document.querySelectorAll('.report-card h2')].map(h => h.textContent);

const COMPANIES = [company('1', 'Empresa A'), company('2', 'Empresa B')];
const REPORTS = [
  report('r-a', 'Reporte A', ['1'], 'Indicadores de A'),
  report('r-b', 'Reporte B', ['2'], 'Indicadores de B'),
  report('r-ab', 'Ventas regionales', ['1', '2'], 'Comercial por región'),
  report('r-x', 'Sin empresa visible', [], 'Relación no determinada')
];

describe('catálogo por empresa (proveedor con relación empresa–informe, sin grupos)', () => {
  it('lista cada informe una sola vez, con todas sus empresas, y no inventa grupos', async () => {
    await setup(new CatalogApi(REPORTS, COMPANIES));
    await waitFor(() => expect(names()).toHaveLength(4));
    const multi = [...document.querySelectorAll('.report-card')].find(card => card.textContent?.includes('Ventas regionales'));
    expect(multi?.querySelector('.company')?.textContent).toBe('Empresa A · Empresa B');
    expect(document.querySelectorAll('.report-card .tag')).toHaveLength(0);
    expect(screen.queryByLabelText('Grupo')).toBeNull();
  });

  it('el filtro por empresa incluye los informes vinculados a varias empresas', async () => {
    const user = await setup(new CatalogApi(REPORTS, COMPANIES));
    await screen.findByLabelText('Empresa');
    await user.selectOptions(screen.getByLabelText('Empresa'), '2');
    expect(names()).toEqual(['Reporte B', 'Ventas regionales']);
    await user.selectOptions(screen.getByLabelText('Empresa'), '1');
    expect(names()).toEqual(['Reporte A', 'Ventas regionales']);
    expect(screen.getByText('Mostrando', { exact: false }).textContent).toContain('2 de 4');
  });

  it('opciones de empresa solo con empresas que tienen informes y combinación con la búsqueda', async () => {
    const user = await setup(new CatalogApi(REPORTS, [...COMPANIES, company('3', 'Empresa sin informes')]));
    const options = within(await screen.findByLabelText('Empresa')).getAllByRole('option').map(o => o.textContent);
    expect(options).toEqual(['Todas mis empresas', 'Empresa A', 'Empresa B']);
    await user.selectOptions(screen.getByLabelText('Empresa'), '2');
    await user.type(screen.getByLabelText('Buscar informe'), 'región');
    expect(names()).toEqual(['Ventas regionales']);
    await user.clear(screen.getByLabelText('Buscar informe'));
    await user.type(screen.getByLabelText('Buscar informe'), 'indicadores de a');
    expect(names()).toEqual([]);
    expect(screen.getByText('Sin resultados')).toBeInTheDocument();
  });

  it('un informe sin empresa determinada se muestra, pero no entra en ningún filtro por empresa', async () => {
    const user = await setup(new CatalogApi(REPORTS, COMPANIES));
    await waitFor(() => expect(names()).toContain('Sin empresa visible'));
    await user.selectOptions(screen.getByLabelText('Empresa'), '1');
    expect(names()).not.toContain('Sin empresa visible');
  });

  it('«Mis empresas» cuenta los informes de cada empresa, incluidos los compartidos', async () => {
    const user = await setup(new CatalogApi(REPORTS, COMPANIES));
    await user.click(await screen.findByRole('link', { name: /Mis empresas/ }));
    const cards = await screen.findAllByRole('article');
    expect(cards.map(c => c.querySelector('.company')?.textContent)).toEqual(['2 informes disponibles', '2 informes disponibles']);
  });

  it('el visor muestra todas las empresas del informe compartido', async () => {
    const user = await setup(new CatalogApi(REPORTS, COMPANIES));
    await user.click(await screen.findByRole('link', { name: 'Abrir informe Ventas regionales' }));
    expect(await screen.findByRole('heading', { level: 1, name: 'Ventas regionales' })).toBeInTheDocument();
    expect(document.querySelector('.viewer-meta')?.textContent).toContain('Empresa A · Empresa B');
    // UX-07E.C: los otros informes van en una ventana («Otros informes»), no como etiquetas encima del visor
    expect(screen.getByRole('button', { name: /Otros informes \(\d+\)/ })).toBeInTheDocument();
    expect(screen.queryByText(/Más informes de/)).toBeNull();
  });

  it('un error al componer el catálogo no muestra resultados parciales y permite reintentar', async () => {
    const api = new CatalogApi(REPORTS, COMPANIES);
    api.reportsError = new ApiError(502, 'Listado incompleto', 'invalid_response');
    const tokens = createMemoryTokenStore();
    render(<MemoryRouter initialEntries={['/login']}><AppProvider services={{ tokens, client: api }}><App /></AppProvider></MemoryRouter>);
    const user = userEvent.setup();
    expect(screen.getByLabelText('Correo o usuario')).toHaveValue(''); // con un proveedor real no se precarga ninguna cuenta de demostración
  await user.type(screen.getByLabelText('Correo o usuario'), 'multi');
  await user.type(screen.getByLabelText('Contraseña'), 'x');
    await user.click(screen.getByRole('button', { name: 'Ingresar al portal' }));
    expect(await screen.findByText('No se pudo cargar la información')).toBeInTheDocument();
    expect(document.querySelectorAll('.report-card')).toHaveLength(0);
    api.reportsError = null;
    await user.click(screen.getByRole('button', { name: 'Reintentar' }));
    await waitFor(() => expect(names()).toHaveLength(4));
  });
});
