<?php
// UX-08I · P4a · Desactivación de administradores y concurrencia: ninguna operación NO sensible de Usuarios puede dejar al sistema sin administrador activo, ni siquiera con dos administradores
// que se desactivan mutuamente a la vez. Se ejecuta DENTRO de `appawb` (cuenta 2B no sensible, ADMIN_WRITES_USUARIOS=1). Entorno: TEST_DB_PASS.
// Los administradores ajenos a la prueba se desactivan por SQL (root) y se restauran al final; la bitácora no se toca.
$failures = 0;
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$root = new PDO('mysql:host=db;dbname=fixture_aw;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), $opts);
$estado = new PDO('mysql:host=db;dbname=estado_aw;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), $opts);
function api($method, $path, $token = null, $body = null) {
    $h = ['Content-Type: application/json', 'X-API-Contract: 2', 'X-Forwarded-Proto: https'];
    if ($token) $h[] = 'Authorization: Bearer ' . $token;
    $o = ['http' => ['method' => $method, 'header' => implode("\r\n", $h), 'ignore_errors' => true, 'timeout' => 30]];
    if ($body !== null) $o['http']['content'] = json_encode($body);
    $raw = (string)@file_get_contents("http://127.0.0.1:8080$path", false, stream_context_create($o));
    $st = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int)$m[1] : 0;
    return [$st, json_decode($raw, true), $raw];
}
function ok($c, $m) { global $failures; echo ($c ? 'PASS ' : 'FAIL ') . $m . "\n"; if (!$c) $failures++; }
function one($pdo, $sql, $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }
function login($u) { [, $d] = api('POST', '/api/login', null, ['login' => $u, 'password' => 'synthetic-password']); return $d['token'] ?? ''; }
$activos = fn () => array_map('intval', $root->query("SELECT id FROM user WHERE role = 'admin' AND is_active = 1 ORDER BY id")->fetchAll(PDO::FETCH_COLUMN));
$auditoria = fn ($accion) => (int)one($root, "SELECT COUNT(*) FROM audit_log WHERE entity = 'user' AND action = ?", [$accion]);

$root->exec("DELETE FROM user WHERE username IN ('conc_a','conc_b','conc_u')");
$hash = (string)one($root, 'SELECT password FROM user WHERE id = 1');
$ins = $root->prepare("INSERT INTO user (username, email, password, first_name, last_name, role, is_active, session_version, created_at, updated_at) VALUES (?, ?, ?, 'C', ?, ?, 1, 1, NOW(), NOW())");
foreach ([['conc_a', 'admin'], ['conc_b', 'admin'], ['conc_u', 'user']] as [$u, $r]) { $ins->execute([$u, "$u@example.invalid", $hash, $u, $r]); }
$estado->exec("DELETE FROM usuarios_piloto WHERE nota = 'p4a concurrencia'");
$A = (int)one($root, "SELECT id FROM user WHERE username = 'conc_a'"); $B = (int)one($root, "SELECT id FROM user WHERE username = 'conc_b'"); $U = (int)one($root, "SELECT id FROM user WHERE username = 'conc_u'");
foreach ([(int)one($root, "SELECT id FROM user WHERE username = 'conc_a'"), (int)one($root, "SELECT id FROM user WHERE username = 'conc_b'")] as $idw) { $estado->exec("INSERT INTO usuarios_piloto (user_id, nota, activo, creado_en, creado_por) VALUES ($idw, 'p4a concurrencia', 1, UTC_TIMESTAMP(), 'test')"); }
$otros = array_values(array_diff($activos(), [$A, $B]));
$ta = login('conc_a'); $tb = login('conc_b');
ok($ta !== '' && $tb !== '' && count($activos()) >= 3, 'hay al menos 3 administradores activos (dos de la prueba y el administrador de base)');

echo "== 1. Reglas de desactivación con la cuenta NO sensible\n";
[$s] = api('POST', "/api/users/$A/deactivate", $ta);
ok($s === 409 && in_array($A, $activos(), true), 'un administrador NO puede desactivarse a sí mismo (409) y sigue activo');
$d0 = $auditoria('user.deactivate');
[$s] = api('POST', "/api/users/$U/deactivate", $ta);
ok($s === 200 && (int)one($root, 'SELECT is_active FROM user WHERE id = ?', [$U]) === 0 && $auditoria('user.deactivate') === $d0 + 1, 'desactivar a un usuario común: 200, una auditoría, y su sesión se invalida (session_version)');
api('POST', "/api/users/$U/reactivate", $ta); // reactivación bloqueada por la espera de 24 h: 409, sin efecto
ok((int)one($root, 'SELECT is_active FROM user WHERE id = ?', [$U]) === 0, 'reactivar antes de la espera: sigue inactivo (protección de sesiones antiguas de Flutter)');

echo "== 2. Dos administradores que se desactivan mutuamente A LA VEZ (con más administradores de respaldo)\n";
$fuente = static function ($tok, $id, $when) {
    return 'while (microtime(true) < ' . $when . ') { usleep(200); } $o = ["http" => ["method" => "POST", "header" => "Content-Type: application/json\r\nX-API-Contract: 2\r\nX-Forwarded-Proto: https\r\nAuthorization: Bearer ' . $tok . '", "ignore_errors" => true, "timeout" => 30, "content" => "{}"]]; @file_get_contents("http://127.0.0.1:8080/api/users/' . $id . '/deactivate", false, stream_context_create($o)); preg_match("/ (\\\\d{3}) /", $http_response_header[0] ?? "", $m); echo $m[1] ?? 0;';
};
$carrera = function ($tokA, $idObjetivoA, $tokB, $idObjetivoB) use ($fuente) {
    $when = microtime(true) + 2.0; $procs = [];
    foreach ([[$tokA, $idObjetivoA], [$tokB, $idObjetivoB]] as $i => [$tok, $id]) {
        $procs[$i] = proc_open([PHP_BINARY, '-r', $fuente($tok, $id, $when)], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes[$i]);
    }
    $out = [];
    foreach ($procs as $i => $p) { $out[$i] = (int)trim(stream_get_contents($pipes[$i][1])); proc_close($p); }
    return $out;
};
// con respaldo: se permite que uno de los dos caiga, nunca los dos del mismo golpe si fueran los únicos
[$ra, $rb] = $carrera($ta, $B, $tb, $A);
$quedan = array_values(array_intersect($activos(), [$A, $B]));
echo "INFO respuestas de la carrera con respaldo: A→B=$ra · B→A=$rb · activos de la pareja: " . json_encode($quedan) . "\n";
ok(count($activos()) >= count($otros) && count($otros) >= 1, 'tras la carrera con respaldo siguen activos los administradores ajenos a la pareja');
ok($ra > 0 && $rb > 0 && $ra < 500 && $rb < 500 && ($ra === 200 || $rb === 200), 'ninguna respuesta fue 5xx y al menos una operación se aplicó (200)');

echo "== 3. Los DOS únicos administradores se desactivan mutuamente a la vez: NUNCA se queda el sistema sin administrador\n";
foreach ([$A, $B] as $id) { $root->exec("UPDATE user SET is_active = 1, session_version = session_version + 1 WHERE id = $id"); }
if ($otros) { $root->exec('UPDATE user SET is_active = 0 WHERE id IN (' . implode(',', $otros) . ')'); }
$ta = login('conc_a'); $tb = login('conc_b');
ok(count($activos()) === 2 && $ta !== '' && $tb !== '', 'preparación: exactamente 2 administradores activos');
$d1 = $auditoria('user.deactivate');
[$ra, $rb] = $carrera($ta, $B, $tb, $A);
$activosFin = $activos();
echo "INFO respuestas: A→B=$ra · B→A=$rb · administradores activos: " . json_encode($activosFin) . "\n";
ok(count($activosFin) === 1, 'queda EXACTAMENTE un administrador activo (la segunda desactivación se rechazó por el cerrojo de administradores)');
ok((($ra === 200) xor ($rb === 200)) && in_array($ra === 200 ? $rb : $ra, [401, 409], true), 'una operación se aplicó (200) y la otra fue rechazada con 409/401, sin 5xx');
ok($auditoria('user.deactivate') === $d1 + 1, 'exactamente UNA auditoría user.deactivate por la carrera (la rechazada no deja rastro)');
$ganador = $activosFin[0] ?? 0;
$perdedor = $ganador === $A ? $B : $A;
ok((int)one($root, 'SELECT is_active FROM user WHERE id = ?', [$perdedor]) === 0, 'el administrador desactivado quedó inactivo');
[$s] = api('POST', "/api/users/$ganador/deactivate", $ganador === $A ? $ta : $tb);
ok($s === 409 && $activos() === [$ganador], 'el último administrador no puede desactivarse a sí mismo (409)');

echo "== 4. Restauración\n";
foreach ($otros as $id) { $root->exec("UPDATE user SET is_active = 1 WHERE id = $id"); }
$estado->exec("DELETE FROM usuarios_piloto WHERE nota = 'p4a concurrencia'");
$root->exec("DELETE FROM user WHERE username IN ('conc_a','conc_b','conc_u')");
ok(count($activos()) === count($otros), 'administradores originales restaurados');
echo $failures === 0 ? "\nAll p4a concurrencia checks passed\n" : "\n$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
