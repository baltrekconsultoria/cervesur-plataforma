<?php
/**
 * Cervesur: prueba unica de PHP CLI por cPanel Cron Jobs.
 * No lee variables de entorno, archivos .env ni bases de datos.
 * Guardar exclusivamente fuera del directorio publico.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}

umask(0077);
$directory = realpath(__DIR__);
if ($directory === false || preg_match('~/(?:public_html|htdocs|httpdocs|wwwroot)(?:/|$)~i', $directory)) {
    fwrite(STDERR, "CERVESUR_CRON_PREFLIGHT=INVALID_PRIVATE_DIRECTORY\n");
    exit(2);
}

$resultPath = $directory . DIRECTORY_SEPARATOR . 'cervesur-cron-preflight-result.txt';

// Un solo resultado: los disparos cron posteriores no modifican el archivo.
if (file_exists($resultPath)) {
    exit(0);
}

$checks = [
    'PHP_CLI' => 'PASS',
    'PHP_VERSION_MIN_8_0' => PHP_VERSION_ID >= 80000 ? 'PASS' : 'FAIL',
    'PDO' => extension_loaded('pdo') ? 'PASS' : 'FAIL',
    'PDO_MYSQL' => extension_loaded('pdo_mysql') ? 'PASS' : 'FAIL',
    'JSON' => extension_loaded('json') ? 'PASS' : 'FAIL',
    'HASH_HMAC' => function_exists('hash_hmac') ? 'PASS' : 'FAIL',
    'PRIVATE_DIRECTORY_WRITABLE' => is_writable($directory) ? 'PASS' : 'FAIL',
];

$status = in_array('FAIL', $checks, true) ? 'CHECK' : 'PASS';
$lines = [
    'CERVESUR_CRON_PREFLIGHT=' . $status,
    'UTC=' . gmdate('Y-m-d\TH:i:s\Z'),
    'PHP_VERSION=' . PHP_VERSION,
];
foreach ($checks as $name => $result) {
    $lines[] = $name . '=' . $result;
}
$lines[] = 'DB_ACCESS=NOT_ATTEMPTED';
$lines[] = 'PRODUCTIVE_DATA_MODIFIED=NO';
$lines[] = 'SECRETS_READ=NO';

$handle = @fopen($resultPath, 'x');
if ($handle === false) {
    fwrite(STDERR, "CERVESUR_CRON_PREFLIGHT=RESULT_FILE_ERROR\n");
    exit(3);
}
try {
    $written = fwrite($handle, implode("\n", $lines) . "\n");
    if ($written === false || $written < strlen(implode("\n", $lines) . "\n")) {
        fwrite(STDERR, "CERVESUR_CRON_PREFLIGHT=RESULT_WRITE_ERROR\n");
        exit(4);
    }
} finally {
    fclose($handle);
}
@chmod($resultPath, 0600);
exit($status === 'PASS' ? 0 : 5);
