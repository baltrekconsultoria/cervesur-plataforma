<?php
// UX-08B: capa transaccional común de escrituras (Empresas y Reportes), auditoría, conflictos 409, concurrencia y vistas estrictas.
// Solo red Docker aislada, identidades ficticias y MySQL desechable. Instancia `appw` (base `fixture_w`, con los UNIQUE productivos) con
// AUDIT_LOG_MODE=required y REACT_STRICT_VIEWS=1. `audit_log` NO existe al empezar: la prueba aplica y revierte la migración real.
$failures = 0;
$pdo = new PDO('mysql:host=db;dbname=fixture_w;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
const BASE = 'http://appw:8080';

function requestApi($method, $path, $body = null, $token = null, $strict = false) {
    $headers = ['Content-Type: application/json'];
    if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
    if ($strict) $headers[] = 'X-API-Contract: 2';
    $options = ['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 10]];
    if ($body !== null) $options['http']['content'] = json_encode($body);
    $raw = file_get_contents(BASE . $path, false, stream_context_create($options));
    $GLOBALS['lastResponse'] = substr($raw ?: '', 0, 400);
    $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int)$m[1] : 0;
    return [$status, json_decode($raw ?: '', true), $raw ?: ''];
}
/** Peticiones simultáneas reales (curl_multi). Devuelve [[status, json], …] en el mismo orden. */
function parallel(array $calls, $token) {
    $multi = curl_multi_init();
    $handles = [];
    foreach ($calls as $i => [$method, $path, $body]) {
        $ch = curl_init(BASE . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $token, 'X-API-Contract: 2'],
            CURLOPT_POSTFIELDS => json_encode($body)]);
        curl_multi_add_handle($multi, $ch);
        $handles[$i] = $ch;
    }
    do { $status = curl_multi_exec($multi, $running); if ($running) curl_multi_select($multi, 0.2); } while ($running && $status === CURLM_OK);
    $out = [];
    foreach ($handles as $i => $ch) {
        $out[$i] = [curl_getinfo($ch, CURLINFO_HTTP_CODE), json_decode((string)curl_multi_getcontent($ch), true)];
        curl_multi_remove_handle($multi, $ch);
    }
    curl_multi_close($multi);
    return $out;
}
function expectCase($condition, $label) {
    global $failures;
    if ($condition) echo "PASS $label\n"; else { echo "FAIL $label\n  última respuesta: " . ($GLOBALS['lastResponse'] ?? '') . "\n"; $failures++; }
}
function tokenOf($name) { [, $d] = requestApi('POST', '/api/login', ['login' => $name, 'password' => 'synthetic-password']); return $d['token'] ?? ''; }
function one($pdo, $sql, $params = []) { $st = $pdo->prepare($sql); $st->execute($params); return $st->fetch(); }
function count_of($pdo, $table, $where = '1=1', $params = []) { return (int)one($pdo, "SELECT COUNT(*) c FROM $table WHERE $where", $params)['c']; }
function audits($pdo, $entity, $id) { $st = $pdo->prepare('SELECT * FROM audit_log WHERE entity = ? AND entity_id = ? ORDER BY id'); $st->execute([$entity, $id]); return $st->fetchAll(); }
function embedLink($n) { return 'https://app.powerbi.com/reportEmbed?reportId=cccccccc-0000-4000-8000-0000000000' . sprintf('%02d', $n) . '&groupId=bbbbbbbb-0000-4000-8000-000000000001'; }

$admin = tokenOf('admin');
$worker = tokenOf('usera');
$empresaNew = fn ($ruc, $name = 'Empresa Nueva') => ['nombre' => $name, 'razon_social' => $name, 'ruc' => $ruc];

// ---------- 1. Auditoría obligatoria: sin la tabla, la escritura se rechaza y NO deja nada a medias ----------
$before = count_of($pdo, 'empresa');
[$s, $d, $raw] = requestApi('POST', '/api/empresas', $empresaNew('20000000001'), $admin);
expectCase(($d['success'] ?? true) === false && count_of($pdo, 'empresa') === $before, 'AUDIT_LOG_MODE=required without the table: the write fails closed and the INSERT is rolled back');
expectCase(stripos($raw, 'audit_log') === false && stripos($raw, 'SQLSTATE') === false && stripos($raw, 'doesn') === false, 'the error body does not leak SQL or table names');
[$s] = requestApi('POST', '/api/empresas', $empresaNew('20000000001'), $admin, true);
expectCase($s === 500, 'with contract 2 an internal failure is HTTP 500');

