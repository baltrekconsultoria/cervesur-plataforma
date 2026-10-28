import { useMemo, useState } from 'react';
import { useSearchParams } from 'react-router';
import { isApiError } from '../../api/errors';
import type { GrantResult, Report, User } from '../../api/types';
import { useApp } from '../../app/AppProvider';
import { Page } from '../../app/layout';
import { Button, ButtonLink, EmptyState, ErrorState, LoadingState, SearchField, Tag } from '../../components/ui';
import { norm } from '../../lib/text';
import { useAsync } from '../../lib/useAsync';

const crumbs = [{ label: 'Administración', to: '/admin' }, { label: 'Accesos y asignaciones', to: '/admin/accesos' }, { label: 'Nueva asignación' }];
const STEPS = ['Usuario', 'Empresa', 'Informes', 'Confirmar'];

/** Usuarios a los que tiene sentido asignar informes: activos, con rol Usuario y con inicio de sesión posible. */
export const assignable = (u: User) => u.active && u.role === 'user' && !u.loginBlocked;

/**
 * Asistente de 4 pasos (usuario → empresa → informes → confirmar) y resultado.
 * Los identificadores se mantienen separados: usuario y empresa por su id; el informe por su `reporte.id` numérico (`Report.id`); la referencia histórica `reporte_id` no interviene.
 * El cliente HTTP traduce a la relación empresa↔informe (`empresa_reporte`) y a las tres tablas de acceso.
 */
