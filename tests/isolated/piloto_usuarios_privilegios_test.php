<?php
// UX-07D.4B · Mínimo privilegio REAL de las TRES cuentas del piloto con usuarios (MySQL sintético desechable, cuentas creadas por el procedimiento administrativo real).
//   cervesur_ro_auth (9 columnas de user) · cervesur_ro_catalogo (21 columnas, sin user) · cervesur_react_estado (DML solo en su base).
// Suficiencia (cada catálogo cerrado funciona), necesidad (quitar cualquier columna rompe algo), separación (el catálogo no ve user; auth no ve nada más) y denegaciones.
// Entorno: TEST_DB_PASS (root), U_AUTH_PASS, U_CAT_PASS, U_STATE_PASS.
require_once __DIR__ . '/../../backend/lib/usuarios_catalogo.php';
$failures = 0;
function expectCase($c, $label) { global $failures; if ($c) echo "PASS $label\n"; else { echo "FAIL $label\n"; $failures++; } }
function connect($user, $pass, $db = 'fixture_prod') { return new PDO("mysql:host=db;dbname=$db;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]); }
function errorOf(PDO $pdo, $sql) { try { $pdo->query($sql)->fetchAll(); return null; } catch (PDOException $e) { return (int)($e->errorInfo[1] ?? 0); } }
function execError(PDO $pdo, $sql) { try { $pdo->exec($sql); return null; } catch (PDOException $e) { return (int)($e->errorInfo[1] ?? 0); } }
function runStmt(PDO $pdo, $sql) {
    try {
        $st = $pdo->prepare($sql); $n = substr_count($sql, '?'); $login = str_contains($sql, 'username = ?');
        for ($i = 0; $i < $n; $i++) { $v = $login ? 'x' : ($n === 3 ? [1, 5, 0][$i] : 1); $st->bindValue($i + 1, $v, is_int($v) ? PDO::PARAM_INT : PDO::PARAM_STR); }
        $st->execute(); $st->fetchAll(); return null;
    } catch (PDOException $e) { return (int)($e->errorInfo[1] ?? 0); }
}
const DENIED = [1142, 1143, 1044, 1227, 1370, 1410, 1792, 1045];

$root = connect('root', getenv('TEST_DB_PASS'));
$expectedAuth = ['user' => ['first_name', 'id', 'is_active', 'last_name', 'password', 'role', 'session_version', 'username', 'email']];
sort($expectedAuth['user']);
$expectedCat = ['empresa' => ['activo', 'ciudad', 'id', 'nombre', 'ruc'], 'empresa_reporte' => ['activo', 'empresa_id', 'id', 'reporte_id'], 'empresa_usuario' => ['activo', 'empresa_id', 'user_id'], 'reporte' => ['activo', 'descripcion', 'enlace', 'id', 'nombre', 'reporte_id'], 'user_empresa_reporte' => ['activo', 'empresa_reporte_id', 'user_id']];
$grantedOf = function ($account) use ($root) { $g = []; foreach ($root->query("SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMN_PRIVILEGES WHERE GRANTEE = \"'$account'@'%'\" AND TABLE_SCHEMA = 'fixture_prod' ORDER BY TABLE_NAME, COLUMN_NAME") as $r) $g[$r['TABLE_NAME']][] = $r['COLUMN_NAME']; ksort($g); foreach ($g as &$c) sort($c); return $g; };

echo "== Columnas concedidas\n";
expectCase($grantedOf('cervesur_ro_auth') === $expectedAuth, 'cervesur_ro_auth: exactamente las 9 columnas de la tabla user');
$cat = $grantedOf('cervesur_ro_catalogo');
expectCase($cat === $expectedCat && array_sum(array_map('count', $cat)) === 21, 'cervesur_ro_catalogo: exactamente 21 columnas (6+5+4+3+3) y NINGUNA de la tabla user');
foreach (['cervesur_ro_auth', 'cervesur_ro_catalogo'] as $a) {
    foreach (['USER_PRIVILEGES' => "AND PRIVILEGE_TYPE <> 'USAGE'", 'SCHEMA_PRIVILEGES' => '', 'TABLE_PRIVILEGES' => ''] as $view => $extra) expectCase((int)$root->query("SELECT COUNT(*) FROM information_schema.$view WHERE GRANTEE = \"'$a'@'%'\" $extra")->fetchColumn() === 0, "$a: sin privilegios en $view (solo por columna)");
}
expectCase((int)$root->query("SELECT COUNT(*) FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE = \"'cervesur_react_estado'@'%'\" AND TABLE_SCHEMA = 'estado_piloto_test' AND PRIVILEGE_TYPE IN ('SELECT','INSERT','UPDATE','DELETE')")->fetchColumn() === 4
    && (int)$root->query("SELECT COUNT(*) FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE = \"'cervesur_react_estado'@'%'\"")->fetchColumn() === 4
    && (int)$root->query("SELECT COUNT(*) FROM information_schema.USER_PRIVILEGES WHERE GRANTEE = \"'cervesur_react_estado'@'%'\" AND PRIVILEGE_TYPE <> 'USAGE'")->fetchColumn() === 0, 'cervesur_react_estado: SELECT/INSERT/UPDATE/DELETE únicamente en su base de estado; nada global');

echo "== Suficiencia: los catálogos cerrados funcionan\n";
$auth = connect('cervesur_ro_auth', (string)getenv('U_AUTH_PASS')); $cc = connect('cervesur_ro_catalogo', (string)getenv('U_CAT_PASS'));
$f1 = []; foreach (usuarios_auth_consultas() as $n => $sql) if (runStmt($auth, $sql) !== null) $f1[] = $n;
$f2 = []; foreach (usuarios_catalogo_consultas() as $n => $sql) if (runStmt($cc, $sql) !== null) $f2[] = $n;
expectCase($f1 === [] && $f2 === [], 'las ' . count(usuarios_auth_consultas()) . ' consultas de autenticación y las ' . count(usuarios_catalogo_consultas()) . ' del catálogo se ejecutan con sus cuentas' . ($f1 || $f2 ? ' · FALLAN: ' . implode(',', array_merge($f1, $f2)) : ''));

echo "== Necesidad: ninguna columna sobra\n";
$necesidad = function (array $expected, array $queries, $prefix) use ($root) {
    $sobran = []; $n = 0;
    foreach ($expected as $table => $cols) foreach ($cols as $col) {
        $n++; $tmp = $prefix . $n; $pass = 'Aa1!' . bin2hex(random_bytes(8));
        $root->exec("DROP USER IF EXISTS '$tmp'@'%'"); $root->exec("CREATE USER '$tmp'@'%' IDENTIFIED BY '$pass'");
        foreach ($expected as $t => $cs) { $keep = array_values(array_filter($cs, fn ($c) => !($t === $table && $c === $col))); if ($keep) $root->exec('GRANT SELECT (' . implode(', ', array_map(fn ($c) => "`$c`", $keep)) . ") ON `fixture_prod`.`$t` TO '$tmp'@'%'"); }
        $probe = connect($tmp, $pass); $broken = 0; foreach ($queries as $sql) if (runStmt($probe, $sql) !== null) $broken++;
        if ($broken === 0) $sobran[] = "$table.$col";
        $probe = null; $root->exec("DROP USER '$tmp'@'%'");
    }
    return [$n, $sobran];
};
[$n1, $s1] = $necesidad($expectedAuth, usuarios_auth_consultas(), 'tmp_aut_');
[$n2, $s2] = $necesidad($expectedCat, usuarios_catalogo_consultas(), 'tmp_cat_');
expectCase($s1 === [], "las $n1 columnas de autenticación son imprescindibles" . ($s1 ? ' · SOBRAN: ' . implode(', ', $s1) : ''));
expectCase($s2 === [], "las $n2 columnas del catálogo son imprescindibles" . ($s2 ? ' · SOBRAN: ' . implode(', ', $s2) : ''));

echo "== Separación de funciones y denegaciones\n";
$catDenied = ['SELECT id FROM user', 'SELECT password FROM user', 'SELECT COUNT(*) FROM user', 'SELECT * FROM user', 'SELECT powerbi_tenant FROM empresa', 'SELECT powerbi_client FROM empresa', 'SELECT powerbi_clientsecret FROM empresa', 'SELECT email FROM empresa', 'SELECT razon_social FROM empresa', 'SELECT grupo_id FROM reporte', 'SELECT created_by FROM reporte', 'SELECT notas FROM empresa_usuario', 'SELECT id FROM empresa_usuario', 'SELECT id FROM user_empresa_reporte', 'SELECT notas FROM empresa_reporte'];
$bad = []; foreach ($catDenied as $s) if (!in_array(errorOf($cc, $s), [1142, 1143], true)) $bad[] = $s;
expectCase($bad === [], 'la cuenta de CATÁLOGO no puede leer la tabla user (ni hashes ni nada), ni credenciales de Power BI, ni columnas ajenas (' . count($catDenied) . ' casos)' . ($bad ? ' · LEÍDO: ' . implode(' | ', $bad) : ''));
$authDenied = ['SELECT enlace FROM reporte', 'SELECT id FROM reporte', 'SELECT id FROM empresa', 'SELECT powerbi_clientsecret FROM empresa', 'SELECT user_id FROM empresa_usuario', 'SELECT user_id FROM user_empresa_reporte', 'SELECT id FROM empresa_reporte', 'SELECT phone FROM user', 'SELECT avatar FROM user', 'SELECT last_login FROM user', 'SELECT * FROM user'];
$bad = []; foreach ($authDenied as $s) if (!in_array(errorOf($auth, $s), [1142, 1143], true)) $bad[] = $s;
expectCase($bad === [], 'la cuenta de AUTENTICACIÓN solo ve 9 columnas de user: ni enlaces, ni catálogo, ni relaciones, ni teléfono/avatar/último acceso (' . count($authDenied) . ' casos)' . ($bad ? ' · LEÍDO: ' . implode(' | ', $bad) : ''));
$w = [];
foreach (['user', 'reporte', 'empresa', 'empresa_reporte', 'empresa_usuario', 'user_empresa_reporte'] as $t) array_push($w, "INSERT INTO `$t` (id) VALUES (999999)", "UPDATE `$t` SET id = id WHERE id = 0", "DELETE FROM `$t` WHERE id = 0", "TRUNCATE TABLE `$t`", "ALTER TABLE `$t` ADD COLUMN zz INT", "DROP TABLE `$t`");
array_push($w, 'UPDATE user SET last_login = NOW()', 'UPDATE user SET session_version = session_version + 1', 'UPDATE user SET password = \'x\'', 'CREATE TABLE zz (a INT)', 'CREATE DATABASE zz', "CREATE USER 'zz'@'%' IDENTIFIED BY 'Aa1!xxxxxxxx'", "GRANT SELECT ON *.* TO 'zz'@'%'", 'DROP DATABASE fixture_prod', 'SET GLOBAL read_only = 0');
$bad = []; foreach (['auth' => $auth, 'catalogo' => $cc] as $name => $pdo) foreach ($w as $s) if (!in_array(execError($pdo, $s), DENIED, true)) $bad[] = "$name: $s";
expectCase($bad === [], count($w) . ' escrituras, DDL, GRANT y cambios globales × 2 cuentas de lectura: todos DENEGADOS (incluye last_login y session_version de Flutter)' . ($bad ? ' · ' . implode(' | ', array_slice($bad, 0, 4)) : ''));
$estado = connect('cervesur_react_estado', (string)getenv('U_STATE_PASS'), 'estado_piloto_test');
$estadoOk = true; foreach (['SELECT id FROM fixture_prod.user', 'SELECT id FROM fixture_prod.reporte'] as $s) if (!in_array(errorOf($estado, $s), [1142, 1143, 1044], true)) $estadoOk = false;
foreach (['INSERT INTO fixture_prod.user (id) VALUES (999999)', 'UPDATE fixture_prod.user SET session_version = 9', 'DELETE FROM fixture_prod.reporte', 'DROP TABLE fixture_prod.user', 'CREATE TABLE estado_piloto_test.zz (a INT)', 'ALTER TABLE estado_piloto_test.sesiones ADD COLUMN zz INT', 'DROP TABLE estado_piloto_test.auditoria', "GRANT SELECT ON *.* TO 'zz'@'%'"] as $s) if (!in_array(execError($estado, $s), DENIED, true)) { $estadoOk = false; echo "  no denegado: $s\n"; }
expectCase($estadoOk, 'la cuenta de ESTADO no ve nada de cervesur_portalbi, no puede escribir en él ni hacer DDL, ni en su propia base (solo DML), ni GRANT');
expectCase(execError($estado, "INSERT INTO usuarios_piloto (user_id, nota, activo, creado_en, creado_por) VALUES (999999, 'prueba', 1, UTC_TIMESTAMP(), 'test')") === null && execError($estado, 'DELETE FROM usuarios_piloto WHERE user_id = 999999') === null, 'la cuenta de estado sí escribe (DML) en su propia base');
$dbs = array_column($estado->query('SHOW DATABASES')->fetchAll(), 'Database');
expectCase(!in_array('fixture_prod', $dbs, true) && !in_array('mysql', $dbs, true), 'SHOW DATABASES de la cuenta de estado no revela la base de Flutter ni mysql');
expectCase(!in_array('estado_piloto_test', array_column($auth->query('SHOW DATABASES')->fetchAll(), 'Database'), true) && !in_array('estado_piloto_test', array_column($cc->query('SHOW DATABASES')->fetchAll(), 'Database'), true), 'las cuentas de lectura no ven la base de estado');
expectCase((int)$auth->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'fixture_prod'")->fetchColumn() === 9 && (int)$cc->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = 'fixture_prod'")->fetchColumn() === 21, 'information_schema solo muestra a cada cuenta sus columnas concedidas (9 y 21)');
echo $failures === 0 ? "\nAll user-pilot least-privilege cases passed\n" : "\n$failures case(s) failed\n";
exit($failures === 0 ? 0 : 1);
