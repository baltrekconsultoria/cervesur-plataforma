// UX-08H.1 · Colocación y validación de los archivos SQL dentro del paquete productivo de la Fase 2A (la usan empaquetar.mjs y probar-empaquetado.mjs).
// Rutas DEFINITIVAS (relativas a la raíz del paquete), no deducidas de prosa:
//   migraciones/repo/{UX-08B-audit-log.up.sql, …audit-log.down.sql, …audit-log-immutable.up.sql, …immutable.down.sql, UX-08H1-cuenta-escritura-fase2a.sql.template, SHA256SUMS}
//   e0/repo/UX-08B-audit-log-immutable.up.sql
// Ninguna carpeta `repo/` ni `database/` debe quedar en la raíz del paquete (era el defecto del RC 70a14f2).
import { createHash } from 'node:crypto';
import { existsSync, mkdirSync, readFileSync, readdirSync, statSync, writeFileSync } from 'node:fs';
import { basename, join } from 'node:path';

/** Orden fijo: es también el orden de SHA256SUMS. */
export const DB_ORIGENES = [
  'migrations/UX-08B-audit-log.up.sql',
  'migrations/UX-08B-audit-log.down.sql',
  'migrations/UX-08B-audit-log-immutable.up.sql',
  'migrations/UX-08B-audit-log-immutable.down.sql',
  'privileges/UX-08H1-cuenta-escritura-fase2a.sql.template'
];
export const MIGRACIONES_REPO = 'migraciones/repo';
export const E0_REPO = 'e0/repo';
export const E0_ARCHIVO = 'UX-08B-audit-log-immutable.up.sql';
export const PLANTILLA = 'UX-08H1-cuenta-escritura-fase2a.sql.template';
export const PROHIBIDOS_EN_REPO = ['UX-08H-cuenta-escritura-produccion.sql.template', 'UX-08B3-reporte-grupo-nullable.up.sql', 'UX-08B3-reporte-grupo-nullable.down.sql'];

const sha256 = data => createHash('sha256').update(data).digest('hex');
const lf = text => text.replace(/\r\n/g, '\n');

/** Archivos que el paquete debe tener en cada carpeta `repo/` (nombres planos). */
export const esperadosMigraciones = () => DB_ORIGENES.map(f => basename(f));

/**
 * Copia los SQL a su ruta definitiva, con finales de línea LF, y genera migraciones/repo/SHA256SUMS (formato `sha256sum -c`, dos espacios, nombres planos).
 * @param {string} root raíz del paquete   @param {string} repoRoot raíz del repositorio (database/…)   @param {(msg:string)=>never} fail
 */
export function colocarBaseDeDatos(root, repoRoot, fail) {
  const sumas = [];
  mkdirSync(join(root, MIGRACIONES_REPO), { recursive: true });
  mkdirSync(join(root, E0_REPO), { recursive: true });
  for (const origen of DB_ORIGENES) {
    const desde = join(repoRoot, 'database', origen);
    if (!existsSync(desde)) fail(`Falta database/${origen}`);
    const contenido = lf(readFileSync(desde, 'utf8'));
    const nombre = basename(origen);
    writeFileSync(join(root, MIGRACIONES_REPO, nombre), contenido);
    sumas.push(`${sha256(contenido)}  ${nombre}`);
    if (nombre === E0_ARCHIVO) writeFileSync(join(root, E0_REPO, nombre), contenido);
  }
  writeFileSync(join(root, MIGRACIONES_REPO, 'SHA256SUMS'), sumas.join('\n') + '\n');
}

/** Lee del LEEME las líneas «database/<origen> → repo/<nombre>» y comprueba que coinciden con la tabla fija (el LEEME no manda, pero no puede contradecirla). */
export function contradiccionesConLeeme(textoLeeme) {
  const problemas = [];
  const pedidos = new Map();
  for (const linea of lf(textoLeeme).split('\n')) {
    const m = linea.match(/^\s*(?:database\/)?((?:migrations|privileges)\/[A-Za-z0-9_.-]+)\s*(?:->|→|=>)\s*(\S+)\s*$/);
    if (m) pedidos.set(m[1], m[2]);
  }
  for (const origen of DB_ORIGENES) {
    const pedido = pedidos.get(origen);
    if (pedido === undefined) problemas.push(`el LEEME no menciona ${origen}`);
    else if (pedido !== `repo/${basename(origen)}`) problemas.push(`el LEEME pide ${origen} → ${pedido}, pero la ruta definitiva es ${MIGRACIONES_REPO}/${basename(origen)}`);
  }
  return problemas;
}

const lista = dir => (existsSync(dir) && statSync(dir).isDirectory() ? readdirSync(dir).sort() : null);

