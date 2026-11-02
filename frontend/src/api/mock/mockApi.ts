import { assignmentStatus } from '../../domain/access';
import { ApiError } from '../errors';
import { classifyPowerBiLink } from '../../domain/powerbiLink';
import type { AuditEntry, AuditPage, AuditQuery, ApiCapabilities, ApiClient, AuthResponse, Assignment, Company, CompanyDetail, CompanyInput, GrantRequest, GrantResult, LinkRequest, LoginRequest, NewUser, ProfileUpdate, Report, ReportDetail, ReportInput, ReportLink, TokenStore, User, ViewerConfig } from '../types';
import { createSeed, type Seed } from './seed';

export type MockScenario = 'normal' | 'empty' | 'forbidden';

/**
 * Proveedor simulado. Hace de "servidor": valida el token, comprueba el rol y
 * decide la autorización de cada recurso, igual que debe hacerlo el backend.
 * La interfaz nunca confía en sus propias comprobaciones de rol.
 * Las credenciales no se validan: basta un usuario sintético existente y una contraseña no vacía.
 */
export class MockApi implements ApiClient {
  readonly capabilities: ApiCapabilities = { adminDirectory: true, reportCompany: true, reportGroups: true, profileEdit: true, adminWrites: true, liveViewer: false };
  private seed: Seed = createSeed();
  private sessions = new Map<string, string>();
  /** Datos técnicos y de contacto que el modelo normalizado no lleva (solo para los formularios administrativos). */
  private companyExtra = new Map<string, Partial<CompanyInput>>();
  private reportMeta = new Map<string, string>();
  private scenario: MockScenario = 'normal';
  /** Historial simulado (en el servidor real es la tabla `audit_log`). */
  private auditLog: AuditEntry[] = [];
  private auditSeq = 0;

  constructor(private readonly tokens: TokenStore) {}

  /* --- Controles de demostración (no forman parte del contrato ApiClient) --- */
  getScenario(): MockScenario { return this.scenario; }
  setScenario(scenario: MockScenario): void { this.scenario = scenario; }
  /** Invalida todas las sesiones: la siguiente petición responde 401. */
  expireSessions(): void { this.sessions.clear(); }
  reset(): void { this.seed = createSeed(); this.scenario = 'normal'; this.companyExtra.clear(); this.reportMeta.clear(); }

  /* --- ApiClient --- */
  async login({ login, password }: LoginRequest): Promise<AuthResponse> {
    if (!login.trim() || !password) throw new ApiError(400, 'Faltan datos de login o password');
    const needle = login.trim().toLowerCase();
    const user = this.seed.users.find(u => u.username === needle || u.email === needle);
    if (!user || !user.active) throw new ApiError(401, 'Usuario o contraseña incorrectos.');
    const token = `mock-${Math.random().toString(36).slice(2)}${Math.random().toString(36).slice(2)}`;
    this.sessions.set(token, user.id);
    return { token, user: { ...user } };
  }

  async logout(): Promise<void> {
    const token = this.tokens.get();
    if (token) this.sessions.delete(token);
  }

  async me(): Promise<User> { return { ...this.requireUser() }; }

  async listReports(): Promise<Report[]> {
    const user = this.requireUser();
    if (user.role === 'admin') return this.seed.reports.map(r => ({ ...r }));
    if (this.scenario === 'empty') return [];
    return this.effectiveReports(user).map(r => ({ ...r }));
  }

  async getReport(id: string): Promise<Report> {
    const user = this.requireUser();
    const report = this.seed.reports.find(r => r.id === id);
    const allowed = user.role === 'admin' ? Boolean(report) : Boolean(report) && this.effectiveReports(user).some(r => r.id === id);
    if (!report || !allowed || this.scenario === 'forbidden') throw new ApiError(403, 'Acceso denegado');
    return { ...report };
  }

  async listCompanies(): Promise<Company[]> {
    const user = this.requireUser();
    const all = user.role === 'admin' ? this.seed.companies : this.seed.companies.filter(c => c.active && user.companyIds.includes(c.id));
    return all.map(c => ({ ...c }));
  }

  async listUsers(): Promise<User[]> { this.requireAdmin(); return this.seed.users.map(u => ({ ...u })); }
  async listAssignments(): Promise<Assignment[]> { this.requireAdmin(); return this.seed.assignments.map(a => ({ ...a })); }

