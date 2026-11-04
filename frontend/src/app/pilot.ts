/**
 * Piloto visual (UX-07D.4 / 4B): compilaciones de consulta con datos reales y solo lectura. Las fija `npm run build:pilot` (operador único, VITE_PILOT_MODE=operador)
 * y `npm run build:pilot-usuarios` (usuarios reales con permisos individuales, VITE_PILOT_MODE=usuarios). Solo añaden contexto visible (aviso y notas de acceso); la
 * autorización y la ausencia de escrituras las aplica SIEMPRE el servidor.
 */
export type PilotMode = 'operador' | 'usuarios' | 'admin' | 'admin-write';

export const pilotMode = (): PilotMode | null => (import.meta.env.VITE_PILOT !== 'true' ? null : import.meta.env.VITE_PILOT_MODE === 'usuarios' ? 'usuarios' : import.meta.env.VITE_PILOT_MODE === 'admin' ? 'admin' : import.meta.env.VITE_PILOT_MODE === 'admin-write' ? 'admin-write' : 'operador');
export const isPilotBuild = (): boolean => pilotMode() !== null;

export const PILOT_BANNER = 'PILOTO · Datos reales de Cervesur · Solo consulta · Operador autorizado';
export const PILOT_NOTE = 'Los enlaces de los informes solo se entregan para los informes que el operador autorizó. Ninguna pantalla de este piloto modifica datos.';
export const PILOT_BANNER_USUARIOS = 'PILOTO · Usuarios autorizados · Solo consulta · Sus informes asignados';
export const PILOT_NOTE_USUARIOS = 'Verá únicamente los informes que su empresa tiene asignados. Esta versión no modifica datos y su sesión se cierra por inactividad.';

// UX-07E.B: piloto ADMINISTRATIVO de solo lectura (`npm run build:pilot-admin`): las pantallas de administración se muestran, pero sin ninguna acción de escritura (el servidor las rechaza con 403).
export const PILOT_BANNER_ADMIN = 'PILOTO ADMINISTRATIVO · Datos reales de Cervesur · Solo consulta · Administradores autorizados';
export const PILOT_NOTE_ADMIN = 'Esta versión no crea, edita, activa, desactiva ni asigna nada: solo muestra usuarios, empresas, informes y asignaciones. Los enlaces de los informes solo se entregan para los autorizados en el piloto.';
export const isAdminPilot = (): boolean => pilotMode() === 'admin';

// UX-08H: administración CON escrituras (`npm run build:admin-write`). Las acciones que se ofrecen salen de lo que el servidor declara habilitado; el servidor las vuelve a autorizar siempre.
export const PILOT_BANNER_ADMIN_WRITE = 'ADMINISTRACIÓN · Datos reales de Cervesur · Los cambios se guardan y quedan registrados en el historial';
export const PILOT_NOTE_ADMIN_WRITE = 'Solo se ofrecen las acciones que el servidor tiene habilitadas. Las bajas son lógicas (se pueden reactivar) y los enlaces de los informes nunca se muestran: solo se escriben.';
export const isAdminWrite = (): boolean => pilotMode() === 'admin-write';

/** UX-08H.1: la compilación de escritura publicada con el servidor aún en solo lectura (o con todo apagado) debe decirlo: nunca anunciar que se guardan cambios si no es cierto. */
export const adminWriteReadOnly = (writesEnabled: boolean): boolean => pilotMode() === 'admin-write' && !writesEnabled;

export const pilotBanner = (writesEnabled = true): string => (adminWriteReadOnly(writesEnabled) ? PILOT_BANNER_ADMIN : pilotMode() === 'admin-write' ? PILOT_BANNER_ADMIN_WRITE : pilotMode() === 'admin' ? PILOT_BANNER_ADMIN : pilotMode() === 'usuarios' ? PILOT_BANNER_USUARIOS : PILOT_BANNER);
export const pilotNote = (writesEnabled = true): string => (adminWriteReadOnly(writesEnabled) ? PILOT_NOTE_ADMIN : pilotMode() === 'admin-write' ? PILOT_NOTE_ADMIN_WRITE : pilotMode() === 'admin' ? PILOT_NOTE_ADMIN : pilotMode() === 'usuarios' ? PILOT_NOTE_USUARIOS : PILOT_NOTE);