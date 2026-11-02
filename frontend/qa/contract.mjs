// Pruebas de contrato y aislamiento contra el backend PHP REAL del entorno QA local (127.0.0.1:8080 / 8081).
// Requiere `node qa/backend.mjs up`. No usa el navegador: demuestra que la autorización vive en PHP.
// Salida: PASS / FAIL / HALLAZGO (comportamiento conocido del backend que conviene corregir; no hace fallar la ejecución).
import { createHmac, randomBytes } from 'node:crypto';

const API_PORT = Number(process.env.QA_API_PORT ?? 8080);
const API = `http://127.0.0.1:${API_PORT}`;
const SHORT = `http://127.0.0.1:${API_PORT + 1}`;
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
ok('rol heredado "usuario": el login devuelve el rol normalizado "user" (SEC-05A)', legacy.status === 200 && legacy.json?.success === true && legacy.json?.user?.role === 'user');
ok('rol heredado "usuario" no tiene privilegios de administrador (403 en /api/users)', (await call('GET', '/api/users', { token: legacy.token })).status === 403);
ok('rol heredado ve solo su informe asignado', names(await call('GET', '/api/reportes', { token: legacy.token })).join() === 'Reporte A');
ok('rol inesperado "superadmin": el login se rechaza con HTTP 200 y success:false (contrato de errores sin cambios)', weird.status === 200 && weird.json?.success === false);
ok('rol inesperado "superadmin": mensaje público genérico "Credenciales inválidas"', weird.json?.message === 'Credenciales inválidas');
ok('rol inesperado "superadmin": no se emite JWT ni datos de usuario', weird.token === undefined && weird.json?.user === undefined && !/eyJ[A-Za-z0-9_-]{10,}\./.test(weird.raw));
ok('rol inesperado "superadmin": un token ausente no abre /api/users (401)', (await call('GET', '/api/users', { token: weird.token })).status === 401);

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

section = 'Administración de accesos (UX-07A)';
const sameKeys = (row, keys) => JSON.stringify(Object.keys(row).sort()) === JSON.stringify([...keys].sort());
const adminUsersAll = await walkAll('/api/users?status=all', admin.token, 100);
ok('administrador: /api/users?status=all devuelve todos los usuarios (activos e inactivos) con total coherente', adminUsersAll.ok && adminUsersAll.rows.length === adminUsersAll.metas[0].total && adminUsersAll.rows.some(u => u.is_active === false) && adminUsersAll.rows.some(u => u.is_active === true));
ok('/api/users: sin contraseña ni hash ni session_version', !/password|session_version|\$2y\$/i.test(JSON.stringify(adminUsersAll.rows)));
const euMin = await walkAll('/api/empresa-usuario?view=minimal', admin.token, 100);
ok('/api/empresa-usuario?view=minimal: solo id, user_id, empresa_id y activo (relación usuario ↔ empresa)', euMin.ok && euMin.rows.length > 0 && euMin.rows.every(r => sameKeys(r, ['id', 'user_id', 'empresa_id', 'activo'])));
const eruMin = await walkAll('/api/empresa-reporte-usuario?view=minimal', admin.token, 100);
ok('/api/empresa-reporte-usuario?view=minimal: ids de relación y UUID del informe, sin enlace de Power BI', eruMin.ok && eruMin.rows.length > 0 && eruMin.rows.every(r => sameKeys(r, ['id', 'user_id', 'empresa_id', 'empresa_reporte_id', 'reporte_id', 'activo'])) && !/enlace|example\.invalid/.test(JSON.stringify(eruMin.rows)));
ok('los tres identificadores son distintos: id de acceso, id empresa_reporte y UUID del informe', eruMin.rows.every(r => typeof r.reporte_id === 'string' && /^[0-9a-f-]{36}$/.test(r.reporte_id) && Number.isInteger(r.empresa_reporte_id) && Number.isInteger(r.id)));
const eruLegacy = await call('GET', '/api/empresa-reporte-usuario?per_page=2', { token: admin.token });
ok('compatibilidad: sin view se conservan los campos históricos (usuario, empresa_nombre, reporte_nombre) y se añaden los ids', eruLegacy.status === 200 && rowsOf(eruLegacy.json).every(r => 'usuario' in r && 'empresa_nombre' in r && 'reporte_nombre' in r && 'user_id' in r && 'empresa_reporte_id' in r));
const asgMin = await walkAll('/api/asignacion?view=minimal', admin.token, 100);
ok('/api/asignacion?view=minimal aporta el id de la relación empresa_reporte que usa el alta de accesos', asgMin.ok && asgMin.rows.every(a => Number.isInteger(a.id) && Number.isInteger(a.empresa_id) && Number.isInteger(a.reporte_id)));

