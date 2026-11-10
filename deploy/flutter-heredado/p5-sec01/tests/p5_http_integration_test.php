<?php
/**
 * P5-SEC01: PHP HTTP real en loopback con SQLite TEMPORAL y JWT sintéticos.
 * NO usa base, archivos .env, certificados ni secretos de producción.
 */
if (!extension_loaded('pdo_sqlite') || !function_exists('proc_open')) {
    fwrite(STDERR, "P5_SEC01_HTTP=NO_RUN: faltan pdo_sqlite/proc_open\n");
    exit(2);
}
$root = __DIR__;
$database = sys_get_temp_dir() . '/p5-sec01-' . bin2hex(random_bytes(8)) . '.sqlite';
$secret = 'P5_qa_synthetic_secret_2026_never_used_outside_tests';
$port = random_int(20000, 27000);
$server = null;
$passes = 0;
$fails = 0;
$now = time();

function check_http($description, $ok) {
    global $passes, $fails;
    if ($ok) {
        $passes++;
        echo "PASS: $description\n";
    } else {
        $fails++;
        echo "FAIL: $description\n";
    }
}
function b64test($raw) {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}
function sign_test($claims, $key, $header = ['typ' => 'JWT', 'alg' => 'HS256']) {
    $message = b64test(json_encode($header, JSON_THROW_ON_ERROR)) . '.' .
        b64test(json_encode($claims, JSON_THROW_ON_ERROR));
    return $message . '.' . b64test(hash_hmac('sha256', $message, $key, true));
}
function http_test($port, $path, $token = null, $method = 'GET') {
    $headers = ['Accept: application/json'];
    if ($token !== null) {
        $headers[] = 'Authorization: ' . $token;
    }
    $opts = ['http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers) . "\r\n",
        'timeout' => 5,
        'ignore_errors' => true
    ]];
    $body = @file_get_contents(
        'http://127.0.0.1:' . $port . $path,
        false,
        stream_context_create($opts)
    );
    $line = $http_response_header[0] ?? '';
    preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $match);
    return [(int)($match[1] ?? 0), is_string($body) ? $body : ''];
}

