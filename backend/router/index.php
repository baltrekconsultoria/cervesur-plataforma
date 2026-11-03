<?php

/**
 * Router principal modular
 * Maneja todas las rutas de la API de manera organizada
 */

header('Content-Type: application/json');

// Los avisos/errores de PHP nunca se imprimen en la respuesta (revelan rutas y SQL); solo van al log interno.
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Incluir conexión a la base de datos
require_once __DIR__ . '/../lib/db.php';

$existing = session_status();
if ($existing === PHP_SESSION_NONE && !in_array(app_mode(), ['diagnostico', 'piloto_visual', 'piloto_usuarios', 'piloto_admin'], true)) { // el modo diagnóstico no tiene sesiones: ni cookie ni archivo
    session_start();
}

$method = $_SERVER['REQUEST_METHOD'];
$uri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// Piloto con usuarios reales (UX-07D.4B): las credenciales solo viajan cifradas. Toda petición sin TLS se rechaza ANTES de cualquier otra cosa.
if (in_array(app_mode(), ['piloto_usuarios', 'piloto_admin'], true) && !app_https_ok($_SERVER, getenv('CERVESUR_INSECURE_TESTS'))) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Este servicio exige una conexión HTTPS', 'code' => 'https_requerido']);
    exit;
}

// Puerta de rutas del modo de operación (UX-07D.3A, capa 1): en solo lectura/diagnóstico se rechaza TODA escritura y lo no permitido ANTES de cualquier
// controlador, aunque alguien manipule la API. En read_write (entornos de prueba) no hace nada.
if ($gate = app_mode_route_gate(app_mode(), $method, $uri)) {
    http_response_code($gate['status']);
    echo json_encode(['success' => false, 'message' => $gate['message'], 'code' => $gate['code']]);
    exit;
}

// Piloto con usuarios reales (solo en ese modo): su router propio atiende las rutas permitidas y nada más.
if (app_mode() === 'piloto_usuarios') {
    require_once __DIR__ . '/UsuariosRouter.php';
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
    exit;
}

// Piloto administrativo de solo lectura (UX-07E.B): su router propio atiende las rutas permitidas y nada más.
if (app_mode() === 'piloto_admin') {
    require_once __DIR__ . '/AdminRouter.php';
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
    exit;
}

// Piloto visual (solo en ese modo): el router propio atiende las rutas permitidas y nada más (incluye /api/ping).
if (app_mode() === 'piloto_visual') {
    require_once __DIR__ . '/PilotoRouter.php';
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
    exit;
}

// Endpoint de prueba
if ($method === 'GET' && $uri === '/api/ping') {
    echo json_encode(['pong' => true]);
    exit;
}

// Diagnósticos agregados (solo existen en el modo «diagnostico»; la puerta de rutas ya impidió todo lo demás)
if (app_mode() === 'diagnostico') {
    require_once __DIR__ . '/DiagnosticoRouter.php';
    // En este modo NINGUNA otra ruta existe: lo que no atendió el router de diagnóstico no llega a los demás routers (defensa en profundidad).
    http_response_code(404);
    echo json_encode(['error' => 'Not found']);
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

// Incluir rutas del historial de cambios administrativos
require_once __DIR__ . '/AuditoriaRouter.php';

// Incluir rutas de Power BI
require_once __DIR__ . '/PowerBIRouter.php';

// Incluir rutas de estadísticas
require_once __DIR__ . '/StatsRouter.php';

// Incluir rutas de documentación (wiki)
require_once __DIR__ . '/wiki.php';

// Si no se encontró ninguna ruta
http_response_code(404);
echo json_encode(['error' => 'Not found']);
