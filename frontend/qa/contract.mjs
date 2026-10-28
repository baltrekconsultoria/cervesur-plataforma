// Pruebas de contrato y aislamiento contra el backend PHP REAL del entorno QA local (127.0.0.1:8080 / 8081).
// Requiere `node qa/backend.mjs up`. No usa el navegador: demuestra que la autorización vive en PHP.
// Salida: PASS / FAIL / HALLAZGO (comportamiento conocido del backend que conviene corregir; no hace fallar la ejecución).
import { createHmac, randomBytes } from 'node:crypto';

const API = 'http://127.0.0.1:8080';
const SHORT = 'http://127.0.0.1:8081';
const PASSWORD = 'synthetic-password'; // contraseña sintética pública del fixture del repositorio
const results = [];
let section = '';
const record = (status, name, extra = '') => results.push([status, `${section} · ${name}`, extra]);
const ok = (name, cond, extra = '') => record(cond ? 'PASS' : 'FAIL', name, extra);
const finding = (name, present, extra = '') => record(present ? 'HALLAZGO' : 'PASS', name, extra);
const rawBodies = [];

async function call(method, path, { body, token, base = API, headers = {} } = {}) {
  const res = await fetch(base + path, { method, headers: { 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}), ...headers }, body: body === undefined ? undefined : typeof body === 'string' ? body : JSON.stringify(body) });
  const raw = await res.text();
  rawBodies.push(raw);
  let json = null; try { json = JSON.parse(raw); } catch { /* no JSON */ }
  return { status: res.status, json, raw };
}
const login = async (login, base = API) => { const r = await call('POST', '/api/login', { body: { login, password: PASSWORD }, base }); return { ...r, token: r.json?.token }; };
const names = r => (r.json?.data ?? []).map(x => x.nombre).sort();
const b64 = o => Buffer.from(JSON.stringify(o)).toString('base64url');
const forge = (payload, secret, alg = 'HS256') => {
  const head = b64({ typ: 'JWT', alg }); const body = b64(payload);
  const sig = alg === 'none' ? '' : createHmac('sha256', secret).update(`${head}.${body}`).digest('base64url');
  return `${head}.${body}.${sig}`;
};

section = 'Sesión';
const usera = await login('usera'); const userb = await login('userb'); const admin = await login('admin'); const nobody = await login('nobody');
ok('login usera → 200, success y token JWT', usera.status === 200 && usera.json.success === true && /^[\w-]+\.[\w-]+\.[\w-]+$/.test(usera.token ?? ''));
ok('login administrador → rol admin', admin.json?.user?.role === 'admin');
ok('login devuelve id, first_name, last_name y role (no user_id)', ['id', 'username', 'email', 'first_name', 'last_name', 'role'].every(k => k in (usera.json?.user ?? {})) && !('user_id' in usera.json.user));
const bad = await call('POST', '/api/login', { body: { login: 'usera', password: 'incorrecta' } });
finding('credenciales incorrectas responden HTTP 200 con success:false (no 401)', bad.status === 200 && bad.json?.success === false, `HTTP ${bad.status} · "${bad.json?.message}"`);
ok('credenciales incorrectas: sin token en la respuesta', !('token' in (bad.json ?? {})));
const inactive = await call('POST', '/api/login', { body: { login: 'inactive', password: PASSWORD } });
ok('usuario inactivo: no obtiene sesión y recibe el mismo mensaje que credenciales incorrectas', inactive.json?.success === false && inactive.json?.message === bad.json?.message, `HTTP ${inactive.status}`);
ok('login con cuerpo incompleto → 400', (await call('POST', '/api/login', { body: { login: 'usera' } })).status === 400);
ok('login con JSON inválido → 400', (await call('POST', '/api/login', { body: '{no es json' })).status === 400);
ok('sin token → 401', (await call('GET', '/api/reportes')).status === 401);
ok('token basura → 401', (await call('GET', '/api/reportes', { token: 'abc.def.ghi' })).status === 401);
const payload = { user_id: 1, username: 'usera', email: 'usera@example.invalid', role: 'admin', session_version: 1, exp: Math.floor(Date.now() / 1000) + 3600 };
ok('JWT firmado con otra clave → 401', (await call('GET', '/api/reportes', { token: forge(payload, randomBytes(32).toString('hex')) })).status === 401);
ok('JWT con alg "none" → 401', (await call('GET', '/api/reportes', { token: forge(payload, '', 'none') })).status === 401);
ok('JWT que se auto-asigna rol admin (firma falsa) no obtiene datos de administrador', (await call('GET', '/api/users', { token: forge(payload, 'x') })).status === 401);

