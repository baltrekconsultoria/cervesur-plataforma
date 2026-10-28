import type { Assignment, Company, Report, User } from '../api/types';

export interface AssignmentStatus {
  ok: boolean;
  reason: string;
}

/**
 * Regla de negocio del prototipo: una asignación solo surte efecto si el usuario
 * y el informe están activos, la empresa está activa y el usuario sigue
 * relacionado con ella. Es informativa para la interfaz; la autorización real
 * la decide siempre el backend.
 */
export function assignmentStatus(a: Assignment, users: User[], reports: Report[], companies: Company[]): AssignmentStatus {
  const user = users.find(u => u.id === a.userId);
  const report = reports.find(r => r.id === a.reportId);
  const company = report && companies.find(c => c.id === report.companyId);
  if (!user?.active) return { ok: false, reason: 'Usuario inactivo' };
  if (!report?.active) return { ok: false, reason: 'Informe inactivo' };
  if (!company?.active) return { ok: false, reason: 'Empresa inactiva' };
  if (!user.companyIds.includes(report.companyId)) return { ok: false, reason: 'Sin relación con la empresa' };
  return { ok: true, reason: 'Vigente' };
}
