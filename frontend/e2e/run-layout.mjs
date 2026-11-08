// UX-07D.5 · Bloque A: títulos largos sin espacios (p. ej. DASHBOARD_PRESUPUESTAL_CERVESUR_031) no deben desbordar ni invadir tarjetas vecinas,
// en escritorio, tablet y móvil. Edge headless (CDP) contra la compilación servida por `vite preview`. Datos sintéticos; se inyecta el título largo en el DOM.
// Uso: npm run build && node e2e/run-layout.mjs
import { spawn } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { launch } from './cdp.mjs';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const shots = process.env.E2E_SHOTS_DIR ?? join(root, 'e2e', '.tmp', 'capturas-layout');
mkdirSync(shots, { recursive: true });
const ORIGIN = 'http://127.0.0.1:4174';
const results = [];
const ok = (name, cond, extra = '') => results.push([cond ? 'PASS' : 'FAIL', name, extra]);
const LONG = ['DASHBOARD_PRESUPUESTAL_CERVESUR_031', 'DASHBOARD_RESPONSABLE_CERVESUR_031_VERSION_EXTENDIDA_SIN_ESPACIOS_PARA_PROBAR_DESBORDAMIENTO'];

const preview = spawn(process.execPath, [join(root, 'node_modules', 'vite', 'bin', 'vite.js'), 'preview', '--host', '127.0.0.1', '--port', '4174', '--strictPort'], { cwd: root, stdio: 'ignore' });
for (let i = 0; i < 40; i++) { try { if ((await fetch(ORIGIN)).ok) break; } catch { /* aún no */ } await new Promise(r => setTimeout(r, 250)); }

// En emulación móvil Chrome ensancha el viewport de diseño cuando el contenido desborda (innerWidth crece), así que se compara con el ancho CONFIGURADO.
let W = 0;
const noHScroll = b => b.eval(`document.documentElement.scrollWidth <= ${W}`);
const go = async (b, to) => { await b.eval(`history.pushState({}, '', ${JSON.stringify(to)}); dispatchEvent(new PopStateEvent('popstate'))`); await b.wait(400); };

/** Cada elemento dentro de su tarjeta (nada se sale por los lados) y las tarjetas de una misma fila con la misma altura y el botón alineado. */
const cardsOk = b => b.eval(`(() => {
  const cards = [...document.querySelectorAll('.report-card')];
  if (cards.length < 2) return 'pocas tarjetas';
  const rows = new Map();
  for (const c of cards) {
    const r = c.getBoundingClientRect();
    for (const el of c.querySelectorAll('h2, p, .tag, .company, .btn')) {
      const e = el.getBoundingClientRect();
      if (e.left < r.left - 1 || e.right > r.right + 1) return 'desborda: ' + el.className + ' ' + el.tagName;
    }
    if (r.right > ${W} + 1 || r.left < -1) return 'tarjeta fuera de la pantalla';
    const key = Math.round(r.top); rows.set(key, [...(rows.get(key) ?? []), c]);
  }
  for (const group of rows.values()) {
    const heights = new Set(group.map(c => Math.round(c.getBoundingClientRect().height)));
    const buttons = new Set(group.map(c => Math.round(c.querySelector('.btn').getBoundingClientRect().bottom)));
    if (heights.size > 1) return 'alturas distintas en una fila';
    if (buttons.size > 1) return 'botones desalineados en una fila';
  }
  for (let i = 0; i < cards.length; i++) for (let j = i + 1; j < cards.length; j++) {
    const a = cards[i].getBoundingClientRect(), c = cards[j].getBoundingClientRect();
    if (a.left < c.right - 1 && c.left < a.right - 1 && a.top < c.bottom - 1 && c.top < a.bottom - 1) return 'tarjetas superpuestas';
  }
  return 'ok';
})()`);