for (const [label, method, path, body] of [['vincular usuario a empresa', 'POST', '/api/empresa-usuario', { user_id: 1, empresa_id: 1 }], ['conceder acceso', 'POST', '/api/empresa-reporte-usuario', { user_id: 1, empresa_reporte_id: 1 }], ['retirar acceso', 'DELETE', '/api/empresa-reporte-usuario/1', undefined], ['crear usuario', 'POST', '/api/register', { first_name: 'X', last_name: 'Y', username: 'intruso', email: 'intruso@example.invalid', password: 'synthetic-password' }]]) {
  ok(`usuario normal no puede ${label} → 403`, (await call(method, path, { token: usera.token, body })).status === 403);
  ok(`sin autenticación no se puede ${label} → 401`, (await call(method, path, { body })).status === 401);
}

const suffix = randomBytes(3).toString('hex');
const newUser = { first_name: 'Contrato', last_name: 'Prueba', username: `contrato.${suffix}`, email: `contrato.${suffix}@example.invalid`, password: PASSWORD };
const created = await call('POST', '/api/register', { token: admin.token, body: { ...newUser, role: 'admin' } });
ok('alta que intenta fijar el rol → 400 y no crea nada', created.status === 400 && !(await walkAll('/api/users?status=all', admin.token, 100)).rows.some(u => u.username === newUser.username));
const made = await call('POST', '/api/register', { token: admin.token, body: newUser });
ok('alta válida → success:true con el mensaje histórico', made.status === 200 && made.json?.success === true && made.json?.message === 'Usuario registrado exitosamente');
const listed = (await walkAll('/api/users?status=all', admin.token, 100)).rows.find(u => u.username === newUser.username);
ok('el usuario creado figura con rol user y activo; el servidor decidió ambos', listed?.role === 'user' && listed?.is_active === true);
ok('alta duplicada → success:false con mensaje claro', (await call('POST', '/api/register', { token: admin.token, body: newUser })).json?.success === false);
const fresh = await login(newUser.username);
ok('el usuario creado inicia sesión con el rol user', fresh.json?.success === true && fresh.json?.user?.role === 'user');
ok('antes de asignar, no ve ningún informe', (await walkAll('/api/reportes?view=minimal', fresh.token, 100)).rows.length === 0);
const relationA = asgMin.rows.find(a => a.empresa_nombre === 'Empresa A' && a.reporte_nombre === 'Reporte A');
ok('conceder acceso sin relación con la empresa → success:false y no se crea nada', (await call('POST', '/api/empresa-reporte-usuario', { token: admin.token, body: { user_id: listed.id, empresa_reporte_id: relationA.id } })).json?.success === false && (await walkAll('/api/empresa-reporte-usuario/user/' + listed.id, admin.token, 100)).rows.length === 0);
ok('vincular usuario a Empresa A → success:true', (await call('POST', '/api/empresa-usuario', { token: admin.token, body: { user_id: listed.id, empresa_id: relationA.empresa_id } })).json?.success === true);
ok('vincular de nuevo no duplica', (await call('POST', '/api/empresa-usuario', { token: admin.token, body: { user_id: listed.id, empresa_id: relationA.empresa_id } })).json?.success === false);
const granted = await call('POST', '/api/empresa-reporte-usuario', { token: admin.token, body: { user_id: listed.id, empresa_reporte_id: relationA.id } });
ok('conceder Reporte A (id empresa_reporte) → success:true con InsertedId', granted.json?.success === true && Number(granted.json?.InsertedId) > 0);
ok('conceder de nuevo no duplica', (await call('POST', '/api/empresa-reporte-usuario', { token: admin.token, body: { user_id: listed.id, empresa_reporte_id: relationA.id } })).json?.success === false);
ok('el UUID del informe no sirve como id de relación', (await call('POST', '/api/empresa-reporte-usuario', { token: admin.token, body: { user_id: listed.id, empresa_reporte_id: '00000000-0000-0000-0000-000000000011' } })).json?.success === false);
ok('ahora ve exactamente Reporte A y ningún informe de Empresa B', JSON.stringify(names(await call('GET', '/api/reportes?view=minimal&per_page=100', { token: fresh.token }))) === JSON.stringify(['Reporte A']) && (await call('GET', '/api/reportes/reporteid/00000000-0000-0000-0000-000000000022?view=minimal', { token: fresh.token })).status === 404);
const mine = (await walkAll('/api/empresa-reporte-usuario?view=minimal', admin.token, 100)).rows.find(r => r.user_id === listed.id && r.empresa_reporte_id === relationA.id);
ok('el acceso aparece en el listado administrativo con su id de relación', mine && Number(mine.id) === Number(granted.json.InsertedId));
ok('retirar el acceso → success:true', (await call('DELETE', `/api/empresa-reporte-usuario/${mine.id}`, { token: admin.token })).json?.success === true);
ok('retirar de nuevo → success:false (ya inactivo)', (await call('DELETE', `/api/empresa-reporte-usuario/${mine.id}`, { token: admin.token })).json?.success === false);
ok('tras retirar ya no ve informes', (await walkAll('/api/reportes?view=minimal', fresh.token, 100)).rows.length === 0 && (await call('GET', '/api/reportes/reporteid/00000000-0000-0000-0000-000000000011?view=minimal', { token: fresh.token })).status === 404);
const regrant = await call('POST', '/api/empresa-reporte-usuario', { token: admin.token, body: { user_id: listed.id, empresa_reporte_id: relationA.id } });
ok('volver a conceder reactiva la misma relación (sin duplicar filas)', regrant.json?.success === true && Number(regrant.json.InsertedId) === Number(mine.id));
ok('tras reactivar vuelve a ver Reporte A', JSON.stringify(names(await call('GET', '/api/reportes?view=minimal&per_page=100', { token: fresh.token }))) === JSON.stringify(['Reporte A']));

