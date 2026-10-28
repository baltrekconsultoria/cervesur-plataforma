import { spawnSync } from 'node:child_process';
import { mkdirSync, mkdtempSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { createApiServices } from './factory';
import { ConfigurationError, createServerServices, parseAllowedHosts } from './serverServices';
import { createMemoryTokenStore } from './tokenStore';

const tokens = createMemoryTokenStore();
const portal = { protocol: 'https:', hostname: 'portal.ejemplo.com', origin: 'https://portal.ejemplo.com' };
const env = { VITE_API_PROVIDER: 'http', VITE_API_BASE_URL: 'same-origin', VITE_ALLOWED_HOSTS: 'portal.ejemplo.com' };

describe('compilación de servidor · configuración', () => {
  it('usa el proveedor HTTP del mismo origen y HTTPS en el dominio autorizado', () => {
    const services = createServerServices(env, tokens, portal);
    expect(services.provider).toBe('http');
    expect('mock' in services).toBe(false);
    expect(services.client.capabilities).toMatchObject({ adminWrites: true, liveViewer: true });
  });

  it('no existe camino a datos simulados: un proveedor mock o desconocido falla claramente', () => {
    for (const VITE_API_PROVIDER of ['mock', 'otro', '']) {
      expect(() => createServerServices({ ...env, VITE_API_PROVIDER }, tokens, portal), VITE_API_PROVIDER).toThrow(ConfigurationError);
    }
    expect(() => createServerServices({ ...env, VITE_API_PROVIDER: 'mock' }, tokens, portal)).toThrow(/datos simulados/);
  });

  it('no admite servidores de API arbitrarios (solo /api del mismo origen)', () => {
    for (const VITE_API_BASE_URL of ['https://api.evil.example', 'http://127.0.0.1:8080', '//evil.example', '/api']) {
      expect(() => createServerServices({ ...env, VITE_API_BASE_URL }, tokens, portal), VITE_API_BASE_URL).toThrow(/mismo origen/);
    }
  });

  it('exige dominios autorizados y rechaza un dominio ajeno', () => {
    expect(() => createServerServices({ ...env, VITE_ALLOWED_HOSTS: '' }, tokens, portal)).toThrow(/ningún dominio autorizado/);
    expect(() => createServerServices(env, tokens, { protocol: 'https:', hostname: 'otro.ejemplo.com', origin: 'https://otro.ejemplo.com' })).toThrow(/no está autorizado/);
    expect(() => createServerServices({ ...env, VITE_ALLOWED_HOSTS: 'portal.ejemplo.com, *.ejemplo.com' }, tokens, portal)).toThrow(/no válido/);
  });

  it('exige HTTPS salvo en loopback (solo para verificar la compilación en la propia máquina)', () => {
    expect(() => createServerServices(env, tokens, { protocol: 'http:', hostname: 'portal.ejemplo.com', origin: 'http://portal.ejemplo.com' })).toThrow(/HTTPS/);
    expect(createServerServices(env, tokens, { protocol: 'http:', hostname: '127.0.0.1', origin: 'http://127.0.0.1:4178' }).provider).toBe('http');
  });

  it('el dominio se compara sin distinguir mayúsculas y la lista admite varios dominios', () => {
    expect(parseAllowedHosts(' Portal.Ejemplo.com , prueba.ejemplo.com ')).toEqual(['portal.ejemplo.com', 'prueba.ejemplo.com']);
    expect(createServerServices({ ...env, VITE_ALLOWED_HOSTS: 'prueba.ejemplo.com,portal.ejemplo.com' }, tokens, { ...portal, hostname: 'Portal.Ejemplo.com' }).provider).toBe('http');
  });

  it('la ausencia de origen también es un error de configuración', () => {
    expect(() => createServerServices(env, tokens, null)).toThrow(ConfigurationError);
  });

  it('un fallo de PHP se informa tal cual: no hay regreso silencioso al simulador', async () => {
    const failing = vi.fn(() => Promise.reject(new TypeError('sin conexión')));
    vi.stubGlobal('fetch', failing);
    const { client } = createServerServices(env, createMemoryTokenStore(), portal);
    await expect(client.login({ login: 'usera', password: 'x' })).rejects.toMatchObject({ kind: 'network' });
    await expect(client.listReports()).rejects.toMatchObject({ kind: 'network' });
    expect(failing).toHaveBeenCalled();
    vi.unstubAllGlobals();
  });
});

describe('compilaciones que no son de servidor', () => {
  it('el proveedor predeterminado sigue siendo el simulado (pruebas y modo sin Internet)', () => {
    expect(createApiServices({}, tokens).provider).toBe('mock');
  });
});

describe('verificador de compilaciones de servidor (impide publicar un build con proveedor mock)', () => {
  const dirs: string[] = [];
  afterEach(() => { for (const d of dirs.splice(0)) rmSync(d, { recursive: true, force: true }); });

  const fixture = (js: string, extra: Record<string, string> = {}) => {
    const dir = mkdtempSync(join(tmpdir(), 'cervesur-build-'));
    dirs.push(dir);
    mkdirSync(join(dir, 'assets'));
    writeFileSync(join(dir, 'index.html'), '<!doctype html><script type="module" src="/assets/index-abc.js"></script>');
    writeFileSync(join(dir, '.htaccess'), 'RewriteCond %{REQUEST_URI} !^/api(/|$)\n');
    writeFileSync(join(dir, 'build-info.json'), JSON.stringify({ target: 'server', provider: 'http', apiBase: 'same-origin', allowedHosts: ['portal.ejemplo.com'] }));
    writeFileSync(join(dir, 'assets', 'index-abc.js'), js);
    for (const [name, content] of Object.entries(extra)) { mkdirSync(join(dir, name, '..'), { recursive: true }); writeFileSync(join(dir, name), content); }
    return dir;
  };
  const verify = (dir: string) => spawnSync(process.execPath, [join(process.cwd(), 'scripts', 'verify-production-build.mjs'), dir], { encoding: 'utf8' });
  const GOOD_JS = 'fetch(location.origin+"/api/login");"/api/logout";"/api/user/profile";"/api/reportes";"/visor";const o=window.location.origin;';

  it('acepta una compilación con proveedor HTTP y sin datos simulados', () => {
    const result = verify(fixture(GOOD_JS));
    expect(result.status, result.stderr).toBe(0);
  });

  it('rechaza una compilación que incluye el proveedor o los datos simulados', () => {
    const result = verify(fixture(`${GOOD_JS} const u="maria.rios";`));
    expect(result.status).toBe(1);
    expect(result.stderr).toContain('proveedor mock');
  });

  it('rechaza una compilación que no usa PHP, la página local de QA, el endpoint heredado de tokens o mapas de código', () => {
    expect(verify(fixture('const x=1;')).status).toBe(1);
    expect(verify(fixture(`${GOOD_JS} "/qa/powerbi-view.html"`)).status).toBe(1);
    expect(verify(fixture(`${GOOD_JS} "/api/powerbi/tokens/"`)).status).toBe(1);
    expect(verify(fixture(GOOD_JS, { 'assets/index-abc.js.map': '{}' })).status).toBe(1);
    expect(verify(fixture(GOOD_JS, { 'qa/powerbi-view.html': '<html></html>' })).status).toBe(1);
    expect(verify(fixture(GOOD_JS, { '.env': 'X=1' })).status).toBe(1);
  });

  it('rechaza una compilación sin manifiesto de servidor (p. ej. la compilación normal o de QA)', () => {
    const dir = fixture(GOOD_JS);
    writeFileSync(join(dir, 'build-info.json'), JSON.stringify({ target: 'qa', provider: 'mock' }));
    expect(verify(dir).status).toBe(1);
  });
});
