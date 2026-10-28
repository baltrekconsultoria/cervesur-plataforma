import { act, cleanup, render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, useNavigate } from 'react-router';
import { describe, expect, it } from 'vitest';
import { HttpApiClient } from '../api/http/httpApi';
import { createMemoryTokenStore } from '../api/tokenStore';
import { App } from './App';
import { AppProvider } from './AppProvider';

/* UX-07D.2B: la interfaz completa con el proveedor HTTP REAL contra un servidor simulado que imita la autorización del backend y los
   identificadores productivos (reporte_id de hasta 50 caracteres, no UUID e incluso repetido). Todo es ficticio. */
const LONG_REF = 'SYN-LARGA-0001-ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456'; // 50 caracteres
const SHARED_REF = 'REF-HISTORICA-COMPARTIDA';
const PUBLIC_LINK = (n: number) => `https://app.powerbi.com/view?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDE${n}`;
const REPORTS = [
  { id: 1001, reporte_id: LONG_REF, nombre: 'Informe de referencia larga', descripcion: 'Sintético', activo: 1 },
  { id: 1002, reporte_id: 'SYN-REF-00002', nombre: 'Informe de otro formato', descripcion: 'Sintético', activo: 1 },
  { id: 1003, reporte_id: SHARED_REF, nombre: 'Informe con referencia repetida A', descripcion: 'Sintético', activo: 1 },
  { id: 1004, reporte_id: SHARED_REF, nombre: 'Informe con referencia repetida B (ajeno)', descripcion: 'Sintético', activo: 1 }
];
const VISIBLE = new Set([1001, 1002, 1003]); // lo que PHP autoriza al usuario; el 1004 es de otra empresa
const json = (status: number, body: unknown) => new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
const page = (rows: unknown[]) => ({ page: 1, per_page: 100, total: rows.length, total_pages: 1, has_more: false });

function backend() {
  const urls: string[] = [];
  const fetchImpl = ((url: string, init: RequestInit) => {
    const u = new URL(url); const path = u.pathname; urls.push(`${init.method ?? 'GET'} ${path}${u.search}`);
    if (path === '/api/login') return Promise.resolve(json(200, { success: true, token: 'tok', user: { id: 7, username: 'multi', email: 'multi@example.invalid', first_name: 'Usuario', last_name: 'Multi', role: 'usuario', is_active: 1 } }));
    if (path === '/api/reportes') { const rows = REPORTS.filter(r => VISIBLE.has(r.id)); return Promise.resolve(json(200, { success: true, data: rows, pagination: page(rows) })); }
    if (path === '/api/asignacion') { const rows = [{ id: 31, empresa_id: 1, reporte_id: 1001, activo: 1 }, { id: 32, empresa_id: 1, reporte_id: 1002, activo: 1 }, { id: 33, empresa_id: 2, reporte_id: 1003, activo: 1 }]; return Promise.resolve(json(200, { success: true, data: { success: true, data: rows }, pagination: page(rows) })); }
    if (path === '/api/empresas') { const rows = [{ id: 1, nombre: 'Empresa A', ruc: '1', ciudad: 'A', activo: 1 }, { id: 2, nombre: 'Empresa B', ruc: '2', ciudad: 'B', activo: 1 }]; return Promise.resolve(json(200, { success: true, data: rows, pagination: page(rows) })); }
    const detail = /^\/api\/reportes\/(\d+)$/.exec(path);
    if (detail) { const r = REPORTS.find(x => x.id === Number(detail[1])); return Promise.resolve(r && VISIBLE.has(r.id) ? json(200, { success: true, data: r }) : json(404, { success: false, message: 'Reporte no encontrado' })); }
    const viewer = /^\/api\/reportes\/id\/([1-9]\d{0,9})\/visor$/.exec(path);
    if (viewer) { const r = REPORTS.find(x => x.id === Number(viewer[1])); return Promise.resolve(r && VISIBLE.has(r.id) ? json(200, { success: true, data: { id: r.id, reporte_id: r.reporte_id, nombre: r.nombre, descripcion: '', modo: 'publico', url: PUBLIC_LINK(r.id) } }) : json(404, { success: false, message: 'Reporte no encontrado' })); }
    return Promise.resolve(json(404, { success: false, message: 'Endpoint no encontrado' }));
  }) as unknown as typeof fetch;
  const tokens = createMemoryTokenStore();
  return { urls, tokens, client: new HttpApiClient({ baseUrl: 'http://127.0.0.1:9', tokens, fetchImpl }) };
}

