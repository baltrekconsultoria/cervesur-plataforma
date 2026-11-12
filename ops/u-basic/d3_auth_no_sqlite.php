<?php
/**
 * Cervesur D3/H1: fixture aislado sin driver SQLite, sin MySQL ni secretos reales.
 * No importa lib/db.php, no lee .env, no escribe ni hace HTTP.
 *
 * Uso:
 *   php d3_auth_no_sqlite.php --fixture-test
 *   sudo php d3_auth_no_sqlite.php --backend /var/www/cervesur/backend
 */
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '0');

if (PHP_SAPI !== 'cli' || !extension_loaded('PDO')) {
    echo "D3_AUTH=NO_GO_ENTORNO_CLI_PDO\n";
    exit(2);
}

/** PDOStatement sintético de SOLO SELECT para la llamada de H1. */
final class D3MemoryStatement extends PDOStatement {
    private array $users;
    private array|false $current = false;

    public function __construct(array $users) {
        $this->users = $users;
    }

    public function execute(?array $params = null): bool {
        if ($params === null || array_keys($params) !== ['id'] || !is_numeric($params['id'])) {
            throw new RuntimeException('D3_SQL_UNEXPECTED_PARAMS');
        }
        $id = (int)$params['id'];
        $this->current = $this->users[$id] ?? false;
        return true;
    }

    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0
    ): mixed {
        if ($mode !== PDO::FETCH_ASSOC || $cursorOrientation !== PDO::FETCH_ORI_NEXT || $cursorOffset !== 0) {
            throw new RuntimeException('D3_SQL_UNEXPECTED_FETCH');
        }
        $row = $this->current;
        $this->current = false;
        return $row;
    }
}

/** No abre conexiones: el ctor del padre NO se ejecuta. */
final class D3MemoryPDO extends PDO {
    public int $queries = 0;
    private array $users = [
        1 => ['session_version' => 1, 'is_active' => 1, 'role' => 'admin'],
        2 => ['session_version' => 1, 'is_active' => 1, 'role' => 'user'],
        3 => ['session_version' => 1, 'is_active' => 0, 'role' => 'user'],
        4 => ['session_version' => 2, 'is_active' => 1, 'role' => 'user'],
    ];
    public function __construct() {
        // Sin parent::__construct: imposible usar DSN/credenciales del sistema.
    }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        if ($options !== [] || !preg_match(
            '/^\s*SELECT\s+session_version\s*,\s*is_active\s*,\s*role\s+FROM\s+user\s+WHERE\s+id\s*=\s*:id\s+LIMIT\s+1\s*$/i',
            $query
        )) {
            throw new RuntimeException('D3_SQL_QUERY_NOT_ALLOWLISTED');
        }
        $this->queries++;
        return new D3MemoryStatement($this->users);
    }
}

if (count($argv) === 2 && $argv[1] === '--fixture-test') {
    try {
        $test = new D3MemoryPDO();
        $valid = $test instanceof PDO;
        $statement = $test->prepare(
            'SELECT session_version, is_active, role FROM user WHERE id = :id LIMIT 1'
        );
        $valid = $valid && $statement->execute(['id' => 1]);
        $valid = $valid && $statement->fetch(PDO::FETCH_ASSOC) ===
            ['session_version' => 1, 'is_active' => 1, 'role' => 'admin'];
        $statement->execute(['id' => 900]);
        $valid = $valid && $statement->fetch(PDO::FETCH_ASSOC) === false;
        try {
            $test->prepare('UPDATE user SET role = "admin" WHERE id=2');
            $valid = false;
        } catch (RuntimeException $exception) {
            $valid = $valid && $exception->getMessage() === 'D3_SQL_QUERY_NOT_ALLOWLISTED';
        }
        echo "D3_FIXTURE_ONLY=" . ($valid ? "PASS" : "FAIL") . "\n";
        echo "D3_AUTH_REAL=NO_EJECUTADO\n";
        exit($valid ? 0 : 2);
    } catch (Throwable $exception) {
        echo "D3_FIXTURE_ONLY=NO_GO\n";
        exit(2);
    }
}
$backend = '/var/www/cervesur/backend';
if (count($argv) === 3 && $argv[1] === '--backend') {
    $backend = $argv[2];
} elseif (count($argv) !== 1) {
    echo "D3_AUTH=NO_GO_ARGUMENTOS\n";
    exit(2);
}

$backendReal = realpath($backend);
if ($backendReal === false || !is_dir($backendReal) || is_link($backend)) {
    echo "D3_AUTH=NO_GO_BACKEND\n";
    exit(2);
}
$expected = [
    'lib/auth_middleware.php' => 'c717dcb962eb6c01aed798bac48dfe126736ab918f3131ee509af380b5e1b298',
    'lib/jwt.php' => 'b2fe4af95848',
];
foreach ($expected as $rel => $shaPrefix) {
    $path = $backendReal . '/' . $rel;
    if (!is_file($path) || is_link($path) || !str_starts_with((string)realpath($path), $backendReal . '/')) {
        echo "D3_AUTH=NO_GO_FUENTE\n";
        exit(2);
    }
    $shaActual = hash_file('sha256', $path);
    if ($shaActual === false || !str_starts_with($shaActual, $shaPrefix)) {
        echo "D3_AUTH=NO_GO_HASH_DRIFT\n";
        exit(2);
    }
}
if (function_exists('getallheaders')) {
    echo "D3_AUTH=NO_GO_HEADERS_CLI\n";
    exit(2);
}

