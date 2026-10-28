<?php
/**
 * Controlador para asignaciones
 * Maneja la lógica de negocio para las operaciones de asignación de reportes a empresas
 */
require_once __DIR__ . '/../models/AsignacionModel.php';

class AsignacionController {
    private $model;

    public function __construct($pdo) {
        $this->model = new AsignacionModel($pdo);
    }

    /**
     * Obtener todas las asignaciones
     */
    public function getAll($userId, $role, $pagination = null) {
        try {
            $asignaciones = $this->model->getAll($userId, $role, $pagination);

            return [
                'success' => true,
                'data' => $asignaciones,
                'count' => count($asignaciones)
            ] + (isset($asignaciones['pagination']) ? ['pagination' => $asignaciones['pagination']] : []);
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => public_error_message('Error al procesar la solicitud', $e)
            ];
        }
    }

    /**
     * Obtener asignación por ID
     */
    public function getById($id, $userId, $role) {
        try {
            // Validar ID
            if (!is_numeric($id) || $id <= 0) {
                return [
                    'success' => false,
                    'message' => 'ID de asignación inválido'
                ];
            }

            $asignacion = $this->model->getById($id, $userId, $role);

            if (!$asignacion) {
                return [
                    'success' => false,
                    'message' => 'Asignación no encontrada'
                ];
            }

            return [
                'success' => true,
                'data' => $asignacion
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => public_error_message('Error al procesar la solicitud', $e)
            ];
        }
    }

    /**
     * Obtener asignaciones por empresa
     */
    public function getByEmpresa($empresa_id, $userId, $role) {
        try {
            // Validar ID de empresa
            if (!is_numeric($empresa_id) || $empresa_id <= 0) {
                return [
                    'success' => false,
                    'message' => 'ID de empresa inválido'
                ];
            }

            // Verificar si existe la empresa
            if (!$this->model->empresaExists($empresa_id)) {
                return [
                    'success' => false,
                    'message' => 'Empresa no encontrada'
                ];
            }

            $asignaciones = $this->model->getByEmpresa($empresa_id, $userId, $role);

            if (empty($asignaciones) && strtolower((string)$role) !== 'admin') {
                return ['success' => false, 'message' => 'Asignación no encontrada'];
            }

            return [
                'success' => true,
                'data' => $asignaciones,
                'count' => count($asignaciones)
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => public_error_message('Error al procesar la solicitud', $e)
            ];
        }
    }

    /**
     * Obtener asignaciones por reporte
     */
    public function getByReporte($reporte_id, $userId, $role) {
        try {
            // Validar ID de reporte
            if (!is_numeric($reporte_id) || $reporte_id <= 0) {
                return [
                    'success' => false,
                    'message' => 'ID de reporte inválido'
                ];
            }

            // Verificar si existe el reporte
            if (!$this->model->reporteExists($reporte_id)) {
                return [
                    'success' => false,
                    'message' => 'Reporte no encontrado'
                ];
            }

            $asignaciones = $this->model->getByReporte($reporte_id, $userId, $role);

            if (empty($asignaciones) && strtolower((string)$role) !== 'admin') {
                return ['success' => false, 'message' => 'Asignación no encontrada'];
            }

            return [
                'success' => true,
                'data' => $asignaciones,
                'count' => count($asignaciones)
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => public_error_message('Error al procesar la solicitud', $e)
            ];
        }
    }

    /**
     * Crear nueva asignación
     */
    public function create($data, $user_id) {
        try {
            // Validar campos requeridos
            if (empty($data['empresa_id']) || empty($data['reporte_id'])) {
                return [
                    'success' => false,
                    'message' => 'empresa_id y reporte_id son campos requeridos'
                ];
            }

            // Validar tipos de datos
            if (!is_numeric($data['empresa_id']) || $data['empresa_id'] <= 0) {
                return [
                    'success' => false,
                    'message' => 'empresa_id debe ser un número válido'
                ];
            }

            if (!is_numeric($data['reporte_id']) || $data['reporte_id'] <= 0) {
                return [
                    'success' => false,
                    'message' => 'reporte_id debe ser un número válido'
                ];
            }

            // Verificar si existe la empresa
            if (!$this->model->empresaExists($data['empresa_id'])) {
                return [
                    'success' => false,
                    'message' => 'Empresa no encontrada'
                ];
            }

            // Verificar si existe el reporte
            if (!$this->model->reporteExists($data['reporte_id'])) {
                return [
                    'success' => false,
                    'message' => 'Reporte no encontrado'
                ];
            }

            // Preparar datos para crear
            $asignacionData = [
                'empresa_id' => $data['empresa_id'],
                'reporte_id' => $data['reporte_id'],
                'notas' => $data['notas'] ?? '',
                'created_by' => $user_id
            ];

            $id = $this->model->create($asignacionData);

            return [
                'success' => true,
                'message' => 'Asignación creada exitosamente',
                'data' => [
                    'id' => $id,
                    'empresa_id' => $data['empresa_id'],
                    'reporte_id' => $data['reporte_id']
                ]
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => public_error_message('Error al procesar la solicitud', $e)
            ];
        }
    }

    /**
     * Actualizar asignación
     */
    public function update($id, $data, $user_id) {
        try {
            // Validar ID
            if (!is_numeric($id) || $id <= 0) {
                return [
                    'success' => false,
                    'message' => 'ID de asignación inválido'
                ];
            }

            // Validar campos requeridos
            if (empty($data['empresa_id']) || empty($data['reporte_id'])) {
                return [
                    'success' => false,
                    'message' => 'empresa_id y reporte_id son campos requeridos'
                ];
            }

            // Validar tipos de datos
            if (!is_numeric($data['empresa_id']) || $data['empresa_id'] <= 0) {
                return [
                    'success' => false,
                    'message' => 'empresa_id debe ser un número válido'
                ];
            }

            if (!is_numeric($data['reporte_id']) || $data['reporte_id'] <= 0) {
                return [
                    'success' => false,
                    'message' => 'reporte_id debe ser un número válido'
                ];
            }

            // Verificar si existe la empresa
            if (!$this->model->empresaExists($data['empresa_id'])) {
                return [
                    'success' => false,
                    'message' => 'Empresa no encontrada'
                ];
            }

            // Verificar si existe el reporte
            if (!$this->model->reporteExists($data['reporte_id'])) {
                return [
                    'success' => false,
                    'message' => 'Reporte no encontrado'
                ];
            }

            // Preparar datos para actualizar
            $asignacionData = [
                'empresa_id' => $data['empresa_id'],
                'reporte_id' => $data['reporte_id'],
                'notas' => $data['notas'] ?? '',
                'updated_by' => $user_id
            ];

            $updated = $this->model->update($id, $asignacionData);

            if (!$updated) {
                return [
                    'success' => false,
                    'message' => 'Asignación no encontrada o no se pudo actualizar'
                ];
            }

            return [
                'success' => true,
                'message' => 'Asignación actualizada exitosamente'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => public_error_message('Error al procesar la solicitud', $e)
            ];
        }
    }

    /**
     * Eliminar asignación
     */
    public function delete($id, $user_id) {
        try {
            // Validar ID
            if (!is_numeric($id) || $id <= 0) {
                return [
                    'success' => false,
                    'message' => 'ID de asignación inválido'
                ];
            }

            $deleted = $this->model->delete($id, $user_id);

            if (!$deleted) {
                return [
                    'success' => false,
                    'message' => 'Asignación no encontrada o no se pudo eliminar'
                ];
            }

            return [
                'success' => true,
                'message' => 'Asignación eliminada exitosamente'
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => public_error_message('Error al procesar la solicitud', $e)
            ];
        }
    }
}
