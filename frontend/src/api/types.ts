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

/**
 * Informe del catálogo. No incluye el vínculo de visualización del backend.
 * IDENTIDAD (UX-07D.2B): `id` es la identidad operativa y estable del informe. En el proveedor HTTP es `reporte.id` (entero positivo de MySQL,
 * como cadena); se usa en rutas, selección, permisos y llamadas al visor. `ref` es la referencia histórica `reporte_id` de la base (en
 * producción NO es un UUID: puede tener hasta 50 caracteres y otros formatos): solo informativa, nunca se usa para enrutar ni para unir datos.
 */
export interface Report {
  id: string;
  /** Referencia histórica (`reporte_id`). Ausente si el servidor no la envía; jamás se inventa. */
  ref?: string;
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
  /** Id del informe en el modelo del frontend (`Report.id`; en el proveedor HTTP, el `reporte.id` numérico). */
  reportId: string;
  /** Empresa a través de la cual se concede el acceso. */
  companyId?: string;
  /** `false` si el acceso fue revocado (baja lógica). Ausente = activo. Solo llega con `ListScope` `all`. */
  active?: boolean;
}

/**
 * Alcance de un listado: `active` (por defecto) solo trae registros activos; `all` (UX-08A, solo administradores)
 * trae también los inactivos para poder filtrarlos en las pantallas administrativas.
 */
export type ListScope = 'active' | 'all';

/** Alta de usuario. El rol, el estado y la versión de sesión los fija siempre el backend. */
export interface NewUser {
  firstName: string;
  lastName: string;
  username: string;
  email: string;
  phone: string;
  password: string;
}

/** Datos editables de una empresa. No incluye credenciales de Power BI (se gestionarán en otra etapa). */
export interface CompanyInput {
  name: string;
  legalName: string;
  /** RUC: 11 dígitos. */
  taxId: string;
  city: string;
  country: string;
  email: string;
  phone: string;
  address: string;
  /** Versión de la fila que leyó el formulario (bloqueo optimista). Solo viaja en ediciones; si cambió, el servidor responde 409. */
  version?: string;
}
export interface CompanyDetail extends CompanyInput { id: string }

/**
 * Alta o edición de un informe (UX-07C): solo nombre, descripción y enlace de Power BI.
 * Los identificadores de Power BI, cuando el enlace los trae, los extrae y valida el servidor; nunca se piden ni se inventan.
 */
export interface ReportInput {
  name: string;
  description: string;
  /** Enlace de Power BI (publicación web, inserción o enlace normal). Solo lo ve un administrador. */
  link: string;
  /** Versión de la fila que leyó el formulario (bloqueo optimista). Solo viaja en ediciones; si cambió, el servidor responde 409. */
  version?: string;
}
export interface ReportDetail extends ReportInput {
  /** Id numérico interno (MySQL): identidad estable del informe (igual a `Report.id` en el proveedor HTTP), necesaria para guardar cambios. */
  internalId: string;
  /** Referencia histórica `reporte_id` (puede no ser UUID ni tener 36 caracteres). Solo informativa; editar el informe no la modifica. */
  ref: string;
}

/**
 * Configuración autorizada del visor de un informe.
 *  - simulated: proveedor simulado (sin servicios externos).
 *  - public: informe publicado en web; `url` es su enlace de publicación (el visor lo revalida y reconstruye).
 *  - private: inserción autenticada de Power BI; requiere una configuración de acceso que aún no existe.
 *  - unsupported: el enlace registrado no es compatible con el visor.
 */
export interface ViewerConfig {
  mode: 'simulated' | 'public' | 'private' | 'unsupported';
  url?: string;
}

/**
 * Asociación empresa ↔ informe (tabla `empresa_reporte`). `id` es el id de LA RELACIÓN (no el del informe);
 * `reportId` es `Report.id` (el `reporte.id` numérico en el proveedor HTTP).
 */
export interface ReportLink {
  id: string;
  companyId: string;
  reportId: string;
}

export interface LinkRequest {
  companyId: string;
  /** `Report.id` de cada informe a asociar. */
  reportIds: string[];
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
  /** El visor consulta al servidor la configuración autorizada de cada informe (en lugar de mostrar el panel simulado). */
  liveViewer: boolean;
}

/** Contrato único que implementan el proveedor simulado y el proveedor HTTP. */
export interface ApiClient {
  readonly capabilities: ApiCapabilities;
  login(request: LoginRequest): Promise<AuthResponse>;
  logout(): Promise<void>;
  me(): Promise<User>;
  /** Informes que el usuario autenticado puede consultar (el servidor decide). */
  listReports(scope?: ListScope): Promise<Report[]>;
  /** 403 y 404 se tratan igual en la interfaz para no revelar la existencia del recurso. */
  getReport(id: string): Promise<Report>;
  listCompanies(scope?: ListScope): Promise<Company[]>;
  /** Solo administradores. */
  listUsers(): Promise<User[]>;
  /** Solo administradores. */
  listAssignments(scope?: ListScope): Promise<Assignment[]>;
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
  /** Solo administradores (UX-07B). */
  getCompanyDetail(id: string): Promise<CompanyDetail>;
  createCompany(input: CompanyInput): Promise<void>;
  updateCompany(id: string, input: CompanyInput): Promise<void>;
  getReportDetail(reportId: string): Promise<ReportDetail>;
  /** Configuración del visor; el servidor la entrega solo si el usuario está autorizado (403/404 se tratan igual). */
  getViewerConfig(reportId: string): Promise<ViewerConfig>;
  createReport(input: ReportInput): Promise<void>;
  updateReport(internalId: string, input: ReportInput): Promise<void>;
  /** Asociaciones empresa ↔ informe vigentes. */
  listReportLinks(): Promise<ReportLink[]>;
  /** Asocia informes a una empresa. Un resultado por informe; nunca figura como asociado algo que el servidor rechazó. */
  linkReports(request: LinkRequest): Promise<GrantResult[]>;
  /** Desvincula (baja lógica) una asociación por su id. */
  unlinkReport(linkId: string): Promise<void>;
}

/** Almacén del token. La implementación por defecto vive solo en memoria. */
export interface TokenStore {
  get(): string | null;
  set(token: string | null): void;
}
