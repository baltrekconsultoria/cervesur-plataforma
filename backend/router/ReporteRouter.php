<?php

/**
 * Router para reportes
 * Maneja las rutas relacionadas con la gestión de reportes
 */

// Incluir dependencias
require_once __DIR__ . '/../lib/views.php';
require_once __DIR__ . '/../lib/pagination.php';
require_once __DIR__ . '/../lib/status_filter.php';
require_once __DIR__ . '/../lib/auth_middleware.php';
require_once __DIR__ . '/../controllers/ReporteController.php';

// Crear instancia del controlador
$reporteController = new ReporteController($pdo);

// Rutas de reportes
if (strpos($uri, '/api/reportes') === 0) {

    // POST /api/reportes - Crear nuevo reporte
    if ($method === 'POST' && $uri === '/api/reportes') {
        // Verificar autenticación
        $jwt_secret = get_jwt_secret_or_fail('ruta protegida');
        $user_data = require_auth($jwt_secret);
        if (strtolower((string)$user_data['role']) !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
            exit;
        }
        $user_id = $user_data['user_id'];

        // Obtener datos del request
        $data = json_decode(file_get_contents('php://input'), true);
        $response = $reporteController->create($data, $user_id);
        write_respond($response);
        exit;
    }

    // PUT /api/reportes/{id} - Actualizar reporte
    if ($method === 'PUT' && preg_match('/^\/api\/reportes\/(\d+)$/', $uri, $matches)) {
        // Verificar autenticación
        $jwt_secret = get_jwt_secret_or_fail('ruta protegida');
        $user_data = require_auth($jwt_secret);
        if (strtolower((string)$user_data['role']) !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
            exit;
        }
        $user_id = $user_data['user_id'];

        $data = json_decode(file_get_contents('php://input'), true);
        $response = $reporteController->update($matches[1], $data, $user_data['user_id']);
        write_respond($response);
        exit;
    }

    // DELETE /api/reportes/{id} - Eliminar reporte
    if ($method === 'DELETE' && preg_match('/^\/api\/reportes\/(\d+)$/', $uri, $matches)) {
        // Verificar autenticación
        $jwt_secret = get_jwt_secret_or_fail('ruta protegida');
        $user_data = require_auth($jwt_secret);
        if (strtolower((string)$user_data['role']) !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
            exit;
        }
        $user_id = $user_data['user_id'];

        $response = $reporteController->delete($matches[1], $user_data['user_id']);
        write_respond($response);
        exit;
    }

    // POST /api/reportes/{id}/reactivate - Reactivar un reporte dado de baja (solo administradores)
    if ($method === 'POST' && preg_match('/^\/api\/reportes\/(\d+)\/reactivate$/', $uri, $matches)) {
        $jwt_secret = get_jwt_secret_or_fail('ruta protegida');
        $user_data = require_auth($jwt_secret);
        if (strtolower((string)$user_data['role']) !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
            exit;
        }
        write_respond($reporteController->reactivate((int)$matches[1], $user_data['user_id']));
        exit;
    }

    // GET /api/reportes - Listar todos los reportes
    if ($method === 'GET' && $uri === '/api/reportes') {
        // Verificar autenticación
        $jwt_secret = get_jwt_secret_or_fail('ruta protegida');
        $user = require_auth($jwt_secret);
        run_with_view(require_view(), 'reporte', function () use ($reporteController, $user) {
            $reporteController->getAll($user['user_id'], $user['role'], require_pagination(), require_status_filter($user['role']));
        });
        exit;
    }

    // GET /api/reportes/activos - Listar solo reportes activos
    if ($method === 'GET' && $uri === '/api/reportes/activos') {
        // Verificar autenticación
        $jwt_secret = get_jwt_secret_or_fail('ruta protegida');
        $user = require_auth($jwt_secret);
        run_with_view(require_view(), 'reporte', function () use ($reporteController, $user) {
            $reporteController->getActivos($user['user_id'], $user['role'], require_pagination());
        });
        exit;
    }

    // GET /api/reportes/{id} - Obtener reporte por ID
    if ($method === 'GET' && preg_match('/^\/api\/reportes\/(\d+)$/', $uri, $matches)) {
        // Verificar autenticación
        $jwt_secret = get_jwt_secret_or_fail('ruta protegida');
        $user = require_auth($jwt_secret);
        [$view, $resource] = require_detail_view($user['role'], 'reporte');
        run_with_view($view, $resource, function () use ($reporteController, $user, $matches) {
            $reporteController->getById($matches[1], $user['user_id'], $user['role']);
        });
        exit;
    }

    // GET /api/reportes/grupoid/{uuid} - Obtener reportes por grupo
    if ($method === 'GET' && preg_match('/^\/api\/reportes\/grupoid\/([a-f0-9\-]{36})$/', $uri, $matches)) {
        // Verificar autenticación
        $jwt_secret = get_jwt_secret_or_fail('ruta protegida');
        $user = require_auth($jwt_secret);
        run_with_view(require_view(), 'reporte', function () use ($reporteController, $user, $matches) {
            $reporteController->getByGrupoId($matches[1], $user['user_id'], $user['role']);
        });
        exit;
    }

    // GET /api/reportes/id/{id}/visor - Configuración del visor por ID NUMÉRICO del informe (UX-07D.2B).
    // Es la ruta que usa React: `reporte_id` histórico de producción no siempre es un UUID de 36 caracteres. Mismo control de acceso que
    // la ruta por UUID. Solo se aceptan enteros positivos canónicos (sin ceros a la izquierda, signo ni decimales); lo demás cae al 404 genérico.
    if ($method === 'GET' && preg_match('/^\/api\/reportes\/id\/([1-9][0-9]{0,9})\/visor$/', $uri, $matches)) {
        $jwt_secret = get_jwt_secret_or_fail('ruta protegida');
        $user = require_auth($jwt_secret);
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        [$response, $status] = $reporteController->getViewerById($matches[1], $user['user_id'], $user['role']);
        http_response_code($status);
        echo json_encode($response);
        exit;
    }

    // GET /api/reportes/reporteid/{uuid}/visor - Configuración del visor (UX-07C), solo si el servidor autoriza el acceso
    if ($method === 'GET' && preg_match('/^\/api\/reportes\/reporteid\/([a-f0-9\-]{36})\/visor$/', $uri, $matches)) {
        $jwt_secret = get_jwt_secret_or_fail('ruta protegida');
        $user = require_auth($jwt_secret);
        header('Cache-Control: no-store, no-cache, must-revalidate, private');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
        [$response, $status] = $reporteController->getViewer($matches[1], $user['user_id'], $user['role']);
        http_response_code($status);
        echo json_encode($response);
        exit;
    }

    // GET /api/reportes/reporteid/{uuid} - Obtener reporte por reporte_id
    if ($method === 'GET' && preg_match('/^\/api\/reportes\/reporteid\/([a-f0-9\-]{36})$/', $uri, $matches)) {
        // Verificar autenticación
        $jwt_secret = get_jwt_secret_or_fail('ruta protegida');
        $user = require_auth($jwt_secret);
        [$view, $resource] = require_detail_view($user['role'], 'reporte');
        run_with_view($view, $resource, function () use ($reporteController, $user, $matches) {
            $reporteController->getByReporteId($matches[1], $user['user_id'], $user['role']);
        });
        exit;
    }

    // Si no coincide con ninguna ruta, devolver 404
    http_response_code(404);
    echo json_encode([
        'success' => false,
        'message' => 'Endpoint de reportes no encontrado'
    ]);
    exit;
}