export function AccessWizardPage() {
  const { api, version, bump } = useApp();
  const [params] = useSearchParams();
  const data = useAsync(async () => {
    const [users, companies, reports, assignments] = await Promise.all([api.listUsers(), api.listCompanies(), api.listReports(), api.listAssignments()]);
    return { users, companies, reports, assignments };
  }, [api, version]);

  const [step, setStep] = useState(1);
  const [userId, setUserId] = useState(params.get('user') ?? '');
  const [companyId, setCompanyId] = useState('');
  const [picked, setPicked] = useState<string[]>([]);
  const [query, setQuery] = useState('');
  const [saving, setSaving] = useState(false);
  const [failure, setFailure] = useState('');
  const [results, setResults] = useState<GrantResult[] | null>(null);

  const view = useMemo(() => {
    if (data.status !== 'ready') return null;
    const { users, companies, reports, assignments } = data.data;
    const user = users.find(u => u.id === userId && assignable(u));
    const company = companies.find(c => c.id === companyId && c.active);
    const options = company ? reports.filter(r => r.active && r.companyIds.includes(company.id)) : [];
    const already = new Set(assignments.filter(a => a.userId === userId && a.companyId === companyId).map(a => a.reportId));
    return { users, companies, reports, assignments, user, company, options, already };
  }, [data, userId, companyId]);

  const reset = () => { setStep(1); setUserId(''); setCompanyId(''); setPicked([]); setQuery(''); setResults(null); setFailure(''); };

  const save = async () => {
    if (!view?.user || !view.company || !picked.length) return;
    setSaving(true);
    setFailure('');
    try {
      const outcome = await api.grantAccess({ userId: view.user.id, companyId: view.company.id, reportIds: picked });
      setResults(outcome);
      bump(); // recarga los listados: el resultado mostrado debe coincidir con lo que persiste en el servidor
    } catch (error) {
      if (isApiError(error) && error.kind === 'unauthorized') return;
      setFailure(isApiError(error) && error.message ? error.message : 'No se pudieron guardar los accesos.');
    } finally {
      setSaving(false);
    }
  };

  if (data.status === 'loading') return <Page title="Nueva asignación" crumbs={crumbs}><LoadingState /></Page>;
  if (data.status === 'error' || !view) return <Page title="Nueva asignación" crumbs={crumbs}><ErrorState onRetry={bump} /></Page>;

  const { users, companies, user, company, options, already } = view;
  const needle = norm(query);
  const candidates = users.filter(assignable).filter(u => !needle || norm(`${u.displayName} ${u.email} ${u.username}`).includes(needle));
  const reportName = (id: string) => view.reports.find(r => r.id === id)?.name ?? id;

  const context = (
    <div className="context-strip" aria-label="Selección actual">
      <span><span>Usuario: </span>{user ? <strong>{user.displayName}</strong> : '—'}</span>
      <span><span>Empresa: </span>{company ? <strong>{company.name}</strong> : '—'}</span>
      <span><span>Informes: </span><strong>{picked.length}</strong></span>
    </div>
  );

  if (results) {
    const failed = results.filter(r => r.status === 'failed');
    const granted = results.filter(r => r.status === 'granted').length;
    return (
      <Page title="Nueva asignación" crumbs={crumbs}>
        {failed.length === 0
          ? <div className="success-box" role="status"><h2>Accesos guardados</h2><p>{granted} acceso(s) nuevo(s) para {user?.displayName} en {company?.name}.{results.length - granted > 0 ? ` ${results.length - granted} ya existía(n).` : ''}</p></div>
          : <div className="empty-state" role="alert"><div><h2>Se guardó solo una parte</h2><p>{failed.length} de {results.length} acceso(s) no se pudieron guardar. Revise el detalle e inténtelo de nuevo.</p></div></div>}
        <ul className="attention-list" aria-label="Resultado por informe">
          {results.map(r => (
            <li key={r.reportId} data-status={r.status}><span>{reportName(r.reportId)}</span>
              {r.status === 'failed' ? <Tag tone="warn">No guardado · {r.message}</Tag> : <Tag tone="ok">{r.status === 'granted' ? 'Concedido' : 'Ya existía'}</Tag>}
            </li>
          ))}
        </ul>
        <div className="wizard-actions">
          <ButtonLink to="/admin/accesos">Ver accesos</ButtonLink>
          {failed.length > 0 && <Button variant="secondary" onClick={() => { setPicked(failed.map(f => f.reportId)); setResults(null); setStep(3); }}>Reintentar los no guardados</Button>}
          <Button variant="secondary" onClick={reset}>Asignar a otro usuario</Button>
        </div>
      </Page>
    );
  }

  return (
    <Page title="Nueva asignación" crumbs={crumbs}>
      <ol className="stepper" aria-label="Pasos">
        {STEPS.map((label, i) => <li key={label} className={`step${step === i + 1 ? ' active' : step > i + 1 ? ' done' : ''}`} aria-current={step === i + 1 ? 'step' : undefined}>{i + 1}. {label}</li>)}
      </ol>
      {step > 1 && context}
      <div className="step-panel">
        {step === 1 && (
          <>
            <h2>1. Seleccione el usuario</h2>
            <p className="muted">Solo usuarios activos con rol Usuario. Los administradores ven todos los informes y no necesitan asignaciones.</p>
            <div className="toolbar" role="search"><SearchField id="wz-q" label="Buscar usuario" placeholder="Nombre, usuario o correo" value={query} onChange={setQuery} /></div>
            {candidates.length === 0 ? <EmptyState title="Sin usuarios disponibles">No hay usuarios que coincidan. Cree uno desde Usuarios.</EmptyState> : (
              <div className="selection-list" role="radiogroup" aria-label="Usuario">
                {candidates.map(u => (
                  <label key={u.id} className="selectable"><input type="radio" name="wz-user" value={u.id} checked={userId === u.id} onChange={() => { setUserId(u.id); setPicked([]); }} />
                    <span><strong>{u.displayName}</strong><span className="muted">{u.email || u.username}</span></span></label>
                ))}
              </div>
            )}
            <div className="wizard-actions"><Button disabled={!user} onClick={() => setStep(2)}>Continuar</Button></div>
          </>
        )}
        {step === 2 && (
          <>
            <h2>2. Seleccione la empresa</h2>
            <p className="muted">El usuario quedará relacionado con la empresa (si aún no lo está) y el acceso se concede a través de ella.</p>
            <div className="selection-list" role="radiogroup" aria-label="Empresa">
              {companies.filter(c => c.active).map(c => (
                <label key={c.id} className="selectable"><input type="radio" name="wz-company" value={c.id} checked={companyId === c.id} onChange={() => { setCompanyId(c.id); setPicked([]); }} />
                  <span><strong>{c.name}</strong><span className="muted">RUC {c.taxId}{user?.companyIds.includes(c.id) ? ' · ya relacionado' : ''}</span></span></label>
              ))}
            </div>
            <div className="wizard-actions"><Button variant="secondary" onClick={() => setStep(1)}>Atrás</Button><Button disabled={!company} onClick={() => setStep(3)}>Continuar</Button></div>
          </>
        )}
        {step === 3 && (
          <>
            <h2>3. Seleccione los informes de {company?.name}</h2>
            {options.length === 0 ? <EmptyState title="Esta empresa no tiene informes asignados">Asigne informes a la empresa antes de dárselos a un usuario.</EmptyState> : (
              <>
                <div className="selection-list" role="group" aria-label="Informes">
                  {options.map((r: Report) => {
                    const has = already.has(r.id);
                    return (
                      <label key={r.id} className={`selectable${has ? ' is-disabled' : ''}`}>
                        <input type="checkbox" value={r.id} disabled={has} checked={has || picked.includes(r.id)} onChange={e => setPicked(p => (e.target.checked ? [...p, r.id] : p.filter(id => id !== r.id)))} />
                        <span><strong>{r.name}</strong><span className="muted">{has ? 'Ya asignado a este usuario' : r.description}</span></span>
                      </label>
                    );
                  })}
                </div>
                <Button variant="ghost" size="sm" onClick={() => setPicked(options.filter(r => !already.has(r.id)).map(r => r.id))}>Seleccionar todos los disponibles</Button>
              </>
            )}
            <div className="wizard-actions"><Button variant="secondary" onClick={() => setStep(2)}>Atrás</Button><Button disabled={!picked.length} onClick={() => setStep(4)}>Continuar</Button></div>
          </>
        )}
        {step === 4 && (
          <>
            <h2>4. Confirme y guarde</h2>
            <dl className="summary-list">
              <dt>Usuario</dt><dd>{user?.displayName} <span className="muted">({user?.email || user?.username})</span></dd>
              <dt>Empresa</dt><dd>{company?.name}</dd>
              <dt>Informes</dt><dd>{picked.map(reportName).join(', ')}</dd>
            </dl>
            <p className="field-error" role="alert" hidden={!failure}>{failure}</p>
            <div className="wizard-actions">
              <Button variant="secondary" disabled={saving} onClick={() => setStep(3)}>Atrás</Button>
              <Button disabled={saving || !picked.length} aria-busy={saving} onClick={() => { void save(); }}>{saving ? 'Guardando…' : 'Guardar accesos'}</Button>
            </div>
          </>
        )}
      </div>
    </Page>
  );
}
