// ENSAYO LOCAL (Docker) de la FASE 2A del administrador React (UX-08H.1). No se conecta a ningún servidor real ni toca nada fuera del contenedor desechable.
//   node deploy/produccion-escritura/ensayo-docker.mjs
//   ENSAYO_ADMIN_BASE_TGZ=<ruta al .tar.gz de la fase 1 (3f859210c5c4)>   (por defecto, el de paquetes-qa/produccion-v4)
//   ENSAYO_PAQUETE=<.tar.gz de la fase 2A ya construido> (por defecto lo construye empaquetar.mjs --permitir-arbol-sucio en ENSAYO_OUT)
//   ENSAYO_OUT=<carpeta externa> · ENSAYO_CONSERVAR=1 (no borra el contenedor)
// Réplica: Ubuntu 24.04 + Apache 2.4 + mod_php 8.3 + MySQL 8 (validate_password MEDIUM) con Flutter simulado (80), React de trabajadores señuelo (443, TLS) y QA señuelo (8094), y la base
// SINTÉTICA cervesur_portalbi (fixture de producción, nombre protegido por el código: la fase 2B queda bloqueada). Recorre, con el paquete BASE de la fase 1 realmente instalado:
//   preflight → actualizar --simular → inyecciones de fallo de A1 (restauración automática) → A1 real (comportamiento de la fase 1) → migraciones + cuenta (el ensayo hace de DBA) →
//   modo-escritura (A2: negativas, inyecciones y real) → Apache (PUT/DELETE) → interruptores (B: orden, evidencia, una escritura real con su fila de auditoría por módulo) → usuarios
//   rechazado (script y backend) → apagar-escrituras → revertir-codigo → comportamiento de la fase 1 de nuevo. Al final cuenta PASS/FAIL.
// Las migraciones y la cuenta de escritura las aplica el ensayo con los SQL del repositorio (hace de DBA); los scripts de migraciones/ los escribe otro equipo y se rehúsan aparte.
import { spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { cpSync, existsSync, mkdirSync, mkdtempSync, readdirSync, readFileSync, rmSync, statSync } from 'node:fs';
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

  // ------------------------------------------------------------------------------------------------ Calidad estática
  section = 'Calidad de los scripts';
  const scripts = readdirSync(join(here, 'scripts')).filter(f => f.endsWith('.sh'));
  ok('hay los scripts esperados del kit', ['preflight-fase2a', 'actualizar', 'modo-escritura', 'interruptores', 'apagar-escrituras', 'revertir-codigo', 'verificar-posdespliegue', 'comprobar-otros-portales', 'apache-metodos-escritura', 'lib', 'verificar-fase1', 'tls'].every(s => scripts.includes(`${s}.sh`)));
  ok('bash -n correcto en todos los scripts', scripts.every(f => spawnSync('bash', ['-n', join(here, 'scripts', f)]).status === 0));
  const sc = docker(['run', '--rm', '--volume', `${here}:/mnt:ro`, '--workdir', '/mnt', 'koalaman/shellcheck:latest', '-S', 'warning', '-x', '-P', 'scripts', ...scripts.map(f => `scripts/${f}`), 'ensayo/entrypoint.sh']);
  ok('shellcheck -S warning -x sin avisos en todos los scripts', sc.code === 0, sc.out.slice(0, 800));
  const prueba = spawnSync(process.execPath, [join(here, 'probar-empaquetado.mjs')], { encoding: 'utf8' });
  ok('probar-empaquetado.mjs: rutas de migraciones/repo y e0/repo, SHA256SUMS, plantilla y URL de trabajadores (fallan ante carpetas incorrectas o manifiestos ausentes)', prueba.status === 0, (prueba.stdout || '').split(String.fromCharCode(10)).filter(l => l.startsWith('FAIL')).join(' | '));
  ok('node --check de empaquetar.mjs, lib-empaquetado.mjs, probar-empaquetado.mjs y de este ensayo', ['empaquetar.mjs', 'lib-empaquetado.mjs', 'probar-empaquetado.mjs', 'ensayo-docker.mjs'].every(f => spawnSync(process.execPath, ['--check', join(here, f)]).status === 0));
  ok('ayuda (--ayuda) disponible en todos los scripts ejecutables', scripts.filter(f => !['lib.sh', 'tls.sh', 'verificar-fase1.sh'].includes(f)).every(f => { const r = spawnSync('bash', [join(here, 'scripts', f), '--ayuda'], { encoding: 'utf8' }); return r.status === 0 && r.stdout.length > 200; }));

  // ------------------------------------------------------------------------------------------------ Paquete
  section = 'Paquete';
  if (!existsSync(BASE_TGZ)) throw new Error(`Falta el paquete BASE de la fase 1: ${BASE_TGZ} (ENSAYO_ADMIN_BASE_TGZ)`);
  let tgz = process.env.ENSAYO_PAQUETE ? resolve(process.env.ENSAYO_PAQUETE) : null;
  if (!tgz) {
    const pack = spawnSync(process.execPath, [join(here, 'empaquetar.mjs'), '--host=192.168.200.12', '--out', OUT, '--permitir-arbol-sucio'], { cwd: REPO, encoding: 'utf8' });
    ok('empaquetar.mjs construye el paquete (frontend admin-write en copia temporal, hosts localhost + 192.168.200.12)', pack.status === 0, (pack.stderr || pack.stdout).slice(-600));
    tgz = newest(OUT, 'cervesur-react-admin-fase2a-');
  }
  if (!tgz) throw new Error('Falta el paquete de la fase 2A');
  const dirty = /-con-cambios-/.test(tgz);
  ok('con árbol sucio el nombre lleva «-con-cambios» y RELEASE.json declara produccion:false', !dirty || JSON.parse(readFileSync(`${tgz.replace(/\.tar\.gz$/, '')}.RELEASE.json`, 'utf8')).produccion === false);
  const rechazo = spawnSync(process.execPath, [join(here, 'empaquetar.mjs'), '--host=192.168.200.12', '--out', join(REPO, 'dist-prohibido')], { cwd: REPO, encoding: 'utf8' });
  ok('empaquetar.mjs rechaza una carpeta --out dentro del repositorio', rechazo.status !== 0 && /dentro del repositorio/.test(rechazo.stderr));
  const sucio = spawnSync(process.execPath, [join(here, 'empaquetar.mjs'), '--host=192.168.200.12', '--out', join(OUT, 'sin-permiso')], { cwd: REPO, encoding: 'utf8' });
  ok('sin --permitir-arbol-sucio se NIEGA a construir si el árbol tiene cambios (o construye si está limpio)', spawnSync('git', ['status', '--porcelain'], { cwd: REPO, encoding: 'utf8' }).stdout.trim() === '' ? sucio.status === 0 : (sucio.status !== 0 && /no está limpio/.test(sucio.stderr)), sucio.stderr.slice(-300));
  const dif = join(OUT, 'DIFERENCIAS-vs-3f859210c5c4.txt');
  ok('DIFERENCIAS-vs-3f859210c5c4.txt/.json existen y dicen si mode.php, AdminRouter.php y la puerta piloto_admin cambiaron', existsSync(dif) && existsSync(dif.replace('.txt', '.json')) && /backend\/lib\/mode\.php \.+ (CAMBIÓ|SIN CAMBIOS)/.test(readFileSync(dif, 'utf8')) && /puerta de rutas del modo piloto_admin \.+ (CAMBIÓ|SIN CAMBIOS)/.test(readFileSync(dif, 'utf8')));

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
  ok('el instalador REAL de la fase 1 deja el administrador (8093, piloto_admin) funcionando', inst.code === 0 && /\d+ PASS \/ 0 FAIL/.test(inst.out), inst.out.slice(-700));
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

  section = 'Usuarios (fase 2B) · bloqueo en el kit y en el backend';
  const estOn = run(`bash ${PK}/scripts/interruptores.sh --estado`, CE);
  ok('--estado: los tres módulos ENCENDIDOS y verificados; usuarios BLOQUEADO', estOn.code === 0 && ['empresas', 'reportes', 'accesos'].every(m => new RegExp(`${m}\\s+ENCENDIDO\\s+sí`).test(estOn.out)) && /usuarios\s+BLOQUEADO/.test(estOn.out), estOn.out);
  const envBueno = envSha();
  dx(`cp -p ${ENVF} /root/env-bueno && printf 'ADMIN_WRITES_USUARIOS=1\\nADMIN_WRITES_USUARIOS_SENSIBLE=1\\n' >> ${ENVF}`);
  const tU = tokenOf(login(lAdm, GOODPW));
  const capU = callRaw('GET', '/api/admin/capacidades', tU);
  ok('con el .env EDITADO A MANO con ADMIN_WRITES_USUARIOS=1 y SENSIBLE=1 el backend sigue negando: capacidades usuarios=false y users_sensitive=false', capU.status === 200 && capU.json?.data?.writes?.usuarios === false && capU.json?.data?.users_sensitive === false, capU.raw);
  const rsU2 = USERS.map(([me, p]) => callRaw(me, p, tU, '{}'));
  ok('…y las 6 rutas de usuarios responden 403 «fase_2b_bloqueada» (puerta de rutas; los PUT: backend o Apache)', rsU2.every(r => r.status === 403) && rsU2.filter((_, i) => USERS[i][0] === 'POST').every(r => codeOf(r) === 'fase_2b_bloqueada') && rsU2.filter((_, i) => USERS[i][0] === 'PUT').every(r => ['fase_2b_bloqueada'].includes(codeOf(r))), rsU2.map(codeOf).join(','));
  const nUser = sqlN(`SELECT COUNT(*) FROM ${DB}.user`);
  callRaw('POST', '/api/register', tU, JSON.stringify({ username: 'ensayo_fase2b', email: 'f2b@example.invalid', password: 'Aa1!ensayo-fase2b', first_name: 'x', last_name: 'y', role: 'user' }));
  ok('…y un alta de usuario no escribió nada (la tabla user sigue igual)', sqlN(`SELECT COUNT(*) FROM ${DB}.user`) === nUser && tablesSum(['user']) === CK_USER0);
  const estBad = run(`bash ${PK}/scripts/interruptores.sh --estado`, CE);
  ok('el kit marca como ERROR las claves ADMIN_WRITES_USUARIOS* halladas en el .env (--estado sale con error)', estBad.code !== 0 && /ERROR: el \.env contiene claves prohibidas: .*ADMIN_WRITES_USUARIOS/.test(estBad.out));
  const verBad = run(`bash ${PK}/scripts/verificar-posdespliegue.sh --etapa accesos`, CE);
  ok('verificar-posdespliegue FALLA mientras el .env tenga claves de usuarios', verBad.code !== 0 && /claves PROHIBIDAS/.test(verBad.out) && /RESULTADO-ETAPA: FAIL/.test(verBad.out));
  const habBad = run(`bash ${PK}/scripts/interruptores.sh --apagar empresas`, CE);
  ok('interruptores se NIEGA a operar con claves de usuarios en el .env', habBad.code !== 0 && /claves prohibidas/.test(habBad.out));
  const preBad = run(`bash ${PK}/scripts/preflight-fase2a.sh`, CE);
  ok('preflight-fase2a también las marca FAIL', preBad.code !== 0 && /claves prohibidas en el \.env/.test(preBad.out));
  dx(`cp -p /root/env-bueno ${ENVF}`);
  ok('(se restituye el .env bueno para seguir)', envSha() === envBueno);

  // ------------------------------------------------------------------------------------------------ Apagado
  section = 'Apagado de emergencia';
  const dataAntes = { audit: sqlN(`SELECT COUNT(*) FROM ${DB}.audit_log`), ck: tablesSum(['user', 'empresa', 'reporte', 'empresa_reporte', 'empresa_usuario', 'user_empresa_reporte']), auditSum: sql(`SELECT SHA2(GROUP_CONCAT(id, action ORDER BY id), 256) FROM ${DB}.audit_log`) };
  dx(`printf 'ADMIN_WRITES_USUARIOS=1\\n' >> ${ENVF}`);
  const t0ap = Date.now();
  const off = run(`bash ${PK}/scripts/apagar-escrituras.sh`, CE);
  const seg = (Date.now() - t0ap) / 1000;
  ok(`apagar-escrituras.sh: termina en ${seg.toFixed(1)} s, ADMIN_WRITES_* en 0, elimina las claves de usuarios, verifica capacidades (todo false)`, off.code === 0 && seg < 30 && /verificado: \/api\/admin\/capacidades → todo false/.test(off.out) && ['EMPRESAS', 'REPORTES', 'ACCESOS'].every(m => envOf(`ADMIN_WRITES_${m}`) === '0') && !envKeys().some(k => /USUARIOS/.test(k)), off.out.slice(-600));
  const tOff = tokenOf(login(lAdm, GOODPW));
  ok('tras apagar: todas las escrituras vuelven a 403 «escritura_desactivada» y las lecturas siguen', ['empresas', 'reportes', 'accesos'].every(k => RUTAS[k].filter(([me]) => me === 'POST').every(([me, p]) => codeOf(callRaw(me, p, tOff, '{}')) === 'escritura_desactivada')) && callRaw('GET', '/api/empresas?view=minimal&per_page=1&page=1', tOff).status === 200);
  const ev = dx(`d=$(ls -1d ${RESP}/evidencia-apagado-* | tail -1); echo $d; stat -c %a $d; ls $d; grep -c . $d/estado-anterior.txt`).out.split('\n');
  ok('evidencia en evidencia-apagado-<sello> (700) con el estado anterior y el log de Apache sanitizado; sin ningún secreto', /evidencia-apagado-/.test(ev[0]) && ev[1] === '700' && /estado-anterior\.txt/.test(ev.join(' ')) && dx(`! grep -rqF -e '${PASSW}' -e '${envOf('JWT_SECRET')}' -e '${envOf('AUTH_DB_PASS')}' ${ev[0]}`).code === 0);
  ok('apagar NO toca datos ni auditoría: mismas filas de audit_log y mismos CHECKSUM de las 6 tablas de negocio', sqlN(`SELECT COUNT(*) FROM ${DB}.audit_log`) === dataAntes.audit && tablesSum(['user', 'empresa', 'reporte', 'empresa_reporte', 'empresa_usuario', 'user_empresa_reporte']) === dataAntes.ck && sql(`SELECT SHA2(GROUP_CONCAT(id, action ORDER BY id), 256) FROM ${DB}.audit_log`) === dataAntes.auditSum);
  const off2 = run(`bash ${PK}/scripts/apagar-escrituras.sh`, { CERVESUR_E_USUARIO: '', CERVESUR_E_CLAVE: '' });
  ok('apagar es idempotente y, sin credenciales, DICE que la verificación se omitió', off2.code === 0 && /verificación de capacidades se OMITIÓ/.test(off2.out));
  const reHab = run(`bash ${PK}/scripts/interruptores.sh --habilitar empresas`, CE);
  ok('tras el apagado se puede volver a encender un módulo ya verificado (empresas) y apagarlo con --apagar-todo', reHab.code === 0 && run(`bash ${PK}/scripts/interruptores.sh --apagar-todo`, CE).code === 0 && ['EMPRESAS', 'REPORTES', 'ACCESOS'].every(m => envOf(`ADMIN_WRITES_${m}`) === '0'));

  // ------------------------------------------------------------------------------------------------ Revertir código
  section = 'Revertir el código (clase 1)';
  const envAdminEsc = envSha();
  dx(`mv ${RESP}/env/ultimo-A2-previo /root/puntero-A2`);
  const revSin = run(`bash ${PK}/scripts/revertir-codigo.sh`, { ...CE, ...YES });
  ok('con el .env en admin_escritura y SIN respaldo válido del .env de piloto_admin, revertir-codigo se NIEGA, explica y no modifica nada', revSin.code !== 0 && /no lo conoce/.test(revSin.out) && identNuevo() && envSha() === envAdminEsc, revSin.out.slice(-600));
  dx(`mv /root/puntero-A2 ${RESP}/env/ultimo-A2-previo`);
  const revSim = run(`bash ${PK}/scripts/revertir-codigo.sh --simular`, CE);
  ok('revertir-codigo --simular: describe qué restauraría (código + .env piloto_admin) y no escribe nada', revSim.code === 0 && /SIMULACIÓN terminada/.test(revSim.out) && identNuevo() && envSha() === envAdminEsc);
  const backupsPre = backupsAntes(); const envBk = envBackups();
  const rev = run(`bash ${PK}/scripts/revertir-codigo.sh`, { ...CE, ...YES });
  ok('revertir-codigo: restaura public/ y backend/ de la fase 1 Y el .env de piloto_admin, verifica el comportamiento de la fase 1', rev.code === 0 && /REVERSIÓN COMPLETA/.test(rev.out) && /idéntico a la fase 1/.test(rev.out), rev.out.slice(-900));
  ok('lo instalado es EXACTAMENTE la fase 1 (archivo por archivo); el .env es byte a byte el de la fase 1', identBase() && envSha() === ENV0 && envOf('CERVESUR_MODE') === 'piloto_admin');
  fase1('Tras revertir-codigo');
  ok('revertir NO borró ningún respaldo (código ni .env; se añadió el del .env actual) y NO tocó la base: audit_log conserva sus 3 filas', backupsPre.split(' ').filter(Boolean).every(d => backupsAntes().includes(d)) && Number(envBackups()) >= Number(envBk) + 1 && sqlN(`SELECT COUNT(*) FROM ${DB}.audit_log`) === 3 && tablesSum(['user']) === CK_USER0);
  otros('Tras revertir', false);
  ok('Apache: solo difiere de la línea base por el cambio de métodos que el operador autorizó (revertir-codigo no lo toca)', apacheTree() !== AP0 ? dx('grep -c "GET POST PUT DELETE" /etc/apache2/cervesur-react-admin/react-usuarios-common.conf').out === '1' : true);
  const apR2 = run(`bash ${PK}/scripts/apache-metodos-escritura.sh --revertir`, { ...CE, ...YES });
  ok('el cambio opcional de Apache se deshace aparte (--revertir) y Apache queda byte a byte como al inicio', apR2.code === 0 && apacheTree() === AP0);
  section = 'Higiene';
  const todo = ALL.join('\n');
  const secretos = [PASSW, GOODPW, jwt0, envOf('AUTH_DB_PASS'), envOf('STATE_DB_PASS'), envOf('CERVESUR_PILOT_SECRET'), dx(`cat ${RESP}/env/$(ls -1 ${RESP}/env | grep '^env-' | head -1) | awk -F= '$1=="JWT_SECRET"{print $2}'`).out].filter(s => s && s.length >= 12);
  ok(`ningún secreto (clave de la cuenta, contraseña del administrador, JWT, claves MySQL, secreto del piloto) apareció en la salida de NINGÚN script (${ALL.length} ejecuciones)`, secretos.every(s => !todo.includes(s)), secretos.filter(s => todo.includes(s)).map(s => s.slice(0, 4) + '…').join(','));
  ok('los respaldos del .env son 600 y todos figuran en el manifiesto con SHA-256 válido', dx(`cd ${RESP}/env && [ "$(stat -c %a env-* | sort -u)" = 600 ] && for f in env-*; do grep -F "$f" MANIFIESTO.txt | head -1 | cut -f2 | sha256sum -c --quiet || exit 1; done`).code === 0);
  ok('no quedan temporales .env.nuevo / .nuevo- / .previo- / .restaurar- en el backend ni en la aplicación', dx(`ls -a ${FRONT} /opt/cervesur-react-admin /opt/cervesur-react-admin/backend | grep -E '^\\.(env\\.nuevo|nuevo-|previo-|restaurar-|env\\.apagado)' | wc -l`).out === '0');
} catch (error) {
  results.push(['FAIL', `${section} · ERROR DEL ENSAYO`, String(error.stack || error).slice(0, 1500)]);
  console.log(`FAIL  ${section} · ERROR DEL ENSAYO\n${String(error.stack || error).slice(0, 1500)}`);
} finally {
  cleanup();
}
const fails = results.filter(r => r[0] === 'FAIL');
console.log(`\n==== ENSAYO FASE 2A: ${results.length - fails.length} PASS / ${fails.length} FAIL ====`);
if (fails.length) { console.log('\nFALLOS:'); for (const f of fails) console.log(` - ${f[1]}${f[2] ? `\n     ${String(f[2]).slice(0, 400).replaceAll('\n', '\n     ')}` : ''}`); }
if (!process.env.ENSAYO_OUT && !process.env.ENSAYO_CONSERVAR) rmSync(OUT, { recursive: true, force: true });
process.exit(fails.length ? 1 : 0);
