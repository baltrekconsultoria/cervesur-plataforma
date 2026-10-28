// Verifica que una compilación de servidor NO es una compilación de demostración ni de QA.
// Uso: node scripts/verify-production-build.mjs [carpeta=dist-production] [dominio…]   (sale con código 1 si algo no cumple)
import { existsSync, readdirSync, readFileSync, statSync } from 'node:fs';
import { dirname, isAbsolute, join, relative } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const [dirArg = 'dist-production', ...expectedHosts] = process.argv.slice(2);
const dir = isAbsolute(dirArg) ? dirArg : join(root, dirArg);
const problems = [];
const check = (ok, message) => { if (!ok) problems.push(message); };

if (!existsSync(dir)) { console.error(`No existe ${dirArg}. Ejecute npm run build:production.`); process.exit(1); }
const walk = d => readdirSync(d).flatMap(n => { const p = join(d, n); return statSync(p).isDirectory() ? walk(p) : [p]; });
const files = walk(dir);
const rel = f => relative(dir, f).replaceAll('\\', '/');
const text = files.filter(f => /\.(js|html|css|json|htaccess|txt)$|(^|[\\/])\.htaccess$/.test(f)).map(f => [rel(f), readFileSync(f, 'utf8')]);
const bundle = text.filter(([n]) => n.endsWith('.js')).map(([, c]) => c).join('\n');

// 1) Manifiesto de la compilación
const infoFile = join(dir, 'build-info.json');
check(existsSync(infoFile), 'Falta build-info.json: no es una compilación generada por build:production.');
if (existsSync(infoFile)) {
  const info = JSON.parse(readFileSync(infoFile, 'utf8'));
  check(info.target === 'server' && info.provider === 'http' && info.apiBase === 'same-origin', 'build-info.json no declara target=server, provider=http y apiBase=same-origin.');
  check(Array.isArray(info.allowedHosts) && info.allowedHosts.length > 0, 'build-info.json no declara dominios autorizados.');
  for (const host of expectedHosts) check(info.allowedHosts?.includes(host), `El dominio ${host} no figura en build-info.json.`);
}

// 2) Sin proveedor ni datos simulados: el simulador debe haberse eliminado del bundle
for (const marker of ['maria.rios', 'carlos.vega', 'elena.torres', 'ejemplo.test', 'Andes Logística', 'Textiles del Sur', 'expireSessions', 'DemoControls', 'Entorno QA', 'QA · backend']) {
  check(!bundle.includes(marker), `El bundle contiene el marcador de datos simulados «${marker}»: la compilación incluye el proveedor mock.`);
}
// 3) Usa realmente PHP
for (const endpoint of ['/api/login', '/api/logout', '/api/user/profile', '/api/reportes', '/visor']) check(bundle.includes(endpoint), `El bundle no referencia ${endpoint}: no usa el proveedor HTTP.`);
check(/same-origin|location\.origin|\.origin/.test(bundle), 'No se encontró el uso del origen de la página como base de la API.');
check(!/VITE_API_BASE_URL["'`]?\s*[:=]\s*["'`]https?:/.test(bundle), 'El bundle contiene una URL de API fija.');

// 4) Sin QA, sin endpoint heredado de tokens, sin credenciales
check(!files.some(f => rel(f).startsWith('qa/')), 'Se publicaría la carpeta qa/ (página local de Power BI de pruebas).');
check(!bundle.includes('/qa/powerbi-view.html') && !bundle.includes('powerbi-view.html'), 'El bundle referencia la página local de pruebas de Power BI.');
check(!bundle.includes('/api/powerbi'), 'El bundle llama al endpoint heredado /api/powerbi (devuelve un token OAuth general).');
check(!/powerbi_(client|tenant|clientsecret)|client_secret|BEGIN [A-Z ]*PRIVATE KEY|eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}/i.test(bundle), 'El bundle contiene credenciales o secretos.');
check(!files.some(f => /\.map$/.test(f)), 'Hay mapas de código fuente (.map) en la compilación.');
check(!files.some(f => /(^|[\\/])\.env/.test(rel(f))), 'Hay archivos .env en la compilación.');
check(!files.some(f => /\.(sql|pem|key|bak|log)$/i.test(f)), 'Hay archivos sensibles (.sql, .pem, .key, .bak, .log) en la compilación.');

// 5) Archivos necesarios para Apache
check(existsSync(join(dir, 'index.html')), 'Falta index.html.');
check(existsSync(join(dir, '.htaccess')), 'Falta .htaccess (reescritura de rutas de React y cabeceras).');
if (existsSync(join(dir, '.htaccess'))) {
  const ht = readFileSync(join(dir, '.htaccess'), 'utf8');
  check(/RewriteCond %\{REQUEST_URI\} !\^\/api/.test(ht), '.htaccess no excluye /api de la reescritura de React.');
}
const index = text.find(([n]) => n === 'index.html')?.[1] ?? '';
check(/<script[^>]+src="\/assets\//.test(index) || /<script[^>]+src="\.\/assets\//.test(index), 'index.html no referencia los scripts de /assets.');

if (problems.length) { console.error(`\nLa compilación NO es apta para el servidor (${problems.length} problema(s)):\n- ${problems.join('\n- ')}`); process.exit(1); }
console.log(`Compilación de servidor verificada: ${files.length} archivos; proveedor HTTP/PHP, sin datos simulados, sin QA, sin secretos.`);