  async createUser(input: NewUser): Promise<void> {
    this.requireAdmin();
    const username = input.username.trim().toLowerCase(); const email = input.email.trim().toLowerCase();
    if (!input.firstName.trim() || !input.lastName.trim() || !username || !email || !input.password) throw new ApiError(400, 'Faltan campos obligatorios');
    if (input.password.length < 6) throw new ApiError(422, 'La contraseña debe tener al menos 6 caracteres');
    if (this.seed.users.some(u => u.email === email)) throw new ApiError(422, 'El email ya está registrado');
    if (this.seed.users.some(u => u.username === username)) throw new ApiError(422, 'El nombre de usuario ya está en uso');
    this.seed.users.push({ id: `usr-${this.seed.users.length + 1}`, username, displayName: `${input.firstName.trim()} ${input.lastName.trim()}`, email, role: 'user', active: true, phone: input.phone.trim(), companyIds: [] });
  }

  async grantAccess({ userId, companyId, reportIds }: GrantRequest): Promise<GrantResult[]> {
    this.requireAdmin();
    const user = this.seed.users.find(u => u.id === userId);
    const company = this.seed.companies.find(c => c.id === companyId);
    if (!user?.active || !company?.active) throw new ApiError(422, 'La empresa o el usuario especificado no existe o está inactivo');
    if (!user.companyIds.includes(companyId)) user.companyIds.push(companyId);
    return reportIds.map((reportId): GrantResult => {
      const report = this.seed.reports.find(r => r.id === reportId);
      if (!report?.active || !report.companyIds.includes(companyId)) return { reportId, status: 'failed', message: 'El informe no está asignado a esa empresa.' };
      const existing = this.seed.assignments.find(a => a.userId === userId && a.reportId === reportId && a.companyId === companyId);
      if (existing && existing.active === false && existing.id) {
        existing.active = true; // reactiva la fila revocada en lugar de duplicarla
        this.record('user_empresa_reporte.reactivate', 'user_empresa_reporte', existing.id, { activo: 0 }, { activo: 1 });
        return { reportId, status: 'granted', message: 'Acceso concedido.' };
      }
      if (existing) return { reportId, status: 'already', message: 'El usuario ya tenía este acceso.' };
      const id = `asg-${Math.random().toString(36).slice(2, 8)}`;
      this.seed.assignments.push({ id, userId, reportId, companyId });
      this.record('user_empresa_reporte.create', 'user_empresa_reporte', id, null, { activo: 1 });
      return { reportId, status: 'granted', message: 'Acceso concedido.' };
    });
  }

  async getCompanyDetail(id: string): Promise<CompanyDetail> {
    this.requireAdmin();
    const company = this.seed.companies.find(c => c.id === id);
    if (!company) throw new ApiError(404, 'Empresa no encontrada');
    const extra = this.companyExtra.get(id) ?? {};
    return { id, name: company.name, legalName: extra.legalName ?? company.name, taxId: company.taxId, city: company.city, country: extra.country ?? 'Perú', email: extra.email ?? '', phone: extra.phone ?? '', address: extra.address ?? '' };
  }

  async createCompany(input: CompanyInput): Promise<void> {
    this.requireAdmin();
    this.validateCompany(input, null);
    const id = `emp-${this.seed.companies.length + 1}`;
    this.seed.companies.push({ id, name: input.name.trim(), city: input.city.trim(), taxId: input.taxId.trim(), active: true });
    this.companyExtra.set(id, { legalName: input.legalName.trim(), country: input.country.trim(), email: input.email.trim(), phone: input.phone.trim(), address: input.address.trim() });
  }

  async updateCompany(id: string, input: CompanyInput): Promise<void> {
    this.requireAdmin();
    const company = this.seed.companies.find(c => c.id === id);
    if (!company) throw new ApiError(422, 'Empresa no encontrada');
    this.validateCompany(input, id);
    Object.assign(company, { name: input.name.trim(), city: input.city.trim(), taxId: input.taxId.trim() });
    this.companyExtra.set(id, { legalName: input.legalName.trim(), country: input.country.trim(), email: input.email.trim(), phone: input.phone.trim(), address: input.address.trim() });
  }

  async getReportDetail(reportId: string): Promise<ReportDetail> {
    this.requireAdmin();
    const report = this.seed.reports.find(r => r.id === reportId);
    if (!report) throw new ApiError(404, 'Informe no encontrado');
    // El simulador no inventa enlaces: un informe sembrado no tiene vínculo hasta que el administrador lo escribe.
    const link = this.reportMeta.get(reportId) ?? '';
    return { internalId: report.id, name: report.name, description: report.description, link, ref: report.id };
  }

