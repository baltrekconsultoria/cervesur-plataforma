<?php
/**
 * Cervesur - Preflight solo lectura del MySQL local (Ubuntu).
 * No exporta identidades, enlaces ni secretos, no realiza DML.
 * Uso: php ubuntu_db_preflight.php --loader=/var/www/cervesur/backend/lib/env.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
ini_set('display_errors', '0');

$checks = [
    'PHP_CLI' => 'PASS',
    'EXPECTED_DATABASE' => 'cervesur_portalbi',
    'DB_CONNECTED' => 'NO',
    'DATABASE_MATCH' => 'NO',
    'READ_ONLY_TRANSACTION' => 'NO',
    'REQUIRED_TABLES_PRESENT' => 'UNKNOWN',
    'DB_DATA_MODIFIED' => 'NO',
    'SECRET_VALUES_PRINTED' => 'NO',
];
$code = 'NOT_STARTED';
$pdo = null;
try {
    if (count($argv) !== 2 || !str_starts_with($argv[1], '--loader=')) {
        throw new RuntimeException('LOADER_ARGUMENT_INVALID');
    }
    $argPath = substr($argv[1], strlen('--loader='));
    $loader = realpath($argPath);
    if ($loader === false || !is_file($loader) || is_link($argPath)
        || basename($loader) !== 'env.php') {
        throw new RuntimeException('LOADER_NOT_FOUND');
    }
    if (!extension_loaded('pdo_mysql')) {
        throw new RuntimeException('PDO_MYSQL_UNAVAILABLE');
    }
    // El loader de esta aplicacion lee backend/.env automaticamente.
    // Comprobar primero su legibilidad para no producir warnings ni
    // confundir una denegacion del sistema operativo con una falla MySQL.
    $envFile = dirname($loader, 2) . DIRECTORY_SEPARATOR . '.env';
    if (!is_file($envFile)) {
        throw new RuntimeException('ENV_NOT_FOUND');
    }
    if (!is_readable($envFile)) {
        throw new RuntimeException('ENV_PERMISSION_DENIED');
    }
    require_once $loader;
    $host = getenv('DB_HOST');
    $db = getenv('DB_NAME');
    $user = getenv('DB_USER');
    $password = getenv('DB_PASS');
    if (!$host || !$db || !$user || $password === false) {
        throw new RuntimeException('DB_ENV_INCOMPLETE');
    }
    if ($db !== 'cervesur_portalbi') {
        throw new RuntimeException('WRONG_DATABASE');
    }
    $pdo = new PDO(
        'mysql:host=' . $host . ';dbname=' . $db . ';charset=utf8mb4',
        $user,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 8,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    $checks['DB_CONNECTED'] = 'PASS';
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'cervesur_portalbi') {
        throw new RuntimeException('WRONG_DATABASE');
    }
    $checks['DATABASE_MATCH'] = 'PASS';
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    $checks['READ_ONLY_TRANSACTION'] = 'PASS';
    $sql = "SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name IN ('user', 'empresa', 'reporte',
                                 'empresa_usuario', 'empresa_reporte',
                                 'user_empresa_reporte')";
    $n = (int) $pdo->query($sql)->fetchColumn();
    $checks['REQUIRED_TABLES_PRESENT'] = $n . '/6';
    $code = $n === 6 ? 'PASS' : 'SCHEMA_REVIEW_REQUIRED';
} catch (Throwable $e) {
    $known = ['LOADER_ARGUMENT_INVALID', 'LOADER_NOT_FOUND',
        'PDO_MYSQL_UNAVAILABLE', 'ENV_NOT_FOUND', 'ENV_PERMISSION_DENIED',
        'DB_ENV_INCOMPLETE', 'WRONG_DATABASE'];
    $code = in_array($e->getMessage(), $known, true)
        ? $e->getMessage() : 'CONNECTION_OR_READONLY_ERROR';
} finally {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo 'CERVESUR_UBUNTU_DB_PREFLIGHT=' . $code . "\n";
    foreach ($checks as $name => $value) {
        echo $name . '=' . $value . "\n";
    }
}
exit($code === 'PASS' ? 0 : 2);