// ---------- 2. Migración real ----------
$pdo->exec(file_get_contents('database/migrations/UX-08B-audit-log.up.sql'));
$cols = array_column($pdo->query('SHOW COLUMNS FROM audit_log')->fetchAll(), 'Field');
expectCase(array_diff(['id', 'correlation_id', 'occurred_at', 'actor_user_id', 'action', 'entity', 'entity_id', 'before_json', 'after_json', 'request_ip'], $cols) === [], 'the migration creates audit_log with the designed columns');
$fks = (int)one($pdo, "SELECT COUNT(*) c FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log' AND CONSTRAINT_TYPE = 'FOREIGN KEY'")['c'];
expectCase($fks === 0, 'audit_log has no foreign key (audit rows are never cascaded away)');
$pdo->exec(file_get_contents('database/migrations/UX-08B-audit-log.up.sql'));
expectCase(true, 'the migration is idempotent (CREATE TABLE IF NOT EXISTS)');

// ---------- 3. Alta de empresa: transacción + auditoría ----------
[$s, $d] = requestApi('POST', '/api/empresas', $empresaNew('20000000001') + ['powerbi_tenant' => 'tenant-x', 'powerbi_clientsecret' => 'SECRET-VALUE-XYZ'], $admin);
$cid = (int)($d['id'] ?? 0);
expectCase($s === 200 && ($d['success'] ?? false) === true && $cid > 0 && !empty($d['correlation_id']), 'admin creates a company and receives a correlation id');
$log = audits($pdo, 'empresa', $cid);
expectCase(count($log) === 1 && $log[0]['action'] === 'empresa.create' && (int)$log[0]['actor_user_id'] === 1 && $log[0]['correlation_id'] === $d['correlation_id'] && $log[0]['before_json'] === null, 'exactly one audit row: action, actor and the response correlation id');
$after = json_decode($log[0]['after_json'] ?? '{}', true);
expectCase(($after['ruc'] ?? '') === '20000000001' && ($after['powerbi_campos'] ?? []) === ['powerbi_tenant', 'powerbi_clientsecret'], 'the audit keeps allowed values and only the NAMES of Power BI fields that were set');

// ---------- 4. Atomicidad negocio + auditoría ----------
$pdo->exec("CREATE TRIGGER audit_block BEFORE INSERT ON audit_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit blocked'");
$before = count_of($pdo, 'empresa');
[$s, $d] = requestApi('POST', '/api/empresas', $empresaNew('20000000002'), $admin);
expectCase(($d['success'] ?? true) === false && count_of($pdo, 'empresa') === $before && count_of($pdo, 'empresa', "ruc = '20000000002'") === 0, 'if the audit insert fails the business write is rolled back (atomic)');
[$s, $d] = requestApi('PUT', "/api/empresas/$cid", ['ciudad' => 'Rollback'], $admin);
expectCase(($d['success'] ?? true) === false && one($pdo, 'SELECT ciudad FROM empresa WHERE id = ?', [$cid])['ciudad'] !== 'Rollback', 'an update whose audit fails is rolled back too');
$pdo->exec('DROP TRIGGER audit_block');

// ---------- 5. Duplicados ----------
[$s, $d] = requestApi('POST', '/api/empresas', $empresaNew('20000000001', 'Otra'), $admin);
expectCase($s === 200 && ($d['success'] ?? true) === false && strpos($d['message'] ?? '', 'RUC') !== false, 'legacy contract: duplicate RUC keeps HTTP 200 + success:false');
[$s, $d] = requestApi('POST', '/api/empresas', $empresaNew('20000000001', 'Otra'), $admin, true);
expectCase($s === 409, 'contract 2: duplicate RUC is HTTP 409');
[$s] = requestApi('POST', '/api/empresas', $empresaNew('10000000003', 'Otra'), $admin, true);
expectCase($s === 409, 'a RUC that belongs to an INACTIVE company is also a conflict (parity with the production UNIQUE)');
expectCase(count_of($pdo, 'empresa', "ruc = '20000000001'") === 1, 'no duplicate row was created');

