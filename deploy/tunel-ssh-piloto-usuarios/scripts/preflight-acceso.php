<?php
// PREFLIGHT B · ACCESO del piloto con usuarios reales (UX-07D.4B.1). SOLO LECTURA: no emite ninguna escritura ni muestra ningún dato ni cifra de las tablas.
// Uso (lo invoca preflight-acceso.sh / verificar.sh):  php preflight-acceso.php <directorio-del-backend>
// Entorno: DB_HOST, DB_NAME, STATE_DB_NAME, AUTH_DB_USER, CATALOGO_DB_USER, STATE_DB_USER y las claves CERVESUR_RO_AUTH_PASS, CERVESUR_RO_CATALOGO_PASS, CERVESUR_REACT_ESTADO_PASS
// (llegan por el entorno del proceso: nunca por argumentos ni por archivos nuevos). Salida: líneas OK/FALLO sin valores sensibles. Código: 0 = todo correcto · 1 = alguna falla.
if (PHP_SAPI !== 'cli') { exit(1); }
$backend = $argv[1] ?? '';
foreach (['mode.php', 'usuarios_catalogo.php', 'estado_piloto.php'] as $f) { if (!is_file("$backend/lib/$f")) { fwrite(STDERR, "FALLO  falta el backend del paquete ($f)\n"); exit(1); } }
require "$backend/lib/mode.php";
require "$backend/lib/usuarios_catalogo.php";
require "$backend/lib/estado_piloto.php";

$host = getenv('DB_HOST') ?: '127.0.0.1'; $db = getenv('DB_NAME') ?: ''; $stateDb = getenv('STATE_DB_NAME') ?: '';
$cuentas = [
    'auth' => [getenv('AUTH_DB_USER') ?: '', (string)getenv('CERVESUR_RO_AUTH_PASS')],
    'catalogo' => [getenv('CATALOGO_DB_USER') ?: '', (string)getenv('CERVESUR_RO_CATALOGO_PASS')],
    'estado' => [getenv('STATE_DB_USER') ?: '', (string)getenv('CERVESUR_REACT_ESTADO_PASS')],
];
$errores = 0;
function resultado($ok, $texto) { global $errores; if ($ok) { echo "OK     $texto\n"; } else { fwrite(STDERR, "FALLO  $texto\n"); $errores++; } return $ok; }

// Columnas exactas (deben coincidir con database/schema/piloto-usuarios/01-cuentas-lectura.sql; una prueba estática del repositorio lo garantiza).
$esperadasAuth = ['user' => ['email', 'first_name', 'id', 'is_active', 'last_name', 'password', 'role', 'session_version', 'username']];
$esperadasCatalogo = [
    'empresa' => ['activo', 'ciudad', 'id', 'nombre', 'ruc'], 'empresa_reporte' => ['activo', 'empresa_id', 'id', 'reporte_id'],
    'empresa_usuario' => ['activo', 'empresa_id', 'user_id'], 'reporte' => ['activo', 'descripcion', 'enlace', 'id', 'nombre', 'reporte_id'],
    'user_empresa_reporte' => ['activo', 'empresa_reporte_id', 'user_id'],
];
$tablasEstado = ['auditoria', 'intentos_login', 'sesiones', 'usuarios_piloto'];

if ($db === '' || $stateDb === '' || !preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $db) || !preg_match('/^[A-Za-z0-9_$-]{1,64}$/', $stateDb)) { fwrite(STDERR, "FALLO  configuración incompleta o no válida (DB_NAME, STATE_DB_NAME)\n"); exit(1); }
foreach ($cuentas as $k => [$u, $p]) { if ($u === '' || $p === '') { fwrite(STDERR, "FALLO  falta el usuario o la clave de la cuenta «{$k}»\n"); exit(1); } }
resultado(count(array_unique(array_map(fn ($c) => strtolower($c[0]), $cuentas))) === 3 && $stateDb !== $db, 'las tres cuentas son distintas y la base de estado es distinta de la base de lectura');

$roOptions = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false, PDO::MYSQL_ATTR_INIT_COMMAND => 'SET SESSION TRANSACTION READ ONLY'];
$rwOptions = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];

