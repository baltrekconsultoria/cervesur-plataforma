// ENSAYO LOCAL (Docker) de la instalación por túnel SSH. No se conecta a ningún servidor real.
//   node deploy/tunel-ssh/ensayo-docker.mjs
// Réplica de ensayo: Ubuntu 24.04 + Apache 2.4 + mod_php 8.3 + MySQL 8 locales, con un portal «Flutter» simulado en el puerto 80
// (cervesur.conf, .htaccess, módulos alias/php/rewrite, headers NO habilitado). socat emula el túnel SSH hacia 127.0.0.1:8088.
// Ejecuta los MISMOS scripts del paquete (instalar.sh, verificar.sh, revertir.sh) y comprueba aislamiento y reversión.
import { spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { existsSync, readdirSync, statSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { launch } from '../../frontend/e2e/cdp.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const REPO = join(here, '..', '..');
const IMAGE = 'cervesur-ensayo-ubuntu:local';
const NAME = 'cervesur-ensayo-tunel';
const HOST_PORT = Number(process.env.ENSAYO_PORT ?? 18095);
const BASE = `http://localhost:${HOST_PORT}`;
const results = [];
let section = '';
const ok = (name, cond, extra = '') => results.push([cond ? 'PASS' : 'FAIL', `${section} · ${name}`, cond ? '' : extra]);
const docker = (args, { allowFail = true } = {}) => {
  const r = spawnSync('docker', args, { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
  if (r.status !== 0 && !allowFail) throw new Error(`docker ${args.slice(0, 3).join(' ')} falló:\n${r.stderr || r.stdout}`);
  return { code: r.status, out: `${r.stdout ?? ''}${r.stderr ?? ''}`.trim() };
};
const dx = (cmd, env = {}) => docker(['exec', ...Object.entries(env).flatMap(([k, v]) => ['--env', `${k}=${v}`]), NAME, 'bash', '-c', cmd]);
const put = (path, text) => spawnSync('docker', ['exec', '-i', NAME, 'bash', '-c', `cat > ${path}`], { input: text });
const sleep = ms => new Promise(r => setTimeout(r, ms));
const cleanup = () => docker(['rm', '--force', '--volumes', NAME]);

async function fetchRaw(path, { method = 'GET', token, body } = {}) {
  const res = await fetch(BASE + path, { method, redirect: 'manual', headers: { ...(body ? { 'Content-Type': 'application/json' } : {}), ...(token ? { Authorization: `Bearer ${token}` } : {}) }, body: body ? JSON.stringify(body) : undefined });
  const raw = await res.text();
  let json = null; try { json = JSON.parse(raw); } catch { /* no JSON */ }
  return { status: res.status, headers: res.headers, raw, json };
}

try {
  cleanup();
  section = 'Paquete';
  // 1) Paquete real, generado por el mismo empaquetador que usará el operador.
  const pack = spawnSync(process.execPath, [join(here, 'empaquetar.mjs')], { cwd: REPO, encoding: 'utf8' });
  ok('empaquetar.mjs genera el paquete (compilación de servidor verificada con «localhost»)', pack.status === 0, pack.stderr || pack.stdout.slice(-300));
  const outDir = join(REPO, 'dist-paquete');
  const tgz = readdirSync(outDir).filter(f => f.endsWith('.tar.gz')).map(f => join(outDir, f)).sort((a, b) => statSync(b).mtimeMs - statSync(a).mtimeMs)[0];
  if (!tgz || !existsSync(tgz)) throw new Error('No hay paquete .tar.gz');

  docker(['build', '--quiet', '--tag', IMAGE, join(here, 'ensayo')], { allowFail: false });
  docker(['run', '--detach', '--name', NAME, '--publish', `127.0.0.1:${HOST_PORT}:18089`, IMAGE], { allowFail: false });
  for (let i = 0; ; i++) { if (dx('curl -fs http://127.0.0.1/ >/dev/null && mysqladmin ping --silent').code === 0) break; if (i > 60) throw new Error('El contenedor no arrancó'); await sleep(1000); }
  docker(['cp', tgz, `${NAME}:/root/paquete.tar.gz`], { allowFail: false });
  const extract = dx('cd /root && tar xzf paquete.tar.gz && ls -d cervesur-react-prueba-*');
  const PKG = `/root/${extract.out.split('\n').pop()}`;
  ok('el paquete se extrae y su SHA256SUMS es válido', extract.code === 0 && dx(`cd ${PKG} && sha256sum -c SHA256SUMS --quiet`).code === 0);
  ok('los scripts del paquete usan finales de línea LF', dx(`! grep -rlI $'\\r' ${PKG}/scripts ${PKG}/apache`).code === 0);

  const huella = () => dx(`source ${PKG}/scripts/lib.sh; huella_flutter`).out;
  const flutterGet = path => dx(`curl -s http://127.0.0.1${path}`).out;
  const estadoLimpio = () => dx('! test -e /var/www/cervesur-react-prueba && ! test -e /opt/cervesur-react-prueba && ! test -e /etc/apache2/cervesur-react-prueba && ! test -e /etc/apache2/sites-available/cervesur-react-prueba.conf && ! test -e /etc/apache2/sites-enabled/cervesur-react-prueba.conf && ! test -e /etc/apache2/mods-enabled/headers.load && [ -z "$(ss -ltnH \'sport = :8088\')" ] && [ -z "$(mysql -N -e "SHOW DATABASES LIKE \'cervesur_react_prueba\'")" ] && [ "$(mysql -N -e "SELECT COUNT(*) FROM mysql.user WHERE user=\'cervesur_react\'")" = 0 ]').code === 0;
  const baseline = huella();
  const flutterConf = dx('sha256sum /etc/apache2/sites-enabled/cervesur.conf /var/www/cervesur/.htaccess').out;

  section = 'Réplica del servidor';
  ok('Flutter responde en el puerto 80 y su .htaccess redirige las rutas profundas', flutterGet('/').includes('FLUTTER-SENTINEL') && flutterGet('/ruta/profunda').includes('FLUTTER-SENTINEL'));
  ok('módulos como en el servidor real: alias, php y rewrite activos; headers NO habilitado', dx('apache2ctl -M 2>/dev/null | grep -E "alias_module|php_module|rewrite_module" | wc -l').out === '3' && dx('! test -e /etc/apache2/mods-enabled/headers.load').code === 0);
  ok('estado inicial limpio (sin carpetas, sitio, base ni usuario de prueba)', estadoLimpio());

  section = 'Instalador · comprobaciones previas';
  const sim = dx(`bash ${PKG}/scripts/instalar.sh --simular`);
  ok('--simular supera todas las comprobaciones y termina sin modificar nada', sim.code === 0 && /Modo --simular: fin/.test(sim.out) && estadoLimpio() && dx('! test -e /var/backups/cervesur-react-prueba').code === 0, sim.out.slice(-400));
  // Flutter con una directiva Header inactiva: habilitar mod_headers la activaría → el instalador debe negarse ANTES de tocar nada.
  dx('printf "<IfModule mod_headers.c>\\nHeader set X-Flutter-Test 1\\n</IfModule>\\n" > /etc/apache2/conf-available/zz-flutter-header.conf && a2enconf zz-flutter-header >/dev/null && apache2ctl graceful');
  await sleep(1000);
  const bloqueo = dx(`bash ${PKG}/scripts/instalar.sh`, { CERVESUR_CONFIRMAR: 'SI' });
  ok('se niega a habilitar mod_headers si Flutter tiene directivas Header inactivas', bloqueo.code !== 0 && /Header/.test(bloqueo.out) && /No se modificó nada/.test(bloqueo.out));
  ok('tras negarse no queda ningún rastro (ni carpetas, ni respaldo, ni base)', estadoLimpio() && dx('! test -e /var/backups/cervesur-react-prueba').code === 0);
  dx('a2disconf zz-flutter-header >/dev/null; rm -f /etc/apache2/conf-available/zz-flutter-header.conf; apache2ctl graceful');
  await sleep(1000);
  ok('Flutter vuelve a su configuración original', huella() === baseline);

  section = 'Reversión automática ante un fallo a mitad de instalación';
  // Paquete deliberadamente roto (directiva inexistente en la configuración de Apache) con SHA256SUMS regenerado: pasa las
  // comprobaciones previas y falla DESPUÉS de crear carpetas, base de datos y usuario → debe revertir solo.
  dx(`rm -rf /root/roto && cp -r ${PKG} /root/roto && echo "DirectivaInexistente x" >> /root/roto/apache/react-common.conf && cd /root/roto && find . -type f ! -name SHA256SUMS -print0 | xargs -0 sha256sum > SHA256SUMS`);
  const roto = dx('bash /root/roto/scripts/instalar.sh', { CERVESUR_CONFIRMAR: 'SI' });
  ok('la instalación falla (configtest rechaza la configuración) y se revierte automáticamente', roto.code !== 0 && /se revierte automáticamente/.test(roto.out), roto.out.slice(-500));
  ok('tras el fallo no quedan carpetas, sitio, módulo headers, base ni usuario', estadoLimpio());
  ok('Flutter intacto y sirviendo tras el fallo (Apache nunca se recargó con la configuración rota)', huella() === baseline && flutterGet('/').includes('FLUTTER-SENTINEL'));
  ok('los respaldos se conservan', dx('test -d /var/backups/cervesur-react-prueba && ls /var/backups/cervesur-react-prueba/*.tgz >/dev/null').code === 0);
  dx('rm -rf /root/roto');

  section = 'Política de contraseñas de MySQL (ERROR 1819)';
  // La réplica impone la MISMA política que el servidor real (MySQL 8.0.46, validate_password MEDIUM).
  const politica = dx('mysql -N -e "SELECT @@validate_password.policy, @@validate_password.length, @@validate_password.mixed_case_count, @@validate_password.number_count, @@validate_password.special_char_count, @@validate_password.check_user_name"').out;
  ok('la réplica impone validate_password: MEDIUM, longitud 8, 1 mayúscula/minúscula, 1 número, 1 especial, check_user_name', /^MEDIUM\s+8\s+1\s+1\s+1\s+1$|^MEDIUM\s+8\s+1\s+1\s+1\s+ON$/.test(politica), politica);
  // Reproduce el defecto original: contraseña solo hexadecimal (openssl rand -hex 18) → MySQL la rechaza con ERROR 1819.
  const antigua = randomBytes(18).toString('hex');
  const rechazo = dx(`mysql -e "CREATE USER 'zz_prueba_1819'@'localhost' IDENTIFIED BY '${antigua}'"`);
  ok('una contraseña solo hexadecimal (la del código anterior) es rechazada con ERROR 1819', rechazo.code !== 0 && /1819/.test(rechazo.out), rechazo.out);
  dx("mysql -e \"DROP USER IF EXISTS 'zz_prueba_1819'@'localhost'\"");
  // Generador nuevo: 300 muestras, todas conformes con MEDIUM, únicas y con caracteres seguros para SQL, .env y PDO.
  const muestras = dx(`source ${PKG}/scripts/lib.sh; for i in $(seq 1 300); do generar_clave_mysql; echo; done`).out.split('\n');
  const cumple = c => c.length >= 8 && /[A-Z]/.test(c) && /[a-z]/.test(c) && /[0-9]/.test(c) && /[^A-Za-z0-9]/.test(c) && /^[A-Za-z0-9!]+$/.test(c);
  ok('generar_clave_mysql: 300 de 300 contraseñas cumplen MEDIUM, son únicas y solo usan caracteres seguros (letras, números y «!»)', muestras.length === 300 && muestras.every(cumple) && new Set(muestras).size === 300 && muestras.every(c => c.length === 52), `${muestras.length} muestras`);
  ok('clave_cumple_politica_medium rechaza hexadecimal puro, sin símbolo, corta y con caracteres peligrosos', ['abcdef0123456789abcdef', 'Aa1abcdefgh', 'Aa1!', "Aa1!x'y;--zzzz", 'Aa1!x=y#zzzzzz'].every(c => { put('/tmp/candidata.txt', `${c}\n`); return dx(`source ${PKG}/scripts/lib.sh; clave_cumple_politica_medium < /tmp/candidata.txt`).code !== 0; }));
  const generada = muestras[0];
  const acepta = dx(`mysql -e "CREATE USER 'zz_prueba_1819'@'localhost' IDENTIFIED BY '${generada}'; CREATE USER 'zz_prueba_1819'@'127.0.0.1' IDENTIFIED BY '${generada}'; DROP USER 'zz_prueba_1819'@'localhost', 'zz_prueba_1819'@'127.0.0.1'"`);
  ok('MySQL con política MEDIUM ACEPTA la contraseña generada (CREATE USER para localhost y 127.0.0.1)', acepta.code === 0, acepta.out);

  section = 'Instalación';
  const inst = dx(`set -o pipefail; bash ${PKG}/scripts/instalar.sh 2>&1 | tee /root/instalacion.log`, { CERVESUR_CONFIRMAR: 'SI' });
  ok('instalar.sh termina correctamente', inst.code === 0, inst.out.slice(-800));
  const verif = /(\d+) PASS \/ (\d+) FAIL/.exec(inst.out);
  ok('verificar.sh integrado: todas las comprobaciones pasan', Boolean(verif) && verif[2] === '0' && Number(verif[1]) >= 15, verif?.[0] ?? inst.out.slice(-400));
  const cuentas = Object.fromEntries([...inst.out.matchAll(/^CUENTA (\w+) ([0-9a-f]{20})$/gm)].map(m => [m[1], m[2]]));
  ok('se muestran las cuentas sintéticas con contraseñas aleatorias distintas', ['admin', 'usera', 'userb'].every(u => cuentas[u]) && new Set(Object.values(cuentas)).size === 3);
  ok('la contraseña de la base y el JWT no aparecen en la salida del instalador', !/DB_PASS|JWT_SECRET/.test(inst.out));
  // La comprobación se hace DENTRO del contenedor: el valor secreto nunca llega a este proceso ni a la consola.
  const sinFuga = dx('p=$(grep "^DB_PASS=" /opt/cervesur-react-prueba/backend/.env | cut -d= -f2-); j=$(grep "^JWT_SECRET=" /opt/cervesur-react-prueba/backend/.env | cut -d= -f2-); [ ${#p} -eq 52 ] && [ ${#j} -eq 64 ] && ! grep -rqsF -e "$p" -e "$j" /root/instalacion.log /var/log /var/backups /root/cervesur-react-prueba-* --exclude=mysql.err 2>/dev/null; echo $?').out;
  ok('la contraseña de MySQL (52 caracteres) y el JWT no aparecen en el registro del instalador, en /var/log ni en los respaldos', sinFuga === '0', sinFuga);
  ok('el .env del backend contiene la contraseña generada con el formato nuevo (Aa1!+48 hex) sin caracteres problemáticos', dx('grep -Eq "^DB_PASS=Aa1![0-9a-f]{48}$" /opt/cervesur-react-prueba/backend/.env').code === 0);

  section = 'Aislamiento respecto a Flutter y a la red';
  ok('Flutter: cervesur.conf y .htaccess byte a byte idénticos', dx('sha256sum /etc/apache2/sites-enabled/cervesur.conf /var/www/cervesur/.htaccess').out === flutterConf);
  ok('Flutter: huella completa (configuración, árbol de archivos y cabeceras HTTP) idéntica a la línea base', huella() === baseline, `${baseline}\n---\n${huella()}`);
  ok('Flutter sigue sirviendo en el puerto 80 (inicio y rutas profundas)', flutterGet('/').includes('FLUTTER-SENTINEL') && flutterGet('/ruta/profunda').includes('FLUTTER-SENTINEL'));
  ok('habilitar mod_headers no añadió cabeceras a las respuestas de Flutter', !/x-frame-options|content-security-policy/i.test(dx('curl -sI http://127.0.0.1/').out));
  const listen = dx('ss -ltnH "sport = :8088" | awk \'{print $4}\'').out;
  ok('el puerto 8088 escucha ÚNICAMENTE en 127.0.0.1', listen === '127.0.0.1:8088', listen);
  ok('el puerto 80 de Flutter no cambió', dx('ss -ltnH "sport = :80" | awk \'{print $4}\'').out === '0.0.0.0:80');
  const ip = docker(['inspect', '-f', '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}', NAME]).out;
  ok('se obtuvo la IP del contenedor en la red de Docker', /^\d+\.\d+\.\d+\.\d+$/.test(ip), ip);
  const desdeLaRed = docker(['run', '--rm', '--entrypoint', 'curl', IMAGE, '-sS', '-m', '5', '-o', '/dev/null', '-w', '%{http_code}', `http://${ip}:8088/`]);
  const control = docker(['run', '--rm', '--entrypoint', 'curl', IMAGE, '-sS', '-m', '5', '-o', '/dev/null', '-w', '%{http_code}', `http://${ip}/`]);
  ok('desde otra máquina de la red NO se puede llegar al puerto 8088 (conexión rechazada)', desdeLaRed.code !== 0 && !/^2/.test(desdeLaRed.out), desdeLaRed.out);
  ok('control: desde esa misma máquina de la red sí se llega a Flutter (puerto 80)', control.out === '200', control.out);
  ok('.env del backend de prueba: modo 640 root:www-data; la carpeta pública no es escribible por Apache', dx('stat -c "%a %U:%G" /opt/cervesur-react-prueba/backend/.env').out === '640 root:www-data' && dx('stat -c "%U" /var/www/cervesur-react-prueba/public').out === 'root');
  ok('no hay ningún .env dentro del DocumentRoot', dx('! find /var/www/cervesur-react-prueba -name ".env*" | grep -q .').code === 0);
  const grants = ['localhost', '127.0.0.1'].map(h => dx(`mysql -N -e "SHOW GRANTS FOR 'cervesur_react'@'${h}'"`).out);
  ok('el usuario MySQL (@localhost y @127.0.0.1) solo tiene SELECT, INSERT, UPDATE, DELETE sobre la base de prueba', grants.every(g => /GRANT SELECT, INSERT, UPDATE, DELETE ON `cervesur_react_prueba`\.\* TO/.test(g) && !/ALL PRIVILEGES|DROP|ALTER|GRANT OPTION/.test(g)), grants.join(' | '));
  ok('solo se creó una base nueva (cervesur_react_prueba); no se tocó ninguna otra', dx('mysql -N -e "SHOW DATABASES"').out.split('\n').filter(d => !['information_schema', 'mysql', 'performance_schema', 'sys'].includes(d)).join(',') === 'cervesur_react_prueba');
  ok('esquema de prueba con la migración UX-07C aplicada (grupo_id admite NULL; enlace de 1024)', dx('mysql -N -e "SELECT CONCAT(IS_NULLABLE, \':\', CHARACTER_MAXIMUM_LENGTH) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=\'cervesur_react_prueba\' AND TABLE_NAME=\'reporte\' AND COLUMN_NAME IN (\'grupo_id\',\'enlace\') ORDER BY COLUMN_NAME"').out === 'YES:1024\nYES:36');
  // open_basedir: sonda TEMPORAL solo del ensayo. Dentro del backend (donde PHP sí puede ejecutarse) intenta leer el backend de Flutter.
  const sondaPhp = '<?php echo json_encode(["obd" => ini_get("open_basedir"), "lee_flutter" => @file_get_contents("/var/www/cervesur/backend/api.php") !== false, "lee_propio" => @is_file("/opt/cervesur-react-prueba/backend/api.php")]);';
  put('/opt/cervesur-react-prueba/backend/sonda.php', sondaPhp);
  put('/etc/apache2/conf-available/zz-sonda.conf', 'AliasMatch "^/sonda\\.php$" "/opt/cervesur-react-prueba/backend/sonda.php"\n');
  dx('a2enconf zz-sonda >/dev/null && apache2ctl graceful');
  await sleep(1200);
  const sonda = await fetchRaw('/sonda.php');
  dx('a2disconf zz-sonda >/dev/null; rm -f /etc/apache2/conf-available/zz-sonda.conf /opt/cervesur-react-prueba/backend/sonda.php; apache2ctl graceful');
  await sleep(1000);
  ok('open_basedir: el PHP del sitio de prueba NO puede leer los archivos del backend de Flutter, pero sí el suyo', sonda.json?.lee_flutter === false && sonda.json?.lee_propio === true && /cervesur-react-prueba/.test(sonda.json?.obd ?? ''), sonda.raw.slice(0, 200));
  put('/var/www/cervesur-react-prueba/public/suelto.php', '<?php echo 1;');
  const suelto = await fetchRaw('/suelto.php');
  dx('rm -f /var/www/cervesur-react-prueba/public/suelto.php');
  ok('un .php suelto en el DocumentRoot no se ejecuta (open_basedir lo impide)', !/^1$/.test(suelto.raw.trim()) && suelto.status !== 200, `${suelto.status} ${suelto.raw.slice(0, 60)}`);

  section = 'Acceso por el túnel (http://localhost)';
  const index = await fetchRaw('/');
  ok('/ entrega la aplicación React', index.status === 200 && /<div id="root">/.test(index.raw));
  ok('recargar una ruta interna entrega index.html', (await fetchRaw('/admin/usuarios')).raw === index.raw);
  const ping = await fetchRaw('/api/ping');
  ok('/api/ping lo atiende PHP (JSON, no-store)', ping.json?.pong === true && /no-store/.test(ping.headers.get('cache-control') ?? ''));
  const cookie = ping.headers.get('set-cookie') ?? '';
  ok('la cookie de sesión tiene nombre propio, HttpOnly y SameSite=Strict (no usa PHPSESSID)', /^CERVESUR_REACT=/.test(cookie) && /httponly/i.test(cookie) && /samesite=strict/i.test(cookie) && !/PHPSESSID/.test(cookie), cookie);
  const bad = await fetchRaw('/api/login', { method: 'POST', body: { login: 'admin', password: 'incorrecta' } });
  ok('login con credenciales inválidas → JSON success:false', bad.json?.success === false);
  const good = await fetchRaw('/api/login', { method: 'POST', body: { login: 'admin', password: cuentas.admin } });
  const token = good.json?.token;
  ok('login del administrador sintético con la contraseña generada', good.json?.success === true && good.json?.user?.role === 'admin' && typeof token === 'string');
  ok('401 y 403 de la API llegan como JSON', (await fetchRaw('/api/user/profile')).status === 401 && (await fetchRaw('/api/users', { token: (await fetchRaw('/api/login', { method: 'POST', body: { login: 'usera', password: cuentas.usera } })).json?.token })).status === 403);
  ok('404 de la API llega como JSON', (await fetchRaw('/api/no-existe', { token })).json?.error === 'Not found');
  const legado = await fetchRaw('/api/powerbi/tokens/1', { token });
  ok('el endpoint heredado de tokens OAuth NO está expuesto: 403 (GET) y 403 (POST)', legado.status === 403 && (await fetchRaw('/api/powerbi/tokens/1', { method: 'POST', token, body: {} })).status === 403 && (await fetchRaw('/api/powerbi/tokens', { token })).status === 403);
  for (const ruta of ['/.env', '/.htaccess', '/build-info.json', '/api.php', '/backend/.env']) { const r = await fetchRaw(ruta); ok(`${ruta} no es accesible (${r.status})`, [403, 404].includes(r.status) && !/DB_PASS|JWT_SECRET|<\?php/.test(r.raw)); }
  const csp = index.headers.get('content-security-policy') ?? '';
  ok('CSP con frame-src https://app.powerbi.com y sin unsafe-inline/unsafe-eval; sin HSTS por HTTP', /frame-src https:\/\/app\.powerbi\.com(;|$)/.test(csp) && /frame-ancestors 'none'/.test(csp) && !/unsafe-/.test(csp) && !index.headers.get('strict-transport-security'));

  section = 'Navegador real a través del túnel (origen http://localhost)';
  const b = await launch({ port: 9512 });
  try {
    await b.goto(`${BASE}/`); await b.wait(900);
    const texto = await b.eval('document.body.innerText');
    ok('la aplicación NO muestra «Portal no disponible»: HTTP se admite solo en loopback', (await b.eval('location.pathname')) === '/login' && !/Portal no disponible/.test(texto) && !/Entorno QA/.test(texto), texto.slice(0, 160));
    await b.type('#login-name', 'admin'); await b.type('#login-password', cuentas.admin); await b.click('form button[type=submit]'); await b.wait(1500);
    ok('inicia sesión contra PHP y entra a /admin', (await b.eval('location.pathname')) === '/admin');
    await b.eval(`[...document.querySelectorAll('.nav-button')].find(a => a.textContent.includes('Usuarios')).click()`); await b.wait(1500);
    ok('/admin/usuarios lista los usuarios sintéticos de la base de prueba', (await b.eval(`document.querySelectorAll('tbody tr').length`)) >= 3 && (await b.eval('document.body.innerText')).includes('usera@example.invalid'));
    ok('todas las peticiones van al mismo origen; ninguna a dominios externos ni al endpoint heredado', b.requests.every(u => u.startsWith(BASE) || /^(data:|about:)/.test(u)) && !b.requestLog.some(r => r.url.includes('/api/powerbi')));
    ok('sin errores de consola ni violaciones de CSP', b.consoleErrors.length === 0, b.consoleErrors.slice(0, 3).join(' | '));
  } finally { await b.close(); }

  section = 'Reversión';
  const rev = dx(`bash ${PKG}/scripts/revertir.sh`, { CERVESUR_CONFIRMAR: 'SI' });
  ok('revertir.sh termina correctamente', rev.code === 0 && !/FALLO/.test(rev.out), rev.out.slice(-600));
  ok('no quedan carpetas, sitio, módulo headers (lo habilitó el instalador), puerto 8088, base ni usuario', estadoLimpio());
  ok('Flutter idéntico a la línea base tras revertir (configuración, archivos y cabeceras)', huella() === baseline && dx('sha256sum /etc/apache2/sites-enabled/cervesur.conf /var/www/cervesur/.htaccess').out === flutterConf);
  ok('Flutter sigue sirviendo y Apache sigue en marcha sin haberse reiniciado', flutterGet('/').includes('FLUTTER-SENTINEL') && dx('apache2ctl configtest 2>&1 | grep -c "Syntax OK"').out === '1');
  ok('los respaldos se conservan tras revertir', dx('ls /var/backups/cervesur-react-prueba/*.tgz /var/backups/cervesur-react-prueba/linea-base-flutter.txt >/dev/null').code === 0);
  ok('revertir dos veces no falla (idempotente)', dx(`bash ${PKG}/scripts/revertir.sh`, { CERVESUR_CONFIRMAR: 'SI' }).code === 0);
} catch (error) {
  results.push(['FAIL', `${section} · EXCEPCIÓN`, error.message]);
} finally {
  if (!process.env.ENSAYO_CONSERVAR) cleanup(); // ENSAYO_CONSERVAR=1 deja el contenedor para depurar
}
for (const [status, name, extra] of results) console.log(`${status}  ${name}${extra ? `  → ${extra}` : ''}`);
const failed = results.filter(r => r[0] === 'FAIL').length;
console.log(`\n${results.length - failed} PASS / ${failed} FAIL`);
process.exit(failed ? 1 : 0);
