<?php
// UX-07A: administración de accesos (usuario ↔ empresa ↔ informe). Solo red Docker aislada, identidades ficticias y MySQL desechable.
// Se ejecuta DESPUÉS de http_test.php, la suite de paginación y http_auth_hardening_test.php.
$failures = 0;
$pdo = new PDO('mysql:host=db;dbname=fixture;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

function requestApi($method, $path, $body = null, $token = null) {
    $headers = ["Content-Type: application/json"];
    if ($token !== null) $headers[] = "Authorization: Bearer " . $token;
    $options = ['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 5]];
    if ($body !== null) $options['http']['content'] = json_encode($body);
    $raw = file_get_contents('http://app:8080' . $path, false, stream_context_create($options));
    $GLOBALS['lastResponse'] = substr($raw ?: '', 0, 300);
    $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int)$m[1] : 0;
    return [$status, json_decode($raw ?: '', true), $raw ?: ''];
}
function expectCase($condition, $label) {
    global $failures;
    if ($condition) echo "PASS $label\n"; else { echo "FAIL $label\n  última respuesta: " . ($GLOBALS['lastResponse'] ?? '') . "\n"; $failures++; }
}
function tokenOf($name, $password = 'synthetic-password') {
    [, $d] = requestApi('POST', '/api/login', ['login' => $name, 'password' => $password]);
    return $d['token'] ?? '';
}
function scalar($pdo, $sql, $params = []) { $st = $pdo->prepare($sql); $st->execute($params); return $st->fetchColumn(); }
function sortedKeys($row) { $k = array_keys($row); sort($k); return $k; }

$admin = tokenOf('admin');
$userA = tokenOf('usera');

// ---------- Alta (POST /api/register) con el fixture ----------
[$s, $d] = requestApi('POST', '/api/register', ['first_name' => 'Operador', 'last_name' => 'Prueba', 'username' => 'operador.ux07', 'email' => 'operador.ux07@example.invalid', 'password' => 'synthetic-password'], $admin);
expectCase($s === 200 && ($d['success'] ?? false) === true, 'admin creates the operator user through /api/register');
$uid = (int)scalar($pdo, "SELECT id FROM user WHERE username = 'operador.ux07'");
expectCase($uid > 0 && scalar($pdo, 'SELECT role FROM user WHERE id = ?', [$uid]) === 'user' && (int)scalar($pdo, 'SELECT is_active FROM user WHERE id = ?', [$uid]) === 1, 'the operator exists as an active user with role user');
[$s, $d] = requestApi('GET', '/api/users?status=all&per_page=100', null, $admin);
expectCase($s === 200 && in_array('operador.ux07', array_column($d['users'] ?? [], 'username'), true) && isset($d['pagination']['total']), '/api/users lists the new user with pagination metadata');

// ---------- Autorización: solo administradores escriben ----------
[$s] = requestApi('POST', '/api/empresa-usuario', ['user_id' => $uid, 'empresa_id' => 1], $userA);
expectCase($s === 403, 'non-admin cannot link a user to a company');
[$s] = requestApi('POST', '/api/empresa-reporte-usuario', ['user_id' => $uid, 'empresa_reporte_id' => 1], $userA);
expectCase($s === 403, 'non-admin cannot grant report access');
[$s] = requestApi('DELETE', '/api/empresa-reporte-usuario/1', null, $userA);
expectCase($s === 403, 'non-admin cannot revoke report access');
[$s] = requestApi('POST', '/api/empresa-usuario', ['user_id' => $uid, 'empresa_id' => 1]);
expectCase($s === 401, 'anonymous cannot write');

// ---------- Contratos de lectura: ids aditivos y vistas mínimas ----------
[$s, $d] = requestApi('GET', '/api/empresa-usuario?per_page=5', null, $admin);
$row = $d['data'][0] ?? [];
expectCase($s === 200 && isset($row['user_id'], $row['empresa_id'], $row['id']) && isset($row['usuario'], $row['empresa_nombre'], $row['empresa_ruc']), 'empresa-usuario keeps its historical fields and adds user_id and empresa_id');
[$s, $d] = requestApi('GET', '/api/empresa-usuario?view=minimal&per_page=5', null, $admin);
expectCase($s === 200 && sortedKeys($d['data'][0] ?? []) === ['activo', 'empresa_id', 'id', 'user_id'] && isset($d['pagination']), 'empresa-usuario view=minimal exposes only ids and state');
[$s, $d] = requestApi('GET', '/api/empresa-reporte-usuario?per_page=5', null, $admin);
$row = $d['data'][0] ?? [];
expectCase($s === 200 && isset($row['empresa_reporte_id'], $row['user_id'], $row['empresa_id'], $row['usuario'], $row['reporte_nombre'], $row['reporte_enlace']), 'empresa-reporte-usuario keeps its historical fields (including the legacy link) and adds ids');
[$s, $d, $raw] = requestApi('GET', '/api/empresa-reporte-usuario?view=minimal&per_page=5', null, $admin);
expectCase($s === 200 && sortedKeys($d['data'][0] ?? []) === ['activo', 'empresa_id', 'empresa_reporte_id', 'id', 'reporte_id', 'user_id'] && strpos($raw, 'example.invalid') === false && strpos($raw, 'enlace') === false, 'empresa-reporte-usuario view=minimal has no Power BI link, notes or creator data');
[$s] = requestApi('GET', '/api/empresa-reporte-usuario?view=full', null, $admin);
expectCase($s === 400, 'an unknown view is rejected');

// ---------- Relación usuario ↔ empresa ----------
$erId = (int)scalar($pdo, 'SELECT id FROM empresa_reporte WHERE empresa_id = 1 AND reporte_id = 1 AND activo = 1');
[$s, $d] = requestApi('POST', '/api/empresa-reporte-usuario', ['user_id' => $uid, 'empresa_reporte_id' => $erId], $admin);
expectCase(($d['success'] ?? true) === false && stripos($d['message'] ?? '', 'no está asignado a la empresa') !== false && (int)scalar($pdo, 'SELECT COUNT(*) FROM user_empresa_reporte WHERE user_id = ?', [$uid]) === 0, 'granting a report without the company relation is rejected and writes nothing');
[$s, $d] = requestApi('POST', '/api/empresa-usuario', ['user_id' => $uid, 'empresa_id' => 1], $admin);
expectCase(($d['success'] ?? false) === true, 'admin links the user to Empresa A');
[$s, $d] = requestApi('POST', '/api/empresa-usuario', ['user_id' => $uid, 'empresa_id' => 1], $admin);
expectCase(($d['success'] ?? true) === false && (int)scalar($pdo, 'SELECT COUNT(*) FROM empresa_usuario WHERE user_id = ? AND empresa_id = 1 AND activo = 1', [$uid]) === 1, 'the company relation is not duplicated');
$pdo->exec("INSERT INTO empresa (id, nombre, ruc, activo) VALUES (91, 'Empresa inactiva UX07', '00000000091', 0)");
[$s, $d] = requestApi('POST', '/api/empresa-usuario', ['user_id' => $uid, 'empresa_id' => 91], $admin);
expectCase(($d['success'] ?? true) === false && (int)scalar($pdo, 'SELECT COUNT(*) FROM empresa_usuario WHERE empresa_id = 91') === 0, 'an inactive company cannot receive users');
[$s, $d] = requestApi('POST', '/api/empresa-usuario', ['user_id' => 4, 'empresa_id' => 1], $admin); // usuario 4: inactivo en el fixture
expectCase(($d['success'] ?? true) === false && (int)scalar($pdo, 'SELECT COUNT(*) FROM empresa_usuario WHERE user_id = 4 AND empresa_id = 1') === 0, 'an inactive user cannot be linked to a company');
[$s, $d] = requestApi('POST', '/api/empresa-usuario', ['user_id' => 987654, 'empresa_id' => 1], $admin);
expectCase(($d['success'] ?? true) === false, 'a non-existent user cannot be linked');

// ---------- Acceso a informes: los tres identificadores ----------
[$s, $d] = requestApi('POST', '/api/empresa-reporte-usuario', ['user_id' => $uid, 'empresa_reporte_id' => '00000000-0000-0000-0000-000000000011'], $admin);
expectCase(($d['success'] ?? true) === false && (int)scalar($pdo, 'SELECT COUNT(*) FROM user_empresa_reporte WHERE user_id = ?', [$uid]) === 0, 'the Power BI UUID is not accepted where the empresa_reporte id is required');
[$s, $d] = requestApi('POST', '/api/empresa-reporte-usuario', ['user_id' => $uid, 'empresa_reporte_id' => 987654], $admin);
expectCase(($d['success'] ?? true) === false, 'a non-existent empresa_reporte relation is rejected');
$inactiveEr = (int)scalar($pdo, 'SELECT id FROM empresa_reporte WHERE activo = 0 LIMIT 1');
[$s, $d] = requestApi('POST', '/api/empresa-reporte-usuario', ['user_id' => $uid, 'empresa_reporte_id' => $inactiveEr], $admin);
expectCase($inactiveEr > 0 && ($d['success'] ?? true) === false && (int)scalar($pdo, 'SELECT COUNT(*) FROM user_empresa_reporte WHERE user_id = ?', [$uid]) === 0, 'an inactive empresa_reporte relation is rejected');
[$s, $d] = requestApi('POST', '/api/empresa-reporte-usuario', ['user_id' => 4, 'empresa_reporte_id' => $erId], $admin);
expectCase(($d['success'] ?? true) === false, 'an inactive user cannot receive report access');

[$s, $d] = requestApi('POST', '/api/empresa-reporte-usuario', ['user_id' => $uid, 'empresa_reporte_id' => $erId], $admin);
expectCase(($d['success'] ?? false) === true && (int)($d['InsertedId'] ?? 0) > 0, 'admin grants Reporte A through Empresa A');
$accessId = (int)($d['InsertedId'] ?? 0);
[$s, $d] = requestApi('POST', '/api/empresa-reporte-usuario', ['user_id' => $uid, 'empresa_reporte_id' => $erId], $admin);
expectCase(($d['success'] ?? true) === false && stripos($d['message'] ?? '', 'ya existe') !== false && (int)scalar($pdo, 'SELECT COUNT(*) FROM user_empresa_reporte WHERE user_id = ? AND empresa_reporte_id = ?', [$uid, $erId]) === 1, 'the access is not duplicated');

// ---------- Efecto real para el usuario ----------
$operator = tokenOf('operador.ux07');
expectCase($operator !== '', 'the created user can log in');
[$s, $d] = requestApi('GET', '/api/reportes?view=minimal&per_page=100', null, $operator);
expectCase($s === 200 && array_column($d['data'] ?? [], 'nombre') === ['Reporte A'], 'the operator sees only the report granted to them');
$otherUuid = scalar($pdo, "SELECT reporte_id FROM reporte WHERE nombre = 'Reporte B'");
[$s] = requestApi('GET', '/api/reportes/reporteid/' . $otherUuid . '?view=minimal', null, $operator);
expectCase($s === 404 || $s === 403, "a report of another company is not accessible to the operator ($s)");
[$s, $d] = requestApi('GET', '/api/empresa-reporte-usuario/user/' . $uid . '?per_page=10', null, $admin);
expectCase($s === 200 && count($d['data'] ?? []) === 1 && (int)$d['data'][0]['id'] === $accessId, 'the admin sees the access in the per-user list');
[$s] = requestApi('GET', '/api/users', null, $operator);
expectCase($s === 403, 'the operator has no administrative access');

// ---------- Retirada (baja lógica) y reactivación ----------
[$s, $d] = requestApi('DELETE', '/api/empresa-reporte-usuario/' . $accessId, null, $admin);
expectCase(($d['success'] ?? false) === true && (int)scalar($pdo, 'SELECT activo FROM user_empresa_reporte WHERE id = ?', [$accessId]) === 0 && (int)scalar($pdo, 'SELECT COUNT(*) FROM user_empresa_reporte WHERE id = ?', [$accessId]) === 1, 'revoking is a soft delete (the row is kept, activo = 0)');
[$s, $d] = requestApi('DELETE', '/api/empresa-reporte-usuario/' . $accessId, null, $admin);
expectCase(($d['success'] ?? true) === false, 'revoking an already revoked access reports failure');
[$s, $d] = requestApi('GET', '/api/reportes?view=minimal&per_page=100', null, $operator);
expectCase($s === 200 && count($d['data'] ?? []) === 0, 'after revoking, the operator no longer sees the report');
[$s] = requestApi('GET', '/api/reportes/reporteid/' . scalar($pdo, "SELECT reporte_id FROM reporte WHERE nombre = 'Reporte A'") . '?view=minimal', null, $operator);
expectCase($s === 404 || $s === 403, "after revoking, the report cannot be opened ($s)");
[$s, $d] = requestApi('POST', '/api/empresa-reporte-usuario', ['user_id' => $uid, 'empresa_reporte_id' => $erId], $admin);
expectCase(($d['success'] ?? false) === true && (int)($d['InsertedId'] ?? 0) === $accessId && (int)scalar($pdo, 'SELECT COUNT(*) FROM user_empresa_reporte WHERE user_id = ? AND empresa_reporte_id = ?', [$uid, $erId]) === 1 && (int)scalar($pdo, 'SELECT activo FROM user_empresa_reporte WHERE id = ?', [$accessId]) === 1, 'granting again reactivates the revoked row instead of duplicating it');
[$s, $d] = requestApi('GET', '/api/reportes?view=minimal&per_page=100', null, $operator);
expectCase(array_column($d['data'] ?? [], 'nombre') === ['Reporte A'], 'after re-granting, the operator sees the report again');

// ---------- Sin filtraciones técnicas en los errores nuevos ----------
$pdo->exec('RENAME TABLE `empresa_usuario` TO `empresa_usuario__off`');
[, , $raw] = requestApi('POST', '/api/empresa-usuario', ['user_id' => $uid, 'empresa_id' => 1], $admin);
[, , $raw2] = requestApi('GET', '/api/empresa-usuario?view=minimal', null, $admin);
$pdo->exec('RENAME TABLE `empresa_usuario__off` TO `empresa_usuario`');
expectCase(!preg_match('/SQLSTATE|PDOException|doesn.t exist|Base table|\.php|\bSELECT\b|fixture/i', $raw . $raw2), 'database failures on the new paths reveal no internal detail');

echo $failures === 0 ? "\nAll admin access cases passed\n" : "\n$failures admin access case(s) failed\n";
exit($failures === 0 ? 0 : 1);
