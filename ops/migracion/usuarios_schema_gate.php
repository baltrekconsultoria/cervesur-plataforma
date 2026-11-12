<?php
/**
 * Cervesur: comprobacion READ-ONLY del esquema MySQL de usuarios.
 * Solo metadatos y conteos. Nunca nombres, emails, passwords ni secretos.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(2); }
ini_set('display_errors', '0');
error_reporting(E_ALL);

function gate_error(string $code): void {
    fwrite(STDERR, "CERVESUR_USUARIOS_SCHEMA=ERROR_" . $code . "\n");
    exit(2);
}
$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (!preg_match('/^--(loader|database)=(.+)$/D', $arg, $m) || isset($opts[$m[1]])) gate_error('ARGUMENTOS');
    $opts[$m[1]] = $m[2];
}
if (count($opts) !== 2 || ($opts['database'] ?? '') !== 'cervesur_portalbi') gate_error('BASE_INCORRECTA');
$loader = realpath($opts['loader']);
if (!$loader || !is_file($loader) || !is_readable($loader) || is_link($opts['loader'])) gate_error('LOADER_INACCESIBLE');

try {
    require_once $loader;
    $host = getenv('DB_HOST');
    $db = getenv('DB_NAME');
    $user = getenv('DB_USER');
    $pass = getenv('DB_PASS');
    if (!$host || !$db || !$user || $pass === false || $db !== 'cervesur_portalbi') gate_error('CONFIGURACION');
    $pdo = new PDO('mysql:host=' . $host . ';dbname=' . $db . ';charset=utf8mb4', $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 8,
    ]);
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'cervesur_portalbi') gate_error('DATABASE_MISMATCH');
    $stmt = $pdo->prepare("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'user'");
    $stmt->execute(['db' => $db]);
    $engine = $stmt->fetchColumn() ?: 'MISSING';
    $stmt = $pdo->prepare("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, EXTRA, CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'user' ORDER BY ORDINAL_POSITION");
    $stmt->execute(['db' => $db]);
    $cols = $stmt->fetchAll();
    $stmt = $pdo->prepare("SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = :db AND TABLE_NAME = 'user' ORDER BY INDEX_NAME, SEQ_IN_INDEX");
    $stmt->execute(['db' => $db]);
    $indexes = $stmt->fetchAll();
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = :db AND EVENT_OBJECT_TABLE = 'user'");
    $stmt->execute(['db' => $db]);
    $triggers = (int)$stmt->fetchColumn();
    $counts = $pdo->query('SELECT COUNT(*) AS total, COALESCE(SUM(is_active = 1),0) AS active FROM user')->fetch();
    $pdo->rollBack();

    $byName = [];
    foreach ($cols as $c) {
        $byName[$c['COLUMN_NAME']] = [
            'type' => $c['COLUMN_TYPE'],
            'nullable' => $c['IS_NULLABLE'],
            'extra' => $c['EXTRA'],
            'max_length' => $c['CHARACTER_MAXIMUM_LENGTH'] === null ? null : (int)$c['CHARACTER_MAXIMUM_LENGTH'],
        ];
    }
    $indices = [];
    foreach ($indexes as $i) if ((int)$i['NON_UNIQUE'] === 0) $indices[$i['INDEX_NAME']][] = $i['COLUMN_NAME'];
    $single = [];
    foreach ($indices as $names) if (count($names) === 1) $single[$names[0]] = true;
    $required = ['id','first_name','last_name','username','email','phone',
        'password','role','is_active','session_version','created_at','updated_at'];
    $missing = array_values(array_diff($required, array_keys($byName)));
    $ready = $engine === 'InnoDB'
        && count($missing) === 0
        && isset($single['id'], $single['email'], $single['username'])
        && str_contains(strtolower($byName['id']['extra'] ?? ''), 'auto_increment')
        && ($byName['password']['max_length'] ?? 0) >= 60
        && $triggers === 0
        && (int)$counts['total'] === 76
        && (int)$counts['active'] === 62;
    echo "CERVESUR_USUARIOS_SCHEMA=PASS\n";
    echo json_encode([
        'modo' => 'SOLO_LECTURA', 'database' => $db, 'table' => 'user',
        'engine' => $engine, 'total' => (int)$counts['total'], 'activos' => (int)$counts['active'],
        'columnas_requeridas_faltantes' => $missing,
        'columnas' => array_intersect_key($byName, array_flip($required)),
        'indice_unico_id' => isset($single['id']),
        'indice_unico_email' => isset($single['email']),
        'indice_unico_username' => isset($single['username']),
        'triggers_usuario' => $triggers,
        'puerta_para_importacion' => $ready ? 'CANDIDATA' : 'BLOQUEADA',
        'datos_personales_en_salida' => false, 'mysql_modificado' => false,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
} catch (Throwable $e) {
    gate_error('CONSULTA_O_ESTRUCTURA');
}
