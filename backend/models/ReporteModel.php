<?php

/**
 * Modelo para la gestión de reportes
 * Maneja operaciones CRUD en la tabla reporte
 */
require_once __DIR__ . '/../lib/pagination.php';
require_once __DIR__ . '/../lib/status_filter.php';

class ReporteModel {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Crear un nuevo reporte
     * @param array $data Datos del reporte
     * @return array Resultado de la operación
     */
    public function create($data) {
        try {
            // Insertar el reporte
            $sql = "INSERT INTO reporte (
                            nombre, descripcion, enlace,
                            grupo_id, reporte_id, created_by
                        ) VALUES (
                            :nombre, :descripcion, :enlace,
                            :grupo_id, :reporte_id, :created_by
                        )";

            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute([
                'nombre' => $data['nombre'],
                'descripcion' => $data['descripcion'] ?? null,
                'enlace' => $data['enlace'],
                'grupo_id' => $data['grupo_id'] ?? null,
                'reporte_id' => $data['reporte_id'],
                'created_by' => $data['created_by'] ?? null
            ]);

            if ($result) {
                $reporte_id = $this->pdo->lastInsertId();
                return [
                    'success' => true,
                    'message' => 'Reporte creado exitosamente',
                    'data' => [
                        'id' => $reporte_id,
                        'reporte_id' => $data['reporte_id'],
                        'nombre' => $data['nombre']
                    ]
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Error al crear el reporte'
                ];
            }
        } catch (PDOException $e) {
            error_log("Error en ReporteModel::create: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error interno del servidor '
            ];
        }
    }

    /** Identificadores almacenados de un informe activo (para conservarlos al editar solo el enlace). */
    public function internalRow($id) {
        $stmt = $this->pdo->prepare('SELECT reporte_id, grupo_id FROM reporte WHERE id = :id AND activo = 1 LIMIT 1');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Longitud máxima real de la columna `enlace` en esta base (null si no se puede determinar). */
    public function linkMaxLength() {
        try {
            $stmt = $this->pdo->prepare("SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reporte' AND COLUMN_NAME = 'enlace'");
            $stmt->execute();
            $value = $stmt->fetchColumn();
            return $value === false || $value === null ? null : (int)$value;
        } catch (PDOException $e) {
            return null;
        }
    }

    /** ¿`reporte.grupo_id` admite NULL en esta base? (true/false; null si no se puede determinar). En producción es NOT NULL (UX-07D.2B). */
    public function grupoIdNullable() {
        try {
            $stmt = $this->pdo->prepare("SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reporte' AND COLUMN_NAME = 'grupo_id'");
            $stmt->execute();
            $value = $stmt->fetchColumn();
            return $value === false || $value === null ? null : strtoupper((string)$value) === 'YES';
        } catch (PDOException $e) {
            return null;
        }
    }

    /** ¿Existe un informe activo con ese id interno? */
    public function existsActive($id) {
        $stmt = $this->pdo->prepare('SELECT 1 FROM reporte WHERE id = :id AND activo = 1 LIMIT 1');
        $stmt->execute(['id' => $id]);
        return (bool)$stmt->fetchColumn();
    }

    /** ¿Otro informe activo usa ya ese UUID de Power BI? */
    public function reporteIdExists($uuid, $excludeId = null) {
        $sql = 'SELECT 1 FROM reporte WHERE reporte_id = :uuid AND activo = 1';
        $params = ['uuid' => $uuid];
        if ($excludeId !== null) {
            $sql .= ' AND id <> :exclude';
            $params['exclude'] = $excludeId;
        }
        $stmt = $this->pdo->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        return (bool)$stmt->fetchColumn();
    }

    /**
     * Eliminar un reporte
     * @param int $id ID del reporte
     * @return array Resultado de la operación
     */
    public function delete($id, $updated_by) {
        try {
            $sql = "UPDATE reporte SET
                    activo = 0,
                    updated_by = :updated_by
                    WHERE id = :id AND activo = 1";

            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute([
                'id' => $id,
                'updated_by' => $updated_by
            ]);

            if ($result) {
                return [
                    'success' => true,
                    'message' => 'Reporte eliminado exitosamente'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Error al eliminar el reporte'
                ];
            }
        } catch (PDOException $e) {
            error_log("Error en ReporteModel::delete: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al eliminar el reporte'
            ];
        }
    }

    /**
     * Actualizar un reporte
     * @param array $data Datos del reporte
     * @return array Resultado de la operación
     */
    public function update($id, $data) {
        try {
            // Actualizar el reporte
            $sql = "UPDATE reporte SET
                    nombre = :nombre, descripcion = :descripcion, enlace = :enlace,
                    grupo_id = :grupo_id, reporte_id = :reporte_id, updated_by = :updated_by
                    WHERE id = :id AND activo = 1";

            $stmt = $this->pdo->prepare($sql);

            $result = $stmt->execute([
                'nombre' => $data['nombre'],
                'descripcion' => $data['descripcion'] ?? null,
                'enlace' => $data['enlace'],
                'grupo_id' => $data['grupo_id'] ?? null,
                'reporte_id' => $data['reporte_id'],
                'updated_by' => $data['updated_by'],
                'id' => $id
            ]);

            if ($result) {
                return [
                    'success' => true,
                    'message' => 'Reporte actualizado exitosamente'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Error al actualizar el reporte'
                ];
            }
        } catch (PDOException $e) {
            error_log("Error en ReporteModel::update: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al actualizar el reporte'
            ];
        }
    }


    /**
     * Restringe las lecturas de usuarios normales a una asignación activa y
     * consistente: usuario -> empresa -> empresa/reporte -> reporte.
     */
    private function authorizedReportSql($user_id, $role, &$params) {
        if (strtolower((string)$role) === 'admin') {
            return '';
        }

        $params[':authorized_company_user_id'] = $user_id;
        $params[':authorized_report_user_id'] = $user_id;
        return " AND EXISTS (
            SELECT 1
            FROM empresa_reporte er
            INNER JOIN empresa e ON e.id = er.empresa_id AND e.activo = 1
            INNER JOIN empresa_usuario eu ON eu.empresa_id = e.id
                AND eu.user_id = :authorized_company_user_id AND eu.activo = 1
            INNER JOIN user_empresa_reporte uer ON uer.empresa_reporte_id = er.id
                AND uer.user_id = :authorized_report_user_id AND uer.activo = 1
            WHERE er.reporte_id = r.id AND er.activo = 1
        )";
    }

    private function reportBaseSql($status = 'active') {
        return "SELECT r.*, u.username as created_by_username
                FROM reporte r
                LEFT JOIN user u ON r.created_by = u.id
                WHERE " . status_sql('r.activo', $status);
    }

    /**
     * Obtener todos los reportes autorizados.
     */
    public function getAll($user_id, $role, $pagination = null, $status = 'active') {
        try {
            $params = [];
            $sql = $this->reportBaseSql($status)
                . $this->authorizedReportSql($user_id, $role, $params);

            [$reportes, $total] = paginated_rows($this->pdo, $sql, $params, 'r.created_at DESC, r.id DESC', $pagination);

            return [
                'success' => true,
                'data' => $reportes,
                'count' => count($reportes)
            ] + pagination_response($pagination, $total);
        } catch (PDOException $e) {
            error_log("Error en ReporteModel::getAll: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al obtener los reportes'
            ];
        }
    }

    /**
     * Obtener un reporte por ID
     * @param int $id ID del reporte
     * @return array Datos del reporte
     */
    public function getById($id, $user_id, $role) {
        try {
            $params = [':id' => $id];
            $sql = $this->reportBaseSql()
                . " AND r.id = :id"
                . $this->authorizedReportSql($user_id, $role, $params);

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $reporte = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($reporte) {
                return [
                    'success' => true,
                    'data' => $reporte
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Reporte no encontrado'
                ];
            }
        } catch (PDOException $e) {
            error_log("Error en ReporteModel::getById: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al obtener el reporte'
            ];
        }
    }

    /**
     * Obtener reportes por grupo_id
     * @param string $grupo_id UUID del grupo
     * @return array Lista de reportes del grupo
     */
    public function getByGrupoId($grupo_id, $user_id, $role) {
        try {
            $params = [':grupo_id' => $grupo_id];
            $sql = $this->reportBaseSql()
                . " AND r.grupo_id = :grupo_id"
                . $this->authorizedReportSql($user_id, $role, $params)
                . " ORDER BY r.created_at DESC";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $reportes = $stmt->fetchAll(PDO::FETCH_ASSOC);

            return [
                'success' => true,
                'data' => $reportes,
                'count' => count($reportes)
            ];
        } catch (PDOException $e) {
            error_log("Error en ReporteModel::getByGrupoId: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al obtener los reportes del grupo'
            ];
        }
    }

    /**
     * Obtener reportes por reporte_id
     * @param string $reporte_id UUID del reporte
     * @return array Datos del reporte
     */
    public function getByReporteId($reporte_id, $user_id, $role) {
        try {
            $params = [':reporte_id' => $reporte_id];
            $sql = $this->reportBaseSql()
                . " AND r.reporte_id = :reporte_id"
                . $this->authorizedReportSql($user_id, $role, $params);

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $reporte = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($reporte) {
                return [
                    'success' => true,
                    'data' => $reporte
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Reporte no encontrado'
                ];
            }
        } catch (PDOException $e) {
            error_log("Error en ReporteModel::getByReporteId: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al obtener el reporte'
            ];
        }
    }

    /**
     * Obtiene el total de reportes activos
     * @return int Total de reportes activos
     */
    public function getTotalCount() {
        try {
            $sql = "SELECT COUNT(*) as total FROM reporte WHERE activo = 1";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)$result['total'];
        } catch (PDOException $e) {
            error_log("Error en ReporteModel::getTotalCount: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Generar un UUID v4
     * @return string UUID generado
     */
    private function generateUUID() {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
