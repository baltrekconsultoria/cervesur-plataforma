import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { describe, expect, it } from 'vitest';
import { frameSandbox, isAllowedFrameUrl, resolveViewer } from '../lib/viewerBase';
import { buildPublicViewerUrl, classifyPowerBiLink, describeLink, POWERBI_VIEW_URL } from './powerbiLink';

interface Vector { name: string; url: string; kind?: string; token?: string | null; reportId?: string | null; groupId?: string | null; error?: string }
// Mismos vectores que la prueba PHP (tests/security_unit.php): ambas implementaciones deben coincidir.
const vectors = (JSON.parse(readFileSync(join(__dirname, '..', '..', '..', 'tests', 'fixtures', 'link_vectors.json'), 'utf8')) as { cases: Vector[] }).cases;

describe('clasificación de enlaces de Power BI (vectores compartidos con PHP)', () => {
  it('hay vectores suficientes de enlaces válidos y maliciosos', () => {
    expect(vectors.filter(v => v.kind).length).toBeGreaterThanOrEqual(8);
    expect(vectors.filter(v => v.error).length).toBeGreaterThanOrEqual(25);
  });

  for (const v of vectors) {
    it(v.error ? `rechaza: ${v.name}` : `acepta: ${v.name}`, () => {
      const result = classifyPowerBiLink(v.url);
      if (v.error) {
        expect(result.ok).toBe(false);
        expect(!result.ok && result.error).toContain(v.error);
      } else {
        expect(result).toMatchObject({ ok: true, kind: v.kind, token: v.token ?? null, reportId: v.reportId ?? null, groupId: v.groupId ?? null });
      }
    });
  }

  it('rechaza valores que no son texto', () => {
    for (const value of [null, undefined, 42, {}, ['https://app.powerbi.com/view?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDE']]) expect(classifyPowerBiLink(value).ok).toBe(false);
  });

  it('describe cada tipo para el administrador sin inventar identificadores', () => {
    expect(describeLink(classifyPowerBiLink('https://app.powerbi.com/view?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDE'))).toMatchObject({ tone: 'ok' });
    const embed = describeLink(classifyPowerBiLink('https://app.powerbi.com/reportEmbed?reportId=11111111-2222-3333-4444-555555555555'));
    expect(embed.text).toContain('11111111-2222-3333-4444-555555555555');
    expect(embed.text).toContain('sin ID de grupo en el enlace');
    expect(describeLink(classifyPowerBiLink('https://example.invalid/x'))).toMatchObject({ tone: 'error' });
  });
});

describe('URL del iframe', () => {
  const link = 'https://app.powerbi.com/view?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDE&pageName=ReportSection1&filterPaneEnabled=false';

  it('se reconstruye desde los componentes validados con la base oficial', () => {
    expect(buildPublicViewerUrl(link)).toBe(`${POWERBI_VIEW_URL}?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDE&pageName=ReportSection1&filterPaneEnabled=false`);
  });

  it('solo las publicaciones web producen iframe: inserción, enlace normal e inseguros devuelven null', () => {
    for (const bad of ['https://app.powerbi.com/reportEmbed?reportId=11111111-2222-3333-4444-555555555555', 'https://app.powerbi.com/reports/11111111-2222-3333-4444-555555555555', 'https://evil.example/view?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDE', 'javascript:alert(1)', '<iframe src="x">']) {
      expect(buildPublicViewerUrl(bad), bad).toBeNull();
    }
  });

  it('el orden y el conjunto de parámetros son fijos aunque el enlace los traiga distinto', () => {
    expect(buildPublicViewerUrl('https://app.powerbi.com/view?filterPaneEnabled=true&r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDE')).toBe(`${POWERBI_VIEW_URL}?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDE&filterPaneEnabled=true`);
  });
});

