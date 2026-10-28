import { ApiError } from '../errors';
import type { ApiCapabilities, ApiClient, Assignment, AuthResponse, Company, CompanyDetail, CompanyInput, GrantRequest, GrantResult, LinkRequest, LoginRequest, NewUser, Report, ReportDetail, ReportInput, ReportLink, TokenStore, User } from '../types';
import { companyBody, composeReportCatalog, reportBody, toActive, toAssignment, toCompany, toCompanyDetail, toDirectoryUser, toReport, toReportDetail, toReportLink, toUser, type AccessDto, type AssignmentDto, type CompanyDto, type CompanyFormDto, type CompanyLinkDto, type ReportDto, type ReportFormDto, type UserDto } from './adapters';

interface PaginationBlock { page?: number; per_page?: number; total?: number; total_pages?: number; has_more?: boolean }
interface Envelope { success?: boolean; message?: string; token?: string; user?: unknown; users?: unknown; profile?: unknown; data?: unknown; pagination?: PaginationBlock }
interface ListOptions { /** Pide la vista mínima (solo endpoints que la admiten). */ minimal?: boolean; /** Parámetros adicionales, p. ej. `status=all`. */ query?: string; /** Propiedad con las filas (por defecto `data`). */ key?: 'data' | 'users' }

export interface HttpApiOptions {
  baseUrl: string;
  tokens: TokenStore;
  /** Inyectable para pruebas; por defecto `fetch` del navegador. */
  fetchImpl?: typeof fetch;
}

const PAGE_SIZE = 100; // máximo permitido por el servidor
const MAX_PAGES = 500;
const NOT_INTEGRATED = (what: string) => new ApiError(501, `${what} aún no está integrado con el servicio real.`, 'not_integrated');
const invalid = (message: string) => new ApiError(502, message, 'invalid_response');

/**
 * Proveedor HTTP contra el backend PHP real (solo entorno local aislado; la fábrica rechaza otros hosts).
 * Contrato verificado contra el código del backend (UX-06A / UX-06B.0):
 *  - POST /api/login: credenciales inválidas o usuario inactivo responden HTTP 200 con `success:false`
 *    ("Credenciales inválidas"); aquí se traducen a 401 para el resto de la aplicación.
 *  - GET /api/user/profile → `{ profile }`.
 *  - Listados (`/api/reportes`, `/api/asignacion`, `/api/empresas`) con `?view=minimal` (sin vínculos de visualización, campos de creación,
 *    datos de Power BI ni contacto) y paginación de servidor: se leen TODAS las páginas y se exige que el total declarado
 *    coincida con las filas recibidas; si algo falta se falla en lugar de mostrar un resultado incompleto.
 *  - Catálogo de informes: `/api/reportes` + `/api/asignacion` + `/api/empresas` (provisional, ver `composeReportCatalog`).
 *  - GET /api/reportes/reporteid/{uuid}: un informe ajeno o inexistente responde 404 (se trata como acceso denegado).
 *  - POST /api/logout incrementa `session_version`: el token usado deja de ser válido.
 * UX-07A (administración): listados y escrituras administrativas contra el backend real:
 *  - `/api/users?status=all` (usuarios), `/api/empresa-usuario` (usuario ↔ empresa) y `/api/empresa-reporte-usuario` (acceso efectivo), con `view=minimal` donde existe.
 *  - POST /api/register (alta; el backend fija rol, estado y versión de sesión), POST /api/empresa-usuario, POST/DELETE /api/empresa-reporte-usuario.
 *  - Las escrituras solo se dan por buenas si el servidor responde `success:true`; un rechazo (HTTP 200 con `success:false`) se informa tal cual.
 * UX-07B (catálogo): `GET /api/empresas/{id}?view=form` y `GET /api/reportes/reporteid/{uuid}?view=form` (solo administradores) para editar;
 *  POST/PUT /api/empresas, POST/PUT /api/reportes y POST/DELETE /api/asignacion (asociación empresa ↔ informe con ids numéricos internos).
 *  El formulario de empresa NO envía ni lee credenciales de Power BI.
 * Fuera de alcance en esta etapa: tokens de inserción de Power BI reales.
 */
export class HttpApiClient implements ApiClient {
  // El nombre del grupo no existe en ninguna fuente real: el filtro de grupo permanece oculto.
  readonly capabilities: ApiCapabilities = { adminDirectory: true, reportCompany: true, reportGroups: false, profileEdit: false, adminWrites: true };
  private readonly baseUrl: string;
  private readonly tokens: TokenStore;
  private readonly fetchImpl: typeof fetch;