// ---------- 6. Bloqueo optimista ----------
[$s, $form] = requestApi('GET', "/api/empresas/$cid?view=form", null, $admin);
$v1 = $form['data']['version'] ?? '';
expectCase($s === 200 && preg_match('/^[0-9a-f]{16}$/', $v1) && !isset($form['data']['powerbi_clientsecret']) && !isset($form['data']['powerbi_tenant']), 'the form view carries a version and still hides Power BI configuration');
[$s, $d] = requestApi('PUT', "/api/empresas/$cid", ['ciudad' => 'Lima', 'version' => $v1], $admin);
expectCase($s === 200 && ($d['success'] ?? false) === true, 'an update with the current version succeeds');
$log = audits($pdo, 'empresa', $cid);
$last = end($log);
expectCase($last['action'] === 'empresa.update' && json_decode($last['before_json'], true)['ciudad'] !== 'Lima' && json_decode($last['after_json'], true)['ciudad'] === 'Lima', 'the update audit stores before and after');
[$s, $d] = requestApi('PUT', "/api/empresas/$cid", ['ciudad' => 'Cusco', 'version' => $v1], $admin);
expectCase($s === 409 && ($d['success'] ?? true) === false, 'an update with a stale version is HTTP 409 (even without contract 2)');
expectCase(one($pdo, 'SELECT ciudad FROM empresa WHERE id = ?', [$cid])['ciudad'] === 'Lima', 'the stale update changed nothing');
expectCase(count(audits($pdo, 'empresa', $cid)) === 2, 'a rejected update leaves no audit row');
[$s, $d] = requestApi('PUT', "/api/empresas/$cid", ['ciudad' => 'Tacna'], $admin);
expectCase($s === 200 && ($d['success'] ?? false) === true, 'compatibility: an update without version keeps working (last write wins, as before)');

// ---------- 7. Concurrencia real ----------
[, $form] = requestApi('GET', "/api/empresas/$cid?view=form", null, $admin);
$v = $form['data']['version'];
$res = parallel([['PUT', "/api/empresas/$cid", ['ciudad' => 'Carrera A', 'version' => $v]], ['PUT', "/api/empresas/$cid", ['ciudad' => 'Carrera B', 'version' => $v]]], $admin);
$codes = [$res[0][0], $res[1][0]]; sort($codes);
expectCase($codes === [200, 409], 'two simultaneous edits with the same version: exactly one wins and the other gets 409');
$winner = $res[0][0] === 200 ? 'Carrera A' : 'Carrera B';
expectCase(one($pdo, 'SELECT ciudad FROM empresa WHERE id = ?', [$cid])['ciudad'] === $winner, 'the stored value is the winner\'s, never a mix');
$res = parallel([['POST', '/api/empresas', $empresaNew('20000000077', 'Carrera 1')], ['POST', '/api/empresas', $empresaNew('20000000077', 'Carrera 2')]], $admin);
$codes = [$res[0][0], $res[1][0]]; sort($codes);
expectCase($codes === [200, 409] && count_of($pdo, 'empresa', "ruc = '20000000077'") === 1, 'two simultaneous creations with the same RUC: one row, one 409');
$ids = array_column($pdo->query("SELECT id FROM empresa WHERE ruc = '20000000077'")->fetchAll(), 'id');
expectCase(count(audits($pdo, 'empresa', (int)$ids[0])) === 1, 'the losing creation left no audit row');

// ---------- 8. Baja lógica y reactivación ----------
$res = requestApi('DELETE', "/api/empresas/$cid", null, $admin);
expectCase($res[0] === 200 && (int)one($pdo, 'SELECT activo FROM empresa WHERE id = ?', [$cid])['activo'] === 0 && count_of($pdo, 'empresa', 'id = ?', [$cid]) === 1, 'DELETE is a logical deactivation (the row remains)');
[$s, $d] = requestApi('DELETE', "/api/empresas/$cid", null, $admin);
expectCase($s === 200 && ($d['success'] ?? true) === false, 'deactivating an inactive company keeps the legacy not-found answer');
[$s] = requestApi('DELETE', "/api/empresas/$cid", null, $admin, true);
expectCase($s === 404, 'contract 2: deactivating an inactive company is HTTP 404');
[$s, $d] = requestApi('POST', "/api/empresas/$cid/reactivate", [], $admin);
expectCase($s === 200 && ($d['success'] ?? false) === true && (int)one($pdo, 'SELECT activo FROM empresa WHERE id = ?', [$cid])['activo'] === 1, 'an inactive company is reactivated, not duplicated');
[$s] = requestApi('POST', "/api/empresas/$cid/reactivate", [], $admin);
expectCase($s === 409, 'reactivating an active company is HTTP 409 (state conflict)');
[$s] = requestApi('POST', '/api/empresas/999999/reactivate', [], $admin, true);
expectCase($s === 404, 'reactivating an unknown company is HTTP 404');
$actions = array_column(audits($pdo, 'empresa', $cid), 'action');
expectCase(array_slice($actions, -2) === ['empresa.deactivate', 'empresa.reactivate'], 'deactivation and reactivation are audited in order');

