// ENSAYO LOCAL (Docker) del entorno de ESCRITURA ADMINISTRATIVA de QA (UX-08H, modo admin_escritura). No se conecta a ningún servidor real.
//   node deploy/qa-escritura/ensayo-docker.mjs                       (compila el frontend admin-write en una COPIA temporal y empaqueta fuera del repositorio)
//   ENSAYO_BUNDLE=<dir> node deploy/qa-escritura/ensayo-docker.mjs  (reutiliza una compilación admin-write con «localhost» autorizado)
//   ENSAYO_OUT=<carpeta> · ENSAYO_CONSERVAR=1 (no borra el contenedor) · ENSAYO_PORT=18096 (puerto del anfitrión que emula el túnel SSH) · ENSAYO_QA_PORT=8095
// Réplica del servidor: Ubuntu 24.04 + Apache 2.4 + mod_php 8.3 + MySQL 8 (validate_password MEDIUM, binlog) con Flutter simulado en el 80 y portales SEÑUELO en 443/8443 (TLS),
// 8088, 8089 y 8090-8094, más la base señuelo cervesur_portalbi. Recorre: instalar → verificar → escritura real por la API → interruptores → actualizar (--simular, real, fallo forzado con
// vuelta automática, respaldo corrupto, paquete roto) → revertir la actualización → desinstalar, y comprueba que Flutter, los directorios de 443/8443 y la base señuelo quedan IDÉNTICOS.
import { spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { mkdtempSync, readdirSync, rmSync, statSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const REPO = resolve(here, '..', '..');
const BASE_IMAGE = 'cervesur-ensayo-ubuntu:local';
const IMAGE = 'cervesur-ensayo-qa-escritura:local';
const NAME = 'cervesur-ensayo-qa-escritura';
const PORT = Number(process.env.ENSAYO_PORT ?? 18096);
const QA = Number(process.env.ENSAYO_QA_PORT ?? 8095); // puerto CANDIDATO; 8090-8094 están ocupados en el servidor real (y en la réplica)
const BASE = `http://127.0.0.1:${PORT}`;
const OUT = process.env.ENSAYO_OUT ? resolve(process.env.ENSAYO_OUT) : mkdtempSync(join(tmpdir(), 'cervesur-ensayo-qa-escritura-'));
const DB = 'cervesur_qa_escritura'; const DBE = 'cervesur_qa_escritura_estado';
const CUENTAS = ['cervesur_qa_esc_w', 'cervesur_qa_esc_auth', 'cervesur_qa_esc_cat', 'cervesur_qa_esc_estado'];
const INST = '/opt/cervesur-react-escritura-qa/backend'; const PUB = '/var/www/cervesur-react-escritura-qa/public';
const results = [];
let section = '';
const ok = (name, cond, extra = '') => results.push([cond ? 'PASS' : 'FAIL', `${section} · ${name}`, cond ? '' : extra]);
const docker = (args, { allowFail = true, input } = {}) => {
  const r = spawnSync('docker', args, { encoding: 'utf8', maxBuffer: 256 * 1024 * 1024, input, env: { ...process.env, MSYS_NO_PATHCONV: '1' } });
  if (r.status !== 0 && !allowFail) throw new Error(`docker ${args.slice(0, 3).join(' ')} falló:\n${r.stderr || r.stdout}`);
  return { code: r.status, out: `${r.stdout ?? ''}${r.stderr ?? ''}`.trim() };
};
const dx = (cmd, env = {}) => docker(['exec', ...Object.entries(env).flatMap(([k, v]) => ['--env', `${k}=${v}`]), NAME, 'bash', '-c', cmd]);
const put = (path, text) => spawnSync('docker', ['exec', '-i', NAME, 'bash', '-c', `cat > ${path}`], { input: text, env: { ...process.env, MSYS_NO_PATHCONV: '1' } });
const sleep = ms => new Promise(r => setTimeout(r, ms));
const cleanup = () => docker(['rm', '--force', '--volumes', NAME]);
const sqlx = query => { put('/tmp/q.sql', query); const r = dx('mysql --default-character-set=utf8mb4 -N -B < /tmp/q.sql'); if (r.code !== 0) throw new Error(`SQL falló: ${query}\n${r.out}`); return r.out; };
const newest = prefix => readdirSync(OUT).filter(f => f.startsWith(prefix) && f.endsWith('.tar.gz')).map(f => join(OUT, f)).sort((a, b) => statSync(b).mtimeMs - statSync(a).mtimeMs)[0];

const api = async (method, path, { token, body } = {}) => {
  const res = await fetch(BASE + path, { method, headers: { ...(body ? { 'Content-Type': 'application/json' } : {}), ...(token ? { Authorization: `Bearer ${token}` } : {}) }, body: body ? JSON.stringify(body) : undefined });
  const raw = await res.text(); let json = null; try { json = JSON.parse(raw); } catch { /* no JSON */ }
  return { status: res.status, json, raw, headers: res.headers };
};
const login = async (u, p) => (await api('POST', '/api/login', { body: { login: u, password: p } })).json;

// Instantánea INDEPENDIENTE de los scripts del paquete. Modo «ajenos»: excluye lo que pertenece al entorno nuevo. Modo «estricto»: no excluye nada.
const SNAP = String.raw`set -u
MODE="$1"
F() { if [ "$MODE" = ajenos ]; then grep -v -E 'cervesur-react-escritura-qa|cervesur_qa_escritura|cervesur_qa_esc_|:__QA__$'; else cat; fi; }
echo "== apache (archivos y enlaces, byte a byte)"
find /etc/apache2 \( -type f -o -type l \) | F | LC_ALL=C sort | while read -r f; do if [ -L "$f" ]; then echo "L $f $(readlink "$f")"; else sha256sum "$f"; fi; done
echo "== arboles de portales (metadatos)"
find /var/www /opt -type f -printf '%p %s %T@ %m %u:%g\n' | F | LC_ALL=C sort
echo "== contenido de los señuelos y de Flutter"
sha256sum /var/www/cervesur/.htaccess /var/www/cervesur/frontend/index.html /var/www/cervesur/backend/api.php /var/www/cervesur-react-*/public/index.html /opt/cervesur-react-*/backend/.env | F
echo "== carpetas"
for d in /var/www /opt /var/lib /etc/apache2 /etc/apache2/sites-available /etc/apache2/sites-enabled /etc/apache2/conf-enabled /etc/apache2/mods-enabled; do echo "[$d]"; ls -A "$d" | F | LC_ALL=C sort; done
echo "== mysql: bases, cuentas, privilegios, checksums"
mysql -N -e 'SHOW DATABASES' | F | LC_ALL=C sort
mysql -N -e "SELECT user, host FROM mysql.user" | F | LC_ALL=C sort
mysql -N -e "SELECT CONCAT(QUOTE(user), '@', QUOTE(host)) FROM mysql.user" | F | LC_ALL=C sort | while read -r a; do echo "## $a"; mysql -N -e "SHOW GRANTS FOR $a" | LC_ALL=C sort; done
for db in cervesur_portalbi cervesur_react_prueba cervesur_react_compat_qa; do for t in $(mysql -N -e "SHOW TABLES" "$db"); do mysql -N -e "CHECKSUM TABLE $t" "$db"; done; done
echo "== puertos"; ss -ltnH | awk '{print $4}' | F | LC_ALL=C sort -u
echo "== modulos"; apache2ctl -M 2>/dev/null | LC_ALL=C sort | sha256sum
echo "== respuestas HTTP(S) de los demás portales"
for u in http://127.0.0.1/ http://127.0.0.1/ruta/profunda http://127.0.0.1:8088/ http://127.0.0.1:8089/ https://127.0.0.1:8443/ https://127.0.0.1:443/; do echo "$u"; curl -k -sS -m 6 -o /dev/null -D - "$u" | tr -d '\r' | awk 'NR==1{print;next} /^[A-Za-z-]+:/{print tolower($0)}' | grep -v -E '^(date|etag|last-modified):' | LC_ALL=C sort; curl -k -sS -m 6 "$u" | sha256sum; done
`.replace('__QA__', String(QA));

try {
  cleanup();
  section = 'Paquete';
  const bundle = process.env.ENSAYO_BUNDLE ? ['--bundle', process.env.ENSAYO_BUNDLE] : [];
  const distAntes = docker(['version']).code === 0 ? spawnSync(process.execPath, ['-e', `const fs=require('fs');const p=${JSON.stringify(join(REPO, 'frontend', 'dist-admin-write', 'build-info.json'))};console.log(fs.existsSync(p)?fs.statSync(p).mtimeMs:'none')`], { encoding: 'utf8' }).stdout.trim() : '';
  const pack = spawnSync(process.execPath, [join(here, 'empaquetar.mjs'), '--out', OUT, ...bundle], { cwd: REPO, encoding: 'utf8' });
  ok('empaquetar.mjs construye frontend admin-write (localhost) + backend + SQL + scripts en un único .tar.gz con SHA256SUMS, fuera del repositorio', pack.status === 0 && !OUT.startsWith(REPO), (pack.stderr || pack.stdout).slice(-500));
  const distDespues = spawnSync(process.execPath, ['-e', `const fs=require('fs');const p=${JSON.stringify(join(REPO, 'frontend', 'dist-admin-write', 'build-info.json'))};console.log(fs.existsSync(p)?fs.statSync(p).mtimeMs:'none')`], { encoding: 'utf8' }).stdout.trim();
  ok('frontend/dist-admin-write NO se modificó al empaquetar (la compilación se hizo en una copia temporal)', distAntes === distDespues, `${distAntes} → ${distDespues}`);
  const tgz = newest('cervesur-react-escritura-qa-');
  if (!tgz) throw new Error('Falta el paquete');
  const rejects = spawnSync(process.execPath, [join(here, 'empaquetar.mjs'), '--sin-compilar', '--out', join(REPO, 'dist-paquete-prohibido')], { cwd: REPO, encoding: 'utf8' });
  ok('empaquetar.mjs rechaza una carpeta --out dentro del repositorio', rejects.status !== 0 && /dentro del repositorio/.test(rejects.stderr));

  section = 'Imagen de ensayo';
  if (docker(['image', 'inspect', BASE_IMAGE]).code !== 0) docker(['build', '--quiet', '--tag', BASE_IMAGE, '--file', join(here, 'ensayo', 'Dockerfile.base'), join(here, 'ensayo')], { allowFail: false });
  docker(['build', '--quiet', '--build-arg', `BASE=${BASE_IMAGE}`, '--tag', IMAGE, join(here, 'ensayo')], { allowFail: false });
  docker(['run', '--detach', '--name', NAME, '--env', `QA_PORT=${QA}`, '--publish', `127.0.0.1:${PORT}:18092`, IMAGE], { allowFail: false });
  for (let i = 0; ; i++) { if (dx('curl -fs http://127.0.0.1/ >/dev/null && curl -fs http://127.0.0.1:8088/ >/dev/null && curl -ksf https://127.0.0.1:8443/ >/dev/null && curl -ksf https://127.0.0.1:443/ >/dev/null && mysqladmin ping --silent').code === 0) break; if (i > 90) throw new Error('El contenedor no arrancó'); await sleep(1000); }
  ok('la réplica arranca con Flutter (80), señuelos 443/8443 (TLS), 8088, 8089 y 8090-8094 y la base señuelo cervesur_portalbi', dx('mysql -N -e "SHOW DATABASES" | grep -qx cervesur_portalbi').code === 0);
  docker(['cp', tgz, `${NAME}:/root/qa.tar.gz`], { allowFail: false });
  const PK = `/root/${dx('cd /root && tar xzf qa.tar.gz && ls -d cervesur-react-escritura-qa-*/ | tr -d /').out.split('\n').pop()}`;
  ok('el paquete se extrae y su SHA256SUMS es válido', dx(`cd ${PK} && sha256sum -c SHA256SUMS --quiet`).code === 0);
  ok('LF en scripts y Apache; sin .env (solo la plantilla sin valores), sin pruebas, sin claves privadas; trae las SQL de cuentas, estado y la plantilla de escritura', dx(`! grep -rlI $'\\r' ${PK}/scripts ${PK}/apache && [ "$(find ${PK} -name ".env*" | wc -l)" = 0 ] && [ -f ${PK}/env-qa-escritura.example ] && ! find ${PK}/backend -ipath "*tests*" | grep -q . && ! grep -rIl -e "BEGIN .*PRIVATE KEY" ${PK} && ls ${PK}/database/schema/piloto-admin/01-cuentas-lectura.sql ${PK}/database/schema/piloto-admin/02-estado-base-y-cuenta.sql ${PK}/database/privileges/UX-08H-cuenta-escritura-produccion.sql.template ${PK}/database/migrations/UX-08B-audit-log-immutable.up.sql >/dev/null && grep -q '"pilotMode": "admin-write"' ${PK}/public/build-info.json && grep -q localhost ${PK}/public/build-info.json`).code === 0);

  section = 'Calidad de los scripts';
  const sc = docker(['run', '--rm', '--volume', `${here}:/mnt:ro`, '--workdir', '/mnt', 'koalaman/shellcheck:latest', '-S', 'warning', '-x', '-P', 'scripts', ...['lib', 'instalar', 'pasos-instalacion', 'revertir', 'verificar', 'actualizar', 'interruptor-escrituras', 'comprobar-portales-publicados'].map(s => `scripts/${s}.sh`), 'ensayo/entrypoint.qa.sh']);
  ok('shellcheck -S warning (koalaman/shellcheck:latest) sin avisos en los 8 scripts y el entrypoint', sc.code === 0, sc.out.slice(0, 500));
  ok('bash -n y php -l correctos', dx(`for f in ${PK}/scripts/*.sh; do bash -n $f || exit 1; done; php -l ${PK}/scripts/sembrar-qa-escritura.php`).code === 0);

  const apachePid = () => dx('cat /var/run/apache2/apache2.pid').out;
  put('/root/snap.sh', SNAP);
  const snap = (mode = 'ajenos') => dx(`bash /root/snap.sh ${mode}`).out;
  const estadoLimpio = () => dx(`for p in /var/www/cervesur-react-escritura-qa /opt/cervesur-react-escritura-qa /etc/apache2/cervesur-react-escritura-qa /etc/apache2/sites-available/cervesur-react-escritura-qa.conf /etc/apache2/sites-enabled/cervesur-react-escritura-qa.conf /var/lib/cervesur-react-escritura-qa; do test -e $p && exit 1; done; [ -z "$(ss -ltnH "sport = :${QA}")" ] && [ -z "$(mysql -N -e "SHOW DATABASES LIKE 'cervesur_qa_escritura%'")" ] && [ "$(mysql -N -e "SELECT COUNT(*) FROM mysql.user WHERE user LIKE 'cervesur_qa_esc%'")" = 0 ]`).code === 0;
  const instalar = (extra = '', env = {}) => dx(`bash ${PK}/scripts/instalar.sh ${/--puerto/.test(extra) ? '' : `--puerto ${QA} `}${extra}`, { CERVESUR_QAE_CONFIRMAR: 'SI', ...env });
  const pid0 = apachePid();
  const S0 = snap('estricto'); const S0a = snap('ajenos');
  const portalbi0 = sqlx('CHECKSUM TABLE cervesur_portalbi.clientes_senuelo, cervesur_portalbi.usuarios_senuelo');
  const flutter0 = dx('cd /var/www/cervesur && find . -type f -print0 | sort -z | xargs -0 sha256sum | sha256sum; find /var/www/cervesur-react-trabajadores /var/www/cervesur-react-admin /opt/cervesur-react-trabajadores /opt/cervesur-react-admin -type f -print0 | sort -z | xargs -0 sha256sum | sha256sum').out;
  ok('línea base independiente tomada (Apache byte a byte, árboles, Flutter, 443/8443, bases/cuentas/privilegios, checksums de cervesur_portalbi, puertos, módulos y respuestas)', S0.length > 3000 && /== respuestas/.test(S0) && /cervesur_portalbi/.test(S0) && S0 === S0a && flutter0.length > 60, `${S0.length}`);

  section = 'Preflight y negativas (todo ANTES de instalar: sin cambios)';
  const sim = instalar('--simular');
  ok('--simular supera todas las comprobaciones, resume puerto/bases/cuentas/escrituras y termina sin modificar nada', sim.code === 0 && /Modo --simular: fin/.test(sim.out) && /RESULTADO: GO/.test(sim.out) && /Bases QA\s+cervesur_qa_escritura \+ cervesur_qa_escritura_estado/.test(sim.out) && /cervesur_qa_esc_w \(escritura\)/.test(sim.out) && estadoLimpio() && snap('estricto') === S0 && dx('test -e /var/backups/cervesur-react-escritura-qa').code !== 0, sim.out.slice(-600));
  const binlog0 = sqlx('SHOW MASTER STATUS'); instalar('--simular'); ok('--simular no escribe ni una sentencia en MySQL (posición del binlog idéntica)', binlog0 === sqlx('SHOW MASTER STATUS') && /^binlog\.\d+\t\d+/.test(binlog0), binlog0);
  dx(`rm -rf /root/p-tamper && cp -r ${PK} /root/p-tamper && echo x >> /root/p-tamper/public/index.html`);
  const tamper = dx(`bash /root/p-tamper/scripts/instalar.sh --puerto ${QA}`, { CERVESUR_QAE_CONFIRMAR: 'SI' });
  ok('un paquete alterado (SHA-256 no coincide) se rechaza antes de instalar', tamper.code !== 0 && /integridad del paquete/.test(tamper.out) && estadoLimpio() && snap('estricto') === S0);
  const sinPuerto = dx(`bash ${PK}/scripts/instalar.sh --simular`);
  ok('sin --puerto el instalador se NIEGA a elegir uno y no modifica nada', sinPuerto.code !== 0 && /Falta el puerto/.test(sinPuerto.out) && estadoLimpio() && snap('estricto') === S0);
  const rp = [8090, 8091, 8092, 8093, 8094, 80, 443, 8443, 8088, 8089, 22, 3306].map(p => [p, instalar(`--simular --puerto ${p}`)]);
  ok('los puertos 8090-8093 (ocupados), 8094 (QA administrativo aislado), 80, 443, 8443, 8088, 8089, ssh y MySQL se RECHAZAN con NO-GO y sin cambios', rp.every(([p, r]) => r.code !== 0 && new RegExp(`RECHAZADO: el puerto ${p}`).test(r.out)) && snap('estricto') === S0, rp.map(([p, r]) => `${p}:${r.code}`).join(' '));
  const real8094 = instalar('--puerto 8094');
  ok('la instalación REAL con --puerto 8094 también se rechaza antes de cualquier cambio', real8094.code !== 0 && /RECHAZADO/.test(real8094.out) && estadoLimpio() && dx('test -e /var/backups/cervesur-react-escritura-qa').code !== 0);
  const malos = ['abc', '1023', '65536', '08095', ' 8095', '8095;touch /tmp/inyectado', '8095$(touch /tmp/inyectado)'];
  const rmalos = malos.map(m => dx(`bash ${PK}/scripts/instalar.sh --simular --puerto '${m}'`));
  ok('puertos mal formados (letras, <1024, >65535, ceros, espacios, inyección de órdenes) se rechazan sin ejecutar nada', rmalos.every(r => r.code !== 0 && /Puerto no válido/.test(r.out)) && dx('test ! -e /tmp/inyectado').code === 0 && snap('estricto') === S0);
  const escBad = ['empresas,empresas', 'borrar', 'usuarios,'].map(l => instalar(`--simular --escrituras '${l}'`));
  ok('--escrituras con valores repetidos, desconocidos o vacíos se rechaza sin cambios', escBad.every(r => r.code !== 0 && /--escrituras debe ser/.test(r.out)) && snap('estricto') === S0);
  for (const [label, dbName] of [['cervesur_portalbi como nombre de base', 'cervesur_portalbi'], ['nombre que contiene «portalbi»', 'qa_portalbi_copia']]) {
    const r = dx(`bash ${PK}/scripts/instalar.sh --puerto ${QA}`, { CERVESUR_QAE_CONFIRMAR: 'SI', CERVESUR_QAE_DB_NOMBRE: dbName });
    ok(`${label}: RECHAZADO antes de cualquier cambio`, r.code !== 0 && /RECHAZADO/.test(r.out) && estadoLimpio() && dx('test -e /var/backups/cervesur-react-escritura-qa').code !== 0);
  }
  const hex = randomBytes(6).toString('hex');
  const collisions = [
    ['la base de estado cervesur_qa_escritura_estado ya existe', "mysql -e 'CREATE DATABASE cervesur_qa_escritura_estado'", "mysql -e 'DROP DATABASE IF EXISTS cervesur_qa_escritura_estado'", /las bases cervesur_qa_escritura y cervesur_qa_escritura_estado no existen todavía/],
    ['la cuenta cervesur_qa_esc_auth ya existe (otro host)', `mysql -e "CREATE USER 'cervesur_qa_esc_auth'@'localhost' IDENTIFIED BY 'Aa1!${hex}'"`, "mysql -e \"DROP USER IF EXISTS 'cervesur_qa_esc_auth'@'localhost'\"", /ninguna de las cuentas MySQL/],
    ['la carpeta del backend /opt/cervesur-react-escritura-qa ya existe', 'mkdir /opt/cervesur-react-escritura-qa', 'rmdir /opt/cervesur-react-escritura-qa', /no existe \/opt\/cervesur-react-escritura-qa/],
    [`el puerto ${QA} está ocupado`, `setsid nohup socat TCP-LISTEN:${QA},bind=127.0.0.1,fork,reuseaddr SYSTEM:true >/dev/null 2>&1 & sleep 1`, `pkill -f 'TCP-LISTEN:${QA}' || true`, new RegExp(`puerto ${QA} libre`)]
  ];
  for (const [label, arm, disarm, pattern] of collisions) {
    dx(arm); const r = instalar(); dx(disarm); await sleep(300);
    ok(`colisión (${label}): ABORTA con «No se modificó nada», sin respaldos ni marcas`, r.code !== 0 && pattern.test(r.out) && /No se modificó nada/.test(r.out) && dx('test -e /var/backups/cervesur-react-escritura-qa').code !== 0, r.out.slice(-300));
  }
  // Los portales publicados NO pueden tener escrituras ni admin_escritura encendidos.
  for (const [label, linea] of [['USER_ADMIN_WRITES=1', 'USER_ADMIN_WRITES=1'], ['ADMIN_WRITES_USUARIOS=1', 'ADMIN_WRITES_USUARIOS=1'], ['CERVESUR_MODE=admin_escritura', 'CERVESUR_MODE=admin_escritura']]) {
    dx(`cp -p /opt/cervesur-react-admin/backend/.env /root/env-admin.bak && printf "${linea}\\n" >> /opt/cervesur-react-admin/backend/.env`);
    const r = instalar('--simular'); dx('cp -p /root/env-admin.bak /opt/cervesur-react-admin/backend/.env');
    ok(`con ${label} en un portal publicado (8443) la simulación da NO-GO sin modificar nada`, r.code !== 0 && /ENCENDIDO/.test(r.out) && /RESULTADO: NO-GO/.test(r.out) && estadoLimpio() && snap('estricto') === S0, r.out.slice(-400));
  }
  dx('cp -p /opt/cervesur-react-admin/backend/.env /root/env-admin.bak && printf "# SetEnv ADMIN_WRITES_USUARIOS 0\\n" > /etc/apache2/conf-available/zz-w.conf');
  const apacheMention = instalar('--simular'); dx('rm -f /etc/apache2/conf-available/zz-w.conf');
  ok('una mención de ADMIN_WRITES_ en la configuración de Apache también es NO-GO (no se puede demostrar apagado)', apacheMention.code !== 0 && /no-se-puede-demostrar/.test(apacheMention.out) && snap('estricto') === S0);
  ok('tras las negativas el servidor es idéntico a la línea base y cervesur_portalbi no cambió', estadoLimpio() && snap('estricto') === S0 && sqlx('CHECKSUM TABLE cervesur_portalbi.clientes_senuelo, cervesur_portalbi.usuarios_senuelo') === portalbi0);

  section = 'Fallo a mitad de instalación → reversión automática';
  for (const paso of ['cuentas', 'env', 'apache']) {
    const r = dx(`bash ${PK}/scripts/instalar.sh --puerto ${QA}`, { CERVESUR_QAE_CONFIRMAR: 'SI', CERVESUR_QAE_INYECTAR_FALLO: paso });
    ok(`fallo inyectado en «${paso}» (DESPUÉS de crear bases y/o cuentas): se revierte SOLO lo creado y el servidor queda idéntico`, r.code !== 0 && /se revierte automáticamente/.test(r.out) && estadoLimpio() && snap('estricto') === S0 && apachePid() === pid0, r.out.slice(-600));
  }
  ok('queda evidencia: respaldo de Apache, línea base y registro de reversión (nunca se borran)', dx('ls /var/backups/cervesur-react-escritura-qa/*.tgz /var/backups/cervesur-react-escritura-qa/linea-base-portales.txt /var/backups/cervesur-react-escritura-qa/revertir-*.log >/dev/null').code === 0);

  section = 'Instalación (escrituras iniciales: solo usuarios)';
  const inst = instalar();
  ok('instalar.sh termina correctamente', inst.code === 0, inst.out.slice(-1200));
  const verif = /(\d+) PASS \/ (\d+) FAIL \/ (\d+) omitidas/.exec(inst.out);
  ok('verificar.sh integrado: todas las comprobaciones pasan, resultado GO', Boolean(verif) && verif[2] === '0' && verif[3] === '0' && Number(verif[1]) >= 50 && /RESULTADO: GO/.test(inst.out) && !/\[NO-GO\]/.test(inst.out), verif?.[0] ?? inst.out.slice(-700));
  const accounts = Object.fromEntries([...inst.out.matchAll(/^CUENTA (\w+) ([0-9a-f]{20})$/gm)].map(m => [m[1], m[2]]));
  const ADM = accounts.syn001;
  ok('se muestran cuatro cuentas sintéticas con contraseñas aleatorias distintas', ['syn001', 'syn005', 'syn006', 'syn007'].every(u => accounts[u]) && new Set(Object.values(accounts)).size === 4);
  const envChk = dx(`E=${INST}/.env; grep -qx CERVESUR_MODE=admin_escritura $E && grep -qx CERVESUR_REQUIRED_MODE=admin_escritura $E && grep -qx AUDIT_LOG_MODE=required $E && grep -qx REACT_STRICT_VIEWS=1 $E && grep -qx ADMIN_WRITES_USUARIOS=1 $E && grep -qx ADMIN_WRITES_EMPRESAS=0 $E && grep -qx ADMIN_WRITES_REPORTES=0 $E && grep -qx ADMIN_WRITES_ACCESOS=0 $E && grep -qx ADMIN_WRITES_USUARIOS_SENSIBLE=0 $E && grep -qx ADMIN_USER_REACTIVATE_HOURS=24 $E && ! grep -q USER_ADMIN_WRITES $E && grep -qx DB_NAME=${DB} $E && grep -qx STATE_DB_NAME=${DBE} $E && [ "$(stat -c '%a %U:%G' $E)" = "640 root:www-data" ]`);
  ok('.env: modo admin_escritura, DB propia y de estado, cuentas propias, interruptores (solo usuarios encendido), USER_ADMIN_WRITES AUSENTE, 640 root:www-data', envChk.code === 0, envChk.out);
  ok('secretos: JWT 64 hex propio, contraseñas de las 4 cuentas distintas y generadas en el servidor; ninguna aparece en la salida, en /var/log, en los respaldos ni en el paquete', dx(`E=${INST}/.env; j=$(grep ^JWT_SECRET= $E | cut -d= -f2-); [ \${#j} -eq 64 ] && [ "$(grep -E '^(DB_PASS|AUTH_DB_PASS|STATE_DB_PASS)=' $E | cut -d= -f2- | sort -u | wc -l)" = 3 ] && ! grep -rqsF -e "$j" -e "$(grep ^DB_PASS= $E | cut -d= -f2-)" /var/log /var/backups ${PK} --exclude=mysql.err 2>/dev/null`).code === 0 && !/DB_PASS=|JWT_SECRET=/.test(inst.out));
  const nCuentas = sqlx("SELECT COUNT(*) FROM mysql.user WHERE user LIKE 'cervesur_qa_esc%'");
  ok('existen exactamente las 4 cuentas MySQL nuevas, ambas bases y la lista blanca (solo el usuario 1)', nCuentas === '4' && sqlx(`SHOW DATABASES LIKE '${DB}%'`).split('\n').length === 2 && sqlx(`SELECT GROUP_CONCAT(user_id) FROM ${DBE}.usuarios_piloto`) === '1');
  const grants = sqlx("SHOW GRANTS FOR 'cervesur_qa_esc_w'@'127.0.0.1'").replace(/\([^)]*\)/g, '');
  ok('SHOW GRANTS de la cuenta de escritura: sin DELETE, ALL PRIVILEGES, GRANT OPTION ni TRIGGER; audit_log solo SELECT/INSERT; sin portalbi', !/\b(DELETE|ALL PRIVILEGES|GRANT OPTION|TRIGGER|DROP|ALTER|CREATE)\b/.test(grants) && !/portalbi/.test(grants) && /SELECT, INSERT ON `cervesur_qa_escritura`\.`audit_log`/.test(grants) && !/UPDATE[^`]*ON `cervesur_qa_escritura`\.`audit_log`/.test(grants), grants.slice(0, 400));
  ok('audit_log tiene los 2 triggers de solo inserción', sqlx(`SELECT GROUP_CONCAT(TRIGGER_NAME ORDER BY TRIGGER_NAME) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='${DB}' AND EVENT_OBJECT_TABLE='audit_log'`) === 'audit_log_no_delete,audit_log_no_update');
  ok('Apache no se reinició (mismo proceso maestro) y mod_headers sigue', apachePid() === pid0 && dx('test -e /etc/apache2/mods-enabled/headers.load').code === 0);
  ok(`el puerto ${QA} queda registrado, el sitio escucha SOLO en 127.0.0.1:${QA} y ports.conf no se editó`, dx(`[ "$(cat /var/lib/cervesur-react-escritura-qa/puerto)" = "${QA}" ] && grep -qx "Listen 127.0.0.1:${QA}" /etc/apache2/sites-available/cervesur-react-escritura-qa.conf && [ "$(ss -ltnH "sport = :${QA}" | awk '{print $4}' | sort -u)" = "127.0.0.1:${QA}" ] && ! grep -q "${QA}" /etc/apache2/ports.conf`).code === 0);
  const S1a = snap('ajenos');
  ok('Flutter (80), señuelos 443/8443/8088/8089/8090-8094, cervesur_portalbi y el resto del servidor: IDÉNTICOS a antes de instalar', S1a === S0a && sqlx('CHECKSUM TABLE cervesur_portalbi.clientes_senuelo, cervesur_portalbi.usuarios_senuelo') === portalbi0, S1a.split('\n').filter((l, i) => l !== S0a.split('\n')[i]).slice(0, 4).join(' | '));

  section = 'verificar.sh a mano';
  const v0 = dx(`bash ${PK}/scripts/verificar.sh`);
  ok('sin credenciales las pruebas autenticadas se OMITEN y el resultado es NO-GO (nunca un GO falso)', v0.code !== 0 && /RESULTADO: NO-GO/.test(v0.out) && /OMIT/.test(v0.out));
  const creds = { CERVESUR_QAE_ADMIN_PASSWORD: ADM, CERVESUR_QAE_USUARIO_PASSWORD: accounts.syn005, CERVESUR_QAE_USER_PASSWORD: accounts.syn007 };
  const v1 = dx(`bash ${PK}/scripts/verificar.sh`, creds);
  ok('con las credenciales de QA: GO (incluye: trabajadores con clave correcta NO entran, 403 escritura_desactivada, auditoría)', v1.code === 0 && /RESULTADO: GO/.test(v1.out) && /PASS .*escritura_desactivada/.test(v1.out) && /PASS .*deja una fila user\.update/.test(v1.out) && /PASS .*NO puede entrar.*syn005|PASS .*syn005.*NO puede entrar/.test(v1.out), v1.out.split('\n').filter(l => /FAIL|OMIT|RESULTADO/.test(l)).join(' | '));
  sqlx(`DROP TRIGGER ${DB}.audit_log_no_update`);
  const sinTrig = dx(`bash ${PK}/scripts/verificar.sh`, creds);
  ok('si falta un trigger de audit_log, verificar.sh emite NO-GO (FAIL explícito)', sinTrig.code !== 0 && /RESULTADO: NO-GO/.test(sinTrig.out) && /FAIL .*triggers/.test(sinTrig.out));
  dx(`mysql ${DB} < ${PK}/database/migrations/UX-08B-audit-log-immutable.up.sql`);
  ok('al restaurar los triggers, verificar.sh vuelve a GO', dx(`bash ${PK}/scripts/verificar.sh`, creds).code === 0);
  dx(`cp -p ${INST}/.env /root/env-ok.bak && sed -i 's/^AUDIT_LOG_MODE=required/AUDIT_LOG_MODE=off/' ${INST}/.env`);
  const sinAudit = dx(`bash ${PK}/scripts/verificar.sh`, creds); dx(`cp -p /root/env-ok.bak ${INST}/.env`);
  ok('con AUDIT_LOG_MODE distinto de required el backend rehúsa servir y verificar.sh da NO-GO', sinAudit.code !== 0 && /RESULTADO: NO-GO/.test(sinAudit.out));
  dx(`cp -p ${INST}/.env /root/env-ok.bak && printf 'USER_ADMIN_WRITES=1\\n' >> ${INST}/.env`);
  const conUser = dx(`bash ${PK}/scripts/verificar.sh`, creds); dx(`cp -p /root/env-ok.bak ${INST}/.env`);
  ok('con USER_ADMIN_WRITES en el .env, verificar.sh da NO-GO', conUser.code !== 0 && /FAIL .*USER_ADMIN_WRITES ausente/.test(conUser.out));
  ok('restaurado el .env, vuelve a GO', dx(`bash ${PK}/scripts/verificar.sh`, creds).code === 0);

  section = 'API real por el túnel (anfitrión → socat → 127.0.0.1)';
  const index = await api('GET', '/');
  ok('el frontend admin-write se sirve por el túnel (React, CSP con frame-src powerbi, X-Frame-Options DENY, sin HSTS)', index.status === 200 && /<div id="root">/.test(index.raw) && /frame-src https:\/\/app\.powerbi\.com/.test(index.headers.get('content-security-policy') ?? '') && index.headers.get('x-frame-options') === 'DENY' && !index.headers.get('strict-transport-security'));
  ok('/api/ping responde pong (modo válido con la bandera de QA) y no pone cookies', (await api('GET', '/api/ping')).json?.pong === true && !(await api('GET', '/api/ping')).headers.get('set-cookie'));
  const w5 = await login('syn005', accounts.syn005); const w7 = await login('syn007', accounts.syn007); const wBad = await login('syn001', 'incorrecta');
  ok('trabajadores (syn005 rol usuario, syn007 rol user) con clave correcta NO entran (lista blanca); clave errónea del administrador tampoco', w5?.success === false && w7?.success === false && wBad?.success === false && !w5.token);
  const admin = await login('syn001', ADM);
  ok('syn001 (admin de la lista blanca) entra con token opaco de 64 hex', admin?.success === true && /^[0-9a-f]{64}$/.test(admin.token ?? '') && admin.user.role === 'admin');
  const cap = await api('GET', '/api/admin/capacidades', { token: admin.token });
  ok('GET /api/admin/capacidades devuelve modo admin_escritura, auditoría y SOLO usuarios encendido (empresas, reportes, accesos y lo sensible apagados), horas 24', cap.status === 200 && cap.json?.data?.mode === 'admin_escritura' && cap.json.data.audit === true && JSON.stringify(cap.json.data.writes) === JSON.stringify({ empresas: false, reportes: false, accesos: false, usuarios: true }) && cap.json.data.users_sensitive === false && cap.json.data.reactivate_after_hours === 24, cap.raw);
  const u40 = await api('GET', '/api/users/40', { token: admin.token });
  const a0 = Number(sqlx(`SELECT COUNT(*) FROM ${DB}.audit_log`));
  const upd = await api('PUT', '/api/users/40', { token: admin.token, body: { first_name: 'Editado QA', version: u40.json?.data?.version } });
  const ult = sqlx(`SELECT action, entity, entity_id, actor_user_id FROM ${DB}.audit_log ORDER BY id DESC LIMIT 1`);
  ok('una escritura real (PUT /api/users/40) funciona y deja EXACTAMENTE una fila user.update en audit_log (actor 1, entidad user, id 40)', upd.status === 200 && Number(sqlx(`SELECT COUNT(*) FROM ${DB}.audit_log`)) === a0 + 1 && ult === 'user.update\tuser\t40\t1' && sqlx(`SELECT first_name FROM ${DB}.user WHERE id=40`) === 'Editado QA', `${upd.status} ${ult}`);
  const rechazos = await Promise.all([['POST', '/api/empresas'], ['POST', '/api/reportes'], ['POST', '/api/empresa-usuario'], ['PUT', '/api/users/40/password'], ['PUT', '/api/user/40/role']].map(([m, p]) => api(m, p, { token: admin.token, body: {} })));
  ok('empresas, reportes, accesos y lo sensible están APAGADOS: 403 con code escritura_desactivada (nada se escribe)', rechazos.every(r => r.status === 403 && r.json?.code === 'escritura_desactivada'), rechazos.map(r => `${r.status}:${r.json?.code}`).join(' '));
  ok('una ruta fuera de la tabla cerrada (DELETE /api/users/40) → 403 code admin_escritura; /api/powerbi → 403', (await api('DELETE', '/api/users/40', { token: admin.token })).json?.code === 'admin_escritura' && (await api('GET', '/api/powerbi/tokens/1')).status === 403);
  const v31 = await api('GET', '/api/reportes/id/31/visor', { token: admin.token }); const v1r = await api('GET', '/api/reportes/id/1/visor', { token: admin.token });
  ok('visor: 200 en el informe sintético 31 y 403 en otro (lista blanca del servidor)', v31.status === 200 && v1r.status === 403);

  section = 'Interruptores (scripts/interruptor-escrituras.sh)';
  const sw = (args, env = {}) => dx(`bash ${PK}/scripts/interruptor-escrituras.sh ${args}`, env);
  const hashEnvSinInterruptores = () => dx(`grep -vE '^(ADMIN_WRITES_|ADMIN_USER_REACTIVATE_HOURS)' ${INST}/.env | sha256sum`).out;
  const envBase = hashEnvSinInterruptores();
  ok('--estado muestra los interruptores sin ningún valor secreto', /usuarios\s+ENCENDIDO/.test(sw('--estado').out) && !/JWT|PASS|SECRET/.test(sw('--estado').out));
  const on = sw('--encender empresas');
  const capOn = await api('GET', '/api/admin/capacidades', { token: admin.token });
  const empOn = await api('POST', '/api/empresas', { token: admin.token, body: {} });
  ok('--encender empresas: solo cambia el .env, la capacidad se refleja al instante y la ruta deja de dar escritura_desactivada (sin recargar Apache)', on.code === 0 && capOn.json?.data?.writes?.empresas === true && empOn.json?.code !== 'escritura_desactivada' && apachePid() === pid0 && hashEnvSinInterruptores() === envBase && dx(`stat -c '%a %U:%G' ${INST}/.env`).out === '640 root:www-data', on.out.slice(-300));
  const off = sw('--apagar empresas');
  ok('--apagar empresas: vuelve el 403 escritura_desactivada', off.code === 0 && (await api('POST', '/api/empresas', { token: admin.token, body: {} })).json?.code === 'escritura_desactivada');
  const sens = sw('--encender usuarios-sensible --horas 48'); const cap2 = await api('GET', '/api/admin/capacidades', { token: admin.token });
  ok('--encender usuarios-sensible y --horas 48 se reflejan en capacidades (users_sensitive=true, 48 h)', sens.code === 0 && cap2.json?.data?.users_sensitive === true && cap2.json.data.reactivate_after_hours === 48, sens.out.slice(-300));
  const offU = sw('--apagar usuarios'); const cap3 = await api('GET', '/api/admin/capacidades', { token: admin.token });
  ok('--apagar usuarios apaga también lo sensible (el backend exige usuarios=1) y la escritura de usuarios da 403 escritura_desactivada', offU.code === 0 && cap3.json?.data?.users_sensitive === false && (await api('PUT', '/api/users/40', { token: admin.token, body: {} })).json?.code === 'escritura_desactivada' && /apaga también/.test(offU.out + ''), offU.out.slice(-300));
  const badSens = sw('--encender usuarios-sensible'); const badHoras = sw('--horas 900'); const badName = sw('--encender todo');
  ok('encender lo sensible sin usuarios, --horas fuera de 0-720 y nombres desconocidos se RECHAZAN sin cambiar el .env', badSens.code !== 0 && badHoras.code !== 0 && badName.code !== 0 && dx(`grep -qx ADMIN_WRITES_USUARIOS_SENSIBLE=0 ${INST}/.env && grep -qx ADMIN_WRITES_USUARIOS=0 ${INST}/.env`).code === 0 && (await api('GET', '/api/ping')).json?.pong === true);
  const vOff = dx(`bash ${PK}/scripts/verificar.sh`, creds);
  ok('con «usuarios» apagado, verificar.sh no da un GO falso: omite la prueba de auditoría (NO-GO)', vOff.code !== 0 && /OMIT .*escritura auditada/.test(vOff.out));
  sw('--encender usuarios --horas 24');
  ok('estado final restaurado (usuarios encendido, 24 h) y verificar.sh vuelve a GO', dx(`bash ${PK}/scripts/verificar.sh`, creds).code === 0 && hashEnvSinInterruptores() === envBase);
  dx(`cp -p ${INST}/.env /root/env-modo.bak && printf 'CERVESUR_MODE=read_only\n' >> ${INST}/.env`);
  const modoMal = sw('--encender empresas'); dx(`cp -p /root/env-modo.bak ${INST}/.env`);
  ok('si el .env no está en modo admin_escritura, el interruptor se NIEGA a tocarlo', modoMal.code !== 0 && /no está en modo admin_escritura/.test(modoMal.out));
  ok('tras los interruptores el servidor ajeno sigue idéntico', snap('ajenos') === S0a);

  section = 'Actualización (actualizar.sh)';
  const mkPkg = (dir, extraPhp = '<?php // v2\n', publico = 'v2\n') => dx(`rm -rf ${dir} && cp -r ${PK} ${dir} && printf %b ${JSON.stringify(publico)} > ${dir}/public/version-ensayo.txt && printf %b ${JSON.stringify(extraPhp)} > ${dir}/backend/lib/version_ensayo.php && cd ${dir} && find . -type f ! -name SHA256SUMS -print0 | sort -z | xargs -0 sha256sum > SHA256SUMS`);
  const act = (dir, args = '', env = {}) => dx(`bash ${dir}/scripts/actualizar.sh ${args}`, { CERVESUR_QAE_ADMIN_PASSWORD: ADM, CERVESUR_QAE_CONFIRMAR: 'SI', CERVESUR_QAE_ESPERA_OPCACHE: '3', ...env });
  const instHash = () => dx('(cd /var/www/cervesur-react-escritura-qa && find public -type f | sort | xargs sha256sum; cd /opt/cervesur-react-escritura-qa && find backend -type f ! -path backend/.env | sort | xargs sha256sum) | sha256sum').out;
  const envHash = () => dx(`sha256sum ${INST}/.env`).out;
  mkPkg('/root/p-v2');
  const S2 = snap('estricto'); const envH0 = envHash();
  const aSim = act('/root/p-v2', '--simular');
  ok('--simular: comprueba (integridad, sintaxis PHP de todo el backend, modo, salud, loopback) y NO escribe nada (servidor y entorno idénticos, sin carpeta de respaldo de actualización)', aSim.code === 0 && /SIMULACIÓN terminada/.test(aSim.out) && snap('estricto') === S2 && envHash() === envH0 && dx('ls /var/backups/cervesur-react-escritura-qa | grep -c actualizacion || true').out === '0', aSim.out.slice(-500));
  const sinClave = dx(`bash /root/p-v2/scripts/actualizar.sh --simular`);
  ok('sin CERVESUR_QAE_ADMIN_PASSWORD no se actualiza (verificación incompleta) y no se modifica nada', sinClave.code !== 0 && /Falta CERVESUR_QAE_ADMIN_PASSWORD/.test(sinClave.out) && snap('estricto') === S2);
  dx('cp -r /root/p-v2 /root/p-roto && echo "<?php sintaxis rota {" > /root/p-roto/backend/lib/version_ensayo.php && cd /root/p-roto && find . -type f ! -name SHA256SUMS -print0 | sort -z | xargs -0 sha256sum > SHA256SUMS');
  const aRoto = act('/root/p-roto');
  ok('un paquete con un error de sintaxis PHP se rechaza ANTES de tocar nada', aRoto.code !== 0 && /Error de sintaxis PHP/.test(aRoto.out) && snap('estricto') === S2 && dx(`test ! -e ${PUB}/version-ensayo.txt`).code === 0);
  dx('cp -r /root/p-v2 /root/p-alt && echo x >> /root/p-alt/public/index.html');
  const aAlt = act('/root/p-alt', '--simular');
  ok('un paquete alterado (SHA-256) se rechaza', aAlt.code !== 0 && /integridad del paquete/.test(aAlt.out));
  const idv1 = instHash();
  const aCorr = act('/root/p-v2', '', { CERVESUR_QAE_INYECTAR_FALLO: 'respaldo-corrupto' });
  ok('respaldo corrupto (SHA-256 no coincide): NO se reemplaza nada; la evidencia del respaldo se conserva', aCorr.code !== 0 && /Respaldo NO creado o no verificable/.test(aCorr.out) && instHash() === idv1 && envHash() === envH0 && dx(`test ! -e ${PUB}/version-ensayo.txt && ls -d /var/backups/cervesur-react-escritura-qa/actualizacion-* | wc -l`).out === '1', aCorr.out.slice(-400));
  const aMan = act('/root/p-v2', '', { CERVESUR_QAE_INYECTAR_FALLO: 'manifiesto' });
  ok('sin poder generar el manifiesto del respaldo: aborta sin reemplazar', aMan.code !== 0 && /Respaldo NO creado/.test(aMan.out) && instHash() === idv1);
  const a2 = act('/root/p-v2');
  const bk2 = dx('ls -d /var/backups/cervesur-react-escritura-qa/actualizacion-* | tail -1').out;
  ok('actualización real a v2: respaldo verificado (SHA-256, permisos 700/600, sin .env), reemplazo, verificar.sh sin fallos y huella de los demás portales idéntica', a2.code === 0 && /respaldo en .*SHA-256 de ambos archivos verificado/.test(a2.out) && /ACTUALIZACIÓN COMPLETA/.test(a2.out) && /idénticos antes y después/.test(a2.out), a2.out.slice(-900));
  ok('v2 está en disco (public/version-ensayo.txt y backend/lib/version_ensayo.php); el .env y SUS INTERRUPTORES quedan idénticos; permisos 640 root:www-data; el respaldo no contiene .env', dx(`test -f ${PUB}/version-ensayo.txt && test -f ${INST}/lib/version_ensayo.php`).code === 0 && envHash() === envH0 && dx(`stat -c '%a %U:%G' ${INST}/.env`).out === '640 root:www-data' && dx(`! tar tzf ${bk2}/backend.tgz | grep -q '/\\.env'; cd ${bk2} && sha256sum -c SHA256SUMS --quiet`).code === 0);
  ok('tras actualizar la API sigue operativa (login + capacidades) y Apache conserva su proceso maestro', (await login('syn001', ADM))?.success === true && apachePid() === pid0);
  mkPkg('/root/p-v3', '<?php // v3\n', 'v3\n');
  const idv2 = instHash();
  const aFail = act('/root/p-v3', '', { CERVESUR_QAE_INYECTAR_FALLO: 'verificacion' });
  ok('FALLO FORZADO de la verificación posterior (v3): vuelta AUTOMÁTICA a v2 (contenido idéntico), .env intacto, código de salida ≠ 0, respaldo conservado', aFail.code !== 0 && /La actualización falló: se restaura la versión anterior/.test(aFail.out) && /versión anterior restaurada y verificada/.test(aFail.out) && instHash() === idv2 && envHash() === envH0 && dx(`cat ${PUB}/version-ensayo.txt`).out === 'v2' && dx("ls -A /var/www/cervesur-react-escritura-qa /opt/cervesur-react-escritura-qa | grep -c -E '^[.](previo|nuevo)' || true").out === '0', aFail.out.slice(-700));
  for (const paso of ['frontend', 'backend']) {
    const r = act('/root/p-v3', '', { CERVESUR_QAE_INYECTAR_FALLO: paso });
    ok(`fallo forzado en el reemplazo de «${paso}»: vuelta automática a v2`, r.code !== 0 && /se restaura la versión anterior/.test(r.out) && instHash() === idv2 && envHash() === envH0, r.out.slice(-400));
  }
  ok('tras los fallos forzados el entorno sigue en GO', dx(`bash ${PK}/scripts/verificar.sh`, creds).code === 0);
  const rev = act('/root/p-v2', `--revertir ${bk2}`);
  ok('actualizar.sh --revertir <respaldo>: restaura v1 (sin version-ensayo.txt), conserva el .env y los interruptores y verifica', rev.code === 0 && /versión anterior restaurada y verificada/.test(rev.out) && dx(`test ! -e ${PUB}/version-ensayo.txt && test ! -e ${INST}/lib/version_ensayo.php`).code === 0 && instHash() === idv1 && envHash() === envH0, rev.out.slice(-500));
  const revMal = act('/root/p-v2', '--revertir /root/no-existe');
  ok('--revertir con un respaldo inexistente se niega sin tocar nada', revMal.code !== 0 && /respaldo indicado no es válido/.test(revMal.out) && instHash() === idv1);
  ok('los respaldos de actualización NO se borraron', Number(dx('ls -d /var/backups/cervesur-react-escritura-qa/actualizacion-* | wc -l').out) >= 3);
  ok('tras todas las actualizaciones, Flutter, 443/8443 y los señuelos siguen idénticos', snap('ajenos') === S0a && sqlx('CHECKSUM TABLE cervesur_portalbi.clientes_senuelo, cervesur_portalbi.usuarios_senuelo') === portalbi0);

  section = 'Reversión (desinstalación)';
  dx('mv /opt/cervesur-react-escritura-qa/.marca-cervesur-react-escritura-qa /root/marca-back.bak');
  const revMarca = dx(`bash ${PK}/scripts/revertir.sh`, { CERVESUR_QAE_CONFIRMAR: 'SI' });
  ok('con la marca del backend retirada, revertir.sh se NIEGA a borrar esa carpeta (FALLO explícito), sale con error y conserva el estado', revMarca.code !== 0 && /NO se borra \/opt\/cervesur-react-escritura-qa: no tiene la marca/.test(revMarca.out) && dx('test -d /opt/cervesur-react-escritura-qa && test -f /var/lib/cervesur-react-escritura-qa/.marca-cervesur-react-escritura-qa').code === 0, revMarca.out.slice(-400));
  dx('mv /root/marca-back.bak /opt/cervesur-react-escritura-qa/.marca-cervesur-react-escritura-qa');
  const revPuertoMalo = dx(`bash ${PK}/scripts/revertir.sh`, { CERVESUR_QAE_CONFIRMAR: 'SI', CERVESUR_QAE_PUERTO: String(QA + 1) });
  ok('revertir con un puerto distinto del registrado aborta sin hacer nada', revPuertoMalo.code !== 0 && /no coincide con el de la instalación registrada/.test(revPuertoMalo.out) && dx('test -f /var/lib/cervesur-react-escritura-qa/puerto').code === 0);
  const rv = await Promise.resolve(dx(`bash ${PK}/scripts/revertir.sh`, { CERVESUR_QAE_CONFIRMAR: 'SI' }));
  ok('revertir.sh termina correctamente: retira sitio, snippets, carpetas, las DOS bases y las CUATRO cuentas (todas marcadas) y compara con la línea base', rv.code === 0 && !/FALLO/.test(rv.out) && /base cervesur_qa_escritura (eliminada|ya no existe)/.test(rv.out) && /base cervesur_qa_escritura_estado (eliminada|ya no existe)/.test(rv.out) && CUENTAS.every(c => new RegExp(`cuenta ${c}@127\\.0\\.0\\.1 (eliminada|ya no existe)`).test(rv.out)) && /IDÉNTICO a la línea base/.test(rv.out), rv.out.slice(-900));
  ok('no queda nada del entorno (carpetas, sitio, puerto, bases, cuentas)', estadoLimpio());
  ok('el servidor emulado queda EXACTAMENTE como antes de instalar (Apache byte a byte, árboles, Flutter, 443/8443, bases, cuentas, privilegios, checksums, puertos, módulos y respuestas)', snap('estricto') === S0, snap('estricto').split('\n').filter((l, i) => l !== S0.split('\n')[i]).slice(0, 4).join(' | '));
  const flutter1 = dx('cd /var/www/cervesur && find . -type f -print0 | sort -z | xargs -0 sha256sum | sha256sum; find /var/www/cervesur-react-trabajadores /var/www/cervesur-react-admin /opt/cervesur-react-trabajadores /opt/cervesur-react-admin -type f -print0 | sort -z | xargs -0 sha256sum | sha256sum').out;
  ok('Flutter, los directorios de 443/8443 y la base señuelo cervesur_portalbi son byte a byte IDÉNTICOS antes y después de TODO el ciclo', flutter1 === flutter0 && sqlx('CHECKSUM TABLE cervesur_portalbi.clientes_senuelo, cervesur_portalbi.usuarios_senuelo') === portalbi0);
  ok('Apache conserva su proceso maestro; los respaldos, la línea base y los registros se conservan; revertir dos veces no falla ni borra nada', apachePid() === pid0 && dx('ls /var/backups/cervesur-react-escritura-qa/linea-base-portales.txt /var/backups/cervesur-react-escritura-qa/revertir-*.log >/dev/null').code === 0 && dx(`bash ${PK}/scripts/revertir.sh`, { CERVESUR_QAE_CONFIRMAR: 'SI' }).code === 0 && snap('estricto') === S0);
} catch (error) {
  results.push(['FAIL', `${section} · EXCEPCIÓN`, error.stack ?? error.message]);
} finally {
  if (!process.env.ENSAYO_CONSERVAR) cleanup(); // ENSAYO_CONSERVAR=1 deja el contenedor para depurar
  if (!process.env.ENSAYO_OUT) rmSync(OUT, { recursive: true, force: true });
}
for (const [status, name, extra] of results) console.log(`${status}  ${name}${extra ? `  → ${extra}` : ''}`);
const failed = results.filter(r => r[0] === 'FAIL').length;
console.log(`\n${results.length - failed} PASS / ${failed} FAIL`);
process.exit(failed ? 1 : 0);
