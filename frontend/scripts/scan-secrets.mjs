// Auditoría de secretos y de artefactos prohibidos sobre lo que REALMENTE se publicaría (archivos que git versionaría
// según las reglas de ignorado) y sobre las compilaciones (dist, dist-qa, dist-qa-real) si existen.
// Uso: node scripts/scan-secrets.mjs   (desde frontend/). Sale con código 1 si encuentra algo.
import { execFileSync } from 'node:child_process';
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const TEXT = /\.(ts|tsx|js|mjs|cjs|json|md|html|css|php|yml|yaml|txt|example|htaccess)$|(^|\/)\.(env|npmrc|gitignore)/i;

const FORBIDDEN_NAME = [
  [/(^|\/)\.env(\.|$)/i, 'archivo de entorno'], [/\.(pem|key|crt|cer|p12|pfx|jks)$/i, 'certificado o clave'], [/\.(sql|dump|sqlite3?)$/i, 'copia de base de datos'],
  [/\.xlsx?$/i, 'hoja de cálculo'], [/id_rsa|\.kdbx$/i, 'material de credenciales'],
  [/canvaskit|main\.dart\.js|flutter_service_worker|flutter_bootstrap/i, 'artefacto de Flutter compilado'],
  [/(^|\/)(_referencia|_respaldo|respaldo|backups?)[^/]*\//i, 'copia de respaldo o referencia']
];
// Únicas plantillas de entorno permitidas (valores ficticios o banderas, sin credenciales).
const ALLOWED_ENV = /(^|\/)\.env\.(example|qa\.example)$/;
const PATTERNS = [
  ['JWT', /eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}/], ['clave privada', /BEGIN [A-Z ]*PRIVATE KEY/], ['token de GitHub', /gh[pousr]_[A-Za-z0-9]{20,}/],
  ['clave AWS', /AKIA[0-9A-Z]{16}/], ['secreto asignado', /(client_?secret|api_?key|secret_?key)\s*[:=]\s*['"][^'"\s]{8,}['"]/i], ['URL con credenciales', /https?:\/\/[^\s/:@]+:[^\s/@]+@/],
  ['ruta absoluta de Windows', /[A-Za-z]:\\Users\\/]
];
// Contraseñas literales: solo se vigilan en el código de la aplicación. Pruebas, E2E y QA usan contraseñas sintéticas por diseño.
const APP_CODE = rel => /^(src|public)\//.test(rel) && !/\.test\.tsx?$|^src\/test\//.test(rel);
const PASSWORD_LITERAL = /\bpassword\s*[:=]\s*['"][^'"\s]{4,}['"]/i;
// Valores públicos y sintéticos conocidos (fixture del repositorio): se permiten explícitamente.
const ALLOW_LINE = /synthetic-password|fixture-private/;

const walk = dir => readdirSync(dir).flatMap(n => { const p = join(dir, n); return statSync(p).isDirectory() ? walk(p) : [relative(root, p).replaceAll('\\', '/')]; });

// 1) Archivos que git versionaría (rastreados + no ignorados); si git no está disponible, recorrido completo sin node_modules.
let publishable;
let source = 'git (rastreados + no ignorados)';
try {
  publishable = execFileSync('git', ['ls-files', '-z', '--cached', '--others', '--exclude-standard', '--', '.'], { cwd: root, encoding: 'utf8' }).split('\0').filter(Boolean);
} catch {
  source = 'recorrido del disco (git no disponible)';
  publishable = walk(root).filter(f => !/^(node_modules|coverage)\//.test(f));
}
// 2) Compilaciones: son ignoradas por git, pero se auditan igualmente.
const builds = ['dist', 'dist-qa', 'dist-qa-real', 'dist-production', 'dist-pilot', 'dist-pilot-usuarios', 'dist-pilot-admin', 'dist-admin-write'].filter(d => existsSync(join(root, d)));
const files = [...new Set([...publishable, ...builds.flatMap(d => walk(join(root, d)).map(f => `${d}/${f}`.replace(`${d}/${d}/`, `${d}/`)))])];

const findings = [];
for (const rel of files) {
  if (rel === 'scripts/scan-secrets.mjs') continue;
  const p = join(root, rel);
  if (!existsSync(p)) continue;
  for (const [re, why] of FORBIDDEN_NAME) { if (re.test(rel) && !ALLOWED_ENV.test(rel)) findings.push(`${rel}: ${why}`); }
  if (!TEXT.test(rel) || statSync(p).size > 3e6) continue;
  readFileSync(p, 'utf8').split(/\r?\n/).forEach((line, i) => {
    if (ALLOW_LINE.test(line)) return;
    for (const [why, re] of PATTERNS) if (re.test(line)) findings.push(`${rel}:${i + 1}: ${why}`);
    if (APP_CODE(rel) && PASSWORD_LITERAL.test(line)) findings.push(`${rel}:${i + 1}: contraseña literal en código de aplicación`);
  });
}
console.log(`Archivos revisados: ${files.length} · origen: ${source} · compilaciones: ${builds.length ? builds.join(', ') : 'ninguna'}`);
if (findings.length) { console.error('HALLAZGOS:\n' + findings.map(f => '  ' + f).join('\n')); process.exit(1); }
console.log('Sin secretos, credenciales ni artefactos prohibidos.');
