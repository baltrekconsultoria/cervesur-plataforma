// Empaqueta el entorno de COMPATIBILIDAD PRODUCTIVA por túnel SSH (UX-07D.2C). No se conecta a ningún servidor ni despliega nada.
//   node deploy/tunel-ssh-compat/empaquetar.mjs                compila (CERVESUR_ALLOWED_HOSTS=localhost), ensambla y comprime
//   node deploy/tunel-ssh-compat/empaquetar.mjs --sin-compilar  reutiliza frontend/dist-production (debe ser de servidor y admitir localhost)
// Resultado (ignorado por Git): dist-paquete/cervesur-react-compat-<commit>-<fecha-hora UTC>.tar.gz, con SHA256SUMS.
// Excluye siempre cualquier .env y verifica que el paquete no contenga claves privadas.
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { cpSync, existsSync, mkdirSync, readFileSync, readdirSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { basename, dirname, extname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const REPO = join(here, '..', '..');
const FRONT = join(REPO, 'frontend');
const DIST = join(FRONT, 'dist-production');
const fail = message => { console.error(`\nERROR: ${message}`); process.exit(1); };
const git = args => spawnSync('git', args, { cwd: REPO, encoding: 'utf8' }).stdout.trim();

if (!process.argv.includes('--sin-compilar')) {
  const result = spawnSync(process.execPath, [join(FRONT, 'scripts', 'build-production.mjs')], { cwd: FRONT, stdio: 'inherit', env: { ...process.env, CERVESUR_ALLOWED_HOSTS: 'localhost' } });
  if (result.status !== 0) fail('La compilación de servidor falló.');
}
if (!existsSync(join(DIST, 'index.html'))) fail('Falta frontend/dist-production: ejecute sin --sin-compilar.');
const info = JSON.parse(readFileSync(join(DIST, 'build-info.json'), 'utf8'));
if (info.target !== 'server' || info.provider !== 'http' || info.apiBase !== 'same-origin') fail('frontend/dist-production no es una compilación de servidor (HTTP, mismo origen).');
if (!info.allowedHosts.includes('localhost')) fail('La compilación debe declarar «localhost» como host autorizado (acceso por túnel SSH).');

const commit = git(['rev-parse', '--short=12', 'HEAD']) || 'sin-git';
const dirty = git(['status', '--porcelain', '--', 'frontend', 'backend', 'deploy', 'database', 'tests']) ? '-con-cambios' : '';
const stamp = new Date().toISOString().replace(/[-:]/g, '').replace(/\.\d+Z$/, 'Z').replace('T', '-');
const name = `cervesur-react-compat-${commit}${dirty}-${stamp}`;
const OUT = join(REPO, 'dist-paquete');
const ROOT = join(OUT, name);
rmSync(ROOT, { recursive: true, force: true });
mkdirSync(ROOT, { recursive: true });

const TEXT = new Set(['.sh', '.php', '.conf', '.sql', '.html', '.js', '.css', '.json', '.txt', '.svg', '.map', '.md', '.htaccess', '']);
const lf = file => { const text = readFileSync(file, 'utf8'); if (text.includes('\r\n')) writeFileSync(file, text.replace(/\r\n/g, '\n')); };
const copy = (from, to, filter = () => true) => cpSync(from, join(ROOT, to), { recursive: true, filter: src => !basename(src).startsWith('.env') && filter(src) });

copy(DIST, 'public');
copy(join(REPO, 'backend'), 'backend', src => !['tests', 'node_modules', '.git'].includes(basename(src)));
copy(join(here, 'apache'), 'apache');
copy(join(here, 'scripts'), 'scripts');
copy(join(REPO, 'tests', 'isolated', 'productive_fixture.php'), 'scripts/productive_fixture.php'); // fixture sintético compartido con las pruebas HTTP
writeFileSync(join(ROOT, 'VERSION'), `commit: ${commit}${dirty}\nconstruido: ${new Date().toISOString()}\nentorno: compatibilidad productiva (127.0.0.1:8089)\nservidor-autorizado: ${info.allowedHosts.join(',')}\n`);

const files = [];
const walk = dir => { for (const entry of readdirSync(dir).sort()) { const full = join(dir, entry); statSync(full).isDirectory() ? walk(full) : files.push(full); } };
walk(ROOT);
for (const file of files) if (!['.png', '.jpg', '.ico', '.woff2', '.webp'].includes(extname(file).toLowerCase()) && (TEXT.has(extname(file)) || basename(file) === '.htaccess')) lf(file);

for (const file of files) {
  if (basename(file).startsWith('.env')) fail(`El paquete contiene un archivo .env: ${relative(ROOT, file)}`);
  if (/-----BEGIN [A-Z ]*PRIVATE KEY-----/.test(readFileSync(file, 'latin1'))) fail(`Clave privada en el paquete: ${relative(ROOT, file)}`);
}
const sums = files.filter(f => basename(f) !== 'SHA256SUMS').map(f => `${createHash('sha256').update(readFileSync(f)).digest('hex')}  ${relative(ROOT, f).split('\\').join('/')}`);
writeFileSync(join(ROOT, 'SHA256SUMS'), `${sums.join('\n')}\n`);

const tgz = join(OUT, `${name}.tar.gz`);
const tar = spawnSync('tar', ['-czf', `${name}.tar.gz`, name], { cwd: OUT, stdio: 'inherit' }); // rutas relativas: sirve con GNU tar y bsdtar
if (tar.status !== 0) fail('No se pudo crear el .tar.gz (se necesita «tar», incluido en Windows 10/11).');
const sha = createHash('sha256').update(readFileSync(tgz)).digest('hex');
console.log(`\nPaquete listo (nada se ha enviado ni instalado):\n  ${tgz}\n  Nombre: ${name}.tar.gz\n  SHA-256 del .tar.gz: ${sha}\n  Archivos: ${files.length + 1}`);
