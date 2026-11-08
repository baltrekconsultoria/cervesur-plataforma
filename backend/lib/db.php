<?php
/**
 * Archivo de conexión a la base de datos MySQL
 * Usa variables de entorno para mayor seguridad
 */

// Cargar variables de entorno
require_once __DIR__ . '/env.php';
// Mensajes de error públicos (el detalle técnico solo va al log interno)
require_once __DIR__ . '/errors.php';
// Modo de operación (UX-07D.3A): se evalúa ANTES de abrir cualquier conexión; una configuración inválida o incompleta rechaza (503) sin tocar la base.
require_once __DIR__ . '/mode.php';
$appMode = app_mode_boot();

// Obtener credenciales desde variables de entorno
$host = getenv('DB_HOST');
$db   = getenv('DB_NAME');
$user = getenv('DB_USER');
$pass = getenv('DB_PASS');
$charset = getenv('DB_CHARSET') ?: 'utf8mb4';

if (!$host || !$db || !$user || $pass === false || $pass === '') {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Configuración de base de datos incompleta']);
    exit;
}

// Crear DSN (Data Source Name) para PDO
$dsn = "mysql:host=$host;dbname=$db;charset=$charset";

// Configurar opciones de PDO
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,  // Lanzar excepciones en errores
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Devolver arrays asociativos
    PDO::ATTR_EMULATE_PREPARES   => false,                  // Usar prepared statements nativos
];

// Intentar conectar a la base de datos
try {
    if ($appMode === 'read_write') {
        $pdo = new PDO($dsn, $user, $pass, $options);
    } else {
        // Solo lectura: guardia de SQL (capa 2) + transacción de sesión de solo lectura (capa 3) + verificación de los privilegios de la cuenta (capa 4).
        // MYSQL_ATTR_INIT_COMMAND hace que MySQL fije el solo lectura en CADA conexión nueva, antes de cualquier consulta, y no solo a través del código.
        $options[PDO::MYSQL_ATTR_INIT_COMMAND] = 'SET SESSION TRANSACTION READ ONLY';
        $pdo = new GuardedPDO($dsn, $user, $pass, $options);
        $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    }
    // Con la conexión ya abierta: base realmente conectada, productiva solo en solo lectura, y sesión realmente en solo lectura.
    app_mode_verify_connection($pdo, $appMode);
    if ($appMode !== 'read_write') {
        app_mode_assert_readonly_account($pdo);
    }
    if ($appMode === 'diagnostico') {
        // Catálogo cerrado: desde aquí la conexión solo ejecuta las sentencias de diagnostico_catalogo.php.
        require_once __DIR__ . '/diagnostico_catalogo.php';
        $pdo->useCatalog(diagnostico_catalogo_sentencias());
    } elseif ($appMode === 'piloto_visual') {
        // Piloto visual (UX-07D.4): catálogo cerrado de lib/piloto_catalogo.php (empresas e informes; nunca la tabla user).
        require_once __DIR__ . '/piloto_catalogo.php';
        $pdo->useCatalog(piloto_catalogo_sentencias());
    } elseif ($appMode === 'piloto_admin') {
        // Consulta administrativa de solo lectura (UX-07E.B): esta conexión (cuenta de CATÁLOGO) ve empresas, informes y relaciones; nunca la tabla user.
        require_once __DIR__ . '/admin_catalogo.php';
        $pdo->useCatalog(admin_catalogo_sentencias());
    } elseif ($appMode === 'piloto_usuarios') {
        // Piloto con usuarios reales (UX-07D.4B): esta conexión (cuenta de CATÁLOGO) solo ve empresas, informes y relaciones; nunca la tabla user.
        require_once __DIR__ . '/usuarios_catalogo.php';
        $pdo->useCatalog(usuarios_catalogo_sentencias());
    }
} catch (\PDOException $e) {
    // En caso de error, devolver JSON con error (para APIs)
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error de conexión a la base de datos'
    ]);
    exit;
}

// Piloto con usuarios reales: conexiones de autenticacion (tabla user) y de estado propio (UX-07D.4B). Solo en ese modo.
if ($appMode === 'piloto_usuarios' || $appMode === 'piloto_admin') {
    require_once __DIR__ . '/db_usuarios.php';
}
