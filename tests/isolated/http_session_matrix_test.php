<?php
// UX-08A: matriz negativa de sesión y autorización contra la instancia `appw` (base `fixture_w`).
// Fabrica tokens HS256 con el MISMO secreto de la aplicación (variable JWT_SECRET del contenedor de pruebas) y la misma estructura de
// claims que emite el login. Solo red Docker aislada, identidades ficticias y MySQL desechable. Usa ids >= 2000 propios y los elimina.
$failures = 0;
$pdo = new PDO('mysql:host=db;dbname=fixture_w;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
const BASE = 'http://appw:8080';
$secret = getenv('JWT_SECRET');
if (!$secret) { echo "FAIL JWT_SECRET must be passed to the test container (--env JWT_SECRET)\n"; exit(1); }
require 'backend/lib/jwt.php';

function requestApi($method, $path, $body = null, $token = null) {
    $headers = ['Content-Type: application/json'];
    if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
    $options = ['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 10]];
    if ($body !== null) $options['http']['content'] = json_encode($body);
    $raw = file_get_contents(BASE . $path, false, stream_context_create($options));
    $GLOBALS['lastResponse'] = substr($raw ?: '', 0, 300);
    $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int)$m[1] : 0;
    return [$status, json_decode($raw ?: '', true), $raw ?: ''];
}
function expectCase($condition, $label) {
    global $failures;
    if ($condition) echo "PASS $label\n"; else { echo "FAIL $label\n  última respuesta: " . ($GLOBALS['lastResponse'] ?? '') . "\n"; $failures++; }
}
function login($name) { return requestApi('POST', '/api/login', ['login' => $name, 'password' => 'synthetic-password']); }
function dbRow($pdo, $id) { $st = $pdo->prepare('SELECT * FROM user WHERE id = ?'); $st->execute([$id]); return $st->fetch(); }
/** Token válido (mismos claims que el login) para la fila actual del usuario; `$over` sustituye/quita claims. */
function forge($pdo, $secret, $id, array $over = [], $signWith = null) {
    $u = dbRow($pdo, $id);
    $payload = array_merge(['user_id' => (int)$u['id'], 'username' => $u['username'], 'email' => $u['email'], 'role' => $u['role'], 'session_version' => (int)$u['session_version'], 'exp' => time() + 600], $over);
    foreach ($over as $k => $v) if ($v === null) unset($payload[$k]);
    return generate_jwt($payload, $signWith ?? $secret);
}
/** Sondas: una ruta autenticada cualquiera y una ruta solo para administradores. */
function probeAuth($token) { return requestApi('GET', '/api/empresas?per_page=1', null, $token)[0]; }
function probeAdmin($token) { return requestApi('GET', '/api/auditoria?per_page=1', null, $token)[0]; }
function b64($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }

$pdo->exec('DELETE FROM user WHERE id >= 2000');
$hash = password_hash('synthetic-password', PASSWORD_DEFAULT);
$ins = $pdo->prepare('INSERT INTO user (id, username, email, password, first_name, last_name, role, is_active, session_version) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)');
foreach ([[2001, 'sm_active', 'user', 1], [2002, 'sm_inactive', 'user', 0], [2003, 'sm_deact', 'user', 1], [2004, 'sm_oldver', 'user', 1], [2005, 'sm_legacy', 'user', 1],
          [2006, 'sm_rolechange', 'admin', 1], [2007, 'sm_badrole', 'user', 1], [2008, 'sm_tamper', 'user', 1], [2009, 'sm_logout', 'user', 1], [2010, 'sm_admin', 'admin', 1]] as [$id, $name, $role, $active]) {
    $ins->execute([$id, $name, "$name@example.invalid", $hash, 'Sm', ucfirst($name), $role, $active]);
}

// (1) usuario inactivo no puede iniciar sesión
[$s, $d] = login('sm_inactive');
expectCase(($d['success'] ?? true) === false && empty($d['token']), '(1) an inactive user cannot log in (no token issued)');
// (2) usuario activo recibe sesión
[$s, $d] = login('sm_active');
$t = $d['token'] ?? '';
expectCase($s === 200 && ($d['success'] ?? false) === true && $t !== '' && probeAuth($t) === 200, '(2) an active user gets a session and the token works');

// (3) token de un usuario que luego se desactiva
$t = login('sm_deact')[1]['token'] ?? '';
$before = probeAuth($t);
$pdo->exec('UPDATE user SET is_active = 0 WHERE id = 2003');
expectCase($before === 200 && probeAuth($t) === 401, '(3) the token of a user deactivated afterwards is rejected (401)');
$pdo->exec('UPDATE user SET is_active = 1 WHERE id = 2003');
expectCase(probeAuth($t) === 200, '(3b) reactivating the user (same session_version) makes the same token valid again');

// (4) session_version antigua
$old = forge($pdo, $secret, 2004);
expectCase(probeAuth($old) === 200, '(4a) a token with the current session_version works');
$pdo->exec('UPDATE user SET session_version = session_version + 1 WHERE id = 2004');
expectCase(probeAuth($old) === 401, '(4) a token with an OLD session_version is rejected (401)');
expectCase(probeAuth(forge($pdo, $secret, 2004, ['session_version' => 999])) === 401, '(4b) a token with a FUTURE session_version is rejected (401)');
expectCase(probeAuth(forge($pdo, $secret, 2004)) === 200, '(4c) a token with the current session_version is accepted');

// (5) token heredado sin session_version (bien firmado)
expectCase(probeAuth(forge($pdo, $secret, 2005, ['session_version' => null])) === 401, '(5) a validly signed LEGACY token without session_version is rejected (401)');
expectCase(probeAuth(forge($pdo, $secret, 2005, ['session_version' => '1'])) === 401, '(5b) a token whose session_version is a string is rejected (401)');
expectCase(probeAuth(forge($pdo, $secret, 2005, ['user_id' => null])) === 401, '(5c) a token without user_id is rejected (401)');
expectCase(probeAuth(forge($pdo, $secret, 2005, ['exp' => time() - 10])) === 401, '(5d) an expired token is rejected (401)');
expectCase(probeAuth(forge($pdo, $secret, 2005, ['exp' => null])) === 401, '(5e) a token without expiry is rejected (401)');

// (6) cambio de rol en MySQL: un token de administrador antiguo pierde el poder de inmediato
$t = forge($pdo, $secret, 2006);
expectCase(probeAdmin($t) === 200, '(6a) while the user is an admin in MySQL the admin route works');
$pdo->exec("UPDATE user SET role = 'user' WHERE id = 2006");
expectCase(probeAdmin($t) === 403 && probeAuth($t) === 200, '(6) after the role change in MySQL the old admin token loses admin power at once (403) but is still a valid session');
[$s] = requestApi('POST', '/api/empresa-usuario', ['user_id' => 2001, 'empresa_id' => 1], $t);
expectCase($s === 403, '(6b) the demoted user cannot use an admin write either');
$pdo->exec("UPDATE user SET role = 'admin' WHERE id = 2006");
expectCase(probeAdmin($t) === 200, '(6c) promoting the user in MySQL takes effect on the very next request');
// el claim del token no concede nada
$tUser = forge($pdo, $secret, 2008, ['role' => 'admin']);
expectCase(probeAdmin($tUser) === 403, '(6d) a validly signed token that CLAIMS role admin for a non-admin user gets no admin power (role comes from MySQL)');

// (7) rol inesperado en MySQL
foreach (['superuser', 'root', ''] as $bad) {
    $pdo->prepare('UPDATE user SET role = ? WHERE id = 2007')->execute([$bad]);
    $t = forge($pdo, $secret, 2007, ['role' => 'user']);
    expectCase(probeAuth($t) === 401 && probeAdmin($t) === 401, "(7) an unexpected role '$bad' in MySQL is rejected (401), even with a role:user claim");
    [$s, $d] = login('sm_badrole');
    expectCase(($d['success'] ?? true) === false && empty($d['token']), "(7b) a user with the unexpected role '$bad' cannot log in");
}
$pdo->exec("UPDATE user SET role = 'usuario' WHERE id = 2007");
expectCase(probeAuth(forge($pdo, $secret, 2007)) === 200 && probeAdmin(forge($pdo, $secret, 2007)) === 403, "(7c) the legacy role 'usuario' is a basic user (200 / no admin power)");

// (8) firma o claims manipulados
$good = forge($pdo, $secret, 2008);
[$h, $p, $sig] = explode('.', $good);
$flip = $sig[0] === 'A' ? 'B' : 'A';
expectCase(probeAuth($good) === 200, '(8a) the untouched token works');
expectCase(probeAuth("$h.$p." . $flip . substr($sig, 1)) === 401, '(8) a token with a tampered signature is rejected (401)');
$claims = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
$claims['role'] = 'admin';
expectCase(probeAuth("$h." . b64(json_encode($claims)) . ".$sig") === 401, '(8b) a token with a tampered role claim (not re-signed) is rejected (401)');
$claims = json_decode(base64_decode(strtr($p, '-_', '+/')), true);
$claims['user_id'] = 2010;
expectCase(probeAdmin("$h." . b64(json_encode($claims)) . ".$sig") === 401, '(8c) a token with another user\'s id (not re-signed) is rejected (401)');
expectCase(probeAuth(forge($pdo, $secret, 2008, [], 'another-secret-value')) === 401, '(8d) a token signed with a different secret is rejected (401)');
expectCase(probeAuth("$h.$p") === 401 && probeAuth("$h.$p.$sig.extra") === 401 && probeAuth('not-a-jwt') === 401 && probeAuth('') === 401, '(8e) malformed tokens (wrong number of parts, garbage, empty) are rejected (401)');

// (9) alg:none y variantes
$payload = b64(json_encode(['user_id' => 2010, 'username' => 'sm_admin', 'email' => 'sm_admin@example.invalid', 'role' => 'admin', 'session_version' => 1, 'exp' => time() + 600]));
$none = b64(json_encode(['alg' => 'none', 'typ' => 'JWT']));
expectCase(probeAdmin("$none.$payload.") === 401, '(9) an alg:none token with an empty signature is rejected (401)');
expectCase(probeAdmin("$none.$payload.$sig") === 401, '(9b) an alg:none token carrying a signature is rejected (401)');
$none2 = b64(json_encode(['alg' => 'None', 'typ' => 'JWT']));
expectCase(probeAdmin("$none2.$payload.") === 401, '(9c) an alg:None (case variant) token is rejected (401)');
$hs512 = b64(json_encode(['alg' => 'HS512', 'typ' => 'JWT']));
expectCase(probeAdmin("$hs512.$payload." . b64(hash_hmac('sha512', "$hs512.$payload", $secret, true))) === 401, '(9d) a correctly signed HS512 token (algorithm confusion) is rejected (401)');

// (10) logout y reutilización
[$s, $d] = login('sm_logout');
$t = $d['token'] ?? '';
expectCase(probeAuth($t) === 200, '(10a) the token works before logout');
[$s, $d] = requestApi('POST', '/api/logout', [], $t);
expectCase($s === 200 && ($d['success'] ?? false) === true, '(10b) logout succeeds');
expectCase(probeAuth($t) === 401, '(10) the same token is rejected after logout (401)');
[$s] = requestApi('POST', '/api/logout', [], $t);
expectCase($s === 401, '(10c) a second logout with the same token is rejected (401)');
$t2 = login('sm_logout')[1]['token'] ?? '';
expectCase($t2 !== '' && $t2 !== $t && probeAuth($t2) === 200, '(10d) a new login after logout issues a working token with the new session_version');

// (11) todas las rutas de administración nuevas: trabajador 403, anónimo 401
$worker = login('usera')[1]['token'] ?? '';
$routes = [['POST', '/api/empresa-usuario/1/reactivate', []], ['POST', '/api/asignacion/1/reactivate', []], ['POST', '/api/empresa-reporte-usuario/1/reactivate', []],
           ['GET', '/api/auditoria', null], ['GET', '/api/auditoria?entity=access&entity_id=1&correlation_id=' . str_repeat('a', 32), null],
           ['POST', '/api/empresa-usuario', ['user_id' => 2001, 'empresa_id' => 1]], ['PUT', '/api/empresa-usuario/1', ['notas' => 'x']], ['DELETE', '/api/empresa-usuario/1', null],
           ['POST', '/api/asignacion', ['empresa_id' => 1, 'reporte_id' => 1]], ['PUT', '/api/asignacion/1', ['empresa_id' => 1, 'reporte_id' => 1]], ['DELETE', '/api/asignacion/1', null],
           ['POST', '/api/empresa-reporte-usuario', ['user_id' => 2001, 'empresa_reporte_id' => 1]], ['PUT', '/api/empresa-reporte-usuario/1', ['notas' => 'x']], ['DELETE', '/api/empresa-reporte-usuario/1', null]];
function count_of_rows($pdo) { return [(int)$pdo->query('SELECT COUNT(*) FROM empresa_usuario WHERE activo = 1')->fetchColumn(), (int)$pdo->query('SELECT COUNT(*) FROM empresa_reporte WHERE activo = 1')->fetchColumn(), (int)$pdo->query('SELECT COUNT(*) FROM user_empresa_reporte WHERE activo = 1')->fetchColumn()]; }
$stateBefore = count_of_rows($pdo);
foreach ($routes as [$m, $p, $b]) {
    [$s] = requestApi($m, $p, $b, $worker);
    expectCase($s === 403, "(11) a worker token gets 403 on $m $p");
    [$s] = requestApi($m, $p, $b);
    expectCase($s === 401, "(11) an anonymous call gets 401 on $m $p");
}
expectCase(count_of_rows($pdo) === $stateBefore, '(11z) none of the rejected calls changed any relation');

$pdo->exec('DELETE FROM user WHERE id >= 2000');
echo $failures === 0 ? "ALL PASSED\n" : "$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