/** @return array{PDO,string}|null */
function conectar($etiqueta, $dsn, $user, $pass, array $options) {
    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
        resultado(true, "la cuenta «{$etiqueta}» se autentica por TCP local con la clave proporcionada");
        return [$pdo, (string)$pdo->query('SELECT CURRENT_USER()')->fetchColumn()];
    } catch (PDOException $e) {
        resultado(false, "la cuenta «{$etiqueta}» NO se autentica (código MySQL " . (int)($e->errorInfo[1] ?? 0) . ')');
        return null;
    }
}
function yo($current) { $u = explode('@', $current)[0]; return "'" . str_replace("'", "''", $u) . "'@'" . str_replace("'", "''", substr($current, strlen($u) + 1)) . "'"; }

/** Auditoría común de una cuenta de LECTURA: identidad, sesión de solo lectura, solo SELECT por columna, columnas exactas, sin otras bases. */
function auditar_lectura(PDO $pdo, $current, $etiqueta, $user, $db, array $esperadas, array $sensibles) {
    resultado(strpos($current, $user . '@') === 0, "«{$etiqueta}»: la identidad efectiva es la cuenta del piloto (no una cuenta anónima ni homónima)");
    resultado($pdo->query('SELECT DATABASE()')->fetchColumn() === $db, "«{$etiqueta}»: la base conectada es la base de lectura esperada");
    resultado((int)$pdo->query('SELECT @@session.transaction_read_only')->fetchColumn() === 1, "«{$etiqueta}»: la sesión MySQL está realmente en solo lectura (fijada al conectar)");
    $grants = $pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN, 0);
    $viol = app_grants_violations($grants);
    resultado($viol === [], "«{$etiqueta}»: SHOW GRANTS sin privilegio de escritura, administración, roles ni GRANT OPTION" . ($viol ? ' (' . implode(', ', $viol) . ')' : ''));
    $q = yo($current);
    resultado(array_filter($pdo->query('SELECT PRIVILEGE_TYPE, IS_GRANTABLE FROM information_schema.USER_PRIVILEGES WHERE GRANTEE = ' . $pdo->quote($q))->fetchAll(), fn ($r) => $r['PRIVILEGE_TYPE'] !== 'USAGE' || $r['IS_GRANTABLE'] !== 'NO') === [], "«{$etiqueta}»: sin privilegios globales (solo USAGE)");
    resultado((int)$pdo->query('SELECT COUNT(*) FROM information_schema.SCHEMA_PRIVILEGES WHERE GRANTEE = ' . $pdo->quote($q))->fetchColumn() === 0, "«{$etiqueta}»: sin privilegios a nivel de base de datos");
    resultado((int)$pdo->query('SELECT COUNT(*) FROM information_schema.TABLE_PRIVILEGES WHERE GRANTEE = ' . $pdo->quote($q))->fetchColumn() === 0, "«{$etiqueta}»: sin privilegios a nivel de tabla completa");
    $concedidas = []; $raras = 0;
    foreach ($pdo->query('SELECT TABLE_SCHEMA, TABLE_NAME, COLUMN_NAME, PRIVILEGE_TYPE, IS_GRANTABLE FROM information_schema.COLUMN_PRIVILEGES WHERE GRANTEE = ' . $pdo->quote($q))->fetchAll() as $f) {
        if ($f['TABLE_SCHEMA'] !== $db || $f['PRIVILEGE_TYPE'] !== 'SELECT' || $f['IS_GRANTABLE'] !== 'NO') { $raras++; }
        $concedidas[$f['TABLE_NAME']][] = $f['COLUMN_NAME'];
    }
    ksort($concedidas); foreach ($concedidas as &$c) { sort($c); } unset($c);
    $total = array_sum(array_map('count', $esperadas)); $faltan = []; $sobran = [];
    foreach ($esperadas as $t => $cols) { foreach ($cols as $c) { if (!in_array($c, $concedidas[$t] ?? [], true)) { $faltan[] = "$t.$c"; } } }
    foreach ($concedidas as $t => $cols) { foreach ($cols as $c) { if (!in_array($c, $esperadas[$t] ?? [], true)) { $sobran[] = "$t.$c"; } } }
    resultado($faltan === [], "«{$etiqueta}»: están concedidas las $total columnas autorizadas" . ($faltan ? ' · FALTAN: ' . implode(', ', $faltan) : ''));
    resultado($sobran === [] && $raras === 0, "«{$etiqueta}»: no hay ninguna columna ni privilegio adicional" . ($sobran ? ' · SOBRAN: ' . implode(', ', $sobran) : ''));
    // «WHERE 1 = 0» no devuelve filas: se prueba el permiso, nunca se lee un dato.
    $leidas = []; $denegadas = 0;
    foreach ($sensibles as $col) {
        [$t, $c] = explode('.', $col);
        try { $pdo->query("SELECT `$c` FROM `$t` WHERE 1 = 0"); $leidas[] = $col; } catch (PDOException $e) { if (in_array((int)($e->errorInfo[1] ?? 0), [1142, 1143], true)) { $denegadas++; } else { $leidas[] = $col . ' (error inesperado)'; } }
    }
    resultado($leidas === [], "«{$etiqueta}»: lo que NO debe leer está DENEGADO ($denegadas/" . count($sensibles) . ')' . ($leidas ? ' · ACCESIBLES: ' . implode(', ', $leidas) : ''));
    $otras = array_values(array_diff(array_column($pdo->query('SHOW DATABASES')->fetchAll(), 'Database'), ['information_schema', 'performance_schema', $db]));
    resultado($otras === [], "«{$etiqueta}»: no ve ninguna otra base de datos" . ($otras ? ' · VISIBLES: ' . implode(', ', $otras) : ''));
}

