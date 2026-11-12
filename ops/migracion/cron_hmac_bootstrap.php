<?php
/**
 * Cervesur - generar la clave HMAC privada una sola vez (cPanel Cron / PHP CLI).
 *
 * Solo crea una clave aleatoria local de 256 bits fuera de public_html.
 * No conecta a MySQL y no imprime, registra ni publica el valor de la clave.
 * No reemplaza una clave existente: si existe se detiene de forma segura.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit(1);
}
ini_set('display_errors', '0');
error_reporting(E_ALL);
umask(0077);

$privateDir = realpath(__DIR__);
if ($privateDir === false ||
    preg_match('~/(?:public_html|htdocs|httpdocs|wwwroot)(?:/|$)~i', $privateDir) ||
    is_link(__DIR__)) {
    fwrite(STDERR, "CERVESUR_HMAC_BOOTSTRAP=INVALID_PRIVATE_DIRECTORY\n");
    exit(2);
}

$keyFile = $privateDir . '/.cervesur-migration-hmac';
$resultFile = $privateDir . '/cervesur-hmac-bootstrap-result.txt';
if (file_exists($resultFile) || is_link($resultFile)) {
    exit(0); // no regenerar al repetirse el cron
}

$status = 'NOT_STARTED';
$details = ['SECRET_VALUE_PRINTED' => 'NO', 'DATABASE_ACCESSED' => 'NO', 'PRODUCTIVE_DATA_MODIFIED' => 'NO'];
try {
    if (file_exists($keyFile) || is_link($keyFile)) {
        $status = 'KEY_ALREADY_EXISTS_NO_OVERWRITE';
    } else {
        $secret = bin2hex(random_bytes(32)); // 256 bits, 64 caracteres hex
        $handle = @fopen($keyFile, 'x');
        if ($handle === false) {
            throw new RuntimeException('KEY_FILE_CREATE_FAILED');
        }
        try {
            $n = fwrite($handle, $secret . "\n");
            if ($n !== strlen($secret) + 1) {
                throw new RuntimeException('KEY_FILE_WRITE_FAILED');
            }
        } finally {
            fclose($handle);
        }
        unset($secret);
        if (!@chmod($keyFile, 0600) || (fileperms($keyFile) & 0077) !== 0) {
            throw new RuntimeException('KEY_FILE_PERMISSIONS_FAILED');
        }
        $details['KEY_PERMISSIONS'] = '0600';
        $details['KEY_SIZE'] = '256_BITS';
        $status = 'PASS';
    }
} catch (Throwable $e) {
    // Los mensajes son codigos cerrados: no revelar ruta o variables secretas.
    $allowed = [
        'KEY_FILE_CREATE_FAILED', 'KEY_FILE_WRITE_FAILED',
        'KEY_FILE_PERMISSIONS_FAILED',
    ];
    $status = in_array($e->getMessage(), $allowed, true)
        ? $e->getMessage() : 'BOOTSTRAP_ERROR';
} finally {
    $lines = [
        'CERVESUR_HMAC_BOOTSTRAP=' . $status,
        'UTC=' . gmdate('Y-m-d\TH:i:s\Z'),
    ];
    foreach ($details as $name => $value) {
        $lines[] = $name . '=' . $value;
    }
    $out = @fopen($resultFile, 'x');
    if ($out !== false) {
        fwrite($out, implode("\n", $lines) . "\n");
        fclose($out);
        @chmod($resultFile, 0600);
    }
}
exit($status === 'PASS' ? 0 : 3);
