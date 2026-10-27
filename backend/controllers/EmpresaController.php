<?php

/**
 * Controlador para manejar operaciones de empresas
 * Valida datos y coordina con el modelo
 */

require_once __DIR__ . '/../models/EmpresaModel.php';

class EmpresaController {
    private $empresaModel;

    public function __construct($pdo) {
        $this->empresaModel = new EmpresaModel($pdo);
    }

    /**
     * Obtiene todas las empresas
     * @param int $user_id ID del usuario autenticado
     * @param string $role Rol del usuario
     * @return array Respuesta JSON
     */
    public function getAll($user_id, $role) {
        try {
            $empresas = $this->empresaModel->getAll($user_id, $role);

            return [
                'success' => true,
                'data' => $empresas,
                'count' => count($empresas)
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error interno del servidor'
            ];
        }
    }

    /**
     * Obtiene una empresa específica por ID
     * @param int $id ID de la empresa
     * @param int $user_id ID del usuario autenticado
     * @param string $role Rol del usuario
     * @return array Respuesta JSON
     */
    public function getById($id, $user_id, $role) {
        try {
            $empresa = $this->empresaModel->getById($id, $user_id, $role);

            if (!$empresa) {
                return [
                    'success' => false,
                    'message' => 'Empresa no encontrada'
                ];
            }

            return [
                'success' => true,
                'data' => $empresa
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error interno del servidor'
            ];
        }
    }

    /**
     * Crea una nueva empresa
     * @param array $data Datos de la empresa
     * @param int $user_id ID del usuario autenticado
     * @return array Respuesta JSON
     */
    public function create($data, $user_id) {
        try {
            // Validar campos requeridos
            $required_fields = ['nombre', 'razon_social', 'ruc'];
            foreach ($required_fields as $field) {
                if (empty($data[$field])) {
                    return [
                        'success' => false,
                        'message' => "El campo '$field' es obligatorio"
                    ];
                }
            }

            // Validar formato de RUC (11 dígitos)
            if (!preg_match('/^\d{11}$/', $data['ruc'])) {
                return [
                    'success' => false,
                    'message' => 'El RUC debe tener 11 dígitos'
                ];
            }

            // Verificar si el RUC ya existe
            if ($this->empresaModel->rucExists($data['ruc'])) {
                return [
                    'success' => false,
                    'message' => 'El RUC ya está registrado'
                ];
            }

            // Validar email si se proporciona
            if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                return [
                    'success' => false,
                    'message' => 'El formato del email no es válido'
                ];
            }

            // Validar website si se proporciona
            if (!empty($data['website']) && !filter_var($data['website'], FILTER_VALIDATE_URL)) {
                return [
                    'success' => false,
                    'message' => 'El formato del website no es válido'
                ];
            }

            // Agregar created_by
            $data['created_by'] = $user_id;

            // Crear la empresa
            $result = $this->empresaModel->create($data);
            return $result;
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error interno del servidor'
            ];
        }
    }

    /**
     * Actualiza una empresa existente
     * @param int $id ID de la empresa
     * @param array $data Datos a actualizar
     * @param int $user_id ID del usuario autenticado
     * @param string $role Rol del usuario
     * @return array Respuesta JSON
     */
    public function update($id, $data, $user_id, $role) {
        try {
            // Validar RUC si se proporciona
            if (!empty($data['ruc'])) {
                if (!preg_match('/^\d{11}$/', $data['ruc'])) {
                    return [
                        'success' => false,
                        'message' => 'El RUC debe tener 11 dígitos'
                    ];
                }

                // Verificar si el RUC ya existe (excluyendo la empresa actual)
                if ($this->empresaModel->rucExists($data['ruc'], $id)) {
                    return [
                        'success' => false,
                        'message' => 'El RUC ya está registrado en otra empresa'
                    ];
                }
            }

            // Validar email si se proporciona
            if (!empty($data['email']) && !filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                return [
                    'success' => false,
                    'message' => 'El formato del email no es válido'
                ];
            }

            // Validar website si se proporciona
            if (!empty($data['website']) && !filter_var($data['website'], FILTER_VALIDATE_URL)) {
                return [
                    'success' => false,
                    'message' => 'El formato del website no es válido'
                ];
            }

            $result = $this->empresaModel->update($id, $data, $user_id, $role);
            return $result;
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error interno del servidor'
            ];
        }
    }

    /**
     * Elimina una empresa (soft delete)
     * @param int $id ID de la empresa
     * @param int $user_id ID del usuario autenticado
     * @param string $role Rol del usuario
     * @return array Respuesta JSON
     */
    public function delete($id, $user_id, $role) {
        try {
            $result = $this->empresaModel->delete($id, $user_id, $role);
            return $result;
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error interno del servidor'
            ];
        }
    }

    /**
     * Busca empresas por término de búsqueda
     * @param string $search Término de búsqueda
     * @param int $user_id ID del usuario autenticado
     * @param string $role Rol del usuario
     * @return array Respuesta JSON
     */
    public function search($search, $user_id, $role) {
        try {
            if (empty($search)) {
                return [
                    'success' => false,
                    'message' => 'El término de búsqueda es requerido'
                ];
            }

            $empresas = $this->empresaModel->search($search, $user_id, $role);

            return [
                'success' => true,
                'data' => $empresas,
                'count' => count($empresas),
                'search_term' => $search
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error interno del servidor'
            ];
        }
    }

    /**
     * Obtiene empresas por ciudad
     * @param string $ciudad Ciudad para filtrar
     * @param int $user_id ID del usuario autenticado
     * @param string $role Rol del usuario
     * @return array Respuesta JSON
     */
    public function getByCity($ciudad, $user_id, $role) {
        try {
            if (empty($ciudad)) {
                return [
                    'success' => false,
                    'message' => 'La ciudad es requerida'
                ];
            }

            $empresas = $this->empresaModel->getByCity($ciudad, $user_id, $role);

            return [
                'success' => true,
                'data' => $empresas,
                'count' => count($empresas),
                'ciudad' => $ciudad
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Error interno del servidor'
            ];
        }
    }
}
