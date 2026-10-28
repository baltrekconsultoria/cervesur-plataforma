// Compilación QA con Power BI PÚBLICO REAL (opt-in). Genera `dist-qa-real/` sin tocar `dist-qa/` ni `.env.qa`.
// El navegador cargará app.powerbi.com con el enlace público que el administrador haya registrado; PHP y MySQL siguen siendo los locales.
// No guarda ningún enlace: el enlace real vive solo en la base de datos sintética local que usted rellene manualmente.
import { spawnSync } from 'node:child_process';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
// Se ejecutan los programas con el propio Node (sin shell): sin concatenar argumentos en una línea de comandos.
const run = (script, args, env = {}) => {
  const result = spawnSync(process.execPath, [join(root, script), ...args], { cwd: root, stdio: 'inherit', env: { ...process.env, ...env } });
  if (result.status !== 0) process.exit(result.status ?? 1);
};
run('scripts/ensure-qa-env.mjs', []);
run('node_modules/typescript/bin/tsc', ['--noEmit']);
run('node_modules/vite/bin/vite.js', ['build', '--mode', 'qa', '--outDir', 'dist-qa-real'], { VITE_POWERBI_VIEWER_MODE: 'real-public' });
console.log('\nListo: dist-qa-real/ (modo «QA · Power BI público REAL»). Sírvalo con: npm run preview:qa:real  → http://127.0.0.1:4177');
