<?php
// UX-07D.3A.1 · La conexión REAL que abre backend/lib/db.php en modo diagnostico: sesión de solo lectura en MySQL (visible desde otra conexión), catálogo cerrado,
// imposibilidad de volver a read-write, y fail-closed del arranque (cada configuración peligrosa se prueba en un proceso PHP aparte). Red Docker aislada.
// Entorno: TEST_DB_PASS, RO_DIAG_PASS, RO_DIAG_TOKEN.
$failures = 0;
function expectCase($condition, $label) {
    global $failures;
    if ($condition) echo "PASS $label\n"; else { echo "FAIL $label\n"; $failures++; }
}

if (($argv[1] ?? '') === 'child') {
    // Proceso hijo: abre la conexión como lo hace la aplicación y la deja disponible a la prueba.
    foreach (['DB_HOST' => 'db', 'DB_NAME' => 'fixture_prod', 'DB_USER' => 'cervesur_ro_diag', 'DB_PASS' => getenv('RO_DIAG_PASS'), 'CERVESUR_MODE' => 'diagnostico', 'CERVESUR_REQUIRED_MODE' => 'diagnostico', 'CERVESUR_DIAG_TOKEN' => getenv('RO_DIAG_TOKEN')] as $k => $v) putenv("$k=$v");
    require __DIR__ . '/../../backend/lib/db.php';
    $root = new PDO('mysql:host=db;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $out = [];
    // 1. La conexión viva, vista DESDE OTRA conexión (root), está en solo lectura en MySQL
    $out['mysql_ve_ro'] = $root->query("SELECT v.VARIABLE_VALUE FROM performance_schema.variables_by_thread v JOIN performance_schema.threads t ON t.THREAD_ID = v.THREAD_ID WHERE t.PROCESSLIST_USER = 'cervesur_ro_diag' AND v.VARIABLE_NAME = 'transaction_read_only'")->fetchAll(PDO::FETCH_COLUMN);
    $out['sesion_ro'] = (int)$pdo->query('SELECT @@session.transaction_read_only')->fetchColumn();
    // 2. Catálogo cerrado: ninguna otra sentencia, ni siquiera de lectura
    $try = function ($fn) { try { $fn(); return 'EJECUTADO'; } catch (ReadOnlyViolation $e) { return 'rechazado-guardia'; } catch (PDOException $e) { return 'rechazado-mysql-' . ($e->errorInfo[1] ?? '?'); } };
    $cte = 'WITH c AS (SELECT 1 AS id) UPDATE user u JOIN c ON c.id = u.id SET u.role = \'admin\'';
    $out['fuera_de_catalogo'] = [
        $try(fn () => $pdo->query('SELECT id FROM user')), $try(fn () => $pdo->query('SELECT password FROM user')), $try(fn () => $pdo->query('SELECT 1')), $try(fn () => $pdo->query('SELECT COUNT(*) FROM user WHERE is_active = 1 OR 1 = 1')),
        $try(fn () => $pdo->prepare('SELECT COUNT(*) FROM user WHERE id = ?')), $try(fn () => $pdo->query($cte)), $try(fn () => $pdo->exec($cte)), $try(fn () => $pdo->exec('DELETE FROM user')), $try(fn () => $pdo->exec('SET SESSION TRANSACTION READ WRITE')),
        $try(fn () => $pdo->exec('START TRANSACTION READ WRITE')), $try(fn () => $pdo->query('SHOW GRANTS')), $try(fn () => $pdo->query('SHOW DATABASES')), $try(fn () => $pdo->useCatalog(['DELETE FROM user'])),
    ];
    $out['dentro_del_catalogo'] = [$try(fn () => $pdo->query('SELECT COUNT(*) FROM user')->fetchColumn()), $try(fn () => $pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll())];
    // 3. Tras todo lo anterior, la sesión SIGUE en solo lectura y nada cambió
    $out['sesion_ro_despues'] = (int)$pdo->query('SELECT @@session.transaction_read_only')->fetchColumn();
    $out['roles_admin'] = (int)$root->query("SELECT COUNT(*) FROM fixture_prod.user WHERE role = 'admin'")->fetchColumn();
    echo json_encode($out), "\n";
    exit(0);
}

function sh($cmd) { return (string)shell_exec($cmd . ' 2>/dev/null'); }
$self = __FILE__;
echo "== Conexión real de diagnóstico\n";
$root = new PDO('mysql:host=db;dbname=fixture_prod;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$adminsBefore = (int)$root->query("SELECT COUNT(*) FROM user WHERE role = 'admin'")->fetchColumn();
$checksumsBefore = $root->query('CHECKSUM TABLE user, empresa, reporte, empresa_usuario, empresa_reporte, user_empresa_reporte')->fetchAll();
$results = [];
for ($i = 1; $i <= 3; $i++) $results[] = json_decode(trim(sh(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($self) . ' child')), true);
expectCase(!in_array(null, $results, true), 'el proceso hijo abre la conexión con la configuración de diagnóstico (3 conexiones NUEVAS e independientes)');
if (!in_array(null, $results, true)) {
    foreach ($results as $i => $r) {
        $n = $i + 1;
        expectCase($r['sesion_ro'] === 1 && $r['mysql_ve_ro'] !== [] && count(array_unique($r['mysql_ve_ro'])) === 1 && $r['mysql_ve_ro'][0] === 'ON', "conexión nueva $n: MySQL (visto desde OTRA conexión) tiene transaction_read_only=ON en la sesión de diagnóstico");
        expectCase($r['fuera_de_catalogo'] === array_fill(0, 13, 'rechazado-guardia'), "conexión nueva $n: 13 sentencias fuera del catálogo (lecturas incluidas, CTE de escritura, cambio a READ WRITE, SHOW, ampliar el catálogo) las rechaza la guardia ANTES de llegar a MySQL");
        expectCase($r['dentro_del_catalogo'] === ['EJECUTADO', 'EJECUTADO'], "conexión nueva $n: las sentencias del catálogo sí funcionan");
        expectCase($r['sesion_ro_despues'] === 1 && $r['roles_admin'] === $adminsBefore, "conexión nueva $n: tras los intentos la sesión sigue en solo lectura y los datos no cambiaron");
    }
}
expectCase($root->query('CHECKSUM TABLE user, empresa, reporte, empresa_usuario, empresa_reporte, user_empresa_reporte')->fetchAll() === $checksumsBefore, 'las seis tablas quedaron idénticas');

echo "== Arranque fail-closed (cada caso en un proceso aparte)\n";
$request = function (array $env) {
    $code = 'foreach (' . var_export($env, true) . ' as $k => $v) putenv("$k=$v"); register_shutdown_function(function () { echo "\nHTTP:" . http_response_code(); }); require ' . var_export(__DIR__ . '/../../backend/lib/db.php', true) . '; echo "ARRANCO";';
    return sh(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code));
};
$good = ['DB_HOST' => 'db', 'DB_NAME' => 'fixture_prod', 'DB_USER' => 'cervesur_ro_diag', 'DB_PASS' => (string)getenv('RO_DIAG_PASS'), 'CERVESUR_MODE' => 'diagnostico', 'CERVESUR_REQUIRED_MODE' => 'diagnostico', 'CERVESUR_DIAG_TOKEN' => (string)getenv('RO_DIAG_TOKEN')];
expectCase(str_contains($request($good), 'ARRANCO'), 'configuración correcta del sitio de diagnóstico: arranca');
$bad = [
    'modo ausente con modo exigido' => array_diff_key($good, ['CERVESUR_MODE' => 1]),
    'modo vacío con modo exigido' => ['CERVESUR_MODE' => ''] + $good,
    'modo read_only con modo exigido diagnostico' => ['CERVESUR_MODE' => 'read_only', 'JWT_SECRET' => str_repeat('j', 40)] + $good,
    'modo read_write con modo exigido diagnostico' => ['CERVESUR_MODE' => 'read_write'] + $good,
    'modo inválido' => ['CERVESUR_MODE' => 'diagnóstico'] + $good,
    'modo exigido inválido' => ['CERVESUR_REQUIRED_MODE' => 'lectura'] + $good,
    'token de diagnóstico ausente' => ['CERVESUR_DIAG_TOKEN' => ''] + $good,
    'token de diagnóstico corto' => ['CERVESUR_DIAG_TOKEN' => 'corto'] + $good,
    'token igual al JWT_SECRET' => ['JWT_SECRET' => (string)getenv('RO_DIAG_TOKEN')] + $good,
    'base productiva configurada y cuenta con escritura permitida' => ['DB_NAME' => 'cervesur_portalbi', 'CERVESUR_ALLOW_WRITABLE_DB_ACCOUNT' => 'SI'] + $good,
    'base marcada productiva por lista y cuenta con escritura permitida' => ['CERVESUR_PRODUCTION_DBS' => 'fixture_prod', 'CERVESUR_ALLOW_WRITABLE_DB_ACCOUNT' => 'SI'] + $good,
    'nombre de base con inyección de DSN' => ['DB_NAME' => 'fixture_prod;dbname=cervesur_portalbi'] + $good,
    'nombre de base con espacio y mayúsculas tipo productiva' => ['DB_NAME' => ' CERVESUR_PORTALBI'] + $good,
    'cuenta de MySQL con escritura (root)' => ['DB_USER' => 'root', 'DB_PASS' => (string)getenv('TEST_DB_PASS')] + $good,
];
foreach ($bad as $label => $env) {
    $o = $request($env);
    expectCase(!str_contains($o, 'ARRANCO') && (str_contains($o, 'HTTP:503') || str_contains($o, 'HTTP:500')) && !str_contains($o, (string)getenv('TEST_DB_PASS')) && !str_contains($o, (string)getenv('RO_DIAG_PASS')), "NO arranca: $label");
}
$o = $request(['CERVESUR_MODE' => 'read_write', 'CERVESUR_PRODUCTION_DBS' => 'fixture_prod', 'DB_USER' => 'root', 'DB_PASS' => (string)getenv('TEST_DB_PASS'), 'DB_HOST' => 'db', 'DB_NAME' => 'fixture_prod']);
expectCase(!str_contains($o, 'ARRANCO') && str_contains($o, 'HTTP:503'), 'una base productiva en read_write: NO arranca (503) antes de conectar');
$verify = function ($dbNameEnv, $mode, $production = '') {
    $code = 'require ' . var_export(__DIR__ . '/../../backend/lib/mode.php', true) . '; putenv("DB_NAME=' . $dbNameEnv . '"); putenv("CERVESUR_PRODUCTION_DBS=' . $production . '"); $p = new PDO("mysql:host=db;dbname=fixture_prod", "root", getenv("TEST_DB_PASS")); app_mode_verify_connection($p, "' . $mode . '"); echo "PASO";';
    return sh(escapeshellarg(PHP_BINARY) . ' -r ' . escapeshellarg($code));
};
expectCase(!str_contains($verify('fixture_prod', 'read_write', 'fixture_prod'), 'PASO'), 'comprobación POSTERIOR a la conexión: aunque la previa se esquivase, la base realmente abierta sigue rechazada en read_write si es productiva');
expectCase(!str_contains($verify('otra_cosa', 'read_write'), 'PASO'), 'comprobación POSTERIOR: si la base abierta no coincide con DB_NAME, se rechaza');
expectCase(!str_contains($verify('fixture_prod', 'diagnostico'), 'PASO'), 'comprobación POSTERIOR: una sesión que NO está en solo lectura en un modo restringido se rechaza');
expectCase(str_contains($verify('fixture_prod', 'read_write'), 'PASO'), 'control: la misma comprobación deja pasar una base no productiva en read_write (entornos de prueba intactos)');

echo $failures === 0 ? "\nAll connection cases passed\n" : "\n$failures case(s) failed\n";
exit($failures === 0 ? 0 : 1);
