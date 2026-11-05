<?php
// UX-08I · Condición previa a P4a: ¿pueden las operaciones PERMITIDAS de Usuarios (cuenta 2B «no sensibles») usarse para crear usuarios con rol elevado o modificar credenciales
// sensibles fuera de los controles autorizados? Se prueba en tres niveles: (1) API, (2) guardia de SQL, (3) motor MySQL con la cuenta real. Informa también las BRECHAS que persisten.
// Se ejecuta DENTRO de la instancia `appawb` (cuenta cervesur_escritura2b_aw, ADMIN_WRITES_USUARIOS=1, sensible ausente). Entorno: TEST_DB_PASS, W_WRITE2A_PASS.
$failures = 0; $gaps = [];
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$root = new PDO('mysql:host=db;dbname=fixture_aw;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), $opts);
$acct = new PDO('mysql:host=db;dbname=fixture_aw;charset=utf8mb4', 'cervesur_escritura2b_aw', getenv('W_WRITE2A_PASS'), $opts);
function api($method, $path, $token = null, $body = null) {
    $h = ['Content-Type: application/json', 'X-API-Contract: 2', 'X-Forwarded-Proto: https'];
    if ($token) $h[] = 'Authorization: Bearer ' . $token;
    $o = ['http' => ['method' => $method, 'header' => implode("\r\n", $h), 'ignore_errors' => true, 'timeout' => 20]];
    if ($body !== null) $o['http']['content'] = is_string($body) ? $body : json_encode($body);
    $raw = (string)@file_get_contents("http://127.0.0.1:8080$path", false, stream_context_create($o));
    $st = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int)$m[1] : 0;
    return [$st, json_decode($raw, true), $raw];
}
function ok($c, $label) { global $failures; echo ($c ? 'PASS ' : 'FAIL ') . $label . "\n"; if (!$c) $failures++; }
function gap($label) { global $gaps; $gaps[] = $label; echo "BRECHA $label\n"; }
function denied(PDO $p, $sql) { try { $p->exec($sql); return false; } catch (PDOException $e) { return (bool)preg_match('/denied|1142|1143|1044/i', $e->getMessage()) ? true : $e->getMessage(); } }
$root->exec("DELETE FROM user WHERE username LIKE 'esc_%'");

echo "== 1. API: el administrador no puede fijar el rol de un usuario nuevo\n";
[, $d] = api('POST', '/api/login', null, ['login' => 'admin', 'password' => 'synthetic-password']);
$t = $d['token'] ?? '';
ok($t !== '', 'sesión de administrador');
$base = ['first_name' => 'E', 'last_name' => 'S', 'password' => 'Aa1!escalamiento-x'];
$n = 0; $malos = [];
foreach (['role', 'is_active', 'session_version', 'id'] as $k) {
    [$s] = api('POST', '/api/register', $t, $base + ['username' => "esc_$k", 'email' => "esc_$k@example.invalid", $k => $k === 'role' ? 'admin' : 9]);
    if ($s !== 400) $malos[] = "$k=>$s";
}
ok(!$malos, 'role / is_active / session_version / id en el cuerpo: 400 (' . ($malos ? implode(',', $malos) : 'las 4') . ')');
// variantes que NO son el nombre exacto: no deben tener ningún efecto sobre el rol almacenado
$variantes = ['Role' => 'admin', 'ROLE' => 'admin', 'role ' => 'admin', "role\0" => 'admin', 'user_role' => 'admin', 'rol' => 'admin', 'roles' => ['admin']];
foreach ($variantes as $k => $v) {
    $n++;
    $u = "esc_v$n";
    [$s, $dd] = api('POST', '/api/register', $t, json_encode($base + ['username' => $u, 'email' => "$u@example.invalid", $k => $v]));
    $rol = $root->query("SELECT role FROM user WHERE username = '$u'")->fetchColumn();
    if ($s === 200 && $rol !== 'user' && $rol !== 'usuario') $malos[] = "$k=>$rol";
}
ok(!$malos, 'variantes del nombre del campo (Role, ROLE, "role ", rol, roles…): si el alta prospera, el rol almacenado es SIEMPRE el fijado por el servidor' . ($malos ? ' · ' . implode(',', $malos) : ''));
[$s, $dd] = api('POST', '/api/register', $t, ['first_name' => 'E', 'last_name' => 'S', 'username' => 'esc_json', 'email' => 'esc_json@example.invalid', 'password' => 'Aa1!escalamiento-x', 'phone' => ['role' => 'admin']]);
ok($s === 400, 'valor no escalar en un campo: 400');
ok((int)$root->query("SELECT COUNT(*) FROM user WHERE username LIKE 'esc_%' AND role NOT IN ('user','usuario')")->fetchColumn() === 0, 'ningún usuario creado por la API tiene un rol distinto de user/usuario');
[$s1] = api('PUT', '/api/user/2/role', $t, ['role' => 'admin']); [$s2] = api('PUT', '/api/users/2/password', $t, ['password' => 'Aa1!otra-clave-larga']);
ok($s1 === 403 && $s2 === 403, 'las rutas de rol y contraseña de usuarios EXISTENTES siguen cerradas (403)');
[$s] = api('PUT', '/api/users/2', $t, ['role' => 'admin', 'password' => 'x', 'username' => 'nuevo', 'is_active' => 1, 'session_version' => 99]);
ok(in_array($s, [200, 400], true) && $root->query('SELECT CONCAT(role,"|",username) FROM user WHERE id = 2')->fetchColumn() === 'user|usera', 'la edición ignora role, password, username, is_active y session_version del cuerpo');

