import { describe, expect, it } from 'vitest';
import { homePath, sanitizeReturnTo } from '../auth/returnTo';
import { norm, plural, splitMatches } from './text';

describe('texto', () => {
  it('normaliza tildes y mayúsculas', () => {
    expect(norm('  Estratégico ')).toBe('estrategico');
    expect(plural(1, 'informe', 'informes')).toBe('1 informe');
    expect(plural(3, 'informe', 'informes')).toBe('3 informes');
  });
  it('marca coincidencias sin importar tildes', () => {
    expect(splitMatches('Inventario estratégico', 'estrategico')).toEqual([{ text: 'Inventario ', match: false }, { text: 'estratégico', match: true }]);
    expect(splitMatches('Flujo', '')).toEqual([{ text: 'Flujo', match: false }]);
  });
});

describe('ruta de retorno tras 401', () => {
  it('acepta rutas internas del rol correcto', () => {
    expect(sanitizeReturnTo('/empresas', 'user')).toBe('/empresas');
    expect(sanitizeReturnTo('/reportes?company=emp-1', 'user')).toBe('/reportes?company=emp-1');
    expect(sanitizeReturnTo('/admin/usuarios?status=active', 'admin')).toBe('/admin/usuarios?status=active');
  });
  it('nunca retoma un informe concreto: vuelve al listado', () => {
    expect(sanitizeReturnTo('/reportes/rep-6', 'user')).toBe('/reportes');
    expect(sanitizeReturnTo('/reportes/rep-6', 'admin')).toBe('/admin/reportes');
  });
  it('descarta rutas externas, ambiguas o de otro rol', () => {
    for (const raw of ['https://malo.example/x', '//malo.example', '/\\malo', 'javascript:alert(1)', '/javascript:x', '', null, undefined, 5, '/login']) {
      expect(sanitizeReturnTo(raw, 'user')).toBeNull();
    }
    expect(sanitizeReturnTo('/admin/usuarios', 'user')).toBeNull();
    expect(sanitizeReturnTo('/empresas', 'admin')).toBeNull();
  });
  it('define la portada por rol', () => {
    expect(homePath('admin')).toBe('/admin');
    expect(homePath('user')).toBe('/reportes');
  });
});
