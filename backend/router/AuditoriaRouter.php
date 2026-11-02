<?php

/**
 * Router del historial de cambios administrativos (UX-08A).
 *
 * GET /api/auditoria?entity=&entity_id=&correlation_id=&page=&per_page=
 * Solo administradores (el rol se lee de MySQL en cada petición, ver require_auth).
 */
require_once __DIR__ . '/../lib/pagination.php';
require_once __DIR__ . '/../controllers/AuditoriaController.php';
require_once __DIR__ . '/../lib/auth_middleware.php';

if ($method === 'GET' && $uri === '/api/auditoria') {
    $user_data = require_auth(get_jwt_secret_or_fail('AuditoriaRouter'));
    if (strtolower((string)$user_data['role']) !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
        exit;
    }
    $pagination = require_pagination(20);
    [$status, $body] = (new AuditoriaController($pdo))->history($_GET, $pagination);
    http_response_code($status);
    echo json_encode($body);
    exit;
}
