import { useState } from 'react';
import { useSearchParams } from 'react-router';
import { isApiError } from '../../api/errors';
import type { GrantResult, Report, ReportLink } from '../../api/types';
import { useApp } from '../../app/AppProvider';
import { Page } from '../../app/layout';
import { Dialog, DialogFooter, submitHandler } from '../../components/Dialog';
import { Button, EmptyState, ErrorState, LoadingState, SelectField, StatusTag, Tag } from '../../components/ui';
import { useAsync } from '../../lib/useAsync';

const crumbs = [{ label: 'Administración', to: '/admin' }, { label: 'Informes por empresa' }];

/** Confirmación antes de desvincular un informe de una empresa. Solo se cierra con éxito si el servidor confirmó la baja. */
function UnlinkDialog({ link, reportName, companyName, onClose, onDone }: { link: ReportLink; reportName: string; companyName: string; onClose: () => void; onDone: () => void }) {
  const { api } = useApp();
  const [saving, setSaving] = useState(false);
  const [failure, setFailure] = useState('');
  const confirm = async () => {
    setSaving(true);
    setFailure('');
    try {
      await api.unlinkReport(link.id);
      onDone();
    } catch (error) {
      if (isApiError(error) && error.kind === 'unauthorized') return;
      setFailure(isApiError(error) && error.message ? error.message : 'No se pudo desvincular el informe. Intente nuevamente.');
      setSaving(false);
    }
  };
  return (
    <Dialog title="Desvincular informe" labelId="ul-title" onClose={() => { if (!saving) onClose(); }}>
      <form onSubmit={submitHandler(confirm)}>
        <div className="modal-body">
          <p>¿Desvincular el informe <strong>{reportName}</strong> de <strong>{companyName}</strong>?</p>
          <p className="form-note">Los usuarios que lo veían a través de esta empresa dejarán de verlo. El informe no se elimina y, si se vuelve a asociar, esos usuarios lo recuperan.</p>
        </div>
        <DialogFooter error={failure} saving={saving} submitLabel="Desvincular" savingLabel="Desvinculando…" variant="danger" onCancel={onClose} />
      </form>
    </Dialog>
  );
}

