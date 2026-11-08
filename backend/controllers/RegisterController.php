<?php
/**
 * Controlador para registro de usuarios
 * Maneja la creación de nuevos usuarios en la base de datos
 */
require_once __DIR__ . '/../lib/db.php';

class RegisterController {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Crea un usuario (operación administrativa: la ruta exige un administrador autenticado).
     * Siempre con rol 'user', is_active = 1 y session_version = 1.
     * @param array $data Datos del usuario a registrar
     * @return array Resultado del registro
     */
    public function register($data) {
        // Validar campos obligatorios
        $required_fields = ['first_name', 'last_name', 'username', 'email', 'password'];
        foreach ($required_fields as $field) {
            if (empty($data[$field])) {
                return [
                    'success' => false,
                    'message' => "El campo '$field' es obligatorio"
                ];
            }
        }

        // Validar formato de email
        if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'message' => 'El formato del email no es válido'
            ];
        }

        // Validar longitud de contraseña
        if (strlen($data['password']) < 6) {
            return [
                'success' => false,
                'message' => 'La contraseña debe tener al menos 6 caracteres'
            ];
        }

        // Verificar si el email ya existe
        $sql = "SELECT id FROM user WHERE email = :email LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['email' => $data['email']]);

        if ($stmt->fetch()) {
            return [
                'success' => false,
                'message' => 'El email ya está registrado'
            ];
        }

        // Verificar si el username ya existe
        $sql = "SELECT id FROM user WHERE username = :username LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(['username' => $data['username']]);

        if ($stmt->fetch()) {
            return [
                'success' => false,
                'message' => 'El nombre de usuario ya está en uso'
            ];
        }

        // Hashear la contraseña
        $passwordHash = password_hash($data['password'], PASSWORD_DEFAULT);

        // Insertar usuario
        $sql = "INSERT INTO user (first_name, last_name, username, email, phone, password, role, is_active, session_version, created_at, updated_at) VALUES (:first_name, :last_name, :username, :email, :phone, :password, :role, 1, 1, NOW(), NOW())";
        $stmt = $this->pdo->prepare($sql);

        $result = $stmt->execute([
            'first_name' => $data['first_name'],
            'last_name' => $data['last_name'],
            'username' => $data['username'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? null,
            'password' => $passwordHash,
            // Fijo: el rol jamás proviene de la petición. Para otorgar 'admin' existe PUT /api/user/{id}/role.
            'role' => 'user'
        ]);

        if ($result) {
            return [
                'success' => true,
                'message' => 'Usuario registrado exitosamente'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Error al registrar el usuario'
            ];
        }
    }
}
