import { useState } from 'react';
import { Link, useLocation } from 'react-router';
import type { ApiCapabilities, Assignment, Company, Report, User } from '../../api/types';
import { useApp } from '../../app/AppProvider';
import { Page } from '../../app/layout';
import { assignmentStatus } from '../../domain/access';
import { Button, ButtonLink, DataTable, EmptyState, ErrorState, Highlight, LoadingState, NoMatches, PAGE_SIZE, Pager, ResultBar, SearchField, SelectField, StatusTag, Tag, type Column } from '../../components/ui';
import { norm } from '../../lib/text';
import { useAsync, type AsyncState } from '../../lib/useAsync';
import { useUrlFilters } from '../../lib/useUrlFilters';
import { CompanyDialog } from './CompanyDialog';
import { NewUserDialog } from './NewUserDialog';
import { ReportDialog } from './ReportDialog';
import { RevokeDialog } from './RevokeDialog';

const crumbs = (label: string) => [{ label: 'Administración', to: '/admin' }, { label }];
const NOTE = 'Versión de solo consulta: las altas, ediciones y asignaciones todavía no están habilitadas. Los datos mostrados son los del servicio; no se modifica nada desde esta pantalla.';

interface AdminData { users: User[]; companies: Company[]; reports: Report[]; assignments: Assignment[] }

/** Carga solo lo que el proveedor puede entregar: sin directorio administrativo no se piden usuarios ni asignaciones. */
function useAdminData(): AsyncState<AdminData> {
  const { api, version, capabilities } = useApp();
  return useAsync(async () => {
    const [companies, reports] = await Promise.all([api.listCompanies(), api.listReports()]);
    const [users, assignments] = capabilities.adminDirectory ? await Promise.all([api.listUsers(), api.listAssignments()]) : [[], []];
    return { users, companies, reports, assignments };
  }, [api, version]);
}

function NotIntegrated({ title, what }: { title: string; what: string }) {
  return (
    <Page title={title} crumbs={crumbs(title)}>
      <EmptyState title="Se conecta en la siguiente etapa de integración">
        {what} aún no se obtiene del servicio real. En esta etapa solo están integrados el inicio de sesión, el perfil, las empresas y los reportes.
      </EmptyState>
    </Page>
  );
}

/** Envoltorio común: carga, error y cabecera. */
function AdminScreen({ title, data, children, notice }: { title: string; data: AsyncState<AdminData>; children: (d: AdminData) => React.ReactNode; notice?: React.ReactNode }) {
  const { bump, capabilities } = useApp();
  return (
    <Page title={title} crumbs={crumbs(title)}>
      {notice}
      {data.status === 'loading' ? <LoadingState /> : data.status === 'error' ? <ErrorState onRetry={bump} /> : <section>{children(data.data)}{!capabilities.adminWrites && <p className="form-note" style={{ marginTop: '.75rem' }}>{NOTE}</p>}</section>}
    </Page>
  );
}

export function AdminDashboardPage() {
  const data = useAdminData();
  const { capabilities, bump } = useApp();
  return (
    <Page title="Inicio administrativo" crumbs={[{ label: 'Administración' }]}>
      {data.status === 'loading' ? <LoadingState /> : data.status === 'error' ? <ErrorState onRetry={bump} /> : <Dashboard data={data.data} capabilities={capabilities} />}
    </Page>
  );
}

