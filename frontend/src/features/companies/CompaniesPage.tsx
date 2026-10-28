import { useApp } from '../../app/AppProvider';
import { Page } from '../../app/layout';
import { ButtonLink, ErrorState, Highlight, LoadingState, NoMatches, ResultBar, SearchField } from '../../components/ui';
import { norm, plural } from '../../lib/text';
import { useAsync } from '../../lib/useAsync';
import { useUrlFilters } from '../../lib/useUrlFilters';

export function CompaniesPage() {
  const { api, version, capabilities, bump } = useApp();
  const filters = useUrlFilters({ q: '' });
  const data = useAsync(async () => {
    const [companies, reports] = await Promise.all([api.listCompanies(), capabilities.reportCompany ? api.listReports() : Promise.resolve([])]);
    return { companies: companies.filter(c => c.active), reports };
  }, [api, version]);
  const crumbs = [{ label: 'Portal', to: '/reportes' }, { label: 'Mis empresas' }];

  if (data.status === 'loading') return <Page title="Mis empresas" crumbs={crumbs}><LoadingState /></Page>;
  if (data.status === 'error') return <Page title="Mis empresas" crumbs={crumbs}><ErrorState onRetry={bump} /></Page>;

  const { companies, reports } = data.data;
  const q = filters.values.q;
  const needle = norm(q);
  const rows = companies.filter(c => !needle || norm(`${c.name} ${c.city} ${c.taxId}`).includes(needle));

  return (
    <Page title="Mis empresas" crumbs={crumbs}>
      <section>
        <div className="toolbar" role="search">
          <SearchField id="co-q" label="Buscar empresa" placeholder="Nombre, ciudad o RUC" value={q} onChange={value => filters.set('q', value)} />
        </div>
        <ResultBar shown={rows.length} total={companies.length} noun="empresas" canClear={filters.active} onClear={filters.clear} />
        {rows.length ? (
          <div className="grid company-grid">
            {rows.map(company => {
              const count = reports.filter(r => r.companyIds.includes(company.id) && r.active).length;
              return (
                <article className="card" key={company.id}>
                  <div><h2><Highlight text={company.name} query={q} /></h2><p><Highlight text={company.city} query={q} /> · RUC <Highlight text={company.taxId} query={q} /></p></div>
                  <footer>
                    {capabilities.reportCompany ? (
                      <>
                        <span className="company">{count ? plural(count, 'informe disponible', 'informes disponibles') : 'Sin informes asignados'}</span>
                        {count ? <ButtonLink to={`/reportes?company=${encodeURIComponent(company.id)}`} variant="secondary" size="sm">Ver informes</ButtonLink> : null}
                      </>
                    ) : (
                      <>
                        <span className="company">Empresa vinculada a su cuenta</span>
                        <ButtonLink to="/reportes" variant="secondary" size="sm">Mis reportes</ButtonLink>
                      </>
                    )}
                  </footer>
                </article>
              );
            })}
          </div>
        ) : <NoMatches noun="empresa" onClear={filters.clear} />}
      </section>
    </Page>
  );
}
