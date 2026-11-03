// ENSAYO LOCAL (Docker) del entorno ADMINISTRATIVO DE QA aislado (UX-08F). No se conecta a ningún servidor real.
//   node deploy/qa-admin-aislado/ensayo-docker.mjs                  (compila el frontend de servidor y empaqueta en una carpeta temporal fuera del repositorio)
//   ENSAYO_SIN_COMPILAR=1 node deploy/qa-admin-aislado/ensayo-docker.mjs   (reutiliza frontend/dist-production)
//   ENSAYO_OUT=<carpeta> · ENSAYO_CONSERVAR=1 (no borra el contenedor) · ENSAYO_PORT=18097 (puerto del anfitrión que emula el túnel SSH)
// Réplica del servidor: Ubuntu 24.04 + Apache 2.4 + mod_php 8.3 + MySQL 8 (validate_password MEDIUM) con Flutter simulado en el 80 y portales SEÑUELO
// en 443 (TLS), 8443 (TLS), 8088 y 8089, más la base señuelo cervesur_portalbi. Demuestra que instalar, fallar, verificar y revertir 8090 deja
// todo lo demás byte a byte idéntico, que las tres negativas abortan sin cambios y que la reversión devuelve el servidor exactamente a su estado previo.
import { spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { mkdtempSync, readdirSync, rmSync, statSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const REPO = resolve(here, '..', '..');
const BASE_IMAGE = 'cervesur-ensayo-ubuntu:local';
const IMAGE = 'cervesur-ensayo-qa-admin:local';
const NAME = 'cervesur-ensayo-qa-admin';
const PORT = Number(process.env.ENSAYO_PORT ?? 18097);
const BASE = `http://127.0.0.1:${PORT}`;
const OUT = process.env.ENSAYO_OUT ? resolve(process.env.ENSAYO_OUT) : mkdtempSync(join(tmpdir(), 'cervesur-ensayo-qa-admin-'));
const results = [];
let section = '';
const ok = (name, cond, extra = '') => results.push([cond ? 'PASS' : 'FAIL', `${section} · ${name}`, cond ? '' : extra]);
const docker = (args, { allowFail = true, input } = {}) => {
  const r = spawnSync('docker', args, { encoding: 'utf8', maxBuffer: 256 * 1024 * 1024, input, env: { ...process.env, MSYS_NO_PATHCONV: '1' } });
  if (r.status !== 0 && !allowFail) throw new Error(`docker ${args.slice(0, 3).join(' ')} falló:\n${r.stderr || r.stdout}`);
  return { code: r.status, out: `${r.stdout ?? ''}${r.stderr ?? ''}`.trim() };
};
const dx = (cmd, env = {}) => docker(['exec', ...Object.entries(env).flatMap(([k, v]) => ['--env', `${k}=${v}`]), NAME, 'bash', '-c', cmd]);
const put = (path, text) => spawnSync('docker', ['exec', '-i', NAME, 'bash', '-c', `cat > ${path}`], { input: text });
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
MODE=\${1:-ajenos}
F() { if [ "$MODE" = ajenos ]; then grep -v -E 'cervesur-react-admin-qa|cervesur_qa_admin|:8090$'; else cat; fi; }
echo "== apache (archivos y enlaces, byte a byte)"
find /etc/apache2 \( -type f -o -type l \) | F | LC_ALL=C sort | while read -r f; do if [ -L "$f" ]; then echo "L $f $(readlink "$f")"; else sha256sum "$f"; fi; done
echo "== arboles de portales (metadatos)"
find /var/www /opt -type f -printf '%p %s %T@ %m %u:%g\n' | F | LC_ALL=C sort
echo "== contenido de los señuelos y de Flutter"
sha256sum /var/www/cervesur/.htaccess /var/www/cervesur-react-*/public/index.html /opt/cervesur-react-*/backend/.env | F
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
`.replace('\\${1', '${1');

try {
  cleanup();
  section = 'Paquete';
  const pack = spawnSync(process.execPath, [join(here, 'empaquetar.mjs'), '--out', OUT, ...(process.env.ENSAYO_SIN_COMPILAR ? ['--sin-compilar'] : [])], { cwd: REPO, encoding: 'utf8' });
  ok('empaquetar.mjs construye/incluye el frontend y el backend en un único .tar.gz con SHA256SUMS, fuera del repositorio', pack.status === 0 && !OUT.startsWith(REPO), (pack.stderr || pack.stdout).slice(-400));
  const tgz = newest('cervesur-react-admin-qa-');
  if (!tgz) throw new Error('Falta el paquete');
  const rejects = spawnSync(process.execPath, [join(here, 'empaquetar.mjs'), '--sin-compilar', '--out', join(REPO, 'dist-paquete-prohibido')], { cwd: REPO, encoding: 'utf8' });
  ok('empaquetar.mjs rechaza una carpeta --out dentro del repositorio', rejects.status !== 0 && /dentro del repositorio/.test(rejects.stderr));

  section = 'Imagen de ensayo';
  if (docker(['image', 'inspect', BASE_IMAGE]).code !== 0) docker(['build', '--quiet', '--tag', BASE_IMAGE, '--file', join(here, 'ensayo', 'Dockerfile.base'), join(here, 'ensayo')], { allowFail: false });
  docker(['build', '--quiet', '--build-arg', `BASE=${BASE_IMAGE}`, '--tag', IMAGE, join(here, 'ensayo')], { allowFail: false });
  docker(['run', '--detach', '--name', NAME, '--publish', `127.0.0.1:${PORT}:18092`, IMAGE], { allowFail: false });
  for (let i = 0; ; i++) { if (dx('curl -fs http://127.0.0.1/ >/dev/null && curl -fs http://127.0.0.1:8088/ >/dev/null && curl -ksf https://127.0.0.1:8443/ >/dev/null && curl -ksf https://127.0.0.1:443/ >/dev/null && mysqladmin ping --silent').code === 0) break; if (i > 90) throw new Error('El contenedor no arrancó'); await sleep(1000); }
  ok('la réplica arranca con Flutter (80), señuelos 443/8443 (TLS), 8088 y 8089 y la base señuelo cervesur_portalbi', dx('mysql -N -e "SHOW DATABASES" | grep -qx cervesur_portalbi').code === 0);
  docker(['cp', tgz, `${NAME}:/root/qa-admin.tar.gz`], { allowFail: false });
  const PK = `/root/${dx('cd /root && tar xzf qa-admin.tar.gz && ls -d cervesur-react-admin-qa-*').out.split('\n').pop()}`;
  ok('el paquete se extrae y su SHA256SUMS es válido', dx(`cd ${PK} && sha256sum -c SHA256SUMS --quiet`).code === 0);
  ok('LF en scripts y Apache; sin .env (solo la plantilla sin valores), sin pruebas y sin claves privadas', dx(`! grep -rlI $'\\r' ${PK}/scripts ${PK}/apache && [ "$(find ${PK} -name ".env*" | wc -l)" = 1 ] && [ -f ${PK}/.env.qa-admin.example ] && ! find ${PK}/backend -ipath "*tests*" | grep -q . && ! grep -rIl -e "BEGIN .*PRIVATE KEY" ${PK}`).code === 0);
  ok('el paquete trae las migraciones UX-08B, UX-08B.2, UX-08B3 y la plantilla UX-08E', dx(`ls ${PK}/database/migrations ${PK}/database/privileges | grep -c -E 'UX-08B-audit-log.up|UX-08B-audit-log-immutable.up|UX-08B3-reporte-grupo-nullable.up|UX-08E-cuenta-admin-minima'`).out === '4');

  section = 'Calidad de los scripts';
  const sc = docker(['run', '--rm', '--volume', `${join(here)}:/mnt:ro`, '--workdir', '/mnt', 'koalaman/shellcheck:latest', '-x', '-P', 'scripts', 'scripts/lib.sh', 'scripts/instalar.sh', 'scripts/pasos-instalacion.sh', 'scripts/revertir.sh', 'scripts/verificar.sh', 'ensayo/entrypoint.qa.sh']);
  ok('shellcheck (koalaman/shellcheck:latest) sin avisos en los 5 scripts y el entrypoint', sc.code === 0, sc.out.slice(0, 400));
  ok('bash -n y php -l correctos', dx(`for f in ${PK}/scripts/*.sh; do bash -n $f || exit 1; done; php -l ${PK}/scripts/sembrar-qa-admin.php`).code === 0);

  const apachePid = () => dx('cat /var/run/apache2/apache2.pid').out;
  put('/root/snap.sh', SNAP);
  const snap = (mode = 'ajenos') => dx(`bash /root/snap.sh ${mode}`).out;
  const estadoLimpio = () => dx('for p in /var/www/cervesur-react-admin-qa /opt/cervesur-react-admin-qa /etc/apache2/cervesur-react-admin-qa /etc/apache2/sites-available/cervesur-react-admin-qa.conf /etc/apache2/sites-enabled/cervesur-react-admin-qa.conf /var/lib/cervesur-react-admin-qa; do test -e $p && exit 1; done; [ -z "$(ss -ltnH "sport = :8090")" ] && [ -z "$(mysql -N -e "SHOW DATABASES LIKE \'cervesur_qa_admin\'")" ] && [ "$(mysql -N -e "SELECT COUNT(*) FROM mysql.user WHERE user=\'cervesur_qa_admin_w\'")" = 0 ]').code === 0;
  const instalar = (extra = '', env = {}) => dx(`bash ${PK}/scripts/instalar.sh ${extra}`, { CERVESUR_QA_ADMIN_CONFIRMAR: 'SI', ...env });
  const pid0 = apachePid();
  const S0 = snap('estricto'); const S0a = snap('ajenos');
  ok('línea base independiente tomada: Apache byte a byte, árboles, carpetas, bases/cuentas/privilegios, checksums de cervesur_portalbi, puertos, módulos y respuestas', S0.length > 3000 && /== respuestas/.test(S0) && /cervesur_portalbi/.test(S0) && S0 === S0a, `${S0.length}`);
  const portalbi0 = sqlx('CHECKSUM TABLE cervesur_portalbi.clientes_senuelo, cervesur_portalbi.usuarios_senuelo');

  section = 'Preflight y negativas (todo ANTES de instalar: sin cambios)';
  const sim = instalar('--simular');
  ok('--simular supera todas las comprobaciones y termina sin modificar nada', sim.code === 0 && /Modo --simular: fin/.test(sim.out) && /USER_ADMIN_WRITES=1/.test(sim.out) && estadoLimpio() && snap('estricto') === S0 && dx('test -e /var/backups/cervesur-react-admin-qa').code !== 0, sim.out.slice(-500));
  dx(`rm -rf /root/p-tamper && cp -r ${PK} /root/p-tamper && echo x >> /root/p-tamper/public/index.html`);
  const tamper = dx('bash /root/p-tamper/scripts/instalar.sh', { CERVESUR_QA_ADMIN_CONFIRMAR: 'SI' });
  ok('un paquete alterado (SHA-256 no coincide) se rechaza antes de instalar', tamper.code !== 0 && /integridad del paquete/.test(tamper.out) && estadoLimpio() && snap('estricto') === S0);
  dx('a2dismod headers >/dev/null && apache2ctl graceful'); await sleep(1500);
  const sinHeaders = instalar('--simular');
  dx('a2enmod headers >/dev/null && apache2ctl graceful'); await sleep(1500);
  ok('sin mod_headers cargado el instalador se NIEGA (no lo habilita) y no modifica nada', sinHeaders.code !== 0 && /mod_headers YA cargado/.test(sinHeaders.out) && estadoLimpio() && snap('estricto') === S0);

  // Las tres negativas pedidas y el resto de colisiones. Cada una: el recurso ajeno se arma, el instalador ABORTA, nada cambia y se desarma.
  const hex = randomBytes(6).toString('hex');
  const collisions = [
    ['carpeta del frontend /var/www/cervesur-react-admin-qa ya existe', 'mkdir /var/www/cervesur-react-admin-qa', 'rmdir /var/www/cervesur-react-admin-qa', /no existe \/var\/www\/cervesur-react-admin-qa/],
    ['carpeta del backend /opt/cervesur-react-admin-qa ya existe', 'mkdir /opt/cervesur-react-admin-qa', 'rmdir /opt/cervesur-react-admin-qa', /no existe \/opt\/cervesur-react-admin-qa/],
    ['carpeta de snippets /etc/apache2/cervesur-react-admin-qa ya existe', 'mkdir /etc/apache2/cervesur-react-admin-qa', 'rmdir /etc/apache2/cervesur-react-admin-qa', /no existe \/etc\/apache2\/cervesur-react-admin-qa/],
    ['carpeta de sesiones /var/lib/cervesur-react-admin-qa ya existe', 'mkdir /var/lib/cervesur-react-admin-qa', 'rmdir /var/lib/cervesur-react-admin-qa', /no existe \/var\/lib\/cervesur-react-admin-qa/],
    ['el sitio Apache cervesur-react-admin-qa ya existe', 'echo "# ajeno" > /etc/apache2/sites-available/cervesur-react-admin-qa.conf', 'rm -f /etc/apache2/sites-available/cervesur-react-admin-qa.conf', /no existe \/etc\/apache2\/sites-available\/cervesur-react-admin-qa\.conf/],
    ['el puerto 8090 está ocupado', 'setsid nohup socat TCP-LISTEN:8090,bind=127.0.0.1,fork,reuseaddr SYSTEM:true >/dev/null 2>&1 & sleep 1', "pkill -f 'TCP-LISTEN:809[0]' || true", /puerto 8090 libre/],
    ['la base cervesur_qa_admin ya existe', "mysql -e 'CREATE DATABASE cervesur_qa_admin'", "mysql -e 'DROP DATABASE IF EXISTS cervesur_qa_admin'", /la base cervesur_qa_admin no existe/],
    ['NEGATIVA 3: el usuario MySQL cervesur_qa_admin_w ya existe (otro host)', `mysql -e "CREATE USER 'cervesur_qa_admin_w'@'localhost' IDENTIFIED BY 'Aa1!${hex}'"`, "mysql -e \"DROP USER IF EXISTS 'cervesur_qa_admin_w'@'localhost'\"", /ninguna cuenta MySQL llamada cervesur_qa_admin_w existe todavía/],
    ['el usuario MySQL cervesur_qa_admin_w@127.0.0.1 ya existe', `mysql -e "CREATE USER 'cervesur_qa_admin_w'@'127.0.0.1' IDENTIFIED BY 'Aa1!${hex}'"`, "mysql -e \"DROP USER IF EXISTS 'cervesur_qa_admin_w'@'127.0.0.1'\"", /ninguna cuenta MySQL llamada cervesur_qa_admin_w existe todavía/],
    ['un Apache ya menciona el puerto 8090', 'echo "# Listen 8090 ajeno" > /etc/apache2/conf-available/zz-ajeno.conf; a2enconf zz-ajeno >/dev/null', 'a2disconf zz-ajeno >/dev/null; rm -f /etc/apache2/conf-available/zz-ajeno.conf', /ninguna configuración de Apache menciona el puerto 8090/]
  ];
  for (const [label, arm, disarm, pattern] of collisions) {
    dx(arm); const r = instalar();
    const trasAbort = label.startsWith('la base') ? dx("mysql -N -e \"SHOW DATABASES LIKE 'cervesur_qa_admin'\"").out === 'cervesur_qa_admin' : true; // el recurso ajeno sigue ahí (no se tocó)
    dx(disarm); await sleep(300);
    ok(`colisión (${label}): el instalador ABORTA con «No se modificó nada», sin respaldos ni marcas, y el recurso ajeno no se toca`, r.code !== 0 && pattern.test(r.out) && /No se modificó nada/.test(r.out) && trasAbort && dx('test -e /var/backups/cervesur-react-admin-qa').code !== 0, r.out.slice(-300));
  }
  ok('tras las colisiones el servidor sigue idéntico a la línea base y sin restos', estadoLimpio() && snap('estricto') === S0 && sqlx('CHECKSUM TABLE cervesur_portalbi.clientes_senuelo, cervesur_portalbi.usuarios_senuelo') === portalbi0);
  for (const [label, dbName] of [['NEGATIVA 2: cervesur_portalbi como nombre de base', 'cervesur_portalbi'], ['un nombre que contiene cervesur_portalbi', 'qa_cervesur_portalbi_copia'], ['un nombre con «portalbi» en cualquier posición', 'portalbi']]) {
    for (const modo of ['--simular', '']) {
      const r = dx(`bash ${PK}/scripts/instalar.sh ${modo}`, { CERVESUR_QA_ADMIN_CONFIRMAR: 'SI', CERVESUR_QA_DB_NOMBRE: dbName });
      ok(`${label} (${modo || 'instalación real'}): RECHAZADO antes de cualquier cambio`, r.code !== 0 && /RECHAZADO/.test(r.out) && estadoLimpio() && dx('test -e /var/backups/cervesur-react-admin-qa').code !== 0, r.out.slice(-300));
    }
  }
  const rv = dx(`bash ${PK}/scripts/revertir.sh`, { CERVESUR_QA_ADMIN_CONFIRMAR: 'SI', CERVESUR_QA_DB_NOMBRE: 'cervesur_portalbi' });
  ok('revertir.sh con cervesur_portalbi como nombre de base también se NIEGA', rv.code !== 0 && /RECHAZADO/.test(rv.out));
  ok('la base señuelo cervesur_portalbi (datos y privilegios) sigue idéntica tras todas las negativas', sqlx('CHECKSUM TABLE cervesur_portalbi.clientes_senuelo, cervesur_portalbi.usuarios_senuelo') === portalbi0 && snap('estricto') === S0);

  section = 'Reversión sin marca: no borra lo ajeno';
  dx('mkdir -p /var/www/cervesur-react-admin-qa/ajeno && echo x > /var/www/cervesur-react-admin-qa/ajeno/f && mkdir /opt/cervesur-react-admin-qa');
  const revSinMarca = dx(`bash ${PK}/scripts/revertir.sh`, { CERVESUR_QA_ADMIN_CONFIRMAR: 'SI' });
  ok('revertir.sh sin instalación marcada NO borra carpetas homónimas ajenas y sale sin error', revSinMarca.code === 0 && /NO se elimina nada/.test(revSinMarca.out) && dx('test -f /var/www/cervesur-react-admin-qa/ajeno/f && test -d /opt/cervesur-react-admin-qa').code === 0, revSinMarca.out.slice(-300));
  dx('rm -rf /var/www/cervesur-react-admin-qa /opt/cervesur-react-admin-qa');
  ok('sin restos tras retirar las carpetas ajenas de la prueba', estadoLimpio());

  section = 'Reversión automática ante un fallo a mitad de instalación';
  dx(`rm -rf /root/p-roto && cp -r ${PK} /root/p-roto && echo "DirectivaInexistente x" >> /root/p-roto/apache/react-admin-qa-common.conf && cd /root/p-roto && find . -type f ! -name SHA256SUMS -print0 | xargs -0 sha256sum > SHA256SUMS`);
  const roto = dx('bash /root/p-roto/scripts/instalar.sh', { CERVESUR_QA_ADMIN_CONFIRMAR: 'SI' });
  ok('la instalación falla en Apache (configtest) DESPUÉS de crear base, cuenta y carpetas, y revierte automáticamente SOLO lo creado', roto.code !== 0 && /se revierte automáticamente/.test(roto.out) && /cuenta cervesur_qa_admin_w@127.0.0.1 eliminada/.test(roto.out) && /base cervesur_qa_admin eliminada/.test(roto.out), roto.out.slice(-700));
  ok('sin restos: carpetas, sesiones, sitio, base y cuenta eliminados; mod_headers sigue; Apache conserva su proceso maestro', estadoLimpio() && dx('test -e /etc/apache2/mods-enabled/headers.load').code === 0 && apachePid() === pid0);
  ok('el servidor es idéntico a la línea base tras el fallo y los respaldos/registro se conservan', snap('estricto') === S0 && dx('ls /var/backups/cervesur-react-admin-qa/*.tgz /var/backups/cervesur-react-admin-qa/revertir-*.log >/dev/null').code === 0);

  section = 'Instalación (por defecto: UX-08B3 NO aplicada)';
  const inst = instalar();
  ok('instalar.sh termina correctamente', inst.code === 0, inst.out.slice(-1200));
  const verif = /(\d+) PASS \/ (\d+) FAIL \/ (\d+) omitidas/.exec(inst.out);
  ok('verificar.sh integrado: todas las comprobaciones pasan, resultado GO', Boolean(verif) && verif[2] === '0' && verif[3] === '0' && Number(verif[1]) >= 45 && /RESULTADO: GO/.test(inst.out) && !/\[NO-GO\]/.test(inst.out), verif?.[0] ?? inst.out.slice(-600));
  ok('la salida imprime el aviso fuerte de USER_ADMIN_WRITES=1 solo para QA', /USER_ADMIN_WRITES=1 es SOLO de este entorno de QA/.test(inst.out) && /AVISO: este entorno arranca con USER_ADMIN_WRITES=1/.test(inst.out));
  const accounts = Object.fromEntries([...inst.out.matchAll(/^CUENTA (\w+) ([0-9a-f]{20})$/gm)].map(m => [m[1], m[2]]));
  ok('se muestran cuatro cuentas sintéticas con contraseñas aleatorias distintas entre sí', ['syn001', 'syn005', 'syn006', 'syn007'].every(u => accounts[u]) && new Set(Object.values(accounts)).size === 4);
  ok('migraciones aplicadas en orden: UX-08B, UX-08B.2; UX-08B3 NO aplicada (grupo_id NOT NULL)', /UX-08B-audit-log aplicada y verificada/.test(inst.out) && /UX-08B-audit-log-immutable aplicada y verificada/.test(inst.out) && /UX-08B3 NO aplicada/.test(inst.out) && sqlx("SELECT CONCAT(COLUMN_TYPE,'|',IS_NULLABLE) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='cervesur_qa_admin' AND TABLE_NAME='reporte' AND COLUMN_NAME='grupo_id'") === 'varchar(50)|NO');
  ok('tipos productivos y las 8 claves UNIQUE productivas presentes', sqlx("SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='cervesur_qa_admin' AND NON_UNIQUE=0 AND INDEX_NAME IN ('uq_user_username','uq_user_email','uq_empresa_ruc','uq_reporte_enlace','uq_reporte_reporte_id','uq_eu','uq_er','uq_uer')") === '8');
  ok('datos sintéticos: 76 usuarios (62 activos), 95 informes (82 activos) y los TRES valores de rol (admin, user, usuario)', sqlx('SELECT COUNT(*), SUM(is_active) FROM cervesur_qa_admin.user') === '76\t62' && sqlx('SELECT GROUP_CONCAT(DISTINCT role ORDER BY role) FROM cervesur_qa_admin.user') === 'admin,user,usuario' && sqlx('SELECT COUNT(*), SUM(activo) FROM cervesur_qa_admin.reporte') === '95\t82');
  ok('el informe 31 hace de DB031 (sintético) y el id real 72 NO figura en la lista del visor', sqlx('SELECT nombre FROM cervesur_qa_admin.reporte WHERE id = 31') === 'DB031-SINTETICO (QA)' && dx('grep -x "ADMIN_VIEWER_ALLOWED_REPORT_IDS=31" /opt/cervesur-react-admin-qa/backend/.env').code === 0 && dx('grep -E "ADMIN_VIEWER_ALLOWED_REPORT_IDS=.*72" /opt/cervesur-react-admin-qa/backend/.env').code !== 0);
  const envFlags = dx('grep -c -x -e DB_HOST=127.0.0.1 -e DB_NAME=cervesur_qa_admin -e DB_USER=cervesur_qa_admin_w -e AUDIT_LOG_MODE=required -e REACT_STRICT_VIEWS=1 -e USER_ADMIN_WRITES=1 -e ADMIN_VIEWER_ALLOWED_REPORT_IDS=31 /opt/cervesur-react-admin-qa/backend/.env').out;
  ok('.env con DB_HOST/NAME/USER propios y las banderas AUDIT_LOG_MODE=required, REACT_STRICT_VIEWS=1, USER_ADMIN_WRITES=1', envFlags === '7');
  const sinFuga = dx('p=$(grep "^DB_PASS=" /opt/cervesur-react-admin-qa/backend/.env | cut -d= -f2-); j=$(grep "^JWT_SECRET=" /opt/cervesur-react-admin-qa/backend/.env | cut -d= -f2-); [ ${#p} -eq 52 ] && [ ${#j} -eq 64 ]; echo $?').out;
  ok('secretos propios generados en el servidor: contraseña MySQL (52 caracteres, política MEDIUM) y JWT (64 hex)', sinFuga === '0', sinFuga);
  ok('ni la contraseña de MySQL, ni el JWT, ni las contraseñas sintéticas aparecen en la salida del instalador, en /var/log, en los respaldos ni en el paquete', dx(`p=$(grep "^DB_PASS=" /opt/cervesur-react-admin-qa/backend/.env | cut -d= -f2-); j=$(grep "^JWT_SECRET=" /opt/cervesur-react-admin-qa/backend/.env | cut -d= -f2-); ! grep -rqsF -e "$p" -e "$j" /var/log /var/backups ${PK} --exclude=mysql.err 2>/dev/null; echo $?`).out === '0' && !/DB_PASS=|JWT_SECRET=/.test(inst.out));
  ok('.env: 640 root:www-data; sesiones: 700 www-data; carpeta pública propiedad de root; marcas de instalación en las 4 carpetas', dx('stat -c "%a %U:%G" /opt/cervesur-react-admin-qa/backend/.env /var/lib/cervesur-react-admin-qa/sessions; stat -c %U /var/www/cervesur-react-admin-qa/public; ls /var/lib/cervesur-react-admin-qa/.marca-cervesur-react-admin-qa /var/www/cervesur-react-admin-qa/.marca-cervesur-react-admin-qa /opt/cervesur-react-admin-qa/.marca-cervesur-react-admin-qa /etc/apache2/cervesur-react-admin-qa/.marca-cervesur-react-admin-qa | wc -l').out === '640 root:www-data\n700 www-data:www-data\nroot\n4');
  ok('Apache no se reinició (mismo proceso maestro) y mod_headers sigue', apachePid() === pid0 && dx('test -e /etc/apache2/mods-enabled/headers.load').code === 0);

  section = 'Cuenta MySQL de privilegios mínimos (SHOW GRANTS)';
  const grants = sqlx("SHOW GRANTS FOR 'cervesur_qa_admin_w'@'127.0.0.1'");
  ok('SHOW GRANTS: ni DELETE, ni ALL PRIVILEGES, ni GRANT OPTION, ni mención de cervesur_portalbi ni de ninguna otra base', !/\bDELETE\b/.test(grants) && !/ALL PRIVILEGES/.test(grants) && !/GRANT OPTION/.test(grants) && !/portalbi/.test(grants) && grants.split('\n').every(l => /^GRANT USAGE ON \*\.\*/.test(l) || l.includes('`cervesur_qa_admin`.')), grants.slice(0, 300));
  ok('solo SELECT, INSERT y UPDATE (por columnas); audit_log: solo INSERT; ningún DROP/ALTER/CREATE/TRIGGER/TRUNCATE', grants.replace(/\([^)]*\)/g, '').split('\n').every(l => l.replace(/^GRANT /, '').replace(/ ON .*$/, '').split(',').map(s => s.trim()).every(p => ['SELECT', 'INSERT', 'UPDATE', 'USAGE'].includes(p))) && /GRANT INSERT ON `cervesur_qa_admin`\.`audit_log`/.test(grants) && !/UPDATE[^`]*ON `cervesur_qa_admin`\.`audit_log`/.test(grants));
  ok('existe una única cuenta cervesur_qa_admin_w (@127.0.0.1)', sqlx("SELECT GROUP_CONCAT(host) FROM mysql.user WHERE user = 'cervesur_qa_admin_w'") === '127.0.0.1');
  const appSql = q => { put('/tmp/app.sql', q); return dx('f=$(mktemp); chmod 600 $f; printf "[client]\\nuser=%s\\npassword=%s\\nhost=127.0.0.1\\n" "$(grep ^DB_USER= /opt/cervesur-react-admin-qa/backend/.env | cut -d= -f2-)" "$(grep ^DB_PASS= /opt/cervesur-react-admin-qa/backend/.env | cut -d= -f2-)" > $f; mysql --defaults-extra-file=$f -N -B < /tmp/app.sql 2>&1; rc=$?; rm -f $f; exit $rc'); };
  const dbs = appSql('SHOW DATABASES');
  ok('con la cuenta de la aplicación solo se ve la base cervesur_qa_admin (más las bases virtuales information_schema y performance_schema): cervesur_portalbi, mysql y las demás bases son invisibles e inaccesibles', dbs.code === 0 && dbs.out.split('\n').filter(d => !['information_schema', 'performance_schema'].includes(d)).join(',') === 'cervesur_qa_admin' && appSql('SELECT * FROM cervesur_portalbi.clientes_senuelo').code !== 0 && appSql('SELECT * FROM cervesur_react_prueba.t_senuelo').code !== 0 && appSql('SELECT user FROM mysql.user').code !== 0, `${dbs.code}:${dbs.out}|${appSql('SELECT * FROM cervesur_portalbi.clientes_senuelo').out.slice(0, 80)}|${appSql('SELECT * FROM cervesur_react_prueba.t_senuelo').out.slice(0, 80)}|${appSql('SELECT user FROM mysql.user').out.slice(0, 80)}`);
  const antes = sqlx('SELECT COUNT(*) FROM cervesur_qa_admin.audit_log');
  const denied = ['UPDATE cervesur_qa_admin.audit_log SET action = "x"', 'DELETE FROM cervesur_qa_admin.audit_log', 'TRUNCATE TABLE cervesur_qa_admin.audit_log', 'DROP TABLE cervesur_qa_admin.audit_log', 'DELETE FROM cervesur_qa_admin.user', 'DELETE FROM cervesur_qa_admin.reporte WHERE id = 0', 'CREATE TABLE cervesur_qa_admin.x (a INT)', 'DROP TRIGGER cervesur_qa_admin.audit_log_no_update', 'CREATE USER zz@localhost', 'GRANT SELECT ON *.* TO zz@localhost'].map(q => [q, appSql(q)]);
  ok('la cuenta de la aplicación NO puede UPDATE/DELETE/TRUNCATE/DROP sobre audit_log, DELETE en negocio, DDL, quitar triggers, crear cuentas ni conceder (10 sentencias rechazadas)', denied.every(([, r]) => r.code !== 0 && /denied|ERROR 1142|ERROR 1044|ERROR 1227|ERROR 1143/i.test(r.out)) && sqlx('SELECT COUNT(*) FROM cervesur_qa_admin.audit_log') === antes, denied.filter(([, r]) => r.code === 0).map(([q]) => q).join(' | '));
  const trig1 = dx('mysql -e "UPDATE cervesur_qa_admin.audit_log SET action = action LIMIT 1" 2>&1'); const trig2 = dx('mysql -e "DELETE FROM cervesur_qa_admin.audit_log LIMIT 1" 2>&1');
  ok('ni siquiera root de MySQL puede UPDATE/DELETE en audit_log mientras existan los triggers (solo inserción)', /solo insercion/.test(trig1.out) && /solo insercion/.test(trig2.out) && sqlx('SELECT COUNT(*) FROM cervesur_qa_admin.audit_log') === antes);
  ok('las migraciones inmutables dejaron los 2 triggers y la tabla audit_log con sus 10 columnas', sqlx("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='cervesur_qa_admin' AND EVENT_OBJECT_TABLE='audit_log'") === '2' && sqlx("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='cervesur_qa_admin' AND TABLE_NAME='audit_log'") === '10');

  section = 'Red: solo loopback; Flutter y señuelos intactos';
  const listen = port => dx(`ss -ltnH "sport = :${port}" | awk '{print $4}' | sort -u | tr '\\n' ' '`).out;
  ok('8090 escucha SOLO en 127.0.0.1 y NO en 0.0.0.0; 80 y 443 en todas las interfaces; 8443, 8088 y 8089 en loopback', listen(8090) === '127.0.0.1:8090' && listen(80) === '0.0.0.0:80' && listen(443) === '0.0.0.0:443' && listen(8443) === '127.0.0.1:8443' && listen(8088) === '127.0.0.1:8088' && listen(8089) === '127.0.0.1:8089', `${listen(8090)}|${listen(80)}|${listen(443)}|${listen(8443)}`);
  const ip = docker(['inspect', '-f', '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}', NAME]).out;
  const net = port => docker(['run', '--rm', '--entrypoint', 'curl', IMAGE, '-sS', '-m', '5', '-o', '/dev/null', '-w', '%{http_code}', `http://${ip}:${port}/`]);
  ok('desde otra máquina de la red: 8090, 8088 y 8089 rechazan la conexión y el puerto 80 de Flutter responde', /^\d+\.\d+\.\d+\.\d+$/.test(ip) && net(8090).code !== 0 && net(8088).code !== 0 && net(8089).code !== 0 && net(80).out === '200', `${net(8090).out}/${net(80).out}`);
  const S1a = snap('ajenos');
  ok('Flutter (80), señuelos 443/8443/8088/8089: configuración Apache byte a byte, árboles, contenido, bases, cuentas, privilegios, checksums, puertos, módulos y respuestas HTTP(S) IDÉNTICOS a antes de instalar', S1a === S0a, S1a.split('\n').filter((l, i) => l !== S0a.split('\n')[i]).slice(0, 4).join(' | '));
  ok('la tabla de cervesur_portalbi sigue con el mismo checksum y Flutter sirve su página', sqlx('CHECKSUM TABLE cervesur_portalbi.clientes_senuelo, cervesur_portalbi.usuarios_senuelo') === portalbi0 && dx('curl -s http://127.0.0.1/ruta/profunda').out.includes('FLUTTER-SENTINEL'));

  section = 'API a través del túnel (equivalente: anfitrión → socat → 127.0.0.1:8090)';
  const index = await api('GET', '/');
  ok('el frontend de QA se sirve por el túnel (index.html de React, CSP con frame-src powerbi, X-Frame-Options DENY, sin HSTS)', index.status === 200 && /<div id="root">/.test(index.raw) && /frame-src https:\/\/app\.powerbi\.com/.test(index.headers.get('content-security-policy') ?? '') && index.headers.get('x-frame-options') === 'DENY' && !index.headers.get('strict-transport-security'));
  const assets = [...index.raw.matchAll(/(?:src|href)="(\/assets\/[^"]+)"/g)].map(m => m[1]);
  ok('los assets referenciados por index.html se descargan (200) y una ruta interna recarga la aplicación', assets.length > 0 && (await Promise.all(assets.map(a => api('GET', a)))).every(r => r.status === 200) && (await api('GET', '/admin/usuarios')).raw === index.raw);
  const anon = await Promise.all(['/api/users/capabilities', '/api/auditoria', '/api/users?per_page=1', '/api/user/profile'].map(p => api('GET', p)));
  ok('anónimo: 401 en capabilities, historial, usuarios y perfil', anon.every(r => r.status === 401), anon.map(r => r.status).join(','));
  const admin = (await login('syn001', accounts.syn001)); const w5 = (await login('syn005', accounts.syn005)); const w7 = (await login('syn007', accounts.syn007));
  ok('inicio de sesión de syn001 (admin), syn005 (rol usuario) y syn007 (rol user)', admin.success && w5.success && w7.success && admin.user.role === 'admin', JSON.stringify([admin.success, w5.success, w7.success]));
  for (const [label, w] of [['rol «usuario» (syn005)', w5], ['rol heredado «user» (syn007)', w7]]) {
    const rs = await Promise.all([['GET', '/api/users/capabilities'], ['GET', '/api/auditoria'], ['GET', '/api/users?per_page=1'], ['GET', '/api/users/40'], ['PUT', '/api/users/40'], ['POST', '/api/users/40/deactivate']].map(([m, p]) => api(m, p, { token: w.token, body: m === 'GET' ? undefined : { first_name: 'x', version: 'x' } })));
    ok(`trabajador ${label}: 403 en las rutas de administración (capabilities, historial, usuarios, edición, baja)`, rs.every(r => r.status === 403), rs.map(r => r.status).join(','));
  }
  const cap = await api('GET', '/api/users/capabilities', { token: admin.token });
  ok('/api/users/capabilities → user_admin_writes = true', cap.status === 200 && cap.json?.data?.user_admin_writes === true);
  const hist0 = await api('GET', '/api/auditoria?per_page=5', { token: admin.token });
  ok('endpoint de historial disponible para el administrador', hist0.status === 200 && hist0.json?.success === true);
  const u40 = await api('GET', '/api/users/40', { token: admin.token });
  const auditBefore = Number(sqlx('SELECT COUNT(*) FROM cervesur_qa_admin.audit_log'));
  const upd = await api('PUT', '/api/users/40', { token: admin.token, body: { first_name: 'Editado QA', version: u40.json.data.version } });
  const lastAudit = sqlx('SELECT action, entity, entity_id, actor_user_id FROM cervesur_qa_admin.audit_log ORDER BY id DESC LIMIT 1');
  ok('una escritura administrativa (PUT /api/users/40) deja EXACTAMENTE una fila user.update en audit_log, con el actor y la entidad', upd.status === 200 && Number(sqlx('SELECT COUNT(*) FROM cervesur_qa_admin.audit_log')) === auditBefore + 1 && lastAudit === 'user.update\tuser\t40\t1', `${upd.status} ${lastAudit}`);
  const deact = await api('POST', '/api/users/41/deactivate', { token: admin.token, body: {} }); const react = await api('POST', '/api/users/41/reactivate', { token: admin.token, body: {} });
  ok('baja y reactivación lógicas (sin DELETE) dejan cada una su fila de auditoría', deact.status === 200 && react.status === 200 && Number(sqlx('SELECT COUNT(*) FROM cervesur_qa_admin.audit_log')) === auditBefore + 3 && sqlx('SELECT is_active FROM cervesur_qa_admin.user WHERE id = 41') === '1', `${deact.status}/${react.status}`);
  const hist = await api('GET', '/api/auditoria?entity=user&entity_id=40', { token: admin.token });
  ok('el historial por API devuelve la fila de la edición (entidad user, id 40)', hist.status === 200 && JSON.stringify(hist.json).includes('user.update'));
  const v31 = await api('GET', '/api/reportes/id/31/visor', { token: admin.token }); const v1 = await api('GET', '/api/reportes/id/1/visor', { token: admin.token });
  ok('visor del administrador: 200 en el informe sintético 31 (DB031) y 403 en otro informe activo (lista blanca)', v31.status === 200 && v1.status === 403 && !/powerbi\.com/.test(v1.raw), `${v31.status}/${v1.status}`);
  const list = await api('GET', '/api/reportes?view=minimal&per_page=1', { token: admin.token }); const listFull = await api('GET', '/api/reportes?per_page=1', { token: admin.token });
  ok('vistas estrictas: la lista de informes es mínima por defecto (sin enlace ni created_by)', list.status === 200 && list.json?.pagination?.total === 82 && !/enlace|created_by/.test(listFull.raw), `${listFull.status}`);
  const ping = await api('GET', '/api/ping');
  ok('cookie de sesión propia CERVESUR_REACT_ADMIN_QA (HttpOnly, SameSite=Strict, sin Secure) y sesiones en su directorio exclusivo', /^CERVESUR_REACT_ADMIN_QA=/.test(ping.headers.get('set-cookie') ?? '') && /httponly/i.test(ping.headers.get('set-cookie') ?? '') && /samesite=strict/i.test(ping.headers.get('set-cookie') ?? '') && dx('ls /var/lib/cervesur-react-admin-qa/sessions | grep -c sess_').out !== '0');
  ok('/api/powerbi/... responde 403 y /.env no es accesible', (await api('GET', '/api/powerbi/tokens/1')).status === 403 && [403, 404].includes((await api('GET', '/.env')).status));
  const S2a = snap('ajenos');
  ok('tras usar la API (escrituras incluidas) los demás portales y cervesur_portalbi siguen idénticos', S2a === S0a && sqlx('CHECKSUM TABLE cervesur_portalbi.clientes_senuelo, cervesur_portalbi.usuarios_senuelo') === portalbi0);
  const verif2 = dx('bash ' + PK + '/scripts/verificar.sh');
  ok('verificar.sh ejecutado a mano sin credenciales: las pruebas autenticadas se OMITEN y el resultado es NO-GO (nunca un GO falso)', verif2.code !== 0 && /RESULTADO: NO-GO/.test(verif2.out) && /OMIT/.test(verif2.out));
  const verif3 = dx('bash ' + PK + '/scripts/verificar.sh', { CERVESUR_QA_ADMIN_PASSWORD: accounts.syn001, CERVESUR_QA_USUARIO_PASSWORD: accounts.syn005, CERVESUR_QA_USER_PASSWORD: accounts.syn007 });
  ok('verificar.sh ejecutado a mano con las credenciales de QA (tras las escrituras): GO', verif3.code === 0 && /RESULTADO: GO/.test(verif3.out), verif3.out.slice(-500));

  section = 'Reversión: rechaza lo que no tiene marca';
  dx('mv /opt/cervesur-react-admin-qa/.marca-cervesur-react-admin-qa /root/marca-back.bak');
  const revMarca = dx(`bash ${PK}/scripts/revertir.sh`, { CERVESUR_QA_ADMIN_CONFIRMAR: 'SI' });
  ok('con la marca del backend retirada, revertir.sh se NIEGA a borrar esa carpeta (FALLO explícito), sale con error y conserva el estado', revMarca.code !== 0 && /NO se borra \/opt\/cervesur-react-admin-qa: no tiene la marca/.test(revMarca.out) && dx('test -d /opt/cervesur-react-admin-qa && test -f /var/lib/cervesur-react-admin-qa/.marca-cervesur-react-admin-qa').code === 0, revMarca.out.slice(-500));
  ok('...pero sí retiró lo que conservaba su marca (sitio, snippets, base, cuenta, frontend), y los demás portales siguen idénticos', dx('! test -e /etc/apache2/sites-enabled/cervesur-react-admin-qa.conf && ! test -e /etc/apache2/cervesur-react-admin-qa && ! test -e /var/www/cervesur-react-admin-qa').code === 0 && sqlx("SHOW DATABASES LIKE 'cervesur_qa_admin'") === '' && snap('ajenos') === S0a);
  dx('mv /root/marca-back.bak /opt/cervesur-react-admin-qa/.marca-cervesur-react-admin-qa');

  section = 'Reversión completa';
  const rev = dx(`bash ${PK}/scripts/revertir.sh`, { CERVESUR_QA_ADMIN_CONFIRMAR: 'SI' });
  ok('revertir.sh termina correctamente y sin avisos de fallo', rev.code === 0 && !/FALLO/.test(rev.out) && /IDÉNTICO a la línea base/.test(rev.out), rev.out.slice(-700));
  ok('no quedan carpetas, sesiones, sitio, puerto 8090, base ni cuenta del entorno', estadoLimpio());
  ok('el servidor emulado queda EXACTAMENTE como antes de instalar (Apache byte a byte, árboles, carpetas, bases, cuentas, privilegios, checksums, puertos, módulos y respuestas)', snap('estricto') === S0, snap('estricto').split('\n').filter((l, i) => l !== S0.split('\n')[i]).slice(0, 4).join(' | '));
  ok('Apache conserva su proceso maestro (nunca se reinició), mod_headers sigue habilitado, y los respaldos + registro de la reversión se conservan', apachePid() === pid0 && dx('test -e /etc/apache2/mods-enabled/headers.load && ls /var/backups/cervesur-react-admin-qa/linea-base-portales.txt /var/backups/cervesur-react-admin-qa/revertir-*.log >/dev/null').code === 0);
  ok('revertir dos veces no falla ni borra nada', dx(`bash ${PK}/scripts/revertir.sh`, { CERVESUR_QA_ADMIN_CONFIRMAR: 'SI' }).code === 0 && snap('estricto') === S0);

  section = 'Reinstalación tras la reversión, con UX-08B3 pedida explícitamente';
  const inst2 = instalar('--aplicar-migracion-grupo-nullable');
  const v2 = /(\d+) PASS \/ (\d+) FAIL \/ (\d+) omitidas/.exec(inst2.out);
  ok('la reinstalación tras el rollback funciona y verificar.sh da GO', inst2.code === 0 && Boolean(v2) && v2[2] === '0' && v2[3] === '0' && /RESULTADO: GO/.test(inst2.out), inst2.out.slice(-800));
  ok('con --aplicar-migracion-grupo-nullable: UX-08B3 se aplica y verifica; reporte.grupo_id queda VARCHAR(50) NULL (el resto del esquema intacto)', /UX-08B3 aplicada \(pedida\)/.test(inst2.out) && sqlx("SELECT CONCAT(COLUMN_TYPE,'|',IS_NULLABLE) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='cervesur_qa_admin' AND TABLE_NAME='reporte' AND COLUMN_NAME='grupo_id'") === 'varchar(50)|YES' && sqlx("SELECT GROUP_CONCAT(CONCAT(COLUMN_NAME,':',COLUMN_TYPE,':',IS_NULLABLE) ORDER BY COLUMN_NAME) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='cervesur_qa_admin' AND TABLE_NAME='reporte' AND COLUMN_NAME IN ('enlace','reporte_id')") === 'enlace:varchar(500):NO,reporte_id:varchar(50):NO');
  const accounts2 = Object.fromEntries([...inst2.out.matchAll(/^CUENTA (\w+) ([0-9a-f]{20})$/gm)].map(m => [m[1], m[2]]));
  ok('la reinstalación genera contraseñas, JWT y contraseña MySQL NUEVOS (no se reutiliza nada del intento anterior)', ['syn001', 'syn005', 'syn007'].every(u => accounts2[u] && accounts2[u] !== accounts[u]));
  ok('API tras la reinstalación: el administrador entra, capabilities = true, y el trabajador sigue con 403', (await login('syn001', accounts2.syn001)).success === true && (await api('GET', '/api/users/capabilities', { token: (await login('syn001', accounts2.syn001)).token })).json?.data?.user_admin_writes === true && (await api('GET', '/api/users/capabilities', { token: (await login('syn005', accounts2.syn005)).token })).status === 403);
  ok('los demás portales siguen idénticos con la segunda instalación', snap('ajenos') === S0a && sqlx('CHECKSUM TABLE cervesur_portalbi.clientes_senuelo, cervesur_portalbi.usuarios_senuelo') === portalbi0);
  const rev2 = dx(`bash ${PK}/scripts/revertir.sh`, { CERVESUR_QA_ADMIN_CONFIRMAR: 'SI' });
  ok('la segunda reversión también deja el servidor exactamente como antes', rev2.code === 0 && estadoLimpio() && snap('estricto') === S0 && apachePid() === pid0, rev2.out.slice(-500));
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
