<?php

/**
 * Router para manejar la relación usuario ↔ empresa_reporte
 * Endpoints protegidos con JWT
 */
require_once __DIR__ . '/../lib/pagination.php';
require_once __DIR__ . '/../lib/views.php';
require_once __DIR__ . '/../controllers/EmpresaReporteUsuarioController.php';
require_once __DIR__ . '/../lib/auth_middleware.php';

// Instancia del controlador
$empresaReporteUsuarioController = new EmpresaReporteUsuarioController($pdo);

// JWT secret
$jwt_secret = get_jwt_secret_or_fail('EmpresaReporteUsuarioRouter');

// Rutas base: /api/empresa-reporte-usuario
if (preg_match('#^/api/empresa-reporte-usuario$#', $uri)) {
    if ($method === 'GET') {
        $user_data = require_auth($jwt_secret);

        $view = require_view();
        $response = $empresaReporteUsuarioController->getAll($user_data['user_id'], $user_data['role'], require_pagination(20));

        echo json_encode(apply_view($response, $view, 'empresa_reporte_usuario'));
        exit;
    } elseif ($method === 'POST') {
        $user_data = require_auth($jwt_secret);
        if (strtolower((string)$user_data['role']) !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
            exit;
        }
        $auth_user_id = $user_data['user_id'];
        $data = json_decode(file_get_contents('php://input'), true);
        $response = $empresaReporteUsuarioController->create($data, $auth_user_id);
        echo json_encode($response);
        exit;
    }
}

// GET /api/empresa-reporte-usuario/{id}
// PUT /api/empresa-reporte-usuario/{id}
// DELETE /api/empresa-reporte-usuario/{id}
if (preg_match('#^/api/empresa-reporte-usuario/(\d+)$#', $uri, $matches)) {
    $relacion_id = (int)$matches[1];
    if ($method === 'GET') {
        $user_data = require_auth($jwt_secret);
        $response = $empresaReporteUsuarioController->getById($relacion_id, $user_data['user_id'], $user_data['role']);
        echo json_encode($response);
        exit;
    } elseif ($method === 'PUT') {
        $user_data = require_auth($jwt_secret);
        if (strtolower((string)$user_data['role']) !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
            exit;
        }
        $auth_user_id = $user_data['user_id'];
        $data = json_decode(file_get_contents('php://input'), true);
        $response = $empresaReporteUsuarioController->update($relacion_id, $data, $auth_user_id);
        echo json_encode($response);
        exit;
    } elseif ($method === 'DELETE') {
        $user_data = require_auth($jwt_secret);
        if (strtolower((string)$user_data['role']) !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
            exit;
        }
        $auth_user_id = $user_data['user_id'];
        $response = $empresaReporteUsuarioController->delete($relacion_id, $auth_user_id);
        echo json_encode($response);
        exit;
    }
}

// GET /api/empresa-reporte-usuario/ruc/{ruc}
if (preg_match('#^/api/empresa-reporte-usuario/ruc/(.+)$#', $uri, $matches)) {
    if ($method === 'GET') {
        $user_data = require_auth($jwt_secret);
        $ruc = urldecode($matches[1]);
        $response = $empresaReporteUsuarioController->getByEmpresaRuc($ruc, $user_data['user_id'], $user_data['role'], require_pagination());
        echo json_encode($response);
        exit;
    }
}

// GET /api/empresa-reporte-usuario/empresa/{nombre}
if (preg_match('#^/api/empresa-reporte-usuario/empresa/(.+)$#', $uri, $matches)) {
    if ($method === 'GET') {
        $user_data = require_auth($jwt_secret);
        $nombre = urldecode($matches[1]);
        $response = $empresaReporteUsuarioController->getByEmpresaNombre($nombre, $user_data['user_id'], $user_data['role'], require_pagination(20));
        echo json_encode($response);
        exit;
    }
}

// GET /api/empresa-reporte-usuario/user/{userId}
if (preg_match('#^/api/empresa-reporte-usuario/user/(\d+)$#', $uri, $matches)) {
    if ($method === 'GET') {
        $user_data = require_auth($jwt_secret);
        $userId = (int)$matches[1];
        if (strtolower((string)$user_data['role']) !== 'admin' && $userId !== (int)$user_data['user_id']) {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
            exit;
        }
        $response = $empresaReporteUsuarioController->getByUserId($userId, $user_data['user_id'], $user_data['role'], require_pagination());
        echo json_encode($response);
        exit;
    }
}