  async getViewerConfig(reportId: string): Promise<ViewerConfig> {
    const user = this.requireUser();
    const report = this.seed.reports.find(r => r.id === reportId);
    const allowed = user.role === 'admin' ? Boolean(report) : Boolean(report) && this.effectiveReports(user).some(r => r.id === reportId);
    if (!report || !allowed || this.scenario === 'forbidden') throw new ApiError(404, 'Reporte no encontrado');
    return { mode: 'simulated' };
  }

  async createReport(input: ReportInput): Promise<void> {
    this.requireAdmin();
    this.validateReport(input);
    const id = `rep-${this.seed.reports.length + 1}`;
    this.seed.reports.push({ id, name: input.name.trim(), description: input.description.trim(), companyId: '', companyIds: [], group: 'Sin grupo', active: true });
    this.reportMeta.set(id, input.link.trim());
  }

  async updateReport(internalId: string, input: ReportInput): Promise<void> {
    this.requireAdmin();
    const report = this.seed.reports.find(r => r.id === internalId);
    if (!report) throw new ApiError(422, 'Reporte no encontrado');
    this.validateReport(input);
    Object.assign(report, { name: input.name.trim(), description: input.description.trim() });
    // La identidad del informe no cambia al editar el vínculo (los accesos y las asociaciones la referencian).
    this.reportMeta.set(internalId, input.link.trim());
  }

  async listReportLinks(): Promise<ReportLink[]> {
    this.requireAdmin();
    return this.seed.reports.flatMap(r => r.companyIds.map(companyId => ({ id: `${r.id}@${companyId}`, companyId, reportId: r.id })));
  }

  async linkReports({ companyId, reportIds }: LinkRequest): Promise<GrantResult[]> {
    this.requireAdmin();
    const company = this.seed.companies.find(c => c.id === companyId);
    if (!company?.active) throw new ApiError(422, 'Empresa no encontrada');
    return reportIds.map((reportId): GrantResult => {
      const report = this.seed.reports.find(r => r.id === reportId);
      if (!report?.active) return { reportId, status: 'failed', message: 'El informe no existe o está inactivo.' };
      if (report.companyIds.includes(companyId)) return { reportId, status: 'already', message: 'El informe ya estaba asociado a la empresa.' };
      report.companyIds = [...report.companyIds, companyId].sort((a, b) => (this.seed.companies.find(c => c.id === a)?.name ?? '').localeCompare(this.seed.companies.find(c => c.id === b)?.name ?? '', 'es'));
      report.companyId = report.companyIds[0] ?? '';
      return { reportId, status: 'granted', message: 'Informe asociado.' };
    });
  }

  async unlinkReport(linkId: string): Promise<void> {
    this.requireAdmin();
    const [reportId, companyId] = linkId.split('@');
    const report = this.seed.reports.find(r => r.id === reportId);
    if (!report || !companyId || !report.companyIds.includes(companyId)) throw new ApiError(422, 'Asignación no encontrada o no se pudo eliminar');
    report.companyIds = report.companyIds.filter(id => id !== companyId);
    report.companyId = report.companyIds[0] ?? '';
  }

  private validateCompany(input: CompanyInput, selfId: string | null): void {
    if (!input.name.trim() || !input.legalName.trim() || !input.taxId.trim()) throw new ApiError(422, 'Complete nombre, razón social y RUC');
    if (!/^\d{11}$/.test(input.taxId.trim())) throw new ApiError(422, 'El RUC debe tener 11 dígitos');
    if (this.seed.companies.some(c => c.taxId === input.taxId.trim() && c.id !== selfId)) throw new ApiError(422, 'El RUC ya está registrado');
  }

  private validateReport(input: ReportInput): void {
    if (!input.name.trim()) throw new ApiError(422, "El campo 'nombre' es obligatorio");
    const link = classifyPowerBiLink(input.link);
    if (!link.ok) throw new ApiError(422, link.error);
  }

  async revokeAssignment(assignmentId: string): Promise<void> {
    this.requireAdmin();
    const assignment = this.seed.assignments.find(a => a.id === assignmentId && a.active !== false);
    if (!assignment) throw new ApiError(422, 'Relación no encontrada o ya inactiva');
    // Baja lógica, como el servidor: la fila se conserva y puede reactivarse.
    assignment.active = false;
    this.record('user_empresa_reporte.revoke', 'user_empresa_reporte', assignmentId, { activo: 1 }, { activo: 0 });
  }

  /* --- UX-08B.2: baja lógica, reactivación e historial (mismas reglas que el servidor) --- */
  private record(action: string, entity: string, entityId: string, before: Record<string, unknown> | null, after: Record<string, unknown> | null): void {
    this.auditLog.unshift({ id: String(++this.auditSeq), at: new Date().toISOString().slice(0, 19).replace('T', ' '), correlationId: `sim${String(this.auditSeq).padStart(29, '0')}`, actor: this.requireAdmin().displayName, action, entity, entityId, before, after });
  }