  constructor({ baseUrl, tokens, fetchImpl }: HttpApiOptions) {
    if (!baseUrl) throw new Error('HttpApiClient requiere una URL base.');
    this.baseUrl = baseUrl.replace(/\/+$/, '');
    this.tokens = tokens;
    this.fetchImpl = fetchImpl ?? ((input, init) => fetch(input, init));
  }

  async login({ login, password }: LoginRequest): Promise<AuthResponse> {
    const env = await this.request('POST', '/api/login', { login, password }, false);
    if (env.success !== true) throw new ApiError(401, env.message || 'Credenciales inválidas');
    if (typeof env.token !== 'string' || !env.token || !env.user) throw invalid('Respuesta de login inesperada.');
    return { token: env.token, user: toUser(env.user as Parameters<typeof toUser>[0]) };
  }

  async logout(): Promise<void> {
    try {
      await this.request('POST', '/api/logout');
    } catch (error) {
      // 401: el token ya no era válido, objetivo cumplido. Cualquier otro fallo sí se informa.
      if (!(error instanceof ApiError && error.kind === 'unauthorized')) throw error;
    }
  }

  async me(): Promise<User> {
    const env = await this.request('GET', '/api/user/profile');
    if (!env.profile) throw invalid('Respuesta de perfil inesperada.');
    return toUser(env.profile as Parameters<typeof toUser>[0]);
  }

  async listReports(): Promise<Report[]> {
    // Las tres lecturas son necesarias: si cualquiera falla, falla todo (nunca un catálogo parcial como definitivo).
    const [reports, assignments, companies] = await Promise.all([
      this.listAll<ReportDto>('/api/reportes'),
      this.listAll<AssignmentDto>('/api/asignacion'),
      this.listCompanies()
    ]);
    return composeReportCatalog(reports, assignments, companies);
  }

  async getReport(id: string): Promise<Report> {
    const env = await this.request('GET', `/api/reportes/reporteid/${encodeURIComponent(id)}?view=minimal`);
    const report = env.data && typeof env.data === 'object' ? toReport(env.data as ReportDto) : null;
    if (!report) throw new ApiError(404, 'Reporte no encontrado');
    return report;
  }

  async listCompanies(): Promise<Company[]> { return (await this.listAll<CompanyDto>('/api/empresas')).map(toCompany); }
  async listUsers(): Promise<User[]> {
    const [users, links] = await Promise.all([
      this.listAll<UserDto>('/api/users', { minimal: false, key: 'users', query: 'status=all' }),
      this.listAll<CompanyLinkDto>('/api/empresa-usuario')
    ]);
    const companiesByUser = new Map<string, string[]>();
    for (const link of links) {
      const key = String(link.user_id);
      const list = companiesByUser.get(key) ?? companiesByUser.set(key, []).get(key)!;
      if (!list.includes(String(link.empresa_id))) list.push(String(link.empresa_id));
    }
    return users.map(dto => { const user = toDirectoryUser(dto); return { ...user, companyIds: companiesByUser.get(user.id) ?? [] }; });
  }

  async listAssignments(): Promise<Assignment[]> {
    const rows = await this.listAll<AccessDto>('/api/empresa-reporte-usuario');
    return rows.map(toAssignment).filter((a): a is Assignment => a !== null);
  }

  updateProfile(): Promise<User> { return Promise.reject(NOT_INTEGRATED('La edición del perfil')); }

  async createUser(input: NewUser): Promise<void> {
    // Solo datos de la persona: el rol, el estado y la versión de sesión los decide el backend (SEC-05A).
    const body: Record<string, string> = { first_name: input.firstName.trim(), last_name: input.lastName.trim(), username: input.username.trim(), email: input.email.trim(), password: input.password };
    if (input.phone.trim()) body.phone = input.phone.trim();
    await this.mutate('POST', '/api/register', body);
  }

