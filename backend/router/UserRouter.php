<?php

/**
 * Rutas de usuario
 * Maneja operaciones relacionadas con el perfil y datos de usuario
 */

require_once __DIR__ . '/../lib/pagination.php';

// Endpoint protegido: obtener datos básicos del usuario autenticado
if ($method === 'GET' && $uri === '/api/user') {
    require_once __DIR__ . '/../lib/auth_middleware.php';
    require_once __DIR__ . '/../controllers/UserController.php';

    // Verificar autenticación
    $jwt_secret = get_jwt_secret_or_fail('/api/user');
    $user = require_auth($jwt_secret);

    // Instanciar controlador y obtener datos básicos
    $userController = new UserController($pdo);
    $result = $userController->getBasicInfo($user['user_id']);

    // Responder
    if (!$result['success']) {
        http_response_code(404);
    }
    echo json_encode($result);
    exit;
}

// Endpoint protegido: obtener/actualizar perfil completo de un usuario por ID
// Esta ruta debe ir ANTES de /api/user/profile para evitar conflictos
if (preg_match('#^/api/user/profile/(\d+)$#', $uri, $matches)) {
    require_once __DIR__ . '/../lib/auth_middleware.php';
    require_once __DIR__ . '/../controllers/UserController.php';

    // Verificar autenticación
    $jwt_secret = get_jwt_secret_or_fail('/api/user/profile/{id}');
    $user = require_auth($jwt_secret);

    // Obtener el ID del usuario desde la URL
    $target_user_id = (int)$matches[1];

    // Validar que el ID sea válido
    if ($target_user_id <= 0) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'ID de usuario inválido'
        ]);
        exit;
    }

    if (strtolower((string)$user['role']) !== 'admin' && $target_user_id !== (int)$user['user_id']) {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
        exit;
    }

    if ($method === 'GET') {
        // GET /api/user/profile/{id} - Obtener perfil de un usuario
        $userController = new UserController($pdo);
        $result = $userController->getProfile($target_user_id);

        // Responder
        if (!$result['success']) {
            http_response_code(404);
        }
        echo json_encode($result);
        exit;
    } elseif ($method === 'PUT') {
        // PUT /api/user/profile/{id} - Actualizar perfil de un usuario
        // Leer el body JSON
        $raw_input = file_get_contents('php://input');
        $input = json_decode($raw_input, true);

        // Validar que se envíen datos
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'JSON inválido: ' . json_last_error_msg()
            ]);
            exit;
        }

        if (!$input || empty($input)) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'No se enviaron datos para actualizar'
            ]);
            exit;
        }

        // Instanciar controlador y actualizar perfil del usuario solicitado
        $userController = new UserController($pdo);
        $result = $userController->updateProfile($target_user_id, $input);

        // Responder
        if (!$result['success']) {
            http_response_code(400);
        }
        echo json_encode($result);
        exit;
    }
}

// Endpoint protegido: obtener perfil completo del usuario autenticado
if ($method === 'GET' && $uri === '/api/user/profile') {
    require_once __DIR__ . '/../lib/auth_middleware.php';
    require_once __DIR__ . '/../controllers/UserController.php';

    // Verificar autenticación
    $jwt_secret = get_jwt_secret_or_fail('/api/user/profile');
    $user = require_auth($jwt_secret);

    // Instanciar controlador y obtener perfil completo
    $userController = new UserController($pdo);
    $result = $userController->getProfile($user['user_id']);

    // Responder
    if (!$result['success']) {
        http_response_code(404);
    }
    echo json_encode($result);
    exit;
}

// Endpoint protegido: actualizar perfil del usuario autenticado
if ($method === 'PUT' && $uri === '/api/user/profile') {
    require_once __DIR__ . '/../lib/auth_middleware.php';
    require_once __DIR__ . '/../controllers/UserController.php';

    // Verificar autenticación
    $jwt_secret = get_jwt_secret_or_fail('PUT /api/user/profile');
    $user = require_auth($jwt_secret);

    // Leer el body JSON
    $raw_input = file_get_contents('php://input');
    $input = json_decode($raw_input, true);

    // Validar que se envíen datos
    if (json_last_error() !== JSON_ERROR_NONE) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'JSON inválido: ' . json_last_error_msg()
        ]);
        exit;
    }

    if (!$input || empty($input)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'No se enviaron datos para actualizar'
        ]);
        exit;
    }

    // Instanciar controlador y actualizar perfil
    $userController = new UserController($pdo);
    $result = $userController->updateProfile($user['user_id'], $input);

    // Responder
    if (!$result['success']) {
        http_response_code(400);
    }
    echo json_encode($result);
    exit;
}

