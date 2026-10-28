import type { Assignment, Company, Report, User } from '../types';

/** Datos 100 % ficticios (mismo conjunto que el prototipo UX-04B). Dominio `ejemplo.test`. */
export interface Seed {
  companies: Company[];
  reports: Report[];
  users: User[];
  assignments: Assignment[];
}

export function createSeed(): Seed {
  const raw: Omit<Seed, 'reports'> & { reports: Array<Omit<Report, 'companyIds'>> } = {
    companies: [
      { id: 'emp-1', name: 'Andes Logística S.A.C.', city: 'Arequipa', taxId: '20987654321', active: true },
      { id: 'emp-2', name: 'Textiles del Sur S.A.C.', city: 'Lima', taxId: '20876543210', active: true },
      { id: 'emp-3', name: 'Agroexportadora Horizonte S.A.C.', city: 'Ica', taxId: '20765432109', active: true },
      { id: 'emp-4', name: 'Inmobiliaria Valle Azul S.A.C.', city: 'Lima', taxId: '20654321098', active: true },
      { id: 'emp-5', name: 'Servicios Pacífico S.A.C.', city: 'Callao', taxId: '20543210987', active: false }
    ],
    reports: [
      { id: 'rep-1', name: 'Ventas consolidadas', description: 'Seguimiento comercial mensual y acumulado.', companyId: 'emp-1', group: 'Comercial', active: true },
      { id: 'rep-2', name: 'Margen por canal', description: 'Análisis de rentabilidad por unidad de negocio.', companyId: 'emp-1', group: 'Finanzas', active: true },
      { id: 'rep-3', name: 'Inventario estratégico', description: 'Disponibilidad, rotación y cobertura de inventario.', companyId: 'emp-2', group: 'Operaciones', active: true },
      { id: 'rep-4', name: 'Cumplimiento de entregas', description: 'Nivel de servicio y pedidos atendidos.', companyId: 'emp-2', group: 'Operaciones', active: true },
      { id: 'rep-5', name: 'Flujo de caja', description: 'Proyección y ejecución financiera semanal.', companyId: 'emp-3', group: 'Finanzas', active: true },
      { id: 'rep-6', name: 'Ocupación de activos', description: 'Informe sin asignaciones para el usuario de demostración.', companyId: 'emp-4', group: 'Finanzas', active: true },
      { id: 'rep-7', name: 'Presupuesto anual', description: 'Informe retirado temporalmente del catálogo.', companyId: 'emp-3', group: 'Finanzas', active: false }
    ],
    users: [
      { id: 'usr-1', username: 'maria.rios', displayName: 'María Ríos', email: 'maria.rios@ejemplo.test', role: 'user', active: true, phone: '999 000 000', companyIds: ['emp-1', 'emp-2', 'emp-3'] },
      { id: 'usr-2', username: 'carlos.vega', displayName: 'Carlos Vega', email: 'carlos.vega@ejemplo.test', role: 'user', active: true, phone: '', companyIds: ['emp-1', 'emp-3', 'emp-4'] },
      { id: 'usr-3', username: 'elena.torres', displayName: 'Elena Torres', email: 'elena.torres@ejemplo.test', role: 'admin', active: true, phone: '988 111 222', companyIds: ['emp-1', 'emp-2', 'emp-3', 'emp-4', 'emp-5'] },
      { id: 'usr-4', username: 'jorge.pena', displayName: 'Jorge Peña', email: 'jorge.pena@ejemplo.test', role: 'user', active: false, phone: '', companyIds: ['emp-2'] }
    ],
    assignments: [
      { userId: 'usr-1', reportId: 'rep-1' }, { userId: 'usr-1', reportId: 'rep-2' }, { userId: 'usr-1', reportId: 'rep-3' },
      { userId: 'usr-1', reportId: 'rep-4' }, { userId: 'usr-1', reportId: 'rep-5' },
      { userId: 'usr-2', reportId: 'rep-1' }, { userId: 'usr-2', reportId: 'rep-5' }, { userId: 'usr-4', reportId: 'rep-3' }
    ]
  };
  return {
    ...raw,
    reports: raw.reports.map(r => ({ ...r, companyIds: [r.companyId] })),
    assignments: raw.assignments.map((a, i) => ({ ...a, id: `asg-${i + 1}`, companyId: raw.reports.find(r => r.id === a.reportId)?.companyId }))
  };
}
