<?php
/**
 * P5-SEC01: MySQL 8 REAL, but exclusively in GitHub Actions isolated service.
 * Only synthetic users, signatures, passwords and ephemeral p5_sec01_ci database.
 *
 * THIS FILE MUST NEVER RUN AGAINST THE CERVESUR PRODUCTIVE MYSQL SERVER.
 */
require_once __DIR__ . '/../candidato/p5_strict_auth.php';

if (getenv('P5_MYSQL_ISOLATED_ONLY') !== '1' || getenv('CI') !== 'true'
    || getenv('P5_MYSQL_HOST') !== '127.0.0.1'
    || getenv('P5_MYSQL_DB') !== 'p5_sec01_ci'
    || !extension_loaded('pdo_mysql')) {
    fwrite(STDERR, "P5_MYSQL=NO_RUN: isolated CI preflight denied\n");
    exit(2);
}
$password = getenv('P5_MYSQL_PASSWORD');
if (!is_string($password) || $password === '') {
    fwrite(STDERR, "P5_MYSQL=NO_RUN: synthetic database credentials missing\n");
    exit(2);
}

$passed = 0;
$failed = 0;
function p5_mysql_check($label, $condition) {
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "PASS: $label\n";
    } else {
        $failed++;
        echo "FAIL: $label\n";
    }
}
function p5_mysql_b64($raw) {
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}
function p5_mysql_token($claims, $key) {
    $msg = p5_mysql_b64('{"typ":"JWT","alg":"HS256"}') . '.' .
        p5_mysql_b64(json_encode($claims, JSON_THROW_ON_ERROR));
    return $msg . '.' . p5_mysql_b64(hash_hmac('sha256', $msg, $key, true));
}
function p5_mysql_auth($claims, $secret, PDO $pdo) {
    return p5_strict_authenticate_jwt(p5_mysql_token($claims, $secret), $secret, $pdo);
}

$root = null;
$pdo = null;
$created = false;
try {
    $root = new PDO('mysql:host=127.0.0.1;port=3306;charset=utf8mb4', 'root', $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    // Avoid destructive cleanup on a pre-existing or wrong environment.
    $existing = $root->query("SELECT COUNT(*) FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME = 'p5_sec01_ci'")->fetchColumn();
    if ((int)$existing !== 0) {
        throw new RuntimeException('Pre-existing QA database detected; refusing to modify it');
    }
    $root->exec('CREATE DATABASE p5_sec01_ci CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    $pdo = new PDO('mysql:host=127.0.0.1;port=3306;dbname=p5_sec01_ci;charset=utf8mb4', 'root', $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    $pdo->exec('CREATE TABLE user (id INT PRIMARY KEY, username VARCHAR(80) NOT NULL, email VARCHAR(100) NOT NULL, role VARCHAR(30) NOT NULL, is_active TINYINT NOT NULL, session_version INT NOT NULL)');
    $pdo->exec("INSERT INTO user VALUES
        (1,'admin_qa','admin@qa.invalid','admin',1,1),
        (2,'user_qa','user@qa.invalid','user',1,1),
        (3,'hist_qa','hist@qa.invalid','usuario',1,1)");

    $key = 'synthetic-only-not-a-production-jwt-secret';
    $now = time();
    $user = ['user_id' => 2, 'session_version' => 1, 'exp' => $now + 600,
        'username' => 'user_qa', 'email' => 'user@qa.invalid', 'role' => 'user'];
    $admin = array_merge($user, ['user_id' => 1, 'username' => 'admin_qa', 'email' => 'admin@qa.invalid', 'role' => 'admin']);
    $historical = array_merge($user, ['user_id' => 3, 'username' => 'hist_qa', 'email' => 'hist@qa.invalid', 'role' => 'usuario']);

    p5_mysql_check('MySQL real: user autentica', (p5_mysql_auth($user, $key, $pdo)['role'] ?? null) === 'user');
    p5_mysql_check('MySQL real: admin autentica', (p5_mysql_auth($admin, $key, $pdo)['role'] ?? null) === 'admin');
    p5_mysql_check('MySQL real: rol usuario heredado', (p5_mysql_auth($historical, $key, $pdo)['role'] ?? null) === 'user');
    p5_mysql_check('MySQL real: token user role admin no eleva', (p5_mysql_auth(array_merge($user, ['role' => 'admin']), $key, $pdo)['role'] ?? null) === 'user');

    $pdo->exec("UPDATE user SET role='user' WHERE id=1");
    p5_mysql_check('MySQL real: admin degradado usa rol DB', (p5_mysql_auth($admin, $key, $pdo)['role'] ?? null) === 'user');
    $pdo->exec('UPDATE user SET session_version=session_version+1 WHERE id=1');
    p5_mysql_check('MySQL real: admin antiguo queda revocado', p5_mysql_auth($admin, $key, $pdo) === false);

    $pdo->exec('UPDATE user SET session_version=session_version+1 WHERE id=2');
    p5_mysql_check('MySQL real: contraseña nueva revoca sesión antigua', p5_mysql_auth($user, $key, $pdo) === false);
    $user['session_version'] = 2;
    p5_mysql_check('MySQL real: nueva sesión admitida', (p5_mysql_auth($user, $key, $pdo)['role'] ?? null) === 'user');
    $pdo->exec('UPDATE user SET is_active=0 WHERE id=2');
    p5_mysql_check('MySQL real: usuario inactivo 401 lógico', p5_mysql_auth($user, $key, $pdo) === false);
    $pdo->exec('UPDATE user SET is_active=1,session_version=session_version+1 WHERE id=2');
    p5_mysql_check('MySQL real: reactivación no revive sesión vieja', p5_mysql_auth($user, $key, $pdo) === false);

    $user['session_version'] = 3;
    p5_mysql_check('MySQL real: versión actual después de reactivar', (p5_mysql_auth($user, $key, $pdo)['role'] ?? null) === 'user');
    $without = $user; unset($without['session_version']);
    p5_mysql_check('MySQL real: token sin version rechazado', p5_mysql_auth($without, $key, $pdo) === false);
    $without = $user; unset($without['exp']);
    p5_mysql_check('MySQL real: token sin expiración rechazado', p5_mysql_auth($without, $key, $pdo) === false);
    p5_mysql_check('MySQL real: firma ajena rechazada',
        p5_strict_authenticate_jwt(p5_mysql_token($user, 'another-test-key'), $key, $pdo) === false);
    $pdo->exec("UPDATE user SET role='unknown' WHERE id=2");
    p5_mysql_check('MySQL real: rol desconocido denegado', p5_mysql_auth($user, $key, $pdo) === false);
    $pdo->exec('DROP TABLE user');
    p5_mysql_check('MySQL real: fallo SQL falla cerrado', p5_mysql_auth($user, $key, $pdo) === false);
} catch (Throwable $e) {
    $failed++;
    fwrite(STDERR, 'P5_MYSQL_HARNESS_ERROR=' . get_class($e) . "\n");
} finally {
    $pdo = null;
    if ($created && $root instanceof PDO) {
        try {
            $root->exec('DROP DATABASE p5_sec01_ci');
        } catch (Throwable $e) {
            $failed++;
            fwrite(STDERR, "P5_MYSQL_CLEANUP=FAIL\n");
        }
    }
    $root = null;
}
echo "P5_MYSQL_REAL=$passed PASS / $failed FAIL\n";
exit($failed === 0 && $passed === 16 ? 0 : 1);
