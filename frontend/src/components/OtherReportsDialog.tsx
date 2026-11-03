import { useEffect, useMemo, useRef, useState } from 'react';
import { Link } from 'react-router';
import type { Company, Report } from '../api/types';
import { norm } from '../lib/text';
import { Dialog } from './Dialog';
import { Button, Highlight, Tag } from './ui';

/**
 * UX-07E.C · «Otros informes»: ventana modal con buscador y lista desplazable, agrupada por empresa, para pasar a otro informe sin llenar la pantalla del visor con decenas de botones.
 * Reutilizable por el administrador y por el trabajador (el llamador decide qué informes ofrece: el servidor ya filtró los que cada uno puede ver).
 *  - Cierra con el botón, Escape (nativo de <dialog>) y clic en el fondo; devuelve el foco al botón que la abrió.
 *  - Muestra nombre completo y código (referencia histórica) de cada informe; «Sin enlace autorizado» solo cuando el servidor lo indica (`linkAuthorized === false`).
 *  - La lista nunca contiene enlaces de Power BI: solo nombre, código, estado y empresa.
 */
interface Props { reports: Report[]; companies: Company[]; from: string; onClose: () => void }

const NO_COMPANY = '__sin_empresa__';

export function OtherReportsDialog({ reports, companies, from, onClose }: Props) {
  const [q, setQ] = useState('');
  const searchRef = useRef<HTMLInputElement>(null);
  const needle = norm(q);
  // El foco inicial va al buscador (teclado inmediato); showModal() lo hace en el navegador, esto lo asegura también donde <dialog> no lo gestiona.
  useEffect(() => { const t = setTimeout(() => searchRef.current?.focus(), 0); return () => clearTimeout(t); }, []);
  const companyName = (id: string) => companies.find(c => c.id === id)?.name ?? 'Empresa';

  const groups = useMemo(() => {
    const filtered = reports.filter(r => !needle || norm(`${r.name} ${r.ref ?? ''} ${r.description}`).includes(needle));
    const map = new Map<string, Report[]>();
    for (const report of filtered) {
      const keys = report.companyIds.length ? report.companyIds : [NO_COMPANY];
      for (const key of keys) (map.get(key) ?? map.set(key, []).get(key)!).push(report);
    }
    return [...map.entries()]
      .map(([key, list]) => ({ key, name: key === NO_COMPANY ? 'Sin empresa asociada' : companyName(key), list: [...list].sort((a, b) => a.name.localeCompare(b.name, 'es')) }))
      .sort((a, b) => (a.key === NO_COMPANY ? 1 : b.key === NO_COMPANY ? -1 : a.name.localeCompare(b.name, 'es')));
    // companyName depende de `companies`, que ya es parte de la identidad de la lista
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [reports, companies, needle]);
  const shown = new Set(groups.flatMap(g => g.list.map(r => r.id))).size;

  return (
    <Dialog title="Otros informes" labelId="other-reports-title" onClose={onClose} closeOnBackdrop>
      <div className="modal-body report-picker">
        <label htmlFor="other-reports-q">
          Buscar por nombre o código
          <input id="other-reports-q" ref={searchRef} type="search" autoFocus autoComplete="off" value={q} placeholder="Nombre o código del informe" onChange={e => setQ(e.target.value)} />
        </label>
        <p className="muted report-picker-count" role="status" aria-live="polite">{shown} de {reports.length} informes</p>
        {groups.length === 0 ? (
          <p className="report-picker-empty">Ningún informe coincide con «{q}».</p>
        ) : (
          <div className="report-picker-list" tabIndex={0} aria-label="Lista de informes">
            {groups.map(group => (
              <section key={group.key} aria-label={group.name}>
                <h3>{group.name}</h3>
                <ul>
                  {group.list.map(report => (
                    <li key={report.id}>
                      <Link to={`/reportes/${encodeURIComponent(report.id)}`} state={{ from }} onClick={onClose}>
                        <span className="rp-name"><Highlight text={report.name} query={q} /></span>
                        <span className="rp-meta">
                          {report.ref ? <code className="rp-code"><Highlight text={report.ref} query={q} /></code> : null}
                          {report.linkAuthorized === false ? <Tag tone="warn">Sin enlace autorizado</Tag> : null}
                          {!report.active ? <Tag tone="off">Inactivo</Tag> : null}
                        </span>
                      </Link>
                    </li>
                  ))}
                </ul>
              </section>
            ))}
          </div>
        )}
      </div>
      <div className="modal-foot"><Button variant="secondary" onClick={onClose}>Cerrar</Button></div>
    </Dialog>
  );
}
