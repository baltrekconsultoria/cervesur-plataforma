import { describe, expect, it, vi } from 'vitest';
import { ApiError, isDenied } from './errors';
import { assertIsolatedBackend, createApiServices } from './factory';
import { guardUnauthorized } from './guard';
import { parseRole, toActive, toCompany, toReport, toUser, type CompanyDto, type UserDto } from './http/adapters';
import { MockApi } from './mock/mockApi';
import { createMemoryTokenStore } from './tokenStore';

describe('adaptadores del backend PHP (contrato real verificado en UX-06A)', () => {
  it('política de roles: admin, user y el heredado usuario; todo lo demás se rechaza', () => {
    expect(parseRole('admin')).toBe('admin');
    expect(parseRole('user')).toBe('user');
    expect(parseRole('usuario')).toBe('user');
    for (const bad of ['Admin', 'ADMIN', ' admin ', 'superadmin', 'administrador', '', undefined, null, 1, true, {}]) {
      expect(() => parseRole(bad), JSON.stringify(bad) ?? 'undefined').toThrow(ApiError);
      try { parseRole(bad); } catch (e) { expect(e).toMatchObject({ kind: 'invalid_response' }); }
    }
  });

  it('mapea el usuario real (id, first_name, last_name, is_active) y compone el nombre', () => {
    const user = toUser({ id: 7, username: 'ana', email: 'ana@example.invalid', first_name: 'Ana', last_name: 'Díaz', phone: null, role: 'usuario', is_active: 1 });
    expect(user).toMatchObject({ id: '7', username: 'ana', displayName: 'Ana Díaz', role: 'user', active: true, phone: '', companyIds: [] });
    expect(toUser({ id: 1, username: 'sin.nombre', email: 'a@example.invalid', role: 'user' }).displayName).toBe('sin.nombre');
  });

  it('un usuario con rol inesperado o respuesta incompleta se rechaza', () => {
    expect(() => toUser({ id: 1, username: 'x', email: 'x@example.invalid', role: 'superadmin' })).toThrow(/rol no reconocido/);
    expect(() => toUser({ id: 1, username: '', email: 'x@example.invalid', role: 'user' })).toThrow(ApiError);
    expect(() => toUser(null as unknown as UserDto)).toThrow(ApiError);
  });

  it('interpreta distintas formas de "activo"', () => {
    expect([true, 1, '1', 'true'].every(toActive)).toBe(true);
    expect([false, 0, '0', 'no'].some(toActive)).toBe(false);
    expect(toActive(undefined)).toBe(true);
  });

  it('el reporte usa reporte_id y descarta enlace, creador y grupo en UUID', () => {
    const dto = { id: 6, nombre: 'Flujo', descripcion: null, enlace: 'https://example.invalid/secreto', grupo_id: '00000000-0000-0000-0000-000000000003', reporte_id: '00000000-0000-0000-0000-000000000066', created_by: 1, created_by_username: 'usera', activo: 1 };
    const report = toReport(dto);
    expect(report).toEqual({ id: '00000000-0000-0000-0000-000000000066', name: 'Flujo', description: '', companyId: '', companyIds: [], group: '', active: true });
    expect(JSON.stringify(report)).not.toMatch(/example\.invalid|usera|00000000-0000-0000-0000-000000000003/);
    expect(toReport({ id: 5, nombre: 'Sin UUID' })).toBeNull();
  });

  it('la empresa descarta todos los campos powerbi_*', () => {
    const dto = { id: 2, nombre: 'ABC', ruc: '20123456789', ciudad: 'Lima', activo: 1, powerbi_tenant: 'TENANT-NO-SALE', powerbi_client: 'CLIENT-NO-SALE' } as CompanyDto;
    const company = toCompany(dto);
    expect(company).toEqual({ id: '2', name: 'ABC', city: 'Lima', taxId: '20123456789', active: true });
    expect(JSON.stringify(company)).not.toMatch(/NO-SALE/);
  });
});

