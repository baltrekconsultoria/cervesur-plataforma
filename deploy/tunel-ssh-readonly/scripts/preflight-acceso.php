<?php
// PREFLIGHT B · ACCESO a la base de lectura con la cuenta de diagnóstico (UX-07D.3B). SOLO LECTURA: no emite ninguna escritura ni muestra ningún dato.
// Uso (lo invoca preflight-acceso.sh / verificar.sh):  CERVESUR_RO_DIAG_PASS=… php preflight-acceso.php <directorio-del-backend>
// Entorno: DB_HOST, DB_NAME, DB_USER, CERVESUR_RO_DIAG_PASS (la clave llega por el entorno del proceso: nunca por argumentos ni por archivos nuevos).
// Salida: líneas OK/FALLO sin valores sensibles ni cifras de las tablas. Código: 0 = todo correcto · 1 = alguna comprobación falla.
if (PHP_SAPI !== 'cli') { exit(1); }
$backend = $argv[1] ?? '';
if (!is_file("$backend/lib/mode.php") || !is_file("$backend/lib/diagnostico_catalogo.php")) { fwrite(STDERR, "FALLO  falta el backend del paquete\n"); exit(1); }
putenv('CERVESUR_MODE=diagnostico'); // mode.php no se usa aquí para arrancar, solo sus funciones puras y el catálogo
require "$backend/lib/mode.php";
require "$backend/lib/diagnostico_catalogo.php";

$host = getenv('DB_HOST') ?: '127.0.0.1'; $db = getenv('DB_NAME') ?: ''; $user = getenv('DB_USER') ?: ''; $pass = (string)getenv('CERVESUR_RO_DIAG_PASS');
$errores = 0;
function resultado($ok, $texto) { global $errores; if ($ok) { echo "OK     $texto\n"; } else { fwrite(STDERR, "FALLO  $texto\n"); $errores++; } return $ok; }

// Las 18 columnas autorizadas (deben coincidir con admin-mysql/01-cuenta-diagnostico.sql; una prueba estática del repositorio lo garantiza).
$esperadas = [
    'empresa' => ['activo', 'id'], 'empresa_reporte' => ['activo', 'empresa_id', 'id', 'reporte_id'], 'empresa_usuario' => ['activo', 'empresa_id', 'user_id'],
    'reporte' => ['activo', 'id'], 'user' => ['id', 'is_active', 'role', 'session_version'], 'user_empresa_reporte' => ['activo', 'empresa_reporte_id', 'user_id'],
];

if ($db === '' || $user === '' || $pass === '' || !preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $db)) { fwrite(STDERR, "FALLO  configuración incompleta o no válida (DB_NAME, DB_USER, clave)\n"); exit(1); }

try {
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION TRANSACTION READ ONLY'];
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, $options); // conexión de INTROSPECCIÓN (solo lecturas); el catálogo se prueba más abajo con GuardedPDO
    resultado(true, 'la cuenta se autentica por TCP local con la clave proporcionada');
} catch (PDOException $e) {
    resultado(false, 'la cuenta NO se autentica (código MySQL ' . (int)($e->errorInfo[1] ?? 0) . ')');
    exit(1);
}

// Identidad efectiva: debe ser la cuenta esperada y no otra que la haya suplantado (p. ej. una cuenta anónima más específica).
$current = (string)$pdo->query('SELECT CURRENT_USER()')->fetchColumn();
resultado(strpos($current, $user . '@') === 0, 'la identidad efectiva es la cuenta de diagnóstico (no una cuenta anónima ni homónima)');
resultado($pdo->query('SELECT DATABASE()')->fetchColumn() === $db, 'la base conectada es la base de lectura esperada');
resultado((int)$pdo->query('SELECT @@session.transaction_read_only')->fetchColumn() === 1, 'la sesión MySQL está realmente en solo lectura (fijada al conectar)');