section = 'JWT expirado';
const short = await login('usera', SHORT);
ok('token de 2 s válido recién emitido', (await call('GET', '/api/user/profile', { token: short.token, base: SHORT })).status === 200);
await new Promise(r => setTimeout(r, 3500));
const expired = await call('GET', '/api/user/profile', { token: short.token, base: SHORT });
ok('el mismo token tras expirar → 401', expired.status === 401, `HTTP ${expired.status} · "${expired.json?.message}"`);

section = 'Logout y revocación';
const second = await login('usera');
const outcome = await call('POST', '/api/logout', { token: second.token });
ok('POST /api/logout → 200 success', outcome.status === 200 && outcome.json?.success === true);
ok('el token usado en logout deja de ser válido', (await call('GET', '/api/reportes', { token: second.token })).status === 401);
ok('también se invalidan los otros tokens previos del mismo usuario (session_version)', (await call('GET', '/api/reportes', { token: usera.token })).status === 401);
ok('los tokens de OTROS usuarios no se ven afectados', (await call('GET', '/api/reportes', { token: userb.token })).status === 200);
const again = await login('usera');
ok('un nuevo login tras el logout vuelve a funcionar', again.status === 200 && again.json.success === true);
ok('logout sin token → 401', (await call('POST', '/api/logout')).status === 401);
usera.token = again.token;

section = 'Aislamiento · informes';
const ra = await call('GET', '/api/reportes', { token: usera.token });
const rb = await call('GET', '/api/reportes', { token: userb.token });
const rn = await call('GET', '/api/reportes', { token: nobody.token });
const radm = await call('GET', '/api/reportes', { token: admin.token });
ok('usera ve solo sus 4 informes vigentes', JSON.stringify(names(ra)) === JSON.stringify(['Flujo semanal', 'Inventario norte', 'Reporte A', 'Ventas regionales']), names(ra).join(', '));
ok('usera no ve informes de otras empresas ni el inactivo ni el retirado', !names(ra).some(n => ['Reporte B', 'Reporte C', 'Reporte retirado'].includes(n)));
ok('userb ve solo "Reporte B"', JSON.stringify(names(rb)) === JSON.stringify(['Reporte B']));
ok('usuario sin asignaciones recibe lista vacía (no error)', rn.status === 200 && rn.json?.data?.length === 0);
ok('administrador ve todos los informes activos (6)', names(radm).length === 6, names(radm).join(', '));
const uuidA = '00000000-0000-0000-0000-000000000011'; const uuidB = '00000000-0000-0000-0000-000000000022';
ok('usera abre su informe por UUID', (await call('GET', `/api/reportes/reporteid/${uuidA}`, { token: usera.token })).status === 200);
const foreign = await call('GET', `/api/reportes/reporteid/${uuidB}`, { token: usera.token });
const missing = await call('GET', '/api/reportes/reporteid/00000000-0000-0000-0000-0000000000ff', { token: usera.token });
ok('informe ajeno → 404', foreign.status === 404);
ok('informe ajeno e inexistente son indistinguibles (mismo estado y mensaje)', foreign.status === missing.status && foreign.raw === missing.raw);
ok('informe ajeno por id numérico → 404', (await call('GET', '/api/reportes/2', { token: usera.token })).status === 404);
ok('informe del grupo ajeno → lista vacía o 404, nunca datos', ((await call('GET', '/api/reportes/grupoid/00000000-0000-0000-0000-000000000002', { token: userb.token })).json?.data ?? []).every(x => x.nombre === 'Reporte B'));

