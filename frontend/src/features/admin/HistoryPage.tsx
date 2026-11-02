import { useState } from 'react';
import type { AuditEntity, AuditEntry } from '../../api/types';
import { useApp } from '../../app/AppProvider';
import { Page } from '../../app/layout';
import { DataTable, EmptyState, ErrorState, LoadingState, Pager, SelectField, type Column } from '../../components/ui';
import { useAsync } from '../../lib/useAsync';

/* UX-08B.2 · Historial de operaciones administrativas (tabla de auditoría del servidor). Solo lectura. */
const crumbs = [{ label: 'Administración', to: '/admin' }, { label: 'Historial' }];

const SCOPES: Array<[AuditEntity, string]> = [['access', 'Accesos y asignaciones'], ['empresa', 'Empresas'], ['reporte', 'Reportes']];

const ACTION_LABELS: Record<string, string> = {
  'user_empresa_reporte.create': 'Acceso concedido',
  'user_empresa_reporte.reactivate': 'Acceso reactivado',
  'user_empresa_reporte.revoke': 'Acceso retirado',
  'empresa_usuario.create': 'Usuario vinculado a empresa',
  'empresa_usuario.reactivate': 'Vínculo usuario–empresa reactivado',
  'empresa_usuario.revoke': 'Vínculo usuario–empresa retirado',
  'empresa_reporte.create': 'Informe asociado a empresa',
  'empresa_reporte.reactivate': 'Asociación empresa–informe reactivada',
  'empresa_reporte.revoke': 'Asociación empresa–informe retirada',
  'empresa.create': 'Empresa creada',
  'empresa.update': 'Empresa editada',
  'empresa.deactivate': 'Empresa desactivada',
  'empresa.reactivate': 'Empresa reactivada',
  'reporte.create': 'Reporte creado',
  'reporte.update': 'Reporte editado',
  'reporte.deactivate': 'Reporte desactivado',
  'reporte.reactivate': 'Reporte reactivado'
};

const FIELD_LABELS: Record<string, string> = { nombre: 'Nombre', razon_social: 'Razón social', ruc: 'RUC', ciudad: 'Ciudad', pais: 'País', email: 'Correo', telefono: 'Teléfono', direccion: 'Dirección', descripcion: 'Descripción', activo: 'Estado' };

/** Texto de un valor de auditoría (escalares tal cual; cualquier otra cosa como JSON, nunca «[object Object]»). */
const text = (value: unknown): string => (value === null || value === undefined ? '' : typeof value === 'string' || typeof value === 'number' || typeof value === 'boolean' ? String(value) : JSON.stringify(value));

const show = (field: string, value: unknown): string => {
  if (text(value) === '') return '—';
  if (field === 'activo') return Number(value) === 1 ? 'activo' : 'inactivo';
  return text(value);
};

/** Cambios legibles de una entrada: solo los campos que difieren (no se listan identificadores técnicos ni resúmenes internos). */
export function describeChanges(entry: Pick<AuditEntry, 'before' | 'after'>): string[] {
  const before = entry.before ?? {};
  const after = entry.after ?? {};
  const skip = (key: string) => /(_id|_sha256_12|_campos)$/.test(key);
  const keys = [...new Set([...Object.keys(before), ...Object.keys(after)])].filter(key => !skip(key) && text(before[key]) !== text(after[key]));
  if (!entry.before) return keys.filter(key => key !== 'activo').slice(0, 4).map(key => `${FIELD_LABELS[key] ?? key}: ${show(key, after[key])}`);
  return keys.slice(0, 4).map(key => `${FIELD_LABELS[key] ?? key}: ${show(key, before[key])} → ${show(key, after[key])}`);
}

export function AdminHistoryPage() {
  const { api, version, capabilities, bump } = useApp();
  const [scope, setScope] = useState<AuditEntity>('access');
  const [paging, setPaging] = useState({ scope: 'access' as AuditEntity, page: 1 });
  const page = paging.scope === scope ? paging.page : 1;
  const data = useAsync(async () => (capabilities.adminWrites ? api.listAudit({ entity: scope, page }) : null), [api, version, scope, page, capabilities.adminWrites]);

  const cols: Array<Column<AuditEntry>> = [
    { header: 'Fecha', cell: e => e.at || '—' },
    { header: 'Operación', cell: e => <strong>{ACTION_LABELS[e.action] ?? e.action}</strong> },
    { header: 'Registro', cell: e => `#${e.entityId}` },
    { header: 'Detalle', cell: e => { const changes = describeChanges(e); return changes.length ? <span className="muted">{changes.join(' · ')}</span> : <span className="muted">—</span>; } },
    { header: 'Realizada por', cell: e => e.actor }
  ];

  return (
    <Page title="Historial" crumbs={crumbs}>
      <div className="toolbar" role="search">
        <SelectField id="hi-scope" label="Ver" value={scope} onChange={value => { setScope(value as AuditEntity); setPaging({ scope: value as AuditEntity, page: 1 }); }} options={SCOPES} />
      </div>
      {!capabilities.adminWrites ? <EmptyState title="Historial no disponible">El historial se habilita junto con la administración operativa.</EmptyState>
        : data.status === 'loading' ? <LoadingState />
        : data.status === 'error' ? <ErrorState onRetry={bump} />
        : !data.data?.available ? <EmptyState title="El historial aún no está habilitado">El servidor todavía no tiene la bitácora de auditoría. Las operaciones se realizan, pero no quedan registradas hasta aplicar la migración.</EmptyState>
        : data.data.entries.length === 0 ? <EmptyState title="Sin operaciones registradas">Todavía no hay movimientos en esta categoría.</EmptyState>
        : <>
          <p className="muted" aria-live="polite">{data.data.total} operaciones registradas, de la más reciente a la más antigua.</p>
          <DataTable columns={cols} rows={data.data.entries} rowKey={e => e.id} />
          <Pager page={page} total={data.data.total} onPage={next => setPaging({ scope, page: next })} />
        </>}
    </Page>
  );
}
