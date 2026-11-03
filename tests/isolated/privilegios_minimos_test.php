<?php
// UX-07D.3A.1 · Mínimo privilegio REAL de la cuenta de diagnóstico, contra un MySQL sintético desechable y con las cuentas creadas por el procedimiento
// administrativo real (database/schema/admin-requiere-autorizacion/01-cuenta-diagnostico.sql). Solo red Docker aislada y datos ficticios.
//   1. La lista de columnas es SUFICIENTE (todo el catálogo funciona) y NECESARIA (quitar cualquier columna rompe al menos un diagnóstico).
//   2. Lectura de columnas sensibles, escrituras, DDL, otras bases y metadatos de seguridad: DENEGADOS.
//   3. La premisa del hallazgo de la guardia SQL: MySQL ejecuta «WITH … UPDATE/DELETE»; la cuenta y la sesión de solo lectura lo impiden aunque la guardia fallase.
// Entorno: TEST_DB_PASS (root del MySQL desechable), RO_DIAG_PASS (clave de la cuenta de diagnóstico).
require_once __DIR__ . '/../../backend/lib/diagnostico_catalogo.php';

$failures = 0;
function expectCase($condition, $label) {
    global $failures;
    if ($condition) echo "PASS $label\n"; else { echo "FAIL $label\n"; $failures++; }
}
function connect($user, $pass, $db = 'fixture_prod') {
    return new PDO("mysql:host=db;dbname=$db;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
}
function errorOf(PDO $pdo, $sql) { try { $pdo->query($sql)->fetchAll(); return null; } catch (PDOException $e) { return (int)($e->errorInfo[1] ?? 0); } }
function execError(PDO $pdo, $sql) { try { $pdo->exec($sql); return null; } catch (PDOException $e) { return (int)($e->errorInfo[1] ?? 0); } }
const DENIED = [1142, 1143, 1044, 1227, 1370, 1410, 1792, 1045];

$root = connect('root', getenv('TEST_DB_PASS'));
$diag = connect('cervesur_ro_diag', (string)getenv('RO_DIAG_PASS'));

// ------------------------------------------------------------------ columnas concedidas (leídas de las concesiones REALES de la cuenta)
$granted = [];
foreach ($root->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMN_PRIVILEGES WHERE GRANTEE = \"'cervesur_ro_diag'@'%'\" AND TABLE_SCHEMA = 'fixture_prod' ORDER BY TABLE_NAME, COLUMN_NAME") as $r) {
    $granted[$r['TABLE_NAME']][] = $r['COLUMN_NAME'];
}
echo "== Columnas concedidas a cervesur_ro_diag\n";
$expectedColumns = [
    'empresa' => ['activo', 'id'],
    'empresa_reporte' => ['activo', 'empresa_id', 'id', 'reporte_id'],
    'empresa_usuario' => ['activo', 'empresa_id', 'user_id'],
    'reporte' => ['activo', 'id'],
    'user' => ['id', 'is_active', 'role', 'session_version'],
    'user_empresa_reporte' => ['activo', 'empresa_reporte_id', 'user_id'],
];
ksort($granted); foreach ($granted as &$cols) sort($cols); unset($cols);
expectCase($granted === $expectedColumns, 'las columnas concedidas son exactamente las 18 esperadas en 6 tablas: ' . json_encode($granted));
expectCase((int)$root->query("SELECT COUNT(*) FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE = \"'cervesur_ro_diag'@'%'\"")->fetchColumn() === 0
    && (int)$root->query("SELECT COUNT(*) FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE = \"'cervesur_ro_diag'@'%'\"")->fetchColumn() === 0
    && (int)$root->query("SELECT COUNT(*) FROM information_schema.USER_PRIVILEGES WHERE GRANTEE = \"'cervesur_ro_diag'@'%'\" AND PRIVILEGE_TYPE <> 'USAGE'")->fetchColumn() === 0, 'sin permisos globales, de base ni de tabla completa: SOLO permisos por columna');

