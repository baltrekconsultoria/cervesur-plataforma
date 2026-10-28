import { useEffect } from 'react';
import { Link, useLocation } from 'react-router';
import { useApp } from '../../app/AppProvider';
import { Page } from '../../app/layout';
import { ButtonLink, EmptyState, ErrorState, Highlight, LoadingState, NoMatches, ResultBar, SearchField, SelectField, Tag } from '../../components/ui';
import { norm } from '../../lib/text';
import { useAsync } from '../../lib/useAsync';
import { useUrlFilters } from '../../lib/useUrlFilters';

const DEFAULTS = { q: '', company: 'all', group: 'all' };

export function LibraryPage() {
  const { api, version, capabilities, bump } = useApp();
  const location = useLocation();
  const filters = useUrlFilters(DEFAULTS);
  const data = useAsync(async () => {
    // Los nombres de empresa solo se piden si el proveedor indica a qué empresa pertenece cada informe.
    const [reports, companies] = await Promise.all([api.listReports(), capabilities.reportCompany ? api.listCompanies() : Promise.resolve([])]);
    return { reports: reports.filter(r => r.active), companies };
  }, [api, version]);

  const focusId = (location.state as { focusId?: string } | null)?.focusId;
  const ready = data.status === 'ready';
  useEffect(() => {
    if (ready && focusId) [...document.querySelectorAll<HTMLElement>('[data-report-id]')].find(el => el.dataset.reportId === focusId)?.focus();
  }, [ready, focusId]);

  const crumbs = [{ label: 'Portal', to: '/reportes' }, { label: 'Mis reportes' }];
  if (data.status === 'loading') return <Page title="Mis reportes" crumbs={crumbs}><LoadingState /></Page>;
  if (data.status === 'error') return <Page title="Mis reportes" crumbs={crumbs}><ErrorState onRetry={bump} /></Page>;

  const { reports, companies } = data.data;
  if (!reports.length) {
    return (
      <Page title="Mis reportes" crumbs={crumbs}>
        <EmptyState title="Aún no tiene informes disponibles" action={<ButtonLink to="/empresas" variant="secondary">Ver mis empresas</ButtonLink>}>
          Pertenecer a una empresa no concede acceso automático. Cuando un administrador le asigne un informe, aparecerá aquí.
        </EmptyState>
      </Page>
    );
  }

  const { q } = filters.values;
  // Un filtro que el proveedor no puede respaldar se ignora aunque venga en la URL.
  const company = capabilities.reportCompany ? filters.values.company : 'all';
  const group = capabilities.reportGroups ? filters.values.group : 'all';
  const companyName = (id: string) => companies.find(c => c.id === id)?.name ?? '';
  const companyIds = [...new Set(reports.flatMap(r => r.companyIds))];
  const scoped = company === 'all' ? reports : reports.filter(r => r.companyIds.includes(company));
  const groups = [...new Set(scoped.map(r => r.group).filter(Boolean))].sort((a, b) => a.localeCompare(b, 'es'));
  const needle = norm(q);
  const rows = scoped.filter(r => (group === 'all' || r.group === group) && (!needle || norm(`${r.name} ${r.description}`).includes(needle)));
  const from = `${location.pathname}${location.search}`;

  return (
    <Page title="Mis reportes" crumbs={crumbs}>
      <section>
        <div className="toolbar" role="search">
          <SearchField id="lib-q" label="Buscar informe" placeholder="Nombre o descripción" value={q} onChange={value => filters.set('q', value)} />
          {capabilities.reportCompany && <SelectField id="lib-company" label="Empresa" value={company} onChange={value => filters.setMany({ company: value, group: 'all' })} options={[['all', 'Todas mis empresas'], ...companyIds.map((id): [string, string] => [id, companyName(id) || 'Empresa'])]} />}
          {capabilities.reportGroups && <SelectField id="lib-group" label="Grupo" value={group} onChange={value => filters.set('group', value)} options={[['all', 'Todos los grupos'], ...groups.map((g): [string, string] => [g, g])]} />}
        </div>
        <ResultBar shown={rows.length} total={reports.length} noun="informes" canClear={filters.active} onClear={filters.clear} />
        {rows.length ? (
          <div className="grid report-grid">
            {rows.map(report => (
              <article className="card report-card" key={report.id}>
                {report.group ? <Tag>{report.group}</Tag> : null}
                <div><h2><Highlight text={report.name} query={q} /></h2><p><Highlight text={report.description} query={q} /></p></div>
                <footer>
                  <span className="company">{report.companyIds.map(companyName).filter(Boolean).join(' · ')}</span>
                  <Link className="btn btn-primary btn-sm" to={`/reportes/${encodeURIComponent(report.id)}`} state={{ from }} data-report-id={report.id} aria-label={`Abrir informe ${report.name}`}>Abrir</Link>
                </footer>
              </article>
            ))}
          </div>
        ) : <NoMatches noun="informe" onClear={filters.clear} />}
      </section>
    </Page>
  );
}
