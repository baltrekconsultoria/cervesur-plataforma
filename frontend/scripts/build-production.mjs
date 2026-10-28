// Compilación REPRODUCIBLE para el servidor: `npm run build:production` → dist-production/
//
// Configuración pública y sin secretos. El único dato que debe aportar quien compila es la lista de dominios autorizados:
//   PowerShell:  $env:CERVESUR_ALLOWED_HOSTS = 'portal.ejemplo.com'; npm run build:production
// La compilación:
//  - usa OBLIGATORIAMENTE el proveedor HTTP contra PHP, mismo origen (/api); no existe camino a datos simulados;
//  - ignora cualquier configuración local (.env*, variables VITE_*) que pudiera alterarla y falla si las encuentra;
//  - no incluye el modo QA de Power BI, la página local de pruebas ni mapas de código fuente;
//  - termina verificando el resultado (scripts/verify-production-build.mjs).
import { spawnSync } from 'node:child_process';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const OUT = 'dist-production';
const fail = message => { console.error(`\nERROR: ${message}`); process.exit(1); };

const hosts = (process.env.CERVESUR_ALLOWED_HOSTS ?? '').split(',').map(h => h.trim().toLowerCase()).filter(Boolean);
if (hosts.length === 0) fail('Defina los dominios autorizados: $env:CERVESUR_ALLOWED_HOSTS = \'portal.ejemplo.com\' (separados por comas).');
for (const host of hosts) if (!/^[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?(\.[a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?)*$/.test(host)) fail(`Dominio no válido: «${host}» (sin esquema, puerto, ruta ni comodines).`);

// Ninguna configuración externa puede alterar la compilación de servidor.
const stray = Object.keys(process.env).filter(key => key.startsWith('VITE_'));
if (stray.length) fail(`Hay variables VITE_* definidas en el entorno (${stray.join(', ')}). Elimínelas: la compilación de servidor fija su propia configuración.`);
for (const name of ['.env', '.env.local', '.env.production', '.env.production.local']) {
  const file = join(root, name);
  if (existsSync(file) && /^\s*VITE_/m.test(readFileSync(file, 'utf8'))) fail(`${name} define variables VITE_*: no se admite en la compilación de servidor.`);
}

const config = { VITE_DEPLOY_TARGET: 'server', VITE_API_PROVIDER: 'http', VITE_API_BASE_URL: 'same-origin', VITE_ALLOWED_HOSTS: hosts.join(',') };
const run = (script, args, env = {}) => {
  const result = spawnSync(process.execPath, [join(root, script), ...args], { cwd: root, stdio: 'inherit', env: { ...process.env, ...env } });
  if (result.status !== 0) process.exit(result.status ?? 1);
};

run('node_modules/typescript/bin/tsc', ['--noEmit']);
run('node_modules/vite/bin/vite.js', ['build', '--mode', 'production', '--outDir', OUT, '--emptyOutDir'], config);
writeFileSync(join(root, OUT, 'build-info.json'), `${JSON.stringify({ target: 'server', provider: 'http', apiBase: 'same-origin', allowedHosts: hosts }, null, 2)}\n`);
run('scripts/verify-production-build.mjs', [OUT, ...hosts]);
console.log(`\nListo: ${OUT}/ (dominios autorizados: ${hosts.join(', ')}). No se ha desplegado nada.`);
