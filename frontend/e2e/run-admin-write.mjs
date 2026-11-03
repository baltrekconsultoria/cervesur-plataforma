// E2E UX-08H: navegador (Edge/CDP) → compilación `admin-write` → backend PHP REAL en modo admin_escritura (datos sintéticos, aislado).
// Requiere: `npm run build:admin-write` (CERVESUR_ALLOWED_HOSTS=127.0.0.1) y tres instancias del backend publicadas en loopback:
//   E2E_API_ALL (todas las escrituras + sensibles) · E2E_API_EMP (solo empresas) · E2E_API_OFF (ninguna). Las prepara scripts de la rama (ver docs/UX-08H-INFORME-QA.md).
// Uso: node e2e/run-admin-write.mjs
import { createServer, request as httpRequest } from 'node:http';
import { existsSync, mkdirSync, readFileSync } from 'node:fs';
import { dirname, extname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { launch } from './cdp.mjs';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const dist = join(root, 'dist-admin-write');
const shots = process.env.E2E_SHOTS_DIR ?? join(root, 'e2e', '.tmp', 'capturas-admin-write');
mkdirSync(shots, { recursive: true });
const APIS = { all: process.env.E2E_API_ALL ?? 'http://127.0.0.1:18080', emp: process.env.E2E_API_EMP ?? 'http://127.0.0.1:18081', off: process.env.E2E_API_OFF ?? 'http://127.0.0.1:18082' };
const PORTS = { all: 4281, emp: 4282, off: 4283 };
const PASSWORD = 'synthetic-password';
const MIME = { '.html': 'text/html; charset=utf-8', '.js': 'text/javascript', '.css': 'text/css', '.json': 'application/json', '.svg': 'image/svg+xml', '.png': 'image/png' };
const results = [];
let current = '';
const ok = (name, cond, extra = '') => results.push([cond ? 'PASS' : 'FAIL', `${current} · ${name}`, extra]);
const sleep = ms => new Promise(r => setTimeout(r, ms));

/** Servidor de la compilación + proxy `/api` al backend (la compilación usa el mismo origen). */
function serve(port, api) {
  const target = new URL(api);
  const server = createServer((req, res) => {
    if (req.url.startsWith('/api/')) {
      const proxied = httpRequest({ host: target.hostname, port: target.port, path: req.url, method: req.method, headers: req.headers }, upstream => { res.writeHead(upstream.statusCode ?? 502, upstream.headers); upstream.pipe(res); });
      proxied.on('error', () => { res.writeHead(502); res.end(); });
      req.pipe(proxied);
      return;
    }
    const clean = decodeURIComponent(req.url.split('?')[0]);
    let file = join(dist, clean === '/' ? 'index.html' : clean);
    if (!file.startsWith(dist) || !existsSync(file) || clean.endsWith('/')) file = join(dist, 'index.html');
    res.writeHead(200, { 'Content-Type': MIME[extname(file)] ?? 'application/octet-stream' });
    res.end(readFileSync(file));
  });
  return new Promise(resolve => server.listen(port, '127.0.0.1', () => resolve(server)));
}

const count = (b, sel) => b.eval(`document.querySelectorAll(${JSON.stringify(sel)}).length`);
const body = b => b.eval('document.body.innerText');
const has = async (b, label) => (await body(b)).includes(label);
const go = async (b, to) => { await b.eval(`history.pushState({}, '', ${JSON.stringify(to)}); dispatchEvent(new PopStateEvent('popstate'))`); await b.wait(700); };
const login = async (b, user) => { await b.type('#login-name', user); await b.type('#login-password', PASSWORD); await b.click('form button[type=submit]'); await b.wait(1200); };
const clickText = (b, label, tag = 'button') => b.eval(`(() => { const e = [...document.querySelectorAll(${JSON.stringify(tag)})].find(x => x.textContent.trim() === ${JSON.stringify(label)} || x.getAttribute('aria-label') === ${JSON.stringify(label)}); if (!e) return false; e.click(); return true; })()`);
async function nodeApi(api, method, route, token, payload) {
  const res = await fetch(api + route, { method, headers: { 'Content-Type': 'application/json', 'X-API-Contract': '2', ...(token ? { Authorization: `Bearer ${token}` } : {}) }, body: payload ? JSON.stringify(payload) : undefined });
  return { status: res.status, json: await res.json().catch(() => null) };
}
const apiToken = async api => (await nodeApi(api, 'POST', '/api/login', null, { login: 'admin', password: PASSWORD })).json?.token ?? '';

const servers = await Promise.all(Object.keys(PORTS).map(k => serve(PORTS[k], APIS[k])));
const front = k => `http://127.0.0.1:${PORTS[k]}`;

async function scenarioAll() {
  const b = await launch({ port: 9531 });
  try {
    await b.goto(front('all') + '/');
    current = 'Todo encendido';
    await login(b, 'admin');
    ok('el administrador entra al panel', (await b.eval('location.pathname')) === '/admin');
    ok('aviso de la compilación de escritura visible', await has(b, 'Los cambios se guardan y quedan registrados'));
    await go(b, '/admin/empresas');
    ok('Empresas: aparece «Nueva empresa» (capacidad real del servidor)', await has(b, 'Nueva empresa'));
    await clickText(b, 'Nueva empresa');
    await b.wait(500);
    await b.type('#co-name', 'Empresa E2E'); await b.type('#co-legal', 'Empresa E2E SAC'); await b.type('#co-ruc', '20999000111'); await b.type('#co-city', 'Lima');
    await b.shot(join(shots, '01-empresa-formulario.png'));
    // doble envío: dos clics seguidos deben crear UNA sola empresa
    await b.eval(`(() => { const f = document.querySelector('.modal-body')?.closest('form') ?? document.querySelector('form'); f.requestSubmit(); f.requestSubmit(); })()`);
    await b.wait(1500);
    const token = await apiToken(APIS.all);
    const list = await nodeApi(APIS.all, 'GET', '/api/empresas?view=minimal&per_page=100&status=all', token);
    const made = (list.json?.data ?? []).filter(e => e.ruc === '20999000111');
    ok('doble envío: se creó UNA sola empresa', made.length === 1, `filas=${made.length}`);
    ok('confirmación visible y la empresa figura en la lista', (await has(b, 'Empresa E2E')) && (await count(b, '[role=status]')) >= 1);
    await b.shot(join(shots, '02-empresa-creada.png'));
    // duplicado: mensaje comprensible, el diálogo no se cierra
    await clickText(b, 'Nueva empresa'); await b.wait(400);
    await b.type('#co-name', 'Duplicada'); await b.type('#co-legal', 'Duplicada SAC'); await b.type('#co-ruc', '20999000111');
    await clickText(b, 'Crear empresa'); await b.wait(1200);
    ok('RUC duplicado: mensaje de error comprensible y el formulario sigue abierto', (await has(b, 'RUC')) && (await count(b, '[role=dialog], .modal')) >= 1);
    await b.shot(join(shots, '03-empresa-duplicada.png'));
    await clickText(b, 'Cancelar'); await b.wait(400);

    await go(b, '/admin/reportes');
    ok('Reportes: aparece «Nuevo reporte»', await has(b, 'Nuevo reporte'));
    await clickText(b, 'Nuevo reporte'); await b.wait(500);
    const link = 'https://app.powerbi.com/reportEmbed?reportId=eeeeeeee-0000-4000-8000-000000000001&groupId=bbbbbbbb-0000-4000-8000-000000000001';
    await b.type('#rp-name', 'Informe E2E'); await b.type('#rp-description', 'creado por E2E'); await b.type('#rp-link', link);
    await clickText(b, 'Crear reporte'); await b.wait(1500);
    ok('informe creado y visible en la lista', await has(b, 'Informe E2E'));
    ok('la página de reportes NO muestra el enlace', !(await body(b)).includes('powerbi.com'));
    await clickText(b, 'Editar Informe E2E'); await b.wait(800);
    ok('edición: aviso «ya hay un enlace configurado» y el campo del enlace vacío', (await count(b, '[data-testid=link-configured]')) === 1 && (await b.eval(`document.querySelector('#rp-link').value`)) === '');
    ok('edición: el DOM no contiene el enlace', !(await b.eval('document.documentElement.outerHTML')).includes('eeeeeeee-0000'));
    await b.shot(join(shots, '04-informe-edicion.png'));
    await b.type('#rp-name', 'Informe E2E editado');
    await clickText(b, 'Guardar cambios'); await b.wait(1500);
    ok('guardar sin tocar el enlace: se aplica el nuevo nombre', await has(b, 'Informe E2E editado'));
    const reps = await nodeApi(APIS.all, 'GET', '/api/reportes?view=minimal&per_page=100&status=all', token);
    ok('el servidor no devuelve enlaces en el listado', !JSON.stringify(reps.json).includes('powerbi.com'));

    await go(b, '/admin/usuarios');
    ok('Usuarios: «Nuevo usuario» y «Cambiar rol» (interruptores sensibles encendidos)', (await has(b, 'Nuevo usuario')) && (await b.eval(`[...document.querySelectorAll('button')].some(x => (x.getAttribute('aria-label') || '').startsWith('Cambiar rol'))`)));
    await go(b, '/admin/historial');
    ok('Historial: lista los cambios hechos', (await has(b, 'empresa')) || (await has(b, 'Empresa')));
    await b.shot(join(shots, '05-historial.png'));
  } finally { await b.close(); }
}

async function scenarioEmp() {
  const b = await launch({ port: 9532 });
  try {
    await b.goto(front('emp') + '/');
    current = 'Solo empresas';
    await login(b, 'admin');
    await go(b, '/admin/empresas');
    ok('Empresas: «Nueva empresa» sí', await has(b, 'Nueva empresa'));
    await go(b, '/admin/reportes');
    ok('Reportes: sin «Nuevo reporte» ni acciones de baja', !(await has(b, 'Nuevo reporte')));
    await go(b, '/admin/usuarios');
    ok('Usuarios: sin «Nuevo usuario»', !(await has(b, 'Nuevo usuario')));
    await go(b, '/admin/accesos');
    ok('Accesos: sin «Nueva asignación»', !(await has(b, 'Nueva asignación')));
    // acceso directo a la ruta de escritura sin capacidad: el servidor la rechaza igual
    const t = await apiToken(APIS.emp);
    const r = await nodeApi(APIS.emp, 'POST', '/api/reportes', t, { nombre: 'x', enlace: 'x' });
    ok('ruta directa de escritura sin capacidad: 403 escritura_desactivada', r.status === 403 && r.json?.code === 'escritura_desactivada');
  } finally { await b.close(); }
}

async function scenarioOff() {
  const b = await launch({ port: 9533 });
  try {
    await b.goto(front('off') + '/');
    current = 'Sin escrituras';
    await login(b, 'admin');
    let any = false;
    for (const [path, label] of [['/admin/empresas', 'Nueva empresa'], ['/admin/reportes', 'Nuevo reporte'], ['/admin/usuarios', 'Nuevo usuario'], ['/admin/accesos', 'Nueva asignación']]) {
      await go(b, path);
      if (await has(b, label)) any = true;
    }
    ok('ninguna pantalla ofrece acciones de escritura (solo consulta)', !any);
    await go(b, '/admin/empresas');
    ok('hay una nota de solo consulta', await has(b, 'Versión de solo consulta'));
    await b.shot(join(shots, '06-solo-consulta.png'));
  } finally { await b.close(); }
}

try { await scenarioAll(); await scenarioEmp(); await scenarioOff(); } catch (error) { ok('el recorrido terminó sin excepciones', false, String(error?.stack ?? error)); }
for (const s of servers) s.close();
const failed = results.filter(r => r[0] === 'FAIL');
for (const [status, name, extra] of results) console.log(`${status} ${name}${extra ? ` (${extra})` : ''}`);
console.log(`\n${results.length - failed.length} PASS / ${failed.length} FAIL`);
process.exit(failed.length ? 1 : 0);
