<?php
/**
 * CERVESUR - USERS ONLY - importacion controlada por tres modos:
 * CHECK (solo lectura), BACKUP (JSON privado 0600), APPLY (INSERT 114 inactivos).
 *
 * No distribuye passwords, no altera cuentas existentes, empresas ni accesos.
 * No ejecutar APPLY hasta verificar la puerta de esquema + QA y respaldo.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(2); }
ini_set('display_errors', '0');
error_reporting(E_ALL);
umask(0077);

function stopUsers(string $code): void {
    fwrite(STDERR, "CERVESUR_IMPORT_USUARIOS=ERROR_" . $code . "\n");
    exit(2);
}
function requireGate(bool $condition, string $code): void {
    if (!$condition) throw new RuntimeException($code);
}
function privateFile(string $path): array {
    $real = realpath($path);
    requireGate($real !== false && $real === $path && is_file($real)
        && !is_link($path) && ((fileperms($real) & 0077) === 0)
        && str_starts_with($real, '/var/lib/cervesur-migration/snapshots/'), 'ARCHIVO_PRIVADO');
    $json = json_decode((string)file_get_contents($real), true, 64, JSON_THROW_ON_ERROR);
    requireGate(is_array($json), 'JSON_INVALIDO');
    return $json;
}
function normalized(string $s): string { return strtolower(trim($s)); }
function idxByEmail(array $list, string $key, string $error): array {
    $out = [];
    foreach ($list as $row) {
        requireGate(is_array($row) && isset($row[$key]) && is_string($row[$key]), $error);
        $email = normalized($row[$key]);
        requireGate($email !== '' && !isset($out[$email]), $error);
        $out[$email] = $row;
    }
    return $out;
}
function validatePlan(array $plan, array $snapshot): array {
    requireGate(($plan['schema'] ?? '') === 'cervesur-plan-usuarios-v1'
        && ($plan['modo'] ?? '') === 'SIMULACION_SIN_ESCRITURAS'
        && ($plan['politica'] ?? '') === 'EXISTENTES_INTACTOS_ALTAS_INACTIVAS'
        && ($plan['no_contiene_passwords'] ?? false) === true
        && ($snapshot['schema'] ?? '') === 'cervesur-usuarios-privados-v1'
        && ($snapshot['origin'] ?? '') === 'react'
        && ($snapshot['database'] ?? '') === 'cervesur_portalbi'
        && ($snapshot['includes_passwords'] ?? true) === false, 'PLAN_O_SNAPSHOT_INVALIDO');
    $r = $plan['resumen'] ?? [];
    requireGate(is_array($r)
        && ($r['origen_total'] ?? null) === 190
        && ($r['destino_total'] ?? null) === 76
        && ($r['coincidentes_conservados'] ?? null) === 76
        && ($r['candidatos_inactivos'] ?? null) === 114
        && ($r['sin_correo_coincidente'] ?? null) === 114
        && ($r['revision_requerida'] ?? null) === 0
        && ($r['destino_no_presente_en_origen'] ?? null) === 0
        && ($r['aplicacion_autorizada'] ?? true) === false
        && ($r['mysql_modificado'] ?? true) === false
        && count($plan['revisiones_privadas'] ?? []) === 0
        && count($snapshot['users'] ?? []) === 76, 'RESUMEN_DISTINTO');
    $propuestas = $plan['creaciones_propuestas_privadas'] ?? null;
    requireGate(is_array($propuestas) && count($propuestas) === 114, 'CANDIDATOS_DISTINTOS');
    $seenEmail = [];
    $seenUser = [];
    $destEmails = idxByEmail($snapshot['users'], 'email', 'EMAIL_DESTINO_DUPLICADO');
    $destUser = [];
    foreach ($snapshot['users'] as $u) {
        $name = normalized((string)($u['username'] ?? ''));
        requireGate($name !== '' && !isset($destUser[$name]), 'USUARIO_DESTINO_DUPLICADO');
        $destUser[$name] = true;
    }
    foreach ($propuestas as $p) {
        requireGate(is_array($p)
            && ($p['rol_propuesto'] ?? '') === 'user'
            && ($p['activo_propuesto'] ?? null) === 0
            && ($p['activo_origen'] ?? null) === 1
            && ($p['requiere_inicializacion_credenciales'] ?? false) === true
            && in_array(normalized((string)($p['role_origen'] ?? '')), ['user', 'usuario'], true)
            && !array_key_exists('password', $p), 'CANDIDATO_INSEGURO');
        $email = normalized((string)($p['email'] ?? ''));
        $name = normalized((string)($p['username'] ?? ''));
        requireGate(filter_var($email, FILTER_VALIDATE_EMAIL) !== false
            // Los identificadores LEGADOS pueden incluir puntuacion imprimible o tildes.
            // PHP login acepta username como texto; no cambiarlo ni imponer la regla
            // del formulario de ALTA NUEVA (3-30 [a-z0-9._-]).
            // Limite de tabla: 50 caracteres; prohibir controles/Unicode invalido.
            && preg_match('/\A[^\p{C}]{3,50}\z/u', trim((string)($p['username'] ?? ''))) === 1
            && trim((string)($p['first_name'] ?? '')) !== ''
            && trim((string)($p['last_name'] ?? '')) !== ''
            && !isset($seenEmail[$email]) && !isset($seenUser[$name])
            && !isset($destEmails[$email]) && !isset($destUser[$name])
            && !isset($destEmails[$name]) && !isset($destUser[$email])
            && !isset($seenEmail[$name]) && !isset($seenUser[$email]), 'COLISION_O_DATOS_INVALIDOS');
        $seenEmail[$email] = true;
        $seenUser[$name] = true;
        requireGate(!isset($seenEmail[$name]) || $name === $email, 'CRUCE_ENTRE_CANDIDATOS');
    }
    return $propuestas;
}
function connectUsers(string $loader): PDO {
    requireGate(is_file($loader) && is_readable($loader) && !is_link($loader), 'LOADER');
    require_once $loader;
    $host = getenv('DB_HOST');
    $name = getenv('DB_NAME');
    $user = getenv('DB_USER');
    $pass = getenv('DB_PASS');
    requireGate($host && $name === 'cervesur_portalbi' && $user && $pass !== false, 'DATABASE');
    return new PDO('mysql:host=' . $host . ';dbname=' . $name . ';charset=utf8mb4', $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 8,
    ]);
}
function schemaUsers(PDO $pdo): array {
    $db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    requireGate($db === 'cervesur_portalbi', 'DB_INCORRECTA');
    $stmt = $pdo->prepare("SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = 'user'");
    $stmt->execute([$db]);
    requireGate($stmt->fetchColumn() === 'InnoDB', 'MOTOR_NO_TRANSACCIONAL');
    $stmt = $pdo->prepare("SELECT COLUMN_NAME, CHARACTER_MAXIMUM_LENGTH, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=? AND TABLE_NAME='user'");
    $stmt->execute([$db]);
    $cols = [];
    foreach ($stmt->fetchAll() as $c) $cols[$c['COLUMN_NAME']] = $c;
    foreach (['id','first_name','last_name','username','email','phone','password','role',
        'is_active','session_version','created_at','updated_at'] as $name) {
        requireGate(isset($cols[$name]), 'COLUMNA_FALTANTE');
    }
    requireGate(str_contains(strtolower((string)$cols['id']['EXTRA']), 'auto_increment')
        && (int)$cols['password']['CHARACTER_MAXIMUM_LENGTH'] >= 60, 'ESQUEMA_NO_COMPATIBLE');
    $stmt = $pdo->prepare("SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=? AND TABLE_NAME='user' ORDER BY INDEX_NAME, SEQ_IN_INDEX");
    $stmt->execute([$db]);
    $keys = [];
    foreach ($stmt->fetchAll() as $i) {
        if ((int)$i['NON_UNIQUE'] === 0) $keys[$i['INDEX_NAME']][] = $i['COLUMN_NAME'];
    }
    $one = [];
    foreach ($keys as $fields) if (count($fields) === 1) $one[$fields[0]] = true;
    requireGate(isset($one['id'], $one['email'], $one['username']), 'INDICES_UNICOS_FALTANTES');
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=? AND EVENT_OBJECT_TABLE='user'");
    $stmt->execute([$db]);
    requireGate((int)$stmt->fetchColumn() === 0, 'TRIGGERS_NO_REVISADOS');
    return $cols;
}
function verifyLengths(array $entries, array $cols): void {
    foreach ($entries as $p) foreach (['first_name','last_name','username','email','phone'] as $name) {
        $value = $p[$name] ?? null;
        if ($value === null) continue;
        requireGate(is_string($value), 'TIPO_CAMPO');
        $max = $cols[$name]['CHARACTER_MAXIMUM_LENGTH'] ?? null;
        $length = function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
        requireGate($max === null || $length <= (int)$max, 'LONGITUD_CAMPO');
    }
}
function baselineUsers(array $current, array $snapshot): void {
    requireGate(count($current) === 76, 'CONTEO_ACTUAL_CAMBIO');
    $map = idxByEmail($snapshot['users'], 'email', 'SNAPSHOT_INVALIDO');
    foreach ($current as $row) {
        $email = normalized((string)$row['email']);
        $old = $map[$email] ?? null;
        requireGate($old !== null
            && (int)$row['id'] === (int)$old['id']
            && normalized((string)$row['username']) === normalized((string)$old['username'])
            && normalized((string)$row['role']) === normalized((string)$old['role'])
            && (int)$row['is_active'] === (int)$old['activo'], 'USUARIO_EXISTENTE_CAMBIO');
        unset($map[$email]);
    }
    requireGate(count($map) === 0, 'SNAPSHOT_CAMBIO');
}
function outputBackup(string $file, array $rows): string {
    requireGate(str_starts_with($file, '/var/lib/cervesur-migration/snapshots/')
        && !file_exists($file) && !is_link($file) && is_dir(dirname($file))
        && ((fileperms(dirname($file)) & 0007) === 0), 'RUTA_BACKUP');
    $payload = json_encode([
        'schema' => 'cervesur-backup-users-v1', 'database' => 'cervesur_portalbi',
        'includes_password_hashes' => true, 'rows' => $rows,
    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $fp = fopen($file, 'x');
    requireGate(is_resource($fp), 'BACKUP_EXISTE_O_INACCESIBLE');
    $written = fwrite($fp, $payload . "\n");
    fclose($fp);
    chmod($file, 0600);
    requireGate($written === strlen($payload) + 1, 'BACKUP_INCOMPLETO');
    return hash_file('sha256', $file);
}
function readBackup(string $path, string $checksum, array $rows): void {
    requireGate(preg_match('/^[a-f0-9]{64}$/D', $checksum) === 1, 'BACKUP_HASH_INVALIDO');
    requireGate(hash_equals($checksum, (string)hash_file('sha256', $path)), 'BACKUP_DISTINTO');
    $saved = privateFile($path);
    requireGate(($saved['schema'] ?? '') === 'cervesur-backup-users-v1'
        && ($saved['database'] ?? '') === 'cervesur_portalbi'
        && ($saved['includes_password_hashes'] ?? false) === true
        && isset($saved['rows']) && is_array($saved['rows'])
        && $saved['rows'] === $rows, 'BACKUP_NO_COINCIDE_CON_DB');
}

// Permite cargar las funciones puras desde el QA sintetico sin conectar a BD.
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) return;

$options = [];
foreach (array_slice($argv, 1) as $arg) {
    if (!preg_match('/^--([a-z0-9-]+)=(.+)$/D', $arg, $m) || isset($options[$m[1]])) stopUsers('ARGUMENTOS');
    $options[$m[1]] = $m[2];
}
$mode = $options['mode'] ?? '';
if (!in_array($mode, ['check', 'backup', 'apply'], true)) stopUsers('MODO');
$allowed = ['mode','loader','database','plan','snapshot','backup','backup-sha256','confirm'];
foreach (array_keys($options) as $name) if (!in_array($name, $allowed, true)) stopUsers('ARGUMENTOS');
if (($options['database'] ?? '') !== 'cervesur_portalbi') stopUsers('DB_INCORRECTA');
if (!isset($options['plan'],$options['snapshot'],$options['loader'])) stopUsers('ARGUMENTOS');
if ($mode === 'apply') {
    if (($options['confirm'] ?? '') !== 'IMPORTAR_114_USUARIOS_INACTIVOS' || !isset($options['backup'],$options['backup-sha256'])) stopUsers('APLICACION_NO_AUTORIZADA');
} elseif (isset($options['confirm']) || isset($options['backup-sha256'])) stopUsers('ARGUMENTOS');

$pdo = null;
try {
    $plan = privateFile($options['plan']);
    $snapshot = privateFile($options['snapshot']);
    $candidates = validatePlan($plan, $snapshot);
    $pdo = connectUsers($options['loader']);
    $cols = schemaUsers($pdo);
    verifyLengths($candidates, $cols);
    if ($mode === 'apply') {
        // El respaldo ya fue generado en una corrida independiente de SOLO LECTURA.
        $pdo->beginTransaction();
        $rows = $pdo->query('SELECT * FROM user ORDER BY id FOR UPDATE')->fetchAll();
        baselineUsers($rows, $snapshot);
        readBackup($options['backup'], $options['backup-sha256'], $rows);
        $sql = 'INSERT INTO user (first_name,last_name,username,email,phone,password,role,is_active,session_version,created_at,updated_at) VALUES (?,?,?,?,?,?,?,0,1,NOW(),NOW())';
        $stmt = $pdo->prepare($sql);
        $count = 0;
        foreach ($candidates as $c) {
            $unused = bin2hex(random_bytes(32));
            $hash = password_hash($unused, PASSWORD_DEFAULT);
            unset($unused);
            requireGate(is_string($hash) && strlen($hash) >= 60, 'HASH');
            $stmt->execute([
                trim($c['first_name']), trim($c['last_name']),
                trim($c['username']), trim($c['email']),
                $c['phone'] ?? null, $hash, 'user',
            ]);
            $count++;
        }
        $summary = $pdo->query('SELECT COUNT(*) AS total, SUM(is_active=1) AS active FROM user')->fetch();
        requireGate($count === 114 && (int)$summary['total'] === 190
            && (int)$summary['active'] === 62, 'POSTCONDICION');
        $pdo->commit();
        echo "CERVESUR_IMPORT_USUARIOS=PASS\n";
        echo "MODO=APPLY\nNUEVOS_INACTIVOS=114\nEXISTENTES_MODIFICADOS=0\nTOTAL_FINAL=190\nACTIVOS_FINAL=62\nCONTRASENAS_PUBLICADAS=NO\n";
    } else {
        $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        $pdo->beginTransaction();
        $rows = $pdo->query('SELECT * FROM user ORDER BY id')->fetchAll();
        baselineUsers($rows, $snapshot);
        $pdo->rollBack();
        if ($mode === 'backup') {
            if (!isset($options['backup'])) throw new RuntimeException('FALTA_BACKUP_RUTA');
            $sha = outputBackup($options['backup'], $rows);
            echo "CERVESUR_IMPORT_USUARIOS=PASS\nMODO=BACKUP\nFILAS_RESPALDADAS=76\nBACKUP_SHA256=" . $sha . "\n";
            echo "BACKUP_INCLUYE_HASHES_PRIVADOS=SI\n";
        } else {
            echo "CERVESUR_IMPORT_USUARIOS=PASS\nMODO=CHECK\nNUEVOS_PROPUESTOS_INACTIVOS=114\nEXISTENTES_CONSERVADOS=76\nCONFLICTOS=0\nMYSQL_MODIFICADO=NO\n";
        }
    }
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    $msg = ($e instanceof RuntimeException) ? $e->getMessage() : 'OPERACION';
    if (!preg_match('/^[A-Z_]+$/D', $msg)) $msg = 'OPERACION';
    stopUsers($msg);
}