section = 'Aislamiento · empresas';
const ea = await call('GET', '/api/empresas', { token: usera.token });
const eb = await call('GET', '/api/empresas', { token: userb.token });
const en = await call('GET', '/api/empresas', { token: nobody.token });
const eadm = await call('GET', '/api/empresas', { token: admin.token });
ok('usera ve solo "Empresa A" (no B, y la inactiva C vinculada no aparece)', (ea.json?.data ?? []).map(e => e.nombre).join() === 'Empresa A');
ok('userb ve solo "Empresa B"', (eb.json?.data ?? []).map(e => e.nombre).join() === 'Empresa B');
ok('usuario sin empresas recibe lista vacía', en.status === 200 && en.json?.data?.length === 0);
ok('administrador ve las empresas activas (A y B)', (eadm.json?.data ?? []).map(e => e.nombre).sort().join() === 'Empresa A,Empresa B');
ok('empresa ajena por id → sin datos', (await call('GET', '/api/empresas/2', { token: usera.token })).json?.success !== true);
ok('búsqueda de empresa ajena no la revela', ((await call('GET', '/api/empresas/buscar/Empresa%20B', { token: usera.token })).json?.data ?? []).length === 0);

section = 'Aislamiento · perfiles';
ok('usera lee su propio perfil (/api/user/profile)', (await call('GET', '/api/user/profile', { token: usera.token })).json?.profile?.username === 'usera');
ok('usera NO puede leer el perfil de userb → 403', (await call('GET', '/api/user/profile/2', { token: usera.token })).status === 403);
ok('usera NO puede modificar el perfil de userb → 403', (await call('PUT', '/api/user/profile/2', { token: usera.token, body: { first_name: 'X' } })).status === 403);
ok('administrador sí puede leer el perfil de userb', (await call('GET', '/api/user/profile/2', { token: admin.token })).json?.profile?.username === 'userb');
const profile = (await call('GET', '/api/user/profile', { token: usera.token })).json?.profile ?? {};
ok('el perfil no incluye contraseña ni hash', !/password|\$2y\$/i.test(JSON.stringify(profile)));

section = 'Permisos en PHP (no en React)';
ok('usuario no puede listar usuarios → 403', (await call('GET', '/api/users', { token: usera.token })).status === 403);
ok('administrador sí lista usuarios', (await call('GET', '/api/users?status=all', { token: admin.token })).json?.users?.length >= 7);
ok('usuario no puede crear empresa → 403', (await call('POST', '/api/empresas', { token: usera.token, body: { nombre: 'X', ruc: '99999999999' } })).status === 403);
ok('usuario no puede crear reporte → 403', (await call('POST', '/api/reportes', { token: usera.token, body: { nombre: 'X' } })).status === 403);
ok('usuario no puede cambiar el rol de otro → 403', (await call('PUT', '/api/user/2/role', { token: usera.token, body: { role: 'admin' } })).status === 403);
const legacy = await login('legacy'); const weird = await login('weird');
ok('rol heredado "usuario": el backend lo devuelve tal cual', legacy.json?.user?.role === 'usuario');
ok('rol heredado "usuario" no tiene privilegios de administrador (403 en /api/users)', (await call('GET', '/api/users', { token: legacy.token })).status === 403);
ok('rol heredado ve solo su informe asignado', names(await call('GET', '/api/reportes', { token: legacy.token })).join() === 'Reporte A');
finding('el backend emite roles no normalizados (usuario, superadmin) sin rechazarlos en el login', weird.json?.success === true && weird.json?.user?.role === 'superadmin', `weird → role "${weird.json?.user?.role}"`);
ok('rol inesperado "superadmin" no obtiene privilegios de administrador (403 en /api/users)', (await call('GET', '/api/users', { token: weird.token })).status === 403);

