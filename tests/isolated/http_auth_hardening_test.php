<?php
// SEC-05A: endurecimiento de registro, roles y errores internos.
// Solo red Docker aislada, identidades ficticias y MySQL desechable. Se ejecuta DESPUÉS de http_test.php.
$failures = 0;
$pdo = new PDO('mysql:host=db;dbname=fixture;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

function requestApi($method, $path, $body = null, $token = null) {
    $headers = ["Content-Type: application/json"];
    if ($token !== null) $headers[] = "Authorization: Bearer " . $token;
    $options = ['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 5]];
    if ($body !== null) $options['http']['content'] = json_encode($body);
    $raw = file_get_contents('http://app:8080' . $path, false, stream_context_create($options));
    $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int)$m[1] : 0;
    return [$status, json_decode($raw ?: '', true), $raw ?: ''];
}
function expectCase($condition, $label) {
    global $failures;
    if ($condition) echo "PASS $label\n"; else { echo "FAIL $label\n"; $failures++; }
}
function loginAs($name, $password = 'synthetic-password') {
    return requestApi('POST', '/api/login', ['login' => $name, 'password' => $password]);
}
function tokenOf($name) {
    [, $data] = loginAs($name);
    return $data['token'] ?? '';
}
function dbUser($pdo, $username) {
    $st = $pdo->prepare('SELECT * FROM user WHERE username = ?'); $st->execute([$username]);
    return $st->fetch();
}
function insertUser($pdo, $username, $role, $active = 1) {
    $st = $pdo->prepare('INSERT INTO user (username, email, password, first_name, last_name, role, is_active, session_version) VALUES (?,?,?,?,?,?,?,1)');
    $st->execute([$username, $username . '@example.invalid', password_hash('synthetic-password', PASSWORD_DEFAULT), 'Demo', 'User', $role, $active]);
    return (int)$pdo->lastInsertId();
}

// Preparación del fixture (idempotente): la tabla sintética base no tiene AUTO_INCREMENT ni created_at/updated_at.
foreach (['ALTER TABLE user MODIFY id INT AUTO_INCREMENT', 'ALTER TABLE user ADD COLUMN created_at DATETIME NULL', 'ALTER TABLE user ADD COLUMN updated_at DATETIME NULL'] as $ddl) {
    try { $pdo->exec($ddl); } catch (PDOException $e) { /* ya aplicado por otro fixture */ }
}
$pdo->exec('ALTER TABLE user AUTO_INCREMENT = 100');

$admin = tokenOf('admin');
$userA = tokenOf('usera');
$newUser = ['first_name' => 'Nuevo', 'last_name' => 'Sintetico', 'username' => 'nuevo1', 'email' => 'nuevo1@example.invalid', 'password' => 'synthetic-password'];

// ---------- B-01: registro ----------
[$s, $d] = requestApi('POST', '/api/register', $newUser);
expectCase($s === 401 && !($d['success'] ?? true), 'anonymous registration is rejected (401)');
expectCase(dbUser($pdo, 'nuevo1') === false, 'anonymous registration created no row');
[$s] = requestApi('POST', '/api/register', $newUser, 'not-a-jwt');
expectCase($s === 401, 'registration with an invalid token is rejected');
[$s, $d] = requestApi('POST', '/api/register', $newUser, $userA);
expectCase($s === 403 && !($d['success'] ?? true), 'non-admin cannot create users (403)');
expectCase(dbUser($pdo, 'nuevo1') === false, 'non-admin creation created no row');

foreach (['role' => 'admin', 'is_active' => 0, 'session_version' => 99, 'id' => 1] as $field => $value) {
    [$s, $d] = requestApi('POST', '/api/register', $newUser + [$field => $value], $admin);
    expectCase($s === 400 && !($d['success'] ?? true) && dbUser($pdo, 'nuevo1') === false, "admin request carrying '$field' is rejected without creating a user");
}

[$s, $d] = requestApi('POST', '/api/register', $newUser, $admin);
expectCase($s === 200 && ($d['success'] ?? false) === true && ($d['message'] ?? '') === 'Usuario registrado exitosamente', 'admin creates a synthetic user (historical response contract)');
$row = dbUser($pdo, 'nuevo1');
expectCase($row && $row['role'] === 'user' && (int)$row['is_active'] === 1 && (int)$row['session_version'] === 1, "new user has role 'user', is_active=1, session_version=1");
expectCase($row && $row['password'] !== 'synthetic-password' && password_verify('synthetic-password', $row['password']), 'new user password is stored hashed');
[$s, $d] = loginAs('nuevo1');
expectCase($s === 200 && ($d['success'] ?? false) && ($d['user']['role'] ?? '') === 'user' && isset($d['token']), 'created user can log in with role user');
[$s, $d] = requestApi('POST', '/api/register', $newUser, $admin);
expectCase(!($d['success'] ?? true) && dbUser($pdo, 'nuevo1') !== false, 'duplicate creation is refused');
[$s] = requestApi('POST', '/api/register', null, $admin);
expectCase($s === 400, 'admin registration without a body is a 400');

// ---------- B-02: elevación de rol ----------
$userId = (int)dbUser($pdo, 'usera')['id'];
[$s] = requestApi('PUT', "/api/user/$userId/role", ['role' => 'admin'], $userA);
expectCase($s === 403 && dbUser($pdo, 'usera')['role'] === 'user', 'user cannot promote self through the role endpoint');
$versionBefore = (int)dbUser($pdo, 'usera')['session_version'];
[$s] = requestApi('PUT', '/api/user/profile', ['first_name' => 'X', 'role' => 'admin', 'is_active' => 1, 'session_version' => 50], $userA);
expectCase(dbUser($pdo, 'usera')['role'] === 'user' && (int)dbUser($pdo, 'usera')['session_version'] === $versionBefore && (int)dbUser($pdo, 'usera')['is_active'] === 1, 'profile update cannot change role or session_version');
[$s] = requestApi('GET', '/api/users', null, $userA);
expectCase($s === 403, 'promotion attempts left the user without administrative access');
foreach (['superadmin', 'usuario', 'root', 'ADMIN '] as $badRole) {
    [$s, $d] = requestApi('PUT', "/api/user/$userId/role", ['role' => $badRole], $admin);
    expectCase(dbUser($pdo, 'usera')['role'] === 'user', "admin cannot assign the unexpected role '" . trim($badRole) . "' (role unchanged)");
}
[$s, $d] = requestApi('PUT', "/api/user/$userId/role", ['role' => 'admin'], $admin);
expectCase($s === 200 && ($d['success'] ?? false), 'an admin can still assign a valid role');
[$s] = requestApi('PUT', "/api/user/$userId/role", ['role' => 'user'], $admin);

// ---------- Política de roles heredados / inesperados ----------
insertUser($pdo, 'legacy', 'usuario');
[$s, $d] = loginAs('legacy');
expectCase($s === 200 && ($d['success'] ?? false) && ($d['user']['role'] ?? '') === 'user', "legacy role 'usuario' logs in and is reported as 'user'");
$legacyToken = $d['token'] ?? '';
[$s] = requestApi('GET', '/api/user/profile', null, $legacyToken);
expectCase($s === 200, "legacy 'usuario' token is accepted for user-level endpoints");
[$s] = requestApi('GET', '/api/users', null, $legacyToken);
expectCase($s === 403, "legacy 'usuario' has no administrative privileges");
expectCase(dbUser($pdo, 'legacy')['role'] === 'usuario', 'the stored legacy value is not rewritten (no migration)');

insertUser($pdo, 'upper', 'ADMIN');
[$s, $d] = loginAs('upper');
expectCase(($d['success'] ?? false) && ($d['user']['role'] ?? '') === 'admin', "role 'ADMIN' is normalised to 'admin' (previous case-insensitive behaviour)");

foreach (['superadmin', 'root', '', 'administrator', 'guest'] as $i => $badRole) {
    $name = 'weird' . $i;
    insertUser($pdo, $name, $badRole);
    [$s, $d] = loginAs($name);
    expectCase($s === 200 && !($d['success'] ?? true) && ($d['message'] ?? '') === 'Credenciales inválidas' && !isset($d['token']), "unexpected role '$badRole' cannot log in (uniform message)");
}
$pdo->exec("UPDATE user SET role = NULL WHERE username = 'weird4'");
[$s, $d] = loginAs('weird4');
expectCase(!($d['success'] ?? true) && !isset($d['token']), 'NULL role cannot log in');

// Un rol dañado después del login revoca el acceso de inmediato (el middleware relee la BD)
$tokenB = tokenOf('nuevo1');
[$s] = requestApi('GET', '/api/user/profile', null, $tokenB);
expectCase($s === 200, 'control: active session works');
$pdo->exec("UPDATE user SET role = 'superadmin' WHERE username = 'nuevo1'");
[$s] = requestApi('GET', '/api/user/profile', null, $tokenB);
expectCase($s === 401, 'existing session of a user whose role became unexpected is rejected');
[$s] = requestApi('GET', '/api/users', null, $tokenB);
expectCase($s === 401, 'unexpected role never reaches administrative endpoints');
$pdo->exec("UPDATE user SET role = 'user' WHERE username = 'nuevo1'");

// ---------- Usuario inactivo y revocación de sesión ----------
$tokenI = tokenOf('nuevo1');
$pdo->exec("UPDATE user SET is_active = 0 WHERE username = 'nuevo1'");
[$s] = requestApi('GET', '/api/user/profile', null, $tokenI);
expectCase($s === 401, 'deactivating a user rejects its existing token');
[$s, $d] = loginAs('nuevo1');
expectCase(!($d['success'] ?? true) && ($d['message'] ?? '') === 'Credenciales inválidas', 'inactive user cannot obtain a new session');
$pdo->exec("UPDATE user SET is_active = 1 WHERE username = 'nuevo1'");
$t1 = tokenOf('nuevo1');
[$s] = requestApi('POST', '/api/logout', null, $t1);
[$s2] = requestApi('GET', '/api/user/profile', null, $t1);
expectCase($s2 === 401, 'logout revokes the token (session_version bump)');
$t2 = tokenOf('nuevo1');
expectCase($t2 !== '' && requestApi('GET', '/api/user/profile', null, $t2)[0] === 200, 'a fresh login after revocation works');
[$s, $d] = loginAs('nuevo1', 'wrong-password');
expectCase($s === 200 && !($d['success'] ?? true) && ($d['message'] ?? '') === 'Credenciales inválidas', 'invalid password keeps the historical failure contract');

// ---------- Contrato histórico de login ----------
[$s, $d] = loginAs('admin');
$keys = array_keys($d['user'] ?? []); sort($keys);
expectCase($s === 200 && ($d['success'] ?? false) && ($d['message'] ?? '') === 'Login exitoso' && $keys === ['email', 'first_name', 'id', 'last_name', 'role', 'username'] && ($d['user']['role'] ?? '') === 'admin', 'login success keeps its historical shape');

// ---------- B-04: ningún error interno llega al cliente ----------
$leak = '/SQLSTATE|PDOException|SQL syntax|doesn.t exist|Base table|Unknown (column|table)|Duplicate entry|\bfixture\b|mysql|Stack trace|#\d+ \/|\.php|\b(SELECT|INSERT INTO|UPDATE|DELETE FROM|FROM|JOIN|WHERE)\b|\b(empresa_usuario|empresa_reporte|user_empresa_reporte)\b/i';
$endpoints = [
    ['GET', '/api/empresa-usuario'], ['GET', '/api/empresa-usuario/1'], ['GET', '/api/empresa-usuario/ruc/00000000001'], ['GET', '/api/empresa-usuario/empresa/Empresa%20A'],
    ['POST', '/api/empresa-usuario', ['empresa_id' => 1, 'user_id' => 3]], ['PUT', '/api/empresa-usuario/1', ['notas' => 'x']], ['DELETE', '/api/empresa-usuario/1'],
    ['GET', '/api/empresa-reporte-usuario'], ['GET', '/api/empresa-reporte-usuario/1'], ['GET', '/api/empresa-reporte-usuario/ruc/00000000001'], ['GET', '/api/empresa-reporte-usuario/empresa/Empresa%20A'], ['GET', '/api/empresa-reporte-usuario/user/1'],
    ['POST', '/api/empresa-reporte-usuario', ['empresa_reporte_id' => 1, 'user_id' => 3]], ['PUT', '/api/empresa-reporte-usuario/1', ['notas' => 'x']], ['DELETE', '/api/empresa-reporte-usuario/1'],
    ['GET', '/api/asignacion'], ['POST', '/api/asignacion', ['empresa_id' => 1, 'reporte_id' => 1]],
    ['GET', '/api/reportes'], ['GET', '/api/reportes/activos'], ['GET', '/api/reportes/1'], ['POST', '/api/reportes', ['nombre' => 'R', 'enlace' => 'https://app.powerbi.com/reportEmbed?reportId=00000000-0000-0000-0000-000000000009&groupId=00000000-0000-0000-0000-000000000008']],
    ['PUT', '/api/reportes/1', ['nombre' => 'R2', 'enlace' => 'https://app.powerbi.com/view?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDE']], ['DELETE', '/api/reportes/1'],
    ['GET', '/api/empresas'], ['GET', '/api/empresas/1'], ['GET', '/api/stats'],
];
// Las escrituras de este bucle (PUT/DELETE/POST) pueden prosperar cuando la tabla renombrada no es la que usan: se restaura el fixture al terminar.
$snapshotTables = ['empresa_usuario', 'user_empresa_reporte', 'empresa_reporte', 'reporte', 'empresa'];
foreach ($snapshotTables as $table) $pdo->exec("CREATE TABLE `snap_$table` AS SELECT * FROM `$table`");
$checked = 0; $leaks = [];
foreach (['empresa_usuario', 'user_empresa_reporte', 'empresa_reporte', 'reporte', 'empresa'] as $table) {
    $pdo->exec("RENAME TABLE `$table` TO `{$table}__off`");
    try {
        foreach ($endpoints as $e) {
            [$s, , $raw] = requestApi($e[0], $e[1], $e[2] ?? null, $admin);
            $checked++;
            if (preg_match($leak, $raw, $m)) $leaks[] = "$e[0] $e[1] (sin $table): {$m[0]}";
        }
    } finally {
        $pdo->exec("RENAME TABLE `{$table}__off` TO `$table`");
    }
}
foreach ($snapshotTables as $table) {
    $pdo->exec("DELETE FROM `$table`");
    $pdo->exec("INSERT INTO `$table` SELECT * FROM `snap_$table`");
    $pdo->exec("DROP TABLE `snap_$table`");
}
foreach (array_slice($leaks, 0, 15) as $l) echo "  filtración: $l\n";
expectCase($checked > 100 && count($leaks) === 0, "no database detail in $checked failing-database responses (" . count($leaks) . ' leaks)');

// Con un fallo real de base de datos la respuesta sí debe seguir siendo un JSON de error legible
$pdo->exec('RENAME TABLE `empresa_usuario` TO `empresa_usuario__off`');
[$s, $d, $raw] = requestApi('GET', '/api/empresa-usuario', null, $admin);
$pdo->exec('RENAME TABLE `empresa_usuario__off` TO `empresa_usuario`');
expectCase(is_array($d) && ($d['success'] ?? null) === false && is_string($d['message'] ?? null) && $d['message'] !== '', 'database failure still yields a JSON error with a generic message');

echo $failures === 0 ? "\nAll auth hardening cases passed\n" : "\n$failures auth hardening case(s) failed\n";
exit($failures === 0 ? 0 : 1);
