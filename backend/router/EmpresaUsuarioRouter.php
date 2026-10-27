<?php

/**
 * Router para manejar asignación de usuarios a empresas
 */

require_once __DIR__ . '/../controllers/EmpresaUsuarioController.php';
require_once __DIR__ . '/../lib/auth_middleware.php';

// Instancia del controlador
$empresaUsuarioController = new EmpresaUsuarioController($pdo);

// Obtener JWT secret
$jwt_secret = get_jwt_secret_or_fail('EmpresaUsuarioRouter');

// POST /api/empresa-usuario - Asignar usuario a empresa
if (preg_match('#^/api/empresa-usuario$#', $uri)) {
    if ($method === 'POST') {
        $user_data = require_auth($jwt_secret);
        if (strtolower((string)$user_data['role']) !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
            exit;
        }
        $auth_user_id = $user_data['user_id'];
        $data = json_decode(file_get_contents('php://input'), true);

        $response = $empresaUsuarioController->assignUserToEmpresa($data, $auth_user_id);
        echo json_encode($response);
        exit;
    } elseif ($method === 'GET') {
        $user_data = require_auth($jwt_secret);
        $response = $empresaUsuarioController->getAll($user_data['user_id'], $user_data['role']);
        echo json_encode($response);
        exit;
    }
}

// GET /api/empresa-usuario/empresa/{nombre} - Listar relaciones por nombre de empresa (búsqueda parcial)
if (preg_match('#^/api/empresa-usuario/empresa/(.+)$#', $uri, $matches)) {
    if ($method === 'GET') {
        $user_data = require_auth($jwt_secret);
        $nombre = urldecode($matches[1]);
        $response = $empresaUsuarioController->getByEmpresaNombre($nombre, $user_data['user_id'], $user_data['role']);
        echo json_encode($response);
        exit;
    }
}

// GET /api/empresa-usuario/ruc/{ruc} - Listar relaciones por RUC de empresa
if (preg_match('#^/api/empresa-usuario/ruc/(.+)$#', $uri, $matches)) {
    if ($method === 'GET') {
        $user_data = require_auth($jwt_secret);
        $ruc = urldecode($matches[1]);
        $response = $empresaUsuarioController->getByEmpresaRuc($ruc, $user_data['user_id'], $user_data['role']);
        echo json_encode($response);
        exit;
    }
}

// DELETE /api/empresa-usuario/{id} - Eliminar (desactivar) relación
if (preg_match('#^/api/empresa-usuario/(\d+)$#', $uri, $matches)) {
    if ($method === 'DELETE') {
        $user_data = require_auth($jwt_secret);
        if (strtolower((string)$user_data['role']) !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
            exit;
        }
        $auth_user_id = $user_data['user_id'];
        $relacion_id = (int)$matches[1];

        $response = $empresaUsuarioController->delete($relacion_id, $auth_user_id);
        echo json_encode($response);
        exit;
    } elseif ($method === 'PUT') {
        // PUT /api/empresa-usuario/{id} - Actualizar relación
        $user_data = require_auth($jwt_secret);
        if (strtolower((string)$user_data['role']) !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
            exit;
        }
        $auth_user_id = $user_data['user_id'];
        $relacion_id = (int)$matches[1];
        $data = json_decode(file_get_contents('php://input'), true);

        $response = $empresaUsuarioController->update($relacion_id, $data, $auth_user_id);
        echo json_encode($response);
        exit;
    } elseif ($method === 'GET') {
        // GET /api/empresa-usuario/{id} - Obtener relación por ID
        $user_data = require_auth($jwt_secret);
        $relacion_id = (int)$matches[1];
        $response = $empresaUsuarioController->getById($relacion_id, $user_data['user_id'], $user_data['role']);
        echo json_encode($response);
        exit;
    }
}
