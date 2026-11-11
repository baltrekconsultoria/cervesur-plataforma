<?php
/** P5-SEC01: matriz autónoma, sin JWT_SECRET real, sin usuarios ni DB productivos. */
require_once __DIR__ . '/../candidato/p5_strict_auth.php';

if (!extension_loaded('pdo_sqlite')) {
    fwrite(STDERR, "P5_SEC01_TEST=NO_RUN: PDO SQLite no está instalado\n");
    exit(2);
}

$now = 1700000000;
$secret = str_repeat('synthetic-only-p5-', 3);
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE user (id INTEGER PRIMARY KEY, username TEXT, email TEXT, role TEXT, is_active INTEGER, session_version INTEGER)");
$pdo->exec("INSERT INTO user VALUES (1,'admin_qa','admin@qa.invalid','admin',1,1)");
$pdo->exec("INSERT INTO user VALUES (2,'normal_qa','normal@qa.invalid','user',1,1)");
$pdo->exec("INSERT INTO user VALUES (3,'hist_qa','hist@qa.invalid','usuario',1,1)");

function p5_b64($value) {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}
function p5_sign($payload, $secret, $header = ['typ' => 'JWT', 'alg' => 'HS256']) {
    $a = p5_b64(json_encode($header, JSON_THROW_ON_ERROR)) . '.' .
         p5_b64(json_encode($payload, JSON_THROW_ON_ERROR));
    return $a . '.' . p5_b64(hash_hmac('sha256', $a, $secret, true));
}

$base = [
    'user_id' => 2, 'session_version' => 1, 'exp' => $now + 600,
    'username' => 'normal_qa', 'email' => 'normal@qa.invalid', 'role' => 'user'
];

$passed = 0;
$failed = 0;
function p5_check($title, $condition) {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS: $title\n";
    } else {
        $failed++;
        echo "FAIL: $title\n";
    }
}
function p5_verify($payload, $secret, $pdo, $now) {
    return p5_strict_authenticate_jwt(p5_sign($payload, $secret), $secret, $pdo, $now);
}

$validUser = p5_verify($base, $secret, $pdo, $now);
p5_check('login user moderno', is_array($validUser) && $validUser['role'] === 'user');
$adm = p5_verify(array_merge($base, ['user_id' => 1, 'role' => 'admin']), $secret, $pdo, $now);
p5_check('login admin moderno', is_array($adm) && $adm['role'] === 'admin');
$hist = p5_verify(array_merge($base, ['user_id' => 3, 'role' => 'usuario']), $secret, $pdo, $now);
p5_check('rol heredado usuario', is_array($hist) && $hist['role'] === 'user');

$falso = p5_verify(array_merge($base, ['role' => 'admin', 'username' => 'suplantado']), $secret, $pdo, $now);
p5_check('role admin de token ignorado: manda BD', is_array($falso) && $falso['role'] === 'user' && $falso['username'] === 'normal_qa');

$pdo->exec("UPDATE user SET role='user' WHERE id=1");
$rebajado = p5_verify(array_merge($base, ['user_id' => 1, 'role' => 'admin']), $secret, $pdo, $now);
p5_check('degradación sin incremento usa rol DB', is_array($rebajado) && $rebajado['role'] === 'user');
$pdo->exec("UPDATE user SET session_version=session_version+1 WHERE id=1");
p5_check('degradación con version+1 revoca token', p5_verify(array_merge($base, ['user_id' => 1, 'role' => 'admin']), $secret, $pdo, $now) === false);

$pdo->exec("UPDATE user SET session_version=session_version+1 WHERE id=2");
p5_check('cambio de contraseña revoca token anterior', p5_verify($base, $secret, $pdo, $now) === false);
$base['session_version'] = 2;
p5_check('token nuevo tras relogin válido', p5_verify($base, $secret, $pdo, $now) !== false);
$pdo->exec("UPDATE user SET is_active=0 WHERE id=2");
p5_check('usuario inactivo 401', p5_verify($base, $secret, $pdo, $now) === false);
$pdo->exec("UPDATE user SET is_active=1 WHERE id=2");
p5_check('reactivación con nueva versión no revive token', p5_verify(array_merge($base, ['session_version' => 1]), $secret, $pdo, $now) === false);

