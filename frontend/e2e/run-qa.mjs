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
// Puertos configurables (E2E_PORT): evita chocar con una vista previa que el equipo tenga abierta para QA manual.
const PORT = Number(process.env.E2E_PORT ?? 4175);
const FRONT = `http://127.0.0.1:${PORT}`;
const FRONT_SHORT = `http://127.0.0.1:${PORT + 1}`;
const API_PORT = Number(process.env.QA_API_PORT ?? 8080);
const QA_PREFIX = process.env.QA_PREFIX ?? 'cervesur-qa';
const API = `http://127.0.0.1:${API_PORT}`;
const PASSWORD = 'synthetic-password';
const results = [];
let current = '';
const ok = (name, cond, extra = '') => results.push([cond ? 'PASS' : 'FAIL', `${current} · ${name}`, extra]);
const sleep = ms => new Promise(r => setTimeout(r, ms));

const previews = [[PORT, `http://127.0.0.1:${API_PORT}`], [PORT + 1, `http://127.0.0.1:${API_PORT + 1}`]].map(([port, target]) =>
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
// La página local de pruebas avisa al padre con postMessage (su iframe no comparte origen). Se recarga el marco para recibir el mensaje.
const stubMessage = async b => {
  await b.eval(`(() => { window.__qaStub = []; if (!window.__qaStubListener) { window.__qaStubListener = e => { if (e.data && e.data.source === 'qa-powerbi-stub') window.__qaStub.push(e.data); }; addEventListener('message', window.__qaStubListener); } const f = document.querySelector('.powerbi-frame iframe'); f.src = f.src; })()`);
  await b.wait(1000);
  return b.eval('window.__qaStub.at(-1) ?? null');
};
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
  await b.wait(900);
  ok('abre el visor del informe autorizado: iframe hacia la página LOCAL de pruebas (nunca un dominio externo)', (await text(b, '#page-title')) === 'Reporte A' && await count(b, '.powerbi-frame iframe') === 1 && await b.eval(`document.querySelector('.powerbi-frame iframe').src.startsWith(location.origin + '/qa/powerbi-view.html?r=U0lOVEVUSUNPLVJFUE9SVEUtMDAwMQ')`));
  const frameMsg = await stubMessage(b);
  ok('el marco (página local) informa por postMessage el token público recibido; no demuestra nada sobre Power BI real', frameMsg?.token === 'U0lOVEVUSUNPLVJFUE9SVEUtMDAwMQ');
  ok('aislamiento del iframe local: sandbox solo «allow-scripts» (sin allow-same-origin) y el padre no puede leer su documento', await b.eval(`(() => { const f = document.querySelector('.powerbi-frame iframe'); return f.getAttribute('sandbox') === 'allow-scripts' && f.contentDocument === null; })()`));
  ok('el aviso de modo indica «QA · visor simulado»', (await text(b, '[data-testid=viewer-mode]')).includes('QA · visor simulado') && await b.eval(`document.querySelector('[data-testid=viewer-mode]').dataset.mode === 'qa-simulated'`));
  ok('la ayuda y la alternativa «Abrir en una pestaña nueva» siguen visibles aunque el marco haya cargado', (await text(b, '.viewer-help')).includes('¿No ve el informe?') && await count(b, '.viewer-help a[target=_blank][rel~=noopener]') === 1);
  ok('la configuración del visor se pidió al servidor (endpoint autorizado) con el token del usuario', b.requestLog.some(r => r.method === 'GET' && /\/api\/reportes\/id\/[1-9]\d*\/visor$/.test(r.url) && r.hasAuth));
  ok('el navegador no contactó ningún dominio externo durante el visor', b.requests.every(u => u.startsWith(FRONT) || /^(data:|about:)/.test(u)));
  ok('la página no expone el enlace de publicación ni el dominio de los datos de prueba', !(await b.eval('document.documentElement.outerHTML')).match(/example\.invalid|app\.powerbi\.com\/view\?r=/));
  await b.shot(join(shots, '04-visor-publico-local.png'));
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

  current = 'Visor · modos privado y no compatible';
  await go(b, '/reportes/5'); await b.wait(900);
  ok('inserción autenticada sin configurar: «requiere configuración de acceso a Power BI», sin iframe ni tokens generales', (await text(b, '[data-testid=viewer-state]')).includes('Este informe requiere configuración de acceso a Power BI') && await count(b, 'iframe') === 0 && !b.requestLog.some(r => r.url.includes('/api/powerbi/tokens')));
  await b.shot(join(shots, '63-visor-privado.png'));
  await go(b, '/reportes/4'); await b.wait(900);
  ok('enlace no compatible (dato antiguo): aviso claro y ningún iframe', (await text(b, '[data-testid=viewer-state]')).includes('no es compatible con el visor') && await count(b, 'iframe') === 0);
  await b.shot(join(shots, '64-visor-no-compatible.png'));

  current = 'Aislamiento (UI)';
  await go(b, '/reportes/2');
  const foreignDenied = await text(b, '.denied');
  ok('informe de otra empresa → 403 neutral sin su nombre', (await text(b, '#page-title')) === 'Acceso denegado' && !(await body(b)).includes('Reporte B') && await count(b, '.denied') === 1);
  await b.shot(join(shots, '07-informe-ajeno-denegado.png'));
  await go(b, '/reportes/99999');
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
  ok('inicio: empresas 2 de 2, reportes 6 de 6, usuarios y accesos vigentes reales desde PHP', (await body(b)).includes('2 de 2') && (await body(b)).includes('6 de 6') && (await body(b)).includes('USUARIOS ACTIVOS') && (await body(b)).includes('ACCESOS VIGENTES'));
  await b.shot(join(shots, '09-admin-inicio-real.png'));
  await navTo(b, 'Usuarios');
  const adminTok = (await nodeApi('POST', '/api/login', null, { login: 'admin', password: PASSWORD })).token;
  const realUsers = await nodeApi('GET', '/api/users?status=all&per_page=100', adminTok);
  ok('usuarios: lista real de MySQL (activos e inactivos), sin aviso de integración pendiente', await count(b, 'tbody tr') === realUsers.pagination.total && !(await body(b)).includes('Se conecta en la siguiente etapa'), `${await count(b, 'tbody tr')} filas / ${realUsers.pagination.total} en el servidor`);
  ok('usuarios: el rol heredado se muestra como Usuario y el rol desconocido como «sin acceso»', (await body(b)).includes('Rol no reconocido') && await b.eval(`[...document.querySelectorAll('tbody tr')].some(r => r.textContent.includes('Demo Legacy') && r.textContent.includes('Usuario'))`));
  await b.shot(join(shots, '30-admin-usuarios-real.png'));
  await navTo(b, 'Empresas');
  ok('empresas (admin): las 2 activas', await count(b, 'tbody tr') === 2);
  await navTo(b, 'Reportes');
  ok('reportes (admin): 6 activos con columnas Empresa y Usuarios con acceso, sin Grupo (no existe)', await count(b, 'tbody tr') === 6 && (await text(b, 'thead')).includes('EMPRESA') && (await text(b, 'thead')).includes('USUARIOS CON ACCESO') && !(await text(b, 'thead')).includes('GRUPO'));
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
  docker('stop', `${QA_PREFIX}-proxy`);
  await navTo(b, 'Mis empresas');
  ok('con el backend caído se muestra un error recuperable (no pantalla en blanco)', (await text(b, '#view')).includes('No se pudo cargar la información') && await count(b, '#view button') >= 1);
  await b.shot(join(shots, '11-error-recuperable.png'));
  docker('start', `${QA_PREFIX}-proxy`);
  ok('el backend vuelve a responder', await waitApi());
  await b.eval(`[...document.querySelectorAll('#view button')].find(x => x.textContent.includes('Reintentar')).click()`); await b.wait(1000);
  ok('«Reintentar» recupera la pantalla sin volver a iniciar sesión', await count(b, '.company-grid .card') === 1 && await path(b) === '/empresas');
  await logout(b);

  current = 'Aislamiento de red del navegador';
  const apiPaths = [...new Set(b.requestLog.map(r => new URL(r.url).pathname).filter(p => p.startsWith('/api')).map(p => p.replace(/\/(\d+|id-con[\w-]+)(?=\/|$)/g, '/:id')))].sort();
  ok('todas las solicitudes van al origen local de la aplicación', b.requests.every(u => u.startsWith(FRONT) || u.startsWith('data:') || u.startsWith('about:')), `${b.requests.length} solicitudes`);
  ok('solo se usan los endpoints autorizados', apiPaths.every(p => /^\/api\/(login|logout|user\/profile|reportes|reportes\/:id|reportes\/id\/:id\/visor|asignacion|empresas|users|empresa-usuario|empresa-reporte-usuario)$/.test(p)), apiPaths.join(', '));
  const lists = b.requestLog.filter(r => /\/api\/(reportes|asignacion|empresas)(\?|$)/.test(r.url));
  ok('todos los listados piden view=minimal y páginas de 100 como máximo', lists.length > 0 && lists.every(r => new URL(r.url).searchParams.get('view') === 'minimal' && Number(new URL(r.url).searchParams.get('per_page')) <= 100 && new URL(r.url).searchParams.has('page')), `${lists.length} solicitudes de listado`);
  ok('el detalle de informe pide view=minimal', b.requestLog.filter(r => /\/api\/reportes\/\d+(\?|$)/.test(r.url)).every(r => r.url.includes('view=minimal')));
  ok('el visor pide su configuración solo con GET autenticado (nunca el endpoint antiguo de tokens)', b.requestLog.filter(r => r.url.includes('/visor')).every(r => r.method === 'GET' && r.hasAuth) && !b.requestLog.some(r => r.url.includes('/api/powerbi/tokens')));
  ok('no se consume /api/powerbi y, solo navegando y consultando, no se escribe nada', !b.requestLog.some(r => /powerbi/i.test(r.url.replace('/qa/powerbi-view.html', ''))) && b.requestLog.every(r => r.method === 'GET' || /\/api\/(login|logout)$/.test(r.url)));
  const adminLists = b.requestLog.filter(r => /\/api\/(users|empresa-usuario|empresa-reporte-usuario)(\?|$)/.test(r.url));
  ok('los listados administrativos leen todas las páginas (máximo 100 por página)', adminLists.length > 0 && adminLists.every(r => Number(new URL(r.url).searchParams.get('per_page')) === 100 && new URL(r.url).searchParams.has('page')), `${adminLists.length} solicitudes`);
  ok('los listados de relaciones piden view=minimal (sin enlaces de Power BI ni datos de creación)', adminLists.filter(r => !/\/api\/users/.test(r.url)).every(r => new URL(r.url).searchParams.get('view') === 'minimal'));
  ok('ninguna petición lleva credenciales de cookie ni a hosts externos', b.requests.every(u => u.startsWith(FRONT) || /^(data:|about:)/.test(u)));
  ok('sin errores de consola (escritorio)', b.consoleErrors.length === 0, b.consoleErrors.join(' | '));
  await b.close();
}

