// Crea `.env.qa` (configuración ACTIVA del modo QA, ignorada por git) a partir de `.env.qa.example`.
// Nunca sobrescribe un `.env.qa` existente. Solo contiene banderas de configuración sin credenciales.
import { copyFileSync, existsSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = join(dirname(fileURLToPath(import.meta.url)), '..');
const example = join(root, '.env.qa.example');
const active = join(root, '.env.qa');
if (!existsSync(example)) throw new Error('Falta .env.qa.example');
if (existsSync(active)) {
  console.log('.env.qa ya existe: se conserva sin cambios.');
} else {
  copyFileSync(example, active);
  console.log('.env.qa creado desde .env.qa.example (archivo local, ignorado por git).');
}