/** Ejecuta cada consulta del catálogo con la cuenta; el primer «?» es el id de usuario. No muestra ninguna cifra. */
function probar_catalogo(PDO $guarded, array $consultas) {
    $falla = [];
    foreach ($consultas as $nombre => $sql) {
        try {
            $st = $guarded->prepare($sql); $n = substr_count($sql, '?'); $vals = [1, 1, 0];
            for ($i = 0; $i < $n; $i++) { $st->bindValue($i + 1, $vals[$i] ?? 1, PDO::PARAM_INT); }
            $st->execute(); $st->fetchAll();
        } catch (Throwable $e) { $falla[] = $nombre; }
    }
    return $falla;
}

// ------------------------------------------------------------------ cuenta de AUTENTICACIÓN (única que lee el hash)
[$uAuth, $pAuth] = $cuentas['auth'];
if ($c = conectar('auth', "mysql:host=$host;dbname=$db;charset=utf8mb4", $uAuth, $pAuth, $roOptions)) {
    auditar_lectura($c[0], $c[1], 'auth', $uAuth, $db, $esperadasAuth, ['reporte.enlace', 'reporte.id', 'empresa.powerbi_tenant', 'empresa.powerbi_clientsecret', 'empresa.email', 'empresa_usuario.user_id', 'user_empresa_reporte.user_id']);
    $g = new GuardedPDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $uAuth, $pAuth, $roOptions);
    $g->exec('SET SESSION TRANSACTION READ ONLY');
    $g->useCatalog(usuarios_auth_sentencias());
    $falla = probar_catalogo($g, usuarios_auth_consultas());
    resultado($falla === [], 'auth: las consultas de autenticación funcionan con esta cuenta (' . (count(usuarios_auth_consultas()) - count($falla)) . '/' . count(usuarios_auth_consultas()) . ')' . ($falla ? ' · FALLAN: ' . implode(', ', $falla) : ''));
}

