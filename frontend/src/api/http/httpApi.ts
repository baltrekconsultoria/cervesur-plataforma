import { ApiError } from '../errors';
import type { ApiCapabilities, ApiClient, Assignment, AuthResponse, Company, LoginRequest, Report, TokenStore, User } from '../types';
import { composeReportCatalog, toCompany, toReport, toUser, type AssignmentDto, type CompanyDto, type ReportDto } from './adapters';

interface PaginationBlock { page?: number; per_page?: number; total?: number; total_pages?: number; has_more?: boolean }
interface Envelope { success?: boolean; message?: string; token?: string; user?: unknown; profile?: unknown; data?: unknown; pagination?: PaginationBlock }

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
 * Fuera de alcance en esta etapa: tokens de inserción de Power BI, listados administrativos de usuarios y escrituras.
 */
export class HttpApiClient implements ApiClient {
  // El nombre del grupo no existe en ninguna fuente real: el filtro de grupo permanece oculto.
  readonly capabilities: ApiCapabilities = { adminDirectory: false, reportCompany: true, reportGroups: false, profileEdit: false };
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
  listUsers(): Promise<User[]> { return Promise.reject(NOT_INTEGRATED('El listado de usuarios')); }
  listAssignments(): Promise<Assignment[]> { return Promise.reject(NOT_INTEGRATED('El listado de asignaciones')); }
  updateProfile(): Promise<User> { return Promise.reject(NOT_INTEGRATED('La edición del perfil')); }

  /**
   * Lee todas las páginas de un listado con `view=minimal` y valida la coherencia:
   * bloque `pagination` obligatorio, total estable entre páginas y filas recibidas = total declarado.
   */
  private async listAll<T>(path: string): Promise<T[]> {
    const rows: T[] = [];
    let declaredTotal: number | null = null;
    for (let page = 1; page <= MAX_PAGES; page++) {
      const env = await this.request('GET', `${path}?view=minimal&per_page=${PAGE_SIZE}&page=${page}`);
      const chunk = rowsOf<T>(env);
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

/** Filas de un listado: `data[]` o, en /api/asignacion, la doble envoltura `data.data[]`. */
function rowsOf<T>(env: Envelope): T[] | null {
  const data = env.data;
  if (Array.isArray(data)) return data as T[];
  if (data && typeof data === 'object' && Array.isArray((data as { data?: unknown }).data)) return (data as { data: T[] }).data;
  return null;
}
