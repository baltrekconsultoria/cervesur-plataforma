/**
 * Piloto visual (UX-07D.4 / 4B): compilaciones de consulta con datos reales y solo lectura. Las fija `npm run build:pilot` (operador único, VITE_PILOT_MODE=operador)
 * y `npm run build:pilot-usuarios` (usuarios reales con permisos individuales, VITE_PILOT_MODE=usuarios). Solo añaden contexto visible (aviso y notas de acceso); la
 * autorización y la ausencia de escrituras las aplica SIEMPRE el servidor.
 */
export type PilotMode = 'operador' | 'usuarios' | 'admin';

export const pilotMode = (): PilotMode | null => (import.meta.env.VITE_PILOT !== 'true' ? null : import.meta.env.VITE_PILOT_MODE === 'usuarios' ? 'usuarios' : import.meta.env.VITE_PILOT_MODE === 'admin' ? 'admin' : 'operador');
export const isPilotBuild = (): boolean => pilotMode() !== null;

export const PILOT_BANNER = 'PILOTO · Datos reales de Cervesur · Solo consulta · Operador autorizado';
export const PILOT_NOTE = 'Los enlaces de los informes solo se entregan para los informes que el operador autorizó. Ninguna pantalla de este piloto modifica datos.';
export const PILOT_BANNER_USUARIOS = 'PILOTO · Usuarios autorizados · Solo consulta · Sus informes asignados';
export const PILOT_NOTE_USUARIOS = 'Verá únicamente los informes que su empresa tiene asignados. Esta versión no modifica datos y su sesión se cierra por inactividad.';

// UX-07E.B: piloto ADMINISTRATIVO de solo lectura (`npm run build:pilot-admin`): las pantallas de administración se muestran, pero sin ninguna acción de escritura (el servidor las rechaza con 403).
export const PILOT_BANNER_ADMIN = 'PILOTO ADMINISTRATIVO · Datos reales de Cervesur · Solo consulta · Administradores autorizados';
export const PILOT_NOTE_ADMIN = 'Esta versión no crea, edita, activa, desactiva ni asigna nada: solo muestra usuarios, empresas, informes y asignaciones. Los enlaces de los informes solo se entregan para los autorizados en el piloto.';
export const isAdminPilot = (): boolean => pilotMode() === 'admin';

export const pilotBanner = (): string => (pilotMode() === 'admin' ? PILOT_BANNER_ADMIN : pilotMode() === 'usuarios' ? PILOT_BANNER_USUARIOS : PILOT_BANNER);
export const pilotNote = (): string => (pilotMode() === 'admin' ? PILOT_NOTE_ADMIN : pilotMode() === 'usuarios' ? PILOT_NOTE_USUARIOS : PILOT_NOTE);