<?php

/**
 * Controlador para la relación usuario ↔ empresa_reporte
 * Maneja la lógica de negocio para asignar reportes de empresas a usuarios
 */
require_once __DIR__ . '/../models/EmpresaReporteUsuarioModel.php';

class EmpresaReporteUsuarioController {
    private $model;

    public function __construct($pdo) {
        $this->model = new EmpresaReporteUsuarioModel($pdo);
    }

    /**
     * Obtiene todas las relaciones usuario ↔ empresa_reporte
     *
     * @return array Listado de relaciones encontradas o mensaje de error
     */
    public function getAll($user_id = null) {
        try {
            return $this->model->getAll($user_id);
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error interno: ' . $e->getMessage()];
        }
    }

    /**
     * Obtiene una relación usuario ↔ empresa_reporte por su ID
     *
     * @param int $id ID único de la relación
     * @return array Detalle de la relación encontrada o mensaje de error
     */
    public function getById($id) {
        try {
            return $this->model->getById($id);
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error interno: ' . $e->getMessage()];
        }
    }

    /**
     * Filtra relaciones usuario ↔ empresa_reporte por RUC exacto de la empresa
     *
     * @param string $ruc RUC exacto de la empresa
     * @return array Listado de relaciones encontradas o mensaje de error
     */
    public function getByEmpresaRuc($ruc) {
        try {
            return $this->model->getByEmpresaRuc($ruc);
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error interno: ' . $e->getMessage()];
        }
    }

    /**
     * Filtra relaciones usuario ↔ empresa_reporte por nombre parcial de la empresa.
     *
     * Busca coincidencias donde el nombre de la empresa contenga el texto proporcionado.
     *
     * @param string $nombre Nombre parcial o completo de la empresa a buscar.
     * @return array Listado de relaciones encontradas o mensaje de error.
     */
    public function getByEmpresaNombre($nombre) {
        try {
            return $this->model->getByEmpresaNombre($nombre);
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error interno: ' . $e->getMessage()];
        }
    }

    /**
     * Obtiene todas las relaciones usuario ↔ empresa_reporte correspondientes a un usuario específico.
     *
     * @param int $userId ID del usuario para el que se desean listar las relaciones.
     * @return array Listado de relaciones encontradas o mensaje de error en caso de fallo.
     */
    public function getByUserId($userId) {
        try {
            return $this->model->getByUserId($userId);
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error interno: ' . $e->getMessage()];
        }
    }

    /**
     * Crea una nueva relación entre usuario y empresa_reporte.
     *
     * @param array $data Datos de la relación a crear. Debe contener los campos requeridos por el modelo,
     *                    como el user_id, empresa_reporte_id, etc.
     * @param int $authUserId ID del usuario autenticado que realiza la operación (para auditoría).
     * @return array Resultado del proceso de creación:
     *               - 'success' => true/false indicando el estado,
     *               - 'data' => array con la relación creada (en caso de éxito),
     *               - 'message' => mensaje informativo o de error.
     */
    public function create($data, $authUserId) {
        try {
            if (!is_array($data)) {
                return ['success' => false, 'message' => 'Cuerpo de solicitud inválido'];
            }

            // Forzar auditoría
            $data['created_by'] = $authUserId;
            return $this->model->create($data);
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error interno: ' . $e->getMessage()];
        }
    }

    /**
     * Actualiza una relación existente usuario ↔ empresa_reporte.
     *
     * @param int $id ID de la relación a actualizar.
     * @param array $data Datos a modificar en la relación. Debe contener los campos a actualizar.
     * @param int $authUserId ID del usuario autenticado que realiza la operación (para auditoría).
     * @return array Resultado del proceso de actualización:
     *               - 'success' => true/false indicando el estado,
     *               - 'data' => array con la relación actualizada (en caso de éxito),
     *               - 'message' => mensaje informativo o de error.
     */
    public function update($id, $data, $authUserId) {
        try {
            if (!is_array($data)) {
                return ['success' => false, 'message' => 'Cuerpo de solicitud inválido'];
            }
            $data['updated_by'] = $authUserId;
            return $this->model->update($id, $data);
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error interno: ' . $e->getMessage()];
        }
    }

    /**
     * Elimina (soft delete) una relación usuario ↔ empresa_reporte por ID.
     *
     * Marca la relación como eliminada (no la borra físicamente de la base de datos) y registra el usuario que realiza la acción.
     *
     * @param int $id ID de la relación a eliminar.
     * @param int $authUserId ID del usuario autenticado que realiza la operación (para auditoría).
     * @return array Resultado del proceso de eliminación:
     *               - 'success' => true/false indicando el estado,
     *               - 'message' => mensaje informativo o de error.
     */
    public function delete($id, $authUserId) {
        try {
            return $this->model->delete($id, $authUserId);
        } catch (Exception $e) {
            return ['success' => false, 'message' => 'Error interno: ' . $e->getMessage()];
        }
    }
}
