// Empaqueta las herramientas de AUDITORÍA DE SOLO LECTURA (UX-08F.1) en un único .tar.gz con SHA256SUMS. No se conecta a ningún servidor.
//   node deploy/auditoria/empaquetar-auditoria.mjs [--out <carpeta>]      (--out debe estar FUERA del repositorio; por defecto, la carpeta temporal)
// Contenido: los scripts de solo lectura, el manifiesto de huellas del backend y las rutas del repositorio (generados AHORA, en el commit actual),
// el verificador del interruptor USER_ADMIN_WRITES y un LEEME. Sin .env, sin claves, sin datos, sin pruebas. Necesita «bash» (Git Bash en Windows).
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { cpSync, existsSync, mkdirSync, readFileSync, readdirSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { basename, dirname, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const REPO = resolve(here, '..', '..');
const fail = message => { console.error(`\nERROR: ${message}`); process.exit(1); };
const git = args => spawnSync('git', args, { cwd: REPO, encoding: 'utf8' }).stdout?.trim() ?? '';
const argv = process.argv.slice(2);
const outIndex = argv.indexOf('--out');
if (outIndex !== -1 && !argv[outIndex + 1]) fail('--out necesita una carpeta.');
const OUT = resolve(outIndex !== -1 ? argv[outIndex + 1] : join(tmpdir(), 'cervesur-paquetes'));
if (OUT === REPO || OUT.startsWith(REPO + sep)) fail(`--out (${OUT}) está dentro del repositorio: use una carpeta externa para que el paquete nunca se versione.`);

const commit = git(['rev-parse', '--short=12', 'HEAD']) || 'sin-git';
const dirty = git(['status', '--porcelain', '--', 'deploy/auditoria', 'deploy/qa-admin-aislado/scripts', 'backend']) ? '-con-cambios' : '';
const stamp = new Date().toISOString().replace(/[-:]/g, '').replace(/\.\d+Z$/, 'Z').replace('T', '-');
const name = `cervesur-auditoria-solo-lectura-${commit}${dirty}-${stamp}`;
const ROOT = join(OUT, name);
const AUD = join(ROOT, 'deploy', 'auditoria');
mkdirSync(OUT, { recursive: true });
rmSync(ROOT, { recursive: true, force: true });
mkdirSync(AUD, { recursive: true });

// El manifiesto y las rutas se generan en el commit actual y fuera del repositorio (no ensucian el árbol de trabajo).
const gen = spawnSync('bash', [join(here, 'generar-manifiesto.sh').split('\\').join('/')], { cwd: REPO, encoding: 'utf8', env: { ...process.env, MANIFIESTO_SALIDA: AUD.split('\\').join('/') } });
if (gen.status !== 0) fail(`No se pudieron generar el manifiesto y las rutas (se necesita «bash»; en Windows, Git Bash):\n${gen.stderr || gen.stdout}`);

const scripts = ['auditoria-solo-lectura.sh', 'inventario-divergencia.sh', 'flutter-original-solo-lectura.sh', 'autorizacion-403-solo-lectura.sh', 'puerto-candidato-solo-lectura.sh'];
for (const file of scripts) {
  if (!existsSync(join(here, file))) fail(`Falta deploy/auditoria/${file}`);
  cpSync(join(here, file), join(AUD, file));
}
const interruptor = join(REPO, 'deploy', 'qa-admin-aislado', 'scripts', 'interruptor-escrituras.sh');
if (!existsSync(interruptor)) fail('Falta deploy/qa-admin-aislado/scripts/interruptor-escrituras.sh');
cpSync(interruptor, join(AUD, 'interruptor-escrituras.sh'));
// UX-08G: preflight de liberación (solo lectura) y huellas del backend del piloto administrativo de referencia (paquete cef58da900e8), para contrastar lo desplegado.
for (const f of ['preflight-produccion.sh', 'manifiesto-piloto-cef58da900e8.txt']) {
  const src = join(REPO, 'deploy', 'produccion-admin', f);
  if (!existsSync(src)) fail(`Falta deploy/produccion-admin/${f}`);
  cpSync(src, join(AUD, f));
}

writeFileSync(join(ROOT, 'LEEME.txt'), `Cervesur · herramientas de AUDITORÍA DE SOLO LECTURA (commit ${commit}${dirty})
Construido: ${new Date().toISOString()}

Nada de este paquete modifica el servidor. Ejecute desde esta carpeta (la que contiene «deploy/»):

  bash deploy/auditoria/puerto-candidato-solo-lectura.sh 8094            # ¿está libre el puerto candidato? (8090-8093 ya están ocupados)
  sudo bash deploy/auditoria/preflight-produccion.sh --paquete <carpeta del paquete de liberación extraído>   # UX-08G, solo lectura
  sudo bash deploy/auditoria/inventario-divergencia.sh /opt/cervesur-react-admin/backend deploy/auditoria/manifiesto-piloto-cef58da900e8.txt > divergencia-admin-vs-piloto.txt
  bash deploy/auditoria/interruptor-escrituras.sh                         # ¿USER_ADMIN_WRITES apagado en los portales publicados? (sudo si el .env es 640)
  bash deploy/auditoria/inventario-divergencia.sh /opt/cervesur-react-admin/backend    > divergencia-admin.txt
  bash deploy/auditoria/inventario-divergencia.sh /opt/cervesur-react-usuarios/backend > divergencia-usuarios.txt
  bash deploy/auditoria/inventario-divergencia.sh /var/www/cervesur/backend            > divergencia-original.txt
  bash deploy/auditoria/autorizacion-403-solo-lectura.sh /opt/cervesur-react-admin/backend --mysql > autorizacion-admin.txt
  bash deploy/auditoria/flutter-original-solo-lectura.sh > flutter-original.txt 2>&1
  bash deploy/auditoria/auditoria-solo-lectura.sh > auditoria.txt 2>&1

Revise cada archivo de salida ANTES de compartirlo. Ninguno debe contener contraseñas, claves, tokens ni enlaces completos.
`);

const files = [];
const walk = dir => { for (const entry of readdirSync(dir).sort()) { const full = join(dir, entry); statSync(full).isDirectory() ? walk(full) : files.push(full); } };
walk(ROOT);
for (const file of files) { const text = readFileSync(file, 'utf8'); if (text.includes('\r\n')) writeFileSync(file, text.replace(/\r\n/g, '\n')); }
for (const file of files) {
  const rel = relative(ROOT, file).split('\\').join('/');
  if (basename(file).startsWith('.env')) fail(`El paquete contiene un archivo .env: ${rel}`);
  const text = readFileSync(file, 'latin1');
  if (/-----BEGIN [A-Z ]*PRIVATE KEY-----/.test(text)) fail(`Clave privada en el paquete: ${rel}`);
  if (/^(DB_PASS|JWT_SECRET)=[A-Za-z0-9!]{12,}\s*$/m.test(text)) fail(`Valor de secreto en el paquete: ${rel}`);
}
const sums = files.map(f => `${createHash('sha256').update(readFileSync(f)).digest('hex')}  ${relative(ROOT, f).split('\\').join('/')}`);
writeFileSync(join(ROOT, 'SHA256SUMS'), `${sums.join('\n')}\n`);

const tgz = join(OUT, `${name}.tar.gz`);
const tar = spawnSync('tar', ['-czf', `${name}.tar.gz`, name], { cwd: OUT, stdio: 'inherit' });
if (tar.status !== 0) fail('No se pudo crear el .tar.gz (se necesita «tar», incluido en Windows 10/11).');
const sha = createHash('sha256').update(readFileSync(tgz)).digest('hex');
writeFileSync(`${tgz}.sha256`, `${sha}  ${name}.tar.gz\n`);
console.log(`\nPaquete de auditoría listo (nada se ha enviado ni ejecutado):\n  ${tgz}\n  SHA-256 del .tar.gz: ${sha}\n  Archivos: ${files.length + 1}\n  Carpeta de salida (fuera del repositorio): ${OUT}`);