$GLOBALS['d3_headers'] = [];
if (!function_exists('getallheaders')) {
    function getallheaders(): array {
        return $GLOBALS['d3_headers'];
    }
}

try {
    $pdo = new D3MemoryPDO();
    require_once $backendReal . '/lib/auth_middleware.php';
    if (!function_exists('generate_jwt') || !function_exists('authenticate_user')) {
        echo "D3_AUTH=NO_GO_INTERFAZ_H1\n";
        exit(2);
    }
} catch (Throwable $exception) {
    echo "D3_AUTH=NO_GO_PREPARACION_H1\n";
    exit(2);
}

$secret = 'D3-QA-LAB-SOLO-FICTICIO-6a95b06cdda8efa41bf5b7';
$checked = 0;
$failed = 0;

function d3_case(string $name, bool $ok): void {
    global $checked, $failed;
    $checked++;
    if (!$ok) $failed++;
    echo "D3_AUTH_" . $name . "=" . ($ok ? 'PASS' : 'FAIL') . "\n";
}
function d3_claims(int $id, string $role, int $version = 1): array {
    return [
        'user_id' => $id,
        'username' => 'cuenta-qa',
        'email' => 'qa@invalid.example',
        'role' => $role,
        'session_version' => $version,
        'exp' => time() + 600,
    ];
}
function d3_auth(array $payload, string $signingSecret, string $verificationSecret): mixed {
    $jwt = generate_jwt($payload, $signingSecret);
    $GLOBALS['d3_headers'] = ['Authorization' => 'Bearer ' . $jwt];
    return authenticate_user($verificationSecret);
}
function d3_is_role(mixed $result, string $role): bool {
    return is_array($result) && ($result['role'] ?? null) === $role;
}
function d3_denied(mixed $result): bool {
    return $result === false || $result === null;
}
function d3_query_observed(mixed $result): bool {
    global $pdo;
    return $pdo instanceof D3MemoryPDO && $pdo->queries > 0;
}

try {
    d3_case('ADMIN_VIGENTE', d3_is_role(d3_auth(d3_claims(1, 'admin'), $secret, $secret), 'admin') && d3_query_observed(true));
    d3_case('USER_VIGENTE', d3_is_role(d3_auth(d3_claims(2, 'user'), $secret, $secret), 'user'));
    d3_case('INACTIVO_RECHAZADO', d3_denied(d3_auth(d3_claims(3, 'user'), $secret, $secret)));
    d3_case('SV_REVOCADO_RECHAZADO', d3_denied(d3_auth(d3_claims(4, 'user'), $secret, $secret)));
    d3_case('ID_INEXISTENTE_RECHAZADO', d3_denied(d3_auth(d3_claims(9999, 'admin'), $secret, $secret)));
    d3_case('FIRMA_AJENA_RECHAZADA', d3_denied(d3_auth(d3_claims(2, 'user'), 'D3-CLAVE-AJENA', $secret)));
    $expired = d3_claims(2, 'user'); $expired['exp'] = time() - 600;
    d3_case('EXPIRADO_RECHAZADO', d3_denied(d3_auth($expired, $secret, $secret)));
    $GLOBALS['d3_headers'] = [];
    d3_case('SIN_CABECERA_RECHAZADA', d3_denied(authenticate_user($secret)));
    $GLOBALS['d3_headers'] = ['Authorization' => 'Bearer no-es-un-jwt'];
    d3_case('TOKEN_MALFORMADO_RECHAZADO', d3_denied(authenticate_user($secret)));

    $signedClaimRole = d3_auth(d3_claims(2, 'admin'), $secret, $secret);
    echo 'D3_RIESGO_ROL_DESDE_JWT=' .
        (d3_is_role($signedClaimRole, 'admin') ? 'HALLAZGO' :
        (d3_is_role($signedClaimRole, 'user') ? 'MITIGADO_DB' : 'REVISAR')) . "\n";
    $noVersion = d3_claims(2, 'user'); unset($noVersion['session_version']);
    echo 'D3_RIESGO_TOKEN_SIN_SV=' .
        (d3_denied(d3_auth($noVersion, $secret, $secret)) ? 'RECHAZADO' : 'HALLAZGO_COMPATIBLE') . "\n";
    $noExpiry = d3_claims(2, 'user'); unset($noExpiry['exp']);
    echo 'D3_RIESGO_TOKEN_SIN_EXP=' .
        (d3_denied(d3_auth($noExpiry, $secret, $secret)) ? 'RECHAZADO' : 'HALLAZGO_VALIDACION') . "\n";
} catch (Throwable $exception) {
    echo "D3_AUTH=NO_GO_EXCEPCION_H1\n";
    exit(2);
}

echo 'D3_AUTH_CRITICOS=' . ($failed === 0 ? 'PASS' : 'NO_GO') .
    '_' . ($checked - $failed) . '_DE_' . $checked . "\n";
echo "D3_FIXTURE=PDOMEMORY_SIN_DRIVER\n";
echo "D3_CIERRE_GLOBAL=PENDIENTE_OTROS_VECTORES_Y_FIRMAS\n";
echo "DATOS_PERSONALES_LEIDOS=NO\n";
echo "MYSQL_PRODUCTIVO_MODIFICADO=NO\n";
echo "U_BASIC_ACTIVADO=NO\n";
exit($failed === 0 ? 0 : 2);
