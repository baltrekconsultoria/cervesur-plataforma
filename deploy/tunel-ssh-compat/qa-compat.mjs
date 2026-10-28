// QA COMPLETO del entorno de compatibilidad productiva (UX-07D.2C): API PHP real + frontend React de PRODUCCIÓN (dist-production) + MySQL sintético
// con la forma y los agregados de producción, accediendo como lo haría el operador (http://localhost:<puerto del túnel>). Lo invoca ensayo-docker.mjs.
// IMPORTANTE: los enlaces de Power BI del fixture son FICTICIOS. Estas pruebas comprueban que el iframe se construye con la URL esperada; NO prueban
// que Power BI funcione (las peticiones a *.powerbi.com se bloquean en el navegador de prueba para no contactar a Microsoft con valores inventados).
import { randomBytes } from 'node:crypto';
import { launch } from '../../frontend/e2e/cdp.mjs';

const EFFECTIVE = 'FROM user_empresa_reporte uer JOIN user u ON u.id = uer.user_id AND u.is_active = 1 JOIN empresa_reporte er ON er.id = uer.empresa_reporte_id AND er.activo = 1 JOIN empresa e ON e.id = er.empresa_id AND e.activo = 1 JOIN empresa_usuario eu ON eu.empresa_id = e.id AND eu.user_id = u.id AND eu.activo = 1 JOIN reporte r ON r.id = er.reporte_id AND r.activo = 1 WHERE uer.activo = 1';
const UUID_V4 = /^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/;

