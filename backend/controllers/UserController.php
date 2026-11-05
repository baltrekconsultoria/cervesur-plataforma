<?php

/**
 * Controlador para operaciones de usuario
 * Maneja la lógica de negocio relacionada con usuarios
 */
require_once __DIR__ . '/../models/UserModel.php';

class UserController {
    private $userModel;

    public function __construct($pdo) {
        $this->userModel = new UserModel($pdo);
    }

    /**
     * Obtiene el perfil completo del usuario
     * @param int $user_id ID del usuario
     * @return array Respuesta con el perfil del usuario
     */
    public function getProfile($user_id) {
        try {
            // Obtener perfil completo (el modelo ya maneja la validación y errores)
            $result = $this->userModel->getProfileById($user_id);

            // Si hay error, devolver directamente la respuesta del modelo
            if (!$result['success']) {
                return $result;
            }

            // El modelo ya convierte is_active a booleano
            return [
                'success' => true,
                'profile' => $result['data']
            ];
        } catch (Exception $e) {
            // Loguear el error y devolver un mensaje genérico
            error_log('Error en UserController::getProfile: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error interno del servidor'
            ];
        }
    }

    /**
     * Obtiene datos básicos del usuario
     * @param int $user_id ID del usuario
     * @return array Respuesta con datos básicos del usuario
     */
    public function getBasicInfo($user_id) {
        try {
            // Obtener datos básicos (el modelo ya maneja validación y errores)
            $result = $this->userModel->getBasicInfoById($user_id);

            // Si hay error, devolver directamente la respuesta del modelo
            if (!$result['success']) {
                return $result;
            }

            return [
                'success' => true,
                'user' => $result['data']
            ];
        } catch (Exception $e) {
            // Loguear el error y devolver un mensaje genérico
            error_log('Error en UserController::getBasicInfo: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error interno del servidor'
            ];
        }
    }

    /**
     * Obtiene todos los usuarios (solo para administradores)
     * @param bool|string $filter Puede ser true (solo activos), false (todos), o 'inactive' (solo inactivos)
     * @return array Respuesta con la lista de usuarios
     */
    public function getAllUsers($filter = true, $pagination = null) {
        try {
            // Obtener todos los usuarios (el modelo ya maneja errores y devuelve estructura consistente)
            $result = $this->userModel->getAllUsers($filter, $pagination);

            // Si hay error, devolver directamente la respuesta del modelo
            if (!$result['success']) {
                return $result;
            }

            return [
                'success' => true,
                'users' => $result['data'],
                'total' => $result['count'],
                'filter' => $result['filter'] ?? 'active'
            ] + (isset($result['pagination']) ? ['pagination' => $result['pagination']] : []);
        } catch (Exception $e) {
            // Loguear el error y devolver un mensaje genérico
            error_log('Error en UserController::getAllUsers: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error interno del servidor'
            ];
        }
    }

    /**
     * Actualiza el rol de un usuario (solo para administradores)
     * @param int $target_user_id ID del usuario cuyo rol se actualizará
     * @param string $new_role Nuevo rol a asignar
     * @param int $current_user_id ID del usuario que realiza la acción
     * @return array Respuesta de la actualización
     */
    public function updateUserRole($target_user_id, $new_role, $current_user_id) {
        try {
            if (!is_string($new_role) || trim($new_role) === '') {
                return [
                    'success' => false,
                    'message' => 'El rol no puede estar vacío'
                ];
            }
            // El modelo valida el rol, impide cambiar el propio y degradar al último administrador, y audita en una transacción.
            return $this->userModel->updateUserRole((int)$target_user_id, trim($new_role), (int)$current_user_id);
        } catch (Exception $e) {
            // Loguear el error y devolver un mensaje genérico
            error_log('Error en UserController::updateUserRole: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error interno del servidor'
            ];
        }
    }

    /* ------------------------------------------------------------------ administración de usuarios (UX-08A.users) */

