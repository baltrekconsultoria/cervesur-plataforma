// ENSAYO LOCAL (Docker) del PILOTO VISUAL con informes reales (UX-07D.4). No se conecta a ningún servidor real.
//   node deploy/tunel-ssh-piloto/ensayo-docker.mjs
// Réplica de ensayo: Ubuntu 24.04 + Apache 2.4 + mod_php 8.3 + MySQL 8 (validate_password MEDIUM) y CINCO portales a la vez:
//   Flutter simulado (80) · React de prueba (127.0.0.1:8088) · React de compatibilidad (127.0.0.1:8089) · diagnóstico (127.0.0.1:8090), todos instalados con SUS instaladores,
//   y el piloto visual (127.0.0.1:8091, los scripts nuevos), contra una base SINTÉTICA llamada cervesur_portalbi (fixture de UX-07D.2B/2C).
// La cuenta cervesur_ro_visor la crea el ensayo con el procedimiento administrativo independiente (admin-mysql/), NUNCA el instalador. Un navegador real (Edge/Chrome)
// recorre el piloto a través de un túnel simulado (socat 18098 → 8091).
import { spawnSync } from 'node:child_process';
import { createHash, randomBytes } from 'node:crypto';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';
import { launch } from '../../frontend/e2e/cdp.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const REPO = join(here, '..', '..');
const IMAGE = 'cervesur-ensayo-ubuntu:local';
const NAME = 'cervesur-ensayo-piloto';
const TUNEL = Number(process.env.ENSAYO_PORT_PILOTO ?? 18098);
const BASE = `http://localhost:${TUNEL}`;
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
const cleanup = () => docker(['rm', '--force', '--volumes', NAME]);
const newest = prefix => readdirSync(join(REPO, 'dist-paquete')).filter(f => f.startsWith(prefix) && f.endsWith('.tar.gz')).map(f => join(REPO, 'dist-paquete', f)).sort((a, b) => statSync(b).mtimeMs - statSync(a).mtimeMs)[0];
const constants = file => Object.fromEntries([...readFileSync(file, 'utf8').matchAll(/^([A-Z_]+)=([^\n(#]+?)\s*(?:#.*)?$/gm)].map(m => [m[1], m[2].replace(/^\$\{.*$/, '')]));
const CONFIRM = { CERVESUR_PILOTO_CONFIRMAR: 'SI' };
const VISOR = 'cervesur_ro_visor';
const sqlRoot = q => dx(`mysql --default-character-set=utf8mb4 -N -B -e "${q.replaceAll('"', '\\"')}"`).out;

try {
  cleanup();
  section = 'Aislamiento de nombres y recursos';
  const libOf = dir => constants(join(REPO, 'deploy', dir, 'scripts', 'lib.sh'));
  const old = libOf('tunel-ssh'); const compat = libOf('tunel-ssh-compat'); const diag = libOf('tunel-ssh-readonly'); const neu = libOf('tunel-ssh-piloto');
  const keys = ['SITIO', 'PUERTO', 'DIR_CONF', 'SITE_CONF', 'DIR_RESPALDO', 'COOKIE_SESION'];
  ok('ningún nombre de sitio, puerto, ruta, respaldo ni cookie coincide con los de 8088, 8089 ni 8090', keys.every(k => neu[k] && [old, compat, diag].every(o => o[k] !== neu[k])), keys.filter(k => [old, compat, diag].some(o => o[k] === neu[k])).join(','));
  const overlap = (a, b) => `${a}/`.startsWith(`${b}/`) || `${b}/`.startsWith(`${a}/`);
  const others = ['/var/www/cervesur', old.DIR_FRONT, old.DIR_BACK, old.DIR_CONF, old.DIR_RESPALDO, compat.DIR_FRONT, compat.DIR_BACK, compat.DIR_CONF, compat.DIR_VAR, compat.DIR_RESPALDO, diag.DIR_BACK, diag.DIR_CONF, diag.DIR_VAR, diag.DIR_RESPALDO];
  ok('ninguna ruta del piloto contiene ni está contenida en la de Flutter, 8088, 8089 ni 8090', ['DIR_FRONT', 'DIR_BACK', 'DIR_CONF', 'DIR_VAR', 'DIR_RESPALDO'].every(k => others.every(o => !overlap(neu[k], o))));
  ok('puertos 80, 8088, 8089, 8090 y 8091 distintos; la cuenta MySQL del piloto es propia (distinta de cervesur_ro_diag); cookie propia en el código de Apache', neu.PUERTO === '8091' && diag.PUERTO === '8090' && neu.DB_USUARIO === VISOR && neu.DB_USUARIO !== diag.DB_USUARIO && /session\.name CERVESUR_REACT_PILOTO\b/.test(readFileSync(join(REPO, 'deploy', 'tunel-ssh-piloto', 'apache', 'react-piloto-common.conf'), 'utf8')));
  const shellFiles = ['instalar', 'pasos-instalacion', 'verificar', 'revertir', 'lib', 'preflight-infra', 'preflight-acceso', 'operador', 'informes'].map(n => join(here, 'scripts', `${n}.sh`)).concat(['preparar-sql-cuenta', 'mostrar-sql-redactado'].map(n => join(here, 'admin-mysql', `${n}.sh`)));
  ok('sintaxis bash correcta en todos los scripts', shellFiles.every(f => spawnSync('bash', ['-n', f]).status === 0));

  section = 'Paquetes';
  const packPi = spawnSync(process.execPath, [join(here, 'empaquetar.mjs')], { cwd: REPO, encoding: 'utf8' });
  ok('empaquetar.mjs (piloto) compila la aplicación del piloto y genera el paquete', packPi.status === 0, packPi.stderr || packPi.stdout.slice(-400));
  const pack88 = spawnSync(process.execPath, [join(REPO, 'deploy', 'tunel-ssh', 'empaquetar.mjs'), '--sin-compilar'], { cwd: REPO, encoding: 'utf8' });
  const pack89 = spawnSync(process.execPath, [join(REPO, 'deploy', 'tunel-ssh-compat', 'empaquetar.mjs')], { cwd: REPO, encoding: 'utf8' });
  const pack90 = spawnSync(process.execPath, [join(REPO, 'deploy', 'tunel-ssh-readonly', 'empaquetar.mjs')], { cwd: REPO, encoding: 'utf8' });
  ok('los paquetes de 8088, 8089 y 8090 ("portales existentes") se generan', pack88.status === 0 && pack89.status === 0 && pack90.status === 0, `${pack88.stderr}${pack89.stderr}${pack90.stderr}`);
  const tgzPi = newest('cervesur-react-piloto-'); const tgz88 = newest('cervesur-react-prueba-'); const tgz89 = newest('cervesur-react-compat-'); const tgz90 = newest('cervesur-react-ro-');
  if (!tgzPi || !tgz88 || !tgz89 || !tgz90) throw new Error('Faltan los paquetes');
  const shaPi = createHash('sha256').update(readFileSync(tgzPi)).digest('hex');
  ok('el .sha256 externo coincide con el SHA-256 del .tar.gz', readFileSync(`${tgzPi}.sha256`, 'utf8').startsWith(shaPi));

  docker(['build', '--quiet', '--tag', IMAGE, join(REPO, 'deploy', 'tunel-ssh', 'ensayo')], { allowFail: false });
  docker(['run', '--detach', '--name', NAME, '--publish', `127.0.0.1:${TUNEL}:18092`, IMAGE], { allowFail: false });
  for (let i = 0; ; i++) { if (dx('curl -fs http://127.0.0.1/ >/dev/null && mysqladmin ping --silent').code === 0) break; if (i > 90) throw new Error('El contenedor no arrancó'); await sleep(1000); }
  for (const [tgz, dest] of [[tgzPi, 'pi'], [tgz88, 'prueba'], [tgz89, 'compat'], [tgz90, 'ro']]) docker(['cp', tgz, `${NAME}:/root/${dest}.tar.gz`], { allowFail: false });
  const first = cmd => dx(cmd).out.split('\n').pop();
  const PK = `/root/${first('cd /root && tar xzf pi.tar.gz && ls -d cervesur-react-piloto-*')}`;
  const PK88 = `/root/${first('cd /root && tar xzf prueba.tar.gz && ls -d cervesur-react-prueba-*')}`;
  const PK89 = `/root/${first('cd /root && tar xzf compat.tar.gz && ls -d cervesur-react-compat-*')}`;
  const PK90 = `/root/${first('cd /root && tar xzf ro.tar.gz && ls -d cervesur-react-ro-*')}`;
  ok('los cuatro paquetes se extraen y su SHA256SUMS interno es válido', [PK, PK88, PK89, PK90].every(p => dx(`cd ${p} && sha256sum -c SHA256SUMS --quiet`).code === 0));
  ok('paquete del piloto: scripts con LF, sin .env, con la aplicación del piloto (build-info pilot=true) y sin tests; VERSION identifica el código probado', dx(`! grep -rlI $'\\r' ${PK}/scripts ${PK}/apache ${PK}/admin-mysql && ! find ${PK} -name ".env*" | grep -q . && test -f ${PK}/public/index.html && grep -q '"pilot": true' ${PK}/public/build-info.json && ! test -d ${PK}/backend/tests && grep -q '^commit: ' ${PK}/VERSION && grep -q '^contenido-sha256: [0-9a-f]\\{64\\}' ${PK}/VERSION`).code === 0);
  ok('el nombre declara «-con-cambios-<huella>» cuando hay cambios locales sin confirmar', /cervesur-react-piloto-[0-9a-f]{12}(-con-cambios-[0-9a-f]{12})?-\d{8}-\d{6}Z\.tar\.gz$/.test(tgzPi) && (spawnSync('git', ['status', '--porcelain'], { cwd: REPO, encoding: 'utf8' }).stdout.trim() === '' || /-con-cambios-[0-9a-f]{12}-/.test(tgzPi)));
  ok('el paquete no contiene secretos (token, clave, hash del operador, JWT) ni la cuenta del diagnóstico', dx(`! grep -rIlE 'JWT_SECRET=[0-9a-f]{32}|OPERATOR_HASH=.2y|DB_PASS=[^%$ ]{8}' ${PK} && test ! -e ${PK}/admin-mysql/01-cuenta-diagnostico.sql`).code === 0);
  ok('la aplicación del piloto NO incluye las pantallas de alta, edición ni asignación (solo catálogo, visor y perfil de lectura)', dx(`! cat ${PK}/public/assets/*.js | grep -q 'Nuevo usuario\\|Crear usuario\\|Nueva empresa\\|Nuevo reporte\\|Asignar'`).code === 0);
  ok('el paquete incluye solo lo del piloto en admin-mysql: 01, 02, 99 de la cuenta del visor, generador y visor redactado; nada de diagnóstico', dx(`cd ${PK}/admin-mysql && ls 01-cuenta-visor.sql 02-verificar-privilegios-visor.sql 99-revocar-cuenta-visor.sql mostrar-sql-redactado.sh preparar-sql-cuenta.sh >/dev/null && ! ls *diagnostico* 2>/dev/null | grep -q .`).code === 0);

  // --- Portales "existentes": se instalan con SUS instaladores, como en el servidor ---
  section = 'Portales existentes (80, 8088, 8089 y 8090)';
  const inst88 = dx(`bash ${PK88}/scripts/instalar.sh`, { CERVESUR_CONFIRMAR: 'SI' });
  const inst89 = dx(`bash ${PK89}/scripts/instalar.sh`, { CERVESUR_COMPAT_CONFIRMAR: 'SI' });
  ok('los instaladores de 8088 y 8089 dejan los portales funcionando', inst88.code === 0 && inst89.code === 0 && /\d+ PASS \/ 0 FAIL/.test(inst88.out) && /\d+ PASS \/ 0 FAIL/.test(inst89.out), `${inst88.out.slice(-200)} ${inst89.out.slice(-200)}`);
  put('/root/portalbi.php', readFileSync(join(REPO, 'deploy', 'tunel-ssh-readonly', 'ensayo', 'sembrar-portalbi.php'), 'utf8'));
  put('/root/productive_fixture.php', readFileSync(join(REPO, 'tests', 'isolated', 'productive_fixture.php'), 'utf8'));
  const seed = dx('php /root/portalbi.php /root/productive_fixture.php');
  ok('base SINTÉTICA cervesur_portalbi creada en el MySQL desechable (agregados del inventario verificados)', seed.code === 0 && /creada/.test(seed.out), seed.out);
  // 8090 (diagnóstico): su cuenta con su procedimiento y su instalador
  const prep90 = dx(`bash ${PK90}/admin-mysql/preparar-sql-cuenta.sh --salida /root/diag90`);
  const mk90 = dx('mysql < /root/diag90.sql');
  const inst90 = dx(`bash ${PK90}/scripts/instalar.sh --clave-archivo /root/diag90.clave`, { CERVESUR_RO_CONFIRMAR: 'SI' });
  ok('el diagnóstico 8090 se instala con su cuenta cervesur_ro_diag y su instalador (como en el servidor)', prep90.code === 0 && mk90.code === 0 && inst90.code === 0 && /\d+ PASS \/ 0 FAIL/.test(inst90.out), inst90.out.slice(-300));
  const diagToken = dx('grep "^CERVESUR_DIAG_TOKEN=" /opt/cervesur-react-ro/backend/.env | cut -d= -f2-').out;
  const diag90 = () => dx(`printf 'header = "X-Diagnostic-Token: %s"\\n' "${diagToken}" | curl -sS -m 10 -K - -o /dev/null -w '%{http_code}' http://127.0.0.1:8090/api/diagnostico/resumen`).out;
  ok('8090 responde con su token antes de empezar', diag90() === '200');

  const apachePid = () => dx('cat /var/run/apache2/apache2.pid').out;
  const headersOn = () => dx('test -e /etc/apache2/mods-enabled/headers.load').code === 0;
  const SNAP = `set -u
tree() { if [ -d "$1" ]; then (cd "$1" && find . -type f -printf '%p %s %T@ %m %u:%g\\n' | LC_ALL=C sort | sha256sum | cut -c1-64); else echo ausente; fi; }
http() { curl -sS -m 6 -o /dev/null -D - "$1" | tr -d '\\r' | awk 'NR==1{print;next} /^[A-Za-z-]+:/{print tolower(substr($0,1,index($0,":")-1))}' | LC_ALL=C sort; curl -sS -m 6 "$1" | sha256sum | cut -c1-64; }
echo "== configuracion"; sha256sum /etc/apache2/sites-available/cervesur.conf /var/www/cervesur/.htaccess /etc/apache2/sites-available/cervesur-react-prueba.conf /etc/apache2/cervesur-react-prueba/react-common.conf /opt/cervesur-react-prueba/backend/.env /etc/apache2/sites-available/cervesur-react-compat.conf /etc/apache2/cervesur-react-compat/react-compat-common.conf /opt/cervesur-react-compat/backend/.env /etc/apache2/sites-available/cervesur-react-ro.conf /etc/apache2/cervesur-react-ro/react-ro-common.conf /opt/cervesur-react-ro/backend/.env /etc/apache2/apache2.conf /etc/apache2/ports.conf
echo "== arboles"; tree /var/www/cervesur; tree /var/www/cervesur-react-prueba; tree /opt/cervesur-react-prueba; tree /var/www/cervesur-react-compat; tree /opt/cervesur-react-compat; tree /opt/cervesur-react-ro; tree /var/lib/cervesur-react-ro
echo "== modulos"; apache2ctl -M 2>/dev/null | LC_ALL=C sort | sha256sum | cut -c1-64; ls /etc/apache2/mods-enabled | LC_ALL=C sort | sha256sum | cut -c1-64
echo "== http"; for u in http://127.0.0.1/ http://127.0.0.1/ruta/profunda http://127.0.0.1:8088/ http://127.0.0.1:8088/api/ping http://127.0.0.1:8089/ http://127.0.0.1:8089/api/ping http://127.0.0.1:8090/api/ping; do echo "$u"; http "$u"; done
echo "== mysql"; mysql -N -e 'SHOW DATABASES' | LC_ALL=C sort; mysql -N -e 'SELECT user, host FROM mysql.user' | LC_ALL=C sort
for d in cervesur_react_prueba cervesur_react_compat_qa cervesur_portalbi; do for t in $(mysql -N -e 'SHOW TABLES' $d); do echo "$d.$t $(mysql -N -e "CHECKSUM TABLE $t" $d)"; done; done
echo "== puertos"; ss -ltnH | awk '{print $4}' | grep -v ':8091$' | grep -v ':18092$' | LC_ALL=C sort -u
echo "== sitios"; ls /etc/apache2/sites-enabled | grep -v '^cervesur-react-piloto.conf$' | LC_ALL=C sort
`;
  put('/root/snap.sh', SNAP);
  const snapshot = () => dx('bash /root/snap.sh').out;
  const grantsHash = () => dx(`mysql -N -e "SELECT user, host FROM mysql.user WHERE user='${VISOR}'" | while read u h; do mysql -N -e "SHOW GRANTS FOR '$u'@'$h'"; done | sha256sum | cut -c1-64`).out;
  const dbHash = () => dx('mysql -N -e "SHOW DATABASES" | LC_ALL=C sort | sha256sum | cut -c1-64').out;
  const estadoLimpio = () => dx('! test -e /opt/cervesur-react-piloto && ! test -e /var/www/cervesur-react-piloto && ! test -e /etc/apache2/cervesur-react-piloto && ! test -e /etc/apache2/sites-available/cervesur-react-piloto.conf && ! test -e /etc/apache2/sites-enabled/cervesur-react-piloto.conf && ! test -e /var/lib/cervesur-react-piloto && [ -z "$(ss -ltnH \'sport = :8091\')" ]').code === 0;
  const cuentaExiste = () => dx(`[ "$(mysql -N -e "SELECT COUNT(*) FROM mysql.user WHERE user='${VISOR}'")" -gt 0 ]`).code === 0;
  const S00 = snapshot(); const pid0 = apachePid(); const dbHash0 = dbHash();
  ok('línea base de los cuatro portales, módulos, bases (CHECKSUM de las tablas de cervesur_portalbi incluido), cuentas, puertos y sitios', S00.length > 1000 && /cervesur_portalbi\.user /.test(S00) && /8090/.test(S00));

  // ----------------------------------------------------------------- Preflight A y B
  section = 'Preflight de infraestructura (A)';
  const pa = dx(`bash ${PK}/scripts/preflight-infra.sh`);
  ok('A correcto SIN la cuenta del piloto: Flutter, 8088, 8089 y 8090 existen y responden; paquete íntegro y con la aplicación del piloto', pa.code === 0 && /preflight A \(infraestructura\): PASS/.test(pa.out) && /Diagnóstico \(8090\) instalado y responde/.test(pa.out) && /compilación del piloto/.test(pa.out), pa.out.slice(-700));
  ok('A no modifica nada', estadoLimpio() && snapshot() === S00);

  section = 'Preflight de acceso (B) sin cuenta';
  ok('la cuenta cervesur_ro_visor todavía NO existe', !cuentaExiste());
  const pbNone = dx(`bash ${PK}/scripts/preflight-acceso.sh --clave-archivo /nonexistente`);
  ok('B queda PENDIENTE (código 3), nunca PASS', pbNone.code === 3 && /PENDIENTE/.test(pbNone.out) && !/preflight B \(acceso\): PASS/.test(pbNone.out), pbNone.out);
  const simNone = dx(`bash ${PK}/scripts/instalar.sh --simular`);
  ok('instalar.sh --simular: infraestructura PASS, acceso PENDIENTE, código 3 y "NO se puede instalar todavía"', simNone.code === 3 && /infraestructura PASS · acceso PENDIENTE/.test(simNone.out) && /NO se puede instalar todavía/.test(simNone.out), simNone.out.slice(-400));
  const realNone = dx(`bash ${PK}/scripts/instalar.sh --clave-archivo /nonexistente`, CONFIRM);
  ok('instalar.sh real sin cuenta se NIEGA («No se modificó nada») y no deja restos', realNone.code !== 0 && /PENDIENTE/.test(realNone.out) && /No se modificó nada/.test(realNone.out) && estadoLimpio() && dx('test -e /var/backups/cervesur-react-piloto').code !== 0 && snapshot() === S00);

  section = 'Procedimiento administrativo independiente (crear cervesur_ro_visor)';
  const prep = dx(`bash ${PK}/admin-mysql/preparar-sql-cuenta.sh`);
  const clave = dx('head -n 1 /root/cervesur-ro-visor.clave').out;
  ok('preparar-sql-cuenta.sh genera SQL y clave (600) SIN mostrar la clave y SIN conectarse a MySQL', prep.code === 0 && !prep.out.includes(clave) && dx('stat -c "%a %U" /root/cervesur-ro-visor.sql /root/cervesur-ro-visor.clave').out === '600 root\n600 root' && !cuentaExiste(), prep.out);
  const sqlText = dx('cat /root/cervesur-ro-visor.sql').out;
  ok('el SQL: un CREATE USER y tres GRANT SELECT por columna (15 columnas), host 127.0.0.1, base cervesur_portalbi, nada más', (sqlText.match(/^CREATE USER/gm) ?? []).length === 1 && (sqlText.match(/^GRANT SELECT \(/gm) ?? []).length === 3 && !/^GRANT (?!SELECT \()/m.test(sqlText) && /@'127\.0\.0\.1'/.test(sqlText) && /`cervesur_portalbi`\.`reporte`/.test(sqlText) && !/`user`|powerbi|password/i.test(sqlText.replace(/^--.*$/gm, '')) && !/__[A-Z_]+__/.test(sqlText));
  ok('REDACCIÓN: la clave aparece exactamente UNA vez (en CREATE USER) y en NINGÚN comentario; el visor redactado la oculta', dx(`grep -cF -- "$(head -n 1 /root/cervesur-ro-visor.clave)" /root/cervesur-ro-visor.sql || true`).out === '1' && dx(`grep -E '^[[:space:]]*--' /root/cervesur-ro-visor.sql | grep -cF -- "$(head -n 1 /root/cervesur-ro-visor.clave)" || true`).out === '0' && !dx(`bash ${PK}/admin-mysql/mostrar-sql-redactado.sh /root/cervesur-ro-visor.sql`).out.includes(clave));
  const crear = (mutacion = ':') => { dx(`mysql -e "DROP USER IF EXISTS '${VISOR}'@'127.0.0.1'"`); return dx(`cp /root/cervesur-ro-visor.sql /root/var.sql && ${mutacion} && mysql < /root/var.sql`); };
  const aniadir = sql => `printf '%s\\n' "${sql.replace(/"/g, '\\"').replace(/`/g, '\\`')}" >> /root/var.sql`;
  const casos = [
    ['permisos INSUFICIENTES (falta reporte.enlace)', 'sed -i "s/, activo, enlace)/, activo)/" /root/var.sql', /FALTAN: reporte\.enlace/],
    ['EXCESO: INSERT sobre reporte', aniadir(`GRANT INSERT ON \`cervesur_portalbi\`.\`reporte\` TO '${VISOR}'@'127.0.0.1';`), /SHOW GRANTS: ningún privilegio/],
    ['EXCESO: SELECT sobre la tabla user (hashes)', aniadir(`GRANT SELECT (id, username) ON \`cervesur_portalbi\`.\`user\` TO '${VISOR}'@'127.0.0.1';`), /SOBRAN: user\.id/],
    ['EXCESO: credenciales de Power BI (empresa.powerbi_clientsecret)', aniadir(`GRANT SELECT (powerbi_clientsecret) ON \`cervesur_portalbi\`.\`empresa\` TO '${VISOR}'@'127.0.0.1';`), /SOBRAN: empresa\.powerbi_clientsecret/],
    ['EXCESO: SELECT sobre toda la base', aniadir(`GRANT SELECT ON \`cervesur_portalbi\`.* TO '${VISOR}'@'127.0.0.1';`), /sin privilegios a nivel de base de datos/],
    ['EXCESO: privilegio global (PROCESS)', aniadir(`GRANT PROCESS ON *.* TO '${VISOR}'@'127.0.0.1';`), /SHOW GRANTS: ningún privilegio|sin privilegios globales/],
    ['EXCESO: GRANT OPTION', aniadir(`GRANT SELECT (id) ON \`cervesur_portalbi\`.\`reporte\` TO '${VISOR}'@'127.0.0.1' WITH GRANT OPTION;`), /SHOW GRANTS: ningún privilegio|ningún privilegio adicional/],
  ];
  section = 'Preflight de acceso (B) con cuentas incorrectas';
  for (const [label, mutation, pattern] of casos) {
    const created = crear(mutation); const gh = grantsHash();
    const r = dx(`bash ${PK}/scripts/preflight-acceso.sh --clave-archivo /root/cervesur-ro-visor.clave`);
    const sim = dx(`bash ${PK}/scripts/instalar.sh --simular --clave-archivo /root/cervesur-ro-visor.clave`); const real = dx(`bash ${PK}/scripts/instalar.sh --clave-archivo /root/cervesur-ro-visor.clave`, CONFIRM);
    ok(`cuenta con ${label}: B falla (código 1), lo explica y nadie la corrige; --simular (1) e instalar real se NIEGAN sin dejar restos`, created.code === 0 && r.code === 1 && pattern.test(r.out) && /preflight B \(acceso\): FAIL/.test(r.out) && grantsHash() === gh && sim.code === 1 && real.code !== 0 && /No se modificó nada/.test(real.out) && estadoLimpio() && dx('test -e /var/backups/cervesur-react-piloto').code !== 0, `${created.out}\n${r.out.slice(-600)}`);
  }
  section = 'Preflight correcto (A y B)';
  const created = crear();
  ok('la cuenta se crea con el SQL del procedimiento administrativo (el ensayo actúa como administrador)', created.code === 0 && cuentaExiste(), created.out);
  const v02 = dx(`mysql -B < ${PK}/admin-mysql/02-verificar-privilegios-visor.sql`);
  ok('02-verificar-privilegios-visor.sql: 15 columnas (empresa 5 · empresa_reporte 4 · reporte 6), sin privilegios globales ni de escritura', v02.code === 0 && /total_columnas_concedidas\n15\b/.test(v02.out) && /^empresa\t5\t/m.test(v02.out) && /^empresa_reporte\t4\t/m.test(v02.out) && /^reporte\t6\t/m.test(v02.out) && !/PROCESS|INSERT|UPDATE|DELETE/.test(v02.out), v02.out);
  const pb = dx(`bash ${PK}/scripts/preflight-acceso.sh --clave-archivo /root/cervesur-ro-visor.clave`);
  ok('B correcto (código 0): autentica, solo lectura, 15 columnas exactas, sin la tabla user, sin credenciales de Power BI, sin otras bases, catálogo 8/8', pb.code === 0 && /preflight B \(acceso\): PASS/.test(pb.out) && /están concedidas las 15 columnas/.test(pb.out) && /8\/8 consultas/.test(pb.out) && /DENEGADAS \(16\/16\)/.test(pb.out), pb.out);
  ok('B no imprime la clave ni cifras de las tablas', !pb.out.includes(clave) && !/\b(76|82|95|102)\b/.test(pb.out));
  const S0 = snapshot();
  const sim = dx(`bash ${PK}/scripts/instalar.sh --simular --clave-archivo /root/cervesur-ro-visor.clave`);
  ok('instalar.sh --simular: infraestructura PASS y acceso PASS (código 0), sin modificar nada', sim.code === 0 && /infraestructura PASS · acceso PASS/.test(sim.out) && estadoLimpio() && snapshot() === S0, sim.out.slice(-300));

  // ----------------------------------------------------------------- Fallos de infraestructura
  section = 'Fallos de infraestructura';
  const K = `--clave-archivo /root/cervesur-ro-visor.clave`;
  dx(`rm -rf /root/p-tamper && cp -r ${PK} /root/p-tamper && echo x >> /root/p-tamper/public/index.html`);
  const tamper = dx(`bash /root/p-tamper/scripts/instalar.sh --simular ${K}`);
  ok('paquete con checksum incorrecto (aplicación alterada): el preflight A lo rechaza antes de instalar', tamper.code !== 0 && /integridad del paquete/.test(tamper.out) && estadoLimpio() && snapshot() === S0);
  dx('phpdismod pdo_mysql'); const sinDep = dx(`bash ${PK}/scripts/instalar.sh --simular ${K}`); dx('phpenmod pdo_mysql');
  ok('dependencia PHP faltante (pdo_mysql): el instalador se NIEGA y no modifica nada', sinDep.code !== 0 && /extensiones del backend/.test(sinDep.out) && estadoLimpio());
  dx('a2dismod headers >/dev/null && apache2ctl graceful'); await sleep(1500);
  const sinH = dx(`bash ${PK}/scripts/instalar.sh --simular ${K}`);
  dx('a2enmod headers >/dev/null && apache2ctl graceful'); await sleep(1500);
  ok('sin mod_headers cargado el instalador se NIEGA (no lo habilita) y mod_headers se restaura intacto', sinH.code !== 0 && /mod_headers YA cargado/.test(sinH.out) && estadoLimpio() && headersOn());
  dx(`rm -rf /root/p-nopilot && cp -r ${PK} /root/p-nopilot && sed -i 's/"pilot": true/"pilot": false/' /root/p-nopilot/public/build-info.json && cd /root/p-nopilot && find . -type f ! -name SHA256SUMS -print0 | xargs -0 sha256sum > SHA256SUMS`);
  const noPilot = dx(`bash /root/p-nopilot/scripts/instalar.sh --simular ${K}`);
  ok('un paquete con una aplicación que NO es la del piloto (pilot=false) se rechaza aunque su checksum sea válido', noPilot.code !== 0 && /compilación del piloto/.test(noPilot.out) && estadoLimpio());
  const colisiones = [
    ['el puerto 8091 está ocupado', 'setsid nohup socat TCP-LISTEN:8091,bind=127.0.0.1,fork,reuseaddr SYSTEM:true >/dev/null 2>&1 & sleep 1', "pkill -f 'TCP-LISTEN:809[1]' || true", /puerto 8091 libre/],
    ['la carpeta /opt/cervesur-react-piloto ya existe', 'mkdir /opt/cervesur-react-piloto', 'rmdir /opt/cervesur-react-piloto', /no existe \/opt\/cervesur-react-piloto/],
    ['la carpeta /var/www/cervesur-react-piloto ya existe', 'mkdir /var/www/cervesur-react-piloto', 'rmdir /var/www/cervesur-react-piloto', /no existe \/var\/www\/cervesur-react-piloto/],
    ['la carpeta /var/lib/cervesur-react-piloto ya existe', 'mkdir /var/lib/cervesur-react-piloto', 'rmdir /var/lib/cervesur-react-piloto', /no existe \/var\/lib\/cervesur-react-piloto/],
    ['el sitio Apache cervesur-react-piloto ya existe', 'echo "# ajeno" > /etc/apache2/sites-available/cervesur-react-piloto.conf', 'rm -f /etc/apache2/sites-available/cervesur-react-piloto.conf', /no existe \/etc\/apache2\/sites-available\/cervesur-react-piloto\.conf/],
    ['la configuración de Apache ya menciona el puerto 8091', 'echo "# Listen 127.0.0.1:8091" > /etc/apache2/conf-enabled/ajeno-8091.conf', 'rm -f /etc/apache2/conf-enabled/ajeno-8091.conf', /ninguna configuración de Apache menciona el puerto 8091/],
  ];
  for (const [label, arm, disarm, pattern] of colisiones) {
    dx(arm); const r = dx(`bash ${PK}/scripts/instalar.sh ${K}`, CONFIRM);
    dx(disarm); await sleep(300);
    ok(`colisión (${label}): el instalador ABORTA con «No se modificó nada» y no crea el respaldo`, r.code !== 0 && pattern.test(r.out) && /No se modificó nada/.test(r.out) && dx('test -e /var/backups/cervesur-react-piloto').code !== 0, r.out.slice(-300));
  }
  ok('tras las colisiones el servidor sigue idéntico a la línea base y sin restos', estadoLimpio() && snapshot() === S0);

  section = 'Fallo forzado a mitad de instalación';
  dx(`rm -rf /root/p-roto && cp -r ${PK} /root/p-roto && echo "DirectivaInexistente x" >> /root/p-roto/apache/react-piloto-common.conf && cd /root/p-roto && find . -type f ! -name SHA256SUMS -print0 | xargs -0 sha256sum > SHA256SUMS`);
  const gh0 = grantsHash();
  const roto = dx(`bash /root/p-roto/scripts/instalar.sh ${K}`, CONFIRM);
  ok('la instalación falla (configtest rechaza la configuración) y revierte automáticamente SOLO lo creado', roto.code !== 0 && /se revierte automáticamente/.test(roto.out), roto.out.slice(-500));
  ok('sin restos de 8091 (ni .env, ni clave del operador, ni aplicación); la cuenta del visor sigue igual; la base y los demás portales, intactos', estadoLimpio() && cuentaExiste() && grantsHash() === gh0 && snapshot() === S0);
  ok('mod_headers SIGUE habilitado, Apache conserva su proceso maestro y los respaldos se conservan', headersOn() && apachePid() === pid0 && dx('ls /var/backups/cervesur-react-piloto/*.tgz >/dev/null').code === 0);
  ok('ni la clave de la cuenta ni ningún secreto aparecen en la salida del fallo', !roto.out.includes(clave));

  // ----------------------------------------------------------------- Instalación
  section = 'Instalación (informes autorizados: dos)';
  const ids = sqlRoot('SELECT GROUP_CONCAT(id) FROM (SELECT id FROM cervesur_portalbi.reporte WHERE activo = 1 ORDER BY id LIMIT 2) x');
  const sinFrase = dx(`bash ${PK}/scripts/instalar.sh ${K} --informes-autorizados ${ids} < /dev/null`, CONFIRM);
  ok('autorizar informes SIN la segunda confirmación escrita cancela la instalación y no deja restos', sinFrase.code !== 0 && /Cancelado/.test(sinFrase.out) && estadoLimpio() && snapshot() === S0);
  const inst = dx(`bash ${PK}/scripts/instalar.sh ${K} --informes-autorizados ${ids}`, { ...CONFIRM, CERVESUR_PILOTO_CONFIRMAR_INFORMES: 'AUTORIZO-INFORMES' });
  ok('instalar.sh termina correctamente', inst.code === 0, inst.out.slice(-1200));
  const verif = /(\d+) PASS \/ (\d+) FAIL/.exec(inst.out);
  ok('verificar.sh integrado: todas las comprobaciones pasan', Boolean(verif) && verif[2] === '0' && Number(verif[1]) >= 24, verif?.[0] ?? inst.out.slice(-500));
  const pass = dx('head -n 1 /opt/cervesur-react-piloto/operador.clave').out;
  const jwt = dx('grep "^JWT_SECRET=" /opt/cervesur-react-piloto/backend/.env | cut -d= -f2-').out;
  const dbPass = dx('grep "^DB_PASS=" /opt/cervesur-react-piloto/backend/.env | cut -d= -f2-').out;
  ok('la clave del operador (24 alfanuméricos) y el JWT (256 bits) son nuevos, únicos y distintos de cualquier secreto de 8088, 8089 y 8090; en el .env solo está el HASH bcrypt', /^[A-Za-z0-9]{24}$/.test(pass) && /^[0-9a-f]{64}$/.test(jwt) && dx(`! grep -rqF -e "${jwt}" -e "${pass}" /opt/cervesur-react-prueba /opt/cervesur-react-compat /opt/cervesur-react-ro && ! grep -qF "${pass}" /opt/cervesur-react-piloto/backend/.env && grep -q '^CERVESUR_PILOT_OPERATOR_HASH=\\$2y\\$' /opt/cervesur-react-piloto/backend/.env`).code === 0);
  ok('ni la clave del operador, ni el JWT, ni la clave de la cuenta aparecen en la salida del instalador, en /var/log, en los respaldos ni en el paquete', ![pass, jwt, clave].some(s => inst.out.includes(s)) && dx(`! grep -rqsF -e "${jwt}" -e "${pass}" -e "${clave}" /var/log /var/backups ${PK} --exclude=mysql.err`).code === 0);
  ok('permisos: .env 640 root:www-data; operador.clave 600 root:root; estado y sesiones 700 www-data; aplicación y backend propiedad de root', dx('stat -c "%a %U:%G" /opt/cervesur-react-piloto/backend/.env /opt/cervesur-react-piloto/operador.clave /var/lib/cervesur-react-piloto/estado /var/lib/cervesur-react-piloto/sessions; stat -c %U /var/www/cervesur-react-piloto/public /opt/cervesur-react-piloto/backend').out === '640 root:www-data\n600 root:root\n700 www-data:www-data\n700 www-data:www-data\nroot\nroot');
  ok('el instalador NO modificó MySQL (cuentas, privilegios y bases iguales) y no creó ninguna base ni cuenta', grantsHash() === gh0 && dbHash() === dbHash0);
  const S1 = snapshot();
  ok('Flutter, 8088, 8089 y 8090, módulos, bases (CHECKSUM de cervesur_portalbi incluido), cuentas y puertos idénticos a la línea base: 0 cambios ajenos', S1 === S0, S0.split('\n').filter((l, i) => l !== S1.split('\n')[i]).join(' | '));
  ok('mod_headers sigue habilitado, Apache no se reinició y el diagnóstico 8090 sigue respondiendo con su token', headersOn() && apachePid() === pid0 && diag90() === '200');

  // ----------------------------------------------------------------- API
  section = 'API del piloto (127.0.0.1:8091)';
  const login = (u, p) => dx(`printf '{"login":"%s","password":"%s"}' "${u}" "${p}" | curl -sS -m 15 -H 'Content-Type: application/json' --data @- http://127.0.0.1:8091/api/login`).out;
  const tokenOf = body => JSON.parse(body).token ?? '';
  const call = (token, path, extra = '') => dx(`printf 'header = "Authorization: Bearer %s"\\n' "${token}" | curl -sS -m 15 -K - -w '\\n%{http_code}' ${extra} http://127.0.0.1:8091${path}`).out;
  const parse = out => { const i = out.lastIndexOf('\n'); let json = null; try { json = JSON.parse(out.slice(0, i)); } catch { /* no JSON */ } return { status: Number(out.slice(i + 1)), json, raw: out.slice(0, i) }; };
  const tok = tokenOf(login('operador', pass));
  ok('el operador inicia sesión con su clave propia y obtiene un token', tok.split('.').length === 3);
  ok('contraseña incorrecta, usuario inexistente y credenciales de usuarios del sistema (syn001 con su contraseña de prueba, correo de empleado) → rechazadas', ['operador:incorrecta', 'nadie:' + pass, 'syn001:synthetic-password', 'syn005@example.invalid:synthetic-password', 'admin:admin'].every(c => { const [u, p] = c.split(':'); return JSON.parse(login(u, p)).success === false; }));
  dx(`bash ${PK}/scripts/operador.sh --desbloquear`); // las cinco credenciales rechazadas seguidas activan el bloqueo (se prueba aparte): se levanta para continuar
  const nosession = ['/api/user/profile', '/api/reportes', '/api/reportes/1', '/api/reportes/id/1/visor', '/api/empresas', '/api/asignacion'].map(p => parse(call('', p)));
  ok('NINGÚN endpoint del catálogo responde sin sesión (401, sin datos)', nosession.every(r => r.status === 401 && !r.raw.includes('"data"')));
  const page = (path, t = tok) => { const out = []; let n = 1; for (;;) { const r = parse(call(t, `${path}?view=minimal&per_page=100&page=${n}`)); out.push(...(r.json?.data ?? [])); if (!r.json?.pagination?.has_more || n > 20) return out; n++; } };
  const reports = page('/api/reportes'); const companies = page('/api/empresas'); const relations = page('/api/asignacion');
  ok('catálogo: 82 informes activos, 6 empresas activas y las relaciones vigentes (= SQL), por id numérico', reports.length === 82 && companies.length === 6 && relations.length === Number(sqlRoot('SELECT COUNT(*) FROM cervesur_portalbi.empresa_reporte er JOIN cervesur_portalbi.empresa e ON e.id = er.empresa_id AND e.activo = 1 JOIN cervesur_portalbi.reporte r ON r.id = er.reporte_id AND r.activo = 1 WHERE er.activo = 1')));
  const bodies = ['/api/reportes?per_page=100', '/api/empresas?per_page=100', '/api/asignacion?per_page=100', '/api/user/profile'].map(p => call(tok, p)).join('');
  ok('los listados no contienen enlaces, URL de Power BI, hashes, credenciales ni la palabra «enlace»', !/enlace|https?:|powerbi|\$2y\$|password|secret/i.test(bodies));
  const okId = Number(ids.split(',')[0]); const noId = Number(sqlRoot(`SELECT id FROM cervesur_portalbi.reporte WHERE activo = 1 AND id NOT IN (${ids}) ORDER BY id LIMIT 1`));
  const link = sqlRoot(`SELECT enlace FROM cervesur_portalbi.reporte WHERE id = ${okId}`);
  const visorOk = parse(call(tok, `/api/reportes/id/${okId}/visor`)); const visorNo = parse(call(tok, `/api/reportes/id/${noId}/visor`));
  ok('visor: el informe AUTORIZADO entrega su enlace «Publicar en web»; uno NO autorizado → 403 «visor_no_autorizado» sin enlace; inactivo/inexistente → 404', visorOk.status === 200 && visorOk.json.data.url === link && visorOk.json.data.modo === 'publico' && visorNo.status === 403 && visorNo.json.code === 'visor_no_autorizado' && !visorNo.raw.includes('url') && parse(call(tok, '/api/reportes/id/83/visor')).status === 404 && parse(call(tok, '/api/reportes/id/4000000000/visor')).status === 404);
  const cerradas = ['/api/users', '/api/user', '/api/stats', '/api/empresa-usuario', '/api/empresa-reporte-usuario', '/api/reportes/activos', '/api/empresas/1', '/api/asignacion/1', '/api/powerbi/tokens/1', '/api/wiki', '/api/reportes/', '/api/ping/', '/api/reportes/id/abc/visor'];
  ok(`${cerradas.length} rutas (usuarios, estadísticas, asignaciones de usuarios, Power BI heredado, variantes) → 403 con sesión válida`, cerradas.every(p => parse(call(tok, p)).status === 403), cerradas.filter(p => parse(call(tok, p)).status !== 403).join(','));
  ok('escrituras (POST/PUT/DELETE sobre informes, empresas, asignaciones, registro, perfil) → 403', [['POST', '/api/reportes'], ['PUT', '/api/reportes/1'], ['DELETE', '/api/reportes/1'], ['POST', '/api/empresas'], ['PUT', '/api/empresas/1'], ['POST', '/api/asignacion'], ['DELETE', '/api/asignacion/1'], ['POST', '/api/register'], ['PUT', '/api/user/profile'], ['PUT', '/api/user/1/role'], ['POST', '/api/empresa-reporte-usuario']].every(([m, p]) => parse(call(tok, p, `-X ${m} -H 'Content-Type: application/json' -d '{}'`)).status === 403));
  ok('token manipulado (cuerpo alterado) y token de otro sitio (8090) → 401', parse(call(tok.split('.')[0] + '.' + Buffer.from(JSON.stringify({ user_id: 1, username: 'operador', email: 'operador@piloto.invalid', session_version: 1, pilot: 'visual', exp: Math.floor(Date.now() / 1000) + 99999 })).toString('base64url') + '.' + tok.split('.')[2], '/api/reportes')).status === 401 && parse(call(diagToken, '/api/reportes')).status === 401);
  ok('respuestas con Cache-Control no-store, nosniff y sin cookies', /cache-control: no-store/i.test(dx('curl -sSI -H "Authorization: Bearer x" http://127.0.0.1:8091/api/reportes').out) && !/set-cookie/i.test(dx('curl -sSI http://127.0.0.1:8091/api/ping').out));
  const idx = dx('curl -sS -D - http://127.0.0.1:8091/').out;
  ok('la aplicación se sirve con CSP (frame-src https://app.powerbi.com), X-Frame-Options DENY y sin HSTS; las rutas internas de React no dan 404', /frame-src https:\/\/app\.powerbi\.com(;|$)/.test(idx) && /x-frame-options: deny/i.test(idx) && !/strict-transport/i.test(idx) && dx('curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8091/reportes/5').out === '200');
  ok('archivos internos (.env, db.php, clave del operador) no se sirven; TRACE/PUT/OPTIONS → 403/405', dx('for r in /.env /backend/.env /lib/db.php /api.php /operador.clave; do curl -s http://127.0.0.1:8091$r | grep -qE "DB_PASS|JWT_SECRET|OPERATOR_HASH|<\\?php" && exit 1; done; exit 0').code === 0 && ['TRACE', 'PUT', 'OPTIONS'].every(m => ['403', '405'].includes(dx(`curl -s -o /dev/null -w "%{http_code}" -X ${m} http://127.0.0.1:8091/api/reportes`).out)));

  section = 'Aislamiento de red y PHP';
  const listen = port => dx(`ss -ltnH "sport = :${port}" | awk '{print $4}'`).out;
  ok('80 escucha en todas las interfaces (Flutter); 8088, 8089, 8090 y 8091 SOLO en 127.0.0.1', listen(80) === '0.0.0.0:80' && ['8088', '8089', '8090', '8091'].every(p => listen(p) === `127.0.0.1:${p}`), ['80', '8088', '8089', '8090', '8091'].map(listen).join(' '));
  const ip = docker(['inspect', '-f', '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}', NAME]).out;
  const net = port => docker(['run', '--rm', '--entrypoint', 'curl', IMAGE, '-sS', '-m', '5', '-o', '/dev/null', '-w', '%{http_code}', `http://${ip}:${port}/`]);
  ok('desde otra máquina de la red: 8088, 8089, 8090 y 8091 rechazan la conexión; el puerto 80 de Flutter responde', /^\d+\.\d+\.\d+\.\d+$/.test(ip) && ['8088', '8089', '8090', '8091'].every(p => net(p).code !== 0) && net(80).out === '200');
  put('/opt/cervesur-react-piloto/backend/sonda.php', '<?php echo json_encode(["name" => session_name(), "path" => session_save_path(), "obd" => ini_get("open_basedir"), "httponly" => ini_get("session.cookie_httponly"), "samesite" => ini_get("session.cookie_samesite"), "lee_flutter" => @file_get_contents("/var/www/cervesur/backend/api.php") !== false, "lee_8088" => @file_get_contents("/opt/cervesur-react-prueba/backend/.env") !== false, "lee_8089" => @file_get_contents("/opt/cervesur-react-compat/backend/.env") !== false, "lee_8090" => @file_get_contents("/opt/cervesur-react-ro/backend/.env") !== false, "lee_passwd" => @file_get_contents("/etc/passwd") !== false, "lee_clave" => @file_get_contents("/opt/cervesur-react-piloto/operador.clave") !== false, "lee_logs" => @scandir("/var/log/apache2") !== false, "lee_propio" => @is_file("/opt/cervesur-react-piloto/backend/api.php"), "escribe_estado" => @file_put_contents("/var/lib/cervesur-react-piloto/estado/prueba.tmp", "x") !== false]);');
  dx('cp /etc/apache2/sites-available/cervesur-react-piloto.conf /root/site-pi.orig');
  dx(String.raw`awk '/Include .*react-piloto-common.conf/ {print "    AliasMatch \"^/sonda\\.php$\" \"/opt/cervesur-react-piloto/backend/sonda.php\""} {print}' /root/site-pi.orig > /etc/apache2/sites-available/cervesur-react-piloto.conf && apache2ctl graceful`);
  let sondaRaw = ''; for (let i = 0; i < 20; i++) { await sleep(1000); sondaRaw = dx('curl -sS http://127.0.0.1:8091/sonda.php').out; if (sondaRaw.startsWith('{')) break; }
  let sonda = {}; try { sonda = JSON.parse(sondaRaw); } catch { sonda = { raw: sondaRaw.slice(0, 100) }; }
  dx('cp /root/site-pi.orig /etc/apache2/sites-available/cervesur-react-piloto.conf && rm -f /opt/cervesur-react-piloto/backend/sonda.php /var/lib/cervesur-react-piloto/estado/prueba.tmp && apache2ctl graceful'); await sleep(2500);
  ok('configuración efectiva de PHP: cookie CERVESUR_REACT_PILOTO, sesiones propias, HttpOnly y SameSite=Strict (php_admin)', sonda.name === 'CERVESUR_REACT_PILOTO' && sonda.path === '/var/lib/cervesur-react-piloto/sessions' && sonda.httponly === '1' && String(sonda.samesite).toLowerCase() === 'strict', JSON.stringify(sonda));
  ok('open_basedir: el PHP del piloto NO lee el backend de Flutter, los .env de 8088, 8089 ni 8090, /etc/passwd, los logs de Apache ni la clave del operador; sí su backend y su carpeta de estado', sonda.lee_flutter === false && sonda.lee_8088 === false && sonda.lee_8089 === false && sonda.lee_8090 === false && sonda.lee_passwd === false && sonda.lee_clave === false && sonda.lee_logs === false && sonda.lee_propio === true && sonda.escribe_estado === true, JSON.stringify(sonda));
  ok('la sonda temporal se retiró y el sitio quedó como estaba', ['403', '404', '200'].includes(dx('curl -s -o /dev/null -w "%{http_code}" http://127.0.0.1:8091/sonda.php').out) && !dx('curl -s http://127.0.0.1:8091/sonda.php').out.startsWith('{') && dx('cmp /etc/apache2/sites-available/cervesur-react-piloto.conf /root/site-pi.orig').code === 0);

  // ----------------------------------------------------------------- Navegador real
  section = 'Navegador real a través del túnel simulado (localhost:' + TUNEL + ')';
  dx('setsid nohup socat TCP-LISTEN:18092,fork,reuseaddr TCP:127.0.0.1:8091 >/dev/null 2>&1 &'); await sleep(1000);
  const nombreOk = sqlRoot(`SELECT nombre FROM cervesur_portalbi.reporte WHERE id = ${okId}`); const nombreNo = sqlRoot(`SELECT nombre FROM cervesur_portalbi.reporte WHERE id = ${noId}`);
  const b = await launch({ port: 9521 });
  try {
    await b.send('Network.setBlockedURLs', { urls: ['https://app.powerbi.com/*', 'https://*.powerbi.com/*'] }); // el ensayo no sale a Internet: se verifica el src del iframe, no se carga
    await b.goto(`${BASE}/`); await b.wait(1000);
    const login0 = await b.eval('document.body.innerText');
    ok('la aplicación carga y redirige a /login; el acceso aclara que es del operador del piloto (no de un empleado); sin datos de demostración', (await b.eval('location.pathname')) === '/login' && /operador autorizado del piloto/.test(login0) && /no use la contraseña de un empleado/.test(login0) && !/maria\.rios|elena\.torres|Entorno QA/.test(login0));
    await b.type('#login-name', 'operador'); await b.type('#login-password', 'incorrecta-123'); await b.click('form button[type=submit]'); await b.wait(1500);
    ok('una clave incorrecta muestra un error genérico y no entra', (await b.eval('location.pathname')) === '/login' && /Credenciales inválidas/.test(await b.eval('document.body.innerText')));
    await b.type('#login-name', 'operador'); await b.type('#login-password', pass); await b.click('form button[type=submit]'); await b.wait(2500);
    const home = await b.eval('document.body.innerText');
    ok('el operador entra: /reportes con el aviso PILOTO · datos reales · solo consulta · operador autorizado, y SIN el modo administrador', (await b.eval('location.pathname')) === '/reportes' && /PILOTO · Datos reales de Cervesur · Solo consulta · Operador autorizado/.test(home) && !(await b.eval(`!!document.querySelector('.app-shell.is-admin')`)) && !/Modo administrador/.test(home));
    const dbg1 = `nombre=${nombreOk} | tarjetas=${await b.eval("document.querySelectorAll('article.report-card').length")} | texto=${home.slice(0, 500).replace(/s+/g, " ")}`;
    ok('el catálogo real muestra los informes (82 activos) por nombre y sin enlaces', home.toLowerCase().includes(nombreOk.toLowerCase()) && (await b.eval(`document.querySelectorAll('article.report-card').length`)) >= 20 && !/powerbi\.com/.test(await b.eval('document.body.innerHTML')), dbg1);
    await b.eval(`history.pushState({}, '', '/empresas'); dispatchEvent(new PopStateEvent('popstate'))`); await b.wait(1500);
    const emp = await b.eval('document.body.innerText'); const empresaNombre = sqlRoot('SELECT nombre FROM cervesur_portalbi.empresa WHERE activo = 1 ORDER BY id LIMIT 1');
const dbg2 = `empresa=${empresaNombre} | texto=${emp.slice(0, 500).replace(/ +/g, " ")}`;
    ok('/empresas muestra las empresas reales activas', emp.toLowerCase().includes(empresaNombre.toLowerCase()) && !/Nueva empresa|Editar|Eliminar/.test(emp), dbg2);
    await b.eval(`history.pushState({}, '', '/reportes/${okId}'); dispatchEvent(new PopStateEvent('popstate'))`); await b.wait(2500);
    const iframeSrc = await b.eval(`document.querySelector('iframe')?.src ?? ''`);
    ok('abre un informe AUTORIZADO: el visor monta el iframe con el enlace real almacenado (solo ahora llega el enlace al navegador)', iframeSrc === link, iframeSrc);
    await b.eval(`history.pushState({}, '', '/reportes/${noId}'); dispatchEvent(new PopStateEvent('popstate'))`); await b.wait(2500);
    const denied = await b.eval('document.body.innerText');
    ok('abre un informe NO autorizado para el piloto: «Acceso denegado», sin iframe ni enlace', /todavía no está autorizado para mostrarse en el piloto/.test(denied) && !/pida una asignación a un administrador/.test(denied) && !(await b.eval(`!!document.querySelector('iframe')`)));
    await b.eval(`history.pushState({}, '', '/admin/usuarios'); dispatchEvent(new PopStateEvent('popstate'))`); await b.wait(1500);
    ok('/admin y /admin/usuarios no existen en el piloto: la interfaz regresa al catálogo', !(await b.eval('location.pathname')).startsWith('/admin'));
    ok('ninguna petición del navegador a rutas de usuarios, estadísticas, escritura ni Power BI heredado; todas al mismo origen', b.requestLog.every(r => !/\/api\/(users|stats|register|empresa-usuario|empresa-reporte-usuario|powerbi)/.test(r.url) && (r.method === 'GET' || /\/api\/(login|logout)$/.test(r.url)) && (r.url.startsWith(BASE) || /^(data:|about:|blob:)/.test(r.url) || r.url.startsWith('https://app.powerbi.com/'))));
    ok('la API del navegador no recibe enlaces en los listados (solo el visor autorizado los trae)', b.requestLog.filter(r => /\/api\/(reportes|empresas|asignacion)(\?|$)/.test(r.url)).length > 0);
    ok('sin errores de consola ni violaciones de CSP (salvo el bloqueo deliberado del ensayo a Power BI)', b.consoleErrors.filter(e => !/powerbi|ERR_BLOCKED_BY_CLIENT/i.test(e)).length === 0, b.consoleErrors.slice(0, 3).join(' | '));
    await b.eval(`[...document.querySelectorAll('button')].find(x => /Cerrar sesión/.test(x.textContent))?.click()`); await b.wait(1500);
    ok('cerrar sesión devuelve al acceso y vacía el token de la memoria (un acceso directo pide la clave de nuevo)', (await b.eval('location.pathname')) === '/login');
  } finally { await b.close(); dx("pkill -f 'TCP-LISTEN:1809[2]' || true"); }

  // ----------------------------------------------------------------- Operador, informes y fail-closed
  section = 'Operador: clave, sesiones, bloqueo e informes autorizados';
  const bloqueado = []; for (let i = 0; i < 5; i++) bloqueado.push(login('operador', 'mala' + i)); const lock = dx(`printf '{"login":"operador","password":"${pass}"}' | curl -sS -m 15 -D - -o /dev/null -H 'Content-Type: application/json' --data @- http://127.0.0.1:8091/api/login`).out;
  ok('tras 5 fallos el operador queda bloqueado (429 + Retry-After) aunque use la clave correcta', /429/.test(lock.split('\n')[0]) && /retry-after/i.test(lock));
  const desb = dx(`bash ${PK}/scripts/operador.sh --desbloquear`);
  ok('operador.sh --desbloquear levanta el bloqueo y el operador vuelve a entrar', desb.code === 0 && tokenOf(login('operador', pass)) !== '');
  const mostrar = dx(`bash ${PK}/scripts/operador.sh --mostrar`, CONFIRM);
  ok('operador.sh --mostrar recupera la clave (solo root, solo por la salida estándar) y sin confirmación no muestra nada', mostrar.out === pass && !dx(`bash ${PK}/scripts/operador.sh --mostrar < /dev/null`).out.includes(pass));
  const antes = tokenOf(login('operador', pass));
  const rev = dx(`bash ${PK}/scripts/operador.sh --revocar-sesiones`);
  ok('operador.sh --revocar-sesiones cierra TODAS las sesiones (el token anterior → 401) sin cambiar la clave ni recargar Apache', rev.code === 0 && parse(call(antes, '/api/reportes')).status === 401 && tokenOf(login('operador', pass)) !== '' && apachePid() === pid0);
  const rot = dx(`bash ${PK}/scripts/operador.sh --rotar-clave`, CONFIRM);
  const pass2 = dx('head -n 1 /opt/cervesur-react-piloto/operador.clave').out;
  ok('operador.sh --rotar-clave: clave nueva y secreto JWT nuevo; la clave anterior ya no entra, la nueva sí; los permisos se conservan y no se imprime nada secreto', rot.code === 0 && pass2 !== pass && /^[A-Za-z0-9]{24}$/.test(pass2) && JSON.parse(login('operador', pass)).success === false && tokenOf(login('operador', pass2)) !== '' && !rot.out.includes(pass2) && dx('stat -c "%a %U:%G" /opt/cervesur-react-piloto/backend/.env /opt/cervesur-react-piloto/operador.clave').out === '640 root:www-data\n600 root:root');
  const lista = dx(`bash ${PK}/scripts/informes.sh --listar`);
  ok('informes.sh --listar muestra los dos informes autorizados', lista.out.includes(ids));
  const tok2 = tokenOf(login('operador', pass2));
  const retirar = dx(`bash ${PK}/scripts/informes.sh --retirar`);
  ok('informes.sh --retirar: el visor deja de entregar el enlace del informe antes autorizado (403) mientras el catálogo sigue completo', retirar.code === 0 && parse(call(tok2, `/api/reportes/id/${okId}/visor`)).status === 403 && page('/api/reportes', tok2).length === 82);
  const sinFrase2 = dx(`bash ${PK}/scripts/informes.sh --autorizar ${okId} < /dev/null`);
  ok('informes.sh --autorizar sin la frase de confirmación no cambia nada', sinFrase2.code !== 0 && parse(call(tok2, `/api/reportes/id/${okId}/visor`)).status === 403);
  const aut = dx(`bash ${PK}/scripts/informes.sh --autorizar ${okId}`, { CERVESUR_PILOTO_CONFIRMAR_INFORMES: 'AUTORIZO-INFORMES' });
  ok('informes.sh --autorizar con la frase vuelve a autorizar solo ese informe (los demás siguen en 403)', aut.code === 0 && parse(call(tok2, `/api/reportes/id/${okId}/visor`)).status === 200 && parse(call(tok2, `/api/reportes/id/${noId}/visor`)).status === 403);
  const sinFraseT = dx(`bash ${PK}/scripts/informes.sh --autorizar TODOS`, { CERVESUR_PILOTO_CONFIRMAR_INFORMES: 'AUTORIZO-INFORMES' });
  ok('«TODOS» exige su propia frase (AUTORIZO-TODOS-LOS-INFORMES): la frase de una lista no basta', sinFraseT.code !== 0 && parse(call(tok2, `/api/reportes/id/${noId}/visor`)).status === 403);
  dx(`bash ${PK}/scripts/informes.sh --retirar`);

  section = 'Fail-closed en ejecución';
  dx('cp -a /opt/cervesur-react-piloto/backend/.env /root/env.pi.bak');
  const restoreEnv = () => dx('cp -a /root/env.pi.bak /opt/cervesur-react-piloto/backend/.env');
  const E = '/opt/cervesur-react-piloto/backend/.env';
  const mutaciones = [
    ['CERVESUR_MODE ausente', `sed -i '/^CERVESUR_MODE=/d' ${E}`], ['CERVESUR_MODE=read_write', `sed -i 's/^CERVESUR_MODE=.*/CERVESUR_MODE=read_write/' ${E}`], ['CERVESUR_MODE=diagnostico', `sed -i 's/^CERVESUR_MODE=.*/CERVESUR_MODE=diagnostico/' ${E}`],
    ['hash del operador ausente', `sed -i '/^CERVESUR_PILOT_OPERATOR_HASH=/d' ${E}`], ['hash del operador que no es bcrypt', `sed -i 's/^CERVESUR_PILOT_OPERATOR_HASH=.*/CERVESUR_PILOT_OPERATOR_HASH=texto-plano/' ${E}`],
    ['JWT_SECRET corto', `sed -i 's/^JWT_SECRET=.*/JWT_SECRET=corto/' ${E}`], ['lista de informes mal formada', `sed -i 's/^CERVESUR_PILOT_VISOR_IDS=.*/CERVESUR_PILOT_VISOR_IDS=1;2/' ${E}`], ['DB_NAME con inyección de DSN', `sed -i 's/^DB_NAME=.*/DB_NAME=x;dbname=cervesur_portalbi/' ${E}`],
  ];
  for (const [label, mutation] of mutaciones) {
    dx(mutation); const ping = dx('curl -sS -m 10 -w "\\n%{http_code}" http://127.0.0.1:8091/api/ping').out; const lg = dx(`curl -sS -m 10 -o /dev/null -w "%{http_code}" -X POST -d '{"login":"operador","password":"x"}' http://127.0.0.1:8091/api/login`).out; restoreEnv();
    ok(`${label}: el piloto NO sirve nada (503), tampoco el inicio de sesión, y no revela detalles`, ping.endsWith('503') && lg === '503' && !/portalbi|sql|hash|jwt/i.test(ping), ping);
  }
  ok('con el .env restaurado el piloto vuelve a responder y es byte a byte el de antes', dx('curl -s http://127.0.0.1:8091/api/ping').out.includes('pong') && dx(`cmp /root/env.pi.bak ${E}`).code === 0);
  dx('rm -f /root/env.pi.bak');
  section = 'Auditoría de escrituras (evidencia, no prueba)';
  const ps = sqlRoot(`SELECT @@performance_schema; SELECT COUNT(*) FROM performance_schema.events_statements_summary_by_user_by_event_name WHERE USER='${VISOR}' AND SUM_ROWS_AFFECTED > 0; SELECT COUNT(*) FROM performance_schema.events_statements_summary_by_user_by_event_name WHERE USER='${VISOR}' AND COUNT_STAR > 0 AND EVENT_NAME IN ('statement/sql/select', 'statement/com/Execute')`).split('\n');
  ok('condiciones de validez cumplidas (performance_schema activo; actividad de lectura registrada) y 0 filas afectadas por la cuenta del piloto', ps[0] === '1' && ps[1] === '0' && Number(ps[2]) >= 1, ps.join('|'));
  ok('la evidencia sólida son los privilegios efectivos: el preflight B sigue en PASS tras todo el uso', dx(`bash ${PK}/scripts/preflight-acceso.sh ${K}`).code === 0);
  const S2 = snapshot(); const gh1 = grantsHash();
  ok('tras todo el uso (navegador, API, operador) los cuatro portales, las tablas de cervesur_portalbi, las bases y las cuentas siguen idénticos a la línea base', S2 === S1 && S2 === S0 && gh1 === gh0 && diag90() === '200');

  // ----------------------------------------------------------------- Reversión
  section = 'Reversión';
  const pid1 = apachePid();
  const revA = dx(`bash ${PK}/scripts/revertir.sh`, CONFIRM);
  ok('revertir.sh termina correctamente y sin avisos de fallo', revA.code === 0 && !/FALLO/.test(revA.out), revA.out.split("\n").filter(l => /FALLO/.test(l)).join(" | ") || revA.out.slice(-300));
  ok('no quedan carpetas, .env, JWT, clave del operador, aplicación, sitio ni puerto 8091', estadoLimpio());
  ok('la base cervesur_portalbi y la cuenta cervesur_ro_visor SIGUEN existiendo con los mismos privilegios (la reversión no toca MySQL); la cuenta del diagnóstico también', cuentaExiste() && grantsHash() === gh1 && dbHash() === dbHash0 && sqlRoot("SELECT COUNT(*) FROM mysql.user WHERE user = 'cervesur_ro_diag'") === '1');
  ok('mod_headers SIGUE habilitado y Apache no se reinició', headersOn() && apachePid() === pid1 && pid1 === pid0);
  ok('Flutter, 8088, 8089 y 8090, módulos, bases (CHECKSUM de cervesur_portalbi), cuentas y puertos idénticos a la línea base original: 0 cambios ajenos', snapshot() === S0);
  ok('Flutter, 8088, 8089 y el diagnóstico 8090 (con su token) siguen funcionando; la verificación de 8090 solo discrepa de SU línea base antigua porque ahora existe la cuenta cervesur_ro_visor (esperado: la creó el administrador después)', dx('curl -s http://127.0.0.1/').out.includes('FLUTTER-SENTINEL') && dx('curl -fs http://127.0.0.1:8088/api/ping && curl -fs http://127.0.0.1:8089/api/ping').code === 0 && diag90() === '200' && (() => { const v = dx(`bash ${PK90}/scripts/verificar.sh`).out; const fails = v.split('\n').filter(l => l.startsWith('FAIL')); return fails.length === 1 && /idénticos a la línea base/.test(fails[0]); })());
  ok('los respaldos se conservan', dx('ls /var/backups/cervesur-react-piloto/linea-base-portales.txt /var/backups/cervesur-react-piloto/*.tgz >/dev/null').code === 0);
  ok('segunda reversión: idempotente (código 0, sin fallos)', (() => { const r = dx(`bash ${PK}/scripts/revertir.sh`, CONFIRM); return r.code === 0 && !/FALLO/.test(r.out); })());
  dx('mkdir -p /opt/cervesur-react-piloto/backend /var/lib/cervesur-react-piloto/estado /var/www/cervesur-react-piloto/public && echo basura > /opt/cervesur-react-piloto/backend/.env && cp /root/site-pi.orig /etc/apache2/sites-available/cervesur-react-piloto.conf');
  const rev3 = dx(`bash ${PK}/scripts/revertir.sh`, CONFIRM);
  ok('reversión de una instalación PARCIAL: termina bien y deja todo limpio, sin tocar nada ajeno', rev3.code === 0 && !/FALLO/.test(rev3.out) && estadoLimpio() && snapshot() === S0, rev3.out.slice(-300));
  ok('sin confirmación escrita revertir.sh no hace nada', (() => { const r = dx(`bash ${PK}/scripts/revertir.sh < /dev/null`); return r.code !== 0 && /Cancelado/.test(r.out); })());
  // la cuenta del visor se retira SOLO con su procedimiento administrativo, que no toca otras cuentas
  const revSql = dx(`mysql < ${PK}/admin-mysql/99-revocar-cuenta-visor.sql`);
  ok('99-revocar-cuenta-visor.sql elimina ÚNICAMENTE cervesur_ro_visor@127.0.0.1 (la cuenta del diagnóstico y las de 8088/8089 siguen)', revSql.code === 0 && !cuentaExiste() && sqlRoot("SELECT COUNT(*) FROM mysql.user WHERE user = 'cervesur_ro_diag'") === '1' && diag90() === '200');
} catch (error) {
  results.push(['FAIL', `${section} · EXCEPCIÓN`, error.stack ?? error.message]);
} finally {
  if (!process.env.ENSAYO_CONSERVAR) cleanup(); // ENSAYO_CONSERVAR=1 deja el contenedor para depurar
}
for (const [status, name, extra] of results) console.log(`${status}  ${name}${extra ? `  → ${extra}` : ''}`);
const failed = results.filter(r => r[0] === 'FAIL').length;
console.log(`\n${results.length - failed} PASS / ${failed} FAIL`);
process.exit(failed ? 1 : 0);
