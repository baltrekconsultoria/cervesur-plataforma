<?php
// UX-07D.4 express · Mínimo privilegio REAL de la cuenta del piloto visual (cervesur_ro_visor), con MySQL sintético desechable y la cuenta creada por el
// procedimiento administrativo real (database/schema/piloto-visual/01-cuenta-visor.sql).
//   1. Suficiencia: todo el catálogo cerrado (backend/lib/piloto_catalogo.php) funciona. 2. Necesidad: quitar CUALQUIERA de las 15 columnas rompe alguna consulta.
//   3. Denegaciones: tabla user (hashes), powerbi_*, datos de contacto, escrituras, DDL, otras bases.
// Entorno: TEST_DB_PASS (root), RO_VISOR_PASS.
require_once __DIR__ . '/../../backend/lib/piloto_catalogo.php';
$failures = 0;
function expectCase($c, $label) { global $failures; if ($c) echo "PASS $label\n"; else { echo "FAIL $label\n"; $failures++; } }
function connect($user, $pass, $db = 'fixture_prod') { return new PDO("mysql:host=db;dbname=$db;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]); }
function errorOf(PDO $pdo, $sql) { try { $pdo->query($sql)->fetchAll(); return null; } catch (PDOException $e) { return (int)($e->errorInfo[1] ?? 0); } }
function execError(PDO $pdo, $sql) { try { $pdo->exec($sql); return null; } catch (PDOException $e) { return (int)($e->errorInfo[1] ?? 0); } }
/** Ejecuta una sentencia del catálogo con parámetros enteros. Devuelve null o el código de error. */
function runCatalog(PDO $pdo, $sql) { try { $st = $pdo->prepare($sql); $n = substr_count($sql, '?'); $vals = $n === 2 ? [5, 0] : [1]; for ($i = 0; $i < $n; $i++) $st->bindValue($i + 1, $vals[$i], PDO::PARAM_INT); $st->execute(); $st->fetchAll(); return null; } catch (PDOException $e) { return (int)($e->errorInfo[1] ?? 0); } }
const DENIED = [1142, 1143, 1044, 1227, 1370, 1410, 1792, 1045];

$root = connect('root', getenv('TEST_DB_PASS'));
$visor = connect('cervesur_ro_visor', (string)getenv('RO_VISOR_PASS'));
$expected = ['empresa' => ['activo', 'ciudad', 'id', 'nombre', 'ruc'], 'empresa_reporte' => ['activo', 'empresa_id', 'id', 'reporte_id'], 'reporte' => ['activo', 'descripcion', 'enlace', 'id', 'nombre', 'reporte_id']];

echo "== Columnas concedidas a cervesur_ro_visor\n";
$granted = [];
foreach ($root->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMN_PRIVILEGES WHERE GRANTEE = \"'cervesur_ro_visor'@'%'\" AND TABLE_SCHEMA = 'fixture_prod' ORDER BY TABLE_NAME, COLUMN_NAME") as $r) $granted[$r['TABLE_NAME']][] = $r['COLUMN_NAME'];
ksort($granted); foreach ($granted as &$c) sort($c); unset($c);
expectCase($granted === $expected && array_sum(array_map('count', $granted)) === 15, 'exactamente 15 columnas (6+5+4): ' . json_encode($granted));
foreach (['USER_PRIVILEGES' => "AND PRIVILEGE_TYPE <> 'USAGE'", 'SCHEMA_PRIVILEGES' => '', 'TABLE_PRIVILEGES' => ''] as $view => $extra) {
    expectCase((int)$root->query("SELECT COUNT(*) FROM information_schema.$view WHERE GRANTEE = \"'cervesur_ro_visor'@'%'\" $extra")->fetchColumn() === 0, "sin privilegios en $view (solo permisos por columna)");
}

echo "== Suficiencia: el catálogo cerrado funciona con la cuenta real\n";
$failed = []; foreach (piloto_catalogo_consultas() as $name => $sql) { if (runCatalog($visor, $sql) !== null) $failed[] = $name; }
expectCase($failed === [], 'las ' . count(piloto_catalogo_consultas()) . ' consultas del catálogo se ejecutan' . ($failed ? ' · FALLAN: ' . implode(', ', $failed) : ''));

echo "== Necesidad: ninguna de las 15 columnas sobra\n";
$unnecessary = []; $n = 0;
foreach ($expected as $table => $cols) {
    foreach ($cols as $col) {
        $n++; $tmp = 'tmp_vis_' . $n; $pass = 'Aa1!' . bin2hex(random_bytes(8));
        $root->exec("DROP USER IF EXISTS '$tmp'@'%'"); $root->exec("CREATE USER '$tmp'@'%' IDENTIFIED BY '$pass'");
        foreach ($expected as $t => $cs) { $keep = array_values(array_filter($cs, fn ($c) => !($t === $table && $c === $col))); if ($keep) $root->exec('GRANT SELECT (' . implode(', ', array_map(fn ($c) => "`$c`", $keep)) . ") ON `fixture_prod`.`$t` TO '$tmp'@'%'"); }
        $probe = connect($tmp, $pass); $broken = 0;
        foreach (piloto_catalogo_consultas() as $sql) { if (runCatalog($probe, $sql) !== null) $broken++; }
        if ($broken === 0) $unnecessary[] = "$table.$col";
        $probe = null; $root->exec("DROP USER '$tmp'@'%'");
    }
}
expectCase($unnecessary === [], "las $n columnas son IMPRESCINDIBLES: quitar cualquiera rompe al menos una consulta" . ($unnecessary ? ' · SOBRAN: ' . implode(', ', $unnecessary) : ''));

echo "== Denegaciones\n";
$sens = ['SELECT password FROM user', 'SELECT id FROM user', 'SELECT username FROM user', 'SELECT email FROM user', 'SELECT role FROM user', 'SELECT * FROM user', 'SELECT COUNT(*) FROM user WHERE password LIKE \'$2y$%\'', 'SELECT COUNT(*) FROM user',
    'SELECT powerbi_tenant FROM empresa', 'SELECT powerbi_client FROM empresa', 'SELECT powerbi_clientsecret FROM empresa', 'SELECT razon_social FROM empresa', 'SELECT email FROM empresa', 'SELECT telefono FROM empresa', 'SELECT direccion FROM empresa', 'SELECT pais FROM empresa', 'SELECT logo FROM empresa', 'SELECT * FROM empresa',
    'SELECT grupo_id FROM reporte', 'SELECT created_by FROM reporte', 'SELECT * FROM reporte', 'SELECT notas FROM empresa_reporte', 'SELECT created_by FROM empresa_reporte', 'SELECT * FROM empresa_reporte',
    'SELECT id FROM empresa_usuario', 'SELECT user_id FROM user_empresa_reporte', 'SELECT * FROM empresa_usuario', 'SELECT * FROM user_empresa_reporte'];
$ok = 0; $leaked = []; foreach ($sens as $s) { if (in_array(errorOf($visor, $s), [1142, 1143], true)) $ok++; else $leaked[] = $s; }
expectCase($leaked === [], "tabla user (hashes), powerbi_*, contacto, razón social, grupo, auditoría y las tablas de usuarios: DENEGADO ($ok casos)" . ($leaked ? ' · LEÍDO: ' . implode(' | ', $leaked) : ''));
$w = [];
foreach (['reporte', 'empresa', 'empresa_reporte', 'user', 'empresa_usuario', 'user_empresa_reporte'] as $t) array_push($w, "INSERT INTO `$t` (id) VALUES (999999)", "UPDATE `$t` SET id = id WHERE id = 0", "DELETE FROM `$t` WHERE id = 0", "TRUNCATE TABLE `$t`", "ALTER TABLE `$t` ADD COLUMN zz INT", "DROP TABLE `$t`");
array_push($w, 'CREATE TABLE zz (a INT)', 'CREATE DATABASE zz', "CREATE USER 'zz'@'%' IDENTIFIED BY 'Aa1!xxxxxxxx'", "GRANT SELECT ON *.* TO 'zz'@'%'", 'UPDATE reporte SET enlace = \'x\'', 'UPDATE user SET last_login = NOW()', 'DROP DATABASE fixture_prod', 'SET GLOBAL read_only = 0');
$bad = []; foreach ($w as $s) { if (!in_array(execError($visor, $s), DENIED, true)) $bad[] = $s; }
expectCase($bad === [], count($w) . ' escrituras, DDL, GRANT y cambios globales: todos DENEGADOS' . ($bad ? ' · ' . implode(' | ', $bad) : ''));
$root->exec('DROP DATABASE IF EXISTS otra_base_vis'); $root->exec('CREATE DATABASE otra_base_vis'); $root->exec('CREATE TABLE otra_base_vis.t (a INT)');
$o = 0; foreach (['SELECT a FROM otra_base_vis.t', 'SELECT * FROM mysql.user', 'SELECT * FROM performance_schema.events_statements_summary_by_user_by_event_name'] as $s) if (in_array(errorOf($visor, $s), [1142, 1143, 1044], true)) $o++;
$dbs = array_column($visor->query('SHOW DATABASES')->fetchAll(), 'Database');
expectCase($o === 3 && !in_array('otra_base_vis', $dbs, true) && !in_array('mysql', $dbs, true), 'otras bases, mysql y performance_schema: DENEGADO; SHOW DATABASES no revela otras bases');
$root->exec('DROP DATABASE otra_base_vis');
expectCase((int)$visor->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'fixture_prod'")->fetchColumn() === 15, 'information_schema solo muestra a la cuenta las 15 columnas concedidas');
echo $failures === 0 ? "\nAll pilot least-privilege cases passed\n" : "\n$failures case(s) failed\n";
exit($failures === 0 ? 0 : 1);
