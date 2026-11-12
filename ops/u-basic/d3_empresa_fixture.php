<?php
/**
 * Cervesur D3-08 — prueba de salida de EmpresaModel con fila ficticia.
 * Esta copia de la clase vive en un directorio aislado con open_basedir.
 * No se importa codigo del backend productivo ni se abre PDO real.
 */
declare(strict_types=1);
ini_set('display_errors', '0');
ini_set('log_errors', '0');
error_reporting(0);

if (PHP_SAPI !== 'cli') {
    echo "D3_08_FIXTURE=NO_GO_SAPI\n";
    exit(2);
}
const D3_SENTINEL = 'CERVESUR_D3_SYNTHETIC_SECRET_NOT_REAL_4251';

final class D3ResultStatement extends PDOStatement {
    private array $row;
    private array $bound = [];
    private bool $consumed = false;

    public function __construct(array $row) {
        $this->row = $row;
    }
    public function bindValue(string|int $param, mixed $value, int $type = PDO::PARAM_STR): bool {
        $this->bound[(string)$param] = $value;
        return true;
    }
    public function execute(?array $params = null): bool {
        $this->consumed = false;
        return true;
    }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed {
        if ($this->consumed) return false;
        $this->consumed = true;
        return $this->row;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array {
        if ($this->consumed) return [];
        $this->consumed = true;
        return [$this->row];
    }
    public function fetchColumn(int $column = 0): mixed {
        return $column === 0 ? 1 : false;
    }
}
final class D3FakePDO extends PDO {
    public int $count = 0;
    public bool $unknownSQL = false;
    private array $data = [
        'id' => 42, 'nombre' => 'EMPRESA_FICTICIA',
        'razon_social' => 'EMPRESA_QA', 'ruc' => '00000000000',
        'email' => 'qa@example.invalid', 'telefono' => '000',
        'website' => null, 'direccion' => null,
        'ciudad' => 'QA', 'pais' => 'QA', 'activo' => 1,
        'powerbi_tenant' => 'tenant-qa',
        'powerbi_client' => 'client-qa',
        'powerbi_clientsecret' => D3_SENTINEL,
        'logo' => null, 'contacto_principal' => null,
        'created_by' => 1, 'updated_by' => 1,
        'created_at' => '2020-01-01', 'updated_at' => '2020-01-01',
    ];
    public function __construct() { /* No PDO parent constructor, no DSN. */ }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        if ($options !== [] || preg_match('/\b(?:INSERT|UPDATE|DELETE|REPLACE|DROP|ALTER|CREATE|TRUNCATE|CALL)\b/i', $query)) {
            throw new RuntimeException('D3_RECHAZO_SQL_ESCRITURA');
        }
        $sql = trim($query);
        $this->count++;
        if (preg_match('/^SELECT\s+COUNT\s*\(\s*\*\s*\)/i', $sql)) {
            return new D3ResultStatement(['COUNT(*)' => 1]);
        }
        if (!preg_match('/^\s*SELECT\s+(.*?)\s+FROM\s+empresa\b/is', $sql, $m)) {
            $this->unknownSQL = true;
            throw new RuntimeException('D3_SQL_NO_RECONOCIDO');
        }
        $projection = trim($m[1]);
        // Solo SELECT * / e.* o columnas simples expresas; cualquier
        // expresion no reconocida invalida el resultado (fail closed).
        if (preg_match('/^(?:e\s*\.\s*)?\*$/i', $projection)) {
            $row = $this->data;
        } else {
            $row = [];
            foreach (explode(',', $projection) as $part) {
                $part = trim($part);
                if (preg_match('/^(?:e\s*\.\s*)?\*$/i', $part)) {
                    $row = array_merge($row, $this->data);
                    continue;
                }
                if (!preg_match('/^(?:e\.)?([A-Za-z_][A-Za-z_0-9]*)(?:\s+AS\s+([A-Za-z_][A-Za-z_0-9]*))?$/i', $part, $col)) {
                    $this->unknownSQL = true;
                    throw new RuntimeException('D3_PROYECCION_NO_RECONOCIDA');
                }
                $key = strtolower($col[1]);
                if (!array_key_exists($key, $this->data)) {
                    $this->unknownSQL = true;
                    throw new RuntimeException('D3_COLUMNA_NO_RECONOCIDA');
                }
                $alias = $col[2] ?? $key;
                $row[$alias] = $this->data[$key];
            }
        }
        return new D3ResultStatement($row);
    }
}