section = 'Empresas, informes y asociaciones (UX-07B)';
const rnd = n => Array.from({ length: n }, () => Math.floor(Math.random() * 10)).join('');
const ruc1 = `2${rnd(10)}`; const ruc2 = `2${rnd(10)}`;
const companyBody = { nombre: `Empresa ${ruc1}`, razon_social: `Empresa ${ruc1} S.A.C.`, ruc: ruc1, ciudad: 'Ciudad X', pais: 'Demo' };
ok('usuario normal no puede crear empresas (403) ni anónimo (401)', (await call('POST', '/api/empresas', { token: usera.token, body: companyBody })).status === 403 && (await call('POST', '/api/empresas', { body: companyBody })).status === 401);
ok('RUC inválido → success:false', (await call('POST', '/api/empresas', { token: admin.token, body: { ...companyBody, ruc: '123' } })).json?.success === false);
const newCompany = await call('POST', '/api/empresas', { token: admin.token, body: companyBody });
const companyId = Number(newCompany.json?.id);
ok('administrador crea una empresa y recibe su id', newCompany.json?.success === true && companyId > 0);
ok('RUC duplicado → success:false', (await call('POST', '/api/empresas', { token: admin.token, body: { ...companyBody, nombre: 'Otra' } })).json?.success === false);
const form = await call('GET', `/api/empresas/${companyId}?view=form`, { token: admin.token });
ok('empresa view=form: campos editables sin datos de Power BI', JSON.stringify(Object.keys(form.json?.data ?? {}).sort()) === JSON.stringify(['activo', 'ciudad', 'direccion', 'email', 'id', 'nombre', 'pais', 'razon_social', 'ruc', 'telefono', 'version']) && !/powerbi|secret|tenant/i.test(form.raw));
ok('empresa view=form: usuario normal → 403', (await call('GET', `/api/empresas/${companyId}?view=form`, { token: usera.token })).status === 403);
ok('editar empresa → success:true y persiste', (await call('PUT', `/api/empresas/${companyId}`, { token: admin.token, body: { ciudad: 'Ciudad Y', telefono: '999 000 222' } })).json?.success === true && (await call('GET', `/api/empresas/${companyId}?view=form`, { token: admin.token })).json?.data?.ciudad === 'Ciudad Y');
ok('no se puede vaciar el nombre al editar', (await call('PUT', `/api/empresas/${companyId}`, { token: admin.token, body: { nombre: '  ' } })).json?.success === false);
ok('usuario normal no puede editar empresas (403)', (await call('PUT', `/api/empresas/${companyId}`, { token: usera.token, body: { ciudad: 'X' } })).status === 403);
ok('editar al RUC de otra empresa → success:false', (await call('PUT', `/api/empresas/${companyId}`, { token: admin.token, body: { ruc: '00000000001' } })).json?.success === false);

