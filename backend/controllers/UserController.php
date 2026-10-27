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
    public function getAllUsers($filter = true) {
        try {
            // Obtener todos los usuarios (el modelo ya maneja errores y devuelve estructura consistente)
            $result = $this->userModel->getAllUsers($filter);

            // Si hay error, devolver directamente la respuesta del modelo
            if (!$result['success']) {
                return $result;
            }

            return [
                'success' => true,
                'users' => $result['data'],
                'total' => $result['count'],
                'filter' => $result['filter'] ?? 'active'
            ];
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
            // Validar que el usuario no puede cambiar su propio rol
            if ($target_user_id === $current_user_id) { // Se asume que ambos vienen tipados como int
                return [
                    'success' => false,
                    'message' => 'No puedes cambiar tu propio rol'
                ];
            }

            // Validar que el nuevo rol no esté vacío
            if (empty(trim($new_role))) {
                return [
                    'success' => false,
                    'message' => 'El rol no puede estar vacío'
                ];
            }

            // Llamar al modelo para actualizar el rol
            $result = $this->userModel->updateUserRole($target_user_id, trim($new_role));

            return $result;
        } catch (Exception $e) {
            // Loguear el error y devolver un mensaje genérico
            error_log('Error en UserController::updateUserRole: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error interno del servidor'
            ];
        }
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