/** Valida la estructura del paquete (raíz). Devuelve la lista de problemas; vacía = correcta. */
export function validarEstructura(root) {
  const p = [];
  for (const mal of ['repo', 'database']) if (existsSync(join(root, mal))) p.push(`carpeta «${mal}/» en la raíz del paquete: los SQL deben estar en ${MIGRACIONES_REPO}/ y ${E0_REPO}/`);
  const mig = lista(join(root, MIGRACIONES_REPO));
  if (mig === null) p.push(`falta ${MIGRACIONES_REPO}/`);
  else {
    for (const f of [...esperadosMigraciones(), 'SHA256SUMS']) if (!mig.includes(f)) p.push(`falta ${MIGRACIONES_REPO}/${f}`);
    for (const f of mig) if (![...esperadosMigraciones(), 'SHA256SUMS'].includes(f)) p.push(`archivo inesperado en ${MIGRACIONES_REPO}/: ${f}`);
    for (const f of PROHIBIDOS_EN_REPO) if (mig.includes(f)) p.push(`archivo prohibido en ${MIGRACIONES_REPO}/: ${f}`);
    for (const f of esperadosMigraciones()) if (mig.includes(f) && statSync(join(root, MIGRACIONES_REPO, f)).size === 0) p.push(`${MIGRACIONES_REPO}/${f} está vacío`);
    if (mig.includes('SHA256SUMS')) {
      const texto = lf(readFileSync(join(root, MIGRACIONES_REPO, 'SHA256SUMS'), 'utf8')).trim();
      const entradas = texto === '' ? [] : texto.split('\n');
      const nombres = entradas.map(l => (l.match(/^[0-9a-f]{64} [ *]?(.+)$/) || [])[1]);
      if (entradas.length !== esperadosMigraciones().length) p.push(`${MIGRACIONES_REPO}/SHA256SUMS tiene ${entradas.length} entradas y debe tener ${esperadosMigraciones().length}`);
      for (const f of esperadosMigraciones()) if (!nombres.includes(f)) p.push(`${MIGRACIONES_REPO}/SHA256SUMS no lista ${f}`);
      for (const l of entradas) {
        const m = l.match(/^([0-9a-f]{64}) [ *]?(.+)$/);
        if (!m) { p.push(`línea inválida en SHA256SUMS: ${l.slice(0, 40)}`); continue; }
        const archivo = join(root, MIGRACIONES_REPO, m[2]);
        if (m[2].includes('/') || m[2].includes('..')) p.push(`SHA256SUMS con ruta no plana: ${m[2]}`);
        else if (!existsSync(archivo)) p.push(`SHA256SUMS apunta a un archivo inexistente: ${m[2]}`);
        else if (sha256(readFileSync(archivo)) !== m[1]) p.push(`SHA256SUMS no verifica ${m[2]}`);
      }
    }
    const plantilla = join(root, MIGRACIONES_REPO, PLANTILLA);
    if (existsSync(plantilla)) {
      const t = lf(readFileSync(plantilla, 'utf8')).split('\n').filter(l => !l.trim().startsWith('--')).join('\n');
      if (/GRANT\s+INSERT[^;]*ON\s+`\{DB\}`\.`user`\s+TO/i.test(t) || /GRANT\s+(?:INSERT|UPDATE)\s*\((?!\s*last_login\s*\))[^)]*\)\s*ON\s+`\{DB\}`\.`user`/i.test(t)) p.push('la plantilla incluida concede escritura sobre `user` más allá de UPDATE(last_login): no es la de la fase 2A');
    }
  }
  const e0 = lista(join(root, E0_REPO));
  if (e0 === null) p.push(`falta ${E0_REPO}/`);
  else {
    if (!e0.includes(E0_ARCHIVO)) p.push(`falta ${E0_REPO}/${E0_ARCHIVO}`);
    else {
      const a = readFileSync(join(root, E0_REPO, E0_ARCHIVO));
      const b = existsSync(join(root, MIGRACIONES_REPO, E0_ARCHIVO)) ? readFileSync(join(root, MIGRACIONES_REPO, E0_ARCHIVO)) : null;
      if (a.length === 0) p.push(`${E0_REPO}/${E0_ARCHIVO} está vacío`);
      if (b && sha256(a) !== sha256(b)) p.push(`${E0_REPO}/${E0_ARCHIVO} difiere de ${MIGRACIONES_REPO}/${E0_ARCHIVO}`);
    }
    for (const f of e0) if (f !== E0_ARCHIVO) p.push(`archivo inesperado en ${E0_REPO}/: ${f}`);
  }
  // scripts: el LEEME del paquete debe seguir siendo coherente con la tabla fija
  const leeme = join(root, 'migraciones', 'LEEME-EMPAQUETADO.txt');
  if (existsSync(leeme)) for (const c of contradiccionesConLeeme(readFileSync(leeme, 'utf8'))) p.push(c);
  return p;
}



