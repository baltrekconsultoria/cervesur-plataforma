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
  /** El servidor entrega el enlace de este informe en el visor (autorizado para el piloto). Ausente si el proveedor no lo informa; solo un indicador, nunca el enlace. */
  linkAuthorized?: boolean;
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
  /** UX-08H: el servidor tiene un enlace configurado pero NO lo muestra (solo se escribe). Si está en `true`, dejar el campo vacío conserva el vigente. */
  linkConfigured?: boolean;
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

export type ApiErrorKind = 'unauthorized' | 'forbidden' | 'not_found' | 'validation' | 'conflict' | 'network' | 'server' | 'invalid_response' | 'not_integrated';

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
  /** UX-08H (modo `admin_escritura`): qué grupos de escritura tiene HABILITADOS el servidor. Sin este campo rige `adminWrites` para todo. */
  writes?: WriteCapabilities;
  /** UX-08H fase 2B: el servidor permite cambiar rol y contraseña (interruptor aparte). Sin este campo (o en `false`) la interfaz no ofrece «Cambiar rol». */
  usersSensitive?: boolean;
  /** P5-R: solo restablecer contrasena de cuentas estandar; independiente de P5 roles. Fail-closed si falta. */
  usersPasswordReset?: boolean;
  /** UX-08I (P4a): en el modo `admin_escritura` el servidor no permite cambiar el correo de un usuario (la cuenta de React no tiene UPDATE(email)); el formulario lo muestra solo como referencia. */
  emailReadOnly?: boolean;
  /** UX-08H fase 2B: horas que deben pasar entre desactivar y reactivar a un usuario (protege sesiones antiguas de Flutter). */
  reactivateAfterHours?: number;
  /** El visor consulta al servidor la configuración autorizada de cada informe (en lugar de mostrar el panel simulado). */
  liveViewer: boolean;
}

/** Grupos de escritura que el servidor puede habilitar de forma independiente (UX-08H). */
export interface WriteCapabilities {
  empresas: boolean;
  reportes: boolean;
  accesos: boolean;
  usuarios: boolean;
}
export type WriteGroup = keyof WriteCapabilities;

/** ¿Puede la interfaz ofrecer acciones de escritura de este grupo? (el servidor las vuelve a comprobar siempre) */
export function canWrite(capabilities: ApiCapabilities, group: WriteGroup): boolean {
  return capabilities.adminWrites && (capabilities.writes ? capabilities.writes[group] === true : true);
}

/** Contrato único que implementan el proveedor simulado y el proveedor HTTP. */
export interface ApiClient {
  readonly capabilities: ApiCapabilities;
  /** UX-08H: lee del servidor qué escrituras están habilitadas (solo el cliente HTTP del modo de escritura; el simulado ya las tiene todas). */
  loadCapabilities?(): Promise<void>;
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
  /** UX-08B.2 · Solo administradores. Baja lógica y reactivación; el servidor rechaza con 409 lo que ya está en ese estado o tiene dependencias inactivas. */
  deactivateCompany(id: string): Promise<void>;
  reactivateCompany(id: string): Promise<void>;
  deactivateReport(internalId: string): Promise<void>;
  reactivateReport(internalId: string): Promise<void>;
  /** Reactiva un acceso revocado (el servidor exige que usuario, empresa, informe y relaciones intermedias estén activos). */
  reactivateAssignment(assignmentId: string): Promise<void>;
  /** Historial de operaciones administrativas (auditoría), de la más reciente a la más antigua. */
  listAudit(query: AuditQuery): Promise<AuditPage>;
  /** UX-08B.3 · `true` si el servidor tiene habilitada la administración de usuarios (`USER_ADMIN_WRITES`). Sin eso no se muestran los botones. */
  getUserAdminEnabled(): Promise<boolean>;
  /** Solo administradores. Datos editables del usuario con la versión de la fila (bloqueo optimista). Nunca trae contraseña ni versión de sesión. */
  getUserDetail(id: string): Promise<UserDetail>;
  updateUser(id: string, input: UserEdit): Promise<void>;
  /** Baja lógica: el servidor además cierra sus sesiones. Rechaza (409) desactivarse a uno mismo o al último administrador activo. */
  deactivateUser(id: string): Promise<void>;
  reactivateUser(id: string): Promise<void>;
  /** Cambio de rol; el servidor rechaza (409) cambiar el propio o degradar al último administrador activo, y cierra las sesiones del usuario. */
  changeUserRole(id: string, role: Role): Promise<void>;
  /** P5-R: solo cuentas estandar activas; el servidor debe volver a validar rol, actor y auditoria. */
  resetUserPassword?(id: string, password: string): Promise<void>;
  /** Empresas e informes del usuario con su efectividad (solo lectura). */
  getUserAccess(id: string): Promise<UserAccess[]>;
}

/** Datos de un usuario para el formulario de edición. */
export interface UserDetail {
  id: string;
  username: string;
  email: string;
  firstName: string;
  lastName: string;
  phone: string;
  role: Role;
  active: boolean;
  /** Huella de la fila leída: si cambió cuando se guarde, el servidor responde 409. */
  version: string;
}

/** Campos que un administrador puede editar. El rol, el estado y las sesiones tienen operaciones propias. */
export interface UserEdit { firstName: string; lastName: string; phone: string; email: string; version: string }

/** Una empresa o un informe del usuario y si surte efecto hoy. */
export interface UserAccess {
  kind: 'company' | 'report';
  name: string;
  /** Empresa a través de la cual se concede un informe. */
  via?: string;
  effective: boolean;
  /** Motivo cuando no surte efecto (usuario/empresa/informe inactivo, falta una relación…). */
  reason: string;
}

/** Entidades que admite el historial. `access` agrupa las tres relaciones (usuario↔empresa, empresa↔informe, usuario↔informe). */
export type AuditEntity = 'access' | 'user' | 'empresa' | 'reporte' | 'empresa_usuario' | 'empresa_reporte' | 'user_empresa_reporte';

export interface AuditQuery { entity: AuditEntity; page: number }

export interface AuditEntry {
  id: string;
  /** Fecha y hora del servidor (texto tal como llega). */
  at: string;
  correlationId: string;
  actor: string;
  action: string;
  entity: string;
  entityId: string;
  before: Record<string, unknown> | null;
  after: Record<string, unknown> | null;
}

export interface AuditPage {
  entries: AuditEntry[];
  total: number;
  /** `false` si el servidor aún no tiene la bitácora (migración sin aplicar). */
  available: boolean;
}

/** Almacén del token. La implementación por defecto vive solo en memoria. */
export interface TokenStore {
  get(): string | null;
  set(token: string | null): void;
}
