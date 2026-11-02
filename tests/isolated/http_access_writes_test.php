<?php
// UX-08A: relaciones de acceso (empresa_usuario, empresa_reporte, user_empresa_reporte): escrituras transaccionales y auditadas,
// reglas de compatibilidad entre relaciones intermedias, concurrencia real, atomicidad, reactivación sin duplicar y endpoint de historial.
// Solo red Docker aislada, identidades ficticias y MySQL desechable. Instancia `appw` (base `fixture_w`, UNIQUE productivos) con
// AUDIT_LOG_MODE=required y REACT_STRICT_VIEWS=1. Se ejecuta DESPUÉS de http_writes_test.php (que deja `audit_log` ausente).
// Usa ids >= 1000 propios y los elimina al terminar (base desechable); aplica y revierte la migración real de auditoría.
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
/** Peticiones simultáneas reales (curl_multi), con contrato 2. Devuelve [[status, json], …] en el mismo orden. */
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
function lastAudit($pdo, $entity, $id) { $a = audits($pdo, $entity, $id); return $a ? end($a) : null; }
/** Igualdad de objetos JSON sin depender del orden de claves (MySQL normaliza el orden de las claves del tipo JSON). */
function eqk($a, $b) { if (!is_array($a)) return false; ksort($a); ksort($b); return $a === $b; }
function sortedKeys($a) { $k = array_keys((array)$a); sort($k); return $k; }
function totals($pdo) { return [count_of($pdo, 'empresa_usuario'), count_of($pdo, 'empresa_reporte'), count_of($pdo, 'user_empresa_reporte')]; }
function noShrink($pdo, &$prev, $label) {
    $now = totals($pdo);
    expectCase($now[0] >= $prev[0] && $now[1] >= $prev[1] && $now[2] >= $prev[2], "no physical deletes: relation row counts never decrease ($label)");
    $prev = $now;
}

