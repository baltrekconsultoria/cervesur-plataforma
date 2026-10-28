import { useApp } from '../app/AppProvider';
import { ButtonLink } from './ui';

export type DeniedKind = 'resource' | 'section';

/**
 * Estado 403 compacto. Nunca recibe ni muestra nombres de informes o empresas:
 * el recurso denegado no se consulta ni se describe.
 */
export function DeniedState({ kind, backTo, backLabel }: { kind: DeniedKind; backTo: string; backLabel: string }) {
  const { user } = useApp();
  const admin = user?.role === 'admin';
  const text = kind === 'section'
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