const uuidReport = crypto.randomUUID(); const uuidGroup = crypto.randomUUID();
// UX-07C: el informe se registra con su enlace; los identificadores se extraen del enlace de inserción.
const reportBody = { nombre: `Informe ${ruc2}`, descripcion: 'Sintético', enlace: `https://app.powerbi.com/reportEmbed?reportId=${uuidReport.toUpperCase()}&groupId=${uuidGroup}` };
ok('usuario normal no puede crear reportes (403)', (await call('POST', '/api/reportes', { token: usera.token, body: reportBody })).status === 403);
for (const [field, bad] of [['enlace', 'no-url'], ['enlace', 'https://example.invalid/x'], ['grupo_id', 'x'], ['reporte_id', '123'], ['nombre', '']]) {
  ok(`reporte con ${field} inválido → success:false`, (await call('POST', '/api/reportes', { token: admin.token, body: { ...reportBody, [field]: bad } })).json?.success === false);
}
const newReport = await call('POST', '/api/reportes', { token: admin.token, body: reportBody });
const reportInternalId = Number(newReport.json?.data?.id);
ok('administrador crea un reporte y recibe su id interno', newReport.json?.success === true && reportInternalId > 0);
ok('UUID de Power BI repetido → success:false', (await call('POST', '/api/reportes', { token: admin.token, body: { ...reportBody, nombre: 'Copia' } })).json?.success === false);
const reportForm = await call('GET', `/api/reportes/reporteid/${uuidReport}?view=form`, { token: admin.token });
ok('el UUID se guarda en minúsculas y el id interno es distinto del UUID', reportForm.json?.data?.reporte_id === uuidReport && Number(reportForm.json?.data?.id) === reportInternalId && JSON.stringify(Object.keys(reportForm.json?.data ?? {}).sort()) === JSON.stringify(['activo', 'descripcion', 'enlace', 'grupo_id', 'id', 'nombre', 'reporte_id', 'version']));
ok('reporte view=form: usuario normal → 403 (el enlace técnico no se entrega)', (await call('GET', `/api/reportes/${reportInternalId}?view=form`, { token: usera.token })).status === 403 && (await call('GET', `/api/reportes/reporteid/${uuidReport}?view=form`, { token: usera.token })).status === 403);
ok('editar reporte → success:true y persiste', (await call('PUT', `/api/reportes/${reportInternalId}`, { token: admin.token, body: { ...reportBody, reporte_id: uuidReport, descripcion: 'Editado' } })).json?.success === true && (await call('GET', `/api/reportes/${reportInternalId}?view=form`, { token: admin.token })).json?.data?.descripcion === 'Editado');
ok('edición parcial → success:false con mensaje claro (antes fallaba sin explicación)', (await call('PUT', `/api/reportes/${reportInternalId}`, { token: admin.token, body: { nombre: 'Solo nombre' } })).json?.success === false);
ok('usuario normal no puede editar reportes (403)', (await call('PUT', `/api/reportes/${reportInternalId}`, { token: usera.token, body: { ...reportBody, reporte_id: uuidReport } })).status === 403);

