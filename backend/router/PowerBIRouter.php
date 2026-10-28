<?php
require_once __DIR__ . '/../lib/auth_middleware.php';
require_once __DIR__ . '/../controllers/PowerBIController.php';

if ($method === 'GET' && preg_match('/^\/api\/powerbi\/tokens\/(\d+)$/', $uri, $matches)) {
    $user = require_auth(get_jwt_secret_or_fail('PowerBIRouter'));
    $id = (int)$matches[1];
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'ID inválido']);
        exit;
    }
    $controller = new PowerBIController($pdo);
    $credentials = $controller->getCredentialsEmpresaReporte($id, $user['user_id'], $user['role']);
    if (empty($credentials['success']) || empty($credentials['data'])) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Reporte no disponible']);
        exit;
    }
    $data = $credentials['data'];
    // UX-07C: un informe registrado solo por su enlace público no tiene identificadores de Power BI: no se contacta con Microsoft.
    if (empty($data['grupo_id']) || empty($data['reporte_id'])) {
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Reporte no disponible']);
        exit;
    }
    $result = $controller->getAccessToken($data['powerbi_tenant'], $data['powerbi_client'], $data['powerbi_clientsecret']);
    http_response_code($result['success'] ? 200 : 502);
    echo json_encode($result);
    exit;
}
