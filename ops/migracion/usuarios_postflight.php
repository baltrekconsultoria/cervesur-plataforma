<?php
/**
 * Cervesur: postflight independiente SOLO LECTURA de 190 usuarios.
 * No imprime correos, nombres, usernames ni hashes. No ejecuta DML.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(2); }
ini_set('display_errors', '0');
error_reporting(E_ALL);
require_once __DIR__ . '/usuarios_import_controlado.php';

function postflightUsers(array $rows, array $plan, array $snapshot): array {
    $propuestas = validatePlan($plan, $snapshot);
    requireGate(count($rows) === 190, 'POST_TOTAL');
    $originales = idxByEmail($snapshot['users'], 'email', 'POST_SNAPSHOT');
    $nuevos = idxByEmail($propuestas, 'email', 'POST_PLAN');
    $ids = [];
    $usernames = [];
    $emails = [];
    $actualOld = 0;
    $actualNew = 0;
    $activeOld = 0;
    foreach ($rows as $row) {
        requireGate(is_array($row), 'POST_ROW');
        $id = filter_var($row['id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $email = normalized((string)($row['email'] ?? ''));
        $username = normalized((string)($row['username'] ?? ''));
        requireGate($id !== false && $email !== '' && $username !== ''
            && !isset($ids[$id]) && !isset($emails[$email]) && !isset($usernames[$username]),
            'POST_UNICIDAD');
        $ids[$id] = true;
        $emails[$email] = true;
        $usernames[$username] = true;
        $old = $originales[$email] ?? null;
        if ($old !== null) {
            requireGate((int)$row['id'] === (int)$old['id']
                && $username === normalized((string)$old['username'])
                && normalized((string)$row['role']) === normalized((string)$old['role'])
                && (int)$row['is_active'] === (int)$old['activo'],
                'POST_ORIGINALES_MODIFICADOS');
            unset($originales[$email]);
            $actualOld++;
            $activeOld += (int)$row['is_active'];
            continue;
        }
        $proposal = $nuevos[$email] ?? null;
        requireGate($proposal !== null, 'POST_USUARIO_NO_PREVISTO');
        $expectedPhone = $proposal['phone'] ?? null;
        $realPhone = $row['phone'] ?? null;
        requireGate($username === normalized((string)$proposal['username'])
            && trim((string)$row['first_name']) === (string)$proposal['first_name']
            && trim((string)$row['last_name']) === (string)$proposal['last_name']
            && (($expectedPhone === null && ($realPhone === null || $realPhone === ''))
                || (string)$expectedPhone === (string)$realPhone)
            && (string)$row['role'] === 'user'
            && (int)$row['is_active'] === 0
            && (int)$row['session_version'] === 1
            && is_string($row['password'])
            && strlen($row['password']) >= 60
            && in_array(password_get_info($row['password'])['algoName'] ?? 'unknown',
                ['bcrypt','argon2i','argon2id'], true),
            'POST_NUEVO_INVALIDO');
        unset($nuevos[$email]);
        $actualNew++;
    }
    requireGate($actualOld === 76 && $actualNew === 114
        && count($originales) === 0 && count($nuevos) === 0
        && $activeOld === 62, 'POST_CONTEOS');
    foreach ($emails as $email => $_) {
        requireGate(!isset($usernames[$email]), 'POST_COLISION_IDENTIDADES');
    }
    return [
        'postflight' => 'PASS', 'database' => 'cervesur_portalbi',
        'usuarios_totales' => 190, 'usuarios_originales_conservados' => 76,
        'originales_activos' => 62, 'originales_inactivos' => 14,
        'nuevos_migrados' => 114, 'nuevos_activos' => 0,
        'nuevos_inactivos' => 114, 'usuarios_activos' => 62,
        'usuarios_inactivos' => 128, 'duplicados' => 0,
        'mysql_modificado' => false, 'datos_personales_en_salida' => false,
    ];
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) return;
$opt = [];
foreach (array_slice($argv, 1) as $arg) {
    if (!preg_match('/^--(loader|database|plan|snapshot)=(.+)$/D', $arg, $match)
        || isset($opt[$match[1]])) stopUsers('POST_ARGUMENTOS');
    $opt[$match[1]] = $match[2];
}
if (count($opt) !== 4 || $opt['database'] !== 'cervesur_portalbi') stopUsers('POST_ARGUMENTOS');
$pdo = null;
try {
    $plan = privateFile($opt['plan']);
    $snapshot = privateFile($opt['snapshot']);
    $pdo = connectUsers($opt['loader']);
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    $rows = $pdo->query(
        'SELECT id,first_name,last_name,username,email,phone,password,role,is_active,session_version FROM user ORDER BY id'
    )->fetchAll();
    $report = postflightUsers($rows, $plan, $snapshot);
    $pdo->rollBack();
    echo "CERVESUR_POSTFLIGHT_USUARIOS=PASS\n";
    echo json_encode($report, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    $message = $e instanceof RuntimeException ? $e->getMessage() : 'POST_OPERACION';
    if (!preg_match('/^[A-Z_]+$/D', $message)) $message = 'POST_OPERACION';
    fwrite(STDERR, "CERVESUR_POSTFLIGHT_USUARIOS=ERROR_" . $message . "\n");
    exit(2);
}
