// UX-08H.1 correctivo · pruebas del empaquetado de los SQL (migraciones/repo, e0/repo, SHA256SUMS) y de la URL del portal de trabajadores. No necesita Docker.
//   node deploy/produccion-escritura/probar-empaquetado.mjs                      pruebas sintéticas (construye una raíz de paquete en una carpeta temporal)
//   node deploy/produccion-escritura/probar-empaquetado.mjs <raíz-de-un-paquete>  además valida un paquete REAL ya construido (carpeta descomprimida)
import { cpSync, existsSync, mkdirSync, mkdtempSync, readFileSync, renameSync, rmSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import { DB_ORIGENES, E0_ARCHIVO, E0_REPO, MIGRACIONES_REPO, PLANTILLA, colocarBaseDeDatos, contradiccionesConLeeme, esperadosMigraciones, validarEstructura } from './lib-empaquetado.mjs';

const here = dirname(fileURLToPath(import.meta.url));
const REPO = resolve(here, '..', '..');
let fallos = 0;
const ok = (nombre, cond, extra = '') => { console.log(`${cond ? 'PASS' : 'FAIL'} ${nombre}${!cond && extra ? ` · ${extra}` : ''}`); if (!cond) fallos++; };
const fail = m => { throw new Error(m); };
const nueva = () => {
  const root = mkdtempSync(join(tmpdir(), 'cervesur-prueba-empaquetado-'));
  mkdirSync(join(root, 'migraciones'), { recursive: true });
  cpSync(join(here, 'migraciones', 'LEEME-EMPAQUETADO.txt'), join(root, 'migraciones', 'LEEME-EMPAQUETADO.txt'));
  colocarBaseDeDatos(root, REPO, fail);
  return root;
};
const sinProblemas = root => validarEstructura(root);
const conProblema = (root, fragmento) => validarEstructura(root).some(p => p.includes(fragmento));
const limpiar = root => rmSync(root, { recursive: true, force: true });

// 1. Camino feliz
{
  const r = nueva();
  const p = sinProblemas(r);
  ok('estructura correcta: sin problemas', p.length === 0, p.join(' | '));
  ok('migraciones/repo/ contiene exactamente los 5 archivos + SHA256SUMS', esperadosMigraciones().length === 5 && existsSync(join(r, MIGRACIONES_REPO, 'SHA256SUMS')));
  ok('e0/repo/ tiene UX-08B-audit-log-immutable.up.sql', existsSync(join(r, E0_REPO, E0_ARCHIVO)));
  ok('no hay carpeta repo/ ni database/ en la raíz', !existsSync(join(r, 'repo')) && !existsSync(join(r, 'database')));
  const sums = readFileSync(join(r, MIGRACIONES_REPO, 'SHA256SUMS'), 'utf8').trim().split('\n');
  ok('SHA256SUMS: 5 líneas «hash␣␣nombre» con nombres planos', sums.length === 5 && sums.every(l => /^[0-9a-f]{64}  [A-Za-z0-9_.-]+$/.test(l)));
  limpiar(r);
}
// 2. Carpeta incorrecta (el defecto del RC 70a14f2: SQL en repo/ de la raíz)
{
  const r = nueva(); renameSync(join(r, MIGRACIONES_REPO), join(r, 'repo')); mkdirSync(join(r, MIGRACIONES_REPO), { recursive: true });
  ok('SQL en repo/ de la raíz (y migraciones/repo/ vacía): FALLA', conProblema(r, 'carpeta «repo/» en la raíz') && conProblema(r, `falta ${MIGRACIONES_REPO}/UX-08B-audit-log.up.sql`) && conProblema(r, `falta ${MIGRACIONES_REPO}/SHA256SUMS`));
  limpiar(r);
}
{
  const r = nueva(); mkdirSync(join(r, 'database'), { recursive: true });
  ok('carpeta database/ en la raíz: FALLA', conProblema(r, 'carpeta «database/»'));
  limpiar(r);
}
// 3. Falta cada uno de los 5 archivos
for (const origen of DB_ORIGENES) {
  const nombre = origen.split('/').pop();
  const r = nueva(); rmSync(join(r, MIGRACIONES_REPO, nombre));
  ok(`falta ${nombre}: FALLA`, conProblema(r, `falta ${MIGRACIONES_REPO}/${nombre}`) && conProblema(r, 'SHA256SUMS apunta a un archivo inexistente'));
  limpiar(r);
}
// 4. Manifiesto
{
  const r = nueva(); rmSync(join(r, MIGRACIONES_REPO, 'SHA256SUMS'));
  ok('falta SHA256SUMS: FALLA', conProblema(r, `falta ${MIGRACIONES_REPO}/SHA256SUMS`)); limpiar(r);
}
{
  const r = nueva(); writeFileSync(join(r, MIGRACIONES_REPO, 'UX-08B-audit-log.up.sql'), '-- alterado\n');
  ok('archivo alterado tras generar SHA256SUMS: FALLA', conProblema(r, 'SHA256SUMS no verifica UX-08B-audit-log.up.sql')); limpiar(r);
}
{
  const r = nueva(); const f = join(r, MIGRACIONES_REPO, 'SHA256SUMS'); const l = readFileSync(f, 'utf8').trim().split('\n'); writeFileSync(f, l.slice(0, 4).join('\n') + '\n');
  ok('SHA256SUMS con 4 de 5 entradas: FALLA', conProblema(r, 'tiene 4 entradas')); limpiar(r);
}
{
  const r = nueva(); const f = join(r, MIGRACIONES_REPO, 'SHA256SUMS'); writeFileSync(f, readFileSync(f, 'utf8') + `${'0'.repeat(64)}  ../etc/passwd\n`);
  ok('SHA256SUMS con ruta fuera de la carpeta: FALLA', conProblema(r, 'ruta no plana') || conProblema(r, 'tiene 6 entradas')); limpiar(r);
}
{
  const r = nueva(); writeFileSync(join(r, MIGRACIONES_REPO, 'UX-08B-audit-log.down.sql'), '');
  ok('archivo vacío: FALLA', conProblema(r, 'está vacío')); limpiar(r);
}
// 5. e0/repo
{
  const r = nueva(); rmSync(join(r, E0_REPO, E0_ARCHIVO));
  ok('e0/repo/ vacía (el defecto del RC): FALLA', conProblema(r, `falta ${E0_REPO}/${E0_ARCHIVO}`)); limpiar(r);
}
{
  const r = nueva(); writeFileSync(join(r, E0_REPO, E0_ARCHIVO), '-- otro contenido\n');
  ok('e0/repo/ difiere de la migración M2: FALLA', conProblema(r, 'difiere de')); limpiar(r);
}
{
  const r = nueva(); rmSync(join(r, E0_REPO), { recursive: true });
  ok('falta e0/repo/: FALLA', conProblema(r, `falta ${E0_REPO}/`)); limpiar(r);
}
// 6. Archivos prohibidos / plantilla incorrecta
{
  const r = nueva(); writeFileSync(join(r, MIGRACIONES_REPO, 'UX-08H-cuenta-escritura-produccion.sql.template'), 'x\n');
  ok('plantilla UX-08H (escribe `user`) dentro del paquete: FALLA', conProblema(r, 'archivo prohibido')); limpiar(r);
}
{
  const r = nueva(); const plantilla = join(r, MIGRACIONES_REPO, PLANTILLA);
  writeFileSync(plantilla, readFileSync(plantilla, 'utf8') + "GRANT UPDATE (role, is_active) ON `{DB}`.`user` TO '{USER}'@'{HOST}';\n");
  ok('plantilla con UPDATE(role…) sobre user: FALLA', conProblema(r, 'concede escritura sobre `user`')); limpiar(r);
}
{
  const r = nueva(); const plantilla = join(r, MIGRACIONES_REPO, PLANTILLA);
  writeFileSync(plantilla, readFileSync(plantilla, 'utf8') + "GRANT INSERT ON `{DB}`.`user` TO '{USER}'@'{HOST}';\n");
  ok('plantilla con INSERT sobre user: FALLA', conProblema(r, 'concede escritura sobre `user`')); limpiar(r);
}
{
  const r = nueva();
  ok('la plantilla real solo concede UPDATE(last_login) sobre user', !conProblema(r, 'concede escritura sobre `user`'));
  limpiar(r);
}
// 7. LEEME coherente con la tabla fija
{
  const texto = readFileSync(join(here, 'migraciones', 'LEEME-EMPAQUETADO.txt'), 'utf8');
  ok('el LEEME de migraciones es coherente con las rutas definitivas', contradiccionesConLeeme(texto).length === 0, contradiccionesConLeeme(texto).join(' | '));
  const raiz = texto.replace(/→\s+repo\/UX-08B-audit-log\.up\.sql/, '→  UX-08B-audit-log.up.sql');
  ok('un LEEME que pide otra ruta se detecta', contradiccionesConLeeme(raiz).length > 0);
}
// 8. colocarBaseDeDatos aborta si falta un origen
{
  const raiz = mkdtempSync(join(tmpdir(), 'cervesur-prueba-empaquetado-'));
  let msg = '';
  try { colocarBaseDeDatos(raiz, join(raiz, 'no-existe'), m => { msg = m; throw new Error(m); }); } catch { /* esperado */ }
  ok('si falta un SQL en el repositorio, el empaquetado aborta', msg.includes('Falta database/migrations/UX-08B-audit-log.up.sql'), msg);
  limpiar(raiz);
}
// 9. empaquetar.mjs usa el módulo y se niega ante problemas
{
  const src = readFileSync(join(here, 'empaquetar.mjs'), 'utf8');
  ok('empaquetar.mjs coloca los SQL con colocarBaseDeDatos y valida con validarEstructura', /colocarBaseDeDatos\(ROOT, REPO, fail\)/.test(src) && /validarEstructura\(ROOT\)/.test(src));
  ok('empaquetar.mjs ya no interpreta destinos del LEEME ni copia a database/ en la raíz', !/destinos\.set|migraciones\/repo\/database\//.test(src));
}
// 10. URL del portal de trabajadores
{
  const sh = readFileSync(join(here, 'scripts', 'comprobar-otros-portales.sh'), 'utf8');
  ok('trabajadores: URL por defecto https://192.168.200.12:443/ (no loopback)', /IP_CORPORATIVA=\$\{CERVESUR_IP_CORPORATIVA:-192\.168\.200\.12\}/.test(sh) && /URL_TRABAJADORES=\$\{CERVESUR_URL_TRABAJADORES:-https:\/\/\$\{IP_CORPORATIVA\}:443\/\}/.test(sh));
  ok('trabajadores: ya no hay 127.0.0.1:443 como valor por defecto', !/CERVESUR_URL_TRABAJADORES:-https:\/\/127\.0\.0\.1/.test(sh));
  ok('se puede sobrescribir con CERVESUR_URL_TRABAJADORES', /CERVESUR_URL_TRABAJADORES/.test(sh));
  const otros = ['preflight-fase2a.sh', 'actualizar.sh', 'modo-escritura.sh', 'revertir-codigo.sh'].map(f => readFileSync(join(here, 'scripts', f), 'utf8'));
  ok('ningún procedimiento fija 127.0.0.1:443 por su cuenta (todos usan el verificador compartido)', otros.every(t => !/127\.0\.0\.1:443/.test(t)) && otros.every(t => /comprobar-otros-portales\.sh/.test(t)));
  const ensayo = readFileSync(join(here, 'ensayo-docker.mjs'), 'utf8');
  ok('el ensayo reproduce la realidad: 443 solo en 192.168.200.12 (NET_ADMIN solo en el ensayo)', /--cap-add', 'NET_ADMIN'/.test(ensayo) && /192\.168\.200\.12:443/.test(readFileSync(join(here, 'ensayo', 'decoys', 'cervesur-react-trabajadores.conf'), 'utf8')));
}
// 11. Paquete real (opcional)
if (process.argv[2]) {
  const raiz = resolve(process.argv[2]);
  const p = validarEstructura(raiz);
  ok(`paquete real ${raiz.split(/[\\/]/).pop()}: estructura correcta`, p.length === 0, p.join(' | '));
}
console.log(`\n${fallos === 0 ? 'All packaging checks passed' : `${fallos} check(s) FAILED`}`);
process.exit(fallos === 0 ? 0 : 1);