// ------------------------------------------------------------------ 1. suficiencia: todo el catálogo funciona con la cuenta real
echo "== Suficiencia: el catálogo completo funciona con la cuenta de diagnóstico\n";
$failed = []; $values = [];
foreach (diagnostico_catalogo_medidas() as $name => $m) { try { $values[$name] = (int)$diag->query($m['sql'])->fetchColumn(); } catch (PDOException $e) { $failed[] = $name; } }
expectCase($failed === [] && count($values) === 27, 'las 27 medidas se calculan con la cuenta de diagnóstico (' . count($values) . ' ejecutadas)');
$inventory = require __DIR__ . '/../../backend/lib/inventario_esperado.php';
expectCase(array_diff_assoc($inventory, $values) === [] && count($values) === 27, 'y coinciden con el inventario real conocido (fixture sintético)');
$ctl = diagnostico_catalogo_controles();
expectCase(in_array((int)$diag->query($ctl['sesion_solo_lectura'])->fetchColumn(), [0, 1], true), 'la sentencia de control de la sesión se ejecuta'); // el valor de ESTA conexión de prueba no importa: el de la del backend se verifica en conexion_solo_lectura_test.php
expectCase(count($diag->query($ctl['privilegios_de_la_cuenta'])->fetchAll(PDO::FETCH_COLUMN, 0)) >= 2 && $diag->query($ctl['base_conectada'])->fetchColumn() === 'fixture_prod', 'SHOW GRANTS y SELECT DATABASE() funcionan sin privilegios adicionales');

// ------------------------------------------------------------------ 2. necesidad: quitar CUALQUIER columna rompe algún diagnóstico
echo "== Necesidad: ninguna columna concedida sobra\n";
$unnecessary = []; $n = 0;
foreach ($expectedColumns as $table => $cols) {
    foreach ($cols as $col) {
        $n++;
        $tmp = 'tmp_min_' . $n; $pass = 'Aa1!' . bin2hex(random_bytes(8));
        $root->exec("DROP USER IF EXISTS '$tmp'@'%'");
        $root->exec("CREATE USER '$tmp'@'%' IDENTIFIED BY '$pass'");
        foreach ($expectedColumns as $t => $cs) {
            $keep = array_values(array_filter($cs, fn ($c) => !($t === $table && $c === $col)));
            if ($keep) $root->exec("GRANT SELECT (" . implode(', ', array_map(fn ($c) => "`$c`", $keep)) . ") ON `fixture_prod`.`$t` TO '$tmp'@'%'");
        }
        $probe = connect($tmp, $pass); $broken = 0;
        foreach (diagnostico_catalogo_medidas() as $name => $m) { if (errorOf($probe, $m['sql']) !== null) $broken++; }
        if ($broken === 0) $unnecessary[] = "$table.$col";
        $probe = null; $root->exec("DROP USER '$tmp'@'%'");
    }
}
expectCase($unnecessary === [], "las $n columnas son IMPRESCINDIBLES: quitar cualquiera rompe al menos un diagnóstico" . ($unnecessary ? ' · SOBRAN: ' . implode(', ', $unnecessary) : ''));

// ------------------------------------------------------------------ 3. denegaciones
echo "== Denegaciones de la cuenta de diagnóstico\n";
$root->exec('DROP DATABASE IF EXISTS otra_base_sintetica'); $root->exec('CREATE DATABASE otra_base_sintetica');
$root->exec('CREATE TABLE otra_base_sintetica.t (a INT)'); $root->exec('INSERT INTO otra_base_sintetica.t VALUES (1)');
$sensitive = ['SELECT password FROM user', 'SELECT email FROM user', 'SELECT phone FROM user', 'SELECT username FROM user', 'SELECT first_name FROM user', 'SELECT last_login FROM user', 'SELECT avatar FROM user', 'SELECT * FROM user',
    'SELECT enlace FROM reporte', 'SELECT grupo_id FROM reporte', 'SELECT reporte_id FROM reporte', 'SELECT nombre FROM reporte', 'SELECT * FROM reporte',
    'SELECT powerbi_tenant FROM empresa', 'SELECT powerbi_client FROM empresa', 'SELECT powerbi_clientsecret FROM empresa', 'SELECT ruc FROM empresa', 'SELECT * FROM empresa',
    'SELECT notas FROM empresa_usuario', 'SELECT id FROM empresa_usuario', 'SELECT created_by FROM empresa_reporte', 'SELECT id FROM user_empresa_reporte', 'SELECT notas FROM user_empresa_reporte', 'SELECT * FROM empresa_usuario',
    'SELECT user.password FROM user JOIN empresa ON 1 = 1', 'SELECT COUNT(*) FROM user WHERE password LIKE \'$2y$%\'', 'SELECT COUNT(*) FROM reporte WHERE enlace IS NOT NULL', 'SELECT LENGTH(password) FROM user', 'SELECT MAX(powerbi_clientsecret) FROM empresa',
    'SELECT SUBSTR(password, 1, 1) FROM user ORDER BY id LIMIT 1', 'SELECT id FROM user ORDER BY password', 'SELECT COUNT(*) FROM user GROUP BY email'];
