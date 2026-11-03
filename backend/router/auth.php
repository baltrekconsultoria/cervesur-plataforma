<?php

/**
 * Rutas de autenticación
 * Maneja login, registro, logout y datos de usuario
 */

require_once __DIR__ . '/../lib/auth_middleware.php';

// Endpoint de login
if ($method === 'POST' && $uri === '/api/login') {
    require_once __DIR__ . '/../controllers/AuthController.php';

    // Leer el body JSON
    $inputRaw = file_get_contents('php://input');
    $input = json_decode($inputRaw, true);

    // Validar que el JSON sea válido
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Body JSON inválido']);
        exit;
    }

    // Validar que se envíen los campos necesarios
    if (!isset($input['login']) || !isset($input['password'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Faltan datos de login o password']);
        exit;
    }

    // Normalizar valores
    $login = trim((string)$input['login']);
    $password = (string)$input['password'];

    if ($login === '' || $password === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Login y password no pueden estar vacíos']);
        exit;
    }

    // Clave secreta para firmar el JWT
    $jwt_secret = get_jwt_secret_or_fail('/api/login');

    try {
        // Instanciar el controlador y procesar el login
        $auth = new AuthController($pdo, $jwt_secret);
        $result = $auth->login($login, $password);

        // Responder en JSON
        echo json_encode($result);
    } catch (Exception $e) {
        // Loguear el error y devolver mensaje genérico
        error_log('Error en ruta /api/login: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error interno del servidor']);
    }
    exit;
}

// Endpoint de registro
if ($method === 'POST' && $uri === '/api/register') {
    require_once __DIR__ . '/../controllers/RegisterController.php';

    // Alta de usuarios: SOLO administradores autenticados (antes era público).
    $jwt_secret = get_jwt_secret_or_fail('/api/register');
    $actor = require_auth($jwt_secret);
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

// Endpoint de logout (ahora invalida los tokens del usuario en backend)
if ($method === 'POST' && $uri === '/api/logout') {
    // Clave secreta para validar el JWT
    $jwt_secret = get_jwt_secret_or_fail('/api/logout');

    // Obtener usuario autenticado (requiere header Authorization: Bearer ...)
    $user = require_auth($jwt_secret);

    // Solo lectura (UX-07D.3A): el cierre de sesión NO escribe en la base de datos (no sube session_version, columna que Flutter podría compartir).
    // El cliente descarta el token y este expira solo (JWT_EXPIRATION corto en el piloto).
    if (app_mode_is_read_only()) {
        echo json_encode([
            'success' => true,
            'message' => 'Sesión cerrada en el cliente. En modo de solo lectura el servidor no revoca tokens: expiran solos.',
            'revoked_server_side' => false
        ]);
        exit;
    }

    try {
        // Invalidar todas las sesiones/token de este usuario incrementando session_version
        $stmt = $pdo->prepare(
            'UPDATE user SET session_version = session_version + 1 WHERE id = :id'
        );
        $stmt->execute(['id' => $user['user_id']]);

        echo json_encode([
            'success' => true,
            'message' => 'Logout exitoso. Todos los tokens anteriores han sido invalidados.'
        ]);
    } catch (PDOException $e) {
        error_log('Error en ruta /api/logout al invalidar tokens: ' . $e->getMessage());
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Error interno del servidor'
        ]);
    }
    exit;
}