  async deactivateCompany(id: string): Promise<void> {
    this.requireAdmin();
    const company = this.seed.companies.find(c => c.id === id);
    if (!company?.active) throw new ApiError(404, 'Empresa no encontrada');
    company.active = false;
    this.record('empresa.deactivate', 'empresa', id, { activo: 1 }, { activo: 0 });
  }

  async reactivateCompany(id: string): Promise<void> {
    this.requireAdmin();
    const company = this.seed.companies.find(c => c.id === id);
    if (!company) throw new ApiError(404, 'Empresa no encontrada');
    if (company.active) throw new ApiError(409, 'La empresa ya está activa');
    company.active = true;
    this.record('empresa.reactivate', 'empresa', id, { activo: 0 }, { activo: 1 });
  }

  async deactivateReport(internalId: string): Promise<void> {
    this.requireAdmin();
    const report = this.seed.reports.find(r => r.id === internalId);
    if (!report?.active) throw new ApiError(404, 'Reporte no encontrado');
    report.active = false;
    this.record('reporte.deactivate', 'reporte', internalId, { activo: 1 }, { activo: 0 });
  }

  async reactivateReport(internalId: string): Promise<void> {
    this.requireAdmin();
    const report = this.seed.reports.find(r => r.id === internalId);
    if (!report) throw new ApiError(404, 'Reporte no encontrado');
    if (report.active) throw new ApiError(409, 'El reporte ya está activo');
    report.active = true;
    this.record('reporte.reactivate', 'reporte', internalId, { activo: 0 }, { activo: 1 });
  }

  async reactivateAssignment(assignmentId: string): Promise<void> {
    this.requireAdmin();
    const assignment = this.seed.assignments.find(a => a.id === assignmentId);
    if (!assignment) throw new ApiError(404, 'Relación no encontrada');
    if (assignment.active !== false) throw new ApiError(409, 'El acceso ya está activo');
    const user = this.seed.users.find(u => u.id === assignment.userId);
    const report = this.seed.reports.find(r => r.id === assignment.reportId);
    const company = this.seed.companies.find(c => c.id === (assignment.companyId ?? report?.companyId));
    if (!user?.active) throw new ApiError(409, 'El usuario está inactivo');
    if (!company?.active) throw new ApiError(409, 'La empresa está inactiva');
    if (!report?.active) throw new ApiError(409, 'El informe está inactivo');
    if (!report.companyIds.includes(company.id) || !user.companyIds.includes(company.id)) throw new ApiError(409, 'Falta una relación intermedia activa (usuario–empresa o empresa–informe)');
    assignment.active = true;
    this.record('user_empresa_reporte.reactivate', 'user_empresa_reporte', assignmentId, { activo: 0 }, { activo: 1 });
  }

  async listAudit({ entity, page }: AuditQuery): Promise<AuditPage> {
    this.requireAdmin();
    const access = ['user_empresa_reporte', 'empresa_usuario', 'empresa_reporte'];
    const rows = this.auditLog.filter(e => (entity === 'access' ? access.includes(e.entity) : e.entity === entity));
    return { entries: rows.slice((page - 1) * 25, page * 25).map(e => ({ ...e })), total: rows.length, available: true };
  }

  async updateProfile({ displayName, phone }: ProfileUpdate): Promise<User> {
    const user = this.requireUser();
    if (!displayName.trim()) throw new ApiError(400, 'Ingrese su nombre.');
    user.displayName = displayName.trim();
    user.phone = phone.trim();
    return { ...user };
  }

  /* --- Internos --- */
  private requireUser(): User {
    const token = this.tokens.get();
    const userId = token ? this.sessions.get(token) : undefined;
    const user = userId ? this.seed.users.find(u => u.id === userId) : undefined;
    if (!user || !user.active) throw new ApiError(401, 'No autorizado. Token inválido o expirado.');
    return user;
  }

  private requireAdmin(): User {
    const user = this.requireUser();
    if (user.role !== 'admin') throw new ApiError(403, 'Acceso denegado');
    return user;
  }

  private effectiveReports(user: User): Report[] {
    const { users, reports, companies } = this.seed;
    return this.seed.assignments
      .filter(a => a.userId === user.id && assignmentStatus(a, users, reports, companies).ok)
      .map(a => reports.find(r => r.id === a.reportId))
      .filter((r): r is Report => Boolean(r));
  }
}
