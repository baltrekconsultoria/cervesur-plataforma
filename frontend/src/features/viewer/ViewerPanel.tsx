import { useEffect, useState } from 'react';
import { isApiError, isDenied } from '../../api/errors';
import { useApp } from '../../app/AppProvider';
import { DeniedState } from '../../components/DeniedState';
import { Button, ErrorState, LoadingState } from '../../components/ui';
import { buildPublicViewerUrl } from '../../domain/powerbiLink';
import { useAsync } from '../../lib/useAsync';
import { frameSandbox, isAllowedFrameUrl, resolveViewer, type ViewerMode } from '../../lib/viewerBase';

/** Si el marco no notifica su carga en este tiempo se avisa al usuario (Power BI o el navegador pueden estar bloqueando la inserción). */
const SLOW_MS = 12000;

function SimulatedPanel() {
  return (
    <div className="powerbi-mock">
      <div className="mock-inner">
        <div className="powerbi-icon" aria-hidden="true">▥</div>
        <h2>Visor Power BI simulado</h2>
        <p>En producción, este espacio cargará únicamente el informe autorizado mediante un token de inserción limitado. Esta base no incluye el SDK de Power BI ni realiza solicitudes externas.</p>
      </div>
    </div>
  );
}

/** Solo en la compilación `qa`: deja claro qué se está viendo. En producción no se muestra. */
function ModeBanner({ mode }: { mode: ViewerMode }) {
  if (mode === 'official') return null;
  return mode === 'qa-simulated'
    ? <p className="viewer-mode viewer-mode--sim" role="note" data-testid="viewer-mode" data-mode="qa-simulated"><strong>QA · visor simulado:</strong> se muestra una página local de pruebas; no se contacta con Power BI.</p>
    : <p className="viewer-mode viewer-mode--real" role="note" data-testid="viewer-mode" data-mode="qa-real"><strong>QA · Power BI público REAL:</strong> este navegador se conecta con <em>app.powerbi.com</em>. El backend, la base de datos y la autorización siguen siendo locales y sintéticos.</p>;
}

function StateBox({ title, children, tone = 'info' }: { title: string; children: React.ReactNode; tone?: 'info' | 'warn' }) {
  return (
    <div className={`viewer-state viewer-state--${tone}`} role="status" data-testid="viewer-state">
      <div className="powerbi-icon" aria-hidden="true">▥</div>
      <h2>{title}</h2>
      <p>{children}</p>
    </div>
  );
}

/**
 * Iframe de un informe publicado en web. La URL se RECONSTRUYE a partir de los componentes validados y debe pertenecer al origen
 * permitido (Power BI oficial; en modo `qa`, una página local de pruebas). El evento `load` solo indica que el navegador terminó
 * de cargar el marco: NO demuestra que Power BI haya mostrado el informe, por eso la ayuda y la alternativa permanecen visibles.
 */
function PublicFrame({ src, title, mode }: { src: string; title: string; mode: ViewerMode }) {
  const [loaded, setLoaded] = useState(false);
  const [slow, setSlow] = useState(false);
  useEffect(() => {
    if (loaded) return;
    const timer = window.setTimeout(() => setSlow(true), SLOW_MS);
    return () => window.clearTimeout(timer);
  }, [loaded]);
  return (
    <div className="powerbi-frame-wrap">
      <div className="powerbi-frame" data-state={loaded ? 'frame-loaded' : 'loading'}>
        {!loaded && <p className="powerbi-frame-loading" role="status">Cargando el informe de Power BI…{slow ? ' Está tardando más de lo normal.' : ''}</p>}
        <iframe
          title={`Informe: ${title}`}
          src={src}
          sandbox={frameSandbox(mode)}
          referrerPolicy="no-referrer"
          loading="lazy"
          allowFullScreen
          onLoad={() => setLoaded(true)}
        />
      </div>
      <p className="form-note viewer-help">
        ¿No ve el informe? Algunos navegadores, bloqueadores de contenido o la configuración de Power BI pueden impedir mostrarlo aquí.{' '}
        <a href={src} target="_blank" rel="noopener noreferrer">Abrir en una pestaña nueva</a>
      </p>
    </div>
  );
}

/** Visor del informe: pide al servidor la configuración autorizada y muestra el modo que corresponda. */
export function ViewerPanel({ reportId, title }: { reportId: string; title: string }) {
  const { api, capabilities, bump } = useApp();
  const [attempt, setAttempt] = useState(0);
  const config = useAsync(async () => (capabilities.liveViewer ? api.getViewerConfig(reportId) : { mode: 'simulated' as const }), [api, reportId, attempt, capabilities.liveViewer]);

  if (config.status === 'loading') return <LoadingState label="Preparando el visor…" />;
  if (config.status === 'error') {
    if (isApiError(config.error) && config.error.kind === 'unauthorized') return null;
    if (isDenied(config.error)) return <DeniedState kind="resource" backTo="/reportes" backLabel="Mis reportes" />;
    return <ErrorState onRetry={() => { setAttempt(a => a + 1); bump(); }} />;
  }
  const value = config.data;
  if (value.mode === 'simulated') return <SimulatedPanel />;
  if (value.mode === 'private') {
    return <StateBox title="Este informe requiere configuración de acceso a Power BI">Está registrado como inserción autenticada de Power BI, que necesita permisos y licencias de Microsoft que todavía no están configurados en este portal. No se utiliza ningún acceso general: pida a un administrador que revise su configuración.</StateBox>;
  }
  if (value.mode === 'public') {
    const { base, mode } = resolveViewer(import.meta.env, window.location.origin);
    const src = buildPublicViewerUrl(value.url, base);
    if (src && isAllowedFrameUrl(src, base)) return <><ModeBanner mode={mode} /><PublicFrame src={src} title={title} mode={mode} /></>;
  }
  return (
    <StateBox title="El enlace de este informe no es compatible con el visor" tone="warn">
      El administrador debe registrar un enlace de «Publicar en web» de Power BI. Por seguridad no se muestran enlaces de otros sitios.{' '}
      <Button variant="secondary" size="sm" onClick={() => setAttempt(a => a + 1)}>Reintentar</Button>
    </StateBox>
  );
}
