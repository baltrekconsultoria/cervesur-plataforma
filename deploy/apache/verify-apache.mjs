// Verificación LOCAL de la configuración de Apache (nada se despliega): Apache + PHP + MySQL sintético en Docker,
// con la compilación de servidor (frontend/dist-production) y la configuración de deploy/apache/.
//   1) npm run build:production   (con CERVESUR_ALLOWED_HOSTS definido)         2) node deploy/apache/verify-apache.mjs
// Comprueba rutas de React, /api hacia PHP, caché, cabeceras, archivos sensibles y un recorrido real en el navegador (login, /admin,
// recarga de una URL interna, sin violaciones de CSP). No usa MySQL real, Internet ni informes reales de Power BI.
import { spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { launch } from '../../frontend/e2e/cdp.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const REPO = join(here, '..', '..');
const DIST = join(REPO, 'frontend', 'dist-production');
const QA = join(REPO, 'frontend', 'qa');
const PHP_IMAGE = 'cervesur-ci-php:local';
const APACHE_IMAGE = 'cervesur-apache-verify:local';
const PORT = Number(process.env.APACHE_VERIFY_PORT ?? 18090);
const BASE = `http://127.0.0.1:${PORT}`;
const N = { db: 'cervesur-apache-verify-db', web: 'cervesur-apache-verify-web', net: 'cervesur-apache-verify-net' };
const PASSWORD = 'synthetic-password';
const results = [];
let section = '';
const ok = (name, cond, extra = '') => results.push([cond ? 'PASS' : 'FAIL', `${section} · ${name}`, extra]);
const sleep = ms => new Promise(r => setTimeout(r, ms));
const docker = (args, { env = {}, allowFail = false } = {}) => {
  const r = spawnSync('docker', args, { env: { ...process.env, ...env }, encoding: 'utf8' });
  if (r.status !== 0 && !allowFail) throw new Error(`docker ${args.slice(0, 3).join(' ')} falló:\n${r.stderr || r.stdout}`);
  return { ok: r.status === 0, out: (r.stdout || '').trim() };
};
const cleanup = () => { docker(['rm', '--force', '--volumes', N.web, N.db], { allowFail: true }); docker(['network', 'rm', N.net], { allowFail: true }); };

if (!existsSync(join(DIST, 'index.html'))) { console.error('Falta frontend/dist-production. Ejecute npm run build:production.'); process.exit(1); }
const info = JSON.parse(readFileSync(join(DIST, 'build-info.json'), 'utf8'));
if (info.target !== 'server') { console.error('frontend/dist-production no es una compilación de servidor.'); process.exit(1); }

const secrets = { TEST_DB_PASS: randomBytes(18).toString('hex'), JWT_SECRET: randomBytes(24).toString('hex') };
const env = { ...secrets, DB_PASS: secrets.TEST_DB_PASS, MYSQL_ROOT_PASSWORD: secrets.TEST_DB_PASS };
const vol = ['--volume', `${REPO}:/work:ro`, '--workdir', '/work'];

async function fetchRaw(path, { method = 'GET', token, body, headers = {} } = {}) {
  const res = await fetch(BASE + path, { method, redirect: 'manual', headers: { ...(body ? { 'Content-Type': 'application/json' } : {}), ...(token ? { Authorization: `Bearer ${token}` } : {}), ...headers }, body: body ? JSON.stringify(body) : undefined });
  const raw = await res.text();
  let json = null; try { json = JSON.parse(raw); } catch { /* no JSON */ }
  return { status: res.status, headers: res.headers, raw, json };
}

try {
  cleanup();
  docker(['build', '--tag', APACHE_IMAGE, '--file', join(here, 'Dockerfile.verify'), here]);
  docker(['network', 'create', '--internal', N.net]);
  docker(['run', '--detach', '--name', N.db, '--network', N.net, '--network-alias', 'db', '--tmpfs', '/var/lib/mysql', '--env', 'MYSQL_ROOT_PASSWORD', '--env', 'MYSQL_DATABASE=fixture', 'mysql:8.0'], { env });
  for (let i = 0; ; i++) { if (docker(['exec', N.db, 'mysqladmin', 'ping', '-h', '127.0.0.1', '-uroot', `--password=${secrets.TEST_DB_PASS}`, '--silent'], { allowFail: true }).ok) break; if (i > 60) throw new Error('MySQL no arrancó'); await sleep(2000); }
  await sleep(3000);
  for (let i = 0; ; i++) { try { docker(['run', '--rm', '--network', N.net, ...vol, '--env', 'TEST_DB_PASS', PHP_IMAGE, 'php', 'tests/isolated/seed.php'], { env }); break; } catch (e) { if (i > 8) throw e; await sleep(2000); } }
  docker(['run', '--rm', '--network', N.net, ...vol, '--volume', `${QA}:/qa:ro`, '--env', 'TEST_DB_PASS', PHP_IMAGE, 'php', '/qa/seed-extra.php'], { env });
  // Apache: el frontend y el backend montados en solo lectura, la configuración común y el sitio de verificación.
  docker(['create', '--name', N.web, '--network', N.net, '--publish', `127.0.0.1:${PORT}:80`, '--read-only', '--tmpfs', '/tmp', '--tmpfs', '/var/run/apache2', '--tmpfs', '/var/lock/apache2', '--tmpfs', '/var/log/apache2',
    '--volume', `${DIST}:/var/www/cervesur-react-prueba/public:ro`, '--volume', `${join(REPO, 'backend')}:/opt/cervesur-react-prueba/backend:ro`,
    '--volume', `${join(here, 'react-common.conf')}:/etc/apache2/cervesur/react-common.conf:ro`, '--volume', `${join(here, 'vhost-verify.conf')}:/etc/apache2/sites-enabled/000-default.conf:ro`,
    '--env', 'DB_HOST=db', '--env', 'DB_NAME=fixture', '--env', 'DB_USER=root', '--env', 'DB_PASS', '--env', 'DB_CHARSET=utf8mb4', '--env', 'JWT_SECRET', '--env', 'JWT_EXPIRATION=3600', APACHE_IMAGE], { env });
  docker(['network', 'create', 'cervesur-apache-verify-pub'], { allowFail: true });
  docker(['network', 'connect', 'cervesur-apache-verify-pub', N.web]);
  docker(['start', N.web]);
  for (let i = 0; ; i++) { try { if ((await fetch(`${BASE}/api/ping`)).ok) break; } catch { /* arrancando */ } if (i > 40) throw new Error(`Apache no responde:\n${docker(['logs', N.web], { allowFail: true }).out}`); await sleep(500); }
  const config = docker(['exec', N.web, 'apachectl', 'configtest'], { allowFail: true });
  section = 'Configuración';
  ok('apachectl configtest: sintaxis correcta', /Syntax OK/.test(config.out) || config.ok, config.out.slice(-120));

  section = 'Rutas de React';
  const index = await fetchRaw('/');
  ok('/ devuelve index.html (200, HTML)', index.status === 200 && /text\/html/.test(index.headers.get('content-type') ?? '') && /<div id="root">/.test(index.raw));
  for (const route of ['/login', '/admin', '/admin/usuarios', '/reportes/00000000-0000-0000-0000-000000000011', '/perfil']) {
    const r = await fetchRaw(route);
    ok(`recargar ${route} no da 404 de Apache: entrega index.html`, r.status === 200 && r.raw === index.raw);
  }
  const asset = /src="(\/assets\/[^"]+\.js)"/.exec(index.raw)?.[1];
  const js = await fetchRaw(asset ?? '/assets/none.js');
  ok('el script de /assets se sirve como JavaScript', js.status === 200 && /javascript/.test(js.headers.get('content-type') ?? ''));
  ok('los assets con hash se cachean un año (immutable)', /max-age=31536000/.test(js.headers.get('cache-control') ?? '') && /immutable/.test(js.headers.get('cache-control') ?? ''));
  ok('index.html y las rutas de React no se cachean', /no-store/.test(index.headers.get('cache-control') ?? '') && /no-store/.test((await fetchRaw('/admin')).headers.get('cache-control') ?? ''));
  ok('un asset inexistente responde 404 real (no index.html)', (await fetchRaw('/assets/index-viejo.js')).status === 404);

  section = 'API hacia PHP';
  const ping = await fetchRaw('/api/ping');
  ok('/api/ping lo atiende PHP (JSON), no la reescritura de React', ping.status === 200 && ping.json?.pong === true && /no-store/.test(ping.headers.get('cache-control') ?? ''));
  const bad = await fetchRaw('/api/login', { method: 'POST', body: { login: 'usera', password: 'incorrecta' } });
  ok('login con credenciales inválidas: JSON de PHP (HTTP 200 success:false), no HTML', bad.status === 200 && bad.json?.success === false && bad.json?.message === 'Credenciales inválidas');
  const good = await fetchRaw('/api/login', { method: 'POST', body: { login: 'admin', password: PASSWORD } });
  const token = good.json?.token;
  ok('login válido: JWT y rol admin', good.json?.success === true && typeof token === 'string' && good.json?.user?.role === 'admin');
  const profile401 = await fetchRaw('/api/user/profile');
  ok('401 de la API llega como JSON (no se convierte en index.html)', profile401.status === 401 && profile401.json?.success === false);
  const users403 = await fetchRaw('/api/users', { token: (await fetchRaw('/api/login', { method: 'POST', body: { login: 'usera', password: PASSWORD } })).json?.token });
  ok('403 de la API llega como JSON', users403.status === 403 && users403.json?.success === false);
  const missing = await fetchRaw('/api/no-existe', { token });
  ok('404 de la API llega como JSON de PHP', missing.status === 404 && typeof missing.json?.error === 'string', `${missing.status} ${missing.raw.slice(0, 80)}`);
  const viewer = await fetchRaw('/api/reportes/reporteid/00000000-0000-0000-0000-000000000011/visor', { token });
  ok('el visor autorizado responde con no-store', viewer.status === 200 && /no-store/.test(viewer.headers.get('cache-control') ?? ''));
  ok('el endpoint heredado de tokens OAuth está bloqueado en este sitio', (await fetchRaw('/api/powerbi/tokens/1', { token })).status === 403);
  ok('ninguna respuesta de la API revela rutas del servidor, SQL ni trazas', ![ping, bad, good, profile401, users403, missing, viewer].some(r => /\/opt\/|\/var\/www|PDOException|SQLSTATE|Stack trace|\.php/.test(r.raw)));

  section = 'Archivos sensibles';
  for (const path of ['/.htaccess', '/.env', '/build-info.json', '/backend/api.php', '/api.php', '/controllers/AuthController.php', '/../backend/.env']) {
    const r = await fetchRaw(path);
    ok(`${path} no es accesible (${r.status})`, [403, 404].includes(r.status) && !/DB_PASS|JWT_SECRET|<\?php/.test(r.raw));
  }
  ok('sin listado de directorios en /assets/', [403, 404].includes((await fetchRaw('/assets/')).status));

  section = 'Cabeceras de seguridad y Power BI';
  const csp = index.headers.get('content-security-policy') ?? '';
  ok('CSP: frame-src solo hacia https://app.powerbi.com', /frame-src https:\/\/app\.powerbi\.com(;|$)/.test(csp) && /default-src 'self'/.test(csp) && /script-src 'self'/.test(csp) && /frame-ancestors 'none'/.test(csp) && !/unsafe-eval|unsafe-inline/.test(csp));
  ok('X-Content-Type-Options, X-Frame-Options y Referrer-Policy', index.headers.get('x-content-type-options') === 'nosniff' && index.headers.get('x-frame-options') === 'DENY' && Boolean(index.headers.get('referrer-policy')));
  ok('sin HSTS por HTTP (solo se emite cuando la solicitud llega por HTTPS)', !index.headers.get('strict-transport-security'));
  ok('el bundle publicado no referencia la página local de QA ni el endpoint heredado', !/powerbi-view\.html|\/api\/powerbi/.test(js.raw));

  section = 'Navegador real (Chrome/Edge) a través de Apache';
  const b = await launch({ port: 9511 });
  try {
    await b.goto(`${BASE}/`);
    await b.wait(800);
    const startup = `value=[${await b.eval(`document.querySelector('#login-name')?.value`)}] qa=${(await b.eval('document.body.innerText')).includes('Entorno QA')} | ${(await b.eval('document.body.innerText')).slice(0, 120)} | ${b.consoleErrors.slice(0, 2).join(' ; ')}`;
    ok('la aplicación carga y redirige a /login (proveedor HTTP, sin datos simulados)', (await b.eval('location.pathname')) === '/login' && (await b.eval(`document.querySelector('#login-name')?.value`)) === '' && !(await b.eval('document.body.innerText')).includes('Entorno QA'), startup);
    await b.type('#login-name', 'admin'); await b.type('#login-password', PASSWORD); await b.click('form button[type=submit]'); await b.wait(1200);
    ok('inicia sesión contra PHP y entra a /admin', (await b.eval('location.pathname')) === '/admin');
    await b.eval(`[...document.querySelectorAll('.nav-button')].find(a => a.textContent.includes('Usuarios')).click()`); await b.wait(1500);
    ok('/admin/usuarios muestra los usuarios reales del servidor (MySQL sintético)', (await b.eval(`document.querySelectorAll('tbody tr').length`)) >= 5 && (await b.eval('document.body.innerText')).includes('usera@example.invalid'));
    await b.send('Page.reload'); await b.wait(1500);
    ok('recargar una URL interna (/admin/usuarios) no da 404: vuelve al login (sesión solo en memoria)', (await b.eval('location.pathname')) === '/login');
    await b.type('#login-name', 'usera'); await b.type('#login-password', PASSWORD); await b.click('form button[type=submit]'); await b.wait(1200);
    await b.eval(`history.pushState({}, '', '/reportes/00000000-0000-0000-0000-000000000055'); dispatchEvent(new PopStateEvent('popstate'))`); await b.wait(1500);
    ok('abre /reportes/{uuid} (inserción autenticada): aviso de configuración, sin iframe ni tokens', (await b.eval('document.body.innerText')).includes('requiere configuración de acceso a Power BI') && (await b.eval(`document.querySelectorAll('iframe').length`)) === 0);
    ok('no hay peticiones a dominios externos ni al endpoint heredado de tokens', b.requests.every(u => u.startsWith(BASE) || /^(data:|about:)/.test(u)) && !b.requestLog.some(r => r.url.includes('/api/powerbi')));
    ok('sin errores de consola ni violaciones de CSP', b.consoleErrors.length === 0, b.consoleErrors.slice(0, 3).join(' | '));
    ok('la API se consume del mismo origen de Apache', b.requestLog.filter(r => r.url.includes('/api/')).every(r => r.url.startsWith(`${BASE}/api/`)));
  } finally { await b.close(); }
} catch (error) {
  results.push(['FAIL', `${section} · EXCEPCIÓN`, error.message]);
} finally {
  cleanup();
  docker(['network', 'rm', 'cervesur-apache-verify-pub'], { allowFail: true });
}
for (const [status, name, extra] of results) console.log(`${status}  ${name}${extra ? `  → ${extra}` : ''}`);
const failed = results.filter(r => r[0] === 'FAIL').length;
console.log(`\n${results.length - failed} PASS / ${failed} FAIL`);
process.exit(failed ? 1 : 0);