$denied = 0; $leaked = [];
foreach ($sensitive as $sql) { if (in_array(errorOf($diag, $sql), [1142, 1143], true)) $denied++; else $leaked[] = $sql; }
expectCase($leaked === [], 'SELECT de user.password, reporte.enlace, empresa.powerbi_clientsecret y de cualquier columna no concedida (incluso en WHERE/ORDER BY/GROUP BY/agregados): DENEGADO (' . $denied . ' casos)' . ($leaked ? ' · LEÍDO: ' . implode(' | ', $leaked) : ''));
$rootThread = (int)$root->query('SELECT CONNECTION_ID()')->fetchColumn();
$writes = [];
foreach (['user', 'empresa', 'reporte', 'empresa_usuario', 'empresa_reporte', 'user_empresa_reporte'] as $t) {
    array_push($writes, "INSERT INTO `$t` (id) VALUES (999999)", "INSERT INTO `$t` SELECT * FROM `$t`", "UPDATE `$t` SET id = id WHERE id = 0", "DELETE FROM `$t` WHERE id = 0", "REPLACE INTO `$t` (id) VALUES (999999)", "TRUNCATE TABLE `$t`", "ALTER TABLE `$t` ADD COLUMN zz INT", "DROP TABLE `$t`", "CREATE INDEX zz ON `$t` (id)", "CREATE TRIGGER zz BEFORE INSERT ON `$t` FOR EACH ROW SET @a = 1", "RENAME TABLE `$t` TO zz_$t", "LOCK TABLES `$t` WRITE", "CREATE VIEW zz_v AS SELECT id FROM `$t`");
}
array_push($writes, 'CREATE TABLE zz (a INT)', 'CREATE DATABASE zz', 'DROP DATABASE fixture_prod', "CREATE USER 'zz'@'%' IDENTIFIED BY 'Aa1!xxxxxxxx'", "GRANT SELECT ON *.* TO 'zz'@'%'", "GRANT SELECT ON fixture_prod.* TO 'cervesur_ro_diag'@'%'", 'SET GLOBAL read_only = 0',
    'CREATE FUNCTION zz() RETURNS INT RETURN 1', 'CREATE PROCEDURE zz() SELECT 1', 'CREATE EVENT zz ON SCHEDULE EVERY 1 DAY DO SELECT 1', "LOAD DATA INFILE '/etc/passwd' INTO TABLE user", "SELECT 1 INTO OUTFILE '/tmp/zz'", 'FLUSH PRIVILEGES', 'FLUSH TABLES', 'KILL ' . $rootThread, 'RESET MASTER', 'SHUTDOWN', 'INSTALL PLUGIN zz SONAME \'zz.so\'');
