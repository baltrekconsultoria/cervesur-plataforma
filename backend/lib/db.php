<?php
/**
 * Archivo de conexión a la base de datos MySQL
 * Usa variables de entorno para mayor seguridad
 */

// Cargar variables de entorno
require_once __DIR__ . '/env.php';

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
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    // En caso de error, devolver JSON con error (para APIs)
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => 'Error de conexión a la base de datos'
    ]);
    exit;
}
