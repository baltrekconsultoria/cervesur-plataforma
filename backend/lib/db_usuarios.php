<?php
/**
 * Conexiones adicionales del modo «piloto_usuarios» (UX-07D.4B). Se incluye desde db.php, que ya abrió la conexión de CATÁLOGO ($pdo).
 *   $pdoAuth    cuenta cervesur_ro_auth: solo lectura, solo la tabla user (9 columnas), catálogo cerrado de 2 consultas (+ controles).
 *   $pdoEstado  cuenta cervesur_react_estado: lectura/escritura SOLO en la base de estado del piloto, catálogo cerrado.
 * Cada conexión se audita al arrancar (privilegios) y, si algo no cuadra, el servicio rehúsa servir (503) sin revelar detalles.
 * Variables: AUTH_DB_USER, AUTH_DB_PASS, STATE_DB_NAME, STATE_DB_USER, STATE_DB_PASS (mismo servidor, DB_HOST). La clave de ninguna cuenta se muestra jamás.
 */
require_once __DIR__ . '/usuarios_catalogo.php';
require_once __DIR__ . '/estado_piloto.php';

$authUser = (string)getenv('AUTH_DB_USER'); $authPass = (string)getenv('AUTH_DB_PASS');
$stateDb = (string)getenv('STATE_DB_NAME'); $stateUser = (string)getenv('STATE_DB_USER'); $statePass = (string)getenv('STATE_DB_PASS');
if ($authUser === '' || $authPass === '' || $stateUser === '' || $statePass === '') {
    app_mode_refuse(['Faltan las credenciales de las cuentas de autenticación o de estado']);
}
if (strtolower($authUser) === strtolower((string)$user) || strtolower($stateUser) === strtolower((string)$user) || strtolower($authUser) === strtolower($stateUser)) {
    app_mode_refuse(['Las tres cuentas (catálogo, autenticación y estado) deben ser distintas']);
}

try {
    // ---- autenticación: solo lectura, solo la tabla user
    $authOptions = $options;
    $authOptions[PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET SESSION TRANSACTION READ ONLY';
    $pdoAuth = new GuardedPDO("mysql:host=$host;dbname=$db;charset=$charset", $authUser, $authPass, $authOptions);
    $pdoAuth->exec('SET SESSION TRANSACTION READ ONLY');
    app_mode_verify_connection($pdoAuth, $appMode);
    app_mode_assert_readonly_account($pdoAuth);
    if ($appMode === 'piloto_admin' || $appMode === 'admin_escritura') { require_once __DIR__ . '/admin_catalogo.php'; $pdoAuth->useCatalog(admin_auth_sentencias()); } else { $pdoAuth->useCatalog(usuarios_auth_sentencias()); }

    // ---- estado propio: escribe, pero solo en su base y solo con su catálogo
    $stateOptions = $options;
    unset($stateOptions[PDO::MYSQL_ATTR_INIT_COMMAND]); // db.php fijó «solo lectura» en $options para las conexiones de la base de Flutter; la de estado SÍ escribe (solo en su base)
    $pdoEstado = new EstadoPDO("mysql:host=$host;dbname=$stateDb;charset=$charset", $stateUser, $statePass, $stateOptions);
    $actual = strtolower((string)$pdoEstado->query('SELECT DATABASE()')->fetchColumn());
    if ($actual !== strtolower($stateDb) || in_array($actual, app_production_databases(['CERVESUR_PRODUCTION_DBS' => getenv('CERVESUR_PRODUCTION_DBS')]), true)) {
        app_mode_refuse(['La base de estado abierta no es la esperada']);
    }
    $violaciones = estado_grants_violations($pdoEstado->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN, 0), $stateDb);
    if ($violaciones) {
        app_mode_refuse(['La cuenta de estado tiene privilegios fuera de su base: ' . implode(', ', $violaciones)]);
    }
    $pdoEstado->fijarCatalogo(estado_sentencias(PHP_SAPI === 'cli'));
} catch (\PDOException $e) {
    error_log('[cervesur] Error al abrir las conexiones del piloto con usuarios: ' . (int)($e->errorInfo[1] ?? 0));
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Error de conexión a la base de datos']);
    exit;
}
