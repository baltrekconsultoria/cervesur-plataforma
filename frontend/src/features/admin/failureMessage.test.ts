import { describe, expect, it } from 'vitest';
import { ApiError } from '../../api/errors';
import { canWrite, type ApiCapabilities } from '../../api/types';
import { failureMessage } from './ConfirmActionDialog';

describe('failureMessage (UX-08I)', () => {
  it('403 muestra el mensaje del servidor o uno explícito, nunca el genérico', () => {
    expect(failureMessage(new ApiError(403, 'Esta operación está desactivada en el servidor'), 'fallback')).toBe('Esta operación está desactivada en el servidor');
    expect(failureMessage(new ApiError(403, ''), 'fallback')).toMatch(/no está habilitada en el servidor/);
  });
  it('409 conserva el mensaje del servidor (p. ej. espera de reactivación)', () => {
    expect(failureMessage(new ApiError(409, 'Debe esperar 24 h desde la desactivación'), 'fallback')).toBe('Debe esperar 24 h desde la desactivación');
  });
  it('5xx/503 aclara que no se aplicó ningún cambio', () => {
    expect(failureMessage(new ApiError(503, 'Servicio no disponible'), 'fallback')).toMatch(/No se aplicó ningún cambio/);
    expect(failureMessage(new ApiError(500, ''), 'No se pudo desactivar.')).toMatch(/^No se pudo desactivar\. No se aplicó ningún cambio/);
  });
  it('un error que no es de la API usa el mensaje de respaldo', () => {
    expect(failureMessage(new Error('x'), 'fallback')).toBe('fallback');
  });
});

describe('Usuarios bloqueado: sin controles de escritura', () => {
  const base = { adminWrites: true, writes: { empresas: true, reportes: true, accesos: true, usuarios: false } } as unknown as ApiCapabilities;
  it('con el servidor en fase 2A no se ofrece ninguna escritura de usuarios', () => {
    expect(canWrite(base, 'usuarios')).toBe(false);
    expect(canWrite(base, 'empresas')).toBe(true);
  });
  it('encendido el interruptor no sensible se ofrecen los controles comunes, pero no rol/contraseña', () => {
    const on = { ...base, writes: { ...(base as unknown as { writes: object }).writes, usuarios: true }, usersSensitive: false } as unknown as ApiCapabilities;
    expect(canWrite(on, 'usuarios')).toBe(true);
    expect(on.usersSensitive).toBe(false);
  });
});
