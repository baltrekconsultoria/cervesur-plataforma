<?php

/**
 * Router para estadísticas del sistema
 * Maneja endpoints relacionados con métricas y totales
 */

require_once __DIR__ . '/../lib/auth_middleware.php';
require_once __DIR__ . '/../controllers/StatsController.php';

// Endpoint protegido: obtener estadísticas generales
if ($method === 'GET' && $uri === '/api/stats') {
    // Verificar autenticación
    $jwt_secret = get_jwt_secret_or_fail('StatsRouter');
    $user = require_auth($jwt_secret);

    try {
        // Instanciar controlador y obtener estadísticas
        $statsController = new StatsController($pdo);
        $result = $statsController->getStats();

        // Responder
        if (!$result['success']) {
            http_response_code(500);
        }
        echo json_encode($result);
    } catch (Exception $e) {
        error_log('Error en ruta /api/stats: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error interno del servidor'
        ]);
    }
    exit;
}
