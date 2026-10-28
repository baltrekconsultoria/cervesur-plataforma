// Sonda de solo lectura del contrato efectivo (muestra formas, no valores sensibles). Uso: node qa/probe.mjs
const B = 'http://127.0.0.1:8080';
const call = async (method, path, body, token) => {
  const r = await fetch(B + path, { method, headers: { 'Content-Type': 'application/json', ...(token ? { Authorization: `Bearer ${token}` } : {}) }, body: body ? JSON.stringify(body) : undefined });
  let j = null; try { j = await r.json(); } catch { /* cuerpo no JSON */ }
  return { status: r.status, body: j };
};
const redact = (v, key = '') => Array.isArray(v) ? v.map(x => redact(x)) : v && typeof v === 'object' ? Object.fromEntries(Object.entries(v).map(([k, x]) => [k, redact(x, k)])) : (key === 'token' && v ? '<jwt:' + String(v).length + ' chars>' : v);
const show = (t, r) => console.log(`\n### ${t}\nHTTP ${r.status}\n${JSON.stringify(redact(r.body), null, 1).slice(0, 900)}`);
const login = async u => (await call('POST', '/api/login', { login: u, password: 'synthetic-password' }));
show('login usera', await login('usera'));
show('login mala contraseña', await call('POST', '/api/login', { login: 'usera', password: 'x' }));
show('login inactivo', await login('inactive'));
show('login sin cuerpo válido', await call('POST', '/api/login', { login: 'usera' }));
const t = (await login('usera')).body.token;
show('GET /api/user/profile', await call('GET', '/api/user/profile', null, t));
show('GET /api/empresas', await call('GET', '/api/empresas', null, t));
show('GET /api/reportes', await call('GET', '/api/reportes', null, t));
show('GET reporteid ajeno (B)', await call('GET', '/api/reportes/reporteid/00000000-0000-0000-0000-000000000022', null, t));
show('GET sin token', await call('GET', '/api/reportes'));