const linkNew = await call('POST', '/api/asignacion', { token: admin.token, body: { empresa_id: companyId, reporte_id: reportInternalId } });
const linkId = Number(linkNew.json?.data?.id);
ok('asociar el reporte a la empresa (ids numéricos) → 201', linkNew.status === 201 && linkNew.json?.success === true && linkId > 0);
const linkDup = await call('POST', '/api/asignacion', { token: admin.token, body: { empresa_id: companyId, reporte_id: reportInternalId } });
ok('asociación duplicada → 400 con mensaje específico', linkDup.status === 400 && /Ya existe/.test(linkDup.json?.message ?? ''));
ok('el UUID no sirve como id de reporte en la asociación', (await call('POST', '/api/asignacion', { token: admin.token, body: { empresa_id: companyId, reporte_id: uuidReport } })).json?.success === false);
ok('usuario normal no puede asociar (403)', (await call('POST', '/api/asignacion', { token: usera.token, body: { empresa_id: companyId, reporte_id: reportInternalId } })).status === 403);
const reportOnEmpresaA = (await walkAll('/api/asignacion?view=minimal', admin.token, 100)).rows.find(a => a.empresa_nombre === 'Empresa A');
const shared = await call('POST', '/api/asignacion', { token: admin.token, body: { empresa_id: reportOnEmpresaA.empresa_id, reporte_id: reportInternalId } });
ok('el mismo reporte puede asociarse a una segunda empresa', shared.json?.success === true);
const allLinks = (await walkAll('/api/asignacion?view=minimal', admin.token, 100)).rows.filter(a => a.reporte_id === reportInternalId);
ok('el listado de asociaciones muestra las dos empresas con ids de relación distintos', allLinks.length === 2 && new Set(allLinks.map(a => a.id)).size === 2);
ok('desvincular la segunda empresa → success:true', (await call('DELETE', `/api/asignacion/${shared.json.data.id}`, { token: admin.token })).json?.success === true);

const sfx = rnd(6);
const opUser = { first_name: 'Operador', last_name: 'C', username: `operador.c.${sfx}`, email: `operador.c.${sfx}@example.invalid`, password: PASSWORD };
await call('POST', '/api/register', { token: admin.token, body: opUser });
const opId = (await walkAll('/api/users?status=all', admin.token, 100)).rows.find(u => u.username === opUser.username).id;
await call('POST', '/api/empresa-usuario', { token: admin.token, body: { user_id: opId, empresa_id: companyId } });
ok('UX-07A sobre el reporte nuevo: acceso concedido', (await call('POST', '/api/empresa-reporte-usuario', { token: admin.token, body: { user_id: opId, empresa_reporte_id: linkId } })).json?.success === true);
const op = await login(opUser.username);
ok('el usuario ve solo el reporte nuevo', JSON.stringify(names(await call('GET', '/api/reportes?view=minimal&per_page=100', { token: op.token }))) === JSON.stringify([`Informe ${ruc2}`]));
ok('el usuario no recibe el enlace técnico', !/example\.invalid|enlace/.test((await call('GET', '/api/reportes?view=minimal&per_page=100', { token: op.token })).raw));
ok('desvincular el reporte de la empresa → success:true', (await call('DELETE', `/api/asignacion/${linkId}`, { token: admin.token })).json?.success === true);
ok('desvincular dos veces → success:false', (await call('DELETE', `/api/asignacion/${linkId}`, { token: admin.token })).json?.success === false);
ok('tras desvincular, el usuario deja de ver el reporte y no puede abrirlo', (await walkAll('/api/reportes?view=minimal', op.token, 100)).rows.length === 0 && (await call('GET', `/api/reportes/reporteid/${uuidReport}?view=minimal`, { token: op.token })).status === 404);
const relink = await call('POST', '/api/asignacion', { token: admin.token, body: { empresa_id: companyId, reporte_id: reportInternalId } });
ok('volver a asociar reactiva la misma relación (sin duplicar) y restituye el acceso', relink.json?.success === true && Number(relink.json?.data?.id) === linkId && JSON.stringify(names(await call('GET', '/api/reportes?view=minimal&per_page=100', { token: op.token }))) === JSON.stringify([`Informe ${ruc2}`]));


