<?php
/**
 * Cervesur: exportacion PRIVADA de datos de usuarios, sin contrasenas.
 * Solo CLI, MySQL READ ONLY y salida exclusiva a fichero fuera de DocumentRoot.
 * Incluye PII: transferir solo por SFTP; nunca GitHub/chat/logs/public_html.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(1); }
ini_set('display_errors', '0');
error_reporting(E_ALL);
umask(0077);

function abortar(string $code): void {
    fwrite(STDERR, "CERVESUR_USUARIOS_EXPORT=ERROR_" . $code . "\n");
    exit(2);
}
function opciones(array $argv): array {
    $opts = [];
    foreach (array_slice($argv, 1) as $a) {
        if (!preg_match('/^--([a-z-]+)=(.+)$/D', $a, $m)) abortar('ARGUMENTOS');
        if (isset($opts[$m[1]])) abortar('ARGUMENTO_DUPLICADO');
        $opts[$m[1]] = $m[2];
    }
    foreach (['origin', 'loader', 'database', 'out'] as $r) {
        if (!isset($opts[$r])) abortar('FALTA_' . strtoupper($r));
    }
    $bases = ['legado' => 'databalt_portalbi', 'react' => 'cervesur_portalbi'];
    if (!isset($bases[$opts['origin']]) ||
        $opts['database'] !== $bases[$opts['origin']]) abortar('ORIGEN_DB_INCORRECTO');
    return $opts;
}
$args = opciones($argv);
$loader = realpath($args['loader']);
$out = $args['out'];
$parent = realpath(dirname($out));
if (!str_starts_with($out, '/') || $loader === false || $parent === false ||
    !is_file($loader) || !is_readable($loader) || is_link($args['loader']) ||
    !is_dir($parent) || !is_writable($parent) || file_exists($out) || is_link($out) ||
    basename($out) !== 'usuarios-' . $args['origin'] . '.json') {
    abortar('RUTAS_INVALIDAS');
}
if (preg_match('~/(?:public_html|htdocs|httpdocs|wwwroot)(?:/|$)~i', $parent) ||
    str_starts_with($parent, '/var/www') ||
    ((fileperms($parent) & 0007) !== 0)) {
    abortar('DIRECTORIO_NO_PRIVADO');
}
$rows = [];
try {
    require_once $loader;
    $host = getenv('DB_HOST');
    $db = getenv('DB_NAME');
    $user = getenv('DB_USER');
    $pass = getenv('DB_PASS');
    if (!$host || !$db || !$user || $pass === false || $db !== $args['database']) {
        abortar('DB_CONFIG_NO_ESPERADA');
    }
    $pdo = new PDO(
        'mysql:host=' . $host . ';dbname=' . $db . ';charset=utf8mb4',
        $user, $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_TIMEOUT => 8,
         PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION TRANSACTION READ ONLY',
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $args['database']) {
        abortar('DB_NO_COINCIDE');
    }
    $pdo->beginTransaction();
    // Whitelist estricta: no SELECT *, password, JWT, session_version, token ni avatar.
    $q = $pdo->query(
        'SELECT id, first_name, last_name, username, email, phone, role, is_active FROM user ORDER BY id'
    );
    while ($v = $q->fetch()) {
        $rows[] = [
            'id' => (int)$v['id'],
            'first_name' => (string)($v['first_name'] ?? ''),
            'last_name' => (string)($v['last_name'] ?? ''),
            'username' => (string)($v['username'] ?? ''),
            'email' => (string)($v['email'] ?? ''),
            'phone' => $v['phone'] === null ? null : (string)$v['phone'],
            'role' => (string)($v['role'] ?? ''),
            'activo' => (int)$v['is_active'] === 1 ? 1 : 0,
        ];
    }
    $pdo->rollBack();
    $document = [
        'schema' => 'cervesur-usuarios-privados-v1',
        'origin' => $args['origin'],
        'database' => $args['database'],
        'includes_passwords' => false,
        'users' => $rows,
    ];
    $json = json_encode($document, JSON_UNESCAPED_UNICODE |
        JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    $fp = @fopen($out, 'x');
    if (!$fp) abortar('SALIDA_EXISTENTE_O_NO_ESCRIBIBLE');
    $payload = $json . "\n";
    $written = fwrite($fp, $payload);
    fclose($fp);
    @chmod($out, 0600);
    if ($written !== strlen($payload)) {
        @unlink($out);
        abortar('ESCRITURA_INCOMPLETA');
    }
    $active = count(array_filter($rows, fn($r) => $r['activo'] === 1));
    echo "CERVESUR_USUARIOS_EXPORT=PASS\n";
    echo "ORIGEN=" . $args['origin'] . "\n";
    echo "USUARIOS=" . count($rows) . "\n";
    echo "ACTIVOS=" . $active . "\n";
    echo "CONTRASENAS_EXPORTADAS=NO\n";
    echo "BASE_MODIFICADA=NO\n";
    echo "SALIDA_PRIVADA=SI\n";
} catch (Throwable $e) {
    abortar('CONSULTA_O_EXPORTACION');
}
