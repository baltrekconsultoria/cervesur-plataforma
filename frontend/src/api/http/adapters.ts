import { ApiError } from '../errors';
import type { AuditEntry, Assignment, Company, CompanyDetail, CompanyInput, Report, ReportDetail, ReportInput, ReportLink, Role, User, ViewerConfig } from '../types';

/**
 * Adaptadores entre el JSON REAL del backend PHP (verificado contra el código en UX-06A/06B.0, no solo contra
 * API_DOCUMENTATION.md) y el modelo normalizado del frontend.
 *
 * Política de roles (UX-06A):
 *  - `admin`            → admin
 *  - `user`             → user
 *  - `usuario` (heredado) → user
 *  - cualquier otro valor (incluidos mayúsculas distintas, `superadmin`, vacío) → se RECHAZA con
 *    ApiError `invalid_response`; nunca se degrada en silencio a usuario válido.
 *
 * Las respuestas se piden con `?view=minimal` (UX-06B.0): el servidor ya no envía `enlace`, `created_by*`,
 * `powerbi_*` ni datos de contacto. Aun así los adaptadores copian SOLO los campos que necesitan.
 */
export interface UserDto { id: number | string; username: string; email: string; first_name?: string | null; last_name?: string | null; phone?: string | null; role: unknown; is_active?: boolean | number | string | null }
/** Fila de GET /api/reportes?view=minimal. `id` es la identidad (entero de MySQL); `reporte_id` es la referencia histórica y NO es única ni UUID. */
export interface ReportDto { id?: number | string; reporte_id?: string | null; nombre: string; descripcion?: string | null; activo?: boolean | number | string | null }
export interface CompanyDto { id: number | string; nombre: string; ciudad?: string | null; ruc?: string | null; activo?: boolean | number | string | null }
/** Fila de GET /api/asignacion?view=minimal: relación empresa–informe (`reporte_id` es el id NUMÉRICO del informe). */
export interface AssignmentDto { id?: number | string; empresa_id: number | string; reporte_id: number | string; activo?: boolean | number | string | null }

/** GET /api/empresas/{id}?view=form (solo administradores): datos editables, sin Power BI. */
export interface CompanyFormDto { id: number | string; version?: string | null; nombre: string; razon_social?: string | null; ruc?: string | null; email?: string | null; telefono?: string | null; direccion?: string | null; ciudad?: string | null; pais?: string | null }
/** GET /api/reportes/{id}?view=form (solo administradores). `reporte_id` es la referencia histórica (hasta 50 caracteres, no necesariamente UUID). */
export interface ReportFormDto { id: number | string; version?: string | null; nombre: string; descripcion?: string | null; enlace?: string | null; grupo_id?: string | null; reporte_id?: string | null }

/** Fila de GET /api/empresa-usuario?view=minimal. */
export interface CompanyLinkDto { id: number | string; user_id: number | string; empresa_id: number | string }
/** Fila de GET /api/empresa-reporte-usuario?view=minimal: `reporte_id` es la referencia HISTÓRICA del informe (no sirve para unir); `empresa_reporte_id` es el id de la relación empresa–informe. */
export interface AccessDto { id: number | string; user_id: number | string; empresa_id: number | string; empresa_reporte_id?: number | string; reporte_id?: string | null; activo?: boolean | number | string | null }

/** Identificador numérico canónico de MySQL (INT UNSIGNED): entero positivo, sin signo, decimales ni ceros a la izquierda. Devuelve la cadena o null. */
const MAX_NUMERIC_ID = 4294967295;
export function numericId(value: unknown): string | null {
  const text = typeof value === 'number' && Number.isSafeInteger(value) ? String(value) : value;
  return typeof text === 'string' && /^[1-9]\d{0,9}$/.test(text) && Number(text) <= MAX_NUMERIC_ID ? text : null;
}

const invalid = (message: string) => new ApiError(502, message, 'invalid_response');

export function parseRole(value: unknown): Role {
  if (value === 'admin') return 'admin';
  if (value === 'user' || value === 'usuario') return 'user';
  throw invalid('El servicio devolvió un rol no reconocido. Se rechazó la sesión por seguridad.');
}

/**
 * Rol de un usuario del directorio administrativo. Refleja la política del backend (SEC-05A: recorte de espacios y minúsculas;
 * `usuario` es alias de `user`). Un valor no reconocido NO se rechaza aquí (el administrador debe verlo para corregirlo):
 * se marca `loginBlocked` porque el servidor no le permitirá iniciar sesión.
 */
export function directoryRole(value: unknown): { role: Role; blocked: boolean } {
  const normalized = typeof value === 'string' ? value.trim().toLowerCase() : '';
  if (normalized === 'admin') return { role: 'admin', blocked: false };
  if (normalized === 'user' || normalized === 'usuario') return { role: 'user', blocked: false };
  return { role: 'user', blocked: true };
}

