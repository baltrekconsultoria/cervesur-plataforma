import { useState } from 'react';
import { Link, useLocation } from 'react-router';
import { canManageSensitiveUsers, canWrite, type ApiCapabilities, type Assignment, type Company, type Report, type User } from '../../api/types';
import { useApp } from '../../app/AppProvider';
import { Page } from '../../app/layout';
import { assignmentStatus } from '../../domain/access';
import { Button, ButtonLink, DataTable, EmptyState, ErrorState, Highlight, LoadingState, NoMatches, Pager, ResultBar, SearchField, SelectField, StatusTag, Tag, type Column } from '../../components/ui';
import { norm } from '../../lib/text';
import { useAsync, type AsyncState } from '../../lib/useAsync';
import { usePaging } from '../../lib/usePaging';
import { useUrlFilters } from '../../lib/useUrlFilters';
import { CompanyDialog } from './CompanyDialog';
import { ConfirmActionDialog } from './ConfirmActionDialog';
import { NewUserDialog } from './NewUserDialog';
import { UserAccessDialog, UserEditDialog } from './UserDialogs';
import { ReportDialog } from './ReportDialog';
import { RevokeDialog } from './RevokeDialog';

const crumbs = (label: string) => [{ label: 'Administración', to: '/admin' }, { label }];
const NOTE = 'Versión de solo consulta: las altas, ediciones y asignaciones todavía no están habilitadas. Los datos mostrados son los del servicio; no se modifica nada desde esta pantalla.';

interface AdminData { users: User[]; companies: Company[]; reports: Report[]; assignments: Assignment[] }

