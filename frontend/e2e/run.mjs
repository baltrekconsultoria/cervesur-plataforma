// Recorridos E2E con Edge headless (CDP) contra la compilación de producción servida por `vite preview`.
// Sin dependencias nuevas. Uso: npm run build && npm run test:e2e
import { spawn } from 'node:child_process';
import { mkdirSync, readFileSync, readdirSync, statSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { launch } from './cdp.mjs';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const shots = process.env.E2E_SHOTS_DIR ?? join(root, 'e2e', '.tmp', 'capturas');
mkdirSync(shots, { recursive: true });
const ORIGIN = 'http://127.0.0.1:4174';
const results = [];
let current = '';
const ok = (name, cond, extra = '') => results.push([cond ? 'PASS' : 'FAIL', `${current} · ${name}`, extra]);

const preview = spawn(process.execPath, [join(root, 'node_modules', 'vite', 'bin', 'vite.js'), 'preview', '--host', '127.0.0.1', '--port', '4174', '--strictPort'], { cwd: root, stdio: 'ignore' });
for (let i = 0; i < 40; i++) { try { if ((await fetch(ORIGIN)).ok) break; } catch { /* aún no */ } await new Promise(r => setTimeout(r, 250)); }

const text = (b, sel) => b.eval(`document.querySelector(${JSON.stringify(sel)})?.innerText ?? ''`);
const count = (b, sel) => b.eval(`document.querySelectorAll(${JSON.stringify(sel)}).length`);
const path = b => b.eval('location.pathname + location.search');
const go = async (b, to) => { await b.eval(`history.pushState({}, '', ${JSON.stringify(to)}); dispatchEvent(new PopStateEvent('popstate'))`); await b.wait(350); };
const navTo = async (b, label) => { await b.eval(`[...document.querySelectorAll('.nav-button')].find(a => a.innerText.includes(${JSON.stringify(label)})).click()`); await b.wait(350); };
const login = async (b, user, password = 'cualquiera') => { await b.type('#login-name', user); await b.type('#login-password', password); await b.click('form button[type=submit]'); await b.wait(400); };
const logout = async b => { await b.eval(`document.querySelector('.sidebar-footer .text-button').click()`); await b.wait(300); };
const clickLink = async (b, label) => { await b.eval(`[...document.querySelectorAll('a')].find(a => a.getAttribute('aria-label') === ${JSON.stringify(label)} || a.innerText.trim().includes(${JSON.stringify(label)})).click()`); await b.wait(350); };
const storageEmpty = b => b.eval(`localStorage.length === 0 && sessionStorage.length === 0 && document.cookie === ''`);

async function desktop() {
  const b = await launch({ port: 9401 });
  await b.goto(ORIGIN + '/');
  current = 'Login';
  ok('redirige a /login sin sesión', await path(b) === '/login');
  ok('logotipo vertical original carga', await b.eval(`(() => { const i = document.querySelector('.login-brand img'); return i.complete && i.naturalWidth > 0; })()`));
  await b.shot(join(shots, '01-login.png'));
  await login(b, 'maria.rios');
  ok('usuario entra a /reportes', await path(b) === '/reportes');
  ok('logotipo horizontal original carga', await b.eval(`(() => { const i = document.querySelector('.sidebar-brand img'); return i.complete && i.naturalWidth > 0; })()`));
  ok('navegación de usuario sin sección admin', await count(b, '.nav-button') === 3 && (await text(b, '.navigation')).includes('Mis empresas') && !(await text(b, '.navigation')).includes('Usuarios'));
  ok('ningún dato en almacenamiento del navegador', await storageEmpty(b));

  current = 'Biblioteca';
  ok('5 informes y contador', await count(b, '.report-card') === 5 && (await text(b, '.resultbar span')).includes('5 de 5'));
  ok('informe no asignado oculto', !(await text(b, '#view')).includes('Ocupación'));
  await b.shot(join(shots, '02-biblioteca.png'));
  await b.type('#lib-q', 'rentabilidad');
  ok('búsqueda por descripción', await count(b, '.report-card') === 1 && await count(b, '.report-card mark') === 1);
  ok('el campo conserva el foco', await b.eval(`document.activeElement.id === 'lib-q'`));
  await b.type('#lib-q', 'ESTRATEGICO');
  ok('insensible a mayúsculas y tildes', await count(b, '.report-card') === 1);
  await b.type('#lib-q', '');
  await b.select('#lib-company', 'emp-1');
  ok('filtro por empresa', await count(b, '.report-card') === 2);
  ok('grupos acotados a la empresa', await b.eval(`[...document.querySelectorAll('#lib-group option')].map(o => o.textContent).join('|')`) === 'Todos los grupos|Comercial|Finanzas');
  await b.select('#lib-group', 'Finanzas');
  ok('empresa + grupo combinados', await count(b, '.report-card') === 1 && await path(b) === '/reportes?company=emp-1&group=Finanzas', await path(b));
  await b.shot(join(shots, '03-biblioteca-filtrada.png'));
  await b.type('#lib-q', 'zzz');
  ok('estado sin resultados', (await text(b, '#view')).includes('Sin resultados') && (await text(b, '.resultbar span')).includes('0 de 5'));
  await b.shot(join(shots, '04-sin-resultados.png'));
  await b.click('.resultbar button');
  ok('limpiar restaura todo', await count(b, '.report-card') === 5 && await path(b) === '/reportes' && await b.eval(`document.querySelector('.resultbar button').disabled`));

  current = 'Visor y retorno';
  await b.select('#lib-company', 'emp-2'); await b.type('#lib-q', 'entregas');
  await clickLink(b, 'Abrir informe Cumplimiento de entregas');
  ok('abre el visor', await path(b) === '/reportes/rep-4' && (await text(b, '#page-title')) === 'Cumplimiento de entregas');
  ok('empresa visible en la miga', (await text(b, '.breadcrumb')).includes('Textiles del Sur'));
  ok('visor simulado, sin iframes', (await text(b, '.powerbi-mock')).includes('Visor Power BI simulado') && await count(b, 'iframe') === 0);
  await b.shot(join(shots, '05-visor.png'));
  await clickLink(b, 'Volver a Mis reportes');
  ok('vuelve con filtros conservados', await path(b) === '/reportes?company=emp-2&q=entregas' && await b.eval(`document.querySelector('#lib-q').value === 'entregas' && document.querySelector('#lib-company').value === 'emp-2'`));
  ok('foco regresa al informe abierto', await b.eval(`document.activeElement.dataset.reportId === 'rep-4'`));
  await clickLink(b, 'Abrir informe Cumplimiento de entregas'); await b.eval('history.back()'); await b.wait(400);
  ok('botón Atrás del navegador', await path(b) === '/reportes?company=emp-2&q=entregas');
  await b.click('.resultbar button');

  current = 'Empresas y perfil';
  await navTo(b, 'Mis empresas');
  ok('3 empresas relacionadas', await count(b, '.company-grid .card') === 3);
  await b.shot(join(shots, '06-empresas.png'));
  await b.eval(`document.querySelectorAll('.company-grid a')[2].click()`); await b.wait(350);
  ok('"Ver informes" salta a la biblioteca filtrada', await path(b) === '/reportes?company=emp-3' && await count(b, '.report-card') === 1);
  await b.eval(`document.querySelector('.avatar').click()`); await b.wait(300);
  ok('avatar abre el perfil', await path(b) === '/perfil');
  await b.type('#pf-name', ''); await b.eval(`document.querySelector('form').requestSubmit()`); await b.wait();
  ok('perfil valida nombre', (await text(b, '#pf-name-err')).includes('nombre'));
  await b.type('#pf-name', 'María Ríos Díaz'); await b.eval(`document.querySelector('form').requestSubmit()`); await b.wait();
  ok('perfil guarda', (await text(b, 'form .form-note')).includes('Perfil actualizado'));
  ok('correo de solo lectura', await b.eval(`document.querySelector('#pf-email').readOnly`));
  await b.shot(join(shots, '07-perfil.png'));

  current = '403 usuario';
  await go(b, '/reportes/rep-6');
  ok('informe no asignado → 403 neutral', (await text(b, '#page-title')) === 'Acceso denegado' && !(await text(b, 'body')).match(/Ocupación|Valle Azul/));
  ok('un solo bloque compacto', await count(b, '.denied') === 1 && await count(b, '.alert') === 0 && await count(b, '.empty-state') === 0 && ((await text(b, '#view')).match(/403/g) || []).length === 1);
  await b.shot(join(shots, '08-403-usuario.png'));
  await clickLink(b, 'Volver a Mis reportes');
  ok('retorno desde 403', await path(b) === '/reportes' && await count(b, '.report-card') === 5);
  await go(b, '/admin/usuarios');
  ok('ruta admin → 403 de sección', (await text(b, '.denied')).includes('solo para administradores') && await count(b, '.denied') === 1);
  await go(b, '/reportes');
  await b.select('#demo-state', 'forbidden'); await b.wait();
  await clickLink(b, 'Abrir informe Ventas consolidadas');
  ok('403 simulado sin nombres del recurso', (await text(b, '#page-title')) === 'Acceso denegado' && !(await text(b, 'body')).match(/Ventas consolidadas|Andes Log/));
  await b.select('#demo-state', 'normal');

  current = 'Sin informes';
  await go(b, '/reportes');
  await b.select('#demo-state', 'empty'); await b.wait();
  ok('estado vacío por falta de asignaciones', (await text(b, '#view')).includes('Aún no tiene informes disponibles'));
  await b.shot(join(shots, '09-sin-informes.png'));
  await b.select('#demo-state', 'normal');

  current = '401 usuario';
  await go(b, '/empresas');
  await b.select('#demo-state', 'expired'); await b.wait(500);
  ok('401 lleva a login con aviso', await path(b) === '/login' && (await text(b, '[role=alert]')).includes('401'));
  ok('contraseña vacía y usuario conservado', await b.eval(`document.querySelector('#login-password').value === '' && document.querySelector('#login-name').value === 'maria.rios'`));
  ok('sin contenido autenticado en el DOM', await count(b, '.sidebar') === 0 && !(await text(b, 'body')).includes('Mis empresas'));
  ok('sin tokens ni credenciales en almacenamiento', await storageEmpty(b));
  await b.shot(join(shots, '10-401-usuario.png'));
  await b.eval(`document.querySelector('form').requestSubmit()`); await b.wait();
  ok('sin contraseña no reingresa', await path(b) === '/login');
  await login(b, 'maria.rios');
  ok('reingreso retoma la sección segura', await path(b) === '/empresas', await path(b));
  await logout(b);
  ok('cierre de sesión: login limpio, sin aviso 401', await path(b) === '/login' && !(await text(b, 'body')).includes('sesión venció'));

  current = 'Admin';
  await login(b, 'elena.torres');
  ok('admin entra a /admin con navegación propia', await path(b) === '/admin' && (await text(b, '.navigation')).includes('Accesos y asignaciones') && (await text(b, '.role-badge')) === 'Modo administrador');
  await b.shot(join(shots, '11-admin-inicio.png'));
  await go(b, '/reportes');
  ok('admin en ruta de usuario vuelve a su inicio', await path(b) === '/admin');
  await b.eval(`document.querySelector('.attention-list a').click()`); await b.wait(400);
  ok('pendientes enlazan a listas filtradas', await path(b) === '/admin/reportes?status=unassigned' && await count(b, 'tbody tr') === 1);
  await navTo(b, 'Usuarios');
  ok('usuarios: 4 filas', await count(b, 'tbody tr') === 4);
  await b.select('#us-status', 'inactive');
  ok('filtro inactivos', await count(b, 'tbody tr') === 1 && (await text(b, 'tbody')).includes('Jorge'));
  await b.click('.resultbar button');
  await b.type('#us-q', 'carlos');
  ok('búsqueda de usuarios', await count(b, 'tbody tr') === 1);
  await b.type('#us-q', '');
  ok('sin identificadores técnicos visibles', !/(usr|emp|rep)-\d/.test(await text(b, '#view')));
  await b.shot(join(shots, '12-admin-usuarios.png'));
  await navTo(b, 'Empresas');
  ok('empresas con estados', await count(b, 'tbody tr') === 5 && (await text(b, 'tbody')).includes('Inactiva'));
  await b.shot(join(shots, '13-admin-empresas.png'));
  await navTo(b, 'Reportes');
  ok('reportes: 7 filas', await count(b, 'tbody tr') === 7);
  await b.select('#ar-company', 'emp-3'); await b.select('#ar-group', 'Finanzas');
  ok('reportes empresa + grupo', await count(b, 'tbody tr') === 2);
  await b.click('.resultbar button');
  await b.shot(join(shots, '14-admin-reportes.png'));
  await b.type('#ar-q', 'flujo');
  await clickLink(b, 'Vista previa de Flujo de caja');
  ok('vista previa admin', (await text(b, '#page-title')) === 'Flujo de caja' && (await text(b, '.navigation [aria-current=page]')).includes('Reportes'));
  await clickLink(b, 'Volver a Reportes');
  ok('retorno admin conserva filtro', await path(b) === '/admin/reportes?q=flujo' && await b.eval(`document.querySelector('#ar-q').value === 'flujo'`));
  await navTo(b, 'Accesos');
  ok('accesos vigentes / sin efecto', (await text(b, 'tbody')).includes('Vigente') && (await text(b, 'tbody')).includes('Sin efecto'));
  await b.shot(join(shots, '15-admin-accesos.png'));

  current = '403/401 admin';
  await b.select('#demo-state', 'forbidden'); await b.wait();
  await go(b, '/reportes/rep-5');
  ok('403 de administrador: mensaje propio y sin nombres', (await text(b, '.denied')).includes('vista previa') && !(await text(b, 'body')).match(/Flujo de caja|Agroexportadora/) && (await text(b, '#page-title')) === 'Acceso denegado');
  await b.shot(join(shots, '16-403-admin.png'));
  ok('retorno nombra la sección', (await text(b, '.denied a')).includes('Reportes'));
  await b.select('#demo-state', 'normal');
  await go(b, '/admin/usuarios');
  await b.select('#demo-state', 'expired'); await b.wait(500);
  ok('401 admin: login, contraseña vacía, usuario conservado', await path(b) === '/login' && await b.eval(`document.querySelector('#login-password').value === '' && document.querySelector('#login-name').value === 'elena.torres'`));
  await b.shot(join(shots, '17-401-admin.png'));
  await login(b, 'elena.torres');
  ok('reingreso admin retoma Usuarios', await path(b) === '/admin/usuarios', await path(b));
  await logout(b);
  ok('sin errores de consola (escritorio)', b.consoleErrors.length === 0, b.consoleErrors.join(' | '));
  const external = b.requests.filter(u => !u.startsWith(ORIGIN) && !u.startsWith('data:') && !u.startsWith('about:'));
  ok('ninguna solicitud fuera de 127.0.0.1:4174', external.length === 0, `${b.requests.length} solicitudes locales; externas: ${external.join(', ') || 'ninguna'}`);
  ok('ninguna solicitud a /api (proveedor HTTP inactivo)', b.requests.every(u => !new URL(u, ORIGIN).pathname.startsWith('/api')));
  await b.close();
}

async function keyboard() {
  current = 'Teclado';
  const b = await launch({ port: 9402 });
  await b.goto(ORIGIN + '/');
  await b.eval(`document.querySelector('#login-name').focus()`);
  await b.key('Tab', 'Tab', { vk: 9 });
  ok('Tab pasa de usuario a contraseña', await b.eval(`document.activeElement.id === 'login-password'`));
  await b.type('#login-password', 'x'); await b.key('Enter', 'Enter', { vk: 13, text: '\r' }); await b.wait(500);
  ok('Enter envía el formulario', await path(b) === '/reportes', await path(b));
  ok('al entrar, el foco va al título', await b.eval(`document.activeElement.id === 'page-title'`));
  await b.eval(`document.querySelector('.skip-link').focus()`);
  const seq = [];
  for (let i = 0; i < 6; i++) { await b.key('Tab', 'Tab', { vk: 9 }); seq.push(await b.eval(`document.activeElement.className.split(' ')[0] || document.activeElement.tagName`)); }
  ok('Tab recorre navegación y luego el contenido', seq.filter(s => s === 'nav-button').length === 3, seq.join(' '));
  ok('foco visible (contorno)', await b.eval(`getComputedStyle(document.activeElement).outlineStyle !== 'none'`));
  await b.eval(`[...document.querySelectorAll('.nav-button')][1].focus()`); await b.key('Enter', 'Enter', { vk: 13, text: '\r' }); await b.wait(400);
  ok('Enter en un enlace de navegación', await path(b) === '/empresas');
  const unnamed = await b.eval(`[...document.querySelectorAll('button,a[href],input,select,textarea')].filter(e => e.offsetParent !== null).filter(e => !(e.getAttribute('aria-label') || e.innerText || e.labels?.[0]?.innerText || e.title || '').trim()).map(e => e.outerHTML.slice(0, 80))`);
  ok('controles con nombre accesible', unnamed.length === 0, unnamed.join(' | '));
  await b.close();
}

async function mobile() {
  current = 'Móvil';
  const b = await launch({ width: 390, height: 800, mobile: true, port: 9403 });
  await b.goto(ORIGIN + '/');
  await b.shot(join(shots, '20-movil-login.png'));
  await login(b, 'maria.rios');
  ok('sin desplazamiento horizontal (biblioteca)', await b.eval(`document.documentElement.scrollWidth <= innerWidth`));
  ok('menú oculto por defecto', await b.eval(`getComputedStyle(document.querySelector('#sidebar')).visibility === 'hidden'`));
  await b.click('#menu-button');
  ok('menú abre con aria-expanded', await b.eval(`document.querySelector('#sidebar').classList.contains('open') && document.querySelector('#menu-button').getAttribute('aria-expanded') === 'true'`));
  await b.wait(300); await b.shot(join(shots, '21-movil-menu.png'));
  await b.key('Escape', 'Escape', { vk: 27 });
  ok('Escape cierra el menú', await b.eval(`!document.querySelector('#sidebar').classList.contains('open')`));
  await b.shot(join(shots, '22-movil-biblioteca.png'));
  ok('objetivos táctiles ≥ 40 px', (await b.eval(`Math.min(...[...document.querySelectorAll('.btn,input,select')].filter(e => e.offsetParent !== null).map(e => e.getBoundingClientRect().height))`)) >= 40);
  await b.click('#menu-button'); await b.eval(`[...document.querySelectorAll('.nav-button')][1].click()`); await b.wait(300);
  ok('navegar desde el menú lo cierra', await path(b) === '/empresas' && await b.eval(`!document.querySelector('#sidebar').classList.contains('open')`));
  await go(b, '/reportes/rep-6');
  ok('403 móvil compacto, sin desbordamiento', await count(b, '.denied') === 1 && await b.eval(`document.documentElement.scrollWidth <= innerWidth`));
  await b.shot(join(shots, '23-movil-403.png'));
  await go(b, '/reportes');
  await b.select('#demo-state', 'expired'); await b.wait(500);
  ok('401 móvil: login, contraseña vacía', await path(b) === '/login' && await b.eval(`document.querySelector('#login-password').value === ''`) && await b.eval(`document.documentElement.scrollWidth <= innerWidth`));
  await b.shot(join(shots, '24-movil-401.png'));
  await login(b, 'elena.torres');
  await go(b, '/admin/usuarios');
  ok('tabla admin como tarjetas, sin desbordamiento', await b.eval(`getComputedStyle(document.querySelector('tbody tr')).display === 'block' && document.documentElement.scrollWidth <= innerWidth`));
  await b.shot(join(shots, '25-movil-usuarios.png'));
  ok('sin errores de consola (móvil)', b.consoleErrors.length === 0, b.consoleErrors.join(' | '));
  await b.close();
}

function secrets() {
  current = 'Secretos';
  const dist = join(root, 'dist');
  const files = (function walk(d) { return readdirSync(d).flatMap(n => { const p = join(d, n); return statSync(p).isDirectory() ? walk(p) : [p]; }); })(dist);
  const textFiles = files.filter(f => /\.(js|css|html)$/.test(f));
  const all = textFiles.map(f => readFileSync(f, 'utf8')).join('\n');
  ok('la compilación no contiene JWT', !/eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/.test(all));
  ok('la compilación no contiene claves privadas ni secretos de cliente', !/BEGIN [A-Z ]*PRIVATE KEY|clientsecret\s*[:=]/i.test(all));
  ok('la compilación no referencia enlaces de Power BI', !/app\.powerbi\.com|powerbi_|\benlace\b/.test(all));
  ok('la compilación no incluye Flutter/CanvasKit', !/canvaskit|flutter_bootstrap|main\.dart/i.test(all) && !files.some(f => /canvaskit|main\.dart|flutter/i.test(f)));
  const urls = [...new Set(all.match(/https?:\/\/[^\s"'`)\\]+/g) ?? [])];
  ok('URLs absolutas en la compilación: solo cadenas inertes de React, React Router y espacios de nombres W3C', urls.every(u => /^https?:\/\/(react\.dev|reactrouter\.com|www\.w3\.org|github\.com\/ungap|localhost$)/i.test(u)), urls.join(', '));
  ok('logotipos originales presentes en dist', files.some(f => f.endsWith('logo-cervesur-horizontal.png')) && files.some(f => f.endsWith('logo-cervesur-vertical.png')));
}

try {
  try { await desktop(); } catch (e) { results.push(['FAIL', `${current} · EXCEPCIÓN`, e.message]); }
  try { await keyboard(); } catch (e) { results.push(['FAIL', `${current} · EXCEPCIÓN`, e.message]); }
  try { await mobile(); } catch (e) { results.push(['FAIL', `${current} · EXCEPCIÓN`, e.message]); }
  try { secrets(); } catch (e) { results.push(['FAIL', `${current} · EXCEPCIÓN`, e.message]); }
} finally {
  preview.kill();
}
for (const [status, name, extra] of results) console.log(`${status}  ${name}${extra ? `  → ${extra}` : ''}`);
const failed = results.filter(r => r[0] === 'FAIL').length;
console.log(`\n${results.length - failed} PASS / ${failed} FAIL`);
process.exit(failed ? 1 : 0);
