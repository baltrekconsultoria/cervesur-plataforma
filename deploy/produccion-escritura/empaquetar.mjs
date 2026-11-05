// Construye el PAQUETE DE LIBERACIÓN de la FASE 2A del administrador React (UX-08H.1, modo admin_escritura). No se conecta a ningún servidor ni despliega nada.
//   node deploy/produccion-escritura/empaquetar.mjs --host=192.168.200.12 --out <carpeta-fuera-del-repo> [--sin-compilar] [--permitir-arbol-sucio] [--base <ruta-a-SHA256SUMS-de-la-fase-1>]
// Normal: se NIEGA a construir con el árbol sucio o sin commit identificado. --permitir-arbol-sucio es SOLO para ensayos: el nombre lleva «-con-cambios» y RELEASE.json dice produccion:false.
// Compila el frontend con `node scripts/build-production.mjs --admin-write` (hosts: localhost + el indicado, como la fase 1) EN UNA COPIA TEMPORAL de frontend/, para no tocar
// frontend/dist-admin-write. --sin-compilar reutiliza esa carpeta en SOLO LECTURA (debe declarar ya los hosts pedidos).
// Resultado en <out>: <nombre>.tar.gz + .sha256 + .RELEASE.json + DIFERENCIAS-vs-<commit base>.txt/.json (diferencias por archivo frente al SHA256SUMS del paquete instalado de la fase 1).
// Contenido del paquete: public/, backend/, scripts/, base/ (manifiesto de la fase 1), apache/ (nota; no se instala nada de Apache), e0/ y migraciones/ (si existen; los escribe otro
// equipo), database/ (las dos migraciones de audit_log con su .down y la plantilla de la cuenta 2A), docs/, README.md, VERSION, RELEASE.json y SHA256SUMS. Sin .env, claves, certificados,
// datos de QA, cuentas syn###, archivos deploy/qa-* ni secretos (se inspecciona el resultado).
import { spawnSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { cpSync, existsSync, mkdirSync, mkdtempSync, readFileSync, readdirSync, rmSync, statSync, symlinkSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { basename, dirname, extname, join, relative, resolve, sep } from 'node:path';
import { fileURLToPath } from 'node:url';
import { colocarBaseDeDatos, colocarFase2b, validarEstructura, validarFase2b } from './lib-empaquetado.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const REPO = resolve(here, '..', '..');
const FRONT = join(REPO, 'frontend');
const FASE2B = process.argv.includes('--fase=2b'); // UX-08I: paquete de la fase 2B (Usuarios) sobre la fase 2A RC2 ya operando
const BASE_COMMIT = FASE2B ? 'f539c9fd543d' : '3f859210c5c4';
const BASE_TAG = FASE2B ? 'FASE2A' : 'FASE1';
const BASE_DEFECTO = FASE2B
  ? resolve(REPO, '..', 'paquetes-qa', 'produccion-fase2a-rc2', 'cervesur-react-admin-fase2a-f539c9fd543d-20261104-225140Z', 'SHA256SUMS')
  : resolve(REPO, '..', 'paquetes-qa', 'produccion-v4', 'cervesur-react-admin-3f859210c5c4-20261103-211438Z', 'SHA256SUMS');
const fail = message => { console.error(`\nERROR: ${message}`); process.exit(1); };
const git = args => spawnSync('git', args, { cwd: REPO, encoding: 'utf8' }).stdout?.trim() ?? '';
const sha = data => createHash('sha256').update(data).digest('hex');
const rel = (root, file) => relative(root, file).split('\\').join('/');

const argv = process.argv.slice(2);
const valueOf = flag => { const i = argv.indexOf(flag); if (i === -1) return null; if (!argv[i + 1] || argv[i + 1].startsWith('--')) fail(`${flag} necesita un valor.`); return argv[i + 1]; };
const hostArg = argv.find(a => a.startsWith('--host='));
if (!hostArg) fail('--host=<IP o nombre interno de publicación> es obligatorio. Ej.: --host=192.168.200.12');
const HOST = hostArg.slice(7).toLowerCase();
if (!/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/.test(HOST) || HOST === 'localhost') fail(`--host no es un nombre o IP válido: «${HOST}»`);
const outArg = valueOf('--out');
if (!outArg) fail('--out <carpeta> es obligatorio (fuera del repositorio).');
const OUT = resolve(outArg);
if (OUT === REPO || OUT.startsWith(REPO + sep)) fail(`--out (${OUT}) está dentro del repositorio: use una carpeta externa para que el paquete nunca se versione.`);
const SUCIO_OK = argv.includes('--permitir-arbol-sucio');
const BASE_SUMS = resolve(valueOf('--base') ?? BASE_DEFECTO);

// 1. Árbol limpio y commit identificado
const dirtyStatus = git(['status', '--porcelain']);
if (dirtyStatus && !SUCIO_OK) fail(`El árbol de trabajo no está limpio; confirme o descarte antes de construir un paquete de liberación (o use --permitir-arbol-sucio solo para ensayos):\n${dirtyStatus.split('\n').slice(0, 10).join('\n')}`);
const commit = git(['rev-parse', 'HEAD']);
const branch = git(['branch', '--show-current']);
if (!/^[0-9a-f]{40}$/.test(commit)) fail('No se pudo identificar el commit.');
const dirty = dirtyStatus ? '-con-cambios' : '';
if (!existsSync(BASE_SUMS)) fail(`No se encuentra el SHA256SUMS de la fase 1 (${BASE_SUMS}). Indique --base <ruta>.`);

// 2. Frontend admin-write
const hosts = ['localhost', HOST];
let DIST; let scratch = null;
if (argv.includes('--sin-compilar')) DIST = join(FRONT, 'dist-admin-write');
else {
  scratch = mkdtempSync(join(tmpdir(), 'cervesur-front-build-'));
  const copia = join(scratch, 'frontend');
  cpSync(FRONT, copia, { recursive: true, filter: src => !['node_modules', 'dist-admin-write', 'dist-production', 'dist-pilot', 'dist-pilot-admin', 'dist-pilot-usuarios', '.git'].includes(basename(src)) && !basename(src).startsWith('.env') });
  symlinkSync(join(FRONT, 'node_modules'), join(copia, 'node_modules'), 'junction');
  const result = spawnSync(process.execPath, [join(copia, 'scripts', 'build-production.mjs'), '--admin-write'], { cwd: copia, stdio: 'inherit', env: { ...process.env, CERVESUR_ALLOWED_HOSTS: hosts.join(',') } });
  if (result.status !== 0) { rmSync(scratch, { recursive: true, force: true }); fail('La compilación admin-write falló.'); }
  DIST = join(copia, 'dist-admin-write');
}
if (!existsSync(join(DIST, 'index.html'))) fail(`Falta la compilación (${DIST}/index.html): ejecute sin --sin-compilar.`);
const info = JSON.parse(readFileSync(join(DIST, 'build-info.json'), 'utf8'));
if (info.target !== 'server' || info.provider !== 'http' || info.apiBase !== 'same-origin' || info.pilotMode !== 'admin-write') fail('La compilación no es la de servidor admin-write (HTTP, mismo origen).');
for (const h of hosts) if (!info.allowedHosts.includes(h)) fail(`La compilación no declara «${h}» como host autorizado (${info.allowedHosts.join(', ')}): ejecute sin --sin-compilar.`);

// 3. Ensamblado
const stamp = new Date().toISOString().replace(/[-:]/g, '').replace(/\.\d+Z$/, 'Z').replace('T', '-');
const short = commit.slice(0, 12);
const name = `cervesur-react-admin-fase${FASE2B ? '2b' : '2a'}-${short}${dirty}-${stamp}`;
const ROOT = join(OUT, name);
mkdirSync(OUT, { recursive: true });
rmSync(ROOT, { recursive: true, force: true });
mkdirSync(ROOT, { recursive: true });
const TEXT = new Set(['.sh', '.php', '.conf', '.sql', '.template', '.html', '.js', '.css', '.json', '.txt', '.svg', '.map', '.md', '.tsv', '.regex', '.awk', '.sed', '.example', '']);
const lf = file => { const text = readFileSync(file, 'utf8'); if (text.includes('\r\n')) writeFileSync(file, text.replace(/\r\n/g, '\n')); };
const EXCLUIR = ['tests', 'node_modules', '.git', '.github', 'ensayo'];
const copy = (from, to, filter = () => true) => cpSync(from, join(ROOT, to), { recursive: true, filter: src => !basename(src).startsWith('.env') && !/\.(key|pem|crt|p12|pfx)$/i.test(basename(src)) && filter(src) });
const need = path => { if (!existsSync(path)) fail(`Falta ${relative(REPO, path)}`); return path; };
const notas = [];

copy(DIST, 'public');
copy(need(join(REPO, 'backend')), 'backend', src => !EXCLUIR.includes(basename(src)));
need(join(REPO, 'backend', 'lib', 'admin_escritura.php'));
copy(need(join(here, 'scripts')), 'scripts');
copy(need(join(here, FASE2B ? 'README-FASE2B.md' : 'README.md')), 'README.md');
if (existsSync(join(here, 'apache'))) copy(join(here, 'apache'), 'apache');
for (const d of ['e0', 'migraciones']) {
  // Se excluyen las carpetas de ensayo y los arneses de prueba «probar-*» de esos kits (contienen datos de prueba a propósito: no son para el servidor)
  if (existsSync(join(here, d))) { copy(join(here, d), d, src => !EXCLUIR.includes(basename(src)) && !/^probar-/.test(basename(src))); notas.push(`${d}/ incluido (sin ensayo/ ni probar-*)`); }
  else notas.push(`${d}/ NO existe todavía: no se incluye`);
}
// Archivos de base de datos (solo las migraciones aditivas de audit_log, sus .down y la plantilla de la cuenta 2A; NO UX-08B3) en SUS RUTAS DEFINITIVAS:
// migraciones/repo/ (con SHA256SUMS) y e0/repo/ (ver lib-empaquetado.mjs). Defecto del RC 70a14f2: el LEEME decía «repo/…» y se copiaba a la raíz del paquete.
colocarBaseDeDatos(ROOT, REPO, fail);
notas.push('SQL colocados en migraciones/repo/ (con SHA256SUMS de 5 archivos) y e0/repo/');
if (FASE2B) {
  colocarFase2b(ROOT, REPO, fail);
  // El instalador de la fase 2A (A1/A2) y su reversión no forman parte del paquete 2B: lo instalado YA es la fase 2A.
  for (const f of ['actualizar.sh', 'modo-escritura.sh', 'revertir-codigo.sh', 'preflight-fase2a.sh']) rmSync(join(ROOT, 'scripts', f), { force: true });
  notas.push('fase2b/repo/ con las 4 plantillas UX-08I y su SHA256SUMS; sin los instaladores A1/A2 de la fase 2A');
}
for (const doc of FASE2B ? ['UX-08H1-LIBERACION-DIRECTA-PRODUCCION.md', 'UX-08I-FASE-2B-AUDITORIA-GO-NO-GO.md', 'UX-08I-FASE-2B-LIBERACION.md'] : ['UX-08H1-LIBERACION-DIRECTA-PRODUCCION.md']) if (existsSync(join(REPO, 'docs', doc))) copy(join(REPO, 'docs', doc), `docs/${doc}`); else notas.push(`docs/${doc} no existe: no se incluye`);

// Manifiesto de lo instalado en la fase 1 (backend/ y public/ de su SHA256SUMS): lo usan preflight y actualizar.sh para exigir que lo instalado ES la fase 1
const baseText = readFileSync(BASE_SUMS, 'utf8');
const baseMap = new Map();
for (const linea of baseText.split(/\r?\n/)) { const m = linea.match(/^([0-9a-f]{64}) [ *]?(.+)$/); if (m) baseMap.set(m[2].replace(/^\.\//, ''), m[1]); }
if (![...baseMap.keys()].some(p => p.startsWith('backend/')) || ![...baseMap.keys()].some(p => p.startsWith('public/'))) fail('El SHA256SUMS base no parece de un paquete de la fase 1 (sin backend/ ni public/).');
mkdirSync(join(ROOT, 'base'), { recursive: true });
writeFileSync(join(ROOT, 'base', `MANIFIESTO-${BASE_TAG}-${BASE_COMMIT}.sha256`), [...baseMap].filter(([p]) => p.startsWith('backend/') || p.startsWith('public/')).map(([p, h]) => `${h}  ${p}`).join('\n') + '\n');

// 4. LF, VERSION
const walk = (dir, out = []) => { for (const entry of readdirSync(dir).sort()) { const full = join(dir, entry); statSync(full).isDirectory() ? walk(full, out) : out.push(full); } return out; };
for (const file of walk(ROOT)) if (TEXT.has(extname(file).toLowerCase()) || basename(file) === '.htaccess') lf(file);
const construido = new Date().toISOString();
writeFileSync(join(ROOT, 'VERSION'), `commit: ${short}${dirty}\nrama: ${branch}\nconstruido: ${construido}\nentorno: administrador React ${FASE2B ? 'FASE 2B (Usuarios; interruptor APAGADO por defecto) sobre la fase 2A' : 'FASE 2A (modo admin_escritura tras A2; escrituras APAGADAS por defecto) sobre'} ${BASE_COMMIT}\nhosts-autorizados: ${info.allowedHosts.join(',')}\nproduccion: ${dirty ? 'NO (árbol con cambios: solo ensayo)' : 'sí'}\n`);

// 5. Inspección del contenido
let files = walk(ROOT);
const problems = [...validarEstructura(ROOT).map(m => `estructura del paquete: ${m}`), ...(FASE2B ? validarFase2b(ROOT).map(m => `fase 2B: ${m}`) : [])];
for (const file of files) {
  const r = rel(ROOT, file);
  if (basename(file).startsWith('.env')) problems.push(`.env: ${r}`);
  if (/\.(key|pem|crt|p12|pfx)$/i.test(r)) problems.push(`certificado o clave: ${r}`);
  if (/(^|\/)(qa-[a-z-]+|sembrar-qa[^/]*|\.env\.qa[^/]*|env-qa-[^/]*)(\/|$)/.test(r) || /(^|\/)qa-(admin|escritura)/.test(r)) problems.push(`archivo de QA: ${r}`);
  if (/(^|\/)tests?\//.test(r)) problems.push(`pruebas en el paquete: ${r}`);
  if (/UX-08B3/.test(r)) problems.push(`migración que NO se aplica en la fase 2A: ${r}`);
  const text = readFileSync(file, 'latin1');
  if (/-----BEGIN [A-Z ]*PRIVATE KEY-----/.test(text)) problems.push(`clave privada: ${r}`);
  if (/\bsyn\d{3}\b/.test(text) && !r.startsWith('docs/')) problems.push(`cuenta sintética syn### en ${r}`);
  if (/^(DB_PASS|AUTH_DB_PASS|STATE_DB_PASS|JWT_SECRET|CERVESUR_PILOT_SECRET|CERVESUR_ESCRITURA_PASS)=[A-Za-z0-9!]{16,}\s*$/m.test(text)) problems.push(`valor de secreto en ${r}`);
  if (/\$2[aby]\$\d\d\$(?!abcdefghijklmnopqrstuu)[./A-Za-z0-9]{53}/.test(text)) problems.push(`posible hash bcrypt en ${r}`);
  if (/IDENTIFIED BY '(?!\{PASS\}|__)[A-Za-z0-9!]{12,}'/.test(text)) problems.push(`contraseña en un SQL: ${r}`);
}
if (!existsSync(join(ROOT, 'backend', 'lib', 'write_support.php'))) problems.push('falta backend/lib/write_support.php');
for (const f of FASE2B ? ['scripts/instalar-codigo-2b.sh', 'scripts/privilegios-usuarios.sh', 'scripts/interruptor-usuarios.sh', 'scripts/interruptores.sh', 'scripts/apagar-escrituras.sh', 'scripts/verificar-posdespliegue.sh', 'scripts/comprobar-otros-portales.sh', 'scripts/lib.sh'] : ['scripts/actualizar.sh', 'scripts/modo-escritura.sh', 'scripts/interruptores.sh', 'scripts/apagar-escrituras.sh', 'scripts/revertir-codigo.sh', 'scripts/verificar-posdespliegue.sh', 'scripts/comprobar-otros-portales.sh', 'scripts/preflight-fase2a.sh', 'scripts/lib.sh']) if (!existsSync(join(ROOT, f))) problems.push(`falta ${f}`);
if (problems.length) { rmSync(ROOT, { recursive: true, force: true }); if (scratch) rmSync(scratch, { recursive: true, force: true }); fail(`El paquete no cumple las garantías de liberación:\n - ${problems.join('\n - ')}`); }

// 6. RELEASE.json (dentro del paquete: sin el SHA del .tar.gz, que no puede contener su propio hash) y SHA256SUMS
const claves = ['backend/lib/mode.php', 'backend/lib/admin_escritura.php', 'backend/router/AdminRouter.php', 'backend/router/AdminWriteRouter.php', 'backend/router/index.php'];
const hashDe = p => { const f = join(ROOT, p); return existsSync(f) ? sha(readFileSync(f)) : null; };
const releaseBase = {
  fase: FASE2B ? 'produccion-2b-usuarios' : 'produccion-2a-admin-escritura', commit, rama: branch, arbol_limpio: !dirty, produccion: !dirty, construido, hosts: info.allowedHosts, modo: FASE2B ? 'admin_escritura-fase2b' : 'admin_escritura-fase2a',
  base_instalada: BASE_COMMIT, escrituras: FASE2B ? 'Usuarios APAGADO por defecto (cuatro capas: ruta, guardia SQL, privilegios de la cuenta, aserción de arranque); rol y contraseña fuera de alcance (P5)' : 'apagadas hasta el Paso B (empresas → reportes → accesos, uno a uno); usuarios (fase 2B) bloqueado en código en 3 capas',
  huellas: Object.fromEntries(claves.map(p => [p, hashDe(p)])), notas
};
writeFileSync(join(ROOT, 'RELEASE.json'), `${JSON.stringify(releaseBase, null, 2)}\n`);
files = walk(ROOT).filter(f => basename(f) !== 'SHA256SUMS');
const sums = files.map(f => `${sha(readFileSync(f))}  ${rel(ROOT, f)}`);
writeFileSync(join(ROOT, 'SHA256SUMS'), `${sums.join('\n')}\n`);

// 7. Diferencias frente a la fase 1
const nuevoMap = new Map(sums.map(l => { const [h, ...p] = l.split('  '); return [p.join('  '), h]; }));
const grupoDe = p => (p.startsWith('backend/') ? 'backend' : p.startsWith('public/') ? 'public' : p.startsWith('scripts/') ? 'scripts' : /(^|\/)database\//.test(p) ? 'database' : p.startsWith('docs/') ? 'docs' : p.startsWith('e0/') ? 'e0' : p.startsWith('migraciones/') ? 'migraciones' : p.startsWith('base/') ? 'base' : 'otros');
const GRUPOS = ['backend', 'public', 'scripts', 'database', 'docs', 'e0', 'migraciones', 'base', 'otros'];
const dif = Object.fromEntries(GRUPOS.map(g => [g, { agregados: [], eliminados: [], modificados: [] }]));
let iguales = 0;
for (const [p, h] of nuevoMap) {
  if (!baseMap.has(p)) dif[grupoDe(p)].agregados.push({ ruta: p, sha256: h.slice(0, 12) });
  else if (baseMap.get(p) !== h) dif[grupoDe(p)].modificados.push({ ruta: p, antes: baseMap.get(p).slice(0, 12), despues: h.slice(0, 12) });
  else iguales++;
}
for (const [p, h] of baseMap) if (!nuevoMap.has(p)) dif[grupoDe(p)].eliminados.push({ ruta: p, sha256: h.slice(0, 12) });
// Puertas: hash de archivos y comparación del TEXTO de la puerta de rutas de piloto_admin
const baseDir = dirname(BASE_SUMS);
const baseFile = p => { const f = join(baseDir, p); return existsSync(f) ? readFileSync(f, 'utf8').replace(/\r\n/g, '\n') : null; };
const nuevoFile = p => readFileSync(join(ROOT, p), 'utf8');
const cambioArchivo = p => {
  const antes = baseMap.get(p) ?? null; const despues = nuevoMap.get(p) ?? null;
  return { ruta: p, existe_en_base: antes !== null, existe_en_nuevo: despues !== null, cambio: antes !== despues, antes: antes?.slice(0, 12) ?? null, despues: despues?.slice(0, 12) ?? null };
};
const extraerPuertaPilotoAdmin = text => {
  if (!text) return null;
  const ini = text.indexOf("if ($mode === 'piloto_admin') {");
  const fin = text.indexOf("'code' => 'piloto_admin'", ini);
  if (ini === -1 || fin === -1) return null;
  return text.slice(ini, text.indexOf('\n', fin) + 1);
};
const puertaBase = extraerPuertaPilotoAdmin(baseFile('backend/lib/mode.php')); const puertaNueva = extraerPuertaPilotoAdmin(nuevoFile('backend/lib/mode.php'));
const puerta = { comparable: puertaBase !== null && puertaNueva !== null, cambio: puertaBase !== null && puertaNueva !== null ? puertaBase !== puertaNueva : null, sha256_antes: puertaBase ? sha(puertaBase).slice(0, 12) : null, sha256_despues: puertaNueva ? sha(puertaNueva).slice(0, 12) : null };
const afirmaciones = {
  'backend/lib/mode.php': cambioArchivo('backend/lib/mode.php'),
  'backend/router/AdminRouter.php': cambioArchivo('backend/router/AdminRouter.php'),
  'puerta_de_rutas_piloto_admin': puerta
};
const tot = k => GRUPOS.reduce((n, g) => n + dif[g][k].length, 0);
const resumen = { agregados: tot('agregados'), eliminados: tot('eliminados'), modificados: tot('modificados'), iguales };
const nuevos = GRUPOS.flatMap(g => dif[g].agregados.map(a => a.ruta));
const estado = c => (c === null ? 'NO COMPARABLE (falta el archivo base junto al SHA256SUMS)' : c ? 'CAMBIÓ' : 'SIN CAMBIOS');
const txt = [];
txt.push(`DIFERENCIAS del paquete ${name} frente a la ${FASE2B ? 'fase 2A instalada' : 'fase 1 instalada'} (${BASE_COMMIT})`);
txt.push(`Base: ${BASE_SUMS}`);
txt.push(`Resumen: ${resumen.agregados} agregados · ${resumen.eliminados} eliminados · ${resumen.modificados} modificados · ${resumen.iguales} idénticos (por SHA-256 de archivo)`);
if (!FASE2B) txt.push('Nota: lo «eliminado» en scripts/, admin-mysql/, apache/ y docs/ es el kit de INSTALACIÓN de la fase 1 (instalar, pasos-instalacion, revertir = DESINSTALADOR, usuarios, TLS…): este paquete no lo');
if (!FASE2B) txt.push('lleva a propósito (no instala nada ni trae el desinstalador). Conserva tls.sh, preflight-acceso.php y la verificación de la fase 1 (verificar-fase1.sh) que usan actualizar.sh y revertir-codigo.sh.');
txt.push('');
txt.push('AFIRMACIONES SOBRE LAS PUERTAS (calculadas por huella de archivo y, la puerta de rutas, por el texto del bloque piloto_admin):');
txt.push(`  backend/lib/mode.php ........................ ${estado(afirmaciones['backend/lib/mode.php'].cambio)}  (${afirmaciones['backend/lib/mode.php'].antes} → ${afirmaciones['backend/lib/mode.php'].despues})`);
txt.push(`  backend/router/AdminRouter.php .............. ${afirmaciones['backend/router/AdminRouter.php'].existe_en_nuevo ? estado(afirmaciones['backend/router/AdminRouter.php'].cambio) : 'AUSENTE en el paquete nuevo'}  (${afirmaciones['backend/router/AdminRouter.php'].antes} → ${afirmaciones['backend/router/AdminRouter.php'].despues})`);
txt.push(`  puerta de rutas del modo piloto_admin ....... ${estado(puerta.cambio)}  (${puerta.sha256_antes} → ${puerta.sha256_despues})`);
txt.push('  (mode.php cambia porque el modo admin_escritura se añade a la lista de modos y a la puerta; el bloque de rutas de piloto_admin es el que se compara aparte)');
txt.push('');
txt.push(`ARCHIVOS NUEVOS (${nuevos.length}):`);
for (const n of nuevos) txt.push(`  + ${n}`);
for (const g of GRUPOS) {
  const d = dif[g]; if (!d.agregados.length && !d.eliminados.length && !d.modificados.length) continue;
  txt.push('', `== ${g}: ${d.agregados.length} agregados · ${d.eliminados.length} eliminados · ${d.modificados.length} modificados`);
  for (const a of d.agregados) txt.push(`  A ${a.ruta}  ${a.sha256}`);
  for (const e of d.eliminados) txt.push(`  D ${e.ruta}  ${e.sha256}`);
  for (const m of d.modificados) txt.push(`  M ${m.ruta}  ${m.antes} → ${m.despues}`);
}
const difTxt = join(OUT, `DIFERENCIAS-vs-${BASE_COMMIT}.txt`);
writeFileSync(difTxt, `${txt.join('\n')}\n`);
writeFileSync(join(OUT, `DIFERENCIAS-vs-${BASE_COMMIT}.json`), `${JSON.stringify({ paquete: name, base: BASE_COMMIT, base_sha256sums: BASE_SUMS, resumen, afirmaciones, nuevos, grupos: dif }, null, 2)}\n`);

// 8. Archivo final
const tgz = join(OUT, `${name}.tar.gz`);
const tar = spawnSync('tar', ['-czf', `${name}.tar.gz`, name], { cwd: OUT, stdio: 'inherit' }); // rutas relativas: sirve con GNU tar y bsdtar
if (scratch) rmSync(scratch, { recursive: true, force: true });
if (tar.status !== 0) fail('No se pudo crear el .tar.gz (se necesita «tar», incluido en Windows 10/11).');
const tgzSha = sha(readFileSync(tgz));
writeFileSync(`${tgz}.sha256`, `${tgzSha}  ${name}.tar.gz\n`);
writeFileSync(join(OUT, `${name}.RELEASE.json`), `${JSON.stringify({ ...releaseBase, paquete: `${name}.tar.gz`, sha256: tgzSha, archivos: files.length + 1, diferencias: resumen }, null, 2)}\n`);
console.log(`\nPaquete de la FASE ${FASE2B ? '2B' : '2A'} listo (nada se ha enviado ni instalado):\n  ${tgz}\n  SHA-256 del .tar.gz: ${tgzSha}\n  Commit: ${commit} (${branch})${dirty ? '  ⚠ ÁRBOL CON CAMBIOS: solo ensayo, produccion:false' : ', árbol limpio'}\n  Archivos: ${files.length + 1}\n  Diferencias: ${difTxt}\n  Notas: ${notas.join(' · ')}`);