/** Carga solo lo que el proveedor puede entregar: sin directorio administrativo no se piden usuarios ni asignaciones. */
function useAdminData(): AsyncState<AdminData> {
  const { api, version, capabilities } = useApp();
  return useAsync(async () => {
    const [companies, reports] = await Promise.all([api.listCompanies('all'), api.listReports('all')]);
    const [users, assignments] = capabilities.adminDirectory ? await Promise.all([api.listUsers(), api.listAssignments('all')]) : [[], []];
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

const ACTIVE_OPTIONS = (on: string, off: string): Array<[string, string]> => [['active', on], ['inactive', off], ['all', 'Todos']];

export function AdminUsersPage() {
  const data = useAdminData();
  const { api, user: me, capabilities, bump } = useApp();
  const filters = useUrlFilters({ q: '', role: 'all', status: 'active' });
  const [creating, setCreating] = useState(false);
  const [editing, setEditing] = useState<User | null>(null);
  const [viewing, setViewing] = useState<User | null>(null);
  const [toggling, setToggling] = useState<{ user: User; to: 'off' | 'on' } | null>(null);
  const [roleChange, setRoleChange] = useState<User | null>(null);
  const [notice, setNotice] = useState('');
  // UX-08B.3: los botones de edición, estado y rol solo aparecen si el SERVIDOR tiene habilitada la administración de usuarios.
  const userAdmin = useAsync(async () => (canWrite(capabilities, 'usuarios') ? api.getUserAdminEnabled().catch(() => false) : false), [api, capabilities.adminWrites, capabilities.writes]);
  const manage = userAdmin.status === 'ready' && userAdmin.data;
  const paging = usePaging(JSON.stringify(filters.values));
  if (!capabilities.adminDirectory) return <NotIntegrated title="Usuarios" what="El listado de usuarios" />;
  return (
    <AdminScreen title="Usuarios" data={data} notice={<>
      {notice && <p className="success-box" role="status">{notice}</p>}
      {creating && <NewUserDialog onClose={() => setCreating(false)} onCreated={username => { setCreating(false); setNotice(`Usuario «${username}» creado correctamente.`); bump(); }} />}
      {editing && <UserEditDialog user={editing} onClose={() => setEditing(null)} onStale={bump} onSaved={name => { setEditing(null); setNotice(`Usuario «${name}» actualizado correctamente.`); bump(); }} />}
      {viewing && <UserAccessDialog user={viewing} onClose={() => setViewing(null)} />}
      {toggling && (
        <ConfirmActionDialog
          title={toggling.to === 'off' ? 'Desactivar usuario' : 'Reactivar usuario'} labelId="us-toggle-title"
          confirmLabel={toggling.to === 'off' ? 'Desactivar usuario' : 'Reactivar usuario'} savingLabel={toggling.to === 'off' ? 'Desactivando…' : 'Reactivando…'}
          variant={toggling.to === 'off' ? 'danger' : 'primary'} fallbackError="No se pudo cambiar el estado del usuario. Intente nuevamente."
          run={() => (toggling.to === 'off' ? api.deactivateUser(toggling.user.id) : api.reactivateUser(toggling.user.id))}
          onClose={() => setToggling(null)} onStale={bump}
          onDone={() => { setNotice(`Usuario «${toggling.user.displayName}» ${toggling.to === 'off' ? 'desactivado' : 'reactivado'} correctamente.`); setToggling(null); bump(); }}>
          {toggling.to === 'off'
            ? <><p>¿Desactivar a <strong>{toggling.user.displayName}</strong>?</p><p className="form-note">Se cerrarán sus sesiones en este administrador y no podrá ingresar a él; sus accesos quedarán sin efecto, pero no se borran. Podrá reactivarlo después{capabilities.reactivateAfterHours ? `, pero solo pasadas ${capabilities.reactivateAfterHours} h (protege sesiones antiguas que pudieran seguir vigentes)` : ''}. Importante: esto NO es una revocación inmediata de acceso en todos los sistemas; el tratamiento de sesiones y tokens de Flutter aún no está validado con su backend real.</p></>
            : <><p>¿Reactivar a <strong>{toggling.user.displayName}</strong>?</p><p className="form-note">Podrá ingresar de nuevo con su contraseña; deberá iniciar sesión otra vez. Sus accesos que sigan activos recuperarán su efecto.</p></>}
        </ConfirmActionDialog>
      )}
      {roleChange && canManageSensitiveUsers(capabilities) && (
        <ConfirmActionDialog
          title="Cambiar rol" labelId="us-role-title" confirmLabel={roleChange.role === 'admin' ? 'Quitar rol de administrador' : 'Hacer administrador'} savingLabel="Guardando…"
          variant={roleChange.role === 'admin' ? 'danger' : 'primary'} fallbackError="No se pudo cambiar el rol. Intente nuevamente."
          run={() => api.changeUserRole(roleChange.id, roleChange.role === 'admin' ? 'user' : 'admin')}
          onClose={() => setRoleChange(null)} onStale={bump}
          onDone={() => { setNotice(`Rol de «${roleChange.displayName}» actualizado: ahora es ${roleChange.role === 'admin' ? 'usuario' : 'administrador'}.`); setRoleChange(null); bump(); }}>
          <p>{roleChange.role === 'admin' ? <>¿Quitar el rol de administrador a <strong>{roleChange.displayName}</strong>?</> : <>¿Convertir a <strong>{roleChange.displayName}</strong> en administrador?</>}</p>
          <p className="form-note">Se cerrarán sus sesiones y deberá iniciar sesión de nuevo. No se puede cambiar el propio rol ni quitárselo al último administrador activo. Las sesiones de Flutter ya iniciadas conservan el rol anterior hasta que caduquen.</p>
        </ConfirmActionDialog>
      )}
    </>}>
      {({ users, companies, assignments, reports }) => {
        const { q, role, status } = filters.values;
        const needle = norm(q);
        const rows = users.filter(u => (role === 'all' || u.role === role) && (status === 'all' || (status === 'active') === u.active) && (!needle || norm(`${u.displayName} ${u.email} ${u.username}`).includes(needle)));
        const shown = paging.view(rows);
        const accessCount = (u: User) => assignments.filter(a => a.userId === u.id && assignmentStatus(a, users, reports, companies).ok).length;
        const cols: Array<Column<User>> = [
          { header: 'Usuario', cell: u => <><strong><Highlight text={u.displayName} query={q} /></strong>{u.id === me?.id && <> <Tag>Usted</Tag></>}<span className="muted"><Highlight text={u.email} query={q} /></span></> },
          { header: 'Rol', cell: u => (u.loginBlocked ? <Tag tone="warn">Rol no reconocido · sin acceso</Tag> : u.role === 'admin' ? <Tag tone="admin">Administrador</Tag> : 'Usuario') },
          { header: 'Empresas', cell: u => (u.role === 'admin' ? <span className="muted">Todas</span> : u.companyIds.map(id => companies.find(c => c.id === id)?.name.replace(/ S\.A\.C\.$/, '')).filter(Boolean).join(', ') || '—') },
          { header: 'Informes', cls: 'num', cell: u => (u.role === 'admin' ? <span className="muted">Todos</span> : accessCount(u)) },
          { header: 'Estado', cell: u => <StatusTag active={u.active} /> },
          ...(canWrite(capabilities, 'usuarios') ? [{ header: 'Acciones', cls: 'actions-cell', cell: (u: User) => (
            <>
              {u.active && u.role === 'user' && !u.loginBlocked && <><Link className="btn btn-secondary btn-sm" to={`/admin/accesos/nueva?user=${encodeURIComponent(u.id)}`} aria-label={`Asignar accesos a ${u.displayName}`}>Asignar accesos</Link>{' '}</>}
              <Button variant="secondary" size="sm" aria-label={`Ver accesos de ${u.displayName}`} onClick={() => { setNotice(''); setViewing(u); }}>Accesos</Button>
              {manage && (
                <>
                  {' '}<Button variant="secondary" size="sm" aria-label={`Editar ${u.displayName}`} onClick={() => { setNotice(''); setEditing(u); }}>Editar</Button>
                  {u.id !== me?.id && !u.loginBlocked && u.active && canManageSensitiveUsers(capabilities) && <>{' '}<Button variant="secondary" size="sm" aria-label={`Cambiar rol de ${u.displayName}`} onClick={() => { setNotice(''); setRoleChange(u); }}>Cambiar rol</Button></>}
                  {u.id !== me?.id && (u.active
                    ? <>{' '}<Button variant="secondary" size="sm" aria-label={`Desactivar a ${u.displayName}`} onClick={() => { setNotice(''); setToggling({ user: u, to: 'off' }); }}>Desactivar</Button></>
                    : <>{' '}<Button variant="secondary" size="sm" aria-label={`Reactivar a ${u.displayName}`} onClick={() => { setNotice(''); setToggling({ user: u, to: 'on' }); }}>Reactivar</Button></>)}
                </>
              )}
            </>) }] : [])
        ];
        return (
          <>
            <div className="toolbar" role="search">
              <SearchField id="us-q" label="Buscar usuario" placeholder="Nombre, usuario o correo" value={q} onChange={v => filters.set('q', v)} />
              <SelectField id="us-role" label="Rol" value={role} onChange={v => filters.set('role', v)} options={[['all', 'Todos'], ['user', 'Usuario'], ['admin', 'Administrador']]} />
              <SelectField id="us-status" label="Estado" value={status} onChange={v => filters.set('status', v)} options={ACTIVE_OPTIONS('Activos', 'Inactivos')} />
              {canWrite(capabilities, 'usuarios') && <Button variant="primary" onClick={() => { setNotice(''); setCreating(true); }}>Nuevo usuario</Button>}
            </div>
            <ResultBar shown={rows.length} total={users.length} noun="usuarios" canClear={filters.active} onClear={filters.clear} />
            {rows.length ? <><DataTable columns={cols} rows={shown.rows} rowKey={u => u.id} rowClass={u => (u.active ? '' : 'is-off')} /><Pager page={shown.page} total={rows.length} onPage={paging.setPage} /></> : <NoMatches noun="usuario" onClear={filters.clear} />}
          </>
        );
      }}
    </AdminScreen>
  );
}

export function AdminCompaniesPage() {
  const data = useAdminData();
  const { api, capabilities, bump } = useApp();
  const filters = useUrlFilters({ q: '', status: 'active' });
  const paging = usePaging(JSON.stringify(filters.values));
  const [editing, setEditing] = useState<Company | 'new' | null>(null);
  const [toggling, setToggling] = useState<{ company: Company; to: 'off' | 'on' } | null>(null);
  const [notice, setNotice] = useState('');
  return (
    <AdminScreen title="Empresas" data={data} notice={<>
      {notice && <p className="success-box" role="status">{notice}</p>}
      {editing && <CompanyDialog company={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onSaved={(name, created) => { setEditing(null); setNotice(created ? `Empresa «${name}» creada correctamente.` : `Empresa «${name}» actualizada correctamente.`); bump(); }} />}
      {toggling && (
        <ConfirmActionDialog
          title={toggling.to === 'off' ? 'Desactivar empresa' : 'Reactivar empresa'} labelId="co-toggle-title"
          confirmLabel={toggling.to === 'off' ? 'Desactivar empresa' : 'Reactivar empresa'} savingLabel={toggling.to === 'off' ? 'Desactivando…' : 'Reactivando…'}
          variant={toggling.to === 'off' ? 'danger' : 'primary'} fallbackError="No se pudo cambiar el estado de la empresa. Intente nuevamente."
          run={() => (toggling.to === 'off' ? api.deactivateCompany(toggling.company.id) : api.reactivateCompany(toggling.company.id))}
          onClose={() => setToggling(null)} onStale={bump}
          onDone={() => { setNotice(`Empresa «${toggling.company.name}» ${toggling.to === 'off' ? 'desactivada' : 'reactivada'} correctamente.`); setToggling(null); bump(); }}>
          {toggling.to === 'off'
            ? <><p>¿Desactivar <strong>{toggling.company.name}</strong>?</p><p className="form-note">Sus usuarios dejarán de ver sus informes y sus accesos quedarán sin efecto. No se borra nada: podrá reactivarla después.</p></>
            : <><p>¿Reactivar <strong>{toggling.company.name}</strong>?</p><p className="form-note">Los accesos que sigan activos recuperarán su efecto.</p></>}
        </ConfirmActionDialog>
      )}
    </>}>
      {({ companies, reports, users }) => {
        const { q, status } = filters.values;
        const needle = norm(q);
        const rows = companies.filter(c => (status === 'all' || (status === 'active') === c.active) && (!needle || norm(`${c.name} ${c.city} ${c.taxId}`).includes(needle)));
        const shown = paging.view(rows);
        const cols: Array<Column<Company>> = [
          { header: 'Empresa', cell: c => <><strong><Highlight text={c.name} query={q} /></strong><span className="muted">RUC <Highlight text={c.taxId} query={q} /></span></> },
          { header: 'Ciudad', cell: c => <Highlight text={c.city} query={q} /> },
          ...(capabilities.reportCompany ? [{ header: 'Informes activos', cls: 'num', cell: (c: Company) => reports.filter(r => r.companyIds.includes(c.id) && r.active).length }] : []),
          ...(capabilities.adminDirectory ? [{ header: 'Usuarios', cls: 'num', cell: (c: Company) => users.filter(u => u.active && u.role === 'user' && u.companyIds.includes(c.id)).length }] : []),
          { header: 'Estado', cell: c => <StatusTag active={c.active} on="Activa" off="Inactiva" /> },
          ...(canWrite(capabilities, 'empresas') ? [{ header: 'Acciones', cls: 'actions-cell', cell: (c: Company) => (
            c.active ? (
              <>
                <Button variant="secondary" size="sm" aria-label={`Editar ${c.name}`} onClick={() => { setNotice(''); setEditing(c); }}>Editar</Button>{' '}
                <Link className="btn btn-secondary btn-sm" to={`/admin/asociaciones?company=${encodeURIComponent(c.id)}`} aria-label={`Informes de ${c.name}`}>Informes</Link>{' '}
                <Button variant="secondary" size="sm" aria-label={`Desactivar ${c.name}`} onClick={() => { setNotice(''); setToggling({ company: c, to: 'off' }); }}>Desactivar</Button>
              </>
            ) : <Button variant="secondary" size="sm" aria-label={`Reactivar ${c.name}`} onClick={() => { setNotice(''); setToggling({ company: c, to: 'on' }); }}>Reactivar</Button>) }] : [])
        ];
        return (
          <>
            <div className="toolbar" role="search">
              <SearchField id="ac-q" label="Buscar empresa" placeholder="Nombre, ciudad o RUC" value={q} onChange={v => filters.set('q', v)} />
              <SelectField id="ac-status" label="Estado" value={status} onChange={v => filters.set('status', v)} options={ACTIVE_OPTIONS('Activas', 'Inactivas')} />
              {canWrite(capabilities, 'empresas') && <Button variant="primary" onClick={() => { setNotice(''); setEditing('new'); }}>Nueva empresa</Button>}
            </div>
            <ResultBar shown={rows.length} total={companies.length} noun="empresas" canClear={filters.active} onClear={filters.clear} />
            {rows.length ? <><DataTable columns={cols} rows={shown.rows} rowKey={c => c.id} rowClass={c => (c.active ? '' : 'is-off')} /><Pager page={shown.page} total={rows.length} onPage={paging.setPage} /></> : <NoMatches noun="empresa" onClear={filters.clear} />}
          </>
        );
      }}
    </AdminScreen>
  );
}

export function AdminReportsPage() {
  const data = useAdminData();
  const { api, capabilities, bump } = useApp();
  const location = useLocation();
  const filters = useUrlFilters({ q: '', company: 'all', group: 'all', status: 'active' });
  const paging = usePaging(JSON.stringify(filters.values));
  const [editing, setEditing] = useState<Report | 'new' | null>(null);
  const [toggling, setToggling] = useState<{ report: Report; to: 'off' | 'on' } | null>(null);
  const [notice, setNotice] = useState('');
  return (
    <AdminScreen title="Reportes" data={data} notice={<>
      {notice && <p className="success-box" role="status">{notice}</p>}
      {editing && <ReportDialog report={editing === 'new' ? null : editing} onClose={() => setEditing(null)} onSaved={(name, created) => { setEditing(null); setNotice(created ? `Reporte «${name}» creado correctamente. Asócielo a una empresa desde «Informes por empresa».` : `Reporte «${name}» actualizado correctamente.`); bump(); }} />}
      {toggling && (
        <ConfirmActionDialog
          title={toggling.to === 'off' ? 'Desactivar reporte' : 'Reactivar reporte'} labelId="rp-toggle-title"
          confirmLabel={toggling.to === 'off' ? 'Desactivar reporte' : 'Reactivar reporte'} savingLabel={toggling.to === 'off' ? 'Desactivando…' : 'Reactivando…'}
          variant={toggling.to === 'off' ? 'danger' : 'primary'} fallbackError="No se pudo cambiar el estado del reporte. Intente nuevamente."
          run={() => (toggling.to === 'off' ? api.deactivateReport(toggling.report.id) : api.reactivateReport(toggling.report.id))}
          onClose={() => setToggling(null)} onStale={bump}
          onDone={() => { setNotice(`Reporte «${toggling.report.name}» ${toggling.to === 'off' ? 'desactivado' : 'reactivado'} correctamente.`); setToggling(null); bump(); }}>
          {toggling.to === 'off'
            ? <><p>¿Desactivar <strong>{toggling.report.name}</strong>?</p><p className="form-note">Nadie podrá abrirlo y los accesos asociados quedarán sin efecto. No se borra nada: podrá reactivarlo después.</p></>
            : <><p>¿Reactivar <strong>{toggling.report.name}</strong>?</p><p className="form-note">Los accesos que sigan activos recuperarán su efecto.</p></>}
        </ConfirmActionDialog>
      )}
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
        const shown = paging.view(rows);
        const cols: Array<Column<Report>> = [
          { header: 'Reporte', cell: r => <><strong><Highlight text={r.name} query={q} /></strong><span className="muted"><Highlight text={r.description} query={q} /></span></> },
          ...(capabilities.reportCompany ? [{ header: 'Empresa', cell: (r: Report) => r.companyIds.map(id => companies.find(c => c.id === id)?.name).filter(Boolean).join(' · ') || '—' }] : []),
          ...(capabilities.reportGroups ? [{ header: 'Grupo', cell: (r: Report) => <Tag>{r.group}</Tag> }] : []),
          ...(capabilities.adminDirectory ? [{ header: 'Usuarios con acceso', cls: 'num', cell: access }] : []),
          { header: 'Estado', cell: r => <StatusTag active={r.active} /> },
          { header: 'Acciones', cls: 'actions-cell', cell: r => (
            <>
              {r.active && <Link className="btn btn-secondary btn-sm" to={`/reportes/${encodeURIComponent(r.id)}`} state={{ from }} data-report-id={r.id} aria-label={`Vista previa de ${r.name}`}>Ver</Link>}
              {canWrite(capabilities, 'reportes') && (r.active
                ? <>{' '}<Button variant="secondary" size="sm" aria-label={`Editar ${r.name}`} onClick={() => { setNotice(''); setEditing(r); }}>Editar</Button>{' '}<Button variant="secondary" size="sm" aria-label={`Desactivar ${r.name}`} onClick={() => { setNotice(''); setToggling({ report: r, to: 'off' }); }}>Desactivar</Button></>
                : <Button variant="secondary" size="sm" aria-label={`Reactivar ${r.name}`} onClick={() => { setNotice(''); setToggling({ report: r, to: 'on' }); }}>Reactivar</Button>)}
            </>) }
        ];
        const statusOptions: Array<[string, string]> = [['active', 'Activos'], ['inactive', 'Inactivos'], ['all', 'Todos'], ...(capabilities.adminDirectory ? [['unassigned', 'Activos sin usuarios'] as [string, string]] : []), ...(capabilities.reportCompany ? [['unlinked', 'Sin empresa'] as [string, string]] : [])];
        return (
          <>
            <div className="toolbar" role="search">
              <SearchField id="ar-q" label="Buscar reporte" placeholder="Nombre o descripción" value={q} onChange={v => filters.set('q', v)} />
              {capabilities.reportCompany && <SelectField id="ar-company" label="Empresa" value={company} onChange={v => filters.set('company', v)} options={[['all', 'Todas'], ...companies.map((c): [string, string] => [c.id, c.name])]} />}
              {capabilities.reportGroups && <SelectField id="ar-group" label="Grupo" value={group} onChange={v => filters.set('group', v)} options={[['all', 'Todos'], ...groups.map((g): [string, string] => [g, g])]} />}
              <SelectField id="ar-status" label="Estado" value={status} onChange={v => filters.set('status', v)} options={statusOptions} />
              {canWrite(capabilities, 'reportes') && <Button variant="primary" onClick={() => { setNotice(''); setEditing('new'); }}>Nuevo reporte</Button>}
            </div>
            <ResultBar shown={rows.length} total={reports.length} noun="reportes" canClear={filters.active} onClear={filters.clear} />
            {rows.length ? <><DataTable columns={cols} rows={shown.rows} rowKey={r => r.id} rowClass={r => (r.active ? '' : 'is-off')} /><Pager page={shown.page} total={rows.length} onPage={paging.setPage} /></> : <NoMatches noun="reporte" onClear={filters.clear} />}
          </>
        );
      }}
    </AdminScreen>
  );
}

export function AdminAccessPage() {
  const data = useAdminData();
  const { api, capabilities, bump } = useApp();
  const filters = useUrlFilters({ q: '', company: 'all', effect: 'ok' });
  const paging = usePaging(JSON.stringify(filters.values));
  const [revoking, setRevoking] = useState<{ assignment: Assignment; userName: string; reportName: string; companyName: string } | null>(null);
  const [reactivating, setReactivating] = useState<{ assignment: Assignment; userName: string; reportName: string } | null>(null);
  const [notice, setNotice] = useState('');
  if (!capabilities.adminDirectory) return <NotIntegrated title="Accesos y asignaciones" what="El listado de asignaciones" />;
  return (
    <AdminScreen title="Accesos y asignaciones" data={data} notice={<>
      {notice && <p className="success-box" role="status">{notice}</p>}
      {reactivating && (
        <ConfirmActionDialog
          title="Reactivar acceso" labelId="rv-react-title" confirmLabel="Reactivar acceso" savingLabel="Reactivando…" fallbackError="No se pudo reactivar el acceso. Intente nuevamente."
          run={() => api.reactivateAssignment(reactivating.assignment.id ?? '')} onClose={() => setReactivating(null)} onStale={bump}
          onDone={() => { setNotice(`Acceso de ${reactivating.userName} a «${reactivating.reportName}» reactivado.`); setReactivating(null); bump(); }}>
          <p>¿Reactivar el acceso de <strong>{reactivating.userName}</strong> al informe <strong>{reactivating.reportName}</strong>?</p>
          <p className="form-note">Solo surtirá efecto si el usuario, la empresa, el informe y sus relaciones siguen activos; de lo contrario el servidor lo rechazará.</p>
        </ConfirmActionDialog>
      )}
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
        const shown = paging.view(rows);
        type Row = (typeof all)[number];
        const cols: Array<Column<Row>> = [
          { header: 'Usuario', cell: x => <><strong><Highlight text={x.user.displayName} query={q} /></strong><span className="muted"><Highlight text={x.user.email} query={q} /></span></> },
          { header: 'Informe', cell: x => <Highlight text={x.report.name} query={q} /> },
          { header: 'Empresa', cell: x => companies.find(c => c.id === companyOf(x))?.name ?? '—' },
          { header: 'Estado', cell: x => (x.status.ok ? <Tag tone="ok">Vigente</Tag> : <Tag tone="warn">Sin efecto · {x.status.reason}</Tag>) },
          ...(canWrite(capabilities, 'accesos') ? [{ header: 'Acciones', cls: 'actions-cell', cell: (x: Row) => (x.a.id && x.a.active === false
            ? <Button variant="secondary" size="sm" aria-label={`Reactivar acceso de ${x.user.displayName} a ${x.report.name}`} onClick={() => { setNotice(''); setReactivating({ assignment: x.a, userName: x.user.displayName, reportName: x.report.name }); }}>Reactivar</Button>
            : x.a.id
            ? <Button variant="secondary" size="sm" aria-label={`Retirar acceso de ${x.user.displayName} a ${x.report.name}`} onClick={() => { setNotice(''); setRevoking({ assignment: x.a, userName: x.user.displayName, reportName: x.report.name, companyName: companies.find(c => c.id === companyOf(x))?.name ?? '' }); }}>Retirar</Button>
            : null) }] : [])
        ];
        return (
          <>
            <div className="toolbar" role="search">
              <SearchField id="ax-q" label="Buscar asignación" placeholder="Usuario o informe" value={q} onChange={v => filters.set('q', v)} />
              <SelectField id="ax-company" label="Empresa" value={company} onChange={v => filters.set('company', v)} options={[['all', 'Todas'], ...companies.map((c): [string, string] => [c.id, c.name])]} />
              <SelectField id="ax-effect" label="Estado" value={effect} onChange={v => filters.set('effect', v)} options={[['ok', 'Vigentes'], ['off', 'Sin efecto'], ['all', 'Todos']]} />
              {canWrite(capabilities, 'accesos') && <ButtonLink to="/admin/accesos/nueva">Nueva asignación</ButtonLink>}
              {capabilities.adminWrites && <ButtonLink to="/admin/historial">Ver historial</ButtonLink>}
            </div>
            <ResultBar shown={rows.length} total={all.length} noun="asignaciones" canClear={filters.active} onClear={filters.clear} />
            {rows.length ? <><DataTable columns={cols} rows={shown.rows} rowKey={x => x.a.id ?? `${x.a.userId}-${x.a.reportId}-${x.a.companyId ?? ''}`} rowClass={x => (x.status.ok ? '' : 'is-off')} /><Pager page={shown.page} total={rows.length} onPage={paging.setPage} /></> : <NoMatches noun="acceso" onClear={filters.clear} />}
          </>
        );
      }}
    </AdminScreen>
  );
}
