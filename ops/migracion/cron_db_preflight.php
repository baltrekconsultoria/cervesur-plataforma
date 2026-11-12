<?php
/**
 * Cervesur - comprobacion minima de acceso MySQL con transaccion READ ONLY.
 * Solo CLI. Sin leer filas ni imprimir credenciales. Produce un resultado 0600.
 * Compatible con PHP 8.1 (hosting cPanel legado).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
ini_set('display_errors', '0');
error_reporting(E_ALL);
umask(0077);

$privateDirectory = realpath(__DIR__);
if ($privateDirectory === false ||
    preg_match('~/(?:public_html|htdocs|httpdocs|wwwroot)(?:/|$)~i', $privateDirectory)) {
    exit(2);
}
$resultPath = $privateDirectory . '/cervesur-db-preflight-result.txt';
if (file_exists($resultPath) || is_link($resultPath)) {
    exit(0); // prueba de una sola ejecucion
}

$code = 'NOT_STARTED';
$checks = [
    'PHP_CLI' => 'PASS',
    'EXPECTED_DATABASE' => 'databalt_portalbi',
    'DB_CONNECTED' => 'NO',
    'DATABASE_MATCH' => 'NO',
    'READ_ONLY_TRANSACTION' => 'NO',
    'REQUIRED_TABLES_PRESENT' => 'UNKNOWN',
];

try {
    $opts = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!preg_match('/^--([a-z-]+)=(.+)$/D', $arg, $match)) {
            throw new RuntimeException('INVALID_ARGUMENTS');
        }
        $opts[$match[1]] = $match[2];
    }
    if (!isset($opts['loader']) || !str_starts_with($opts['loader'], '/')) {
        throw new RuntimeException('LOADER_ARG_MISSING');
    }
    $loader = realpath($opts['loader']);
    if ($loader === false || !is_file($loader) ||
        is_link($opts['loader']) || basename($loader) !== 'env.php') {
        throw new RuntimeException('LOADER_NOT_FOUND');
    }

    // El cargador lee la configuracion existente de la aplicacion; no se imprime.
    require_once $loader;
    $host = getenv('DB_HOST');
    $db = getenv('DB_NAME');
    $user = getenv('DB_USER');
    $password = getenv('DB_PASS');
    if (!$host || !$db || !$user || $password === false) {
        throw new RuntimeException('DB_ENV_INCOMPLETE');
    }
    if ($db !== 'databalt_portalbi') {
        throw new RuntimeException('WRONG_DATABASE');
    }
    if (!extension_loaded('pdo_mysql')) {
        throw new RuntimeException('PDO_MYSQL_UNAVAILABLE');
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
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'databalt_portalbi') {
        throw new RuntimeException('WRONG_DATABASE');
    }
    $checks['DATABASE_MATCH'] = 'PASS';

    // Falla sin realizar consultas de datos si no se puede garantizar READ ONLY.
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    $checks['READ_ONLY_TRANSACTION'] = 'PASS';

    $sql = "SELECT COUNT(*) FROM information_schema.tables
            WHERE table_schema = DATABASE()
              AND table_name IN ('user', 'empresa', 'reporte',
                                 'empresa_usuario', 'empresa_reporte',
                                 'user_empresa_reporte')";
    $tableCount = (int)$pdo->query($sql)->fetchColumn();
    $checks['REQUIRED_TABLES_PRESENT'] = $tableCount . '/6';
    $pdo->rollBack();
    $code = $tableCount === 6 ? 'PASS' : 'SCHEMA_REVIEW_REQUIRED';
} catch (Throwable $error) {
    // No se imprimen mensajes de PDO ni variables del entorno, solo codigos cerrados.
    $allowed = [
        'INVALID_ARGUMENTS', 'LOADER_ARG_MISSING', 'LOADER_NOT_FOUND',
        'DB_ENV_INCOMPLETE', 'WRONG_DATABASE', 'PDO_MYSQL_UNAVAILABLE',
    ];
    $code = in_array($error->getMessage(), $allowed, true)
        ? $error->getMessage() : 'CONNECTION_OR_READONLY_ERROR';
} finally {
    $checks['DB_DATA_MODIFIED'] = 'NO';
    $checks['SECRET_VALUES_PRINTED'] = 'NO';
    $lines = ['CERVESUR_DB_PREFLIGHT=' . $code, 'UTC=' . gmdate('Y-m-d\TH:i:s\Z')];
    foreach ($checks as $key => $value) {
        $lines[] = $key . '=' . $value;
    }
    $payload = implode("\n", $lines) . "\n";
    $file = @fopen($resultPath, 'x');
    if ($file !== false) {
        fwrite($file, $payload);
        fclose($file);
        @chmod($resultPath, 0600);
    }
}
exit($code === 'PASS' ? 0 : 3);
