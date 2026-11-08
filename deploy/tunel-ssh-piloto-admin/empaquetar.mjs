// Empaqueta el PILOTO ADMINISTRATIVO DE SOLO LECTURA (UX-07E.B): instancia SEPARADA del piloto de usuarios (127.0.0.1:8093, solo por túnel SSH y solo por HTTPS). No se conecta a ningún
// servidor ni despliega nada.
//   node deploy/tunel-ssh-piloto-admin/empaquetar.mjs                  compila la aplicación administrativa (build-production --pilot-admin, host localhost) y ensambla
//   node deploy/tunel-ssh-piloto-admin/empaquetar.mjs --sin-compilar   reutiliza frontend/dist-pilot-admin
// Resultado (ignorado por Git): dist-paquete/cervesur-react-admin-<commit>[-con-cambios-<huella>]-<fecha-hora UTC>.tar.gz con SHA256SUMS interno y .tar.gz.sha256 externo.
//
// Una sola fuente de verdad: los scripts, la configuración de Apache y el procedimiento SQL son los del kit del piloto de usuarios (deploy/tunel-ssh-piloto-usuarios), con una
// TRANSFORMACIÓN DETERMINISTA de nombres (puerto, sitio, carpetas, cuentas MySQL con sufijo _adm, base de estado propia, modo piloto_admin). Así las mejoras de instalación,
// verificación y reversión del kit probado se heredan sin duplicar código. Nada se instala sobre el piloto de usuarios (8092): rutas, cuentas y base de estado son distintas.
// Excluye siempre .env, certificados, claves privadas, tests y node_modules, y verifica que el paquete no contenga claves privadas ni hashes productivos.
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { cpSync, existsSync, mkdirSync, readFileSync, readdirSync, renameSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { basename, dirname, extname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const REPO = join(here, '..', '..');
const KIT = join(REPO, 'deploy', 'tunel-ssh-piloto-usuarios');
const fail = message => { console.error(`\nERROR: ${message}`); process.exit(1); };
const FRONT = join(REPO, 'frontend');
const DIST = join(FRONT, 'dist-pilot-admin');
const git = args => spawnSync('git', args, { cwd: REPO, encoding: 'utf8' }).stdout.trim();

// --host=<nombre.interno>: además de «localhost» (túnel) la aplicación admite ese nombre de publicación corporativa (UX-07E.C). Sin él, solo localhost.
const hostsArg = (process.argv.find(a => a.startsWith('--host='))?.slice(7).toLowerCase() ?? '').split(',').filter(Boolean); // nombre y/o IP privada (UX-07E.D)
for (const h of hostsArg) if (!/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/.test(h) || h === 'localhost') fail(`--host no es un nombre o IP válido: «${h}»`);
if (!process.argv.includes('--sin-compilar')) {
  const result = spawnSync(process.execPath, [join(FRONT, 'scripts', 'build-production.mjs'), '--pilot-admin'], { cwd: FRONT, stdio: 'inherit', env: { ...process.env, CERVESUR_ALLOWED_HOSTS: ['localhost', ...hostsArg].join(',') } });
  if (result.status !== 0) fail('La compilación del piloto administrativo falló.');
}
if (!existsSync(join(DIST, 'index.html'))) fail('Falta frontend/dist-pilot-admin: ejecute sin --sin-compilar.');
const info = JSON.parse(readFileSync(join(DIST, 'build-info.json'), 'utf8'));
if (info.target !== 'server' || info.provider !== 'http' || info.apiBase !== 'same-origin' || info.pilot !== true || info.pilotMode !== 'admin') fail('frontend/dist-pilot-admin no es una compilación del piloto administrativo (servidor, HTTP, mismo origen, pilot=true, pilotMode=admin).');
if (!info.allowedHosts.includes('localhost')) fail('La compilación debe declarar «localhost» como host autorizado (acceso por túnel SSH).');
for (const h of hostsArg) if (!info.allowedHosts.includes(h)) fail(`La compilación no declara «${h}» como host autorizado: ejecute sin --sin-compilar.`);

const commit = git(['rev-parse', '--short=12', 'HEAD']) || 'sin-git';
const dirty = git(['status', '--porcelain', '--', 'frontend', 'backend', 'deploy', 'database', 'tests', 'docs']) ? '-con-cambios' : '';
const stamp = new Date().toISOString().replace(/[-:]/g, '').replace(/\.\d+Z$/, 'Z').replace('T', '-');
const OUT = join(REPO, 'dist-paquete');
const TMP = join(OUT, `.admin-construccion-${process.pid}`);
rmSync(TMP, { recursive: true, force: true });
mkdirSync(TMP, { recursive: true });

const TEXT = new Set(['.sh', '.php', '.conf', '.sql', '.html', '.js', '.css', '.json', '.txt', '.md', '']);
const lf = file => { const text = readFileSync(file, 'utf8'); if (text.includes('\r\n')) writeFileSync(file, text.replace(/\r\n/g, '\n')); };
const excluded = src => basename(src).startsWith('.env') || /\.(key|pem|crt|p12|pfx)$/i.test(basename(src)) || ['node_modules', '.git', 'tests', 'uploads'].includes(basename(src));
const copy = (from, to) => { if (!existsSync(from)) fail(`Falta ${relative(REPO, from)}`); cpSync(from, join(TMP, to), { recursive: true, filter: src => !excluded(src) }); };

/** Transformación determinista de nombres del kit del piloto de usuarios al piloto administrativo (orden importante). */
const RENAMES = [
  [/18093/g, '18094'], [/8092/g, '8093'],
  [/CERVESUR_REACT_USUARIOS/g, 'CERVESUR_REACT_ADMIN'], [/cervesur-react-usuarios/g, 'cervesur-react-admin'], [/cervesur-usuarios/g, 'cervesur-admin'],
  [/pilotMode=usuarios/g, 'pilotMode=admin'], [/(pilotMode\\?":\s?\*?\\?")usuarios/g, '$1admin'], [/compilación del piloto de usuarios/g, 'compilación del piloto administrativo'],
  [/piloto_usuarios/g, 'piloto_admin'], [/PILOTO CON USUARIOS REALES/g, 'PILOTO ADMINISTRATIVO DE SOLO LECTURA'], [/piloto con usuarios reales/g, 'piloto administrativo de solo lectura'],
  [/cervesur_ro_auth/g, 'cervesur_ro_auth_adm'], [/cervesur_ro_catalogo/g, 'cervesur_ro_catalogo_adm'], [/cervesur_react_estado(?![A-Za-z_])/g, 'cervesur_react_estado_adm']
];
const transform = (text, rel) => {
  let out = RENAMES.reduce((acc, [from, to]) => acc.replace(from, to), text);
  if (rel === 'scripts/lib.sh') {
    // La base de estado (no la cuenta) tiene su propio nombre; y el piloto de usuarios (8092) pasa a ser un portal que esta instalación debe dejar intacto.
    out = out.replace('DB_ESTADO=cervesur_react_estado_adm ', 'DB_ESTADO=cervesur_react_estado_admin ');
    if (!out.includes('DB_ESTADO=cervesur_react_estado_admin')) fail('No se pudo fijar el nombre de la base de estado en lib.sh (el formato de lib.sh cambió).');
    out = out.replace('huella_portales() {', 'huella_usuarios_8092() { # piloto de usuarios (solo se LEE su huella; no se toca)\n  curl -sk -m 8 -o /dev/null -D - https://127.0.0.1:8092/api/ping 2>/dev/null | tr -d \'\\r\' | awk \'NR==1{print} NR>1{split($0,a,":"); print tolower(a[1])}\' | LC_ALL=C sort\n  for d in /var/www/cervesur-react-usuarios/public /opt/cervesur-react-usuarios/backend; do [ -d "$d" ] && arbol_digest "$d" || echo "ausente:$d"; done\n}\nhuella_portales() {');
    out = out.replace('  echo "== servidor"; huella_servidor', '  echo "== piloto-usuarios-8092"; huella_usuarios_8092\n  echo "== servidor"; huella_servidor');
    if (!out.includes('huella_usuarios_8092\n  echo "== servidor"')) fail('No se pudo incorporar el piloto de usuarios a la línea base (el formato de lib.sh cambió).');
  }
  if (rel === 'scripts/verificar.sh') {
    // El piloto administrativo SÍ ofrece GET de consulta administrativa (con sesión) y conserva las pantallas de administración (sin acciones de escritura): dos comprobaciones del kit de
    // usuarios se invierten/ajustan. Sin sesión, las consultas administrativas devuelven 401 (nunca datos); estadísticas, Power BI heredado, registro, variantes y escrituras, 403.
    const reemplazar = (from, to) => { if (!out.includes(from)) fail(`verificar.sh cambió: no se encuentra «${from.slice(0, 60)}…»`); out = out.replace(from, to); };
    reemplazar('for r in /api/users /api/user /api/stats /api/empresa-usuario /api/empresa-reporte-usuario /api/reportes/activos', 'for r in /api/user /api/stats /api/reportes/activos');
    reemplazar('  for r in POST PUT DELETE; do [ "$(http -X $r -H \'Content-Type: application/json\' -d \'{}\' "$BASE/api/reportes")" = "403" ] || return 1; done\n',
      '  for r in POST PUT DELETE; do [ "$(http -X $r -H \'Content-Type: application/json\' -d \'{}\' "$BASE/api/reportes")" = "403" ] || return 1; done\n  for r in POST PUT DELETE; do [ "$(http -X $r -H \'Content-Type: application/json\' -d \'{}\' "$BASE/api/users")" = "403" ] || return 1; done\n  for r in /api/users /api/empresas /api/reportes /api/asignacion /api/empresa-usuario /api/empresa-reporte-usuario; do [ "$(http "$BASE$r")" = "401" ] || { echo "no 401: $r" >&2; return 1; }; done\n');
    reemplazar('prueba "la aplicación NO incluye las pantallas de administración, alta, edición ni asignación" sin_pantallas_admin\n', '# (la aplicación administrativa conserva las pantallas de consulta; las escrituras las rechaza el servidor — ver rutas cerradas)\n');
    reemplazar('usuarios, estadísticas, asignaciones de usuarios, Power BI heredado, registro, variantes de ruta y escrituras → 403', 'consultas administrativas sin sesión → 401; estadísticas, Power BI heredado, registro, variantes de ruta y escrituras (también sobre /api/users) → 403');
  }
  if (rel === 'scripts/corporativo.sh') {
    // El portal administrativo se publica en el puerto 8443 de la IP interna (el de trabajadores, en el 443).
    if (!out.includes('PUERTO_PUB_DEFECTO=443 ')) fail('corporativo.sh cambió: no se encuentra PUERTO_PUB_DEFECTO.');
    out = out.replace('PUERTO_PUB_DEFECTO=443 ', 'PUERTO_PUB_DEFECTO=8443 ');
  }
  return out;
};
const copyTransformed = (from, to) => {
  copy(from, to);
  const walk = dir => { for (const entry of readdirSync(dir)) { const full = join(dir, entry); if (statSync(full).isDirectory()) { walk(full); continue; } const ext = extname(full).toLowerCase(); if (!TEXT.has(ext)) continue; const rel = relative(TMP, full).split('\\').join('/'); writeFileSync(full, transform(readFileSync(full, 'utf8'), rel)); } };
  walk(join(TMP, to));
};

copy(DIST, 'public');
copy(join(REPO, 'backend'), 'backend');
copyTransformed(join(KIT, 'apache'), 'apache');
renameSync(join(TMP, 'apache', 'cervesur-react-usuarios.conf'), join(TMP, 'apache', 'cervesur-react-admin.conf')); // el nombre del archivo del sitio sigue al del sitio
copyTransformed(join(KIT, 'scripts'), 'scripts');
copyTransformed(join(KIT, 'admin-mysql'), 'admin-mysql');
// SQL propio de la instancia (cuentas _adm, base de estado propia): sustituye a los del kit de usuarios. Mismos privilegios mínimos; ninguna escritura sobre la base de Flutter.
const schema = join(REPO, 'database', 'schema', 'piloto-admin');
for (const f of ['01-cuentas-lectura.sql', '02-estado-base-y-cuenta.sql', '03-verificar-privilegios.sql', '99-revocar-cuentas.sql']) copy(join(schema, f), `admin-mysql/${f}`);
// El procedimiento de preparación del SQL lee los archivos 01 y 02: ya son los de esta instancia. La base de estado predeterminada se fija aquí.
const prep = join(TMP, 'admin-mysql', 'preparar-sql-cuentas.sh');
writeFileSync(prep, readFileSync(prep, 'utf8').replace('BASE_ESTADO=cervesur_react_estado_adm;', 'BASE_ESTADO=cervesur_react_estado_admin;'));
if (!readFileSync(prep, 'utf8').includes('BASE_ESTADO=cervesur_react_estado_admin;')) fail('No se pudo fijar la base de estado en preparar-sql-cuentas.sh (el formato cambió).');
for (const f of ['INSTALACION-PILOTO-ADMIN-8093.md', 'PILOTO-ADMIN-UX-07EB.md', 'PARIDAD-ADMIN-FLUTTER-REACT.md', 'GUIA-QA-ADMIN-A1-A10.md', 'POWERBI-PUBLICAR-WEB-RIESGO.md', 'GUIA-QA-UX-07EC.md', 'DIAGNOSTICO-INDICADORES-ADMIN.md', 'MATRIZ-AUTORIZACION-ENLACES.md', 'PUBLICACION-CORPORATIVA-HTTPS.md', 'PUBLICACION-IP-INTERNA.md', 'GUIA-QA-PUBLICACION-IP.md']) copy(join(REPO, 'docs', f), `docs/${f}`);

// Autocomprobación: todo archivo del paquete al que un script hace referencia ($PAQ/ruta) debe existir (la transformación de nombres no puede dejar referencias rotas).
for (const entry of readdirSync(join(TMP, 'scripts')).filter(f => f.endsWith('.sh'))) {
  for (const m of readFileSync(join(TMP, 'scripts', entry), 'utf8').matchAll(/\$PAQ\/([A-Za-z0-9_.\/-]+)/g)) {
    const ref = m[1].replace(/[.\/-]+$/, '');
    if (ref && !['SHA256SUMS', 'VERSION'].includes(ref) && !ref.includes('$') && !existsSync(join(TMP, ref))) fail(`scripts/${entry} hace referencia a «${ref}», que no existe en el paquete (revise la transformación de nombres).`);
  }
}

const files = [];
const walk = dir => { for (const entry of readdirSync(dir).sort()) { const full = join(dir, entry); statSync(full).isDirectory() ? walk(full) : files.push(full); } };
walk(TMP);
for (const file of files) if (TEXT.has(extname(file).toLowerCase()) || basename(file) === '.htaccess') lf(file);
const CERT_LIKE = /\.(key|pem|crt|p12|pfx)$/i;
// El único hash bcrypt permitido es el FICTICIO de lib/pilot_sesion.php (igualar el tiempo de login de cuentas inexistentes); cualquier otro hash bcrypt es un hash productivo.
for (const file of files) {
  if (basename(file).startsWith('.env')) fail(`El paquete contiene un archivo .env: ${relative(TMP, file)}`);
  if (CERT_LIKE.test(basename(file))) fail(`El paquete contiene un certificado o una clave: ${relative(TMP, file)}`);
  const text = readFileSync(file, 'latin1');
  if (/-----BEGIN [A-Z ]*PRIVATE KEY-----/.test(text)) fail(`Clave privada en el paquete: ${relative(TMP, file)}`);
  if (/CERVESUR_DIAG_TOKEN=[0-9a-f]{32,}|DB_PASS=[^\s_%]{8,}|JWT_SECRET=[0-9a-f]{32,}|PILOT_SECRET=[0-9a-f]{32,}|OPERATOR_HASH=\$2y\$|IDENTIFIED BY '(?!__CLAVE_)[A-Za-z0-9!]{12,}'|\$2[aby]\$\d\d\$(?!abcdefghijklmnopqrstuu)[.\/A-Za-z0-9]{53}/.test(text)) fail(`Posible secreto en el paquete: ${relative(TMP, file)}`);
}
const sums = files.map(f => `${createHash('sha256').update(readFileSync(f)).digest('hex')}  ${relative(TMP, f).split('\\').join('/')}`);
const contentDigest = createHash('sha256').update(sums.join('\n')).digest('hex');
const name = `cervesur-react-admin-${commit}${dirty ? `${dirty}-${contentDigest.slice(0, 12)}` : ''}-${stamp}`;
writeFileSync(join(TMP, 'VERSION'), `commit: ${commit}${dirty}\ncontenido-sha256: ${contentDigest}\nconstruido: ${new Date().toISOString()}\nentorno: piloto ADMINISTRATIVO de solo lectura (https://127.0.0.1:8093, solo por túnel SSH)\nbase-de-lectura: cervesur_portalbi (solo lectura)\n`);
files.push(join(TMP, 'VERSION'));
const allSums = files.map(f => `${createHash('sha256').update(readFileSync(f)).digest('hex')}  ${relative(TMP, f).split('\\').join('/')}`);
writeFileSync(join(TMP, 'SHA256SUMS'), `${allSums.join('\n')}\n`);

const ROOT = join(OUT, name);
rmSync(ROOT, { recursive: true, force: true });
renameSync(TMP, ROOT);
const tgz = join(OUT, `${name}.tar.gz`);
const tar = spawnSync('tar', ['-czf', `${name}.tar.gz`, name], { cwd: OUT, stdio: 'inherit' }); // rutas relativas: sirve con GNU tar y bsdtar
if (tar.status !== 0) fail('No se pudo crear el .tar.gz (se necesita «tar», incluido en Windows 10/11).');
const sha = createHash('sha256').update(readFileSync(tgz)).digest('hex');
writeFileSync(`${tgz}.sha256`, `${sha}  ${name}.tar.gz\n`);
console.log(`\nPaquete listo (nada se ha enviado ni instalado):\n  ${tgz}\n  Nombre: ${name}.tar.gz\n  SHA-256 del .tar.gz: ${sha}\n  Huella del contenido: ${contentDigest}\n  Archivos: ${files.length + 1}`);
