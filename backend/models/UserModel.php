<?php

require_once __DIR__ . '/../lib/pagination.php';

/**
 * Modelo para la tabla user
 * Maneja todas las operaciones de base de datos relacionadas con usuarios
 */
class UserModel {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Obtiene el perfil completo de un usuario por ID (sin password por seguridad)
     * @param int $user_id ID del usuario
     * @return array Arreglo estructurado con success, data/message
     */
    public function getProfileById($user_id) {
        try {
            // Validar que el user_id sea un entero positivo
            $user_id = filter_var($user_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($user_id === false) {
                return [
                    'success' => false,
                    'message' => 'ID de usuario inválido'
                ];
            }

            $sql = "SELECT id, first_name, last_name, username, email, phone, avatar, role, last_login, is_active FROM user WHERE id = :user_id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['user_id' => $user_id]);
            $profile = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($profile) {
                // Convertir is_active a booleano
                $profile['is_active'] = (bool)$profile['is_active'];

                return [
                    'success' => true,
                    'data' => $profile
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Usuario no encontrado'
                ];
            }
        } catch (PDOException $e) {
            error_log("Error en UserModel::getProfileById: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al obtener el perfil del usuario'
            ];
        }
    }

    /**
     * Obtiene datos básicos del usuario por ID
     * @param int $user_id ID del usuario
     * @return array Arreglo estructurado con success, data/message
     */
    public function getBasicInfoById($user_id) {
        try {
            // Validar que el user_id sea un entero positivo
            $user_id = filter_var($user_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($user_id === false) {
                return [
                    'success' => false,
                    'message' => 'ID de usuario inválido'
                ];
            }

            $sql = "SELECT id, username, email, first_name, last_name, role FROM user WHERE id = :user_id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['user_id' => $user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                return [
                    'success' => true,
                    'data' => $user
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Usuario no encontrado'
                ];
            }
        } catch (PDOException $e) {
            error_log("Error en UserModel::getBasicInfoById: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al obtener los datos del usuario'
            ];
        }
    }

