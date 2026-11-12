<?php
/**
 * D3/Cervesur: ejercita middleware REAL en CLI con datos y secreto SINTETICOS.
 * No usa .env, db.php, conexiones productivas, tokens reales ni HTTP.
 * Exige SHA-256 de middleware y jwt.php atestados antes de incluirlos.
 *
 * Uso: php d3_auth_synthetic.php [--backend /var/www/cervesur/backend]
 */
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '0');

if (PHP_SAPI !== 'cli') {
    echo "D3_AUTH=NO_GO_SAPI\n";
    exit(2);
}

$root = '/var/www/cervesur/backend';
if (count($argv) === 3 && $argv[1] === '--backend') {
    $root = $argv[2];
} elseif (count($argv) !== 1) {
    echo "D3_AUTH=NO_GO_ARGS\n";
    exit(2);
}
$realRoot = realpath($root);
if ($realRoot === false || !is_dir($realRoot) || is_link($root)) {
    echo "D3_AUTH=NO_GO_BACKEND\n";
    exit(2);
}

$expected = [
    'lib/auth_middleware.php' => 'c717dcb962eb6c01aed798bac48dfe126736ab918f3131ee509af380b5e1b298',
    'lib/jwt.php' => 'b2fe4af95848', // SHA historico atestado (prefijo de 12 hex).
];
foreach ($expected as $rel => $hash) {
    $file = $realRoot . '/' . $rel;
    if (!is_file($file) || is_link($file) || !str_starts_with((string)realpath($file), $realRoot . '/')) {
        echo "D3_AUTH=NO_GO_HUELLA_O_ARCHIVO\n";
        exit(2);
    }
    $actual = hash_file('sha256', $file);
    if ($actual === false || !str_starts_with($actual, $hash)) {
        echo "D3_AUTH=NO_GO_DRIFT\n";
        exit(2);
    }
}
if (!extension_loaded('pdo_sqlite') || function_exists('getallheaders')) {
    // Necesitamos simular cabeceras sin alterar servicios, y una base efimera en RAM.
    echo "D3_AUTH=NO_GO_PHP_EXTENSION_O_HEADER\n";
    exit(2);
}

$GLOBALS['d3_headers'] = [];
if (!function_exists('getallheaders')) {
    function getallheaders(): array {
        return $GLOBALS['d3_headers'];
    }
}

try {
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec('CREATE TABLE user (
        id INTEGER PRIMARY KEY, role TEXT, is_active INTEGER, session_version INTEGER
    )');
    $pdo->exec("INSERT INTO user VALUES
      (1, 'admin', 1, 1),
      (2, 'user', 1, 1),
      (3, 'user', 0, 1),
      (4, 'user', 1, 2)");
    // Esta inclusion exige H1 firmado y NO carga db.php ni .env.
    require_once $realRoot . '/lib/auth_middleware.php';
} catch (Throwable $e) {
    echo "D3_AUTH=NO_GO_FIXTURE\n";
    exit(2);
}

if (!function_exists('generate_jwt') || !function_exists('authenticate_user')) {
    echo "D3_AUTH=NO_GO_INTERFAZ\n";
    exit(2);
}

$secret = 'QA-D3-SINTETICO-NO-PRODUCTIVO-9a53f8e4c12285d06d70e77';
$checked = 0;
$failed = 0;

function issue(string $name, bool $passes): void {
    global $checked, $failed;
    $checked++;
    if (!$passes) $failed++;
    echo "D3_AUTH_" . $name . '=' . ($passes ? 'PASS' : 'FAIL') . "\n";
}
function claims(int $id, string $role, int $sv = 1): array {
    return [
        'user_id' => $id, 'username' => 'qa-sintetico',
        'email' => 'qa@invalid.example', 'role' => $role,
        'session_version' => $sv, 'exp' => time() + 600,
    ];
}
function authenticate_claims(array $payload, string $signSecret, string $verifySecret): mixed {
    $token = generate_jwt($payload, $signSecret);
    $GLOBALS['d3_headers'] = ['Authorization' => 'Bearer ' . $token];
    return authenticate_user($verifySecret);
}
function role_is(mixed $user, string $role): bool {
    return is_array($user) && ($user['role'] ?? null) === $role;
}
function denied(mixed $user): bool {
    return $user === false || $user === null;
}

try {
    issue('ADMIN_VIGENTE', role_is(authenticate_claims(claims(1, 'admin'), $secret, $secret), 'admin'));
    issue('USER_VIGENTE', role_is(authenticate_claims(claims(2, 'user'), $secret, $secret), 'user'));
    issue('INACTIVO_RECHAZADO', denied(authenticate_claims(claims(3, 'user'), $secret, $secret)));
    issue('SV_REVOCADO_RECHAZADO', denied(authenticate_claims(claims(4, 'user'), $secret, $secret)));
    issue('ID_INEXISTENTE_RECHAZADO', denied(authenticate_claims(claims(9999, 'admin'), $secret, $secret)));
    issue('FIRMA_AJENA_RECHAZADA', denied(authenticate_claims(claims(2, 'user'), 'QA-CLAVE-DISTINTA', $secret)));
    $expired = claims(2, 'user'); $expired['exp'] = time() - 600;
    issue('EXPIRADO_RECHAZADO', denied(authenticate_claims($expired, $secret, $secret)));
    $GLOBALS['d3_headers'] = [];
    issue('SIN_CABECERA_RECHAZADA', denied(authenticate_user($secret)));
    $GLOBALS['d3_headers'] = ['Authorization' => 'Bearer cadena-sin-firma'];
    issue('TOKEN_MALFORMADO_RECHAZADO', denied(authenticate_user($secret)));

    // Son HALLAZGOS RESIDUALES, no fallos ocultos. No invalidan los PASS anteriores,
    // pero necesitan criterio compensatorio en P4b o un correctivo separado.
    $forgedRole = authenticate_claims(claims(2, 'admin'), $secret, $secret);
    echo 'D3_RIESGO_ROL_DESDE_JWT=' .
        (role_is($forgedRole, 'admin') ? 'HALLAZGO' : (role_is($forgedRole, 'user') ? 'MITIGADO_DB' : 'REVISAR')) . "\n";
    $missingSv = claims(2, 'user'); unset($missingSv['session_version']);
    echo 'D3_RIESGO_TOKEN_SIN_SV=' .
        (denied(authenticate_claims($missingSv, $secret, $secret)) ? 'RECHAZADO' : 'HALLAZGO_COMPATIBLE') . "\n";
    $missingExp = claims(2, 'user'); unset($missingExp['exp']);
    echo 'D3_RIESGO_TOKEN_SIN_EXP=' .
        (denied(authenticate_claims($missingExp, $secret, $secret)) ? 'RECHAZADO' : 'HALLAZGO_VALIDACION') . "\n";
} catch (Throwable $e) {
    echo "D3_AUTH=NO_GO_EXCEPCION\n";
    exit(2);
}

echo 'D3_AUTH_CRITICOS=' . ($failed === 0 ? 'PASS' : 'NO_GO') . '_' . ($checked - $failed) . '_DE_' . $checked . "\n";
echo "D3_CIERRE_GLOBAL=PENDIENTE_OTROS_VECTORES_Y_FIRMAS\n";
echo "DATOS_REALES_LEIDOS=NO\n";
echo "MYSQL_PRODUCTIVO_MODIFICADO=NO\n";
echo "U_BASIC_ACTIVADO=NO\n";
exit($failed === 0 ? 0 : 2);
