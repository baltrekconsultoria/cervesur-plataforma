<?php
/**
 * P5-SEC01 HTTP fixture. SOLO PHP built-in server en 127.0.0.1 y DB temporal.
 * Nunca instalar en el backend ni exponerlo mediante Apache.
 */
if (getenv('P5_TEST_ONLY') !== '1'
    || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)
    || !is_string(getenv('P5_TEST_DB')) || !is_file(getenv('P5_TEST_DB'))) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../candidato/p5_legacy_adapter.php';
header('Content-Type: application/json; charset=utf-8');
$path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if ($_SERVER['REQUEST_METHOD'] !== 'GET' || !in_array($path, ['/qa/me', '/qa/admin-only'], true)) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Not found']);
    exit;
}
try {
    $pdo = new PDO('sqlite:' . getenv('P5_TEST_DB'));
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $actor = p5_legacy_authenticate_headers(
        getallheaders(), (string)getenv('P5_TEST_SECRET'), $pdo
    );
} catch (Throwable $e) {
    $actor = false;
}
if ($actor === false) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => 'No autorizado. Token inválido o expirado.'
    ]);
    exit;
}
if ($path === '/qa/admin-only' && $actor['role'] !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
    exit;
}
http_response_code(200);
echo json_encode([
    'success' => true,
    'user_id' => $actor['user_id'],
    'role' => $actor['role']
]);
