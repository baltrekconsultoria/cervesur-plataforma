// Empaqueta el PILOTO VISUAL con informes reales por túnel SSH (UX-07D.4). No se conecta a ningún servidor ni despliega nada.
//   node deploy/tunel-ssh-piloto/empaquetar.mjs                  compila el piloto (npm run build:pilot, host localhost) y ensambla
//   node deploy/tunel-ssh-piloto/empaquetar.mjs --sin-compilar   reutiliza frontend/dist-pilot (debe ser del piloto, de servidor y con localhost)
// Resultado (ignorado por Git): dist-paquete/cervesur-react-piloto-<commit>[-con-cambios-<huella>]-<fecha-hora UTC>.tar.gz, con SHA256SUMS interno
// y <nombre>.tar.gz.sha256 externo. La aplicación es la compilación del piloto SIN pantallas de administración.
// Excluye siempre cualquier .env, cualquier token/clave, tests y node_modules, y verifica que el paquete no contenga claves privadas.
//
// Versión = código probado: si hay cambios locales sin confirmar (o archivos sin seguimiento) el nombre lo declara («-con-cambios») e incorpora
// los 12 primeros caracteres del SHA-256 del CONTENIDO empaquetado (SHA256SUMS), de modo que dos paquetes con el mismo commit pero distinto contenido
// no se confundan. El contenido (SHA256SUMS) es reproducible; los bytes del .tar.gz incluyen fechas de archivo y pueden variar entre ejecuciones.
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { cpSync, existsSync, mkdirSync, readFileSync, readdirSync, renameSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { basename, dirname, extname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const REPO = join(here, '..', '..');
const fail = message => { console.error(`\nERROR: ${message}`); process.exit(1); };
const FRONT = join(REPO, 'frontend');
const DIST = join(FRONT, 'dist-pilot');
const git = args => spawnSync('git', args, { cwd: REPO, encoding: 'utf8' }).stdout.trim();

if (!process.argv.includes('--sin-compilar')) {
  const result = spawnSync(process.execPath, [join(FRONT, 'scripts', 'build-production.mjs'), '--pilot'], { cwd: FRONT, stdio: 'inherit', env: { ...process.env, CERVESUR_ALLOWED_HOSTS: 'localhost' } });
  if (result.status !== 0) fail('La compilación del piloto falló.');
}
if (!existsSync(join(DIST, 'index.html'))) fail('Falta frontend/dist-pilot: ejecute sin --sin-compilar.');
const info = JSON.parse(readFileSync(join(DIST, 'build-info.json'), 'utf8'));
if (info.target !== 'server' || info.provider !== 'http' || info.apiBase !== 'same-origin' || info.pilot !== true) fail('frontend/dist-pilot no es una compilación de piloto (servidor, HTTP, mismo origen, pilot=true).');
if (!info.allowedHosts.includes('localhost')) fail('La compilación debe declarar «localhost» como host autorizado (acceso por túnel SSH).');

const commit = git(['rev-parse', '--short=12', 'HEAD']) || 'sin-git';
const dirty = git(['status', '--porcelain', '--', 'frontend', 'backend', 'deploy', 'database', 'tests', 'docs']) ? '-con-cambios' : '';
const stamp = new Date().toISOString().replace(/[-:]/g, '').replace(/\.\d+Z$/, 'Z').replace('T', '-');
const OUT = join(REPO, 'dist-paquete');
const TMP = join(OUT, `.ro-construccion-${process.pid}`);
rmSync(TMP, { recursive: true, force: true });
mkdirSync(TMP, { recursive: true });

const TEXT = new Set(['.sh', '.php', '.conf', '.sql', '.html', '.js', '.css', '.json', '.txt', '.md', '']);
const lf = file => { const text = readFileSync(file, 'utf8'); if (text.includes('\r\n')) writeFileSync(file, text.replace(/\r\n/g, '\n')); };
const excluded = src => basename(src).startsWith('.env') || ['node_modules', '.git', 'tests', 'uploads'].includes(basename(src));
const copy = (from, to) => { if (!existsSync(from)) fail(`Falta ${relative(REPO, from)}`); cpSync(from, join(TMP, to), { recursive: true, filter: src => !excluded(src) }); };

copy(DIST, 'public');
copy(join(REPO, 'backend'), 'backend');
copy(join(here, 'apache'), 'apache');
copy(join(here, 'scripts'), 'scripts');
copy(join(here, 'admin-mysql'), 'admin-mysql');
// SQL revisable (sin credenciales): procedimiento administrativo de la cuenta del piloto (cervesur_ro_visor), verificación y reversión acotada.
const schema = join(REPO, 'database', 'schema');
for (const f of ['01-cuenta-visor.sql', '02-verificar-privilegios-visor.sql', '99-revocar-cuenta-visor.sql']) copy(join(schema, 'piloto-visual', f), `admin-mysql/${f}`);
for (const f of ['UX-07D3A-preflight-produccion-solo-lectura.sql', 'UX-07D3A-comparacion-estructural-solo-lectura.sql']) copy(join(schema, f), `admin-mysql/operador/${f}`);
for (const f of ['INSTALACION-PILOTO-VISUAL-8091.md', 'RECUPERACION-PILOTO-VISUAL-8091.md']) copy(join(REPO, 'docs', f), `docs/${f}`);

const files = [];
const walk = dir => { for (const entry of readdirSync(dir).sort()) { const full = join(dir, entry); statSync(full).isDirectory() ? walk(full) : files.push(full); } };
walk(TMP);
for (const file of files) if (TEXT.has(extname(file).toLowerCase()) || basename(file) === '.htaccess') lf(file);
for (const file of files) {
  if (basename(file).startsWith('.env')) fail(`El paquete contiene un archivo .env: ${relative(TMP, file)}`);
  const text = readFileSync(file, 'latin1');
  if (/-----BEGIN [A-Z ]*PRIVATE KEY-----/.test(text)) fail(`Clave privada en el paquete: ${relative(TMP, file)}`);
  if (/CERVESUR_DIAG_TOKEN=[0-9a-f]{32,}|DB_PASS=[^\s_%]{8,}|JWT_SECRET=[0-9a-f]{32,}|OPERATOR_HASH=\$2y\$/.test(text)) fail(`Posible secreto en el paquete: ${relative(TMP, file)}`);
}
const sums = files.map(f => `${createHash('sha256').update(readFileSync(f)).digest('hex')}  ${relative(TMP, f).split('\\').join('/')}`);
const contentDigest = createHash('sha256').update(sums.join('\n')).digest('hex');
const name = `cervesur-react-piloto-${commit}${dirty ? `${dirty}-${contentDigest.slice(0, 12)}` : ''}-${stamp}`;
writeFileSync(join(TMP, 'VERSION'), `commit: ${commit}${dirty}\ncontenido-sha256: ${contentDigest}\nconstruido: ${new Date().toISOString()}\nentorno: piloto visual con informes reales (127.0.0.1:8091)\nbase-de-lectura: cervesur_portalbi (cuenta cervesur_ro_visor, creada aparte)\nfrontend: dist-pilot (sin pantallas de administración)\n`);
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
