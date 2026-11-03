<?php

/**
 * Middleware de autenticación JWT
 * Verifica que el usuario esté autenticado y devuelve sus datos
 */

require_once __DIR__ . '/jwt.php';
require_once __DIR__ . '/roles.php';

/**
 * Obtiene la clave JWT desde variables de entorno o detiene la ejecución si no está configurada.
 * Centraliza la validación para evitar repetir código en los routers.
 *
 * @param string $context Mensaje breve para identificar el lugar donde se usa (para logs)
 * @return string Clave secreta JWT
 */
function get_jwt_secret_or_fail($context = 'general') {
    $jwt_secret = getenv('JWT_SECRET');
    if (!$jwt_secret) {
        error_log("JWT_SECRET no está configurado en el entorno ({$context})");
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'message' => 'Configuración del servidor incompleta (JWT)'
        ]);
        exit;
    }
    return $jwt_secret;
}

/**
 * Obtiene el tiempo de expiración del token JWT desde variables de entorno.
 * Si no está configurado, usa 24 horas (86400 segundos) por defecto.
 *
 * La variable de entorno JWT_EXPIRATION debe estar en segundos.
 * Ejemplos:
 * - 3600 = 1 hora
 * - 86400 = 24 horas (por defecto)
 * - 604800 = 7 días
 *
 * @return int Tiempo de expiración en segundos
 */
function get_jwt_expiration() {
    $expiration = getenv('JWT_EXPIRATION');
    if ($expiration === false || $expiration === '') {
        // Valor por defecto: 24 horas (86400 segundos)
        return 86400;
    }
    // Convertir a entero y validar que sea un número positivo
    $expiration = (int)$expiration;
    if ($expiration <= 0) {
        error_log("JWT_EXPIRATION tiene un valor inválido ({$expiration}), usando valor por defecto (86400)");
        return 86400;
    }
    return $expiration;
}

/**
 * Verifica la autenticación del usuario mediante JWT
 * @param string $jwt_secret Clave secreta para validar JWT
 * @return array|false Datos del usuario autenticado o false si no está autenticado
 */
function authenticate_user($jwt_secret) {
    // UX-08H: en «admin_escritura» la identidad la fija ÚNICAMENTE el router del modo, tras validar la sesión del piloto (lista blanca, rol, estado y versión en cada petición).
    // Nunca se acepta un JWT: sin identidad fijada el resultado es «no autenticado».
    if (function_exists('app_mode') && app_mode() === 'admin_escritura') {
        return $GLOBALS['ADM_WRITE_USER'] ?? false;
    }
    // Obtener el header Authorization
    $headers = getallheaders();
    $auth_header = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    // Verificar formato: "Bearer [token]"
    if (!preg_match('/Bearer\s+(.*)$/i', $auth_header, $matches)) {
        return false;
    }

    $token = $matches[1];

    // Validar el JWT
    $payload = validate_jwt($token, $jwt_secret);

    if (!$payload) {
        return false;
    }

    // validate_jwt exige user_id y session_version; contrastarlos con la BD
    // permite revocar todas las sesiones incrementando session_version.
    try {
            // Usar el mismo PDO global que ya se creó en index.php
            global $pdo;

            if (!$pdo instanceof PDO) {
                // Si por alguna razón no hay conexión disponible, fallar de forma segura
                error_log('auth_middleware: $pdo no está disponible');
                return false;
            }

            $stmt = $pdo->prepare(
                'SELECT session_version, is_active, role FROM user WHERE id = :id LIMIT 1'
            );
            $stmt->execute(['id' => $payload['user_id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                // Usuario ya no existe
                return false;
            }

            // Si el usuario está marcado como inactivo, considerar el token inválido
            if (isset($row['is_active']) && (int)$row['is_active'] !== 1) {
                return false;
            }

            if (!isset($row['session_version']) || !is_numeric($row['session_version']) || (int)$row['session_version'] < 1) {
                return false;
            }
            if ((int)$row['session_version'] !== $payload['session_version']) {
                return false;
            }
    } catch (PDOException $e) {
        // En caso de error al consultar la BD, registrar y fallar de forma segura
        error_log('Error en auth_middleware al validar session_version: ' . $e->getMessage());
        return false;
    }

    // Un rol no reconocido no concede acceso (ni siquiera de usuario básico)
    $normalized_role = normalize_role($row['role'] ?? null);
    if ($normalized_role === null) {
        return false;
    }

    // Devolver datos del usuario autenticado
    return [
        'user_id' => $payload['user_id'],
        'username' => $payload['username'],
        'email' => $payload['email'],
        'role' => $normalized_role
    ];
}

/**
 * Middleware para endpoints protegidos
 * Si el usuario no está autenticado, devuelve error 401
 * @param string $jwt_secret Clave secreta para validar JWT
 * @return array Datos del usuario autenticado
 */
function require_auth($jwt_secret) {
    $user = authenticate_user($jwt_secret);

    if (!$user) {
        http_response_code(401);
        echo json_encode([
            'success' => false,
            'message' => 'No autorizado. Token inválido o expirado.'
        ]);
        exit;
    }

    return $user;
}
