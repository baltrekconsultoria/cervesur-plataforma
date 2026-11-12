<?php
// UX-08H · MODO «admin_escritura» (fase 2) contra el backend REAL, con la cuenta MySQL de PRIVILEGIOS MÍNIMOS (plantilla database/privileges/UX-08H-…) y datos 100 % sintéticos.
// Instancias (todas sobre la base `fixture_aw` y la base de estado `estado_aw`):
//   appaw   : las cuatro capacidades de escritura encendidas      appawe : SOLO empresas      appaw0 : ninguna (solo lectura efectiva)
//   appawus : usuarios encendido SIN operaciones sensibles y con la espera de reactivación por defecto (fase 2B)
//   appawna : empresas/reportes/accesos encendidos, usuarios apagados (interruptor independiente)
//   appawbad: sin AUDIT_LOG_MODE=required (debe negarse a arrancar)   appawdb : clave de la cuenta de escritura equivocada (falla de conexión MySQL)
// Entorno: TEST_DB_PASS, W_WRITE_PASS.
$failures = 0;
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$root = new PDO('mysql:host=db;dbname=fixture_aw;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), $opts);
$st = new PDO('mysql:host=db;dbname=estado_aw;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), $opts);
$bodies = '';
function api($host, $method, $path, $token = null, $body = null) {
    $headers = ['Content-Type: application/json', 'X-API-Contract: 2'];
    if ($token) $headers[] = 'Authorization: Bearer ' . $token;
    $o = ['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 20]];
    if ($body !== null) $o['http']['content'] = json_encode($body);
    $raw = (string)@file_get_contents("http://$host:8080$path", false, stream_context_create($o));
    $GLOBALS['last'] = substr($raw, 0, 300);
    if ($path !== '' && !preg_match('~/visor$~', $path)) $GLOBALS['bodies'] .= $raw; // todo lo que NO sea el visor se revisa buscando enlaces/secretos
    $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int)$m[1] : 0;
    return [$status, json_decode($raw, true), $raw];
}
function expectCase($c, $label) { global $failures; if ($c) echo "PASS $label\n"; else { echo "FAIL $label\n  última respuesta: " . ($GLOBALS['last'] ?? '') . "\n"; $failures++; } }
function login($username, $host = 'appaw', $password = 'synthetic-password') { [, $d] = api($host, 'POST', '/api/login', null, ['login' => $username, 'password' => $password]); return $d['token'] ?? ''; }
function one($pdo, $sql, $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }
function rows($pdo, $sql, $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); }
function pbiLink($n) { return 'https://app.powerbi.com/reportEmbed?reportId=dddddddd-0000-4000-8000-0000000000' . sprintf('%02d', $n) . '&groupId=bbbbbbbb-0000-4000-8000-000000000001'; }
function sums($root) { $o = []; foreach ($root->query('CHECKSUM TABLE user, empresa, reporte, empresa_usuario, empresa_reporte, user_empresa_reporte') as $r) $o[$r['Table']] = $r['Checksum']; return $o; }
const SENSITIVE = '/\$2y\$|"password"|powerbi_(tenant|client|clientsecret)|SQLSTATE|PDOException|cervesur_(escritura|ro_|react_)|Stack trace|\/var\/www|\/work\//i';

echo "== Arranque y configuración (fail-closed)\n";
[$s, $d, $raw] = api('appawbad', 'GET', '/api/ping');
expectCase($s === 503 && ($d['code'] ?? '') === 'mode_config' && !preg_match(SENSITIVE, $raw), 'sin AUDIT_LOG_MODE=required el modo no arranca (503 genérico, sin detalles)');
[$s, $d, $raw] = api('appawdb', 'GET', '/api/ping');
expectCase($s >= 500 && !preg_match(SENSITIVE, $raw) && !str_contains($raw, 'Access denied'), 'cuenta de escritura con clave equivocada: error genérico 5xx, sin SQLSTATE ni nombres de cuentas');
[$s] = api('appaw', 'GET', '/api/ping');
expectCase($s === 200, 'la instancia correcta responde al ping');

echo "== Autenticación: solo el administrador de la lista blanca\n";
expectCase(login('usera') === '' && login('userb') === '', 'trabajadores (rol user/usuario) no inician sesión');
foreach (['/api/empresas', '/api/users', '/api/admin/capacidades', '/api/auditoria'] as $p) { [$s] = api('appaw', 'GET', $p); if ($s !== 401) { $bad[] = $p; } }
expectCase(empty($bad), 'sin token toda ruta devuelve 401');
$root->exec("INSERT INTO user (id, username, email, password, first_name, last_name, role, is_active) SELECT 90, 'admin90', 'admin90@example.invalid', password, 'A', 'N', 'admin', 1 FROM user WHERE id = 1");
expectCase(login('admin90') === '', 'administrador ACTIVO que no figura en la lista blanca de la instancia: rechazado');
$root->exec('DELETE FROM user WHERE id = 90');
$t = login('admin');
expectCase(preg_match('/^[0-9a-f]{64}$/', $t) === 1, 'administrador de la lista: sesión opaca de 256 bits');
[$s, $d] = api('appaw', 'GET', '/api/admin/capacidades', $t);
expectCase($s === 200 && $d['data']['writes'] === ['empresas' => true, 'reportes' => true, 'accesos' => true, 'usuarios' => true] && $d['data']['audit'] === true && $d['data']['mode'] === 'admin_escritura' && $d['data']['users_sensitive'] === true && $d['data']['reactivate_after_hours'] === 0, 'capacidades reales del servidor: las cuatro encendidas, operaciones sensibles y espera visibles');
[, $d] = api('appawe', 'GET', '/api/admin/capacidades', login('admin', 'appawe'));
expectCase($d['data']['writes'] === ['empresas' => true, 'reportes' => false, 'accesos' => false, 'usuarios' => false], 'instancia parcial: solo empresas');
[, $d] = api('appaw0', 'GET', '/api/admin/capacidades', login('admin', 'appaw0'));
expectCase(!in_array(true, $d['data']['writes'], true), 'instancia sin escrituras: todas apagadas');

echo "== Cuenta de escritura: privilegios reales\n";
$w = new PDO('mysql:host=db;dbname=fixture_aw;charset=utf8mb4', 'cervesur_escritura_aw', getenv('W_WRITE_PASS'), $opts);
$denied = function ($sql) use ($w) { try { $w->query($sql); return false; } catch (PDOException $e) { return true; } };
expectCase($denied('DELETE FROM empresa WHERE id = 999') && $denied('DELETE FROM reporte WHERE id = 999') && $denied('DELETE FROM user WHERE id = 999'), 'la cuenta no puede DELETE en tablas de negocio');
expectCase($denied('SELECT password FROM user') && $denied('SELECT powerbi_clientsecret FROM empresa') && $denied('SELECT powerbi_tenant FROM empresa'), 'la cuenta no puede leer user.password ni empresa.powerbi_*');
expectCase($denied("UPDATE audit_log SET action = 'x'") && $denied('DELETE FROM audit_log') && $denied('TRUNCATE audit_log') && $denied('DROP TABLE audit_log') && $denied('CREATE TABLE zz (a int)'), 'audit_log inmutable y sin DDL para la cuenta');
expectCase($denied('UPDATE user SET username = "zz" WHERE id = 3') && $denied('UPDATE empresa SET powerbi_tenant = "x" WHERE id = 1'), 'columnas fuera de la lista (username, powerbi_*) no se pueden modificar');
$grants = array_map(fn ($r) => implode(' ', $r), $w->query("SHOW GRANTS FOR CURRENT_USER()")->fetchAll(PDO::FETCH_NUM));
expectCase(!preg_grep('/\b(DELETE|ALL PRIVILEGES|GRANT OPTION|TRIGGER|DROP|ALTER|CREATE)\b/', $grants), 'SHOW GRANTS: sin DELETE, ALL, GRANT OPTION, TRIGGER ni DDL');

echo "== Puerta de rutas y capacidades por servidor\n";
$te = login('admin', 'appawe'); $t0 = login('admin', 'appaw0'); $tna = login('admin', 'appawna');
foreach ([['POST', '/api/reportes', ['nombre' => 'x']], ['POST', '/api/asignacion', ['empresa_id' => 1, 'reporte_id' => 1]], ['POST', '/api/register', []], ['PUT', '/api/users/2', []]] as [$m, $p, $b]) {
    [$s, $d] = api('appawe', $m, $p, $te, $b); if (!($s === 403 && ($d['code'] ?? '') === 'escritura_desactivada')) $bad2[] = "$m $p => $s";
}
expectCase(empty($bad2), 'con solo «empresas» encendida, reportes/accesos/usuarios responden 403 escritura_desactivada' . (empty($bad2) ? '' : ' · ' . implode(', ', $bad2)));
$before = sums($root);
foreach ([['POST', '/api/empresas'], ['PUT', '/api/empresas/1'], ['DELETE', '/api/empresas/1'], ['POST', '/api/reportes'], ['POST', '/api/empresa-usuario'], ['POST', '/api/register']] as [$m, $p]) {
    [$s, $d] = api('appaw0', $m, $p, $t0, []); if ($s !== 403 || ($d['code'] ?? '') !== 'escritura_desactivada') $bad3[] = "$m $p => $s";
}
expectCase(empty($bad3) && sums($root) === $before, 'instancia sin escrituras: toda escritura 403 y los datos no cambian (checksums)' . (empty($bad3) ? '' : ' · ' . implode(', ', $bad3)));
foreach ([['GET', '/api/empresas'], ['GET', '/api/reportes'], ['GET', '/api/users'], ['GET', '/api/auditoria']] as [$m, $p]) { [$s] = api('appaw0', $m, $p, $t0); if ($s !== 200) $bad4[] = "$p => $s"; }
expectCase(empty($bad4), 'las lecturas siguen disponibles sin escrituras');
[$s, $d] = api('appawna', 'POST', '/api/register', $tna, ['first_name' => 'N', 'last_name' => 'A', 'username' => 'noadmin', 'email' => 'noadmin@example.invalid', 'password' => 'long-enough-password']);
expectCase($s === 403 && ($d['code'] ?? '') === 'escritura_desactivada' && (int)one($root, "SELECT COUNT(*) FROM user WHERE username = 'noadmin'") === 0, 'interruptor de usuarios independiente: apagado impide /api/register aunque empresas/reportes/accesos estén encendidos');
[$s] = api('appawna', 'POST', '/api/empresas', $tna, ['nombre' => 'Indep SA', 'razon_social' => 'Indep SA', 'ruc' => '20555000001', 'ciudad' => 'X', 'pais' => 'Y']);
expectCase($s === 200, '…y las empresas siguen funcionando en esa instancia');
foreach ([['DELETE', '/api/users/2'], ['GET', '/api/diagnostico'], ['POST', '/api/migrate'], ['GET', '/api/empresas/abc'], ['GET', '/api/empresas/0'], ['PATCH', '/api/empresas/1'], ['PUT', '/api/user/profile']] as [$m, $p]) {
    [$s, $d] = api('appaw', $m, $p, $t, []); if ($s !== 403) $bad5[] = "$m $p => $s";
}
expectCase(empty($bad5), 'rutas fuera de la tabla cerrada (borrado físico, diagnóstico, métodos no previstos): 403' . (empty($bad5) ? '' : ' · ' . implode(', ', $bad5)));

echo "== Empresas: alta, duplicado, versión, baja y reactivación\n";
$nEmp = fn ($ruc, $nom = 'Empresa Nueva') => ['nombre' => $nom, 'razon_social' => $nom, 'ruc' => $ruc, 'ciudad' => 'Lima', 'pais' => 'Perú'];
[$s, $d] = api('appaw', 'POST', '/api/empresas', $t, $nEmp('20111111111') + ['powerbi_tenant' => 'tenant-secreto', 'powerbi_clientsecret' => 'SECRETO-XYZ']);
$eid = (int)($d['id'] ?? 0);
expectCase($s === 200 && $eid > 0 && !empty($d['correlation_id']), 'alta de empresa: 200, id y correlation_id');
$row = rows($root, 'SELECT ruc, activo, created_by FROM empresa WHERE id = ?', [$eid])[0] ?? [];
expectCase(($row['ruc'] ?? '') === '20111111111' && (int)$row['created_by'] === 1, 'la fila existe con created_by = administrador de la sesión');
$aud = rows($root, "SELECT action, entity, entity_id, actor_user_id, correlation_id FROM audit_log WHERE entity = 'empresa' AND entity_id = ? ORDER BY id", [$eid]);
expectCase(count($aud) === 1 && $aud[0]['action'] === 'empresa.create' && (int)$aud[0]['actor_user_id'] === 1 && $aud[0]['correlation_id'] === $d['correlation_id'], 'una fila de auditoría con el mismo correlation_id, en la misma operación');
expectCase(!str_contains((string)one($root, 'SELECT CONCAT_WS("|", before_json, after_json) FROM audit_log WHERE entity = "empresa" AND entity_id = ?', [$eid]), 'SECRETO-XYZ'), 'la auditoría no guarda valores de credenciales');
expectCase(one($root, 'SELECT powerbi_tenant FROM empresa WHERE id = ?', [$eid]) === null && one($root, 'SELECT powerbi_clientsecret FROM empresa WHERE id = ?', [$eid]) === null, 'los campos powerbi_* enviados se ignoran: quedan NULL en la fila');
[$s, $d] = api('appaw', 'POST', '/api/empresas', $t, $nEmp('20111111111', 'Otra'));
expectCase($s === 409 && (int)one($root, "SELECT COUNT(*) FROM empresa WHERE ruc = '20111111111'") === 1, 'RUC duplicado: 409 y sigue habiendo una sola fila');
[, $form] = api('appaw', 'GET', "/api/empresas/$eid?view=form", $t);
$v1 = $form['data']['version'] ?? '';
expectCase($v1 !== '' && !str_contains(json_encode($form), 'powerbi'), 'el formulario trae versión y ningún campo powerbi_*');
[$s] = api('appaw', 'PUT', "/api/empresas/$eid", $t, ['ciudad' => 'Cusco', 'version' => $v1]);
[$s2, $d2] = api('appaw', 'PUT', "/api/empresas/$eid", $t, ['ciudad' => 'Tacna', 'version' => $v1]);
expectCase($s === 200 && $s2 === 409 && one($root, 'SELECT ciudad FROM empresa WHERE id = ?', [$eid]) === 'Cusco', 'edición con versión: la primera aplica; la segunda con versión vencida da 409 y no pisa');
[$s] = api('appaw', 'DELETE', "/api/empresas/$eid", $t);
expectCase($s === 200 && (int)one($root, 'SELECT activo FROM empresa WHERE id = ?', [$eid]) === 0 && (int)one($root, 'SELECT COUNT(*) FROM empresa WHERE id = ?', [$eid]) === 1, 'baja lógica: activo = 0 y la fila sigue existiendo');
[$s] = api('appaw', 'POST', "/api/empresas/$eid/reactivate", $t, []);
expectCase($s === 200 && (int)one($root, 'SELECT activo FROM empresa WHERE id = ?', [$eid]) === 1, 'reactivación');
expectCase((int)one($root, "SELECT COUNT(*) FROM audit_log WHERE entity = 'empresa' AND entity_id = ?", [$eid]) === 4, 'alta + edición + baja + reactivación = 4 filas de auditoría');
[$s] = api('appaw', 'POST', '/api/empresas', $t, ['nombre' => '', 'ruc' => 'no-valido']);
expectCase($s === 400 || $s === 422, 'datos inválidos: rechazados con 400/422');

echo "== Informes: el enlace es de solo escritura\n";
$rep = ['nombre' => 'Informe Nuevo', 'descripcion' => 'desc', 'enlace' => pbiLink(21), 'grupo_id' => 'bbbbbbbb-0000-4000-8000-000000000001', 'reporte_id' => 'dddddddd-0000-4000-8000-000000000021'];
[$s, $d, $raw] = api('appaw', 'POST', '/api/reportes', $t, $rep);
$rid = (int)($d['data']['id'] ?? 0);
expectCase($s === 200 && $rid > 0 && !str_contains($raw, 'powerbi.com'), 'alta de informe: 200 y la respuesta no repite el enlace');
expectCase(one($root, 'SELECT enlace FROM reporte WHERE id = ?', [$rid]) === pbiLink(21) && one($root, 'SELECT grupo_id FROM reporte WHERE id = ?', [$rid]) === 'bbbbbbbb-0000-4000-8000-000000000001', 'el enlace y el grupo se guardaron tal cual');
$ja = (string)one($root, 'SELECT CONCAT_WS("|", before_json, after_json) FROM audit_log WHERE entity = "reporte" AND entity_id = ?', [$rid]);
expectCase($ja !== '' && !str_contains($ja, 'powerbi.com') && !str_contains($ja, 'reportEmbed'), 'la auditoría no contiene el enlace (solo una huella)');
[$s, $d, $raw] = api('appaw', 'GET', "/api/reportes/$rid?view=form", $t);
expectCase($s === 200 && !str_contains($raw, 'powerbi.com') && ($d['data']['enlace_configurado'] ?? null) === true && !array_key_exists('enlace', $d['data'] ?? []), 'formulario: sin enlace, con «enlace_configurado»');
$v = $d['data']['version'] ?? '';
[$s] = api('appaw', 'POST', '/api/reportes', $t, array_merge($rep, ['nombre' => 'Duplicado']));
expectCase($s === 409 && (int)one($root, "SELECT COUNT(*) FROM reporte WHERE reporte_id = 'dddddddd-0000-4000-8000-000000000021'") === 1, 'mismo informe (reporte_id y enlace) otra vez: 409 y una sola fila');
[$s] = api('appaw', 'POST', '/api/reportes', $t, array_merge($rep, ['reporte_id' => 'dddddddd-0000-4000-8000-000000000022']));
expectCase(in_array($s, [400, 422], true), 'reporte_id que no coincide con el del enlace: rechazado');
[$s] = api('appaw', 'POST', '/api/reportes', $t, array_merge($rep, ['enlace' => 'https://evil.example/x', 'reporte_id' => 'dddddddd-0000-4000-8000-000000000024']));
expectCase($s === 400 || $s === 422, 'enlace que no es de Power BI: rechazado');
[$s] = api('appaw', 'PUT', "/api/reportes/$rid", $t, ['nombre' => 'Informe Editado', 'descripcion' => 'd2', 'enlace' => pbiLink(21), 'version' => $v]);
expectCase($s === 200 && one($root, 'SELECT nombre FROM reporte WHERE id = ?', [$rid]) === 'Informe Editado', 'edición con versión vigente');
[, $fv] = api('appaw', 'GET', "/api/reportes/$rid?view=form", $t);
[$s] = api('appaw', 'PUT', "/api/reportes/$rid", $t, ['nombre' => 'Solo nombre', 'descripcion' => 'sin enlace', 'version' => $fv['data']['version'] ?? '']);
expectCase($s === 200 && one($root, 'SELECT nombre FROM reporte WHERE id = ?', [$rid]) === 'Solo nombre' && one($root, 'SELECT enlace FROM reporte WHERE id = ?', [$rid]) === pbiLink(21), 'editar sin reenviar el enlace conserva el vigente');
[$s] = api('appaw', 'PUT', "/api/reportes/$rid", $t, ['nombre' => 'Enlace nuevo', 'descripcion' => 'x', 'enlace' => pbiLink(26), 'reporte_id' => 'dddddddd-0000-4000-8000-000000000026']);
expectCase(in_array($s, [200, 409], true), 'reemplazar el enlace sigue siendo posible (el servidor lo revalida)');
[$s] = api('appaw', 'DELETE', "/api/reportes/$rid", $t);
expectCase($s === 200 && (int)one($root, 'SELECT activo FROM reporte WHERE id = ?', [$rid]) === 0, 'baja lógica del informe');
[$s] = api('appaw', 'GET', "/api/reportes/id/$rid/visor", $t);
expectCase($s === 404, 'un informe dado de baja no se abre en el visor');
[$s] = api('appaw', 'POST', "/api/reportes/$rid/reactivate", $t, []);
[$s2, $d2, $raw2] = api('appaw', 'GET', "/api/reportes/id/$rid/visor", $t);
expectCase($s === 200 && $s2 === 200 && in_array($d2['data']['modo'] ?? '', ['publico', 'privado', 'no_compatible'], true), 'reactivado, el visor responde (único camino que entrega el enlace)');
expectCase((int)one($root, "SELECT COUNT(*) FROM audit_log WHERE entity = 'reporte' AND entity_id = ?", [$rid]) === 6, 'alta + tres ediciones + baja + reactivación auditadas (6 filas)');
$n1 = (int)one($root, 'SELECT COUNT(*) FROM reporte');
// UX-08B4: el esquema real es NOT NULL, pero el backend genera su identificador INTERNO
// para un enlace publico sin groupId. Es un UUID de Cervesur, NO un grupo de Microsoft.
[$s, $web] = api('appaw', 'POST', '/api/reportes', $t, ['nombre' => 'Sin grupo', 'descripcion' => 'x', 'enlace' => 'https://app.powerbi.com/view?r=eyJrIjoiMTIzNDU2Nzg5MDEyMyJ9']);
$webId = (int)($web['data']['id'] ?? 0);
// one() devuelve un ESCALAR; para leer ambas columnas se usa rows() (fetchAll asociativo).
$webRows = $webId ? rows($root, 'SELECT reporte_id, grupo_id FROM reporte WHERE id = ?', [$webId]) : [];
$webRow = $webRows[0] ?? null;
expectCase($s === 200 && $webId > 0 && (int)one($root, 'SELECT COUNT(*) FROM reporte') === $n1 + 1
    && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', (string)($webRow['grupo_id'] ?? '')) === 1
    && $webRow['grupo_id'] === $webRow['reporte_id']
    && (int)one($root, "SELECT COUNT(*) FROM audit_log WHERE entity = 'reporte' AND entity_id = ? AND action = 'reporte.create'", [$webId]) === 1,
    'UX-08B4: enlace publico creado en NOT NULL con UUID interno, auditado y sin grupo Power BI real');
if ($webId) {
    $root->prepare('DELETE FROM reporte WHERE id = ?')->execute([$webId]); // cleanup SOLO en DB sintetica
}
expectCase((int)one($root, 'SELECT COUNT(*) FROM reporte') === $n1,
    'UX-08B4: limpieza fixture, filas historicas intactas');

echo "== Accesos y asignaciones\n";
[$s, $d] = api('appaw', 'POST', '/api/empresa-usuario', $t, ['user_id' => 3, 'empresa_id' => 2]); $eu = (int)($d['InsertedId'] ?? $d['id'] ?? 0);
expectCase($s === 200 && $eu > 0, 'dar acceso a una empresa');
[$s] = api('appaw', 'POST', '/api/empresa-usuario', $t, ['user_id' => 3, 'empresa_id' => 2]);
expectCase($s === 409 && (int)one($root, 'SELECT COUNT(*) FROM empresa_usuario WHERE user_id = 3 AND empresa_id = 2') === 1, 'duplicado: 409, una sola fila');
[$s] = api('appaw', 'POST', '/api/empresa-usuario', $t, ['user_id' => 987654, 'empresa_id' => 2]);
expectCase(in_array($s, [400, 404, 422], true), 'usuario inexistente: rechazado');
[$s] = api('appaw', 'POST', '/api/empresa-usuario', $t, ['user_id' => 3, 'empresa_id' => 3]);
expectCase(in_array($s, [400, 404, 409, 422], true) && (int)one($root, 'SELECT COUNT(*) FROM empresa_usuario WHERE user_id = 3 AND empresa_id = 3') === 0, 'empresa inactiva: no se crea el acceso');
[$s] = api('appaw', 'DELETE', "/api/empresa-usuario/$eu", $t);
expectCase($s === 200 && (int)one($root, 'SELECT activo FROM empresa_usuario WHERE id = ?', [$eu]) === 0, 'revocar acceso: baja lógica');
[$s, $d] = api('appaw', 'POST', '/api/empresa-usuario', $t, ['user_id' => 3, 'empresa_id' => 2]);
expectCase(in_array($s, [200, 409], true) && (int)one($root, 'SELECT COUNT(*) FROM empresa_usuario WHERE user_id = 3 AND empresa_id = 2') === 1, 'volver a dar el acceso reutiliza la fila (sin duplicar)');
[$s, $d] = api('appaw', 'POST', '/api/asignacion', $t, ['empresa_id' => 1, 'reporte_id' => 2]); $er = (int)($d['data']['id'] ?? $d['id'] ?? 0);
expectCase(in_array($s, [200, 201], true) && $er > 0, 'asignar informe a empresa');
[$s] = api('appaw', 'POST', '/api/asignacion', $t, ['empresa_id' => 1, 'reporte_id' => 3]);
expectCase(in_array($s, [400, 404, 409, 422], true), 'informe inactivo: no se asigna');
[$s, $d] = api('appaw', 'POST', '/api/empresa-reporte-usuario', $t, ['user_id' => 3, 'empresa_reporte_id' => $er]); $uer = (int)($d['data']['id'] ?? $d['InsertedId'] ?? $d['id'] ?? 0);
expectCase(in_array($s, [200, 409, 422], true), 'dar acceso a un informe (requiere acceso a la empresa)');
[$s] = api('appaw', 'POST', '/api/empresa-reporte-usuario', $t, ['user_id' => 2, 'empresa_reporte_id' => 987654]);
expectCase(in_array($s, [400, 404, 422], true), 'asignación inexistente: rechazada');
[$s] = api('appaw', 'DELETE', "/api/asignacion/$er", $t);
expectCase($s === 200 && (int)one($root, 'SELECT activo FROM empresa_reporte WHERE id = ?', [$er]) === 0, 'baja lógica de la asignación');
$ac = (int)one($root, "SELECT COUNT(*) FROM audit_log WHERE entity IN ('empresa_usuario','empresa_reporte','user_empresa_reporte')");
expectCase($ac >= 5, 'cada cambio de accesos quedó auditado (' . $ac . ' filas)');

echo "== Usuarios (interruptor propio)\n";
$nu = ['first_name' => 'Nuevo', 'last_name' => 'Usuario', 'username' => 'nuevo.usr', 'email' => 'nuevo.usr@example.invalid', 'password' => 'long-enough-password'];
[$s, $d, $raw] = api('appaw', 'POST', '/api/register', $t, $nu);
$uid = (int)one($root, "SELECT id FROM user WHERE username = 'nuevo.usr'");
expectCase($s === 200 && $uid > 0 && !str_contains($raw, 'long-enough-password') && !preg_match('/\$2y\$/', $raw), 'alta de usuario: sin contraseña ni hash en la respuesta');
expectCase(one($root, 'SELECT role FROM user WHERE id = ?', [$uid]) !== 'admin', 'el rol por defecto nunca es administrador');
[$s] = api('appaw', 'POST', '/api/register', $t, $nu);
expectCase($s === 409 || $s === 400 || $s === 422, 'usuario duplicado: rechazado');
$sv = (int)one($root, 'SELECT session_version FROM user WHERE id = ?', [$uid]);
[$s] = api('appaw', 'POST', "/api/users/$uid/deactivate", $t, []);
expectCase($s === 200 && (int)one($root, 'SELECT is_active FROM user WHERE id = ?', [$uid]) === 0 && (int)one($root, 'SELECT session_version FROM user WHERE id = ?', [$uid]) === $sv + 1, 'desactivar: is_active = 0 y session_version + 1 (invalida sus tokens)');
[$s] = api('appaw', 'POST', "/api/users/$uid/reactivate", $t, []);
expectCase($s === 200 && (int)one($root, 'SELECT is_active FROM user WHERE id = ?', [$uid]) === 1 && (int)one($root, 'SELECT session_version FROM user WHERE id = ?', [$uid]) >= $sv + 2, 'reactivar: vuelve a activo y la versión de sesión sigue subiendo (no resucita tokens)');
[$s] = api('appaw', 'PUT', "/api/user/$uid/role", $t, ['role' => 'admin']);
expectCase($s === 200 && one($root, 'SELECT role FROM user WHERE id = ?', [$uid]) === 'admin' && (int)one($root, 'SELECT session_version FROM user WHERE id = ?', [$uid]) >= $sv + 3, 'cambio de rol: se guarda y sube session_version');
[$s] = api('appaw', 'POST', '/api/users/1/deactivate', $t, []);
expectCase($s !== 200 && (int)one($root, 'SELECT is_active FROM user WHERE id = 1') === 1, 'el administrador no puede desactivarse a sí mismo');
$pw1 = (string)one($root, 'SELECT SHA2(password,256) FROM user WHERE id = ?', [$uid]);
[$s] = api('appaw', 'PUT', "/api/users/$uid/password", $t, ['password' => 'another-long-password']);
expectCase($s === 200 && (string)one($root, 'SELECT SHA2(password,256) FROM user WHERE id = ?', [$uid]) !== $pw1, 'cambio de contraseña: el hash cambia');
$tn = login('nuevo.usr');
expectCase($tn === '', 'un usuario creado aquí NO puede entrar a la instancia administrativa si no está en la lista blanca ni es del rol correcto');
expectCase((int)one($root, "SELECT COUNT(*) FROM audit_log WHERE entity = 'user' AND entity_id = ?", [$uid]) >= 5, 'todos los cambios de usuario quedaron auditados');
expectCase(!preg_match('/\$2y\$|long-enough-password|another-long-password/', (string)one($root, 'SELECT GROUP_CONCAT(CONCAT_WS("|", before_json, after_json)) FROM audit_log WHERE entity = "user"')), 'la auditoría de usuarios no guarda contraseñas ni hashes');

echo "== Fase 2B: controles de sesión para usuarios compartidos con Flutter
";
$tus = login('admin', 'appawus');
[$s, $d] = api('appawus', 'GET', '/api/admin/capacidades', $tus);
expectCase($d['data']['writes']['usuarios'] === true && $d['data']['users_sensitive'] === false && $d['data']['reactivate_after_hours'] === 24, 'instancia 2B por defecto: usuarios sí, operaciones sensibles no, espera de 24 h');
$nu2 = ['first_name' => 'Dos', 'last_name' => 'B', 'username' => 'usuario.2b', 'email' => 'usuario.2b@example.invalid', 'password' => 'long-enough-password'];
[$s] = api('appawus', 'POST', '/api/register', $tus, $nu2);
$u2 = (int)one($root, "SELECT id FROM user WHERE username = 'usuario.2b'");
expectCase($s === 200 && $u2 > 0, 'alta de usuario permitida en 2B por defecto');
[$s] = api('appawus', 'PUT', "/api/users/$u2", $tus, ['phone' => '555-0202']);
expectCase($s === 200 && one($root, 'SELECT phone FROM user WHERE id = ?', [$u2]) === '555-0202', 'edición de datos personales permitida');
[$s, $d] = api('appawus', 'PUT', "/api/user/$u2/role", $tus, ['role' => 'admin']);
[$s2, $d2] = api('appawus', 'PUT', "/api/users/$u2/password", $tus, ['password' => 'another-long-password']);
expectCase($s === 403 && ($d['code'] ?? '') === 'escritura_desactivada' && $s2 === 403 && ($d2['code'] ?? '') === 'escritura_desactivada' && one($root, 'SELECT role FROM user WHERE id = ?', [$u2]) !== 'admin', 'cambio de rol y de contraseña bloqueados (Flutter no invalida tokens antiguos): 403 y sin cambios');
$sv0 = (int)one($root, 'SELECT session_version FROM user WHERE id = ?', [$u2]);
[$s] = api('appawus', 'POST', "/api/users/$u2/deactivate", $tus, []);
expectCase($s === 200 && (int)one($root, 'SELECT is_active FROM user WHERE id = ?', [$u2]) === 0 && (int)one($root, 'SELECT session_version FROM user WHERE id = ?', [$u2]) === $sv0 + 1, 'desactivar funciona de inmediato (Flutter lo rechaza por is_active en cada petición)');
[$s, $d] = api('appawus', 'POST', "/api/users/$u2/reactivate", $tus, []);
expectCase($s === 409 && (int)one($root, 'SELECT is_active FROM user WHERE id = ?', [$u2]) === 0 && str_contains(json_encode($d), '24 h'), 'reactivar antes de 24 h se rechaza (409): no revive tokens antiguos de Flutter');
expectCase((int)one($root, "SELECT COUNT(*) FROM audit_log WHERE entity = 'user' AND entity_id = ? AND action = 'user.reactivate'", [$u2]) === 0, '…y no deja una auditoría de reactivación falsa');

echo "== Atomicidad: si falla la auditoría no se confirma el cambio\n";
$rootAdmin = new PDO('mysql:host=db;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), $opts);
$n0 = (int)one($root, 'SELECT COUNT(*) FROM empresa'); $a0 = (int)one($root, 'SELECT COUNT(*) FROM audit_log');
$rootAdmin->exec("REVOKE INSERT ON `fixture_aw`.`audit_log` FROM 'cervesur_escritura_aw'@'%'");
[$s, $d, $raw] = api('appaw', 'POST', '/api/empresas', $t, $nEmp('20222222222', 'Sin Auditoria'));
expectCase($s >= 500 && !preg_match(SENSITIVE, $raw) && (int)one($root, 'SELECT COUNT(*) FROM empresa') === $n0 && (int)one($root, 'SELECT COUNT(*) FROM audit_log') === $a0, 'sin permiso para auditar: 5xx genérico, la empresa NO quedó creada');
[$s] = api('appaw', 'PUT', '/api/users/' . $uid, $t, ['phone' => '999-0000']);
expectCase($s >= 500 && one($root, 'SELECT phone FROM user WHERE id = ?', [$uid]) !== '999-0000', 'idem para una edición: el cambio se revierte completo');
$rootAdmin->exec("GRANT INSERT ON `fixture_aw`.`audit_log` TO 'cervesur_escritura_aw'@'%'");
[$s] = api('appaw', 'POST', '/api/empresas', $t, $nEmp('20222222222', 'Con Auditoria'));
expectCase($s === 200 && (int)one($root, 'SELECT COUNT(*) FROM empresa') === $n0 + 1, 'restablecido el permiso, la misma operación funciona');

echo "== Concurrencia\n";
$calls = array_fill(0, 6, ['POST', '/api/empresas', $nEmp('20333333333', 'Carrera')]);
$mh = curl_multi_init(); $hs = [];
foreach ($calls as $i => [$m, $p, $b]) { $ch = curl_init("http://appaw:8080$p"); curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $m, CURLOPT_RETURNTRANSFER => true, CURLOPT_POSTFIELDS => json_encode($b), CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-API-Contract: 2', "Authorization: Bearer $t"]]); curl_multi_add_handle($mh, $ch); $hs[] = $ch; }
do { curl_multi_exec($mh, $run); curl_multi_select($mh, 0.2); } while ($run > 0);
$codes = array_map(fn ($h) => curl_getinfo($h, CURLINFO_HTTP_CODE), $hs); sort($codes);
expectCase($codes === [200, 409, 409, 409, 409, 409] && (int)one($root, "SELECT COUNT(*) FROM empresa WHERE ruc = '20333333333'") === 1, 'seis altas simultáneas con el mismo RUC: exactamente una gana, cinco reciben 409');

echo "== Sesiones y revocación\n";
[$s] = api('appaw', 'POST', '/api/logout', $t);
[$s2] = api('appaw', 'GET', '/api/empresas', $t);
expectCase($s === 200 && $s2 === 401, 'cerrar sesión revoca el token en el servidor');
$t = login('admin');
$root->exec('UPDATE user SET session_version = session_version + 1 WHERE id = 1');
[$s] = api('appaw', 'GET', '/api/empresas', $t);
expectCase($s === 401, 'un cambio de session_version invalida la sesión abierta');
$t = login('admin');
$st->exec('UPDATE usuarios_piloto SET activo = 0 WHERE user_id = 1');
[$s] = api('appaw', 'GET', '/api/empresas', $t); [$s2] = api('appaw', 'POST', '/api/empresas', $t, $nEmp('20444444444'));
expectCase($s === 401 && $s2 === 401 && (int)one($root, "SELECT COUNT(*) FROM empresa WHERE ruc = '20444444444'") === 0, 'quitar al administrador de la lista blanca corta lectura y escritura de inmediato');
$st->exec('UPDATE usuarios_piloto SET activo = 1 WHERE user_id = 1');
$root->exec('UPDATE user SET is_active = 0 WHERE id = 1'); $t = login('admin'); [$s] = api('appaw', 'GET', '/api/empresas', $t);
expectCase($t === '' && $s === 401, 'administrador desactivado: no entra');
$root->exec('UPDATE user SET is_active = 1 WHERE id = 1');

echo "== Ninguna respuesta filtra enlaces, secretos ni detalles internos\n";
expectCase(!preg_match(SENSITIVE, $bodies), 'ninguna respuesta (fuera del visor) contiene hashes, SQL, rutas internas ni nombres de cuentas');
$leak = preg_match('~.{40}(https?:[\/]+[a-z.]*powerbi\.com|reportEmbed|groupId=|SECRETO-XYZ|fixture-private).{20}~s', $bodies, $lm) ? $lm[0] : '';
expectCase($leak === '', 'ninguna respuesta (fuera del visor) contiene enlaces de Power BI ni credenciales' . ($leak ? ' · ' . $leak : ''));

echo "\n" . ($failures === 0 ? 'All admin_escritura checks passed' : "$failures check(s) FAILED") . "\n";
exit($failures === 0 ? 0 : 1);
