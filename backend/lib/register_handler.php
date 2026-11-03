<?php
/**
 * Alta de usuarios (POST /api/register) como función reutilizable (UX-08H): la usan el router heredado (JWT) y el router de «admin_escritura» (sesión del piloto).
 * El llamador ya autenticó al actor; aquí se exige rol administrador, se valida el cuerpo y se delega en RegisterController (transacción + auditoría).
 */
require_once __DIR__ . '/write_support.php';

function handle_register_request(PDO $pdo, array $actor) {
    require_once __DIR__ . '/../controllers/RegisterController.php';
    if ($actor['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Acceso denegado. Solo administradores pueden crear usuarios']);
        exit;
    }

    // Leer el body JSON
    $inputRaw = file_get_contents('php://input');
    $input = json_decode($inputRaw, true);

    // Validar que el JSON sea válido
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Body JSON inválido']);
        exit;
    }

    // El rol y el estado nunca los decide la petición: se rechazan en lugar de ignorarlos en silencio
    foreach (['role', 'is_active', 'session_version', 'id'] as $forbidden) {
        if (array_key_exists($forbidden, $input)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "El campo '$forbidden' no está permitido"]);
            exit;
        }
    }

    // Validar que se envíen los campos necesarios
    if (!isset($input['first_name']) || !isset($input['last_name']) || !isset($input['username']) || !isset($input['email']) || !isset($input['password'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Faltan campos obligatorios']);
        exit;
    }

    // El controlador valida tipos (solo cadenas), longitudes y formato; aquí solo se rechazan valores no escalares.
    foreach (['first_name', 'last_name', 'username', 'email', 'password'] as $field) {
        if (!is_string($input[$field])) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => "El campo '$field' no es válido"]);
            exit;
        }
    }

    try {
        // Instanciar el controlador y procesar el registro (transacción + auditoría; duplicado = conflicto)
        $register = new RegisterController($pdo);
        write_respond($register->register($input, $actor['user_id']));
    } catch (Exception $e) {
        // Loguear el error y devolver mensaje genérico
        error_log('Error en ruta /api/register: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error interno del servidor']);
    }
    exit;
}
