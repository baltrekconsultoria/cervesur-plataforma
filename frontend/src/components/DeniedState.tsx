import { useApp } from '../app/AppProvider';
import { pilotMode } from '../app/pilot';
import { ButtonLink } from './ui';

export type DeniedKind = 'resource' | 'section';

/**
 * Estado 403 compacto. Nunca recibe ni muestra nombres de informes o empresas:
 * el recurso denegado no se consulta ni se describe.
 */
export function DeniedState({ kind, backTo: backToIn, backLabel: backLabelIn }: { kind: DeniedKind; backTo: string; backLabel: string }) {
  let backTo = backToIn; let backLabel = backLabelIn;
  const { user } = useApp();
  const admin = user?.role === 'admin';
  // Piloto administrativo (UX-07E.B): un informe sin autorizar para el piloto se explica como tal y se vuelve al listado administrativo.
  const adminPilot = (pilotMode() === 'admin' || pilotMode() === 'admin-write') && kind === 'resource';
  if (admin && backTo === '/reportes') { backTo = '/admin/reportes'; backLabel = 'Reportes'; }
  const text = adminPilot
    ? 'Este informe todavía no está autorizado para mostrarse en el piloto administrativo. El responsable del piloto debe autorizarlo; el listado sigue disponible.'
    : pilotMode() === 'operador' && kind === 'resource'
    ? 'Este informe todavía no está autorizado para mostrarse en el piloto. El responsable del piloto debe autorizarlo; el catálogo sigue disponible.'
    : kind === 'section'
    ? 'Esta sección es solo para administradores. Sus informes asignados siguen disponibles.'
    : admin
      ? 'Su cuenta no puede abrir la vista previa de este contenido. Revise la asignación del informe o los permisos de su cuenta.'
      : 'No tiene permiso para abrir este contenido o ya no está disponible. Si lo necesita, pida una asignación a un administrador. Sus informes asignados siguen disponibles.';
  return (
    <div className="denied" role="alert">
      <span className="tag tag--warn">Error 403</span>
      <p>{text}</p>
      <ButtonLink to={backTo} variant="secondary" size="sm">← Volver a {backLabel}</ButtonLink>
    </div>
  );
}
