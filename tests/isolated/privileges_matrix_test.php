<?php
// UX-08E: la cuenta de privilegios mínimos (database/privileges/UX-08E-cuenta-admin-minima.sql.template) permite lo que la API necesita y
// NADA más. Todo ocurre en un MySQL desechable; el archivo nunca se ejecuta contra un servidor real.
$failures = 0;
function expectCase($condition, $label) { global $failures; if ($condition) echo "PASS $label\n"; else { echo "FAIL $label\n"; $failures++; } }
$rootPass = getenv('TEST_DB_PASS');
$root = new PDO('mysql:host=db;charset=utf8mb4', 'root', $rootPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
require __DIR__ . '/productive_fixture.php';

$root->exec('DROP DATABASE IF EXISTS fixture_priv');
$root->exec('CREATE DATABASE fixture_priv CHARACTER SET utf8mb4');
$admin = new PDO('mysql:host=db;dbname=fixture_priv;charset=utf8mb4', 'root', $rootPass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
productive_fixture_ddl($admin);
$admin->exec(file_get_contents('database/migrations/UX-08B-audit-log.up.sql'));
$admin->exec("INSERT INTO user (id, username, email, password, first_name, last_name, role, is_active) VALUES (1, 'admin', 'a@example.invalid', 'x', 'A', 'B', 'admin', 1)");
$admin->exec("INSERT INTO empresa (id, nombre, razon_social, ruc, powerbi_tenant, powerbi_clientsecret, activo) VALUES (1, 'E', 'E', '10000000001', 'tenant-x', 'secret-x', 1)");
$admin->exec("INSERT INTO reporte (id, nombre, enlace, grupo_id, reporte_id, activo) VALUES (1, 'R', 'https://example.invalid/r', 'g', 'ref-1', 1)");

// ---- Crear la cuenta con la plantilla (marcadores sustituidos solo aquí)
$pass = bin2hex(random_bytes(12));
$sql = strtr(file_get_contents('database/privileges/UX-08E-cuenta-admin-minima.sql.template'), ['{DB}' => 'fixture_priv', '{USER}' => 'app_admin_min', '{HOST}' => '%', '{PASS}' => $pass]);
$sql = preg_replace('/^--.*$/m', '', $sql);
foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) $root->exec($statement);
$app = new PDO('mysql:host=db;dbname=fixture_priv;charset=utf8mb4', 'app_admin_min', $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

function ok(PDO $pdo, $sql) { try { $pdo->exec($sql); return true; } catch (PDOException $e) { return false; } }
/** Rechazado por privilegios: 1142/1143 (tabla/columna), 1044 (base), 1227 (falta SUPER/privilegio), 1370 (rutina), 1419 (crear trigger sin SUPER con binlog). */
function denied(PDO $pdo, $sql) { try { $pdo->exec($sql); return false; } catch (PDOException $e) { $code = (int)($e->errorInfo[1] ?? 0); if (!in_array($code, [1142, 1143, 1044, 1227, 1370, 1419], true)) fwrite(STDERR, "  código inesperado $code: " . substr($e->getMessage(), 0, 120) . "
"); return in_array($code, [1142, 1143, 1044, 1227, 1370, 1419], true); } }
function rows(PDO $pdo, $sql) { return $pdo->query($sql)->fetchAll(); }

// ---- Lo que la API necesita
expectCase(count(rows($app, 'SELECT id FROM empresa')) === 1, 'reads the business tables');
expectCase(ok($app, "INSERT INTO empresa (nombre, razon_social, ruc, powerbi_tenant, powerbi_client, powerbi_clientsecret, logo, contacto_principal, created_by) VALUES ('N', 'N', '10000000002', NULL, NULL, NULL, NULL, NULL, 1)"), 'inserts a company with the columns the application names');
expectCase(ok($app, "INSERT INTO reporte (nombre, descripcion, enlace, grupo_id, reporte_id, created_by) VALUES ('R2', NULL, 'https://example.invalid/r2', 'g2', 'ref-2', 1)"), 'inserts a report');
expectCase(ok($app, "INSERT INTO user (username, email, password, first_name, last_name, role, is_active, session_version) VALUES ('u2', 'u2@example.invalid', 'h', 'U', 'Dos', 'user', 1, 1)"), 'inserts a user');
expectCase(ok($app, "UPDATE empresa SET ciudad = 'Lima', activo = 1, updated_by = 1 WHERE id = 1"), 'updates the editable company columns (logical deactivation included)');
expectCase(ok($app, 'UPDATE reporte SET activo = 0, updated_by = 1 WHERE id = 1'), 'deactivates a report logically');
expectCase(ok($app, 'UPDATE user SET is_active = 0, session_version = session_version + 1 WHERE id = 1'), 'deactivates a user and bumps the session version in one statement');
expectCase(ok($app, 'UPDATE user SET last_login = NOW() WHERE id = 1'), 'records last_login');
expectCase(count(rows($app, 'SELECT id FROM empresa WHERE id = 1 FOR UPDATE')) === 1 && count(rows($app, 'SELECT id FROM user WHERE id = 1 FOR UPDATE')) === 1, 'SELECT ... FOR UPDATE works (column-level UPDATE is enough)');
expectCase(ok($app, "INSERT INTO audit_log (correlation_id, actor_user_id, action, entity, entity_id) VALUES ('" . str_repeat('c', 32) . "', 1, 'empresa.create', 'empresa', 1)"), 'inserts audit rows');
expectCase(count(rows($app, 'SELECT id FROM audit_log')) === 1, 'reads the audit history');
$app->beginTransaction();
$app->exec("INSERT INTO empresa (nombre, razon_social, ruc) VALUES ('T', 'T', '10000000003')");
$app->rollBack();
expectCase(count(rows($admin, "SELECT id FROM empresa WHERE ruc = '10000000003'")) === 0, 'transactions roll back with the restricted account');

// ---- Lo que NO debe poder hacer
foreach (['empresa', 'reporte', 'user', 'empresa_usuario', 'empresa_reporte', 'user_empresa_reporte', 'audit_log'] as $table) {
    expectCase(denied($app, "DELETE FROM `$table`"), "cannot DELETE from $table");
}
expectCase(denied($app, "UPDATE audit_log SET action = 'x'"), 'cannot UPDATE the audit history');
expectCase(denied($app, 'TRUNCATE TABLE audit_log'), 'cannot TRUNCATE the audit history');
expectCase(denied($app, 'DROP TABLE audit_log'), 'cannot DROP the audit history');
expectCase(denied($app, "UPDATE empresa SET powerbi_clientsecret = 'otro' WHERE id = 1"), 'cannot change the Power BI client secret');
expectCase(denied($app, "UPDATE empresa SET powerbi_tenant = 'otro' WHERE id = 1"), 'cannot change the Power BI tenant');
expectCase(denied($app, "UPDATE user SET username = 'otro' WHERE id = 1"), 'cannot rename a user (username is not an updatable column)');
expectCase(denied($app, 'UPDATE user SET created_at = NOW() WHERE id = 1'), 'cannot rewrite creation dates');
expectCase(denied($app, 'ALTER TABLE empresa ADD COLUMN x INT'), 'cannot ALTER tables');
expectCase(denied($app, 'CREATE TABLE intruso (id INT)'), 'cannot CREATE tables');
expectCase(denied($app, 'CREATE TRIGGER t BEFORE INSERT ON empresa FOR EACH ROW SET NEW.nombre = NEW.nombre'), 'cannot CREATE or drop triggers (the append-only guard stays in place)');
expectCase(denied($app, "CREATE USER 'otro'@'%' IDENTIFIED BY 'x'"), 'cannot create accounts');
expectCase(denied($app, "GRANT SELECT ON fixture_priv.* TO 'otro'@'%'"), 'cannot grant privileges');
$grants = implode("\n", array_map(fn ($r) => (string)array_values($r)[0], rows($root, "SHOW GRANTS FOR 'app_admin_min'@'%'")));
expectCase(stripos($grants, 'DELETE') === false && stripos($grants, 'ALL PRIVILEGES') === false && stripos($grants, 'GRANT OPTION') === false && stripos($grants, 'DROP') === false, 'SHOW GRANTS contains no DELETE, DROP, ALL PRIVILEGES or GRANT OPTION');

$root->exec("DROP USER 'app_admin_min'@'%'");
$root->exec('DROP DATABASE fixture_priv');
echo $failures === 0 ? "ALL PASSED\n" : "$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