function Dashboard({ data, capabilities }: { data: AdminData; capabilities: ApiCapabilities }) {
  const { provider } = useApp();
  const { users, companies, reports, assignments } = data;
  const directory = capabilities.adminDirectory;
  const statuses = assignments.map(a => assignmentStatus(a, users, reports, companies));
  const effective = statuses.filter(s => s.ok).length;
  const effectiveFor = (r: Report) => assignments.filter(a => a.reportId === r.id && assignmentStatus(a, users, reports, companies).ok).length;
  const unassigned = reports.filter(r => r.active && !effectiveFor(r)).length;
  const stale = users.filter(u => !u.active && assignments.some(a => a.userId === u.id)).length;
  const metric = (label: string, value: number, total: number | null, to: string, note: string) => (
    <Link className="card metric" to={to}><span className="eyebrow">{label}</span><strong>{value}{total !== null && <> <small className="muted">de {total}</small></>}</strong><span className="muted">{note}</span></Link>
  );
  const item = (text: string, count: number, to: string) => (
    <li><span>{text}</span>{count ? <Link className="btn btn-secondary btn-sm" to={to}>Revisar ({count})</Link> : <Tag tone="ok">Sin pendientes</Tag>}</li>
  );
  return (
    <section>
      <div className="grid metric-grid">
        {directory && metric('Usuarios activos', users.filter(u => u.active).length, users.length, '/admin/usuarios?status=active', 'Ver usuarios')}
        {metric('Empresas activas', companies.filter(c => c.active).length, companies.length, '/admin/empresas?status=active', 'Ver empresas')}
        {metric('Reportes activos', reports.filter(r => r.active).length, reports.length, '/admin/reportes?status=active', 'Ver reportes')}
        {directory && metric('Accesos vigentes', effective, null, '/admin/accesos?effect=ok', 'Ver asignaciones')}
      </div>
      {directory ? (
        <div className="dash-cols">
          <div className="panel">
            <h2>Requiere atención</h2>
            <p className="muted">{provider === 'http' ? 'Calculado con los datos reales que el servicio entregó en esta consulta (solo lectura).' : 'Calculado con los datos de demostración (sintéticos) de este entorno.'}</p>
            <ul className="attention-list">
              {item('Informes activos sin usuarios con acceso', unassigned, '/admin/reportes?status=unassigned')}
              {item('Asignaciones sin efecto', statuses.length - effective, '/admin/accesos?effect=off')}
              {item('Usuarios inactivos con asignaciones', stale, '/admin/usuarios?status=inactive')}
            </ul>
          </div>
        </div>
      ) : (
        <p className="form-note" style={{ marginTop: '.75rem' }}>Usuarios, accesos y pendientes se mostrarán cuando esos listados se integren con el servicio real.</p>
      )}
      {!capabilities.adminWrites && <p className="form-note" style={{ marginTop: '.75rem' }}>{NOTE}</p>}
    </section>
  );
}

const ACTIVE_OPTIONS = (on: string, off: string): Array<[string, string]> => [['all', 'Todos'], ['active', on], ['inactive', off]];

export function AdminUsersPage() {
  const data = useAdminData();
  const { user: me, capabilities, bump } = useApp();
  const filters = useUrlFilters({ q: '', role: 'all', status: 'all' });
  const [creating, setCreating] = useState(false);
  const [notice, setNotice] = useState('');
  const [paging, setPaging] = useState({ key: '', page: 1 });
  if (!capabilities.adminDirectory) return <NotIntegrated title="Usuarios" what="El listado de usuarios" />;
  const filterKey = JSON.stringify(filters.values);
  const page = paging.key === filterKey ? paging.page : 1;
  return (
    <AdminScreen title="Usuarios" data={data} notice={<>
      {notice && <p className="success-box" role="status">{notice}</p>}
      {creating && <NewUserDialog onClose={() => setCreating(false)} onCreated={username => { setCreating(false); setNotice(`Usuario «${username}» creado correctamente.`); bump(); }} />}
    </>}>
      {({ users, companies, assignments, reports }) => {
        const { q, role, status } = filters.values;
        const needle = norm(q);
        const rows = users.filter(u => (role === 'all' || u.role === role) && (status === 'all' || (status === 'active') === u.active) && (!needle || norm(`${u.displayName} ${u.email} ${u.username}`).includes(needle)));
        const visible = rows.slice((page - 1) * PAGE_SIZE, page * PAGE_SIZE);
        const accessCount = (u: User) => assignments.filter(a => a.userId === u.id && assignmentStatus(a, users, reports, companies).ok).length;
        const cols: Array<Column<User>> = [
          { header: 'Usuario', cell: u => <><strong><Highlight text={u.displayName} query={q} /></strong>{u.id === me?.id && <> <Tag>Usted</Tag></>}<span className="muted"><Highlight text={u.email} query={q} /></span></> },
          { header: 'Rol', cell: u => (u.loginBlocked ? <Tag tone="warn">Rol no reconocido · sin acceso</Tag> : u.role === 'admin' ? <Tag tone="admin">Administrador</Tag> : 'Usuario') },
          { header: 'Empresas', cell: u => (u.role === 'admin' ? <span className="muted">Todas</span> : u.companyIds.map(id => companies.find(c => c.id === id)?.name.replace(/ S\.A\.C\.$/, '')).filter(Boolean).join(', ') || '—') },
          { header: 'Informes', cls: 'num', cell: u => (u.role === 'admin' ? <span className="muted">Todos</span> : accessCount(u)) },
          { header: 'Estado', cell: u => <StatusTag active={u.active} /> },
          ...(capabilities.adminWrites ? [{ header: 'Acciones', cls: 'actions-cell', cell: (u: User) => (u.active && u.role === 'user' && !u.loginBlocked ? <Link className="btn btn-secondary btn-sm" to={`/admin/accesos/nueva?user=${encodeURIComponent(u.id)}`} aria-label={`Asignar accesos a ${u.displayName}`}>Asignar accesos</Link> : null) }] : [])
        ];
        return (
          <>
            <div className="toolbar" role="search">
              <SearchField id="us-q" label="Buscar usuario" placeholder="Nombre, usuario o correo" value={q} onChange={v => filters.set('q', v)} />
              <SelectField id="us-role" label="Rol" value={role} onChange={v => filters.set('role', v)} options={[['all', 'Todos'], ['user', 'Usuario'], ['admin', 'Administrador']]} />
              <SelectField id="us-status" label="Estado" value={status} onChange={v => filters.set('status', v)} options={ACTIVE_OPTIONS('Activos', 'Inactivos')} />
              {capabilities.adminWrites && <Button variant="primary" onClick={() => { setNotice(''); setCreating(true); }}>Nuevo usuario</Button>}
            </div>
            <ResultBar shown={rows.length} total={users.length} noun="usuarios" canClear={filters.active} onClear={filters.clear} />
            {rows.length ? <><DataTable columns={cols} rows={visible} rowKey={u => u.id} rowClass={u => (u.active ? '' : 'is-off')} /><Pager page={page} total={rows.length} onPage={p => setPaging({ key: filterKey, page: p })} /></> : <NoMatches noun="usuario" onClear={filters.clear} />}
          </>
        );
      }}
    </AdminScreen>
  );
}