section = 'Secretos y campos técnicos';
const all = rawBodies.join('\n');
ok('ninguna respuesta contiene powerbi_clientsecret ni valores privados del fixture', !/clientsecret|fixture-private-value/i.test(all));
ok('ninguna respuesta contiene hashes de contraseña ni la contraseña sintética', !/\$2y\$|synthetic-password/.test(all));
ok('ninguna respuesta contiene la JWT_SECRET (no disponible para esta suite, se comprueba que no aparezcan cadenas de 64 hex)', !/\b[a-f0-9]{64}\b/.test(all));
finding('GET /api/reportes devuelve `enlace` (URL de Power BI) además de created_by y created_by_username', (ra.json?.data ?? []).some(r => 'enlace' in r), 'el frontend lo descarta en el adaptador; debería dejar de enviarse');
finding('GET /api/empresas devuelve powerbi_tenant y powerbi_client a usuarios no administradores', (ea.json?.data ?? []).some(e => 'powerbi_tenant' in e || 'powerbi_client' in e), 'el secreto de cliente sí está excluido');
finding('GET /api/reportes no incluye empresa ni nombre de grupo (solo grupo_id en UUID)', (ra.json?.data ?? []).every(r => !('empresa_id' in r) && !('grupo' in r) && !('grupo_nombre' in r)), 'el frontend no puede filtrar por empresa ni mostrar el grupo con este endpoint');

section = 'Paginación de servidor (UX-06B.0)';
const multi = await login('multi');
const walkAll = async (path, token, perPage) => {
  const rows = []; const metas = []; let page = 1; let more = true;
  while (more && page < 50) {
    const sep = path.includes('?') ? '&' : '?';
    const r = await call('GET', `${path}${sep}per_page=${perPage}&page=${page}`, { token });
    const meta = r.json?.pagination ?? r.json?.data?.pagination;
    if (r.status !== 200 || !meta) return { ok: false, rows, metas };
    rows.push(...(r.json?.data?.data ?? r.json?.users ?? r.json?.data ?? [])); metas.push(meta); more = meta.has_more; page++;
  }
  return { ok: true, rows, metas };
};
const rowsOf = j => j?.data?.data ?? j?.users ?? j?.data ?? [];
const full = await call('GET', '/api/reportes', { token: usera.token });
const w2 = await walkAll('/api/reportes', usera.token, 2);
ok('usera: /api/reportes per_page=2 → 2 páginas, total 4, sin duplicados', w2.ok && w2.metas.length === 2 && w2.metas.every(m => m.total === 4) && new Set(w2.rows.map(r => r.id)).size === 4, JSON.stringify(w2.metas.map(m => [m.page, m.has_more])));
ok('las páginas reunidas son exactamente el listado completo y en el mismo orden', JSON.stringify(w2.rows.map(r => r.id)) === JSON.stringify(rowsOf(full.json).map(r => r.id)));
const beyond = await call('GET', '/api/reportes?per_page=2&page=9', { token: usera.token });
ok('página fuera de rango → datos vacíos, total coherente y has_more=false', beyond.status === 200 && beyond.json.data.length === 0 && beyond.json.pagination.total === 4 && beyond.json.pagination.has_more === false);
for (const bad of ['page=0', 'page=-3', 'page=x', 'page=1.5', 'per_page=0', 'per_page=101', 'per_page=x', 'per_page[]=2']) {
  const r = await call('GET', `/api/reportes?${bad}`, { token: usera.token });
  ok(`parámetro inválido (${bad}) → 400`, r.status === 400 && r.json?.success === false);
}
const legacyEU = await call('GET', '/api/empresa-usuario', { token: usera.token });
ok('compatibilidad: /api/empresa-usuario sin parámetros conserva claves y tope de 20 y ahora informa el total real', legacyEU.status === 200 && ['success', 'data', 'count', 'message'].every(k => k in legacyEU.json) && legacyEU.json.pagination.per_page === 20 && legacyEU.json.pagination.total === 2, 'total 2 = Empresa A + relación activa con la Empresa C INACTIVA (este endpoint no filtra empresas inactivas: hallazgo existente)');
const legacyERU = await call('GET', '/api/empresa-reporte-usuario', { token: usera.token });
ok('/api/empresa-reporte-usuario: total real = 4 relaciones vigentes de usera', legacyERU.json.pagination.total === 4 && legacyERU.json.data.length === 4);
const adminEU = await walkAll('/api/empresa-usuario', admin.token, 2);
ok('administrador: /api/empresa-usuario recorrido completo por páginas de 2 sin duplicados y con total estable', adminEU.ok && new Set(adminEU.rows.map(r => r.id)).size === adminEU.rows.length && adminEU.metas.every(m => m.total === adminEU.rows.length) && adminEU.rows.length >= 5);
const adminUsers = await walkAll('/api/users?status=all', admin.token, 3);
ok('administrador: /api/users por páginas de 3, total = filas recibidas', adminUsers.ok && adminUsers.rows.length === adminUsers.metas[0].total && adminUsers.rows.length >= 8);
ok('usuario normal sigue sin acceso a /api/users con paginación (403)', (await call('GET', '/api/users?per_page=2', { token: usera.token })).status === 403);
ok('autenticación obligatoria también con parámetros de paginación', (await call('GET', '/api/empresa-usuario?per_page=2')).status === 401);

