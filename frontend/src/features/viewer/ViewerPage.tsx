import { useEffect, useRef, useState } from 'react';
import { useLocation, useParams } from 'react-router';
import { isApiError, isDenied } from '../../api/errors';
import { useApp } from '../../app/AppProvider';
import { Page } from '../../app/layout';
import { DeniedState } from '../../components/DeniedState';
import { Button, ButtonLink, ErrorState, LoadingState, Tag } from '../../components/ui';
import { OtherReportsDialog } from '../../components/OtherReportsDialog';
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

  const [picker, setPicker] = useState(false);
  const opener = useRef<HTMLElement | null>(null);
  // Al cerrar la ventana el foco vuelve al botón que la abrió (se hace DESPUÉS de desmontar el <dialog>: mientras es modal, el resto de la página es inerte).
  useEffect(() => { if (!picker) opener.current?.focus(); }, [picker]);
  const data = useAsync(async () => {
    // El informe se pide primero: si el servidor lo deniega no se consulta nada más sobre él.
    const report = await api.getReport(reportId);
    const [companies, reports] = await Promise.all([
      capabilities.reportCompany ? api.listCompanies() : Promise.resolve([]),
      capabilities.reportCompany ? api.listReports() : Promise.resolve([])
    ]);
    // La empresa(s) del informe sale del catálogo compuesto (el detalle por id no la incluye).
    const known = reports.find(r => r.id === report.id)?.companyIds ?? report.companyIds;
    const owned = companies.filter(c => known.includes(c.id));
    return {
      report,
      companies: owned,
      // Trabajador: los otros informes de sus empresas. Administrador: todos los demás informes del catálogo (activos o no) para pasar de uno a otro sin volver al listado.
      siblings: admin ? reports.filter(r => r.id !== report.id) : owned.length ? reports.filter(r => r.id !== report.id && r.active && r.companyIds.some(id => known.includes(id))) : [],
      allCompanies: companies
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

  const { report, companies: owners, siblings, allCompanies } = data.data;
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
          <div className="viewer-actions">
            {siblings.length > 0 && <Button variant="secondary" aria-haspopup="dialog" onClick={event => { opener.current = event.currentTarget; setPicker(true); }}>Otros informes ({siblings.length})</Button>}
            <ButtonLink to={from} state={{ focusId: report.id }} variant="secondary">← Volver a {backLabel}{filtersKept ? ' (filtros conservados)' : ''}</ButtonLink>
          </div>
        </div>
        {picker && <OtherReportsDialog reports={siblings} companies={allCompanies} from={from} onClose={() => setPicker(false)} />}
        <ViewerPanel reportId={report.id} title={report.name} />
      </section>
    </Page>
  );
}