/* UX-07A: escenario funcional completo contra PHP y MySQL sintéticos (alta → asignación → acceso → retirada → persistencia). */
async function adminFlow() {
  const b = await launch({ port: 9504 });
  await b.goto(FRONT + '/');
  const clickByText = async (selector, label) => { const done = await b.eval(`(() => { const e = [...document.querySelectorAll(${JSON.stringify(selector)})].find(x => x.textContent.includes(${JSON.stringify(label)})); if (!e) return false; e.click(); return true; })()`); await b.wait(500); return done; };
  const tableRows = b => b.eval(`[...document.querySelectorAll('tbody tr')].map(r => r.innerText.replace(/\\s+/g, ' ').trim())`);
  const adminTok = (await nodeApi('POST', '/api/login', null, { login: 'admin', password: PASSWORD })).token;
  const findUser = async username => (await nodeApi('GET', '/api/users?status=all&per_page=100', adminTok)).users.find(u => u.username === username);

  current = 'UX-07A · alta de usuario';
  await login(b, 'admin');
  await navTo(b, 'Usuarios');
  ok('el administrador ve «Nuevo usuario»', await clickByText('button', 'Nuevo usuario') && await count(b, 'dialog[open]') === 1);
  await b.shot(join(shots, '31-nuevo-usuario-vacio.png'));
  await b.click('.modal form button[type=submit]');
  ok('validaciones claras antes de enviar (sin llamar al servidor)', (await text(b, '.modal')).includes('Ingrese los nombres.') && !b.requestLog.some(r => r.method === 'POST' && r.url.endsWith('/api/register')));
  await b.type('#nu-first', 'Operador'); await b.type('#nu-last', 'Prueba'); await b.type('#nu-username', 'operador.prueba');
  await b.type('#nu-email', 'usera@example.invalid'); await b.type('#nu-password', PASSWORD); // correo ya registrado: debe rechazarlo PHP
  await b.click('.modal form button[type=submit]'); await b.wait(900);
  ok('rechazo de PHP (correo duplicado): el mensaje se muestra, el diálogo sigue abierto y no se anuncia ningún alta', (await text(b, '.modal')).includes('El email ya está registrado') && await count(b, 'dialog[open]') === 1 && !(await body(b)).includes('creado correctamente'));
  ok('el rechazo no creó el usuario en MySQL', !(await findUser('operador.prueba')));
  await b.shot(join(shots, '32-nuevo-usuario-rechazado.png'));
  await b.type('#nu-email', 'operador.prueba@example.invalid');
  await b.click('.modal form button[type=submit]'); await b.wait(1200);
  ok('alta confirmada por PHP: el diálogo se cierra y se avisa', await count(b, 'dialog[open]') === 0 && (await body(b)).includes('Usuario «operador.prueba» creado correctamente.'));
  ok('el usuario aparece en el listado recargado desde el servidor', (await tableRows(b)).some(r => r.includes('Operador Prueba') && r.includes('Activo')));
  const created = await findUser('operador.prueba');
  ok('persistió en MySQL con rol user, activo y sin elegirlos desde React', created?.role === 'user' && created?.is_active === true);
  ok('la petición de alta no envía role, is_active ni session_version', (() => { const post = b.requestLog.filter(r => r.method === 'POST' && r.url.endsWith('/api/register')).at(-1); return Boolean(post) && !/role|is_active|session_version/.test(post.postData ?? ''); })());
  await b.shot(join(shots, '33-usuario-creado.png'));

  current = 'UX-07A · asignación de accesos';
  ok('la fila del usuario ofrece «Asignar accesos»', await b.eval(`[...document.querySelectorAll('tbody tr')].some(r => r.textContent.includes('Operador Prueba') && r.querySelector('a[href*="accesos/nueva"]'))`));
  await clickLink(b, 'Asignar accesos a Operador Prueba');
  ok('el asistente abre con el usuario preseleccionado', await path(b) === `/admin/accesos/nueva?user=${created.id}` && await b.eval(`document.querySelector('input[name=wz-user]:checked')?.closest('label').textContent.includes('Operador Prueba')`));
  await b.shot(join(shots, '34-asistente-usuario.png'));
  await clickByText('button', 'Continuar');
  ok('paso 2: solo empresas activas', await count(b, 'input[name=wz-company]') === 2);
  await b.eval(`[...document.querySelectorAll('input[name=wz-company]')].find(i => i.closest('label').textContent.includes('Empresa A')).click()`); await b.wait(300);
  await b.shot(join(shots, '35-asistente-empresa.png'));
  await clickByText('button', 'Continuar');
  ok('paso 3: solo los 5 informes de Empresa A (ninguno de Empresa B)', JSON.stringify(await b.eval(`[...document.querySelectorAll('.selection-list input[type=checkbox]')].map(i => i.closest('label').querySelector('strong').textContent).sort()`)) === JSON.stringify(['Flujo semanal', 'Inventario norte', 'Reporte A', 'Reporte C', 'Ventas regionales']));
  await b.eval(`[...document.querySelectorAll('.selection-list input[type=checkbox]')].find(i => i.closest('label').textContent.includes('Reporte A')).click()`); await b.wait(300);
  await b.shot(join(shots, '36-asistente-informes.png'));
  await clickByText('button', 'Continuar');
  ok('paso 4: resumen antes de guardar', (await text(b, '.summary-list')).includes('Operador Prueba') && (await text(b, '.summary-list')).includes('Empresa A') && (await text(b, '.summary-list')).includes('Reporte A'));
  await b.shot(join(shots, '37-asistente-confirmar.png'));
  await clickByText('button', 'Guardar accesos'); await b.wait(1200);
  ok('resultado por informe confirmado por PHP', (await text(b, '.success-box')).includes('Accesos guardados') && (await text(b, '[aria-label="Resultado por informe"]')).includes('Concedido'));
  const writes = b.requestLog.filter(r => r.method === 'POST' && /empresa-(reporte-)?usuario$/.test(r.url));
  ok('se crearon la relación con la empresa y el acceso, con ids numéricos de relación (no UUID)', writes.length === 2 && /"empresa_reporte_id":\d+/.test(writes[1].postData ?? '') && !/-0000-/.test(writes[1].postData ?? ''), writes.map(w => w.postData).join(' | '));
  await b.shot(join(shots, '38-asistente-resultado.png'));
  const operatorLogin = await nodeApi('POST', '/api/login', null, { login: 'operador.prueba', password: PASSWORD });
  const operatorReports = await nodeApi('GET', '/api/reportes?view=minimal&per_page=100', operatorLogin.token);
  ok('persistió en MySQL: el usuario ve en el servidor solo Reporte A', JSON.stringify((operatorReports.data ?? []).map(r => r.nombre)) === '["Reporte A"]');

  current = 'UX-07A · el usuario creado';
  await clickLink(b, 'Ver accesos');
  await b.type('#ax-q', 'operador');
  ok('el listado de accesos muestra la asignación vigente', (await tableRows(b)).some(r => r.includes('Operador Prueba') && r.includes('Reporte A') && r.includes('Empresa A') && r.includes('Vigente')));
  await b.shot(join(shots, '39-accesos-listado.png'));
  await logout(b);
  await login(b, 'operador.prueba');
  ok('entra como usuario y ve únicamente Reporte A', await path(b) === '/reportes' && JSON.stringify(await cards(b)) === '["Reporte A"]', (await cards(b)).join(', '));
  await go(b, '/reportes/2');
  ok('un informe de Empresa B → acceso denegado neutral', (await text(b, '#page-title')) === 'Acceso denegado' && !(await body(b)).includes('Reporte B'));
  await go(b, '/admin/usuarios');
  ok('no alcanza las pantallas administrativas', (await text(b, '.denied')).includes('solo para administradores'));
  await logout(b);

  current = 'UX-07A · retirada de acceso';
  await login(b, 'admin');
  await go(b, '/admin/accesos?q=operador');
  await clickByText('button', 'Retirar');
  ok('pide confirmación antes de retirar', await count(b, 'dialog[open]') === 1 && (await text(b, '.modal')).includes('Operador Prueba') && (await text(b, '.modal')).includes('Reporte A'));
  await b.shot(join(shots, '40-retirar-confirmar.png'));
  await clickByText('.modal button', 'Cancelar');
  ok('cancelar no retira nada', await count(b, 'dialog[open]') === 0 && (await tableRows(b)).some(r => r.includes('Operador Prueba')) && !b.requestLog.some(r => r.method === 'DELETE'));
  await clickByText('button', 'Retirar');
  await b.click('.modal form button[type=submit]'); await b.wait(1200);
  ok('retirada confirmada por PHP: aviso y la fila desaparece', (await body(b)).includes('retirado') && !(await tableRows(b)).some(r => r.includes('Operador Prueba')));
  ok('se llamó a DELETE /api/empresa-reporte-usuario/{id numérico}', b.requestLog.some(r => r.method === 'DELETE' && /\/api\/empresa-reporte-usuario\/\d+$/.test(r.url)));
  await b.shot(join(shots, '41-retirada-confirmada.png'));

  current = 'UX-07A · persistencia tras recargar';
  await b.send('Page.reload'); await b.wait(1200);
  ok('recargar vuelve al login (sesión solo en memoria)', await path(b) === '/login');
  await login(b, 'admin');
  await go(b, '/admin/accesos?q=operador');
  ok('tras recargar, el acceso retirado sigue retirado', !(await tableRows(b)).some(r => r.includes('Operador Prueba')) && (await text(b, '#view')).includes('Sin resultados'));
  await go(b, '/admin/usuarios?q=operador');
  ok('tras recargar, el usuario creado sigue existiendo', (await tableRows(b)).some(r => r.includes('Operador Prueba')));
  await logout(b);
  await login(b, 'operador.prueba');
  ok('el usuario ya no ve informes', await path(b) === '/reportes' && await count(b, '.report-card') === 0);
  await go(b, '/reportes/1');
  ok('el informe retirado ya no se puede abrir (acceso denegado neutral)', (await text(b, '#page-title')) === 'Acceso denegado');
  const afterApi = await nodeApi('GET', '/api/reportes?view=minimal&per_page=100', (await nodeApi('POST', '/api/login', null, { login: 'operador.prueba', password: PASSWORD })).token);
  ok('MySQL: el servidor tampoco le entrega ningún informe', (afterApi.data ?? []).length === 0);

  current = 'UX-07A · reasignación (reactivar tras retirar)';
  await logout(b);
  await login(b, 'admin');
  await go(b, `/admin/accesos/nueva?user=${created.id}`);
  await clickByText('button', 'Continuar');
  await b.eval(`[...document.querySelectorAll('input[name=wz-company]')].find(i => i.closest('label').textContent.includes('Empresa A')).click()`); await b.wait(300);
  await clickByText('button', 'Continuar');
  ok('tras retirar, el informe vuelve a estar disponible (no figura como asignado)', await b.eval(`[...document.querySelectorAll('.selection-list input[type=checkbox]')].find(i => i.closest('label').textContent.includes('Reporte A')).disabled === false`));
  await b.eval(`[...document.querySelectorAll('.selection-list input[type=checkbox]')].find(i => i.closest('label').textContent.includes('Reporte A')).click()`); await b.wait(300);
  await clickByText('button', 'Continuar');
  await clickByText('button', 'Guardar accesos'); await b.wait(1200);
  ok('se vuelve a conceder (PHP reactiva la relación retirada en lugar de duplicarla)', (await text(b, '[aria-label="Resultado por informe"]')).includes('Concedido'));
  const again = await nodeApi('GET', '/api/reportes?view=minimal&per_page=100', (await nodeApi('POST', '/api/login', null, { login: 'operador.prueba', password: PASSWORD })).token);
  ok('MySQL: vuelve a ver Reporte A', JSON.stringify((again.data ?? []).map(r => r.nombre)) === '["Reporte A"]');
  ok('sin errores de consola (flujo administrativo)', b.consoleErrors.length === 0, b.consoleErrors.join(' | '));
  await b.close();
}

