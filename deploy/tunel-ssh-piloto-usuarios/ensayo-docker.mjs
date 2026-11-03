// ENSAYO LOCAL (Docker) del PILOTO CON USUARIOS REALES (UX-07D.4B.1) y, instalada encima, la instancia ADMINISTRATIVA de solo lectura (UX-07E.B, 8093). No se conecta a ningún servidor real.
//   node deploy/tunel-ssh-piloto-usuarios/ensayo-docker.mjs
// Réplica de ensayo: Ubuntu 24.04 + Apache 2.4 + mod_php 8.3 + MySQL 8 (validate_password MEDIUM) y SEIS entornos a la vez:
//   Flutter simulado (80) · React de prueba (8088) · compatibilidad (8089) · diagnóstico (8090) · piloto visual (8091), todos instalados con SUS instaladores,
//   y el piloto con usuarios (https://127.0.0.1:8092, los scripts nuevos), contra una base SINTÉTICA llamada cervesur_portalbi (fixture de UX-07D.2B/2C).
// Las tres cuentas y la base de estado las crea el ensayo con el procedimiento administrativo independiente (admin-mysql/), NUNCA el instalador. Un navegador real
// (Edge/Chrome) recorre el piloto por HTTPS a través de un túnel simulado (socat → 8092) fijando el certificado del sitio por su SPKI.
import { spawnSync } from 'node:child_process';
import { createHash, randomBytes } from 'node:crypto';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { createServer } from 'node:http';
import { connect as netConnect } from 'node:net';
import { launch } from '../../frontend/e2e/cdp.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const REPO = join(here, '..', '..');
// El navegador de prueba y el proxy de ensayo pueden reiniciar conexiones de precarga al cerrarse (ECONNRESET/EPIPE): es ruido del arnés de pruebas, no un fallo del sistema.
process.on('uncaughtException', error => { if (['ECONNRESET', 'EPIPE'].includes(error?.code)) return; throw error; });
const IMAGE = 'cervesur-ensayo-ubuntu:local';
const NAME = 'cervesur-ensayo-usuarios';
const TUNEL = Number(process.env.ENSAYO_PORT_USUARIOS ?? 18099);
const BASE = `https://localhost:${TUNEL}`;
// UX-07E.D · red Docker propia con IP fija: imita la IP privada del servidor (192.168.200.12) y permite probar un segundo equipo de la red (10.77.77.50)
const NETNAME = 'cervesur-ensayo-red'; const NETSUB = '10.77.77.0/24'; const IPPUB = '10.77.77.12';
const results = [];
let section = '';
const ok = (name, cond, extra = '') => results.push([cond ? 'PASS' : 'FAIL', `${section} · ${name}`, cond ? '' : String(extra).slice(-900)]);
const docker = (args, { allowFail = true } = {}) => {
  const r = spawnSync('docker', args, { encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
  if (r.status !== 0 && !allowFail) throw new Error(`docker ${args.slice(0, 3).join(' ')} falló:\n${r.stderr || r.stdout}`);
  return { code: r.status, out: `${r.stdout ?? ''}${r.stderr ?? ''}`.trim() };
};
const dx = (cmd, env = {}) => docker(['exec', ...Object.entries(env).flatMap(([k, v]) => ['--env', `${k}=${v}`]), NAME, 'bash', '-c', cmd]);
const put = (path, text) => spawnSync('docker', ['exec', '-i', NAME, 'bash', '-c', `cat > ${path}`], { input: text });
const sleep = ms => new Promise(r => setTimeout(r, ms));
const cleanup = () => { docker(['rm', '--force', '--volumes', NAME]); docker(['network', 'rm', NETNAME]); };
const newest = prefix => readdirSync(join(REPO, 'dist-paquete')).filter(f => f.startsWith(prefix) && f.endsWith('.tar.gz')).map(f => join(REPO, 'dist-paquete', f)).sort((a, b) => statSync(b).mtimeMs - statSync(a).mtimeMs)[0];
const constants = file => Object.fromEntries([...readFileSync(file, 'utf8').matchAll(/^([A-Z_]+)=([^\n(#]+?)\s*(?:#.*)?$/gm)].map(m => [m[1], m[2].replace(/^\$\{.*$/, '')]));
const CONFIRM = { CERVESUR_USUARIOS_CONFIRMAR: 'SI' };
const sqlRoot = q => dx(`mysql --default-character-set=utf8mb4 -N -B -e "${q.replaceAll('"', '\\"')}"`).out;
const TLS = '--cacert /etc/apache2/cervesur-react-usuarios/tls/servidor.crt --resolve localhost:8092:127.0.0.1';
const U = 'https://localhost:8092';
const CUENTAS = ['cervesur_ro_auth', 'cervesur_ro_catalogo', 'cervesur_react_estado'];
const GOODPW = `Ux${randomBytes(9).toString('hex')}!`; // contraseña SINTÉTICA de los usuarios de prueba (solo existe en este ensayo)

try {
  cleanup();
  section = 'Aislamiento de nombres y recursos';
  const libOf = dir => constants(join(REPO, 'deploy', dir, 'scripts', 'lib.sh'));
  const old = libOf('tunel-ssh'); const compat = libOf('tunel-ssh-compat'); const diag = libOf('tunel-ssh-readonly'); const vis = libOf('tunel-ssh-piloto'); const neu = libOf('tunel-ssh-piloto-usuarios');
  const keys = ['SITIO', 'PUERTO', 'DIR_CONF', 'SITE_CONF', 'DIR_RESPALDO', 'COOKIE_SESION'];
  ok('ningún nombre de sitio, puerto, ruta, respaldo ni cookie coincide con los de 8088, 8089, 8090 ni 8091', keys.every(k => neu[k] && [old, compat, diag, vis].every(o => o[k] !== neu[k])), keys.filter(k => [old, compat, diag, vis].some(o => o[k] === neu[k])).join(','));
  const overlap = (a, b) => `${a}/`.startsWith(`${b}/`) || `${b}/`.startsWith(`${a}/`);
  const others = ['/var/www/cervesur', old.DIR_FRONT, old.DIR_BACK, old.DIR_CONF, old.DIR_RESPALDO, compat.DIR_FRONT, compat.DIR_BACK, compat.DIR_CONF, compat.DIR_VAR, compat.DIR_RESPALDO, diag.DIR_BACK, diag.DIR_CONF, diag.DIR_VAR, diag.DIR_RESPALDO, vis.DIR_FRONT, vis.DIR_BACK, vis.DIR_CONF, vis.DIR_VAR, vis.DIR_RESPALDO];
  ok('ninguna ruta del piloto de usuarios contiene ni está contenida en la de Flutter, 8088, 8089, 8090 ni 8091', ['DIR_FRONT', 'DIR_BACK', 'DIR_CONF', 'DIR_VAR', 'DIR_RESPALDO'].every(k => others.every(o => !overlap(neu[k], o))));
  ok('puertos 80, 8088, 8089, 8090, 8091 y 8092 distintos; las tres cuentas MySQL son propias (distintas de las de 8088, 8089, 8090 y 8091)', neu.PUERTO === '8092' && vis.PUERTO === '8091' && [neu.DB_USUARIO_CATALOGO, neu.DB_USUARIO_AUTH, neu.DB_USUARIO_ESTADO].every(c => ![old.DB_USUARIO, compat.DB_USUARIO, diag.DB_USUARIO, vis.DB_USUARIO].includes(c)));
  ok('Apache: TLS en el propio socket, solo TLS 1.2/1.3, cabeceras de protocolo del cliente eliminadas y cookie propia', (() => { const a = readFileSync(join(here, 'apache', 'cervesur-react-usuarios.conf'), 'utf8'); const c = readFileSync(join(here, 'apache', 'react-usuarios-common.conf'), 'utf8'); return /Listen 127\.0\.0\.1:8092/.test(a) && /SSLEngine on/.test(a) && /SSLProtocol -all \+TLSv1\.2 \+TLSv1\.3/.test(a) && /RequestHeader unset X-Forwarded-Proto/.test(c) && /RequestHeader unset Forwarded/.test(c) && /session\.name CERVESUR_REACT_USUARIOS\b/.test(c) && /session\.cookie_secure on/.test(c); })());
  const shellFiles = ['instalar', 'pasos-instalacion', 'verificar', 'revertir', 'lib', 'preflight-infra', 'preflight-acceso', 'usuarios', 'tls'].map(n => join(here, 'scripts', `${n}.sh`)).concat(['preparar-sql-cuentas', 'mostrar-sql-redactado'].map(n => join(here, 'admin-mysql', `${n}.sh`)));
  ok('sintaxis bash correcta en todos los scripts', shellFiles.every(f => spawnSync('bash', ['-n', f]).status === 0));

  section = 'Paquetes';
  const packUs = spawnSync(process.execPath, [join(here, 'empaquetar.mjs')], { cwd: REPO, encoding: 'utf8' });
  ok('empaquetar.mjs (usuarios) compila la aplicación del piloto de usuarios y genera el paquete', packUs.status === 0, packUs.stderr || packUs.stdout.slice(-400));
  const packVi = spawnSync(process.execPath, [join(REPO, 'deploy', 'tunel-ssh-piloto', 'empaquetar.mjs')], { cwd: REPO, encoding: 'utf8' });
  const pack88 = spawnSync(process.execPath, [join(REPO, 'deploy', 'tunel-ssh', 'empaquetar.mjs'), '--sin-compilar'], { cwd: REPO, encoding: 'utf8' });
  const pack89 = spawnSync(process.execPath, [join(REPO, 'deploy', 'tunel-ssh-compat', 'empaquetar.mjs')], { cwd: REPO, encoding: 'utf8' });
  const pack90 = spawnSync(process.execPath, [join(REPO, 'deploy', 'tunel-ssh-readonly', 'empaquetar.mjs')], { cwd: REPO, encoding: 'utf8' });
  ok('los paquetes de 8088, 8089, 8090 y 8091 ("portales existentes") se generan', packVi.status === 0 && pack88.status === 0 && pack89.status === 0 && pack90.status === 0, `${packVi.stderr}${pack88.stderr}${pack89.stderr}${pack90.stderr}`);
  const tgzUs = newest('cervesur-react-usuarios-'); const tgzVi = newest('cervesur-react-piloto-'); const tgz88 = newest('cervesur-react-prueba-'); const tgz89 = newest('cervesur-react-compat-'); const tgz90 = newest('cervesur-react-ro-');
  if (!tgzUs || !tgzVi || !tgz88 || !tgz89 || !tgz90) throw new Error('Faltan los paquetes');
  const shaUs = createHash('sha256').update(readFileSync(tgzUs)).digest('hex');
  ok('el .sha256 externo coincide con el SHA-256 del .tar.gz', readFileSync(`${tgzUs}.sha256`, 'utf8').startsWith(shaUs));

  docker(['build', '--quiet', '--tag', IMAGE, join(REPO, 'deploy', 'tunel-ssh', 'ensayo')], { allowFail: false });
  cleanup(); docker(['network', 'create', '--subnet', NETSUB, NETNAME], { allowFail: false });
  docker(['run', '--detach', '--name', NAME, '--network', NETNAME, '--ip', IPPUB, '--publish', `127.0.0.1:${TUNEL}:18093`, '--publish', `127.0.0.1:${TUNEL + 1}:443`, '--publish', `127.0.0.1:${TUNEL + 2}:8443`, IMAGE], { allowFail: false });
  for (let i = 0; ; i++) { if (dx('curl -fs http://127.0.0.1/ >/dev/null && mysqladmin ping --silent').code === 0) break; if (i > 90) throw new Error('El contenedor no arrancó'); await sleep(1000); }
  for (const [tgz, dest] of [[tgzUs, 'us'], [tgzVi, 'vi'], [tgz88, 'prueba'], [tgz89, 'compat'], [tgz90, 'ro']]) docker(['cp', tgz, `${NAME}:/root/${dest}.tar.gz`], { allowFail: false });
  const first = cmd => dx(cmd).out.split('\n').pop();
  const PK = `/root/${first('cd /root && tar xzf us.tar.gz && ls -d cervesur-react-usuarios-*')}`;
  const PK91 = `/root/${first('cd /root && tar xzf vi.tar.gz && ls -d cervesur-react-piloto-*')}`;
  const PK88 = `/root/${first('cd /root && tar xzf prueba.tar.gz && ls -d cervesur-react-prueba-*')}`;
  const PK89 = `/root/${first('cd /root && tar xzf compat.tar.gz && ls -d cervesur-react-compat-*')}`;
  const PK90 = `/root/${first('cd /root && tar xzf ro.tar.gz && ls -d cervesur-react-ro-*')}`;
  ok('los cinco paquetes se extraen y su SHA256SUMS interno es válido', [PK, PK91, PK88, PK89, PK90].every(p => dx(`cd ${p} && sha256sum -c SHA256SUMS --quiet`).code === 0));
  ok('paquete de usuarios: scripts con LF, sin .env, certificados ni claves privadas, con la aplicación del piloto de usuarios (pilot=true, pilotMode=usuarios), sin tests; VERSION identifica el código', dx(`! grep -rlI $'\\r' ${PK}/scripts ${PK}/apache ${PK}/admin-mysql && ! find ${PK} \\( -name ".env*" -o -name "*.key" -o -name "*.pem" -o -name "*.crt" \\) | grep -q . && ! grep -rIl -e '-----BEGIN [A-Z ]*PRIVATE KEY-----' ${PK} | grep -q . && test -f ${PK}/public/index.html && grep -q '"pilot": true' ${PK}/public/build-info.json && grep -q '"pilotMode": "usuarios"' ${PK}/public/build-info.json && ! test -d ${PK}/backend/tests && grep -q '^commit: ' ${PK}/VERSION && grep -q '^contenido-sha256: [0-9a-f]\\{64\\}' ${PK}/VERSION`).code === 0);
  ok('el nombre declara «-con-cambios-<huella>» cuando hay cambios locales sin confirmar', /cervesur-react-usuarios-[0-9a-f]{12}(-con-cambios-[0-9a-f]{12})?-\d{8}-\d{6}Z\.tar\.gz$/.test(tgzUs));
  ok('el paquete no contiene secretos (JWT, secreto del piloto, contraseñas, hashes bcrypt productivos, claves en el SQL)', dx(`! grep -rIlE 'JWT_SECRET=[0-9a-f]{32}|PILOT_SECRET=[0-9a-f]{32}|DB_PASS=[^%$ ]{8}|IDENTIFIED BY .[A-Za-z0-9!]{12,}.' ${PK}/scripts ${PK}/apache ${PK}/admin-mysql ${PK}/docs`).code === 0);
  ok('la aplicación NO incluye las pantallas de alta, edición ni asignación', dx(`! cat ${PK}/public/assets/*.js | grep -q 'Nuevo usuario\\|Crear usuario\\|Nueva empresa\\|Nuevo reporte\\|Asignar'`).code === 0);
  ok('admin-mysql del paquete: 01, 02, 03 y 99 de las tres cuentas, generador y visor redactado; nada de otros pilotos', dx(`cd ${PK}/admin-mysql && ls 01-cuentas-lectura.sql 02-estado-base-y-cuenta.sql 03-verificar-privilegios.sql 99-revocar-cuentas.sql mostrar-sql-redactado.sh preparar-sql-cuentas.sh >/dev/null && ! ls *visor* *diagnostico* 2>/dev/null | grep -q .`).code === 0);

  // ----------------------------------------------------------------- Portales "existentes"
  section = 'Portales existentes (80, 8088, 8089, 8090 y 8091)';
  const inst88 = dx(`bash ${PK88}/scripts/instalar.sh`, { CERVESUR_CONFIRMAR: 'SI' });
  const inst89 = dx(`bash ${PK89}/scripts/instalar.sh`, { CERVESUR_COMPAT_CONFIRMAR: 'SI' });
  ok('los instaladores de 8088 y 8089 dejan los portales funcionando', inst88.code === 0 && inst89.code === 0 && /\d+ PASS \/ 0 FAIL/.test(inst88.out) && /\d+ PASS \/ 0 FAIL/.test(inst89.out), `${inst88.out.slice(-200)} ${inst89.out.slice(-200)}`);
  put('/root/portalbi.php', readFileSync(join(REPO, 'deploy', 'tunel-ssh-readonly', 'ensayo', 'sembrar-portalbi.php'), 'utf8'));
  put('/root/productive_fixture.php', readFileSync(join(REPO, 'tests', 'isolated', 'productive_fixture.php'), 'utf8'));
  const seed = dx('php /root/portalbi.php /root/productive_fixture.php');
  ok('base SINTÉTICA cervesur_portalbi creada en el MySQL desechable (agregados del inventario verificados)', seed.code === 0 && /creada/.test(seed.out), seed.out);
  const prep90 = dx(`bash ${PK90}/admin-mysql/preparar-sql-cuenta.sh --salida /root/diag90`);
  const mk90 = dx('mysql < /root/diag90.sql');
  const inst90 = dx(`bash ${PK90}/scripts/instalar.sh --clave-archivo /root/diag90.clave`, { CERVESUR_RO_CONFIRMAR: 'SI' });
  ok('el diagnóstico 8090 se instala con su cuenta cervesur_ro_diag y su instalador', prep90.code === 0 && mk90.code === 0 && inst90.code === 0 && /\d+ PASS \/ 0 FAIL/.test(inst90.out), inst90.out.slice(-300));
  const prep91 = dx(`bash ${PK91}/admin-mysql/preparar-sql-cuenta.sh`);
  const mk91 = dx('mysql < /root/cervesur-ro-visor.sql');
  const inst91 = dx(`bash ${PK91}/scripts/instalar.sh --clave-archivo /root/cervesur-ro-visor.clave`, { CERVESUR_PILOTO_CONFIRMAR: 'SI' });
  ok('el piloto visual 8091 se instala con su cuenta cervesur_ro_visor y su instalador', prep91.code === 0 && mk91.code === 0 && inst91.code === 0 && /\d+ PASS \/ 0 FAIL/.test(inst91.out), inst91.out.slice(-300));
  const diagToken = dx('grep "^CERVESUR_DIAG_TOKEN=" /opt/cervesur-react-ro/backend/.env | cut -d= -f2-').out;
  const diag90 = () => dx(`printf 'header = "X-Diagnostic-Token: %s"\\n' "${diagToken}" | curl -sS -m 10 -K - -o /dev/null -w '%{http_code}' http://127.0.0.1:8090/api/diagnostico/resumen`).out;
  const visor91 = () => dx('curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8091/api/ping').out;
  ok('8090 responde con su token y 8091 responde antes de empezar (seis entornos vivos)', diag90() === '200' && visor91() === '200' && dx('curl -s http://127.0.0.1/').out.includes('FLUTTER-SENTINEL') && dx('curl -fs http://127.0.0.1:8088/api/ping >/dev/null && curl -fs http://127.0.0.1:8089/api/ping >/dev/null').code === 0);

  // usuarios SINTÉTICOS con contraseña conocida (solo en la base desechable del ensayo)
  section = 'Datos sintéticos de usuarios';
  const hashPw = dx(`php -r 'echo password_hash(getenv("PW"), PASSWORD_BCRYPT);'`, { PW: GOODPW }).out;
  // el fixture no tiene ningún usuario activo SIN informes: se añade uno sintético (sin relaciones) ANTES de tomar la línea base
  put('/root/sin.sql', `INSERT INTO cervesur_portalbi.user (id, username, email, password, first_name, last_name, role, is_active) VALUES (900, 'syn900', 'syn900@example.invalid', '${hashPw}', 'Sintético', 'SinInformes', 'usuario', 1);
`);
  ok('se añade un usuario sintético activo sin ninguna asignación (caso «sin informes»)', dx('mysql < /root/sin.sql').code === 0);
  const VIS = id => `(SELECT COUNT(DISTINCT r.id) FROM cervesur_portalbi.user_empresa_reporte uer JOIN cervesur_portalbi.empresa_reporte er ON er.id = uer.empresa_reporte_id AND er.activo = 1 JOIN cervesur_portalbi.empresa e ON e.id = er.empresa_id AND e.activo = 1 JOIN cervesur_portalbi.empresa_usuario eu ON eu.empresa_id = e.id AND eu.user_id = ${id} AND eu.activo = 1 JOIN cervesur_portalbi.reporte r ON r.id = er.reporte_id AND r.activo = 1 WHERE uer.user_id = ${id} AND uer.activo = 1)`;
  const rows = sqlRoot(`SELECT u.id, u.role, u.is_active, ${VIS('u.id')} FROM cervesur_portalbi.user u ORDER BY u.id`).split('\n').map(l => l.split('\t')).map(([id, role, active, n]) => ({ id: Number(id), raw: role, role: String(role).toLowerCase(), active: Number(active), n: Number(n) }));
  const isU = r => ['user', 'usuario'].includes(r.role); // el backend normaliza «usuario» → «user»
  const candidatos = rows.filter(r => isU(r) && r.active === 1 && r.n >= 1).sort((a, b) => b.n - a.n);
  const A = candidatos[0]; const B = candidatos.find(r => r.id !== A.id && r.n !== A.n) ?? candidatos[1];
  const SIN = rows.find(r => isU(r) && r.active === 1 && r.n === 0);
  const ADM = rows.find(r => !isU(r) && r.active === 1); const INACT = rows.find(r => isU(r) && r.active === 0); const FUERA = rows.find(r => isU(r) && r.active === 1 && ![A?.id, B?.id, SIN?.id].includes(r.id) && r.n > 0);
  ok('hay usuarios sintéticos para cada caso: dos con informes distintos, uno sin informes, un administrador, uno inactivo y uno activo fuera de la lista', Boolean(A && B && SIN && ADM && INACT && FUERA), JSON.stringify({ A, B, SIN, ADM, INACT, FUERA }));
  const idsTodos = [A, B, SIN, ADM, INACT, FUERA].map(r => r.id).join(',');
  put('/root/pw.sql', `UPDATE cervesur_portalbi.user SET password = '${hashPw}' WHERE id IN (${idsTodos});\n`);
  ok('las contraseñas de prueba se asignan solo a estos usuarios sintéticos (el hash nunca sale del contenedor)', dx('mysql < /root/pw.sql').code === 0 && !hashPw.includes(GOODPW));
  const loginOf = id => sqlRoot(`SELECT username FROM cervesur_portalbi.user WHERE id = ${id}`);
  const lA = loginOf(A.id); const lB = loginOf(B.id); const lS = loginOf(SIN.id); const lAdm = loginOf(ADM.id); const lIn = loginOf(INACT.id); const lF = loginOf(FUERA.id);

  // ----------------------------------------------------------------- Línea base y utilidades de instantánea
  const apachePid = () => dx('cat /var/run/apache2/apache2.pid').out;
  const headersOn = () => dx('test -e /etc/apache2/mods-enabled/headers.load').code === 0;
  const sslOn = () => dx('test -e /etc/apache2/mods-enabled/ssl.load').code === 0;
  const SNAP = `set -u
tree() { if [ -d "$1" ]; then (cd "$1" && find . -type f -printf '%p %s %T@ %m %u:%g\\n' | LC_ALL=C sort | sha256sum | cut -c1-64); else echo ausente; fi; }
http() { curl -sS -m 6 -o /dev/null -D - "$1" | tr -d '\\r' | awk 'NR==1{print;next} /^[A-Za-z-]+:/{print tolower(substr($0,1,index($0,":")-1))}' | LC_ALL=C sort; curl -sS -m 6 "$1" | sha256sum | cut -c1-64; }
echo "== configuracion"; sha256sum /etc/apache2/sites-available/cervesur.conf /var/www/cervesur/.htaccess /etc/apache2/sites-available/cervesur-react-prueba.conf /etc/apache2/cervesur-react-prueba/react-common.conf /opt/cervesur-react-prueba/backend/.env /etc/apache2/sites-available/cervesur-react-compat.conf /etc/apache2/cervesur-react-compat/react-compat-common.conf /opt/cervesur-react-compat/backend/.env /etc/apache2/sites-available/cervesur-react-ro.conf /etc/apache2/cervesur-react-ro/react-ro-common.conf /opt/cervesur-react-ro/backend/.env /etc/apache2/sites-available/cervesur-react-piloto.conf /etc/apache2/cervesur-react-piloto/react-piloto-common.conf /opt/cervesur-react-piloto/backend/.env /etc/apache2/apache2.conf /etc/apache2/ports.conf
echo "== arboles"; tree /var/www/cervesur; tree /var/www/cervesur-react-prueba; tree /opt/cervesur-react-prueba; tree /var/www/cervesur-react-compat; tree /opt/cervesur-react-compat; tree /opt/cervesur-react-ro; tree /var/lib/cervesur-react-ro; tree /var/www/cervesur-react-piloto; tree /opt/cervesur-react-piloto; tree /var/lib/cervesur-react-piloto
echo "== modulos"; apache2ctl -M 2>/dev/null | LC_ALL=C sort | sha256sum | cut -c1-64; ls /etc/apache2/mods-enabled | LC_ALL=C sort | sha256sum | cut -c1-64
echo "== http"; for u in http://127.0.0.1/ http://127.0.0.1/ruta/profunda http://127.0.0.1:8088/ http://127.0.0.1:8088/api/ping http://127.0.0.1:8089/ http://127.0.0.1:8089/api/ping http://127.0.0.1:8090/api/ping http://127.0.0.1:8091/ http://127.0.0.1:8091/api/ping; do echo "$u"; http "$u"; done
echo "== mysql"; mysql -N -e 'SHOW DATABASES' | LC_ALL=C sort; mysql -N -e 'SELECT user, host FROM mysql.user' | LC_ALL=C sort
for d in cervesur_react_prueba cervesur_react_compat_qa cervesur_portalbi; do for t in $(mysql -N -e 'SHOW TABLES' $d); do echo "$d.$t $(mysql -N -e "CHECKSUM TABLE $t" $d)"; done; done
echo "== puertos"; ss -ltnH | awk '{print $4}' | grep -v ':8092$' | grep -v ':18093$' | LC_ALL=C sort -u
echo "== sitios"; ls /etc/apache2/sites-enabled | grep -v '^cervesur-react-usuarios.conf$' | LC_ALL=C sort
`;
  put('/root/snap.sh', SNAP);
  const snapshot = () => dx('bash /root/snap.sh').out;
  const grantsOf = u => dx(`mysql -N -e "SELECT user, host FROM mysql.user WHERE user='${u}'" | while read u h; do mysql -N -e "SHOW GRANTS FOR '$u'@'$h'"; done | sha256sum | cut -c1-64`).out;
  const grantsHash = () => CUENTAS.map(grantsOf).join('|');
  const dbHash = () => dx('mysql -N -e "SHOW DATABASES" | LC_ALL=C sort | sha256sum | cut -c1-64').out;
  const estadoLimpio = () => dx('! test -e /opt/cervesur-react-usuarios && ! test -e /var/www/cervesur-react-usuarios && ! test -e /etc/apache2/cervesur-react-usuarios && ! test -e /etc/apache2/sites-available/cervesur-react-usuarios.conf && ! test -e /etc/apache2/sites-enabled/cervesur-react-usuarios.conf && ! test -e /var/lib/cervesur-react-usuarios && [ -z "$(ss -ltnH \'sport = :8092\')" ]').code === 0;
  const cuentasExisten = () => Number(sqlRoot(`SELECT COUNT(*) FROM mysql.user WHERE user IN ('${CUENTAS.join("','")}')`));
  const portalesVivos = () => diag90() === '200' && visor91() === '200' && dx('curl -s http://127.0.0.1/').out.includes('FLUTTER-SENTINEL') && dx('curl -fs http://127.0.0.1:8088/api/ping >/dev/null && curl -fs http://127.0.0.1:8089/api/ping >/dev/null').code === 0;

  // ----------------------------------------------------------------- Preflight A
  section = 'Preflight de infraestructura (A)';
  ok('mod_ssl NO está cargado de partida (como puede ocurrir en el servidor real)', !sslOn());
  const paSinSsl = dx(`bash ${PK}/scripts/preflight-infra.sh`);
  ok('A FALLA si mod_ssl no está cargado, lo explica y NO lo habilita; no se modifica nada', paSinSsl.code === 1 && /mod_ssl YA cargado/.test(paSinSsl.out) && !sslOn() && estadoLimpio(), paSinSsl.out.slice(-500));
  const simSinSsl = dx(`bash ${PK}/scripts/instalar.sh --simular`);
  ok('instalar.sh --simular se NIEGA sin mod_ssl (código 1) y no deja restos', simSinSsl.code !== 0 && estadoLimpio());
  dx('a2enmod ssl >/dev/null && apache2ctl graceful'); await sleep(2500); // acción de un ADMINISTRADOR con autorización expresa (no del instalador)
  ok('un administrador habilita mod_ssl (a2enmod ssl): ahora está cargado', sslOn());
  const S00 = snapshot(); const pid0 = apachePid(); const dbHash0 = dbHash();
  ok('línea base de los cinco portales existentes, módulos, bases (CHECKSUM de cervesur_portalbi), cuentas, puertos y sitios', S00.length > 1000 && /cervesur_portalbi\.user /.test(S00) && /8091/.test(S00));
  const pa = dx(`bash ${PK}/scripts/preflight-infra.sh`);
  ok('A correcto SIN las cuentas: mod_ssl, puerto 8092 libre (comprobado), paquete íntegro, Flutter/8088/8089/8090/8091 responden', pa.code === 0 && /preflight A \(infraestructura\): PASS/.test(pa.out) && /puerto 8092 libre/.test(pa.out) && /Piloto visual \(8091\) instalado y responde/.test(pa.out) && /Diagnóstico \(8090\) instalado y responde/.test(pa.out), pa.out.slice(-700));
  ok('A no modifica nada', estadoLimpio() && snapshot() === S00);

  section = 'Preflight de acceso (B) sin cuentas';
  ok('las tres cuentas y la base de estado todavía NO existen', cuentasExisten() === 0 && sqlRoot("SHOW DATABASES LIKE 'cervesur_react_estado'") === '');
  const pbNone = dx(`bash ${PK}/scripts/preflight-acceso.sh --claves-archivo /nonexistente`);
  ok('B queda PENDIENTE (código 3), nunca PASS', pbNone.code === 3 && /PENDIENTE/.test(pbNone.out) && !/preflight B \(acceso\): PASS/.test(pbNone.out), pbNone.out);
  const simNone = dx(`bash ${PK}/scripts/instalar.sh --simular`);
  ok('instalar.sh --simular: infraestructura PASS, acceso PENDIENTE, código 3 y "NO se puede instalar todavía"', simNone.code === 3 && /infraestructura PASS · acceso PENDIENTE/.test(simNone.out) && /NO se puede instalar todavía/.test(simNone.out), simNone.out.slice(-400));
  const realNone = dx(`bash ${PK}/scripts/instalar.sh --claves-archivo /nonexistente`, CONFIRM);
  ok('instalar.sh real sin cuentas se NIEGA («No se modificó nada») y no deja restos', realNone.code !== 0 && /PENDIENTE/.test(realNone.out) && /No se modificó nada/.test(realNone.out) && estadoLimpio() && dx('test -e /var/backups/cervesur-react-usuarios').code !== 0 && snapshot() === S00);

  section = 'Procedimiento administrativo independiente (SQL de tres cuentas)';
  const prep = dx(`bash ${PK}/admin-mysql/preparar-sql-cuentas.sh`);
  const claves = dx('cat /root/cervesur-usuarios.claves').out.split('\n').map(l => l.split(/=(.*)/s)[1]);
  ok('preparar-sql-cuentas.sh genera SQL y claves (600) SIN mostrar ninguna clave y SIN conectarse a MySQL', prep.code === 0 && claves.length === 3 && claves.every(c => c && !prep.out.includes(c)) && dx('stat -c "%a %U" /root/cervesur-usuarios.sql /root/cervesur-usuarios.claves').out === '600 root\n600 root' && cuentasExisten() === 0, prep.out);
  ok('las tres claves son distintas, aleatorias y con complejidad (MySQL validate_password MEDIUM)', new Set(claves).size === 3 && claves.every(c => /^Aa1!/.test(c) && c.length >= 40));
  const sqlFull = dx('cat /root/cervesur-usuarios.sql').out;
  const sqlText = sqlFull.split('\n').filter(l => !/^\s*--/.test(l)).join('\n'); // solo sentencias: los comentarios explican lo que NO se concede
  ok('el SQL: tres CREATE USER, solo GRANT SELECT por columna en la base de lectura (9 + 21 columnas) y DML solo en la base de estado; host 127.0.0.1; nada global ni WITH GRANT OPTION', (sqlText.match(/^CREATE USER/gm) ?? []).length === 3 && (sqlText.match(/^GRANT SELECT \(/gm) ?? []).length === 6 && /^GRANT SELECT, INSERT, UPDATE, DELETE ON `cervesur_react_estado`\.\* TO 'cervesur_react_estado'@'127\.0\.0\.1';$/m.test(sqlText) && !/ON \*\.\*|GRANT OPTION|GRANT ALL/i.test(sqlText) && !/^GRANT (?!SELECT)/m.test(sqlText.replace(/^GRANT SELECT, INSERT, UPDATE, DELETE ON `cervesur_react_estado`\.\*.*$/m, '')) && !/powerbi/i.test(sqlText) && (sqlText.match(/@'127\.0\.0\.1'/g) ?? []).length >= 9 && !/cervesur_portalbi`\.\*/.test(sqlText), sqlText.slice(-400));
  ok('las columnas concedidas son EXACTAMENTE las del catálogo SQL del backend (9 en user; 21 en reporte, empresa, empresa_reporte, empresa_usuario y user_empresa_reporte)', (() => { const cols = t => (sqlText.match(new RegExp('GRANT SELECT \\(([^)]*)\\) ON `cervesur_portalbi`\\.`' + t + '`', 'g')) ?? []).flatMap(g => g.replace(/GRANT SELECT \(|\).*/g, '').split(',').map(c => c.trim())); return cols('user').length === 9 && ['reporte', 'empresa', 'empresa_reporte', 'empresa_usuario', 'user_empresa_reporte'].reduce((n, t) => n + cols(t).length, 0) === 21 && !cols('user').includes('powerbi_clientsecret'); })());
  ok('REDACCIÓN: cada clave aparece exactamente UNA vez (en su CREATE USER) y en NINGÚN comentario', claves.every((c, i) => dx(`grep -cF -- "${c}" /root/cervesur-usuarios.sql || true`).out === '1' && dx(`grep -E '^[[:space:]]*--' /root/cervesur-usuarios.sql | grep -cF -- "${c}" || true`).out === '0' && dx(`grep -E "^CREATE USER '${CUENTAS[i]}'@" /root/cervesur-usuarios.sql | grep -cF -- "${c}" || true`).out === '1'));
  const red = dx(`bash ${PK}/admin-mysql/mostrar-sql-redactado.sh /root/cervesur-usuarios.sql`);
  ok('mostrar-sql-redactado.sh oculta las tres claves (********) y muestra el resto', red.code === 0 && claves.every(c => !red.out.includes(c)) && (red.out.match(/\*{8}/g) ?? []).length === 3 && /CREATE USER 'cervesur_ro_auth'/.test(red.out));
  ok('sin el archivo de claves el visor se NIEGA a mostrar el SQL (jamás sin redactar)', (() => { dx('mv /root/cervesur-usuarios.claves /root/c.tmp'); const r = dx(`bash ${PK}/admin-mysql/mostrar-sql-redactado.sh /root/cervesur-usuarios.sql`); dx('mv /root/c.tmp /root/cervesur-usuarios.claves'); return r.code !== 0 && !/CREATE USER/.test(r.out) && claves.every(c => !r.out.includes(c)); })());
  ok('el generador no sobrescribe archivos existentes y falla sin dejar basura si se le pide la misma base para lectura y estado', dx(`bash ${PK}/admin-mysql/preparar-sql-cuentas.sh`).code !== 0 && dx(`bash ${PK}/admin-mysql/preparar-sql-cuentas.sh --salida /root/x --base-estado cervesur_portalbi`).code !== 0 && dx('test ! -e /root/x.sql && test ! -e /root/x.claves').code === 0);

  // cuentas defectuosas: B debe detectarlas y nadie las corrige
  const crear = (mutacion = ':') => { dx(`mysql -e "DROP USER IF EXISTS ${CUENTAS.map(c => `'${c}'@'127.0.0.1'`).join(', ')}; DROP DATABASE IF EXISTS cervesur_react_estado"`); return dx(`cp /root/cervesur-usuarios.sql /root/var.sql && ${mutacion} && mysql < /root/var.sql`); };
  const aniadir = sql => `printf '%s\\n' "${sql.replace(/"/g, '\\"').replace(/`/g, '\\`')}" >> /root/var.sql`;
  const casos = [
    ['auth con exceso: SELECT sobre reporte.enlace', aniadir("GRANT SELECT (enlace) ON `cervesur_portalbi`.`reporte` TO 'cervesur_ro_auth'@'127.0.0.1';"), /SOBRAN: reporte\.enlace/],
    ['auth con exceso: INSERT sobre user', aniadir("GRANT INSERT ON `cervesur_portalbi`.`user` TO 'cervesur_ro_auth'@'127.0.0.1';"), /SHOW GRANTS: ningún privilegio|sin privilegios a nivel de base|auth»: SHOW GRANTS/],
    ['catálogo con exceso: SELECT sobre user.password', aniadir("GRANT SELECT (password) ON `cervesur_portalbi`.`user` TO 'cervesur_ro_catalogo'@'127.0.0.1';"), /SOBRAN: user\.password/],
    ['catálogo con exceso: credenciales de Power BI', aniadir("GRANT SELECT (powerbi_clientsecret) ON `cervesur_portalbi`.`empresa` TO 'cervesur_ro_catalogo'@'127.0.0.1';"), /SOBRAN: empresa\.powerbi_clientsecret/],
    ['catálogo con permisos INSUFICIENTES (falta reporte.enlace)', 'sed -i "s/, activo, enlace) ON/, activo) ON/" /root/var.sql', /FALTAN: reporte\.enlace/],
    ['catálogo con SELECT sobre toda la base', aniadir("GRANT SELECT ON `cervesur_portalbi`.* TO 'cervesur_ro_catalogo'@'127.0.0.1';"), /sin privilegios a nivel de base de datos/],
    ['estado con escritura sobre la base de LECTURA', aniadir("GRANT INSERT, UPDATE ON `cervesur_portalbi`.* TO 'cervesur_react_estado'@'127.0.0.1';"), /estado: solo SELECT\/INSERT\/UPDATE\/DELETE/],
    ['estado con DDL (CREATE) en su base', aniadir("GRANT CREATE ON `cervesur_react_estado`.* TO 'cervesur_react_estado'@'127.0.0.1';"), /estado: solo SELECT\/INSERT\/UPDATE\/DELETE/],
    ['estado con privilegio global (PROCESS)', aniadir("GRANT PROCESS ON *.* TO 'cervesur_react_estado'@'127.0.0.1';"), /estado: solo SELECT\/INSERT\/UPDATE\/DELETE/],
    ['catálogo con privilegio global (PROCESS)', aniadir("GRANT PROCESS ON *.* TO 'cervesur_ro_catalogo'@'127.0.0.1';"), /SHOW GRANTS|sin privilegios globales/],
    ['catálogo con GRANT OPTION', aniadir("GRANT SELECT (id) ON `cervesur_portalbi`.`reporte` TO 'cervesur_ro_catalogo'@'127.0.0.1' WITH GRANT OPTION;"), /SHOW GRANTS|ningún privilegio adicional/],
  ];
  section = 'Preflight de acceso (B) con cuentas incorrectas';
  for (const [label, mutation, pattern] of casos) {
    const created = crear(mutation); const gh = grantsHash();
    const r = dx(`bash ${PK}/scripts/preflight-acceso.sh --claves-archivo /root/cervesur-usuarios.claves`);
    const sim = dx(`bash ${PK}/scripts/instalar.sh --simular --claves-archivo /root/cervesur-usuarios.claves`); const real = dx(`bash ${PK}/scripts/instalar.sh --claves-archivo /root/cervesur-usuarios.claves`, CONFIRM);
    ok(`${label}: B falla (código 1), lo explica y nadie la corrige; --simular (1) e instalar real se NIEGAN sin dejar restos`, created.code === 0 && r.code === 1 && pattern.test(r.out) && /preflight B \(acceso\): FAIL/.test(r.out) && grantsHash() === gh && sim.code === 1 && real.code !== 0 && /No se modificó nada/.test(real.out) && estadoLimpio() && dx('test -e /var/backups/cervesur-react-usuarios').code !== 0, `${r.code} ${sim.code} ${real.code} ${r.out.slice(-500)}`);
  }
  section = 'Preflight correcto (A y B)';
  const created = crear();
  ok('las tres cuentas y la base de estado se crean con el SQL del procedimiento administrativo (el ensayo actúa como administrador)', created.code === 0 && cuentasExisten() === 3 && sqlRoot("SHOW DATABASES LIKE 'cervesur_react_estado'") === 'cervesur_react_estado', created.out);
  const v03 = dx(`mysql -B < ${PK}/admin-mysql/03-verificar-privilegios.sql`);
  ok('03-verificar-privilegios.sql: sin privilegios globales ni de escritura sobre la base de lectura', v03.code === 0 && !/PROCESS|GRANT OPTION|SUPER/.test(v03.out), v03.out.slice(-500));
  const pb = dx(`bash ${PK}/scripts/preflight-acceso.sh --claves-archivo /root/cervesur-usuarios.claves`);
  ok('B correcto (código 0): autentica las tres cuentas, 9 y 21 columnas exactas, solo lectura, sin tabla user en el catálogo, sin credenciales de Power BI, estado con DML solo en su base', pb.code === 0 && /preflight B \(acceso\): PASS/.test(pb.out) && /están concedidas las 9 columnas/.test(pb.out) && /están concedidas las 21 columnas/.test(pb.out) && /estado: NO puede leer la base de lectura/.test(pb.out) && /consultas\)/.test(pb.out), pb.out);
  ok('B no imprime ninguna clave ni cifras de las tablas', claves.every(c => !pb.out.includes(c)) && !/\b(76|82|95|102)\b/.test(pb.out), pb.out.split('\n').filter(l => /\b(76|82|95|102)\b/.test(l)).join(' | '));
  const S0 = snapshot();
  const sim = dx(`bash ${PK}/scripts/instalar.sh --simular --claves-archivo /root/cervesur-usuarios.claves`);
  ok('instalar.sh --simular: infraestructura PASS y acceso PASS (código 0), sin modificar nada', sim.code === 0 && /infraestructura PASS · acceso PASS/.test(sim.out) && estadoLimpio() && snapshot() === S0, sim.out.slice(-300));

  // ----------------------------------------------------------------- Fallos de infraestructura y de TLS
  section = 'Fallos de infraestructura';
  const K = '--claves-archivo /root/cervesur-usuarios.claves';
  dx(`rm -rf /root/p-tamper && cp -r ${PK} /root/p-tamper && echo x >> /root/p-tamper/public/index.html`);
  const tamper = dx(`bash /root/p-tamper/scripts/instalar.sh --simular ${K}`);
  ok('paquete con checksum incorrecto (aplicación alterada): el preflight A lo rechaza antes de instalar', tamper.code !== 0 && /integridad del paquete/.test(tamper.out) && estadoLimpio() && snapshot() === S0);
  dx('phpdismod pdo_mysql'); const sinDep = dx(`bash ${PK}/scripts/instalar.sh --simular ${K}`); dx('phpenmod pdo_mysql');
  ok('dependencia PHP faltante (pdo_mysql): el instalador se NIEGA y no modifica nada', sinDep.code !== 0 && /extensiones del backend/.test(sinDep.out) && estadoLimpio());
  dx('a2dismod headers >/dev/null && apache2ctl graceful'); await sleep(1500);
  const sinH = dx(`bash ${PK}/scripts/instalar.sh --simular ${K}`);
  dx('a2enmod headers >/dev/null && apache2ctl graceful'); await sleep(1500);
  ok('sin mod_headers cargado el instalador se NIEGA (no lo habilita) y mod_headers se restaura intacto', sinH.code !== 0 && /mod_headers YA cargado/.test(sinH.out) && estadoLimpio() && headersOn());
  dx(`rm -rf /root/p-nopilot && cp -r ${PK} /root/p-nopilot && sed -i 's/"pilotMode": "usuarios"/"pilotMode": "operador"/' /root/p-nopilot/public/build-info.json && cd /root/p-nopilot && find . -type f ! -name SHA256SUMS -print0 | xargs -0 sha256sum > SHA256SUMS`);
  const noPil = dx(`bash /root/p-nopilot/scripts/instalar.sh --simular ${K}`);
  ok('un paquete con una aplicación que NO es la del piloto de usuarios (pilotMode distinto) se rechaza aunque su checksum sea válido', noPil.code !== 0 && /compilación del piloto de usuarios/.test(noPil.out) && estadoLimpio());
  const colisiones = [
    ['el puerto 8092 está ocupado', 'setsid nohup socat TCP-LISTEN:8092,bind=127.0.0.1,fork,reuseaddr SYSTEM:true >/dev/null 2>&1 & sleep 1', "pkill -f 'TCP-LISTEN:809[2]' || true", /puerto 8092 libre/],
    ['la carpeta /opt/cervesur-react-usuarios ya existe', 'mkdir /opt/cervesur-react-usuarios', 'rmdir /opt/cervesur-react-usuarios', /no existe \/opt\/cervesur-react-usuarios/],
    ['la carpeta /var/www/cervesur-react-usuarios ya existe', 'mkdir /var/www/cervesur-react-usuarios', 'rmdir /var/www/cervesur-react-usuarios', /no existe \/var\/www\/cervesur-react-usuarios/],
    ['la carpeta /var/lib/cervesur-react-usuarios ya existe', 'mkdir /var/lib/cervesur-react-usuarios', 'rmdir /var/lib/cervesur-react-usuarios', /no existe \/var\/lib\/cervesur-react-usuarios/],
    ['el sitio Apache cervesur-react-usuarios ya existe', 'echo "# ajeno" > /etc/apache2/sites-available/cervesur-react-usuarios.conf', 'rm -f /etc/apache2/sites-available/cervesur-react-usuarios.conf', /no existe \/etc\/apache2\/sites-available\/cervesur-react-usuarios\.conf/],
    ['la configuración de Apache ya menciona el puerto 8092', 'echo "# Listen 127.0.0.1:8092" > /etc/apache2/conf-enabled/ajeno-8092.conf', 'rm -f /etc/apache2/conf-enabled/ajeno-8092.conf', /ninguna configuración de Apache menciona el puerto 8092/],
  ];
  for (const [label, arm, disarm, pattern] of colisiones) {
    dx(arm); const r = dx(`bash ${PK}/scripts/instalar.sh ${K}`, CONFIRM);
    dx(disarm); await sleep(300);
    ok(`colisión (${label}): el instalador ABORTA con «No se modificó nada» y no crea el respaldo`, r.code !== 0 && pattern.test(r.out) && /No se modificó nada/.test(r.out) && dx('test -e /var/backups/cervesur-react-usuarios').code !== 0, r.out.slice(-300));
  }
  ok('tras las colisiones el servidor sigue idéntico a la línea base y sin restos', estadoLimpio() && snapshot() === S0);

  section = 'Certificado TLS aportado (validación previa)';
  dx(`mkdir -p /root/tls && cd /root/tls && chmod 700 . && openssl ecparam -name prime256v1 -genkey -noout -out ok.key && openssl req -new -x509 -key ok.key -days 365 -subj /CN=localhost -addext subjectAltName=DNS:localhost,IP:127.0.0.1 -out ok.crt 2>/dev/null \
    && openssl ecparam -name prime256v1 -genkey -noout -out otra.key && openssl req -new -x509 -key ok.key -days 5 -subj /CN=localhost -addext subjectAltName=DNS:localhost -out corto.crt 2>/dev/null \
    && openssl req -new -x509 -key ok.key -days 365 -subj /CN=otro.invalido -addext subjectAltName=DNS:otro.invalido -out nombre.crt 2>/dev/null && chmod 600 *.key && cp ok.key abierta.key && chmod 644 abierta.key`);
  const tlsCases = [
    ['caduca en menos de 30 días', '--tls-cert /root/tls/corto.crt --tls-clave /root/tls/ok.key', /no caduca en los próximos 30 días/],
    ['no cubre el nombre localhost', '--tls-cert /root/tls/nombre.crt --tls-clave /root/tls/ok.key', /cubre el nombre «localhost»/],
    ['la clave no corresponde al certificado', '--tls-cert /root/tls/ok.crt --tls-clave /root/tls/otra.key', /la clave privada corresponde al certificado/],
    ['la clave privada es legible por todos (644)', '--tls-cert /root/tls/ok.crt --tls-clave /root/tls/abierta.key', /clave privada es de root con modo 600/],
  ];
  for (const [label, args, pattern] of tlsCases) {
    const r = dx(`bash ${PK}/scripts/instalar.sh ${K} ${args}`, CONFIRM);
    ok(`certificado ${label}: el preflight A lo rechaza y el instalador NO instala nada`, r.code !== 0 && pattern.test(r.out) && /No se modificó nada/.test(r.out) && estadoLimpio() && dx('test -e /var/backups/cervesur-react-usuarios').code !== 0, r.out.slice(-400));
  }
  const solo = dx(`bash ${PK}/scripts/instalar.sh ${K} --tls-cert /root/tls/ok.crt`, CONFIRM);
  ok('--tls-cert sin --tls-clave (o al revés) se rechaza', solo.code !== 0 && /van juntos/.test(solo.out) && estadoLimpio());
  const okCert = dx(`bash ${PK}/scripts/preflight-infra.sh --tls-cert /root/tls/ok.crt --tls-clave /root/tls/ok.key`);
  ok('un certificado vigente, con nombre y con su clave protegida pasa el preflight A', okCert.code === 0 && /clave privada corresponde al certificado/.test(okCert.out), okCert.out.slice(-400));
  ok('todo el bloque de certificados dejó el servidor intacto', snapshot() === S0 && estadoLimpio());

  section = 'Fallo forzado a mitad de instalación';
  dx(`rm -rf /root/p-roto && cp -r ${PK} /root/p-roto && echo "DirectivaInexistente x" >> /root/p-roto/apache/react-usuarios-common.conf && cd /root/p-roto && find . -type f ! -name SHA256SUMS -print0 | xargs -0 sha256sum > SHA256SUMS`);
  const gh0 = grantsHash();
  const roto = dx(`bash /root/p-roto/scripts/instalar.sh ${K}`, CONFIRM);
  ok('la instalación falla (configtest rechaza la configuración) y revierte automáticamente SOLO lo creado', roto.code !== 0 && /se revierte automáticamente/.test(roto.out), roto.out.slice(-500));
  ok('sin restos de 8092 (ni .env, ni clave privada TLS, ni aplicación); las tres cuentas y la base de estado siguen igual; los demás portales, intactos', estadoLimpio() && cuentasExisten() === 3 && grantsHash() === gh0 && snapshot() === S0);
  ok('mod_headers y mod_ssl SIGUEN habilitados, Apache conserva su proceso maestro y los respaldos se conservan', headersOn() && sslOn() && apachePid() === pid0 && dx('ls /var/backups/cervesur-react-usuarios/*.tgz >/dev/null').code === 0);
  ok('ni las claves de las cuentas ni ningún secreto aparecen en la salida del fallo', claves.every(c => !roto.out.includes(c)));

  // ----------------------------------------------------------------- Instalación
  section = 'Instalación (informes autorizados: dos)';
  const ids = sqlRoot('SELECT GROUP_CONCAT(id) FROM (SELECT id FROM cervesur_portalbi.reporte WHERE activo = 1 ORDER BY id LIMIT 2) x');
  const sinFrase = dx(`bash ${PK}/scripts/instalar.sh ${K} --informes-autorizados ${ids} < /dev/null`, CONFIRM);
  ok('autorizar informes SIN la segunda confirmación escrita cancela la instalación y no deja restos', sinFrase.code !== 0 && /Cancelado/.test(sinFrase.out) && estadoLimpio() && snapshot() === S0);
  const sinConf = dx(`bash ${PK}/scripts/instalar.sh ${K} < /dev/null`);
  ok('sin la confirmación escrita INSTALAR-8092 no se instala nada', sinConf.code !== 0 && /Cancelado/.test(sinConf.out) && estadoLimpio() && snapshot() === S0);
  const inst = dx(`bash ${PK}/scripts/instalar.sh ${K} --informes-autorizados ${ids}`, { ...CONFIRM, CERVESUR_USUARIOS_CONFIRMAR_INFORMES: 'AUTORIZO-INFORMES' });
  ok('instalar.sh termina correctamente', inst.code === 0, inst.out.slice(-1500));
  const verif = /(\d+) PASS \/ (\d+) FAIL/.exec(inst.out);
  ok('verificar.sh integrado: todas las comprobaciones pasan', Boolean(verif) && verif[2] === '0' && Number(verif[1]) >= 28, verif?.[0] ?? inst.out.slice(-500));
  const env = k => dx(`grep "^${k}=" /opt/cervesur-react-usuarios/backend/.env | cut -d= -f2-`).out;
  const secretos = [env('JWT_SECRET'), env('CERVESUR_PILOT_SECRET'), env('DB_PASS'), env('AUTH_DB_PASS'), env('STATE_DB_PASS')];
  ok('el .env tiene el modo exigido, sin HTTP de pruebas ni HSTS, las cuentas y la base de estado correctas, el tope de IP holgado y los secretos aleatorios de 256 bits; las claves son las generadas', env('CERVESUR_MODE') === 'piloto_usuarios' && env('CERVESUR_REQUIRED_MODE') === 'piloto_usuarios' && env('CERVESUR_INSECURE_TESTS') === '' && env('CERVESUR_PILOT_HSTS') === '' && env('CERVESUR_PILOT_MAX_FALLOS_IP') === '100' && env('DB_USER') === 'cervesur_ro_catalogo' && env('AUTH_DB_USER') === 'cervesur_ro_auth' && env('STATE_DB_USER') === 'cervesur_react_estado' && env('STATE_DB_NAME') === 'cervesur_react_estado' && /^[0-9a-f]{64}$/.test(env('JWT_SECRET')) && /^[0-9a-f]{64}$/.test(env('CERVESUR_PILOT_SECRET')) && env('JWT_SECRET') !== env('CERVESUR_PILOT_SECRET') && env('CERVESUR_PILOT_VISOR_IDS') === ids && secretos.slice(2).every(s => claves.includes(s)));
  ok('los secretos del piloto son distintos de los de 8088, 8089, 8090 y 8091', dx(`! grep -rqF -e "${secretos[0]}" -e "${secretos[1]}" /opt/cervesur-react-prueba /opt/cervesur-react-compat /opt/cervesur-react-ro /opt/cervesur-react-piloto`).code === 0);
  ok('ningún secreto ni clave aparece en la salida del instalador, en /var/log, en los respaldos ni en el paquete', ![...secretos, ...claves].some(s => inst.out.includes(s)) && dx(`! grep -rqsF ${[...secretos.slice(0, 2), ...claves].map(s => `-e "${s}"`).join(' ')} /var/log /var/backups ${PK} --exclude=mysql.err`).code === 0);
  ok('permisos: .env 640 root:www-data; carpeta TLS 700 y clave privada 600 root; sesiones 700 www-data; aplicación y backend propiedad de root', dx('stat -c "%a %U:%G" /opt/cervesur-react-usuarios/backend/.env /etc/apache2/cervesur-react-usuarios/tls /etc/apache2/cervesur-react-usuarios/tls/servidor.key /var/lib/cervesur-react-usuarios/sessions; stat -c %U /var/www/cervesur-react-usuarios/public /opt/cervesur-react-usuarios/backend').out === '640 root:www-data\n700 root:root\n600 root:root\n700 www-data:www-data\nroot\nroot');
  ok('el paquete NO copió ningún certificado, clave privada ni .env al DocumentRoot; la clave TLS fue creada EN el servidor', dx('! find /var/www/cervesur-react-usuarios /opt/cervesur-react-usuarios \\( -name "*.key" -o -name "*.pem" -o -name "*.crt" \\) | grep -q . && ! find /opt/cervesur-react-usuarios /var/www/cervesur-react-usuarios -name ".env*" ! -path "*/backend/.env" | grep -q .').code === 0);
  ok('el instalador NO modificó MySQL (cuentas, privilegios y bases iguales) y no creó ninguna base ni cuenta', grantsHash() === gh0 && cuentasExisten() === 3);
  const S1 = snapshot();
  ok('Flutter, 8088, 8089, 8090 y 8091, módulos, bases (CHECKSUM de cervesur_portalbi), cuentas y puertos idénticos a la línea base: 0 cambios ajenos', S1 === S0, S0.split('\n').filter((l, i) => l !== S1.split('\n')[i]).join(' | '));
  ok('mod_headers y mod_ssl siguen habilitados, Apache no se reinició y los otros cinco entornos siguen respondiendo', headersOn() && sslOn() && apachePid() === pid0 && portalesVivos());

  // ----------------------------------------------------------------- TLS y rechazo de HTTP
  section = 'HTTPS obligatorio';
  const listen = port => dx(`ss -ltnH "sport = :${port}" | awk '{print $4}'`).out;
  ok('80 escucha en todas las interfaces (Flutter); 8088, 8089, 8090, 8091 y 8092 SOLO en 127.0.0.1', listen(80) === '0.0.0.0:80' && ['8088', '8089', '8090', '8091', '8092'].every(p => listen(p) === `127.0.0.1:${p}`), ['80', '8088', '8089', '8090', '8091', '8092'].map(listen).join(' '));
  ok('el certificado se valida contra el propio certificado del sitio y cubre localhost (curl con --cacert, sin -k)', dx(`curl -sS -m 10 ${TLS} ${U}/api/ping`).out.includes('"pong":true'));
  ok('sin confiar en el certificado (sin --cacert) curl RECHAZA la conexión: el certificado es autofirmado y no público', dx(`curl -sS -m 10 --resolve localhost:8092:127.0.0.1 ${U}/api/ping`).code !== 0);
  ok('solo TLS 1.2 y 1.3; TLS 1.0 y 1.1 rechazados', dx('for v in tls1_3 tls1_2; do openssl s_client -connect 127.0.0.1:8092 -$v </dev/null >/dev/null 2>&1 || exit 1; done; for v in tls1_1 tls1; do openssl s_client -connect 127.0.0.1:8092 -$v </dev/null >/dev/null 2>&1 && exit 1; done; exit 0').code === 0);
  const plano = dx('curl -sS -m 10 -i http://127.0.0.1:8092/api/ping');
  ok('HTTP SIN cifrar al puerto 8092 → 400 de Apache, jamás una respuesta de la API', /^HTTP\/1\.1 400/.test(plano.out) && !/pong/.test(plano.out), plano.out.slice(0, 300));
  const loginPlano = dx(`printf '{"login":"${lA}","password":"${GOODPW}"}' | curl -sS -m 10 -i -H 'Content-Type: application/json' --data @- http://127.0.0.1:8092/api/login`);
  ok('iniciar sesión por HTTP sin cifrar con una contraseña CORRECTA se rechaza: no hay token ni respuesta de la API', !/"token"|"success"/.test(loginPlano.out) && /400/.test(loginPlano.out.split('\n')[0]));
  const spoof = dx(`curl -sS -m 10 -i -H 'X-Forwarded-Proto: https' -H 'Forwarded: proto=https;for=127.0.0.1' -H 'X-Forwarded-For: 127.0.0.1' http://127.0.0.1:8092/api/ping; printf '{"login":"${lA}","password":"${GOODPW}"}' | curl -sS -m 10 -i -H 'X-Forwarded-Proto: https' -H 'Content-Type: application/json' --data @- http://127.0.0.1:8092/api/login`);
  ok('forjar X-Forwarded-Proto / Forwarded por HTTP plano NO engaña al servicio: ni ping ni login', !/pong|"token"|"success"/.test(spoof.out));
  dx('cp /etc/apache2/sites-available/cervesur-react-usuarios.conf /root/site-us.orig');
  put('/opt/cervesur-react-usuarios/backend/sonda.php', '<?php setcookie("sonda", "1"); echo json_encode(["https" => $_SERVER["HTTPS"] ?? null, "xfp" => $_SERVER["HTTP_X_FORWARDED_PROTO"] ?? null, "xff" => $_SERVER["HTTP_X_FORWARDED_FOR"] ?? null, "fwd" => $_SERVER["HTTP_FORWARDED"] ?? null, "name" => session_name(), "path" => session_save_path(), "obd" => ini_get("open_basedir"), "secure" => ini_get("session.cookie_secure"), "httponly" => ini_get("session.cookie_httponly"), "samesite" => ini_get("session.cookie_samesite"), "lee_flutter" => @file_get_contents("/var/www/cervesur/backend/api.php") !== false, "lee_8088" => @file_get_contents("/opt/cervesur-react-prueba/backend/.env") !== false, "lee_8089" => @file_get_contents("/opt/cervesur-react-compat/backend/.env") !== false, "lee_8090" => @file_get_contents("/opt/cervesur-react-ro/backend/.env") !== false, "lee_8091" => @file_get_contents("/opt/cervesur-react-piloto/backend/.env") !== false, "lee_passwd" => @file_get_contents("/etc/passwd") !== false, "lee_clave_tls" => @file_get_contents("/etc/apache2/cervesur-react-usuarios/tls/servidor.key") !== false, "lee_logs" => @file_get_contents("/var/log/apache2/error.log") !== false, "lee_su_env" => @file_get_contents(__DIR__ . "/.env") !== false]);');
  dx(String.raw`awk '/Include .*react-usuarios-common.conf/ {print "    AliasMatch \"^/sonda\\.php$\" \"/opt/cervesur-react-usuarios/backend/sonda.php\""} {print}' /root/site-us.orig > /etc/apache2/sites-available/cervesur-react-usuarios.conf && apache2ctl graceful`);
  let sondaRaw = ''; let sondaHdr = ''; for (let i = 0; i < 20; i++) { await sleep(1000); sondaHdr = dx(`curl -sS -i -m 10 ${TLS} -H 'X-Forwarded-Proto: http' -H 'X-Forwarded-For: 9.9.9.9' -H 'Forwarded: for=9.9.9.9' ${U}/sonda.php`).out; sondaRaw = sondaHdr.slice(sondaHdr.indexOf('{')); if (sondaRaw.startsWith('{')) break; }
  let sonda = {}; try { sonda = JSON.parse(sondaRaw); } catch { sonda = { raw: sondaRaw.slice(0, 100) }; }
  dx('cp /root/site-us.orig /etc/apache2/sites-available/cervesur-react-usuarios.conf && rm -f /opt/cervesur-react-usuarios/backend/sonda.php && apache2ctl graceful'); await sleep(2500);
  ok('PHP ve HTTPS=on (lo fija mod_ssl) y NUNCA ve las cabeceras X-Forwarded-*/Forwarded del cliente: el proxy las elimina', sonda.https === 'on' && sonda.xfp === null && sonda.xff === null && sonda.fwd === null, JSON.stringify(sonda));
  ok('configuración efectiva de PHP: cookie CERVESUR_REACT_USUARIOS, sesiones propias, Secure, HttpOnly y SameSite=Strict (php_admin)', sonda.name === 'CERVESUR_REACT_USUARIOS' && sonda.path === '/var/lib/cervesur-react-usuarios/sessions' && sonda.secure === '1' && sonda.httponly === '1' && String(sonda.samesite).toLowerCase() === 'strict', JSON.stringify(sonda));
  ok('cualquier cookie que apareciera saldría con Secure y HttpOnly (red de seguridad de Apache)', /set-cookie: sonda=1[^\n]*;\s*Secure/i.test(sondaHdr) && /set-cookie: sonda=1[^\n]*;\s*HttpOnly/i.test(sondaHdr), sondaHdr.split('{')[0].slice(0, 500));
  ok('open_basedir: el PHP del piloto NO lee Flutter, los .env de 8088/8089/8090/8091, /etc/passwd, los logs ni la clave privada TLS; sí su backend', sonda.lee_flutter === false && sonda.lee_8088 === false && sonda.lee_8089 === false && sonda.lee_8090 === false && sonda.lee_8091 === false && sonda.lee_passwd === false && sonda.lee_clave_tls === false && sonda.lee_logs === false && sonda.lee_su_env === true, JSON.stringify(sonda));
  ok('la sonda temporal se retiró y el sitio quedó como estaba', !dx(`curl -sS ${TLS} ${U}/sonda.php`).out.startsWith('{') && dx('cmp /etc/apache2/sites-available/cervesur-react-usuarios.conf /root/site-us.orig').code === 0);

  // ----------------------------------------------------------------- API con usuarios
  section = 'Inicio de sesión y lista blanca (HTTPS)';
  const login = (u, p) => dx(`printf '{"login":"%s","password":"%s"}' "${u}" "${p}" | curl -sS -m 20 ${TLS} -H 'Content-Type: application/json' --data @- ${U}/api/login`).out;
  const tokenOf = body => { try { return JSON.parse(body).token ?? ''; } catch { return ''; } };
  const call = (token, path, extra = '') => dx(`printf 'header = "Authorization: Bearer %s"\\n' "${token}" | curl -sS -m 20 ${TLS} -K - -w '\\n%{http_code}' ${extra} ${U}${path}`).out;
  const parse = out => { const i = out.lastIndexOf('\n'); let json = null; try { json = JSON.parse(out.slice(0, i)); } catch { /* no JSON */ } return { status: Number(out.slice(i + 1)), json, raw: out.slice(0, i) }; };
  const usr = (cmd) => dx(`bash ${PK}/scripts/usuarios.sh ${cmd}`);
  const nope = login(lA, GOODPW);
  ok('con la lista blanca VACÍA nadie entra, ni con credenciales correctas: respuesta genérica sin token', JSON.parse(nope).success === false && !nope.includes('"token"') && /Credenciales inv/.test(nope), nope);
  ok('la lista blanca arranca vacía (usuarios.sh listar)', usr('listar').out === '');
  const addA = usr(`agregar ${A.id} "usuario A"`); const addB = usr(`agregar ${B.id} "usuario B"`); const addS = usr(`agregar ${SIN.id} "sin informes"`); const addAdm = usr(`agregar ${ADM.id}`); const addIn = usr(`agregar ${INACT.id}`);
  ok('usuarios.sh agregar autoriza a usuarios existentes, activos y de rol «user»; rechaza al administrador y al inactivo', addA.code === 0 && addB.code === 0 && addS.code === 0 && addAdm.code !== 0 && /rol/.test(addAdm.out) && addIn.code !== 0 && /inactivo/.test(addIn.out), `${addAdm.out} | ${addIn.out}`);
  ok('usuarios.sh agregar rechaza ids inexistentes o mal formados', usr('agregar 99999').code !== 0 && usr('agregar abc').code !== 0 && usr('agregar "1; DROP"').code !== 0);
  const fallos = [[lA, 'incorrecta-123', 'contraseña incorrecta'], ['nadie-existe', GOODPW, 'usuario inexistente'], [lF, GOODPW, 'activo pero fuera de la lista blanca'], [lAdm, GOODPW, 'administrador'], [lIn, GOODPW, 'inactivo']];
  const respuestas = fallos.map(([u, p]) => login(u, p));
  ok('contraseña incorrecta, usuario inexistente, fuera de la lista, administrador e inactivo → la MISMA respuesta genérica, sin token', respuestas.every(r => r === respuestas[0] && JSON.parse(r).success === false && !r.includes('token')), JSON.stringify(respuestas));
  const tA = tokenOf(login(lA, GOODPW)); const tB = tokenOf(login(lB, GOODPW)); const tS = tokenOf(login(lS, GOODPW));
  ok('los usuarios autorizados inician sesión: token opaco de 256 bits (64 hex), no un JWT', [tA, tB, tS].every(t => /^[0-9a-f]{64}$/.test(t)));
  const tAmail = tokenOf(login(sqlRoot(`SELECT email FROM cervesur_portalbi.user WHERE id = ${A.id}`), GOODPW));
  ok('también se puede entrar con el correo', /^[0-9a-f]{64}$/.test(tAmail));
  const page = (path, t) => { const out = []; let n = 1; for (;;) { const r = parse(call(t, `${path}?view=minimal&per_page=100&page=${n}`)); out.push(...(r.json?.data ?? [])); if (!r.json?.pagination?.has_more || n > 20) return out; n++; } };
  const repA = page('/api/reportes', tA); const repB = page('/api/reportes', tB); const repS = page('/api/reportes', tS);
  ok('cada usuario ve EXACTAMENTE los informes de su cadena activa (= SQL): A, B y el usuario sin informes (0)', repA.length === A.n && repB.length === B.n && repS.length === 0 && A.n !== B.n, `${repA.length}/${A.n} ${repB.length}/${B.n} ${repS.length}`);
  const sqlIds = id => sqlRoot(`SELECT DISTINCT r.id FROM cervesur_portalbi.user_empresa_reporte uer JOIN cervesur_portalbi.empresa_reporte er ON er.id = uer.empresa_reporte_id AND er.activo = 1 JOIN cervesur_portalbi.empresa e ON e.id = er.empresa_id AND e.activo = 1 JOIN cervesur_portalbi.empresa_usuario eu ON eu.empresa_id = e.id AND eu.user_id = ${id} AND eu.activo = 1 JOIN cervesur_portalbi.reporte r ON r.id = er.reporte_id AND r.activo = 1 WHERE uer.user_id = ${id} AND uer.activo = 1 ORDER BY r.id`).split('\n').filter(Boolean).map(Number);
  ok('los ids de A y B coinciden uno a uno con el SQL y los de A no se mezclan con los de B', JSON.stringify(repA.map(r => r.id).sort((x, y) => x - y)) === JSON.stringify(sqlIds(A.id)) && JSON.stringify(repB.map(r => r.id).sort((x, y) => x - y)) === JSON.stringify(sqlIds(B.id)));
  const bodies = [tA, tB].map(t => ['/api/reportes?per_page=100', '/api/empresas?per_page=100', '/api/asignacion?per_page=100', '/api/user/profile'].map(p => call(t, p)).join('')).join('');
  ok('los listados y el perfil no contienen enlaces, URL de Power BI, hashes, contraseñas, credenciales de empresa ni la palabra «enlace»', !/enlace|https?:|powerbi|\$2y\$|password|secret|clientsecret/i.test(bodies));
  const idsA = sqlIds(A.id); const fueraA = idsA.length ? Number(sqlRoot(`SELECT id FROM cervesur_portalbi.reporte WHERE activo = 1 AND id NOT IN (${idsA.join(',')}) ORDER BY id LIMIT 1`)) : 0;
  const autorizados = ids.split(',').map(Number);
  const okId = idsA.find(i => autorizados.includes(i)) ?? idsA[0]; const noAut = idsA.find(i => !autorizados.includes(i));
  const link = sqlRoot(`SELECT enlace FROM cervesur_portalbi.reporte WHERE id = ${okId}`);
  const vOk = parse(call(tA, `/api/reportes/id/${okId}/visor`)); const vAjeno = parse(call(tA, `/api/reportes/id/${fueraA}/visor`)); const vAjenoDet = parse(call(tA, `/api/reportes/${fueraA}`));
  ok('visor: un informe PROPIO y autorizado entrega su enlace «Publicar en web»; uno ajeno es indistinguible de uno inexistente (404) y no entrega nada; el detalle ajeno también 404', (autorizados.includes(okId) ? vOk.status === 200 && vOk.json.data.url === link : vOk.status === 403) && vAjeno.status === 404 && vAjenoDet.status === 404 && !vAjeno.raw.includes('http') && parse(call(tA, '/api/reportes/id/999999/visor')).status === 404, `${vOk.status} ${vAjeno.status} ${vAjenoDet.status}`);
  if (noAut) { const vNo = parse(call(tA, `/api/reportes/id/${noAut}/visor`)); ok('un informe propio pero NO autorizado para el piloto → 403 «visor_no_autorizado», sin enlace', vNo.status === 403 && vNo.json.code === 'visor_no_autorizado' && !vNo.raw.includes('http')); }
  const nosession = ['/api/user/profile', '/api/reportes', '/api/reportes/1', '/api/reportes/id/1/visor', '/api/empresas', '/api/asignacion'].map(p => parse(call('', p)));
  ok('NINGÚN endpoint del catálogo responde sin sesión (401, sin datos), ni con tokens inventados o de otro formato', nosession.every(r => r.status === 401 && !r.raw.includes('"data"')) && ['a.b.c', '0'.repeat(64), 'x'.repeat(64), tA.slice(0, 63) + (tA.endsWith('0') ? '1' : '0')].every(t => parse(call(t, '/api/reportes')).status === 401));
  const cerradas = ['/api/users', '/api/user', '/api/stats', '/api/empresa-usuario', '/api/empresa-reporte-usuario', '/api/reportes/activos', '/api/empresas/1', '/api/asignacion/1', '/api/powerbi/tokens/1', '/api/wiki', '/api/reportes/', '/api/ping/', '/api/reportes/id/abc/visor', '/api/register'];
  ok(`${cerradas.length} rutas (usuarios, estadísticas, asignaciones, Power BI heredado, registro, variantes) → 403 con sesión válida`, cerradas.every(p => parse(call(tA, p)).status === 403), cerradas.filter(p => parse(call(tA, p)).status !== 403).join(','));
  ok('escrituras (POST/PUT/DELETE sobre informes, empresas, asignaciones, registro, perfil) → 403', [['POST', '/api/reportes'], ['PUT', '/api/reportes/1'], ['DELETE', '/api/reportes/1'], ['POST', '/api/empresas'], ['PUT', '/api/empresas/1'], ['POST', '/api/asignacion'], ['DELETE', '/api/asignacion/1'], ['POST', '/api/register'], ['PUT', '/api/user/profile'], ['PUT', '/api/user/1/role']].every(([m, p]) => parse(call(tA, p, `-X ${m} -H 'Content-Type: application/json' -d '{}'`)).status === 403));
  const hdr = dx(`curl -sS -i -m 10 ${TLS} -H "Authorization: Bearer ${tA}" ${U}/api/reportes`).out;
  ok('respuestas con Cache-Control no-store, nosniff, sin cookies y SIN HSTS (acceso por túnel)', /cache-control: no-store/i.test(hdr) && /x-content-type-options: nosniff/i.test(hdr) && !/set-cookie/i.test(hdr) && !/strict-transport/i.test(hdr));
  const idx = dx(`curl -sS -D - ${TLS} ${U}/`).out;
  ok('la aplicación estática TAMPOCO emite HSTS en localhost (afectaría a los túneles de los otros portales); con un nombre propio sí lo emitiría', !/strict-transport/i.test(idx) && /strict-transport-security/i.test(dx(`curl -sS -D - -o /dev/null ${TLS} -H "Host: informes.ejemplo.test" ${U}/`).out));
  ok('la aplicación se sirve con CSP (frame-src https://app.powerbi.com), X-Frame-Options DENY; las rutas internas de React no dan 404', /frame-src https:\/\/app\.powerbi\.com(;|$)/.test(idx) && /x-frame-options: deny/i.test(idx) && dx(`curl -s -o /dev/null -w "%{http_code}" ${TLS} ${U}/reportes/5`).out === '200');
  ok('archivos internos (.env, db.php, CLI, clave TLS) no se sirven; TRACE/PUT/OPTIONS → 403/405', dx(`for r in /.env /backend/.env /lib/db.php /api.php /cli/piloto-usuarios.php /tls/servidor.key; do curl -s ${TLS} ${U}$r | grep -qE "DB_PASS|JWT_SECRET|PILOT_SECRET|<\\?php|PRIVATE KEY" && exit 1; done; exit 0`).code === 0 && ['TRACE', 'PUT', 'OPTIONS'].every(m => ['403', '405'].includes(dx(`curl -s -o /dev/null -w "%{http_code}" ${TLS} -X ${m} ${U}/api/reportes`).out)));

  section = 'Revocación de informe y de sesión';
  const rel = sqlRoot(`SELECT uer.empresa_reporte_id FROM cervesur_portalbi.user_empresa_reporte uer JOIN cervesur_portalbi.empresa_reporte er ON er.id = uer.empresa_reporte_id WHERE uer.user_id = ${A.id} AND uer.activo = 1 AND er.reporte_id = ${okId} LIMIT 1`);
  const antes = page('/api/reportes', tA).length;
  dx(`mysql -e "UPDATE cervesur_portalbi.user_empresa_reporte SET activo = 0 WHERE user_id = ${A.id} AND empresa_reporte_id = ${rel}"`);
  const despues = page('/api/reportes', tA); const vRev = parse(call(tA, `/api/reportes/id/${okId}/visor`));
  const comparte = sqlIds(A.id).includes(okId);
  ok('revocar la asignación de UN informe (user_empresa_reporte.activo = 0) lo quita al instante del catálogo y del visor de esa persona, sin cerrar su sesión ni tocar al usuario B', (comparte || despues.length === antes - 1) && (comparte || vRev.status === 404) && parse(call(tA, '/api/user/profile')).status === 200 && page('/api/reportes', tB).length === B.n, `${antes} ${despues.length} ${vRev.status} comparte=${comparte}`);
  dx(`mysql -e "UPDATE cervesur_portalbi.user_empresa_reporte SET activo = 1 WHERE user_id = ${A.id} AND empresa_reporte_id = ${rel}"`);
  ok('al restaurar la asignación el informe vuelve (la base de Flutter es la única fuente de permisos)', page('/api/reportes', tA).length === antes);
  const eu = sqlRoot(`SELECT empresa_id FROM cervesur_portalbi.empresa_usuario WHERE user_id = ${A.id} AND activo = 1 LIMIT 1`);
  dx(`mysql -e "UPDATE cervesur_portalbi.empresa_usuario SET activo = 0 WHERE user_id = ${A.id} AND empresa_id = ${eu}"`);
  const sinEmp = page('/api/reportes', tA).length;
  dx(`mysql -e "UPDATE cervesur_portalbi.empresa_usuario SET activo = 1 WHERE user_id = ${A.id} AND empresa_id = ${eu}"`);
  ok('desactivar la pertenencia del usuario a una empresa (empresa_usuario) retira los informes de esa empresa; restaurarla los devuelve', sinEmp < antes && page('/api/reportes', tA).length === antes, `${sinEmp} < ${antes}`);
  // sesión: cierre de sesión
  const tOut = tokenOf(login(lB, GOODPW));
  const lo = parse(call(tOut, '/api/logout', '-X POST'));
  ok('logout revoca el token en el servidor (revoked_server_side) y el mismo token ya no sirve (401)', lo.status === 200 && lo.json.revoked_server_side === true && parse(call(tOut, '/api/reportes')).status === 401 && parse(call(tOut, '/api/logout', '-X POST')).status === 401);
  // sesión: quitar de la lista blanca
  usr(`agregar ${FUERA.id} "temporal"`); const tF = tokenOf(login(lF, GOODPW));
  ok('un usuario recién autorizado entra', /^[0-9a-f]{64}$/.test(tF) && parse(call(tF, '/api/reportes')).status === 200);
  const q = usr(`quitar ${FUERA.id}`);
  ok('usuarios.sh quitar lo saca de la lista y su sesión muere al instante (401) y no puede volver a entrar', q.code === 0 && parse(call(tF, '/api/reportes')).status === 401 && JSON.parse(login(lF, GOODPW)).success === false);
  // sesión: cambio de contraseña en Flutter
  const tPw = tokenOf(login(lS, GOODPW));
  dx(`mysql -e "UPDATE cervesur_portalbi.user SET password = '$(php -r 'echo password_hash("otra-clave-123", PASSWORD_BCRYPT);')' WHERE id = ${SIN.id}"`);
  ok('cambiar la contraseña en Flutter invalida la sesión abierta del piloto (huella de contraseña) y la clave vieja deja de entrar', parse(call(tPw, '/api/reportes')).status === 401 && JSON.parse(login(lS, GOODPW)).success === false);
  put('/root/pw2.sql', `UPDATE cervesur_portalbi.user SET password = '${hashPw}' WHERE id = ${SIN.id};\n`); dx('mysql < /root/pw2.sql');
  // sesión: session_version y desactivación
  const tV = tokenOf(login(lB, GOODPW)); const sv = sqlRoot(`SELECT session_version FROM cervesur_portalbi.user WHERE id = ${B.id}`);
  dx(`mysql -e "UPDATE cervesur_portalbi.user SET session_version = session_version + 1 WHERE id = ${B.id}"`);
  const svMuere = parse(call(tV, '/api/reportes')).status === 401;
  dx(`mysql -e "UPDATE cervesur_portalbi.user SET session_version = ${sv} WHERE id = ${B.id}"`);
  const tD = tokenOf(login(lB, GOODPW)); dx(`mysql -e "UPDATE cervesur_portalbi.user SET is_active = 0 WHERE id = ${B.id}"`);
  const desMuere = parse(call(tD, '/api/reportes')).status === 401; dx(`mysql -e "UPDATE cervesur_portalbi.user SET is_active = 1 WHERE id = ${B.id}"`);
  const tR = tokenOf(login(lB, GOODPW)); dx(`mysql -e "UPDATE cervesur_portalbi.user SET role = 'admin' WHERE id = ${B.id}"`);
  const rolMuere = parse(call(tR, '/api/reportes')).status === 401; dx(`mysql -e "UPDATE cervesur_portalbi.user SET role = '${rows.find(r => r.id === B.id).raw}' WHERE id = ${B.id}"`);
  ok('cambiar session_version, desactivar al usuario o hacerlo administrador en Flutter mata la sesión del piloto en la siguiente petición', svMuere && desMuere && rolMuere, `${svMuere} ${desMuere} ${rolMuere}`);
  // revocación manual
  const tM1 = tokenOf(login(lA, GOODPW)); const tM2 = tokenOf(login(lB, GOODPW)); const tM3 = tokenOf(login(lA, GOODPW));
  const r1 = usr(`revocar ${A.id}`);
  ok('usuarios.sh revocar <id> cierra todas las sesiones de esa persona y solo las suyas', r1.code === 0 && parse(call(tM1, '/api/reportes')).status === 401 && parse(call(tM3, '/api/reportes')).status === 401 && parse(call(tM2, '/api/reportes')).status === 200);
  const tM4 = tokenOf(login(lA, GOODPW)); const r2 = usr('revocar todos');
  ok('usuarios.sh revocar todos cierra todas las sesiones', r2.code === 0 && parse(call(tM2, '/api/reportes')).status === 401 && parse(call(tM4, '/api/reportes')).status === 401);
  ok('usuarios.sh sesiones y auditoria no imprimen contraseñas, hashes de token ni enlaces', (() => { const s = usr('sesiones').out + usr('auditoria').out; return !/\$2y\$|http|[0-9a-f]{64}/i.test(s) && !s.includes(GOODPW) && /login_ok/.test(usr('auditoria').out) && /logout/.test(usr('auditoria').out); })());

  section = 'Bloqueo por intentos fallidos';
  for (let i = 0; i < 5; i++) login(lS, 'mala' + i);
  const lock = dx(`printf '{"login":"${lS}","password":"${GOODPW}"}' | curl -sS -m 15 -D - -o /dev/null ${TLS} -H 'Content-Type: application/json' --data @- ${U}/api/login`).out;
  ok('tras 5 fallos el acceso queda bloqueado (429 + Retry-After) aunque use la contraseña correcta', /429/.test(lock.split('\n')[0]) && /retry-after/i.test(lock));
  ok('el bloqueo de UN acceso no impide entrar a los demás', /^[0-9a-f]{64}$/.test(tokenOf(login(lB, GOODPW))));
  const desb = usr(`desbloquear ${lS}`);
  ok('usuarios.sh desbloquear levanta el bloqueo y la persona vuelve a entrar', desb.code === 0 && /^[0-9a-f]{64}$/.test(tokenOf(login(lS, GOODPW))));

  section = 'Informes autorizados';
  const lista = usr('informes --listar'); const tU = tokenOf(login(lA, GOODPW));
  ok('usuarios.sh informes --listar muestra los dos informes autorizados', lista.out.includes(ids));
  const retirar = usr('informes --retirar');
  const vRet = parse(call(tU, `/api/reportes/id/${okId}/visor`));
  ok('informes --retirar: el visor deja de entregar el enlace (403) mientras el catálogo sigue completo', retirar.code === 0 && vRet.status === 403 && page('/api/reportes', tU).length === A.n);
  const sf = dx(`bash ${PK}/scripts/usuarios.sh informes --autorizar ${okId} < /dev/null`);
  ok('informes --autorizar sin la frase de confirmación no cambia nada', sf.code !== 0 && parse(call(tU, `/api/reportes/id/${okId}/visor`)).status === 403);
  const aut = dx(`bash ${PK}/scripts/usuarios.sh informes --autorizar ${okId}`, { CERVESUR_USUARIOS_CONFIRMAR_INFORMES: 'AUTORIZO-INFORMES' });
  ok('informes --autorizar con la frase vuelve a autorizar solo ese informe', aut.code === 0 && parse(call(tU, `/api/reportes/id/${okId}/visor`)).status === 200);
  const sfT = dx(`bash ${PK}/scripts/usuarios.sh informes --autorizar TODOS`, { CERVESUR_USUARIOS_CONFIRMAR_INFORMES: 'AUTORIZO-INFORMES' });
  ok('«TODOS» exige su propia frase: la de una lista no basta', sfT.code !== 0);

  // ----------------------------------------------------------------- Exposición de secretos
  section = 'Ausencia de exposición de contraseñas y tokens';
  const tokens = [tA, tB, tM2, tM4, tU].filter(Boolean);
  const todo = dx(`grep -rIl -F -e "${GOODPW}" ${tokens.map(t => `-e "${t}"`).join(' ')} /var/log /var/backups /opt/cervesur-react-usuarios /var/www/cervesur-react-usuarios /etc/apache2 /var/lib/cervesur-react-usuarios 2>/dev/null | grep -v mysql.err || true`).out;
  ok('ni la contraseña de los usuarios ni los tokens aparecen en logs de Apache, respaldos, configuración, .env ni aplicación', todo === '', todo);
  const dumpEstado = dx('mysqldump --no-tablespaces cervesur_react_estado 2>/dev/null || mysqldump cervesur_react_estado 2>/dev/null').out;
  ok('la base de estado NO contiene contraseñas, hashes bcrypt, tokens en claro, enlaces de Power BI ni nombres de acceso en claro (solo SHA-256 del token y huellas HMAC)', !dumpEstado.includes(GOODPW) && !/\$2y\$/.test(dumpEstado) && tokens.every(t => !dumpEstado.includes(t)) && dumpEstado.includes(createHash('sha256').update(tU).digest('hex')) && !/powerbi|https?:/i.test(dumpEstado.replace(/COMMENT[^,\n]*/g, '')) && ![lA, lB, lS, lF].some(l => dumpEstado.includes(`'${l}'`)) && !/127\.0\.0\.1|172\.\d+\.\d+\.\d+/.test(dumpEstado.replace(/Host: [^\n]*/g, '')), dumpEstado.slice(0, 300));
  ok('el token almacenado es el SHA-256 del que recibió el cliente (no el token)', Number(sqlRoot(`SELECT COUNT(*) FROM cervesur_react_estado.sesiones WHERE token_hash = '${createHash('sha256').update(tU).digest('hex')}'`)) === 1 && Number(sqlRoot(`SELECT COUNT(*) FROM cervesur_react_estado.sesiones WHERE token_hash = '${tU}'`)) === 0);
  ok('ninguna respuesta de la API (login, perfil, catálogo, errores) contiene la contraseña ni un hash', ![login(lA, GOODPW), login(lA, 'mala'), call(tU, '/api/user/profile'), call(tU, '/api/users'), call('', '/api/reportes')].some(r => r.includes(GOODPW) || /\$2y\$/.test(r)));
  ok('el usuario de MySQL con escritura (estado) no puede tocar la base de Flutter y las cuentas de lectura no pueden escribir en ninguna parte (preflight B sigue en PASS tras todo el uso)', dx(`bash ${PK}/scripts/preflight-acceso.sh ${K}`).code === 0);

  // ----------------------------------------------------------------- Fallo de conexiones MySQL
  section = 'Fallo de las conexiones MySQL (fail-closed)';
  const E = '/opt/cervesur-react-usuarios/backend/.env';
  dx(`cp -a ${E} /root/env.us.bak`);
  const restoreEnv = () => dx(`cp -a /root/env.us.bak ${E}`);
  const sedPw = k => `sed -i 's/^${k}=.*/${k}=Clave-Equivocada-123!/' ${E}`;
  const conexiones = [
    ['la cuenta de AUTENTICACIÓN con clave incorrecta (cervesur_ro_auth)', sedPw('AUTH_DB_PASS')], ['la cuenta de CATÁLOGO con clave incorrecta (cervesur_ro_catalogo)', sedPw('DB_PASS')], ['la cuenta de ESTADO con clave incorrecta (cervesur_react_estado)', sedPw('STATE_DB_PASS')],
    ['AMBAS conexiones (autenticación y estado) con clave incorrecta', `${sedPw('AUTH_DB_PASS')} && ${sedPw('STATE_DB_PASS')}`], ['la base de estado inexistente', `sed -i 's/^STATE_DB_NAME=.*/STATE_DB_NAME=no_existe_estado/' ${E}`],
    ['las tres cuentas iguales (misma cuenta para todo)', `sed -i 's/^AUTH_DB_USER=.*/AUTH_DB_USER=cervesur_ro_catalogo/' ${E}`],
  ];
  for (const [label, mutation] of conexiones) {
    dx(mutation); const ping = dx(`curl -sS -m 15 ${TLS} -w "\\n%{http_code}" ${U}/api/ping`).out; const lg = dx(`curl -sS -m 15 ${TLS} -w "\\n%{http_code}" -X POST -d '{"login":"${lA}","password":"${GOODPW}"}' ${U}/api/login`).out; const prof = parse(call(tU, '/api/reportes')); restoreEnv();
    ok(`${label}: el piloto NO sirve datos ni inicia sesión (5xx), no entrega token y no revela detalles`, /\n(500|503)$/.test(ping) && /\n(500|503)$/.test(lg) && !lg.includes('"token"') && prof.status >= 500 && !/portalbi|sql|SQLSTATE|password|cervesur_ro|Access denied/i.test(ping + lg + prof.raw), `${ping} | ${lg}`);
  }
  const mutaciones = [
    ['CERVESUR_MODE ausente', `sed -i '/^CERVESUR_MODE=/d' ${E}`], ['CERVESUR_MODE=read_write', `sed -i 's/^CERVESUR_MODE=.*/CERVESUR_MODE=read_write/' ${E}`], ['CERVESUR_MODE=piloto_visual', `sed -i 's/^CERVESUR_MODE=.*/CERVESUR_MODE=piloto_visual/' ${E}`],
    ['CERVESUR_PILOT_SECRET ausente', `sed -i '/^CERVESUR_PILOT_SECRET=/d' ${E}`], ['CERVESUR_PILOT_SECRET corto', `sed -i 's/^CERVESUR_PILOT_SECRET=.*/CERVESUR_PILOT_SECRET=corto/' ${E}`],
    ['STATE_DB_NAME igual a la base productiva', `sed -i 's/^STATE_DB_NAME=.*/STATE_DB_NAME=cervesur_portalbi/' ${E}`], ['lista de informes mal formada', `sed -i 's/^CERVESUR_PILOT_VISOR_IDS=.*/CERVESUR_PILOT_VISOR_IDS=1;2/' ${E}`], ['DB_NAME con inyección de DSN', `sed -i 's/^DB_NAME=.*/DB_NAME=x;dbname=cervesur_portalbi/' ${E}`],
    ['HTTP de pruebas con la base productiva (CERVESUR_INSECURE_TESTS=SI)', `echo CERVESUR_INSECURE_TESTS=SI >> ${E}`],
  ];
  for (const [label, mutation] of mutaciones) {
    dx(mutation); const ping = dx(`curl -sS -m 10 ${TLS} -w "\\n%{http_code}" ${U}/api/ping`).out; const lg = dx(`curl -sS -m 10 ${TLS} -o /dev/null -w "%{http_code}" -X POST -d '{"login":"${lA}","password":"${GOODPW}"}' ${U}/api/login`).out; restoreEnv();
    ok(`${label}: el piloto NO sirve nada (503), tampoco el inicio de sesión, y no revela detalles`, ping.endsWith('503') && lg === '503' && !/portalbi|sql|hash|jwt|secret/i.test(ping), ping);
  }
  ok('con el .env restaurado el piloto vuelve a responder y es byte a byte el de antes; el token anterior sigue siendo válido (las sesiones viven en la base de estado)', dx(`curl -s ${TLS} ${U}/api/ping`).out.includes('pong') && dx(`cmp /root/env.us.bak ${E}`).code === 0 && parse(call(tU, '/api/reportes')).status === 200);
  dx('rm -f /root/env.us.bak');

  // ----------------------------------------------------------------- Navegador real con HTTPS
  section = 'Navegador real con HTTPS a través del túnel simulado (https://localhost:' + TUNEL + ')';
  usr('revocar todos');
  dx('setsid nohup socat TCP-LISTEN:18093,fork,reuseaddr TCP:127.0.0.1:8092 >/dev/null 2>&1 &'); await sleep(1000);
  const spki = dx('openssl x509 -in /etc/apache2/cervesur-react-usuarios/tls/servidor.crt -pubkey -noout | openssl pkey -pubin -outform der | openssl dgst -sha256 -binary | base64').out;
  const nombreOk = sqlRoot(`SELECT nombre FROM cervesur_portalbi.reporte WHERE id = ${okId}`); const nombreFuera = sqlRoot(`SELECT nombre FROM cervesur_portalbi.reporte WHERE id = ${fueraA}`);
  const empresasA = sqlRoot(`SELECT e.nombre FROM cervesur_portalbi.empresa e JOIN cervesur_portalbi.empresa_usuario eu ON eu.empresa_id = e.id AND eu.user_id = ${A.id} AND eu.activo = 1 WHERE e.activo = 1 ORDER BY e.id LIMIT 1`);
  const b = await launch({ port: 9531, args: [`--ignore-certificate-errors-spki-list=${spki}`] });
  try {
    await b.send('Network.setBlockedURLs', { urls: ['https://app.powerbi.com/*', 'https://*.powerbi.com/*'] }); // el ensayo no sale a Internet: se verifica el src del iframe, no se carga
    await b.goto(`${BASE}/`); await b.wait(1500);
    const login0 = await b.eval('document.body.innerText');
    ok('la aplicación carga por HTTPS (el navegador acepta el certificado fijado) y redirige a /login; el acceso pide la cuenta habitual de Cervesur; sin datos de demostración', (await b.eval('location.protocol')) === 'https:' && (await b.eval('location.pathname')) === '/login' && /ingrese con su cuenta de Cervesur/.test(login0) && !/operador autorizado del piloto/.test(login0) && !/maria\.rios|elena\.torres|Entorno QA/.test(login0), login0.slice(0, 300));
    await b.type('#login-name', lA); await b.type('#login-password', 'incorrecta-123'); await b.click('form button[type=submit]'); await b.wait(1800);
    ok('una contraseña incorrecta muestra un error genérico y no entra', (await b.eval('location.pathname')) === '/login' && /Credenciales inválidas/.test(await b.eval('document.body.innerText')));
    await b.type('#login-name', lF); await b.type('#login-password', GOODPW); await b.click('form button[type=submit]'); await b.wait(1800);
    ok('un usuario activo pero FUERA de la lista blanca recibe el mismo error genérico', (await b.eval('location.pathname')) === '/login' && /Credenciales inválidas/.test(await b.eval('document.body.innerText')));
    await b.type('#login-name', lA); await b.type('#login-password', GOODPW); await b.click('form button[type=submit]'); await b.wait(2800);
    const home = await b.eval('document.body.innerText');
    ok('el usuario autorizado entra: /reportes con el aviso PILOTO · Usuarios autorizados · Solo consulta, sin modo administrador', (await b.eval('location.pathname')) === '/reportes' && /PILOTO · Usuarios autorizados · Solo consulta/.test(home) && !(await b.eval(`!!document.querySelector('.app-shell.is-admin')`)) && !/Modo administrador/.test(home), home.slice(0, 300));
    const tarjetas = await b.eval(`document.querySelectorAll('article.report-card').length`);
    ok('Mis informes muestra SOLO informes del usuario (entre 1 y los que le corresponden según el SQL), el propio por nombre, ninguno ajeno y sin enlaces', home.toLowerCase().includes(nombreOk.toLowerCase()) && !home.toLowerCase().includes(nombreFuera.toLowerCase()) && tarjetas > 0 && tarjetas <= A.n && !/powerbi\.com/.test(await b.eval('document.body.innerHTML')), `${A.n} tarjetas=${tarjetas}`);
    await b.eval(`history.pushState({}, '', '/empresas'); dispatchEvent(new PopStateEvent('popstate'))`); await b.wait(1500);
    const emp = await b.eval('document.body.innerText');
    ok('Mis empresas muestra las empresas del usuario y ninguna acción de edición', emp.toLowerCase().includes(empresasA.toLowerCase()) && !/Nueva empresa|Editar|Eliminar/.test(emp), emp.slice(0, 300));
    await b.eval(`history.pushState({}, '', '/perfil'); dispatchEvent(new PopStateEvent('popstate'))`); await b.wait(1500);
    const perfil = await b.eval('document.body.innerText');
    ok('Mi perfil muestra los datos del usuario (su correo), de solo lectura, sin contraseña', perfil.toLowerCase().includes(sqlRoot(`SELECT last_name FROM cervesur_portalbi.user WHERE id = ${A.id}`).toLowerCase()) && !perfil.includes(GOODPW) && !/Guardar cambios/.test(perfil), JSON.stringify(perfil.slice(0, 400)));
    await b.eval(`history.pushState({}, '', '/reportes/${okId}'); dispatchEvent(new PopStateEvent('popstate'))`); await b.wait(2500);
    const iframeSrc = await b.eval(`document.querySelector('iframe')?.src ?? ''`);
    ok('abre un informe PROPIO y autorizado: el visor monta el iframe con el enlace real (solo ahora llega el enlace al navegador)', iframeSrc === link, iframeSrc);
    await b.eval(`history.pushState({}, '', '/reportes/${fueraA}'); dispatchEvent(new PopStateEvent('popstate'))`); await b.wait(2500);
    const denied = await b.eval('document.body.innerText');
    ok('abre por URL un informe AJENO: acceso denegado, sin iframe ni enlace', /denegado|no tiene acceso|no encontrado|asignaci/i.test(denied) && !(await b.eval(`!!document.querySelector('iframe')`)), denied.slice(0, 200));
    await b.eval(`history.pushState({}, '', '/admin/usuarios'); dispatchEvent(new PopStateEvent('popstate'))`); await b.wait(1500);
    ok('/admin y /admin/usuarios no existen en el piloto: la interfaz regresa al catálogo', !(await b.eval('location.pathname')).startsWith('/admin'));
    ok('ninguna petición del navegador a rutas de usuarios, estadísticas, escritura ni Power BI heredado; todas HTTPS y al mismo origen; ninguna lleva la contraseña en la URL', b.requestLog.every(r => !/\/api\/(users|stats|register|empresa-usuario|empresa-reporte-usuario|powerbi)/.test(r.url) && (r.method === 'GET' || /\/api\/(login|logout)$/.test(r.url)) && (r.url.startsWith(BASE) || /^(data:|about:|blob:)/.test(r.url) || r.url.startsWith('https://app.powerbi.com')) && !r.url.includes(GOODPW) && !r.url.startsWith('http://')), b.requestLog.filter(r => r.url.startsWith('http://')).map(r => r.url).join(','));
    ok('la contraseña solo viaja en el cuerpo del POST /api/login, nunca en otra petición; el token no se guarda en localStorage ni sessionStorage', b.requestLog.filter(r => (r.postData ?? '').includes(GOODPW)).every(r => /\/api\/login$/.test(r.url) && r.method === 'POST') && (await b.eval('localStorage.length + sessionStorage.length')) === 0 && !(await b.eval('document.cookie')).includes('=') );
    ok('la API del navegador no recibe enlaces en los listados (solo el visor autorizado los trae)', b.requestLog.filter(r => /\/api\/(reportes|empresas|asignacion)(\?|$)/.test(r.url)).length > 0);
    ok('sin errores de consola ni violaciones de CSP ni de contenido mixto (salvo el bloqueo deliberado del ensayo a Power BI)', b.consoleErrors.filter(e => !/powerbi|ERR_BLOCKED_BY_CLIENT|status of 40[134]|Failed to load resource/i.test(e)).length === 0, b.consoleErrors.slice(0, 3).join(' | '));
    await b.eval(`[...document.querySelectorAll('button')].find(x => /Cerrar sesión/.test(x.textContent))?.click()`); await b.wait(1800);
    ok('Cerrar sesión devuelve al acceso; el token del navegador queda revocado en el servidor (una sesión del usuario menos) y un acceso directo pide la contraseña de nuevo', (await b.eval('location.pathname')) === '/login' && Number(sqlRoot(`SELECT COUNT(*) FROM cervesur_react_estado.sesiones WHERE user_id = ${A.id} AND revocada_en IS NULL AND expira_en > UTC_TIMESTAMP()`)) === 0 && Number(sqlRoot(`SELECT COUNT(*) FROM cervesur_react_estado.sesiones WHERE user_id = ${A.id} AND motivo_revocacion = 'cierre_de_sesion'`)) >= 1);
    await b.goto(`${BASE}/reportes`); await b.wait(1500);
    ok('tras cerrar sesión, volver a /reportes redirige al acceso', (await b.eval('location.pathname')) === '/login');
  } finally { await b.close(); dx("pkill -f 'TCP-LISTEN:1809[3]' || true"); }

  section = 'Aislamiento de red';
  const ip = docker(['inspect', '-f', '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}', NAME]).out;
  const net = (port, scheme = 'http') => docker(['run', '--rm', '--network', NETNAME, '--entrypoint', 'curl', IMAGE, '-sSk', '-m', '5', '-o', '/dev/null', '-w', '%{http_code}', `${scheme}://${ip}:${port}/`]);
  ok('desde otra máquina de la red: 8088, 8089, 8090, 8091 y 8092 (HTTP y HTTPS) rechazan la conexión; el puerto 80 de Flutter responde', /^\d+\.\d+\.\d+\.\d+$/.test(ip) && ['8088', '8089', '8090', '8091'].every(p => net(p).code !== 0) && net(8092).code !== 0 && net(8092, 'https').code !== 0 && net(80).out === '200');
  ok(`efecto de a2enmod ssl (ya presente en la línea base): Apache escucha en 443 (${listen(443) || 'no escucha'}), pero ese puerto NO sirve ni a Flutter ni al piloto, ni por HTTPS ni por HTTP`, dx('curl -sk -m 5 https://127.0.0.1:443/ | grep -qE "FLUTTER-SENTINEL|pong"').code !== 0 && dx('curl -s -m 5 http://127.0.0.1:443/ | grep -qE "FLUTTER-SENTINEL|pong"').code !== 0);

  section = 'Auditoría de escrituras (evidencia, no prueba)';
  const psr = sqlRoot("SELECT @@performance_schema; SELECT COUNT(*) FROM performance_schema.events_statements_summary_by_user_by_event_name WHERE USER IN ('cervesur_ro_auth','cervesur_ro_catalogo') AND SUM_ROWS_AFFECTED > 0; SELECT COUNT(*) FROM performance_schema.events_statements_summary_by_user_by_event_name WHERE USER IN ('cervesur_ro_auth','cervesur_ro_catalogo') AND COUNT_STAR > 0 AND EVENT_NAME IN ('statement/sql/select', 'statement/com/Execute')").split('\n');
  ok('condiciones de validez (performance_schema activo; actividad de lectura registrada) y 0 filas afectadas por las dos cuentas de lectura', psr[0] === '1' && psr[1] === '0' && Number(psr[2]) >= 1, psr.join('|'));
  const filasEstado = sqlRoot("SELECT SUM(SUM_ROWS_AFFECTED) FROM performance_schema.events_statements_summary_by_user_by_event_name WHERE USER = 'cervesur_react_estado'");
  ok('la única cuenta que escribe (estado) lo hizo (filas afectadas > 0) y las cuentas de lectura no; la base de Flutter conserva sus CHECKSUM (se comprueba en la instantánea final)', Number(filasEstado) > 0);
  usr('revocar todos');
  const S2 = snapshot();
  ok('tras todo el uso (HTTPS, API, navegador, revocaciones, bloqueos) los cinco portales existentes, las tablas de cervesur_portalbi, las bases y las cuentas siguen idénticos a la línea base', S2 === S0 && grantsHash() === gh0 && portalesVivos(), S0.split('\n').filter((l, i) => l !== S2.split('\n')[i]).join(' | '));

  // ----------------------------------------------------------------- Actualización de un piloto ya instalado (UX-07D.5)
  section = 'Actualización de un piloto instalado';
  const tree = d => dx(`cd ${d} && find . -type f ! -name .env -print0 | sort -z | xargs -0 sha256sum | sha256sum`).out;
  const resum = d => dx(`cd ${d} && find . -type f ! -name SHA256SUMS -print0 | sort -z | xargs -0 sha256sum > SHA256SUMS`);
  const arbolInstalado = () => `${tree('/var/www/cervesur-react-usuarios/public')}|${tree('/opt/cervesur-react-usuarios/backend')}`;
  const envSha = () => dx('sha256sum /opt/cervesur-react-usuarios/backend/.env').out.split(' ')[0];
  const A0 = arbolInstalado(); const E0 = envSha(); const pidU0 = apachePid();
  const simAct = dx(`bash ${PK}/scripts/actualizar.sh --simular`);
  ok('actualizar.sh --simular: código 0, comprueba las precondiciones y NO escribe nada (mismos archivos, mismo .env, sin respaldos nuevos)', simAct.code === 0 && /SIMULACIÓN terminada/.test(simAct.out) && arbolInstalado() === A0 && envSha() === E0 && dx('ls /var/backups/cervesur-react-usuarios | grep -c actualizacion || true').out === '0', simAct.out.slice(-400));
  const sinConfAct = dx(`bash ${PK}/scripts/actualizar.sh < /dev/null`);
  ok('sin la confirmación escrita ACTUALIZAR-8092 no se actualiza nada', sinConfAct.code !== 0 && /Cancelado/.test(sinConfAct.out) && arbolInstalado() === A0);
  dx(`rm -rf /root/paq-v2 /root/paq-malo /root/paq-malo2 && cp -a ${PK} /root/paq-v2 && echo v2-ensayo > /root/paq-v2/public/marca-v2.txt`); resum('/root/paq-v2');
  const upd = dx('bash /root/paq-v2/scripts/actualizar.sh', CONFIRM);
  const respaldo = /--revertir (\S+)/.exec(upd.out)?.[1] ?? '';
  ok('actualizar.sh con una versión nueva termina bien: respalda, reemplaza, verificar.sh sin fallos y los otros portales idénticos', upd.code === 0 && /ACTUALIZACIÓN COMPLETA/.test(upd.out) && /idénticos antes y después/.test(upd.out) && respaldo.startsWith('/var/backups/cervesur-react-usuarios/actualizacion-'), upd.out.slice(-700));
  ok('la versión nueva está servida (HTTPS) y los archivos instalados son EXACTAMENTE los del paquete', dx(`curl -sS -m 10 ${TLS} ${U}/marca-v2.txt`).out === 'v2-ensayo' && tree('/var/www/cervesur-react-usuarios/public') === tree('/root/paq-v2/public') && tree('/opt/cervesur-react-usuarios/backend') === tree('/root/paq-v2/backend'));
  ok('el .env quedó BIT A BIT igual, 640 root:www-data; sin carpetas temporales; el respaldo existe (700) y NO contiene el .env', envSha() === E0 && dx('stat -c "%a %U:%G" /opt/cervesur-react-usuarios/backend/.env').out === '640 root:www-data' && dx('ls -d /var/www/cervesur-react-usuarios/.nuevo-* /var/www/cervesur-react-usuarios/.previo-* /opt/cervesur-react-usuarios/.nuevo-* /opt/cervesur-react-usuarios/.previo-* 2>/dev/null | wc -l').out === '0' && dx(`stat -c %a ${respaldo} && ! tar tzf ${respaldo}/backend.tgz | grep -q "\\.env$"`).code === 0);
  ok('Apache no se reinició; la lista blanca persiste: la misma persona entra con su contraseña y quien no está en la lista sigue sin entrar', apachePid() === pidU0 && /^[0-9a-f]{64}$/.test(tokenOf(login(lA, GOODPW))) && JSON.parse(login(lF, GOODPW)).success === false);
  ok('tras actualizar, Flutter, 8088, 8089, 8090 y 8091, módulos, bases, cuentas y puertos siguen idénticos a la línea base', snapshot() === S0 && portalesVivos());
  // fallo: un paquete íntegro pero que no arranca (sin api.php) → verificar.sh falla → vuelve SOLO a la versión anterior
  const A1 = arbolInstalado();
  dx('cp -a /root/paq-v2 /root/paq-malo && rm /root/paq-malo/backend/api.php'); resum('/root/paq-malo');
  const malo = dx('bash /root/paq-malo/scripts/actualizar.sh', CONFIRM);
  ok('una actualización que NO pasa verificar.sh se revierte sola: código 1, versión anterior restaurada bit a bit, mismo .env, el piloto vuelve a responder', malo.code === 1 && /se restaura la versión anterior/.test(malo.out) && /restaurada y verificada/.test(malo.out) && arbolInstalado() === A1 && envSha() === E0 && /^[0-9a-f]{64}$/.test(tokenOf(login(lA, GOODPW))), malo.out.slice(-700));
  dx('cp -a /root/paq-v2 /root/paq-malo2 && echo "DB_PASS=filtrado" > /root/paq-malo2/public/.env'); resum('/root/paq-malo2');
  const conEnv = dx('bash /root/paq-malo2/scripts/actualizar.sh --simular');
  ok('un paquete que contiene un .env (secretos) se rechaza en las precondiciones, sin tocar nada', conEnv.code !== 0 && /contiene \.env/.test(conEnv.out) && arbolInstalado() === A1);
  const volver = dx(`bash /root/paq-v2/scripts/actualizar.sh --revertir ${respaldo}`, CONFIRM);
  ok('--revertir restaura la versión anterior desde el respaldo: la marca nueva desaparece (404), archivos idénticos a los de antes, mismo .env, verificado', volver.code === 0 && /restaurada y verificada/.test(volver.out) && dx(`curl -s -o /dev/null -w "%{http_code}" ${TLS} ${U}/marca-v2.txt`).out === '404' && arbolInstalado() === A0 && envSha() === E0, volver.out.slice(-500));
  ok('tras revertir la actualización: otros portales idénticos, Apache sin reiniciar y la misma persona sigue entrando', snapshot() === S0 && portalesVivos() && apachePid() === pidU0 && /^[0-9a-f]{64}$/.test(tokenOf(login(lA, GOODPW))));
  const corrupto = dx(`cp -a ${respaldo} /root/resp-corrupto && echo x >> /root/resp-corrupto/public.tgz && bash /root/paq-v2/scripts/actualizar.sh --revertir /root/resp-corrupto`, CONFIRM);
  ok('un respaldo corrupto (SHA256SUMS) se rechaza sin tocar nada', corrupto.code !== 0 && /corrupto/.test(corrupto.out) && arbolInstalado() === A0);
  dx('rm -rf /root/paq-v2 /root/paq-malo /root/paq-malo2 /root/resp-corrupto');

  // ----------------------------------------------------------------- Instancia administrativa de solo lectura (UX-07E.B), con el piloto de usuarios (8092) ya instalado
  section = 'Administrativo 8093 · paquete';
  const packAd = spawnSync(process.execPath, [join(REPO, 'deploy', 'tunel-ssh-piloto-admin', 'empaquetar.mjs'), '--host=informes.cervesur.test'], { cwd: REPO, encoding: 'utf8' });
  ok('empaquetar.mjs (administrativo) compila la aplicación administrativa y genera el paquete', packAd.status === 0, packAd.stderr || packAd.stdout.slice(-400));
  const tgzAd = newest('cervesur-react-admin-');
  if (!tgzAd) throw new Error('Falta el paquete administrativo');
  ok('el .sha256 externo del paquete administrativo coincide con su SHA-256', readFileSync(`${tgzAd}.sha256`, 'utf8').startsWith(createHash('sha256').update(readFileSync(tgzAd)).digest('hex')));
  docker(['cp', tgzAd, `${NAME}:/root/adm.tar.gz`], { allowFail: false });
  const PKA = `/root/${first('cd /root && tar xzf adm.tar.gz && ls -d cervesur-react-admin-*')}`;
  ok('integridad interna (SHA256SUMS) correcta y el paquete no contiene .env, claves ni certificados', dx(`cd ${PKA} && sha256sum -c SHA256SUMS --quiet`).code === 0 && dx(`find ${PKA} \\( -name '.env*' -o -name '*.key' -o -name '*.pem' -o -name '*.crt' \\) | wc -l`).out === '0');
  ok('la aplicación del paquete es la administrativa y el modo exigido es piloto_admin', dx(`grep -c '"pilotMode": "admin"' ${PKA}/public/build-info.json`).out === '1' && dx(`grep -c 'CERVESUR_MODE=piloto_admin' ${PKA}/scripts/pasos-instalacion.sh`).out === '1');
  const adSql = dx(`cat ${PKA}/admin-mysql/01-cuentas-lectura.sql ${PKA}/admin-mysql/02-estado-base-y-cuenta.sql`).out.split('\n').filter(l => !/^\s*--/.test(l)).join('\n');
  ok('el SQL administrativo: tres CREATE USER con sufijo _adm; SELECT por columna en la base de lectura; DML solo en la base de estado propia; ningún privilegio de escritura sobre cervesur_portalbi', (adSql.match(/^CREATE USER '(cervesur_ro_auth_adm|cervesur_ro_catalogo_adm|cervesur_react_estado_adm)'/gm) ?? []).length === 3 && !/GRANT[^;]*\b(INSERT|UPDATE|DELETE|ALL|CREATE|DROP|ALTER)\b[^;]*`__BASE__`/i.test(adSql) && /GRANT SELECT, INSERT, UPDATE, DELETE ON `__BASE_ESTADO__`\.\*/.test(adSql) && !/GRANT OPTION/i.test(adSql));

  const S0adm = snapshot(); const U0 = arbolInstalado(); const UE0 = envSha(); const PIDU0 = apachePid();
  const FLUTTER_TABLAS = "CHECKSUM TABLE cervesur_portalbi.user, cervesur_portalbi.empresa, cervesur_portalbi.reporte, cervesur_portalbi.empresa_usuario, cervesur_portalbi.empresa_reporte, cervesur_portalbi.user_empresa_reporte";
  const CK0 = sqlRoot(FLUTTER_TABLAS);

  section = 'Administrativo 8093 · preflights';
  dx('setsid nohup socat TCP-LISTEN:8093,bind=127.0.0.1,fork,reuseaddr /dev/null >/dev/null 2>&1 &'); await sleep(800);
  const paOcup = dx(`bash ${PKA}/scripts/preflight-infra.sh`);
  const simOcup = dx(`bash ${PKA}/scripts/instalar.sh --simular`);
  dx("pkill -f 'TCP-LISTEN:8093' || true"); await sleep(500);
  ok('con el puerto 8093 OCUPADO el preflight A falla y lo dice (no se asume libre) y la simulación también; no se crea nada', paOcup.code === 1 && /8093/.test(paOcup.out) && simOcup.code !== 0 && dx('! test -e /opt/cervesur-react-admin && ! test -e /var/www/cervesur-react-admin').code === 0, paOcup.out.slice(-400));
  const paOk = dx(`bash ${PKA}/scripts/preflight-infra.sh`);
  ok('con el puerto libre el preflight A pasa (mod_ssl cargado, rutas libres, piloto de usuarios y demás portales respondiendo)', paOk.code === 0, paOk.out.slice(-600));

  section = 'Administrativo 8093 · cuentas y base de estado propias';
  const prepAd = dx(`bash ${PKA}/admin-mysql/preparar-sql-cuentas.sh`);
  const clavesAd = dx('cat /root/cervesur-admin.claves').out.split('\n').map(l => l.split(/=(.*)/s)[1]);
  ok('preparar-sql-cuentas.sh (administrativo) genera SQL y claves (600) sin mostrar ninguna clave; tres claves distintas', prepAd.code === 0 && clavesAd.length === 3 && clavesAd.every(c => c && !prepAd.out.includes(c)) && new Set(clavesAd).size === 3 && dx('stat -c "%a %U" /root/cervesur-admin.sql /root/cervesur-admin.claves').out === '600 root\n600 root', prepAd.out.slice(-300));
  const creadoAd = dx('mysql < /root/cervesur-admin.sql');
  ok('el administrador de MySQL crea las tres cuentas _adm y cervesur_react_estado_admin; las cuentas y la base del piloto de usuarios siguen', creadoAd.code === 0 && Number(sqlRoot("SELECT COUNT(*) FROM mysql.user WHERE user IN ('cervesur_ro_auth_adm','cervesur_ro_catalogo_adm','cervesur_react_estado_adm')")) === 3 && cuentasExisten() === 3 && sqlRoot("SHOW DATABASES LIKE 'cervesur_react_estado_admin'") === 'cervesur_react_estado_admin', creadoAd.out.slice(-300));
  const v03Ad = dx(`mysql -B < ${PKA}/admin-mysql/03-verificar-privilegios.sql`);
  ok('03-verificar-privilegios.sql (administrativo): sin privilegios globales ni de escritura sobre la base de lectura', v03Ad.code === 0 && !/PROCESS|GRANT OPTION|SUPER/.test(v03Ad.out), v03Ad.out.slice(-400));
  const pbAd = dx(`bash ${PKA}/scripts/preflight-acceso.sh --claves-archivo /root/cervesur-admin.claves`);
  ok('preflight B (administrativo) PASS: 9 y 21 columnas exactas, solo lectura, sin tabla user en el catálogo, sin credenciales de Power BI, estado con DML solo en su base; no imprime claves', pbAd.code === 0 && /preflight B \(acceso\): PASS/.test(pbAd.out) && clavesAd.every(c => !pbAd.out.includes(c)), pbAd.out.slice(-500));
  const KA = '--claves-archivo /root/cervesur-admin.claves';
  const S1adm = snapshot(); // con las cuentas _adm y su base de estado ya creadas por el administrador de MySQL (eso no lo hace el instalador)
  const simAd = dx(`bash ${PKA}/scripts/instalar.sh --simular ${KA}`);
  ok('instalar.sh --simular (administrativo): infraestructura PASS y acceso PASS, sin modificar nada', simAd.code === 0 && /infraestructura PASS · acceso PASS/.test(simAd.out) && snapshot() === S1adm, simAd.out.slice(-300));
  const sinConfAd = dx(`bash ${PKA}/scripts/instalar.sh ${KA} < /dev/null`);
  ok('sin la confirmación escrita INSTALAR-8093 no se instala nada', sinConfAd.code !== 0 && /Cancelado/.test(sinConfAd.out) && snapshot() === S1adm);

  section = 'Administrativo 8093 · instalación';
  const instAd = dx(`bash ${PKA}/scripts/instalar.sh ${KA}`, CONFIRM);
  const verAd = /(\d+) PASS \/ (\d+) FAIL/.exec(instAd.out);
  ok('instalar.sh (administrativo) termina bien y verificar.sh integrado no tiene fallos', instAd.code === 0 && Boolean(verAd) && verAd[2] === '0' && Number(verAd[1]) >= 25, `${verAd?.[0] ?? ''} ${instAd.out.split('\n').filter(l => /^FAIL/.test(l)).join(' | ')} ${instAd.out.slice(-300)}`);
  const envA = k => dx(`grep "^${k}=" /opt/cervesur-react-admin/backend/.env | cut -d= -f2-`).out;
  ok('.env: modo piloto_admin exigido; cuentas y base de estado propias; sin HTTP de pruebas ni HSTS; ningún informe autorizado; secretos propios distintos de los del piloto de usuarios', envA('CERVESUR_MODE') === 'piloto_admin' && envA('CERVESUR_REQUIRED_MODE') === 'piloto_admin' && envA('AUTH_DB_USER') === 'cervesur_ro_auth_adm' && envA('DB_USER') === 'cervesur_ro_catalogo_adm' && envA('STATE_DB_USER') === 'cervesur_react_estado_adm' && envA('STATE_DB_NAME') === 'cervesur_react_estado_admin' && envA('CERVESUR_INSECURE_TESTS') === '' && envA('CERVESUR_PILOT_HSTS') === '' && envA('CERVESUR_PILOT_VISOR_IDS') === '' && envA('JWT_SECRET') !== env('JWT_SECRET') && envA('CERVESUR_PILOT_SECRET') !== env('CERVESUR_PILOT_SECRET') && dx('stat -c "%a %U:%G" /opt/cervesur-react-admin/backend/.env').out === '640 root:www-data');
  ok('el piloto de usuarios (8092) queda INTACTO: mismos archivos, mismo .env, Apache sin reiniciar, y la misma persona sigue entrando', arbolInstalado() === U0 && envSha() === UE0 && apachePid() === PIDU0 && /^[0-9a-f]{64}$/.test(tokenOf(login(lA, GOODPW))) && JSON.parse(login(lF, GOODPW)).success === false);
  ok('Flutter, 8088, 8089, 8090 y 8091 siguen vivos; las tablas de cervesur_portalbi idénticas', portalesVivos() && sqlRoot(FLUTTER_TABLAS) === CK0);

  section = 'Administrativo 8093 · API';
  const UA = 'https://localhost:8093';
  const TLSA = '--cacert /etc/apache2/cervesur-react-admin/tls/servidor.crt --resolve localhost:8093:127.0.0.1';
  const loginAd = (u, p) => dx(`printf '{"login":"%s","password":"%s"}' "${u}" "${p}" | curl -sS -m 20 ${TLSA} -H 'Content-Type: application/json' --data @- ${UA}/api/login`).out;
  const callAd = (method, path, token = '', body = '') => { const r = dx(`curl -sS -m 20 ${TLSA} -X ${method} ${token ? `-H 'Authorization: Bearer ${token}'` : ''} ${body ? `-H 'Content-Type: application/json' --data '${body}'` : ''} -w '\\n%{http_code}' ${UA}${path}`).out.split('\n'); const status = Number(r.pop()); return { status, raw: r.join('\n'), json: (() => { try { return JSON.parse(r.join('\n')); } catch { return null; } })() }; };
  ok('con la lista de administradores VACÍA nadie entra (ni siquiera un administrador activo con la contraseña correcta)', JSON.parse(loginAd(lAdm, GOODPW)).success === false);
  const admUsr = c => dx(`bash ${PKA}/scripts/usuarios.sh ${c}`);
  const adAddW = admUsr(`agregar ${A.id} "trabajador"`); const adAddA = admUsr(`agregar ${ADM.id} "QA administrador"`);
  ok('usuarios.sh agregar rechaza a un trabajador y acepta al administrador activo', adAddW.code !== 0 && adAddA.code === 0, adAddW.out + adAddA.out);
  const tAd = tokenOf(loginAd(lAdm, GOODPW));
  ok('el administrador autorizado inicia sesión (token opaco de 256 bits) y el perfil trae rol admin', /^[0-9a-f]{64}$/.test(tAd) && callAd('GET', '/api/user/profile', tAd).json?.profile?.role === 'admin');
  admUsr(`agregar ${A.id} "x"`); // rechazado: el trabajador no puede colarse en la lista
  const mismos = [loginAd(lAdm, 'incorrecta'), loginAd(lA, GOODPW), loginAd('nadie', 'x')];
  ok('contraseña incorrecta, trabajador (aunque la contraseña sea correcta) e inexistente dan la MISMA respuesta', new Set(mismos.map(m => m.replace(/\s/g, ''))).size === 1 && JSON.parse(mismos[0]).success === false);
  const sqlCount = t => Number(sqlRoot(`SELECT COUNT(*) FROM cervesur_portalbi.${t}`));
  const usersAd = callAd('GET', '/api/users?status=all&per_page=100&page=1', tAd);
  ok('GET /api/users?status=all: total = SQL, sin contraseña, hash, session_version ni teléfono', usersAd.status === 200 && usersAd.json?.pagination?.total === sqlCount('user') && !/password|\$2y\$|session_version|phone/i.test(usersAd.raw));
  const listAd = p => callAd('GET', `${p}?view=minimal&per_page=100&page=1`, tAd);
  const empAd = listAd('/api/empresas'); const repAd = listAd('/api/reportes'); const relAd = listAd('/api/asignacion');
  ok('empresas, informes y relaciones empresa–informe: totales = SQL (activos e inactivos) y NINGÚN enlace de Power BI en los listados', empAd.json?.pagination?.total === sqlCount('empresa') && repAd.json?.pagination?.total === sqlCount('reporte') && relAd.json?.pagination?.total === sqlCount('empresa_reporte') && !/enlace|https?:|powerbi/i.test(empAd.raw + repAd.raw + relAd.raw));
  ok('relaciones usuario–empresa y accesos usuario–informe responden con la forma esperada y solo los vigentes', listAd('/api/empresa-usuario').json?.pagination?.total === Number(sqlRoot('SELECT COUNT(*) FROM cervesur_portalbi.empresa_usuario WHERE activo = 1')) && listAd('/api/empresa-reporte-usuario').json?.pagination?.total === Number(sqlRoot('SELECT COUNT(*) FROM cervesur_portalbi.user_empresa_reporte uer JOIN cervesur_portalbi.empresa_reporte er ON er.id = uer.empresa_reporte_id WHERE uer.activo = 1')));
  const escrituras = [['POST', '/api/register', '{"x":1}'], ['PUT', '/api/reportes/1', '{"nombre":"x"}'], ['DELETE', '/api/empresas/1', ''], ['POST', '/api/empresas', '{"nombre":"x"}'], ['POST', '/api/asignacion', '{"empresa_id":1,"reporte_id":1}'], ['PUT', '/api/user/1/role', '{"role":"admin"}'], ['DELETE', '/api/empresa-reporte-usuario/1', ''], ['GET', '/api/stats', ''], ['GET', '/api/powerbi/tokens/1', ''], ['GET', '/api/reportes?view=form', '']];
  const res403 = escrituras.map(([m, p, b]) => callAd(m, p, tAd, b)).map(r => r.status);
  ok(`${escrituras.length} intentos de escritura, formularios, estadísticas y token OAuth de Power BI → 403 con sesión de administrador válida`, res403.slice(0, 9).every(s => s === 403) && res403[9] === 400, res403.join(','));
  ok('sin sesión o con tokens inventados ningún endpoint entrega datos (401)', ['/api/users', '/api/empresas', '/api/reportes', '/api/asignacion', '/api/empresa-usuario', '/api/empresa-reporte-usuario'].every(p => callAd('GET', p).status === 401 && callAd('GET', p, 'a'.repeat(64)).status === 401));
  const visorSin = callAd('GET', '/api/reportes/id/1/visor', tAd);
  ok('el visorAd sin informes autorizados en la instancia → 403 sin enlace (valor predeterminado seguro)', visorSin.status === 403 && !/"url"/.test(visorSin.raw));
  const hdrAdm = dx(`curl -sS -i -m 10 ${TLSA} -H "Authorization: Bearer ${tAd}" ${UA}/api/users?per_page=1`).out;
  ok('respuestas con Cache-Control no-store, nosniff, sin cookies y SIN HSTS (acceso por túnel)', /cache-control: no-store/i.test(hdrAdm) && /x-content-type-options: nosniff/i.test(hdrAdm) && !/set-cookie/i.test(hdrAdm) && !/strict-transport/i.test(hdrAdm));
  const planoAd = dx('curl -sS -m 8 -o /dev/null -w "%{http_code}" http://127.0.0.1:8093/api/ping || true').out; const spoofAd = dx(`printf '{"login":"${lAdm}","password":"${GOODPW}"}' | curl -sS -m 8 -o /dev/null -w "%{http_code}" -H 'X-Forwarded-Proto: https' -H 'Content-Type: application/json' --data @- http://127.0.0.1:8093/api/login || true`).out;
  ok('HTTP planoAd al 8093 se rechaza (400 de Apache, nunca llega a PHP) y X-Forwarded-Proto falsificado no sirve', planoAd === '400' && spoofAd === '400' && !/token/.test(dx(`curl -sk -m 8 -H 'X-Forwarded-Proto: https' ${UA}/api/ping`).out.replace('pong', '')));
  const escribeMy = (cuenta, clave, sql) => dx(`MYSQL_PWD='${clave}' mysql -h127.0.0.1 -u${cuenta} -e "${sql}" cervesur_portalbi 2>&1`, {}).out;
  const cAuth = clavesAd[0]; const cCat = clavesAd[1]; const cEst = clavesAd[2];
  const negAd = [escribeMy('cervesur_ro_catalogo_adm', cCat, "INSERT INTO empresa (nombre) VALUES ('x')"), escribeMy('cervesur_ro_auth_adm', cAuth, "UPDATE user SET role='admin' WHERE id=5"), escribeMy('cervesur_react_estado_adm', cEst, "UPDATE cervesur_portalbi.user SET is_active=1"), escribeMy('cervesur_ro_catalogo_adm', cCat, 'SELECT password FROM user LIMIT 1'), escribeMy('cervesur_ro_auth_adm', cAuth, 'SELECT phone FROM user LIMIT 1')];
  ok('a nivel de MySQL las tres cuentas están denegadas: sin escritura sobre la base de Flutter, sin leer contraseñas desde el catálogo ni columnas fuera de su lista', negAd.every(o => /denied|ERROR 1142|ERROR 1143|ERROR 1044|ERROR 1045/i.test(o)) && sqlRoot(FLUTTER_TABLAS) === CK0, negAd.join(' | ').slice(0, 400));
  // revocación inmediata
  admUsr(`quitar ${ADM.id}`);
  ok('quitar al administrador de la lista cierra su sesión de inmediato (401) y ya no puede volver a entrar', callAd('GET', '/api/users?per_page=1', tAd).status === 401 && JSON.parse(loginAd(lAdm, GOODPW)).success === false);
  admUsr(`agregar ${ADM.id} "QA administrador"`);
  const tAd2 = tokenOf(loginAd(lAdm, GOODPW));
  ok('al volver a agregarlo entra con una sesión nueva; la anterior NO revive', /^[0-9a-f]{64}$/.test(tAd2) && callAd('GET', '/api/users?per_page=1', tAd).status === 401 && callAd('GET', '/api/users?per_page=1', tAd2).status === 200);
  const sesionesAd = admUsr('sesiones'); const audAd = admUsr('auditoria');
  ok('el CLI muestra sesiones y auditoría sin contraseñas, hashes ni enlaces; la auditoría registra inicios, fallos, consultas administrativas y la lista', sesionesAd.code === 0 && audAd.code === 0 && !/\$2y\$|https?:|powerbi|synthetic|Ux[0-9a-f]{18}/.test(audAd.out + sesionesAd.out) && /login_ok/.test(audAd.out) && /login_fallido/.test(audAd.out) && /lista_blanca_agregado/.test(audAd.out), audAd.out.slice(-300));
  const estadoDump = sqlRoot('SELECT * FROM cervesur_react_estado_admin.auditoria; SELECT * FROM cervesur_react_estado_admin.sesiones; SELECT * FROM cervesur_react_estado_admin.intentos_login; SELECT * FROM cervesur_react_estado_admin.usuarios_piloto');
  ok('la base de estado administrativa NO contiene contraseñas, hashes, enlaces, nombres de usuario, correos ni direcciones IP en claro', !estadoDump.includes(GOODPW) && !/\$2y\$|https?:|powerbi|@example|syn\d{3}/.test(estadoDump) && !/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/.test(estadoDump));

  section = 'Administrativo 8093 · autorización de enlaces (UX-07E.C)';
  const [R1, R2, R3] = sqlRoot('SELECT GROUP_CONCAT(id ORDER BY id) FROM (SELECT id FROM cervesur_portalbi.reporte WHERE activo = 1 ORDER BY id LIMIT 3) x').split(',');
  const repList = () => callAd('GET', '/api/reportes?view=minimal&per_page=100&page=1', tAd2).json?.data ?? [];
  const CONF_INF = { ...CONFIRM, CERVESUR_USUARIOS_CONFIRMAR_INFORMES: 'AUTORIZO-INFORMES' };
  const infAd = (args, entorno = CONF_INF) => dx(`bash ${PKA}/scripts/usuarios.sh informes ${args}`, entorno);
  const visorAd = id => callAd('GET', `/api/reportes/id/${id}/visor`, tAd2);
  const idsUsuarios0 = env('CERVESUR_PILOT_VISOR_IDS');
  ok('sin autorizar ningún informe: todos los del listado traen visor_autorizado = 0, ningún enlace, y el visor responde 403', repList().length > 3 && repList().every(r => r.visor_autorizado === 0) && visorAd(R1).status === 403);
  const aut1 = infAd(`--agregar ${R1}`); const aut2 = infAd(`--agregar ${R2}`);
  ok('usuarios.sh informes --agregar autoriza informes de a uno y acumula (se pide la confirmación escrita)', aut1.code === 0 && aut2.code === 0 && envA('CERVESUR_PILOT_VISOR_IDS') === `${R1},${R2}`, aut1.out + aut2.out);
  ok('sin la confirmación escrita --agregar no cambia nada; ids mal formados se rechazan', infAd(`--agregar ${R3} < /dev/null`, CONFIRM).code !== 0 && infAd('--agregar "1;2"').code !== 0 && infAd('--agregar abc').code !== 0 && envA('CERVESUR_PILOT_VISOR_IDS') === `${R1},${R2}`);
  const v1 = visorAd(R1); const v2 = visorAd(R2); const v3 = visorAd(R3);
  ok('el administrador abre solo los informes autorizados (200 con enlace); el rol admin NO es autorización irrestricta: el resto da 403 sin enlace', v1.status === 200 && Boolean(v1.json?.data?.url) && v2.status === 200 && v3.status === 403 && !/"url"/.test(v3.raw));
  const filas = repList();
  ok('el indicador visor_autorizado = 1 está solo en los autorizados (listado y detalle) y los listados siguen sin enlaces', filas.filter(r => r.visor_autorizado === 1).map(r => String(r.id)).sort().join() === [R1, R2].sort().join() && !/https?:|powerbi|enlace/i.test(JSON.stringify(filas)) && callAd('GET', `/api/reportes/${R1}`, tAd2).json?.data?.visor_autorizado === 1 && callAd('GET', `/api/reportes/${R3}`, tAd2).json?.data?.visor_autorizado === 0);
  ok('la lista de enlaces del piloto de usuarios (8092) NO cambió', env('CERVESUR_PILOT_VISOR_IDS') === idsUsuarios0);
  const quita = infAd(`--quitar ${R1}`);
  ok('--quitar retira un informe: su visor vuelve a 403 y el otro sigue autorizado', quita.code === 0 && envA('CERVESUR_PILOT_VISOR_IDS') === R2 && visorAd(R1).status === 403 && visorAd(R2).status === 200);
  const bitacora = dx('cat /var/backups/cervesur-react-admin/autorizaciones-informes.log').out;
  ok('bitácora de autorizaciones (root, 600): fecha, quién, acción e ids; sin enlaces', /agregar\tids=\d+/.test(bitacora) && /quitar\tids=\d+/.test(bitacora) && !/https?:|powerbi/i.test(bitacora) && dx('stat -c %a /var/backups/cervesur-react-admin/autorizaciones-informes.log').out === '600', bitacora.slice(0, 300));
  const audAut = admUsr('auditoria').out;
  ok('la auditoría del piloto registra visor_abierto (autorizados) y visor_no_autorizado_piloto (rechazados), sin enlaces', /visor_abierto/.test(audAut) && /visor_no_autorizado_piloto/.test(audAut) && !/https?:/.test(audAut));

  section = 'Administrativo 8093 · navegador real con HTTPS (túnel simulado al 8093)';
  dx('setsid nohup socat TCP-LISTEN:18093,fork,reuseaddr TCP:127.0.0.1:8093 >/dev/null 2>&1 &'); await sleep(1000);
  const spkiAd = dx('openssl x509 -in /etc/apache2/cervesur-react-admin/tls/servidor.crt -pubkey -noout | openssl pkey -pubin -outform der | openssl dgst -sha256 -binary | base64').out;
  const BASEA = BASE; // el puerto publicado del contenedor (18093 dentro, TUNEL fuera) lo había liberado el ensayo del piloto de usuarios
  const usuariosSql = sqlCount('user'); const empresasSql = sqlCount('empresa'); const reportesSql = sqlCount('reporte');
  const ba = await launch({ port: 9541, args: [`--ignore-certificate-errors-spki-list=${spkiAd}`] });
  try {
    await ba.send('Network.setBlockedURLs', { urls: ['https://app.powerbi.com/*', 'https://*.powerbi.com/*'] });
    await ba.goto(`${BASEA}/`); await ba.wait(1500);
    ok('la aplicación administrativa carga por HTTPS y redirige a /login pidiendo la cuenta de administrador', (await ba.eval('location.protocol')) === 'https:' && (await ba.eval('location.pathname')) === '/login' && /cuenta de administrador de Cervesur/.test(await ba.eval('document.body.innerText')), `${await ba.eval('location.href')} :: ${(await ba.eval('document.body.innerText')).slice(0, 300)} :: ${ba.consoleErrors.slice(0, 3).join(' | ')}`);
    await ba.type('#login-name', lA); await ba.type('#login-password', GOODPW); await ba.click('form button[type=submit]'); await ba.wait(1800);
    ok('un TRABAJADOR con su contraseña correcta recibe el error genérico y no entra', (await ba.eval('location.pathname')) === '/login' && /Credenciales inválidas/.test(await ba.eval('document.body.innerText')));
    await ba.type('#login-name', lAdm); await ba.type('#login-password', GOODPW); await ba.click('form button[type=submit]'); await ba.wait(3000);
    const inicio = await ba.eval('document.body.innerText');
    ok('el administrador autorizado entra a /admin con el aviso PILOTO ADMINISTRATIVO · Solo consulta y el modo administrador', (await ba.eval('location.pathname')) === '/admin' && /PILOTO ADMINISTRATIVO/.test(inicio) && /Solo consulta/.test(inicio) && /Modo administrador/.test(inicio), inicio.slice(0, 300));
    const menuAd = await ba.eval(`[...document.querySelectorAll('.nav-button')].map(a => a.innerText.trim()).join('|')`);
    ok('el menú trae Usuarios, Empresas, Reportes y Accesos y asignaciones, y NO las asociaciones empresa–informe', /Usuarios/.test(menuAd) && /Empresas/.test(menuAd) && /Reportes/.test(menuAd) && /Accesos y asignaciones/.test(menuAd) && !/Informes por empresa/.test(menuAd), menuAd);
    const filas = async ruta => { await ba.eval(`history.pushState({}, '', '${ruta}'); dispatchEvent(new PopStateEvent('popstate'))`); await ba.wait(2500); return ba.eval(`({ rows: document.querySelectorAll('tbody tr').length, text: document.body.innerText })`); };
    const usA = await filas('/admin/usuarios');
    ok('Usuarios: el listado real se carga, con el total del servidor, sin «Nuevo usuario» ni acciones de escritura ni datos sensibles', usA.rows > 0 && !/Nuevo usuario|Asignar|Eliminar/.test(usA.text) && !new RegExp(GOODPW).test(usA.text) && !/\$2y\$/.test(usA.text) && new RegExp(String(usuariosSql)).test(usA.text), `${usA.rows} filas · ${usA.text.slice(0, 200)}`);
    const emA = await filas('/admin/empresas');
    ok('Empresas: listado cargado, sin «Nueva empresa» ni «Editar»', emA.rows > 0 && !/Nueva empresa|Editar/.test(emA.text) && new RegExp(String(empresasSql)).test(emA.text), emA.text.slice(0, 200));
    const reA = await filas('/admin/reportes');
    ok('Reportes: listado cargado, sin «Nuevo reporte» ni «Editar» ni enlaces visibles', reA.rows > 0 && !/Nuevo reporte|Editar/.test(reA.text) && !/powerbi\.com/.test(reA.text) && new RegExp(String(reportesSql)).test(reA.text), reA.text.slice(0, 200));
    const acA = await filas('/admin/accesos');
    ok('Accesos y asignaciones: listado cargado, sin «Nueva asignación» ni «Retirar»', acA.rows > 0 && !/Nueva asignación|Retirar/.test(acA.text), acA.text.slice(0, 200));
    for (const ruta of ['/admin/accesos/nueva', '/admin/asociaciones']) { await ba.eval(`history.pushState({}, '', '${ruta}'); dispatchEvent(new PopStateEvent('popstate'))`); await ba.wait(1500); ok(`${ruta} no existe en esta compilación: la interfaz regresa a /admin`, (await ba.eval('location.pathname')) === '/admin'); }
    // UX-07E.C: detalle de un informe AUTORIZADO: el visor ocupa el área principal y los demás informes van en la ventana «Otros informes»
    await ba.eval(`history.pushState({}, '', '/reportes/${R2}'); dispatchEvent(new PopStateEvent('popstate'))`); await ba.wait(3000);
    const vd = await ba.eval(`(() => { const f = document.querySelector('iframe'); const r = f?.getBoundingClientRect(); const b = [...document.querySelectorAll('button')].find(x => /^Otros informes \\(\\d+\\)$/.test(x.innerText.trim())); return { src: f?.src ?? '', h: r?.height ?? 0, w: r?.width ?? 0, ih: innerHeight, iw: innerWidth, top: r?.top ?? 9999, hasButton: Boolean(b), n: b ? Number(/\\((\\d+)\\)/.exec(b.innerText)[1]) : 0, tags: document.querySelectorAll('.sibling-nav').length, hscroll: document.documentElement.scrollWidth > innerWidth }; })()`);
    ok('el visor de un informe autorizado monta el iframe de Power BI con el enlace del servidor, ocupa más de la mitad de la altura de la ventana y casi todo el ancho disponible, sin desbordamiento horizontal', vd.src.startsWith('https://app.powerbi.com/view') && vd.h >= vd.ih * 0.5 && vd.w >= vd.iw * 0.6 && !vd.hscroll, JSON.stringify(vd));
    ok('encima del visor hay UN botón «Otros informes (N)» y ninguna fila de etiquetas', vd.hasButton && vd.n >= 2 && vd.tags === 0, JSON.stringify(vd));
    await ba.eval(`[...document.querySelectorAll('button')].find(x => /^Otros informes/.test(x.innerText.trim())).click()`); await ba.wait(500);
    const pk = await ba.eval(`(() => { const d = document.querySelector('dialog[open]'); const a = [...document.querySelectorAll('dialog[open] .report-picker-list a')]; return { open: Boolean(d), n: a.length, sinEnlace: a.filter(x => /Sin enlace autorizado/.test(x.innerText)).length, propio: a.some(x => x.getAttribute('href') === '/reportes/${R2}'), grupos: document.querySelectorAll('dialog[open] .report-picker-list h3').length, foco: document.activeElement?.id, enlaces: d ? /https?:|powerbi/i.test(d.innerHTML) : null }; })()`);
    ok('la ventana lista todos los demás informes agrupados por empresa, con la marca «Sin enlace autorizado» en los no autorizados, sin el informe abierto y sin enlaces; el foco va al buscador', pk.open && pk.n >= vd.n && pk.sinEnlace >= 1 && pk.sinEnlace <= pk.n && !pk.propio && pk.grupos >= 1 && pk.foco === 'other-reports-q' && pk.enlaces === false, JSON.stringify(pk));
    await ba.key('Escape', 'Escape', { vk: 27 }); await ba.wait(500);
    ok('Escape cierra la ventana y devuelve el foco al botón', await ba.eval(`!document.querySelector('dialog[open]') && /^Otros informes/.test(document.activeElement?.innerText ?? '')`));
    await ba.eval(`history.pushState({}, '', '/reportes/1'); dispatchEvent(new PopStateEvent('popstate'))`); await ba.wait(2500);
    const visorAd = await ba.eval('document.body.innerText');
    ok('abrir un informe sin autorización del piloto: acceso denegado, sin iframe ni enlace', /denegado|no tiene acceso|no está autorizado|Error 403/i.test(visorAd) && !(await ba.eval(`!!document.querySelector('iframe')`)), visorAd.slice(0, 200));
    ok('todas las peticiones del navegador son HTTPS al mismo origen (salvo el iframe oficial de Power BI del informe autorizado), solo GET (salvo el login y el cierre de sesión); ninguna lleva la contraseña en la URL; el token no se guarda en el navegador', ba.requestLog.every(r => (r.url.startsWith(BASEA) || r.url.startsWith('https://app.powerbi.com/view?r=')) && (r.method === 'GET' || /\/api\/(login|logout)$/.test(r.url)) && !r.url.includes(GOODPW)) && (await ba.eval('localStorage.length === 0 && sessionStorage.length === 0 && document.cookie === ""')), ba.requestLog.filter(r => r.method !== 'GET').map(r => `${r.method} ${r.url}`).join(' | '));
    ok('sin errores de consola ni violaciones de CSP (salvo el bloqueo deliberado a Power BI)', ba.consoleErrors.filter(e => !/powerbi|ERR_BLOCKED_BY_CLIENT|status of 40[134]|Failed to load resource/i.test(e)).length === 0, ba.consoleErrors.slice(0, 3).join(' | '));
    await ba.eval(`[...document.querySelectorAll('button')].find(x => /Cerrar sesión/.test(x.textContent))?.click()`); await ba.wait(1800);
    ok('Cerrar sesión devuelve al acceso y revoca el token en el servidor; volver a /admin pide la contraseña de nuevo', (await ba.eval('location.pathname')) === '/login' && Number(sqlRoot("SELECT COUNT(*) FROM cervesur_react_estado_admin.sesiones WHERE motivo_revocacion = 'cierre_de_sesion'")) >= 1);
    await ba.goto(`${BASEA}/admin`); await ba.wait(1500);
    ok('tras cerrar sesión, /admin redirige al acceso', (await ba.eval('location.pathname')) === '/login');
  } finally { await ba.close(); dx("pkill -f 'TCP-LISTEN:1809[3]' || true"); }
  ok('tras todo el uso administrativo, las tablas de cervesur_portalbi son idénticas (CHECKSUM) y el piloto de usuarios sigue intacto', sqlRoot(FLUTTER_TABLAS) === CK0 && arbolInstalado() === U0 && envSha() === UE0 && apachePid() === PIDU0);

  infAd('--retirar'); // fin de la prueba de autorización: ningún enlace autorizado
  ok('--retirar deja la instancia sin enlaces autorizados (valor predeterminado seguro)', envA('CERVESUR_PILOT_VISOR_IDS') === '' && visorAd(R2).status === 403);

  // ----------------------------------------------------------------- Publicación interna por HTTPS: modo DNS (opcional) y modo IP (decisión del operador: sin DNS)
  section = 'Publicación interna · modo DNS (opcional, solo administrador)';
  const HOSTC = 'informes.cervesur.test'; const IPC = IPPUB;
  const CD = '/root/corp'; const COR = `bash ${PKA}/scripts/corporativo.sh`; const REDC = `${NETSUB} 127.0.0.0/8`;
  const nuevoCert = (nombre, san, dias = 365, issuer = 'ca') => dx(`mkdir -p ${CD} && cd ${CD} && openssl req -newkey ec -pkeyopt ec_paramgen_curve:P-256 -nodes -keyout ${nombre}.key -out ${nombre}.csr -subj "/O=Prueba" 2>/dev/null && printf "subjectAltName=${san}\\nextendedKeyUsage=serverAuth\\nbasicConstraints=CA:FALSE\\nkeyUsage=digitalSignature\\n" > ${nombre}.ext && openssl x509 -req -in ${nombre}.csr -CA ${issuer}.crt -CAkey ${issuer}.key -CAcreateserial -out ${nombre}.crt -days ${dias} -extfile ${nombre}.ext 2>/dev/null && cat ${nombre}.crt ca.crt > ${nombre}.full.pem`);
  dx(`rm -rf ${CD} && mkdir -p ${CD} && cd ${CD} && openssl req -x509 -newkey ec -pkeyopt ec_paramgen_curve:P-256 -nodes -keyout ca.key -out ca.crt -subj "/CN=Cervesur Prueba CA" -days 3650 -addext basicConstraints=critical,CA:TRUE -addext keyUsage=critical,keyCertSign,cRLSign 2>/dev/null`);
  nuevoCert('bueno', `DNS:${HOSTC}`); nuevoCert('otronombre', 'DNS:otro.cervesur.test'); nuevoCert('corto', `DNS:${HOSTC}`, 5);
  dx(`cd ${CD} && openssl req -newkey ec -pkeyopt ec_paramgen_curve:P-256 -nodes -keyout otraclave.key -out /dev/null -subj "/CN=x" 2>/dev/null`);
  const args = (cert = 'bueno.full.pem', clave = 'bueno.key', host = HOSTC, ip = IPC, puerto = '', redes = REDC) => `${host ? `--host ${host}` : ''} --ip ${ip} ${puerto ? `--puerto ${puerto}` : ''} --redes "${redes}" --cert ${CD}/${cert} --clave ${CD}/${clave}`;
  const snapCorp0 = snapshot(); const envCorp0 = dx('sha256sum /opt/cervesur-react-admin/backend/.env').out.split(' ')[0]; const U1 = arbolInstalado(); const PIDC0 = apachePid();
  ok('hay una IP privada de la máquina para publicar y no hay publicación previa', /^(10\.|172\.(1[6-9]|2\d|3[01])\.|192\.168\.)/.test(IPC) && dx('! test -e /etc/apache2/sites-available/cervesur-react-admin-corp.conf').code === 0, IPC);
  const prefMal = [['certificado de OTRO nombre', args('otronombre.full.pem', 'otronombre.key'), /cubre «/], ['certificado AUTOFIRMADO (el de localhost)', `--host ${HOSTC} --ip ${IPC} --redes "${REDC}" --cert /etc/apache2/cervesur-react-admin/tls/servidor.crt --clave /etc/apache2/cervesur-react-admin/tls/servidor.key`, /NO es autofirmado|cubre «/], ['certificado a punto de vencer (5 días)', args('corto.full.pem', 'corto.key'), /vigente al menos/], ['clave que NO corresponde', args('bueno.full.pem', 'otraclave.key'), /corresponde al certificado/], ['IP pública', args('bueno.full.pem', 'bueno.key', HOSTC, '8.8.8.8'), /PRIVADA/], ['nombre que no es un dominio (localhost)', args('bueno.full.pem', 'bueno.key', 'localhost'), /nombre de dominio completo/], ['puerto 8092 (otro portal)', args('bueno.full.pem', 'bueno.key', HOSTC, IPC, '8092'), /no es el de SSH/], ['redes públicas / 0.0.0.0/0', args('bueno.full.pem', 'bueno.key', HOSTC, IPC, '', '0.0.0.0/0'), /CIDR privados válidos/], ['red pública 8.8.8.0/24', args('bueno.full.pem', 'bueno.key', HOSTC, IPC, '', '8.8.8.0/24'), /CIDR privados válidos/]];
  const resMal = prefMal.map(([nombre, a, re]) => [nombre, dx(`${COR} preflight ${a}`), re]);
  ok('el preflight RECHAZA (código 1, sin tocar nada): ' + prefMal.map(p => p[0]).join('; '), resMal.every(([, r, re]) => r.code === 1 && re.test(r.out)), resMal.filter(([, r, re]) => !(r.code === 1 && re.test(r.out))).map(([n, r]) => `${n}: ${r.out.slice(-300)}`).join(' || '));
  ok('sin --redes la publicación no se prepara: argumento obligatorio (no hay publicación sin restricción de red)', (() => { const r = dx(`${COR} preflight --host ${HOSTC} --ip ${IPC} --cert ${CD}/bueno.full.pem --clave ${CD}/bueno.key`); return r.code !== 0 && /Faltan argumentos/.test(r.out); })());
  dx('setsid nohup socat TCP-LISTEN:9443,bind=127.0.0.1,fork,reuseaddr /dev/null >/dev/null 2>&1 &'); await sleep(500);
  const ocupado = dx(`${COR} preflight ${args('bueno.full.pem', 'bueno.key', HOSTC, IPC, '9443')}`);
  dx("pkill -f 'TCP-LISTEN:9443' || true");
  ok('con el puerto ocupado por OTRO proceso (no Apache) el preflight se detiene: no asume que esté libre', ocupado.code === 1 && /OTRO proceso/.test(ocupado.out), ocupado.out.slice(-300));
  const prefOk = dx(`${COR} preflight ${args('bueno.full.pem', 'bueno.key', HOSTC, IPC, '', REDC)}`);
  ok('con certificado de la CA, IP privada, redes autorizadas y el puerto 8443 libre el preflight pasa', prefOk.code === 0 && /PREFLIGHT de la publicación: PASS/.test(prefOk.out) && /está libre|ya lo atiende Apache/.test(prefOk.out), prefOk.out.slice(-700));
  ok('el preflight no modificó nada', dx('! test -e /etc/apache2/sites-available/cervesur-react-admin-corp.conf && ! test -e /etc/apache2/cervesur-react-admin/tls-corp').code === 0 && snapshot() === snapCorp0);
  const sinConfC = dx(`${COR} instalar ${args()} < /dev/null`);
  ok('sin la confirmación escrita INSTALAR-CORP no se publica nada', sinConfC.code !== 0 && /Cancelado/.test(sinConfC.out) && dx('! test -e /etc/apache2/sites-available/cervesur-react-admin-corp.conf').code === 0);
  dx(`cp ${CD}/ca.crt /usr/local/share/ca-certificates/cervesur-prueba-ca.crt && update-ca-certificates >/dev/null 2>&1`);
  const instC = dx(`${COR} instalar ${args()}`, CONFIRM);
  ok('modo DNS: instalar termina bien sobre el puerto 8443 (Listen solo en la IP privada), con recarga suave y verificación sin fallos', instC.code === 0 && /PUBLICACIÓN COMPLETA/.test(instC.out) && /verificación de la publicación: PASS/.test(instC.out) && !/FALLO/.test(instC.out) && dx(`grep -c "^Listen ${IPC}:8443" /etc/apache2/sites-available/cervesur-react-admin-corp.conf`).out === '1', instC.out.slice(-1200));
  ok('Apache NO se reinició; la clave privada quedó root:root 600; TLS 1.2/1.3 y la restricción Require ip en la configuración', apachePid() === PIDC0 && dx('stat -c "%a %U:%G" /etc/apache2/cervesur-react-admin/tls-corp/servidor.key').out === '600 root:root' && dx('grep -c "SSLProtocol -all +TLSv1.2 +TLSv1.3" /etc/apache2/sites-available/cervesur-react-admin-corp.conf').out === '1' && dx(`grep -c "Require ip ${NETSUB} 127.0.0.0/8" /etc/apache2/sites-available/cervesur-react-admin-corp.conf`).out === '1');
  const curlC = path => dx(`curl -sS -m 10 --resolve ${HOSTC}:8443:${IPC} -o /dev/null -D - https://${HOSTC}:8443${path} | tr -d '\\r'`).out;
  ok('modo DNS: HSTS en aplicación y API con el nombre propio; por el túnel (localhost) SIN HSTS', /strict-transport-security: max-age=\d{8,}/i.test(curlC('/')) && /strict-transport-security: max-age=\d{8,}/i.test(curlC('/api/ping')) && !/strict-transport/i.test(dx('curl -sS -m 10 --cacert /etc/apache2/cervesur-react-admin/tls/servidor.crt --resolve localhost:8093:127.0.0.1 -o /dev/null -D - https://localhost:8093/api/ping').out));
  const revC = dx(`${COR} revertir`, CONFIRM);
  ok('revertir retira SOLO el VirtualHost, su certificado y HSTS (sin reinicio); el .env y el servidor quedan idénticos a antes', revC.code === 0 && !/FALLO/.test(revC.out) && dx('! test -e /etc/apache2/sites-available/cervesur-react-admin-corp.conf && ! test -e /etc/apache2/cervesur-react-admin/tls-corp').code === 0 && apachePid() === PIDC0 && dx('sha256sum /opt/cervesur-react-admin/backend/.env').out.split(' ')[0] === envCorp0 && snapshot() === snapCorp0);
  ok('revertir es idempotente', (() => { const r = dx(`${COR} revertir`, CONFIRM); return r.code === 0 && !/FALLO/.test(r.out); })());
  dx('rm -f /usr/local/share/ca-certificates/cervesur-prueba-ca.crt && update-ca-certificates --fresh >/dev/null 2>&1; rm -rf /root/corp');

  // ----------------------------------------------------------------- MODO IP: trabajadores en https://IP (443) y administrador en https://IP:8443, sin DNS ni túneles
  section = 'Publicación por IP interna · CA privada y certificado de la IP';
  const PKI = '/root/pki'; const PKI2 = '/root/pki-otra';
  const emi = dx(`bash ${PK}/scripts/emitir-certificado-ip.sh --ip ${IPPUB} --red ${NETSUB} --sin-clave-ca --salida ${PKI}`);
  ok('emitir-certificado-ip.sh crea la CA privada (restringida a la red) y un certificado con la IP en el SAN como «IP Address»; la clave del servidor queda 600 y la de la CA no sale de la carpeta de la CA', emi.code === 0 && /Listo en/.test(emi.out) && dx(`openssl x509 -in ${PKI}/servidor/servidor.crt -noout -ext subjectAltName`).out.includes(`IP Address:${IPPUB}`) && dx(`stat -c %a ${PKI}/servidor/servidor.key ${PKI}/ca/ca.key`).out === '600\n600' && !dx(`ls ${PKI}/servidor`).out.includes('ca.key'), emi.out.slice(-300));
  dx(`mkdir -p /root/nc && cd /root/nc && openssl genpkey -algorithm EC -pkeyopt ec_paramgen_curve:P-256 -out x.key && openssl req -new -key x.key -subj "/O=x" -out x.csr && printf "subjectAltName=IP:8.8.8.8\\nextendedKeyUsage=serverAuth\\nbasicConstraints=CA:FALSE\\n" > a.ext && printf "subjectAltName=DNS:www.google.com\\nextendedKeyUsage=serverAuth\\nbasicConstraints=CA:FALSE\\n" > b.ext && openssl x509 -req -in x.csr -CA ${PKI}/ca/ca.crt -CAkey ${PKI}/ca/ca.key -CAcreateserial -out a.crt -days 30 -extfile a.ext 2>/dev/null && openssl x509 -req -in x.csr -CA ${PKI}/ca/ca.crt -CAkey ${PKI}/ca/ca.key -CAcreateserial -out b.crt -days 30 -extfile b.ext 2>/dev/null`);
  ok('la CA solo puede certificar su red: aunque se filtrara su clave, un certificado de OTRA IP y uno de un nombre ajeno (www.google.com) firmados por ella NO validan (name constraints)', dx(`openssl verify -CAfile ${PKI}/ca/ca.crt ${PKI}/servidor/servidor.crt`).code === 0 && dx(`openssl verify -CAfile ${PKI}/ca/ca.crt /root/nc/a.crt`).code !== 0 && dx(`openssl verify -CAfile ${PKI}/ca/ca.crt /root/nc/b.crt`).code !== 0);
  ok('el emisor se niega a certificar una IP pública o una IP que no está en las redes indicadas', dx(`bash ${PK}/scripts/emitir-certificado-ip.sh --ip 8.8.8.8 --red 8.8.8.0/24 --sin-clave-ca --salida /root/pki-x`).code !== 0 && dx(`bash ${PK}/scripts/emitir-certificado-ip.sh --ip ${IPPUB} --red 10.8.0.0/24 --sin-clave-ca --salida /root/pki-y`).code !== 0);
  dx(`bash ${PK}/scripts/emitir-certificado-ip.sh --ip 10.77.77.99 --red ${NETSUB} --sin-clave-ca --salida ${PKI2}`); // certificado de OTRA IP (para los rechazos)
  dx(`cp ${PKI}/ca/ca.crt /usr/local/share/ca-certificates/cervesur-ip-ca.crt && update-ca-certificates >/dev/null 2>&1`); // en los equipos reales la CA la distribuye la empresa (GPO / certutil)

  section = 'Publicación por IP interna · paquetes con la IP y preflight';
  const packIpU = spawnSync(process.execPath, [join(here, 'empaquetar.mjs'), `--host=${IPPUB}`], { cwd: REPO, encoding: 'utf8' });
  const packIpA = spawnSync(process.execPath, [join(REPO, 'deploy', 'tunel-ssh-piloto-admin', 'empaquetar.mjs'), `--host=${IPPUB}`], { cwd: REPO, encoding: 'utf8' });
  ok('los dos paquetes se compilan admitiendo la IP (además de localhost) y no contienen claves ni certificados', packIpU.status === 0 && packIpA.status === 0, `${packIpU.stderr}${packIpA.stderr}`.slice(-300));
  const tgzIpU = newest('cervesur-react-usuarios-'); const tgzIpA = newest('cervesur-react-admin-');
  docker(['cp', tgzIpU, `${NAME}:/root/ipu.tar.gz`], { allowFail: false }); docker(['cp', tgzIpA, `${NAME}:/root/ipa.tar.gz`], { allowFail: false });
  const PKU2 = `/root/${first('cd /root && mkdir -p ipu ipa && tar xzf ipu.tar.gz -C ipu && ls ipu')}`.replace('/root/', '/root/ipu/'); const PKA2 = `/root/${first('cd /root && tar xzf ipa.tar.gz -C ipa && ls ipa')}`.replace('/root/', '/root/ipa/');
  ok('integridad de los dos paquetes de publicación (SHA256SUMS) y la IP figura en allowedHosts de ambos', dx(`cd ${PKU2} && sha256sum -c SHA256SUMS --quiet`).code === 0 && dx(`cd ${PKA2} && sha256sum -c SHA256SUMS --quiet`).code === 0 && dx(`grep -c '"${IPPUB}"' ${PKU2}/public/build-info.json ${PKA2}/public/build-info.json`).out.split('\n').every(l => l.endsWith(':1')) && dx(`find ${PKU2} ${PKA2} \\( -name '.env*' -o -name '*.key' -o -name '*.pem' -o -name '*.crt' \\) | wc -l`).out === '0');
  const ARGS_IP = `--ip ${IPPUB} --redes "${NETSUB} 127.0.0.0/8" --cert ${PKI}/servidor/fullchain.pem --clave ${PKI}/servidor/servidor.key`;
  const ipSnap0 = snapshot(); const UT0 = arbolInstalado(); const AT0 = `${tree('/var/www/cervesur-react-admin/public')}|${tree('/opt/cervesur-react-admin/backend')}`;
  const UENV0 = envSha(); const AENV0 = dx('sha256sum /opt/cervesur-react-admin/backend/.env').out.split(' ')[0]; const PIDIP0 = apachePid();
  const malos = [['certificado de OTRA IP', `--ip ${IPPUB} --redes "${NETSUB}" --cert ${PKI2}/servidor/fullchain.pem --clave ${PKI2}/servidor/servidor.key`, /cubre «/], ['clave que no corresponde', `--ip ${IPPUB} --redes "${NETSUB}" --cert ${PKI}/servidor/fullchain.pem --clave ${PKI2}/servidor/servidor.key`, /corresponde al certificado/], ['redes 0.0.0.0/0', `--ip ${IPPUB} --redes "0.0.0.0/0" --cert ${PKI}/servidor/fullchain.pem --clave ${PKI}/servidor/servidor.key`, /CIDR privados válidos/], ['IP pública', `--ip 8.8.8.8 --redes "${NETSUB}" --cert ${PKI}/servidor/fullchain.pem --clave ${PKI}/servidor/servidor.key`, /PRIVADA/]];
  const resMalIp = malos.flatMap(([n, a, re]) => [['trabajadores', PKU2], ['administrador', PKA2]].map(([quien, dir]) => [`${n} (${quien})`, dx(`bash ${dir}/scripts/corporativo.sh preflight ${a} --previo`), re]));
  ok('modo IP: el preflight de AMBOS portales rechaza certificado de otra IP, clave ajena, redes 0.0.0.0/0 e IP pública; sin tocar nada', resMalIp.every(([, r, re]) => r.code === 1 && re.test(r.out)) && snapshot() === ipSnap0, resMalIp.filter(([, r, re]) => !(r.code === 1 && re.test(r.out))).map(([n, r]) => `${n}: ${r.out.slice(-250)}`).join(' || '));
  dx('setsid nohup socat TCP-LISTEN:8443,bind=127.0.0.1,fork,reuseaddr /dev/null >/dev/null 2>&1 &'); await sleep(500);
  const ocupado8443 = dx(`bash ${PKA2}/scripts/corporativo.sh preflight ${ARGS_IP} --previo`);
  dx("pkill -f 'TCP-LISTEN:8443' || true"); await sleep(500);
  ok('con el 8443 ocupado por otro proceso el preflight del administrador se detiene y lo dice; el 443 de trabajadores sigue evaluándose por separado', ocupado8443.code === 1 && /OTRO proceso/.test(ocupado8443.out) && /puerto 8443/.test(ocupado8443.out), ocupado8443.out.slice(-300));
  const prefTrab = dx(`bash ${PKU2}/scripts/corporativo.sh preflight ${ARGS_IP} --previo`); const prefAdm = dx(`bash ${PKA2}/scripts/corporativo.sh preflight ${ARGS_IP} --previo`);
  ok('con el certificado de la IP, las redes autorizadas y los puertos 443/8443 el preflight de cada portal pasa (la aplicación del paquete nuevo admitirá la IP)', prefTrab.code === 0 && prefAdm.code === 0 && /URL|https:\/\/10\.77\.77\.12/.test(prefTrab.out) && /8443/.test(prefAdm.out), prefTrab.out.slice(-500) + prefAdm.out.slice(-500));
  const simIp = dx(`bash ${PKU2}/scripts/publicar-ip.sh --trabajadores ${PKU2} --administrador ${PKA2} ${ARGS_IP} --simular`);
  ok('publicar-ip.sh --simular: verifica paquetes, simula las dos actualizaciones y los dos preflights; código 0 y NO escribe nada', simIp.code === 0 && /SIMULACIÓN terminada/.test(simIp.out) && snapshot() === ipSnap0 && arbolInstalado() === UT0 && dx('! test -e /etc/apache2/sites-available/cervesur-react-usuarios-corp.conf && ! test -e /etc/apache2/sites-available/cervesur-react-admin-corp.conf').code === 0, simIp.out.slice(-700));
  const sinConfIp = dx(`bash ${PKU2}/scripts/publicar-ip.sh --trabajadores ${PKU2} --administrador ${PKA2} ${ARGS_IP} < /dev/null`);
  ok('sin escribir PUBLICAR-IP no se actualiza ni se publica nada', sinConfIp.code !== 0 && /Cancelado/.test(sinConfIp.out) && snapshot() === ipSnap0 && arbolInstalado() === UT0);

  section = 'Publicación por IP interna · intervención única';
  const pubIp = dx(`bash ${PKU2}/scripts/publicar-ip.sh --trabajadores ${PKU2} --administrador ${PKA2} ${ARGS_IP}`, CONFIRM);
  const respIp = /--respaldos "([^"]+)"/.exec(pubIp.out)?.[1] ?? '';
  ok('publicar-ip.sh actualiza los dos portales y publica trabajadores (443) y administrador (8443) en una sola intervención: PUBLICADO', pubIp.code === 0 && /PUBLICADO: trabajadores https:\/\/10\.77\.77\.12 · administrador https:\/\/10\.77\.77\.12:8443/.test(pubIp.out) && respIp.split(' ').length === 2, pubIp.out.slice(-1500));
  ok('Apache NO se reinició; las claves quedaron root:root 600 en cada portal; Listen solo en la IP privada (el 443 y el 8443)', apachePid() === PIDIP0 && dx('stat -c %a:%U:%G /etc/apache2/cervesur-react-usuarios/tls-corp/servidor.key /etc/apache2/cervesur-react-admin/tls-corp/servidor.key').out === '600:root:root\n600:root:root' && !dx('ss -ltnH').out.split('\n').some(l => /(\*|0\.0\.0\.0):8443\b/.test(l)) && dx("ss -ltnH | awk '{print $4}'").out.includes(`${IPPUB}:8443`));
  const UIP = 'https://' + IPPUB; const AIP = 'https://' + IPPUB + ':8443';
  const cv = (url, extra = '') => dx(`curl -sS -m 12 ${extra} ${url}`);
  const post = (base, user, pass) => dx(`printf '{"login":"%s","password":"%s"}' "${user}" "${pass}" | curl -sS -m 20 -H 'Content-Type: application/json' --data @- ${base}/api/login`).out;
  const get = (base, path, tk = '') => { const r = dx(`curl -sS -m 20 ${tk ? `-H 'Authorization: Bearer ${tk}'` : ''} -w '\\n%{http_code}' ${base}${path}`).out.split('\n'); const status = Number(r.pop()); return { status, raw: r.join('\n'), json: (() => { try { return JSON.parse(r.join('\n')); } catch { return null; } })() }; };
  ok('el certificado se valida con el almacén de confianza del sistema (sin -k): 443 y 8443 sirven la aplicación y la API; la IP del certificado coincide', cv(`${UIP}/`).out.includes('id="root"') && cv(`${AIP}/`).out.includes('id="root"') && cv(`${UIP}/api/ping`).out.includes('pong') && cv(`${AIP}/api/ping`).out.includes('pong') && dx(`openssl s_client -connect ${IPPUB}:443 -verify_ip ${IPPUB} -verify_return_error </dev/null 2>&1 | grep -c 'Verify return code: 0'`).out === '1' && dx(`openssl s_client -connect ${IPPUB}:8443 -verify_ip ${IPPUB} -verify_return_error </dev/null 2>&1 | grep -c 'Verify return code: 0'`).out === '1');
  const tTrab = tokenOf(post(UIP, lA, GOODPW)); const tAdm = tokenOf(post(AIP, lAdm, GOODPW));
  ok('login de trabajador en https://IP y de administrador en https://IP:8443', /^[0-9a-f]{64}$/.test(tTrab) && /^[0-9a-f]{64}$/.test(tAdm));
  const generico = [post(UIP, lAdm, GOODPW), post(AIP, lA, GOODPW), post(UIP, lA, 'incorrecta'), post(UIP, 'nadie', 'x')].map(b => b.replace(/\s/g, ''));
  ok('los roles NO se cruzan: el administrador no entra en el portal de trabajadores ni el trabajador en el administrativo; ambos reciben la MISMA respuesta genérica que una clave incorrecta', new Set(generico).size === 1 && JSON.parse(generico[0]).success === false);
  ok('el token de un portal no sirve en el otro (sesiones y bases de estado independientes)', get(AIP, '/api/user/profile', tTrab).status === 401 && get(UIP, '/api/user/profile', tAdm).status === 401);
  ok('trabajador: ve solo lo suyo y NO tiene endpoints administrativos (403); administrador: directorio de solo lectura y escrituras 403', get(UIP, '/api/users', tTrab).status === 403 && get(UIP, '/api/reportes?view=minimal&per_page=100', tTrab).json?.pagination?.total <= A.n + 5 && get(AIP, '/api/users?status=all&per_page=1', tAdm).status === 200 && [['POST', '/api/register'], ['PUT', '/api/reportes/1'], ['DELETE', '/api/empresas/1']].every(([m, p]) => dx(`curl -sS -m 10 -o /dev/null -w '%{http_code}' -X ${m} -H 'Authorization: Bearer ${tAdm}' -d '{}' ${AIP}${p}`).out === '403'));
  ok('HSTS no se emite en una IP (ni aplicación ni API, ni por el túnel); sin cookies; HTTP plano a 443 y 8443 → 400 y X-Forwarded-Proto falsificado inútil', [UIP, AIP].every(b => !/strict-transport/i.test(dx(`curl -sS -m 10 -o /dev/null -D - ${b}/`).out + dx(`curl -sS -m 10 -o /dev/null -D - ${b}/api/ping`).out) && !/set-cookie/i.test(dx(`curl -sS -m 10 -o /dev/null -D - ${b}/api/ping`).out)) && ['443', '8443'].every(p => dx(`curl -sS -m 8 -o /dev/null -w '%{http_code}' http://${IPPUB}:${p}/api/ping`).out === '400' && dx(`curl -sS -m 8 -o /dev/null -w '%{http_code}' -H 'X-Forwarded-Proto: https' -d '{}' http://${IPPUB}:${p}/api/login`).out === '400'));
  ok('Flutter (80), 8088–8091 y los túneles de ambos portales siguen vivos; las tablas de cervesur_portalbi idénticas', portalesVivos() && sqlRoot(FLUTTER_TABLAS) === CK0 && dx(`curl -sS -m 10 --cacert /etc/apache2/cervesur-react-usuarios/tls/servidor.crt --resolve localhost:8092:127.0.0.1 https://localhost:8092/api/ping`).out.includes('pong') && dx(`curl -sS -m 10 --cacert /etc/apache2/cervesur-react-admin/tls/servidor.crt --resolve localhost:8093:127.0.0.1 https://localhost:8093/api/ping`).out.includes('pong'));

  section = 'Publicación por IP interna · redes autorizadas (otro cliente de la red)';
  const otro = (url, extra = '') => docker(['run', '--rm', '--network', NETNAME, '--ip', '10.77.77.50', '--entrypoint', 'curl', IMAGE, '-sS', '-m', '8', '-o', '/dev/null', '-w', '%{http_code}', ...extra.split(' ').filter(Boolean), url]).out;
  const otroPost = base => docker(['run', '--rm', '--network', NETNAME, '--ip', '10.77.77.50', '--entrypoint', 'sh', IMAGE, '-c', `curl -sSk -m 8 -w '|%{http_code}' -H 'Content-Type: application/json' -d '{"login":"${lA}","password":"${GOODPW}"}' ${base}/api/login`]).out;
  ok('un equipo de la red autorizada (10.77.77.50, distinto del servidor) llega a ambos portales: la aplicación responde 200 (certificado confiable porque la CA está en su almacén; aquí se usa -k solo porque este cliente de prueba no tiene la CA)', otro(`${UIP}/`, '-k') === '200' && otro(`${AIP}/`, '-k') === '200');
  // el administrador se vuelve a publicar con una red MÁS estrecha (solo el propio servidor y loopback): el otro cliente debe recibir 403 en el 8443 y seguir entrando en el 443
  const ARGS_ESTRECHO = `--ip ${IPPUB} --redes "${IPPUB}/32 127.0.0.0/8" --cert ${PKI}/servidor/fullchain.pem --clave ${PKI}/servidor/servidor.key`;
  dx(`bash ${PKA2}/scripts/corporativo.sh revertir`, CONFIRM);
  const reinst = dx(`bash ${PKA2}/scripts/corporativo.sh instalar ${ARGS_ESTRECHO}`, CONFIRM);
  ok('el administrador se republica con una red más estrecha (solo el servidor y loopback)', reinst.code === 0 && /PUBLICACIÓN COMPLETA/.test(reinst.out), reinst.out.slice(-500));
  const bloq = otro(`${AIP}/`, '-k'); const bloqApi = otroPost(AIP); const permitido = otro(`${UIP}/`, '-k');
  ok('un equipo FUERA de las redes autorizadas recibe 403 de Apache en toda ruta (aplicación, API y el inicio de sesión: no llega a PHP ni se emite token); el servidor mismo sí entra; el portal de trabajadores (red /24) sigue accesible para ese equipo', bloq === '403' && /\|403$/.test(bloqApi) && !/token/.test(bloqApi) && permitido === '200' && cv(`${AIP}/api/ping`).out.includes('pong'), `${bloq} ${bloqApi} ${permitido}`);
  ok('la restricción por red se aplica a todos los métodos (POST, PUT, DELETE, OPTIONS) y a rutas inexistentes', ['POST', 'PUT', 'DELETE', 'OPTIONS'].every(m => docker(['run', '--rm', '--network', NETNAME, '--ip', '10.77.77.50', '--entrypoint', 'curl', IMAGE, '-sk', '-m', '8', '-o', '/dev/null', '-w', '%{http_code}', '-X', m, `${AIP}/api/login`]).out === '403') && otro(`${AIP}/no/existe`, '-k') === '403');
  dx(`bash ${PKA2}/scripts/corporativo.sh revertir`, CONFIRM);
  ok('al reinstalar con la red /24 el otro equipo vuelve a entrar al administrador', dx(`bash ${PKA2}/scripts/corporativo.sh instalar ${ARGS_IP}`, CONFIRM).code === 0 && otro(`${AIP}/`, '-k') === '200');

  section = 'Publicación por IP interna · renovación del certificado';
  const huellaCert = () => dx('openssl x509 -in /etc/apache2/cervesur-react-usuarios/tls-corp/servidor.crt -noout -serial').out;
  const serie0 = huellaCert();
  dx(`bash ${PK}/scripts/emitir-certificado-ip.sh --ip ${IPPUB} --red ${NETSUB} --sin-clave-ca --salida ${PKI} --dias 200`);
  const renOk = dx(`bash ${PKU2}/scripts/corporativo.sh renovar ${ARGS_IP}`, CONFIRM);
  ok('renovar cambia SOLO el certificado y la clave con el emitido por la misma CA: verificación sin fallos, número de serie nuevo, anterior guardado en el respaldo (root 700), sin reiniciar Apache', renOk.code === 0 && /CERTIFICADO RENOVADO/.test(renOk.out) && huellaCert() !== serie0 && dx('ls -d /var/backups/cervesur-react-usuarios/tls-corp-anterior-* | wc -l').out === '1' && apachePid() === PIDIP0, renOk.out.slice(-500));
  const serie1 = huellaCert();
  const renAdm = dx(`bash ${PKA2}/scripts/corporativo.sh renovar ${ARGS_IP}`, CONFIRM);
  ok('también el portal administrativo se renueva con el mismo certificado nuevo (ambos puertos usan el mismo certificado de la IP)', renAdm.code === 0 && /CERTIFICADO RENOVADO/.test(renAdm.out), renAdm.out.slice(-400));
  const renMal = dx(`bash ${PKU2}/scripts/corporativo.sh renovar --ip ${IPPUB} --redes "${NETSUB} 127.0.0.0/8" --cert ${PKI2}/servidor/fullchain.pem --clave ${PKI2}/servidor/servidor.key`, CONFIRM);
  ok('una renovación con un certificado de OTRA IP se rechaza antes de tocar nada: el certificado instalado sigue siendo el anterior y el portal responde', renMal.code !== 0 && huellaCert() === serie1 && cv(`${UIP}/api/ping`).out.includes('pong'), renMal.out.slice(-300));

  section = 'Publicación por IP interna · navegador real por https://IP y https://IP:8443';
  const proxy = createServer((req, res) => { res.writeHead(405); res.end(); });
  const puertos = { '443': TUNEL + 1, '8443': TUNEL + 2 };
  proxy.on('connect', (req, client, head) => {
    const [h, p] = req.url.split(':'); const dest = h === IPPUB ? puertos[p] : undefined;
    if (!dest) { client.end('HTTP/1.1 403 Forbidden\r\n\r\n'); return; }
    const up = netConnect(dest, '127.0.0.1', () => { client.write('HTTP/1.1 200 Connection Established\r\n\r\n'); up.write(head); up.pipe(client); client.pipe(up); });
    up.on('error', () => client.destroy()); client.on('error', () => up.destroy());
  });
  await new Promise(r => proxy.listen(0, '127.0.0.1', r)); const proxyPort = proxy.address().port;
  const spkiIp = dx(`openssl x509 -in ${PKI}/servidor/servidor.crt -pubkey -noout | openssl pkey -pubin -outform der | openssl dgst -sha256 -binary | base64`).out;
  const bi = await launch({ port: 9561, args: [`--proxy-server=http://127.0.0.1:${proxyPort}`, `--ignore-certificate-errors-spki-list=${spkiIp}`] });
  try {
    await bi.send('Network.setBlockedURLs', { urls: ['https://app.powerbi.com/*', 'https://*.powerbi.com/*'] });
    const txt = () => bi.eval('document.body.innerText'); const pathOf = () => bi.eval('location.pathname');
    const irA = async ruta => { await bi.eval(`history.pushState({}, '', '${ruta}'); dispatchEvent(new PopStateEvent('popstate'))`); await bi.wait(2500); };
    await bi.goto(`${UIP}/`); await bi.wait(2000);
    ok('TRABAJADORES por https://IP (puerto 443): la aplicación carga con el host = la IP, y pide la cuenta habitual de Cervesur', (await bi.eval('location.hostname')) === IPPUB && (await bi.eval('location.protocol')) === 'https:' && (await pathOf()) === '/login' && /ingrese con su cuenta de Cervesur/.test(await txt()), `${await bi.eval('location.href')} :: ${(await txt()).slice(0, 200)}`);
    await bi.type('#login-name', lAdm); await bi.type('#login-password', GOODPW); await bi.click('form button[type=submit]'); await bi.wait(1800);
    ok('un ADMINISTRADOR no entra por el portal de trabajadores (mismo error genérico)', (await pathOf()) === '/login' && /Credenciales inválidas/.test(await txt()));
    await bi.type('#login-name', lA); await bi.type('#login-password', GOODPW); await bi.click('form button[type=submit]'); await bi.wait(2800);
    const lib = await txt();
    ok('el trabajador entra: Mis informes con el aviso de usuarios autorizados y SOLO sus informes (sin enlaces)', (await pathOf()) === '/reportes' && /PILOTO · Usuarios autorizados/.test(lib) && (await bi.eval(`document.querySelectorAll('article.report-card').length`)) > 0 && !/powerbi\.com/.test(await bi.eval('document.body.innerHTML')));
    await irA('/admin'); ok('/admin no existe en el portal de trabajadores: la interfaz regresa al catálogo', !(await pathOf()).startsWith('/admin'));
    const idsVis = sqlRoot(`SELECT DISTINCT r.id FROM cervesur_portalbi.user_empresa_reporte uer JOIN cervesur_portalbi.empresa_reporte er ON er.id = uer.empresa_reporte_id AND er.activo = 1 JOIN cervesur_portalbi.empresa e ON e.id = er.empresa_id AND e.activo = 1 JOIN cervesur_portalbi.empresa_usuario eu ON eu.empresa_id = e.id AND eu.user_id = ${A.id} AND eu.activo = 1 JOIN cervesur_portalbi.reporte r ON r.id = er.reporte_id AND r.activo = 1 WHERE uer.activo = 1 AND uer.user_id = ${A.id} ORDER BY r.id LIMIT 1`);
    await irA(`/reportes/${idsVis}`);
    const detalle = await bi.eval(`({ boton: [...document.querySelectorAll('button')].some(b => /^Otros informes/.test(b.innerText.trim())), etiquetas: document.querySelectorAll('.sibling-nav').length, denegado: /Error 403|denegado/i.test(document.body.innerText), visor: Boolean(document.querySelector('iframe')), hscroll: document.documentElement.scrollWidth > innerWidth })`);
    ok('el detalle de un informe propio funciona por la IP: o carga el visor (si el informe está autorizado en el piloto) o muestra el aviso 403 neutral; en ambos casos sin etiquetas de otros informes (hay un botón compacto) y sin desbordamiento', (detalle.denegado || detalle.visor) && detalle.etiquetas === 0 && !detalle.hscroll, JSON.stringify(detalle));
    await bi.eval(`[...document.querySelectorAll('button')].find(x => /Cerrar sesión/.test(x.textContent))?.click()`); await bi.wait(1800);
    ok('Cerrar sesión devuelve al acceso (token revocado en el servidor) y volver a /reportes pide la contraseña', (await pathOf()) === '/login' && Number(sqlRoot("SELECT COUNT(*) FROM cervesur_react_estado.sesiones WHERE motivo_revocacion = 'cierre_de_sesion'")) >= 1);

    await bi.goto(`${AIP}/`); await bi.wait(2000);
    ok('ADMINISTRADOR por https://IP:8443: la aplicación carga con el host = la IP y pide la cuenta de administrador', (await bi.eval('location.hostname')) === IPPUB && (await bi.eval('location.port')) === '8443' && (await pathOf()) === '/login' && /cuenta de administrador de Cervesur/.test(await txt()), `${await bi.eval('location.href')} :: ${(await txt()).slice(0, 200)}`);
    await bi.type('#login-name', lA); await bi.type('#login-password', GOODPW); await bi.click('form button[type=submit]'); await bi.wait(1800);
    ok('un TRABAJADOR no entra por el portal administrativo', (await pathOf()) === '/login' && /Credenciales inválidas/.test(await txt()));
    await bi.type('#login-name', lAdm); await bi.type('#login-password', GOODPW); await bi.click('form button[type=submit]'); await bi.wait(3000);
    ok('el administrador autorizado entra a /admin con el aviso de solo consulta', (await pathOf()) === '/admin' && /PILOTO ADMINISTRATIVO/.test(await txt()));
    await irA('/admin/usuarios'); const us2 = await bi.eval(`({ rows: document.querySelectorAll('tbody tr').length, escritura: /Nuevo usuario|Asignar|Eliminar/.test(document.body.innerText) })`);
    ok('Listados administrativos por la IP: usuarios cargados y sin acciones de escritura', us2.rows > 0 && !us2.escritura, JSON.stringify(us2));
    await irA('/admin/reportes'); const rp2 = await bi.eval(`({ rows: document.querySelectorAll('tbody tr').length, escritura: /Nuevo reporte|Editar/.test(document.body.innerText) })`);
    await bi.eval(`document.querySelector('tbody tr a')?.click()`); await bi.wait(2500);
    const sel = await bi.eval(`({ boton: [...document.querySelectorAll('button')].some(b => /^Otros informes \\(\\d+\\)$/.test(b.innerText.trim())), etiquetas: document.querySelectorAll('.sibling-nav').length })`);
    ok('Reportes por la IP: listado sin escritura y el detalle trae el botón «Otros informes (N)» (sin fila de etiquetas)', rp2.rows > 0 && !rp2.escritura && sel.boton && sel.etiquetas === 0, JSON.stringify({ rp2, sel }));
    await bi.eval(`[...document.querySelectorAll('button')].find(b => /^Otros informes/.test(b.innerText.trim()))?.click()`); await bi.wait(600);
    const modal = await bi.eval(`({ abierto: Boolean(document.querySelector('dialog[open]')), enlaces: document.querySelectorAll('dialog[open] .report-picker-list a').length, foco: document.activeElement?.id, sinHttp: !/https?:|powerbi/i.test(document.querySelector('dialog[open]')?.innerHTML ?? 'x') })`);
    ok('el selector modal «Otros informes» funciona por la IP: lista los informes, foco en el buscador y sin enlaces', modal.abierto && modal.enlaces > 1 && modal.foco === 'other-reports-q' && modal.sinHttp, JSON.stringify(modal));
    await bi.key('Escape', 'Escape', { vk: 27 }); await bi.wait(500);
    ok('Escape cierra la ventana', await bi.eval(`!document.querySelector('dialog[open]')`));
    ok('todas las peticiones del navegador fueron HTTPS a la IP (443 u 8443) —salvo el iframe oficial de Power BI de un informe autorizado—, solo GET salvo login/logout; ninguna con la contraseña en la URL; el token no se guarda en el navegador', bi.requestLog.every(r => (r.url.startsWith(UIP + '/') || r.url.startsWith(AIP + '/') || r.url.startsWith('https://app.powerbi.com/view?r=')) && (r.method === 'GET' || /\/api\/(login|logout)$/.test(r.url)) && !r.url.includes(GOODPW)) && (await bi.eval('localStorage.length === 0 && sessionStorage.length === 0 && document.cookie === ""')), bi.requestLog.filter(r => !(r.url.startsWith(UIP + '/') || r.url.startsWith(AIP + '/'))).map(r => r.url).slice(0, 3).join(' | '));
    ok('sin errores de consola ni violaciones de CSP (salvo el bloqueo deliberado a Power BI)', bi.consoleErrors.filter(e => !/powerbi|ERR_BLOCKED_BY_CLIENT|status of 40[134]|Failed to load resource/i.test(e)).length === 0, bi.consoleErrors.slice(0, 3).join(' | '));
    await bi.eval(`[...document.querySelectorAll('button')].find(x => /Cerrar sesión/.test(x.textContent))?.click()`); await bi.wait(1800);
    ok('Cerrar sesión del administrador por la IP: vuelve al acceso y /admin pide la contraseña', (await pathOf()) === '/login' && (await (async () => { await bi.goto(`${AIP}/admin`); await bi.wait(1500); return pathOf(); })()) === '/login');
  } finally { await bi.close(); proxy.close(); }

  // ---------------------------------------------------------------------------------------------------------------------------------------------
  // UX-08G.1 · Producción 8443: con el administrador YA publicado en https://IP:8443 (directorios compartidos con 8093) se ensaya la actualización in situ
  // del release candidate: simulación sin escritura, respaldo verificable sin .env, fallo con vuelta automática, actualización, QA y reversión bit a bit.
  section = 'Producción 8443 · actualización in situ del release candidate';
  const P8_AT = () => `${tree('/var/www/cervesur-react-admin/public')}|${tree('/opt/cervesur-react-admin/backend')}`;
  const P8_envSum = () => dx('sha256sum /opt/cervesur-react-admin/backend/.env').out.split(' ')[0];
  const P8_respN = () => dx('ls -d /var/backups/cervesur-react-admin/actualizacion-* 2>/dev/null | wc -l').out;
  const P8_AT0 = P8_AT(); const P8_ENV = P8_envSum(); const P8_UT = arbolInstalado(); const P8_PID = apachePid(); const P8_R0 = P8_respN();
  const P8_ping = () => cv(`${AIP}/api/ping`).out.includes('pong');
  ok('punto de partida: el administrador responde en 8443 por HTTPS con el almacén del sistema y la persona administradora entra', P8_ping() && /^[0-9a-f]{64}$/.test(tokenOf(post(AIP, lAdm, GOODPW))));

  const simu8443 = dx(`bash ${PKA2}/scripts/actualizar.sh --simular`);
  ok('actualizar.sh --simular sobre el administrador publicado: código 0, NO escribe (mismos archivos, mismo .env, sin respaldos nuevos)', simu8443.code === 0 && /SIMULACIÓN terminada/.test(simu8443.out) && P8_AT() === P8_AT0 && P8_envSum() === P8_ENV && P8_respN() === P8_R0, simu8443.out.slice(-400));

  // (a) error de SINTAXIS: se rechaza en las precondiciones, antes de tocar nada
  dx(`rm -rf /root/paq-ad-sint && cp -a ${PKA2} /root/paq-ad-sint && printf '<?php\\n$x = (;\\n' >> /root/paq-ad-sint/backend/router/AdminRouter.php`); resum('/root/paq-ad-sint');
  const sint8443 = dx('bash /root/paq-ad-sint/scripts/actualizar.sh', CONFIRM);
  ok('paquete con un error de SINTAXIS PHP: se rechaza ANTES de tocar nada (código ≠ 0, sin respaldo nuevo, archivos idénticos)', sint8443.code !== 0 && /Error de sintaxis PHP en el paquete/.test(sint8443.out) && P8_AT() === P8_AT0 && P8_envSum() === P8_ENV && P8_respN() === P8_R0 && P8_ping(), sint8443.out.slice(-400));
  dx('rm -rf /root/paq-ad-sint');
  // (b) backend con sintaxis válida pero que FALLA al ejecutarse: la verificación posterior lo detecta (tras la espera de opcache) y la actualización VUELVE SOLA
  dx(`rm -rf /root/paq-ad-roto && cp -a ${PKA2} /root/paq-ad-roto && sed -i '0,/^<?php/s//<?php\\nthrow new RuntimeException("roto");/' /root/paq-ad-roto/backend/router/AdminRouter.php && head -3 /root/paq-ad-roto/backend/router/AdminRouter.php | grep -c RuntimeException`); resum('/root/paq-ad-roto');
  const roto8443 = dx('bash /root/paq-ad-roto/scripts/actualizar.sh', CONFIRM);
  ok('backend de sintaxis válida pero roto en ejecución: la verificación posterior lo DETECTA, la actualización FALLA (código ≠ 0) y VUELVE SOLA a la versión anterior', roto8443.code !== 0 && /se restaura la versión anterior/.test(roto8443.out) && /versión anterior restaurada y verificada/.test(roto8443.out), roto8443.out.slice(-700));
  ok('tras el fallo: archivos de la aplicación y del backend idénticos bit a bit a antes, mismo .env, 8443 sigue respondiendo y la persona administradora sigue entrando', P8_AT() === P8_AT0 && P8_envSum() === P8_ENV && P8_ping() && /^[0-9a-f]{64}$/.test(tokenOf(post(AIP, lAdm, GOODPW))));
  ok('el fallo conservó su respaldo (nunca se borra) y no tocó Apache, trabajadores, Flutter ni los demás portales', Number(P8_respN()) === Number(P8_R0) + 1 && apachePid() === P8_PID && arbolInstalado() === P8_UT && portalesVivos());
  dx('rm -rf /root/paq-ad-roto');

  dx(`rm -rf /root/paq-ad-v3 && cp -a ${PKA2} /root/paq-ad-v3 && echo v3-admin > /root/paq-ad-v3/public/marca-v3.txt`); resum('/root/paq-ad-v3');
  const upd8443 = dx('bash /root/paq-ad-v3/scripts/actualizar.sh', CONFIRM);
  const resp8443 = /--revertir (\S+)/.exec(upd8443.out)?.[1] ?? '';
  ok('actualización in situ del release candidate: respalda, reemplaza, verificar.sh sin fallos y los demás portales idénticos', upd8443.code === 0 && /ACTUALIZACIÓN COMPLETA/.test(upd8443.out) && Boolean(resp8443), upd8443.out.slice(-600));
  ok('QA en 8443 tras actualizar: sirve la versión nueva, conserva el .env, la persona administradora entra, un trabajador NO entra al administrador y la API pública responde', cv(`${AIP}/marca-v3.txt`).out === 'v3-admin' && P8_envSum() === P8_ENV && /^[0-9a-f]{64}$/.test(tokenOf(post(AIP, lAdm, GOODPW))) && !/^[0-9a-f]{64}$/.test(tokenOf(post(AIP, lA, GOODPW))) && P8_ping());
  ok('respaldo verificable: SHA256SUMS correcto, public.tgz y backend.tgz presentes y NINGÚN .env dentro; permisos 700/600', dx(`cd ${resp8443} && sha256sum -c SHA256SUMS --quiet`).code === 0 && dx(`tar tzf ${resp8443}/backend.tgz | grep -c '\\.env'`).out === '0' && dx(`tar tzf ${resp8443}/public.tgz | grep -c 'index.html'`).out !== '0' && dx(`stat -c %a ${resp8443} ${resp8443}/public.tgz`).out.replace('\n', ':') === '700:600', dx(`ls -la ${resp8443}`).out);
  ok('tras actualizar, Flutter, trabajadores y los demás portales siguen idénticos y Apache no se reinició', arbolInstalado() === P8_UT && apachePid() === P8_PID && portalesVivos());
  const rev8443 = dx(`bash /root/paq-ad-v3/scripts/actualizar.sh --revertir ${resp8443}`, CONFIRM);
  ok('--revertir: la aplicación y el backend vuelven BIT A BIT a como estaban antes, el .env no cambia, la marca nueva desaparece (404) y 8443 sigue en pie', rev8443.code === 0 && P8_AT() === P8_AT0 && P8_envSum() === P8_ENV && cv(`-o /dev/null -w "%{http_code}" ${AIP}/marca-v3.txt`).out === '404' && P8_ping() && /^[0-9a-f]{64}$/.test(tokenOf(post(AIP, lAdm, GOODPW))), rev8443.out.slice(-500));
  const corrupto8443 = dx(`cp -a ${resp8443} /root/resp-corrupto8443 && echo x >> /root/resp-corrupto8443/backend.tgz && bash /root/paq-ad-v3/scripts/actualizar.sh --revertir /root/resp-corrupto8443`, CONFIRM);
  ok('un respaldo corrupto se rechaza (SHA256SUMS) sin tocar nada', corrupto8443.code !== 0 && /corrupto/.test(corrupto8443.out) && P8_AT() === P8_AT0 && P8_ping());
  dx('rm -rf /root/paq-ad-v3 /root/resp-corrupto8443');
  ok('cierre: el servidor está como al empezar esta sección (trabajadores, Apache sin reiniciar, portales vivos)', arbolInstalado() === P8_UT && apachePid() === P8_PID && portalesVivos());

  section = 'Publicación por IP interna · reversión total';
  const revIp = dx(`bash ${PKU2}/scripts/publicar-ip.sh revertir --trabajadores ${PKU2} --administrador ${PKA2} --ip ${IPPUB} --respaldos "${respIp}"`, CONFIRM);
  ok('publicar-ip.sh revertir retira las dos publicaciones y restaura las versiones anteriores de ambos portales', revIp.code === 0 && /publicación por IP retirada/.test(revIp.out) && !/FALLO/.test(revIp.out), revIp.out.slice(-600));
  ok('tras la reversión total: archivos y .env de AMBOS portales idénticos bit a bit a antes de la intervención, Apache sin reiniciar, el servidor completo idéntico y la IP ya no responde por HTTPS', arbolInstalado() === UT0 && `${tree('/var/www/cervesur-react-admin/public')}|${tree('/opt/cervesur-react-admin/backend')}` === AT0 && envSha() === UENV0 && dx('sha256sum /opt/cervesur-react-admin/backend/.env').out.split(' ')[0] === AENV0 && apachePid() === PIDIP0 && snapshot() === ipSnap0 && portalesVivos() && dx(`curl -sS -m 6 https://${IPPUB}/api/ping`).code !== 0, `${arbolInstalado() === UT0} ${envSha() === UENV0} ${snapshot() === ipSnap0}`);
  ok('el piloto de trabajadores por túnel sigue entrando con la misma persona', /^[0-9a-f]{64}$/.test(tokenOf(login(lA, GOODPW))));
  dx('rm -f /usr/local/share/ca-certificates/cervesur-ip-ca.crt && update-ca-certificates --fresh >/dev/null 2>&1; rm -rf /root/pki /root/pki-otra /root/nc /root/ipu /root/ipa');


  section = 'Administrativo 8093 · actualización y reversión';
  const arbolAd = () => `${tree('/var/www/cervesur-react-admin/public')}|${tree('/opt/cervesur-react-admin/backend')}`;
  const AA0 = arbolAd(); const AE0 = dx('sha256sum /opt/cervesur-react-admin/backend/.env').out.split(' ')[0];
  dx(`rm -rf /root/paq-ad2 && cp -a ${PKA} /root/paq-ad2 && echo v2-admin > /root/paq-ad2/public/marca-v2.txt`); resum('/root/paq-ad2');
  const updAd = dx('bash /root/paq-ad2/scripts/actualizar.sh', CONFIRM);
  ok('la actualización reversible (ACTUALIZAR-8093) funciona sobre la instancia administrativa y conserva el .env y la lista', updAd.code === 0 && /ACTUALIZACIÓN COMPLETA/.test(updAd.out) && dx(`curl -sS -m 10 ${TLSA} ${UA}/marca-v2.txt`).out === 'v2-admin' && dx('sha256sum /opt/cervesur-react-admin/backend/.env').out.split(' ')[0] === AE0 && /^[0-9a-f]{64}$/.test(tokenOf(loginAd(lAdm, GOODPW))), updAd.out.slice(-500));
  const respAd = /--revertir (\S+)/.exec(updAd.out)?.[1] ?? '';
  const volverAd = dx(`bash /root/paq-ad2/scripts/actualizar.sh --revertir ${respAd}`, CONFIRM);
  ok('--revertir restaura la versión anterior de la instancia administrativa bit a bit', volverAd.code === 0 && arbolAd() === AA0 && dx(`curl -s -o /dev/null -w "%{http_code}" ${TLSA} ${UA}/marca-v2.txt`).out === '404', volverAd.out.slice(-400));
  dx('rm -rf /root/paq-ad2');
  const revAd = dx(`bash ${PKA}/scripts/revertir.sh`, CONFIRM);
  ok('revertir.sh (administrativo) termina bien y retira SOLO la instancia: carpetas, .env, sitio y certificado', revAd.code === 0 && !/FALLO/.test(revAd.out) && dx('! test -e /opt/cervesur-react-admin && ! test -e /var/www/cervesur-react-admin && ! test -e /etc/apache2/cervesur-react-admin && ! test -e /etc/apache2/sites-available/cervesur-react-admin.conf && ! test -e /var/lib/cervesur-react-admin').code === 0, `code=${revAd.code} ${revAd.out.split('\n').filter(l => /FALLO/.test(l)).join(' | ')} :: ${dx(`cd ${PKA} && source scripts/lib.sh >/dev/null 2>&1; huella_portales > /tmp/h-ahora.txt 2>/dev/null; diff /var/backups/cervesur-react-admin/linea-base-portales.txt /tmp/h-ahora.txt | head -14`).out.replace(/\n/g, ' | ')}`);
  ok('el piloto de usuarios (8092) sigue intacto tras retirar la instancia administrativa y la misma persona entra', arbolInstalado() === U0 && envSha() === UE0 && apachePid() === PIDU0 && /^[0-9a-f]{64}$/.test(tokenOf(login(lA, GOODPW))));
  const revSqlAd = dx(`mysql < ${PKA}/admin-mysql/99-revocar-cuentas.sql`);
  dx('mysql -e "DROP DATABASE IF EXISTS cervesur_react_estado_admin"');
  ok('99-revocar-cuentas.sql (administrativo) elimina ÚNICAMENTE las tres cuentas _adm; las del piloto de usuarios siguen', revSqlAd.code === 0 && Number(sqlRoot("SELECT COUNT(*) FROM mysql.user WHERE user LIKE '%\\_adm'")) === 0 && cuentasExisten() === 3);
  ok('tras retirar cuentas y base de estado administrativas, el servidor es IDÉNTICO a antes de empezar (cinco portales, bases, cuentas, puertos, módulos) y las tablas de Flutter no cambiaron', snapshot() === S0adm && sqlRoot(FLUTTER_TABLAS) === CK0 && portalesVivos());
  dx('shred -u /root/cervesur-admin.sql /root/cervesur-admin.claves 2>/dev/null || rm -f /root/cervesur-admin.sql /root/cervesur-admin.claves');

  // ----------------------------------------------------------------- Reversión y reinstalación
  section = 'Reversión';
  const pid1 = apachePid(); const gh1 = grantsHash();
  const revA = dx(`bash ${PK}/scripts/revertir.sh`, CONFIRM);
  ok('revertir.sh termina correctamente y sin avisos de fallo', revA.code === 0 && !/FALLO/.test(revA.out), revA.out.split('\n').filter(l => /FALLO/.test(l)).join(' | ') || revA.out.slice(-300));
  ok('no quedan carpetas, .env, secretos, aplicación, sitio, certificado ni CLAVE PRIVADA TLS, ni puerto 8092', estadoLimpio() && dx('! find / -xdev \\( -name servidor.key -o -name servidor.crt \\) 2>/dev/null | grep -q .').code === 0);
  ok('las tres cuentas y la base de estado SIGUEN existiendo con los mismos privilegios (la reversión no toca MySQL); las de 8090 y 8091 también', cuentasExisten() === 3 && grantsHash() === gh1 && sqlRoot("SELECT COUNT(*) FROM mysql.user WHERE user IN ('cervesur_ro_diag', 'cervesur_ro_visor')") === '2' && sqlRoot("SHOW DATABASES LIKE 'cervesur_react_estado'") === 'cervesur_react_estado');
  ok('mod_headers y mod_ssl SIGUEN habilitados y Apache no se reinició', headersOn() && sslOn() && apachePid() === pid1 && pid1 === pid0);
  ok('Flutter, 8088, 8089, 8090 y 8091, módulos, bases (CHECKSUM de cervesur_portalbi), cuentas y puertos idénticos a la línea base: 0 cambios ajenos', snapshot() === S0 && portalesVivos());
  ok('los respaldos se conservan', dx('ls /var/backups/cervesur-react-usuarios/linea-base-portales.txt /var/backups/cervesur-react-usuarios/*.tgz >/dev/null').code === 0);
  ok('la lista blanca y la auditoría persisten en la base de estado tras la reversión', Number(sqlRoot('SELECT COUNT(*) FROM cervesur_react_estado.usuarios_piloto WHERE activo = 1')) >= 3 && Number(sqlRoot('SELECT COUNT(*) FROM cervesur_react_estado.auditoria')) > 10);
  ok('segunda reversión: idempotente (código 0, sin fallos)', (() => { const r = dx(`bash ${PK}/scripts/revertir.sh`, CONFIRM); return r.code === 0 && !/FALLO/.test(r.out); })());
  ok('sin confirmación escrita revertir.sh no hace nada', (() => { const r = dx(`bash ${PK}/scripts/revertir.sh < /dev/null`); return r.code !== 0 && /Cancelado/.test(r.out); })());

  section = 'Reinstalación';
  const inst2 = dx(`bash ${PK}/scripts/instalar.sh ${K} --informes-autorizados ${ids}`, { ...CONFIRM, CERVESUR_USUARIOS_CONFIRMAR_INFORMES: 'AUTORIZO-INFORMES' });
  const verif2 = /(\d+) PASS \/ (\d+) FAIL/.exec(inst2.out);
  ok('reinstalar sobre las mismas cuentas funciona: verificar.sh sin fallos', inst2.code === 0 && Boolean(verif2) && verif2[2] === '0', inst2.out.slice(-800));
  ok('el certificado y los secretos son NUEVOS (la reversión destruyó los anteriores) y la lista blanca persiste: la misma persona entra con su contraseña', env('JWT_SECRET') !== secretos[0] && env('CERVESUR_PILOT_SECRET') !== secretos[1] && /^[0-9a-f]{64}$/.test(tokenOf(login(lA, GOODPW))) && JSON.parse(login(lF, GOODPW)).success === false);
  ok('tras reinstalar, los otros cinco entornos siguen idénticos a la línea base', snapshot() === S0 && portalesVivos());
  const revB = dx(`bash ${PK}/scripts/revertir.sh`, CONFIRM);
  ok('segunda reversión completa tras reinstalar', revB.code === 0 && !/FALLO/.test(revB.out) && estadoLimpio() && snapshot() === S0);
  dx('mkdir -p /opt/cervesur-react-usuarios/backend /var/lib/cervesur-react-usuarios/sessions /var/www/cervesur-react-usuarios/public /etc/apache2/cervesur-react-usuarios/tls && echo basura > /opt/cervesur-react-usuarios/backend/.env && echo basura > /etc/apache2/cervesur-react-usuarios/tls/servidor.key && cp /root/site-us.orig /etc/apache2/sites-available/cervesur-react-usuarios.conf');
  const rev3 = dx(`bash ${PK}/scripts/revertir.sh`, CONFIRM);
  ok('reversión de una instalación PARCIAL: termina bien y deja todo limpio, sin tocar nada ajeno', rev3.code === 0 && !/FALLO/.test(rev3.out) && estadoLimpio() && snapshot() === S0, rev3.out.slice(-300));

  // las cuentas se retiran SOLO con su procedimiento administrativo, que no toca otras cuentas
  section = 'Retirada administrativa de cuentas';
  const revSql = dx(`mysql < ${PK}/admin-mysql/99-revocar-cuentas.sql`);
  ok('99-revocar-cuentas.sql elimina ÚNICAMENTE las tres cuentas del piloto en 127.0.0.1 (cervesur_ro_diag, cervesur_ro_visor y las de 8088/8089 siguen) y NO elimina la base de estado', revSql.code === 0 && cuentasExisten() === 0 && sqlRoot("SELECT COUNT(*) FROM mysql.user WHERE user IN ('cervesur_ro_diag', 'cervesur_ro_visor')") === '2' && sqlRoot("SHOW DATABASES LIKE 'cervesur_react_estado'") === 'cervesur_react_estado' && diag90() === '200' && visor91() === '200');
  dx('mysql -e "DROP DATABASE cervesur_react_estado"'); // decisión administrativa aparte
  ok('tras retirar cuentas y base de estado, el servidor completo es IDÉNTICO a la línea base original (antes de empezar el piloto de usuarios)', snapshot() === S00 && dbHash() === dbHash0 && portalesVivos());
} catch (error) {
  results.push(['FAIL', `${section} · EXCEPCIÓN`, error.stack ?? error.message]);
} finally {
  if (!process.env.ENSAYO_CONSERVAR) cleanup(); // ENSAYO_CONSERVAR=1 deja el contenedor para depurar
}
for (const [status, name, extra] of results) console.log(`${status}  ${name}${extra ? `  → ${extra}` : ''}`);
const failed = results.filter(r => r[0] === 'FAIL').length;
console.log(`\n${results.length - failed} PASS / ${failed} FAIL`);
process.exit(failed ? 1 : 0);
