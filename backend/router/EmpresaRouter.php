<?php

/**
 * Router para manejar rutas de empresas
 * Incluye todas las operaciones CRUD y búsquedas
 */

require_once __DIR__ . '/../lib/views.php';
require_once __DIR__ . '/../lib/pagination.php';
require_once __DIR__ . '/../lib/status_filter.php';
require_once __DIR__ . '/../controllers/EmpresaController.php';
require_once __DIR__ . '/../lib/auth_middleware.php';

// Crear instancia del controlador
$empresaController = new EmpresaController($pdo);

// Obtener JWT secret
$jwt_secret = getenv('JWT_SECRET');

// Rutas de empresas
if (preg_match('#^/api/empresas$#', $uri)) {
    if ($method === 'GET') {
        // GET /api/empresas - Listar todas las empresas
        $user_data = require_auth($jwt_secret);
        $user_id = $user_data['user_id'];
        $role = $user_data['role'];
        $response = $empresaController->getAll($user_id, $role, require_pagination(), require_status_filter($role));
        echo json_encode(apply_view($response, require_view(), 'empresa'));
        exit;
    } elseif ($method === 'POST') {
        // POST /api/empresas - Crear nueva empresa
        $user_data = require_auth($jwt_secret);
        if (strtolower((string)$user_data['role']) !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
            exit;
        }
        $user_id = $user_data['user_id'];
        $data = json_decode(file_get_contents('php://input'), true);
        $response = $empresaController->create($data, $user_id);
        write_respond($response);
        exit;
    }
}

// GET /api/empresas/buscar/{termino} - Buscar empresas
if (preg_match('#^/api/empresas/buscar/(.+)$#', $uri, $matches)) {
    if ($method === 'GET') {
        $user_data = require_auth($jwt_secret);
        $user_id = $user_data['user_id'];
        $role = $user_data['role'];
        $search = urldecode($matches[1]);
        $response = $empresaController->search($search, $user_id, $role);
        echo json_encode(apply_view($response, require_view(), 'empresa'));
        exit;
    }
}

// GET /api/empresas/ciudad/{ciudad} - Obtener empresas por ciudad
if (preg_match('#^/api/empresas/ciudad/(.+)$#', $uri, $matches)) {
    if ($method === 'GET') {
        $user_data = require_auth($jwt_secret);
        $user_id = $user_data['user_id'];
        $role = $user_data['role'];
        $ciudad = urldecode($matches[1]);
        $response = $empresaController->getByCity($ciudad, $user_id, $role);
        echo json_encode(apply_view($response, require_view(), 'empresa'));
        exit;
    }
}

// POST /api/empresas/{id}/reactivate - Reactivar una empresa dada de baja (solo administradores)
if ($method === 'POST' && preg_match('#^/api/empresas/(\d+)/reactivate$#', $uri, $matches)) {
    $user_data = require_auth($jwt_secret);
    if (strtolower((string)$user_data['role']) !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
        exit;
    }
    write_respond($empresaController->reactivate((int)$matches[1], $user_data['user_id']));
    exit;
}

// GET /api/empresas/{id} - Obtener empresa por ID
if (preg_match('#^/api/empresas/(\d+)$#', $uri, $matches)) {
    $id = (int)$matches[1];

    if ($method === 'GET') {
        $user_data = require_auth($jwt_secret);
        $user_id = $user_data['user_id'];
        $role = $user_data['role'];
        [$view, $resource] = require_detail_view($role, 'empresa');
        $response = $empresaController->getById($id, $user_id, $role);
        echo json_encode(apply_view($response, $view, $resource));
        exit;
    } elseif ($method === 'PUT') {
        // PUT /api/empresas/{id} - Actualizar empresa
        $user_data = require_auth($jwt_secret);
        if (strtolower((string)$user_data['role']) !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
            exit;
        }
        $user_id = $user_data['user_id'];
        $role = $user_data['role'];
        $data = json_decode(file_get_contents('php://input'), true);
        $response = $empresaController->update($id, $data, $user_id, $role);
        write_respond($response);
        exit;
    } elseif ($method === 'DELETE') {
        // DELETE /api/empresas/{id} - Eliminar empresa
        $user_data = require_auth($jwt_secret);
        if (strtolower((string)$user_data['role']) !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
            exit;
        }
        $user_id = $user_data['user_id'];
        $role = $user_data['role'];
        $response = $empresaController->delete($id, $user_id, $role);
        write_respond($response);
        exit;
    }
}
