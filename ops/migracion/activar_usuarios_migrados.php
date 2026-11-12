<?php
/**
 * CERVESUR users-only: activate EXACTLY the 114 imported accounts.
 * Modes: check (READ ONLY), backup (READ ONLY + private file), apply (atomic),
 * postflight (READ ONLY).
 * Uses the private migration plan+baseline, never names/emails in stdout.
 * Activation does not provision credentials. Login stays unavailable until
 * individually initialized via a separately approved workflow.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit(2); }
ini_set('display_errors', '0');
error_reporting(E_ALL);
umask(0077);
require_once __DIR__ . '/usuarios_import_controlado.php';

function activationValidate(array $rows, array $plan, array $snapshot, bool $activated): array {
    $proposals = validatePlan($plan, $snapshot);
    requireGate(count($rows) === 190, 'ACT_TOTAL');
    $old = idxByEmail($snapshot['users'], 'email', 'ACT_BASELINE');
    $fresh = idxByEmail($proposals, 'email', 'ACT_PLAN');
    $seenIds = [];
    $seenUsernames = [];
    $seenEmails = [];
    $targets = [];
    $oldActive = 0;
    foreach ($rows as $row) {
        requireGate(is_array($row), 'ACT_ROW');
        $id = filter_var($row['id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]);
        $email = normalized((string)($row['email'] ?? ''));
        $name = normalized((string)($row['username'] ?? ''));
        requireGate($id !== false && $email !== '' && $name !== ''
            && !isset($seenIds[$id]) && !isset($seenEmails[$email])
            && !isset($seenUsernames[$name]), 'ACT_UNICIDAD');
        $seenIds[$id] = true;
        $seenEmails[$email] = true;
        $seenUsernames[$name] = true;
        $oldRow = $old[$email] ?? null;
        if ($oldRow !== null) {
            requireGate((int)$row['id'] === (int)$oldRow['id']
                && $name === normalized((string)$oldRow['username'])
                && normalized((string)$row['role']) === normalized((string)$oldRow['role'])
                && (int)$row['is_active'] === (int)$oldRow['activo'],
                'ACT_EXISTENTES_CAMBIADOS');
            unset($old[$email]);
            $oldActive += (int)$row['is_active'];
            continue;
        }
        $p = $fresh[$email] ?? null;
        requireGate(is_array($p), 'ACT_DESCONOCIDO');
        $expectedPhone = $p['phone'] ?? null;
        $actualPhone = $row['phone'] ?? null;
        requireGate($name === normalized((string)$p['username'])
            && trim((string)$row['first_name']) === (string)$p['first_name']
            && trim((string)$row['last_name']) === (string)$p['last_name']
            && (($expectedPhone === null && ($actualPhone === null || $actualPhone === ''))
                || (string)$expectedPhone === (string)$actualPhone)
            && (string)$row['role'] === 'user'
            && (int)$row['is_active'] === ($activated ? 1 : 0)
            && (int)$row['session_version'] === ($activated ? 2 : 1)
            && is_string($row['password'])
            && strlen($row['password']) >= 60
            && in_array(password_get_info($row['password'])['algoName'] ?? 'unknown',
                ['bcrypt', 'argon2i', 'argon2id'], true),
            'ACT_CUENTA_INESPERADA');
        $targets[] = ['id'=>(int)$row['id'], 'email'=>(string)$row['email'],
            'username'=>(string)$row['username']];
        unset($fresh[$email]);
    }
    requireGate(count($old) === 0 && count($fresh) === 0
        && count($targets) === 114 && $oldActive === 62, 'ACT_CONTEOS');
    foreach ($seenEmails as $email => $_) {
        requireGate(!isset($seenUsernames[$email]), 'ACT_ALIAS_CRUZADO');
    }
    return $targets;
}

// Pure functions can be included by synthetic QA without touching MySQL.
if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) !== __FILE__) return;
$opts = [];
foreach (array_slice($argv, 1) as $arg) {
    if (!preg_match('/^--([a-z0-9-]+)=(.+)$/D', $arg, $m)
        || isset($opts[$m[1]])) stopUsers('ACT_ARGUMENTOS');
    $opts[$m[1]] = $m[2];
}
$allowed = ['mode','loader','database','plan','snapshot','backup','backup-sha256','confirm'];
foreach ($opts as $k => $_) if (!in_array($k, $allowed, true)) stopUsers('ACT_ARGUMENTOS');
$mode = $opts['mode'] ?? '';
if (!in_array($mode, ['check','backup','apply','postflight'], true)
    || ($opts['database'] ?? '') !== 'cervesur_portalbi'
    || !isset($opts['loader'],$opts['plan'],$opts['snapshot'])) stopUsers('ACT_ARGUMENTOS');
if ($mode === 'apply') {
    if (!isset($opts['backup'],$opts['backup-sha256'])
        || ($opts['confirm'] ?? '') !== 'ACTIVAR_SOLO_114_MIGRADOS') stopUsers('ACT_CONFIRMACION');
} elseif (isset($opts['confirm'],$opts['backup-sha256']) || isset($opts['backup-sha256'])
    || isset($opts['confirm']) || ($mode !== 'backup' && isset($opts['backup']))) {
    stopUsers('ACT_ARGUMENTOS');
}
$pdo = null;
try {
    $plan = privateFile($opts['plan']);
    $snapshot = privateFile($opts['snapshot']);
    $pdo = connectUsers($opts['loader']);
    schemaUsers($pdo);
    if ($mode === 'apply') {
        $pdo->beginTransaction();
        $before = $pdo->query('SELECT * FROM user ORDER BY id FOR UPDATE')->fetchAll();
        $targets = activationValidate($before, $plan, $snapshot, false);
        readBackup($opts['backup'], $opts['backup-sha256'], $before);
        $stmt = $pdo->prepare(
            'UPDATE user SET is_active=1, session_version=session_version+1 '
            . 'WHERE id=? AND username=? AND email=? AND role=? AND is_active=0 AND session_version=1'
        );
        $updated = 0;
        foreach ($targets as $target) {
            $stmt->execute([$target['id'],$target['username'],$target['email'],'user']);
            requireGate($stmt->rowCount() === 1, 'ACT_UPDATE_INCOMPLETO');
            $updated++;
        }
        $after = $pdo->query('SELECT * FROM user ORDER BY id')->fetchAll();
        activationValidate($after, $plan, $snapshot, true);
        $summary = $pdo->query(
            'SELECT COUNT(*) AS total, SUM(is_active=1) AS active, '
            . 'SUM(is_active=0) AS inactive FROM user'
        )->fetch();
        requireGate($updated === 114 && (int)$summary['total'] === 190
            && (int)$summary['active'] === 176
            && (int)$summary['inactive'] === 14, 'ACT_POSTCONDICION');
        $pdo->commit();
        echo "CERVESUR_ACTIVACION_USUARIOS=PASS\n";
        echo "MODO=APPLY\nNUEVOS_ACTIVADOS=114\nANTIGUOS_MODIFICADOS=0\n";
        echo "TOTAL=190\nACTIVOS=176\nINACTIVOS=14\nSESIONES_NUEVAS_CREADAS=0\n";
        echo "CREDENCIALES_INICIALIZADAS=NO\n";
    } else {
        $pdo->exec('SET SESSION TRANSACTION READ ONLY');
        $pdo->beginTransaction();
        $rows = $pdo->query('SELECT * FROM user ORDER BY id')->fetchAll();
        activationValidate($rows, $plan, $snapshot, $mode === 'postflight');
        $pdo->rollBack();
        if ($mode === 'backup') {
            requireGate(isset($opts['backup']), 'ACT_BACKUP_REQUERIDO');
            $sha = outputBackup($opts['backup'], $rows);
            echo "CERVESUR_ACTIVACION_USUARIOS=PASS\nMODO=BACKUP\nFILAS_RESPALDADAS=190\n";
            echo "BACKUP_SHA256=" . $sha . "\nBACKUP_PRIVADO_CON_HASHES=SI\n";
        } elseif ($mode === 'postflight') {
            echo "CERVESUR_ACTIVACION_USUARIOS=PASS\nMODO=POSTFLIGHT\n";
            echo "TOTAL=190\nACTIVOS=176\nINACTIVOS=14\n";
            echo "MIGRADOS_ACTIVOS=114\nANTIGUOS_INTACTOS=76\nMYSQL_MODIFICADO=NO\n";
        } else {
            echo "CERVESUR_ACTIVACION_USUARIOS=PASS\nMODO=CHECK\n";
            echo "MIGRADOS_INACTIVOS_PREPARADOS=114\nANTIGUOS_INTACTOS=76\n";
            echo "TOTAL=190\nACTIVOS=62\nINACTIVOS=128\nMYSQL_MODIFICADO=NO\n";
        }
    }
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    $reason = $e instanceof RuntimeException ? $e->getMessage() : 'ACT_OPERACION';
    if (!preg_match('/^[A-Z_]+$/D', $reason)) $reason = 'ACT_OPERACION';
    fwrite(STDERR, "CERVESUR_ACTIVACION_USUARIOS=ERROR_" . $reason . "\n");
    exit(2);
}