echo "== 2. Guardia de SQL\n";
require_once '/work/backend/lib/mode.php';
putenv('DB_NAME=cervesur_portalbi'); putenv('ADMIN_WRITES_USUARIOS=1'); putenv('ADMIN_WRITES_USUARIOS_SENSIBLE');
$g = function ($sql) { try { app_sql_write_guard($sql); return true; } catch (WriteViolation $e) { return false; } };
ok(!$g("UPDATE user SET role = 'admin' WHERE id = 1") && !$g('UPDATE user SET password = ? WHERE id = 1'), 'UPDATE de role y de password rechazados');
ok(!$g("INSERT INTO user (username, role) VALUES ('a','admin') ON DUPLICATE KEY UPDATE role = 'admin'") && !$g("REPLACE INTO user (id, role) VALUES (1,'admin')"), 'INSERT … ON DUPLICATE KEY UPDATE y REPLACE rechazados (no sirven para modificar filas existentes)');
ok(!$g("DELETE FROM user WHERE id = 2") && !$g("INSERT INTO user (username) SELECT username FROM user"), 'DELETE e INSERT … SELECT rechazados');
ok(!$g("INSERT INTO user (first_name, last_name, username, email, phone, password, role, is_active, session_version, created_at, updated_at) VALUES ('a','b','c','d','e','f','admin',1,1,NOW(),NOW())")
    && !$g("INSERT INTO user (first_name, last_name, username, email, phone, password, role, is_active, session_version, created_at, updated_at) VALUES (:first_name, :last_name, :username, :email, :phone, :password, 'admin', 1, 1, NOW(), NOW())")
    && !$g("INSERT INTO user (first_name, last_name, username, email, phone, password, role, is_active, session_version, created_at, updated_at) VALUES (:first_name, :last_name, :username, :email, :phone, :password, :role, 1, 1, NOW(), NOW())"),
    'el guardia rechaza un INSERT en user con role «admin» (literal o parámetro): solo admite la forma EXACTA del alta, con rol literal user/usuario');

echo "== 3. Motor MySQL con la cuenta real (plantilla 2B no sensibles)\n";
ok(denied($acct, "UPDATE user SET role = 'admin' WHERE id = 0") === true, 'UPDATE(role) denegado por el motor');
ok(denied($acct, "UPDATE user SET password = 'x' WHERE id = 0") === true, 'UPDATE(password) denegado por el motor');
ok(denied($acct, "UPDATE user SET username = 'x' WHERE id = 0") === true, 'UPDATE(username) denegado');
ok(denied($acct, "INSERT INTO user (id, username, role, is_active, session_version) VALUES (7777, 'esc_id', 'user', 1, 1)") === true, 'INSERT fijando el id explícito denegado (id no está concedido)');
ok(denied($acct, "INSERT INTO user (username, role, is_active, session_version, password) VALUES ('esc_dup', 'user', 1, 1, 'h') ON DUPLICATE KEY UPDATE password = 'x'") === true, 'INSERT … ON DUPLICATE KEY UPDATE password denegado (necesitaría UPDATE(password))');
ok(denied($acct, "DELETE FROM user WHERE id = 0") === true && denied($acct, "REPLACE INTO user (username, role, is_active, session_version) VALUES ('esc_rep','admin',1,1)") === true, 'DELETE y REPLACE denegados');
ok(denied($acct, 'SELECT password FROM user LIMIT 1') === true, 'la cuenta no puede LEER password');
// la brecha estructural del valor del rol: con la vista de alta la cuenta ya no puede insertar en la tabla ni fijar un rol elevado
$direct = denied($acct, "INSERT INTO user (username, role, is_active, session_version, password, email, first_name, last_name, created_at, updated_at) VALUES ('esc_admin_directo', 'admin', 1, 1, 'hash-ficticio', 'esc_admin@example.invalid', 'a', 'b', NOW(), NOW())");
ok($direct === true, 'INSERT directo en user con role=admin: DENEGADO por el motor (la cuenta no tiene INSERT sobre la tabla)');
try { $acct->exec("INSERT INTO v_user_alta (username, role, is_active, session_version, password, email, first_name, last_name, created_at, updated_at) VALUES ('esc_admin_vista', 'admin', 1, 1, 'hash-ficticio', 'esc_admin2@example.invalid', 'a', 'b', NOW(), NOW())"); ok(false, 'la vista admitió role=admin'); }
catch (PDOException $e) { ok(str_contains($e->getMessage(), 'CHECK OPTION') || str_contains($e->getMessage(), '1369'), 'INSERT con role=admin por la vista de alta: RECHAZADO por el motor (CHECK OPTION)'); }
ok((int)$root->query("SELECT COUNT(*) FROM user WHERE username LIKE 'esc_admin%'")->fetchColumn() === 0, 'no quedó ningún usuario administrador creado por la cuenta de React');
ok(denied($acct, "UPDATE user SET email = 'x@example.invalid' WHERE id = 0") === true, 'UPDATE(email) denegado por el motor');
$root->exec("DELETE FROM user WHERE username LIKE 'esc_%'");

echo "\nBRECHAS RESIDUALES: " . (count($gaps) ?: 'ninguna') . "\n";
echo $failures === 0 ? "All P4 escalamiento checks passed\n" : "$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