// Endpoint protegido: subir/actualizar avatar del usuario autenticado
if ($method === 'POST' && $uri === '/api/user/avatar') {
    require_once __DIR__ . '/../lib/auth_middleware.php';
    require_once __DIR__ . '/../controllers/UserController.php';

    // Verificar autenticación
    $jwt_secret = get_jwt_secret_or_fail('ruta protegida');
    $user = require_auth($jwt_secret);

    // Validar que se haya enviado un archivo
    if (!isset($_FILES['avatar'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'No se envió ningún archivo en el campo "avatar"'
        ]);
        exit;
    }

    $file = $_FILES['avatar'];

    // Validar errores de subida
    if (!isset($file['error']) || is_array($file['error'])) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Error al subir el archivo'
        ]);
        exit;
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Error en la subida del archivo (código ' . $file['error'] . ')'
        ]);
        exit;
    }

    // Validar tamaño (ej: máximo 2MB)
    $maxSize = 2 * 1024 * 1024; // 2MB
    if ($file['size'] > $maxSize) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'El archivo es demasiado grande (máximo 2MB)'
        ]);
        exit;
    }

    // Validar extensión / tipo de archivo
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));

    if (!in_array($extension, $allowedExtensions, true)) {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'message' => 'Tipo de archivo no permitido. Extensiones válidas: ' . implode(', ', $allowedExtensions)
        ]);
        exit;
    }

    // Definir carpeta de destino (carpeta uploads/avatars en la raíz del proyecto)
    // Ruta física: /var/www/baltrek/uploads/avatars
    $uploadDir = __DIR__ . '/../../uploads/avatars';

    if (!is_dir($uploadDir)) {
        if (!mkdir($uploadDir, 0775, true) && !is_dir($uploadDir)) {
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'No se pudo crear el directorio de subida'
            ]);
            exit;
        }
    }

    // Generar nombre único para el archivo
    $filename = 'avatar_' . $user['user_id'] . '_' . time() . '.' . $extension;
    $targetPath = rtrim($uploadDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $filename;

    // Mover archivo subido
    if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'No se pudo guardar el archivo en el servidor'
        ]);
        exit;
    }

    // Ruta relativa que se guardará en la base de datos (accesible desde el navegador)
    $relativePath = '/uploads/avatars/' . $filename;

    // Actualizar el perfil del usuario con la nueva ruta del avatar
    $userController = new UserController($pdo);
    $result = $userController->updateProfile($user['user_id'], ['avatar' => $relativePath]);

    if (!$result['success']) {
        http_response_code(400);
        echo json_encode($result);
        exit;
    }

    echo json_encode([
        'success' => true,
        'message' => 'Avatar actualizado correctamente',
        'avatar'  => $relativePath,
        'data'    => $result
    ]);
    exit;
}

// Endpoint protegido: actualizar rol de un usuario (solo administradores)
// Esta ruta debe ir ANTES de otras rutas con parámetros para evitar conflictos
if (preg_match('#^/api/user/(\d+)/role$#', $uri, $matches)) {
    if ($method === 'PUT') {
        require_once __DIR__ . '/../lib/auth_middleware.php';
        require_once __DIR__ . '/../controllers/UserController.php';

        // Verificar autenticación
        $jwt_secret = get_jwt_secret_or_fail('PUT /api/user/{id}/role');
        $user = require_auth($jwt_secret);

        // Verificar que sea administrador
        if ($user['role'] !== 'admin' && $user['role'] !== 'ADMIN') {
            http_response_code(403);
            echo json_encode([
                'success' => false,
                'message' => 'Acceso denegado. Solo administradores pueden cambiar roles'
            ]);
            exit;
        }

        // Obtener el ID del usuario desde la URL
        $target_user_id = (int)$matches[1];

        // Validar que el ID sea válido
        if ($target_user_id <= 0) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'ID de usuario inválido'
            ]);
            exit;
        }

        // Leer el body JSON
        $raw_input = file_get_contents('php://input');
        $input = json_decode($raw_input, true);

        // Validar que se envíen datos
        if (json_last_error() !== JSON_ERROR_NONE) {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'JSON inválido: ' . json_last_error_msg()
            ]);
            exit;
        }

        // Validar que se envíe el campo 'role' (solo cadenas)
        if (!is_array($input) || !isset($input['role']) || !is_string($input['role']) || trim($input['role']) === '') {
            http_response_code(400);
            echo json_encode([
                'success' => false,
                'message' => 'El campo "role" es requerido'
            ]);
            exit;
        }

        // Instanciar controlador y actualizar rol (transacción + auditoría; conflictos de estado = 409)
        require_once __DIR__ . '/../lib/write_support.php';
        $userController = new UserController($pdo);
        $result = $userController->updateUserRole($target_user_id, $input['role'], $user['user_id']);

        // Responder: los fallos de validación conservan su 400 histórico; los de estado son 409
        write_respond($result, 400);
        exit;
    }
}