// Privilegios efectivos: solo SELECT/USAGE, sin GRANT OPTION ni roles (los roles no se pueden auditar y se rechazan).
$grants = $pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN, 0);
$violaciones = app_grants_violations($grants);
resultado($violaciones === [], 'SHOW GRANTS: ningún privilegio de escritura, administración, roles ni GRANT OPTION' . ($violaciones ? ' (' . implode(', ', $violaciones) . ')' : ''));
$yo = "'" . str_replace("'", "''", explode('@', $current)[0]) . "'@'" . str_replace("'", "''", substr($current, strlen(explode('@', $current)[0]) + 1)) . "'";
$globales = $pdo->query("SELECT PRIVILEGE_TYPE, IS_GRANTABLE FROM information_schema.USER_PRIVILEGES WHERE GRANTEE = " . $pdo->quote($yo))->fetchAll();
resultado(array_filter($globales, fn ($r) => $r['PRIVILEGE_TYPE'] !== 'USAGE' || $r['IS_GRANTABLE'] !== 'NO') === [], 'sin privilegios globales (solo USAGE)');
resultado((int)$pdo->query("SELECT COUNT(*) FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE = " . $pdo->quote($yo))->fetchColumn() === 0, 'sin privilegios a nivel de base de datos');
resultado((int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE = " . $pdo->quote($yo))->fetchColumn() === 0, 'sin privilegios a nivel de tabla completa');

// Las 18 columnas: exactamente esas, solo SELECT, nada concedible, y solo en la base de lectura.
$filas = $pdo->query("SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, PRIVILEGE_TYPE, IS_GRANTABLE FROM information_schema.COLUMN_PRIVILEGES WHERE GRANTEE = " . $pdo->quote($yo))->fetchAll();
$concedidas = []; $raras = 0;
foreach ($filas as $f) { if ($f['TABLE_SCHEMA'] !== $db || $f['PRIVILEGE_TYPE'] !== 'SELECT' || $f['IS_GRANTABLE'] !== 'NO') { $raras++; } $concedidas[$f['TABLE_NAME']][] = $f['COLUMN_NAME']; }
ksort($concedidas); foreach ($concedidas as &$c) { sort($c); } unset($c);
$faltan = []; $sobran = [];
foreach ($esperadas as $t => $cols) { foreach ($cols as $c) { if (!in_array($c, $concedidas[$t] ?? [], true)) { $faltan[] = "$t.$c"; } } }
foreach ($concedidas as $t => $cols) { foreach ($cols as $c) { if (!in_array($c, $esperadas[$t] ?? [], true)) { $sobran[] = "$t.$c"; } } }
resultado($faltan === [], 'están concedidas las 18 columnas autorizadas' . ($faltan ? ' · FALTAN: ' . implode(', ', $faltan) : ''));
resultado($sobran === [] && $raras === 0, 'no hay ninguna columna ni privilegio adicional' . ($sobran ? ' · SOBRAN: ' . implode(', ', $sobran) : ''));

// Las columnas sensibles deben estar denegadas. «WHERE 1 = 0» no devuelve filas: se prueba el permiso, nunca se lee un dato.
$denegadas = 0; $sensibles = ['user.password', 'user.email', 'reporte.enlace', 'reporte.reporte_id', 'reporte.grupo_id', 'empresa.powerbi_tenant', 'empresa.powerbi_client', 'empresa.powerbi_clientsecret'];
$leidas = [];
foreach ($sensibles as $col) {
    [$t, $c] = explode('.', $col);
    try { $pdo->query("SELECT `$c` FROM `$t` WHERE 1 = 0"); $leidas[] = $col; } catch (ReadOnlyViolation $e) { $denegadas++; } catch (PDOException $e) { if (in_array((int)($e->errorInfo[1] ?? 0), [1142, 1143], true)) { $denegadas++; } else { $leidas[] = $col . ' (error inesperado)'; } }
}
resultado($leidas === [], 'las columnas sensibles (password, correo, enlaces, grupo, credenciales de Power BI) están DENEGADAS (' . $denegadas . '/' . count($sensibles) . ')' . ($leidas ? ' · ACCESIBLES: ' . implode(', ', $leidas) : ''));

// Otras bases: la cuenta no debe ver ninguna salvo information_schema y la base de lectura.
$bases = array_column($pdo->query('SHOW DATABASES')->fetchAll(), 'Database');
$otras = array_values(array_diff($bases, ['information_schema', 'performance_schema', $db])); // information_schema y performance_schema son visibles para toda cuenta; su contenido no se concede
resultado($otras === [], 'la cuenta no ve ninguna otra base de datos' . ($otras ? ' · VISIBLES: ' . implode(', ', $otras) : ''));

// Catálogo del diagnóstico: cada consulta es ejecutable con esta cuenta. No se muestra ninguna cifra.
$catalogo = new GuardedPDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, $options);
$catalogo->exec('SET SESSION TRANSACTION READ ONLY');
$catalogo->useCatalog(diagnostico_catalogo_sentencias());
$medidas = diagnostico_catalogo_medidas(); $falla = [];
foreach ($medidas as $nombre => $m) { try { $catalogo->query($m['sql'])->fetchColumn(); } catch (Throwable $e) { $falla[] = $nombre; } }
resultado($falla === [], 'el catálogo de diagnóstico funciona con esta cuenta (' . (count($medidas) - count($falla)) . '/' . count($medidas) . ' medidas)' . ($falla ? ' · FALLAN: ' . implode(', ', $falla) : ''));
foreach (diagnostico_catalogo_controles() as $nombre => $sql) { try { $catalogo->query($sql)->fetchAll(); } catch (Throwable $e) { resultado(false, "el control «$nombre» falla"); } }

exit($errores === 0 ? 0 : 1);
