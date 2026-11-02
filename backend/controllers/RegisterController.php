<?php
/**
 * Controlador para registro de usuarios
 * Maneja la creación de nuevos usuarios en la base de datos
 */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/write_support.php';
require_once __DIR__ . '/../models/UserModel.php';

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
    public function register($data, $actorId = 0) {
        // Validar campos obligatorios, tipos y longitudes (solo cadenas)
        [$clean, $error] = UserModel::validateIdentity($data, ['first_name', 'last_name', 'username', 'email', 'phone'], ['first_name', 'last_name', 'username', 'email']);
        if ($error !== null) {
            return ['success' => false, 'message' => $error];
        }
        // Contraseña: 12 a 72 bytes (límite de bcrypt)
        $passwordError = UserModel::passwordError($data['password'] ?? null);
        if ($passwordError !== null) {
            return ['success' => false, 'message' => $passwordError];
        }

        return run_write(function () use ($clean, $data, $actorId) {
            return db_tx($this->pdo, function () use ($clean, $data, $actorId) {
                // Lectura sin bloqueo a propósito (como en Empresas): la carrera la decide el UNIQUE real (1062 -> duplicado).
                $stmt = $this->pdo->prepare('SELECT id FROM user WHERE email = :email LIMIT 1');
                $stmt->execute(['email' => $clean['email']]);
                if ($stmt->fetch()) {
                    throw new WriteConflict('duplicate', 'El email ya está registrado');
                }
                $stmt = $this->pdo->prepare('SELECT id FROM user WHERE username = :username LIMIT 1');
                $stmt->execute(['username' => $clean['username']]);
                if ($stmt->fetch()) {
                    throw new WriteConflict('duplicate', 'El nombre de usuario ya está en uso');
                }

                // Hashear la contraseña (compatible con password_verify del backend original)
                $passwordHash = password_hash($data['password'], PASSWORD_DEFAULT);

                $sql = "INSERT INTO user (first_name, last_name, username, email, phone, password, role, is_active, session_version, created_at, updated_at) VALUES (:first_name, :last_name, :username, :email, :phone, :password, :role, 1, 1, NOW(), NOW())";
                $this->pdo->prepare($sql)->execute([
                    'first_name' => $clean['first_name'],
                    'last_name' => $clean['last_name'],
                    'username' => $clean['username'],
                    'email' => $clean['email'],
                    'phone' => $clean['phone'] ?? null,
                    'password' => $passwordHash,
                    // Fijo: el rol jamás proviene de la petición. Para otorgar 'admin' existe PUT /api/user/{id}/role.
                    'role' => 'user'
                ]);
                $id = (int)$this->pdo->lastInsertId();
                $row = ['role' => 'user', 'is_active' => 1, 'email' => $clean['email']];
                audit_record($this->pdo, $actorId, 'user.create', 'user', $id, null, audit_snapshot('user', $row),
                    ['first_name_modificado' => true, 'last_name_modificado' => true, 'email_modificado' => true, 'phone_modificado' => isset($clean['phone'])]);
                return ['success' => true, 'message' => 'Usuario registrado exitosamente', 'id' => $id];
            });
        }, 'Error al registrar el usuario');
    }
}
