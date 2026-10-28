// Diagnóstico de contratos del backend PHP (UX-06A.1). Solo lecturas y cargas inválidas, contra la base sintética desechable.
// No modifica datos. Requiere `npm run qa:up`. Uso: node qa/diagnose.mjs
const API = 'http://127.0.0.1:8080';
const PASSWORD = 'synthetic-password';

async function call(method, path, token, body) {
  const res = await fetch(API + path, { method, headers: { 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) }, body: body === undefined ? undefined : JSON.stringify(body) });
  const raw = await res.text();
  let json = null; try { json = JSON.parse(raw); } catch { /* no JSON */ }
  return { status: res.status, json, raw };
}
const token = async u => (await call('POST', '/api/login', null, { login: u, password: PASSWORD })).json.token;
const admin = await token('admin'); const usera = await token('usera');

const rowKeys = j => {
  const d = j?.data ?? j?.users ?? j?.profile ?? j?.user;
  const row = Array.isArray(d) ? d[0] : Array.isArray(d?.data) ? d.data[0] : d;
  return row && typeof row === 'object' ? Object.keys(row).join(',') : '';
};
const GETS = ['/api/ping', '/api/user', '/api/user/profile', '/api/users?status=all', '/api/stats', '/api/empresas', '/api/empresas/1', '/api/reportes', '/api/reportes/activos', '/api/reportes/1',
  '/api/asignacion', '/api/asignacion/1', '/api/asignacion/empresa/1', '/api/asignacion/reporte/1', '/api/empresa-usuario', '/api/empresa-usuario/1', '/api/empresa-usuario/empresa/Empresa%20A',
  '/api/empresa-reporte-usuario', '/api/empresa-reporte-usuario/1', '/api/empresa-reporte-usuario/user/1', '/api/empresa-reporte-usuario/empresa/Empresa%20A', '/api/powerbi/tokens/1'];

console.log('# 1. Barrido de lecturas (administrador / usera)\n');
console.log('| Endpoint | admin | usera | éxito con HTTP≠2xx / error con HTTP 200 | campos de la primera fila (admin) |\n|---|---|---|---|---|');
const anomalies = [];
for (const path of GETS) {
  const a = await call('GET', path, admin); const u = await call('GET', path, usera);
  const flag = r => (r.status === 200 && r.json?.success === false) ? 'error con HTTP 200' : r.status >= 500 ? 'HTTP 5xx' : '';
  const fa = flag(a), fu = flag(u);
  if (fa || fu) anomalies.push(`${path} → ${fa || fu}: "${(a.json?.message ?? u.json?.message ?? '').slice(0, 70)}"`);
  console.log(`| \`${path}\` | ${a.status}${a.json?.success === false ? ' ✗' : ''} | ${u.status}${u.json?.success === false ? ' ✗' : ''} | ${[fa && `admin: ${fa}`, fu && `usera: ${fu}`].filter(Boolean).join('; ') || '—'} | ${rowKeys(a.json).slice(0, 170)} |`);
}
console.log('\nAnomalías:\n' + (anomalies.length ? anomalies.map(x => `- ${x}`).join('\n') : '- ninguna'));

console.log('\n# 2. Catálogo de estados HTTP ante errores\n');
console.log('| Caso | HTTP | success | message |\n|---|---|---|---|');
const row = (name, r) => console.log(`| ${name} | ${r.status} | ${String(r.json?.success)} | ${(r.json?.message ?? r.json?.error ?? '').toString().slice(0, 70)} |`);
row('login con contraseña incorrecta', await call('POST', '/api/login', null, { login: 'usera', password: 'x' }));
row('login de usuario inactivo', await call('POST', '/api/login', null, { login: 'inactive', password: PASSWORD }));
row('login con campos vacíos', await call('POST', '/api/login', null, { login: '', password: '' }));
row('sin token', await call('GET', '/api/reportes'));
row('token inválido', await call('GET', '/api/reportes', 'x.y.z'));
row('usuario → recurso de administrador (/api/users)', await call('GET', '/api/users', usera));
row('informe ajeno (por uuid)', await call('GET', '/api/reportes/reporteid/00000000-0000-0000-0000-000000000022', usera));
row('empresa ajena (por id)', await call('GET', '/api/empresas/2', usera));
row('asignación ajena (por id)', await call('GET', '/api/asignacion/2', usera));
row('relación empresa-usuario ajena', await call('GET', '/api/empresa-usuario/2', usera));
row('relación usuario-reporte ajena', await call('GET', '/api/empresa-reporte-usuario/2', usera));
row('perfil ajeno', await call('GET', '/api/user/profile/2', usera));
row('Power BI: informe sin asignación', await call('GET', '/api/powerbi/tokens/2', usera));
row('crear empresa vacía (admin)', await call('POST', '/api/empresas', admin, {}));
row('crear reporte vacío (admin)', await call('POST', '/api/reportes', admin, {}));
row('actualizar perfil sin datos', await call('PUT', '/api/user/profile', usera, {}));
row('JSON inválido en login', await fetch(API + '/api/login', { method: 'POST', body: '{x' }).then(async r => ({ status: r.status, json: await r.json().catch(() => null) })));
row('ruta inexistente', await call('GET', '/api/nada', usera));

console.log('\n# 3. Ejemplos de volumen\n');
for (const p of ['/api/empresa-reporte-usuario', '/api/empresa-usuario']) {
  const r = await call('GET', p, admin);
  console.log(`- \`${p}\` (admin): ${r.json?.count ?? r.json?.data?.length ?? '?'} filas devueltas; el SQL contiene LIMIT 20 fijo (ver código).`);
}
const asg = await call('GET', '/api/asignacion', admin);
console.log(`- \`/api/asignacion\` (admin): claves de primer nivel ${Object.keys(asg.json ?? {}).join(',')}; \`data\` es ${Array.isArray(asg.json?.data) ? 'lista' : `objeto con ${Object.keys(asg.json?.data ?? {}).join(',')}`}.`);