try {
    $pdo = new PDO('sqlite:' . $database);
    chmod($database, 0600);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE user (id INTEGER PRIMARY KEY, username TEXT, email TEXT, role TEXT, is_active INTEGER, session_version INTEGER)');
    $pdo->exec("INSERT INTO user VALUES
        (1,'admin_q','admin@qa.invalid','admin',1,1),
        (2,'user_q','user@qa.invalid','user',1,1),
        (3,'historico_q','historico@qa.invalid','usuario',1,1)");

    $env = [
        'PATH' => (string)getenv('PATH'),
        'P5_TEST_ONLY' => '1',
        'P5_TEST_DB' => $database,
        'P5_TEST_SECRET' => $secret
    ];
    $command = [PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root, $root . '/p5_legacy_http_router.php'];
    $processOptions = ['bypass_shell' => true];
    $server = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => ['file', '/dev/null', 'w'],
        2 => ['file', '/dev/null', 'w']
    ], $pipes, $root, $env, $processOptions);
    if (!is_resource($server)) {
        throw new RuntimeException('PHP server no pudo iniciarse');
    }
    fclose($pipes[0]);
    $ready = false;
    for ($i = 0; $i < 35; $i++) {
        $conn = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.15);
        if (is_resource($conn)) {
            fclose($conn);
            $ready = true;
            break;
        }
        usleep(100000);
    }
    if (!$ready) {
        throw new RuntimeException('PHP server no responde en loopback');
    }

    $user = ['user_id' => 2, 'session_version' => 1, 'exp' => $now + 600,
        'username' => 'user_q', 'email' => 'user@qa.invalid', 'role' => 'user'];
    $admin = ['user_id' => 1, 'session_version' => 1, 'exp' => $now + 600,
        'username' => 'admin_q', 'email' => 'admin@qa.invalid', 'role' => 'admin'];
    $historico = ['user_id' => 3, 'session_version' => 1, 'exp' => $now + 600,
        'username' => 'historico_q', 'email' => 'historico@qa.invalid', 'role' => 'usuario'];
    $u = 'Bearer ' . sign_test($user, $secret);
    $a = 'Bearer ' . sign_test($admin, $secret);
    $h = 'Bearer ' . sign_test($historico, $secret);
    [$s, $body] = http_test($port, '/qa/me');
    check_http('401 sin Authorization', $s === 401 && is_array($anon = json_decode($body, true))
        && str_contains((string)($anon['message'] ?? ''), 'Token inválido'));
    [$s] = http_test($port, '/qa/me', 'Basic abc');
    check_http('401 sin Bearer', $s === 401);
    [$s, $body] = http_test($port, '/qa/me', $u);
    check_http('200 sesion de usuario', $s === 200 && ($j = json_decode($body, true)) && $j['role'] === 'user');
    [$s] = http_test($port, '/qa/admin-only', $u);
    check_http('403 usuario en area admin', $s === 403);
    [$s, $body] = http_test($port, '/qa/admin-only', $a);
    check_http('200 administrador vigente', $s === 200 && json_decode($body, true)['role'] === 'admin');
    [$s, $body] = http_test($port, '/qa/me', $h);
    check_http('200 rol historico normalizado', $s === 200 && json_decode($body, true)['role'] === 'user');

    [$s] = http_test($port, '/qa/admin-only', 'Bearer ' . sign_test(array_merge($user, ['role' => 'admin']), $secret));
    check_http('403 rol forjado en payload firmado', $s === 403);
    $pdo->exec("UPDATE user SET role='user' WHERE id=1");
    [$s] = http_test($port, '/qa/admin-only', $a);
    check_http('403 admin degradado incluso sin cambiar version', $s === 403);
    $pdo->exec('UPDATE user SET session_version=session_version+1 WHERE id=1');
    [$s] = http_test($port, '/qa/me', $a);
    check_http('401 token viejo tras degradacion y version+1', $s === 401);

    $pdo->exec('UPDATE user SET session_version=session_version+1 WHERE id=2');
    [$s] = http_test($port, '/qa/me', $u);
    check_http('401 tras cambio de contrasena y version+1', $s === 401);
    $user['session_version'] = 2;
    $u2 = 'Bearer ' . sign_test($user, $secret);
    [$s] = http_test($port, '/qa/me', $u2);
    check_http('200 token renovado despues de login', $s === 200);
    $pdo->exec('UPDATE user SET is_active=0 WHERE id=2');
    [$s] = http_test($port, '/qa/me', $u2);
    check_http('401 desactivacion', $s === 401);
    $pdo->exec('UPDATE user SET is_active=1 WHERE id=2');

    foreach (['session_version', 'exp', 'user_id'] as $claim) {
        $invalid = $user;
        unset($invalid[$claim]);
        [$s] = http_test($port, '/qa/me', 'Bearer ' . sign_test($invalid, $secret));
        check_http('401 sin ' . $claim, $s === 401);
    }
    [$s] = http_test($port, '/qa/me', 'Bearer ' . sign_test($user, 'otro-secreto'));
    check_http('401 firma incorrecta', $s === 401);
    [$s] = http_test($port, '/qa/me', 'Bearer ' . sign_test($user, $secret, ['typ' => 'JWT', 'alg' => 'none']));
    check_http('401 algoritmo none', $s === 401);
    [$s] = http_test($port, '/qa/me', 'Bearer ' . sign_test(array_merge($user, ['exp' => $now - 1]), $secret));
    check_http('401 token caducado', $s === 401);
    [$s] = http_test($port, '/qa/no-existe', $u2);
    check_http('404 no expone otras rutas', $s === 404);
    [$s] = http_test($port, '/qa/me', $u2, 'POST');
    check_http('404 no permite escritura', $s === 404);
    $pdo->exec("UPDATE user SET role='desconocido' WHERE id=2");
    [$s] = http_test($port, '/qa/me', $u2);
    check_http('401 rol desconocido', $s === 401);
    $pdo->exec('DROP TABLE user');
    [$s, $body] = http_test($port, '/qa/me', $u2);
    check_http('401 ante falla de BD sin detalles internos', $s === 401
        && !str_contains($body, 'SQLSTATE') && !str_contains($body, $secret));
} catch (Throwable $e) {
    $fails++;
    fwrite(STDERR, "FAIL de arnes de QA: " . get_class($e) . "\n");
} finally {
    if (is_resource($server)) {
        proc_terminate($server);
        proc_close($server);
    }
    $pdo = null;
    if (is_file($database)) {
        unlink($database);
    }
}
echo "P5_SEC01_HTTP=$passes PASS / $fails FAIL\n";
exit($fails === 0 && $passes >= 20 ? 0 : 1);
