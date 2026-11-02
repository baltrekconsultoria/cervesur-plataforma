<?php
// UX-08B.2: la bitácora de solo inserción (triggers) se aplica, bloquea UPDATE/DELETE y se revierte. Base propia y desechable (`fixture_audit_h`).
$failures = 0;
function expectCase($condition, $label) { global $failures; if ($condition) echo "PASS $label\n"; else { echo "FAIL $label\n"; $failures++; } }
$root = new PDO('mysql:host=db;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$root->exec('DROP DATABASE IF EXISTS fixture_audit_h');
$root->exec('CREATE DATABASE fixture_audit_h CHARACTER SET utf8mb4');
$pdo = new PDO('mysql:host=db;dbname=fixture_audit_h;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

/** Ejecuta un archivo de migración sentencia por sentencia (las sentencias no contienen «;» internos). */
function applyFile(PDO $pdo, $path) {
    $sql = preg_replace('/^--.*$/m', '', file_get_contents($path));
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) $pdo->exec($statement);
}
function fails(PDO $pdo, $sql) { try { $pdo->exec($sql); return null; } catch (PDOException $e) { return $e->getMessage(); } }

applyFile($pdo, 'database/migrations/UX-08B-audit-log.up.sql');
$insert = "INSERT INTO audit_log (correlation_id, actor_user_id, action, entity, entity_id) VALUES ('" . str_repeat('a', 32) . "', 1, 'empresa.create', 'empresa', 7)";
$pdo->exec($insert);
expectCase(fails($pdo, "UPDATE audit_log SET action = 'x'") === null, 'before hardening an audit row can be modified (the gap the hardening closes)');
$pdo->exec("UPDATE audit_log SET action = 'empresa.create'");

applyFile($pdo, 'database/migrations/UX-08B-audit-log-immutable.up.sql');
applyFile($pdo, 'database/migrations/UX-08B-audit-log-immutable.up.sql');
expectCase((int)$pdo->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'audit_log'")->fetchColumn() === 2, 'the hardening installs exactly two triggers and is idempotent');
expectCase($pdo->exec($insert) === 1, 'INSERT keeps working');
$msg = fails($pdo, "UPDATE audit_log SET action = 'manipulada'");
expectCase($msg !== null && stripos($msg, 'solo insercion') !== false, 'UPDATE is rejected');
$msg = fails($pdo, 'DELETE FROM audit_log');
expectCase($msg !== null && stripos($msg, 'solo insercion') !== false, 'DELETE is rejected');
expectCase((int)$pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn() === 2 && $pdo->query('SELECT action FROM audit_log LIMIT 1')->fetchColumn() === 'empresa.create', 'rows are untouched by the rejected statements');
$pdo->beginTransaction();
$pdo->exec($insert);
try { $pdo->exec('DELETE FROM audit_log'); } catch (PDOException $e) { }
expectCase($pdo->inTransaction(), 'a rejected DELETE raises an error but does not commit anything (the surrounding transaction can be rolled back)');
$pdo->rollBack();

applyFile($pdo, 'database/migrations/UX-08B-audit-log-immutable.down.sql');
expectCase((int)$pdo->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE() AND EVENT_OBJECT_TABLE = 'audit_log'")->fetchColumn() === 0 && (int)$pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn() === 2, 'the down migration removes only the triggers and keeps the data');
$root->exec('DROP DATABASE fixture_audit_h');
echo $failures === 0 ? "ALL PASSED\n" : "$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
