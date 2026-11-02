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
    public function getAll($userId, $role, $pagination = null, $status = 'active') {
        try {
            $asignaciones = $this->model->getAll($userId, $role, $pagination, $status);

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
     * Valida empresa_id / reporte_id de un cuerpo de solicitud. Devuelve [empresa_id, reporte_id] o un arreglo de fallo.
     */
    private function parseIds($data) {
        if (!is_array($data) || empty($data['empresa_id']) || empty($data['reporte_id'])) {
            return ['error' => ['success' => false, 'message' => 'empresa_id y reporte_id son campos requeridos']];
        }
        $empresaId = positive_int($data['empresa_id']);
        if ($empresaId === null) {
            return ['error' => ['success' => false, 'message' => 'empresa_id debe ser un número válido']];
        }
        $reporteId = positive_int($data['reporte_id']);
        if ($reporteId === null) {
            return ['error' => ['success' => false, 'message' => 'reporte_id debe ser un número válido']];
        }
        return [$empresaId, $reporteId];
    }

    /**
     * Crear nueva asignación (o reactivar la desvinculada de la misma pareja). La existencia y el estado de empresa e informe
     * se comprueban dentro de la transacción del modelo.
     */
    public function create($data, $user_id) {
        try {
            $ids = $this->parseIds($data);
            if (isset($ids['error'])) {
                return $ids['error'];
            }
            [$empresaId, $reporteId] = $ids;

            $result = $this->model->create([
                'empresa_id' => $empresaId,
                'reporte_id' => $reporteId,
                'notas' => $data['notas'] ?? '',
                'created_by' => $user_id
            ]);
            if (empty($result['success'])) {
                return $result;
            }
            return [
                'success' => true,
                'message' => $result['message'],
                'data' => [
                    'id' => $result['id'],
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
            if (positive_int($id) === null) {
                return ['success' => false, 'message' => 'ID de asignación inválido'];
            }
            $ids = $this->parseIds($data);
            if (isset($ids['error'])) {
                return $ids['error'];
            }
            [$empresaId, $reporteId] = $ids;

            return $this->model->update($id, [
                'empresa_id' => $empresaId,
                'reporte_id' => $reporteId,
                'notas' => $data['notas'] ?? '',
                'updated_by' => $user_id
            ]);
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => public_error_message('Error al procesar la solicitud', $e)
            ];
        }
    }

    /**
     * Eliminar (desvincular) asignación
     */
    public function delete($id, $user_id) {
        try {
            if (positive_int($id) === null) {
                return ['success' => false, 'message' => 'ID de asignación inválido'];
            }
            return $this->model->delete($id, $user_id);
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => public_error_message('Error al procesar la solicitud', $e)
            ];
        }
    }

    /**
     * Reactivar una asignación desvinculada (UX-08A)
     */
    public function reactivate($id, $user_id) {
        try {
            return $this->model->reactivate($id, $user_id);
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => public_error_message('Error al procesar la solicitud', $e)
            ];
        }
    }
}