$wDenied = 0; $wBad = [];
foreach ($writes as $sql) { $e = execError($diag, $sql); if (in_array($e, DENIED, true) || $e === 1095 || ($e === 1419 && str_starts_with($sql, 'CREATE TRIGGER'))) { $wDenied++; } else { $wBad[] = $sql . ' → ' . json_encode($e); } }
expectCase((int)$root->query("SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = 'fixture_prod'")->fetchColumn() === 0 && (int)$root->query("SELECT COUNT(*) FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = 'fixture_prod'")->fetchColumn() === 0 && (int)$root->query("SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA = 'fixture_prod'")->fetchColumn() === 0, 'no se creó ningún disparador, rutina ni vista (el error 1419 de CREATE TRIGGER es un rechazo, no una creación)');
expectCase($wBad === [], 'INSERT/UPDATE/DELETE/REPLACE/TRUNCATE, CREATE/ALTER/DROP/RENAME, vistas, disparadores, rutinas, eventos, GRANT, CREATE USER, LOAD DATA, INTO OUTFILE, SET GLOBAL, FLUSH, KILL, SHUTDOWN: todo DENEGADO (' . $wDenied . ' intentos)' . ($wBad ? ' · NO RECHAZADO: ' . implode(' | ', $wBad) : ''));
$others = ['SELECT a FROM otra_base_sintetica.t', 'SELECT COUNT(*) FROM otra_base_sintetica.t', 'SELECT * FROM mysql.user', 'SELECT user FROM mysql.user', 'SELECT * FROM mysql.db', 'SELECT * FROM mysql.role_edges', 'SELECT * FROM performance_schema.events_statements_summary_by_user_by_event_name',
    'SELECT * FROM sys.sys_config', 'SELECT * FROM information_schema.user_privileges WHERE GRANTEE <> "\'cervesur_ro_diag\'@\'%\'"'];
$oDenied = 0; $oBad = [];
foreach ($others as $sql) { $e = errorOf($diag, $sql); if (in_array($e, [1142, 1143, 1044, 1049], true)) $oDenied++; elseif ($e === null && str_contains($sql, 'information_schema.user_privileges')) { $rows = $diag->query($sql)->fetchAll(); if ($rows === []) $oDenied++; else $oBad[] = $sql; } else $oBad[] = $sql . ' → ' . json_encode($e); }
expectCase($oBad === [], 'acceso a OTRAS bases (otra base sintética, mysql, performance_schema, sys) y a los privilegios de otras cuentas: DENEGADO (' . $oDenied . ' casos)' . ($oBad ? ' · ' . implode(' | ', $oBad) : ''));
expectCase(execError($diag, 'USE mysql') === 1044 && execError($diag, 'USE otra_base_sintetica') === 1044, 'USE de otra base: DENEGADO');
$dbs = array_column($diag->query('SHOW DATABASES')->fetchAll(), 'Database'); sort($dbs);
expectCase(!in_array('otra_base_sintetica', $dbs, true) && !in_array('mysql', $dbs, true) && !in_array('sys', $dbs, true), 'SHOW DATABASES no revela otras bases (visibles: ' . implode(', ', $dbs) . ')');
$visibleCols = (int)$diag->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'fixture_prod'")->fetchColumn();
expectCase($visibleCols === 18, "information_schema solo muestra a la cuenta las 18 columnas concedidas (vista: $visibleCols)");
$root->exec('DROP DATABASE otra_base_sintetica');