// ---------- 9. Reportes ----------
$rep = fn ($n, $name = 'Informe Nuevo') => ['nombre' => $name, 'descripcion' => 'Prueba', 'enlace' => embedLink($n)];
[$s, $d] = requestApi('POST', '/api/reportes', $rep(10), $admin);
$rid = (int)($d['data']['id'] ?? 0);
expectCase($s === 200 && ($d['success'] ?? false) === true && $rid > 0, 'admin registers a report');
$log = audits($pdo, 'reporte', $rid);
$after = json_decode($log[0]['after_json'] ?? '{}', true);
expectCase(count($log) === 1 && $log[0]['action'] === 'reporte.create' && isset($after['enlace_sha256_12']) && !isset($after['enlace']), 'the report audit stores a link digest, never the link');
expectCase(strpos(json_encode($pdo->query('SELECT * FROM audit_log')->fetchAll()), 'powerbi.com') === false, 'no audit row anywhere contains a Power BI link');
expectCase(strpos(json_encode($pdo->query('SELECT * FROM audit_log')->fetchAll()), 'SECRET-VALUE-XYZ') === false, 'no audit row anywhere contains a Power BI secret');
[$s] = requestApi('POST', '/api/reportes', $rep(10, 'Copia'), $admin, true);
expectCase($s === 409, 'contract 2: registering the same link twice is HTTP 409');
[, $form] = requestApi('GET', "/api/reportes/$rid?view=form", null, $admin);
$rv = $form['data']['version'] ?? '';
expectCase(preg_match('/^[0-9a-f]{16}$/', $rv) === 1 && isset($form['data']['enlace']), 'the report form (admin only) carries its version and link');
[$s] = requestApi('PUT', "/api/reportes/$rid", ['nombre' => 'Editado', 'descripcion' => 'x', 'enlace' => embedLink(10), 'version' => $rv], $admin);
expectCase($s === 200 && one($pdo, 'SELECT nombre FROM reporte WHERE id = ?', [$rid])['nombre'] === 'Editado', 'a report update with the current version succeeds');
[$s, $d] = requestApi('PUT', "/api/reportes/$rid", ['nombre' => 'Obsoleto', 'descripcion' => 'x', 'enlace' => embedLink(10), 'version' => $rv], $admin);
expectCase($s === 409 && one($pdo, 'SELECT nombre FROM reporte WHERE id = ?', [$rid])['nombre'] === 'Editado', 'a stale report update is HTTP 409 and changes nothing');
[$s] = requestApi('PUT', "/api/reportes/$rid", ['nombre' => 'Duplicado', 'descripcion' => 'x', 'enlace' => 'https://app.powerbi.com/reportEmbed?reportId=aaaaaaaa-0000-4000-8000-000000000001&groupId=bbbbbbbb-0000-4000-8000-000000000001'], $admin, true);
expectCase($s === 409, 'editing a report to use another report\'s link is HTTP 409');
[, $form] = requestApi('GET', "/api/reportes/$rid?view=form", null, $admin);
$rv = $form['data']['version'];
$res = parallel([['PUT', "/api/reportes/$rid", ['nombre' => 'Carrera A', 'descripcion' => 'x', 'enlace' => embedLink(10), 'version' => $rv]], ['PUT', "/api/reportes/$rid", ['nombre' => 'Carrera B', 'descripcion' => 'x', 'enlace' => embedLink(10), 'version' => $rv]]], $admin);
$codes = [$res[0][0], $res[1][0]]; sort($codes);
expectCase($codes === [200, 409], 'two simultaneous report edits: exactly one wins and the other gets 409');
[$s] = requestApi('DELETE', "/api/reportes/$rid", null, $admin);
expectCase($s === 200 && (int)one($pdo, 'SELECT activo FROM reporte WHERE id = ?', [$rid])['activo'] === 0, 'a report is deactivated logically');
[$s] = requestApi('POST', "/api/reportes/$rid/reactivate", [], $admin);
expectCase($s === 200 && (int)one($pdo, 'SELECT activo FROM reporte WHERE id = ?', [$rid])['activo'] === 1, 'an inactive report is reactivated');
[$s] = requestApi('POST', "/api/reportes/$rid/reactivate", [], $admin);
expectCase($s === 409, 'reactivating an active report is HTTP 409');
expectCase(array_slice(array_column(audits($pdo, 'reporte', $rid), 'action'), -2) === ['reporte.deactivate', 'reporte.reactivate'], 'report deactivation and reactivation are audited');

