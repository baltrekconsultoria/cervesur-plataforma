import { afterEach, describe, expect, it, vi } from 'vitest';
import { PILOT_BANNER_ADMIN, PILOT_BANNER_ADMIN_WRITE, adminWriteReadOnly, pilotBanner, pilotNote, PILOT_NOTE_ADMIN } from './pilot';

/* UX-08H.1: la compilación de escritura nunca anuncia que se guardan cambios si el servidor no tiene ninguna escritura habilitada. */
afterEach(() => vi.unstubAllEnvs());

describe('aviso de la compilación admin-write', () => {
  it('con escrituras habilitadas anuncia que los cambios se guardan', () => {
    vi.stubEnv('VITE_PILOT', 'true'); vi.stubEnv('VITE_PILOT_MODE', 'admin-write');
    expect(adminWriteReadOnly(true)).toBe(false);
    expect(pilotBanner(true)).toBe(PILOT_BANNER_ADMIN_WRITE);
  });
  it('con el servidor en solo lectura (piloto_admin o todo apagado) muestra el aviso de solo consulta', () => {
    vi.stubEnv('VITE_PILOT', 'true'); vi.stubEnv('VITE_PILOT_MODE', 'admin-write');
    expect(adminWriteReadOnly(false)).toBe(true);
    expect(pilotBanner(false)).toBe(PILOT_BANNER_ADMIN);
    expect(pilotNote(false)).toBe(PILOT_NOTE_ADMIN);
  });
  it('en el piloto de solo lectura de la fase 1 nada cambia', () => {
    vi.stubEnv('VITE_PILOT', 'true'); vi.stubEnv('VITE_PILOT_MODE', 'admin');
    expect(adminWriteReadOnly(false)).toBe(false);
    expect(pilotBanner(true)).toBe(PILOT_BANNER_ADMIN);
  });
});
