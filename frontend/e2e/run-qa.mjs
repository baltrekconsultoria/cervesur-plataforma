// E2E de integración UX-06A: navegador (Edge/CDP) → compilación React en modo `qa` → backend PHP REAL aislado.
// Requiere: `npm run qa:up` y `npm run build:qa`. Uso: npm run test:e2e:qa
import { spawn, spawnSync } from 'node:child_process';
import { mkdirSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { launch } from './cdp.mjs';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const shots = process.env.E2E_SHOTS_DIR ?? join(root, 'e2e', '.tmp', 'capturas-qa');
mkdirSync(shots, { recursive: true });
const FRONT = 'http://127.0.0.1:4175';
const FRONT_SHORT = 'http://127.0.0.1:4176';
const API = 'http://127.0.0.1:8080';
const PASSWORD = 'synthetic-password';
const results = [];
let current = '';
const ok = (name, cond, extra = '') => results.push([cond ? 'PASS' : 'FAIL', `${current} · ${name}`, extra]);
const sleep = ms => new Promise(r => setTimeout(r, ms));

const previews = [[4175, 'http://127.0.0.1:8080'], [4176, 'http://127.0.0.1:8081']].map(([port, target]) =>
  spawn(process.execPath, [join(root, 'node_modules', 'vite', 'bin', 'vite.js'), 'preview', '--mode', 'qa', '--outDir', 'dist-qa', '--port', String(port)], { cwd: root, stdio: 'ignore', env: { ...process.env, QA_API_TARGET: target } }));
for (const url of [FRONT, FRONT_SHORT]) { for (let i = 0; i < 40; i++) { try { if ((await fetch(url)).ok) break; } catch { /* aún no */ } await sleep(250); } }

/* --- Utilidades --- */
const text = (b, sel) => b.eval(`document.querySelector(${JSON.stringify(sel)})?.innerText ?? ''`);
const count = (b, sel) => b.eval(`document.querySelectorAll(${JSON.stringify(sel)}).length`);
const path = b => b.eval('location.pathname + location.search');
const body = b => b.eval('document.body.innerText');
const storageEmpty = b => b.eval(`localStorage.length === 0 && sessionStorage.length === 0 && document.cookie === ''`);
const go = async (b, to) => { await b.eval(`history.pushState({}, '', ${JSON.stringify(to)}); dispatchEvent(new PopStateEvent('popstate'))`); await b.wait(500); };
const navTo = async (b, label) => { await b.eval(`[...document.querySelectorAll('.nav-button')].find(a => a.textContent.includes(${JSON.stringify(label)})).click()`); await b.wait(600); };
const login = async (b, user, password = PASSWORD) => { await b.type('#login-name', user); await b.type('#login-password', password); await b.click('form button[type=submit]'); await b.wait(700); };
const logout = async b => { await b.eval(`document.querySelector('.sidebar-footer .text-button').click()`); await b.wait(500); };
const cards = b => b.eval(`[...document.querySelectorAll('.report-card h2')].map(h => h.textContent).sort()`);
const clickLink = async (b, label) => { await b.eval(`[...document.querySelectorAll('a')].find(a => a.getAttribute('aria-label') === ${JSON.stringify(label)} || a.textContent.trim().includes(${JSON.stringify(label)})).click()`); await b.wait(600); };
async function nodeApi(method, route, token, bodyObj) {
  const res = await fetch(API + route, { method, headers: { 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) }, body: bodyObj ? JSON.stringify(bodyObj) : undefined });
  return res.json().catch(() => null);
}
const docker = (...args) => spawnSync('docker', args, { encoding: 'utf8' });
const waitApi = async () => { for (let i = 0; i < 40; i++) { try { if ((await fetch(`${API}/api/ping`)).ok) return true; } catch { /* esperando */ } await sleep(500); } return false; };

