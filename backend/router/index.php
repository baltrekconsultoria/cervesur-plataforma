<?php

/**
 * Router principal modular
 * Maneja todas las rutas de la API de manera organizada
 */

header('Content-Type: application/json');

// Incluir conexión a la base de datos
require_once __DIR__ . '/../lib/db.php';

$existing = session_status();
if ($existing === PHP_SESSION_NONE) {
    session_start();
}

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Endpoint de prueba
if ($method === 'GET' && $uri === '/api/ping') {
    echo json_encode(['pong' => true]);
    exit;
}

// Incluir rutas de autenticación
require_once __DIR__ . '/auth.php';

// Incluir rutas de usuarios
require_once __DIR__ . '/UserRouter.php';

// Incluir rutas de reportes
require_once __DIR__ . '/ReporteRouter.php';

// Incluir rutas de empresas
require_once __DIR__ . '/EmpresaRouter.php';

// Incluir rutas de empresa-usuario (asignaciones de usuarios a empresas)
require_once __DIR__ . '/EmpresaUsuarioRouter.php';

// Incluir rutas de empresa-reporte-usuario (asignaciones de reportes a usuarios)
require_once __DIR__ . '/EmpresaReporteUsuarioRouter.php';

// Incluir rutas de asignaciones
require_once __DIR__ . '/AsignacionRouter.php';

// Incluir rutas de Power BI
require_once __DIR__ . '/PowerBIRouter.php';

// Incluir rutas de estadísticas
require_once __DIR__ . '/StatsRouter.php';

// Incluir rutas de documentación (wiki)
require_once __DIR__ . '/wiki.php';

// Si no se encontró ninguna ruta
http_response_code(404);
echo json_encode(['error' => 'Not found']);