/** El API puede enviar 1/0, "1"/"0", true/false; ausente = activo. */
export function toActive(value: unknown): boolean {
  if (value === undefined || value === null) return true;
  return value === true || value === 1 || value === '1' || value === 'true';
}

const requireText = (value: unknown, field: string): string => {
  if (typeof value !== 'string' || !value.trim()) throw invalid(`Respuesta incompleta del servicio (${field}).`);
  return value;
};

export function toUser(dto: UserDto): User {
  if (dto === null || typeof dto !== 'object' || dto.id === undefined || dto.id === null) throw invalid('Respuesta de usuario incompleta.');
  const username = requireText(dto.username, 'username');
  const fullName = `${dto.first_name ?? ''} ${dto.last_name ?? ''}`.trim();
  return {
    id: String(dto.id),
    username,
    displayName: fullName || username,
    email: requireText(dto.email, 'email'),
    role: parseRole(dto.role),
    active: toActive(dto.is_active),
    phone: dto.phone ?? '',
    companyIds: []
  };
}

/** Usuario del listado administrativo (`GET /api/users`): tolera roles no reconocidos y no requiere correo. */
export function toDirectoryUser(dto: UserDto): User {
  if (dto === null || typeof dto !== 'object' || dto.id === undefined || dto.id === null) throw invalid('Respuesta de usuario incompleta.');
  const username = requireText(dto.username, 'username');
  const fullName = `${dto.first_name ?? ''} ${dto.last_name ?? ''}`.trim();
  const { role, blocked } = directoryRole(dto.role);
  return { id: String(dto.id), username, displayName: fullName || username, email: typeof dto.email === 'string' ? dto.email : '', role, active: toActive(dto.is_active), phone: dto.phone ?? '', companyIds: [], ...(blocked ? { loginBlocked: true } : {}) };
}

/**
 * Acceso (asignación individual). El servidor solo envía el `reporte_id` HISTÓRICO del informe, que no sirve para unir datos (no es único ni UUID);
 * el informe se resuelve por la relación empresa–informe (`empresa_reporte_id`) y su `reporte.id` numérico. Si la relación no está entre las
 * vigentes, el acceso no se puede relacionar con el catálogo y se descarta: no se adivina por coincidencia de textos.
 */
export function toAssignment(dto: AccessDto, reportIdByRelation: Map<string, string>): Assignment | null {
  if (!dto || dto.empresa_reporte_id === undefined || dto.empresa_reporte_id === null) return null;
  const reportId = reportIdByRelation.get(String(dto.empresa_reporte_id));
  if (!reportId) return null;
  return { id: String(dto.id), userId: String(dto.user_id), reportId, companyId: String(dto.empresa_id), ...(toActive(dto.activo) ? {} : { active: false }) };
}

/** Fila de GET /api/auditoria (solo administradores). El servidor no envía IP ni secretos. */
export interface AuditDto { id: number | string; occurred_at?: string; correlation_id?: string; actor?: string | null; actor_user_id?: number | string; action?: string; entity?: string; entity_id?: number | string; before?: Record<string, unknown> | null; after?: Record<string, unknown> | null }
export function toAuditEntry(dto: AuditDto): AuditEntry {
  if (!dto || typeof dto !== 'object' || dto.id === undefined || typeof dto.action !== 'string' || typeof dto.entity !== 'string') throw invalid('Respuesta del historial incompleta.');
  const object = (value: unknown) => (value && typeof value === 'object' && !Array.isArray(value) ? (value as Record<string, unknown>) : null);
  return { id: String(dto.id), at: dto.occurred_at ?? '', correlationId: dto.correlation_id ?? '', actor: dto.actor?.trim() || (dto.actor_user_id !== undefined ? `Usuario ${dto.actor_user_id}` : '—'), action: dto.action, entity: dto.entity, entityId: String(dto.entity_id ?? ''), before: object(dto.before), after: object(dto.after) };
}

export function toCompanyDetail(dto: CompanyFormDto): CompanyDetail {
  if (!dto || typeof dto !== 'object' || dto.id === undefined || dto.id === null) throw invalid('Respuesta de empresa incompleta.');
  return { id: String(dto.id), name: requireText(dto.nombre, 'nombre'), legalName: dto.razon_social ?? '', taxId: dto.ruc ?? '', city: dto.ciudad ?? '', country: dto.pais ?? '', email: dto.email ?? '', phone: dto.telefono ?? '', address: dto.direccion ?? '', ...(dto.version ? { version: dto.version } : {}) };
}

export function toReportDetail(dto: ReportFormDto): ReportDetail {
  if (!dto || typeof dto !== 'object' || dto.id === undefined || dto.id === null) throw invalid('Respuesta de informe incompleta.');
  const internalId = numericId(dto.id);
  if (!internalId) throw invalid('Respuesta de informe con identificador no válido.');
  return { internalId, name: requireText(dto.nombre, 'nombre'), description: dto.descripcion ?? '', link: dto.enlace ?? '', ref: dto.reporte_id ?? '', ...(dto.version ? { version: dto.version } : {}) };
}