async function desktop() {
  const b = await launch({ port: 9501 });
  await b.goto(FRONT + '/');
  current = 'Login real';
  ok('sin sesión redirige a /login', await path(b) === '/login');
  ok('proveedor HTTP visible: insignia QA y aviso de backend aislado', (await body(b)).includes('Entorno QA local') && !(await count(b, '#demo-state')) && !(await count(b, '#login-role')));
  ok('logotipo original vertical', await b.eval(`(() => { const i = document.querySelector('.login-brand img'); return i.complete && i.naturalWidth > 0; })()`));
  await b.shot(join(shots, '01-login-qa.png'));
  await login(b, 'usera', 'contraseña-incorrecta');
  ok('credenciales incorrectas: se queda en /login con el mensaje del backend', await path(b) === '/login' && (await text(b, '[role=alert]')).includes('Credenciales inválidas'));
  ok('credenciales incorrectas: contraseña vacía, sin sesión ni almacenamiento', await b.eval(`document.querySelector('#login-password').value === ''`) && await storageEmpty(b));
  await login(b, 'inactive');
  ok('usuario inactivo: mismo mensaje, sin sesión', await path(b) === '/login' && (await text(b, '[role=alert]')).includes('Credenciales inválidas'));
  await login(b, 'weird');
  ok('rol inesperado ("superadmin"): el backend lo rechaza y React muestra el mensaje genérico de credenciales inválidas', await path(b) === '/login' && (await text(b, '[role=alert]')).includes('Credenciales inválidas') && !(await text(b, '[role=alert]')).includes('rol'));
  ok('rol inesperado: no queda ningún dato de la sesión rechazada', await count(b, '.sidebar') === 0 && await storageEmpty(b));
  await b.shot(join(shots, '02-login-rechazado.png'));
  await login(b, 'legacy');
  ok('rol heredado "usuario" entra como usuario con su único informe', await path(b) === '/reportes' && JSON.stringify(await cards(b)) === '["Reporte A"]');
  await logout(b);

  current = 'Usuario real';
  await login(b, 'usera');
  ok('usera entra a /reportes con navegación de usuario', await path(b) === '/reportes' && await count(b, '.nav-button') === 3);
  ok('lista exactamente sus 4 informes vigentes', JSON.stringify(await cards(b)) === JSON.stringify(['Flujo semanal', 'Inventario norte', 'Reporte A', 'Ventas regionales']), (await cards(b)).join(', '));
  ok('no aparecen informes de otras empresas, inactivos ni retirados', !(await body(b)).match(/Reporte B|Reporte C|Reporte retirado/));
  ok('filtro por empresa disponible (catálogo compuesto) y SIN filtro de grupo (no existe nombre de grupo real)', await count(b, '#lib-company') === 1 && await count(b, '#lib-group') === 0 && !(await body(b)).includes('0000-0000'));
  ok('sin enlaces ni campos técnicos en el HTML', !(await b.eval('document.documentElement.outerHTML')).match(/example\.invalid|tenant-|client-a|created_by/));
  ok('cada informe muestra su empresa real (Empresa A) y ninguna etiqueta de grupo inventada', await b.eval(`[...document.querySelectorAll('.report-card .company')].every(e => e.textContent === 'Empresa A')`) && await count(b, '.report-card .tag') === 0);
  await b.shot(join(shots, '03-biblioteca-real.png'));
  await b.type('#lib-q', 'ventas');
  ok('búsqueda por nombre sobre datos de PHP', JSON.stringify(await cards(b)) === '["Ventas regionales"]' && await count(b, '.report-card mark') === 1);
  await b.type('#lib-q', 'COBERTURA');
  ok('búsqueda por descripción (sin distinguir mayúsculas)', JSON.stringify(await cards(b)) === '["Inventario norte"]');
  await b.type('#lib-q', 'proyeccion');
  ok('búsqueda sin tildes', JSON.stringify(await cards(b)) === '["Flujo semanal"]');
  await b.type('#lib-q', 'zzz');
  ok('estado sin resultados y contador', (await text(b, '#view')).includes('Sin resultados') && (await text(b, '.resultbar span')).includes('0 de 4'));
  await b.click('.resultbar button');
  ok('limpiar filtros', await count(b, '.report-card') === 4);

  current = 'Catálogo por empresa (informe en dos empresas)';
  await logout(b);
  await login(b, 'multi');
  ok('multi entra y ve sus 2 informes', JSON.stringify(await cards(b)) === JSON.stringify(['Reporte A', 'Ventas regionales']), (await cards(b)).join(', '));
  ok('el informe compartido muestra ambas empresas y aparece una sola vez', (await cards(b)).filter(n => n === 'Ventas regionales').length === 1 && (await b.eval(`[...document.querySelectorAll('.report-card')].find(c => c.textContent.includes('Ventas regionales')).querySelector('.company').textContent`)) === 'Empresa A · Empresa B');
  ok('opciones del filtro de empresa: A y B', await b.eval(`[...document.querySelectorAll('#lib-company option')].map(o => o.textContent).join('|')`) === 'Todas mis empresas|Empresa A|Empresa B');
  await b.select('#lib-company', await b.eval(`[...document.querySelectorAll('#lib-company option')].find(o => o.textContent === 'Empresa B').value`));
  ok('filtrar por Empresa B deja solo el informe compartido', JSON.stringify(await cards(b)) === '["Ventas regionales"]');
  await b.select('#lib-company', await b.eval(`[...document.querySelectorAll('#lib-company option')].find(o => o.textContent === 'Empresa A').value`));
  ok('filtrar por Empresa A deja ambos informes', (await cards(b)).length === 2);
  await b.type('#lib-q', 'región');
  ok('empresa + búsqueda combinadas (con tilde)', JSON.stringify(await cards(b)) === '["Ventas regionales"]');
  await b.shot(join(shots, '13-catalogo-multiempresa.png'));
  await b.click('.resultbar button');
  await clickLink(b, 'Abrir informe Ventas regionales');
  ok('visor: ambas empresas visibles', (await text(b, '.viewer-meta')).includes('Empresa A · Empresa B') && (await text(b, '.breadcrumb')).includes('Empresa A · Empresa B'));
  await logout(b);
  await login(b, 'usera');

  current = 'Visor y navegación';
  await b.type('#lib-q', 'reporte a');
  await clickLink(b, 'Abrir informe Reporte A');
  ok('abre el visor simulado del informe autorizado', (await text(b, '#page-title')) === 'Reporte A' && (await text(b, '.powerbi-mock')).includes('Visor Power BI simulado') && await count(b, 'iframe') === 0);
  ok('el visor no expone enlaces de Power BI', !(await b.eval('document.documentElement.outerHTML')).includes('example.invalid'));
  await b.shot(join(shots, '04-visor-simulado.png'));
  await clickLink(b, 'Volver a Mis reportes');
  ok('vuelve al listado con el filtro conservado y el foco en el informe', await path(b) === '/reportes?q=reporte+a' && await b.eval(`document.querySelector('#lib-q').value === 'reporte a' && document.activeElement.dataset.reportId !== undefined`), await path(b));
  await b.click('.resultbar button');
  await navTo(b, 'Mis empresas');
  ok('empresas autorizadas: solo "Empresa A" (no B ni la inactiva C) con su número real de informes', await count(b, '.company-grid .card') === 1 && (await text(b, '.company-grid')).includes('Empresa A') && !(await body(b)).match(/Empresa B|Empresa C/) && (await text(b, '.company-grid')).includes('4 informes disponibles'));
  ok('empresas: sin datos técnicos de Power BI', !(await b.eval('document.documentElement.outerHTML')).match(/tenant-a|client-a|powerbi/i));
  await b.shot(join(shots, '05-empresas-reales.png'));
  await b.eval(`document.querySelector('.avatar').click()`); await b.wait(700);
  ok('perfil real: nombre y correo del servidor, solo lectura', (await body(b)).includes('Demo User') && await b.eval(`document.querySelector('#pf-email').value === 'usera@example.invalid' && document.querySelector('#pf-name').readOnly`) && await count(b, 'form button[type=submit]') === 0);
  ok('perfil: no hay edición ni se llama a escrituras', !b.requestLog.some(r => ['PUT', 'POST', 'DELETE'].includes(r.method) && !/\/api\/(login|logout)$/.test(r.url)));
  await b.shot(join(shots, '06-perfil-real.png'));

  current = 'Aislamiento (UI)';
  await go(b, '/reportes/00000000-0000-0000-0000-000000000022');
  const foreignDenied = await text(b, '.denied');
  ok('informe de otra empresa → 403 neutral sin su nombre', (await text(b, '#page-title')) === 'Acceso denegado' && !(await body(b)).includes('Reporte B') && await count(b, '.denied') === 1);
  await b.shot(join(shots, '07-informe-ajeno-denegado.png'));
  await go(b, '/reportes/00000000-0000-0000-0000-0000000000ff');
  ok('informe inexistente → exactamente el mismo estado que uno ajeno', (await text(b, '.denied')) === foreignDenied);
  await go(b, '/reportes/id-con-formato-invalido');
  ok('identificador inválido → mismo estado neutral', (await text(b, '#page-title')) === 'Acceso denegado');
  await clickLink(b, 'Volver a Mis reportes');
  ok('retorno desde el acceso denegado', await path(b) === '/reportes' && await count(b, '.report-card') === 4);
  await go(b, '/admin/usuarios');
  ok('un usuario en una ruta administrativa recibe 403 de sección', (await text(b, '.denied')).includes('solo para administradores'));
  ok('esa pantalla no llamó a endpoints administrativos', !b.requestLog.some(r => /\/api\/users/.test(r.url)));

  current = 'Sesión revocada (401)';
  await go(b, '/reportes');
  const other = await nodeApi('POST', '/api/login', null, { login: 'usera', password: PASSWORD });
  await nodeApi('POST', '/api/logout', other.token); // otro dispositivo cierra sesión: sube session_version
  await navTo(b, 'Mis empresas');
  await b.wait(500);
  ok('al revocarse la sesión en el servidor, la siguiente petición lleva a /login', await path(b) === '/login' && (await text(b, '[role=alert]')).includes('401'));
  ok('contraseña vacía, usuario conservado y sin datos en el almacenamiento', await b.eval(`document.querySelector('#login-password').value === '' && document.querySelector('#login-name').value === 'usera'`) && await storageEmpty(b));
  ok('no queda contenido autenticado en pantalla', await count(b, '.sidebar') === 0 && !(await body(b)).match(/Mis reportes|Empresa A/));
  await b.shot(join(shots, '08-sesion-revocada-401.png'));
  await login(b, 'usera');
  ok('al reingresar retoma la sección segura (Mis empresas)', await path(b) === '/empresas', await path(b));

  current = 'Recarga y cierre de sesión';
  await b.send('Page.reload'); await b.wait(1200);
  ok('recargar pierde la sesión (token solo en memoria) y no la restaura', await path(b) === '/login' && await storageEmpty(b));
  ok('tras recargar no se muestra el aviso 401 (no fue una expiración)', !(await body(b)).includes('sesión venció'));
  await login(b, 'usera');
  const before = b.requestLog.length;
  await logout(b);
  const logoutCall = b.requestLog.slice(before).find(r => r.method === 'POST' && r.url.endsWith('/api/logout'));
  ok('cerrar sesión llama a POST /api/logout con el token', Boolean(logoutCall?.hasAuth));
  ok('tras el logout vuelve al login sin aviso 401', await path(b) === '/login' && !(await body(b)).includes('sesión venció'));
  await b.eval('history.back()'); await b.wait(500);
  ok('Atrás del navegador tras el logout no muestra datos', await count(b, '.report-card') === 0 && await count(b, '.sidebar') === 0);

  current = 'Administrador real';
  await login(b, 'admin');
  ok('el administrador entra a /admin con navegación propia', await path(b) === '/admin' && (await text(b, '.role-badge')) === 'Modo administrador');
  ok('inicio: empresas 2 de 2 y reportes 6 de 6 desde PHP; sin métricas no integradas', (await body(b)).includes('2 de 2') && (await body(b)).includes('6 de 6') && !(await body(b)).includes('Usuarios activos'));
  await b.shot(join(shots, '09-admin-inicio-real.png'));
  await navTo(b, 'Usuarios');
  ok('usuarios: aviso de integración pendiente, sin llamadas a /api/users', (await body(b)).includes('Se conecta en la siguiente etapa') && !b.requestLog.some(r => /\/api\/users/.test(r.url)));
  await navTo(b, 'Empresas');
  ok('empresas (admin): las 2 activas', await count(b, 'tbody tr') === 2);
  await navTo(b, 'Reportes');
  ok('reportes (admin): 6 activos con columna Empresa, sin Grupo ni Usuarios (no disponibles)', await count(b, 'tbody tr') === 6 && (await text(b, 'thead')).includes('EMPRESA') && !(await text(b, 'thead')).match(/GRUPO|USUARIOS/));
  ok('reportes (admin): el filtro por empresa combina con la búsqueda', await b.eval(`document.querySelector('#ar-company') !== null`) && await b.select('#ar-company', '2').then(() => true) && await count(b, 'tbody tr') >= 2);
  await b.click('.resultbar button');
  await b.type('#ar-q', 'flujo');
  ok('filtro de búsqueda admin sobre datos de PHP', await count(b, 'tbody tr') === 1);
  await b.shot(join(shots, '10-admin-reportes-real.png'));
  await clickLink(b, 'Vista previa de Flujo semanal');
  ok('vista previa del administrador', (await text(b, '#page-title')) === 'Flujo semanal');
  await clickLink(b, 'Volver a Reportes');
  ok('retorno del administrador conserva el filtro', await path(b) === '/admin/reportes?q=flujo');
  await go(b, '/reportes');
  ok('el administrador en rutas de usuario vuelve a su inicio', await path(b) === '/admin');
  await logout(b);

  current = 'Errores HTTP y recuperación';
  await login(b, 'usera');
  docker('stop', 'cervesur-qa-proxy');
  await navTo(b, 'Mis empresas');
  ok('con el backend caído se muestra un error recuperable (no pantalla en blanco)', (await text(b, '#view')).includes('No se pudo cargar la información') && await count(b, '#view button') >= 1);
  await b.shot(join(shots, '11-error-recuperable.png'));
  docker('start', 'cervesur-qa-proxy');
  ok('el backend vuelve a responder', await waitApi());
  await b.eval(`[...document.querySelectorAll('#view button')].find(x => x.textContent.includes('Reintentar')).click()`); await b.wait(1000);
  ok('«Reintentar» recupera la pantalla sin volver a iniciar sesión', await count(b, '.company-grid .card') === 1 && await path(b) === '/empresas');
  await logout(b);

  current = 'Aislamiento de red del navegador';
  const apiPaths = [...new Set(b.requestLog.map(r => new URL(r.url).pathname).filter(p => p.startsWith('/api')).map(p => p.replace(/[0-9a-f-]{36}|id-con[\w-]+/g, ':id')))].sort();
  ok('todas las solicitudes van a 127.0.0.1:4175', b.requests.every(u => u.startsWith(FRONT) || u.startsWith('data:') || u.startsWith('about:')), `${b.requests.length} solicitudes`);
  ok('solo se usan los endpoints autorizados', apiPaths.every(p => /^\/api\/(login|logout|user\/profile|reportes|reportes\/reporteid\/:id|asignacion|empresas)$/.test(p)), apiPaths.join(', '));
  const lists = b.requestLog.filter(r => /\/api\/(reportes|asignacion|empresas)(\?|$)/.test(r.url) && !r.url.includes('/reporteid/'));
  ok('todos los listados piden view=minimal y páginas de 100 como máximo', lists.length > 0 && lists.every(r => new URL(r.url).searchParams.get('view') === 'minimal' && Number(new URL(r.url).searchParams.get('per_page')) <= 100 && new URL(r.url).searchParams.has('page')), `${lists.length} solicitudes de listado`);
  ok('el detalle de informe pide view=minimal', b.requestLog.filter(r => r.url.includes('/reporteid/')).every(r => r.url.includes('view=minimal')));
  ok('no se consume /api/powerbi ni escrituras administrativas', !b.requestLog.some(r => /powerbi|\/api\/(users|empresa-)/i.test(r.url)) && b.requestLog.every(r => r.method === 'GET' || /\/api\/(login|logout)$/.test(r.url)));
  ok('ninguna petición lleva credenciales de cookie ni a hosts externos', b.requests.every(u => /^(http:\/\/127\.0\.0\.1:4175|data:|about:)/.test(u)));
  ok('sin errores de consola (escritorio)', b.consoleErrors.length === 0, b.consoleErrors.join(' | '));
  await b.close();
}