// Los helpers sustituyen deliberadamente el arbol "lib" del servidor.
// La clase EmpresaModel.php es una COPIA del archivo real ya huellado.
try {
    require __DIR__ . '/backend/models/EmpresaModel.php';
    if (!class_exists('EmpresaModel', false)) {
        echo "D3_08_FIXTURE=NO_GO_MODELO_NO_CARGA\n";
        exit(2);
    }
    $pdo = new D3FakePDO();
    $model = new EmpresaModel($pdo);
} catch (Throwable $e) {
    echo "D3_08_FIXTURE=NO_GO_DEPENDENCIAS_INCOMPATIBLES\n";
    exit(2);
}

function d3_has_secret(mixed $item, int $level = 0): bool {
    if ($level > 15) return true;
    if ($item === D3_SENTINEL) return true;
    if (!is_array($item)) return false;
    foreach ($item as $key => $value) {
        if (strtolower((string)$key) === 'powerbi_clientsecret') return true;
        if (d3_has_secret($value, $level + 1)) return true;
    }
    return false;
}
function d3_has_row(mixed $value): bool {
    if (!is_array($value) || $value === []) return false;
    // If a returned wrapper contains a row, recursion finds a known fake id.
    if (($value['id'] ?? null) === 42) return true;
    foreach ($value as $item) {
        if (d3_has_row($item)) return true;
    }
    return false;
}

$cases = [
    'GETALL' => static fn($model) => $model->getAll(1, 'admin'),
    'GETBYID' => static fn($model) => $model->getById(42, 1, 'admin'),
    'SEARCH' => static fn($model) => $model->search('EMPRESA_FICTICIA', 1, 'admin'),
    'GETBYCITY' => static fn($model) => $model->getByCity('QA', 1, 'admin'),
];
$fail = false;
$pending = false;
foreach ($cases as $name => $invoke) {
    $before = $pdo->count;
    try {
        $result = $invoke($model);
        if ($pdo->count === $before || $pdo->unknownSQL || !d3_has_row($result)) {
            echo "D3_08_FIXTURE_{$name}=INCONCLUSO_SIN_FILA\n";
            $pending = true;
        } elseif (d3_has_secret($result)) {
            echo "D3_08_FIXTURE_{$name}=SECRETO_EN_RESPUESTA_SINTETICA\n";
            $fail = true;
        } else {
            echo "D3_08_FIXTURE_{$name}=SIN_SECRETO_EN_FIXTURE\n";
        }
    } catch (Throwable $e) {
        echo "D3_08_FIXTURE_{$name}=INCONCLUSO_HARNESS\n";
        $pending = true;
    }
}
if ($fail) {
    echo "D3_08_FIXTURE_GLOBAL=NO_GO_EXPOSICION_SINTETICA\n";
} elseif ($pending) {
    echo "D3_08_FIXTURE_GLOBAL=PENDIENTE_COMPATIBILIDAD\n";
} else {
    echo "D3_08_FIXTURE_GLOBAL=PASS_ACOTADO_DATOS_SINTETICOS\n";
}
echo "D3_08_HTTP_REAL=NO_PROBADO\n";
echo "MYSQL_PRODUCTIVO_ACCEDIDO=NO\n";
echo "DATOS_PERSONALES_ACCEDIDOS=NO\n";
echo "U_BASIC_ACTIVADO=NO\n";
exit($fail || $pending ? 2 : 0);
