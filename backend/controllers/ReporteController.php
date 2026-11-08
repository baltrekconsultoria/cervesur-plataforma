<?php

/**
 * Controlador para la gestión de reportes
 * Maneja las operaciones CRUD de reportes
 */
require_once __DIR__ . '/../models/ReporteModel.php';
class ReporteController {
    private $reporteModel;

    public function __construct($database) {
        $this->reporteModel = new ReporteModel($database);
    }

    /**
     * Crear un nuevo reporte
     * POST /api/reportes
     */
    public function create($data, $user_id) {
        try {
            // Validar campos requeridos
            $required_fields = ['nombre', 'enlace', 'enlace', 'grupo_id', 'reporte_id'];
            foreach ($required_fields as $field) {
                if (empty($data[$field])) {
                    return [
                        'success' => false,
                        'message' => "El campo '$field' es obligatorio"
                    ];
                }
            }

            // Agregar created_by
            $data['created_by'] = $user_id;

            // Crear el reporte
            $result = $this->reporteModel->create($data);

            return $result;
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => public_error_message('Error al crear el reporte', $e)
            ];
        }
    }

    /**
     * actualiza un reporte
     * POST /api/reportes/{id}
     */
    public function update($id, $data, $user_id) {
        try {
            $result = $this->reporteModel->update($id, $data, $user_id);
            return $result;
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => public_error_message('Error al actualizar el reporte', $e)
            ];
        }
    }

    /**
     * Eliminar un reporte
     * POST /api/reportes/{id}
     */
    public function delete($id, $user_id) {
        try {
            $result = $this->reporteModel->delete($id, $user_id);
            return $result;
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => public_error_message('Error al eliminar el reporte', $e)
            ];
        }
    }

    /**
     * Obtener todos los reportes
     * GET /api/reportes
     */
    public function getAll($user_id, $role) {
        try {
            $result = $this->reporteModel->getAll($user_id, $role);

            if ($result['success']) {
                http_response_code(200);
            } else {
                http_response_code(500);
            }

            echo json_encode($result);
        } catch (Exception $e) {
            error_log(public_error_message('Error en ReporteController::getAll', $e));
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Error interno del servidor'
            ]);
        }
    }

    /**
     * Obtener un reporte por ID
     * GET /api/reportes/{id}
     */
    public function getById($id, $user_id, $role) {
        try {
            if (!is_numeric($id)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'ID de reporte inválido'
                ]);
                return;
            }

            $result = $this->reporteModel->getById($id, $user_id, $role);

            if ($result['success']) {
                http_response_code(200);
            } else {
                http_response_code(404);
            }

            echo json_encode($result);
        } catch (Exception $e) {
            error_log(public_error_message('Error en ReporteController::getById', $e));
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Error interno del servidor'
            ]);
        }
    }

    /**
     * Obtener reportes por grupo_id
     * GET /api/reportes/grupoid/{uuid}
     */
    public function getByGrupoId($grupo_id, $user_id, $role) {
        try {
            if (empty($grupo_id)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Grupo ID requerido'
                ]);
                return;
            }

            $result = $this->reporteModel->getByGrupoId($grupo_id, $user_id, $role);

            if ($result['success']) {
                http_response_code(200);
            } else {
                http_response_code(500);
            }

            echo json_encode($result);
        } catch (Exception $e) {
            error_log(public_error_message('Error en ReporteController::getByGrupoId', $e));
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Error interno del servidor'
            ]);
        }
    }

    /**
     * Obtener reportes por reporte_id
     * GET /api/reportes/reporteid/{uuid}
     */
    public function getByReporteId($reporte_id, $user_id, $role) {
        try {
            if (empty($reporte_id)) {
                http_response_code(400);
                echo json_encode([
                    'success' => false,
                    'message' => 'Reporte ID requerido'
                ]);
                return;
            }

            $result = $this->reporteModel->getByReporteId($reporte_id, $user_id, $role);

            if ($result['success']) {
                http_response_code(200);
            } else {
                http_response_code(404);
            }

            echo json_encode($result);
        } catch (Exception $e) {
            error_log(public_error_message('Error en ReporteController::getByReporteId', $e));
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Error interno del servidor'
            ]);
        }
    }

    /**
     * Obtener solo reportes activos
     * GET /api/reportes/activos
     */
    public function getActivos($user_id, $role) {
        try {
            $result = $this->reporteModel->getAll($user_id, $role);

            if ($result['success']) {
                http_response_code(200);
            } else {
                http_response_code(500);
            }

            echo json_encode($result);
        } catch (Exception $e) {
            error_log(public_error_message('Error en ReporteController::getActivos', $e));
            http_response_code(500);
            echo json_encode([
                'success' => false,
                'message' => 'Error interno del servidor'
            ]);
        }
    }
}