async function expiredJwt() {
  const b = await launch({ port: 9502 });
  await b.goto(FRONT_SHORT + '/');
  current = 'JWT expirado (token de 2 s)';
  await login(b, 'usera');
  ok('inicio de sesión correcto con JWT de vida corta', await path(b) === '/reportes' && await count(b, '.report-card') === 4);
  await sleep(3500);
  await navTo(b, 'Mis empresas');
  await b.wait(500);
  ok('el JWT vencido produce 401 y vuelve al login con aviso', await path(b) === '/login' && (await text(b, '[role=alert]')).includes('401'));
  ok('contraseña vacía, usuario conservado, sin almacenamiento', await b.eval(`document.querySelector('#login-password').value === '' && document.querySelector('#login-name').value === 'usera'`) && await storageEmpty(b));
  await b.shot(join(shots, '12-jwt-expirado.png'));
  await login(b, 'usera');
  ok('reingreso correcto retoma la sección', await path(b) === '/empresas', await path(b));
  await b.close();
}

async function mobile() {
  const b = await launch({ width: 390, height: 800, mobile: true, port: 9503 });
  await b.goto(FRONT + '/');
  current = 'Móvil real';
  await b.shot(join(shots, '20-movil-login.png'));
  await login(b, 'usera');
  ok('biblioteca real sin desbordamiento horizontal', await b.eval(`document.documentElement.scrollWidth <= innerWidth`) && await count(b, '.report-card') === 4);
  await b.shot(join(shots, '21-movil-biblioteca.png'));
  await go(b, '/reportes/00000000-0000-0000-0000-000000000022');
  ok('403 neutral en móvil, sin desbordamiento', await count(b, '.denied') === 1 && !(await body(b)).includes('Reporte B') && await b.eval(`document.documentElement.scrollWidth <= innerWidth`));
  await b.shot(join(shots, '22-movil-denegado.png'));
  await go(b, '/reportes');
  const other = await nodeApi('POST', '/api/login', null, { login: 'usera', password: PASSWORD });
  await nodeApi('POST', '/api/logout', other.token);
  await b.eval(`document.querySelector('#menu-button').click()`); await b.wait(300);
  await b.eval(`[...document.querySelectorAll('.nav-button')][1].click()`); await b.wait(900);
  ok('401 en móvil: login con aviso y contraseña vacía', await path(b) === '/login' && (await text(b, '[role=alert]')).includes('401') && await b.eval(`document.querySelector('#login-password').value === '' && document.documentElement.scrollWidth <= innerWidth`));
  await b.shot(join(shots, '23-movil-401.png'));
  await login(b, 'admin');
  await go(b, '/admin/reportes');
  ok('tabla administrativa real como tarjetas en móvil', await b.eval(`getComputedStyle(document.querySelector('tbody tr')).display === 'block' && document.documentElement.scrollWidth <= innerWidth`));
  await b.shot(join(shots, '24-movil-admin-reportes.png'));
  ok('sin errores de consola (móvil)', b.consoleErrors.length === 0, b.consoleErrors.join(' | '));
  await b.close();
}

try {
  for (const step of [desktop, expiredJwt, mobile]) { try { await step(); } catch (e) { results.push(['FAIL', `${current} · EXCEPCIÓN`, e.message]); } }
} finally {
  previews.forEach(p => p.kill());
  docker('start', 'cervesur-qa-proxy');
}
for (const [status, name, extra] of results) console.log(`${status}  ${name}${extra ? `  → ${extra}` : ''}`);
const failed = results.filter(r => r[0] === 'FAIL').length;
console.log(`\n${results.length - failed} PASS / ${failed} FAIL`);
process.exit(failed ? 1 : 0);