section = 'Visor de Power BI y registro por enlace (UX-07C)';
const countReports = async () => (await walkAll('/api/reportes?view=minimal', admin.token, 100)).rows.length;
const publicLink = t => `https://app.powerbi.com/view?r=${t}`;
const tok1 = `U0lOVEVUSUNP${ruc1.slice(0, 9)}`; const tok2 = `U0lOVEVUSUNQ${ruc1.slice(0, 9)}`;
const reportsBefore = await countReports();
for (const [label, bad] of [['dominio externo', 'https://example.invalid/view?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDE'], ['HTTP', 'http://app.powerbi.com/view?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDE'], ['iframe pegado', '<iframe src="https://app.powerbi.com/view?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDE"></iframe>'], ['host suplantado', 'https://app.powerbi.com.evil.example/view?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDE'], ['parámetro de redirección', 'https://app.powerbi.com/view?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDE&redirect=https://evil.example'], ['javascript', 'javascript:alert(1)'], ['inserción sin reportId', 'https://app.powerbi.com/reportEmbed?groupId=11111111-2222-4333-8444-555555555555']]) {
  const r = await call('POST', '/api/reportes', { token: admin.token, body: { nombre: `Malicioso ${ruc1}`, enlace: bad } });
  ok(`enlace rechazado por el servidor (${label})`, r.json?.success === false);
}
ok('los enlaces rechazados no crearon ningún informe', (await countReports()) === reportsBefore);

const pubCreated = await call('POST', '/api/reportes', { token: admin.token, body: { nombre: `Público ${ruc1}`, descripcion: 'Solo enlace', enlace: publicLink(tok1) } });
const pubId = Number(pubCreated.json?.data?.id); const pubRef = pubCreated.json?.data?.reporte_id;
ok('se registra un informe con solo nombre, descripción y enlace público', pubCreated.json?.success === true && pubId > 0);
const pubForm = (await call('GET', `/api/reportes/${pubId}?view=form`, { token: admin.token })).json?.data;
ok('no se inventan ids de Power BI: grupo NULL y referencia interna UUID v4 distinta del token', pubForm?.grupo_id === null && /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/.test(pubRef ?? '') && !pubForm?.enlace.includes(pubRef));
const embId = crypto.randomUUID();
const embCreated = await call('POST', '/api/reportes', { token: admin.token, body: { nombre: `Inserción ${ruc1}`, enlace: `https://app.powerbi.com/reportEmbed?reportId=${embId.toUpperCase()}` } });
const embForm = (await call('GET', `/api/reportes/${Number(embCreated.json?.data?.id)}?view=form`, { token: admin.token })).json?.data;
ok('en un enlace de inserción se extrae el reportId (minúsculas) y el grupo ausente queda NULL', embCreated.json?.success === true && embForm?.reporte_id === embId && embForm?.grupo_id === null);

