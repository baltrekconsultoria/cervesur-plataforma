// Empaqueta el DIAGNÓSTICO AGREGADO DE SOLO LECTURA por túnel SSH (UX-07D.3B). No se conecta a ningún servidor ni despliega nada.
//   node deploy/tunel-ssh-readonly/empaquetar.mjs
// Resultado (ignorado por Git): dist-paquete/cervesur-react-ro-<commit>[-con-cambios-<huella>]-<fecha-hora UTC>.tar.gz, con SHA256SUMS interno
// y <nombre>.tar.gz.sha256 externo. No hay compilación de frontend: este entorno no publica ninguna aplicación.
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
const git = args => spawnSync('git', args, { cwd: REPO, encoding: 'utf8' }).stdout.trim();

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

copy(join(REPO, 'backend'), 'backend');
copy(join(here, 'apache'), 'apache');
copy(join(here, 'scripts'), 'scripts');
copy(join(here, 'admin-mysql'), 'admin-mysql');
// SQL revisable (sin credenciales): procedimiento administrativo, verificaciones y catálogo de la cuenta de diagnóstico.
const schema = join(REPO, 'database', 'schema');
for (const f of ['01-cuenta-diagnostico.sql', '02-verificar-privilegios-diagnostico.sql', '04-verificar-ausencia-de-escrituras.sql', '99-revocar-cuenta-diagnostico.sql']) copy(join(schema, 'admin-requiere-autorizacion', f), `admin-mysql/${f}`);
for (const f of ['UX-07D3A1-consultas-cuenta-diagnostica.sql', 'UX-07D3A-preflight-produccion-solo-lectura.sql', 'UX-07D3A-comparacion-estructural-solo-lectura.sql']) copy(join(schema, f), `admin-mysql/operador/${f}`);
for (const f of ['INSTALACION-DIAGNOSTICO-8090.md', 'RECUPERACION-DIAGNOSTICO-8090.md', 'ACCESO-PRODUCTIVO-SOLO-LECTURA.md']) copy(join(REPO, 'docs', f), `docs/${f}`);

const files = [];
const walk = dir => { for (const entry of readdirSync(dir).sort()) { const full = join(dir, entry); statSync(full).isDirectory() ? walk(full) : files.push(full); } };
walk(TMP);
for (const file of files) if (TEXT.has(extname(file).toLowerCase()) || basename(file) === '.htaccess') lf(file);
for (const file of files) {
  if (basename(file).startsWith('.env')) fail(`El paquete contiene un archivo .env: ${relative(TMP, file)}`);
  const text = readFileSync(file, 'latin1');
  if (/-----BEGIN [A-Z ]*PRIVATE KEY-----/.test(text)) fail(`Clave privada en el paquete: ${relative(TMP, file)}`);
  if (/CERVESUR_DIAG_TOKEN=[0-9a-f]{32,}|DB_PASS=[^\s_]{8,}/.test(text)) fail(`Posible secreto en el paquete: ${relative(TMP, file)}`);
}
const sums = files.map(f => `${createHash('sha256').update(readFileSync(f)).digest('hex')}  ${relative(TMP, f).split('\\').join('/')}`);
const contentDigest = createHash('sha256').update(sums.join('\n')).digest('hex');
const name = `cervesur-react-ro-${commit}${dirty ? `${dirty}-${contentDigest.slice(0, 12)}` : ''}-${stamp}`;
writeFileSync(join(TMP, 'VERSION'), `commit: ${commit}${dirty}\ncontenido-sha256: ${contentDigest}\nconstruido: ${new Date().toISOString()}\nentorno: diagnóstico agregado de solo lectura (127.0.0.1:8090)\nbase-de-lectura: cervesur_portalbi (cuenta cervesur_ro_diag, creada aparte)\nsin-frontend: sí\n`);
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
