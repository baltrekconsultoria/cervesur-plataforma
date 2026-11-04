// Empaqueta el entorno de ESCRITURA ADMINISTRATIVA de QA (UX-08H, backend en modo admin_escritura). No se conecta a ningún servidor ni despliega nada.
//   node deploy/qa-escritura/empaquetar.mjs [--out <carpeta>]                  compila el frontend `build:admin-write` en una COPIA temporal (CERVESUR_ALLOWED_HOSTS=localhost), ensambla y comprime
//   node deploy/qa-escritura/empaquetar.mjs --bundle <dir> [--out <carpeta>]    usa una compilación ya hecha (debe ser admin-write, de servidor y admitir localhost)
//   node deploy/qa-escritura/empaquetar.mjs --sin-compilar [--out <carpeta>]    reutiliza frontend/dist-admin-write SOLO LECTURA (si admite localhost; jamás la modifica)
// La compilación NO toca frontend/dist-admin-write (puede ser la que se despliega en producción con otro host): se hace en una copia en la carpeta temporal.
// Resultado: <out>/cervesur-react-escritura-qa-<commit>-<fecha-hora UTC>.tar.gz (+ .sha256), con SHA256SUMS dentro. --out debe estar FUERA del repositorio
// (por defecto: <carpeta temporal del sistema>/cervesur-paquetes). Excluye siempre .env, pruebas y secretos y verifica que no haya claves privadas.
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { cpSync, existsSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, statSync, symlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { basename, dirname, extname, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const REPO = resolve(here, '..', '..');
const FRONT = join(REPO, 'frontend');
const fail = message => { console.error(`\nERROR: ${message}`); process.exit(1); };
const git = args => spawnSync('git', args, { cwd: REPO, encoding: 'utf8' }).stdout?.trim() ?? '';

const argv = process.argv.slice(2);
const valueOf = flag => { const i = argv.indexOf(flag); if (i === -1) return null; if (!argv[i + 1]) fail(`${flag} necesita un valor.`); return argv[i + 1]; };
const OUT = resolve(valueOf('--out') ?? join(tmpdir(), 'cervesur-paquetes'));
if (OUT === REPO || OUT.startsWith(REPO + sep)) fail(`--out (${OUT}) está dentro del repositorio: use una carpeta externa para que el paquete nunca se versione.`);

let DIST; let scratch = null;
if (valueOf('--bundle')) DIST = resolve(valueOf('--bundle'));
else if (argv.includes('--sin-compilar')) DIST = join(FRONT, 'dist-admin-write');
else {
  // Copia temporal del frontend (sin node_modules, que se enlaza): así frontend/dist-admin-write queda intacto.
  scratch = mkdtempSync(join(tmpdir(), 'cervesur-front-build-'));
  const copia = join(scratch, 'frontend');
  cpSync(FRONT, copia, { recursive: true, filter: src => !['node_modules', 'dist-admin-write', 'dist-production', 'dist-pilot', 'dist-pilot-admin', 'dist-pilot-usuarios', '.git'].includes(basename(src)) && !basename(src).startsWith('.env') });
  symlinkSync(join(FRONT, 'node_modules'), join(copia, 'node_modules'), 'junction');
  const result = spawnSync(process.execPath, [join(copia, 'scripts', 'build-production.mjs'), '--admin-write'], { cwd: copia, stdio: 'inherit', env: { ...process.env, CERVESUR_ALLOWED_HOSTS: 'localhost' } });
  if (result.status !== 0) { rmSync(scratch, { recursive: true, force: true }); fail('La compilación admin-write falló.'); }
  DIST = join(copia, 'dist-admin-write');
}
if (!existsSync(join(DIST, 'index.html'))) fail(`Falta la compilación (${DIST}/index.html): ejecute sin --sin-compilar.`);
const info = JSON.parse(readFileSync(join(DIST, 'build-info.json'), 'utf8'));
if (info.target !== 'server' || info.provider !== 'http' || info.apiBase !== 'same-origin') fail('La compilación no es de servidor (HTTP, mismo origen).');
if (info.pilotMode !== 'admin-write') fail('La compilación no es la de administración con escrituras (pilotMode admin-write).');
if (!info.allowedHosts.includes('localhost')) fail('La compilación debe declarar «localhost» como host autorizado (acceso por túnel SSH).');

const commit = git(['rev-parse', '--short=12', 'HEAD']) || 'sin-git';
const dirty = git(['status', '--porcelain', '--', 'frontend/src', 'backend', 'deploy/qa-escritura', 'database']) ? '-con-cambios' : '';
const stamp = new Date().toISOString().replace(/[-:]/g, '').replace(/\.\d+Z$/, 'Z').replace('T', '-');
const name = `cervesur-react-escritura-qa-${commit}${dirty}-${stamp}`;
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
need(join(REPO, 'backend', 'lib', 'admin_escritura.php'));
copy(join(here, 'apache'), 'apache');
copy(join(here, 'scripts'), 'scripts');
cpSync(need(join(here, 'env-qa-escritura.example')), join(ROOT, 'env-qa-escritura.example')); // plantilla sin valores
copy(need(join(here, 'README.md')), 'README.md');
for (const file of ['migrations/UX-08B-audit-log.up.sql', 'migrations/UX-08B-audit-log-immutable.up.sql', 'migrations/UX-08B3-reporte-grupo-nullable.up.sql', 'migrations/UX-08B3-reporte-grupo-nullable.down.sql',
  'privileges/UX-08H-cuenta-escritura-produccion.sql.template', 'schema/piloto-admin/01-cuentas-lectura.sql', 'schema/piloto-admin/02-estado-base-y-cuenta.sql']) {
  copy(need(join(REPO, 'database', file)), `database/${file}`);
}
writeFileSync(join(ROOT, 'VERSION'), `commit: ${commit}${dirty}\nconstruido: ${new Date().toISOString()}\nentorno: escritura administrativa de QA aislado, modo admin_escritura (127.0.0.1:<puerto elegido con --puerto; candidato 8095>)\nservidor-autorizado: ${info.allowedHosts.join(',')}\n`);

const files = [];
const walk = dir => { for (const entry of readdirSync(dir).sort()) { const full = join(dir, entry); statSync(full).isDirectory() ? walk(full) : files.push(full); } };
walk(ROOT);
for (const file of files) if (!['.png', '.jpg', '.ico', '.woff2', '.webp'].includes(extname(file).toLowerCase()) && (TEXT.has(extname(file)) || basename(file) === '.htaccess')) lf(file);

for (const file of files) {
  const rel = relative(ROOT, file).split('\\').join('/');
  if (basename(file).startsWith('.env')) fail(`El paquete contiene un archivo .env: ${rel}`);
  if (/(^|\/)tests?\//.test(rel)) fail(`El paquete contiene pruebas: ${rel}`);
  const text = readFileSync(file, 'latin1');
  if (/-----BEGIN [A-Z ]*PRIVATE KEY-----/.test(text)) fail(`Clave privada en el paquete: ${rel}`);
  if (/^(DB_PASS|AUTH_DB_PASS|STATE_DB_PASS|JWT_SECRET|CERVESUR_PILOT_SECRET)=[A-Za-z0-9!]{16,}\s*$/m.test(text)) fail(`Valor de secreto en el paquete: ${rel}`);
}
const sums = files.filter(f => basename(f) !== 'SHA256SUMS').map(f => `${createHash('sha256').update(readFileSync(f)).digest('hex')}  ${relative(ROOT, f).split('\\').join('/')}`);
writeFileSync(join(ROOT, 'SHA256SUMS'), `${sums.join('\n')}\n`);

const tgz = join(OUT, `${name}.tar.gz`);
const tar = spawnSync('tar', ['-czf', `${name}.tar.gz`, name], { cwd: OUT, stdio: 'inherit' }); // rutas relativas: sirve con GNU tar y bsdtar
if (scratch) rmSync(scratch, { recursive: true, force: true });
if (tar.status !== 0) fail('No se pudo crear el .tar.gz (se necesita «tar», incluido en Windows 10/11).');
const sha = createHash('sha256').update(readFileSync(tgz)).digest('hex');
writeFileSync(`${tgz}.sha256`, `${sha}  ${name}.tar.gz\n`);
console.log(`\nPaquete listo (nada se ha enviado ni instalado):\n  ${tgz}\n  Nombre: ${name}.tar.gz\n  SHA-256 del .tar.gz: ${sha}\n  Archivos: ${files.length + 1}\n  Carpeta de salida (fuera del repositorio): ${OUT}`);
