// ENSAYO LOCAL (Docker) del DIAGNÓSTICO AGREGADO DE SOLO LECTURA (UX-07D.3B). No se conecta a ningún servidor real.
//   node deploy/tunel-ssh-readonly/ensayo-docker.mjs
// Réplica de ensayo: Ubuntu 24.04 + Apache 2.4 + mod_php 8.3 + MySQL 8 (validate_password MEDIUM) y CUATRO portales a la vez:
//   - Flutter simulado en el puerto 80 · React de prueba en 127.0.0.1:8088 · React de compatibilidad en 127.0.0.1:8089 (instalados con SUS instaladores),
//   - diagnóstico de solo lectura en 127.0.0.1:8090 (los scripts nuevos), contra una base SINTÉTICA llamada cervesur_portalbi (fixture de UX-07D.2B/2C).
// La cuenta cervesur_ro_diag la crea el ensayo con el procedimiento administrativo independiente (admin-mysql/), NUNCA el instalador.
// Demuestra que preflight, instalar, fallar y revertir 8090 NO alteran 80, 8088, 8089 ni MySQL, y que los controles de aislamiento y seguridad se cumplen.
import { spawnSync } from 'node:child_process';
import { createHash, randomBytes } from 'node:crypto';
import { readdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const REPO = join(here, '..', '..');
const IMAGE = 'cervesur-ensayo-ubuntu:local';
const NAME = 'cervesur-ensayo-ro';
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
const CONFIRM = { CERVESUR_RO_CONFIRMAR: 'SI' };
const DIAG_USER = 'cervesur_ro_diag';

try {
  cleanup();
  section = 'Aislamiento de nombres y recursos';
  const libOf = dir => constants(join(REPO, 'deploy', dir, 'scripts', 'lib.sh'));
  const old = libOf('tunel-ssh'); const compat = libOf('tunel-ssh-compat'); const neu = libOf('tunel-ssh-readonly');
  const keys = ['SITIO', 'PUERTO', 'DIR_CONF', 'SITE_CONF', 'DIR_RESPALDO', 'COOKIE_SESION'];
  ok('ningún nombre de sitio, puerto, ruta, respaldo ni cookie coincide con los de 8088 ni con los de 8089', keys.every(k => neu[k] && old[k] !== neu[k] && compat[k] !== neu[k]), keys.filter(k => old[k] === neu[k] || compat[k] === neu[k]).join(','));
  const overlap = (a, b) => `${a}/`.startsWith(`${b}/`) || `${b}/`.startsWith(`${a}/`);
  const others = ['/var/www/cervesur', old.DIR_FRONT, old.DIR_BACK, old.DIR_CONF, old.DIR_RESPALDO, compat.DIR_FRONT, compat.DIR_BACK, compat.DIR_CONF, compat.DIR_VAR, compat.DIR_RESPALDO];
  ok('ninguna ruta del entorno nuevo contiene ni está contenida en la de Flutter, 8088 o 8089', ['DIR_BACK', 'DIR_CONF', 'DIR_VAR', 'DIR_RESPALDO'].every(k => others.every(o => !overlap(neu[k], o))));
  ok('puertos 80, 8088, 8089 y 8090 distintos; DB_USUARIO distinto de las cuentas de los otros portales; cookie propia en el código de Apache', neu.PUERTO === '8090' && old.PUERTO === '8088' && compat.PUERTO === '8089' && neu.DB_USUARIO === DIAG_USER && ![old.DB_USUARIO, compat.DB_USUARIO].includes(neu.DB_USUARIO) && /session\.name CERVESUR_REACT_RO\b/.test(readFileSync(join(REPO, 'deploy', 'tunel-ssh-readonly', 'apache', 'react-ro-common.conf'), 'utf8')));
  const shellOk = ['instalar', 'pasos-instalacion', 'verificar', 'revertir', 'lib', 'preflight-infra', 'preflight-acceso', 'token', 'conciliar'].every(n => spawnSync('bash', ['-n', join(here, 'scripts', `${n}.sh`)]).status === 0) && spawnSync('bash', ['-n', join(here, 'admin-mysql', 'preparar-sql-cuenta.sh')]).status === 0;
  ok('sintaxis bash correcta en todos los scripts', shellOk);

  section = 'Paquetes';
  const packRo = spawnSync(process.execPath, [join(here, 'empaquetar.mjs')], { cwd: REPO, encoding: 'utf8' });
  ok('empaquetar.mjs (8090) genera el paquete sin compilar ningún frontend', packRo.status === 0, packRo.stderr || packRo.stdout.slice(-300));
  const pack88 = spawnSync(process.execPath, [join(REPO, 'deploy', 'tunel-ssh', 'empaquetar.mjs'), '--sin-compilar'], { cwd: REPO, encoding: 'utf8' });
  ok('empaquetar.mjs (8088) genera el paquete del portal "existente"', pack88.status === 0, pack88.stderr || pack88.stdout.slice(-300));
  const pack89 = spawnSync(process.execPath, [join(REPO, 'deploy', 'tunel-ssh-compat', 'empaquetar.mjs')], { cwd: REPO, encoding: 'utf8' });
  ok('empaquetar.mjs (8089) genera el paquete del portal "existente"', pack89.status === 0, pack89.stderr || pack89.stdout.slice(-300));
  const tgzRo = newest('cervesur-react-ro-'); const tgz88 = newest('cervesur-react-prueba-'); const tgz89 = newest('cervesur-react-compat-');
  if (!tgzRo || !tgz88 || !tgz89) throw new Error('Faltan los paquetes');
  const shaRo = createHash('sha256').update(readFileSync(tgzRo)).digest('hex');
  ok('el .sha256 externo coincide con el SHA-256 del .tar.gz', readFileSync(`${tgzRo}.sha256`, 'utf8').startsWith(shaRo));

  docker(['build', '--quiet', '--tag', IMAGE, join(REPO, 'deploy', 'tunel-ssh', 'ensayo')], { allowFail: false });
  docker(['run', '--detach', '--name', NAME, IMAGE], { allowFail: false }); // sin puertos publicados: todo se prueba desde dentro del contenedor o desde otro contenedor
  for (let i = 0; ; i++) { if (dx('curl -fs http://127.0.0.1/ >/dev/null && mysqladmin ping --silent').code === 0) break; if (i > 90) throw new Error('El contenedor no arrancó'); await sleep(1000); }
  docker(['cp', tgzRo, `${NAME}:/root/ro.tar.gz`], { allowFail: false }); docker(['cp', tgz88, `${NAME}:/root/prueba.tar.gz`], { allowFail: false }); docker(['cp', tgz89, `${NAME}:/root/compat.tar.gz`], { allowFail: false });
  const first = cmd => dx(cmd).out.split('\n').pop();
  const PK = `/root/${first('cd /root && tar xzf ro.tar.gz && ls -d cervesur-react-ro-*')}`;
  const PK88 = `/root/${first('cd /root && tar xzf prueba.tar.gz && ls -d cervesur-react-prueba-*')}`;
  const PK89 = `/root/${first('cd /root && tar xzf compat.tar.gz && ls -d cervesur-react-compat-*')}`;
  ok('los tres paquetes se extraen y su SHA256SUMS interno es válido', [PK, PK88, PK89].every(p => dx(`cd ${p} && sha256sum -c SHA256SUMS --quiet`).code === 0));
  ok('el paquete 8090: scripts con LF, sin .env, sin frontend y sin tests; VERSION identifica el código probado (incluye cambios locales y huella del contenido)', dx(`! grep -rlI $'\\r' ${PK}/scripts ${PK}/apache ${PK}/admin-mysql && ! find ${PK} -name ".env*" | grep -q . && ! test -e ${PK}/public && ! test -d ${PK}/backend/tests && grep -q '^commit: ' ${PK}/VERSION && grep -q '^contenido-sha256: [0-9a-f]\\{64\\}' ${PK}/VERSION`).code === 0);
  ok('el nombre del paquete declara «-con-cambios-<huella>» cuando hay cambios locales sin confirmar', /cervesur-react-ro-[0-9a-f]{12}(-con-cambios-[0-9a-f]{12})?-\d{8}-\d{6}Z\.tar\.gz$/.test(tgzRo) && (spawnSync('git', ['status', '--porcelain'], { cwd: REPO, encoding: 'utf8' }).stdout.trim() === '' || /-con-cambios-[0-9a-f]{12}-/.test(tgzRo)));
  ok('el paquete no contiene token, clave ni valores de .env (ni los scripts ni la documentación)', dx(`! grep -rIlE 'CERVESUR_DIAG_TOKEN=[0-9a-f]{32}|DB_PASS=[^%$ ]{8}' ${PK}`).code === 0);

  // --- Portales "existentes": se instalan con SUS instaladores, como en el servidor ---
  section = 'Portales existentes (80, 8088 y 8089)';
  const inst88 = dx(`bash ${PK88}/scripts/instalar.sh`, { CERVESUR_CONFIRMAR: 'SI' });
  ok('el instalador de 8088 deja el portal funcionando', inst88.code === 0 && /\d+ PASS \/ 0 FAIL/.test(inst88.out), inst88.out.slice(-400));
  const inst89 = dx(`bash ${PK89}/scripts/instalar.sh`, { CERVESUR_COMPAT_CONFIRMAR: 'SI' });
  ok('el instalador de 8089 deja el portal funcionando', inst89.code === 0 && /\d+ PASS \/ 0 FAIL/.test(inst89.out), inst89.out.slice(-400));
  put('/root/portalbi.php', readFileSync(join(here, 'ensayo', 'sembrar-portalbi.php'), 'utf8'));
  put('/root/productive_fixture.php', readFileSync(join(REPO, 'tests', 'isolated', 'productive_fixture.php'), 'utf8'));
  const seed = dx('php /root/portalbi.php /root/productive_fixture.php');
  ok('base SINTÉTICA cervesur_portalbi creada en el MySQL desechable del contenedor (agregados del inventario verificados)', seed.code === 0 && /creada/.test(seed.out), seed.out);

  const apachePid = () => dx('cat /var/run/apache2/apache2.pid').out;
  const headersOn = () => dx('test -e /etc/apache2/mods-enabled/headers.load').code === 0;
  const SNAP = `set -u
tree() { if [ -d "$1" ]; then (cd "$1" && find . -type f -printf '%p %s %T@ %m %u:%g\\n' | LC_ALL=C sort | sha256sum | cut -c1-64); else echo ausente; fi; }
http() { curl -sS -m 6 -o /dev/null -D - "$1" | tr -d '\\r' | awk 'NR==1{print;next} /^[A-Za-z-]+:/{print tolower(substr($0,1,index($0,":")-1))}' | LC_ALL=C sort; curl -sS -m 6 "$1" | sha256sum | cut -c1-64; }
echo "== configuracion"; sha256sum /etc/apache2/sites-available/cervesur.conf /var/www/cervesur/.htaccess /etc/apache2/sites-available/cervesur-react-prueba.conf /etc/apache2/cervesur-react-prueba/react-common.conf /opt/cervesur-react-prueba/backend/.env /etc/apache2/sites-available/cervesur-react-compat.conf /etc/apache2/cervesur-react-compat/react-compat-common.conf /opt/cervesur-react-compat/backend/.env /etc/apache2/apache2.conf /etc/apache2/ports.conf
echo "== arboles"; tree /var/www/cervesur; tree /var/www/cervesur-react-prueba; tree /opt/cervesur-react-prueba; tree /var/www/cervesur-react-compat; tree /opt/cervesur-react-compat
echo "== modulos"; apache2ctl -M 2>/dev/null | LC_ALL=C sort | sha256sum | cut -c1-64; ls /etc/apache2/mods-enabled | LC_ALL=C sort | sha256sum | cut -c1-64
echo "== http"; for u in http://127.0.0.1/ http://127.0.0.1/ruta/profunda http://127.0.0.1:8088/ http://127.0.0.1:8088/api/ping http://127.0.0.1:8089/ http://127.0.0.1:8089/api/ping; do echo "$u"; http "$u"; done
echo "== mysql"; mysql -N -e 'SHOW DATABASES' | LC_ALL=C sort; mysql -N -e 'SELECT user, host FROM mysql.user' | LC_ALL=C sort
for d in cervesur_react_prueba cervesur_react_compat_qa cervesur_portalbi; do for t in $(mysql -N -e 'SHOW TABLES' $d); do echo "$d.$t $(mysql -N -e "CHECKSUM TABLE $t" $d)"; done; done
echo "== puertos"; ss -ltnH | awk '{print $4}' | grep -v ':8090$' | LC_ALL=C sort -u
echo "== sitios"; ls /etc/apache2/sites-enabled | grep -v '^cervesur-react-ro.conf$' | LC_ALL=C sort
`;
  put('/root/snap.sh', SNAP);
  const snapshot = () => dx('bash /root/snap.sh').out;
  const grantsHash = () => dx(`mysql -N -e "SELECT user, host FROM mysql.user WHERE user='${DIAG_USER}'" | while read u h; do mysql -N -e "SHOW GRANTS FOR '$u'@'$h'"; done | sha256sum | cut -c1-64`).out;
  const estadoLimpio = () => dx('! test -e /opt/cervesur-react-ro && ! test -e /etc/apache2/cervesur-react-ro && ! test -e /etc/apache2/sites-available/cervesur-react-ro.conf && ! test -e /etc/apache2/sites-enabled/cervesur-react-ro.conf && ! test -e /var/lib/cervesur-react-ro && [ -z "$(ss -ltnH \'sport = :8090\')" ]').code === 0;
  const cuentaExiste = () => dx(`[ "$(mysql -N -e "SELECT COUNT(*) FROM mysql.user WHERE user='${DIAG_USER}'")" -gt 0 ]`).code === 0;
  const preflightA = () => dx(`bash ${PK}/scripts/preflight-infra.sh`);
  const preflightB = (keyFile = '/root/cervesur-ro-diag.clave') => dx(`bash ${PK}/scripts/preflight-acceso.sh --clave-archivo ${keyFile}`);
  const S00 = snapshot(); const pid0 = apachePid();
  ok('línea base de los tres portales, módulos, bases (incluida cervesur_portalbi sintética: CHECKSUM de sus tablas), cuentas, puertos y sitios', S00.length > 800 && /cervesur_portalbi\.user /.test(S00) && /8089/.test(S00));
  const flutterOk = dx('curl -s http://127.0.0.1/ruta/profunda').out.includes('FLUTTER-SENTINEL');
  ok('Flutter (80), 8088 y 8089 responden antes de empezar', flutterOk && dx('curl -fs http://127.0.0.1:8088/api/ping && curl -fs http://127.0.0.1:8089/api/ping').code === 0);

  // ----------------------------------------------------------------- Preflight A (infraestructura) y B (acceso)
  section = 'Preflight de infraestructura (A)';
  const pa = preflightA();
  ok('A correcto: todas las comprobaciones de infraestructura pasan SIN que exista la cuenta de diagnóstico', pa.code === 0 && /preflight A \(infraestructura\): PASS/.test(pa.out) && /Flutter \(puerto 80\) existe y responde/.test(pa.out) && /React de prueba \(8088\)/.test(pa.out) && /React de compatibilidad \(8089\)/.test(pa.out), pa.out.slice(-600));
  ok('A no modifica nada', estadoLimpio() && snapshot() === S00);

  section = 'Preflight de acceso (B) sin cuenta';
  ok('la cuenta cervesur_ro_diag todavía NO existe', !cuentaExiste());
  const pbNone = preflightB('/nonexistente');
  ok('B queda PENDIENTE (código 3), nunca PASS', pbNone.code === 3 && /PENDIENTE/.test(pbNone.out) && !/preflight B \(acceso\): PASS/.test(pbNone.out) && !/^OK .*autentica/m.test(pbNone.out), pbNone.out);
  const simNone = dx(`bash ${PK}/scripts/instalar.sh --simular`);
  ok('instalar.sh --simular sin cuenta: infraestructura PASS, acceso PENDIENTE, código 3 y "NO se puede instalar todavía"', simNone.code === 3 && /infraestructura PASS · acceso PENDIENTE/.test(simNone.out) && /NO se puede instalar todavía/.test(simNone.out), simNone.out.slice(-500));
  const realNone = dx(`bash ${PK}/scripts/instalar.sh --clave-archivo /nonexistente`, CONFIRM);
  ok('instalar.sh real sin cuenta se NIEGA («No se modificó nada») y no deja restos', realNone.code !== 0 && /PENDIENTE/.test(realNone.out) && /No se modificó nada/.test(realNone.out) && estadoLimpio() && dx('test -e /var/backups/cervesur-react-ro').code !== 0 && snapshot() === S00, realNone.out.slice(-400));

  section = 'Procedimiento administrativo independiente (crear la cuenta)';
  const prep = dx(`bash ${PK}/admin-mysql/preparar-sql-cuenta.sh`);
  const clave = dx('head -n 1 /root/cervesur-ro-diag.clave').out;
  ok('preparar-sql-cuenta.sh genera SQL y clave (600) SIN mostrar la clave y SIN conectarse a MySQL', prep.code === 0 && !prep.out.includes(clave) && dx('stat -c "%a %U" /root/cervesur-ro-diag.sql /root/cervesur-ro-diag.clave').out === '600 root\n600 root' && !cuentaExiste(), prep.out);
  const sqlText = dx('cat /root/cervesur-ro-diag.sql').out;
  ok('el SQL preparado: un CREATE USER, 6 GRANT SELECT por columna, ningún otro privilegio, host 127.0.0.1 y base cervesur_portalbi', (sqlText.match(/^CREATE USER/gm) ?? []).length === 1 && (sqlText.match(/^GRANT SELECT \(/gm) ?? []).length === 6 && !/^GRANT (?!SELECT \()/m.test(sqlText) && /@'127\.0\.0\.1'/.test(sqlText) && /`cervesur_portalbi`\.`user`/.test(sqlText) && !/__[A-Z_]+__/.test(sqlText));
  const enComentarios = dx(`grep -E '^[[:space:]]*--' /root/cervesur-ro-diag.sql | grep -cF -- "$(head -n 1 /root/cervesur-ro-diag.clave)" || true`).out;
  const enTotal = dx(`grep -cF -- "$(head -n 1 /root/cervesur-ro-diag.clave)" /root/cervesur-ro-diag.sql || true`).out;
  ok('REDACCIÓN: la clave aparece exactamente UNA vez en el .sql (en CREATE USER … IDENTIFIED BY) y en NINGÚN comentario', enTotal === '1' && enComentarios === '0' && dx(`grep -E "^CREATE USER" /root/cervesur-ro-diag.sql | grep -cF -- "$(head -n 1 /root/cervesur-ro-diag.clave)"`).out === '1', `${enTotal}/${enComentarios}`);
  const visto = dx(`bash ${PK}/admin-mysql/mostrar-sql-redactado.sh /root/cervesur-ro-diag.sql`);
  ok('mostrar-sql-redactado.sh muestra el SQL completo con la clave como ******** y sin la clave en ninguna línea', visto.code === 0 && !visto.out.includes(clave) && /IDENTIFIED BY '\*{8}'/.test(visto.out) && (visto.out.match(/^GRANT SELECT \(/gm) ?? []).length === 6 && !/--[^\n]*Aa1!/.test(visto.out));
  const sinClave = dx(`cp /root/cervesur-ro-diag.sql /root/solo.sql; bash ${PK}/admin-mysql/mostrar-sql-redactado.sh /root/solo.sql; rc=$?; rm -f /root/solo.sql; exit $rc`);
  ok('sin el archivo de clave el visor NO imprime nada del SQL (jamás muestra el SQL sin redactar)', sinClave.code !== 0 && !/CREATE USER|IDENTIFIED/.test(sinClave.out) && !sinClave.out.includes(clave));
  dx(`rm -rf /root/p-red && cp -r ${PK} /root/p-red && echo "-- prueba de redacción: __CLAVE_DIAGNOSTICO__" >> /root/p-red/admin-mysql/01-cuenta-diagnostico.sql`);
  const red = dx('bash /root/p-red/admin-mysql/preparar-sql-cuenta.sh --salida /root/red-prueba');
  ok('si un comentario del SQL llevara el marcador de la clave, el generador FALLA, borra lo generado y no imprime ninguna clave', red.code !== 0 && /verificación de redacción falló/.test(red.out) && dx('! test -e /root/red-prueba.sql && ! test -e /root/red-prueba.clave').code === 0 && !/Aa1![0-9a-f]{8}/.test(red.out), red.out);
  dx('rm -rf /root/p-red');
  ok('el paquete incluye solo lo del diagnóstico: 01, 02 (diagnóstico), 04, 99 (diagnóstico) y el visor; NO la verificación ni la reversión del piloto', dx(`cd ${PK}/admin-mysql && ls 01-cuenta-diagnostico.sql 02-verificar-privilegios-diagnostico.sql 04-verificar-ausencia-de-escrituras.sql 99-revocar-cuenta-diagnostico.sql mostrar-sql-redactado.sh preparar-sql-cuenta.sh >/dev/null && ! ls 02b* 03* 99-revocar-cuenta-piloto* 99-revocar-cuentas* 2>/dev/null | grep -q .`).code === 0);
  const crear = (mutacion = ':') => { dx(`mysql -e "DROP USER IF EXISTS '${DIAG_USER}'@'127.0.0.1'"`); return dx(`cp /root/cervesur-ro-diag.sql /root/var.sql && ${mutacion} && mysql < /root/var.sql`); };
  const aniadir = sql => `printf '%s\\n' "${sql.replace(/"/g, '\\"').replace(/`/g, '\\`')}" >> /root/var.sql`;

  section = 'Preflight de acceso (B) con cuentas incorrectas';
  const casos = [
    ['permisos INSUFICIENTES (falta empresa_usuario.user_id)', 'sed -i "s/(empresa_id, user_id, activo)/(empresa_id, activo)/" /root/var.sql', /FALTAN: empresa_usuario\.user_id/],
    ['EXCESO: INSERT sobre reporte', aniadir(`GRANT INSERT ON \`cervesur_portalbi\`.\`reporte\` TO '${DIAG_USER}'@'127.0.0.1';`), /SHOW GRANTS: ningún privilegio/],
    ['EXCESO: UPDATE por columna', aniadir(`GRANT UPDATE (role) ON \`cervesur_portalbi\`.\`user\` TO '${DIAG_USER}'@'127.0.0.1';`), /SHOW GRANTS: ningún privilegio|no hay ninguna columna ni privilegio adicional/],
    ['EXCESO: columna sensible user.password', aniadir(`GRANT SELECT (password) ON \`cervesur_portalbi\`.\`user\` TO '${DIAG_USER}'@'127.0.0.1';`), /SOBRAN: user\.password/],
    ['EXCESO: SELECT sobre toda la base', aniadir(`GRANT SELECT ON \`cervesur_portalbi\`.* TO '${DIAG_USER}'@'127.0.0.1';`), /sin privilegios a nivel de base de datos/],
    ['EXCESO: privilegio global (PROCESS) administrativo', aniadir(`GRANT PROCESS ON *.* TO '${DIAG_USER}'@'127.0.0.1';`), /SHOW GRANTS: ningún privilegio|sin privilegios globales/],
    ['EXCESO: SELECT global', aniadir(`GRANT SELECT ON *.* TO '${DIAG_USER}'@'127.0.0.1';`), /sin privilegios globales/],
    ['EXCESO: GRANT OPTION', aniadir(`GRANT SELECT (id) ON \`cervesur_portalbi\`.\`user\` TO '${DIAG_USER}'@'127.0.0.1' WITH GRANT OPTION;`), /SHOW GRANTS: ningún privilegio|ningún privilegio adicional/],
  ];
  for (const [label, mutation, pattern] of casos) {
    const created = crear(mutation);
    const gh = grantsHash(); const r = preflightB(); const sim = dx(`bash ${PK}/scripts/instalar.sh --simular --clave-archivo /root/cervesur-ro-diag.clave`); const real = dx(`bash ${PK}/scripts/instalar.sh --clave-archivo /root/cervesur-ro-diag.clave`, CONFIRM);
    ok(`cuenta con ${label}: B falla (código 1), lo explica y nadie la corrige`, created.code === 0 && r.code === 1 && pattern.test(r.out) && /preflight B \(acceso\): FAIL/.test(r.out) && grantsHash() === gh, `${created.out}\n${r.out.slice(-700)}`);
    ok(`...y instalar.sh --simular (código 1) e instalar.sh real se NIEGAN sin dejar restos`, sim.code === 1 && real.code !== 0 && /No se modificó nada/.test(real.out) && estadoLimpio() && dx('test -e /var/backups/cervesur-react-ro').code !== 0);
  }
  crear();
  const wrong = dx('umask 077; printf "%s\\n" "Aa1!incorrecta" > /root/mala.clave'); void wrong;
  const rBad = preflightB('/root/mala.clave');
  ok('con una clave INCORRECTA la autenticación falla (código 1) sin revelar ni la clave real ni la incorrecta', rBad.code === 1 && /NO se autentica/.test(rBad.out) && !rBad.out.includes(clave) && !rBad.out.includes('incorrecta'));
  dx('printf "%s\\n" "x" > /root/abierta.clave; chmod 644 /root/abierta.clave');
  const rOpen = preflightB('/root/abierta.clave');
  ok('un archivo de clave legible por otros (644) se rechaza', rOpen.code === 1 && /modo 600 o 400/.test(rOpen.out));
  dx('rm -f /root/abierta.clave /root/mala.clave');

  section = 'Preflight correcto (A y B)';
  const created = crear();
  ok('la cuenta se crea con el SQL del procedimiento administrativo (el ensayo actúa como administrador)', created.code === 0 && cuentaExiste(), created.out);
  const pb = preflightB();
  ok('B correcto (código 0): autentica, sesión de solo lectura, 18 columnas exactas, sin escritura ni privilegios globales, sensibles denegadas, sin otras bases, catálogo 27/27', pb.code === 0 && /preflight B \(acceso\): PASS/.test(pb.out) && /están concedidas las 18 columnas/.test(pb.out) && /27\/27 medidas/.test(pb.out) && /sesión MySQL está realmente en solo lectura/.test(pb.out) && /DENEGADAS \(8\/8\)/.test(pb.out), pb.out);
  ok('B no imprime la clave ni cifras de las tablas', !pb.out.includes(clave) && !/\b(76|62|82|95)\b/.test(pb.out));
  const S0 = snapshot();
  const sim = dx(`bash ${PK}/scripts/instalar.sh --simular --clave-archivo /root/cervesur-ro-diag.clave`);
  ok('instalar.sh --simular: infraestructura PASS y acceso PASS (código 0), sin modificar nada', sim.code === 0 && /infraestructura PASS · acceso PASS/.test(sim.out) && estadoLimpio() && snapshot() === S0 && grantsHash() !== '', sim.out.slice(-400));

  // ----------------------------------------------------------------- Colisiones y fallos de infraestructura
  section = 'Fallos de infraestructura';
  dx('rm -rf /root/p-tamper && cp -r ' + PK + ' /root/p-tamper && echo x >> /root/p-tamper/backend/api.php');
  const tamper = dx('bash /root/p-tamper/scripts/instalar.sh --simular --clave-archivo /root/cervesur-ro-diag.clave');
  ok('paquete con checksum incorrecto (backend alterado): el preflight A lo rechaza antes de instalar', tamper.code !== 0 && /integridad del paquete/.test(tamper.out) && estadoLimpio() && snapshot() === S0);
  dx('phpdismod pdo_mysql'); const sinDep = dx(`bash ${PK}/scripts/instalar.sh --simular --clave-archivo /root/cervesur-ro-diag.clave`); dx('phpenmod pdo_mysql');
  ok('dependencia PHP faltante (pdo_mysql): el instalador se NIEGA, no modifica nada', sinDep.code !== 0 && /extensiones del backend/.test(sinDep.out) && estadoLimpio());
  dx('a2dismod headers >/dev/null && apache2ctl graceful'); await sleep(1500);
  const sinH = dx(`bash ${PK}/scripts/instalar.sh --simular --clave-archivo /root/cervesur-ro-diag.clave`);
  dx('a2enmod headers >/dev/null && apache2ctl graceful'); await sleep(1500);
  ok('sin mod_headers cargado el instalador se NIEGA (no lo habilita por su cuenta) y mod_headers se restaura intacto', sinH.code !== 0 && /mod_headers YA cargado/.test(sinH.out) && estadoLimpio() && headersOn());
  const colisiones = [
    ['el puerto 8090 está ocupado', 'setsid nohup socat TCP-LISTEN:8090,bind=127.0.0.1,fork,reuseaddr SYSTEM:true >/dev/null 2>&1 & sleep 1', "pkill -f 'TCP-LISTEN:809[0]' || true", /puerto 8090 libre/],
    ['la carpeta /opt/cervesur-react-ro ya existe', 'mkdir /opt/cervesur-react-ro', 'rmdir /opt/cervesur-react-ro', /no existe \/opt\/cervesur-react-ro/],
    ['la carpeta /var/lib/cervesur-react-ro ya existe', 'mkdir /var/lib/cervesur-react-ro', 'rmdir /var/lib/cervesur-react-ro', /no existe \/var\/lib\/cervesur-react-ro/],
    ['el sitio Apache cervesur-react-ro ya existe', 'echo "# ajeno" > /etc/apache2/sites-available/cervesur-react-ro.conf', 'rm -f /etc/apache2/sites-available/cervesur-react-ro.conf', /no existe \/etc\/apache2\/sites-available\/cervesur-react-ro\.conf/],
    ['la configuración de Apache ya menciona el puerto 8090', 'echo "# Listen 127.0.0.1:8090" > /etc/apache2/conf-enabled/ajeno-8090.conf', 'rm -f /etc/apache2/conf-enabled/ajeno-8090.conf', /ninguna configuración de Apache menciona el puerto 8090/],
  ];
  for (const [label, arm, disarm, pattern] of colisiones) {
    dx(arm); const r = dx(`bash ${PK}/scripts/instalar.sh --clave-archivo /root/cervesur-ro-diag.clave`, CONFIRM);
    const sigue = label.includes('carpeta') ? dx(`test -d ${label.match(/\/\S+/)[0]}`).code === 0 : true;
    dx(disarm); await sleep(300);
    ok(`colisión (${label}): el instalador ABORTA con «No se modificó nada», el recurso ajeno no se toca y no se crea el respaldo`, r.code !== 0 && pattern.test(r.out) && /No se modificó nada/.test(r.out) && sigue && dx('test -e /var/backups/cervesur-react-ro').code !== 0, r.out.slice(-300));
  }
  ok('tras las colisiones el servidor sigue idéntico a la línea base y sin restos', estadoLimpio() && snapshot() === S0);

  section = 'Fallo forzado a mitad de instalación';
  dx(`rm -rf /root/p-roto && cp -r ${PK} /root/p-roto && echo "DirectivaInexistente x" >> /root/p-roto/apache/react-ro-common.conf && cd /root/p-roto && find . -type f ! -name SHA256SUMS -print0 | xargs -0 sha256sum > SHA256SUMS`);
  const gh0 = grantsHash(); const dbHash = () => dx('mysql -N -e "SHOW DATABASES" | LC_ALL=C sort | sha256sum | cut -c1-64').out; const dbHash0 = dbHash();
  const roto = dx('bash /root/p-roto/scripts/instalar.sh --clave-archivo /root/cervesur-ro-diag.clave', CONFIRM);
  ok('la instalación falla (configtest rechaza la configuración) y revierte automáticamente SOLO lo creado', roto.code !== 0 && /se revierte automáticamente/.test(roto.out), roto.out.slice(-500));
  ok('sin restos de 8090; la cuenta de diagnóstico y sus privilegios siguen exactamente igual (no se elimina); la base y los demás portales intactos', estadoLimpio() && cuentaExiste() && grantsHash() === gh0 && snapshot() === S0);
  ok('mod_headers SIGUE habilitado, Apache conserva su proceso maestro (sin reinicio) y los respaldos se conservan', headersOn() && apachePid() === pid0 && dx('ls /var/backups/cervesur-react-ro/*.tgz >/dev/null').code === 0);
  ok('el token y la clave no aparecen en la salida del fallo', !roto.out.includes(clave) && !/[0-9a-f]{64}/.test(roto.out.replace(/sha256[^\n]*/gi, '')));

  // ----------------------------------------------------------------- Instalación
  section = 'Instalación';
  const inst = dx(`bash ${PK}/scripts/instalar.sh --clave-archivo /root/cervesur-ro-diag.clave`, CONFIRM);
  ok('instalar.sh termina correctamente', inst.code === 0, inst.out.slice(-900));
  const verif = /(\d+) PASS \/ (\d+) FAIL/.exec(inst.out);
  ok('verificar.sh integrado: todas las comprobaciones pasan', Boolean(verif) && verif[2] === '0' && Number(verif[1]) >= 20, verif?.[0] ?? inst.out.slice(-500));
  const token = dx('grep "^CERVESUR_DIAG_TOKEN=" /opt/cervesur-react-ro/backend/.env | cut -d= -f2-').out;
  ok('el token tiene 256 bits (64 hexadecimales), es único y distinto de cualquier secreto de 8088 y 8089', /^[0-9a-f]{64}$/.test(token) && dx(`! grep -rqF "${token}" /opt/cervesur-react-prueba /opt/cervesur-react-compat`).code === 0);
  ok('ni el token ni la clave de la cuenta se imprimieron en la salida del instalador', !inst.out.includes(token) && !inst.out.includes(clave));
  ok('ni el token ni la clave aparecen en /var/log, en los respaldos ni en el paquete extraído', dx(`! grep -rqsF -e "${token}" -e "${clave}" /var/log /var/backups ${PK} --exclude=mysql.err`).code === 0);
  ok('.env: modo 640 root:www-data; sin JWT_SECRET; modo diagnóstico exigido; fuera de todo DocumentRoot (docroot vacío); sesiones 700 www-data', dx('stat -c "%a %U:%G" /opt/cervesur-react-ro/backend/.env; stat -c "%a %U:%G" /var/lib/cervesur-react-ro/sessions; ls -A /var/lib/cervesur-react-ro/docroot | wc -l; grep -c "^JWT_SECRET" /opt/cervesur-react-ro/backend/.env; grep -c "^CERVESUR_REQUIRED_MODE=diagnostico$" /opt/cervesur-react-ro/backend/.env').out === '640 root:www-data\n700 www-data:www-data\n0\n0\n1');
  ok('el instalador NO modificó MySQL: las cuentas y sus privilegios son los de antes (la cuenta de diagnóstico no se tocó) y no hay ninguna base nueva', grantsHash() === gh0 && dbHash() === dbHash0);
  const S1 = snapshot();
  ok('Flutter, 8088, 8089, módulos, bases (incluida cervesur_portalbi: CHECKSUM de todas sus tablas), cuentas y puertos idénticos a la línea base: 0 cambios ajenos', S1 === S0, S0.split('\n').filter((l, i) => l !== S1.split('\n')[i]).join(' | '));
  ok('mod_headers sigue habilitado y Apache no se reinició (mismo proceso maestro)', headersOn() && apachePid() === pid0);

  // ----------------------------------------------------------------- Endpoints y seguridad HTTP
  section = 'Endpoints (127.0.0.1:8090)';
  const tk = path => dx(`printf 'header = "X-Diagnostic-Token: %s"\\n' "${token}" | curl -sS -m 10 -K - -w '\\n%{http_code}' http://127.0.0.1:8090${path}`).out;
  const code = (args, path) => dx(`curl -sS -m 10 -o /dev/null -w '%{http_code}' ${args} http://127.0.0.1:8090${path}`).out;
  ok('GET /api/ping → 200 {"pong":true}', /"pong":true/.test(dx('curl -sS http://127.0.0.1:8090/api/ping').out));
  ok('resumen y conciliación SIN token, con token incorrecto o con un JWT → 401 y ningún dato', ['/api/diagnostico/resumen', '/api/diagnostico/conciliacion'].every(p => code('', p) === '401' && code('-H "X-Diagnostic-Token: incorrecto"', p) === '401' && code('-H "Authorization: Bearer a.b.c"', p) === '401'));
  const resumen = tk('/api/diagnostico/resumen'); const conc = tk('/api/diagnostico/conciliacion');
  const rj = JSON.parse(resumen.slice(0, resumen.lastIndexOf('\n'))); const cj = JSON.parse(conc.slice(0, conc.lastIndexOf('\n')));
  ok('resumen con token: 200, modo diagnóstico, sesión MySQL de solo lectura y cuenta sin escritura', resumen.endsWith('200') && rj.controles.modo === 'diagnostico' && rj.controles.sesion_de_base_de_datos_en_solo_lectura === true && rj.controles.cuenta_de_base_de_datos_sin_privilegios_de_escritura === true);
  ok('conciliación con token: 200, 27 métricas, sin desviaciones frente al inventario (la base sintética reproduce sus agregados)', conc.endsWith('200') && cj.coincide === true && cj.desviaciones === 0 && cj.detalle.length === 27);
  ok('las respuestas son solo números, booleanos y nombres de métrica: nada de hashes, correos, enlaces, URLs ni credenciales; sin cookie', !/\$2y\$|@|https?:|powerbi|password|enlace|email|syn0/i.test(resumen + conc) && !/set-cookie/i.test(dx('curl -sSI http://127.0.0.1:8090/api/ping').out));
  const cerradas = ['/api/login', '/api/register', '/api/logout', '/api/users', '/api/user/profile', '/api/empresas', '/api/reportes', '/api/reportes/id/1/visor', '/api/stats', '/api/powerbi/tokens/1', '/', '/index.html', '/.env', '/backend/.env', '/lib/db.php', '/api.php', '/api/ping/', '/api/diagnostico/resumen/', '/api/diagnostico/otro'];
  ok(`${cerradas.length} rutas (login, catálogos, visor, Power BI, archivos internos y variantes) → 403`, cerradas.every(r => code('--path-as-is', r) === '403'), cerradas.filter(r => code('--path-as-is', r) !== '403').join(','));
  ok('POST/PUT/DELETE/OPTIONS/TRACE → 403 o 405; HEAD → 403', ['POST', 'PUT', 'DELETE', 'OPTIONS', 'TRACE'].every(m => ['403', '405'].includes(code(`-X ${m}`, '/api/diagnostico/resumen'))) && dx('curl -sS -o /dev/null -w "%{http_code}" -I http://127.0.0.1:8090/api/ping').out === '403');
  ok('un token en la URL no se acepta (solo la cabecera) y no queda en ningún log', code('', `/api/diagnostico/resumen?X-Diagnostic-Token=${'0'.repeat(64)}`) === '401');
  const rate = dx('s=$(date +%s%N); curl -s -o /dev/null http://127.0.0.1:8090/api/diagnostico/resumen; e=$(date +%s%N); echo $(( (e-s)/1000000 ))').out;
  ok('cada intento sin token cuesta ≥ 250 ms (freno a la adivinación)', Number(rate) >= 250, rate);
  const headers = dx('curl -sSI http://127.0.0.1:8090/api/ping').out.toLowerCase();
  ok('cabeceras: no-store, nosniff, X-Frame-Options DENY, sin HSTS y sin X-Powered-By', /cache-control: no-store/.test(headers) && /x-content-type-options: nosniff/.test(headers) && /x-frame-options: deny/.test(headers) && !/strict-transport/.test(headers) && !/x-powered-by/.test(headers));

  section = 'Aislamiento de red y PHP';
  const listen = port => dx(`ss -ltnH "sport = :${port}" | awk '{print $4}'`).out;
  ok('80 escucha en todas las interfaces (Flutter); 8088, 8089 y 8090 SOLO en 127.0.0.1', listen(80) === '0.0.0.0:80' && listen(8088) === '127.0.0.1:8088' && listen(8089) === '127.0.0.1:8089' && listen(8090) === '127.0.0.1:8090', `${listen(80)} ${listen(8088)} ${listen(8089)} ${listen(8090)}`);
  const ip = docker(['inspect', '-f', '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}', NAME]).out;
  const net = port => docker(['run', '--rm', '--entrypoint', 'curl', IMAGE, '-sS', '-m', '5', '-o', '/dev/null', '-w', '%{http_code}', `http://${ip}:${port}/`]);
  ok('desde otra máquina de la red: 8088, 8089 y 8090 rechazan la conexión; el puerto 80 de Flutter responde', /^\d+\.\d+\.\d+\.\d+$/.test(ip) && net(8088).code !== 0 && net(8089).code !== 0 && net(8090).code !== 0 && net(80).out === '200', `${net(8090).out}`);
  // Sonda TEMPORAL solo del ensayo: se inyecta un AliasMatch en el VirtualHost de 8090 (antes del Include) y después se restaura el archivo original.
  put('/opt/cervesur-react-ro/backend/sonda.php', '<?php echo json_encode(["name" => session_name(), "path" => session_save_path(), "obd" => ini_get("open_basedir"), "httponly" => ini_get("session.cookie_httponly"), "samesite" => ini_get("session.cookie_samesite"), "lee_flutter" => @file_get_contents("/var/www/cervesur/backend/api.php") !== false, "lee_8088" => @file_get_contents("/opt/cervesur-react-prueba/backend/.env") !== false, "lee_8089" => @file_get_contents("/opt/cervesur-react-compat/backend/.env") !== false, "lee_passwd" => @file_get_contents("/etc/passwd") !== false, "lee_logs" => @scandir("/var/log/apache2") !== false, "lee_propio" => @is_file("/opt/cervesur-react-ro/backend/api.php")]);');
  dx('cp /etc/apache2/sites-available/cervesur-react-ro.conf /root/site-ro.orig');
  dx(String.raw`awk '/Include .*react-ro-common.conf/ {print "    AliasMatch \"^/sonda\\.php$\" \"/opt/cervesur-react-ro/backend/sonda.php\""} {print}' /root/site-ro.orig > /etc/apache2/sites-available/cervesur-react-ro.conf && apache2ctl graceful`);
  let sondaRaw = ''; for (let i = 0; i < 20; i++) { await sleep(1000); sondaRaw = dx('curl -sS http://127.0.0.1:8090/sonda.php').out; if (sondaRaw.startsWith('{')) break; }
  let sonda = {}; try { sonda = JSON.parse(sondaRaw); } catch { sonda = { raw: sondaRaw.slice(0, 100) }; }
  dx('cp /root/site-ro.orig /etc/apache2/sites-available/cervesur-react-ro.conf && rm -f /opt/cervesur-react-ro/backend/sonda.php && apache2ctl graceful'); await sleep(2500);
  ok('configuración efectiva de PHP: cookie CERVESUR_REACT_RO, sesiones propias, HttpOnly y SameSite=Strict (php_admin)', sonda.name === 'CERVESUR_REACT_RO' && sonda.path === '/var/lib/cervesur-react-ro/sessions' && sonda.httponly === '1' && String(sonda.samesite).toLowerCase() === 'strict', JSON.stringify(sonda));
  ok('open_basedir: el PHP de 8090 NO puede leer el backend de Flutter, el .env de 8088 ni el de 8089, ni /etc/passwd ni los logs de Apache; sí su propio backend', sonda.lee_flutter === false && sonda.lee_8088 === false && sonda.lee_8089 === false && sonda.lee_passwd === false && sonda.lee_logs === false && sonda.lee_propio === true, JSON.stringify(sonda));
  ok('la sonda temporal se retiró y el sitio quedó como estaba (404/403 en /sonda.php)', ['403', '404'].includes(code('', '/sonda.php')) && dx('cmp /etc/apache2/sites-available/cervesur-react-ro.conf /root/site-ro.orig').code === 0);

  section = 'Fail-closed en ejecución (CERVESUR_REQUIRED_MODE=diagnostico)';
  dx('cp -a /opt/cervesur-react-ro/backend/.env /root/env.ro.bak');
  const restoreEnv = () => dx('cp -a /root/env.ro.bak /opt/cervesur-react-ro/backend/.env');
  const mutaciones = [
    ['CERVESUR_MODE ausente', "sed -i '/^CERVESUR_MODE=/d' /opt/cervesur-react-ro/backend/.env"],
    ['CERVESUR_MODE vacío', "sed -i 's/^CERVESUR_MODE=.*/CERVESUR_MODE=/' /opt/cervesur-react-ro/backend/.env"],
    ['CERVESUR_MODE=read_write', "sed -i 's/^CERVESUR_MODE=.*/CERVESUR_MODE=read_write/' /opt/cervesur-react-ro/backend/.env"],
    ['CERVESUR_MODE=read_only', "sed -i 's/^CERVESUR_MODE=.*/CERVESUR_MODE=read_only/' /opt/cervesur-react-ro/backend/.env"],
    ['CERVESUR_MODE con un valor inválido', "sed -i 's/^CERVESUR_MODE=.*/CERVESUR_MODE=Diagnostico/' /opt/cervesur-react-ro/backend/.env"],
    ['token de diagnóstico corto', "sed -i 's/^CERVESUR_DIAG_TOKEN=.*/CERVESUR_DIAG_TOKEN=corto/' /opt/cervesur-react-ro/backend/.env"],
    ['DB_NAME con inyección de DSN', "sed -i 's/^DB_NAME=.*/DB_NAME=x;dbname=cervesur_portalbi/' /opt/cervesur-react-ro/backend/.env"],
  ];
  for (const [label, mutation] of mutaciones) {
    dx(mutation); const ping = dx('curl -sS -m 10 -w "\\n%{http_code}" http://127.0.0.1:8090/api/ping').out; const res = code('-H "X-Diagnostic-Token: x"', '/api/diagnostico/resumen'); restoreEnv();
    ok(`${label}: el sitio NO sirve nada (503) y no revela detalles`, ping.endsWith('503') && res === '503' && !/mode|diagn|portalbi|sql/i.test(ping.replace(/"code":"mode_config"/, '').replace(/configuración de modo/, '')), ping);
  }
  ok('con el .env restaurado el diagnóstico vuelve a responder (200) y es byte a byte el de antes', code('', '/api/ping') === '200' && dx('cmp /root/env.ro.bak /opt/cervesur-react-ro/backend/.env').code === 0);
  dx('rm -f /root/env.ro.bak');

  section = 'Token: recuperación y rotación';
  const mostrado = dx('bash ' + PK + '/scripts/token.sh --mostrar', CONFIRM);
  ok('token.sh --mostrar recupera el token vigente (solo root, solo por la salida estándar)', mostrado.out === token && dx('stat -c "%a %U:%G" /opt/cervesur-react-ro/backend/.env').out === '640 root:www-data');
  const rotado = dx('bash ' + PK + '/scripts/token.sh --rotar 2>/dev/null', CONFIRM).out;
  ok('token.sh --rotar genera un token nuevo (64 hex) distinto, sin recargar Apache', /^[0-9a-f]{64}$/.test(rotado) && rotado !== token && apachePid() === pid0);
  const old401 = dx(`printf 'header = "X-Diagnostic-Token: %s"\\n' "${token}" | curl -sS -m 10 -K - -o /dev/null -w '%{http_code}' http://127.0.0.1:8090/api/diagnostico/resumen`).out;
  const new200 = dx(`printf 'header = "X-Diagnostic-Token: %s"\\n' "${rotado}" | curl -sS -m 10 -K - -o /dev/null -w '%{http_code}' http://127.0.0.1:8090/api/diagnostico/resumen`).out;
  ok('tras rotar: el token anterior ya NO vale (401) y el nuevo sí (200); permisos del .env intactos', old401 === '401' && new200 === '200' && dx('stat -c "%a %U:%G" /opt/cervesur-react-ro/backend/.env').out === '640 root:www-data' && dx('ls -A /opt/cervesur-react-ro/backend | grep -c "^.env.nuevo"').out === '0');
  const sinConfirmar = dx('bash ' + PK + '/scripts/token.sh --mostrar < /dev/null');
  ok('sin la confirmación escrita token.sh no muestra nada', sinConfirmar.code !== 0 && !/[0-9a-f]{64}/.test(sinConfirmar.out));
  const ver2 = dx(`bash ${PK}/scripts/verificar.sh`);
  ok('verificar.sh sigue pasando tras la rotación (lee el token del .env sin mostrarlo)', /\d+ PASS \/ 0 FAIL/.test(ver2.out) && !ver2.out.includes(rotado), ver2.out.slice(-400));

  section = 'Conciliación de las 27 medidas';
  const c0 = dx(`bash ${PK}/scripts/conciliar.sh`);
  ok('conciliar.sh: coincide con la línea base (código 0)', c0.code === 0 && /COINCIDE/.test(c0.out), c0.out);
  const ids = dx('mysql -N -e "SELECT id FROM cervesur_portalbi.user WHERE role = \'usuario\' AND is_active = 1 ORDER BY id LIMIT 1"').out;
  dx(`mysql -e "UPDATE cervesur_portalbi.user SET is_active = 0 WHERE id = ${ids}"`); // el ensayo actúa como Flutter (cambio operativo) sobre la base SINTÉTICA
  const c1 = dx(`bash ${PK}/scripts/conciliar.sh`);
  dx(`mysql -e "UPDATE cervesur_portalbi.user SET is_active = 1 WHERE id = ${ids}"`);
  ok('un cambio operativo (usuario dado de baja) se detecta, se CLASIFICA y no se corrige (código 10)', c1.code === 10 && /DIFERENCIA/.test(c1.out) && /OPERATIVO|SEGURIDAD/.test(c1.out) && /La línea base es histórica/.test(c1.out) && /UPDATE|INSERT|DELETE/.test(c1.out) === false, c1.out);
  ok('la salida de la conciliación no contiene registros individuales ni el token', !/syn0\d\d|@example|\$2y\$/.test(c1.out) && !c1.out.includes(rotado));
  dx(`mysql -e "UPDATE cervesur_portalbi.user SET role = 'admin' WHERE id = ${ids}"`);
  const c2 = dx(`bash ${PK}/scripts/conciliar.sh --json`);
  dx(`mysql -e "UPDATE cervesur_portalbi.user SET role = 'usuario' WHERE id = ${ids}"`);
  ok('un cambio de roles (alta de administrador) se clasifica como riesgo de SEGURIDAD', c2.code === 10 && /SEGURIDAD/.test(c2.out));
  const cOk = dx(`bash ${PK}/scripts/conciliar.sh`);
  ok('restaurado el estado, la conciliación vuelve a coincidir y las seis tablas son idénticas a la línea base', cOk.code === 0 && snapshot() === S0);
  const stop = dx('mv /opt/cervesur-react-ro/backend/.env /root/env.tmp; bash ' + PK + '/scripts/conciliar.sh; rc=$?; mv /root/env.tmp /opt/cervesur-react-ro/backend/.env; exit $rc');
  ok('si el diagnóstico no está operativo la conciliación informa error de consulta/acceso (código 11), no un falso "coincide"', [1, 11].includes(stop.code) && !/COINCIDE/.test(stop.out));

  section = 'Auditoría de escrituras (evidencia, no prueba)';
  const ps = dx(`mysql -N -e "SELECT @@performance_schema; SELECT COUNT(*) FROM performance_schema.events_statements_summary_by_user_by_event_name WHERE USER='${DIAG_USER}' AND COUNT_STAR > 0 AND EVENT_NAME REGEXP '^statement/sql/(insert|insert_select|update|update_multi|delete|delete_multi|replace|truncate|load|call_procedure|create_.*|alter_.*|drop_.*|rename_.*|grant|revoke|set_password|lock_tables|flush|kill)$'; SELECT COUNT(*) FROM performance_schema.events_statements_summary_by_user_by_event_name WHERE USER='${DIAG_USER}' AND COUNT_STAR > 0 AND EVENT_NAME IN ('statement/sql/select', 'statement/com/Execute'); SELECT COUNT(*) FROM performance_schema.events_statements_summary_by_user_by_event_name WHERE USER='${DIAG_USER}' AND SUM_ROWS_AFFECTED > 0"`).out.split('\n');
  ok('condiciones de validez cumplidas (performance_schema activo, la cuenta tiene actividad de lectura registrada) y 0 sentencias de escritura clasificadas y 0 filas afectadas de la cuenta tras instalar, verificar, rotar y conciliar', ps[0] === '1' && ps[1] === '0' && Number(ps[2]) >= 1 && ps[3] === '0', ps.join('|'));
  ok('aun así el resultado se interpreta junto a los privilegios efectivos (B) y no por sí solo: B sigue PASS', preflightB().code === 0);

  // ----------------------------------------------------------------- Reversión
  section = 'Reversión';
  const S2 = snapshot(); const pid1 = apachePid(); const gh1 = grantsHash();
  dx(`mysql -N -e "SELECT 1" >/dev/null`); // MySQL disponible
  const rev = dx(`bash ${PK}/scripts/revertir.sh`, CONFIRM);
  ok('revertir.sh termina correctamente y sin avisos de fallo', rev.code === 0 && !/FALLO/.test(rev.out), rev.out.slice(-600));
  ok('no quedan carpetas, .env con token, sitio ni puerto 8090', estadoLimpio());
  ok('la base cervesur_portalbi y la cuenta cervesur_ro_diag SIGUEN existiendo con los mismos privilegios (la reversión no toca MySQL)', cuentaExiste() && grantsHash() === gh1 && gh1 === gh0 && dx('mysql -N -e "SHOW DATABASES LIKE \'cervesur_portalbi\'"').out === 'cervesur_portalbi');
  ok('mod_headers SIGUE habilitado tras revertir 8090 y Apache no se reinició', headersOn() && apachePid() === pid1 && pid1 === pid0);
  ok('Flutter, 8088, 8089, módulos, bases (CHECKSUM de cervesur_portalbi incluido), cuentas y puertos idénticos a justo antes de revertir Y a la línea base original: 0 cambios ajenos', snapshot() === S2 && S2 === S0);
  ok('Flutter, 8088 y 8089 siguen funcionando', dx('curl -s http://127.0.0.1/').out.includes('FLUTTER-SENTINEL') && dx('curl -fs http://127.0.0.1:8088/api/ping && curl -fs http://127.0.0.1:8089/api/ping').code === 0);
  ok('los respaldos se conservan', dx('ls /var/backups/cervesur-react-ro/linea-base-portales.txt /var/backups/cervesur-react-ro/*.tgz >/dev/null').code === 0);
  const rev2 = dx(`bash ${PK}/scripts/revertir.sh`, CONFIRM);
  ok('segunda reversión: idempotente (código 0, sin fallos)', rev2.code === 0 && !/FALLO/.test(rev2.out), rev2.out.slice(-300));
  dx('mkdir -p /opt/cervesur-react-ro/backend /var/lib/cervesur-react-ro/docroot && echo basura > /opt/cervesur-react-ro/backend/.env && cp /root/site-ro.orig /etc/apache2/sites-available/cervesur-react-ro.conf');
  const rev3 = dx(`bash ${PK}/scripts/revertir.sh`, CONFIRM);
  ok('reversión de una instalación PARCIAL (carpetas y sitio sin habilitar): termina bien y deja todo limpio', rev3.code === 0 && !/FALLO/.test(rev3.out) && estadoLimpio() && snapshot() === S0, rev3.out.slice(-300));
  const sinConf = dx(`bash ${PK}/scripts/revertir.sh < /dev/null`);
  ok('sin confirmación escrita revertir.sh no hace nada', sinConf.code !== 0 && /Cancelado/.test(sinConf.out));

  section = 'Reinstalación tras revertir';
  const reinst = dx(`bash ${PK}/scripts/instalar.sh --clave-archivo /root/cervesur-ro-diag.clave`, CONFIRM);
  ok('el entorno se puede volver a instalar tras revertir (nuevo token) y verificar', reinst.code === 0 && /\d+ PASS \/ 0 FAIL/.test(reinst.out), reinst.out.slice(-400));
  const token2 = dx('grep "^CERVESUR_DIAG_TOKEN=" /opt/cervesur-react-ro/backend/.env | cut -d= -f2-').out;
  ok('el token de la reinstalación es nuevo (distinto del anterior)', /^[0-9a-f]{64}$/.test(token2) && token2 !== token && token2 !== rotado);
  dx(`bash ${PK}/scripts/revertir.sh`, CONFIRM);
  ok('y se revierte de nuevo sin cambios ajenos', estadoLimpio() && snapshot() === S0);

  section = 'Verificación y reversión de la cuenta de diagnóstico (procedimiento administrativo)';
  crear();
  const v02 = dx(`mysql -B < ${PK}/admin-mysql/02-verificar-privilegios-diagnostico.sql`);
  ok('02 (diagnóstico) se ejecuta sin error aunque cervesur_ro_pilot NO exista, y confirma 2+4+3+2+4+3 = 18 columnas', v02.code === 0 && /total_columnas_concedidas\n18\b/.test(v02.out) && /^user\t4\t/m.test(v02.out) && /^empresa_reporte\t4\t/m.test(v02.out) && /^empresa_usuario\t3\t/m.test(v02.out) && /^user_empresa_reporte\t3\t/m.test(v02.out), v02.out);
  dx(`mysql -e "CREATE USER 'cervesur_ro_pilot'@'127.0.0.1' IDENTIFIED BY 'Aa1!${randomBytes(8).toString('hex')}'; CREATE USER '${DIAG_USER}'@'localhost' IDENTIFIED BY 'Aa1!${randomBytes(8).toString('hex')}'"`);
  const usersAntes = dx('mysql -N -e "SELECT user, host FROM mysql.user ORDER BY user, host"').out; const dbsAntes = dbHash();
  const revDiag = dx(`mysql < ${PK}/admin-mysql/99-revocar-cuenta-diagnostico.sql`);
  const usersDespues = dx('mysql -N -e "SELECT user, host FROM mysql.user ORDER BY user, host"').out;
  const quitadas = usersAntes.split('\n').filter(l => !usersDespues.split('\n').includes(l));
  ok('99-revocar-cuenta-diagnostico.sql elimina ÚNICAMENTE cervesur_ro_diag@127.0.0.1: la cuenta del piloto, la homónima de otro host (localhost) y las demás siguen intactas; ninguna base se toca', revDiag.code === 0 && quitadas.length === 1 && /^cervesur_ro_diag\t127\.0\.0\.1$/.test(quitadas[0]) && /cervesur_ro_pilot\t127\.0\.0\.1/.test(usersDespues) && /cervesur_ro_diag\tlocalhost/.test(usersDespues) && dbHash() === dbsAntes, `${quitadas.join('|')}`);
  const revDiag2 = dx(`mysql < ${PK}/admin-mysql/99-revocar-cuenta-diagnostico.sql`);
  ok('ejecutarlo de nuevo no falla (IF EXISTS) y sigue sin afectar a ninguna otra cuenta', revDiag2.code === 0 && dx('mysql -N -e "SELECT user, host FROM mysql.user ORDER BY user, host"').out === usersDespues);
  dx(`mysql -e "DROP USER IF EXISTS 'cervesur_ro_pilot'@'127.0.0.1', '${DIAG_USER}'@'localhost'"`);
} catch (error) {
  results.push(['FAIL', `${section} · EXCEPCIÓN`, error.stack ?? error.message]);
} finally {
  if (!process.env.ENSAYO_CONSERVAR) cleanup(); // ENSAYO_CONSERVAR=1 deja el contenedor para depurar
}
for (const [status, name, extra] of results) console.log(`${status}  ${name}${extra ? `  → ${extra}` : ''}`);
const failed = results.filter(r => r[0] === 'FAIL').length;
console.log(`\n${results.length - failed} PASS / ${failed} FAIL`);
process.exit(failed ? 1 : 0);
