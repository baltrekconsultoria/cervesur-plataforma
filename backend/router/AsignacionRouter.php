<?php

/**
 * Router para asignaciones
 * Define todas las rutas relacionadas con asignaciones de reportes a empresas
 */

// Incluir dependencias necesarias
require_once __DIR__ . '/../lib/views.php';
require_once __DIR__ . '/../lib/pagination.php';
require_once __DIR__ . '/../lib/status_filter.php';
require_once __DIR__ . '/../lib/write_support.php';
require_once __DIR__ . '/../controllers/AsignacionController.php';
require_once __DIR__ . '/../lib/auth_middleware.php';

// Obtener el secreto JWT desde variables de entorno
$jwt_secret = get_jwt_secret_or_fail('AsignacionRouter');

// Instanciar el controlador
$controller = new AsignacionController($pdo);

// ========================================
// RUTAS PARA ASIGNACIONES
// ========================================

// GET /api/asignacion - Obtener todas las asignaciones
if ($method === 'GET' && $uri === '/api/asignacion') {
    $user = require_auth($jwt_secret);
    $view = require_view();
    $result = $controller->getAll($user['user_id'], $user['role'], require_pagination(), require_status_filter($user['role']));

    http_response_code($result['success'] ? 200 : 400);
    echo json_encode(apply_view($result, $view, 'asignacion'));
    exit;
}

// GET /api/asignacion/{id} - Obtener asignación por ID
if ($method === 'GET' && preg_match('/^\/api\/asignacion\/(\d+)$/', $uri, $matches)) {
    $user = require_auth($jwt_secret);
    $id = (int)$matches[1];
    $view = require_view();
    $result = $controller->getById($id, $user['user_id'], $user['role']);

    http_response_code($result['success'] ? 200 : 404);
    echo json_encode(apply_view($result, $view, 'asignacion'));
    exit;
}

// GET /api/asignacion/empresa/{empresa_id} - Obtener asignaciones por empresa
if ($method === 'GET' && preg_match('/^\/api\/asignacion\/empresa\/(\d+)$/', $uri, $matches)) {
    $user = require_auth($jwt_secret);
    $empresa_id = (int)$matches[1];
    $view = require_view();
    $result = $controller->getByEmpresa($empresa_id, $user['user_id'], $user['role']);

    http_response_code($result['success'] ? 200 : 404);
    echo json_encode(apply_view($result, $view, 'asignacion'));
    exit;
}

// GET /api/asignacion/reporte/{reporte_id} - Obtener asignaciones por reporte
if ($method === 'GET' && preg_match('/^\/api\/asignacion\/reporte\/(\d+)$/', $uri, $matches)) {
    $user = require_auth($jwt_secret);
    $reporte_id = (int)$matches[1];
    $view = require_view();
    $result = $controller->getByReporte($reporte_id, $user['user_id'], $user['role']);

    http_response_code($result['success'] ? 200 : 404);
    echo json_encode(apply_view($result, $view, 'asignacion'));
    exit;
}

// POST /api/asignacion - Crear nueva asignación
if ($method === 'POST' && $uri === '/api/asignacion') {
    $user = require_auth($jwt_secret);
        if (strtolower((string)$user['role']) !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
            exit;
        }

    // Obtener datos del body
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Datos JSON inválidos'
        ]);
        exit;
    }

    $result = $controller->create($input, $user['user_id']);

    // Éxito 201 y fallo 400 como siempre; los conflictos de estado son 409 y el contrato 2 usa 404/409/500 (write_respond).
    write_respond($result, 400, 201);
    exit;
}

// PUT /api/asignacion/{id} - Actualizar asignación
if ($method === 'PUT' && preg_match('/^\/api\/asignacion\/(\d+)$/', $uri, $matches)) {
    $user = require_auth($jwt_secret);
        if (strtolower((string)$user['role']) !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
            exit;
        }
    $id = (int)$matches[1];

    // Obtener datos del body
    $input = json_decode(file_get_contents('php://input'), true);

    if (!$input) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Datos JSON inválidos'
        ]);
        exit;
    }

    $result = $controller->update($id, $input, $user['user_id']);

    write_respond($result, 400);
    exit;
}

// DELETE /api/asignacion/{id} - Eliminar asignación
if ($method === 'DELETE' && preg_match('/^\/api\/asignacion\/(\d+)$/', $uri, $matches)) {
    $user = require_auth($jwt_secret);
        if (strtolower((string)$user['role']) !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
            exit;
        }
    $id = (int)$matches[1];
    $result = $controller->delete($id, $user['user_id']);

    write_respond($result, 400);
    exit;
}

// POST /api/asignacion/{id}/reactivate - Reactivar una asociación desvinculada (solo administradores)
if ($method === 'POST' && preg_match('/^\/api\/asignacion\/(\d+)\/reactivate$/', $uri, $matches)) {
    $user = require_auth($jwt_secret);
    if (strtolower((string)$user['role']) !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
        exit;
    }
    write_respond($controller->reactivate((int)$matches[1], $user['user_id']), 400);
    exit;
}