export function AdminCompaniesPage() {
  const data = useAdminData();
  const { capabilities, bump } = useApp();
  const filters = useUrlFilters({ q: '', status: 'all' });
  const [editing, setEditing] = useState<Company | 'new' | null>(null);
  const [notice, setNotice] = useState('');
  return (
    <AdminScreen title="Empresas" data={data} notice={<>
      {notice && <p className="success-box" role="status">{notice}</p>}
      {editing && <CompanyDialog company={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onSaved={(name, created) => { setEditing(null); setNotice(created ? `Empresa «${name}» creada correctamente.` : `Empresa «${name}» actualizada correctamente.`); bump(); }} />}
    </>}>
      {({ companies, reports, users }) => {
        const { q, status } = filters.values;
        const needle = norm(q);
        const rows = companies.filter(c => (status === 'all' || (status === 'active') === c.active) && (!needle || norm(`${c.name} ${c.city} ${c.taxId}`).includes(needle)));
        const cols: Array<Column<Company>> = [
          { header: 'Empresa', cell: c => <><strong><Highlight text={c.name} query={q} /></strong><span className="muted">RUC <Highlight text={c.taxId} query={q} /></span></> },
          { header: 'Ciudad', cell: c => <Highlight text={c.city} query={q} /> },
          ...(capabilities.reportCompany ? [{ header: 'Informes activos', cls: 'num', cell: (c: Company) => reports.filter(r => r.companyIds.includes(c.id) && r.active).length }] : []),
          ...(capabilities.adminDirectory ? [{ header: 'Usuarios', cls: 'num', cell: (c: Company) => users.filter(u => u.active && u.role === 'user' && u.companyIds.includes(c.id)).length }] : []),
          { header: 'Estado', cell: c => <StatusTag active={c.active} on="Activa" off="Inactiva" /> },
          ...(capabilities.adminWrites ? [{ header: 'Acciones', cls: 'actions-cell', cell: (c: Company) => (
            <>
              <Button variant="secondary" size="sm" aria-label={`Editar ${c.name}`} onClick={() => { setNotice(''); setEditing(c); }}>Editar</Button>{' '}
              <Link className="btn btn-secondary btn-sm" to={`/admin/asociaciones?company=${encodeURIComponent(c.id)}`} aria-label={`Informes de ${c.name}`}>Informes</Link>
            </>) }] : [])
        ];
        return (
          <>
            <div className="toolbar" role="search">
              <SearchField id="ac-q" label="Buscar empresa" placeholder="Nombre, ciudad o RUC" value={q} onChange={v => filters.set('q', v)} />
              <SelectField id="ac-status" label="Estado" value={status} onChange={v => filters.set('status', v)} options={ACTIVE_OPTIONS('Activas', 'Inactivas')} />
              {capabilities.adminWrites && <Button variant="primary" onClick={() => { setNotice(''); setEditing('new'); }}>Nueva empresa</Button>}
            </div>
            <ResultBar shown={rows.length} total={companies.length} noun="empresas" canClear={filters.active} onClear={filters.clear} />
            {rows.length ? <DataTable columns={cols} rows={rows} rowKey={c => c.id} rowClass={c => (c.active ? '' : 'is-off')} /> : <NoMatches noun="empresa" onClear={filters.clear} />}
          </>
        );
      }}
    </AdminScreen>
  );
}

export function AdminReportsPage() {
  const data = useAdminData();
  const { capabilities, bump } = useApp();
  const location = useLocation();
  const filters = useUrlFilters({ q: '', company: 'all', group: 'all', status: 'all' });
  const [editing, setEditing] = useState<Report | 'new' | null>(null);
  const [notice, setNotice] = useState('');
  return (
    <AdminScreen title="Reportes" data={data} notice={<>
      {notice && <p className="success-box" role="status">{notice}</p>}
      {editing && <ReportDialog report={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onSaved={(name, created) => { setEditing(null); setNotice(created ? `Reporte «${name}» creado correctamente. Asócielo a una empresa desde «Informes por empresa».` : `Reporte «${name}» actualizado correctamente.`); bump(); }} />}
    </>}>
      {({ reports, companies, users, assignments }) => {
        const { q, status } = filters.values;
        const company = capabilities.reportCompany ? filters.values.company : 'all';
        const group = capabilities.reportGroups ? filters.values.group : 'all';
        const needle = norm(q);
        const access = (r: Report) => assignments.filter(a => a.reportId === r.id && assignmentStatus(a, users, reports, companies).ok).length;
        const rows = reports.filter(r => (company === 'all' || r.companyIds.includes(company)) && (group === 'all' || r.group === group)
          && (status === 'all' || (status === 'active' && r.active) || (status === 'inactive' && !r.active) || (status === 'unassigned' && capabilities.adminDirectory && r.active && !access(r)) || (status === 'unlinked' && r.companyIds.length === 0))
          && (!needle || norm(`${r.name} ${r.description}`).includes(needle)));
        const groups = [...new Set(reports.map(r => r.group).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'es'));
        const from = `${location.pathname}${location.search}`;
        const cols: Array<Column<Report>> = [
          { header: 'Reporte', cell: r => <><strong><Highlight text={r.name} query={q} /></strong><span className="muted"><Highlight text={r.description} query={q} /></span></> },
          ...(capabilities.reportCompany ? [{ header: 'Empresa', cell: (r: Report) => r.companyIds.map(id => companies.find(c => c.id === id)?.name).filter(Boolean).join(' · ') || '—' }] : []),
          ...(capabilities.reportGroups ? [{ header: 'Grupo', cell: (r: Report) => <Tag>{r.group}</Tag> }] : []),
          ...(capabilities.adminDirectory ? [{ header: 'Usuarios con acceso', cls: 'num', cell: access }] : []),
          { header: 'Estado', cell: r => <StatusTag active={r.active} /> },
          { header: 'Acciones', cls: 'actions-cell', cell: r => (
            <>
              <Link className="btn btn-secondary btn-sm" to={`/reportes/${encodeURIComponent(r.id)}`} state={{ from }} data-report-id={r.id} aria-label={`Vista previa de ${r.name}`}>Ver</Link>
              {capabilities.adminWrites && <>{' '}<Button variant="secondary" size="sm" aria-label={`Editar ${r.name}`} onClick={() => { setNotice(''); setEditing(r); }}>Editar</Button></>}
            </>) }
        ];
        const statusOptions: Array<[string, string]> = [['all', 'Todos'], ['active', 'Activos'], ['inactive', 'Inactivos'], ...(capabilities.adminDirectory ? [['unassigned', 'Activos sin usuarios'] as [string, string]] : []), ...(capabilities.reportCompany ? [['unlinked', 'Sin empresa'] as [string, string]] : [])];
        return (
          <>
            <div className="toolbar" role="search">
              <SearchField id="ar-q" label="Buscar reporte" placeholder="Nombre o descripción" value={q} onChange={v => filters.set('q', v)} />
              {capabilities.reportCompany && <SelectField id="ar-company" label="Empresa" value={company} onChange={v => filters.set('company', v)} options={[['all', 'Todas'], ...companies.map((c): [string, string] => [c.id, c.name])]} />}
              {capabilities.reportGroups && <SelectField id="ar-group" label="Grupo" value={group} onChange={v => filters.set('group', v)} options={[['all', 'Todos'], ...groups.map((g): [string, string] => [g, g])]} />}
              <SelectField id="ar-status" label="Estado" value={status} onChange={v => filters.set('status', v)} options={statusOptions} />
              {capabilities.adminWrites && <Button variant="primary" onClick={() => { setNotice(''); setEditing('new'); }}>Nuevo reporte</Button>}
            </div>
            <ResultBar shown={rows.length} total={reports.length} noun="reportes" canClear={filters.active} onClear={filters.clear} />
            {rows.length ? <DataTable columns={cols} rows={rows} rowKey={r => r.id} rowClass={r => (r.active ? '' : 'is-off')} /> : <NoMatches noun="reporte" onClear={filters.clear} />}
          </>
        );
      }}
    </AdminScreen>
  );
}

export function AdminAccessPage() {
  const data = useAdminData();
  const { capabilities, bump } = useApp();
  const filters = useUrlFilters({ q: '', company: 'all', effect: 'all' });
  const [revoking, setRevoking] = useState<{ assignment: Assignment; userName: string; reportName: string; companyName: string } | null>(null);
  const [notice, setNotice] = useState('');
  if (!capabilities.adminDirectory) return <NotIntegrated title="Accesos y asignaciones" what="El listado de asignaciones" />;
  return (
    <AdminScreen title="Accesos y asignaciones" data={data} notice={<>
      {notice && <p className="success-box" role="status">{notice}</p>}
      {revoking && <RevokeDialog {...revoking} onClose={() => setRevoking(null)} onRevoked={() => { setRevoking(null); setNotice(`Acceso de ${revoking.userName} a «${revoking.reportName}» retirado.`); bump(); }} />}
    </>}>
      {({ assignments, users, reports, companies }) => {
        const { q, company, effect } = filters.values;
        const needle = norm(q);
        const all = assignments.flatMap(a => {
          const user = users.find(u => u.id === a.userId);
          const report = reports.find(r => r.id === a.reportId);
          return user && report ? [{ a, user, report, status: assignmentStatus(a, users, reports, companies) }] : [];
        });
        const companyOf = (x: { a: Assignment; report: Report }) => x.a.companyId ?? x.report.companyId;
        const rows = all.filter(x => (company === 'all' || companyOf(x) === company) && (effect === 'all' || (effect === 'ok') === x.status.ok) && (!needle || norm(`${x.user.displayName} ${x.user.email} ${x.report.name}`).includes(needle)));
        type Row = (typeof all)[number];
        const cols: Array<Column<Row>> = [
          { header: 'Usuario', cell: x => <><strong><Highlight text={x.user.displayName} query={q} /></strong><span className="muted"><Highlight text={x.user.email} query={q} /></span></> },
          { header: 'Informe', cell: x => <Highlight text={x.report.name} query={q} /> },
          { header: 'Empresa', cell: x => companies.find(c => c.id === companyOf(x))?.name ?? '—' },
          { header: 'Estado', cell: x => (x.status.ok ? <Tag tone="ok">Vigente</Tag> : <Tag tone="warn">Sin efecto · {x.status.reason}</Tag>) },
          ...(capabilities.adminWrites ? [{ header: 'Acciones', cls: 'actions-cell', cell: (x: Row) => (x.a.id
            ? <Button variant="secondary" size="sm" aria-label={`Retirar acceso de ${x.user.displayName} a ${x.report.name}`} onClick={() => { setNotice(''); setRevoking({ assignment: x.a, userName: x.user.displayName, reportName: x.report.name, companyName: companies.find(c => c.id === companyOf(x))?.name ?? '' }); }}>Retirar</Button>
            : null) }] : [])
        ];
        return (
          <>
            <div className="toolbar" role="search">
              <SearchField id="ax-q" label="Buscar asignación" placeholder="Usuario o informe" value={q} onChange={v => filters.set('q', v)} />
              <SelectField id="ax-company" label="Empresa" value={company} onChange={v => filters.set('company', v)} options={[['all', 'Todas'], ...companies.map((c): [string, string] => [c.id, c.name])]} />
              <SelectField id="ax-effect" label="Estado" value={effect} onChange={v => filters.set('effect', v)} options={[['all', 'Todos'], ['ok', 'Vigentes'], ['off', 'Sin efecto']]} />
              {capabilities.adminWrites && <ButtonLink to="/admin/accesos/nueva">Nueva asignación</ButtonLink>}
            </div>
            <ResultBar shown={rows.length} total={all.length} noun="asignaciones" canClear={filters.active} onClear={filters.clear} />
            {rows.length ? <DataTable columns={cols} rows={rows} rowKey={x => x.a.id ?? `${x.a.userId}-${x.a.reportId}-${x.a.companyId ?? ''}`} rowClass={x => (x.status.ok ? '' : 'is-off')} /> : <NoMatches noun="acceso" onClear={filters.clear} />}
          </>
        );
      }}
    </AdminScreen>
  );
}