section = 'Vistas mínimas y catálogo (UX-06B.0)';
const allowedFields = { '/api/reportes': ['id', 'reporte_id', 'nombre', 'descripcion', 'activo'], '/api/empresas': ['id', 'nombre', 'razon_social', 'ruc', 'ciudad', 'pais', 'logo', 'activo'], '/api/asignacion': ['id', 'empresa_id', 'empresa_nombre', 'reporte_id', 'reporte_nombre', 'activo'] };
for (const [path, allowedKeys] of Object.entries(allowedFields)) {
  for (const [who, token] of [['usera', usera.token], ['admin', admin.token]]) {
    const r = await call('GET', `${path}?view=minimal&per_page=100`, { token });
    const rows = rowsOf(r.json);
    ok(`${path}?view=minimal (${who}): solo campos de la lista blanca (${rows.length} filas)`, r.status === 200 && rows.length > 0 && rows.every(row => Object.keys(row).every(k => allowedKeys.includes(k))));
    ok(`${path}?view=minimal (${who}): sin enlaces, creadores, powerbi_*, contacto ni secretos`, !/enlace|created_by|updated_by|powerbi_|clientsecret|fixture-private|example\.invalid|notas|telefono|contacto/i.test(r.raw));
  }
  ok(`${path}?view=desconocida → 400`, (await call('GET', `${path}?view=full`, { token: usera.token })).status === 400);
}
const legacyReports = await call('GET', '/api/reportes', { token: usera.token });
ok('compatibilidad: sin view, /api/reportes mantiene los campos completos para clientes anteriores', rowsOf(legacyReports.json).every(r => 'enlace' in r && 'created_by_username' in r));
const legacyCompanies = await call('GET', '/api/empresas', { token: usera.token });
ok('compatibilidad: sin view, /api/empresas mantiene sus campos y sigue sin devolver el secreto de cliente', rowsOf(legacyCompanies.json).every(e => 'powerbi_tenant' in e) && !/clientsecret/i.test(legacyCompanies.raw));
const oneMinimal = await call('GET', '/api/reportes/reporteid/00000000-0000-0000-0000-000000000011?view=minimal', { token: usera.token });
ok('informe por UUID con view=minimal: lista blanca', oneMinimal.status === 200 && Object.keys(oneMinimal.json.data).every(k => allowedFields['/api/reportes'].includes(k)));
ok('informe ajeno con view=minimal: 404 sin datos', (await call('GET', '/api/reportes/reporteid/00000000-0000-0000-0000-000000000022?view=minimal', { token: usera.token })).status === 404);