    /** GET /api/users/{id}: detalle administrativo con `version`. */
    public function adminGet($id) {
        return $this->userModel->getAdminDetail($id);
    }

    /** GET /api/users/{id}/access: accesos de solo lectura. */
    public function adminAccess($id) {
        return $this->userModel->getAccess($id);
    }

    /** PUT /api/users/{id}: solo first_name, last_name, phone (+ version); el correo NO se edita desde el panel (UX-08I) y cualquier otro campo se ignora. */
    public function adminUpdate($id, array $input, $actorId) {
        // UX-08I: la cuenta de React no puede escribir `email` (ni la base lo permite). Un correo distinto del actual se rechaza con un mensaje claro; el mismo correo se ignora.
        if (array_key_exists('email', $input)) {
            $actual = $this->userModel->getAdminDetail($id);
            if (!($actual['success'] ?? false)) { return $actual; }
            if (strtolower(trim((string)$input['email'])) !== strtolower((string)($actual['data']['email'] ?? ''))) {
                return ['success' => false, 'message' => 'El correo no se puede modificar desde este panel'];
            }
            unset($input['email']);
        }
        [$clean, $error] = UserModel::validateIdentity($input, ['first_name', 'last_name', 'phone'], []);
        if ($error !== null) {
            return ['success' => false, 'message' => $error];
        }
        if (!$clean && !array_key_exists('phone', $input)) {
            return ['success' => false, 'message' => 'No se enviaron campos para actualizar. Campos permitidos: first_name, last_name, phone'];
        }
        $version = $input['version'] ?? null;
        if ($version !== null && !is_string($version)) {
            return ['success' => false, 'message' => 'La versión no es válida'];
        }
        // `phone: null` limpia el teléfono explícitamente.
        if (array_key_exists('phone', $input) && $input['phone'] === null) {
            $clean['phone'] = null;
        }
        return $this->userModel->updateAdmin($id, $clean, $version, $actorId);
    }

    /** POST /api/users/{id}/deactivate | reactivate. */
    public function adminSetActive($id, $active, $actorId) {
        return $this->userModel->setActive($id, (bool)$active, $actorId);
    }

    /** PUT /api/users/{id}/password. */
    public function adminSetPassword($id, array $input, $actorId) {
        $error = UserModel::passwordError($input['password'] ?? null);
        if ($error !== null) {
            return ['success' => false, 'message' => $error];
        }
        return $this->userModel->setPassword($id, $input['password'], $actorId);
    }

    /**
     * Actualiza el perfil del usuario
     * @param int $user_id ID del usuario
     * @param array $data Datos a actualizar
     * @return array Respuesta de la actualización
     */
    public function updateProfile($user_id, $data) {
        try {
            // Verificar que el usuario existe
            if (!$this->userModel->userExists($user_id)) {
                return [
                    'success' => false,
                    'message' => 'Usuario no encontrado'
                ];
            }

            // Validar y limpiar campos permitidos para actualización (doble capa de seguridad)
            $allowedFields = ['first_name', 'last_name', 'email', 'phone', 'avatar'];
            $updateData = [];

            foreach ($allowedFields as $field) {
                if (isset($data[$field]) && !empty(trim($data[$field]))) {
                    $updateData[$field] = trim($data[$field]);
                }
            }

            if (empty($updateData)) {
                return [
                    'success' => false,
                    'message' => 'No hay campos válidos para actualizar. Campos permitidos: ' . implode(', ', $allowedFields)
                ];
            }

            // Actualizar en la base de datos (el modelo ya valida campos y devuelve formato estructurado)
            $result = $this->userModel->updateProfile($user_id, $updateData);

            // El modelo ahora devuelve un arreglo estructurado, devolverlo directamente
            return $result;
        } catch (Exception $e) {
            // Loguear el error y devolver un mensaje genérico
            error_log('Error en UserController::updateProfile: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error interno del servidor'
            ];
        }
    }
}
