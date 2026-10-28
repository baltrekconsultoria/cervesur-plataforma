import { readdirSync, readFileSync, statSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';

const walk = (dir: string): string[] => readdirSync(dir).flatMap(name => {
  const path = join(dir, name);
  return statSync(path).isDirectory() ? walk(path) : [path];
});
const root = process.cwd();
const sources = walk(join(root, 'src')).filter(f => /\.(ts|tsx|css)$/.test(f) && !f.endsWith('.test.ts') && !f.endsWith('.test.tsx'));
const read = (file: string) => readFileSync(file, 'utf8');

describe('higiene de seguridad del código fuente', () => {
  it('no contiene JWT ni secretos incrustados', () => {
    for (const file of sources) {
      const text = read(file);
      expect(text, file).not.toMatch(/eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/);
      expect(text, file).not.toMatch(/-----BEGIN [A-Z ]*PRIVATE KEY-----/);
      expect(text, file).not.toMatch(/client_?secret\s*[:=]\s*['"][^'"]+['"]/i);
    }
  });
  it('no persiste tokens ni contraseñas en el navegador', () => {
    for (const file of sources) expect(read(file), file).not.toMatch(/localStorage|sessionStorage|document\.cookie|indexedDB/);
  });
  it('no incluye URLs absolutas hacia servicios externos', () => {
    for (const file of sources) {
      const urls = read(file).match(/https?:\/\/[^\s'"`)]+/g) ?? [];
      const external = urls.filter(url => !/^https?:\/\/(localhost|127\.0\.0\.1|portal\.ejemplo\.com|app\.powerbi\.com\/(x|view$|view\?r=secreto)|www\.w3\.org)/.test(url));
      expect(external, file).toEqual([]);
    }
  });
  it('solo el adaptador conoce el campo técnico "enlace" (como clave de datos) y los campos powerbi_*', () => {
    for (const file of sources.filter(f => !f.replace(/\\/g, '/').endsWith('api/http/adapters.ts'))) {
      // «enlace» es también vocabulario de la interfaz (UX-07C): se prohíbe su uso como CLAVE del backend (`dto.enlace`, `enlace:`, 'enlace').
      expect(read(file), file).not.toMatch(/\.enlace\b|\benlace\s*:|['"`]enlace['"`]|powerbi_/);
    }
  });
  it('no existe ninguna dependencia de Power BI ni de Flutter en esta etapa', () => {
    const pkg = JSON.parse(read(join(root, 'package.json'))) as { dependencies: Record<string, string>; devDependencies: Record<string, string> };
    const names = Object.keys({ ...pkg.dependencies, ...pkg.devDependencies });
    expect(names.filter(n => /powerbi|flutter/i.test(n))).toEqual([]);
  });
  it('las versiones de package.json están fijadas (sin ^ ni ~)', () => {
    const pkg = JSON.parse(read(join(root, 'package.json'))) as { dependencies: Record<string, string>; devDependencies: Record<string, string> };
    for (const [name, version] of Object.entries({ ...pkg.dependencies, ...pkg.devDependencies })) expect(version, name).toMatch(/^\d+\.\d+\.\d+$/);
  });
});

describe('alcance de integración UX-06A', () => {
  it('el código fuente no consume /api/powerbi ni OAuth de aplicación', () => {
    for (const file of sources) {
      expect(read(file), file).not.toMatch(/\/api\/powerbi|oauth2|login\.microsoftonline|access_token|client_credentials/i);
    }
  });
  it('el proveedor HTTP solo referencia los endpoints autorizados para esta etapa', () => {
    const http = read(join(root, 'src', 'api', 'http', 'httpApi.ts'));
    const routes = [...http.matchAll(/['"`](\/api\/[^'"`$]*)/g)].map(m => m[1] ?? '');
    const allowed = ['/api/login', '/api/logout', '/api/user/profile', '/api/reportes', '/api/reportes/reporteid/', '/api/asignacion', '/api/empresas',
      // UX-07A: administración de usuarios y accesos
      '/api/users', '/api/users?status=all', '/api/register', '/api/empresa-usuario', '/api/empresa-reporte-usuario', '/api/empresa-reporte-usuario/',
      // UX-07B: empresas, informes y asociaciones
      '/api/empresas', '/api/empresas/', '/api/reportes/', '/api/asignacion/'];
    // Nunca cambios de rol ni de estado desde esta etapa.
    expect(routes.filter(r => /role|activ|desactiv|status=(?!all)/i.test(r))).toEqual([]);
    expect(routes.filter(r => !allowed.includes(r)), routes.join(', ')).toEqual([]);
  });
  it('los archivos de entorno versionables no contienen secretos', () => {
    for (const name of ['.env.example', '.env.qa.example']) {
      const text = read(join(root, name));
      expect(text, name).not.toMatch(/secret|password|token|jwt/i);
      expect(text, name).not.toMatch(/^VITE_API_BASE_URL=(?!same-origin|http:\/\/(127\.0\.0\.1|localhost))/m);
    }
  });
});
