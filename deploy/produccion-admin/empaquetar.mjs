// Construye el PAQUETE DE LIBERACIÓN del administrador React (UX-08G · versión 1, SOLO LECTURA). No se conecta a ningún servidor ni despliega nada.
//   node deploy/produccion-admin/empaquetar.mjs --host=192.168.200.12 --out <carpeta-fuera-del-repo> [--sin-compilar]
// Es una envoltura ESTRICTA de deploy/tunel-ssh-piloto-admin/empaquetar.mjs (el mismo empaquetador que produjo el piloto desplegado: mismos scripts de instalación, actualización con
// reversión automática, verificación y publicación corporativa), con tres garantías añadidas:
//   1. se niega a construir si el árbol de trabajo no está limpio o si HEAD no está confirmado (el paquete identifica un commit exacto);
//   2. el paquete resultante se inspecciona: sin .env, claves, certificados, cuentas sintéticas de QA (syn###), datos de QA ni migraciones de escritura (esas son otra fase);
//   3. escribe RELEASE.json (commit, rama, huellas, hosts, modo) junto al paquete y el SHA-256 del .tar.gz, fuera del repositorio.
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { cpSync, existsSync, mkdirSync, readFileSync, readdirSync, rmSync, statSync, writeFileSync } from 'node:fs';
import { basename, dirname, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const REPO = resolve(here, '..', '..');
const fail = message => { console.error(`\nERROR: ${message}`); process.exit(1); };
const git = args => spawnSync('git', args, { cwd: REPO, encoding: 'utf8' }).stdout?.trim() ?? '';
const argv = process.argv.slice(2);
const outIndex = argv.indexOf('--out');
if (outIndex === -1 || !argv[outIndex + 1]) fail('--out <carpeta> es obligatorio (fuera del repositorio).');
const OUT = resolve(argv[outIndex + 1]);
if (OUT === REPO || OUT.startsWith(REPO + sep)) fail(`--out (${OUT}) está dentro del repositorio.`);
const hostArg = argv.find(a => a.startsWith('--host='));
if (!hostArg) fail('--host=<IP o nombre interno de publicación> es obligatorio (la aplicación solo admite «localhost» y ese host). Ej.: --host=192.168.200.12');

// 1. Árbol limpio y commit identificado.
const dirty = git(['status', '--porcelain']);
if (dirty) fail(`El árbol de trabajo no está limpio; confirme o descarte antes de construir un paquete de liberación:\n${dirty.split('\n').slice(0, 8).join('\n')}`);
const commit = git(['rev-parse', 'HEAD']);
const branch = git(['branch', '--show-current']);
if (!/^[0-9a-f]{40}$/.test(commit)) fail('No se pudo identificar el commit.');

// 2. Construcción con el empaquetador del piloto administrativo.
const build = spawnSync(process.execPath, [join(REPO, 'deploy', 'tunel-ssh-piloto-admin', 'empaquetar.mjs'), hostArg, ...(argv.includes('--sin-compilar') ? ['--sin-compilar'] : [])], { cwd: REPO, encoding: 'utf8', stdio: ['ignore', 'pipe', 'inherit'] });
if (build.status !== 0) fail('El empaquetador del piloto administrativo falló.');
const built = readdirSync(join(REPO, 'dist-paquete')).filter(f => f.startsWith('cervesur-react-admin-') && f.endsWith('.tar.gz')).map(f => ({ f, t: statSync(join(REPO, 'dist-paquete', f)).mtimeMs })).sort((a, b) => b.t - a.t)[0]?.f;
if (!built) fail('No se encontró el paquete construido en dist-paquete/.');
if (/-con-cambios/.test(built)) fail(`El paquete se marcó «con-cambios» (${built}): el árbol no estaba limpio.`);
mkdirSync(OUT, { recursive: true });
const name = built.replace(/\.tar\.gz$/, '');
const dest = join(OUT, name);
rmSync(dest, { recursive: true, force: true });
cpSync(join(REPO, 'dist-paquete', name), dest, { recursive: true });

// 3. Inspección del contenido.
const files = [];
const walk = dir => { for (const entry of readdirSync(dir).sort()) { const full = join(dir, entry); statSync(full).isDirectory() ? walk(full) : files.push(full); } };
walk(dest);
const problems = [];
for (const file of files) {
  const rel = relative(dest, file).split('\\').join('/');
  if (basename(file).startsWith('.env')) problems.push(`.env: ${rel}`);
  if (/\.(key|pem|crt|p12|pfx)$/i.test(rel)) problems.push(`certificado o clave: ${rel}`);
  if (/(^|\/)qa-admin|sembrar-qa|\.env\.qa-admin/.test(rel)) problems.push(`archivo de la QA aislada: ${rel}`);
  if (/(^|\/)database\/migrations\//.test(rel)) problems.push(`migración de escritura en la versión 1: ${rel}`);
  const text = readFileSync(file, 'latin1');
  if (/-----BEGIN [A-Z ]*PRIVATE KEY-----/.test(text)) problems.push(`clave privada: ${rel}`);
  if (/\bsyn\d{3}\b/.test(text) && !rel.startsWith('docs/')) problems.push(`cuenta sintética syn### en ${rel}`);
  if (/^(DB_PASS|JWT_SECRET|CERVESUR_PILOT_SECRET)=[A-Za-z0-9!]{16,}\s*$/m.test(text)) problems.push(`valor de secreto en ${rel}`);
}
const build_info = JSON.parse(readFileSync(join(dest, 'public', 'build-info.json'), 'utf8'));
if (build_info.pilotMode !== 'admin' || build_info.target !== 'server' || build_info.apiBase !== 'same-origin') problems.push('public/build-info.json no es la compilación administrativa de servidor');
if (!build_info.allowedHosts.includes(hostArg.slice(7))) problems.push(`la compilación no admite el host ${hostArg.slice(7)}`);
if (!existsSync(join(dest, 'backend', 'lib', 'write_support.php'))) problems.push('falta backend/lib/write_support.php (la versión 1 distribuye la capa de UX-08 inalcanzable)');
if (problems.length) fail(`El paquete no cumple las garantías de liberación:\n - ${problems.join('\n - ')}`);

// 4. Archivo final y manifiesto de liberación (fuera del repositorio).
const tgz = join(OUT, `${name}.tar.gz`);
const tar = spawnSync('tar', ['-czf', `${name}.tar.gz`, name], { cwd: OUT, stdio: 'inherit' });
if (tar.status !== 0) fail('No se pudo crear el .tar.gz.');
const sha = createHash('sha256').update(readFileSync(tgz)).digest('hex');
writeFileSync(`${tgz}.sha256`, `${sha}  ${name}.tar.gz\n`);
const release = {
  fase: 'produccion-1-solo-lectura', paquete: `${name}.tar.gz`, sha256: sha, commit, rama: branch, arbol_limpio: true,
  construido: new Date().toISOString(), hosts: build_info.allowedHosts, modo: 'piloto_admin (solo lectura)', escrituras: 'inalcanzables (puerta de rutas + GuardedPDO + cuenta SELECT)',
  migraciones_incluidas: [], archivos: files.length + 1
};
writeFileSync(join(OUT, `${name}.RELEASE.json`), `${JSON.stringify(release, null, 2)}\n`);
console.log(`\nPaquete de liberación (versión 1, solo lectura) listo; nada se ha enviado ni instalado:\n  ${tgz}\n  SHA-256: ${sha}\n  Commit: ${commit} (${branch}), árbol limpio\n  Manifiesto: ${join(OUT, `${name}.RELEASE.json`)}`);