  async grantAccess({ userId, companyId, reportIds }: GrantRequest): Promise<GrantResult[]> {
    const user = positiveInt(userId); const company = positiveInt(companyId);
    if (!user || !company) throw new ApiError(400, 'Usuario o empresa no válidos.');
    // Lecturas frescas: el catálogo y las relaciones pueden haber cambiado desde que se abrió el asistente.
    const [reports, links, relations] = await Promise.all([
      this.listAll<ReportDto>('/api/reportes'),
      this.listAll<CompanyLinkDto>('/api/empresa-usuario'),
      this.listAll<AssignmentDto>('/api/asignacion')
    ]);
    const numericByUuid = new Map<string, string>();
    for (const r of reports) if (typeof r.reporte_id === 'string' && r.id !== undefined) numericByUuid.set(r.reporte_id, String(r.id));
    // 1) relación usuario ↔ empresa (necesaria para el acceso efectivo). Si falla, no se concede nada.
    if (!links.some(l => String(l.user_id) === userId && String(l.empresa_id) === companyId)) {
      await this.mutate('POST', '/api/empresa-usuario', { user_id: user, empresa_id: company });
    }
    // 2) un acceso por informe, a través de la relación empresa ↔ informe (id `empresa_reporte`, distinto del id del informe y de su UUID).
    const results: GrantResult[] = [];
    for (const reportId of reportIds) {
      const numeric = numericByUuid.get(reportId);
      const relation = numeric === undefined ? undefined : relations.find(a => String(a.empresa_id) === companyId && String(a.reporte_id) === numeric && a.id !== undefined && toActive(a.activo));
      const relationId = relation ? positiveInt(String(relation.id)) : null;
      if (!relationId) { results.push({ reportId, status: 'failed', message: 'El informe no está asignado a esa empresa.' }); continue; }
      try {
        await this.mutate('POST', '/api/empresa-reporte-usuario', { user_id: user, empresa_reporte_id: relationId });
        results.push({ reportId, status: 'granted', message: 'Acceso concedido.' });
      } catch (error) {
        if (!(error instanceof ApiError) || error.kind !== 'validation') throw error;
        results.push(/ya existe/i.test(error.message) ? { reportId, status: 'already', message: 'El usuario ya tenía este acceso.' } : { reportId, status: 'failed', message: error.message });
      }
    }
    return results;
  }

  async getCompanyDetail(id: string): Promise<CompanyDetail> {
    const numeric = positiveInt(id);
    if (!numeric) throw new ApiError(400, 'Empresa no válida.');
    const env = await this.request('GET', `/api/empresas/${numeric}?view=form`);
    if (!env.data || typeof env.data !== 'object') throw new ApiError(404, 'Empresa no encontrada');
    return toCompanyDetail(env.data as CompanyFormDto);
  }

  async createCompany(input: CompanyInput): Promise<void> {
    await this.mutate('POST', '/api/empresas', companyBody(input, false));
  }

  async updateCompany(id: string, input: CompanyInput): Promise<void> {
    const numeric = positiveInt(id);
    if (!numeric) throw new ApiError(400, 'Empresa no válida.');
    await this.mutate('PUT', `/api/empresas/${numeric}`, companyBody(input, true));
  }

  async getReportDetail(reportId: string): Promise<ReportDetail> {
    const env = await this.request('GET', `/api/reportes/reporteid/${encodeURIComponent(reportId)}?view=form`);
    if (!env.data || typeof env.data !== 'object') throw new ApiError(404, 'Informe no encontrado');
    return toReportDetail(env.data as ReportFormDto);
  }

  async createReport(input: ReportInput): Promise<void> {
    await this.mutate('POST', '/api/reportes', reportBody(input));
  }

  async updateReport(internalId: string, input: ReportInput): Promise<void> {
    const numeric = positiveInt(internalId);
    if (!numeric) throw new ApiError(400, 'Informe no válido.');
    await this.mutate('PUT', `/api/reportes/${numeric}`, reportBody(input));
  }

  async listReportLinks(): Promise<ReportLink[]> {
    const [reports, relations] = await Promise.all([this.listAll<ReportDto>('/api/reportes'), this.listAll<AssignmentDto>('/api/asignacion')]);
    const uuidByNumeric = new Map<string, string>();
    for (const r of reports) if (typeof r.reporte_id === 'string' && r.id !== undefined) uuidByNumeric.set(String(r.id), r.reporte_id);
    return relations.map(r => toReportLink(r, uuidByNumeric)).filter((l): l is ReportLink => l !== null);
  }

  async linkReports({ companyId, reportIds }: LinkRequest): Promise<GrantResult[]> {
    const company = positiveInt(companyId);
    if (!company) throw new ApiError(400, 'Empresa no válida.');
    const reports = await this.listAll<ReportDto>('/api/reportes');
    const numericByUuid = new Map<string, number>();
    for (const r of reports) if (typeof r.reporte_id === 'string' && r.id !== undefined) { const n = positiveInt(String(r.id)); if (n) numericByUuid.set(r.reporte_id, n); }
    const results: GrantResult[] = [];
    for (const reportId of reportIds) {
      const report = numericByUuid.get(reportId);
      if (!report) { results.push({ reportId, status: 'failed', message: 'El informe no existe o está inactivo.' }); continue; }
      try {
        await this.mutate('POST', '/api/asignacion', { empresa_id: company, reporte_id: report });
        results.push({ reportId, status: 'granted', message: 'Informe asociado.' });
      } catch (error) {
        if (!(error instanceof ApiError) || error.kind !== 'validation') throw error;
        results.push(/ya existe/i.test(error.message) ? { reportId, status: 'already', message: 'El informe ya estaba asociado a la empresa.' } : { reportId, status: 'failed', message: error.message });
      }
    }
    return results;
  }