// ------------------------------------------------------------------ cuenta de CATÁLOGO (sin tabla user, sin credenciales de Power BI)
[$uCat, $pCat] = $cuentas['catalogo'];
if ($c = conectar('catalogo', "mysql:host=$host;dbname=$db;charset=utf8mb4", $uCat, $pCat, $roOptions)) {
    auditar_lectura($c[0], $c[1], 'catalogo', $uCat, $db, $esperadasCatalogo, ['user.password', 'user.id', 'user.email', 'user.username', 'user.role', 'user.session_version', 'empresa.powerbi_tenant', 'empresa.powerbi_client', 'empresa.powerbi_clientsecret', 'empresa.email', 'empresa.telefono', 'empresa.razon_social', 'reporte.grupo_id', 'reporte.created_by', 'empresa_reporte.notas']);
    $g = new GuardedPDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $uCat, $pCat, $roOptions);
    $g->exec('SET SESSION TRANSACTION READ ONLY');
    $g->useCatalog(usuarios_catalogo_sentencias());
    $consultas = usuarios_catalogo_consultas(); $falla = probar_catalogo($g, $consultas);
    resultado($falla === [], 'catalogo: el catálogo cerrado funciona con esta cuenta (' . (count($consultas) - count($falla)) . '/' . count($consultas) . ' consultas)' . ($falla ? ' · FALLAN: ' . implode(', ', $falla) : ''));
    foreach (usuarios_catalogo_controles() as $nombre => $sql) { try { $g->query($sql)->fetchAll(); } catch (Throwable $e) { resultado(false, "catalogo: el control «$nombre» falla"); } }
}

// ------------------------------------------------------------------ cuenta de ESTADO: escribe, pero SOLO en su base (aquí únicamente se lee)
[$uEst, $pEst] = $cuentas['estado'];
if ($c = conectar('estado', "mysql:host=$host;dbname=$stateDb;charset=utf8mb4", $uEst, $pEst, $rwOptions)) {
    [$pdo, $current] = $c;
    resultado(strpos($current, $uEst . '@') === 0, 'estado: la identidad efectiva es la cuenta del piloto');
    resultado($pdo->query('SELECT DATABASE()')->fetchColumn() === $stateDb, 'estado: la base conectada es la base de estado esperada');
    $viol = estado_grants_violations($pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN, 0), $stateDb);
    resultado($viol === [], 'estado: solo SELECT/INSERT/UPDATE/DELETE en su base; nada global, nada en la base de lectura, sin DDL ni GRANT OPTION' . ($viol ? ' (' . implode(', ', $viol) . ')' : ''));
    $tablas = array_column($pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ' . $pdo->quote($stateDb) . ' ORDER BY TABLE_NAME')->fetchAll(), 'TABLE_NAME');
    resultado($tablas === $tablasEstado, 'estado: la base de estado tiene exactamente las tablas esperadas (' . implode(', ', $tablasEstado) . ')');
    $negado = 0;
    foreach (["SELECT 1 FROM `$db`.`reporte` LIMIT 1", "SELECT 1 FROM `$db`.`user` LIMIT 1"] as $sql) {
        try { $pdo->query($sql); } catch (PDOException $e) { if (in_array((int)($e->errorInfo[1] ?? 0), [1142, 1143, 1044], true)) { $negado++; } }
    }
    resultado($negado === 2, 'estado: NO puede leer la base de lectura (ni reporte ni user)');
    $otras = array_values(array_diff(array_column($pdo->query('SHOW DATABASES')->fetchAll(), 'Database'), ['information_schema', 'performance_schema', $stateDb]));
    resultado($otras === [], 'estado: no ve ninguna otra base de datos' . ($otras ? ' · VISIBLES: ' . implode(', ', $otras) : ''));
    $lectura = true;
    try { foreach ($tablasEstado as $t) { $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn(); } } catch (PDOException $e) { $lectura = false; }
    resultado($lectura, 'estado: puede leer sus cuatro tablas');
    // Nada en el estado debe contener secretos: ninguna columna con nombre de contraseña, enlace o IP en claro.
    $cols = array_column($pdo->query('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ' . $pdo->quote($stateDb))->fetchAll(), 'COLUMN_NAME');
    resultado(array_filter($cols, fn ($n) => preg_match('/pass|clave$|enlace|powerbi|secret|^ip$|token$/i', $n) && $n !== 'huella_clave') === [], 'estado: ninguna columna guarda contraseñas, enlaces ni IP en claro (solo huellas HMAC y el SHA-256 del token)');
}

exit($errores === 0 ? 0 : 1);