// ------------------------------------------------------------------ 4. premisa del hallazgo de la guardia
echo "== Premisa: MySQL ejecuta «WITH … UPDATE/DELETE»; solo la sesión y la cuenta lo impiden\n";
$root->exec('DROP TABLE IF EXISTS fixture_prod.zz_cte'); $root->exec('CREATE TABLE fixture_prod.zz_cte (id INT PRIMARY KEY, v INT)'); $root->exec('INSERT INTO fixture_prod.zz_cte VALUES (1, 10), (2, 20)');
$cteUpdate = 'WITH c AS (SELECT 1 AS id) UPDATE fixture_prod.zz_cte t JOIN c ON c.id = t.id SET t.v = 99';
$cteDelete = 'WITH c AS (SELECT 2 AS id) DELETE FROM fixture_prod.zz_cte WHERE id IN (SELECT id FROM c)';
$rootRw = connect('root', getenv('TEST_DB_PASS'));
expectCase(execError($rootRw, $cteUpdate) === null && (int)$root->query('SELECT v FROM fixture_prod.zz_cte WHERE id = 1')->fetchColumn() === 99, 'PREMISA CONFIRMADA: sin protección, «WITH … UPDATE» MODIFICA datos (empezar por WITH no demuestra que sea una lectura)');
expectCase(execError($rootRw, $cteDelete) === null && (int)$root->query('SELECT COUNT(*) FROM fixture_prod.zz_cte')->fetchColumn() === 1, 'PREMISA CONFIRMADA: «WITH … DELETE» borra filas');
$root->exec('TRUNCATE fixture_prod.zz_cte'); $root->exec('INSERT INTO fixture_prod.zz_cte VALUES (1, 10), (2, 20)');
$rootRo = connect('root', getenv('TEST_DB_PASS')); $rootRo->exec('SET SESSION TRANSACTION READ ONLY');
expectCase(execError($rootRo, $cteUpdate) === 1792 && execError($rootRo, $cteDelete) === 1792 && (int)$root->query('SELECT SUM(v) FROM fixture_prod.zz_cte')->fetchColumn() === 30, 'con la sesión de solo lectura, incluso root recibe 1792 y los datos NO cambian (capa independiente de la guardia)');
$root->exec("GRANT SELECT ON fixture_prod.zz_cte TO 'cervesur_ro_diag'@'%'"); // concesión de SELECT sobre la tabla de prueba: aun así no puede escribir
$diag2 = connect('cervesur_ro_diag', (string)getenv('RO_DIAG_PASS'));
$cteDiag = ['WITH c AS (SELECT 1 AS id) UPDATE fixture_prod.zz_cte t JOIN c ON c.id = t.id SET t.v = 0', 'WITH c AS (SELECT 2 AS id) DELETE FROM fixture_prod.zz_cte WHERE id IN (SELECT id FROM c)', 'WITH c AS (SELECT 1 AS id) UPDATE fixture_prod.user u JOIN c ON c.id = u.id SET u.role = \'admin\'', 'WITH c AS (SELECT 1 AS id) DELETE FROM fixture_prod.reporte WHERE id IN (SELECT id FROM c)', 'INSERT INTO fixture_prod.reporte (id) WITH c AS (SELECT 1 AS id) SELECT id FROM c'];
$cteDenied = 0; $cteCodes = []; foreach ($cteDiag as $sql) { $c = execError($diag2, $sql); $cteCodes[] = $c; if (in_array($c, DENIED, true)) $cteDenied++; }
expectCase($cteDenied === count($cteDiag) && (int)$root->query('SELECT SUM(v) FROM fixture_prod.zz_cte')->fetchColumn() === 30, 'la cuenta de diagnóstico, aun con SELECT sobre la tabla, rechaza «WITH … UPDATE/DELETE/INSERT» por falta de privilegios de escritura (' . $cteDenied . ' casos) y los datos no cambian' . ($cteDenied !== count($cteDiag) ? ' · códigos: ' . json_encode($cteCodes) . ' · suma v=' . $root->query('SELECT SUM(v) FROM fixture_prod.zz_cte')->fetchColumn() : ''));
$root->exec("REVOKE SELECT ON fixture_prod.zz_cte FROM 'cervesur_ro_diag'@'%'"); $root->exec('DROP TABLE fixture_prod.zz_cte');

// ------------------------------------------------------------------ 5. LÍMITE CONOCIDO (se documenta, no se oculta)
echo "== Límite conocido de MySQL: la única «escritura» que una cuenta de solo columnas sí puede hacer\n";
$root->exec("DROP USER IF EXISTS 'obs_pwd'@'%'"); $root->exec("CREATE USER 'obs_pwd'@'%' IDENTIFIED BY 'Aa1!obsobsobs1'"); $root->exec("GRANT SELECT (id) ON fixture_prod.user TO 'obs_pwd'@'%'");
$self = connect('obs_pwd', 'Aa1!obsobsobs1');
expectCase(execError($self, "ALTER USER CURRENT_USER() IDENTIFIED BY 'Aa1!otraotra2'") === null, 'una cuenta de MySQL puede cambiar SU PROPIA contraseña aunque solo tenga SELECT por columna (no toca datos del portal; el backend y el catálogo nunca lo emiten; el procedimiento 04 cuenta set_password y alter_user)');
$root->exec("DROP USER 'obs_pwd'@'%'");

echo $failures === 0 ? "\nAll least-privilege cases passed\n" : "\n$failures case(s) failed\n";
exit($failures === 0 ? 0 : 1);
