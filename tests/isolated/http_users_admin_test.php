<?php
// UX-08A.users: administración de usuarios (alta, edición, desactivar/reactivar, rol, contraseña, accesos), contra dos instancias
// sobre la misma base `fixture_u` (ver seed_users.php):
//   `appu`    USER_ADMIN_WRITES=1, AUDIT_LOG_MODE=required, REACT_STRICT_VIEWS=1
//   `appuoff` sin USER_ADMIN_WRITES, AUDIT_LOG_MODE=required
// Solo red Docker aislada, identidades ficticias y MySQL desechable. Requiere JWT_SECRET (para fabricar tokens heredados) y TEST_DB_PASS.
$failures = 0;
$pdo = new PDO('mysql:host=db;dbname=fixture_u;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
const BASE = 'http://appu:8080';
const BASE_OFF = 'http://appuoff:8080';
$secret = getenv('JWT_SECRET');
if (!$secret) { echo "FAIL JWT_SECRET must be passed to the test container (--env JWT_SECRET)\n"; exit(1); }
require 'backend/lib/jwt.php';
require_once 'backend/lib/write_support.php'; // row_version() / FORM_VERSION_FIELDS: la misma huella que calcula el servidor

$GLOBALS['hashSeen'] = false;
function requestApi($method, $path, $body = null, $token = null, $strict = false, $base = BASE) {
    $headers = ['Content-Type: application/json'];
    if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
    if ($strict) $headers[] = 'X-API-Contract: 2';
    $options = ['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 15]];
    if ($body !== null) $options['http']['content'] = json_encode($body);
    $raw = file_get_contents($base . $path, false, stream_context_create($options));
    $GLOBALS['lastResponse'] = substr($raw ?: '', 0, 400);
    if (strpos($raw ?: '', '$2y$') !== false) $GLOBALS['hashSeen'] = true;
    $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int)$m[1] : 0;
    return [$status, json_decode($raw ?: '', true), $raw ?: ''];
}
/** Peticiones simultáneas reales (curl_multi), cada una con su propio token. Devuelve [[status, json], …] en el mismo orden. */
function parallel(array $calls) {
    $multi = curl_multi_init();
    $handles = [];
    foreach ($calls as $i => [$method, $path, $body, $token]) {
        $ch = curl_init(BASE . $path);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CUSTOMREQUEST => $method, CURLOPT_TIMEOUT => 30,
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
function loginAs($name, $password = 'synthetic-password') { return requestApi('POST', '/api/login', ['login' => $name, 'password' => $password]); }
function tokenOf($name) { return loginAs($name)[1]['token'] ?? ''; }
function one($pdo, $sql, $params = []) { $st = $pdo->prepare($sql); $st->execute($params); return $st->fetch(); }
function dbUser($pdo, $id) { return one($pdo, 'SELECT * FROM user WHERE id = ?', [$id]); }
function userCount($pdo) { return (int)one($pdo, 'SELECT COUNT(*) c FROM user')['c']; }
function lastAudit($pdo, $action, $entityId) { return one($pdo, 'SELECT * FROM audit_log WHERE action = ? AND entity_id = ? ORDER BY id DESC LIMIT 1', [$action, $entityId]); }
function auditCount($pdo, $action, $entityId) { return (int)one($pdo, 'SELECT COUNT(*) c FROM audit_log WHERE action = ? AND entity_id = ?', [$action, $entityId])['c']; }
function insertUser($pdo, $name, $role = 'user', $active = 1) {
    $st = $pdo->prepare('INSERT INTO user (username, email, password, first_name, last_name, phone, role, is_active, session_version) VALUES (?,?,?,?,?,?,?,?,1)');
    $st->execute([$name, "$name@example.invalid", password_hash('synthetic-password', PASSWORD_DEFAULT), 'Demo', ucfirst($name), '555-0199', $role, $active]);
    return (int)$pdo->lastInsertId();
}
function probeToken($token) { return requestApi('GET', '/api/user/profile', null, $token)[0]; }
function sv($pdo, $id) { return (int)dbUser($pdo, $id)['session_version']; }
function versionOf($pdo, $id) { return row_version(dbUser($pdo, $id), FORM_VERSION_FIELDS['user_form']); }
function forge($pdo, $secret, $id, array $over = [], $signWith = null) {
    $u = dbUser($pdo, $id);
    $payload = array_merge(['user_id' => (int)$u['id'], 'username' => $u['username'], 'email' => $u['email'], 'role' => $u['role'], 'session_version' => (int)$u['session_version'], 'exp' => time() + 600], $over);
    foreach ($over as $k => $v) if ($v === null) unset($payload[$k]);
    return generate_jwt($payload, $signWith ?? $secret);
}

// ---------- 0. Preparación: migración real de la bitácora (idempotente) y sesiones ----------
$pdo->exec(file_get_contents('database/migrations/UX-08B-audit-log.up.sql'));
$admin = tokenOf('admin');
$admin2 = tokenOf('admin2');
$worker = tokenOf('usera');
$usersAtStart = userCount($pdo);
expectCase($admin !== '' && $admin2 !== '' && $worker !== '', '0. fixture_u sessions (two administrators and one worker) are available');

$writeRoutes = fn ($id) => [['PUT', "/api/users/$id", ['first_name' => 'X']], ['POST', "/api/users/$id/deactivate", []], ['POST', "/api/users/$id/reactivate", []], ['PUT', "/api/users/$id/password", ['password' => 'a-very-long-password']]];
$readRoutes = fn ($id) => [['GET', '/api/users/capabilities', null], ['GET', "/api/users/$id", null], ['GET', "/api/users/$id/access", null]];

// ---------- 1. Interruptor USER_ADMIN_WRITES ----------
foreach ($writeRoutes(3) as [$m, $p, $b]) {
    [$s, $d] = requestApi($m, $p, $b, $admin, false, BASE_OFF);
    expectCase($s === 404 && ($d['message'] ?? '') === 'No encontrado' && ($d['success'] ?? true) === false, "1. flag off: $m $p is HTTP 404 'No encontrado'");
}
[$s] = requestApi('POST', '/api/users/3/deactivate', [], null, false, BASE_OFF);
expectCase($s === 404 && (int)dbUser($pdo, 3)['is_active'] === 1, '1. flag off: even an anonymous write is 404 and nothing changed');
foreach ($readRoutes(3) as [$m, $p, $b]) {
    [$s, $d] = requestApi($m, $p, $b, $admin, false, BASE_OFF);
    expectCase($s === 200 && ($d['success'] ?? false) === true, "1. flag off: read $m $p still works");
}
[, $d] = requestApi('GET', '/api/users/capabilities', null, $admin, false, BASE_OFF);
expectCase(($d['data']['user_admin_writes'] ?? null) === false, '1. capabilities reports user_admin_writes=false when the flag is off');
[, $d] = requestApi('GET', '/api/users/capabilities', null, $admin);
expectCase(($d['data']['user_admin_writes'] ?? null) === true, '1. capabilities reports user_admin_writes=true when USER_ADMIN_WRITES=1');
[$s, $d] = requestApi('POST', '/api/register', ['first_name' => 'Off', 'last_name' => 'Flag', 'username' => 'offflag', 'email' => 'offflag@example.invalid', 'password' => 'long-enough-password'], $admin, false, BASE_OFF);
expectCase($s === 200 && ($d['success'] ?? false) === true && one($pdo, "SELECT id FROM user WHERE username = 'offflag'"), '1. flag off: POST /api/register keeps working (create is not gated)');
[$s, $d] = requestApi('PUT', '/api/user/3/role', ['role' => 'user'], $admin, false, BASE_OFF);
expectCase($s === 200 && ($d['success'] ?? false) === true, '1. flag off: the existing role route stays reachable (hardened, not gated)');

// ---------- 2. Alta (POST /api/register) ----------
$new = ['first_name' => 'Nombre', 'last_name' => 'Apellido', 'username' => 'ucreate.ok', 'email' => 'ucreate.ok@example.invalid', 'phone' => '555-0123', 'password' => 'a-very-long-password'];
[$s, $d, $raw] = requestApi('POST', '/api/register', $new, $admin);
$row = one($pdo, "SELECT * FROM user WHERE username = 'ucreate.ok'");
expectCase($s === 200 && ($d['success'] ?? false) === true && ($d['message'] ?? '') === 'Usuario registrado exitosamente' && isset($d['id']), '2. valid create: 200 with the historical message and the new id');
expectCase($row && $row['role'] === 'user' && (int)$row['is_active'] === 1 && (int)$row['session_version'] === 1 && password_verify($new['password'], $row['password']), '2. created user has role user, is_active=1, session_version=1 and a verifiable password_hash');
$a = $row ? lastAudit($pdo, 'user.create', (int)$row['id']) : null;
expectCase($a && (int)$a['actor_user_id'] === 1 && json_decode($a['after_json'], true)['role'] === 'user', '2. create writes a user.create audit row in the same transaction');
expectCase($a && !preg_match('/ucreate|Nombre|Apellido|555-0123|a-very-long|\$2y\$/', $a['after_json'] . $a['before_json']) && isset(json_decode($a['after_json'], true)['email_sha256_12']), '2. the create audit holds no personal data, no password and no hash (only the email digest)');
$bad = fn (array $over) => requestApi('POST', '/api/register', $over + $new, $admin, true);
$count = userCount($pdo);
[$s, $d] = $bad(['username' => str_repeat('a', 51), 'email' => 'long51@example.invalid']);
expectCase($s === 400 && ($d['success'] ?? true) === false, '2. username of 51 characters is rejected (400 under contract 2)');
[$s, $d] = $bad(['username' => 'bad name!', 'email' => 'badname@example.invalid']);
expectCase($s === 400 && ($d['success'] ?? true) === false, '2. username with characters outside letters/digits/._- is rejected');
[$s] = $bad(['username' => str_repeat('a', 50), 'email' => 'max50@example.invalid']);
expectCase($s === 200, '2. username of exactly 50 characters is accepted');
[$s] = $bad(['username' => 'longmail', 'email' => str_repeat('m', 90) . '@example.invalid']);
expectCase($s === 400, '2. email longer than 100 characters is rejected');
[$s] = $bad(['username' => 'badmail', 'email' => 'not-an-email']);
expectCase($s === 400, '2. malformed email is rejected');
[$s] = $bad(['username' => 'pw11', 'email' => 'pw11@example.invalid', 'password' => str_repeat('x', 11)]);
expectCase($s === 400, '2. password of 11 bytes is rejected');
[$s] = $bad(['username' => 'pw73', 'email' => 'pw73@example.invalid', 'password' => str_repeat('x', 73)]);
expectCase($s === 400, '2. password of 73 bytes is rejected (bcrypt limit)');
[$s] = $bad(['username' => 'pwmb', 'email' => 'pwmb@example.invalid', 'password' => str_repeat('ñ', 37)]);
expectCase($s === 400, '2. password of 37 characters but 74 BYTES is rejected (limit is bytes)');
[$s] = $bad(['username' => 'pw72', 'email' => 'pw72@example.invalid', 'password' => str_repeat('x', 72)]);
expectCase($s === 200, '2. password of exactly 72 bytes is accepted');
[$s] = $bad(['username' => 'pw12', 'email' => 'pw12@example.invalid', 'password' => str_repeat('x', 12)]);
expectCase($s === 200, '2. password of exactly 12 bytes is accepted');
[$s] = $bad(['username' => 'arrname', 'email' => 'arrname@example.invalid', 'first_name' => ['x']]);
expectCase($s === 400 && !one($pdo, "SELECT id FROM user WHERE username = 'arrname'"), '2. an array where a string is expected is rejected (400)');
[$s] = $bad(['username' => 'boolpw', 'email' => 'boolpw@example.invalid', 'password' => true]);
expectCase($s === 400 && !one($pdo, "SELECT id FROM user WHERE username = 'boolpw'"), '2. a JSON boolean password is rejected (400)');
[$s, $d] = requestApi('POST', '/api/register', ['username' => 'ucreate.ok', 'email' => 'other@example.invalid'] + $new, $admin);
expectCase($s === 200 && ($d['success'] ?? true) === false && strpos($d['message'] ?? '', 'usuario') !== false, '2. legacy contract: duplicate username is HTTP 200 + success:false');
[$s] = $bad(['email' => 'other@example.invalid']);
expectCase($s === 409, '2. contract 2: duplicate username is HTTP 409');
[$s, $d] = requestApi('POST', '/api/register', ['username' => 'otheruser'] + $new, $admin);
expectCase($s === 200 && ($d['success'] ?? true) === false && strpos($d['message'] ?? '', 'email') !== false, '2. legacy contract: duplicate email is HTTP 200 + success:false');
[$s] = $bad(['username' => 'otheruser']);
expectCase($s === 409, '2. contract 2: duplicate email is HTTP 409');
expectCase(userCount($pdo) === $count + 3 && !one($pdo, "SELECT id FROM user WHERE username IN ('otheruser','pw11','pw73','pwmb')"), '2. rejected creations left no rows (only the three valid ones were added)');
// Dos altas idénticas simultáneas: una gana (200) y la otra pierde (409).
$race = ['first_name' => 'Race', 'last_name' => 'Create', 'username' => 'race.create', 'email' => 'race.create@example.invalid', 'password' => 'a-very-long-password'];
$res = parallel([['POST', '/api/register', $race, $admin], ['POST', '/api/register', $race, $admin]]);
$st = [$res[0][0], $res[1][0]]; sort($st);
expectCase($st === [200, 409] && (int)one($pdo, "SELECT COUNT(*) c FROM user WHERE username = 'race.create'")['c'] === 1, '2. two simultaneous identical creates: exactly one 200 and one 409, a single row');
foreach ([['/api/register', null, 'anonymous'], ['/api/register', $worker, 'worker']] as [$p, $tk, $who]) {
    [$s] = requestApi('POST', $p, $race, $tk);
    expectCase($s === ($tk === null ? 401 : 403), "2. register as $who is " . ($tk === null ? 401 : 403));
}

// ---------- 3. Detalle (GET /api/users/{id}) ----------
$target = insertUser($pdo, 'utarget', 'user');
[$s, $d, $raw] = requestApi('GET', "/api/users/$target", null, $admin);
$keys = array_keys($d['data'] ?? []); sort($keys);
expectCase($s === 200 && $keys === ['email', 'first_name', 'id', 'is_active', 'last_name', 'phone', 'role', 'username', 'version'], '3. GET /api/users/{id} returns exactly id, username, email, first_name, last_name, phone, role, is_active, version');
expectCase(($d['data']['is_active'] ?? null) === true && strpos($raw, 'password') === false && strpos($raw, 'session_version') === false && strpos($raw, '$2y$') === false, '3. detail has a boolean is_active and never password, hash or session_version');
expectCase(($d['data']['version'] ?? '') === versionOf($pdo, $target), '3. version is the server row_version over first_name,last_name,phone,email,role,is_active');
[$s, $d] = requestApi('GET', '/api/users/99999', null, $admin);
expectCase($s === 200 && ($d['success'] ?? true) === false, '3. legacy contract: unknown id is success:false');
[$s] = requestApi('GET', '/api/users/99999', null, $admin, true);
expectCase($s === 404, '3. contract 2: unknown id is HTTP 404');
[$s, $d] = requestApi('GET', '/api/users/0', null, $admin, true);
expectCase($s === 400 && ($d['success'] ?? true) === false, '3. id 0 is rejected as invalid');
[$s] = requestApi('GET', "/api/users/$target?role=admin&user_id=1&is_active=0", null, $admin);
expectCase($s === 200, '3. query parameters (role, user_id, is_active) are ignored');

// ---------- 4. Edición (PUT /api/users/{id}) ----------
$ver = versionOf($pdo, $target);
$auditBefore = auditCount($pdo, 'user.update', $target);
[$s, $d] = requestApi('PUT', "/api/users/$target", ['first_name' => 'Editado', 'phone' => '555-7777', 'version' => $ver], $admin, true);
$r = dbUser($pdo, $target);
expectCase($s === 200 && ($d['success'] ?? false) === true && $r['first_name'] === 'Editado' && $r['phone'] === '555-7777' && $r['last_name'] === 'Utarget', '4. edit with the current version: only the sent fields change');
expectCase(($d['data']['version'] ?? '') === versionOf($pdo, $target) && versionOf($pdo, $target) !== $ver, '4. the response carries the new version');
$a = lastAudit($pdo, 'user.update', $target);
$after = json_decode($a['after_json'] ?? '{}', true);
expectCase(auditCount($pdo, 'user.update', $target) === $auditBefore + 1 && ($after['first_name_modificado'] ?? null) === true && ($after['last_name_modificado'] ?? null) === false && ($after['phone_modificado'] ?? null) === true, '4. edit audit records user.update with <field>_modificado flags');
expectCase(!preg_match('/Editado|555-7777|utarget@example/', $a['after_json'] . $a['before_json']), '4. the edit audit holds no personal data in clear');
[$s, $d] = requestApi('PUT', "/api/users/$target", ['first_name' => 'Tarde', 'version' => $ver], $admin);
expectCase($s === 409 && dbUser($pdo, $target)['first_name'] === 'Editado', '4. stale version: always HTTP 409 (even without contract 2) and nothing changes');
$other = insertUser($pdo, 'uother', 'user');
[$s] = requestApi('PUT', "/api/users/$target", ['email' => 'uother@example.invalid'], $admin, true);
expectCase($s === 409 && dbUser($pdo, $target)['email'] === 'utarget@example.invalid', '4. duplicate email: HTTP 409 under contract 2, row unchanged');
[$s, $d] = requestApi('PUT', "/api/users/$target", ['email' => 'uother@example.invalid'], $admin);
expectCase($s === 200 && ($d['success'] ?? true) === false, '4. duplicate email under the legacy contract: HTTP 200 + success:false');
[$s] = requestApi('PUT', "/api/users/$target", ['email' => str_repeat('m', 95) . '@e.co'], $admin, true);
expectCase($s === 400, '4. email over 100 characters is rejected on edit');
[$s] = requestApi('PUT', "/api/users/$target", ['first_name' => ['x']], $admin, true);
expectCase($s === 400, '4. an array in a text field is rejected on edit');
[$s] = requestApi('PUT', "/api/users/$target", ['version' => 'x'], $admin, true);
expectCase($s === 400, '4. a body with no editable field is rejected');
// Mass-assignment: campos prohibidos ignorados
$before = dbUser($pdo, $target);
[$s] = requestApi('PUT', "/api/users/$target?role=admin&is_active=0&user_id=1", ['last_name' => 'Cambiado', 'role' => 'admin', 'is_active' => 0, 'session_version' => 99, 'id' => 1, 'password' => 'hacked-password-123', 'last_login' => '2000-01-01 00:00:00', 'username' => 'renamed'], $admin, true);
$r = dbUser($pdo, $target);
expectCase($s === 200 && $r['last_name'] === 'Cambiado' && $r['role'] === 'user' && (int)$r['is_active'] === 1 && (int)$r['session_version'] === (int)$before['session_version'] && (int)$r['id'] === $target
    && $r['password'] === $before['password'] && $r['last_login'] === $before['last_login'] && $r['username'] === 'utarget', '4. role, is_active, session_version, id, password, last_login and username in the body are ignored');
// Auto-promoción por usuario normal
[$s] = requestApi('PUT', '/api/users/3', ['role' => 'admin', 'is_active' => 1, 'session_version' => 50], $worker);
expectCase($s === 403 && dbUser($pdo, 3)['role'] === 'user', '4. a worker cannot self-promote through PUT /api/users/{id} (403)');
[$s] = requestApi('PUT', '/api/user/profile', ['first_name' => 'Perfil', 'role' => 'admin', 'is_active' => 0, 'session_version' => 77], $worker);
$r = dbUser($pdo, 3);
expectCase($r['role'] === 'user' && (int)$r['is_active'] === 1 && (int)$r['session_version'] === 1 && $r['first_name'] === 'Perfil', '4. profile route: JSON-manipulated role/is_active/session_version have no effect (name does change)');
$pdo->exec("UPDATE user SET first_name = 'Demo' WHERE id = 3");
// Editarse a uno mismo: permitido y sin cambio de sesión
$svAdmin = sv($pdo, 1);
[$s] = requestApi('PUT', '/api/users/1', ['phone' => '555-0001', 'version' => versionOf($pdo, 1)], $admin, true);
expectCase($s === 200 && dbUser($pdo, 1)['phone'] === '555-0001' && sv($pdo, 1) === $svAdmin && probeToken($admin) === 200, '4. an administrator may edit themselves: no session change, token still valid');
// Auditoría que falla => reversión
$pdo->exec("CREATE TRIGGER audit_block BEFORE INSERT ON audit_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit blocked'");
$before = dbUser($pdo, $target); $v = versionOf($pdo, $target);
[$s, $d, $raw] = requestApi('PUT', "/api/users/$target", ['first_name' => 'Rollback', 'version' => $v], $admin);
expectCase(($d['success'] ?? true) === false && dbUser($pdo, $target) == $before && stripos($raw, 'audit') === false && stripos($raw, 'SQLSTATE') === false, '4. AUDIT_LOG_MODE=required: a failing audit insert rolls the edit back (row unchanged, no SQL leaked)');
[$s, $d] = requestApi('POST', "/api/users/$target/deactivate", [], $admin);
expectCase(($d['success'] ?? true) === false && dbUser($pdo, $target) == $before, '4. a failing audit also rolls back a deactivation (is_active and session_version unchanged)');
[$s, $d] = requestApi('PUT', "/api/users/$target/password", ['password' => 'rollback-password-123'], $admin);
expectCase(($d['success'] ?? true) === false && dbUser($pdo, $target) == $before, '4. a failing audit also rolls back a password change');
$cnt = userCount($pdo);
[$s, $d] = requestApi('POST', '/api/register', ['first_name' => 'Roll', 'last_name' => 'Back', 'username' => 'rollback.user', 'email' => 'rollback.user@example.invalid', 'password' => 'a-very-long-password'], $admin);
expectCase(($d['success'] ?? true) === false && userCount($pdo) === $cnt, '4. a failing audit also rolls back a creation (no row inserted)');
[$s, $d] = requestApi('PUT', '/api/user/' . $other . '/role', ['role' => 'admin'], $admin);
expectCase(($d['success'] ?? true) === false && dbUser($pdo, $other)['role'] === 'user', '4. a failing audit also rolls back a role change');
$pdo->exec('DROP TRIGGER audit_block');

// ---------- 5. Desactivar / reactivar ----------
$victim = insertUser($pdo, 'uvictim', 'user');
$tv = tokenOf('uvictim');
$access = fn () => (int)one($pdo, 'SELECT (SELECT COUNT(*) FROM empresa_usuario) + (SELECT COUNT(*) FROM user_empresa_reporte) c')['c'];
$rel0 = $access(); $users0 = userCount($pdo); $svBefore = sv($pdo, $victim);
expectCase(probeToken($tv) === 200, '5. victim token works before deactivation');
[$s, $d] = requestApi('POST', "/api/users/$victim/deactivate", ['role' => 'admin'], $admin, true);
$r = dbUser($pdo, $victim);
expectCase($s === 200 && ($d['success'] ?? false) === true && (int)$r['is_active'] === 0 && sv($pdo, $victim) === $svBefore + 1, '5. deactivate: is_active=0 and session_version +1');
expectCase(userCount($pdo) === $users0 && $access() === $rel0, '5. deactivate does not delete the row nor touch access relations');
$a = lastAudit($pdo, 'user.deactivate', $victim);
expectCase($a && (int)$a['actor_user_id'] === 1 && json_decode($a['before_json'], true)['is_active'] === 1 && json_decode($a['after_json'], true)['is_active'] === 0 && strpos($a['after_json'] . $a['before_json'], 'session_version') === false, '5. deactivate audit: user.deactivate with before/after is_active and no session_version value');
expectCase(probeToken($tv) === 401, '5. the deactivated user token is rejected on the next call (401)');
[$s, $d] = loginAs('uvictim');
expectCase(($d['success'] ?? true) === false && empty($d['token']), '5. the deactivated user cannot log in');
[$s] = requestApi('POST', "/api/users/$victim/deactivate", [], $admin);
expectCase($s === 409 && sv($pdo, $victim) === $svBefore + 1, '5. deactivating an already inactive user is HTTP 409 (no second bump)');
[$s, $d] = requestApi('POST', "/api/users/$victim/reactivate", [], $admin);
expectCase($s === 200 && (int)dbUser($pdo, $victim)['is_active'] === 1 && sv($pdo, $victim) === $svBefore + 2, '5. reactivate: is_active=1 and session_version +1 again');
expectCase(lastAudit($pdo, 'user.reactivate', $victim) !== false && probeToken($tv) === 401, '5. reactivate is audited and the OLD token still does not revive (401)');
[$s, $d] = loginAs('uvictim');
$tv2 = $d['token'] ?? '';
expectCase($s === 200 && $tv2 !== '' && probeToken($tv2) === 200, '5. after reactivation a fresh login works');
[$s] = requestApi('POST', "/api/users/$victim/reactivate", [], $admin);
expectCase($s === 409, '5. reactivating an already active user is HTTP 409');
[$s, $d] = requestApi('POST', '/api/users/1/deactivate', [], $admin);
expectCase($s === 409 && (int)dbUser($pdo, 1)['is_active'] === 1 && strpos($d['message'] ?? '', 'propia') !== false, '5. an administrator cannot deactivate themselves (409, clear message)');
[$s] = requestApi('POST', '/api/users/99999/deactivate', [], $admin, true);
expectCase($s === 404, '5. deactivating an unknown user is HTTP 404 under contract 2');
$cnt = $access();
expectCase($cnt === $rel0, '5. access relations count unchanged after the whole deactivate/reactivate cycle');

// ---------- 6. Rol (PUT /api/user/{id}/role, endurecido) ----------
$roleUser = insertUser($pdo, 'urole', 'user');
$tr = tokenOf('urole');
foreach (['superadmin', 'root', 'ADMIN ', 'usuario', ''] as $badRole) {
    [$s, $d] = requestApi('PUT', "/api/user/$roleUser/role", ['role' => $badRole], $admin);
    expectCase(dbUser($pdo, $roleUser)['role'] === 'user' && ($d['success'] ?? true) === false, "6. role '" . trim($badRole) . "' cannot be assigned (role unchanged)");
}
[$s] = requestApi('PUT', "/api/user/$roleUser/role", ['role' => ['admin']], $admin);
expectCase($s === 400 && dbUser($pdo, $roleUser)['role'] === 'user', '6. an array as role is rejected (400)');
[$s, $d] = requestApi('PUT', '/api/user/1/role', ['role' => 'user'], $admin);
expectCase($s === 409 && dbUser($pdo, 1)['role'] === 'admin' && ($d['success'] ?? true) === false, '6. changing your own role is refused (409) and nothing changes');
$svBefore = sv($pdo, $roleUser);
[$s, $d] = requestApi('PUT', "/api/user/$roleUser/role", ['role' => 'admin'], $admin);
expectCase($s === 200 && ($d['success'] ?? false) === true && dbUser($pdo, $roleUser)['role'] === 'admin' && sv($pdo, $roleUser) === $svBefore + 1, '6. a valid role change applies and bumps the target session_version');
$a = lastAudit($pdo, 'user.role', $roleUser);
expectCase($a && json_decode($a['before_json'], true)['role'] === 'user' && json_decode($a['after_json'], true)['role'] === 'admin', '6. role change is audited as user.role (before/after role)');
expectCase(probeToken($tr) === 401, '6. the target old token is rejected after the role change (React backend reads role and version from MySQL)');
[$s] = requestApi('PUT', "/api/user/$roleUser/role", ['role' => 'user'], $admin);
expectCase($s === 200 && dbUser($pdo, $roleUser)['role'] === 'user', '6. the role can be reverted');
// `usuario` heredado se conserva
$legacy = $pdo->query("SELECT id FROM user WHERE username = 'userb'")->fetch()['id'];
$tl = tokenOf('userb');
requestApi('PUT', "/api/users/$legacy", ['first_name' => 'Legacy', 'version' => versionOf($pdo, $legacy)], $admin, true);
requestApi('POST', "/api/users/$legacy/deactivate", [], $admin);
requestApi('POST', "/api/users/$legacy/reactivate", [], $admin);
requestApi('PUT', "/api/users/$legacy/password", ['password' => 'synthetic-password'], $admin);
expectCase(dbUser($pdo, $legacy)['role'] === 'usuario', "6. a stored legacy role 'usuario' survives edit, deactivate, reactivate and password change (never auto-normalised)");
[$s, $d] = loginAs('userb');
expectCase($s === 200 && ($d['user']['role'] ?? '') === 'user', "6. the legacy 'usuario' user still logs in (reported as user)");
[$s] = requestApi('PUT', "/api/user/$legacy/role", ['role' => 'usuario'], $admin);
expectCase($s === 200 && dbUser($pdo, $legacy)['role'] === 'usuario', "6. sending the same legacy value 'usuario' is a harmless no-op");
[$s] = requestApi('PUT', "/api/user/$legacy/role", ['role' => 'user'], $admin);
expectCase($s === 200 && dbUser($pdo, $legacy)['role'] === 'user', "6. an explicit change usuario -> user is allowed");
$pdo->exec("UPDATE user SET role = 'usuario' WHERE id = $legacy");
// Degradar a otro administrador mientras queda uno
$adm3 = insertUser($pdo, 'uadm3', 'admin');
[$s] = requestApi('PUT', "/api/user/$adm3/role", ['role' => 'user'], $admin);
expectCase($s === 200 && dbUser($pdo, $adm3)['role'] === 'user', '6. demoting another administrator works while an active administrator remains');
// El rol del actor se relee de MySQL: un administrador degradado deja de ser administrador de inmediato
$tokenA3 = tokenOf('uadm3');
$pdo->exec("UPDATE user SET role = 'admin' WHERE id = $adm3");

// ---------- 7. Contraseña (PUT /api/users/{id}/password) ----------
$pwUser = insertUser($pdo, 'upw', 'user');
$tp = tokenOf('upw');
$svBefore = sv($pdo, $pwUser);
foreach ([['short', 'short'], ['73 bytes', str_repeat('p', 73)], ['bool', true], ['array', ['a-very-long-password']], ['missing', null]] as [$label, $pw]) {
    [$s, $d] = requestApi('PUT', "/api/users/$pwUser/password", $pw === null ? [] : ['password' => $pw], $admin, true);
    expectCase($s === 400 && sv($pdo, $pwUser) === $svBefore, "7. invalid password ($label) is rejected (400) without touching the session");
}
[$s, $d, $raw] = requestApi('PUT', "/api/users/$pwUser/password", ['password' => 'brand-new-password-42'], $admin, true);
expectCase($s === 200 && ($d['success'] ?? false) === true && strpos($raw, 'brand-new') === false && strpos($raw, '$2y$') === false, '7. password change succeeds and the response carries neither password nor hash');
expectCase(sv($pdo, $pwUser) === $svBefore + 1 && probeToken($tp) === 401, '7. password change bumps session_version and the old token is rejected');
[, $d] = loginAs('upw', 'synthetic-password');
expectCase(($d['success'] ?? true) === false, '7. the old password no longer works');
[, $d] = loginAs('upw', 'brand-new-password-42');
expectCase(($d['success'] ?? false) === true && !empty($d['token']), '7. the new password works');
$a = lastAudit($pdo, 'user.password', $pwUser);
$blob = ($a['after_json'] ?? '') . ($a['before_json'] ?? '');
expectCase($a && (json_decode($a['after_json'], true)['password_changed'] ?? null) === true && !preg_match('/brand-new|\$2y\$|"password"|hash/i', $blob), '7. user.password audit only flags password_changed:true (no password data at all)');
expectCase(password_verify('brand-new-password-42', dbUser($pdo, $pwUser)['password']), '7. the stored hash is a password_hash() hash (compatible with password_verify of the original backend)');

// ---------- 8. Autorización de TODAS las rutas nuevas ----------
$forgedBad = forge($pdo, $secret, 1, [], 'not-the-secret');
$workerAsAdminClaim = forge($pdo, $secret, 3, ['role' => 'admin']);
$all = array_merge($writeRoutes(3), $readRoutes(3));
foreach ($all as [$m, $p, $b]) {
    [$sa] = requestApi($m, $p, $b);
    [$sw] = requestApi($m, $p, $b, $worker);
    [$sf] = requestApi($m, $p, $b, $forgedBad);
    [$sc] = requestApi($m, $p, $b, $workerAsAdminClaim);
    expectCase($sa === 401 && $sw === 403 && $sf === 401 && $sc === 403, "8. $m $p: anonymous 401, worker 403, forged token 401, worker with an admin claim 403 (role read from MySQL)");
}
[$s] = requestApi('GET', '/api/users/3?role=admin&user_id=1', null, $worker);
expectCase($s === 403, '8. query-string manipulation does not grant access to a worker');
expectCase((int)dbUser($pdo, 3)['is_active'] === 1 && dbUser($pdo, 3)['role'] === 'user', '8. none of the rejected calls changed the worker');

// ---------- 9. Accesos (GET /api/users/{id}/access) ----------
$pdo->exec("INSERT INTO empresa (id,nombre,razon_social,ruc,activo) VALUES (3,'Empresa U3','Empresa U3','30000000003',1)");
$pdo->exec("INSERT INTO reporte (id,nombre,descripcion,enlace,grupo_id,reporte_id,created_by,activo) VALUES (3,'Informe U3','','https://app.powerbi.com/reportEmbed?reportId=aaaaaaaa-0000-4000-8000-000000000003&groupId=bbbbbbbb-0000-4000-8000-000000000001','bbbbbbbb-0000-4000-8000-000000000001','aaaaaaaa-0000-4000-8000-000000000003',1,1)");
$pdo->exec('INSERT INTO empresa_reporte (id,empresa_id,reporte_id,activo) VALUES (3,3,3,1)');
$pdo->exec('INSERT INTO user_empresa_reporte (id,user_id,empresa_reporte_id,activo) VALUES (3,3,3,1)'); // sin empresa_usuario para la empresa 3
[$s, $d, $raw] = requestApi('GET', '/api/users/3/access', null, $admin);
$byEmpresa = []; foreach ($d['data']['empresas'] ?? [] as $e) $byEmpresa[$e['empresa_id']] = $e;
$byReporte = []; foreach ($d['data']['reportes'] ?? [] as $r) $byReporte[$r['reporte_id']] = $r;
expectCase($s === 200 && ($byEmpresa[1]['effective'] ?? null) === true && array_key_exists('reason', $byEmpresa[1] ?? []) && $byEmpresa[1]['reason'] === null, '9. active company with an active relation is effective');
expectCase(($byEmpresa[2]['effective'] ?? null) === false && ($byEmpresa[2]['reason'] ?? '') === 'empresa_inactiva', '9. an inactive empresa is NOT effective, reason empresa_inactiva');
expectCase(($byReporte[1]['effective'] ?? null) === true, '9. a report assigned through every active relation is effective');
expectCase(($byReporte[2]['effective'] ?? null) === false && ($byReporte[2]['reason'] ?? '') === 'empresa_inactiva', '9. a report of an inactive empresa is NOT effective');
expectCase(($byReporte[3]['effective'] ?? null) === false && ($byReporte[3]['reason'] ?? '') === 'sin_relacion_empresa_usuario', '9. a missing intermediate empresa_usuario relation makes the report not effective');
$pdo->exec('UPDATE reporte SET activo = 0 WHERE id = 1');
[, $d] = requestApi('GET', '/api/users/3/access', null, $admin);
$r1 = null; foreach ($d['data']['reportes'] ?? [] as $r) if ($r['reporte_id'] === 1) $r1 = $r;
expectCase(($r1['effective'] ?? null) === false && ($r1['reason'] ?? '') === 'reporte_inactivo', '9. an inactive reporte is not effective');
$pdo->exec('UPDATE reporte SET activo = 1 WHERE id = 1');
$pdo->exec('UPDATE empresa_reporte SET activo = 0 WHERE id = 1');
[, $d] = requestApi('GET', '/api/users/3/access', null, $admin);
$r1 = null; foreach ($d['data']['reportes'] ?? [] as $r) if ($r['reporte_id'] === 1) $r1 = $r;
expectCase(($r1['reason'] ?? '') === 'relacion_empresa_reporte_inactiva', '9. an inactive empresa-reporte relation is reported');
$pdo->exec('UPDATE empresa_reporte SET activo = 1 WHERE id = 1');
expectCase(!preg_match('/enlace|powerbi|reportEmbed|clientsecret|password|session_version/i', $raw), '9. the access listing carries no links, Power BI fields or credentials');
$pdo->exec('UPDATE user SET is_active = 0 WHERE id = 3');
[, $d] = requestApi('GET', '/api/users/3/access', null, $admin);
$allInactive = ($d['data']['user_active'] ?? true) === false;
foreach (array_merge($d['data']['empresas'] ?? [], $d['data']['reportes'] ?? []) as $x) $allInactive = $allInactive && $x['effective'] === false && $x['reason'] === 'usuario_inactivo';
$pdo->exec('UPDATE user SET is_active = 1 WHERE id = 3');
expectCase($allInactive, '9. for an inactive user nothing is effective (reason usuario_inactivo)');
[$s] = requestApi('GET', '/api/users/99999/access', null, $admin, true);
expectCase($s === 404, '9. access of an unknown user is HTTP 404 under contract 2');

// ---------- 10. Concurrencia real entre administradores ----------
// Solo dos administradores activos (A, B); los demás se apartan en la base. Cada ronda: ambos se desactivan a la vez.
$admA = insertUser($pdo, 'uadmA', 'admin'); $admB = insertUser($pdo, 'uadmB', 'admin');
$pdo->exec("UPDATE user SET is_active = 0 WHERE role = 'admin' AND id NOT IN ($admA, $admB)");
$okRounds = 0; $exact = 0;
for ($i = 0; $i < 5; $i++) {
    $pdo->exec("UPDATE user SET is_active = 1, role = 'admin' WHERE id IN ($admA, $admB)");
    $ta = tokenOf('uadmA'); $tb = tokenOf('uadmB');
    $res = parallel([['POST', "/api/users/$admB/deactivate", [], $ta], ['POST', "/api/users/$admA/deactivate", [], $tb]]);
    $codes = [$res[0][0], $res[1][0]];
    $active = (int)one($pdo, "SELECT COUNT(*) c FROM user WHERE id IN ($admA, $admB) AND is_active = 1")['c'];
    if ($active === 1) $okRounds++;
    if (count(array_keys($codes, 200)) === 1 && !array_diff($codes, [200, 401, 409])) $exact++;
}
expectCase($okRounds === 5, '10. two administrators deactivating each other at the same time (5 rounds): exactly one stays active every time');
expectCase($exact === 5, '10. in every round exactly one request succeeds (200) and the other is refused (401/409)');
$okRounds = 0; $exact = 0;
for ($i = 0; $i < 5; $i++) {
    $pdo->exec("UPDATE user SET is_active = 1, role = 'admin' WHERE id IN ($admA, $admB)");
    $ta = tokenOf('uadmA'); $tb = tokenOf('uadmB');
    $res = parallel([['PUT', "/api/user/$admB/role", ['role' => 'user'], $ta], ['PUT', "/api/user/$admA/role", ['role' => 'user'], $tb]]);
    $codes = [$res[0][0], $res[1][0]];
    $admins = (int)one($pdo, "SELECT COUNT(*) c FROM user WHERE id IN ($admA, $admB) AND is_active = 1 AND role = 'admin'")['c'];
    if ($admins === 1) $okRounds++;
    if (count(array_keys($codes, 200)) === 1 && !array_diff($codes, [200, 401, 403, 409])) $exact++;
}
expectCase($okRounds === 5 && $exact === 5, '10. two administrators demoting each other at the same time (5 rounds): exactly one succeeds and one administrator remains');
// Último administrador activo: el único activo no puede desactivarse ni degradarse
$pdo->exec("UPDATE user SET is_active = 1, role = 'admin' WHERE id = $admA");
$pdo->exec("UPDATE user SET is_active = 0 WHERE id = $admB");
$ta = tokenOf('uadmA');
[$s] = requestApi('POST', "/api/users/$admA/deactivate", [], $ta);
expectCase($s === 409 && (int)dbUser($pdo, $admA)['is_active'] === 1, '10. the last active administrator cannot deactivate themselves (409)');
[$s] = requestApi('PUT', "/api/user/$admA/role", ['role' => 'user'], $ta);
expectCase($s === 409 && dbUser($pdo, $admA)['role'] === 'admin', '10. the last active administrator cannot demote themselves (409)');
[$s] = requestApi('POST', "/api/users/$admB/deactivate", [], $ta);
expectCase($s === 409, '10. deactivating an already inactive administrator is a state conflict');
// Restauración: administradores originales activos de nuevo (sin tocar session_version: sus tokens siguen válidos)
$pdo->exec('UPDATE user SET is_active = 1 WHERE id IN (1, 2)');
$pdo->exec("UPDATE user SET is_active = 0 WHERE id IN ($admA, $admB)");
expectCase(probeToken($admin) === 200 && probeToken($admin2) === 200, '10. the original administrators work again after the isolated race block');

// ---------- 11. GET /api/users: contrato heredado intacto ----------
[$s, $d] = requestApi('GET', '/api/users?status=all&per_page=100', null, $admin);
$u0 = $d['users'][0] ?? [];
$k = array_keys($u0); sort($k);
expectCase($s === 200 && ($d['success'] ?? false) === true && isset($d['users'], $d['total'], $d['filter']) && $k === ['avatar', 'created_at', 'email', 'first_name', 'id', 'is_active', 'last_login', 'last_name', 'phone', 'role', 'username'], '11. GET /api/users keeps its historical shape (success, users, total, filter; same user keys)');
$inactiveSeen = false; foreach ($d['users'] ?? [] as $x) if ($x['is_active'] === false) $inactiveSeen = true;
expectCase($inactiveSeen && is_bool($u0['is_active'] ?? null), '11. status=all still lists inactive users and is_active is a boolean');
[, $d2] = requestApi('GET', '/api/users?status=inactive', null, $admin);
expectCase(($d2['filter'] ?? '') === 'inactive', '11. status=inactive still filters');

// ---------- 12. Sin borrados físicos ----------
expectCase(userCount($pdo) >= $usersAtStart && (int)one($pdo, 'SELECT COUNT(*) c FROM user WHERE id IN (1,2,3,4)')['c'] === 4, '12. the user table never lost a row during the whole suite (no physical DELETE)');

// ---------- 13. ORIGINAL BACKEND SEMANTICS (simulated) ----------
// Réplica PURA de las reglas del middleware del backend ORIGINAL (Flutter), según la evidencia recogida; no se ejecuta el backend original:
//   rechaza is_active != 1; compara session_version SOLO si el token lo trae; toma el ROL DEL PAYLOAD del JWT (no de MySQL).
// Cada caso se evalúa contra la fila real de MySQL y contra los tokens, y se contrasta con el backend React (más estricto).
function original_semantics(?array $dbRow, array $payload) {
    if (!$dbRow) return [false, null];
    if ((int)$dbRow['is_active'] !== 1) return [false, null];
    if (array_key_exists('session_version', $payload) && (int)$payload['session_version'] !== (int)$dbRow['session_version']) return [false, null];
    return [true, $payload['role'] ?? null];
}
$sim = insertUser($pdo, 'usim', 'user');
$legacyPayload = ['user_id' => $sim, 'username' => 'usim', 'email' => 'usim@example.invalid', 'role' => 'user', 'exp' => time() + 900]; // token heredado SIN session_version
$legacyTok = generate_jwt($legacyPayload, $secret);
$reactTok = tokenOf('usim');                        // token React (trae session_version)
$reactPayload = validate_jwt($reactTok, $secret);
[$okL] = original_semantics(dbUser($pdo, $sim), $legacyPayload);
[$okT] = original_semantics(dbUser($pdo, $sim), $reactPayload);
expectCase($okL && $okT, '13. [simulated original] baseline: both a legacy token and a token with session_version are accepted');
expectCase(probeToken($legacyTok) === 401 && probeToken($reactTok) === 200, '13. [React] baseline: the legacy token (no session_version) is rejected, the current one accepted');

requestApi('POST', "/api/users/$sim/deactivate", [], $admin);
[$okL] = original_semantics(dbUser($pdo, $sim), $legacyPayload);
[$okT] = original_semantics(dbUser($pdo, $sim), $reactPayload);
expectCase($okL === false, '13. (a) [simulated original] after DEACTIVATE a legacy token WITHOUT session_version is rejected because of is_active');
expectCase($okT === false, '13. (c) [simulated original] after DEACTIVATE a token WITH the old session_version is rejected');

requestApi('POST', "/api/users/$sim/reactivate", [], $admin);
[$okL, $roleL] = original_semantics(dbUser($pdo, $sim), $legacyPayload);
[$okT] = original_semantics(dbUser($pdo, $sim), $reactPayload);
expectCase($okL === true, '13. (b) [simulated original] after REACTIVATE the same legacy token is accepted again (KNOWN LIMITATION R1: legacy tokens are not revoked by session_version)');
expectCase($okT === false && probeToken($reactTok) === 401 && probeToken($legacyTok) === 401, '13. (c) [simulated original] a token with the old session_version stays rejected after reactivate; the React backend rejects both');

$tokSim2 = tokenOf('usim'); $payloadSim2 = validate_jwt($tokSim2, $secret);
requestApi('PUT', "/api/user/$sim/role", ['role' => 'admin'], $admin);
[$okL, $roleL] = original_semantics(dbUser($pdo, $sim), $legacyPayload);
[$okT] = original_semantics(dbUser($pdo, $sim), $payloadSim2);
expectCase($okT === false, '13. (c) [simulated original] after a ROLE CHANGE a token with the old session_version is rejected');
expectCase($okL === true && $roleL === 'user' && dbUser($pdo, $sim)['role'] === 'admin', '13. (d) [simulated original] after a role change a still-passing legacy token yields the OLD role (user) although MySQL says admin (KNOWN LIMITATION R3)');
expectCase(probeToken($legacyTok) === 401 && requestApi('GET', '/api/users', null, $legacyTok)[0] === 401, '13. (d) [React] the same legacy token is rejected; the React backend never trusts the JWT role');
$tokSim3 = tokenOf('usim'); $payloadSim3 = validate_jwt($tokSim3, $secret);
expectCase(requestApi('GET', '/api/users', null, $tokSim3)[0] === 200, '13. (d) [React] a fresh token reflects the NEW role from MySQL (admin endpoint now reachable)');
requestApi('PUT', "/api/user/$sim/role", ['role' => 'user'], $admin);

requestApi('PUT', "/api/users/$sim/password", ['password' => 'another-long-password-9'], $admin);
$tokSim4 = null; [$okT] = original_semantics(dbUser($pdo, $sim), $payloadSim3);
[$okL] = original_semantics(dbUser($pdo, $sim), $legacyPayload);
expectCase($okT === false, '13. (c) [simulated original] after a PASSWORD CHANGE a token with the old session_version is rejected');
expectCase($okL === true, '13. [simulated original] after a PASSWORD CHANGE a legacy token without session_version still passes (same KNOWN LIMITATION R1)');

// ---------- 14. Higiene ----------
expectCase($GLOBALS['hashSeen'] === false, '14. no HTTP response body of the whole suite ever contained a password hash ($2y$)');
expectCase((int)one($pdo, "SELECT COUNT(*) c FROM audit_log WHERE after_json LIKE '%$2y$%' OR before_json LIKE '%$2y$%' OR after_json LIKE '%session_version%' OR before_json LIKE '%session_version%'")['c'] === 0, '14. no audit row contains a hash or a session_version value');

echo $failures === 0 ? "\nPASSED\n" : "\nFAILED: $failures\n";
exit($failures === 0 ? 0 : 1);
