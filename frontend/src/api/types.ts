/**
 * Contratos del frontend. Son el modelo *normalizado* de la aplicación; las
 * diferencias del API PHP (snake_case, roles, ids numéricos/UUID) se resuelven
 * en `api/http/adapters.ts`. Ningún tipo de este archivo contiene secretos,
 * enlaces de Power BI ni credenciales.
 */
export type Role = 'user' | 'admin';

export interface User {
  id: string;
  username: string;
  displayName: string;
  email: string;
  role: Role;
  active: boolean;
  phone: string;
  /** Empresas con relación vigente. El acceso a informes NO se deriva de esto. */
  companyIds: string[];
  /** El rol almacenado no es reconocido: el backend no le permite iniciar sesión (SEC-05A). Solo aparece en el directorio administrativo. */
  loginBlocked?: boolean;
}

export interface Company {
  id: string;
  name: string;
  city: string;
  /** RUC (identificador tributario). */
  taxId: string;
  active: boolean;
}

/** Informe del catálogo. No incluye el vínculo de visualización del backend: la visualización real usará un token de inserción limitado. */
export interface Report {
  id: string;
  name: string;
  description: string;
  /** Primera empresa (compatibilidad). Un informe puede pertenecer a varias: usar `companyIds`. */
  companyId: string;
  /** Todas las empresas autorizadas para este informe (orden alfabético). */
  companyIds: string[];
  group: string;
  active: boolean;
}

/** Acceso efectivo de un usuario a un informe a través de una empresa (user_empresa_reporte). */
export interface Assignment {
  /** Id de la relación en el servidor (necesario para retirarla). Ausente en datos que no lo traen. */
  id?: string;
  userId: string;
  /** Id del informe en el modelo del frontend (UUID de Power BI en el proveedor HTTP). */
  reportId: string;
  /** Empresa a través de la cual se concede el acceso. */
  companyId?: string;
}

/** Alta de usuario. El rol, el estado y la versión de sesión los fija siempre el backend. */
export interface NewUser {
  firstName: string;
  lastName: string;
  username: string;
  email: string;
  phone: string;
  password: string;
}

export interface GrantRequest {
  userId: string;
  companyId: string;
  reportIds: string[];
}

export type GrantStatus = 'granted' | 'already' | 'failed';
export interface GrantResult { reportId: string; status: GrantStatus; message: string }

export interface LoginRequest {
  /** Usuario o correo. */
  login: string;
  password: string;
}

export interface AuthResponse {
  token: string;
  user: User;
}

export interface ProfileUpdate {
  displayName: string;
  phone: string;
}

export type ApiErrorKind = 'unauthorized' | 'forbidden' | 'not_found' | 'validation' | 'network' | 'server' | 'invalid_response' | 'not_integrated';

/**
 * Qué datos entrega realmente cada proveedor. La interfaz se adapta a estas capacidades en lugar de
 * inventar valores: el proveedor HTTP actual no recibe empresa ni nombre de grupo por informe.
 */
export interface ApiCapabilities {
  /** Listados administrativos de usuarios y asignaciones. */
  adminDirectory: boolean;
  /** Cada informe indica a qué empresa pertenece. */
  reportCompany: boolean;
  /** Cada informe trae el nombre de su grupo. */
  reportGroups: boolean;
  /** El perfil puede actualizarse. */
  profileEdit: boolean;
  /** Alta de usuarios y concesión/retirada de accesos. */
  adminWrites: boolean;
}

/** Contrato único que implementan el proveedor simulado y el proveedor HTTP. */
export interface ApiClient {
  readonly capabilities: ApiCapabilities;
  login(request: LoginRequest): Promise<AuthResponse>;
  logout(): Promise<void>;
  me(): Promise<User>;
  /** Informes que el usuario autenticado puede consultar (el servidor decide). */
  listReports(): Promise<Report[]>;
  /** 403 y 404 se tratan igual en la interfaz para no revelar la existencia del recurso. */
  getReport(id: string): Promise<Report>;
  listCompanies(): Promise<Company[]>;
  /** Solo administradores. */
  listUsers(): Promise<User[]>;
  /** Solo administradores. */
  listAssignments(): Promise<Assignment[]>;
  updateProfile(update: ProfileUpdate): Promise<User>;
  /** Solo administradores. Resuelve únicamente si el servidor confirmó el alta. */
  createUser(input: NewUser): Promise<void>;
  /**
   * Solo administradores. Relaciona al usuario con la empresa si hace falta y le concede los informes indicados.
   * Devuelve un resultado por informe; nunca marca como concedido algo que el servidor rechazó.
   */
  grantAccess(request: GrantRequest): Promise<GrantResult[]>;
  /** Solo administradores. Retira (baja lógica) un acceso por el id de la relación. */
  revokeAssignment(assignmentId: string): Promise<void>;
}

/** Almacén del token. La implementación por defecto vive solo en memoria. */
export interface TokenStore {
  get(): string | null;
  set(token: string | null): void;
}