/* UX-07B: circuito completo empresa → reporte → asociación → usuario → acceso → retirada, sobre PHP y MySQL sintéticos. */
async function catalogFlow() {
  const b = await launch({ port: 9505 });
  await b.goto(FRONT + '/');
  const settle = () => b.wait(1200);
  const clickByText = async (selector, label) => { const done = await b.eval(`(() => { const e = [...document.querySelectorAll(${JSON.stringify(selector)})].find(x => x.textContent.includes(${JSON.stringify(label)})); if (!e) return false; e.click(); return true; })()`); await b.wait(500); return done; };
  const tableRows = b => b.eval(`[...document.querySelectorAll('tbody tr')].map(r => r.innerText.replace(/\\s+/g, ' ').trim())`);
  // El cierre de sesión de la interfaz revoca la sesión del administrador: cada consulta directa usa un inicio de sesión nuevo.
  const A = async () => (await nodeApi('POST', '/api/login', null, { login: 'admin', password: PASSWORD })).token;
  const digits = Array.from({ length: 9 }, () => Math.floor(Math.random() * 10)).join('');
  const ruc = `20${digits}`;
  const token = `U0lOVEVUSUNP${digits}`; const token2 = `U0lOVEVUSUNQ${digits}`;
  const link = `https://app.powerbi.com/view?r=${token}`; const link2 = `https://app.powerbi.com/view?r=${token2}&pageName=ReportSection1`;
  const companyName = `Empresa C ${digits.slice(0, 4)}`; const reportName = `Informe Sintético ${digits.slice(0, 4)}`; const username = `operador.${digits.slice(0, 5)}`;
  const findCompany = async () => (await nodeApi('GET', '/api/empresas?view=minimal&per_page=100', await A())).data.find(c => c.ruc === ruc);
  const findReport = async () => (await nodeApi('GET', '/api/reportes?view=minimal&per_page=100', await A())).data.find(r => r.nombre === reportName);

  current = 'UX-07B · empresas';
  await login(b, 'admin');
  await navTo(b, 'Empresas'); await settle();
  ok('«Nueva empresa» disponible', await clickByText('button', 'Nueva empresa') && await count(b, 'dialog[open]') === 1);
  ok('el formulario no tiene campos de credenciales de Power BI', await b.eval(`[...document.querySelectorAll('.modal label')].every(l => !/tenant|secret|client|power ?bi/i.test(l.firstChild?.textContent ?? ''))`) && await count(b, '.modal input') === 8);
  await b.shot(join(shots, '50-empresa-nueva.png'));
  await b.click('.modal form button[type=submit]');
  ok('validaciones: nombre, razón social y RUC obligatorios, sin llamar al servidor', (await text(b, '.modal')).includes('Ingrese el nombre.') && (await text(b, '.modal')).includes('11 dígitos') && !b.requestLog.some(r => r.method === 'POST' && r.url.endsWith('/api/empresas')));
  await b.type('#co-name', companyName); await b.type('#co-legal', `${companyName} S.A.C.`); await b.type('#co-ruc', '00000000001'); // RUC de Empresa A
  await b.click('.modal form button[type=submit]'); await b.wait(900);
  ok('RUC duplicado: PHP lo rechaza, el diálogo sigue abierto y no hay éxito falso', (await text(b, '.modal')).includes('El RUC ya está registrado') && await count(b, 'dialog[open]') === 1 && !(await body(b)).includes('creada correctamente') && !(await findCompany()));
  await b.shot(join(shots, '51-empresa-ruc-duplicado.png'));
  await b.type('#co-ruc', ruc); await b.type('#co-city', 'Cusco');
  await b.click('.modal form button[type=submit]'); await b.wait(1200);
  ok('empresa creada: aviso y fila en el listado', (await body(b)).includes(`Empresa «${companyName}» creada correctamente.`) && (await tableRows(b)).some(r => r.includes(companyName) && r.includes('Cusco')));
  const company = await findCompany();
  ok('persistió en MySQL con RUC y estado activo', company?.nombre === companyName && company?.activo === 1);
  await b.shot(join(shots, '52-empresa-creada.png'));
  await b.eval(`[...document.querySelectorAll('button')].find(x => x.getAttribute('aria-label') === ${JSON.stringify(`Editar ${companyName}`)}).click()`); await b.wait(900);
  ok('editar carga el detalle real (RUC y ciudad)', await b.eval(`document.querySelector('#co-ruc').value === ${JSON.stringify(ruc)} && document.querySelector('#co-city').value === 'Cusco'`));
  await b.type('#co-city', 'Arequipa'); await b.type('#co-phone', '988 777 666');
  await b.click('.modal form button[type=submit]'); await b.wait(1200);
  ok('edición confirmada y reflejada en la lista', (await body(b)).includes('actualizada correctamente') && (await tableRows(b)).some(r => r.includes(companyName) && r.includes('Arequipa')));
  const edited = (await nodeApi('GET', `/api/empresas/${company.id}?view=form`, await A())).data;
  ok('persistió en MySQL (ciudad y teléfono) sin tocar Power BI', edited.ciudad === 'Arequipa' && edited.telefono === '988 777 666' && !JSON.stringify(edited).match(/powerbi/));

  current = 'UX-07B · reportes';
  await navTo(b, 'Reportes'); await settle();
  ok('«Nuevo reporte» disponible', await clickByText('button', 'Nuevo reporte') && await count(b, 'dialog[open]') === 1);
  ok('el formulario simplificado: solo nombre, descripción y enlace (sin pedir ids de Power BI) y pide datos sintéticos', (await text(b, '.modal')).includes('solo enlaces sintéticos') && await count(b, '.modal input') === 3 && await count(b, '#rp-group, #rp-uuid') === 0);
  await b.shot(join(shots, '60-reporte-formulario-simplificado.png'));
  await b.type('#rp-name', reportName); await b.type('#rp-description', 'Informe de prueba UX-07C'); await b.type('#rp-link', 'esto no es una url');
  await b.click('.modal form button[type=submit]');
  ok('enlace inválido: mensaje antes de enviar, sin llamar al servidor', (await text(b, '.modal')).includes('HTTPS') && !b.requestLog.some(r => r.method === 'POST' && r.url.endsWith('/api/reportes')));
  await b.type('#rp-link', 'https://example.invalid/informes/ux07c');
  ok('dominio externo: se detecta como no compatible', (await text(b, '[data-testid=link-detected]')).includes('Enlace no compatible') && (await text(b, '[data-testid=link-detected]')).includes('app.powerbi.com'));
  const forged = await nodeApi('POST', '/api/reportes', await A(), { nombre: `${reportName} (forzado)`, enlace: 'https://example.invalid/informes/ux07c' });
  ok('PHP también rechaza el dominio externo si se evita la interfaz', forged?.success === false && !(await nodeApi('GET', '/api/reportes?view=minimal&per_page=100', await A())).data.some(r => r.nombre.includes('(forzado)')));
  await b.type('#rp-link', '<iframe src="https://app.powerbi.com/view?r=' + token + '"></iframe>');
  ok('un iframe pegado se rechaza (solo se admite la URL)', (await text(b, '[data-testid=link-detected]')).includes('no el código HTML'));
  await b.shot(join(shots, '53-reporte-validaciones.png'));
  await b.type('#rp-link', link);
  ok('enlace de publicación en web: tipo detectado y sin identificadores inventados', (await text(b, '[data-testid=link-detected]')).includes('Publicación web') && (await text(b, '[data-testid=link-detected]')).includes('no se inventan'));
  await b.shot(join(shots, '61-reporte-enlace-detectado.png'));
  await b.click('.modal form button[type=submit]'); await b.wait(1200);
  ok('reporte creado: aviso y fila sin empresa', (await body(b)).includes(`Reporte «${reportName}» creado correctamente`) && (await tableRows(b)).some(r => r.includes(reportName) && r.includes('—')));
  const report = await findReport();
  const reportForm = (await nodeApi('GET', `/api/reportes/${report?.id}?view=form`, await A())).data;
  ok('persistió con identidad interna estable: id numérico, referencia UUID v4 propia y SIN identificadores de Power BI inventados (grupo NULL)', Boolean(report) && /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(report.reporte_id) && reportForm.grupo_id === null && reportForm.enlace === link);
  await b.select('#ar-status', 'unlinked'); await b.wait(400);
  ok('el filtro «Sin empresa» lo encuentra', (await tableRows(b)).some(r => r.includes(reportName)));
  await b.eval(`[...document.querySelectorAll('button')].find(x => x.getAttribute('aria-label') === ${JSON.stringify(`Editar ${reportName}`)}).click()`); await b.wait(900);
  ok('editar carga el enlace real (solo administrador)', await b.eval(`document.querySelector('#rp-link').value === ${JSON.stringify(link)}`));
  await b.type('#rp-description', 'Descripción editada');
  await b.click('.modal form button[type=submit]'); await b.wait(1200);
  ok('edición confirmada', (await body(b)).includes('actualizado correctamente') && (await tableRows(b)).some(r => r.includes(reportName) && r.includes('Descripción editada')));
  const stored = (await nodeApi('GET', `/api/reportes/${report.id}?view=form`, await A())).data;
  ok('persistió y el editor quedó registrado (updated_by)', stored.descripcion === 'Descripción editada' && (docker('exec', `${QA_PREFIX}-db`, 'sh', '-c', `mysql -N -uroot -p"$MYSQL_ROOT_PASSWORD" fixture -e "SELECT updated_by FROM reporte WHERE id = ${Number(report.id)}" 2>/dev/null`).stdout ?? '').trim().match(/^\d+$/) !== null);
  await b.shot(join(shots, '54-reporte-editado.png'));

  current = 'UX-07B · asociación empresa ↔ informe';
  await navTo(b, 'Informes por empresa'); await settle();
  ok('sin empresa seleccionada se pide elegir una', (await body(b)).includes('Seleccione una empresa'));
  await b.select('#cr-company', String(company.id)); await b.wait(700);
  ok('la empresa nueva no tiene informes asociados', (await body(b)).includes('aún no tiene informes asociados'));
  ok('el informe nuevo figura entre los disponibles', await b.eval(`[...document.querySelectorAll('[aria-label="Informes disponibles"] label')].some(l => l.textContent.includes(${JSON.stringify(reportName)}))`));
  await b.shot(join(shots, '55-asociacion-disponibles.png'));
  await b.eval(`[...document.querySelectorAll('[aria-label="Informes disponibles"] label')].find(l => l.textContent.includes(${JSON.stringify(reportName)})).querySelector('input').click()`); await b.wait(300);
  await clickByText('button', 'Asociar seleccionados'); await b.wait(1200);
  ok('asociación confirmada: resultado por informe y aparece en «asociados»', (await text(b, '[aria-label="Resultado de la asociación"]')).includes('Asociado') && (await text(b, '[aria-label="Informes asociados"]')).includes(reportName));
  const links = (await nodeApi('GET', '/api/asignacion?view=minimal&per_page=100', await A())).data.data.filter(a => a.reporte_id === report.id);
  ok('persistió en MySQL con los ids numéricos (empresa.id y reporte.id)', links.length === 1 && links[0].empresa_id === company.id);
  const asociar = b.requestLog.filter(r => r.method === 'POST' && r.url.endsWith('/api/asignacion')).at(-1);
  ok('la petición usa ids numéricos, no el UUID', Boolean(asociar) && JSON.parse(asociar.postData).empresa_id === company.id && JSON.parse(asociar.postData).reporte_id === Number(report.id));
  await b.shot(join(shots, '56-asociacion-confirmada.png'));
  await b.select('#cr-company', '1'); await b.wait(700); // Empresa A: informe ajeno ya compartido no se confunde
  ok('Empresa A conserva sus informes y no recibe el nuevo', !(await text(b, '[aria-label="Informes asociados"]')).includes(reportName));
  await navTo(b, 'Reportes'); await settle();
  ok('el listado de reportes muestra el informe con su empresa', (await tableRows(b)).some(r => r.includes(reportName) && r.includes(companyName)));

  current = 'UX-07C · editar el enlace conserva identidad y asociaciones';
  await navTo(b, 'Reportes'); await settle();
  await b.eval(`[...document.querySelectorAll('button')].find(x => x.getAttribute('aria-label') === ${JSON.stringify(`Editar ${reportName}`)}).click()`); await b.wait(900);
  await b.type('#rp-link', link2);
  await b.click('.modal form button[type=submit]'); await b.wait(1200);
  const afterEdit = (await nodeApi('GET', `/api/reportes/${report.id}?view=form`, await A())).data;
  ok('el enlace nuevo se guardó y la referencia interna, el id numérico y la ausencia de grupo no cambiaron', afterEdit.enlace === link2 && afterEdit.reporte_id === report.reporte_id && String(afterEdit.id) === String(report.id) && afterEdit.grupo_id === null);
  ok('la asociación con la empresa sigue vigente tras editar el enlace', (await nodeApi('GET', '/api/asignacion?view=minimal&per_page=100', await A())).data.data.some(a => a.reporte_id === report.id && a.empresa_id === company.id));

  current = 'UX-07B · integración con UX-07A';
  await navTo(b, 'Usuarios'); await settle();
  await clickByText('button', 'Nuevo usuario');
  await b.type('#nu-first', 'Operador'); await b.type('#nu-last', 'Catálogo'); await b.type('#nu-username', username); await b.type('#nu-email', `${username}@example.invalid`); await b.type('#nu-password', PASSWORD);
  await b.click('.modal form button[type=submit]'); await b.wait(1200);
  ok('usuario sintético creado', (await body(b)).includes('creado correctamente'));
  await clickLink(b, 'Asignar accesos a Operador Catálogo');
  await clickByText('button', 'Continuar');
  await b.eval(`[...document.querySelectorAll('input[name=wz-company]')].find(i => i.closest('label').textContent.includes(${JSON.stringify(companyName)})).click()`); await b.wait(300);
  await clickByText('button', 'Continuar');
  ok('el asistente ofrece el informe recién registrado (y solo los de la empresa)', JSON.stringify(await b.eval(`[...document.querySelectorAll('.selection-list input[type=checkbox]')].map(i => i.closest('label').querySelector('strong').textContent)`)) === JSON.stringify([reportName]));
  await b.eval(`document.querySelector('.selection-list input[type=checkbox]').click()`); await b.wait(300);
  await clickByText('button', 'Continuar');
  await clickByText('button', 'Guardar accesos'); await b.wait(1300);
  ok('acceso concedido', (await text(b, '.success-box')).includes('Accesos guardados'));
  await b.shot(join(shots, '57-acceso-informe-nuevo.png'));
  await logout(b);
  await login(b, username);
  ok('el usuario entra y ve únicamente el informe asignado', await path(b) === '/reportes' && JSON.stringify(await cards(b)) === JSON.stringify([reportName]), (await cards(b)).join(', '));
  ok('el catálogo del usuario no trae el enlace de publicación', !(await b.eval('document.documentElement.outerHTML')).includes('app.powerbi.com') && !(await nodeApi('GET', '/api/reportes?view=minimal&per_page=100', (await nodeApi('POST', '/api/login', null, { login: username, password: PASSWORD })).token).then(r => JSON.stringify(r)).catch(() => '')).includes('powerbi.com'));
  await b.shot(join(shots, '58-usuario-ve-informe.png'));
  await go(b, '/reportes/2'); await settle();
  ok('un informe ajeno → acceso denegado neutral', (await text(b, '#page-title')) === 'Acceso denegado');
  await go(b, `/reportes/${report.id}`); await settle(); await b.wait(900);
  ok('el informe asignado abre en el visor: iframe hacia la página local con el token del enlace vigente (el nuevo, no el anterior)', (await text(b, '#page-title')) === reportName && await b.eval(`document.querySelector('.powerbi-frame iframe')?.src === location.origin + '/qa/powerbi-view.html?r=${token2}&pageName=ReportSection1'`));
  const frameMsg2 = await stubMessage(b);
  ok('el marco de pruebas informa el token y la página del enlace vigente', frameMsg2?.token === token2 && frameMsg2?.page === 'ReportSection1');
  await b.shot(join(shots, '62-visor-informe-nuevo.png'));
  const userToken = (await nodeApi('POST', '/api/login', null, { login: username, password: PASSWORD })).token;
  const viewerOf = async tok => nodeApi('GET', `/api/reportes/id/${report.id}/visor`, tok);
  const authorized = await viewerOf(userToken);
  ok('el servidor entrega la configuración solo al usuario autorizado (modo público y enlace vigente, sin credenciales)', authorized?.data?.modo === 'publico' && authorized.data.url === link2 && !JSON.stringify(authorized).match(/secret|tenant|client|password|token/i));
  const other = await viewerOf((await nodeApi('POST', '/api/login', null, { login: 'userb', password: PASSWORD })).token);
  ok('otro usuario (sin acceso) no obtiene la URL: respuesta idéntica a un informe inexistente', other?.success === false && JSON.stringify(other) === JSON.stringify(await nodeApi('GET', '/api/reportes/id/4000000000/visor', userToken)) && !JSON.stringify(other).includes('powerbi'));
  await nodeApi('DELETE', `/api/asignacion/${links[0].id}`, await A());
  ok('desactivar la asociación empresa ↔ informe bloquea nuevas solicitudes del visor', (await viewerOf(userToken))?.success === false);
  await nodeApi('POST', '/api/asignacion', await A(), { empresa_id: company.id, reporte_id: Number(report.id) });
  ok('reasociar restablece el visor (misma relación reactivada)', (await viewerOf(userToken))?.data?.modo === 'publico');
  await logout(b);

  current = 'UX-07B · retirada y persistencia';
  await login(b, 'admin');
  await go(b, `/admin/accesos?q=${encodeURIComponent('Catálogo')}`); await settle();
  await clickByText('button', 'Retirar');
  await b.click('.modal form button[type=submit]'); await b.wait(1200);
  ok('acceso retirado con confirmación', (await body(b)).includes('retirado') && !(await tableRows(b)).some(r => r.includes('Operador Catálogo')));
  await logout(b);
  await login(b, username);
  ok('el usuario ya no ve el informe', await path(b) === '/reportes' && await count(b, '.report-card') === 0);
  ok('retirar el acceso bloquea nuevas solicitudes al visor (el servidor, no la interfaz)', (await nodeApi('GET', `/api/reportes/id/${report.id}/visor`, (await nodeApi('POST', '/api/login', null, { login: username, password: PASSWORD })).token))?.success === false);
  await go(b, `/reportes/${report.id}`); await settle();
  ok('y no puede abrirlo', (await text(b, '#page-title')) === 'Acceso denegado' && await count(b, 'iframe') === 0);
  await logout(b);
  await login(b, 'admin');
  await go(b, `/admin/asociaciones?company=${company.id}`); await settle();
  ok('la asociación empresa ↔ informe sigue vigente (retirar el acceso no la rompe)', (await text(b, '[aria-label="Informes asociados"]')).includes(reportName));
  await b.eval(`[...document.querySelectorAll('button')].find(x => x.getAttribute('aria-label') === ${JSON.stringify(`Desvincular ${reportName} de ${companyName}`)}).click()`); await b.wait(500);
  ok('desvincular pide confirmación e informa del efecto', await count(b, 'dialog[open]') === 1 && (await text(b, '.modal')).includes('dejarán de verlo'));
  await b.shot(join(shots, '59-desvincular-confirmar.png'));
  await clickByText('.modal button', 'Cancelar');
  ok('cancelar no desvincula', (await text(b, '[aria-label="Informes asociados"]')).includes(reportName) && !b.requestLog.some(r => r.method === 'DELETE' && r.url.includes('/api/asignacion/')));
  await b.eval(`[...document.querySelectorAll('button')].find(x => x.getAttribute('aria-label') === ${JSON.stringify(`Desvincular ${reportName} de ${companyName}`)}).click()`); await b.wait(500);
  await b.click('.modal form button[type=submit]'); await b.wait(1200);
  ok('desvinculado: aviso y el informe vuelve a «disponibles»', (await body(b)).includes('desvinculado de') && !(await text(b, '[aria-label="Informes asociados"]').catch(() => '')).includes(reportName) && await b.eval(`[...document.querySelectorAll('[aria-label="Informes disponibles"] label')].some(l => l.textContent.includes(${JSON.stringify(reportName)}))`));
  ok('MySQL: la asociación quedó inactiva y el informe no se eliminó', (await nodeApi('GET', '/api/asignacion?view=minimal&per_page=100', await A())).data.data.filter(a => a.reporte_id === report.id).length === 0 && Boolean(await findReport()));
  await b.send('Page.reload'); await b.wait(1200);
  await login(b, 'admin');
  await go(b, `/admin/asociaciones?company=${company.id}`); await settle();
  ok('tras recargar, la asociación sigue desvinculada y la empresa y el informe siguen existiendo', !(await text(b, '[aria-label="Informes asociados"]').catch(() => '')).includes(reportName) && Boolean(await findCompany()) && Boolean(await findReport()));
  await b.select('#cr-company', String(company.id));
  ok('sin errores de consola (UX-07B)', b.consoleErrors.length === 0, b.consoleErrors.join(' | '));
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
  await go(b, '/reportes/2');
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
  await go(b, '/admin/usuarios');
  ok('usuarios (admin) en móvil: tarjetas sin desbordamiento horizontal', await b.eval(`getComputedStyle(document.querySelector('tbody tr')).display === 'block' && document.documentElement.scrollWidth <= innerWidth`));
  await b.eval(`[...document.querySelectorAll('button')].find(x => x.textContent.includes('Nuevo usuario')).click()`); await b.wait(500);
  ok('formulario de alta en móvil: cabe en pantalla y es usable', await b.eval(`(() => { const d = document.querySelector('dialog[open]'); const r = d.getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth && document.documentElement.scrollWidth <= innerWidth; })()`));
  await b.shot(join(shots, '25-movil-nuevo-usuario.png'));
  await b.eval(`[...document.querySelectorAll('dialog[open] button')].find(x => x.textContent.includes('Cancelar')).click()`); await b.wait(300);
  await go(b, '/admin/accesos/nueva');
  ok('asistente de accesos en móvil sin desbordamiento horizontal', await count(b, 'input[name=wz-user]') >= 1 && await b.eval(`document.documentElement.scrollWidth <= innerWidth`));
  await b.shot(join(shots, '26-movil-asistente.png'));
  await go(b, '/admin/empresas');
  await b.wait(900);
  await b.eval(`[...document.querySelectorAll('button')].find(x => x.textContent.includes('Nueva empresa')).click()`); await b.wait(500);
  ok('formulario de empresa en móvil: cabe en pantalla', await b.eval(`(() => { const r = document.querySelector('dialog[open]').getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth && document.documentElement.scrollWidth <= innerWidth; })()`));
  await b.shot(join(shots, '27-movil-nueva-empresa.png'));
  await b.eval(`[...document.querySelectorAll('dialog[open] button')].find(x => x.textContent.includes('Cancelar')).click()`); await b.wait(300);
  await go(b, '/admin/reportes');
  await b.wait(900);
  await b.eval(`[...document.querySelectorAll('button')].find(x => x.textContent.includes('Nuevo reporte')).click()`); await b.wait(500);
  ok('formulario de reporte en móvil: cabe en pantalla', await b.eval(`(() => { const r = document.querySelector('dialog[open]').getBoundingClientRect(); return r.left >= 0 && r.right <= innerWidth && document.documentElement.scrollWidth <= innerWidth; })()`));
  await b.eval(`[...document.querySelectorAll('dialog[open] button')].find(x => x.textContent.includes('Cancelar')).click()`); await b.wait(300);
  await go(b, '/admin/asociaciones?company=1');
  await b.wait(900);
  ok('asociaciones en móvil sin desbordamiento horizontal', await count(b, '[aria-label="Informes asociados"]') === 1 && await b.eval(`document.documentElement.scrollWidth <= innerWidth`));
  await b.shot(join(shots, '28-movil-asociaciones.png'));
  ok('sin errores de consola (móvil)', b.consoleErrors.length === 0, b.consoleErrors.join(' | '));
  await b.close();
}

try {
  for (const step of [desktop, adminFlow, catalogFlow, expiredJwt, mobile]) { try { await step(); } catch (e) { results.push(['FAIL', `${current} · EXCEPCIÓN`, e.message]); } }
} finally {
  previews.forEach(p => p.kill());
  docker('start', `${QA_PREFIX}-proxy`);
}
for (const [status, name, extra] of results) console.log(`${status}  ${name}${extra ? `  → ${extra}` : ''}`);
const failed = results.filter(r => r[0] === 'FAIL').length;
console.log(`\n${results.length - failed} PASS / ${failed} FAIL`);
process.exit(failed ? 1 : 0);
