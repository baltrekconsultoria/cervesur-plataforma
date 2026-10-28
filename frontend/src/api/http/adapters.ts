import { ApiError } from '../errors';
import type { Assignment, Company, Report, Role, User } from '../types';

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
export interface ReportDto { id?: number | string; reporte_id?: string | null; nombre: string; descripcion?: string | null; activo?: boolean | number | string | null }
export interface CompanyDto { id: number | string; nombre: string; ciudad?: string | null; ruc?: string | null; activo?: boolean | number | string | null }
/** Fila de GET /api/asignacion?view=minimal: relación empresa–informe (`reporte_id` es el id NUMÉRICO del informe). */
export interface AssignmentDto { id?: number | string; empresa_id: number | string; reporte_id: number | string; activo?: boolean | number | string | null }

/** Fila de GET /api/empresa-usuario?view=minimal. */
export interface CompanyLinkDto { id: number | string; user_id: number | string; empresa_id: number | string }
/** Fila de GET /api/empresa-reporte-usuario?view=minimal: `reporte_id` es el UUID de Power BI; `empresa_reporte_id` el id de la relación empresa–informe. */
export interface AccessDto { id: number | string; user_id: number | string; empresa_id: number | string; empresa_reporte_id?: number | string; reporte_id?: string | null }

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

/** Acceso efectivo. Sin UUID del informe no se puede relacionar con el catálogo: se descarta. */
export function toAssignment(dto: AccessDto): Assignment | null {
  if (!dto || typeof dto.reporte_id !== 'string' || !dto.reporte_id) return null;
  return { id: String(dto.id), userId: String(dto.user_id), reportId: dto.reporte_id, companyId: String(dto.empresa_id) };
}

/** Informe sin empresa (la relación se compone aparte). Devuelve null si falta `reporte_id` (no se podría abrir). */
export function toReport(dto: ReportDto): Report | null {
  if (!dto || typeof dto.reporte_id !== 'string' || !dto.reporte_id) return null;
  return {
    id: dto.reporte_id,
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
    if (!report || seen.has(report.id)) continue;
    seen.add(report.id);
    const linked = [...(pairs.get(String(dto.id)) ?? [])]
      .sort((a, b) => (companyById.get(a)?.name ?? '').localeCompare(companyById.get(b)?.name ?? '', 'es') || a.localeCompare(b));
    catalog.push({ ...report, companyIds: linked, companyId: linked[0] ?? '' });
  }
  return catalog;
}