async function viewport(label, width, height, mobile, port) {
  W = width;
  const b = await launch({ width, height, mobile, port });
  await b.goto(ORIGIN + '/');
  await b.type('#login-name', 'maria.rios'); await b.type('#login-password', 'cualquiera'); await b.click('form button[type=submit]'); await b.wait(500);
  await go(b, '/reportes');
  ok(`${label}: biblioteca sin desbordamiento horizontal (títulos normales)`, await noHScroll(b));
  await b.eval(`(() => { const L = ${JSON.stringify(LONG)}; document.querySelectorAll('.report-card h2').forEach((h, i) => { h.textContent = L[i % 2]; }); })()`);
  await b.wait(150);
  ok(`${label}: tarjetas con títulos largos`, await cardsOk(b) === 'ok', await cardsOk(b));
  ok(`${label}: biblioteca con títulos largos sin desbordamiento horizontal`, await noHScroll(b));
  await b.shot(join(shots, `${label}-biblioteca.png`));
  if (mobile && width < 400) {
    // Control negativo: con las reglas antiguas (sin partir palabras) la misma comprobación DEBE fallar; demuestra que la prueba detecta el defecto.
    await b.eval(`(() => { const s = document.createElement('style'); s.id = 'ctl'; s.textContent = 'h1,h2,h3,.card p{overflow-wrap:normal!important;hyphens:none!important}.card,.card>div{min-width:auto!important}.report-grid{grid-template-columns:repeat(auto-fill,minmax(260px,1fr))!important}'; document.head.append(s); })()`);
    await b.wait(150);
    const ctl = [await cardsOk(b), await noHScroll(b), await b.eval("document.documentElement.scrollWidth")]; ok(`${label}: control negativo (reglas antiguas) detecta el defecto`, ctl[0] !== "ok" || !ctl[1], JSON.stringify(ctl));
    await b.eval(`document.getElementById('ctl').remove()`); await b.wait(100);
  }
  await b.eval(`document.querySelector('.report-card .btn').click()`); await b.wait(600);
  await b.eval(`(() => { const L = ${JSON.stringify(LONG)}; for (const sel of ['#page-title', '.viewer h2', '.breadcrumb [aria-current]']) document.querySelectorAll(sel).forEach(h => { h.textContent = L[1]; }); })()`);
  await b.wait(150);
  ok(`${label}: detalle con título largo sin desbordamiento horizontal`, await noHScroll(b));
  ok(`${label}: título del detalle dentro de la pantalla`, await b.eval(`(() => { for (const s of ['#page-title', '.viewer h2']) { const e = document.querySelector(s)?.getBoundingClientRect(); if (!e || e.left < -1 || e.right > ${W} + 1) return false; } return true; })()`));
  await b.shot(join(shots, `${label}-detalle.png`));
  await b.close?.();
}