describe('modo del visor (oficial, QA simulado y QA real opt-in)', () => {
  const origin = 'http://127.0.0.1:4175';

  it('en producción y en cualquier compilación que no sea qa siempre se usa el endpoint oficial', () => {
    for (const MODE of ['production', 'development', 'test']) {
      expect(resolveViewer({ MODE, VITE_POWERBI_VIEW_BASE: '/qa/powerbi-view.html', VITE_POWERBI_VIEWER_MODE: 'real-public' }, origin)).toEqual({ base: POWERBI_VIEW_URL, mode: 'official' });
    }
    expect(resolveViewer({ MODE: 'qa' }, origin)).toEqual({ base: POWERBI_VIEW_URL, mode: 'official' });
  });

  it('en modo qa, por defecto, acepta una página local relativa o loopback (visor simulado)', () => {
    expect(resolveViewer({ MODE: 'qa', VITE_POWERBI_VIEW_BASE: '/qa/powerbi-view.html' }, origin)).toEqual({ base: `${origin}/qa/powerbi-view.html`, mode: 'qa-simulated' });
    expect(resolveViewer({ MODE: 'qa', VITE_POWERBI_VIEW_BASE: 'http://localhost:5173/qa/x.html' }, origin)).toEqual({ base: 'http://localhost:5173/qa/x.html', mode: 'qa-simulated' });
    expect(resolveViewer({ MODE: 'qa', VITE_POWERBI_VIEW_BASE: '/qa/powerbi-view.html', VITE_POWERBI_VIEWER_MODE: 'simulated' }, origin).mode).toBe('qa-simulated');
  });

  it('el modo con Power BI público real exige activarlo de forma explícita y solo en qa', () => {
    expect(resolveViewer({ MODE: 'qa', VITE_POWERBI_VIEW_BASE: '/qa/powerbi-view.html', VITE_POWERBI_VIEWER_MODE: 'real-public' }, origin)).toEqual({ base: POWERBI_VIEW_URL, mode: 'qa-real' });
    for (const other of ['real', 'true', 'REAL-PUBLIC', 'real-private', '']) {
      expect(resolveViewer({ MODE: 'qa', VITE_POWERBI_VIEW_BASE: '/qa/powerbi-view.html', VITE_POWERBI_VIEWER_MODE: other }, origin).mode, other).toBe('qa-simulated');
    }
  });

  it('ignora cualquier dominio externo o esquema inseguro como base (nunca apunta el iframe a un dominio arbitrario)', () => {
    for (const bad of ['https://evil.example/view', 'http://evil.example/view', '//evil.example/view', 'javascript:alert(1)', 'http://127.0.0.1@evil.example/x', 'http://localhost/x?redirect=1', '/qa/../../x?y']) {
      expect(resolveViewer({ MODE: 'qa', VITE_POWERBI_VIEW_BASE: bad }, origin), bad).toEqual({ base: POWERBI_VIEW_URL, mode: 'official' });
    }
  });

  it('sandbox mínimo por origen: la página local sin allow-same-origin; Power BI con su propio origen y sin permisos extra', () => {
    expect(frameSandbox('qa-simulated')).toBe('allow-scripts');
    for (const mode of ['official', 'qa-real'] as const) {
      const flags = frameSandbox(mode).split(' ');
      expect(flags).toEqual(['allow-scripts', 'allow-same-origin', 'allow-popups']);
      for (const forbidden of ['allow-forms', 'allow-top-navigation', 'allow-modals', 'allow-downloads', 'allow-popups-to-escape-sandbox', 'allow-top-navigation-by-user-activation']) expect(flags).not.toContain(forbidden);
    }
    // Un documento del mismo origen que la aplicación nunca recibe la combinación «scripts + mismo origen».
    expect(frameSandbox('qa-simulated').includes('allow-same-origin')).toBe(false);
  });

  it('el iframe solo se permite hacia el origen de la base configurada', () => {
    expect(isAllowedFrameUrl(`${POWERBI_VIEW_URL}?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDE`, POWERBI_VIEW_URL)).toBe(true);
    expect(isAllowedFrameUrl('https://evil.example/view?r=x', POWERBI_VIEW_URL)).toBe(false);
    expect(isAllowedFrameUrl('http://app.powerbi.com/view?r=x', POWERBI_VIEW_URL)).toBe(false);
    expect(isAllowedFrameUrl(`${origin}/qa/powerbi-view.html?r=x`, `${origin}/qa/powerbi-view.html`)).toBe(true);
    expect(isAllowedFrameUrl(`${origin}/otra.html?r=x`, `${origin}/qa/powerbi-view.html`)).toBe(false);
    expect(isAllowedFrameUrl('no es url', POWERBI_VIEW_URL)).toBe(false);
  });
});
