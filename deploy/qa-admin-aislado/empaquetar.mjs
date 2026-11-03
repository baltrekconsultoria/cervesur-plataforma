// Empaqueta el entorno ADMINISTRATIVO DE QA aislado (UX-08F). No se conecta a ningún servidor ni despliega nada.
//   node deploy/qa-admin-aislado/empaquetar.mjs [--out <carpeta>]                compila (CERVESUR_ALLOWED_HOSTS=localhost), ensambla y comprime
//   node deploy/qa-admin-aislado/empaquetar.mjs --sin-compilar [--out <carpeta>]  reutiliza frontend/dist-production (debe ser de servidor y admitir localhost)
// Resultado: <out>/cervesur-react-admin-qa-<commit>-<fecha-hora UTC>.tar.gz (+ .sha256), con SHA256SUMS dentro. --out debe estar FUERA del repositorio
// (por defecto: <carpeta temporal del sistema>/cervesur-paquetes). Excluye siempre .env, pruebas y secretos y verifica que no haya claves privadas.
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { cpSync, existsSync, mkdirSync, readFileSync, readdirSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { basename, dirname, extname, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const REPO = resolve(here, '..', '..');
const FRONT = join(REPO, 'frontend');
const DIST = join(FRONT, 'dist-production');
const fail = message => { console.error(`\nERROR: ${message}`); process.exit(1); };
const git = args => spawnSync('git', args, { cwd: REPO, encoding: 'utf8' }).stdout?.trim() ?? '';

const argv = process.argv.slice(2);
const outIndex = argv.indexOf('--out');
if (outIndex !== -1 && !argv[outIndex + 1]) fail('--out necesita una carpeta.');
const OUT = resolve(outIndex !== -1 ? argv[outIndex + 1] : join(tmpdir(), 'cervesur-paquetes'));
if (OUT === REPO || OUT.startsWith(REPO + sep)) fail(`--out (${OUT}) está dentro del repositorio: use una carpeta externa para que el paquete nunca se versione.`);

if (!argv.includes('--sin-compilar')) {
  const result = spawnSync(process.execPath, [join(FRONT, 'scripts', 'build-production.mjs')], { cwd: FRONT, stdio: 'inherit', env: { ...process.env, CERVESUR_ALLOWED_HOSTS: 'localhost' } });
  if (result.status !== 0) fail('La compilación de servidor falló.');
}
if (!existsSync(join(DIST, 'index.html'))) fail('Falta frontend/dist-production: ejecute sin --sin-compilar.');
const info = JSON.parse(readFileSync(join(DIST, 'build-info.json'), 'utf8'));
if (info.target !== 'server' || info.provider !== 'http' || info.apiBase !== 'same-origin') fail('frontend/dist-production no es una compilación de servidor (HTTP, mismo origen).');
if (!info.allowedHosts.includes('localhost')) fail('La compilación debe declarar «localhost» como host autorizado (acceso por túnel SSH).');

const commit = git(['rev-parse', '--short=12', 'HEAD']) || 'sin-git';
const dirty = git(['status', '--porcelain', '--', 'frontend/src', 'backend', 'deploy/qa-admin-aislado', 'database']) ? '-con-cambios' : '';
const stamp = new Date().toISOString().replace(/[-:]/g, '').replace(/\.\d+Z$/, 'Z').replace('T', '-');
const name = `cervesur-react-admin-qa-${commit}${dirty}-${stamp}`;
const ROOT = join(OUT, name);
mkdirSync(OUT, { recursive: true });
rmSync(ROOT, { recursive: true, force: true });
mkdirSync(ROOT, { recursive: true });

const TEXT = new Set(['.sh', '.php', '.conf', '.sql', '.template', '.html', '.js', '.css', '.json', '.txt', '.svg', '.map', '.md', '.htaccess', '.example', '']);
const lf = file => { const text = readFileSync(file, 'utf8'); if (text.includes('\r\n')) writeFileSync(file, text.replace(/\r\n/g, '\n')); };
const copy = (from, to, filter = () => true) => cpSync(from, join(ROOT, to), { recursive: true, filter: src => !basename(src).startsWith('.env') && filter(src) });
const need = path => { if (!existsSync(path)) fail(`Falta ${relative(REPO, path)}`); return path; };

copy(DIST, 'public');
// Backend: exactamente lo que necesita la API (api.php, router, controladores, modelos, lib); sin pruebas, node_modules, .git ni .env.
copy(need(join(REPO, 'backend')), 'backend', src => !['tests', 'node_modules', '.git', '.github'].includes(basename(src)));
copy(join(here, 'apache'), 'apache');
copy(join(here, 'scripts'), 'scripts');
cpSync(need(join(here, '.env.qa-admin.example')), join(ROOT, '.env.qa-admin.example')); // el único «.env*» permitido: sin valores
copy(need(join(here, 'README.md')), 'README.md');
for (const file of ['migrations/UX-08B-audit-log.up.sql', 'migrations/UX-08B-audit-log-immutable.up.sql', 'migrations/UX-08B3-reporte-grupo-nullable.up.sql', 'migrations/UX-08B3-reporte-grupo-nullable.down.sql', 'privileges/UX-08E-cuenta-admin-minima.sql.template']) {
  copy(need(join(REPO, 'database', file)), `database/${file}`);
}
writeFileSync(join(ROOT, 'VERSION'), `commit: ${commit}${dirty}\nconstruido: ${new Date().toISOString()}\nentorno: administrativo de QA aislado (127.0.0.1:<puerto elegido con --puerto; candidato 8094, pendiente de verificar en el servidor>)\nservidor-autorizado: ${info.allowedHosts.join(',')}\n`);

const files = [];
const walk = dir => { for (const entry of readdirSync(dir).sort()) { const full = join(dir, entry); statSync(full).isDirectory() ? walk(full) : files.push(full); } };
walk(ROOT);
for (const file of files) if (!['.png', '.jpg', '.ico', '.woff2', '.webp'].includes(extname(file).toLowerCase()) && (TEXT.has(extname(file)) || basename(file) === '.htaccess')) lf(file);

for (const file of files) {
  const rel = relative(ROOT, file).split('\\').join('/');
  if (basename(file).startsWith('.env') && basename(file) !== '.env.qa-admin.example') fail(`El paquete contiene un archivo .env: ${rel}`);
  if (/(^|\/)tests?\//.test(rel)) fail(`El paquete contiene pruebas: ${rel}`);
  const text = readFileSync(file, 'latin1');
  if (/-----BEGIN [A-Z ]*PRIVATE KEY-----/.test(text)) fail(`Clave privada en el paquete: ${rel}`);
  if (/^(DB_PASS|JWT_SECRET)=[A-Za-z0-9!]{16,}\s*$/m.test(text)) fail(`Valor de secreto en el paquete: ${rel}`);
}
const sums = files.filter(f => basename(f) !== 'SHA256SUMS').map(f => `${createHash('sha256').update(readFileSync(f)).digest('hex')}  ${relative(ROOT, f).split('\\').join('/')}`);
writeFileSync(join(ROOT, 'SHA256SUMS'), `${sums.join('\n')}\n`);

const tgz = join(OUT, `${name}.tar.gz`);
const tar = spawnSync('tar', ['-czf', `${name}.tar.gz`, name], { cwd: OUT, stdio: 'inherit' }); // rutas relativas: sirve con GNU tar y bsdtar
if (tar.status !== 0) fail('No se pudo crear el .tar.gz (se necesita «tar», incluido en Windows 10/11).');
const sha = createHash('sha256').update(readFileSync(tgz)).digest('hex');
writeFileSync(`${tgz}.sha256`, `${sha}  ${name}.tar.gz\n`);
console.log(`\nPaquete listo (nada se ha enviado ni instalado):\n  ${tgz}\n  Nombre: ${name}.tar.gz\n  SHA-256 del .tar.gz: ${sha}\n  Archivos: ${files.length + 1}\n  Carpeta de salida (fuera del repositorio): ${OUT}`);
