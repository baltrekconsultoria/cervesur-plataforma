import { describe, expect, it, vi } from 'vitest';
import { ApiError } from '../errors';
import { createMemoryTokenStore } from '../tokenStore';
import { HttpApiClient } from './httpApi';

const json = (status: number, body: unknown) => new Response(JSON.stringify(body), {
  status, headers: { 'Content-Type': 'application/json' }
});

function setup(response = json(200, { success: true })) {
  const calls: Array<{ path: string; method: string; body: unknown }> = [];
  const fetchImpl = vi.fn(async (url: string, init: RequestInit) => {
    calls.push({
      path: new URL(url).pathname,
      method: init.method ?? 'GET',
      body: init.body ? JSON.parse(init.body as string) as unknown : null
    });
    return response;
  });
  const tokens = createMemoryTokenStore();
  tokens.set('test-token');
  const api = new HttpApiClient({ baseUrl: 'http://127.0.0.1:9', tokens, fetchImpl: fetchImpl as unknown as typeof fetch });
  const allow = () => Object.assign(api.capabilities, {
    adminWrites: true, writes: { empresas: true, reportes: true, accesos: true, usuarios: true }, usersPasswordReset: true
  });
  return { api, calls, fetchImpl, allow };
}

describe('P5-UI02: reasignación de contraseña por HTTP', () => {
  it('sin autorización P5-R explícita nunca envía la contraseña', async () => {
    const { api, calls } = setup();
    await expect(api.resetUserPassword('2', 'una-contrasena-segura')).rejects.toMatchObject({ status: 403 });
    expect(calls).toEqual([]);
  });

  it('con permiso general pero sin permiso sensible tampoco envía nada', async () => {
    const { api, calls } = setup();
    Object.assign(api.capabilities, {
      adminWrites: true, writes: { empresas: true, reportes: true, accesos: true, usuarios: true }
    });
    await expect(api.resetUserPassword('2', 'una-contrasena-segura')).rejects.toMatchObject({ status: 403 });
    expect(calls).toEqual([]);
  });

  it('envía solo password al endpoint protegido y confirma éxito solamente tras success:true', async () => {
    const { api, calls, allow } = setup();
    allow();
    const newPassword = 'una-contrasena-segura';
    await expect(api.resetUserPassword('2', newPassword)).resolves.toBeUndefined();
    expect(calls).toEqual([{
      method: 'PUT', path: '/api/users/2/password', body: { password: newPassword }
    }]);
  });

  it('id inválido no alcanza al servidor', async () => {
    const { api, calls, allow } = setup();
    allow();
    await expect(api.resetUserPassword('../2', 'una-contrasena-segura')).rejects.toBeInstanceOf(ApiError);
    expect(calls).toEqual([]);
  });

  it('403 del servidor se mantiene como rechazo y nunca se reporta éxito', async () => {
    const { api, allow } = setup(json(403, { success: false, message: 'Operación desactivada' }));
    allow();
    await expect(api.resetUserPassword('2', 'una-contrasena-segura')).rejects.toMatchObject({ status: 403, kind: 'forbidden' });
  });

  it('HTTP 200 con success:false sigue siendo error', async () => {
    const { api, allow } = setup(json(200, { success: false, message: 'Falló la auditoría' }));
    allow();
    await expect(api.resetUserPassword('2', 'una-contrasena-segura')).rejects.toMatchObject({ status: 422 });
  });
});
