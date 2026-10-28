<?php

/**
 * Controlador para manejar la relación empresa-usuario
 * Orquesta validaciones de entrada y delega al modelo
 */

require_once __DIR__ . '/../models/EmpresaUsuarioModel.php';

class EmpresaUsuarioController {
    private $empresaUsuarioModel;

    public function __construct($pdo) {
        $this->empresaUsuarioModel = new EmpresaUsuarioModel($pdo);
    }

    /**
     * Asigna un usuario a una empresa
     * @param array $data Datos de asignación: empresa_id, user_id, notas (opcional)
     * @param int $authUserId Usuario autenticado que realiza la acción
     * @return array Respuesta del proceso
     */
    public function assignUserToEmpresa($data, $authUserId) {
        try {
            // Validar que el cuerpo de la solicitud sea un array
            if (!is_array($data)) {
                return [
                    'success' => false,
                    'message' => 'El cuerpo de la solicitud debe ser un objeto JSON'
                ];
            }

            // Validar que el usuario autenticado sea válido
            $authUserId = filter_var($authUserId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($authUserId === false) {
                return [
                    'success' => false,
                    'message' => 'Usuario autenticado inválido'
                ];
            }

            // Validar que los campos requeridos estén presentes
            if (!isset($data['empresa_id']) || !isset($data['user_id'])) {
                return [
                    'success' => false,
                    'message' => 'Los campos empresa_id y user_id son obligatorios'
                ];
            }

            // Validar que empresa_id sea un número entero positivo
            $empresa_id = filter_var($data['empresa_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($empresa_id === false) {
                return [
                    'success' => false,
                    'message' => 'empresa_id debe ser un número entero positivo'
                ];
            }

            // Validar que user_id sea un número entero positivo
            $user_id = filter_var($data['user_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($user_id === false) {
                return [
                    'success' => false,
                    'message' => 'user_id debe ser un número entero positivo'
                ];
            }

            // Normalizar y preparar el payload con datos validados
            $payload = [
                'empresa_id' => $empresa_id,
                'user_id' => $user_id,
                'notas' => $data['notas'] ?? null,
                'created_by' => $authUserId
            ];

            // Delegar al modelo, que realiza validaciones adicionales de existencia
            $result = $this->empresaUsuarioModel->create($payload);
            return $result;
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => public_error_message('Error al asignar usuario a empresa', $e)
            ];
        }
    }

    /**
     * Lista todas las asignaciones activas empresa-usuario
     * @return array Respuesta con el listado (máximo 20 registros)
     */
    public function getAll($userId, $role, $pagination = null) {
        try {
            $result = $this->empresaUsuarioModel->getAll($userId, $role, $pagination);
            return $result;
        } catch (Exception $e) {
            return [
                'success' => false,
                'data' => [],
                'message' => public_error_message('Error al listar asignaciones empresa-usuario', $e)
            ];
        }
    }

    /**
     * Obtiene una relación empresa-usuario por ID
     * @param int $relacionId ID de la relación a buscar
     * @return array Respuesta con los datos de la relación o mensaje de error
     */
    public function getById($relacionId, $userId, $role) {
        try {
            // Validar que el ID sea un número entero positivo
            // Esto mantiene consistencia con otras funciones del controlador como assignUserToEmpresa
            $relacionId = filter_var($relacionId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($relacionId === false) {
                return [
                    'success' => false,
                    'data' => null,
                    'message' => 'ID de relación inválido'
                ];
            }

            // Delegar al modelo, que realizará la consulta y validaciones adicionales
            $result = $this->empresaUsuarioModel->getById($relacionId, $userId, $role);
            return $result;
        } catch (Exception $e) {
            return [
                'success' => false,
                'data' => null,
                'message' => public_error_message('Error al obtener relación empresa-usuario', $e)
            ];
        }
    }

    /**
     * Lista relaciones empresa-usuario por RUC de la empresa
     * @param string $empresaRuc RUC de la empresa a buscar
     * @return array Respuesta con las relaciones encontradas o mensaje de error
     */
    public function getByEmpresaRuc($empresaRuc, $userId, $role, $pagination = null) {
        try {
            // Validar que el RUC sea un string válido y no esté vacío después de limpiar espacios
            // Esto mantiene consistencia con otras funciones del controlador como getById
            $empresaRuc = is_string($empresaRuc) ? trim($empresaRuc) : '';
            if (empty($empresaRuc)) {
                return [
                    'success' => false,
                    'data' => [],
                    'message' => 'RUC de empresa inválido'
                ];
            }

            // Delegar al modelo, que realizará la consulta y validaciones adicionales
            $result = $this->empresaUsuarioModel->getByEmpresaRuc($empresaRuc, $userId, $role, $pagination);
            return $result;
        } catch (Exception $e) {
            return [
                'success' => false,
                'data' => [],
                'message' => public_error_message('Error al obtener relaciones por RUC', $e)
            ];
        }
    }

    /**
     * Lista relaciones empresa-usuario por nombre (parcial) de la empresa
     * @param string $empresaNombre Nombre o parte del nombre de la empresa a buscar
     * @return array Respuesta con las relaciones encontradas o mensaje de error
     */
    public function getByEmpresaNombre($empresaNombre, $userId, $role, $pagination = null) {
        try {
            // Validar que el nombre sea un string válido y no esté vacío después de limpiar espacios
            // Esto mantiene consistencia con otras funciones del controlador como getByEmpresaRuc
            $empresaNombre = is_string($empresaNombre) ? trim($empresaNombre) : '';
            if (empty($empresaNombre)) {
                return [
                    'success' => false,
                    'data' => [],
                    'message' => 'Nombre de empresa inválido'
                ];
            }

            // Delegar al modelo, que realizará la consulta y validaciones adicionales
            $result = $this->empresaUsuarioModel->getByEmpresaNombre($empresaNombre, $userId, $role, $pagination);
            return $result;
        } catch (Exception $e) {
            return [
                'success' => false,
                'data' => [],
                'message' => public_error_message('Error al obtener relaciones por nombre de empresa', $e)
            ];
        }
    }

    /**
     * Actualiza una relación empresa-usuario
     * @param int $relacionId
     * @param array $data
     * @param int|null $authUserId
     * @return array
     */
    public function update($relacionId, $data, $authUserId = null) {
        try {
            // Validar que el ID de relación sea un número entero positivo
            // Esto mantiene consistencia con otras funciones del controlador como getById
            $relacionId = filter_var($relacionId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($relacionId === false) {
                return [
                    'success' => false,
                    'message' => 'ID de relación inválido'
                ];
            }

            // Validar que el cuerpo de la solicitud sea un array
            if (!is_array($data)) {
                return [
                    'success' => false,
                    'message' => 'El cuerpo de la solicitud debe ser un objeto JSON'
                ];
            }

            // Validar y normalizar el usuario autenticado si está presente
            if ($authUserId !== null) {
                $authUserId = filter_var($authUserId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($authUserId === false) {
                    return [
                        'success' => false,
                        'message' => 'Usuario autenticado inválido'
                    ];
                }
                $data['updated_by'] = $authUserId;
            }

            // Delegar al modelo, que realizará validaciones adicionales
            $result = $this->empresaUsuarioModel->update($relacionId, $data);
            return $result;
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => public_error_message('Error al actualizar relación empresa-usuario', $e)
            ];
        }
    }

    /**
     * Elimina (desactiva) una relación empresa-usuario por ID
     * @param int $relacionId
     * @param int|null $authUserId
     * @return array
     */
    public function delete($relacionId, $authUserId = null) {
        try {
            // Validar que el ID de relación sea un número entero positivo
            // Esto mantiene consistencia con otras funciones del controlador como update y getById
            $relacionId = filter_var($relacionId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($relacionId === false) {
                return [
                    'success' => false,
                    'message' => 'ID de relación inválido'
                ];
            }

            // Validar y normalizar el usuario autenticado si está presente
            if ($authUserId !== null) {
                $authUserId = filter_var($authUserId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($authUserId === false) {
                    return [
                        'success' => false,
                        'message' => 'Usuario autenticado inválido'
                    ];
                }
            }

            // Delegar al modelo, que realizará validaciones adicionales
            $result = $this->empresaUsuarioModel->delete($relacionId, $authUserId);
            return $result;
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => public_error_message('Error al eliminar relación empresa-usuario', $e)
            ];
        }
    }
}