/** Inicia sesión y, si se indica, navega DENTRO de la aplicación a una URL (equivale a pegarla con la sesión abierta). */
async function open(target?: string) {
  const { urls, tokens, client } = backend();
  let go: (to: string) => void | Promise<void> = () => undefined;
  const Capture = () => { go = useNavigate(); return null; };
  render(<MemoryRouter initialEntries={['/login']}><AppProvider services={{ tokens, client }}><Capture /><App /></AppProvider></MemoryRouter>);
  const user = userEvent.setup();
  await user.type(await screen.findByLabelText('Correo o usuario'), 'multi');
  await user.type(screen.getByLabelText('Contraseña'), 'x');
  await user.click(screen.getByRole('button', { name: 'Ingresar al portal' }));
  await screen.findByRole('heading', { level: 1, name: 'Mis reportes' });
  if (target) await act(async () => { await go(target); });
  return { user, urls };
}

describe('identidad numérica del informe con referencias productivas', () => {
  it('el catálogo usa el id numérico en los enlaces y no mezcla informes con la misma referencia histórica', async () => {
    const { urls } = await open();
    await waitFor(() => expect(document.querySelectorAll('.report-card')).toHaveLength(3));
    const hrefs = [...document.querySelectorAll<HTMLAnchorElement>('.report-card a[data-report-id]')].map(a => a.getAttribute('href'));
    expect(hrefs).toEqual(['/reportes/1001', '/reportes/1002', '/reportes/1003']);
    expect(hrefs.join()).not.toContain(LONG_REF);
    expect(urls.some(u => u.includes(LONG_REF) || u.includes('reporteid'))).toBe(false);
  });

  it('abrir un informe de referencia larga usa /api/reportes/{id} y /api/reportes/id/{id}/visor, sin enviar nunca la referencia histórica', async () => {
    const { user, urls } = await open();
    await waitFor(() => expect(document.querySelectorAll('.report-card')).toHaveLength(3));
    await user.click(document.querySelector<HTMLAnchorElement>('a[data-report-id="1001"]') as HTMLAnchorElement);
    const frame = await screen.findByTitle('Informe: Informe de referencia larga');
    expect(frame.getAttribute('src')).toBe(PUBLIC_LINK(1001));
    expect(urls).toContain('GET /api/reportes/1001?view=minimal');
    expect(urls).toContain('GET /api/reportes/id/1001/visor');
    expect(urls.some(u => u.includes(LONG_REF) || u.includes('reporteid'))).toBe(false);
  });

  it('un id numérico NO autorizado (aunque comparta referencia histórica con uno autorizado) muestra acceso denegado y no pide el visor', async () => {
    const { urls } = await open('/reportes/1004');
    expect(await screen.findByRole('heading', { name: 'Acceso denegado' })).toBeInTheDocument();
    expect(document.querySelector('iframe')).toBeNull();
    expect(urls.some(u => u.includes('/visor'))).toBe(false);
    expect(urls).toContain('GET /api/reportes/1004?view=minimal');
  });

  it('una ruta con un id malformado (p. ej. un UUID o una referencia histórica) se trata como inexistente sin consultar al servidor', async () => {
    for (const bad of ['aaaaaaaa-bbbb-4ccc-8ddd-000000000001', LONG_REF, '007', '0']) {
      const { urls } = await open(`/reportes/${bad}`);
      expect(await screen.findByRole('heading', { name: 'Acceso denegado' })).toBeInTheDocument();
      expect(urls.filter(u => /^GET \/api\/reportes\/[^?]/.test(u))).toEqual([]); // ni detalle ni visor: el id malformado no llega al servidor
      cleanup();
    }
  });
});