/** UX-07E.C · «Otros informes»: un botón compacto en lugar de decenas de etiquetas; ventana con buscador, Escape, clic en el fondo, foco y adaptación a cada ancho. */
async function picker(label, width, height, mobile, port) {
  W = width;
  const b = await launch({ width, height, mobile, port });
  await b.goto(ORIGIN + '/');
  await b.type('#login-name', 'elena.torres'); await b.type('#login-password', 'cualquiera'); await b.click('form button[type=submit]'); await b.wait(500);
  await go(b, '/reportes/rep-1'); await b.wait(500);
  const botonOk = await b.eval(`(() => { const x = [...document.querySelectorAll('button')].find(e => /^Otros informes \\(\\d+\\)$/.test(e.innerText.trim())); return Boolean(x) && x.getAttribute('aria-haspopup') === 'dialog'; })()`);
  ok(`${label}: un botón «Otros informes (N)» y ninguna fila de etiquetas encima del visor`, botonOk && await b.eval(`document.querySelectorAll('.sibling-nav').length === 0`) && await noHScroll(b));
  const abrir = async () => { await b.eval(`[...document.querySelectorAll('button')].find(e => /^Otros informes/.test(e.innerText.trim())).click()`); await b.wait(400); };
  await abrir();
  ok(`${label}: la ventana abre dentro de la pantalla y el foco va al buscador`, await b.eval(`(() => { const d = document.querySelector('dialog[open]'); if (!d) return false; const r = d.getBoundingClientRect(); return r.left >= 0 && r.right <= ${width} && r.top >= 0 && r.bottom <= ${height} && document.activeElement?.id === 'other-reports-q'; })()`));
  const antes = await b.eval(`document.querySelectorAll('dialog[open] .report-picker-list a').length`);
  await b.type('#other-reports-q', 'flujo'); await b.wait(250);
  const despues = await b.eval(`document.querySelectorAll('dialog[open] .report-picker-list a').length`);
  ok(`${label}: la búsqueda filtra la lista (${antes} → ${despues})`, antes > 1 && despues >= 1 && despues < antes);
  await b.shot(join(shots, `${label}-otros-informes.png`));
  // Con texto en el buscador (type=search) el primer Escape lo borra —comportamiento estándar del navegador— y el segundo cierra la ventana.
  await b.key('Escape', 'Escape', { vk: 27 }); await b.wait(250); await b.key('Escape', 'Escape', { vk: 27 }); await b.wait(500);
  ok(`${label}: Escape cierra la ventana y devuelve el foco al botón`, await b.eval(`!document.querySelector('dialog[open]') && /^Otros informes/.test(document.activeElement?.innerText ?? '')`));
  await abrir();
  await b.send('Input.dispatchMouseEvent', { type: 'mousePressed', x: 3, y: 3, button: 'left', clickCount: 1 }); await b.send('Input.dispatchMouseEvent', { type: 'mouseReleased', x: 3, y: 3, button: 'left', clickCount: 1 }); await b.wait(300);
  ok(`${label}: un clic fuera de la ventana (en el fondo) la cierra`, await b.eval(`!document.querySelector('dialog[open]')`));
  await abrir();
  let dentro = true; for (let i = 0; i < 6; i++) { await b.key('Tab', 'Tab', { vk: 9 }); if (!(await b.eval(`document.querySelector('dialog[open]')?.contains(document.activeElement) ?? false`))) dentro = false; }
  ok(`${label}: el foco del teclado permanece dentro de la ventana (6 pulsaciones de Tab, menos que los elementos enfocables)`, dentro);
  ok(`${label}: sin desbordamiento horizontal con la ventana abierta`, await noHScroll(b));
  const destino = await b.eval(`(() => { const a = document.querySelector('dialog[open] .report-picker-list a'); return a ? new URL(a.href).pathname : ''; })()`);
  await b.eval(`document.querySelector('dialog[open] .report-picker-list a').click()`); await b.wait(700);
  ok(`${label}: elegir un informe navega a su detalle y cierra la ventana`, destino.startsWith('/reportes/') && (await b.eval('location.pathname')) === destino && await b.eval(`!document.querySelector('dialog[open]')`));
  if (width >= 1600) ok(`${label}: el visor usa todo el ancho disponible (más de 1280 px)`, await b.eval(`document.querySelector('.viewer').getBoundingClientRect().width > 1280`));
  await b.close();
}

try {
  await viewport('escritorio-1366', 1366, 800, false, 9421);
  await viewport('tablet-820', 820, 1100, true, 9422);
  await viewport('movil-360', 360, 740, true, 9423);
  await picker('picker-1920', 1920, 1000, false, 9431);
  await picker('picker-tablet-820', 820, 1100, true, 9432);
  await picker('picker-movil-360', 360, 740, true, 9433);
} catch (e) { results.push(['FAIL', 'EXCEPCIÓN', e.message]); }
preview.kill();
for (const [s, n, x] of results) console.log(`${s} ${n}${s === 'FAIL' && x ? ' · ' + x : ''}`);
const fails = results.filter(r => r[0] === 'FAIL').length;
console.log(`\n${results.length - fails}/${results.length} PASS`);
process.exit(fails ? 1 : 0);