// ---------- 10. Restricciones administrativas ----------
$auditBefore = count_of($pdo, 'audit_log');
$state = [count_of($pdo, 'empresa'), count_of($pdo, 'reporte')];
foreach ([['POST', '/api/empresas', $empresaNew('20000000099')], ['PUT', "/api/empresas/$cid", ['ciudad' => 'X']], ['DELETE', "/api/empresas/$cid", null], ['POST', "/api/empresas/$cid/reactivate", []],
          ['POST', '/api/reportes', $rep(20)], ['PUT', "/api/reportes/$rid", $rep(10)], ['DELETE', "/api/reportes/$rid", null], ['POST', "/api/reportes/$rid/reactivate", []]] as [$m, $p, $b]) {
    [$s] = requestApi($m, $p, $b, $worker);
    expectCase($s === 403, "a worker cannot $m $p");
    [$s] = requestApi($m, $p, $b);
    expectCase($s === 401, "an anonymous caller cannot $m $p");
}
expectCase(count_of($pdo, 'audit_log') === $auditBefore && $state === [count_of($pdo, 'empresa'), count_of($pdo, 'reporte')], 'rejected callers changed nothing and left no audit rows');
$parts = explode('.', $worker);
$payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
$payload['role'] = 'admin';
$forged = $parts[0] . '.' . rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=') . '.' . $parts[2];
[$s] = requestApi('POST', '/api/empresas', $empresaNew('20000000098'), $forged);
expectCase($s === 401, 'a token whose role claim was tampered with is rejected');
[$s] = requestApi('POST', '/api/empresas?role=admin&user_id=1', $empresaNew('20000000098') + ['role' => 'admin', 'user_id' => 1], $worker);
expectCase($s === 403, 'role and user_id sent as parameters do not elevate a worker');

// ---------- 11. Vistas por defecto (REACT_STRICT_VIEWS=1) y exposición de enlaces ----------
[$s, $d, $raw] = requestApi('GET', '/api/reportes?per_page=100', null, $worker);
$ids = array_column($d['data'] ?? [], 'id');
expectCase($s === 200 && $ids === [1] && strpos($raw, 'powerbi.com') === false && !isset($d['data'][0]['enlace']) && !isset($d['data'][0]['created_by']), 'strict instance: a worker calling /api/reportes directly gets only its report, with no link and no creator');
[$s, $d, $raw] = requestApi('GET', '/api/reportes/2', null, $worker);
expectCase(($d['success'] ?? true) === false && strpos($raw, 'powerbi.com') === false && strpos($raw, 'enlace') === false, 'a report without assignment is not found for a worker and no link is returned');
[$s, $d, $raw] = requestApi('GET', '/api/empresas', null, $worker);
expectCase($s === 200 && count($d['data'] ?? []) === 1 && strpos($raw, 'powerbi_tenant') === false && strpos($raw, 'powerbi_client') === false && strpos($raw, 'tenant-w1') === false, 'strict instance: /api/empresas gives a worker no Power BI configuration');
[$s, , $raw] = requestApi('GET', '/api/reportes?per_page=100', null, $admin);
expectCase($s === 200 && strpos($raw, 'powerbi.com') === false, 'strict instance: the admin list carries no links either (links only through view=form)');
[$s, , $raw] = requestApi('GET', '/api/reportes?role=admin&user_id=1&per_page=100', null, $worker);
expectCase($s === 200 && substr_count($raw, '"nombre"') === 1, 'role and user_id query parameters do not widen what a worker sees');
[$s] = requestApi('GET', '/api/reportes/1?view=form', null, $worker);
expectCase($s === 403, 'view=form is for administrators only');
[$s] = requestApi('GET', '/api/reportes?view=full', null, $worker);
expectCase($s === 400, 'an unknown view is rejected');
[$s, $d] = requestApi('GET', '/api/reportes/id/1/visor', null, $worker);
expectCase($s === 200 && ($d['data']['id'] ?? 0) === 1, 'the viewer endpoint is unaffected by the default view');

// ---------- 12. Migración inversa ----------
$pdo->exec(file_get_contents('database/migrations/UX-08B-audit-log.down.sql'));
expectCase((int)one($pdo, "SELECT COUNT(*) c FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log'")['c'] === 0, 'the down migration removes only audit_log');
expectCase(count_of($pdo, 'empresa') > 0 && count_of($pdo, 'reporte') > 0, 'business tables are untouched by the down migration');

echo $failures === 0 ? "ALL PASSED\n" : "$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