/** Asociación empresa ↔ informe vigente. `reporte_id` de /api/asignacion ya es el `reporte.id` NUMÉRICO: no hace falta ninguna tabla de conversión. */
export function toReportLink(dto: AssignmentDto): ReportLink | null {
  const reportId = numericId(dto.reporte_id);
  if (dto.id === undefined || !reportId || !toActive(dto.activo)) return null;
  return { id: String(dto.id), companyId: String(dto.empresa_id), reportId };
}

/**
 * Informe sin empresa (la relación se compone aparte). La identidad es `reporte.id`; `reporte_id` se conserva solo como referencia histórica
 * (`ref`), sin importar su formato ni su longitud. Un `id` ausente o no válido es una violación del contrato: se falla, no se descarta en silencio.
 */
export function toReport(dto: ReportDto): Report {
  const id = numericId(dto?.id);
  if (!id) throw invalid('Respuesta de informe con identificador no válido.');
  return {
    id,
    ...(typeof dto.reporte_id === 'string' && dto.reporte_id ? { ref: dto.reporte_id } : {}),
    name: dto.nombre,
    description: dto.descripcion ?? '',
    companyId: '',
    companyIds: [],
    group: '',
    active: toActive(dto.activo)
  };
}

export function toCompany(dto: CompanyDto): Company {
  return { id: String(dto.id), name: dto.nombre, city: dto.ciudad ?? '', taxId: dto.ruc ?? '', active: toActive(dto.activo) };
}

/**
 * Catálogo provisional (UX-06B.0): une /api/reportes + /api/asignacion + /api/empresas.
 *  - Solo cuentan relaciones activas cuyo informe Y cuya empresa fueron autorizados por PHP en sus propios listados.
 *  - Un informe vinculado a varias empresas aparece UNA vez, con todas sus empresas (`companyIds`, orden alfabético).
 *  - Los duplicados (mismo `reporte_id` o mismo par empresa–informe) se colapsan.
 *  - Un informe sin ninguna relación visible se conserva con `companyIds: []` (se muestra, pero no entra en el
 *    filtro por empresa); nunca se le inventa empresa ni grupo.
 */
export function composeReportCatalog(reports: ReportDto[], assignments: AssignmentDto[], companies: Company[]): Report[] {
  const companyById = new Map(companies.map(c => [c.id, c]));
  const pairs = new Map<string, Set<string>>();
  for (const a of assignments) {
    if (!toActive(a.activo)) continue;
    const company = String(a.empresa_id);
    if (!companyById.has(company)) continue;
    const key = String(a.reporte_id);
    (pairs.get(key) ?? pairs.set(key, new Set()).get(key)!).add(company);
  }
  const seen = new Set<string>();
  const catalog: Report[] = [];
  for (const dto of reports) {
    const report = toReport(dto);
    if (seen.has(report.id)) continue;
    seen.add(report.id);
    const linked = [...(pairs.get(report.id) ?? [])]
      .sort((a, b) => (companyById.get(a)?.name ?? '').localeCompare(companyById.get(b)?.name ?? '', 'es') || a.localeCompare(b));
    catalog.push({ ...report, companyIds: linked, companyId: linked[0] ?? '' });
  }
  return catalog;
}

/** Cuerpo de empresa: solo datos no sensibles. Al crear se omiten los opcionales vacíos; al editar se envían todos (un campo vacío lo borra). */
export function companyBody(input: CompanyInput, editing: boolean): Record<string, string> {
  const body: Record<string, string> = { nombre: input.name.trim(), razon_social: input.legalName.trim(), ruc: input.taxId.trim() };
  const optional: Record<string, string> = { ciudad: input.city, pais: input.country, email: input.email, telefono: input.phone, direccion: input.address };
  for (const [key, value] of Object.entries(optional)) if (editing || value.trim()) body[key] = value.trim();
  if (editing && input.version) body.version = input.version;
  return body;
}

export const reportBody = (input: ReportInput): Record<string, string> => ({ nombre: input.name.trim(), descripcion: input.description.trim(), enlace: input.link.trim(), ...(input.version ? { version: input.version } : {}) });

/** GET /api/reportes/id/{id}/visor: configuración autorizada del visor (por el id numérico del informe). */
export interface ViewerDto { modo?: unknown; url?: unknown }
export function toViewerConfig(dto: ViewerDto | null | undefined): ViewerConfig {
  if (!dto || typeof dto !== 'object') throw invalid('Respuesta del visor incompleta.');
  if (dto.modo === 'publico' && typeof dto.url === 'string' && dto.url) return { mode: 'public', url: dto.url };
  if (dto.modo === 'privado') return { mode: 'private' };
  if (dto.modo === 'no_compatible') return { mode: 'unsupported' };
  throw invalid('Respuesta del visor inesperada.');
}