/** Asociación empresa ↔ informe: elegir una empresa, ver sus informes, asociar los disponibles y desvincular con confirmación. */
export function CompanyReportsPage() {
  const { api, version, bump } = useApp();
  const [params, setParams] = useSearchParams();
  const data = useAsync(async () => {
    const [companies, reports, links] = await Promise.all([api.listCompanies(), api.listReports(), api.listReportLinks()]);
    return { companies, reports, links };
  }, [api, version]);
  const [picked, setPicked] = useState<string[]>([]);
  const [saving, setSaving] = useState(false);
  const [failure, setFailure] = useState('');
  const [results, setResults] = useState<GrantResult[] | null>(null);
  const [unlinking, setUnlinking] = useState<ReportLink | null>(null);
  const [notice, setNotice] = useState('');
  const companyId = params.get('company') ?? '';

  const choose = (id: string) => { setParams(id === '' ? {} : { company: id }, { replace: true }); setPicked([]); setResults(null); setFailure(''); setNotice(''); };

  if (data.status === 'loading') return <Page title="Informes por empresa" crumbs={crumbs}><LoadingState /></Page>;
  if (data.status === 'error') return <Page title="Informes por empresa" crumbs={crumbs}><ErrorState onRetry={bump} /></Page>;
  const { companies, reports, links } = data.data;
  const company = companies.find(c => c.id === companyId);
  const reportById = new Map(reports.map(r => [r.id, r] as const));
  const companyLinks = company ? links.filter(l => l.companyId === company.id && reportById.has(l.reportId)) : [];
  const linkedIds = new Set(companyLinks.map(l => l.reportId));
  const available = company ? reports.filter(r => r.active && !linkedIds.has(r.id)) : [];

  const save = async () => {
    if (!company || !picked.length) return;
    setSaving(true);
    setFailure('');
    setResults(null);
    try {
      const outcome = await api.linkReports({ companyId: company.id, reportIds: picked });
      setResults(outcome);
      const done = outcome.filter(r => r.status !== 'failed').map(r => r.reportId);
      setPicked(p => p.filter(id => !done.includes(id)));
      bump();
    } catch (error) {
      if (isApiError(error) && error.kind === 'unauthorized') return;
      setFailure(isApiError(error) && error.message ? error.message : 'No se pudieron guardar las asociaciones.');
    } finally {
      setSaving(false);
    }
  };

  return (
    <Page title="Informes por empresa" crumbs={crumbs}>
      {notice && <p className="success-box" role="status">{notice}</p>}
      {unlinking && <UnlinkDialog link={unlinking} reportName={reportById.get(unlinking.reportId)?.name ?? ''} companyName={company?.name ?? ''} onClose={() => setUnlinking(null)} onDone={() => { setNotice(`«${reportById.get(unlinking.reportId)?.name ?? 'Informe'}» desvinculado de ${company?.name ?? ''}.`); setUnlinking(null); bump(); }} />}
      <div className="toolbar" role="search">
        <SelectField id="cr-company" label="Empresa" value={company?.id ?? ''} onChange={choose} options={[['', 'Seleccione una empresa…'], ...companies.filter(c => c.active).map((c): [string, string] => [c.id, c.name])]} />
      </div>
      {!company ? <EmptyState title="Seleccione una empresa">Verá los informes asociados y podrá asociar o desvincular informes. Un mismo informe puede pertenecer a varias empresas.</EmptyState> : (
        <div className="dash-cols">
          <div className="panel">
            <h2>Informes asociados ({companyLinks.length})</h2>
            {companyLinks.length === 0 ? <p className="muted">Esta empresa aún no tiene informes asociados.</p> : (
              <ul className="attention-list" aria-label="Informes asociados">
                {companyLinks.map(l => {
                  const report = reportById.get(l.reportId) as Report;
                  return (
                    <li key={l.id}>
                      <span><strong>{report.name}</strong> <StatusTag active={report.active} />{report.companyIds.length > 1 && <> <Tag>Compartido con {report.companyIds.length - 1} más</Tag></>}</span>
                      <Button variant="secondary" size="sm" aria-label={`Desvincular ${report.name} de ${company.name}`} onClick={() => { setNotice(''); setUnlinking(l); }}>Desvincular</Button>
                    </li>
                  );
                })}
              </ul>
            )}
          </div>
          <div className="panel">
            <h2>Informes disponibles ({available.length})</h2>
            {available.length === 0 ? <p className="muted">No hay más informes activos para asociar. Cree uno nuevo desde Reportes.</p> : (
              <>
                <div className="selection-list" role="group" aria-label="Informes disponibles">
                  {available.map(r => (
                    <label key={r.id} className="selectable"><input type="checkbox" value={r.id} checked={picked.includes(r.id)} disabled={saving} onChange={e => setPicked(p => (e.target.checked ? [...p, r.id] : p.filter(id => id !== r.id)))} />
                      <span><strong>{r.name}</strong><span className="muted">{r.companyIds.length ? `Ya en: ${r.companyIds.map(id => companies.find(c => c.id === id)?.name).filter(Boolean).join(' · ')}` : 'Sin empresa'}</span></span></label>
                  ))}
                </div>
                <p className="field-error" role="alert" hidden={!failure}>{failure}</p>
                <div className="wizard-actions">
                  <Button disabled={saving || !picked.length} aria-busy={saving} onClick={() => { void save(); }}>{saving ? 'Guardando…' : `Asociar seleccionados (${picked.length})`}</Button>
                </div>
              </>
            )}
            {results && (
              <ul className="attention-list" aria-label="Resultado de la asociación">
                {results.map(r => (
                  <li key={r.reportId} data-status={r.status}><span>{reportById.get(r.reportId)?.name ?? r.reportId}</span>
                    {r.status === 'failed' ? <Tag tone="warn">No guardado · {r.message}</Tag> : <Tag tone="ok">{r.status === 'granted' ? 'Asociado' : 'Ya estaba asociado'}</Tag>}
                  </li>
                ))}
              </ul>
            )}
          </div>
        </div>
      )}
    </Page>
  );
}
