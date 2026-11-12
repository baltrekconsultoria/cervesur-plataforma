<?php
/**
 * Cervesur V1 — Exportador de snapshot de conciliacion, solo CLI/SELECT.
 * No exporta PII, enlaces, passwords, tokens ni secretos de Power BI.
 * Genera identificadores HMAC para cotejo privado entre dos fuentes.
 * NUNCA se debe invocar via HTTP ni dejar snapshots en public_html.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
function fail(string $code): void {
    fwrite(STDERR, "CERVESUR_SNAPSHOT=ERROR_" . $code . "\n");
    exit(2);
}
function opts(array $argv): array {
    $out = [];
    foreach (array_slice($argv, 1) as $arg) {
        if (!preg_match('/^--([a-z-]+)=(.+)$/D', $arg, $m)) fail('ARGUMENTOS');
        $out[$m[1]] = $m[2];
    }
    foreach (['origin', 'loader', 'key-file', 'out'] as $required) {
        if (!isset($out[$required])) fail('FALTA_' . strtoupper($required));
    }
    if (!in_array($out['origin'], ['legado', 'react'], true)) fail('ORIGEN');
    return $out;
}
function normalized(?string $v): ?string {
    if ($v === null || trim($v) === '') return null;
    return strtolower(trim($v));
}
function signature(?string $v, string $purpose, string $secret): ?string {
    $normalized = normalized($v);
    return $normalized === null ? null : hash_hmac('sha256', $purpose . ':' . $normalized, $secret);
}
function fetchRows(PDO $pdo, string $sql): array {
    return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
}
function active(array $row, string $field): int {
    return (int)($row[$field] ?? 0) === 1 ? 1 : 0;
}
$args = opts($argv);
$loader = realpath($args['loader']);
$keyFile = realpath($args['key-file']);
$parent = realpath(dirname($args['out']));
if (!$loader || !$keyFile || !$parent ||
    !is_file($loader) || !is_file($keyFile) ||
    is_link($args['loader']) || is_link($args['key-file']) ||
    !str_starts_with($args['out'], '/') ||
    file_exists($args['out']) || is_link($args['out'])) fail('RUTAS_INVALIDAS');
if (str_contains($parent, '/public_html') ||
    str_starts_with($parent, '/var/www') ||
    str_contains($parent, '/htdocs') ||
    str_contains($parent, '/wwwroot') ||
    str_contains($parent, '/httpdocs')) fail('DESTINO_PUBLICO_PROHIBIDO');
if ((fileperms($keyFile) & 0077) !== 0) fail('PERMISOS_CLAVE');
$key = rtrim((string)file_get_contents($keyFile), "\r\n");
if (strlen($key) < 32) fail('CLAVE_CORTA');
$keyId = substr(hash_hmac('sha256', 'cervesur-key-id-v1', $key), 0, 16);
try {
    require_once $loader;
    $host = getenv('DB_HOST');
    $dbname = getenv('DB_NAME');
    $dbuser = getenv($args['db-user-key'] ?? 'DB_USER');
    $dbpass = getenv($args['db-pass-key'] ?? 'DB_PASS');
    if (!$host || !$dbname || !$dbuser || $dbpass === false) fail('CONFIG_DB_INCOMPLETA');
    if (isset($args['database']) && $args['database'] !== $dbname) fail('DB_NO_ESPERADA');
    $pdo = new PDO(
        'mysql:host=' . $host . ';dbname=' . $dbname . ';charset=utf8mb4',
        $dbuser, $dbpass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_TIMEOUT => 8,
            PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION TRANSACTION READ ONLY',
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $dbname) fail('DB_DIVERGENTE');
    $users = [];
    foreach (fetchRows($pdo, 'SELECT id, username, email, role, is_active FROM user') as $v) {
        $users[] = [
            'id' => (int)$v['id'],
            'email_h' => signature($v['email'], 'email', $key),
            'username_h' => signature($v['username'], 'username', $key),
            'role' => strtolower(trim($v['role'])),
            'activo' => active($v, 'is_active'),
        ];
    }
    $empresas = [];
    foreach (fetchRows($pdo, 'SELECT id, ruc, activo FROM empresa') as $v) {
        $empresas[] = ['id' => (int)$v['id'], 'ruc_h' => signature($v['ruc'], 'ruc', $key),
            'activo' => active($v, 'activo')];
    }
    $reportes = [];
    foreach (fetchRows($pdo, 'SELECT id, reporte_id, enlace, activo FROM reporte') as $v) {
        $reportes[] = ['id' => (int)$v['id'],
            'ref_h' => signature($v['reporte_id'], 'reporte_id', $key),
            'link_h' => signature($v['enlace'], 'enlace', $key),
            'activo' => active($v, 'activo')];
    }
    $empresaUsuario = [];
    foreach (fetchRows($pdo, 'SELECT id, empresa_id, user_id, activo FROM empresa_usuario') as $v) {
        $empresaUsuario[] = ['id' => (int)$v['id'], 'empresa_id' => (int)$v['empresa_id'],
            'user_id' => (int)$v['user_id'], 'activo' => active($v, 'activo')];
    }
    $empresaReporte = [];
    foreach (fetchRows($pdo, 'SELECT id, empresa_id, reporte_id, activo FROM empresa_reporte') as $v) {
        $empresaReporte[] = ['id' => (int)$v['id'], 'empresa_id' => (int)$v['empresa_id'],
            'reporte_id' => (int)$v['reporte_id'], 'activo' => active($v, 'activo')];
    }
    $userEmpresaReporte = [];
    foreach (fetchRows($pdo, 'SELECT id, user_id, empresa_reporte_id, activo FROM user_empresa_reporte') as $v) {
        $userEmpresaReporte[] = ['id' => (int)$v['id'], 'user_id' => (int)$v['user_id'],
            'empresa_reporte_id' => (int)$v['empresa_reporte_id'], 'activo' => active($v, 'activo')];
    }
    $snapshot = ['schema' => 'cervesur-snapshot-v1', 'origin' => $args['origin'],
        'key_id' => $keyId, 'users' => $users, 'empresas' => $empresas,
        'reportes' => $reportes, 'empresa_usuario' => $empresaUsuario,
        'empresa_reporte' => $empresaReporte,
        'user_empresa_reporte' => $userEmpresaReporte];
    $json = json_encode(
        $snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
    );
    umask(0077);
    $file = @fopen($args['out'], 'x');
    if (!$file) fail('ARCHIVO_YA_EXISTE_O_NO_ESCRIBIBLE');
    try {
        if (fwrite($file, $json . "\n") !== strlen($json) + 1) fail('ESCRITURA_INCOMPLETA');
    } finally {
        fclose($file);
    }
    @chmod($args['out'], 0600);
    echo "CERVESUR_SNAPSHOT=PASS\n";
    echo "ORIGEN=" . $args['origin'] . "\n";
    echo "USUARIOS=" . count($users) . "\n";
    echo "EMPRESAS=" . count($empresas) . "\n";
    echo "REPORTES=" . count($reportes) . "\n";
    echo "EMPRESA_USUARIO=" . count($empresaUsuario) . "\n";
    echo "EMPRESA_REPORTE=" . count($empresaReporte) . "\n";
    echo "USER_EMPRESA_REPORTE=" . count($userEmpresaReporte) . "\n";
    echo "BASE_MODIFICADA=NO\n";
    echo "SALIDA_PRIVADA=SI\n";
} catch (Throwable $e) {
    fail('PRECONDICION_O_CONSULTA');
} finally {
    unset($key);
}
