// ENSAYO LOCAL (Docker) del entorno de COMPATIBILIDAD PRODUCTIVA (UX-07D.2C). No se conecta a ningún servidor real.
//   node deploy/tunel-ssh-compat/ensayo-docker.mjs
// Réplica de ensayo: Ubuntu 24.04 + Apache 2.4 + mod_php 8.3 + MySQL 8 (con validate_password MEDIUM) y TRES portales a la vez:
//   - Flutter simulado en el puerto 80 (cervesur.conf + .htaccess propios),
//   - React de prueba en 127.0.0.1:8088 (instalado con los scripts de UX-07D.1B, como en el servidor),
//   - React de compatibilidad en 127.0.0.1:8089 (los scripts nuevos).  socat emula los túneles SSH (18095→8088, 18096→8089).
// Demuestra que instalar, fallar y revertir 8089 NO altera 80 ni 8088, que las sesiones/cookies están aisladas y que el QA completo pasa.
import { spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { runCompatQa } from './qa-compat.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const REPO = join(here, '..', '..');
const IMAGE = 'cervesur-ensayo-ubuntu:local';
const NAME = 'cervesur-ensayo-compat';
const PORT88 = Number(process.env.ENSAYO_PORT_8088 ?? 18095);
const PORT89 = Number(process.env.ENSAYO_PORT_8089 ?? 18096);
const BASE88 = `http://localhost:${PORT88}`;
const BASE89 = `http://localhost:${PORT89}`;
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
const newest = prefix => readdirSync(join(REPO, 'dist-paquete')).filter(f => f.startsWith(prefix) && f.endsWith('.tar.gz')).map(f => join(REPO, 'dist-paquete', f)).sort((a, b) => statSync(b).mtimeMs - statSync(a).mtimeMs)[0];
const constants = file => Object.fromEntries([...readFileSync(file, 'utf8').matchAll(/^([A-Z_]+)=([^\n(]+)$/gm)].map(m => [m[1], m[2].replace(/^\$\{.*$/, '')]));

// Jar de cookies por HOST (no por puerto), como un navegador: localhost:18095 y localhost:18096 comparten el frasco.
class Jar {
  cookies = new Map();
  absorb(res) { for (const sc of res.headers.getSetCookie()) { const [pair, ...attrs] = sc.split(';'); const i = pair.indexOf('='); this.cookies.set(pair.slice(0, i).trim(), { value: pair.slice(i + 1).trim(), attrs: attrs.map(a => a.trim().toLowerCase()) }); } }
  header() { return [...this.cookies].map(([n, c]) => `${n}=${c.value}`).join('; '); }
}
async function viaJar(jar, base, path, { method = 'GET', body, token } = {}) {
  const res = await fetch(base + path, { method, headers: { ...(body ? { 'Content-Type': 'application/json' } : {}), ...(token ? { Authorization: `Bearer ${token}` } : {}), ...(jar.header() ? { Cookie: jar.header() } : {}) }, body: body ? JSON.stringify(body) : undefined });
  jar.absorb(res); const raw = await res.text(); let json = null; try { json = JSON.parse(raw); } catch { /* no JSON */ }
  return { status: res.status, json, raw, setCookie: res.headers.getSetCookie() };
}

try {
  cleanup();
  section = 'Aislamiento de nombres y recursos';
  // Los recursos del entorno nuevo no pueden coincidir ni solaparse con los del React de 8088 (comparación sobre los lib.sh reales).
  const old = constants(join(REPO, 'deploy', 'tunel-ssh', 'scripts', 'lib.sh')); const neu = constants(join(REPO, 'deploy', 'tunel-ssh-compat', 'scripts', 'lib.sh'));
  const keys = ['SITIO', 'PUERTO', 'DIR_FRONT', 'DIR_BACK', 'DIR_CONF', 'SITE_CONF', 'DIR_RESPALDO', 'DB_NOMBRE', 'DB_USUARIO'];
  ok('ningún nombre, puerto, ruta, base ni usuario del entorno nuevo coincide con los del React de 8088', keys.every(k => old[k] && neu[k] && old[k] !== neu[k]), keys.filter(k => old[k] === neu[k]).join(','));
  const overlap = (a, b) => `${a}/`.startsWith(`${b}/`) || `${b}/`.startsWith(`${a}/`);
  const paths = k => neu[k]; const others = ['/var/www/cervesur', old.DIR_FRONT, old.DIR_BACK, old.DIR_CONF, old.DIR_RESPALDO];
  ok('ninguna ruta del entorno nuevo contiene ni está contenida en la de Flutter ni en la del React de 8088', ['DIR_FRONT', 'DIR_BACK', 'DIR_CONF', 'DIR_VAR', 'DIR_RESPALDO'].every(k => others.every(o => !overlap(paths(k), o))));
  ok('puertos distintos (80, 8088, 8089) y cookie de sesión propia en el código de Apache', neu.PUERTO === '8089' && old.PUERTO === '8088' && /session\.name CERVESUR_REACT_COMPAT/.test(readFileSync(join(REPO, 'deploy', 'tunel-ssh-compat', 'apache', 'react-compat-common.conf'), 'utf8')) && /session\.name CERVESUR_REACT\b/.test(readFileSync(join(REPO, 'deploy', 'apache', 'react-common.conf'), 'utf8')));

  section = 'Paquetes';
  const pack = spawnSync(process.execPath, [join(here, 'empaquetar.mjs')], { cwd: REPO, encoding: 'utf8' });
  ok('empaquetar.mjs (compatibilidad) genera el paquete con la compilación de servidor verificada', pack.status === 0, pack.stderr || pack.stdout.slice(-300));
  const pack88 = spawnSync(process.execPath, [join(REPO, 'deploy', 'tunel-ssh', 'empaquetar.mjs'), '--sin-compilar'], { cwd: REPO, encoding: 'utf8' });
  ok('empaquetar.mjs (8088) genera el paquete del portal "existente"', pack88.status === 0, pack88.stderr || pack88.stdout.slice(-300));
  const tgz89 = newest('cervesur-react-compat-'); const tgz88 = newest('cervesur-react-prueba-');
  if (!tgz89 || !tgz88) throw new Error('Faltan los paquetes');

  docker(['build', '--quiet', '--tag', IMAGE, join(REPO, 'deploy', 'tunel-ssh', 'ensayo')], { allowFail: false });
  docker(['run', '--detach', '--name', NAME, '--publish', `127.0.0.1:${PORT88}:18089`, '--publish', `127.0.0.1:${PORT89}:18090`, IMAGE], { allowFail: false });
  for (let i = 0; ; i++) { if (dx('curl -fs http://127.0.0.1/ >/dev/null && mysqladmin ping --silent').code === 0) break; if (i > 60) throw new Error('El contenedor no arrancó'); await sleep(1000); }
  docker(['cp', tgz89, `${NAME}:/root/compat.tar.gz`], { allowFail: false }); docker(['cp', tgz88, `${NAME}:/root/prueba.tar.gz`], { allowFail: false });
  const PK89 = `/root/${dx('cd /root && tar xzf compat.tar.gz && ls -d cervesur-react-compat-*').out.split('\n').pop()}`;
  const PK88 = `/root/${dx('cd /root && tar xzf prueba.tar.gz && ls -d cervesur-react-prueba-*').out.split('\n').pop()}`;
  ok('ambos paquetes se extraen y su SHA256SUMS es válido', dx(`cd ${PK89} && sha256sum -c SHA256SUMS --quiet`).code === 0 && dx(`cd ${PK88} && sha256sum -c SHA256SUMS --quiet`).code === 0);
  ok('los scripts del paquete nuevo usan LF y no hay ningún .env dentro', dx(`! grep -rlI $'\\r' ${PK89}/scripts ${PK89}/apache && ! find ${PK89} -name ".env*" | grep -q .`).code === 0);

  // Réplica del servidor con MySQL en política MEDIUM; instalamos el React de 8088 con SU instalador (portal "existente").
  section = 'Portal existente: React 8088';
  const inst88 = dx(`bash ${PK88}/scripts/instalar.sh`, { CERVESUR_CONFIRMAR: 'SI' });
  ok('el instalador de 8088 deja el portal funcionando (como en el servidor)', inst88.code === 0 && /18 PASS \/ 0 FAIL|\d+ PASS \/ 0 FAIL/.test(inst88.out), inst88.out.slice(-400));
  const acc88 = Object.fromEntries([...inst88.out.matchAll(/^CUENTA (\w+) ([0-9a-f]{20})$/gm)].map(m => [m[1], m[2]]));
  const apachePid = () => dx('cat /var/run/apache2/apache2.pid').out;
  const headersOn = () => dx('test -e /etc/apache2/mods-enabled/headers.load').code === 0;

  const SNAP = `set -u
tree() { if [ -d "$1" ]; then (cd "$1" && find . -type f -printf '%p %s %T@ %m %u:%g\\n' | LC_ALL=C sort | sha256sum | cut -c1-64); else echo ausente; fi; }
http() { curl -sS -m 6 -o /dev/null -D - "$1" | tr -d '\\r' | awk 'NR==1{print;next} /^[A-Za-z-]+:/{print tolower(substr($0,1,index($0,":")-1))}' | LC_ALL=C sort; curl -sS -m 6 "$1" | sha256sum | cut -c1-64; }
echo "== configuracion"; sha256sum /etc/apache2/sites-available/cervesur.conf /var/www/cervesur/.htaccess /etc/apache2/sites-available/cervesur-react-prueba.conf /etc/apache2/cervesur-react-prueba/react-common.conf /opt/cervesur-react-prueba/backend/.env /etc/apache2/apache2.conf /etc/apache2/ports.conf
echo "== arboles"; tree /var/www/cervesur; tree /var/www/cervesur-react-prueba; tree /opt/cervesur-react-prueba
echo "== modulos"; apache2ctl -M 2>/dev/null | LC_ALL=C sort | sha256sum | cut -c1-64; ls /etc/apache2/mods-enabled | LC_ALL=C sort | sha256sum | cut -c1-64
echo "== http"; for u in http://127.0.0.1/ http://127.0.0.1/ruta/profunda http://127.0.0.1:8088/ http://127.0.0.1:8088/api/ping; do echo "$u"; http "$u"; done
echo "== mysql"; mysql -N -e 'SHOW DATABASES' | grep -vx cervesur_react_compat_qa | LC_ALL=C sort; mysql -N -e 'SELECT user, host FROM mysql.user' | grep -v '^cervesur_react_compat' | LC_ALL=C sort
for t in $(mysql -N -e 'SHOW TABLES' cervesur_react_prueba); do mysql -N -e "CHECKSUM TABLE $t" cervesur_react_prueba; done
echo "== puertos"; ss -ltnH | awk '{print $4}' | grep -v ':8089$' | LC_ALL=C sort -u
echo "== sitios"; ls /etc/apache2/sites-enabled | grep -v '^cervesur-react-compat.conf$' | LC_ALL=C sort
`;
  put('/root/snap.sh', SNAP);
  const snapshot = () => dx('bash /root/snap.sh').out;
  const estadoLimpio = () => dx('! test -e /var/www/cervesur-react-compat && ! test -e /opt/cervesur-react-compat && ! test -e /etc/apache2/cervesur-react-compat && ! test -e /etc/apache2/sites-available/cervesur-react-compat.conf && ! test -e /etc/apache2/sites-enabled/cervesur-react-compat.conf && ! test -e /var/lib/cervesur-react-compat && [ -z "$(ss -ltnH \'sport = :8089\')" ] && [ -z "$(mysql -N -e "SHOW DATABASES LIKE \'cervesur_react_compat_qa\'")" ] && [ "$(mysql -N -e "SELECT COUNT(*) FROM mysql.user WHERE user=\'cervesur_react_compat\'")" = 0 ]').code === 0;

  // mod_headers ausente: el instalador nuevo se niega (no lo habilita ni lo necesita tocar)
  section = 'Preflight del instalador nuevo';
  dx('a2dismod headers >/dev/null && apache2ctl graceful'); await sleep(1500);
  const sinHeaders = dx(`bash ${PK89}/scripts/instalar.sh --simular`);
  dx('a2enmod headers >/dev/null && apache2ctl graceful'); await sleep(1500);
  ok('sin mod_headers cargado el instalador se NIEGA (no lo habilita por su cuenta) y no modifica nada', sinHeaders.code !== 0 && /mod_headers YA cargado/.test(sinHeaders.out) && estadoLimpio() && headersOn());
  dx('phpdismod mbstring');
  const sinMb = dx(`bash ${PK89}/scripts/instalar.sh --simular`);
  dx('phpenmod mbstring');
  ok('sin la extensión PHP mbstring (la usa el backend al validar informes) el instalador se NIEGA y no modifica nada', sinMb.code !== 0 && /extensiones que usa el backend/.test(sinMb.out) && estadoLimpio());
  const S0 = snapshot(); const pid0 = apachePid();
  ok('línea base independiente tomada (Flutter, React 8088, módulos, bases y usuarios MySQL, puertos)', S0.length > 500 && /== mysql/.test(S0));

  const sim = dx(`bash ${PK89}/scripts/instalar.sh --simular`);
  ok('--simular supera todas las comprobaciones y termina sin modificar nada', sim.code === 0 && /Modo --simular: fin/.test(sim.out) && estadoLimpio() && snapshot() === S0, sim.out.slice(-500));
  dx(`rm -rf /root/p89-tamper && cp -r ${PK89} /root/p89-tamper && echo x >> /root/p89-tamper/public/index.html`);
  const tamper = dx('bash /root/p89-tamper/scripts/instalar.sh --simular');
  ok('un paquete alterado (SHA-256 no coincide) se rechaza antes de instalar', tamper.code !== 0 && /integridad del paquete/.test(tamper.out) && estadoLimpio() && snapshot() === S0);
  const collisions = [
    ['la base cervesur_react_compat_qa ya existe', "mysql -e 'CREATE DATABASE cervesur_react_compat_qa'", "mysql -e 'DROP DATABASE IF EXISTS cervesur_react_compat_qa'", /la base cervesur_react_compat_qa no existe/],
    ['el usuario MySQL cervesur_react_compat ya existe', `mysql -e "CREATE USER 'cervesur_react_compat'@'localhost' IDENTIFIED BY 'Aa1!${randomBytes(6).toString('hex')}'"`, "mysql -e \"DROP USER IF EXISTS 'cervesur_react_compat'@'localhost'\"", /el usuario MySQL cervesur_react_compat no existe/],
    ['el puerto 8089 está ocupado', 'setsid nohup socat TCP-LISTEN:8089,bind=127.0.0.1,fork,reuseaddr SYSTEM:true >/dev/null 2>&1 & sleep 1', "pkill -f 'TCP-LISTEN:808[9]' || true", /puerto 8089 libre/],
    ['la carpeta /var/www/cervesur-react-compat ya existe', 'mkdir /var/www/cervesur-react-compat', 'rmdir /var/www/cervesur-react-compat', /no existe \/var\/www\/cervesur-react-compat/],
    ['el sitio Apache cervesur-react-compat ya existe', 'echo "# ajeno" > /etc/apache2/sites-available/cervesur-react-compat.conf', 'rm -f /etc/apache2/sites-available/cervesur-react-compat.conf', /no existe \/etc\/apache2\/sites-available\/cervesur-react-compat\.conf/]
  ];
  for (const [label, arm, disarm, pattern] of collisions) {
    dx(arm); const r = dx(`bash ${PK89}/scripts/instalar.sh`, { CERVESUR_COMPAT_CONFIRMAR: 'SI' });
    const stillThere = label.includes('base') ? dx("mysql -N -e \"SHOW DATABASES LIKE 'cervesur_react_compat_qa'\"").out !== '' : true;
    dx(disarm); await sleep(300);
    ok(`colisión (${label}): el instalador ABORTA con «No se modificó nada» y el recurso ajeno no se toca`, r.code !== 0 && pattern.test(r.out) && /No se modificó nada/.test(r.out) && stillThere && dx('test -e /var/backups/cervesur-react-compat').code !== 0, r.out.slice(-300));
  }
  ok('tras las colisiones el servidor sigue idéntico a la línea base y sin restos', estadoLimpio() && snapshot() === S0);

  section = 'Reversión automática ante un fallo a mitad de instalación';
  dx(`rm -rf /root/p89-roto && cp -r ${PK89} /root/p89-roto && echo "DirectivaInexistente x" >> /root/p89-roto/apache/react-compat-common.conf && cd /root/p89-roto && find . -type f ! -name SHA256SUMS -print0 | xargs -0 sha256sum > SHA256SUMS`);
  const roto = dx('bash /root/p89-roto/scripts/instalar.sh', { CERVESUR_COMPAT_CONFIRMAR: 'SI' });
  ok('la instalación falla (configtest rechaza la configuración) y revierte automáticamente SOLO lo creado', roto.code !== 0 && /se revierte automáticamente/.test(roto.out), roto.out.slice(-500));
  ok('sin restos: carpetas, sesiones, sitio, base y usuario eliminados', estadoLimpio());
  ok('mod_headers SIGUE habilitado (el rollback de 8089 no lo deshabilita) y Apache conserva su proceso maestro (sin reinicio)', headersOn() && apachePid() === pid0);
  ok('Flutter y el React de 8088 idénticos a la línea base tras el fallo (archivos, configuración, cabeceras, respuestas, módulos, bases y usuarios)', snapshot() === S0);
  ok('los respaldos se conservan', dx('ls /var/backups/cervesur-react-compat/*.tgz >/dev/null').code === 0);

  section = 'Instalación';
  const inst = dx(`bash ${PK89}/scripts/instalar.sh`, { CERVESUR_COMPAT_CONFIRMAR: 'SI' });
  ok('instalar.sh termina correctamente', inst.code === 0, inst.out.slice(-900));
  const verif = /(\d+) PASS \/ (\d+) FAIL/.exec(inst.out);
  ok('verificar.sh integrado: todas las comprobaciones pasan', Boolean(verif) && verif[2] === '0' && Number(verif[1]) >= 22, verif?.[0] ?? inst.out.slice(-500));
  const accounts = Object.fromEntries([...inst.out.matchAll(/^CUENTA (\w+) ([0-9a-f]{20})$/gm)].map(m => [m[1], m[2]]));
  ok('se muestran cuatro cuentas sintéticas con contraseñas aleatorias distintas entre sí y distintas de las de 8088', ['syn001', 'syn005', 'syn006', 'syn007'].every(u => accounts[u]) && new Set(Object.values(accounts)).size === 4 && Object.values(accounts).every(p => !Object.values(acc88).includes(p)));
  const sinFuga = dx('p=$(grep "^DB_PASS=" /opt/cervesur-react-compat/backend/.env | cut -d= -f2-); j=$(grep "^JWT_SECRET=" /opt/cervesur-react-compat/backend/.env | cut -d= -f2-); p8=$(grep "^DB_PASS=" /opt/cervesur-react-prueba/backend/.env | cut -d= -f2-); j8=$(grep "^JWT_SECRET=" /opt/cervesur-react-prueba/backend/.env | cut -d= -f2-); [ ${#p} -eq 52 ] && [ ${#j} -eq 64 ] && [ "$p" != "$p8" ] && [ "$j" != "$j8" ]; echo $?').out;
  ok('secretos propios: contraseña MySQL (52 caracteres, política MEDIUM) y JWT distintos de los de 8088', sinFuga === '0', sinFuga);
  ok('ni la contraseña de MySQL ni el JWT aparecen en la salida del instalador, en /var/log ni en los respaldos', dx('p=$(grep "^DB_PASS=" /opt/cervesur-react-compat/backend/.env | cut -d= -f2-); j=$(grep "^JWT_SECRET=" /opt/cervesur-react-compat/backend/.env | cut -d= -f2-); ! grep -rqsF -e "$p" -e "$j" /var/log /var/backups --exclude=mysql.err 2>/dev/null; echo $?').out === '0' && !/DB_PASS|JWT_SECRET/.test(inst.out));
  ok('.env: modo 640 root:www-data; sesiones: 700 www-data; carpeta pública propiedad de root', dx('stat -c "%a %U:%G" /opt/cervesur-react-compat/backend/.env /var/lib/cervesur-react-compat/sessions; stat -c %U /var/www/cervesur-react-compat/public').out === '640 root:www-data\n700 www-data:www-data\nroot');
  ok('mod_headers sigue habilitado, Apache no se reinició (mismo proceso maestro) y los demás portales no cambiaron', headersOn() && apachePid() === pid0 && snapshot() === S0);

  section = 'Verificación cruzada de puertos 80, 8088 y 8089';
  const listen = port => dx(`ss -ltnH "sport = :${port}" | awk '{print $4}'`).out;
  ok('80 escucha en todas las interfaces (Flutter), 8088 y 8089 SOLO en 127.0.0.1', listen(80) === '0.0.0.0:80' && listen(8088) === '127.0.0.1:8088' && listen(8089) === '127.0.0.1:8089', `${listen(80)} ${listen(8088)} ${listen(8089)}`);
  const ip = docker(['inspect', '-f', '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}', NAME]).out;
  const net = port => docker(['run', '--rm', '--entrypoint', 'curl', IMAGE, '-sS', '-m', '5', '-o', '/dev/null', '-w', '%{http_code}', `http://${ip}:${port}/`]);
  ok('desde otra máquina de la red: 8088 y 8089 rechazan la conexión y el puerto 80 de Flutter responde', /^\d+\.\d+\.\d+\.\d+$/.test(ip) && net(8088).code !== 0 && net(8089).code !== 0 && net(80).out === '200', `${net(8088).out}/${net(8089).out}/${net(80).out}`);
  const flutterBody = dx('curl -s http://127.0.0.1/ruta/profunda').out;
  ok('Flutter sigue sirviendo su página y su .htaccess redirige las rutas profundas', flutterBody.includes('FLUTTER-SENTINEL'));
  const r88 = await fetch(`${BASE88}/`); const r89 = await fetch(`${BASE89}/`);
  const csp = r89.headers.get('content-security-policy') ?? '';
  ok('los dos React responden por sus túneles; el nuevo con CSP (frame-src https://app.powerbi.com), X-Frame-Options DENY y sin HSTS', r88.status === 200 && r89.status === 200 && /frame-src https:\/\/app\.powerbi\.com(;|$)/.test(csp) && !/unsafe-/.test(csp) && r89.headers.get('x-frame-options') === 'DENY' && !r89.headers.get('strict-transport-security'));
  ok('endpoint heredado de tokens OAuth: 403 en el sitio nuevo (GET y POST)', (await fetch(`${BASE89}/api/powerbi/tokens/1`)).status === 403 && (await fetch(`${BASE89}/api/powerbi/tokens/1`, { method: 'POST' })).status === 403);

  section = 'Sesiones y cookies aisladas entre 8088 (localhost:18095) y 8089 (localhost:18096)';
  const jar = new Jar();
  const p88 = await viaJar(jar, BASE88, '/api/ping'); const p89 = await viaJar(jar, BASE89, '/api/ping');
  const c88 = jar.cookies.get('CERVESUR_REACT'); const c89 = jar.cookies.get('CERVESUR_REACT_COMPAT');
  ok('cada sitio emite su propia cookie de sesión con nombre exclusivo (CERVESUR_REACT vs CERVESUR_REACT_COMPAT); ninguno usa PHPSESSID', Boolean(c88) && Boolean(c89) && c88.value !== c89.value && !jar.cookies.has('PHPSESSID') && p88.setCookie.every(c => !c.startsWith('CERVESUR_REACT_COMPAT')) && p89.setCookie.every(c => c.startsWith('CERVESUR_REACT_COMPAT=')));
  ok('ambas cookies: HttpOnly y SameSite=Strict, y sin Secure (túnel HTTP de localhost)', [c88, c89].every(c => c.attrs.includes('httponly') && c.attrs.includes('samesite=strict') && !c.attrs.includes('secure')));
  const v88 = c88.value; const v89 = c89.value;
  const l88 = await viaJar(jar, BASE88, '/api/login', { method: 'POST', body: { login: 'admin', password: acc88.admin } });
  const l89 = await viaJar(jar, BASE89, '/api/login', { method: 'POST', body: { login: 'syn001', password: accounts.syn001 } });
  ok('iniciar sesión en uno no sobrescribe la cookie del otro: ambos valores se conservan intactos en el mismo frasco', l88.json?.success === true && l89.json?.success === true && jar.cookies.get('CERVESUR_REACT').value === v88 && jar.cookies.get('CERVESUR_REACT_COMPAT').value === v89);
  const x1 = await viaJar(jar, BASE89, '/api/user/profile', { token: l88.json.token }); const x2 = await viaJar(jar, BASE88, '/api/user/profile', { token: l89.json.token });
  ok('el JWT de un portal no sirve en el otro (secretos y bases propios): 401 en ambos sentidos', x1.status === 401 && x2.status === 401);
  const out89 = await viaJar(jar, BASE89, '/api/logout', { method: 'POST', token: l89.json.token });
  const still88 = await viaJar(jar, BASE88, '/api/user/profile', { token: l88.json.token });
  ok('cerrar sesión en 8089 (sube su session_version) NO invalida la sesión de 8088', out89.status === 200 && still88.status === 200 && (await viaJar(jar, BASE89, '/api/user/profile', { token: l89.json.token })).status === 401);
  const files89 = dx('ls /var/lib/cervesur-react-compat/sessions').out; const filesOld = dx('ls /var/lib/php/sessions /tmp 2>/dev/null').out;
  ok('las sesiones PHP de 8089 viven en su directorio exclusivo y las de 8088 en el del sistema; ninguna se comparte', files89.includes(`sess_${v89}`) && !files89.includes(`sess_${v88}`) && filesOld.includes(`sess_${v88}`) && !filesOld.includes(`sess_${v89}`));
  // Sonda TEMPORAL solo del ensayo: se inyecta un AliasMatch dentro del VirtualHost de 8089 y después se restaura el archivo original.
  const inject = String.raw`cp /etc/apache2/sites-available/cervesur-react-compat.conf /root/site-compat.orig
awk '{print} /Include .*react-compat-common.conf/ {print "    AliasMatch \"^/sonda\\.php$\" \"/opt/cervesur-react-compat/backend/sonda.php\""}' /root/site-compat.orig > /etc/apache2/sites-available/cervesur-react-compat.conf
apache2ctl graceful
`;
  put('/opt/cervesur-react-compat/backend/sonda.php', '<?php echo json_encode(["name" => session_name(), "path" => session_save_path(), "obd" => ini_get("open_basedir"), "secure" => ini_get("session.cookie_secure"), "httponly" => ini_get("session.cookie_httponly"), "samesite" => ini_get("session.cookie_samesite"), "lee_flutter" => @file_get_contents("/var/www/cervesur/backend/api.php") !== false, "lee_8088" => @file_get_contents("/opt/cervesur-react-prueba/backend/.env") !== false, "lee_propio" => @is_file("/opt/cervesur-react-compat/backend/api.php")]);');
  put('/root/inject.sh', inject); dx('bash /root/inject.sh'); await sleep(1500);
  let sondaRes = await fetch(`${BASE89}/sonda.php`);
  for (let i = 0; i < 15 && sondaRes.status === 404; i++) { await sleep(1000); sondaRes = await fetch(`${BASE89}/sonda.php`); } // la recarga suave puede tardar unos segundos
  const sondaRaw = await sondaRes.text(); let sonda = {};
  try { sonda = JSON.parse(sondaRaw); } catch { sonda = { raw: sondaRaw.slice(0, 80), status: sondaRes.status, conf: dx('grep -n Alias /etc/apache2/sites-available/cervesur-react-compat.conf; ls -l /opt/cervesur-react-compat/backend/sonda.php 2>&1').out }; }
  dx('cp /root/site-compat.orig /etc/apache2/sites-available/cervesur-react-compat.conf && rm -f /opt/cervesur-react-compat/backend/sonda.php && apache2ctl graceful'); await sleep(3000);
  ok('configuración efectiva de PHP en 8089: cookie y sesiones propias (php_admin), Secure off, HttpOnly, SameSite=Strict', sonda.name === 'CERVESUR_REACT_COMPAT' && sonda.path === '/var/lib/cervesur-react-compat/sessions' && sonda.httponly === '1' && String(sonda.samesite).toLowerCase() === 'strict' && ['0', ''].includes(String(sonda.secure)), JSON.stringify(sonda));
  ok('open_basedir: el PHP de 8089 NO puede leer el backend de Flutter ni el .env del React de 8088, pero sí el suyo', sonda.lee_flutter === false && sonda.lee_8088 === false && sonda.lee_propio === true && !String(sonda.obd).includes('/var/lib/php'), JSON.stringify(sonda));

  // Los inicios de sesión de la prueba de cookies cambian last_login en la base de 8088 (no es obra de 8089): se acepta solo esa diferencia.
  const S1b = snapshot(); const diff = S0.split('\n').filter((l, i) => l !== S1b.split('\n')[i]);
  ok('tras instalar y usar ambos portales, lo único que difiere de la línea base es la tabla user de la base de 8088 (last_login de la prueba de cookies)', diff.every(l => /cervesur_react_prueba\.user\b/.test(l)) && diff.length <= 2, diff.join(' | '));

  await runCompatQa({
    ok, setSection: s => { section = s; }, base: BASE89, accounts,
    sql: async query => { put('/tmp/q.sql', query); const r = dx('mysql --default-character-set=utf8mb4 -N -B cervesur_react_compat_qa < /tmp/q.sql'); if (r.code !== 0) throw new Error(`SQL falló: ${query}\n${r.out}`); return r.out === '' ? [] : r.out.split('\n'); }
  });

  section = 'Política de contraseñas de MySQL (ERROR 1819)';
  const politica = dx('mysql -N -e "SELECT @@validate_password.policy, @@validate_password.length, @@validate_password.mixed_case_count, @@validate_password.number_count, @@validate_password.special_char_count, @@validate_password.check_user_name"').out;
  ok('la réplica impone validate_password MEDIUM como el servidor real', /^MEDIUM\s+8\s+1\s+1\s+1\s+(1|ON)$/.test(politica), politica);
  const hex = randomBytes(18).toString('hex'); const rechazo = dx(`mysql -e "CREATE USER 'zz_prueba_1819'@'localhost' IDENTIFIED BY '${hex}'"`);
  ok('una contraseña solo hexadecimal se rechaza con ERROR 1819 (el defecto original)', rechazo.code !== 0 && /1819/.test(rechazo.out));
  dx("mysql -e \"DROP USER IF EXISTS 'zz_prueba_1819'@'localhost'\"");
  const muestras = dx(`source ${PK89}/scripts/lib.sh; for i in $(seq 1 300); do generar_clave_mysql; echo; done`).out.split('\n');
  const cumple = c => c.length === 52 && /[A-Z]/.test(c) && /[a-z]/.test(c) && /[0-9]/.test(c) && /[^A-Za-z0-9]/.test(c) && /^[A-Za-z0-9!]+$/.test(c);
  ok('generar_clave_mysql: 300 de 300 contraseñas cumplen MEDIUM, son únicas y solo usan caracteres seguros', muestras.length === 300 && muestras.every(cumple) && new Set(muestras).size === 300);
  ok('MySQL con política MEDIUM acepta la contraseña generada (localhost y 127.0.0.1)', dx(`mysql -e "CREATE USER 'zz_prueba_1819'@'localhost' IDENTIFIED BY '${muestras[0]}'; CREATE USER 'zz_prueba_1819'@'127.0.0.1' IDENTIFIED BY '${muestras[0]}'; DROP USER 'zz_prueba_1819'@'localhost', 'zz_prueba_1819'@'127.0.0.1'"`).code === 0);

  section = 'Reversión exclusiva del entorno nuevo';
  const S1 = snapshot(); const pid1 = apachePid();
  const rev = dx(`bash ${PK89}/scripts/revertir.sh`, { CERVESUR_COMPAT_CONFIRMAR: 'SI' });
  ok('revertir.sh termina correctamente y sin avisos de fallo', rev.code === 0 && !/FALLO/.test(rev.out), rev.out.slice(-600));
  ok('no quedan carpetas, sesiones, sitio, puerto 8089, base ni usuario del entorno nuevo', estadoLimpio());
  ok('mod_headers SIGUE habilitado tras revertir 8089 (lo necesita el React de 8088) y Apache no se reinició', headersOn() && apachePid() === pid1);
  ok('Flutter y el React de 8088 idénticos a justo antes de revertir: archivos, configuración, cabeceras, respuestas, módulos, bases y usuarios', snapshot() === S1);
  const l88b = await viaJar(new Jar(), BASE88, '/api/login', { method: 'POST', body: { login: 'admin', password: acc88.admin } });
  ok('el React de 8088 sigue funcionando (inicio de sesión) y Flutter sigue sirviendo', l88b.json?.success === true && dx('curl -s http://127.0.0.1/').out.includes('FLUTTER-SENTINEL'));
  ok('los respaldos se conservan y revertir dos veces no falla', dx('ls /var/backups/cervesur-react-compat/linea-base-portales.txt >/dev/null').code === 0 && dx(`bash ${PK89}/scripts/revertir.sh`, { CERVESUR_COMPAT_CONFIRMAR: 'SI' }).code === 0);
  const rev88 = dx(`bash ${PK88}/scripts/revertir.sh`, { CERVESUR_CONFIRMAR: 'SI' });
  ok('el instalador/rollback de 8088 sigue funcionando con el entorno nuevo ya revertido', rev88.code === 0 && !/FALLO/.test(rev88.out), rev88.out.slice(-300));
} catch (error) {
  results.push(['FAIL', `${section} · EXCEPCIÓN`, error.stack ?? error.message]);
} finally {
  if (!process.env.ENSAYO_CONSERVAR) cleanup(); // ENSAYO_CONSERVAR=1 deja el contenedor para depurar
}
for (const [status, name, extra] of results) console.log(`${status}  ${name}${extra ? `  → ${extra}` : ''}`);
const failed = results.filter(r => r[0] === 'FAIL').length;
console.log(`\n${results.length - failed} PASS / ${failed} FAIL`);
process.exit(failed ? 1 : 0);