// Endpoint protegido: obtener todos los usuarios (solo administradores)
if ($method === 'GET' && $uri === '/api/users') {
    require_once __DIR__ . '/../lib/auth_middleware.php';
    require_once __DIR__ . '/../controllers/UserController.php';

    // Verificar autenticación
    $jwt_secret = get_jwt_secret_or_fail('/api/users');
    $user = require_auth($jwt_secret);

    // Verificar que sea administrador
    if ($user['role'] !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Acceso denegado. Solo administradores']);
        exit;
    }

    // Leer parámetro query para filtrar usuarios
    // ?status=active (por defecto) - solo usuarios activos
    // ?status=inactive - solo usuarios inactivos
    // ?status=all - todos los usuarios (activos e inactivos)
    // También soporta ?only_active=false para compatibilidad
    $filter = true; // Por defecto solo usuarios activos

    if (isset($_GET['status'])) {
        $status = strtolower(trim($_GET['status']));
        if ($status === 'inactive') {
            $filter = 'inactive';
        } elseif ($status === 'all') {
            $filter = false;
        } else {
            $filter = true; // 'active' o cualquier otro valor → solo activos
        }
    } elseif (isset($_GET['only_active'])) {
        // Compatibilidad con parámetro anterior
        $only_active = filter_var($_GET['only_active'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($only_active === null) {
            $filter = true; // Si el valor no es válido, usar por defecto
        } else {
            $filter = $only_active;
        }
    } elseif (isset($_GET['include_inactive'])) {
        // Compatibilidad: si include_inactive=true, entonces mostrar todos
        $include_inactive = filter_var($_GET['include_inactive'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        $filter = !($include_inactive === true);
    }

    // Instanciar controlador y obtener todos los usuarios
    $userController = new UserController($pdo);
    $result = $userController->getAllUsers($filter, require_pagination());

    // Responder
    echo json_encode($result);
    exit;
}

// ---------------------------------------------------------------------------------------------------------------------------
// Administración de usuarios (UX-08A.users). Solo administradores (rol leído de MySQL en cada petición).
// Las ESCRITURAS nuevas responden 404 salvo que el servidor tenga USER_ADMIN_WRITES=1; las lecturas no están condicionadas.
// ---------------------------------------------------------------------------------------------------------------------------
if (preg_match('#^/api/users/(capabilities|\d+)(?:/(access|deactivate|reactivate|password))?$#', $uri, $matches)) {
    $segment = $matches[1];
    $sub = $matches[2] ?? null;
    $isCapabilities = $segment === 'capabilities';
    $routeKey = $method . ' ' . ($isCapabilities ? 'capabilities' : '{id}') . ($sub ? '/' . $sub : '');
    $readRoutes = ['GET capabilities', 'GET {id}', 'GET {id}/access'];
    $writeRoutes = ['PUT {id}', 'POST {id}/deactivate', 'POST {id}/reactivate', 'PUT {id}/password'];

    if (in_array($routeKey, $readRoutes, true) || in_array($routeKey, $writeRoutes, true)) {
        require_once __DIR__ . '/../lib/auth_middleware.php';
        require_once __DIR__ . '/../lib/write_support.php';
        require_once __DIR__ . '/../controllers/UserController.php';

        // Interruptor: las escrituras no existen (404) mientras USER_ADMIN_WRITES no sea exactamente 1, ni siquiera para anónimos.
        if (in_array($routeKey, $writeRoutes, true) && !user_admin_writes_enabled()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'message' => 'No encontrado']);
            exit;
        }

        $jwt_secret = get_jwt_secret_or_fail('/api/users/*');
        $actor = require_auth($jwt_secret);
        if ($actor['role'] !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acceso denegado. Solo administradores']);
            exit;
        }

        $userController = new UserController($pdo);
        if ($isCapabilities) {
            echo json_encode(['success' => true, 'data' => ['user_admin_writes' => user_admin_writes_enabled()]]);
            exit;
        }

        $targetId = positive_int($segment);
        if ($targetId === null) {
            write_respond(['success' => false, 'message' => 'ID de usuario inválido']);
            exit;
        }

        // Cuerpo JSON (objeto) solo para las rutas que lo usan; los campos no permitidos (role, is_active, session_version…) se ignoran.
        $input = [];
        if ($routeKey === 'PUT {id}' || $routeKey === 'PUT {id}/password') {
            $input = json_decode((string)file_get_contents('php://input'), true);
            if (!is_array($input)) {
                write_respond(['success' => false, 'message' => 'Body JSON inválido']);
                exit;
            }
        }

        switch ($routeKey) {
            case 'GET {id}':
                $result = $userController->adminGet($targetId);
                break;
            case 'GET {id}/access':
                $result = $userController->adminAccess($targetId);
                break;
            case 'PUT {id}':
                $result = $userController->adminUpdate($targetId, $input, $actor['user_id']);
                break;
            case 'POST {id}/deactivate':
                $result = $userController->adminSetActive($targetId, false, $actor['user_id']);
                break;
            case 'POST {id}/reactivate':
                $result = $userController->adminSetActive($targetId, true, $actor['user_id']);
                break;
            default: // PUT {id}/password
                $result = $userController->adminSetPassword($targetId, $input, $actor['user_id']);
        }
        write_respond($result);
        exit;
    }
}