    /**
     * Verifica si un usuario existe por ID
     * @param int $user_id ID del usuario
     * @return bool True si existe, false si no
     */
    public function userExists($user_id) {
        try {
            // Validar que el user_id sea un entero positivo
            $user_id = filter_var($user_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($user_id === false) {
                return false;
            }

            $sql = "SELECT COUNT(*) FROM user WHERE id = :user_id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['user_id' => $user_id]);
            return $stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            error_log("Error en UserModel::userExists: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Actualiza el último login del usuario
     * @param int $user_id ID del usuario
     * @return bool True si se actualizó correctamente
     */
    public function updateLastLogin($user_id) {
        try {
            // Validar que el user_id sea un entero positivo
            $user_id = filter_var($user_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($user_id === false) {
                return false;
            }

            $sql = "UPDATE user SET last_login = NOW() WHERE id = :user_id";
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute(['user_id' => $user_id]);
        } catch (PDOException $e) {
            error_log("Error en UserModel::updateLastLogin: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtiene todos los usuarios (solo para administradores)
     * @param bool|string $filter Puede ser:
     *   - true: solo usuarios activos (por defecto)
     *   - false: todos los usuarios (activos e inactivos)
     *   - 'inactive': solo usuarios inactivos
     * @return array Arreglo estructurado con success, data/message
     */
    public function getAllUsers($filter = true, $pagination = null) {
        try {
            // Construir query según el filtro
            if ($filter === true || $filter === 'active') {
                // Solo usuarios activos
                $sql = "SELECT id, first_name, last_name, username, email, phone, avatar, role, last_login, is_active, created_at
                        FROM user
                        WHERE is_active = 1";
                $filter_type = 'active';
            } elseif ($filter === 'inactive') {
                // Solo usuarios inactivos
                $sql = "SELECT id, first_name, last_name, username, email, phone, avatar, role, last_login, is_active, created_at
                        FROM user
                        WHERE is_active = 0";
                $filter_type = 'inactive';
            } else {
                // Todos los usuarios (activos e inactivos)
                $sql = "SELECT id, first_name, last_name, username, email, phone, avatar, role, last_login, is_active, created_at
                        FROM user";
                $filter_type = 'all';
            }

            [$users, $total] = paginated_rows($this->pdo, $sql, [], 'created_at DESC, id DESC', $pagination);

            // Convertir is_active a booleano para cada usuario
            foreach ($users as &$user) {
                $user['is_active'] = (bool)$user['is_active'];
            }

            return [
                'success' => true,
                'data' => $users,
                'count' => count($users),
                'filter' => $filter_type
            ] + pagination_response($pagination, $total);
        } catch (PDOException $e) {
            error_log("Error en UserModel::getAllUsers: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al obtener la lista de usuarios'
            ];
        }
    }

    /**
     * Obtiene un usuario por email o username (incluye password para autenticación)
     * @param string $login Email o username
     * @return array Arreglo estructurado con success, data/message
     */
    public function getUserByLogin($login) {
        try {
            // Validar que el login no esté vacío
            if (empty(trim($login))) {
                return [
                    'success' => false,
                    'message' => 'El email o username no puede estar vacío'
                ];
            }

            // Especificar campos explícitamente (incluye password porque se necesita para autenticación)
            $sql = "SELECT id, username, email, password, first_name, last_name, phone, avatar, role, last_login, is_active, created_at
                    FROM user
                    WHERE (email = :email OR username = :username)
                    AND is_active = 1
                    LIMIT 1";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'email' => trim($login),
                'username' => trim($login)
            ]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                // Convertir is_active a booleano
                $user['is_active'] = (bool)$user['is_active'];

                return [
                    'success' => true,
                    'data' => $user
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Usuario no encontrado o inactivo'
                ];
            }
        } catch (PDOException $e) {
            error_log("Error en UserModel::getUserByLogin: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al buscar el usuario'
            ];
        }
    }

    /**
     * Actualiza el rol de un usuario (solo para administradores)
     * @param int $target_user_id ID del usuario cuyo rol se actualizará
     * @param string $new_role Nuevo rol a asignar
     * @return array Resultado de la actualización
     */
    public function updateUserRole($target_user_id, $new_role) {
        try {
            // Validar que el target_user_id sea un entero positivo
            $target_user_id = filter_var($target_user_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($target_user_id === false) {
                return [
                    'success' => false,
                    'message' => 'ID de usuario inválido'
                ];
            }

            // Whitelist de roles válidos (seguridad)
            $allowedRoles = ['admin', 'user'];

            // Validar que el rol sea válido
            if (!in_array($new_role, $allowedRoles, true)) {
                return [
                    'success' => false,
                    'message' => 'Rol inválido. Roles permitidos: ' . implode(', ', $allowedRoles)
                ];
            }

            // Verificar que el usuario existe
            if (!$this->userExists($target_user_id)) {
                return [
                    'success' => false,
                    'message' => 'Usuario no encontrado'
                ];
            }

            // Actualizar solo el campo role (updated_at se actualiza automáticamente por la BD)
            $sql = "UPDATE user SET role = :role WHERE id = :user_id";
            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute([
                'role' => $new_role,
                'user_id' => $target_user_id
            ]);

            if ($result) {
                return [
                    'success' => true,
                    'message' => 'Rol del usuario actualizado correctamente'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Error al actualizar el rol del usuario'
                ];
            }
        } catch (PDOException $e) {
            error_log("Error en UserModel::updateUserRole: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al actualizar el rol del usuario'
            ];
        }
    }

    /**
     * Actualiza el perfil de un usuario
     * @param int $user_id ID del usuario
     * @param array $data Datos a actualizar (solo campos permitidos)
     * @return array Resultado de la actualización
     */
    public function updateProfile($user_id, $data) {
        try {
            // Validar que el user_id sea un entero positivo
            $user_id = filter_var($user_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($user_id === false) {
                return [
                    'success' => false,
                    'message' => 'ID de usuario inválido'
                ];
            }

            // Whitelist de campos permitidos para actualización (seguridad)
            $allowedFields = [
                'first_name',
                'last_name',
                'email',
                'phone',
                'avatar'
            ];

            // Construir query dinámicamente solo con campos permitidos
            $updateFields = [];
            $params = ['user_id' => $user_id];

            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updateFields[] = "$field = :$field";
                    $params[$field] = $data[$field];
                }
            }

            if (empty($updateFields)) {
                return [
                    'success' => false,
                    'message' => 'No se proporcionaron campos válidos para actualizar'
                ];
            }

            // Agregar updated_at automáticamente
            // $updateFields[] = 'updated_at = NOW()';

            $sql = "UPDATE user SET " . implode(', ', $updateFields) . " WHERE id = :user_id";

            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute($params);

            if ($result) {
                return [
                    'success' => true,
                    'message' => 'Perfil actualizado correctamente'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Error al actualizar el perfil'
                ];
            }
        } catch (PDOException $e) {
            error_log("Error en UserModel::updateProfile: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al actualizar el perfil del usuario'
            ];
        }
    }
}