  async unlinkReport(linkId: string): Promise<void> {
    const id = positiveInt(linkId);
    if (!id) throw new ApiError(400, 'Asociación no válida.');
    await this.mutate('DELETE', `/api/asignacion/${id}`);
  }

  async revokeAssignment(assignmentId: string): Promise<void> {
    const id = positiveInt(assignmentId);
    if (!id) throw new ApiError(400, 'Acceso no válido.');
    await this.mutate('DELETE', `/api/empresa-reporte-usuario/${id}`);
  }

  /**
   * Lee todas las páginas de un listado con `view=minimal` y valida la coherencia:
   * bloque `pagination` obligatorio, total estable entre páginas y filas recibidas = total declarado.
   */
  private async listAll<T>(path: string, { minimal = true, query, key = 'data' }: ListOptions = {}): Promise<T[]> {
    const rows: T[] = [];
    let declaredTotal: number | null = null;
    const extra = `${minimal ? 'view=minimal&' : ''}${query ? `${query}&` : ''}`;
    for (let page = 1; page <= MAX_PAGES; page++) {
      const env = await this.request('GET', `${path}?${extra}per_page=${PAGE_SIZE}&page=${page}`);
      const chunk = key === 'users' ? (Array.isArray(env.users) ? (env.users as T[]) : null) : rowsOf<T>(env);
      const meta = env.pagination;
      if (!chunk || !meta || typeof meta.total !== 'number' || typeof meta.has_more !== 'boolean') throw invalid('Respuesta de listado sin paginación válida.');
      if (declaredTotal !== null && meta.total !== declaredTotal) throw invalid('Los datos cambiaron durante la lectura; reintente.');
      declaredTotal = meta.total;
      rows.push(...chunk);
      if (!meta.has_more) {
        if (rows.length !== declaredTotal) throw invalid('Listado incompleto: las filas recibidas no coinciden con el total declarado.');
        return rows;
      }
    }
    throw invalid('El listado excede el máximo de páginas permitido.');
  }

  /** Escritura administrativa: solo se da por hecha si el servidor responde `success:true`. */
  private async mutate(method: 'POST' | 'PUT' | 'DELETE', path: string, body?: unknown): Promise<Envelope> {
    const env = await this.request(method, path, body);
    if (env.success !== true) throw new ApiError(422, env.message || 'El servicio rechazó la operación.');
    return env;
  }

  private async request(method: string, path: string, body?: unknown, auth = true): Promise<Envelope> {
    const headers: Record<string, string> = { Accept: 'application/json' };
    if (body !== undefined) headers['Content-Type'] = 'application/json';
    const token = this.tokens.get();
    if (auth && token) headers.Authorization = `Bearer ${token}`;
    let response: Response;
    try {
      response = await this.fetchImpl(`${this.baseUrl}${path}`, { method, headers, body: body === undefined ? undefined : JSON.stringify(body), credentials: 'omit' });
    } catch {
      throw new ApiError(0, 'No se pudo conectar con el servicio.');
    }
    let payload: unknown = null;
    try { payload = await response.json(); } catch { /* cuerpo vacío o no JSON */ }
    const envelope = payload && typeof payload === 'object' && !Array.isArray(payload) ? (payload as Envelope) : null;
    if (!response.ok) throw new ApiError(response.status, envelope?.message ?? `Error ${response.status}`);
    if (!envelope) throw invalid('El servicio devolvió una respuesta no válida.');
    return envelope;
  }
}

const positiveInt = (value: string): number | null => (/^[1-9]\d*$/.test(value) ? Number(value) : null);

/** Filas de un listado: `data[]` o, en /api/asignacion, la doble envoltura `data.data[]`. */
function rowsOf<T>(env: Envelope): T[] | null {
  const data = env.data;
  if (Array.isArray(data)) return data as T[];
  if (data && typeof data === 'object' && Array.isArray((data as { data?: unknown }).data)) return (data as { data: T[] }).data;
  return null;
}