const pubLink = Number((await call('POST', '/api/asignacion', { token: admin.token, body: { empresa_id: companyId, reporte_id: pubId } })).json?.data?.id);
const embLink = Number((await call('POST', '/api/asignacion', { token: admin.token, body: { empresa_id: companyId, reporte_id: Number(embCreated.json.data.id) } })).json?.data?.id);
const grantPub = Number((await call('POST', '/api/empresa-reporte-usuario', { token: admin.token, body: { user_id: opId, empresa_reporte_id: pubLink } })).json?.InsertedId);
await call('POST', '/api/empresa-reporte-usuario', { token: admin.token, body: { user_id: opId, empresa_reporte_id: embLink } });
const viewerOf = (ref, token) => call('GET', `/api/reportes/reporteid/${ref}/visor`, { token });
const v = await viewerOf(pubRef, op.token);
ok('el usuario autorizado recibe el modo público con su enlace', v.status === 200 && v.json?.data?.modo === 'publico' && v.json?.data?.url === publicLink(tok1));
ok('la respuesta no contiene credenciales, secretos ni tokens generales', !/secret|tenant|client|access_token|password|powerbi_/i.test(v.raw));
const headers = (await fetch(`${API}/api/reportes/reporteid/${pubRef}/visor`, { headers: { Authorization: `Bearer ${op.token}` } })).headers;
ok('la configuración del visor se sirve con Cache-Control: no-store', /no-store/.test(headers.get('cache-control') ?? '') && /no-cache/.test(headers.get('pragma') ?? ''));
const dead = await viewerOf('00000000-0000-4000-8000-00000000dead', usera.token);
const denied = await viewerOf(pubRef, usera.token);
ok('un usuario sin acceso recibe exactamente la misma respuesta que ante un informe inexistente (no revela su existencia)', denied.status === 404 && denied.raw === dead.raw && !/powerbi/.test(denied.raw));
ok('sin autenticación no hay configuración del visor', (await viewerOf(pubRef)).status === 401);
ok('el administrador puede abrir cualquier informe activo', (await viewerOf(pubRef, admin.token)).json?.data?.modo === 'publico');
const emb = await viewerOf(embId, op.token);
ok('inserción autenticada: modo «privado», sin enlace ni tokens (requiere configuración de Microsoft)', emb.status === 200 && emb.json?.data?.modo === 'privado' && !('url' in (emb.json?.data ?? {})) && !/powerbi\.com/.test(emb.raw));
const legacyViewer = await viewerOf('00000000-0000-0000-0000-000000000044', admin.token);
ok('un dato antiguo con enlace no compatible → «no_compatible» y su enlace no se devuelve', legacyViewer.json?.data?.modo === 'no_compatible' && !/example\.invalid/.test(legacyViewer.raw));
// UX-07D.2B: visor por id NUMÉRICO (la ruta que usa React; en producción reporte_id no es un UUID). La ruta por UUID anterior sigue intacta.
const viewerById = (id, token) => call('GET', `/api/reportes/id/${id}/visor`, { token });
const vn = await viewerById(pubId, op.token);
ok('visor por id numérico: el usuario autorizado recibe el modo público, el id y la referencia histórica', vn.status === 200 && vn.json?.data?.id === pubId && vn.json?.data?.reporte_id === pubRef && vn.json?.data?.modo === 'publico' && vn.json?.data?.url === publicLink(tok1));
ok('visor por id numérico: sin credenciales, secretos ni tokens generales', !/secret|tenant|client|access_token|password|powerbi_/i.test(vn.raw));
const deadN = await viewerById(4000000000, usera.token);
const deniedN = await viewerById(pubId, usera.token);
ok('visor por id numérico: sin acceso → respuesta idéntica a la de un informe inexistente', deniedN.status === 404 && deniedN.raw === deadN.raw && !/powerbi/.test(deniedN.raw));
ok('visor por id numérico: sin autenticación → 401', (await viewerById(pubId)).status === 401);
ok('visor por id numérico: ids malformados o fuera de rango → siempre 404 sin datos', (await Promise.all(['0', '-1', 'abc', '1.5', '007', '99999999999', '4294967296', '1e3'].map(id => viewerById(id, admin.token)))).every(r => r.status === 404 && r.json?.success === false && !/powerbi/.test(r.raw)));
const embN = await viewerById(Number(embCreated.json.data.id), op.token);
ok('visor por id numérico: inserción autenticada → modo «privado» sin enlace', embN.status === 200 && embN.json?.data?.modo === 'privado' && !('url' in (embN.json?.data ?? {})));
const catalog = await call('GET', '/api/reportes?view=minimal&per_page=100', { token: op.token });
ok('el catálogo normal (vista mínima) no entrega ningún enlace', !/powerbi\.com|"enlace"/.test(catalog.raw));