describe('fábrica de proveedores', () => {
  const tokens = createMemoryTokenStore();
  it('usa el proveedor simulado por defecto', () => {
    const services = createApiServices({}, tokens);
    expect(services.provider).toBe('mock');
    expect(services.mock).toBeInstanceOf(MockApi);
  });
  it('rechaza el proveedor HTTP si no se habilita explícitamente', () => {
    expect(() => createApiServices({ VITE_API_PROVIDER: 'http', VITE_API_BASE_URL: 'http://127.0.0.1:8080' }, tokens)).toThrow(/desactivado/);
  });
  it('rechaza backends que no sean locales (producción incluida)', () => {
    for (const url of ['https://portal.example.com', 'http://10.0.0.5:8080', 'http://127.0.0.1.evil.example', 'http://localhost.evil.example']) {
      expect(() => createApiServices({ VITE_API_PROVIDER: 'http', VITE_ENABLE_HTTP_PROVIDER: 'true', VITE_API_BASE_URL: url }, tokens), url).toThrow(/locales aislados/);
    }
    expect(() => assertIsolatedBackend('no es url')).toThrow();
  });
  it('permite el proveedor HTTP solo con bandera y localhost', () => {
    const services = createApiServices({ VITE_API_PROVIDER: 'http', VITE_ENABLE_HTTP_PROVIDER: 'true', VITE_API_BASE_URL: 'http://localhost:8080' }, tokens);
    expect(services.provider).toBe('http');
    expect(services.client.capabilities).toEqual({ adminDirectory: false, reportCompany: true, reportGroups: false, profileEdit: false });
  });
  it('"same-origin" usa el origen de la página (aquí localhost) y exige loopback', () => {
    expect(createApiServices({ VITE_API_PROVIDER: 'http', VITE_ENABLE_HTTP_PROVIDER: 'true', VITE_API_BASE_URL: 'same-origin' }, tokens).provider).toBe('http');
  });
  it('rechaza proveedores desconocidos', () => {
    expect(() => createApiServices({ VITE_API_PROVIDER: 'otro' }, tokens)).toThrow();
  });
});

describe('proveedor simulado (hace de servidor)', () => {
  const setup = () => { const tokens = createMemoryTokenStore(); const api = new MockApi(tokens); return { tokens, api }; };
  const asUser = async (username: string) => { const ctx = setup(); const { token } = await ctx.api.login({ login: username, password: 'x' }); ctx.tokens.set(token); return ctx; };

  it('rechaza cuentas inexistentes e inactivas con 401', async () => {
    const { api } = setup();
    await expect(api.login({ login: 'nadie', password: 'x' })).rejects.toMatchObject({ status: 401 });
    await expect(api.login({ login: 'jorge.pena', password: 'x' })).rejects.toMatchObject({ status: 401 });
    await expect(api.login({ login: 'maria.rios', password: '' })).rejects.toMatchObject({ status: 400 });
  });
  it('sin token, o con token vencido, responde 401', async () => {
    const { api, tokens } = await asUser('maria.rios');
    api.expireSessions();
    await expect(api.listReports()).rejects.toMatchObject({ status: 401 });
    tokens.set(null);
    await expect(api.me()).rejects.toMatchObject({ status: 401 });
  });
  it('el usuario solo recibe sus informes vigentes', async () => {
    const { api } = await asUser('maria.rios');
    const names = (await api.listReports()).map(r => r.name);
    expect(names).toHaveLength(5);
    expect(names).not.toContain('Ocupación de activos');
    expect(names).not.toContain('Presupuesto anual');
  });
  it('403 idéntico para informe no asignado e inexistente', async () => {
    const { api } = await asUser('maria.rios');
    const a = await api.getReport('rep-6').catch(e => e as ApiError);
    const b = await api.getReport('rep-999').catch(e => e as ApiError);
    expect(a).toMatchObject({ status: 403, message: 'Acceso denegado' });
    expect(b).toMatchObject({ status: 403, message: 'Acceso denegado' });
    expect(isDenied(a)).toBe(true);
  });
  it('listados administrativos: 403 para usuario, datos para administrador', async () => {
    const user = await asUser('maria.rios');
    await expect(user.api.listUsers()).rejects.toMatchObject({ status: 403 });
    await expect(user.api.listAssignments()).rejects.toMatchObject({ status: 403 });
    const admin = await asUser('elena.torres');
    expect(await admin.api.listUsers()).toHaveLength(4);
    expect((await admin.api.listAssignments()).length).toBeGreaterThan(0);
  });
  it('escenarios de demostración: vacío y 403', async () => {
    const { api } = await asUser('maria.rios');
    api.setScenario('empty');
    expect(await api.listReports()).toEqual([]);
    api.setScenario('forbidden');
    await expect(api.getReport('rep-1')).rejects.toMatchObject({ status: 403 });
    api.reset();
    expect((await api.getReport('rep-1')).name).toBe('Ventas consolidadas');
  });
});

describe('guardUnauthorized', () => {
  it('avisa ante un 401 salvo en login, y relanza el error', async () => {
    const tokens = createMemoryTokenStore();
    const mock = new MockApi(tokens);
    const onUnauthorized = vi.fn();
    const guarded = guardUnauthorized(mock, onUnauthorized);
    await expect(guarded.login({ login: 'nadie', password: 'x' })).rejects.toMatchObject({ status: 401 });
    expect(onUnauthorized).not.toHaveBeenCalled();
    await expect(guarded.me()).rejects.toMatchObject({ status: 401 });
    expect(onUnauthorized).toHaveBeenCalledTimes(1);
  });
  it('expone las capacidades del cliente protegido', () => {
    expect(guardUnauthorized(new MockApi(createMemoryTokenStore()), () => undefined).capabilities.adminDirectory).toBe(true);
  });
});
