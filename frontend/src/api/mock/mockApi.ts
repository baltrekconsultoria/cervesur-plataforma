import { assignmentStatus } from '../../domain/access';
import { ApiError } from '../errors';
import type { ApiCapabilities, ApiClient, AuthResponse, Assignment, Company, GrantRequest, GrantResult, LoginRequest, NewUser, ProfileUpdate, Report, TokenStore, User } from '../types';
import { createSeed, type Seed } from './seed';

export type MockScenario = 'normal' | 'empty' | 'forbidden';

/**
 * Proveedor simulado. Hace de "servidor": valida el token, comprueba el rol y
 * decide la autorización de cada recurso, igual que debe hacerlo el backend.
 * La interfaz nunca confía en sus propias comprobaciones de rol.
 * Las credenciales no se validan: basta un usuario sintético existente y una contraseña no vacía.
 */
export class MockApi implements ApiClient {
  readonly capabilities: ApiCapabilities = { adminDirectory: true, reportCompany: true, reportGroups: true, profileEdit: true, adminWrites: true };
  private seed: Seed = createSeed();
  private sessions = new Map<string, string>();
  private scenario: MockScenario = 'normal';

  constructor(private readonly tokens: TokenStore) {}

  /* --- Controles de demostración (no forman parte del contrato ApiClient) --- */
  getScenario(): MockScenario { return this.scenario; }
  setScenario(scenario: MockScenario): void { this.scenario = scenario; }
  /** Invalida todas las sesiones: la siguiente petición responde 401. */
  expireSessions(): void { this.sessions.clear(); }
  reset(): void { this.seed = createSeed(); this.scenario = 'normal'; }

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
      if (this.seed.assignments.some(a => a.userId === userId && a.reportId === reportId && a.companyId === companyId)) return { reportId, status: 'already', message: 'El usuario ya tenía este acceso.' };
      this.seed.assignments.push({ id: `asg-${Math.random().toString(36).slice(2, 8)}`, userId, reportId, companyId });
      return { reportId, status: 'granted', message: 'Acceso concedido.' };
    });
  }

  async revokeAssignment(assignmentId: string): Promise<void> {
    this.requireAdmin();
    const index = this.seed.assignments.findIndex(a => a.id === assignmentId);
    if (index < 0) throw new ApiError(422, 'Relación no encontrada o ya inactiva');
    this.seed.assignments.splice(index, 1);
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
