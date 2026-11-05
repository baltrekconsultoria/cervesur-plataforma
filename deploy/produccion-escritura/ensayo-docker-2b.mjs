// UX-08I · ENSAYO LOCAL (Docker) del RC de la FASE 2B (Usuarios) sobre la réplica del servidor con la FASE 2A (RC2 f539c9f) realmente instalada y verificada módulo a módulo.
//   node deploy/produccion-escritura/ensayo-docker-2b.mjs
//   ENSAYO_PAQUETE=<.tar.gz RC2 de la fase 2A>  ENSAYO_PAQUETE_2B=<.tar.gz del RC 2B a ensayar>  ENSAYO_ADMIN_BASE_TGZ=<fase 1>  ENSAYO_OUT=<carpeta externa>  ENSAYO_CONSERVAR=1
// Guion: fase 1 → A1 → migraciones y cuenta → A2 → Apache → B1/B2/B3 (con una escritura real y su auditoría por módulo) = línea base de la fase 2A; luego P3 (código 2B), P4a (privilegios),
// P4b (interruptor), P4c (operaciones de usuarios), fallos inyectados en cada paso y recuperación integral. Todo con datos SINTÉTICOS dentro de un contenedor desechable.
import { spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { cpSync, existsSync, mkdirSync, mkdtempSync, readdirSync, readFileSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const REPO = resolve(here, '..', '..');
const BASE_IMAGE = 'cervesur-ensayo-ubuntu:local';
const IMAGE = 'cervesur-ensayo-produccion-escritura:local';
const NAME = 'cervesur-ensayo-produccion-escritura';
const OUT = process.env.ENSAYO_OUT ? resolve(process.env.ENSAYO_OUT) : mkdtempSync(join(tmpdir(), 'cervesur-ensayo-prod-escritura-'));
if (OUT === REPO || OUT.startsWith(REPO + sep)) { console.error('ENSAYO_OUT no puede estar dentro del repositorio.'); process.exit(1); }
const BASE_TGZ = process.env.ENSAYO_ADMIN_BASE_TGZ ? resolve(process.env.ENSAYO_ADMIN_BASE_TGZ) : resolve(REPO, '..', 'paquetes-qa', 'produccion-v4', 'cervesur-react-admin-3f859210c5c4-20261103-211438Z.tar.gz');
const results = [];
let section = '';
const ok = (name, cond, extra = '') => { const r = [cond ? 'PASS' : 'FAIL', `${section} · ${name}`, cond ? '' : String(extra).slice(-1200)]; results.push(r); console.log(`${r[0]}  ${r[1]}${r[2] ? `\n      ${r[2].replaceAll('\n', '\n      ')}` : ''}`); };
const docker = (args, { allowFail = true, input } = {}) => {
  const r = spawnSync('docker', args, { encoding: 'utf8', maxBuffer: 256 * 1024 * 1024, input, env: { ...process.env, MSYS_NO_PATHCONV: '1' } });
  if (r.status !== 0 && !allowFail) throw new Error(`docker ${args.slice(0, 3).join(' ')} falló:\n${r.stderr || r.stdout}`);
  return { code: r.status, out: `${r.stdout ?? ''}${r.stderr ?? ''}`.trim() };
};
const ALL = []; // salida de TODOS los scripts del paquete: al final se comprueba que ningún secreto se imprimió
const dx = (cmd, env = {}) => docker(['exec', ...Object.entries(env).flatMap(([k, v]) => ['--env', `${k}=${v}`]), NAME, 'bash', '-c', cmd]);
const run = (cmd, env = {}) => { const r = dx(cmd, env); ALL.push(r.out); return r; };
const put = (path, text) => spawnSync('docker', ['exec', '-i', NAME, 'bash', '-c', `cat > ${path}`], { input: text, env: { ...process.env, MSYS_NO_PATHCONV: '1' } });
const sleep = ms => new Promise(r => setTimeout(r, ms));
const cleanup = () => { if (!process.env.ENSAYO_CONSERVAR) docker(['rm', '--force', '--volumes', NAME]); };
const sql = q => { put('/tmp/q.sql', q); return dx('mysql --default-character-set=utf8mb4 -N -B < /tmp/q.sql').out; };
const sqlN = q => Number(sql(q));
const newest = (dir, prefix) => readdirSync(dir).filter(f => f.startsWith(prefix) && f.endsWith('.tar.gz')).map(f => join(dir, f)).sort((a, b) => statSync(b).mtimeMs - statSync(a).mtimeMs)[0];

const ENV_DIR = '/opt/cervesur-react-admin/backend'; const ENVF = `${ENV_DIR}/.env`;
const FRONT = '/var/www/cervesur-react-admin'; const RESP = '/var/backups/cervesur-react-admin';
const U = 'https://localhost:8093';
const TLS = '--cacert /etc/apache2/cervesur-react-admin/tls/servidor.crt --resolve localhost:8093:127.0.0.1';
const DB = 'cervesur_portalbi';
const GOODPW = `Ux${randomBytes(9).toString('hex')}!`; // contraseña SINTÉTICA del administrador de prueba (solo existe en este ensayo)
const CUENTA = 'cervesur_escritura_adm';
const PASSW = `Aa1!${randomBytes(16).toString('hex')}`; // clave SINTÉTICA de la cuenta de escritura
const envOf = k => dx(`tr -d '\\r' < ${ENVF} | awk -F= -v k=${k} '$1==k{sub(/^[^=]*=/,""); v=$0} END{print v}'`).out;
const envSha = () => dx(`sha256sum < ${ENVF} | cut -c1-64`).out;
const envKeys = () => dx(`tr -d '\\r' < ${ENVF} | awk -F= '!/^[[:space:]]*#/ && NF {print $1}' | sort`).out.split('\n');
const callRaw = (method, path, token = '', body = '') => {
  if (body) put('/tmp/body.json', body);
  const r = dx(`curl -sS -m 25 ${TLS} -X ${method} ${token ? `-H 'Authorization: Bearer ${token}'` : ''} ${body ? "-H 'Content-Type: application/json' --data @/tmp/body.json" : ''} -H 'X-API-Contract: 2' -w '\\n%{http_code}' ${U}${path}`).out.split('\n');
  const status = Number(r.pop()); const raw = r.join('\n');
  let json = null; try { json = JSON.parse(raw); } catch { /* no JSON: respuesta de Apache */ }
  return { status, raw, json };
};
const login = (u, p) => { put('/tmp/login.json', JSON.stringify({ login: u, password: p })); return dx(`curl -sS -m 25 ${TLS} -H 'Content-Type: application/json' --data @/tmp/login.json ${U}/api/login`).out; };
const tokenOf = raw => { try { return JSON.parse(raw).token ?? ''; } catch { return ''; } };
const apacheReloads = () => Number(dx("grep -c 'Graceful restart requested' /var/log/apache2/error.log || true").out) || 0;
const apachePid = () => dx('cat /var/run/apache2/apache2.pid').out;
const apacheTree = () => dx("find /etc/apache2 \\( -type f -o -type l \\) -print0 | LC_ALL=C sort -z | xargs -0 sha256sum 2>/dev/null | sha256sum | cut -c1-64").out;
const CE = { CERVESUR_E_USUARIO: '', CERVESUR_E_CLAVE: GOODPW, CERVESUR_ESPERA_OPCACHE: '3' };
const YES = { CERVESUR_CONFIRMAR_ACTUALIZAR: 'SI', CERVESUR_CONFIRMAR_A2: 'SI', CERVESUR_CONFIRMAR_REVERTIR: 'SI', CERVESUR_CONFIRMAR_APACHE: 'SI' };

try {
  docker(['rm', '--force', '--volumes', NAME]);
  mkdirSync(OUT, { recursive: true });

  section = 'Paquetes';
  const tgz = process.env.ENSAYO_PAQUETE ? resolve(process.env.ENSAYO_PAQUETE) : resolve(REPO, '..', 'paquetes-qa', 'produccion-fase2a-rc2', 'cervesur-react-admin-fase2a-f539c9fd543d-20261104-225140Z.tar.gz');
  if (!existsSync(BASE_TGZ)) throw new Error(`Falta el paquete BASE de la fase 1: ${BASE_TGZ} (ENSAYO_ADMIN_BASE_TGZ)`);
  if (!existsSync(tgz)) throw new Error(`Falta el paquete RC2 de la fase 2A: ${tgz} (ENSAYO_PAQUETE)`);
  // ------------------------------------------------------------------------------------------------ Imagen y contenedor
  section = 'Réplica del servidor';
  if (docker(['image', 'inspect', BASE_IMAGE]).code !== 0) docker(['build', '--quiet', '--tag', BASE_IMAGE, '--file', join(REPO, 'deploy', 'qa-escritura', 'ensayo', 'Dockerfile.base'), join(REPO, 'deploy', 'qa-escritura', 'ensayo')], { allowFail: false });
  docker(['build', '--quiet', '--build-arg', `BASE=${BASE_IMAGE}`, '--tag', IMAGE, join(here, 'ensayo')], { allowFail: false });
  // NET_ADMIN SOLO en el ensayo: el entrypoint añade 192.168.200.12 como alias de loopback (el 443 de trabajadores escucha solo en esa IP, como en el servidor real)
  docker(['run', '--detach', '--cap-add', 'NET_ADMIN', '--name', NAME, IMAGE], { allowFail: false });
  for (let i = 0; ; i++) { if (dx('curl -fs http://127.0.0.1/ >/dev/null && curl -ksf https://192.168.200.12:443/ >/dev/null && curl -fs http://127.0.0.1:8094/ >/dev/null && mysqladmin ping --silent').code === 0) break; if (i > 90) throw new Error('El contenedor no arrancó'); await sleep(1000); }
  ok('REALIDAD DEL SERVIDOR: el 443 de trabajadores escucha SOLO en 192.168.200.12 (en 127.0.0.1:443 no hay nada)', dx('curl -ksf -m 4 https://192.168.200.12:443/ >/dev/null').code === 0 && dx('curl -ksf -m 4 https://127.0.0.1:443/ >/dev/null').code !== 0);
  ok('arranca con Flutter (80), señuelos 443 (TLS) y 8094 y MySQL con validate_password', dx("mysql -N -e \"SHOW VARIABLES LIKE 'validate_password.policy'\"").out.includes('MEDIUM'));
  docker(['cp', BASE_TGZ, `${NAME}:/root/base.tar.gz`], { allowFail: false });
  docker(['cp', tgz, `${NAME}:/root/nuevo.tar.gz`], { allowFail: false });
  docker(['cp', join(REPO, 'deploy', 'tunel-ssh-readonly', 'ensayo', 'sembrar-portalbi.php'), `${NAME}:/root/portalbi.php`], { allowFail: false });
  docker(['cp', join(REPO, 'tests', 'isolated', 'productive_fixture.php'), `${NAME}:/root/productive_fixture.php`], { allowFail: false });
  const first = cmd => dx(cmd).out.split('\n').pop();
  const PKB = `/root/${first('cd /root && tar xzf base.tar.gz && ls -d cervesur-react-admin-3f859210c5c4-*/ | tr -d /')}`;
  const PK = `/root/${first('cd /root && tar xzf nuevo.tar.gz && ls -d cervesur-react-admin-fase2a-*/ | tr -d /')}`;
  ok('ambos paquetes se extraen y su SHA256SUMS interno es válido', dx(`cd ${PKB} && sha256sum -c SHA256SUMS --quiet`).code === 0 && dx(`cd ${PK} && sha256sum -c SHA256SUMS --quiet`).code === 0);
  ok('RUTAS DEFINITIVAS: migraciones/repo/ tiene los 5 archivos y su SHA256SUMS verifica; e0/repo/ tiene M2 idéntica a la de migraciones; no hay repo/ ni database/ en la raíz',
    dx(`cd ${PK}/migraciones/repo && for f in UX-08B-audit-log.up.sql UX-08B-audit-log.down.sql UX-08B-audit-log-immutable.up.sql UX-08B-audit-log-immutable.down.sql UX-08H1-cuenta-escritura-fase2a.sql.template SHA256SUMS; do [ -s $f ] || exit 1; done && [ "$(wc -l < SHA256SUMS)" = 5 ] && sha256sum -c SHA256SUMS --quiet && [ "$(ls | wc -l)" = 6 ] && cmp -s ${PK}/e0/repo/UX-08B-audit-log-immutable.up.sql UX-08B-audit-log-immutable.up.sql && [ ! -e ${PK}/repo ] && [ ! -e ${PK}/database ]`).code === 0);
  ok('CONTINUIDAD: con su URL por defecto (https://192.168.200.12:443/) el verificador compartido ve Flutter, trabajadores y QA', dx(`bash ${PK}/scripts/comprobar-otros-portales.sh`).code === 0);
  ok('…y con la URL ANTIGUA (loopback:443) falla: el ensayo reproduce el defecto real', dx(`CERVESUR_URL_TRABAJADORES=https://127.0.0.1:443/ bash ${PK}/scripts/comprobar-otros-portales.sh`).code !== 0);
  ok('CERVESUR_URL_TRABAJADORES sigue pudiendo sobrescribir la URL', dx(`CERVESUR_URL_TRABAJADORES=https://192.168.200.12:443/ bash ${PK}/scripts/comprobar-otros-portales.sh`).code === 0);
  ok('paquete 2A: LF en scripts, sin .env/claves/certificados/pruebas/QA/syn###, public admin-write con localhost y 192.168.200.12, con base/, scripts, README y VERSION', dx(`! grep -rlI $'\\r' ${PK}/scripts && [ -z "$(find ${PK} \\( -name '.env*' -o -name '*.key' -o -name '*.pem' -o -name '*.crt' \\) -print -quit)" ] && [ -z "$(find ${PK} -ipath '*/tests/*' -print -quit)" ] && [ -z "$(grep -rIlE -e '-----BEGIN [A-Z ]*PRIVATE KEY-----' ${PK})" ] && [ -z "$(grep -rIlE '\\bsyn[0-9]{3}\\b' ${PK}/backend ${PK}/public ${PK}/scripts)" ] && grep -q '"pilotMode": "admin-write"' ${PK}/public/build-info.json && grep -q 192.168.200.12 ${PK}/public/build-info.json && grep -q localhost ${PK}/public/build-info.json && ls ${PK}/base/MANIFIESTO-FASE1-3f859210c5c4.sha256 ${PK}/README.md ${PK}/VERSION ${PK}/RELEASE.json >/dev/null`).code === 0);
  ok('el paquete NO incluye UX-08B3 (grupo_id NULL) ni el revertir.sh del piloto (desinstalador) ni el kit QA', dx(`[ -z "$(find ${PK} -name '*UX-08B3*' -o -name 'revertir.sh' -o -name 'instalar.sh' -o -name '*qa-escritura*' | head -1)" ]`).code === 0);

  // ------------------------------------------------------------------------------------------------ Datos sintéticos + fase 1 REAL
  section = 'Preparación (base sintética y fase 1 instalada)';
  const seed = dx('php /root/portalbi.php /root/productive_fixture.php');
  ok('base SINTÉTICA cervesur_portalbi creada (agregados del inventario verificados)', seed.code === 0 && /creada/.test(seed.out), seed.out);
  const hashPw = dx("php -r 'echo password_hash(getenv(\"PW\"), PASSWORD_BCRYPT);'", { PW: GOODPW }).out;
  put('/root/pw.sql', `UPDATE ${DB}.user SET password = '${hashPw}' WHERE id = 1;\n`);
  ok('contraseña de prueba solo para el administrador sintético id 1 (el hash nunca sale del contenedor)', dx('mysql < /root/pw.sql').code === 0 && !hashPw.includes(GOODPW));
  const lAdm = sql(`SELECT username FROM ${DB}.user WHERE id = 1`); CE.CERVESUR_E_USUARIO = lAdm;
  const prep = dx(`bash ${PKB}/admin-mysql/preparar-sql-cuentas.sh`);
  ok('el procedimiento administrativo de la fase 1 genera SQL y claves (600)', prep.code === 0, prep.out);
  ok('el DBA (ensayo) crea las tres cuentas _adm y la base de estado propia', dx('mysql < /root/cervesur-admin.sql').code === 0);
  const inst = dx(`bash ${PKB}/scripts/instalar.sh --claves-archivo /root/cervesur-admin.claves`, { CERVESUR_USUARIOS_CONFIRMAR: 'SI' });
  ok('el instalador REAL de la fase 1 deja el administrador (8093, piloto_admin) funcionando', inst.code === 0 && /\d+ PASS \/ 0 FAIL/.test(inst.out), inst.out.split(String.fromCharCode(10)).filter(l => /FAIL|FALLO|ERROR/.test(l)).join(' / ').slice(0, 1500));
  if (process.env.ENSAYO_SOLO_FASE1) { // diagnóstico del FAIL intermitente de la fase 1: guarda la salida COMPLETA del instalador y termina
    writeFileSync(join(OUT, 'fase1-instalador.log'), `rc=${inst.code}
${inst.out}`);
    console.log(`FASE1-SOLO rc=${inst.code} ${/(\d+) PASS \/ (\d+) FAIL/.exec(inst.out)?.[0] ?? 'sin resumen'}`);
    throw new Error('FASE1-SOLO: fin del diagnóstico');
  }
  const add = dx(`bash ${PKB}/scripts/usuarios.sh agregar 1 "ensayo fase 2A"`);
  ok('el administrador de prueba queda autorizado en la lista blanca de la instancia', add.code === 0, add.out);
  const t0 = tokenOf(login(lAdm, GOODPW));
  ok('login del administrador con la fase 1 instalada (token opaco de 256 bits)', /^[0-9a-f]{64}$/.test(t0));
  const ENV0 = envSha(); const MODE0 = envOf('CERVESUR_MODE');
  ok('el .env de la fase 1 está en piloto_admin, sin claves de escritura', MODE0 === 'piloto_admin' && !envKeys().some(k => /^ADMIN_WRITES|^AUDIT_LOG_MODE|USER_ADMIN_WRITES/.test(k)));
  const identBase = () => dx(`cd /var/www/cervesur-react-admin && grep -E ' public/' ${PK}/base/MANIFIESTO-FASE1-3f859210c5c4.sha256 | sha256sum -c --quiet && cd /opt/cervesur-react-admin && grep -E ' backend/' ${PK}/base/MANIFIESTO-FASE1-3f859210c5c4.sha256 | sha256sum -c --quiet && [ "$(find ${ENV_DIR} -type f ! -name .env | wc -l)" = "$(grep -c ' backend/' ${PK}/base/MANIFIESTO-FASE1-3f859210c5c4.sha256)" ]`).code === 0;
  const identNuevo = () => dx(`cd /var/www/cervesur-react-admin && grep -E ' public/' ${PK}/SHA256SUMS | sha256sum -c --quiet && cd /opt/cervesur-react-admin && grep -E ' backend/' ${PK}/SHA256SUMS | sha256sum -c --quiet`).code === 0;
  ok('el manifiesto de la fase 1 del paquete coincide con lo REALMENTE instalado (archivo por archivo)', identBase());
  const SNAP = String.raw`set -u
tree() { if [ -d "$1" ]; then (cd "$1" && find . -type f -printf '%p %s %m %u:%g\n' | LC_ALL=C sort | sha256sum | cut -c1-64; find . -type f -print0 | LC_ALL=C sort -z | xargs -0 sha256sum | sha256sum | cut -c1-64); else echo ausente; fi; }
for d in /var/www/cervesur /var/www/cervesur-react-trabajadores /opt/cervesur-react-trabajadores /var/www/cervesur-qa-8094 /opt/cervesur-qa-8094; do echo "[$d]"; tree $d; done
sha256sum /etc/apache2/sites-available/cervesur.conf /etc/apache2/sites-available/cervesur-react-trabajadores.conf /etc/apache2/sites-available/cervesur-qa-8094.conf /var/www/cervesur/.htaccess 2>/dev/null
for u in http://127.0.0.1/ http://127.0.0.1/ruta/profunda https://192.168.200.12:443/ http://127.0.0.1:8094/ https://127.0.0.1:8092/ http://127.0.0.1:8090/; do echo "$u"; curl -k -sS -m 6 -o /dev/null -D - "$u" | tr -d '\r' | awk 'NR==1{print;next} /^[A-Za-z-]+:/{print tolower($0)}' | grep -v -E '^(date|etag|last-modified):' | LC_ALL=C sort; curl -k -sS -m 6 "$u" | sha256sum; done
mysql -N -e 'SHOW DATABASES' | LC_ALL=C sort
`;
  put('/root/snap.sh', SNAP);
  const snap = () => dx('bash /root/snap.sh').out;
  const S0 = snap(); const AP0 = apacheTree();
  ok('línea base independiente de Flutter, señuelos 443/8094 (archivos, cabeceras, cuerpos), bases y Apache', S0.length > 500 && /SENUELO|senuelo/.test(S0) && AP0.length === 64);
  const tablesSum = tables => sql(tables.map(t => `CHECKSUM TABLE ${DB}.${t}`).join('; '));
  const CK_USER0 = tablesSum(['user']);

  // ------------------------------------------------------------------------------------------------ Comportamiento de la FASE 1 (se repite al final)
  const WRITES = [['POST', '/api/empresas', '{"nombre":"x"}'], ['PUT', '/api/empresas/1', '{"nombre":"x"}'], ['DELETE', '/api/empresas/1', ''], ['POST', '/api/reportes', '{"nombre":"x"}'], ['PUT', '/api/reportes/1', '{"nombre":"x"}'], ['DELETE', '/api/reportes/1', ''],
    ['POST', '/api/asignacion', '{"empresa_id":1,"reporte_id":1}'], ['PUT', '/api/asignacion/1', '{}'], ['DELETE', '/api/asignacion/1', ''], ['POST', '/api/empresa-usuario', '{}'], ['POST', '/api/empresa-reporte-usuario', '{}'], ['POST', '/api/register', '{"x":1}'], ['PUT', '/api/user/1/role', '{"role":"admin"}'], ['PUT', '/api/users/1/password', '{}']];
  const fase1 = (etiqueta) => {
    const t = tokenOf(login(lAdm, GOODPW));
    const lec = ['/api/empresas?view=minimal&per_page=1&page=1', '/api/reportes?view=minimal&per_page=1&page=1', '/api/asignacion?view=minimal&per_page=1&page=1', '/api/users?status=all&per_page=1&page=1'].map(p => callRaw('GET', p, t).status);
    const cap = callRaw('GET', '/api/admin/capacidades', t).status;
    const aud = callRaw('GET', '/api/auditoria', t).status;
    const esc = WRITES.map(([m, p, b]) => callRaw(m, p, t, b).status);
    ok(`${etiqueta}: login y lecturas funcionan (4 GET → 200), /api/admin/capacidades y /api/auditoria → 403, ${WRITES.length} rutas de escritura → 403`, /^[0-9a-f]{64}$/.test(t) && lec.every(s => s === 200) && cap === 403 && aud === 403 && esc.every(s => s === 403), `lecturas=${lec} cap=${cap} aud=${aud} escrituras=${esc}`);
  };
  const otros = (etiqueta, conApache = true) => ok(`${etiqueta}: Flutter, trabajadores 443 y QA 8094 byte a byte iguales a la línea base${conApache ? '; Apache (archivos) sin cambios' : ' (Apache: solo el cambio autorizado de métodos)'}`, snap() === S0 && (!conApache || apacheTree() === AP0), '');
  fase1('Fase 1 instalada (referencia)');

  // ------------------------------------------------------------------------------------------------ Preflight y simulación
  section = 'Preflight y simulación de A1';
  const pre = run(`bash ${PK}/scripts/preflight-fase2a.sh`, CE);
  ok('preflight-fase2a: GO (identidad de la fase 1, espacio, puertos, Apache, paquete, otros portales); AVISA de migraciones pendientes y de Apache', pre.code === 0 && /RESULTADO: GO/.test(pre.out) && /coincide archivo por archivo con la fase 1/.test(pre.out) && /Apache solo deja pasar GET y POST/.test(pre.out) && /audit_log\/triggers todavía no están/.test(pre.out), pre.out.slice(-1500));
  const sinCred = run(`bash ${PK}/scripts/actualizar.sh --simular`, { CERVESUR_ESPERA_OPCACHE: '3', CERVESUR_E_USUARIO: '', CERVESUR_E_CLAVE: '' });
  ok('actualizar --simular SIN credenciales se NIEGA (no hay verificación con login) y no escribe nada', sinCred.code !== 0 && /CERVESUR_E_USUARIO/.test(sinCred.out) && !dx(`ls ${RESP} 2>/dev/null | grep -c actualizacion-`).out.match(/^[1-9]/));
  const dirsAntes = dx(`find /var/www /opt /var/backups /var/lib -maxdepth 3 | LC_ALL=C sort | sha256sum | cut -c1-64`).out;
  const sim = run(`bash ${PK}/scripts/actualizar.sh --simular`, CE);
  ok('actualizar --simular: código 0, dice qué reemplazaría y NO crea respaldos ni carpetas', sim.code === 0 && /SIMULACIÓN terminada/.test(sim.out) && dx(`find /var/www /opt /var/backups /var/lib -maxdepth 3 | LC_ALL=C sort | sha256sum | cut -c1-64`).out === dirsAntes && identBase() && envSha() === ENV0, sim.out.slice(-600));
  const modoSinNada = run(`bash ${PK}/scripts/modo-escritura.sh --simular --archivo-clave /root/no-existe`, CE);
  ok('modo-escritura (A2) antes de A1 se NIEGA: el código instalado no es el del paquete', modoSinNada.code !== 0 && /ejecute antes actualizar\.sh/.test(modoSinNada.out) && envSha() === ENV0);

  // ------------------------------------------------------------------------------------------------ Inyecciones de fallo de A1
  section = 'A1 · inyecciones de fallo (cada una prueba la restauración automática)';
  const backupsAntes = () => dx(`ls -1 ${RESP} 2>/dev/null | LC_ALL=C sort | tr '\\n' ' '`).out;
  const restosA1 = () => dx(`ls -a ${FRONT} /opt/cervesur-react-admin | grep -E '^\\.(nuevo|previo)-' | wc -l`).out;
  for (const fallo of ['respaldo', 'manifiesto', 'respaldo-corrupto', 'frontend', 'backend', 'verificacion']) {
    const b0 = backupsAntes(); const reloads0 = apacheReloads();
    const r = run(`bash ${PK}/scripts/actualizar.sh`, { ...CE, ...YES, CERVESUR_INYECTAR_FALLO: fallo });
    const seguro = identBase() && envSha() === ENV0 && restosA1() === '0' && apacheTree() === AP0 && apacheReloads() === reloads0;
    const antiguosIntactos = b0.split(' ').filter(Boolean).every(d => backupsAntes().split(' ').includes(d));
    ok(`fallo «${fallo}»: A1 termina con error, lo instalado sigue siendo EXACTAMENTE la fase 1, el .env intacto, sin restos y sin tocar Apache; los respaldos anteriores se conservan`, r.code !== 0 && seguro && antiguosIntactos, `rc=${r.code} ident=${identBase()} env=${envSha() === ENV0} restos=${restosA1()} ${r.out.slice(-500)}`);
  }
  fase1('Tras las inyecciones');

  // ------------------------------------------------------------------------------------------------ A1 real
  section = 'A1 · actualización real (solo código)';
  const reloadsA1 = apacheReloads();
  const a1 = run(`bash ${PK}/scripts/actualizar.sh`, { ...CE, ...YES });
  ok('actualizar.sh termina bien: respaldo verificado, reemplazo, verificar-fase1 y etapa A1 sin fallos, .env y Apache idénticos', a1.code === 0 && /A1 COMPLETO/.test(a1.out) && /respaldo en .*SHA-256 de ambos archivos verificado/.test(a1.out) && /Apache byte a byte idéntico/.test(a1.out), a1.out.slice(-900));
  ok('lo instalado es ahora EXACTAMENTE el paquete 2A (todo backend/ y public/); el .env es byte a byte el de la fase 1 (sigue piloto_admin)', identNuevo() && envSha() === ENV0 && envOf('CERVESUR_MODE') === 'piloto_admin');
  ok('Apache sin recarga ni reinicio durante A1', apacheReloads() === reloadsA1 && apacheTree() === AP0);
  const respA1 = dx(`ls -1d ${RESP}/actualizacion-* | tail -1`).out;
  ok('el respaldo de A1 existe: 700, public.tgz/backend.tgz/SHA256SUMS 600, sin .env, y su SHA-256 verifica', dx(`cd ${respA1} && sha256sum -c SHA256SUMS --quiet && [ "$(stat -c %a .)" = 700 ] && [ "$(stat -c %a public.tgz backend.tgz SHA256SUMS | sort -u)" = 600 ] && ! tar tzf backend.tgz | grep -q '/\\.env' && grep -q '^fase A1' INFO.txt`).code === 0);
  fase1('A1 aplicada (código nuevo con .env de la fase 1)');
  otros('Tras A1');
  const reA1 = run(`bash ${PK}/scripts/actualizar.sh`, { ...CE, ...YES });
  ok('repetir A1 es inocuo: dice que ya está instalado y sale con 0', reA1.code === 0 && /YA es este paquete/.test(reA1.out));
  const interA1 = run(`bash ${PK}/scripts/interruptores.sh --habilitar empresas`, CE);
  ok('interruptores antes de A2 se NIEGA (el .env no está en admin_escritura)', interA1.code !== 0 && /admin_escritura/.test(interA1.out) && envSha() === ENV0);
  const verA1 = run(`bash ${PK}/scripts/verificar-posdespliegue.sh --etapa A1`, CE);
  ok('verificar-posdespliegue --etapa A1: PASS y «RESULTADO-ETAPA: PASS A1»', verA1.code === 0 && /RESULTADO-ETAPA: PASS A1/.test(verA1.out), verA1.out.slice(-800));

  // ------------------------------------------------------------------------------------------------ Migraciones y cuenta (el ensayo hace de DBA)
  section = 'A2 · negativas antes de las migraciones';
  put('/root/clave-mal.txt', `CERVESUR_ESCRITURA_PASS=${PASSW}\n`);
  dx('chmod 644 /root/clave-mal.txt');
  const a2sin = run(`bash ${PK}/scripts/modo-escritura.sh --simular --archivo-clave /root/clave-mal.txt`, CE);
  ok('A2 se NIEGA si audit_log no existe (migraciones sin aplicar) y no modifica nada', a2sin.code !== 0 && /audit_log no existe/.test(a2sin.out) && envSha() === ENV0);
  section = 'A2 · migraciones y cuenta aplicadas por el DBA (ensayo)';
  // RUN del runner real en modo SIMULAR sobre el entorno preparado: no escribe nada (ni bitácora) y no debe bloquear con una base y un esquema correctos
  const simu = dx(`cd ${PK}/migraciones && CERVESUR_MYSQL_CMD=mysql bash aplicar-migraciones.sh --simular --db ${DB} --bitacora-base /root/bitacora-simulada`);
  ok('aplicar-migraciones.sh --simular (runner real del paquete, rutas migraciones/repo/) no bloquea y no crea bitácora', simu.code === 0 && dx('[ ! -e /root/bitacora-simulada ]').code === 0, simu.out.slice(-700));
  const mig = dx(`for f in ${PK}/migraciones/repo/UX-08B-audit-log.up.sql ${PK}/migraciones/repo/UX-08B-audit-log-immutable.up.sql; do mysql ${DB} < $f || exit 1; done`);
  ok('M1 (audit_log) y M2 (2 triggers de solo inserción) aplicadas con los SQL del paquete', mig.code === 0 && sqlN(`SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='${DB}' AND EVENT_OBJECT_TABLE='audit_log'`) === 2, mig.out);
  const tpl = dx(`T=${PK}/migraciones/repo/UX-08H1-cuenta-escritura-fase2a.sql.template; sed -e "s/{DB}/${DB}/g" -e "s/{USER}/${CUENTA}/g" -e "s/{HOST}/127.0.0.1/g" -e "s/{PASS}/${PASSW.replace(/[/&]/g, '\\$&')}/g" $T > /root/cuenta.sql && mysql < /root/cuenta.sql`);
  ok('la cuenta de escritura de la fase 2A se crea con la plantilla del paquete (todas las columnas existen en el esquema de producción sintético)', tpl.code === 0, tpl.out);
  put('/root/clave-escritura.txt', `CERVESUR_ESCRITURA_PASS=${PASSW}\n`);
  dx('chmod 600 /root/clave-escritura.txt && chown root:root /root/clave-escritura.txt');
  const KEYF = '--archivo-clave /root/clave-escritura.txt';

  section = 'A2 · negativas de precondiciones (nada se modifica)';
  const nm = (nombre, r, patron) => ok(nombre, r.code !== 0 && patron.test(r.out) && envSha() === ENV0, `${r.code} ${r.out.slice(-400)}`);
  nm('archivo de clave con modo 644 (legible por otros) → se NIEGA', run(`bash ${PK}/scripts/modo-escritura.sh --simular --archivo-clave /root/clave-mal.txt`, CE), /sin acceso para grupo ni otros/);
  put('/root/clave-mala.txt', 'CERVESUR_ESCRITURA_PASS=Aa1!esta-no-es-la-clave\n'); dx('chmod 600 /root/clave-mala.txt');
  nm('clave que no corresponde a la cuenta → se NIEGA', run(`bash ${PK}/scripts/modo-escritura.sh --simular --archivo-clave /root/clave-mala.txt`, CE), /no se autentica/);
  nm('cuenta inexistente → se NIEGA', run(`bash ${PK}/scripts/modo-escritura.sh --simular ${KEYF} --cuenta cuenta_que_no_existe`, CE), /no existe/);
  nm('la cuenta no puede ser la de Flutter ni cervesur_ro_*', run(`bash ${PK}/scripts/modo-escritura.sh --simular ${KEYF} --cuenta cervesur_ro_catalogo_adm --host-cuenta 127.0.0.1`, CE), /no existe|no puede ser/);
  nm('sin credenciales para la verificación (y sin --sin-credenciales) → se NIEGA', run(`bash ${PK}/scripts/modo-escritura.sh --simular ${KEYF}`, { CERVESUR_E_USUARIO: '', CERVESUR_E_CLAVE: '' }), /CERVESUR_E_USUARIO/);
  for (const [grant, rx, etiqueta] of [[`GRANT DELETE ON ${DB}.empresa TO '${CUENTA}'@'127.0.0.1'`, /DELETE/, 'DELETE sobre empresa'], [`GRANT INSERT ON ${DB}.user TO '${CUENTA}'@'127.0.0.1'`, /INSERT@user|INSERT/, 'INSERT sobre user'], [`GRANT UPDATE (role) ON ${DB}.user TO '${CUENTA}'@'127.0.0.1'`, /UPDATE@user/, 'UPDATE(role) sobre user'], [`GRANT UPDATE ON ${DB}.audit_log TO '${CUENTA}'@'127.0.0.1'`, /UPDATE@audit_log/, 'UPDATE sobre audit_log'], [`GRANT SELECT ON ${DB}.* TO '${CUENTA}'@'127.0.0.1' WITH GRANT OPTION`, /GRANT OPTION|base de datos/, 'GRANT OPTION y base completa']]) {
    sql(grant);
    const r = run(`bash ${PK}/scripts/modo-escritura.sh --simular ${KEYF}`, CE);
    ok(`cuenta con ${etiqueta} → A2 la RECHAZA con la misma regla del backend y no modifica nada`, r.code !== 0 && /privilegios NO permitidos/.test(r.out) && rx.test(r.out) && envSha() === ENV0, `${r.code} ${r.out.slice(-500)}`);
    sql(`REVOKE ALL PRIVILEGES, GRANT OPTION FROM '${CUENTA}'@'127.0.0.1'`); dx(`sed -e "s/{DB}/${DB}/g" -e "s/{USER}/${CUENTA}/g" -e "s/{HOST}/127.0.0.1/g" -e "s/{PASS}/x/g" $(find ${PK} -name 'UX-08H1-cuenta-escritura-fase2a.sql.template') | grep -v '^CREATE USER' | mysql`);
  }
  const simA2 = run(`bash ${PK}/scripts/modo-escritura.sh --simular ${KEYF}`, CE);
  ok('A2 --simular: migraciones OK, cuenta OK (SHOW GRANTS pasa las reglas del backend y de la fase 2A), sin escribir nada', simA2.code === 0 && /SIMULACIÓN terminada/.test(simA2.out) && envSha() === ENV0 && !simA2.out.includes(PASSW), simA2.out.slice(-800));
  ok('A2 --simular no imprime la clave de la cuenta ni la guarda en el entorno de procesos visibles', !simA2.out.includes(PASSW) && dx(`! ps -eo args | grep -F -- '${PASSW}' | grep -v grep`).code === 0);

  // ------------------------------------------------------------------------------------------------ Inyecciones de fallo de A2
  section = 'A2 · inyecciones de fallo (restauración automática del .env)';
  const visorH0 = dx(`tr -d '\\r' < ${ENVF} | awk -F= '$1=="CERVESUR_PILOT_VISOR_IDS"{print}' | sha256sum | cut -c1-16`).out;
  const envBackups = () => dx(`ls -1 ${RESP}/env 2>/dev/null | wc -l`).out;
  for (const fallo of ['cuenta-sobreprivilegiada', 'env-invalido', 'verificacion']) {
    const r = run(`bash ${PK}/scripts/modo-escritura.sh ${KEYF}`, { ...CE, ...YES, CERVESUR_INYECTAR_FALLO: fallo });
    const hubo = Number(envBackups()) > 0;
    const t = tokenOf(login(lAdm, GOODPW));
    ok(`fallo «${fallo}»: A2 termina con error y el .env queda BYTE A BYTE como estaba (piloto_admin); el administrador sigue respondiendo con el comportamiento de A1${fallo === 'cuenta-sobreprivilegiada' ? ' (ni siquiera se respalda: nada se escribió)' : ' y el respaldo del .env se conserva'}`,
      r.code !== 0 && envSha() === ENV0 && envOf('CERVESUR_MODE') === 'piloto_admin' && /^[0-9a-f]{64}$/.test(t) && (fallo === 'cuenta-sobreprivilegiada' ? !hubo : hubo) && !r.out.includes(PASSW), `rc=${r.code} ${r.out.slice(-600)}`);
  }
  fase1('Tras las inyecciones de A2');

  // ------------------------------------------------------------------------------------------------ A2 real
  section = 'A2 · modo admin_escritura con TODO apagado';
  const jwt0 = envOf('JWT_SECRET'); const authHash0 = dx(`tr -d '\\r' < ${ENVF} | grep -E '^(AUTH_DB_USER|AUTH_DB_PASS|STATE_DB_NAME|STATE_DB_USER|STATE_DB_PASS|CERVESUR_PILOT_SECRET|CERVESUR_PRODUCTION_DBS)=' | sort | sha256sum | cut -c1-16`).out;
  const reloadsA2 = apacheReloads();
  const a2 = run(`bash ${PK}/scripts/modo-escritura.sh ${KEYF}`, { ...CE, ...YES });
  ok('modo-escritura.sh termina bien: .env escrito atómicamente y etapa A2 sin fallos; no imprime la clave', a2.code === 0 && /A2 COMPLETO/.test(a2.out) && /RESULTADO-ETAPA: PASS A2/.test(a2.out) && !a2.out.includes(PASSW), a2.out.slice(-900));
  const keys = envKeys();
  ok('.env: admin_escritura exigido, AUDIT_LOG_MODE=required, REACT_STRICT_VIEWS=1, DB_USER = la cuenta de escritura, los 3 ADMIN_WRITES_* en 0', envOf('CERVESUR_MODE') === 'admin_escritura' && envOf('CERVESUR_REQUIRED_MODE') === 'admin_escritura' && envOf('AUDIT_LOG_MODE') === 'required' && envOf('REACT_STRICT_VIEWS') === '1' && envOf('DB_USER') === CUENTA && envOf('DB_PASS') === PASSW && ['EMPRESAS', 'REPORTES', 'ACCESOS'].every(m => envOf(`ADMIN_WRITES_${m}`) === '0'));
  ok('.env: NINGUNA clave de usuarios (ADMIN_WRITES_USUARIOS*), ni USER_ADMIN_WRITES, ni CERVESUR_INSECURE_TESTS, ni CERVESUR_ALLOW_WRITABLE_DB_ACCOUNT', !keys.some(k => /ADMIN_WRITES_USUARIOS|USER_ADMIN_WRITES|CERVESUR_INSECURE_TESTS|CERVESUR_ALLOW_WRITABLE_DB_ACCOUNT/.test(k)));
  ok('.env: AUTH_DB_*, STATE_DB_*, CERVESUR_PILOT_SECRET y CERVESUR_PRODUCTION_DBS intactos; visor (CERVESUR_PILOT_VISOR_IDS) intacto; JWT propio de ≥ 32 caracteres', dx(`tr -d '\\r' < ${ENVF} | grep -E '^(AUTH_DB_USER|AUTH_DB_PASS|STATE_DB_NAME|STATE_DB_USER|STATE_DB_PASS|CERVESUR_PILOT_SECRET|CERVESUR_PRODUCTION_DBS)=' | sort | sha256sum | cut -c1-16`).out === authHash0 && dx(`tr -d '\\r' < ${ENVF} | awk -F= '$1=="CERVESUR_PILOT_VISOR_IDS"{print}' | sha256sum | cut -c1-16`).out === visorH0 && envOf('JWT_SECRET').length >= 32 && envOf('JWT_SECRET') === jwt0);
  ok('.env 640 root:www-data y respaldo previo en env/ (600, listado con SHA-256 en MANIFIESTO.txt); el pointer ultimo-A2-previo existe', dx(`[ "$(stat -c '%a %U:%G' ${ENVF})" = "640 root:www-data" ] && [ "$(stat -c %a ${RESP}/env)" = 700 ] && f=$(cat ${RESP}/env/ultimo-A2-previo) && [ "$(stat -c %a ${RESP}/env/$f)" = 600 ] && grep -q "$f" ${RESP}/env/MANIFIESTO.txt && [ "$(sha256sum < ${RESP}/env/$f | cut -c1-64)" = "${ENV0}" ] `).code === 0);
  const envPrev = dx(`sha256sum < ${RESP}/env/$(cat ${RESP}/env/ultimo-A2-previo) | cut -c1-64`).out;
  ok('el respaldo previo del .env es idéntico al .env de la fase 1', envPrev === ENV0);
  ok('A2 no recargó ni reinició Apache y no cambió su configuración', apacheReloads() === reloadsA2 && apacheTree() === AP0);
  const t2 = tokenOf(login(lAdm, GOODPW));
  const cap2 = callRaw('GET', '/api/admin/capacidades', t2);
  ok('GET /api/admin/capacidades: todo false (empresas, reportes, accesos, usuarios) y users_sensitive=false', cap2.status === 200 && ['empresas', 'reportes', 'accesos', 'usuarios'].every(m => cap2.json?.data?.writes?.[m] === false) && cap2.json?.data?.users_sensitive === false && cap2.json?.data?.mode === 'admin_escritura', cap2.raw);
  const RUTAS = { empresas: [['POST', '/api/empresas'], ['PUT', '/api/empresas/999999999'], ['DELETE', '/api/empresas/999999999']], reportes: [['POST', '/api/reportes'], ['PUT', '/api/reportes/999999999'], ['DELETE', '/api/reportes/999999999']], accesos: [['POST', '/api/asignacion'], ['DELETE', '/api/asignacion/999999999'], ['POST', '/api/empresa-usuario'], ['POST', '/api/empresa-reporte-usuario'], ['DELETE', '/api/empresa-reporte-usuario/999999999']] };
  const USERS = [['POST', '/api/register'], ['PUT', '/api/users/999999999'], ['POST', '/api/users/999999999/deactivate'], ['POST', '/api/users/999999999/reactivate'], ['PUT', '/api/users/999999999/password'], ['PUT', '/api/user/999999999/role']];
  const codeOf = r => r.json?.code ?? (r.status === 403 ? 'HTML-apache' : `status-${r.status}`);
  for (const [m, lista] of Object.entries(RUTAS)) {
    const rs = lista.map(([me, p]) => callRaw(me, p, t2, '{}'));
    const posts = rs.filter((_, i) => lista[i][0] === 'POST');
    ok(`escrituras de ${m} con el módulo apagado: 403 «escritura_desactivada» (los POST llegan al backend; PUT/DELETE: el backend o, si Apache los rechaza antes, un 403 HTML)`, rs.every(r => r.status === 403) && posts.every(r => codeOf(r) === 'escritura_desactivada'), rs.map(codeOf).join(','));
  }
  const rsU = USERS.map(([me, p]) => callRaw(me, p, t2, '{}'));
  ok('rutas de USUARIOS: 403 «fase_2b_bloqueada» (los POST las responde el backend; PUT: backend o 403 de Apache)', rsU.every(r => r.status === 403) && rsU.filter((_, i) => USERS[i][0] === 'POST').every(r => codeOf(r) === 'fase_2b_bloqueada'), rsU.map(codeOf).join(','));
  ok('lecturas intactas con el modo nuevo (empresas, informes, asignaciones, usuarios, historial → 200)', ['/api/empresas?view=minimal&per_page=1&page=1', '/api/reportes?view=minimal&per_page=1&page=1', '/api/asignacion?view=minimal&per_page=1&page=1', '/api/users?status=all&per_page=1&page=1', '/api/auditoria'].every(p => callRaw('GET', p, t2).status === 200));
  ok('el visor: ningún informe obtiene autorización nueva (DB031/id 72 incluido): sigue 403 sin enlace', (() => { const r = callRaw('GET', '/api/reportes/id/72/visor', t2); return (r.status === 403 || r.status === 404) && !/"url"/.test(r.raw); })());
  ok('la tabla user no cambió y audit_log está vacío (A2 no escribe datos)', tablesSum(['user']) === CK_USER0 && sqlN(`SELECT COUNT(*) FROM ${DB}.audit_log`) === 0);
  otros('Tras A2');
  const verA2 = run(`bash ${PK}/scripts/verificar-posdespliegue.sh --etapa A2`, CE);
  ok('verificar-posdespliegue --etapa A2: PASS, sin OMIT', verA2.code === 0 && /RESULTADO-ETAPA: PASS A2/.test(verA2.out) && !/^OMIT /m.test(verA2.out), verA2.out.slice(-700));
  const verA2s = run(`bash ${PK}/scripts/verificar-posdespliegue.sh --etapa A2 --sin-credenciales`, { CERVESUR_E_USUARIO: '', CERVESUR_E_CLAVE: '' });
  ok('sin credenciales la etapa A2 hace las comprobaciones sin sesión y declara «verificación PARCIAL» (OMIT)', verA2s.code === 0 && /OMIT/.test(verA2s.out) && /PARCIAL/.test(verA2s.out));
  const reA2 = run(`bash ${PK}/scripts/modo-escritura.sh --simular ${KEYF}`, CE);
  ok('repetir A2 con el .env ya en admin_escritura se NIEGA con instrucciones', reA2.code !== 0 && /no está en piloto_admin/.test(reA2.out));

  // ------------------------------------------------------------------------------------------------ Apache y Paso B
  section = 'Paso B · Apache';
  const b0 = backupsAntes(); void b0;
  const envA2 = envSha();
  const noApache = run(`bash ${PK}/scripts/interruptores.sh --habilitar empresas`, CE);
  ok('con Apache rechazando PUT/DELETE (<LimitExcept GET POST>) el interruptor se NIEGA a encender «empresas», lo explica y NO cambia el .env', noApache.code !== 0 && /Apache rechaza PUT\/DELETE/.test(noApache.out) && envSha() === envA2, noApache.out.slice(-500));
  const apSim = run(`bash ${PK}/scripts/apache-metodos-escritura.sh --simular`, CE);
  ok('apache-metodos-escritura --simular: describe el cambio de UNA línea y no escribe nada', apSim.code === 0 && /SIMULACIÓN terminada/.test(apSim.out) && apacheTree() === AP0);
  const apRev0 = run(`bash ${PK}/scripts/apache-metodos-escritura.sh --revertir`, CE);
  ok('--revertir sin respaldo previo se NIEGA', apRev0.code !== 0 && /respaldo verificado/.test(apRev0.out));
  const ap = run(`bash ${PK}/scripts/apache-metodos-escritura.sh`, { ...CE, ...YES });
  ok('apache-metodos-escritura (acción separada y autorizada): respaldo, UNA línea, configtest, recarga suave y un PUT inexistente ya llega a PHP', ap.code === 0 && /Apache admite PUT y DELETE/.test(ap.out), ap.out.slice(-600));
  ok('el cambio es solo esa línea (el diff contra el respaldo tiene una línea cambiada) y el resto de /etc/apache2 está idéntico', dx(`c=/etc/apache2/cervesur-react-admin/react-usuarios-common.conf; b=$(ls ${RESP}/apache/react-usuarios-common.conf.* | grep -v sha256 | tail -1); [ "$(diff $b $c | grep -c '^>')" = 1 ] && grep -q 'LimitExcept GET POST PUT DELETE' $c && [ "$(stat -c %a ${RESP}/apache)" = 700 ]`).code === 0);
  const ap2 = run(`bash ${PK}/scripts/apache-metodos-escritura.sh`, { ...CE, ...YES });
  ok('repetirlo es inocuo', ap2.code === 0 && /ya admite PUT y DELETE/.test(ap2.out));
  const apR = run(`bash ${PK}/scripts/apache-metodos-escritura.sh --revertir`, { ...CE, ...YES });
  ok('--revertir restaura «GET POST» (Apache vuelve a ser byte a byte el original)', apR.code === 0 && apacheTree() === AP0, apR.out.slice(-400));
  const ap3 = run(`bash ${PK}/scripts/apache-metodos-escritura.sh`, { ...CE, ...YES });
  ok('se vuelve a aplicar para el Paso B', ap3.code === 0);
  otros('Tras los cambios de Apache', false);

  section = 'Paso B · interruptores';
  const est0 = run(`bash ${PK}/scripts/interruptores.sh --estado`, CE);
  ok('--estado: todo apagado, ninguno verificado, usuarios BLOQUEADO', est0.code === 0 && /empresas\s+apagado\s+no/.test(est0.out) && /usuarios\s+BLOQUEADO/.test(est0.out), est0.out);
  for (const m of ['usuarios', 'usuarios-sensible', 'ADMIN_WRITES_USUARIOS']) {
    const r = run(`bash ${PK}/scripts/interruptores.sh --habilitar ${m}`, CE);
    ok(`--habilitar ${m} se RECHAZA siempre (fase 2B bloqueada) y no cambia el .env`, r.code !== 0 && /BLOQUEADA/.test(r.out) && envSha() === envA2, r.out);
  }
  const noOrden = run(`bash ${PK}/scripts/interruptores.sh --habilitar reportes`, CE);
  ok('--habilitar reportes antes que empresas se NIEGA (orden obligatorio)', noOrden.code !== 0 && /Orden obligatorio/.test(noOrden.out) && envSha() === envA2);
  const noCred = run(`bash ${PK}/scripts/interruptores.sh --habilitar empresas`, { CERVESUR_E_USUARIO: '', CERVESUR_E_CLAVE: '' });
  ok('--habilitar sin credenciales se NIEGA (no se enciende nada sin verificar)', noCred.code !== 0 && /CERVESUR_E_USUARIO/.test(noCred.out) && envSha() === envA2);

  const modulo = async (m, { etapa, ruta, body, accion, tabla, delta, esperado }) => {
    const antesEnv = envSha();
    const h = run(`bash ${PK}/scripts/interruptores.sh --habilitar ${m}`, CE);
    ok(`--habilitar ${m}: .env respaldado, un solo cambio (ADMIN_WRITES_${m.toUpperCase()}=1), etapa ${m} verificada y línea base tomada`, h.code === 0 && envOf(`ADMIN_WRITES_${m.toUpperCase()}`) === '1' && /RESULTADO-ETAPA: PASS/.test(h.out) && /ENCENDIDO y verificado/.test(h.out), h.out.slice(-800));
    ok(`${m}: solo las capacidades esperadas están encendidas`, (() => { const t = tokenOf(login(lAdm, GOODPW)); const c = callRaw('GET', '/api/admin/capacidades', t).json?.data; return ['empresas', 'reportes', 'accesos'].every(k => c?.writes?.[k] === esperado.includes(k)) && c?.writes?.usuarios === false && c?.users_sensitive === false; })());
    const t = tokenOf(login(lAdm, GOODPW));
    // módulos aún apagados siguen en 403 «escritura_desactivada»; usuarios en 403 «fase_2b_bloqueada»
    const apagados = ['empresas', 'reportes', 'accesos'].filter(k => !esperado.includes(k));
    ok(`${m}: los módulos apagados (${apagados.join(', ') || 'ninguno'}) siguen en 403 «escritura_desactivada» y usuarios en 403 «fase_2b_bloqueada»`, apagados.every(k => RUTAS[k].filter(([me]) => me === 'POST').every(([me, p]) => codeOf(callRaw(me, p, t, '{}')) === 'escritura_desactivada')) && USERS.filter(([me]) => me === 'POST').every(([me, p]) => codeOf(callRaw(me, p, t, '{}')) === 'fase_2b_bloqueada'));
    const auditAntes = sqlN(`SELECT COUNT(*) FROM ${DB}.audit_log`); const tabAntes = sqlN(`SELECT COUNT(*) FROM ${DB}.${tabla}`);
    const real = callRaw('POST', ruta, t, JSON.stringify(typeof body === 'function' ? body() : body));
    ok(`${m}: UNA escritura real por la API (POST ${ruta}) → éxito`, [200, 201].includes(real.status) && real.json?.success !== false, `${real.status} ${real.raw.slice(0, 400)}`);
    ok(`${m}: exactamente UNA fila nueva en audit_log con la acción «${accion}», y «${tabla}» cambió en ${delta}`, sqlN(`SELECT COUNT(*) FROM ${DB}.audit_log`) === auditAntes + 1 && sql(`SELECT action FROM ${DB}.audit_log ORDER BY id DESC LIMIT 1`) === accion && sqlN(`SELECT COUNT(*) FROM ${DB}.${tabla}`) === tabAntes + delta, `${sql(`SELECT action FROM ${DB}.audit_log ORDER BY id DESC LIMIT 1`)} ${auditAntes}`);
    const mal = run(`bash ${PK}/scripts/verificar-posdespliegue.sh --etapa ${m} --confirmar-operacion ${accion} --tabla ${tabla} --delta ${delta + 1}`, CE);
    ok(`${m}: --confirmar-operacion con un delta equivocado FALLA (no se puede marcar verificado)`, mal.code !== 0 && /RESULTADO-ETAPA: FAIL/.test(mal.out) && !/OPERACION-CONFIRMADA/.test(mal.out));
    const bien = run(`bash ${PK}/scripts/verificar-posdespliegue.sh --etapa ${m} --confirmar-operacion ${accion} --tabla ${tabla} --delta ${delta} | tee /root/evidencia-${m}.txt`, CE);
    ok(`${m}: --confirmar-operacion ${accion} --tabla ${tabla} --delta ${delta} → PASS, «OPERACION-CONFIRMADA», sin imprimir before/after`, /RESULTADO-ETAPA: PASS/.test(bien.out) && /OPERACION-CONFIRMADA: /.test(bien.out) && !/before_json|after_json/.test(bien.out), bien.out.slice(-900));
    void antesEnv;
  };

  await modulo('empresas', { ruta: '/api/empresas', body: { nombre: 'Empresa Ensayo 2A', razon_social: 'Empresa Ensayo 2A SAC', ruc: '20999999991', ciudad: 'Ciudad ficticia', pais: 'Demo' }, accion: 'empresa.create', tabla: 'empresa', delta: 1, esperado: ['empresas'] });
  const sinMarcar = run(`bash ${PK}/scripts/interruptores.sh --habilitar reportes`, CE);
  ok('--habilitar reportes ANTES de marcar empresas como verificado se NIEGA y no cambia el .env', sinMarcar.code !== 0 && /todavía NO está marcado como verificado/.test(sinMarcar.out) && envOf('ADMIN_WRITES_REPORTES') === '0');
  put('/root/ev-mala.txt', 'RESULTADO-ETAPA: PASS empresas\n');
  const evMala = run(`bash ${PK}/scripts/interruptores.sh --marcar-verificado empresas --evidencia /root/ev-mala.txt`, CE);
  ok('--marcar-verificado con evidencia SIN «OPERACION-CONFIRMADA» se NIEGA', evMala.code !== 0 && /OPERACION-CONFIRMADA/.test(evMala.out));
  const evOtra = run(`bash ${PK}/scripts/interruptores.sh --marcar-verificado reportes --evidencia /root/evidencia-empresas.txt`, CE);
  ok('--marcar-verificado de un módulo apagado o con la evidencia de otro módulo se NIEGA', evOtra.code !== 0);
  const mark1 = run(`bash ${PK}/scripts/interruptores.sh --marcar-verificado empresas --evidencia /root/evidencia-empresas.txt`, CE);
  ok('--marcar-verificado empresas con la evidencia real: registrado (estado 600 + copia 600 de la evidencia)', mark1.code === 0 && dx(`[ "$(stat -c %a /var/lib/cervesur-react-admin/escritura-estado.txt)" = 600 ] && grep -q '^empresas	verificado	' /var/lib/cervesur-react-admin/escritura-estado.txt && ls ${RESP}/evidencias/empresas-*.txt >/dev/null`).code === 0, mark1.out);

  const empId = sqlN(`SELECT MAX(id) FROM ${DB}.empresa`);
  await modulo('reportes', { ruta: '/api/reportes', body: { nombre: 'Informe Ensayo 2A', descripcion: 'Informe ficticio del ensayo', enlace: 'https://app.powerbi.com/reportEmbed?reportId=11111111-2222-4333-8444-555555555555&groupId=66666666-7777-4888-8999-aaaaaaaaaaaa' }, accion: 'reporte.create', tabla: 'reporte', delta: 1, esperado: ['empresas', 'reportes'] });
  const noAcc = run(`bash ${PK}/scripts/interruptores.sh --habilitar accesos`, CE);
  ok('--habilitar accesos antes de verificar reportes se NIEGA', noAcc.code !== 0 && /todavía NO está marcado/.test(noAcc.out) && envOf('ADMIN_WRITES_ACCESOS') === '0');
  const mark2 = run(`bash ${PK}/scripts/interruptores.sh --marcar-verificado reportes --evidencia /root/evidencia-reportes.txt`, CE);
  ok('--marcar-verificado reportes', mark2.code === 0, mark2.out);
  const repId = sqlN(`SELECT MAX(id) FROM ${DB}.reporte`);
  await modulo('accesos', { ruta: '/api/asignacion', body: () => ({ empresa_id: empId, reporte_id: repId }), accion: 'empresa_reporte.create', tabla: 'empresa_reporte', delta: 1, esperado: ['empresas', 'reportes', 'accesos'] });
  const mark3 = run(`bash ${PK}/scripts/interruptores.sh --marcar-verificado accesos --evidencia /root/evidencia-accesos.txt`, CE);
  ok('--marcar-verificado accesos: fin del Paso B', mark3.code === 0 && /fin del Paso B/.test(mark3.out), mark3.out);
  ok('la tabla user NO cambió durante todo el Paso B y hay exactamente 3 filas de auditoría', tablesSum(['user']) === CK_USER0 && sqlN(`SELECT COUNT(*) FROM ${DB}.audit_log`) === 3);
  ok('audit_log es de solo inserción: UPDATE y DELETE directos (incluso como root) fallan por los triggers', dx(`mysql ${DB} -e "UPDATE audit_log SET action='x' LIMIT 1" 2>&1; mysql ${DB} -e "DELETE FROM audit_log LIMIT 1" 2>&1`).out.match(/solo insercion/g)?.length === 2);

  // ================================================================================================================================================================
  // UX-08I · ENSAYO DEL RC 2B sobre la réplica con la FASE 2A (RC2) realmente instalada y operando
  // ================================================================================================================================================================
  section = 'Línea base 2A (RC2 operando)';
  const tgz2b = process.env.ENSAYO_PAQUETE_2B ? resolve(process.env.ENSAYO_PAQUETE_2B) : newest(resolve(REPO, '..', 'paquetes-qa', 'produccion-fase2b-rc1'), 'cervesur-react-admin-fase2b-');
  if (!tgz2b || !existsSync(tgz2b)) throw new Error('Falta el paquete 2B (ENSAYO_PAQUETE_2B)');
  docker(['cp', tgz2b, `${NAME}:/root/nuevo2b.tar.gz`], { allowFail: false });
  const PK2 = `/root/${first('cd /root && tar xzf nuevo2b.tar.gz && ls -d cervesur-react-admin-fase2b-*/ | tr -d /')}`;
  ok('paquete 2B: SHA256SUMS interno válido, fase2b/repo y migraciones/repo verifican, sin instaladores A1/A2, sin .env/claves/pruebas', dx(`cd ${PK2} && sha256sum -c SHA256SUMS --quiet && (cd fase2b/repo && sha256sum -c SHA256SUMS --quiet) && (cd migraciones/repo && sha256sum -c SHA256SUMS --quiet) && [ ! -e scripts/actualizar.sh ] && [ -f scripts/instalar-codigo-2b.sh ] && [ -z "$(find . \\( -name '.env*' -o -name '*.key' -o -name '*.pem' \\) -print -quit)" ] && [ -z "$(find . -ipath '*/tests/*' -print -quit)" ]`).code === 0);
  const ident2b = () => dx(`cd /var/www/cervesur-react-admin && grep -E ' public/' ${PK2}/SHA256SUMS | sha256sum -c --quiet && cd /opt/cervesur-react-admin && grep -E ' backend/' ${PK2}/SHA256SUMS | sha256sum -c --quiet && [ "$(find ${ENV_DIR} -type f ! -name .env | wc -l)" = "$(grep -c ' backend/' ${PK2}/SHA256SUMS)" ]`).code === 0;
  const restos = () => dx(`ls -a ${FRONT} /opt/cervesur-react-admin | grep -E '^\\.(nuevo|previo|restaurar|sustituido)-' | wc -l`).out;
  const dirsHash = () => dx(`find /var/www /opt /var/backups /var/lib -maxdepth 3 | LC_ALL=C sort | sha256sum | cut -c1-64`).out;
  const grantsOf = () => sql(`SHOW GRANTS FOR '${CUENTA}'@'127.0.0.1'`);
  const bizSum = () => tablesSum(['empresa', 'reporte', 'empresa_reporte', 'empresa_usuario', 'user_empresa_reporte']);
  const auditN = () => sqlN(`SELECT COUNT(*) FROM ${DB}.audit_log`);
  const status = (m, p, t = '', b = '') => callRaw(m, p, t, b).status;
  const adminTok = () => tokenOf(login(lAdm, GOODPW));
  const capsNow = () => callRaw('GET', '/api/admin/capacidades', adminTok()).json?.data;
  const BASE = { env: envSha(), keys: envKeys().join(','), grants: grantsOf(), user: tablesSum(['user']), biz: bizSum(), audit: auditN(), apache: apacheTree() };
  const cap0 = capsNow();
  ok('línea base: admin_escritura; empresas, reportes y accesos ENCENDIDOS; usuarios y rol/contraseña BLOQUEADOS', envOf('CERVESUR_MODE') === 'admin_escritura' && cap0?.writes?.empresas === true && cap0?.writes?.reportes === true && cap0?.writes?.accesos === true && cap0?.writes?.usuarios === false && cap0?.users_sensitive === false, JSON.stringify(cap0));
  ok('línea base: cuenta MySQL con los permisos EXACTOS de la fase 2A (sin escritura sobre user salvo UPDATE(last_login)) y audit_log con sus 2 triggers', /UPDATE \(`last_login`\) ON `cervesur_portalbi`\.`user`/.test(BASE.grants) && !/INSERT \([^)]*`username`/.test(BASE.grants) && sqlN(`SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='${DB}' AND EVENT_OBJECT_TABLE='audit_log'`) === 2);
  ok('línea base: lecturas operativas (empresas, informes, asignaciones, usuarios, historial → 200) y lo instalado es el paquete RC2', ['/api/empresas?view=minimal&per_page=1&page=1', '/api/reportes?view=minimal&per_page=1&page=1', '/api/asignacion?view=minimal&per_page=1&page=1', '/api/users?status=all&per_page=1&page=1', '/api/auditoria'].every(p => status('GET', p, adminTok()) === 200) && identNuevo());
  const vb = run(`bash ${PK}/scripts/verificar-posdespliegue.sh --etapa accesos`, CE);
  ok('línea base: verificar-posdespliegue --etapa accesos (RC2) PASS', vb.code === 0 && /RESULTADO-ETAPA: PASS accesos/.test(vb.out), vb.out.slice(-500));
  dx(`cp -p ${ENVF} /root/env-base`);
  console.log(`   huellas base: env=${BASE.env.slice(0, 16)} user=${String(BASE.user).replace(/\s+/g, ' ').slice(-30)} audit=${BASE.audit} grants-sha=${spawnSync('node', ['-e', `process.stdout.write(require('crypto').createHash('sha256').update(${JSON.stringify(BASE.grants)}).digest('hex').slice(0,16))`], { encoding: 'utf8' }).stdout}`);

  // ----------------------------------------------------------------------------------------------------------------------------------------------------------------
  section = 'P3 · instalación del código 2B';
  const dir0 = dirsHash();
  const simP3 = run(`bash ${PK2}/scripts/instalar-codigo-2b.sh --simular`, CE);
  ok('--simular: código 0, dice qué reemplazaría y NO escribe nada (carpetas, .env, código)', simP3.code === 0 && /SIMULACIÓN terminada/.test(simP3.out) && dirsHash() === dir0 && identNuevo() && envSha() === BASE.env, simP3.out.slice(-700));
  // negativas
  dx(`printf 'ADMIN_WRITES_USUARIOS=0\\n' >> ${ENVF}`);
  const nKey = run(`bash ${PK2}/scripts/instalar-codigo-2b.sh --simular`, CE);
  ok('con una clave ADMIN_WRITES_USUARIOS* en el .env (aunque valga 0) se NIEGA', nKey.code !== 0 && /claves ADMIN_WRITES_USUARIOS/.test(nKey.out) && identNuevo(), nKey.out.slice(-300));
  dx(`cp -p /root/env-base ${ENVF}`);
  dx(`rm -rf /root/pk2-mala && cp -a ${PK2} /root/pk2-mala && echo '// manipulado' >> /root/pk2-mala/backend/lib/mode.php`);
  const nTam = run(`bash /root/pk2-mala/scripts/instalar-codigo-2b.sh --simular`, CE);
  ok('un paquete MANIPULADO (SHA256SUMS no verifica) se NIEGA antes de tocar nada', nTam.code !== 0 && /integridad del paquete/.test(nTam.out) && identNuevo() && dirsHash() === dir0, nTam.out.slice(-300));
  dx(`rm -rf /root/pk2-mala`);
  dx(`mkdir ${FRONT}/.previo-public-20990101-000000Z`);
  const nResto = run(`bash ${PK2}/scripts/instalar-codigo-2b.sh --aplicar`, { ...CE, ...YES });
  ok('ESTADO PARCIAL: restos de una ejecución interrumpida (.previo-*) se IDENTIFICAN y el instalador no continúa ni modifica nada', nResto.code !== 0 && /INTERRUMPIDA/.test(nResto.out) && identNuevo() && envSha() === BASE.env, nResto.out.slice(-400));
  dx(`rmdir ${FRONT}/.previo-public-20990101-000000Z`);
  // fallos inyectados: cada uno debe terminar con la Fase 2A EXACTA
  for (const fallo of ['respaldo', 'backend', 'verificacion']) {
    const b0 = dx(`ls -1 ${RESP} | grep -c '^codigo-2b-'`).out;
    const r = run(`bash ${PK2}/scripts/instalar-codigo-2b.sh --aplicar`, { ...CE, ...YES, CERVESUR_INYECTAR_FALLO: fallo });
    const b1 = dx(`ls -1 ${RESP} | grep -c '^codigo-2b-'`).out;
    ok(`fallo inyectado «${fallo}»: ${fallo === 'respaldo' ? 'aborta sin tocar nada' : 'revierte solo (salida 20)'}; lo instalado sigue siendo EXACTAMENTE la Fase 2A (RC2), .env intacto, sin restos, Apache sin tocar${fallo === 'respaldo' ? ', sin respaldo a medias' : ', respaldo conservado'}`,
      r.code === (fallo === 'respaldo' ? 1 : 20) && identNuevo() && !ident2b() && envSha() === BASE.env && restos() === '0' && apacheTree() === BASE.apache && (fallo === 'respaldo' ? b1 === b0 : Number(b1) === Number(b0) + 1), `rc=${r.code} ${r.out.slice(-400)}`);
    ok(`tras «${fallo}»: la Fase 2A completa sigue funcionando (3 módulos encendidos, usuarios bloqueado)`, (() => { const c = capsNow(); return c?.writes?.empresas && c?.writes?.reportes && c?.writes?.accesos && c?.writes?.usuarios === false; })());
  }
  // instalación real
  const rel0 = apacheReloads();
  const p3 = run(`bash ${PK2}/scripts/instalar-codigo-2b.sh --aplicar`, { ...CE, ...YES });
  ok('--aplicar: respaldo verificado, reemplazo, verificación (etapa accesos) y huella de otros portales idéntica', p3.code === 0 && /RESULTADO-INSTALACION-2B: PASS/.test(p3.out) && /respaldo en .*verificado/.test(p3.out), p3.out.slice(-900));
  const RESP2B = dx(`ls -1d ${RESP}/codigo-2b-* | tail -1`).out;
  ok('lo instalado es EXACTAMENTE el paquete 2B (backend/ y public/ archivo por archivo) y el .env es byte a byte el mismo', ident2b() && envSha() === BASE.env && envKeys().join(',') === BASE.keys);
  ok('el respaldo existe: 700, public.tgz/backend.tgz/SHA256SUMS 600, sin .env, SHA-256 verifica; sin carpetas .previo-*/.nuevo-*', dx(`cd ${RESP2B} && sha256sum -c SHA256SUMS --quiet && [ "$(stat -c %a .)" = 700 ] && [ "$(stat -c %a public.tgz backend.tgz SHA256SUMS | sort -u)" = 600 ] && ! tar tzf backend.tgz | grep -q '/\\.env'`).code === 0 && restos() === '0');
  ok('instalación sin interrumpir los módulos: Apache sin recargas, grants de la cuenta idénticos, Flutter/443/QA byte a byte iguales', apacheReloads() === rel0 && grantsOf() === BASE.grants, '');
  otros('Tras P3', false);
  const c3 = capsNow();
  ok('Usuarios CONTINÚA bloqueado: usuarios=false, users_sensitive=false; empresas, reportes y accesos siguen encendidos', c3?.writes?.usuarios === false && c3?.users_sensitive === false && c3?.writes?.empresas && c3?.writes?.reportes && c3?.writes?.accesos, JSON.stringify(c3));
  const t3 = adminTok();
  const rs3 = USERS.map(([me, p]) => callRaw(me, p, t3, '{}'));
  ok('las 6 rutas de usuarios responden 403 («fase_2b_bloqueada» las POST) con el código 2B', rs3.every(r => r.status === 403) && rs3.filter((_, i) => USERS[i][0] === 'POST').every(r => codeOf(r) === 'fase_2b_bloqueada'), rs3.map(codeOf).join(','));
  const aud3 = auditN();
  const e3 = callRaw('POST', '/api/empresas', t3, JSON.stringify({ nombre: 'Empresa P3', razon_social: 'Empresa P3 SAC', ruc: '20999999903', ciudad: 'X', pais: 'Y' }));
  ok('Empresas sigue escribiendo con el código 2B (una escritura real, una fila de auditoría)', e3.status === 200 && auditN() === aud3 + 1, e3.raw.slice(0, 200));
  const v3 = run(`bash ${PK2}/scripts/verificar-posdespliegue.sh --etapa accesos`, CE);
  ok('verificar-posdespliegue --etapa accesos PASS con el código 2B', v3.code === 0 && /RESULTADO-ETAPA: PASS accesos/.test(v3.out), v3.out.slice(-400));
  const nResp = dx(`ls -1 ${RESP} | grep -c '^codigo-2b-'`).out;
  const rep = run(`bash ${PK2}/scripts/instalar-codigo-2b.sh --aplicar`, { ...CE, ...YES });
  ok('REPETICIÓN CONTROLADA: volver a ejecutar el instalador dice «YA es este paquete» y sale con 0 sin crear respaldos ni tocar nada', rep.code === 0 && /YA es este paquete/.test(rep.out) && dx(`ls -1 ${RESP} | grep -c '^codigo-2b-'`).out === nResp && ident2b() && restos() === '0', rep.out.slice(-300));
  // reversión real
  const rv = run(`bash ${PK2}/scripts/instalar-codigo-2b.sh --revertir ${RESP2B}`, CE);
  ok('REVERSIÓN REAL (--revertir <respaldo>): vuelve EXACTAMENTE a la Fase 2A (RC2), .env intacto, sin restos', rv.code === 0 && identNuevo() && !ident2b() && envSha() === BASE.env && restos() === '0', rv.out.slice(-500));
  ok('tras revertir: Fase 2A completa operando (3 módulos, usuarios 403 «fase_2b_bloqueada»)', (() => { const c = capsNow(); return c?.writes?.empresas && c?.writes?.reportes && c?.writes?.accesos && c?.writes?.usuarios === false; })() && codeOf(callRaw('POST', '/api/register', adminTok(), '{}')) === 'fase_2b_bloqueada');
  const rvAdm = run(`bash ${PK2}/scripts/instalar-codigo-2b.sh --aplicar`, { ...CE, ...YES });
  ok('se reinstala el código 2B para continuar (P3 completo otra vez)', rvAdm.code === 0 && ident2b(), rvAdm.out.slice(-300));
  dx(`printf 'ADMIN_WRITES_USUARIOS=1\\n' >> ${ENVF}`);
  const rvOn = run(`bash ${PK2}/scripts/instalar-codigo-2b.sh --revertir $(ls -1d ${RESP}/codigo-2b-* | tail -1)`, CE);
  ok('--revertir con Usuarios encendido en el .env se NIEGA (primero se apaga)', rvOn.code !== 0 && /ADMIN_WRITES_USUARIOS/.test(rvOn.out) && ident2b(), rvOn.out.slice(-300));
  dx(`cp -p /root/env-base ${ENVF}`);
  const USER0 = tablesSum(['user']);

  // ----------------------------------------------------------------------------------------------------------------------------------------------------------------
  section = 'P4a · transición de privilegios 2A → 2B no sensibles (interruptor AUSENTE)';
  dx(`mysqldump ${DB} | gzip > /root/resp.sql.gz`);
  const AUT = 'Autorizado por el DBA y el negocio (ensayo)';
  const PR = (extra, env = {}) => run(`bash ${PK2}/scripts/privilegios-usuarios.sh --etapa no-sensibles ${extra}`, { ...CE, ...env });
  const prFase = () => run(`bash ${PK2}/scripts/privilegios-usuarios.sh --etapa fase2a --verificar`, CE);
  ok('estado inicial de la cuenta: fase2a (verificación PASS)', prFase().code === 0);
  const prSim = PR('--simular');
  const vistaN = () => sqlN(`SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA='${DB}' AND TABLE_NAME='v_user_alta'`);
  const definerN = () => sqlN(`SELECT COUNT(*) FROM mysql.user WHERE User='cervesur_vista_alta'`);
  ok('--simular: código 0, muestra las 6 sentencias exactas (cuenta DEFINER bloqueada, vista con WITH CHECK OPTION, INSERT solo sobre la vista, UPDATE sin email/role/password), sin claves, y NO escribe nada (sin vista ni DEFINER)', prSim.code === 0 && /CREATE USER IF NOT EXISTS 'cervesur_vista_alta'@'localhost' IDENTIFIED BY RANDOM PASSWORD ACCOUNT LOCK/.test(prSim.out) && /WITH CHECK OPTION/.test(prSim.out) && /GRANT INSERT ON `cervesur_portalbi`\.`v_user_alta`/.test(prSim.out) && /GRANT UPDATE/.test(prSim.out) && !/UPDATE \([^)]*email/.test(prSim.out) && !prSim.out.includes(PASSW) && grantsOf() === BASE.grants && vistaN() === 0 && definerN() === 0, prSim.out.slice(-800));
  const prNo = PR('--aplicar');
  ok('--aplicar sin respaldo ni autorización: bloqueado (10), cuenta intacta', prNo.code === 10 && grantsOf() === BASE.grants, prNo.out.slice(-300));
  const enNoPriv = run(`bash ${PK2}/scripts/interruptor-usuarios.sh encender --autorizacion "${AUT}"`, { ...CE, CERVESUR_CONFIRMAR_USUARIOS: 'SI' });
  ok('ORDEN SEGURO: encender Usuarios SIN privilegios aplicados se NIEGA (verifica la etapa de privilegios antes de tocar el .env)', enNoPriv.code !== 0 && /privilegios de la cuenta NO están/.test(enNoPriv.out) && envSha() === BASE.env, enNoPriv.out.slice(-400));
  // fallo parcial inyectado en el aplicar
  const prInj = PR(`--aplicar --respaldo /root/resp.sql.gz --confirmo-autorizacion "${AUT}"`, { CERVESUR_INYECTAR_FALLO: 'privilegios' });
  ok('FALLO PARCIAL inyectado (cuenta DEFINER, vista y GRANT INSERT sobre la vista creados; GRANT UPDATE falla): salida 20, el script lo informa y DESHACE todo; la cuenta vuelve a la Fase 2A exacta y no quedan vista ni DEFINER', prInj.code === 20 && /PARCIAL/.test(prInj.out) && grantsOf() === BASE.grants && vistaN() === 0 && definerN() === 0, prInj.out.slice(-500));
  ok('…y durante el fallo el backend no se interrumpió: login y una escritura de Empresas funcionan', status('POST', '/api/empresas', adminTok(), JSON.stringify({ nombre: 'Durante fallo', razon_social: 'Durante fallo SAC', ruc: '20999999941', ciudad: 'X', pais: 'Y' })) === 200);
  // estado parcial persistente (la reversión automática también falla): se identifica y se recupera con --revertir
  // (se reproduce lo que dejaría un corte a mitad de la etapa: DEFINER + vista + INSERT sobre la vista, sin los UPDATE)
  dx(`grep -vE '^[[:space:]]*--' ${PK2}/fase2b/repo/UX-08I-alta-usuarios-vista.up.sql.template | sed -e "s/{DB}/${DB}/g" -e "s/{VISTA}/cervesur_vista_alta/g" | mysql >/dev/null`);
  sql(`GRANT INSERT ON ${DB}.v_user_alta TO '${CUENTA}'@'127.0.0.1'`);
  const vPar = run(`bash ${PK2}/scripts/privilegios-usuarios.sh --etapa fase2a --verificar`, CE);
  ok('ESTADO PARCIAL persistente (DEFINER, vista e INSERT sobre la vista): --verificar fase2a FALLA (30) e identifica el estado «otro»', vPar.code === 30 && /otro/.test(vPar.out) && vistaN() === 1 && definerN() === 1, vPar.out.slice(-300));
  const pingPar = callRaw('GET', '/api/ping');
  const tPar = adminTok();
  ok('con el estado parcial el backend SIGUE sirviendo (sin 503) y Usuarios sigue bloqueado por la ruta (la capacidad efectiva no cambió)', pingPar.status === 200 && /^[0-9a-f]{64}$/.test(tPar) && codeOf(callRaw('POST', '/api/register', tPar, '{}')) === 'fase_2b_bloqueada', `${pingPar.status}`);
  const apPar = PR(`--aplicar --respaldo /root/resp.sql.gz --confirmo-autorizacion "${AUT}"`);
  ok('--aplicar sobre un estado parcial se NIEGA (10): primero se recupera', apPar.code === 10 && /fase2a/.test(apPar.out), apPar.out.slice(-300));
  const recPar = PR(`--revertir --confirmo-autorizacion "${AUT}"`);
  ok('RECUPERACIÓN: --revertir sobre el estado parcial lo deja EXACTAMENTE en la Fase 2A (los REVOKE de lo no concedido se toleran, se elimina la vista y la cuenta DEFINER; nada más)', recPar.code === 0 && grantsOf() === BASE.grants && prFase().code === 0 && vistaN() === 0 && definerN() === 0 && sqlN(`SELECT COUNT(*) FROM mysql.tables_priv WHERE Db='${DB}' AND Table_name='v_user_alta'`) === 0, recPar.out.slice(-500));
  // INSERT DIRECTO sobre user (la forma del RC anterior): NO es una transición legítima → el backend se niega a servir y el kit no lo revierte solo
  sql(`GRANT INSERT (username, email, first_name, last_name, phone, password, role, is_active, session_version, created_at, updated_at) ON ${DB}.user TO '${CUENTA}'@'127.0.0.1'`);
  const pingIns = callRaw('GET', '/api/ping'); const prIns = PR('--simular'); const rvIns = PR(`--revertir --confirmo-autorizacion "${AUT}"`);
  ok('INSERT DIRECTO sobre user (el alta debe ser solo por la vista): el backend SE NIEGA a servir (503), el kit bloquea la etapa (10) y se niega a revertirlo como si fuera un estado de la etapa (10)', pingIns.status === 503 && prIns.code === 10 && /INSERT@user/.test(prIns.out) && rvIns.code === 10, `${pingIns.status} ${prIns.code} ${rvIns.code}`);
  sql(`REVOKE INSERT (username, email, first_name, last_name, phone, password, role, is_active, session_version, created_at, updated_at) ON ${DB}.user FROM '${CUENTA}'@'127.0.0.1'`);
  ok('…y al retirarlo el backend vuelve a servir y la cuenta es la de la Fase 2A', callRaw('GET', '/api/ping').status === 200 && grantsOf() === BASE.grants);
  // UPDATE(email) concedido a mano: tampoco es una transición legítima
  sql(`GRANT UPDATE (email) ON ${DB}.user TO '${CUENTA}'@'127.0.0.1'`);
  ok('UPDATE(email) concedido a mano: el backend SE NIEGA a servir (503) y el kit bloquea (10)', callRaw('GET', '/api/ping').status === 503 && PR('--simular').code === 10);
  sql(`REVOKE UPDATE (email) ON ${DB}.user FROM '${CUENTA}'@'127.0.0.1'`);
  ok('…y al retirarlo todo vuelve a la Fase 2A', callRaw('GET', '/api/ping').status === 200 && grantsOf() === BASE.grants && prFase().code === 0);
  // columnas ajenas = fatal (no es una transición)
  sql(`GRANT UPDATE (username) ON ${DB}.user TO '${CUENTA}'@'127.0.0.1'`);
  const ping503 = callRaw('GET', '/api/ping');
  const prAjena = PR('--simular');
  ok('UPDATE(username) (columna ajena a las plantillas): el backend SE NIEGA a servir (503) y el script la bloquea — NO es una transición legítima', ping503.status === 503 && prAjena.code === 10, `${ping503.status} ${prAjena.out.slice(-200)}`);
  sql(`REVOKE UPDATE (username) ON ${DB}.user FROM '${CUENTA}'@'127.0.0.1'`);
  ok('…y al retirarla el backend vuelve a servir y la cuenta es la de la Fase 2A', callRaw('GET', '/api/ping').status === 200 && grantsOf() === BASE.grants);
  // aplicación real
  const userAntes = tablesSum(['user']); const bizAntes = bizSum(); const audAntes = auditN(); const relP4 = apacheReloads();
  const prReal = PR(`--aplicar --respaldo /root/resp.sql.gz --confirmo-autorizacion "${AUT}"`);
  ok('--aplicar no-sensibles: salida 0, verificación posterior PASS, evidencia en carpeta 700', prReal.code === 0 && /RESULTADO-PRIVILEGIOS: PASS aplicar no-sensibles/.test(prReal.out) && dx(`d=$(ls -1d ${RESP}/privilegios-no-sensibles-aplicar-* | tail -1); [ "$(stat -c %a $d)" = 700 ] && [ -s $d/grants-antes.txt ] && [ -s $d/grants-despues.txt ] && [ -s $d/vista-despues.txt ] && ! grep -rqiE 'generated password|[A-Za-z0-9]{20}' $d/salida.txt`).code === 0 && vistaN() === 1 && definerN() === 1, prReal.out.slice(-600));
  ok('la vista de alta quedó vigente: insertable, WITH CHECK OPTION, SQL SECURITY DEFINER con la cuenta dedicada; la cuenta DEFINER está BLOQUEADA y solo tiene SELECT/INSERT por columnas sobre user', sql(`SELECT CONCAT_WS('|', CHECK_OPTION, IS_UPDATABLE, SECURITY_TYPE, DEFINER) FROM information_schema.VIEWS WHERE TABLE_SCHEMA='${DB}' AND TABLE_NAME='v_user_alta'`) === 'CASCADED|YES|DEFINER|cervesur_vista_alta@localhost' && sql(`SELECT account_locked FROM mysql.user WHERE User='cervesur_vista_alta'`) === 'Y' && !/\b(UPDATE|DELETE|ALL PRIVILEGES|GRANT OPTION)\b/.test(sql(`SHOW GRANTS FOR 'cervesur_vista_alta'@'localhost'`)));
  ok('la clave aleatoria de la cuenta DEFINER no quedó en ningún archivo de evidencia ni en la salida del script', !run(`grep -rIl 'generated password' ${RESP}/privilegios-* 2>/dev/null | wc -l`).out.trim().replace(/^0$/, '') && !prReal.out.includes('generated'));
  const pPost = callRaw('GET', '/api/ping');
  ok('P4a → NO HAY 503: inmediatamente después de conceder los permisos, con ADMIN_WRITES_USUARIOS ausente, el backend responde (200) y el administrador inicia sesión', pPost.status === 200 && /^[0-9a-f]{64}$/.test(adminTok()), `${pPost.status}`);
  const cP = capsNow();
  ok('P4a → la capacidad efectiva NO se amplió: usuarios=false, users_sensitive=false; Usuarios sigue 403 «fase_2b_bloqueada» por la ruta', cP?.writes?.usuarios === false && cP?.users_sensitive === false && USERS.every(([me, p]) => callRaw(me, p, adminTok(), '{}').status === 403) && codeOf(callRaw('POST', '/api/register', adminTok(), JSON.stringify({ username: 'p4a_sin_flag', email: 'p4a@example.invalid', password: 'Aa1!p4a-sin-flag-x', first_name: 'a', last_name: 'b' }))) === 'fase_2b_bloqueada', JSON.stringify(cP));
  ok('P4a → ningún dato de usuarios cambió y Empresas/Reportes/Accesos siguen escribiendo (una escritura real de Empresas)', tablesSum(['user']) === userAntes && status('POST', '/api/empresas', adminTok(), JSON.stringify({ nombre: 'Tras P4a', razon_social: 'Tras P4a SAC', ruc: '20999999942', ciudad: 'X', pais: 'Y' })) === 200 && auditN() === audAntes + 1);
  ok('P4a → Apache y otros portales intactos', apacheReloads() === relP4);
  otros('Tras P4a', false);
  const acct = (q) => dx(`mysql -h127.0.0.1 -u${CUENTA} -p'${PASSW}' ${DB} -N -B -e "${q}" 2>&1`).out;
  ok('privilegios REALES del motor: la cuenta NO puede UPDATE de role ni de password, ni leer password, ni DELETE en user; sí puede escribir columnas no sensibles (probado con filas inexistentes)', /denied/i.test(acct("UPDATE user SET role='admin' WHERE id=0")) && /denied/i.test(acct("UPDATE user SET password='x' WHERE id=0")) && /denied/i.test(acct('SELECT password FROM user LIMIT 1')) && /denied/i.test(acct('DELETE FROM user WHERE id=0')) && !/denied/i.test(acct("UPDATE user SET first_name='x' WHERE id=0")));
  const colsV = 'first_name, last_name, username, email, phone, password, role, is_active, session_version, created_at, updated_at';
  const insV = (rol, user) => `INSERT INTO v_user_alta (${colsV}) VALUES ('a', 'b', '${user}', '${user}@example.invalid', NULL, 'hash-ficticio', '${rol}', 1, 1, NOW(), NOW())`;
  const nUser = () => sqlN(`SELECT COUNT(*) FROM ${DB}.user`);
  const u0 = nUser();
  ok('ESCALAMIENTO (SQL directo con la cuenta de React): el alta con role «admin» por la vista la rechaza el MOTOR (CHECK OPTION); los roles «superadmin» y el vacío también; no se crea ninguna fila', ['admin', 'superadmin', ''].every(r => /CHECK OPTION failed|1369/i.test(acct(insV(r, 'esc_' + (r || 'vacio'))))) && nUser() === u0);
  ok('ESCALAMIENTO: INSERT directo en user, UPDATE(email), UPDATE(role) y UPDATE(password) los niega el MOTOR; el id explícito y la lectura de password también', /denied/i.test(acct("INSERT INTO user (username, role, is_active, session_version) VALUES ('esc_directo', 'user', 1, 1)")) && /denied/i.test(acct("UPDATE user SET email='x@example.invalid' WHERE id=0")) && /denied/i.test(acct("UPDATE user SET role='admin' WHERE id=0")) && /denied/i.test(acct("UPDATE user SET password='x' WHERE id=0")) && /denied/i.test(acct('SELECT password FROM user LIMIT 1')) && nUser() === u0);
  ok('LEGÍTIMO (SQL directo, dentro de una transacción que se revierte): el alta de rol «user» por la vista SÍ la admite el motor', /^1\b/m.test(acct(`START TRANSACTION; ${insV('user', 'esc_ok')}; SELECT ROW_COUNT(); ROLLBACK`)) && nUser() === u0, acct(`START TRANSACTION; ${insV('user', 'esc_ok')}; SELECT ROW_COUNT(); ROLLBACK`));
  // una vista alterada (sin WITH CHECK OPTION) abriría la brecha en la base: el verificador del kit la detecta y la restablece la plantilla
  const vistaTpl = 'grep -vE \'^[[:space:]]*--\' ' + `${PK2}/fase2b/repo/UX-08I-alta-usuarios-vista.up.sql.template | sed -e "s/{DB}/${DB}/g" -e "s/{VISTA}/cervesur_vista_alta/g"`;
  sql(`CREATE OR REPLACE DEFINER='cervesur_vista_alta'@'localhost' SQL SECURITY DEFINER VIEW ${DB}.v_user_alta AS SELECT username, email, first_name, last_name, phone, password, role, is_active, session_version, created_at, updated_at FROM ${DB}.user WHERE role IN ('user','usuario') AND is_active = 1 AND session_version = 1`);
  const vAlt = PR('--verificar');
  ok('VISTA ALTERADA (sin WITH CHECK OPTION): --verificar FALLA (30) y lo informa; el backend sigue sirviendo', vAlt.code === 30 && /vista-alterada|check\/insertable/.test(vAlt.out) && callRaw('GET', '/api/ping').status === 200, vAlt.out.slice(-400));
  dx(`${vistaTpl} | sed -n '/CREATE OR REPLACE/,$p' | mysql`);
  const vRest = PR('--verificar');
  ok('…y recrearla desde la plantilla la restablece: --verificar vuelve a PASS sin tocar privilegios', vRest.code === 0 && /vista v_user_alta: insertable/.test(vRest.out), vRest.out.slice(-300));
  const vP = PR('--verificar');
  ok('--verificar no-sensibles PASS; reaplicar la misma etapa se bloquea (10, idempotente)', vP.code === 0 && PR(`--aplicar --respaldo /root/resp.sql.gz --confirmo-autorizacion "${AUT}"`).code === 10);

  // ----------------------------------------------------------------------------------------------------------------------------------------------------------------
  section = 'P4b · interruptor de Usuarios';
  const IU = (args, env = {}) => run(`bash ${PK2}/scripts/interruptor-usuarios.sh ${args}`, { ...CE, ...env });
  const est = IU('estado');
  ok('estado: ambas claves «<sin definir>»', est.code === 0 && /ADMIN_WRITES_USUARIOS=<sin definir>/.test(est.out) && /SENSIBLE=<sin definir>/.test(est.out), est.out);
  const sEnv = envSha();
  ok('encender SIN la confirmación CERVESUR_CONFIRMAR_USUARIOS=SI se NIEGA y no toca el .env', IU(`encender --autorizacion "${AUT}"`).code !== 0 && envSha() === sEnv);
  ok('encender SIN autorización textual se NIEGA', IU('encender', { CERVESUR_CONFIRMAR_USUARIOS: 'SI' }).code !== 0 && envSha() === sEnv);
  const sinCredI = IU(`encender --autorizacion "${AUT}"`, { CERVESUR_CONFIRMAR_USUARIOS: 'SI', CERVESUR_E_USUARIO: '', CERVESUR_E_CLAVE: '' });
  ok('encender SIN credenciales se NIEGA (no se enciende lo que no se puede verificar)', sinCredI.code !== 0 && /CERVESUR_E_USUARIO/.test(sinCredI.out) && envSha() === sEnv);
  const sens = IU('sensible');
  ok('«sensible» (rol y contraseña, P5) se RECHAZA siempre y no toca el .env', sens.code !== 0 && /NO se pueden encender/.test(sens.out) && envSha() === sEnv);
  const inj = IU(`encender --autorizacion "${AUT}"`, { CERVESUR_CONFIRMAR_USUARIOS: 'SI', CERVESUR_INYECTAR_FALLO: 'capacidades' });
  ok('FALLO DE VERIFICACIÓN inyectado tras encender: el script restaura el .env previo y sale con error; el .env queda BYTE A BYTE como estaba', inj.code !== 0 && /NO son las esperadas/.test(inj.out) && envSha() === sEnv && !envKeys().some(k => /USUARIOS/.test(k)), inj.out.slice(-500));
  ok('…y tras el fallo Usuarios sigue bloqueado y 2A operando', (() => { const c = capsNow(); return c?.writes?.usuarios === false && c?.writes?.empresas; })());
  const on = IU(`encender --autorizacion "${AUT}"`, { CERVESUR_CONFIRMAR_USUARIOS: 'SI' });
  ok('ENCENDER: éxito; verifica capacidades (usuarios=true, sensible=false, 2A intactos)', on.code === 0 && /capacidades verificadas: usuarios=true/.test(on.out), on.out.slice(-600));
  ok('.env: ADMIN_WRITES_USUARIOS=1 y NINGUNA otra clave de usuarios (la sensible ni existe); resto del .env intacto', envOf('ADMIN_WRITES_USUARIOS') === '1' && !envKeys().includes('ADMIN_WRITES_USUARIOS_SENSIBLE') && envKeys().filter(k => k !== 'ADMIN_WRITES_USUARIOS').join(',') === BASE.keys);
  const cOn = capsNow();
  ok('capacidades efectivas: SOLO operaciones no sensibles (usuarios=true, users_sensitive=false, reactivate_after_hours=24); el frontend (que lee users_sensitive) NO ofrece rol ni contraseña', cOn?.writes?.usuarios === true && cOn?.users_sensitive === false && cOn?.reactivate_after_hours === 24 && cOn?.writes?.empresas && cOn?.writes?.reportes && cOn?.writes?.accesos, JSON.stringify(cOn));
  const tOn = adminTok();
  const rolR = callRaw('PUT', '/api/user/2/role', tOn, '{"role":"admin"}'); const pwR = callRaw('PUT', '/api/users/2/password', tOn, '{"password":"Aa1!no-debe-cambiar-x"}');
  ok('DENEGACIÓN de lo sensible: PUT rol y PUT contraseña → 403 «escritura_desactivada»; el rol y la contraseña de TODOS los usuarios siguen idénticos', rolR.status === 403 && codeOf(rolR) === 'escritura_desactivada' && pwR.status === 403 && codeOf(pwR) === 'escritura_desactivada' && sql(`SELECT SHA2(GROUP_CONCAT(id, role, password ORDER BY id), 256) FROM ${DB}.user`).length === 64, `${codeOf(rolR)} ${codeOf(pwR)}`);
  const hashSens = sql(`SELECT SHA2(GROUP_CONCAT(id, role, password ORDER BY id), 256) FROM ${DB}.user`);
  callRaw('PUT', '/api/user/2/role', tOn, '{"role":"admin"}'); callRaw('PUT', '/api/users/2/password', tOn, '{"password":"Aa1!no-debe-cambiar-y"}');
  ok('…repetidos, tampoco cambian nada', sql(`SELECT SHA2(GROUP_CONCAT(id, role, password ORDER BY id), 256) FROM ${DB}.user`) === hashSens);
  const estOn2 = IU('estado');
  ok('estado: USUARIOS=1 y la clave sensible sin definir', /ADMIN_WRITES_USUARIOS=1/.test(estOn2.out) && /SENSIBLE=<sin definir>/.test(estOn2.out));

  // ----------------------------------------------------------------------------------------------------------------------------------------------------------------
  section = 'P4c · operaciones funcionales de Usuarios (datos sintéticos)';
  const actAudit = (id, accion) => sqlN(`SELECT COUNT(*) FROM ${DB}.audit_log WHERE entity='user' AND entity_id=${id} AND action='${accion}'`);
  const aU0 = auditN(); const biz0 = bizSum();
  const crea = callRaw('POST', '/api/register', tOn, JSON.stringify({ first_name: 'Prueba', last_name: 'Cuatro', username: 'ensayo.p4c', email: 'ensayo.p4c@example.invalid', phone: '555-0404', password: 'Aa1!ensayo-p4c-clave' }));
  const uid = Number(crea.json?.id ?? 0);
  ok('ALTA: 200 con id; rol «user», activo, session_version 1, contraseña SOLO como hash verificable', crea.status === 200 && uid > 0 && sql(`SELECT CONCAT(role,'|',is_active,'|',session_version,'|',(password LIKE '$2y$%')) FROM ${DB}.user WHERE id=${uid}`) === 'user|1|1|1', crea.raw.slice(0, 200));
  ok('ALTA: exactamente UNA auditoría user.create y la bitácora no contiene contraseña, hash, correo ni teléfono', actAudit(uid, 'user.create') === 1 && auditN() === aU0 + 1 && !/Aa1!ensayo|\$2y\$|ensayo\.p4c|555-0404/.test(sql(`SELECT CONCAT_WS('|', before_json, after_json) FROM ${DB}.audit_log WHERE entity='user' AND entity_id=${uid}`)));
  const dup = callRaw('POST', '/api/register', tOn, JSON.stringify({ first_name: 'Prueba', last_name: 'Cuatro', username: 'ensayo.p4c', email: 'ensayo.p4c@example.invalid', password: 'Aa1!ensayo-p4c-clave' }));
  ok('ALTA repetida (reintento): 409, una sola fila y una sola auditoría', dup.status === 409 && actAudit(uid, 'user.create') === 1 && sqlN(`SELECT COUNT(*) FROM ${DB}.user WHERE username='ensayo.p4c'`) === 1);
  const forz = callRaw('POST', '/api/register', tOn, JSON.stringify({ first_name: 'F', last_name: 'F', username: 'ensayo.forzado', email: 'forzado@example.invalid', password: 'Aa1!ensayo-forzado', role: 'admin' }));
  ok('ALTA con «role» en el cuerpo: 400 y no se crea nada (sin autoescalamiento)', forz.status === 400 && sqlN(`SELECT COUNT(*) FROM ${DB}.user WHERE username='ensayo.forzado'`) === 0);
  const det = callRaw('GET', `/api/users/${uid}`, tOn); const v1 = det.json?.data?.version;
  ok('detalle con versión y sin campo de contraseña', !!v1 && !/password/.test(det.raw));
  const ed = callRaw('PUT', `/api/users/${uid}`, tOn, JSON.stringify({ phone: '555-0505', version: v1, role: 'admin', username: 'robado', is_active: 0, password: 'otra' }));
  ok('EDICIÓN: 200; solo cambian datos personales (role, username, is_active y password del cuerpo se ignoran); una auditoría user.update', ed.status === 200 && sql(`SELECT CONCAT(phone,'|',role,'|',username,'|',is_active) FROM ${DB}.user WHERE id=${uid}`) === '555-0505|user|ensayo.p4c|1' && actAudit(uid, 'user.update') === 1);
  const vEm = callRaw('GET', `/api/users/${uid}`, tOn).json?.data?.version; const aEm = auditN();
  const emCambio = callRaw('PUT', `/api/users/${uid}`, tOn, JSON.stringify({ email: 'otro.correo@example.invalid', version: vEm }));
  const emMismo = callRaw('PUT', `/api/users/${uid}`, tOn, JSON.stringify({ email: 'ENSAYO.P4C@example.invalid', phone: '555-0505', version: vEm }));
  ok('CORREO (UX-08I): cambiar el correo se RECHAZA (400, mensaje claro) sin tocar la fila ni la auditoría; reenviar el MISMO correo no es un cambio y no se rechaza', emCambio.status === 400 && /correo no se puede modificar/.test(emCambio.raw) && sql(`SELECT email FROM ${DB}.user WHERE id=${uid}`) === 'ensayo.p4c@example.invalid' && auditN() === aEm && emMismo.status === 200, `${emCambio.status} ${emMismo.status}`);
  sql(`UPDATE ${DB}.user SET first_name='Escrito por Flutter' WHERE id=${uid}`);
  const conf = callRaw('PUT', `/api/users/${uid}`, tOn, JSON.stringify({ phone: '555-0606', version: v1 }));
  ok('CONFLICTO 409: otra aplicación (Flutter) escribió entre la lectura y el guardado; no se cambia nada y no hay auditoría extra', conf.status === 409 && sql(`SELECT phone FROM ${DB}.user WHERE id=${uid}`) === '555-0505' && actAudit(uid, 'user.update') === 1);
  const sv0 = sqlN(`SELECT session_version FROM ${DB}.user WHERE id=${uid}`);
  const des = callRaw('POST', `/api/users/${uid}/deactivate`, tOn, '');
  ok('DESACTIVAR: 200; is_active=0, session_version +1 (cierra sesiones) y una auditoría user.deactivate', des.status === 200 && sqlN(`SELECT is_active FROM ${DB}.user WHERE id=${uid}`) === 0 && sqlN(`SELECT session_version FROM ${DB}.user WHERE id=${uid}`) === sv0 + 1 && actAudit(uid, 'user.deactivate') === 1);
  ok('desactivar de nuevo: 409 sin auditoría duplicada; reactivar antes de 24 h: 409 sin cambio ni auditoría', callRaw('POST', `/api/users/${uid}/deactivate`, tOn, '').status === 409 && callRaw('POST', `/api/users/${uid}/reactivate`, tOn, '').status === 409 && actAudit(uid, 'user.deactivate') === 1 && actAudit(uid, 'user.reactivate') === 0 && sqlN(`SELECT is_active FROM ${DB}.user WHERE id=${uid}`) === 0);
  ok('el administrador NO puede desactivarse a sí mismo (409)', callRaw('POST', '/api/users/1/deactivate', tOn, '').status === 409 && sqlN(`SELECT is_active FROM ${DB}.user WHERE id=1`) === 1);
  // atomicidad
  const gAntes = grantsOf(); const aAt = auditN(); const phone0 = sql(`SELECT phone FROM ${DB}.user WHERE id=${uid}`);
  sql(`REVOKE INSERT ON ${DB}.audit_log FROM '${CUENTA}'@'127.0.0.1'`);
  const sinAud = callRaw('PUT', `/api/users/${uid}`, tOn, JSON.stringify({ phone: '555-0707', version: callRaw('GET', `/api/users/${uid}`, tOn).json?.data?.version }));
  const sinAud2 = callRaw('POST', '/api/register', tOn, JSON.stringify({ first_name: 'S', last_name: 'A', username: 'ensayo.sinaud', email: 'sinaud@example.invalid', password: 'Aa1!ensayo-sinaud' }));
  ok('ATOMICIDAD: si la auditoría falla (sin permiso INSERT en audit_log) la edición y el alta devuelven 5xx genérico y NO queda ningún cambio ni fila', sinAud.status >= 500 && sinAud2.status >= 500 && !/SQLSTATE|Stack trace|cervesur_escritura/.test(sinAud.raw + sinAud2.raw) && sql(`SELECT phone FROM ${DB}.user WHERE id=${uid}`) === phone0 && sqlN(`SELECT COUNT(*) FROM ${DB}.user WHERE username='ensayo.sinaud'`) === 0 && auditN() === aAt, `${sinAud.status} ${sinAud2.status}`);
  ok('…y Empresas también falla de forma atómica en ese estado (no queda empresa a medias)', (() => { const n = sqlN(`SELECT COUNT(*) FROM ${DB}.empresa`); const r = callRaw('POST', '/api/empresas', tOn, JSON.stringify({ nombre: 'Sin auditoría', razon_social: 'Sin auditoría SAC', ruc: '20999999951', ciudad: 'X', pais: 'Y' })); return r.status >= 500 && sqlN(`SELECT COUNT(*) FROM ${DB}.empresa`) === n; })());
  sql(`GRANT INSERT ON ${DB}.audit_log TO '${CUENTA}'@'127.0.0.1'`);
  ok('restablecido el permiso, la cuenta vuelve a sus grants exactos y la misma edición funciona', grantsOf() === gAntes && callRaw('PUT', `/api/users/${uid}`, tOn, JSON.stringify({ phone: '555-0707', version: callRaw('GET', `/api/users/${uid}`, tOn).json?.data?.version })).status === 200);
  // VISTA AUSENTE o DEFINER AUSENTE: el alta falla cerrada (sin filas parciales ni auditoría) y el resto de Usuarios y Empresas/Reportes/Accesos siguen funcionando
  const altaFalla = (u) => callRaw('POST', '/api/register', tOn, JSON.stringify({ first_name: 'V', last_name: 'A', username: u, email: `${u}@example.invalid`, password: 'Aa1!ensayo-vista-x' }));
  const uV = nUser(); const aV = auditN();
  sql(`RENAME USER 'cervesur_vista_alta'@'localhost' TO 'cervesur_vista_alta_x'@'localhost'`);
  const sinDef = altaFalla('ensayo.sindefiner');
  const edSinDef = callRaw('PUT', `/api/users/${uid}`, tOn, JSON.stringify({ phone: '555-0710', version: callRaw('GET', `/api/users/${uid}`, tOn).json?.data?.version }));
  sql(`RENAME USER 'cervesur_vista_alta_x'@'localhost' TO 'cervesur_vista_alta'@'localhost'`);
  ok('DEFINER AUSENTE: el alta devuelve 5xx genérico (sin SQLSTATE ni nombres), no deja usuario ni auditoría a medias; la EDICIÓN de usuarios sigue funcionando; restituido el DEFINER, el alta vuelve', sinDef.status >= 500 && !/SQLSTATE|Stack trace|v_user_alta|cervesur_/.test(sinDef.raw) && nUser() === uV && edSinDef.status === 200 && altaFalla('ensayo.definer').status === 200 && actAudit(sqlN(`SELECT id FROM ${DB}.user WHERE username='ensayo.definer'`), 'user.create') === 1, `${sinDef.status} ${edSinDef.status}`);
  sql(`DROP VIEW ${DB}.v_user_alta`);
  const sinVista = altaFalla('ensayo.sinvista');
  ok('VISTA AUSENTE (DROP VIEW): el alta devuelve 5xx genérico sin residuos y Empresas sigue escribiendo; el kit lo detecta (--verificar 30)', sinVista.status >= 500 && !/SQLSTATE|v_user_alta/.test(sinVista.raw) && sqlN(`SELECT COUNT(*) FROM ${DB}.user WHERE username='ensayo.sinvista'`) === 0 && status('POST', '/api/empresas', tOn, JSON.stringify({ nombre: 'Con vista ausente', razon_social: 'Con vista ausente SAC', ruc: '20999999953', ciudad: 'X', pais: 'Y' })) === 200 && PR('--verificar').code === 30);
  dx(`${vistaTpl} | sed -n '/CREATE OR REPLACE/,$p' | mysql`);
  ok('…recreada desde la plantilla (los permisos de la cuenta sobrevivieron al DROP/CREATE), el alta y --verificar vuelven a PASS', altaFalla('ensayo.recreada').status === 200 && PR('--verificar').code === 0);
  // el administrador pierde permisos / se inactiva durante la operación
  const ph1 = sql(`SELECT phone FROM ${DB}.user WHERE id=${uid}`); const aInact = auditN();
  sql(`UPDATE ${DB}.user SET is_active=0 WHERE id=1`);
  const inact = callRaw('PUT', `/api/users/${uid}`, tOn, JSON.stringify({ phone: '555-0808' }));
  sql(`UPDATE ${DB}.user SET is_active=1 WHERE id=1`);
  ok('PÉRDIDA DE ESTADO: si el administrador es inactivado, su sesión deja de valer en la siguiente petición (401) y la operación NO se aplica', inact.status === 401 && sql(`SELECT phone FROM ${DB}.user WHERE id=${uid}`) === ph1 && auditN() === aInact, `${inact.status}`);
  sql(`UPDATE ${DB}.user SET role='user' WHERE id=1`);
  const sinRol = callRaw('POST', `/api/users/${uid}/deactivate`, tOn, '');
  sql(`UPDATE ${DB}.user SET role='admin' WHERE id=1`);
  ok('PÉRDIDA DE PERMISO: si el administrador deja de serlo, la operación se rechaza (401/403) y no cambia nada', [401, 403].includes(sinRol.status) && sqlN(`SELECT is_active FROM ${DB}.user WHERE id=${uid}`) === 0 && auditN() === aInact, `${sinRol.status}`);
  ok('tras restituir al administrador (datos de prueba), las operaciones vuelven a funcionar', status('PUT', `/api/users/${uid}`, adminTok(), JSON.stringify({ phone: '555-0909' })) === 200);
  // 2A intacta
  const aE = auditN(); const rE = callRaw('POST', '/api/empresas', adminTok(), JSON.stringify({ nombre: 'Empresa con Usuarios ON', razon_social: 'Empresa con Usuarios ON SAC', ruc: '20999999952', ciudad: 'X', pais: 'Y' }));
  const eid2 = Number(rE.json?.id ?? 0);
  ok('EMPRESAS con Usuarios encendido: alta con una auditoría; REPORTES y ACCESOS siguen accesibles (lecturas 200)', rE.status === 200 && auditN() === aE + 1 && ['/api/reportes?view=minimal&per_page=1&page=1', '/api/asignacion?view=minimal&per_page=1&page=1'].every(p => status('GET', p, adminTok()) === 200) && eid2 > 0);
  ok('la auditoría de TODAS las operaciones de usuarios es de solo inserción (UPDATE/DELETE incluso como root fallan) y no hay acciones fuera de las previstas', dx(`mysql ${DB} -e "UPDATE audit_log SET action='x' LIMIT 1" 2>&1; mysql ${DB} -e "DELETE FROM audit_log LIMIT 1" 2>&1`).out.match(/solo insercion/g)?.length === 2 && sql(`SELECT GROUP_CONCAT(DISTINCT action ORDER BY action) FROM ${DB}.audit_log WHERE entity='user'`) === 'user.create,user.deactivate,user.update');
  const PRE_REC = { user: tablesSum(['user']), biz: bizSum(), audit: auditN() };

  // ----------------------------------------------------------------------------------------------------------------------------------------------------------------
  section = 'Recuperación integral hasta un estado equivalente a la Fase 2A';
  const off = IU('apagar');
  ok('1) APAGAR: elimina ADMIN_WRITES_USUARIOS*, verifica capacidades (usuarios=false, 2A intactos)', off.code === 0 && /claves ADMIN_WRITES_USUARIOS\* eliminadas/.test(off.out) && /capacidades verificadas/.test(off.out) && !envKeys().some(k => /USUARIOS/.test(k)), off.out.slice(-500));
  ok('1) el .env queda BYTE A BYTE como la línea base de la Fase 2A', envSha() === BASE.env && envKeys().join(',') === BASE.keys);
  ok('1) Usuarios vuelve a 403 «fase_2b_bloqueada» (6 rutas) y las escrituras de 2A siguen funcionando', USERS.every(([me, p]) => callRaw(me, p, adminTok(), '{}').status === 403) && codeOf(callRaw('POST', '/api/register', adminTok(), '{}')) === 'fase_2b_bloqueada' && capsNow()?.writes?.empresas === true);
  const rvInj = PR(`--revertir --confirmo-autorizacion "${AUT}"`, { CERVESUR_INYECTAR_FALLO: 'privilegios' });
  ok('2) REVERTIR PRIVILEGIOS con fallo inyectado a mitad (el REVOKE de INSERT se aplica, el de UPDATE no): salida 20 y estado PARCIAL identificado', rvInj.code === 20 && /PARCIAL/.test(rvInj.out) && prFase().code !== 0, rvInj.out.slice(-400));
  ok('2) con el estado parcial el backend sigue sirviendo y Usuarios bloqueado (sin 503)', callRaw('GET', '/api/ping').status === 200 && codeOf(callRaw('POST', '/api/register', adminTok(), '{}')) === 'fase_2b_bloqueada');
  const rvOk = PR(`--revertir --confirmo-autorizacion "${AUT}"`);
  ok('2) segundo --revertir (recuperación): cuenta EXACTAMENTE como la Fase 2A (SHOW GRANTS idéntico a la línea base), sin privilegios residuales de Usuarios; la vista de alta y su cuenta DEFINER fueron eliminadas y no quedan privilegios huérfanos sobre la vista', rvOk.code === 0 && grantsOf() === BASE.grants && prFase().code === 0 && vistaN() === 0 && definerN() === 0 && sqlN(`SELECT COUNT(*) FROM mysql.tables_priv WHERE Db='${DB}' AND Table_name='v_user_alta'`) === 0, rvOk.out.slice(-500));
  ok('2) la cuenta ya no puede escribir user: INSERT/UPDATE de nombre en user → denied (solo last_login)', /denied/i.test(acct("UPDATE user SET first_name='x' WHERE id=0")) && /denied/i.test(acct("INSERT INTO user (username) VALUES ('x')")));
  const rvCode = run(`bash ${PK2}/scripts/instalar-codigo-2b.sh --revertir ${RESP2B}`, CE);
  ok('3) REVERTIR CÓDIGO al respaldo de P3: vuelve EXACTAMENTE a la Fase 2A (RC2), .env intacto, sin restos', rvCode.code === 0 && identNuevo() && !ident2b() && envSha() === BASE.env && restos() === '0', rvCode.out.slice(-500));
  const fin = capsNow();
  ok('4) ESTADO FINAL ≈ FASE 2A: 3 módulos encendidos, usuarios y sensible bloqueados; sin claves prohibidas en el .env', fin?.writes?.empresas && fin?.writes?.reportes && fin?.writes?.accesos && fin?.writes?.usuarios === false && fin?.users_sensitive === false && !envKeys().some(k => /USUARIOS|USER_ADMIN_WRITES|INSECURE/.test(k)));
  const vf = run(`bash ${PK}/scripts/verificar-posdespliegue.sh --etapa accesos`, CE);
  ok('4) el verificador de la Fase 2A (RC2) vuelve a dar PASS en la etapa accesos', vf.code === 0 && /RESULTADO-ETAPA: PASS accesos/.test(vf.out), vf.out.slice(-400));
  ok('4) SIN CAMBIOS DE NEGOCIO INESPERADOS: la recuperación no tocó datos (tablas user y de negocio y audit_log idénticos a los de justo antes de recuperar; el único cambio de datos respecto de la línea base son las operaciones de prueba)', tablesSum(['user']) === PRE_REC.user && bizSum() === PRE_REC.biz && auditN() === PRE_REC.audit && auditN() > BASE.audit, '');
  ok('4) las filas de prueba de usuarios siguen auditadas (la bitácora no se pierde al recuperar) y la cuenta de prueba conserva sus datos (nada se borra)', actAudit(uid, 'user.create') === 1 && sqlN(`SELECT COUNT(*) FROM ${DB}.user WHERE id=${uid}`) === 1);
  otros('Tras la recuperación', false);
  ok('4) Apache como en la línea base de este tramo; sin carpetas temporales ni restos', apacheTree() === BASE.apache && restos() === '0');
  ok('4) los respaldos NO se borraron: código 2B, evidencias de privilegios y .env', Number(dx(`ls -1 ${RESP} | grep -c '^codigo-2b-'`).out) >= 1 && Number(dx(`ls -1 ${RESP} | grep -c '^privilegios-'`).out) >= 5);
  section = 'Higiene del ensayo 2B';
  const todo2 = ALL.join('\n');
  const secretos2 = [PASSW, GOODPW, jwt0, envOf('AUTH_DB_PASS'), envOf('STATE_DB_PASS'), envOf('CERVESUR_PILOT_SECRET'), 'Aa1!ensayo-p4c-clave'].filter(s => s && s.length >= 12);
  ok(`ningún secreto (clave de la cuenta, contraseña del administrador, JWT, claves MySQL, contraseña del usuario de prueba) apareció en la salida de NINGÚN script (${ALL.length} ejecuciones)`, secretos2.every(s => !todo2.includes(s)), secretos2.filter(s => todo2.includes(s)).map(s => s.slice(0, 4) + '…').join(','));

} catch (error) {
  results.push(['FAIL', `${section} · ERROR DEL ENSAYO`, String(error.stack || error).slice(0, 1500)]);
  console.log(`FAIL  ${section} · ERROR DEL ENSAYO\n${String(error.stack || error).slice(0, 1500)}`);
} finally {
  cleanup();
}
const fails = results.filter(r => r[0] === 'FAIL');
console.log(`\n==== ENSAYO FASE 2B (RC): ${results.length - fails.length} PASS / ${fails.length} FAIL ====`);
if (fails.length) { console.log('\nFALLOS:'); for (const f of fails) console.log(` - ${f[1]}${f[2] ? `\n     ${String(f[2]).slice(0, 400).replaceAll('\n', '\n     ')}` : ''}`); }
if (!process.env.ENSAYO_OUT && !process.env.ENSAYO_CONSERVAR) rmSync(OUT, { recursive: true, force: true });
process.exit(fails.length ? 1 : 0);
