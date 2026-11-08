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
        // Columnas explícitas (UX-07D.3A): solo lo que el login necesita, de modo que una cuenta con permisos SELECT por columna baste.
        $sql = "SELECT id, username, email, password, first_name, last_name, role, is_active, session_version FROM user WHERE (email = :email OR username = :username) LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'email' => $login,
            'username' => $login
        ]);

        $user = $stmt->fetch();

        $session_version = $user && isset($user['session_version'])
            ? filter_var($user['session_version'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
            : false;

        $role = $user ? normalize_role($user['role'] ?? null) : null;

        if (!$user || !password_verify($password, $user['password']) || (int)$user['is_active'] !== 1 || $session_version === false || $role === null) {
            return [
                'success' => false,
                'message' => 'Credenciales inválidas'
            ];
        }

        // Actualizar último login. En solo lectura (UX-07D.3A) NO se escribe nada en la base de datos.
        if (!app_mode_is_read_only()) {
            $this->updateLastLogin($user['id']);
        }

        // Generar JWT
        require_once __DIR__ . '/../lib/jwt.php';
        require_once __DIR__ . '/../lib/roles.php';
        require_once __DIR__ . '/../lib/auth_middleware.php';

        // Obtener tiempo de expiración desde variable de entorno (por defecto 24 horas)
        $expiration_seconds = get_jwt_expiration();

        $payload = [
            'user_id' => $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'role' => $role,
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
                'role' => $role
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
