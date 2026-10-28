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

export interface Assignment {
  userId: string;
  reportId: string;
}

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
}

/** Almacén del token. La implementación por defecto vive solo en memoria. */
export interface TokenStore {
  get(): string | null;
  set(token: string | null): void;
}