// Composición provisional: /api/reportes + /api/asignacion + /api/empresas (misma regla que el frontend)
const compose = async token => {
  const [rep, asg, emp] = await Promise.all([walkAll('/api/reportes?view=minimal', token, 100), walkAll('/api/asignacion?view=minimal', token, 100), walkAll('/api/empresas?view=minimal', token, 100)]);
  const companyName = new Map(emp.rows.map(e => [e.id, e.nombre]));
  const pairs = new Map();
  for (const a of asg.rows) { if (!companyName.has(a.empresa_id)) continue; (pairs.get(a.reporte_id) ?? pairs.set(a.reporte_id, new Set()).get(a.reporte_id)).add(a.empresa_id); }
  return rep.rows.map(r => ({ nombre: r.nombre, empresas: [...(pairs.get(r.id) ?? [])].map(id => companyName.get(id)).sort() }));
};
const catalogMulti = await compose(multi.token);
ok('multi: el informe vinculado a dos empresas aparece UNA vez con ambas', catalogMulti.length === 2 && JSON.stringify(catalogMulti.find(r => r.nombre === 'Ventas regionales')?.empresas) === JSON.stringify(['Empresa A', 'Empresa B']), JSON.stringify(catalogMulti));
ok('multi: el informe de una sola empresa conserva solo su empresa', JSON.stringify(catalogMulti.find(r => r.nombre === 'Reporte A')?.empresas) === JSON.stringify(['Empresa A']));
const catalogA = await compose(usera.token);
ok('usera: 4 informes, todos solo en Empresa A (nunca Empresa B)', catalogA.length === 4 && catalogA.every(r => JSON.stringify(r.empresas) === JSON.stringify(['Empresa A'])));
const catalogB = await compose(userb.token);
ok('userb: solo Reporte B en Empresa B (aislamiento entre usuarios)', JSON.stringify(catalogB) === JSON.stringify([{ nombre: 'Reporte B', empresas: ['Empresa B'] }]));
const catalogNobody = await compose(nobody.token);
ok('nobody: catálogo vacío', catalogNobody.length === 0);
const catalogAdmin = await compose(admin.token);
ok('administrador: 6 informes con sus empresas, sin duplicados', catalogAdmin.length === 6 && new Set(catalogAdmin.map(r => r.nombre)).size === 6 && catalogAdmin.find(r => r.nombre === 'Ventas regionales').empresas.length >= 2);
const crossAsg = await walkAll('/api/asignacion?view=minimal', usera.token, 100);
ok('usera: ninguna asignación apunta a un informe que no está en su propio listado', crossAsg.rows.every(a => rowsOf(legacyReports.json).some(r => r.id === a.reporte_id)));

section = 'Errores HTTP';
ok('ruta inexistente → 404 JSON', (await call('GET', '/api/no-existe', { token: usera.token })).status === 404);
ok('error sin detalles internos (sin rutas de servidor, SQL ni trazas)', !/\/work\/|PDOException|Stack trace|SQLSTATE|\.php/i.test(all));
ok('el backend no habilita CORS para orígenes externos (por eso el frontend usa mismo origen)', !(await fetch(API + '/api/ping', { headers: { Origin: 'http://evil.example' } })).headers.has('access-control-allow-origin'));

for (const [status, name, extra] of results) console.log(`${status.padEnd(8)} ${name}${extra ? `  → ${extra}` : ''}`);
const f = results.filter(r => r[0] === 'FAIL').length; const h = results.filter(r => r[0] === 'HALLAZGO').length;
console.log(`\n${results.filter(r => r[0] === 'PASS').length} PASS / ${f} FAIL / ${h} HALLAZGOS`);
process.exit(f ? 1 : 0);