export async function runCompatQa({ ok, setSection, base, accounts, sql, browserPort = 9521 }) {
  const admin = 'syn001';
  const api = async (method, path, { token, body } = {}) => {
    const res = await fetch(base + path, { method, headers: { ...(body ? { 'Content-Type': 'application/json' } : {}), ...(token ? { Authorization: `Bearer ${token}` } : {}) }, body: body ? JSON.stringify(body) : undefined });
    const raw = await res.text(); let json = null; try { json = JSON.parse(raw); } catch { /* no JSON */ }
    return { status: res.status, json, raw };
  };
  const loginApi = async (user, password) => (await api('POST', '/api/login', { body: { login: user, password } })).json;
  const adminToken = (await loginApi(admin, accounts[admin])).token;
  const walk = async (path, token) => { const all = []; for (let page = 1; page < 30; page++) { const { json } = await api('GET', `${path}${path.includes('?') ? '&' : '?'}per_page=100&page=${page}`, { token }); all.push(...(json.data?.data ?? json.data ?? [])); if (!json.pagination?.has_more) break; } return all; };
  const q = async (query) => (await sql(query)).map(r => r.split('\t'));
  const one = async (query) => (await q(query))[0]?.[0] ?? null;

  // ====================================================================================================== API
  setSection('QA · datos y roles históricos (API)');
  const reports = await walk('/api/reportes?view=minimal', adminToken);
  ok('el administrador lista exactamente 82 informes activos con ids numéricos únicos', reports.length === 82 && new Set(reports.map(r => r.id)).size === 82 && reports.every(r => Number.isInteger(r.id)));
  ok('ninguna referencia histórica es un UUID canónico y hay referencias de hasta 50 caracteres', reports.every(r => !/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/.test(r.reporte_id)) && Math.max(...reports.map(r => r.reporte_id.length)) === 50);
  const l5 = await loginApi('syn005', accounts.syn005);
  ok('rol histórico «usuario»: inicia sesión y el servidor lo normaliza a un rol que React acepta', l5.success === true && ['user', 'usuario'].includes(l5.user.role));
  await sql("UPDATE user SET role = 'user' WHERE id = 7");
  const l7 = await loginApi('syn007', accounts.syn007);
  await sql("UPDATE user SET role = 'usuario' WHERE id = 7");
  ok("rol histórico «user»: también inicia sesión", l7.success === true && ['user', 'usuario'].includes(l7.user.role));
  ok('rol «admin»: el administrador entra y puede listar usuarios', (await api('GET', '/api/users?status=all&per_page=1', { token: adminToken })).status === 200);
  ok('usuarios inactivos sembrados (usuario 66, user 67, admin 2): no inician sesión', (await Promise.all([['syn066', 'x'], ['syn067', 'x'], ['syn002', 'x']].map(([u, p]) => loginApi(u, p)))).every(r => r?.success === false));
  const eff5 = (await q(`SELECT DISTINCT r.id ${EFFECTIVE} AND u.id = 5 ORDER BY r.id`)).map(r => Number(r[0]));
  const cat5 = (await walk('/api/reportes?view=minimal', l5.token)).map(r => r.id).sort((a, b) => a - b);
  ok(`syn005 ve exactamente sus informes efectivos (${eff5.join(', ')})`, JSON.stringify(cat5) === JSON.stringify(eff5) && eff5.length >= 1);
  const own = eff5[0]; const foreign = Number(await one(`SELECT r.id FROM reporte r WHERE r.activo = 1 AND r.id NOT IN (${eff5.join(',')}) ORDER BY r.id LIMIT 1`));

  setSection('QA · visor por id numérico y rutas históricas (API)');
  const own5 = await api('GET', `/api/reportes/id/${own}/visor`, { token: l5.token });
  ok('visor numérico: informe autorizado → 200, modo público, id y referencia histórica', own5.status === 200 && own5.json.data.id === own && own5.json.data.modo === 'publico' && own5.json.data.reporte_id === reports.find(r => r.id === own).reporte_id);
  const foreign5 = await api('GET', `/api/reportes/id/${foreign}/visor`, { token: l5.token }); const dead5 = await api('GET', '/api/reportes/id/4000000000/visor', { token: l5.token });
  ok('visor numérico: informe ajeno → 404 idéntico al de un informe inexistente y sin enlace', foreign5.status === 404 && foreign5.raw === dead5.raw && !/powerbi|url/.test(foreign5.raw));
  const malformed = ['0', '-1', 'abc', '1.5', '007', '99999999999', '4294967296', '1e3', '%20', '1%20OR%201%3D1'];
  ok(`visor numérico: ${malformed.length} ids malformados → siempre 404 sin datos`, (await Promise.all(malformed.map(id => api('GET', `/api/reportes/id/${id}/visor`, { token: adminToken })))).every(r => r.status === 404 && r.json?.success === false));
  const longRef = reports.find(r => r.reporte_id.length === 50).reporte_id;
  ok('ruta histórica por UUID: una referencia de 50 caracteres no es UUID y no se sirve por esa ruta (404); por eso React usa el id numérico', (await api('GET', `/api/reportes/reporteid/${longRef}/visor`, { token: adminToken })).status === 404);
  const uuidRow = (await q("SELECT id, reporte_id FROM reporte WHERE reporte_id REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' ORDER BY id LIMIT 1"))[0];
  await sql(`UPDATE reporte SET activo = 1 WHERE id = ${uuidRow[0]}`);
  const hist = await api('GET', `/api/reportes/reporteid/${uuidRow[1]}/visor`, { token: adminToken }); const hist2 = await api('GET', `/api/reportes/reporteid/${uuidRow[1]}?view=minimal`, { token: adminToken });
  await sql(`UPDATE reporte SET activo = 0 WHERE id = ${uuidRow[0]}`);
  ok('rutas históricas por UUID intactas: /reporteid/{uuid}/visor y /reporteid/{uuid} responden como antes (sin campo id nuevo en el visor)', hist.status === 200 && hist.json.data.reporte_id === uuidRow[1] && !('id' in hist.json.data) && hist2.status === 200);
  const g3 = await api('GET', `/api/reportes/grupoid/${await one('SELECT grupo_id FROM reporte WHERE id = 3')}`, { token: adminToken });
  ok('ruta histórica /grupoid/{uuid}: sigue devolviendo el informe del grupo', g3.status === 200 && g3.json.data.some(r => r.id === 3));

  setSection('QA · informe en varias empresas (API)');
  const relations = await walk('/api/asignacion?view=minimal', adminToken);
  const rel1 = relations.filter(r => r.reporte_id === 1);
  ok('el informe 1 tiene 2 asociaciones vigentes (empresas 1 y 2), el catálogo lo lista UNA vez y las relaciones suman 84', rel1.length === 2 && new Set(rel1.map(r => r.empresa_id)).size === 2 && reports.filter(r => r.id === 1).length === 1 && relations.length === 84);

  // ======================================================================================================= Navegador
  const b = await launch({ port: browserPort });
  const text = sel => b.eval(`document.querySelector(${JSON.stringify(sel)})?.innerText ?? ''`);
  const count = sel => b.eval(`document.querySelectorAll(${JSON.stringify(sel)}).length`);
  const path = () => b.eval('location.pathname + location.search');
  const body = () => b.eval('document.body.innerText');
  const rows = () => b.eval(`[...document.querySelectorAll('tbody tr')].map(r => r.innerText.replace(/\\s+/g, ' ').trim())`);
  const go = async to => { await b.eval(`history.pushState({}, '', ${JSON.stringify(to)}); dispatchEvent(new PopStateEvent('popstate'))`); await b.wait(900); };
  const navTo = async label => { await b.eval(`[...document.querySelectorAll('.nav-button')].find(a => a.textContent.includes(${JSON.stringify(label)})).click()`); await b.wait(1200); };
  const clickByText = async (selector, label) => { const done = await b.eval(`(() => { const e = [...document.querySelectorAll(${JSON.stringify(selector)})].find(x => x.textContent.includes(${JSON.stringify(label)})); if (!e) return false; e.click(); return true; })()`); await b.wait(600); return done; };
  const clickAria = async label => { await b.eval(`[...document.querySelectorAll('a,button')].find(x => x.getAttribute('aria-label') === ${JSON.stringify(label)}).click()`); await b.wait(900); };
  const loginUi = async (user, password) => { await b.type('#login-name', user); await b.type('#login-password', password); await b.click('form button[type=submit]'); await b.wait(1500); };
  const logout = async () => { await b.eval(`document.querySelector('.sidebar-footer .text-button').click()`); await b.wait(700); };
  const cards = () => b.eval(`[...document.querySelectorAll('.report-card h2')].map(h => h.textContent)`);
  try {
    await b.send('Network.setBlockedURLs', { urls: ['https://app.powerbi.com/*', 'https://*.powerbi.com/*', 'https://*.microsoft.com/*'] });
    await b.goto(`${base}/`); await b.wait(900);

    setSection('QA · administrador (navegador)');
    ok('la compilación de producción abre en /login sin precargar cuentas ni avisos de QA', (await path()) === '/login' && (await b.eval(`document.querySelector('#login-name').value`)) === '' && !(await body()).includes('Entorno QA'));
    await loginUi(admin, accounts[admin]);
    ok('el administrador entra a /admin', (await path()) === '/admin' && (await text('.role-badge')) === 'Modo administrador');
    await navTo('Reportes');
    const tableRows = await rows();
    ok('el listado de reportes muestra los 82 informes activos y ningún inactivo', tableRows.length === 82 && !tableRows.some(r => r.includes('Informe sintético 083') || r.includes('Informe sintético 093')), `${tableRows.length} filas`);
    const row1 = tableRows.filter(r => r.includes('Informe sintético 001'));
    ok('el informe vinculado a dos empresas aparece UNA vez y muestra ambas empresas', row1.length === 1 && row1[0].includes('Empresa sintética 01 · Empresa sintética 02'), row1.join(' | '));
    const access1 = Number(await one(`SELECT COUNT(*) ${EFFECTIVE} AND r.id = 1`));
    ok(`«Usuarios con acceso» del informe 1 coincide con los permisos efectivos del servidor (${access1})`, row1[0]?.match(/(\d+)\s+Activo/)?.[1] === String(access1), row1[0]);
    await b.type('#ar-q', 'Informe sintético 007');
    ok('la búsqueda filtra sobre datos reales de PHP', (await count('tbody tr')) === 1);
    await b.click('.resultbar button');

    setSection('QA · navegación por ID numérico y visor (navegador)');
    await clickAria('Vista previa de Informe sintético 037');
    ok('«Ver» navega por el id numérico del informe (/reportes/37) y muestra su nombre', (await path()) === '/reportes/37' && (await text('#page-title')) === 'Informe sintético 037');
    const frame = await b.eval(`document.querySelector('.powerbi-frame iframe')?.getAttribute('src') ?? ''`);
    ok('el visor construye el iframe con el enlace público (valor ficticio; NO prueba que Power BI funcione)', /^https:\/\/app\.powerbi\.com\/view\?r=[A-Za-z0-9_-]{16,}/.test(frame));
    await go('/reportes/abc'); ok('/reportes/abc (id malformado) → «Acceso denegado» sin consultar informes', (await text('#page-title')) === 'Acceso denegado' && (await count('iframe')) === 0);
    await go('/reportes/007'); ok('/reportes/007 (ceros a la izquierda) → «Acceso denegado»', (await text('#page-title')) === 'Acceso denegado');
    await go('/reportes/4000000000'); ok('/reportes/4000000000 (inexistente) → el mismo «Acceso denegado»', (await text('#page-title')) === 'Acceso denegado');
    await go('/reportes/1'); ok('/reportes/1 (informe de dos empresas): ambas empresas visibles en el visor', (await text('.viewer-meta')).includes('Empresa sintética 01 · Empresa sintética 02'));

    setSection('QA · edición de empresa e informes históricos (navegador)');
    await navTo('Empresas');
    const before = await q('SELECT nombre, razon_social, ruc, pais FROM empresa WHERE id = 1');
    await clickAria('Editar Empresa sintética 01');
    await b.type('#co-city', 'Ciudad editada QA');
    await b.click('.modal form button[type=submit]'); await b.wait(1500);
    const after = await q('SELECT nombre, razon_social, ruc, pais FROM empresa WHERE id = 1');
    ok('editar una empresa guarda la ciudad y conserva su nombre, razón social, RUC y país', (await one('SELECT ciudad FROM empresa WHERE id = 1')) === 'Ciudad editada QA' && JSON.stringify(before) === JSON.stringify(after));
    await navTo('Reportes');
    const hBefore = (await q('SELECT reporte_id, grupo_id, enlace, nombre FROM reporte WHERE id = 1'))[0];
    await clickAria('Editar Informe sintético 001'); await b.wait(900);
    ok('el formulario de edición carga el enlace del informe (solo administrador)', (await b.eval(`document.querySelector('#rp-link').value`)) === hBefore[2]);
    await b.type('#rp-description', 'Descripción editada en QA');
    await b.click('.modal form button[type=submit]'); await b.wait(1500);
    const hAfter = (await q('SELECT reporte_id, grupo_id, enlace, nombre, descripcion, updated_by FROM reporte WHERE id = 1'))[0];
    ok('editar un informe histórico NO modifica reporte_id, grupo_id, enlace ni nombre; solo la descripción y el editor', hAfter[0] === hBefore[0] && hAfter[1] === hBefore[1] && hAfter[2] === hBefore[2] && hAfter[3] === hBefore[3] && hAfter[4] === 'Descripción editada en QA' && hAfter[5] === '1', JSON.stringify({ hBefore, hAfter }));
    const hRels = await one('SELECT COUNT(*) FROM empresa_reporte er JOIN empresa e ON e.id = er.empresa_id AND e.activo = 1 WHERE er.reporte_id = 1 AND er.activo = 1');
    ok('las 2 asociaciones vigentes empresa–informe del informe 1 siguen intactas tras editarlo', hRels === '2', hRels);

    setSection('QA · informe «Publicar en web» nuevo con grupo_id NULL (navegador)');
    const token = `U0lOVEVUSUNPLVFBLUNPTVBBVC0${randomBytes(6).toString('hex')}`;
    const link = `https://app.powerbi.com/view?r=${token}`;
    const reportName = 'Informe QA publicar en web';
    await clickByText('button', 'Nuevo reporte');
    await b.type('#rp-name', reportName); await b.type('#rp-description', 'Creado en el QA de compatibilidad'); await b.type('#rp-link', link);
    ok('el formulario detecta «Publicación web» sin pedir ni inventar identificadores', (await text('[data-testid=link-detected]')).includes('Publicación web') && (await count('#rp-group, #rp-uuid')) === 0);
    await b.click('.modal form button[type=submit]'); await b.wait(1800);
    const created = (await q(`SELECT id, reporte_id, grupo_id IS NULL, enlace, activo FROM reporte WHERE nombre = '${reportName}'`))[0];
    ok('se creó con grupo_id NULL y una referencia interna UUID v4 de 36 caracteres (cabe en VARCHAR(50)); el enlace quedó tal cual', Boolean(created) && created[2] === '1' && UUID_V4.test(created[1]) && created[3] === link && created[4] === '1', JSON.stringify(created));
    ok('los 82 informes históricos conservan su grupo_id (ninguno pasó a NULL)', (await one('SELECT COUNT(*) FROM reporte WHERE grupo_id IS NULL')) === '1' && (await one("SELECT COUNT(*) FROM reporte WHERE activo = 1 AND grupo_id IS NOT NULL AND grupo_id <> ''")) === '82');
    if (!created) { ok('el QA continúa solo si el informe nuevo existe', false, `diálogo: ${(await text('.modal')).slice(0, 300)} | pantalla: ${(await body()).slice(0, 200)}`); return; }
    const newId = Number(created[0]);
    const viaUuid = await api('GET', `/api/reportes/reporteid/${created[1]}?view=minimal`, { token: adminToken });
    ok('el informe nuevo también responde por la ruta histórica /reporteid/{uuid}', viaUuid.status === 200 && viaUuid.json.data.id === newId);

    setSection('QA · asociación empresa–informe (navegador)');
    await navTo('Informes por empresa');
    for (const companyId of ['6', '5']) {
      await b.select('#cr-company', companyId); await b.wait(900);
      await b.eval(`[...document.querySelectorAll('[aria-label="Informes disponibles"] label')].find(l => l.textContent.includes(${JSON.stringify(reportName)})).querySelector('input').click()`); await b.wait(300);
      await clickByText('button', 'Asociar seleccionados'); await b.wait(1500);
    }
    ok('el informe nuevo quedó asociado a las empresas 5 y 6 (relaciones activas con ids numéricos)', (await one(`SELECT COUNT(*) FROM empresa_reporte WHERE reporte_id = ${newId} AND activo = 1 AND empresa_id IN (5, 6)`)) === '2');
    await navTo('Reportes'); await b.type('#ar-q', reportName);
    const newRows = await rows();
    ok('el informe nuevo aparece UNA vez con sus dos empresas (sin duplicados ni asociaciones perdidas)', newRows.length === 1 && newRows[0].includes('Empresa sintética 05') && newRows[0].includes('Empresa sintética 06'), newRows.join(' | '));
    await b.click('.resultbar button');

    setSection('QA · alta, edición y desactivación de usuarios');
    await navTo('Usuarios'); await clickByText('button', 'Nuevo usuario');
    const qaPass = `Qa-${randomBytes(6).toString('hex')}`;
    await b.type('#nu-first', 'Operador'); await b.type('#nu-last', 'Compatibilidad'); await b.type('#nu-username', 'qa.compat'); await b.type('#nu-email', 'qa.compat@example.invalid'); await b.type('#nu-password', qaPass);
    await b.click('.modal form button[type=submit]'); await b.wait(1800);
    const newUser = (await q("SELECT id, role, is_active, session_version FROM user WHERE username = 'qa.compat'"))[0];
    ok('alta por la interfaz: el servidor fija rol «user», activo y session_version = 1', Boolean(newUser) && newUser[1] === 'user' && newUser[2] === '1' && newUser[3] === '1');
    const uid = Number(newUser[0]);
    const edit = await api('PUT', `/api/user/profile/${uid}`, { token: adminToken, body: { first_name: 'Operador editado', phone: '999111222' } });
    ok('edición del usuario (API de administrador): se guardan nombre y teléfono sin tocar rol ni estado', edit.json?.success === true && (await one(`SELECT CONCAT(first_name, '|', phone, '|', role, '|', is_active) FROM user WHERE id = ${uid}`)) === 'Operador editado|999111222|user|1');
    const qa1 = await loginApi('qa.compat', qaPass);
    ok('el usuario nuevo inicia sesión', qa1.success === true);
    await sql(`UPDATE user SET is_active = 0 WHERE id = ${uid}`);
    const afterOff = await api('GET', '/api/user/profile', { token: qa1.token });
    ok('desactivación (no existe endpoint ni pantalla: se simula en la base): el token vigente deja de servir (401) y no puede volver a entrar', afterOff.status === 401 && (await loginApi('qa.compat', qaPass))?.success === false);
    await sql(`UPDATE user SET is_active = 1 WHERE id = ${uid}`);
    ok('reactivado, vuelve a entrar', (await loginApi('qa.compat', qaPass))?.success === true);

    setSection('QA · asignación individual, retiro y acceso directo por URL (navegador)');
    await go(`/admin/accesos/nueva?user=${uid}`); await b.wait(1200);
    await clickByText('button', 'Continuar');
    await b.eval(`[...document.querySelectorAll('input[name=wz-company]')].find(i => i.closest('label').textContent.includes('Empresa sintética 06')).click()`); await b.wait(400);
    await clickByText('button', 'Continuar');
    await b.eval(`[...document.querySelectorAll('.selection-list input[type=checkbox]')].find(i => i.closest('label').textContent.includes(${JSON.stringify(reportName)})).click()`); await b.wait(400);
    await clickByText('button', 'Continuar'); await clickByText('button', 'Guardar accesos'); await b.wait(1800);
    ok('el asistente concede el acceso (confirmado por PHP)', (await text('.success-box')).includes('Accesos guardados'));
    const uerId = Number(await one(`SELECT uer.id FROM user_empresa_reporte uer JOIN empresa_reporte er ON er.id = uer.empresa_reporte_id WHERE uer.user_id = ${uid} AND er.reporte_id = ${newId} AND uer.activo = 1`));
    ok('la asignación individual existe en MySQL y el usuario ve en el servidor solo ese informe', uerId > 0 && JSON.stringify((await walk('/api/reportes?view=minimal', (await loginApi('qa.compat', qaPass)).token)).map(r => r.id)) === JSON.stringify([newId]));
    await logout(); await loginUi('qa.compat', qaPass);
    ok('el usuario nuevo, en el navegador, ve una sola tarjeta con su informe', JSON.stringify(await cards()) === JSON.stringify([reportName]));
    await clickAria(`Abrir informe ${reportName}`); await b.wait(1200);
    ok('abre su informe por el id numérico', (await path()) === `/reportes/${newId}` && (await count('.powerbi-frame iframe')) === 1);
    await go(`/reportes/${foreign}`); ok('un informe ajeno por URL directa → «Acceso denegado»', (await text('#page-title')) === 'Acceso denegado' && (await count('iframe')) === 0);
    await logout(); await loginUi(admin, accounts[admin]);
    await go('/admin/accesos?q=qa.compat'); await b.wait(900);
    await clickByText('button', 'Retirar'); await b.click('.modal form button[type=submit]'); await b.wait(1500);
    ok('el administrador retira el acceso individual (baja lógica confirmada por PHP)', (await one(`SELECT activo FROM user_empresa_reporte WHERE id = ${uerId}`)) === '0');
    const qa2 = (await loginApi('qa.compat', qaPass)).token;
    const revokedApi = await api('GET', `/api/reportes/id/${newId}/visor`, { token: qa2 });
    ok('tras la revocación, el acceso directo por id numérico responde 404 sin enlace', revokedApi.status === 404 && !revokedApi.raw.includes(token));
    await logout(); await loginUi('qa.compat', qaPass);
    ok('tras la revocación, el catálogo del usuario queda vacío', (await cards()).length === 0);
    await go(`/reportes/${newId}`); ok('tras la revocación, la URL directa del informe → «Acceso denegado» y ningún iframe', (await text('#page-title')) === 'Acceso denegado' && (await count('iframe')) === 0);
    ok('el servidor también rechaza el detalle numérico /api/reportes/{id} tras la revocación', (await api('GET', `/api/reportes/${newId}?view=minimal`, { token: qa2 })).status === 404);
    await logout();

    setSection('QA · usuario inactivo con asignación activa');
    await loginUi(admin, accounts[admin]);
    await go('/admin/accesos?q=syn066'); await b.wait(900);
    ok('las asignaciones activas de usuarios inactivos se ven marcadas como «Usuario inactivo» en la administración', (await body()).includes('Usuario inactivo'));
    const tok5 = (await loginApi('syn005', accounts.syn005)).token;
    await sql('UPDATE user SET is_active = 0 WHERE id = 5');
    const off5 = await api('GET', `/api/reportes/id/${own}/visor`, { token: tok5 });
    await sql('UPDATE user SET is_active = 1 WHERE id = 5');
    ok('usuario desactivado con su asignación todavía activa: el visor responde 401 al instante', off5.status === 401);
    ok('sin errores de consola en toda la sesión del navegador', b.consoleErrors.length === 0, b.consoleErrors.slice(0, 3).join(' | '));
    ok('el navegador no contactó dominios externos (las peticiones a powerbi.com se bloquearon a propósito)', b.requests.every(u => u.startsWith(base) || /^(data:|about:)/.test(u) || /powerbi\.com/.test(u)));
    ok('la aplicación nunca llamó al endpoint heredado de tokens ni a rutas /reporteid/', !b.requestLog.some(r => r.url.includes('/api/powerbi') || r.url.includes('/reporteid/')));
  } finally { await b.close(); }
}