// ---------------------------------------------------------------------------------------------------------------------------------------------------------------------
// UX-08I · Fase 2B: plantillas ADITIVAS de privilegios de Usuarios en fase2b/repo/ (con su SHA256SUMS). La plantilla de la fase 2A sigue en migraciones/repo/ y NO cambia.
export const FASE2B_REPO = 'fase2b/repo';
export const FASE2B_ORIGENES = [
  'privileges/UX-08I-usuarios-no-sensibles.up.sql.template',
  'privileges/UX-08I-usuarios-no-sensibles.down.sql.template',
  'privileges/UX-08I-usuarios-sensibles.up.sql.template',
  'privileges/UX-08I-usuarios-sensibles.down.sql.template'
];
export const esperadosFase2b = () => FASE2B_ORIGENES.map(f => basename(f));

export function colocarFase2b(root, repoRoot, fail) {
  mkdirSync(join(root, FASE2B_REPO), { recursive: true });
  const sumas = [];
  for (const origen of FASE2B_ORIGENES) {
    const desde = join(repoRoot, 'database', origen);
    if (!existsSync(desde)) fail(`Falta database/${origen}`);
    const contenido = lf(readFileSync(desde, 'utf8'));
    const nombre = basename(origen);
    writeFileSync(join(root, FASE2B_REPO, nombre), contenido);
    sumas.push(`${sha256(contenido)}  ${nombre}`);
  }
  writeFileSync(join(root, FASE2B_REPO, 'SHA256SUMS'), sumas.join('\n') + '\n');
}

/** Valida fase2b/repo: los 4 archivos + SHA256SUMS que verifica; «no sensibles» no concede role/password; «sensibles» concede SOLO UPDATE(role, password); ninguna crea cuentas. */
export function validarFase2b(root) {
  const p = [];
  const dir = join(root, FASE2B_REPO);
  const archivos = lista(dir);
  if (archivos === null) return [`falta ${FASE2B_REPO}/`];
  for (const f of [...esperadosFase2b(), 'SHA256SUMS']) if (!archivos.includes(f)) p.push(`falta ${FASE2B_REPO}/${f}`);
  for (const f of archivos) if (![...esperadosFase2b(), 'SHA256SUMS'].includes(f)) p.push(`archivo inesperado en ${FASE2B_REPO}/: ${f}`);
  if (archivos.includes('SHA256SUMS')) {
    const entradas = lf(readFileSync(join(dir, 'SHA256SUMS'), 'utf8')).trim().split('\n').filter(Boolean);
    if (entradas.length !== esperadosFase2b().length) p.push(`${FASE2B_REPO}/SHA256SUMS tiene ${entradas.length} entradas y debe tener ${esperadosFase2b().length}`);
    for (const l of entradas) {
      const m = l.match(/^([0-9a-f]{64}) [ *]?(.+)$/);
      if (!m) { p.push(`línea inválida en ${FASE2B_REPO}/SHA256SUMS`); continue; }
      const archivo = join(dir, m[2]);
      if (m[2].includes('/') || !existsSync(archivo)) p.push(`${FASE2B_REPO}/SHA256SUMS apunta a un archivo inválido: ${m[2]}`);
      else if (sha256(readFileSync(archivo)) !== m[1]) p.push(`${FASE2B_REPO}/SHA256SUMS no verifica ${m[2]}`);
    }
  }
  const sinComentarios = f => (existsSync(join(dir, f)) ? lf(readFileSync(join(dir, f), 'utf8')).split('\n').filter(l => !l.trim().startsWith('--')).join('\n') : '');
  const noSens = sinComentarios('UX-08I-usuarios-no-sensibles.up.sql.template');
  const sensText = sinComentarios('UX-08I-usuarios-sensibles.up.sql.template');
  if (/GRANT\s+UPDATE\s*\([^)]*\b(role|password)\b[^)]*\)/i.test(noSens)) p.push('la plantilla «no sensibles» concede UPDATE de role o password');
  if (/\b(DELETE|ALL PRIVILEGES|GRANT OPTION|TRIGGER|DROP|ALTER|CREATE)\b/i.test(noSens + sensText)) p.push('una plantilla 2B contiene un privilegio o sentencia prohibidos');
  if (/IDENTIFIED BY/i.test(noSens + sensText)) p.push('las plantillas 2B no deben crear cuentas ni contraseñas');
  if (!/^GRANT UPDATE \(role, password\) ON `\{DB\}`\.`user` TO '\{USER\}'@'\{HOST\}';$/.test(sensText.replace(/\s+/g, ' ').trim())) p.push('la plantilla «sensibles» debe conceder únicamente UPDATE(role, password) sobre user');
  return p;
}
