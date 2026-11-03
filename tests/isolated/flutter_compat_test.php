<?php
// UX-08H · Fase 2B · Compatibilidad con Flutter (RECONSTRUIDA).
// Flutter y React comparten las tablas `user`/`empresa`/`reporte`. Este arnés comprueba qué ocurre con las SESIONES de Flutter cuando el administrador de React cambia usuarios.
// IMPORTANTE: no se dispone del código PHP real de Flutter en este entorno; `flutter_*` reproduce SOLO lo verificado en la evidencia del servidor (07):
//   - login con `AND is_active = 1` y password_verify; JWT HS256 con exp = now + JWT_EXPIRATION (86400 s por defecto);
//   - el middleware revisa `is_active` en CADA petición y compara `session_version` SOLO si el token lo trae; el rol sale del JWT;
//   - logout incrementa `session_version`.
// Cuando el operador entregue una copia de los 33 archivos PHP de /var/www/cervesur/backend (sin .env), este arnés se repite contra ellos (ver docs/UX-08H-INFORME-QA.md).
// Instancias: appaw (usuarios + sensibles, sin espera) · appawus (usuarios, sin sensibles, espera 24 h). Entorno: TEST_DB_PASS, JWT_SECRET.
$failures = 0;
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$root = new PDO('mysql:host=db;dbname=fixture_aw;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), $opts);
function expectCase($c, $label) { global $failures; if ($c) echo "PASS $label\n"; else { echo "FAIL $label\n"; $failures++; } }
function one($pdo, $sql, $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }
function api($host, $method, $path, $token = null, $body = null) {
    $h = ['Content-Type: application/json', 'X-API-Contract: 2']; if ($token) $h[] = 'Authorization: Bearer ' . $token;
    $o = ['http' => ['method' => $method, 'header' => implode("\r\n", $h), 'ignore_errors' => true, 'timeout' => 20]]; if ($body !== null) $o['http']['content'] = json_encode($body);
    $raw = (string)@file_get_contents("http://$host:8080$path", false, stream_context_create($o));
    $st = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int)$m[1] : 0;
    return [$st, json_decode($raw, true)];
}
function b64($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function jwt(array $claims, $secret) { $h = b64(json_encode(['typ' => 'JWT', 'alg' => 'HS256'])); $p = b64(json_encode($claims)); return "$h.$p." . b64(hash_hmac('sha256', "$h.$p", $secret, true)); }
function jwt_read($t, $secret) { $x = explode('.', $t); if (count($x) !== 3 || !hash_equals(b64(hash_hmac('sha256', "$x[0].$x[1]", $secret, true)), $x[2])) return null; $c = json_decode(base64_decode(strtr($x[1], '-_', '+/')), true); return ($c['exp'] ?? 0) > time() ? $c : null; }
// --- Referencia de Flutter (reconstruida) ---
function flutter_login(PDO $db, $username, $password, $secret, $legacy = false) {
    $s = $db->prepare('SELECT id, username, password, role, session_version FROM user WHERE username = ? AND is_active = 1'); $s->execute([$username]); $u = $s->fetch();
    if (!$u || !password_verify($password, $u['password'])) return null;
    $claims = ['user_id' => (int)$u['id'], 'username' => $u['username'], 'role' => $u['role'], 'exp' => time() + 86400];
    if (!$legacy) $claims['session_version'] = (int)$u['session_version'];
    return jwt($claims, $secret);
}
function flutter_request(PDO $db, $token, $secret) { // devuelve el rol con el que Flutter atendería la petición, o null si la rechaza
    $c = jwt_read($token, $secret); if (!$c) return null;
    $s = $db->prepare('SELECT is_active, session_version FROM user WHERE id = ?'); $s->execute([$c['user_id']]); $u = $s->fetch();
    if (!$u || (int)$u['is_active'] !== 1) return null;
    if (isset($c['session_version']) && (int)$c['session_version'] !== (int)$u['session_version']) return null;
    return $c['role'];
}
$secret = (string)getenv('JWT_SECRET');
$pw = 'long-enough-password';
function react_token($host) { [, $d] = api($host, 'POST', '/api/login', null, ['login' => 'admin', 'password' => 'synthetic-password']); return $d['token'] ?? ''; }
function make_user($host, $tok, $name) {
    [$s] = api($host, 'POST', '/api/register', $tok, ['first_name' => 'Fl', 'last_name' => 'Compat', 'username' => $name, 'email' => "$name@example.invalid", 'password' => 'long-enough-password']);
    return $s;
}

echo "== F1 · un usuario creado desde React entra por Flutter\n";
$ta = react_token('appaw');
make_user('appaw', $ta, 'flutter.f1');
$id1 = (int)one($root, "SELECT id FROM user WHERE username = 'flutter.f1'");
$tok = flutter_login($root, 'flutter.f1', $pw, $secret);
expectCase($tok !== null && flutter_request($root, $tok, $secret) === one($root, 'SELECT role FROM user WHERE id = ?', [$id1]), 'el hash que escribe React lo acepta el login de Flutter y el rol por defecto no es administrador');
expectCase(one($root, 'SELECT role FROM user WHERE id = ?', [$id1]) !== 'admin', 'el rol de alta nunca es «admin»');

echo "== F2 · desactivar: Flutter deja de atender las sesiones abiertas\n";
$modern = flutter_login($root, 'flutter.f1', $pw, $secret); $legacy = flutter_login($root, 'flutter.f1', $pw, $secret, true);
api('appaw', 'POST', "/api/users/$id1/deactivate", $ta, []);
expectCase(flutter_request($root, $modern, $secret) === null && flutter_request($root, $legacy, $secret) === null, 'token moderno (con session_version) y token antiguo (sin él): ambos rechazados por is_active');
expectCase(flutter_login($root, 'flutter.f1', $pw, $secret) === null, 'y no puede iniciar sesión en Flutter');

echo "== F3 · reactivar SIN espera (appaw, 0 h): R2 documentado\n";
api('appaw', 'POST', "/api/users/$id1/reactivate", $ta, []);
expectCase(flutter_request($root, $modern, $secret) === null, 'el token moderno sigue muerto (session_version subió)');
expectCase(flutter_request($root, $legacy, $secret) !== null, 'RIESGO R2 CONFIRMADO: un token antiguo sin session_version REVIVE al reactivar (por eso producción usa la espera)');

echo "== F4 · reactivar CON espera (appawus, 24 h): el riesgo R2 queda cerrado\n";
$tus = react_token('appawus');
make_user('appawus', $tus, 'flutter.f4');
$id4 = (int)one($root, "SELECT id FROM user WHERE username = 'flutter.f4'");
$legacy4 = flutter_login($root, 'flutter.f4', $pw, $secret, true);
api('appawus', 'POST', "/api/users/$id4/deactivate", $tus, []);
[$s] = api('appawus', 'POST', "/api/users/$id4/reactivate", $tus, []);
expectCase($s === 409 && flutter_request($root, $legacy4, $secret) === null, 'reactivar antes de 24 h → 409 y el token antiguo sigue rechazado');
// Simula que pasaron 25 h: la bitácora es de solo inserción, así que SOLO en esta base desechable y como root se quita y se vuelve a poner el trigger.
$root->exec('DROP TRIGGER IF EXISTS audit_log_no_update');
$root->exec("UPDATE audit_log SET occurred_at = occurred_at - INTERVAL 25 HOUR WHERE entity = 'user' AND entity_id = $id4 AND action = 'user.deactivate'");
$root->exec("CREATE TRIGGER audit_log_no_update BEFORE UPDATE ON audit_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log es de solo insercion'");
[$s] = api('appawus', 'POST', "/api/users/$id4/reactivate", $tus, []);
expectCase($s === 200 && (int)one($root, 'SELECT is_active FROM user WHERE id = ?', [$id4]) === 1, 'pasadas las 24 h la reactivación se permite');

echo "== F5 · cambio de ROL (appaw, con sensibles): R3 documentado\n";
make_user('appaw', $ta, 'flutter.f5');
$id5 = (int)one($root, "SELECT id FROM user WHERE username = 'flutter.f5'");
$m5 = flutter_login($root, 'flutter.f5', $pw, $secret); $l5 = flutter_login($root, 'flutter.f5', $pw, $secret, true);
api('appaw', 'PUT', "/api/user/$id5/role", $ta, ['role' => 'admin']);
expectCase(flutter_request($root, $m5, $secret) === null, 'token moderno invalidado por el cambio de rol (session_version)');
expectCase(flutter_request($root, $l5, $secret) === 'user', 'RIESGO R3 CONFIRMADO: el token antiguo conserva el rol VIEJO que trae el JWT hasta que vence (por eso rol y contraseña van tras un interruptor aparte)');
[$s] = api('appawus', 'PUT', "/api/user/$id5/role", $tus, ['role' => 'user']);
expectCase($s === 403, 'en la instancia 2B por defecto el cambio de rol ni siquiera se ofrece (403)');

echo "== F6 · cambio de CONTRASEÑA\n";
make_user('appaw', $ta, 'flutter.f6');
$id6 = (int)one($root, "SELECT id FROM user WHERE username = 'flutter.f6'");
$m6 = flutter_login($root, 'flutter.f6', $pw, $secret); $l6 = flutter_login($root, 'flutter.f6', $pw, $secret, true);
api('appaw', 'PUT', "/api/users/$id6/password", $ta, ['password' => 'another-long-password']);
expectCase(flutter_request($root, $m6, $secret) === null && flutter_login($root, 'flutter.f6', $pw, $secret) === null && flutter_login($root, 'flutter.f6', 'another-long-password', $secret) !== null, 'la contraseña nueva rige en Flutter y la vieja ya no entra; el token moderno cae');
expectCase(flutter_request($root, $l6, $secret) !== null, 'RIESGO R4 CONFIRMADO: el token antiguo sobrevive al cambio de contraseña (hasta que vence)');

echo "== F7 · session_version compartido: el cierre de sesión de Flutter también cierra la de React\n";
$tr = react_token('appaw');
[$s0] = api('appaw', 'GET', '/api/empresas', $tr);
$root->exec('UPDATE user SET session_version = session_version + 1 WHERE id = 1'); // lo que hace el logout de Flutter
[$s1] = api('appaw', 'GET', '/api/empresas', $tr);
expectCase($s0 === 200 && $s1 === 401, 'si el administrador cierra sesión en Flutter, su sesión de React deja de valer (documentado en la guía de operación)');

echo "== F8 · el administrador no puede dejar a Flutter sin administradores\n";
$ta = react_token('appaw');
$admins = (int)one($root, "SELECT COUNT(*) FROM user WHERE role = 'admin' AND is_active = 1");
$solo = (int)one($root, "SELECT id FROM user WHERE role = 'admin' AND is_active = 1 ORDER BY id LIMIT 1");
foreach ($root->query("SELECT id FROM user WHERE role = 'admin' AND is_active = 1 AND id <> $solo")->fetchAll(PDO::FETCH_COLUMN) as $other) { api('appaw', 'POST', "/api/users/$other/deactivate", $ta, []); }
[$s] = api('appaw', 'POST', "/api/users/$solo/deactivate", $ta, []);
expectCase($s !== 200 && (int)one($root, 'SELECT is_active FROM user WHERE id = ?', [$solo]) === 1, 'no se puede desactivar al último administrador activo (ni a uno mismo)');

echo "\n" . ($failures === 0 ? 'All Flutter compatibility checks passed' : "$failures check(s) FAILED") . "\n";
exit($failures === 0 ? 0 : 1);