/* ------------------------------------------------------------------ fixtures propios (ids >= 1000) */
function cleanup($pdo) {
    foreach (['user_empresa_reporte WHERE user_id >= 1000 OR empresa_reporte_id IN (SELECT id FROM empresa_reporte WHERE empresa_id >= 1000 OR reporte_id >= 1000)',
              'empresa_reporte WHERE empresa_id >= 1000 OR reporte_id >= 1000',
              'empresa_usuario WHERE user_id >= 1000 OR empresa_id >= 1000',
              'user WHERE id >= 1000', 'empresa WHERE id >= 1000', 'reporte WHERE id >= 1000'] as $t) {
        $pdo->exec("DELETE FROM $t");
    }
}
$pdo->exec('DROP TRIGGER IF EXISTS audit_block');
$pdo->exec('DROP TABLE IF EXISTS audit_log');
cleanup($pdo);
$hash = password_hash('synthetic-password', PASSWORD_DEFAULT);
$insU = $pdo->prepare('INSERT INTO user (id, username, email, password, first_name, last_name, role, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
foreach ([[1001, 'acc_u1', 1], [1002, 'acc_u2', 1], [1003, 'acc_u3', 1], [1004, 'acc_u4', 1], [1005, 'acc_inactive', 0]] as [$id, $name, $active]) {
    $insU->execute([$id, $name, "$name@example.invalid", $hash, 'Acc', ucfirst($name), 'user', $active]);
}
$insE = $pdo->prepare('INSERT INTO empresa (id, nombre, razon_social, ruc, activo) VALUES (?, ?, ?, ?, ?)');
foreach ([[1001, '30000001001', 1], [1002, '30000001002', 1], [1003, '30000001003', 0]] as [$id, $ruc, $active]) $insE->execute([$id, "Empresa ACC $id", "Empresa ACC $id", $ruc, $active]);
$insR = $pdo->prepare('INSERT INTO reporte (id, nombre, descripcion, enlace, grupo_id, reporte_id, created_by, activo) VALUES (?, ?, ?, ?, ?, ?, 1, ?)');
foreach ([[1001, 1], [1002, 1], [1003, 0]] as [$id, $active]) {
    $insR->execute([$id, "Informe ACC $id", 'Fixture', "https://app.powerbi.com/reportEmbed?reportId=dddddddd-0000-4000-8000-00000000$id&groupId=bbbbbbbb-0000-4000-8000-000000000001",
        'bbbbbbbb-0000-4000-8000-000000000001', "dddddddd-0000-4000-8000-00000000$id", $active]);
}

$admin = tokenOf('admin');
$worker = tokenOf('usera');
$prev = totals($pdo);

/* ------------------------------------------------------------------ 1. Sin la tabla de auditoría */
[$s, $d] = requestApi('GET', '/api/auditoria', null, $admin);
expectCase($s === 200 && ($d['success'] ?? false) === true && ($d['data'] ?? null) === [] && ($d['audit_available'] ?? null) === false && isset($d['pagination']), 'history without audit_log: 200, empty data, audit_available:false (no error)');
$before = totals($pdo);
[$s, $d, $raw] = requestApi('POST', '/api/empresa-usuario', ['user_id' => 1001, 'empresa_id' => 1001], $admin);
expectCase(($d['success'] ?? true) === false && totals($pdo) === $before && stripos($raw, 'audit_log') === false && stripos($raw, 'SQLSTATE') === false, 'AUDIT_LOG_MODE=required without the table: a grant fails closed, writes nothing and leaks no SQL');
[$s] = requestApi('POST', '/api/empresa-usuario', ['user_id' => 1001, 'empresa_id' => 1001], $admin, true);
expectCase($s === 500, 'contract 2: an internal failure is HTTP 500');

$pdo->exec(file_get_contents('database/migrations/UX-08B-audit-log.up.sql'));

/* ------------------------------------------------------------------ 2. empresa_usuario */
[$s, $d] = requestApi('POST', '/api/empresa-usuario', ['user_id' => 1001, 'empresa_id' => 1001], $admin);
$eu1 = (int)($d['InsertedId'] ?? 0);
expectCase($s === 200 && ($d['success'] ?? false) === true && $eu1 > 0 && preg_match('/^[0-9a-f]{32}$/', $d['correlation_id'] ?? '') === 1, 'empresa_usuario: a valid grant succeeds and returns the new id and a correlation id');
$log = audits($pdo, 'empresa_usuario', $eu1);
expectCase(count($log) === 1 && $log[0]['action'] === 'empresa_usuario.create' && (int)$log[0]['actor_user_id'] === 1 && $log[0]['correlation_id'] === $d['correlation_id'] && $log[0]['before_json'] === null, 'empresa_usuario: exactly one audit row (action, actor, same correlation id as the response, no before)');
expectCase(eqk(json_decode($log[0]['after_json'], true), ['user_id' => 1001, 'empresa_id' => 1001, 'activo' => 1]), 'empresa_usuario: the audit snapshot holds only ids and state');

[$s, $d] = requestApi('POST', '/api/empresa-usuario', ['user_id' => 1001, 'empresa_id' => 1001], $admin);
expectCase($s === 200 && ($d['success'] ?? true) === false && stripos($d['message'] ?? '', 'ya existe') !== false && !isset($d['conflict']), 'empresa_usuario: duplicate grant keeps the legacy shape (HTTP 200 + success:false, no internal fields)');
[$s] = requestApi('POST', '/api/empresa-usuario', ['user_id' => 1001, 'empresa_id' => 1001], $admin, true);
expectCase($s === 409 && count_of($pdo, 'empresa_usuario', 'user_id = 1001 AND empresa_id = 1001') === 1 && count(audits($pdo, 'empresa_usuario', $eu1)) === 1, 'empresa_usuario: contract 2 duplicate is 409, one row, no extra audit row');

[$s, $d] = requestApi('POST', '/api/empresa-usuario', ['user_id' => 1002, 'empresa_id' => 1001, 'id' => 99999, 'activo' => 0, 'created_by' => 999, 'role' => 'admin', 'notas' => 'nota'], $admin);
$eu2 = (int)($d['InsertedId'] ?? 0);
$row = one($pdo, 'SELECT * FROM empresa_usuario WHERE id = ?', [$eu2]);
expectCase($eu2 > 0 && $eu2 !== 99999 && (int)$row['activo'] === 1 && (int)$row['created_by'] === 1 && $row['notas'] === 'nota', 'empresa_usuario: id, activo, created_by and role in the body are ignored (no mass assignment)');
expectCase(strpos(json_encode(audits($pdo, 'empresa_usuario', $eu2)), 'nota') === false, 'empresa_usuario: notes are never copied to the audit log');

foreach ([[1005, 1001, 'inactive user'], [1001, 1003, 'inactive company']] as [$u, $e, $label]) {
    $n = count_of($pdo, 'empresa_usuario');
    [$s, $d] = requestApi('POST', '/api/empresa-usuario', ['user_id' => $u, 'empresa_id' => $e], $admin);
    expectCase($s === 409 && ($d['success'] ?? true) === false && count_of($pdo, 'empresa_usuario') === $n, "empresa_usuario: $label is rejected with 409 (state) and writes nothing");
}
[$s] = requestApi('POST', '/api/empresa-usuario', ['user_id' => 987654, 'empresa_id' => 1001], $admin, true);
expectCase($s === 404, 'empresa_usuario: unknown user is 404 under contract 2');
[$s] = requestApi('POST', '/api/empresa-usuario', ['user_id' => 1001, 'empresa_id' => 987654], $admin, true);
expectCase($s === 404, 'empresa_usuario: unknown company is 404 under contract 2');
[$s, $d] = requestApi('POST', '/api/empresa-usuario', ['user_id' => 987654, 'empresa_id' => 1001], $admin);
expectCase($s === 200 && ($d['success'] ?? true) === false, 'empresa_usuario: unknown user keeps the legacy HTTP 200 + success:false');
$n = count_of($pdo, 'empresa_usuario');
foreach ([['abc', 1001], [-1, 1001], [0, 1001], [1.5, 1001], [true, 1001], [[1], 1001], [1001, 'x']] as $i => [$u, $e]) {
    [$s, $d] = requestApi('POST', '/api/empresa-usuario', ['user_id' => $u, 'empresa_id' => $e], $admin, true);
    expectCase($s === 400 && ($d['success'] ?? true) === false, "empresa_usuario: invalid ids are rejected with 400 under contract 2 (case $i)");
}
expectCase(count_of($pdo, 'empresa_usuario') === $n, 'empresa_usuario: invalid ids wrote nothing');

[$s, $d] = requestApi('DELETE', "/api/empresa-usuario/$eu2", null, $admin);
$row = one($pdo, 'SELECT * FROM empresa_usuario WHERE id = ?', [$eu2]);
$a = lastAudit($pdo, 'empresa_usuario', $eu2);
expectCase($s === 200 && ($d['success'] ?? false) === true && (int)$row['activo'] === 0 && $a['action'] === 'empresa_usuario.revoke' && json_decode($a['before_json'], true)['activo'] === 1 && json_decode($a['after_json'], true)['activo'] === 0 && $a['correlation_id'] === $d['correlation_id'], 'empresa_usuario: revoke is a logical deactivation, audited with before/after');
[$s, $d] = requestApi('DELETE', "/api/empresa-usuario/$eu2", null, $admin);
expectCase($s === 200 && ($d['success'] ?? true) === false, 'empresa_usuario: revoking twice keeps the legacy HTTP 200 + success:false');
[$s] = requestApi('DELETE', "/api/empresa-usuario/$eu2", null, $admin, true);
expectCase($s === 404 && count(audits($pdo, 'empresa_usuario', $eu2)) === 2, 'empresa_usuario: revoking twice is 404 under contract 2 and leaves no audit row');
[$s, $d] = requestApi('POST', '/api/empresa-usuario', ['user_id' => 1002, 'empresa_id' => 1001], $admin);
$a = lastAudit($pdo, 'empresa_usuario', $eu2);
expectCase($s === 200 && (int)($d['InsertedId'] ?? 0) === $eu2 && count_of($pdo, 'empresa_usuario', 'user_id = 1002 AND empresa_id = 1001') === 1 && $a['action'] === 'empresa_usuario.reactivate', 'empresa_usuario: granting again reuses the same row id (no duplicate) and is audited as reactivate');
requestApi('DELETE', "/api/empresa-usuario/$eu2", null, $admin);
[$s, $d] = requestApi('POST', "/api/empresa-usuario/$eu2/reactivate", [], $admin);
expectCase($s === 200 && ($d['success'] ?? false) === true && (int)one($pdo, 'SELECT activo FROM empresa_usuario WHERE id = ?', [$eu2])['activo'] === 1 && lastAudit($pdo, 'empresa_usuario', $eu2)['action'] === 'empresa_usuario.reactivate', 'empresa_usuario: POST /{id}/reactivate reactivates and audits');
[$s] = requestApi('POST', "/api/empresa-usuario/$eu2/reactivate", [], $admin);
expectCase($s === 409, 'empresa_usuario: reactivating an active relation is 409 (state)');
[$s] = requestApi('POST', '/api/empresa-usuario/987654/reactivate', [], $admin, true);
expectCase($s === 404, 'empresa_usuario: reactivating an unknown id is 404');
// Reactivar con un padre inactivo -> 409 y la fila sigue retirada
requestApi('DELETE', "/api/empresa-usuario/$eu2", null, $admin);
$pdo->exec('UPDATE empresa SET activo = 0 WHERE id = 1001');
[$s] = requestApi('POST', "/api/empresa-usuario/$eu2/reactivate", [], $admin);
expectCase($s === 409 && (int)one($pdo, 'SELECT activo FROM empresa_usuario WHERE id = ?', [$eu2])['activo'] === 0, 'empresa_usuario: reactivating with an inactive company is 409 and the row stays revoked');
[$s] = requestApi('POST', '/api/empresa-usuario', ['user_id' => 1002, 'empresa_id' => 1001], $admin);
expectCase($s === 409 && (int)one($pdo, 'SELECT activo FROM empresa_usuario WHERE id = ?', [$eu2])['activo'] === 0, 'empresa_usuario: re-granting with an inactive company is 409 as well');
$pdo->exec('UPDATE empresa SET activo = 1 WHERE id = 1001');
$pdo->exec('UPDATE user SET is_active = 0 WHERE id = 1002');
[$s] = requestApi('POST', "/api/empresa-usuario/$eu2/reactivate", [], $admin);
expectCase($s === 409 && (int)one($pdo, 'SELECT activo FROM empresa_usuario WHERE id = ?', [$eu2])['activo'] === 0, 'empresa_usuario: reactivating with an inactive user is 409 and the row stays revoked');
$pdo->exec('UPDATE user SET is_active = 1 WHERE id = 1002');
requestApi('POST', "/api/empresa-usuario/$eu2/reactivate", [], $admin);

// PUT
[$s, $d] = requestApi('PUT', "/api/empresa-usuario/$eu1", ['notas' => 'editada', 'activo' => 0, 'id' => 77], $admin);
$a = lastAudit($pdo, 'empresa_usuario', $eu1);
expectCase($s === 200 && ($d['success'] ?? false) === true && one($pdo, 'SELECT notas, activo FROM empresa_usuario WHERE id = ?', [$eu1]) == ['notas' => 'editada', 'activo' => 1] && $a['action'] === 'empresa_usuario.update', 'empresa_usuario: PUT updates notes only (activo/id ignored) and is audited');
[$s] = requestApi('PUT', "/api/empresa-usuario/$eu1", ['user_id' => 1005], $admin);
expectCase($s === 409 && (int)one($pdo, 'SELECT user_id FROM empresa_usuario WHERE id = ?', [$eu1])['user_id'] === 1001, 'empresa_usuario: PUT moving the relation to an inactive user is 409 and changes nothing');
[$s] = requestApi('PUT', '/api/empresa-usuario/987654', ['notas' => 'x'], $admin, true);
expectCase($s === 404, 'empresa_usuario: PUT on an unknown id is 404');
noShrink($pdo, $prev, 'empresa_usuario');

/* ------------------------------------------------------------------ 3. empresa_reporte (/api/asignacion) */
[$s, $d] = requestApi('POST', '/api/asignacion', ['empresa_id' => 1001, 'reporte_id' => 1001], $admin);
$er1 = (int)($d['data']['id'] ?? 0);
$log = audits($pdo, 'empresa_reporte', $er1);
expectCase($s === 201 && ($d['success'] ?? false) === true && $er1 > 0 && !empty($d['correlation_id']) && count($log) === 1 && $log[0]['action'] === 'empresa_reporte.create' && $log[0]['correlation_id'] === $d['correlation_id']
    && eqk(json_decode($log[0]['after_json'], true), ['empresa_id' => 1001, 'reporte_id' => 1001, 'activo' => 1]), 'empresa_reporte: a valid grant is 201 (as before) with a correlation id and one audit row');
[$s, $d] = requestApi('POST', '/api/asignacion', ['empresa_id' => 1001, 'reporte_id' => 1001], $admin);
expectCase($s === 400 && ($d['success'] ?? true) === false && stripos($d['message'] ?? '', 'Ya existe') !== false, 'empresa_reporte: duplicate keeps the legacy HTTP 400 + success:false');
[$s] = requestApi('POST', '/api/asignacion', ['empresa_id' => 1001, 'reporte_id' => 1001], $admin, true);
expectCase($s === 409 && count_of($pdo, 'empresa_reporte', 'empresa_id = 1001 AND reporte_id = 1001') === 1, 'empresa_reporte: contract 2 duplicate is 409 with one row');
foreach ([[1003, 1001, 'inactive company'], [1001, 1003, 'inactive report']] as [$e, $r, $label]) {
    $n = count_of($pdo, 'empresa_reporte');
    [$s, $d] = requestApi('POST', '/api/asignacion', ['empresa_id' => $e, 'reporte_id' => $r], $admin);
    expectCase($s === 409 && ($d['success'] ?? true) === false && count_of($pdo, 'empresa_reporte') === $n, "empresa_reporte: $label is rejected with 409 and writes nothing");
}
[$s] = requestApi('POST', '/api/asignacion', ['empresa_id' => 987654, 'reporte_id' => 1001], $admin, true);
expectCase($s === 404, 'empresa_reporte: unknown company is 404 under contract 2');
[$s] = requestApi('POST', '/api/asignacion', ['empresa_id' => 1001, 'reporte_id' => 987654], $admin, true);
expectCase($s === 404, 'empresa_reporte: unknown report is 404 under contract 2');
[$s, $d] = requestApi('POST', '/api/asignacion', ['empresa_id' => 1001, 'reporte_id' => 'dddddddd-0000-4000-8000-000000001001'], $admin);
expectCase($s === 400 && ($d['success'] ?? true) === false, 'empresa_reporte: a report UUID where the numeric id is required is rejected (400)');
[$s, $d] = requestApi('POST', '/api/asignacion', ['empresa_id' => 1001, 'reporte_id' => 1002, 'id' => 99999, 'activo' => 0, 'created_by' => 999], $admin);
$er2 = (int)($d['data']['id'] ?? 0);
$row = one($pdo, 'SELECT * FROM empresa_reporte WHERE id = ?', [$er2]);
expectCase($er2 > 0 && $er2 !== 99999 && (int)$row['activo'] === 1 && (int)$row['created_by'] === 1, 'empresa_reporte: id, activo and created_by in the body are ignored');
[$s, $d] = requestApi('DELETE', "/api/asignacion/$er2", null, $admin);
$a = lastAudit($pdo, 'empresa_reporte', $er2);
expectCase($s === 200 && ($d['success'] ?? false) === true && (int)one($pdo, 'SELECT activo FROM empresa_reporte WHERE id = ?', [$er2])['activo'] === 0 && $a['action'] === 'empresa_reporte.revoke' && json_decode($a['before_json'], true)['activo'] === 1, 'empresa_reporte: revoke is a logical deactivation, audited');
[$s, $d] = requestApi('DELETE', "/api/asignacion/$er2", null, $admin);
expectCase($s === 400 && ($d['success'] ?? true) === false, 'empresa_reporte: revoking twice keeps the legacy HTTP 400 + success:false');
[$s] = requestApi('DELETE', "/api/asignacion/$er2", null, $admin, true);
expectCase($s === 404, 'empresa_reporte: revoking twice is 404 under contract 2');
[$s, $d] = requestApi('POST', '/api/asignacion', ['empresa_id' => 1001, 'reporte_id' => 1002], $admin);
expectCase($s === 201 && (int)($d['data']['id'] ?? 0) === $er2 && count_of($pdo, 'empresa_reporte', 'empresa_id = 1001 AND reporte_id = 1002') === 1 && lastAudit($pdo, 'empresa_reporte', $er2)['action'] === 'empresa_reporte.reactivate', 'empresa_reporte: granting again reuses the same row id and is audited as reactivate');
requestApi('DELETE', "/api/asignacion/$er2", null, $admin);
[$s, $d] = requestApi('POST', "/api/asignacion/$er2/reactivate", [], $admin);
expectCase($s === 200 && ($d['success'] ?? false) === true && (int)one($pdo, 'SELECT activo FROM empresa_reporte WHERE id = ?', [$er2])['activo'] === 1, 'empresa_reporte: POST /{id}/reactivate reactivates');
[$s] = requestApi('POST', "/api/asignacion/$er2/reactivate", [], $admin);
expectCase($s === 409, 'empresa_reporte: reactivating an active relation is 409');
[$s] = requestApi('POST', '/api/asignacion/987654/reactivate', [], $admin, true);
expectCase($s === 404, 'empresa_reporte: reactivating an unknown id is 404');
requestApi('DELETE', "/api/asignacion/$er2", null, $admin);
$pdo->exec('UPDATE reporte SET activo = 0 WHERE id = 1002');
[$s] = requestApi('POST', "/api/asignacion/$er2/reactivate", [], $admin);
expectCase($s === 409 && (int)one($pdo, 'SELECT activo FROM empresa_reporte WHERE id = ?', [$er2])['activo'] === 0, 'empresa_reporte: reactivating with an inactive report is 409 and the row stays revoked');
$pdo->exec('UPDATE reporte SET activo = 1 WHERE id = 1002');
$pdo->exec('UPDATE empresa SET activo = 0 WHERE id = 1001');
[$s] = requestApi('POST', "/api/asignacion/$er2/reactivate", [], $admin);
expectCase($s === 409 && (int)one($pdo, 'SELECT activo FROM empresa_reporte WHERE id = ?', [$er2])['activo'] === 0, 'empresa_reporte: reactivating with an inactive company is 409 and the row stays revoked');
$pdo->exec('UPDATE empresa SET activo = 1 WHERE id = 1001');
[$s, $d] = requestApi('PUT', "/api/asignacion/$er1", ['empresa_id' => 1001, 'reporte_id' => 1001, 'notas' => 'nota er'], $admin);
expectCase($s === 200 && ($d['success'] ?? false) === true && lastAudit($pdo, 'empresa_reporte', $er1)['action'] === 'empresa_reporte.update', 'empresa_reporte: PUT is transactional and audited');
[$s] = requestApi('PUT', "/api/asignacion/$er1", ['empresa_id' => 1001, 'reporte_id' => 1003], $admin);
expectCase($s === 409 && (int)one($pdo, 'SELECT reporte_id FROM empresa_reporte WHERE id = ?', [$er1])['reporte_id'] === 1001, 'empresa_reporte: PUT moving to an inactive report is 409 and changes nothing');
// Concurrencia real: dos altas idénticas
$res = parallel([['POST', '/api/asignacion', ['empresa_id' => 1002, 'reporte_id' => 1002]], ['POST', '/api/asignacion', ['empresa_id' => 1002, 'reporte_id' => 1002]]], $admin);
$codes = [$res[0][0], $res[1][0]]; sort($codes);
$er3 = (int)one($pdo, 'SELECT id FROM empresa_reporte WHERE empresa_id = 1002 AND reporte_id = 1002')['id'];
expectCase($codes === [201, 409] && count_of($pdo, 'empresa_reporte', 'empresa_id = 1002 AND reporte_id = 1002') === 1 && count(audits($pdo, 'empresa_reporte', $er3)) === 1, 'empresa_reporte: two simultaneous identical grants -> exactly one 201, one 409, one row, one audit row');
noShrink($pdo, $prev, 'empresa_reporte');

/* ------------------------------------------------------------------ 4. user_empresa_reporte (/api/empresa-reporte-usuario) */
// Estado: eu1=(u1001,e1001) activo, eu2=(u1002,e1001) activo, er1=(e1001,r1001) activo, er2=(e1001,r1002) activo, er3=(e1002,r1002) activo.
[$s, $d] = requestApi('POST', '/api/empresa-reporte-usuario', ['user_id' => 1001, 'empresa_reporte_id' => $er1], $admin);
$uer1 = (int)($d['InsertedId'] ?? 0);
$log = audits($pdo, 'user_empresa_reporte', $uer1);
expectCase($s === 200 && ($d['success'] ?? false) === true && $uer1 > 0 && count($log) === 1 && $log[0]['action'] === 'user_empresa_reporte.create' && $log[0]['correlation_id'] === $d['correlation_id']
    && eqk(json_decode($log[0]['after_json'], true), ['user_id' => 1001, 'empresa_reporte_id' => $er1, 'activo' => 1]), 'user_empresa_reporte: a valid grant succeeds and is audited (ids and state only)');
[$s, $d] = requestApi('POST', '/api/empresa-reporte-usuario', ['user_id' => 1001, 'empresa_reporte_id' => $er1], $admin);
expectCase($s === 200 && ($d['success'] ?? true) === false && stripos($d['message'] ?? '', 'ya existe') !== false, 'user_empresa_reporte: duplicate keeps the legacy HTTP 200 + success:false');
[$s] = requestApi('POST', '/api/empresa-reporte-usuario', ['user_id' => 1001, 'empresa_reporte_id' => $er1], $admin, true);
expectCase($s === 409 && count_of($pdo, 'user_empresa_reporte', 'user_id = 1001 AND empresa_reporte_id = ?', [$er1]) === 1, 'user_empresa_reporte: contract 2 duplicate is 409 with one row');
// Compatibilidad entre relaciones intermedias
$n = count_of($pdo, 'user_empresa_reporte');
[$s, $d] = requestApi('POST', '/api/empresa-reporte-usuario', ['user_id' => 1004, 'empresa_reporte_id' => $er1], $admin);
expectCase($s === 409 && stripos($d['message'] ?? '', 'no está asignado a la empresa') !== false, 'user_empresa_reporte: a user with no company relation is rejected (409)');
requestApi('DELETE', "/api/empresa-usuario/$eu2", null, $admin);
[$s] = requestApi('POST', '/api/empresa-reporte-usuario', ['user_id' => 1002, 'empresa_reporte_id' => $er1], $admin);
expectCase($s === 409, 'user_empresa_reporte: a REVOKED company relation does not allow the grant (409)');
requestApi('POST', "/api/empresa-usuario/$eu2/reactivate", [], $admin);
[$s] = requestApi('POST', '/api/empresa-reporte-usuario', ['user_id' => 1005, 'empresa_reporte_id' => $er1], $admin);
expectCase($s === 409, 'user_empresa_reporte: an inactive user is rejected (409)');
[$s] = requestApi('POST', '/api/empresa-reporte-usuario', ['user_id' => 987654, 'empresa_reporte_id' => $er1], $admin, true);
expectCase($s === 404, 'user_empresa_reporte: an unknown user is 404 under contract 2');
[$s] = requestApi('POST', '/api/empresa-reporte-usuario', ['user_id' => 1001, 'empresa_reporte_id' => 987654], $admin, true);
expectCase($s === 404, 'user_empresa_reporte: an unknown empresa_reporte is 404 under contract 2');
[$s] = requestApi('POST', '/api/empresa-reporte-usuario', ['user_id' => 1001, 'empresa_reporte_id' => 'aaaaaaaa-0000-4000-8000-000000000001'], $admin, true);
expectCase($s === 400, 'user_empresa_reporte: a UUID where the numeric empresa_reporte id is required is 400');
requestApi('DELETE', "/api/asignacion/$er2", null, $admin);
[$s] = requestApi('POST', '/api/empresa-reporte-usuario', ['user_id' => 1001, 'empresa_reporte_id' => $er2], $admin);
expectCase($s === 409, 'user_empresa_reporte: a REVOKED empresa_reporte is rejected (409)');
requestApi('POST', "/api/asignacion/$er2/reactivate", [], $admin);
$pdo->exec('UPDATE reporte SET activo = 0 WHERE id = 1002');
[$s] = requestApi('POST', '/api/empresa-reporte-usuario', ['user_id' => 1001, 'empresa_reporte_id' => $er2], $admin);
expectCase($s === 409, 'user_empresa_reporte: an inactive report is rejected (409)');
$pdo->exec('UPDATE reporte SET activo = 1 WHERE id = 1002');
$pdo->exec('UPDATE empresa SET activo = 0 WHERE id = 1001');
[$s] = requestApi('POST', '/api/empresa-reporte-usuario', ['user_id' => 1001, 'empresa_reporte_id' => $er2], $admin);
expectCase($s === 409, 'user_empresa_reporte: an inactive company is rejected (409)');
$pdo->exec('UPDATE empresa SET activo = 1 WHERE id = 1001');
expectCase(count_of($pdo, 'user_empresa_reporte') === $n, 'user_empresa_reporte: every rejected grant wrote nothing');

[$s, $d] = requestApi('POST', '/api/empresa-reporte-usuario', ['user_id' => 1002, 'empresa_reporte_id' => $er1, 'id' => 99999, 'activo' => 0, 'created_by' => 999, 'role' => 'admin'], $admin);
$uer2 = (int)($d['InsertedId'] ?? 0);
$row = one($pdo, 'SELECT * FROM user_empresa_reporte WHERE id = ?', [$uer2]);
expectCase($uer2 > 0 && $uer2 !== 99999 && (int)$row['activo'] === 1 && (int)$row['created_by'] === 1, 'user_empresa_reporte: id, activo, created_by and role in the body are ignored');
[$s, $d] = requestApi('DELETE', "/api/empresa-reporte-usuario/$uer2", null, $admin);
$a = lastAudit($pdo, 'user_empresa_reporte', $uer2);
expectCase($s === 200 && ($d['success'] ?? false) === true && (int)one($pdo, 'SELECT activo FROM user_empresa_reporte WHERE id = ?', [$uer2])['activo'] === 0 && $a['action'] === 'user_empresa_reporte.revoke' && json_decode($a['before_json'], true)['activo'] === 1 && json_decode($a['after_json'], true)['activo'] === 0, 'user_empresa_reporte: revoke is a logical deactivation, audited');
expectCase((int)one($pdo, 'SELECT activo FROM empresa_usuario WHERE id = ?', [$eu2])['activo'] === 1 && (int)one($pdo, 'SELECT activo FROM empresa_reporte WHERE id = ?', [$er1])['activo'] === 1, 'user_empresa_reporte: revoke does not cascade to the parent relations');
[$s, $d] = requestApi('DELETE', "/api/empresa-reporte-usuario/$uer2", null, $admin);
expectCase($s === 200 && ($d['success'] ?? true) === false, 'user_empresa_reporte: revoking twice keeps the legacy HTTP 200 + success:false');
[$s] = requestApi('DELETE', "/api/empresa-reporte-usuario/$uer2", null, $admin, true);
expectCase($s === 404 && count(audits($pdo, 'user_empresa_reporte', $uer2)) === 2, 'user_empresa_reporte: revoking twice is 404 under contract 2, no extra audit row');
[$s, $d] = requestApi('POST', '/api/empresa-reporte-usuario', ['user_id' => 1002, 'empresa_reporte_id' => $er1], $admin);
expectCase($s === 200 && (int)($d['InsertedId'] ?? 0) === $uer2 && count_of($pdo, 'user_empresa_reporte', 'user_id = 1002 AND empresa_reporte_id = ?', [$er1]) === 1 && lastAudit($pdo, 'user_empresa_reporte', $uer2)['action'] === 'user_empresa_reporte.reactivate', 'user_empresa_reporte: granting again reuses the same row id and is audited as reactivate');
requestApi('DELETE', "/api/empresa-reporte-usuario/$uer2", null, $admin);
[$s, $d] = requestApi('POST', "/api/empresa-reporte-usuario/$uer2/reactivate", [], $admin);
expectCase($s === 200 && ($d['success'] ?? false) === true && (int)one($pdo, 'SELECT activo FROM user_empresa_reporte WHERE id = ?', [$uer2])['activo'] === 1, 'user_empresa_reporte: POST /{id}/reactivate reactivates');
[$s] = requestApi('POST', "/api/empresa-reporte-usuario/$uer2/reactivate", [], $admin);
expectCase($s === 409, 'user_empresa_reporte: reactivating an active access is 409');
[$s] = requestApi('POST', '/api/empresa-reporte-usuario/987654/reactivate', [], $admin, true);
expectCase($s === 404, 'user_empresa_reporte: reactivating an unknown id is 404');
// Reactivar con la cadena rota
requestApi('DELETE', "/api/empresa-reporte-usuario/$uer2", null, $admin);
requestApi('DELETE', "/api/empresa-usuario/$eu2", null, $admin);
[$s] = requestApi('POST', "/api/empresa-reporte-usuario/$uer2/reactivate", [], $admin);
expectCase($s === 409 && (int)one($pdo, 'SELECT activo FROM user_empresa_reporte WHERE id = ?', [$uer2])['activo'] === 0, 'user_empresa_reporte: reactivating while the company relation is revoked is 409 and the row stays revoked');
requestApi('POST', "/api/empresa-usuario/$eu2/reactivate", [], $admin);
$pdo->exec('UPDATE reporte SET activo = 0 WHERE id = 1001');
[$s] = requestApi('POST', "/api/empresa-reporte-usuario/$uer2/reactivate", [], $admin);
expectCase($s === 409, 'user_empresa_reporte: reactivating with an inactive report is 409');
$pdo->exec('UPDATE reporte SET activo = 1 WHERE id = 1001');
$pdo->exec('UPDATE user SET is_active = 0 WHERE id = 1002');
[$s] = requestApi('POST', "/api/empresa-reporte-usuario/$uer2/reactivate", [], $admin);
expectCase($s === 409, 'user_empresa_reporte: reactivating with an inactive user is 409');
$pdo->exec('UPDATE user SET is_active = 1 WHERE id = 1002');
[$s, $d] = requestApi('PUT', "/api/empresa-reporte-usuario/$uer1", ['notas' => 'nota acceso', 'activo' => 0, 'id' => 55], $admin);
expectCase($s === 200 && one($pdo, 'SELECT notas, activo FROM user_empresa_reporte WHERE id = ?', [$uer1]) == ['notas' => 'nota acceso', 'activo' => 1] && lastAudit($pdo, 'user_empresa_reporte', $uer1)['action'] === 'user_empresa_reporte.update', 'user_empresa_reporte: PUT updates notes only and is audited');
[$s] = requestApi('PUT', "/api/empresa-reporte-usuario/$uer1", ['user_id' => 1004], $admin);
expectCase($s === 409 && (int)one($pdo, 'SELECT user_id FROM user_empresa_reporte WHERE id = ?', [$uer1])['user_id'] === 1001, 'user_empresa_reporte: PUT moving the access to a user without company relation is 409 and changes nothing');
// Concurrencia real
requestApi('POST', '/api/empresa-usuario', ['user_id' => 1003, 'empresa_id' => 1002], $admin);
$res = parallel([['POST', '/api/empresa-reporte-usuario', ['user_id' => 1003, 'empresa_reporte_id' => $er3]], ['POST', '/api/empresa-reporte-usuario', ['user_id' => 1003, 'empresa_reporte_id' => $er3]]], $admin);
$codes = [$res[0][0], $res[1][0]]; sort($codes);
$uer3 = (int)one($pdo, 'SELECT id FROM user_empresa_reporte WHERE user_id = 1003 AND empresa_reporte_id = ?', [$er3])['id'];
expectCase($codes === [200, 409] && count_of($pdo, 'user_empresa_reporte', 'user_id = 1003 AND empresa_reporte_id = ?', [$er3]) === 1 && count(audits($pdo, 'user_empresa_reporte', $uer3)) === 1, 'user_empresa_reporte: two simultaneous identical grants -> exactly one 200, one 409, one row, one audit row');
// Carrera entre revocar el vínculo con la empresa y otorgar el acceso: nunca queda un acceso activo sin vínculo activo
$eu3 = (int)one($pdo, 'SELECT id FROM empresa_usuario WHERE user_id = 1003 AND empresa_id = 1002')['id'];
requestApi('DELETE', "/api/empresa-reporte-usuario/$uer3", null, $admin);
$res = parallel([['DELETE', "/api/empresa-usuario/$eu3", null], ['POST', '/api/empresa-reporte-usuario', ['user_id' => 1003, 'empresa_reporte_id' => $er3]]], $admin);
$euActive = (int)one($pdo, 'SELECT activo FROM empresa_usuario WHERE id = ?', [$eu3])['activo'];
$uerActive = (int)one($pdo, 'SELECT activo FROM user_empresa_reporte WHERE id = ?', [$uer3])['activo'];
expectCase($res[0][0] === 200 && in_array($res[1][0], [200, 409], true) && ($res[1][0] === 200) === ($uerActive === 1) && $euActive === 0, 'user_empresa_reporte: revoking the company relation while granting access ends in a consistent state (the grant either committed or was refused, never half-done)');
expectCase($res[0][0] < 500 && $res[1][0] < 500, 'user_empresa_reporte: the race above produced no server error');
noShrink($pdo, $prev, 'user_empresa_reporte');

/* ------------------------------------------------------------------ 5. Atomicidad: si falla la auditoría no cambia nada */
requestApi('POST', "/api/empresa-usuario/$eu3/reactivate", [], $admin);
$pdo->exec("CREATE TRIGGER audit_block BEFORE INSERT ON audit_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit blocked'");
$snap = [totals($pdo), count_of($pdo, 'audit_log'), count_of($pdo, 'empresa_usuario', 'activo = 1'), count_of($pdo, 'empresa_reporte', 'activo = 1'), count_of($pdo, 'user_empresa_reporte', 'activo = 1')];
$attempts = [
    ['POST', '/api/empresa-usuario', ['user_id' => 1004, 'empresa_id' => 1001]],
    ['DELETE', "/api/empresa-usuario/$eu1", null],
    ['PUT', "/api/empresa-usuario/$eu1", ['notas' => 'x']],
    ['POST', '/api/asignacion', ['empresa_id' => 1002, 'reporte_id' => 1001]],
    ['DELETE', "/api/asignacion/$er1", null],
    ['POST', '/api/empresa-reporte-usuario', ['user_id' => 1004, 'empresa_reporte_id' => $er1]],
    ['DELETE', "/api/empresa-reporte-usuario/$uer1", null],
];
foreach ($attempts as [$m, $p, $b]) {
    [$s, $d, $raw] = requestApi($m, $p, $b, $admin);
    expectCase(($d['success'] ?? true) === false && stripos($raw, 'audit') === false && stripos($raw, 'SQLSTATE') === false, "atomicity: $m $p fails closed when the audit insert fails, without leaking internals");
}
$pdo->exec('DROP TRIGGER audit_block');
expectCase($snap === [totals($pdo), count_of($pdo, 'audit_log'), count_of($pdo, 'empresa_usuario', 'activo = 1'), count_of($pdo, 'empresa_reporte', 'activo = 1'), count_of($pdo, 'user_empresa_reporte', 'activo = 1')], 'atomicity: neither rows, states nor audit entries changed after the failed writes (full rollback)');
// Reactivar con fallo de auditoría
requestApi('DELETE', "/api/empresa-usuario/$eu1", null, $admin);
requestApi('DELETE', "/api/empresa-usuario/$eu3", null, $admin);
$pdo->exec("CREATE TRIGGER audit_block BEFORE INSERT ON audit_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit blocked'");
[$s, $d] = requestApi('POST', "/api/empresa-usuario/$eu3/reactivate", [], $admin);
$pdo->exec('DROP TRIGGER audit_block');
expectCase(($d['success'] ?? true) === false && (int)one($pdo, 'SELECT activo FROM empresa_usuario WHERE id = ?', [$eu3])['activo'] === 0, 'atomicity: a reactivation whose audit fails is rolled back');
requestApi('POST', "/api/empresa-usuario/$eu1/reactivate", [], $admin);
requestApi('POST', "/api/empresa-usuario/$eu3/reactivate", [], $admin);

/* ------------------------------------------------------------------ 6. Contenido de la bitácora */
$all = json_encode($pdo->query("SELECT before_json, after_json FROM audit_log WHERE entity IN ('empresa_usuario','empresa_reporte','user_empresa_reporte')")->fetchAll());
expectCase(strpos($all, 'notas') === false && strpos($all, 'nota acceso') === false && strpos($all, 'password') === false && strpos($all, 'powerbi') === false, 'audit content: no notes, passwords or Power BI data in any access audit row');
$bad = 0;
foreach ($pdo->query("SELECT action, before_json, after_json FROM audit_log WHERE entity IN ('empresa_usuario','empresa_reporte','user_empresa_reporte')")->fetchAll() as $r) {
    foreach (['before_json', 'after_json'] as $c) {
        if ($r[$c] === null) continue;
        $keys = sortedKeys(json_decode($r[$c], true));
        if (!in_array($keys, [['activo', 'empresa_id', 'user_id'], ['activo', 'empresa_id', 'reporte_id'], ['activo', 'empresa_reporte_id', 'user_id']], true)) $bad++;
    }
}
expectCase($bad === 0, 'audit content: every snapshot has exactly the whitelisted keys (ids and activo)');
expectCase(count_of($pdo, 'audit_log', "action NOT REGEXP '^(empresa_usuario|empresa_reporte|user_empresa_reporte)\\\\.(create|reactivate|revoke|update)$' AND entity IN ('empresa_usuario','empresa_reporte','user_empresa_reporte')") === 0, 'audit content: actions are only <entity>.create|reactivate|revoke|update');

/* ------------------------------------------------------------------ 7. Historial GET /api/auditoria */
[$s, $d] = requestApi('GET', '/api/auditoria?entity=access&per_page=100', null, $admin);
$rows = $d['data'] ?? [];
$expectedKeys = ['action', 'actor', 'actor_user_id', 'after', 'before', 'correlation_id', 'entity', 'entity_id', 'id', 'occurred_at'];
$sortedDesc = true; $onlyAccess = true; $keysOk = true;
foreach ($rows as $i => $r) {
    if ($i > 0 && ($rows[$i - 1]['occurred_at'] < $r['occurred_at'] || ($rows[$i - 1]['occurred_at'] === $r['occurred_at'] && $rows[$i - 1]['id'] < $r['id']))) $sortedDesc = false;
    if (!in_array($r['entity'], ['empresa_usuario', 'empresa_reporte', 'user_empresa_reporte'], true)) $onlyAccess = false;
    if (sortedKeys($r) !== $expectedKeys) $keysOk = false;
}
$accessTotal = count_of($pdo, 'audit_log', "entity IN ('empresa_usuario','empresa_reporte','user_empresa_reporte')");
expectCase($s === 200 && ($d['success'] ?? false) === true && ($d['audit_available'] ?? null) === true && count($rows) === min(100, $accessTotal) && ($d['pagination']['total'] ?? -1) === $accessTotal, 'history: an admin sees the access entries (alias access), with audit_available:true and the real total');
expectCase($sortedDesc && $onlyAccess && $keysOk, 'history: newest first, only the three access entities, rows carry exactly the documented fields');
expectCase(strpos(json_encode($rows), 'request_ip') === false && strpos(json_encode($rows), '127.0.0.1') === false, 'history: request_ip is never returned');
expectCase(($rows[0]['actor'] ?? '') === 'Demo Admin' && is_int($rows[0]['actor_user_id']) && is_int($rows[0]['entity_id']) && is_int($rows[0]['id']), 'history: actor is the full name of the administrator and ids are integers');
$first = null;
foreach ($rows as $r) if ($r['action'] === 'empresa_usuario.create' && $r['entity_id'] === $eu1) $first = $r;
expectCase($first !== null && $first['before'] === null && eqk($first['after'], ['user_id' => 1001, 'empresa_id' => 1001, 'activo' => 1]), 'history: before is null and after is a decoded object for a creation');
[$s, $d] = requestApi('GET', "/api/auditoria?entity=empresa_usuario&entity_id=$eu2&per_page=100", null, $admin);
$dbCount = count_of($pdo, 'audit_log', "entity = 'empresa_usuario' AND entity_id = $eu2");
expectCase($s === 200 && count($d['data']) === $dbCount && $dbCount >= 3 && count(array_unique(array_column($d['data'], 'entity_id'))) === 1 && array_unique(array_column($d['data'], 'entity')) === ['empresa_usuario'], 'history: filters by entity and entity_id');
$corr = $first['correlation_id'] ?? '';
[$s, $d] = requestApi('GET', "/api/auditoria?correlation_id=$corr", null, $admin);
expectCase($s === 200 && count($d['data']) >= 1 && array_unique(array_column($d['data'], 'correlation_id')) === [$corr] && count($d['data']) === count_of($pdo, 'audit_log', 'correlation_id = ?', [$corr]), 'history: filters by correlation id');
[$s, $d] = requestApi('GET', '/api/auditoria?entity=access&per_page=3&page=1', null, $admin);
[$s2, $d2] = requestApi('GET', '/api/auditoria?entity=access&per_page=3&page=2', null, $admin);
expectCase($s === 200 && count($d['data']) === 3 && ($d['pagination']['per_page'] ?? 0) === 3 && ($d['pagination']['has_more'] ?? false) === true && ($d['pagination']['total_pages'] ?? 0) === (int)ceil($accessTotal / 3)
    && $s2 === 200 && array_intersect(array_column($d['data'], 'id'), array_column($d2['data'], 'id')) === [] && max(array_column($d2['data'], 'id')) < max(array_column($d['data'], 'id')) + 100000, 'history: pagination (page/per_page) returns disjoint, consistent pages with totals');
[$s, $d] = requestApi('GET', '/api/auditoria?entity=empresa', null, $admin);
expectCase($s === 200 && $d['data'] === [] && $d['audit_available'] === true, 'history: an entity with no entries returns an empty list');
foreach (['entity=foo', 'entity[]=empresa', 'entity_id=abc', 'entity_id=0', 'entity_id=-3', 'entity_id=1.5', 'entity_id[]=1', 'correlation_id=zz', 'correlation_id=' . str_repeat('g', 32), 'per_page=0', 'per_page=101', 'page=0', 'entity=empresa%27%20OR%201=1'] as $qs) {
    [$s, $d, $raw] = requestApi('GET', '/api/auditoria?' . $qs, null, $admin);
    expectCase($s === 400 && ($d['success'] ?? true) === false && stripos($raw, 'SQLSTATE') === false, "history: invalid filter '$qs' is rejected with 400");
}
[$s] = requestApi('GET', '/api/auditoria?entity=access', null, $worker);
expectCase($s === 403, 'history: a worker gets 403');
[$s] = requestApi('GET', '/api/auditoria?role=admin&user_id=1&entity=access', null, $worker);
expectCase($s === 403, 'history: role and user_id query parameters do not elevate a worker');
[$s] = requestApi('GET', '/api/auditoria?entity=access');
expectCase($s === 401, 'history: an anonymous caller gets 401');
$parts = explode('.', $worker);
$payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
$payload['role'] = 'admin';
$forged = $parts[0] . '.' . rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=') . '.' . $parts[2];
[$s] = requestApi('GET', '/api/auditoria?entity=access', null, $forged);
expectCase($s === 401, 'history: a token whose role claim was tampered with is rejected (401)');
[$s] = requestApi('POST', '/api/auditoria', [], $admin);
expectCase($s === 404, 'history: the endpoint is read-only (POST is not routed)');

/* ------------------------------------------------------------------ 8. Autorización de todas las escrituras */
$auditBefore = count_of($pdo, 'audit_log');
$state = totals($pdo);
$activeBefore = [count_of($pdo, 'empresa_usuario', 'activo = 1'), count_of($pdo, 'empresa_reporte', 'activo = 1'), count_of($pdo, 'user_empresa_reporte', 'activo = 1')];
$writes = [
    ['POST', '/api/empresa-usuario', ['user_id' => 1004, 'empresa_id' => 1001]], ['PUT', "/api/empresa-usuario/$eu1", ['notas' => 'x']], ['DELETE', "/api/empresa-usuario/$eu1", null], ['POST', "/api/empresa-usuario/$eu1/reactivate", []],
    ['POST', '/api/asignacion', ['empresa_id' => 1002, 'reporte_id' => 1001]], ['PUT', "/api/asignacion/$er1", ['empresa_id' => 1001, 'reporte_id' => 1001]], ['DELETE', "/api/asignacion/$er1", null], ['POST', "/api/asignacion/$er1/reactivate", []],
    ['POST', '/api/empresa-reporte-usuario', ['user_id' => 1004, 'empresa_reporte_id' => $er1]], ['PUT', "/api/empresa-reporte-usuario/$uer1", ['notas' => 'x']], ['DELETE', "/api/empresa-reporte-usuario/$uer1", null], ['POST', "/api/empresa-reporte-usuario/$uer1/reactivate", []],
];
foreach ($writes as [$m, $p, $b]) {
    [$s] = requestApi($m, $p, $b, $worker);
    expectCase($s === 403, "authorization: a worker cannot $m $p");
    [$s] = requestApi($m, $p, $b);
    expectCase($s === 401, "authorization: an anonymous caller cannot $m $p");
    [$s] = requestApi($m, $p, $b, $forged);
    expectCase($s === 401, "authorization: a forged token cannot $m $p");
    [$s] = requestApi($m, $p . (strpos($p, '?') === false ? '?' : '&') . 'role=admin&user_id=1', $b, $worker);
    expectCase($s === 403, "authorization: role/user_id query parameters do not elevate a worker on $m $p");
}
expectCase(count_of($pdo, 'audit_log') === $auditBefore && totals($pdo) === $state
    && $activeBefore === [count_of($pdo, 'empresa_usuario', 'activo = 1'), count_of($pdo, 'empresa_reporte', 'activo = 1'), count_of($pdo, 'user_empresa_reporte', 'activo = 1')], 'authorization: rejected callers changed nothing and left no audit rows');
noShrink($pdo, $prev, 'final');

/* ------------------------------------------------------------------ 9. Reversión y limpieza */
$pdo->exec(file_get_contents('database/migrations/UX-08B-audit-log.down.sql'));
[$s, $d] = requestApi('GET', '/api/auditoria', null, $admin);
expectCase($s === 200 && ($d['audit_available'] ?? null) === false && ($d['data'] ?? null) === [], 'history: after the down migration the endpoint reports audit_available:false again');
cleanup($pdo);
expectCase(count_of($pdo, 'user', 'id >= 1000') === 0 && count_of($pdo, 'empresa_usuario', 'user_id >= 1000') === 0, 'the fixtures added by this suite were removed');

echo $failures === 0 ? "ALL PASSED\n" : "$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