foreach ([
    'sin user_id' => ['user_id'],
    'sin session_version' => ['session_version'],
    'sin exp' => ['exp'],
    'sin username' => ['username'],
    'sin email' => ['email']
] as $label => $missing) {
    $broken = $base;
    foreach ($missing as $key) { unset($broken[$key]); }
    p5_check($label . ' rechazado', p5_verify($broken, $secret, $pdo, $now) === false);
}
foreach ([
    'user_id texto' => ['user_id' => '2'],
    'session_version texto' => ['session_version' => '2'],
    'exp texto' => ['exp' => (string)($now + 100)],
    'exp nulo' => ['exp' => null],
    'exp caducado' => ['exp' => $now - 1],
    'exp lejano' => ['exp' => $now + 86400 * 9],
    'user_id cero' => ['user_id' => 0]
] as $label => $changes) {
    p5_check($label . ' rechazado', p5_verify(array_merge($base, $changes), $secret, $pdo, $now) === false);
}

$good = p5_sign($base, $secret);
p5_check('firma distinta', p5_strict_authenticate_jwt(p5_sign($base, 'otro-secreto'), $secret, $pdo, $now) === false);
p5_check('token sin las tres partes', p5_strict_authenticate_jwt('aaaa.bbbb', $secret, $pdo, $now) === false);
p5_check('token demasiado largo', p5_strict_authenticate_jwt(str_repeat('a', 9000), $secret, $pdo, $now) === false);
p5_check('Bearer fuera del contrato', p5_strict_authenticate_jwt('Bearer ' . $good, $secret, $pdo, $now) === false);
p5_check('alg none', p5_strict_authenticate_jwt(p5_sign($base, $secret, ['typ' => 'JWT', 'alg' => 'none']), $secret, $pdo, $now) === false);
p5_check('alg HS512', p5_strict_authenticate_jwt(p5_sign($base, $secret, ['typ' => 'JWT', 'alg' => 'HS512']), $secret, $pdo, $now) === false);
p5_check('payload no objeto', p5_strict_authenticate_jwt(p5_sign(123, $secret), $secret, $pdo, $now) === false);
p5_check('token de usuario inexistente', p5_verify(array_merge($base, ['user_id' => 999]), $secret, $pdo, $now) === false);

// P5-SEC03: la sesión real 10800 s no se amplía por el helper aislado (86400).
$ttlUser = array_merge($base, ['exp' => $now + 10800]);
p5_check('TTL real 10800 acepta token vigente', p5_strict_authenticate_jwt(
    p5_sign($ttlUser, $secret), $secret, $pdo, $now, 10800) !== false);
p5_check('TTL real 10800 admite tolerancia 60 s', p5_strict_authenticate_jwt(
    p5_sign(array_merge($base, ['exp' => $now + 10860]), $secret),
    $secret, $pdo, $now, 10800) !== false);
p5_check('TTL real 10800 rechaza 10861', p5_strict_authenticate_jwt(
    p5_sign(array_merge($base, ['exp' => $now + 10861]), $secret),
    $secret, $pdo, $now, 10800) === false);
p5_check('TTL real 10800 rechaza token con 24h restantes', p5_strict_authenticate_jwt(
    p5_sign(array_merge($base, ['exp' => $now + 86400]), $secret),
    $secret, $pdo, $now, 10800) === false);
p5_check('TTL nulo o no entero no se permite en ruta estricta', p5_strict_authenticate_jwt(
    p5_sign($ttlUser, $secret), $secret, $pdo, $now, '10800') === false);
p5_check('TTL mayor a 24h prohibido', p5_strict_authenticate_jwt(
    p5_sign($ttlUser, $secret), $secret, $pdo, $now, 172800) === false);

$pdo->exec("UPDATE user SET role='otro' WHERE id=2");
p5_check('rol desconocido denegado', p5_verify($base, $secret, $pdo, $now) === false);
$pdo->exec("UPDATE user SET role='user' WHERE id=2");
$pdo->exec("DROP TABLE user");
p5_check('fallo de BD denegado', p5_verify($base, $secret, $pdo, $now) === false);

echo "P5_SEC01_TEST=$passed PASS / $failed FAIL\n";
exit($failed === 0 && $passed >= 30 ? 0 : 1);
