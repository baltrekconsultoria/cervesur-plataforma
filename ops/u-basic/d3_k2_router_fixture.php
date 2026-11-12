<?php
/**
 * D3-08: route-level QA of copied EmpresaRouter, controller, model and K2.
 * Only a fake company/secret and a fake admin are used. The real JWT, DB and
 * HTTP transport are deliberately OUT of scope. Never print the secret.
 */
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '0');
error_reporting(0);

if (PHP_SAPI !== 'cli' || count($argv) !== 2) {
    echo '{"d3_error":"args"}';
    exit(2);
}
const D3_K2_MARKER = 'CERVESUR_D3_K2_SYNTHETIC_ONLY_2026';
const D3_K2_ID = 42;

final class D3K2Statement extends PDOStatement {
    private array $row;
    private bool $consumed = false;
    public function __construct(array $row) { $this->row = $row; }
    public function execute(?array $params = null): bool {
        $this->consumed = false;
        return true;
    }
    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool {
        return true;
    }
    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0
    ): mixed {
        if ($this->consumed) return false;
        $this->consumed = true;
        return $this->row;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
        if ($this->consumed) return [];
        $this->consumed = true;
        return [$this->row];
    }
    public function fetchColumn(int $column = 0): mixed { return 1; }
}

final class D3K2PDO extends PDO {
    private array $row = [
        'id' => D3_K2_ID,
        'nombre' => 'EMPRESA_QA',
        'razon_social' => 'EMPRESA_QA',
        'ruc' => '00000000000',
        'email' => 'qa@invalid.example',
        'telefono' => null,
        'website' => null,
        'direccion' => null,
        'ciudad' => 'QA',
        'pais' => 'QA',
        'activo' => 1,
        'powerbi_tenant' => 'tenant-ficticio',
        'powerbi_client' => 'client-ficticio',
        'powerbi_clientsecret' => D3_K2_MARKER,
        'logo' => null,
        'contacto_principal' => null,
        'created_by' => 1,
        'updated_by' => 1,
        'created_at' => '2020-01-01',
        'updated_at' => '2020-01-01'
    ];
    public function __construct() {
        // Do not call the parent constructor: no DSN and no connection.
    }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        if ($options !== []) {
            throw new RuntimeException('D3_K2_OPCIONES_SQL_NO_ADMITIDAS');
        }
        if (preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE|DROP|ALTER|CREATE|TRUNCATE|CALL)\b/i', $query)) {
            throw new RuntimeException('D3_K2_ESCRITURA_PROHIBIDA');
        }
        if (preg_match('/^\s*SELECT\s+COUNT\s*\(\s*\*\s*\)/i', $query)) {
            return new D3K2Statement(['n' => 1]);
        }
        if (!preg_match('/^\s*SELECT\s+(.*?)\s+FROM\s+empresa\b/is', $query, $match)) {
            throw new RuntimeException('D3_K2_SQL_DESCONOCIDO');
        }
        $projection = trim($match[1]);
        $result = [];
        if (preg_match('/^(?:e\s*\.\s*)?\*$/i', $projection)) {
            $result = $this->row;
        } else {
            foreach (explode(',', $projection) as $piece) {
                $piece = trim($piece);
                if (preg_match('/^(?:e\s*\.\s*)?\*$/i', $piece)) {
                    $result = array_merge($result, $this->row);
                    continue;
                }
                if (!preg_match(
                    '/^(?:e\.)?([A-Za-z_]\w*)(?:\s+AS\s+([A-Za-z_]\w*))?$/i',
                    $piece,
                    $col
                )) {
                    throw new RuntimeException('D3_K2_PROYECCION_NO_SOPORTADA');
                }
                $field = strtolower($col[1]);
                if (!array_key_exists($field, $this->row)) {
                    throw new RuntimeException('D3_K2_COLUMNA_DESCONOCIDA');
                }
                $result[$col[2] ?? $field] = $this->row[$field];
            }
        }
        return new D3K2Statement($result);
    }
}

$uris = [
    'GETALL' => '/api/empresas',
    'GETBYID' => '/api/empresas/42',
    'SEARCH' => '/api/empresas/buscar/EMPRESA_QA',
    'GETBYCITY' => '/api/empresas/ciudad/QA',
];
$key = strtoupper($argv[1]);
if (!isset($uris[$key])) {
    echo '{"d3_error":"route"}';
    exit(2);
}
$uri = $uris[$key];
$method = 'GET';
$pdo = new D3K2PDO();
$_GET = [];
$_POST = [];
$_REQUEST = [];
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['REQUEST_URI'] = $uri;
$_SERVER['HTTP_HOST'] = 'qa.invalid';
$jwt_secret = 'ONLY_SYNTHETIC_NOT_A_REAL_SECRET';

try {
    // The copied router must include the copied real controller and model.
    require __DIR__ . '/backend/router/EmpresaRouter.php';
    // Returning here means the expected route did not terminate normally.
    echo '{"d3_error":"route_not_matched"}';
    exit(2);
} catch (Throwable $error) {
    // No exception details, SQL, environment or source content in output.
    echo '{"d3_error":"sandbox_incompatible"}';
    exit(2);
}