await call('DELETE', `/api/empresa-reporte-usuario/${grantPub}`, { token: admin.token });
ok('un acceso retirado bloquea nuevas solicitudes del visor', (await viewerOf(pubRef, op.token)).status === 404);
ok('un acceso retirado también bloquea el acceso directo por id numérico (el servidor, no la interfaz)', (await viewerById(pubId, op.token)).status === 404);
await call('POST', '/api/empresa-reporte-usuario', { token: admin.token, body: { user_id: opId, empresa_reporte_id: pubLink } });
ok('al volver a conceder el acceso, el visor responde de nuevo', (await viewerOf(pubRef, op.token)).status === 200 && (await viewerById(pubId, op.token)).status === 200);
await call('DELETE', `/api/asignacion/${pubLink}`, { token: admin.token });
ok('una asociación empresa ↔ informe desactivada también lo bloquea (por UUID y por id numérico)', (await viewerOf(pubRef, op.token)).status === 404 && (await viewerById(pubId, op.token)).status === 404);
await call('POST', '/api/asignacion', { token: admin.token, body: { empresa_id: companyId, reporte_id: pubId } });

const edited = await call('PUT', `/api/reportes/${pubId}`, { token: admin.token, body: { nombre: `Público ${ruc1}`, descripcion: 'Editado', enlace: publicLink(tok2) } });
const afterEdit = (await call('GET', `/api/reportes/${pubId}?view=form`, { token: admin.token })).json?.data;
ok('editar el enlace conserva el id interno, la referencia y las asociaciones, e invalida el enlace anterior', edited.json?.success === true && afterEdit?.reporte_id === pubRef && Number(afterEdit?.id) === pubId && (await viewerOf(pubRef, op.token)).json?.data?.url === publicLink(tok2));
ok('el historial de rutas por UUID sigue funcionando', (await call('GET', `/api/reportes/reporteid/${pubRef}?view=minimal`, { token: admin.token })).json?.data?.reporte_id === pubRef);
await call('DELETE', `/api/reportes/${pubId}`, { token: admin.token });
ok('un informe dado de baja no se puede abrir ni por su administrador (por UUID ni por id numérico)', (await viewerOf(pubRef, admin.token)).status === 404 && (await viewerById(pubId, admin.token)).status === 404);
await call('DELETE', `/api/reportes/${Number(embCreated.json.data.id)}`, { token: admin.token });

// Limpieza: la empresa y el reporte de prueba se dan de baja (baja lógica) para no alterar los recuentos de otras suites sobre el mismo entorno.
await call('DELETE', `/api/empresas/${companyId}`, { token: admin.token });
await call('DELETE', `/api/reportes/${reportInternalId}`, { token: admin.token });

section = 'Errores HTTP';
ok('ruta inexistente → 404 JSON', (await call('GET', '/api/no-existe', { token: usera.token })).status === 404);
ok('error sin detalles internos (sin rutas de servidor, SQL ni trazas)', !/\/work\/|PDOException|Stack trace|SQLSTATE|\.php/i.test(all));
ok('el backend no habilita CORS para orígenes externos (por eso el frontend usa mismo origen)', !(await fetch(API + '/api/ping', { headers: { Origin: 'http://evil.example' } })).headers.has('access-control-allow-origin'));

for (const [status, name, extra] of results) console.log(`${status.padEnd(8)} ${name}${extra ? `  → ${extra}` : ''}`);
const f = results.filter(r => r[0] === 'FAIL').length; const h = results.filter(r => r[0] === 'HALLAZGO').length;
console.log(`\n${results.filter(r => r[0] === 'PASS').length} PASS / ${f} FAIL / ${h} HALLAZGOS`);
process.exit(f ? 1 : 0);
