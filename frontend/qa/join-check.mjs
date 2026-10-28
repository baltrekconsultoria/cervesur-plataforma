// Comprobación de solo lectura: ¿se puede obtener empresa↔informe para el usuario con endpoints YA existentes?
// Une GET /api/reportes (id numérico + reporte_id UUID) con GET /api/asignacion (empresa_id + reporte_id numérico) y GET /api/empresas.
const API = 'http://127.0.0.1:8080';
const get = async (p, t) => (await fetch(API + p, { headers: { Authorization: `Bearer ${t}` } })).json();
const tok = async u => (await (await fetch(API + '/api/login', { method: 'POST', body: JSON.stringify({ login: u, password: 'synthetic-password' }) })).json()).token;
for (const user of ['usera', 'userb', 'legacy', 'nobody', 'admin']) {
  const t = await tok(user);
  const [rep, asg, emp] = await Promise.all([get('/api/reportes', t), get('/api/asignacion', t), get('/api/empresas', t)]);
  const byNumeric = new Map(rep.data.map(r => [r.id, r]));
  const pairs = (asg.data?.data ?? []).filter(a => byNumeric.has(a.reporte_id)).map(a => `${byNumeric.get(a.reporte_id).nombre} @ ${a.empresa_nombre}`);
  const orphans = rep.data.filter(r => !(asg.data?.data ?? []).some(a => a.reporte_id === r.id)).map(r => r.nombre);
  const foreign = (asg.data?.data ?? []).filter(a => !emp.data.some(e => e.id === a.empresa_id)).map(a => a.empresa_nombre);
  console.log(`${user.padEnd(7)} reportes=${rep.data.length} asignaciones=${(asg.data?.data ?? []).length} empresas=${emp.data.length} | pares: ${pairs.join('; ') || '—'} | informes sin empresa: ${orphans.join(',') || 'ninguno'} | empresas de asignaciones no visibles en /empresas: ${foreign.join(',') || 'ninguna'}`);
}
