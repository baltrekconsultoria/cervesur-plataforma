<?php

/**
 * Controlador para la gestión de reportes
 * Maneja las operaciones CRUD de reportes
 */
require_once __DIR__ . '/../models/ReporteModel.php';
require_once __DIR__ . '/../lib/powerbi_link.php';
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
     * Valida y normaliza los datos de un informe (UX-07B/07C). Devuelve [datos, mensaje de error|null].
     *
     * Campos principales: nombre, descripcion (opcional) y enlace (Power BI). El enlace se clasifica con classify_powerbi_link():
     *  - «Publicar en web» (view?r=…): no trae identificadores de Power BI. NO se inventan: `reporte_id` conserva el valor actual
     *    (en ediciones) o recibe una referencia interna de Cervesur (UUID v4) y `grupo_id` queda NULL.
     *  - Inserción (reportEmbed) o enlace normal del informe: `reporte_id` y `grupo_id` se extraen del enlace (si están presentes y son válidos).
     * Compatibilidad: `reporte_id`/`grupo_id` siguen aceptándose como opcionales y se validan contra el enlace.
     * La identidad estable del informe es `reporte.id` (numérico); `reporte_id` es una referencia UUID única y estable para las rutas históricas.
     */
    private function validateReport($data, $excludeId = null, $current = null) {
        if (!is_array($data)) {
            return [null, 'Datos JSON inválidos'];
        }
        $nombre = trim((string)($data['nombre'] ?? ''));
        if ($nombre === '') {
            return [null, "El campo 'nombre' es obligatorio"];
        }
        if (mb_strlen($nombre) > 100) {
            return [null, 'El nombre no puede superar 100 caracteres'];
        }
        $link = classify_powerbi_link($data['enlace'] ?? null);
        if (!$link['ok']) {
            return [null, $link['error']];
        }
        $enlace = trim((string)$data['enlace']);
        $max = $this->reporteModel->linkMaxLength();
        if ($max !== null && strlen($enlace) > $max) {
            return [null, "El enlace supera el máximo que admite la base de datos ($max caracteres); aplique la migración UX-07C"];
        }

        $uuid = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';
        $explicit = [];
        foreach (['reporte_id', 'grupo_id'] as $field) {
            $value = isset($data[$field]) ? strtolower(trim((string)$data[$field])) : '';
            if ($value !== '' && !preg_match($uuid, $value)) {
                return [null, "El campo '$field' debe ser un UUID válido"];
            }
            $explicit[$field] = $value === '' ? null : $value;
        }

        if ($link['kind'] === 'public') {
            $ref = $explicit['reporte_id'] ?? ($current['reporte_id'] ?? null) ?? generate_report_ref();
            $grupo = $explicit['grupo_id'] ?? ($current ? ($current['grupo_id'] ?? null) : null);
        } else {
            if ($explicit['reporte_id'] !== null && $explicit['reporte_id'] !== $link['report_id']) {
                return [null, 'El identificador del informe indicado no coincide con el del enlace'];
            }
            if ($explicit['grupo_id'] !== null && $link['group_id'] !== null && $explicit['grupo_id'] !== $link['group_id']) {
                return [null, 'El identificador del grupo indicado no coincide con el del enlace'];
            }
            $ref = $link['report_id'];
            $grupo = $link['group_id'] ?? $explicit['grupo_id'];
        }
        if ($this->reporteModel->reporteIdExists($ref, $excludeId)) {
            return [null, 'Ya existe un informe con ese ID de Power BI'];
        }
        return [[
            'nombre' => $nombre,
            'descripcion' => isset($data['descripcion']) ? trim((string)$data['descripcion']) : null,
            'enlace' => $enlace,
            'reporte_id' => $ref,
            'grupo_id' => $grupo,
        ], null];
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
            [$data, $error] = $this->validateReport($data, (int)$id, $this->reporteModel->internalRow((int)$id));
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
     * Configuración del visor (UX-07C). Solo tras comprobar la autorización del servidor (misma regla que el detalle:
     * informe activo, asignación vigente a través de una empresa activa o administrador). Sin autorización → «no encontrado»
     * (no se distingue de un informe inexistente). El enlace solo se entrega para publicaciones web; nunca credenciales ni tokens.
     * @return array [respuesta, código HTTP]
     */
    public function getViewer($reporte_id, $user_id, $role) {
        try {
            $found = $this->reporteModel->getByReporteId($reporte_id, $user_id, $role);
            if (empty($found['success']) || empty($found['data'])) {
                return [['success' => false, 'message' => 'Reporte no encontrado'], 404];
            }
            $row = $found['data'];
            $link = classify_powerbi_link($row['enlace'] ?? null);
            $data = [
                'reporte_id' => $row['reporte_id'],
                'nombre' => $row['nombre'],
                'descripcion' => $row['descripcion'] ?? '',
                'modo' => 'no_compatible',
            ];
            if ($link['ok'] && $link['kind'] === 'public') {
                $data['modo'] = 'publico';
                $data['url'] = trim((string)$row['enlace']);
            } elseif ($link['ok']) {
                $data['modo'] = 'privado'; // inserción autenticada: requiere configuración de Microsoft (no disponible); no se entrega el enlace
            }
            return [['success' => true, 'data' => $data], 200];
        } catch (Exception $e) {
            return [['success' => false, 'message' => public_error_message('Error al obtener el visor', $e)], 500];
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
