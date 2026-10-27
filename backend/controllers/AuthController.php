<?php

/**
 * Controlador para autenticación de usuarios
 * Maneja login y validación de credenciales
 */
require_once __DIR__ . '/../lib/db.php';

class AuthController {
    private $pdo;
    private $jwt_secret;

    public function __construct($pdo, $jwt_secret) {
        $this->pdo = $pdo;
        $this->jwt_secret = $jwt_secret;
    }

    /**
     * Autentica un usuario y devuelve un JWT
     * @param string $login Email o username del usuario
     * @param string $password Contraseña del usuario
     * @return array Resultado de la autenticación
     */
    public function login($login, $password) {
        // Buscar usuario por email o username
        $sql = "SELECT * FROM user WHERE (email = :email OR username = :username) LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'email' => $login,
            'username' => $login
        ]);

        $user = $stmt->fetch();

        $session_version = $user && isset($user['session_version'])
            ? filter_var($user['session_version'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;

        if (!$user || !password_verify($password, $user['password']) || (int)$user['is_active'] !== 1 || $session_version === false) {
            return [
                'success' => false,
                'message' => 'Credenciales inválidas'
            ];
        }

        // Actualizar último login
        $this->updateLastLogin($user['id']);

        // Generar JWT
        require_once __DIR__ . '/../lib/jwt.php';
        require_once __DIR__ . '/../lib/auth_middleware.php';

        // Obtener tiempo de expiración desde variable de entorno (por defecto 24 horas)
        $expiration_seconds = get_jwt_expiration();

        $payload = [
            'user_id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role' => $user['role'],
            // Versión de sesión para poder invalidar tokens desde backend
            'session_version' => (int)$session_version,
            'exp' => time() + $expiration_seconds // Tiempo de expiración configurable
        ];

        $token = generate_jwt($payload, $this->jwt_secret);

        return [
            'success' => true,
            'message' => 'Login exitoso',
            'token' => $token,
            'user' => [
                'id' => $user['id'],
                'username' => $user['username'],
                'email' => $user['email'],
                'first_name' => $user['first_name'],
                'last_name' => $user['last_name'],
                'role' => $user['role']
            ]
        ];
    }

    /**
     * Actualiza la fecha del último login del usuario
     * @param int $user_id ID del usuario
     */
    private function updateLastLogin($user_id) {
        $sql = "UPDATE user SET last_login = NOW() WHERE id = :user_id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['user_id' => $user_id]);
    }
}
