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
    /**
     * Valida y normaliza los datos de un informe (UX-07B). Devuelve [datos, mensaje de error|null].
     * `reporte_id` y `grupo_id` son UUID de Power BI: se guardan en minúsculas, como los espera la ruta /reporteid/{uuid}.
     */
    private function validateReport($data, $excludeId = null) {
        if (!is_array($data)) {
            return [null, 'Datos JSON inválidos'];
        }
        foreach (['nombre', 'enlace', 'grupo_id', 'reporte_id'] as $field) {
            if (!isset($data[$field]) || trim((string)$data[$field]) === '') {
                return [null, "El campo '$field' es obligatorio"];
            }
        }
        $data['nombre'] = trim((string)$data['nombre']);
        $data['enlace'] = trim((string)$data['enlace']);
        if (mb_strlen($data['nombre']) > 100) {
            return [null, 'El nombre no puede superar 100 caracteres'];
        }
        if (strlen($data['enlace']) > 255 || !filter_var($data['enlace'], FILTER_VALIDATE_URL)) {
            return [null, 'El enlace debe ser una URL válida de hasta 255 caracteres'];
        }
        foreach (['grupo_id', 'reporte_id'] as $field) {
            $value = strtolower(trim((string)$data[$field]));
            if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $value)) {
                return [null, "El campo '$field' debe ser un UUID válido"];
            }
            $data[$field] = $value;
        }
        if ($this->reporteModel->reporteIdExists($data['reporte_id'], $excludeId)) {
            return [null, 'Ya existe un informe con ese ID de Power BI'];
        }
        return [$data, null];
    }

    public function create($data, $user_id) {
        try {
            [$data, $error] = $this->validateReport($data);
            if ($error !== null) {
                return ['success' => false, 'message' => $error];
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
            if (!ctype_digit((string)$id) || (int)$id < 1) {
                return ['success' => false, 'message' => 'ID de reporte inválido'];
            }
            if (!$this->reporteModel->existsActive((int)$id)) {
                return ['success' => false, 'message' => 'Reporte no encontrado'];
            }
            [$data, $error] = $this->validateReport($data, (int)$id);
            if ($error !== null) {
                return ['success' => false, 'message' => $error];
            }
            // El modelo exige `updated_by`: sin esto quedaba siempre NULL.
            $data['updated_by'] = $user_id;
            $result = $this->reporteModel->update($id, $data);
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
    public function getAll($user_id, $role, $pagination = null) {
        try {
            $result = $this->reporteModel->getAll($user_id, $role, $pagination);

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
    public function getActivos($user_id, $role, $pagination = null) {
        try {
            $result = $this->reporteModel->getAll($user_id, $role, $pagination);

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
