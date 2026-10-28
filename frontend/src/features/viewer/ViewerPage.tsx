import { Link, useLocation, useParams } from 'react-router';
import { isApiError, isDenied } from '../../api/errors';
import { useApp } from '../../app/AppProvider';
import { Page } from '../../app/layout';
import { DeniedState } from '../../components/DeniedState';
import { ButtonLink, ErrorState, LoadingState, Tag } from '../../components/ui';
import { ViewerPanel } from './ViewerPanel';
import { useAsync } from '../../lib/useAsync';

const isInternal = (value: unknown): value is string => typeof value === 'string' && value.startsWith('/') && !value.startsWith('//') && !value.includes('\\');

/** La clave fuerza un montaje nuevo al cambiar de informe (enlaces a "más informes"). */
export function ViewerPage() {
  const { reportId = '' } = useParams();
  return <ViewerContent key={reportId} reportId={reportId} />;
}

function ViewerContent({ reportId }: { reportId: string }) {
  const { api, user, version, capabilities, bump } = useApp();
  const location = useLocation();
  const admin = user?.role === 'admin';
  const stateFrom = (location.state as { from?: unknown } | null)?.from;
  const from = isInternal(stateFrom) ? stateFrom : admin ? '/admin/reportes' : '/reportes';
  const backLabel = from.startsWith('/admin/reportes') ? 'Reportes' : from.startsWith('/empresas') ? 'Mis empresas' : admin ? 'Reportes' : 'Mis reportes';
  const filtersKept = from.includes('?');
  const back = { label: backLabel, to: from };

  const data = useAsync(async () => {
    // El informe se pide primero: si el servidor lo deniega no se consulta nada más sobre él.
    const report = await api.getReport(reportId);
    const [companies, reports] = await Promise.all([
      capabilities.reportCompany ? api.listCompanies() : Promise.resolve([]),
      capabilities.reportCompany ? api.listReports() : Promise.resolve([])
    ]);
    // La empresa(s) del informe sale del catálogo compuesto (el detalle por UUID no la incluye).
    const known = reports.find(r => r.id === report.id)?.companyIds ?? report.companyIds;
    const owned = companies.filter(c => known.includes(c.id));
    return {
      report,
      companies: owned,
      siblings: owned.length ? reports.filter(r => r.id !== report.id && r.active && r.companyIds.some(id => known.includes(id))) : []
    };
  }, [api, reportId, version]);

  if (data.status === 'loading') return <Page title="Informe" crumbs={[back, { label: 'Informe' }]}><LoadingState label="Cargando informe…" /></Page>;
  if (data.status === 'error') {
    if (isApiError(data.error) && data.error.kind === 'unauthorized') return null;
    if (isDenied(data.error)) {
      return <Page title="Acceso denegado" crumbs={[back, { label: 'Acceso denegado' }]}><DeniedState kind="resource" backTo={from} backLabel={backLabel} /></Page>;
    }
    return <Page title="Informe" crumbs={[back, { label: 'Informe' }]}><ErrorState onRetry={bump} /></Page>;
  }

  const { report, companies: owners, siblings } = data.data;
  const ownerNames = owners.map(c => c.name).join(' · ');
  return (
    <Page title={report.name} crumbs={[back, ...(ownerNames ? [{ label: ownerNames }] : []), { label: report.name }]}>
      <section className="viewer">
        <div className="viewer-head">
          <div>
            <div className="viewer-meta">{report.group ? <Tag>{report.group}</Tag> : null}{ownerNames || !report.active ? <span className="muted">{ownerNames}{report.active ? '' : ' · Informe inactivo (vista previa)'}</span> : null}</div>
            <h2>{report.name}</h2>
            <p className="muted">{report.description}</p>
          </div>
          <ButtonLink to={from} state={{ focusId: report.id }} variant="secondary">← Volver a {backLabel}{filtersKept ? ' (filtros conservados)' : ''}</ButtonLink>
        </div>
        {siblings.length > 0 && (
          <div className="sibling-nav">Más informes de {ownerNames}: {siblings.map(s => <Link key={s.id} to={`/reportes/${encodeURIComponent(s.id)}`} state={{ from }}>{s.name}</Link>)}</div>
        )}
        <ViewerPanel reportId={report.id} title={report.name} />
      </section>
    </Page>
  );
}
