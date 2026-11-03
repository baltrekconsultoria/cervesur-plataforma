<?php

/**
 * Modelo para la gestión de reportes
 * Maneja operaciones CRUD en la tabla reporte
 */
require_once __DIR__ . '/../lib/pagination.php';
require_once __DIR__ . '/../lib/status_filter.php';
require_once __DIR__ . '/../lib/write_support.php';

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
    /** Campos que forman la huella de versión (bloqueo optimista). El enlace entra en la huella, pero nunca se devuelve ni se audita en claro. */
    const VERSION_FIELDS = FORM_VERSION_FIELDS['reporte_form'];

    /** Fila actual bloqueada (`FOR UPDATE`) dentro de la transacción en curso. */
    private function lockRow($id) {
        $stmt = $this->pdo->prepare('SELECT id, nombre, descripcion, enlace, grupo_id, reporte_id, activo FROM reporte WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Huella de versión de una fila (la recibe el cliente en `view=form`). */
    public function versionOf(array $row) {
        return row_version($row, self::VERSION_FIELDS);
    }

    /**
     * Replica los UNIQUE productivos (`enlace`, `reporte_id`) dentro de la transacción: cuentan también los informes inactivos.
     * Lectura sin bloqueo (ver EmpresaModel::assertRucFree); la carrera la decide el UNIQUE real.
     */
    private function assertUnique(array $data, $excludeId) {
        foreach (['enlace' => 'Ya existe un informe con ese enlace', 'reporte_id' => 'Ya existe un informe con ese ID de Power BI'] as $column => $message) {
            $sql = "SELECT id FROM reporte WHERE $column = :v" . ($excludeId !== null ? ' AND id <> :exclude' : '') . ' LIMIT 1';
            $params = ['v' => $data[$column]] + ($excludeId !== null ? ['exclude' => $excludeId] : []);
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            if ($stmt->fetchColumn() !== false) {
                throw new WriteConflict('duplicate', $message);
            }
        }
    }

    /** Crea un informe (transacción + auditoría; enlace o referencia duplicados = conflicto, también ante una carrera). */
    public function create($data) {
        return run_write(function () use ($data) {
            return db_tx($this->pdo, function () use ($data) {
                $this->assertUnique($data, null);
                $sql = "INSERT INTO reporte (
                                nombre, descripcion, enlace,
                                grupo_id, reporte_id, created_by
                            ) VALUES (
                                :nombre, :descripcion, :enlace,
                                :grupo_id, :reporte_id, :created_by
                            )";
                $this->pdo->prepare($sql)->execute([
                    'nombre' => $data['nombre'],
                    'descripcion' => $data['descripcion'] ?? null,
                    'enlace' => $data['enlace'],
                    'grupo_id' => $data['grupo_id'] ?? null,
                    'reporte_id' => $data['reporte_id'],
                    'created_by' => $data['created_by'] ?? null
                ]);
                $id = $this->pdo->lastInsertId();
                audit_record($this->pdo, $data['created_by'] ?? 0, 'reporte.create', 'reporte', $id, null, audit_snapshot('reporte', $this->lockRow($id)));
                return [
                    'success' => true,
                    'message' => 'Reporte creado exitosamente',
                    'data' => ['id' => $id, 'reporte_id' => $data['reporte_id'], 'nombre' => $data['nombre']]
                ];
            });
        }, 'Error interno del servidor ');
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

    /** Cambia `activo` de un informe dentro de una transacción auditada. */
    private function setActive($id, $user_id, $active, $action, $successMessage) {
        return run_write(function () use ($id, $user_id, $active, $action, $successMessage) {
            return db_tx($this->pdo, function () use ($id, $user_id, $active, $action, $successMessage) {
                $before = $this->lockRow($id);
                if (!$before) {
                    throw new WriteNotFound('Reporte no encontrado');
                }
                if ((int)$before['activo'] === $active) {
                    if ($active === 1) {
                        throw new WriteConflict('state', 'El reporte ya está activo');
                    }
                    return ['success' => true, 'message' => $successMessage]; // baja idempotente (comportamiento heredado)
                }
                $this->pdo->prepare('UPDATE reporte SET activo = :a, updated_by = :u WHERE id = :id')->execute(['a' => $active, 'u' => $user_id, 'id' => $id]);
                audit_record($this->pdo, $user_id, $action, 'reporte', $id, audit_snapshot('reporte', $before), audit_snapshot('reporte', $this->lockRow($id)));
                return ['success' => true, 'message' => $successMessage];
            });
        }, 'Error al cambiar el estado del reporte');
    }

    /**
     * Eliminar un reporte (baja lógica)
     * @param int $id ID del reporte
     * @return array Resultado de la operación
     */
    public function delete($id, $updated_by) {
        return $this->setActive($id, $updated_by, 0, 'reporte.deactivate', 'Reporte eliminado exitosamente');
    }

    /** Reactiva un informe dado de baja (UX-08B). Un informe ya activo es un conflicto de estado (409). */
    public function reactivate($id, $user_id) {
        return $this->setActive($id, $user_id, 1, 'reporte.reactivate', 'Reporte reactivado exitosamente');
    }

    /**
     * Actualizar un reporte (transacción + auditoría + bloqueo optimista).
     * Si `$data['version']` viene informado, debe coincidir con la versión actual; si no, conflicto `stale` (409).
     * @param array $data Datos del reporte
     * @return array Resultado de la operación
     */
    public function update($id, $data) {
        return run_write(function () use ($id, $data) {
            return db_tx($this->pdo, function () use ($id, $data) {
                $before = $this->lockRow($id);
                if (!$before || (int)$before['activo'] !== 1) {
                    throw new WriteNotFound('Reporte no encontrado');
                }
                assert_version($data['version'] ?? null, $before, self::VERSION_FIELDS);
                $this->assertUnique($data, (int)$id);
                $sql = "UPDATE reporte SET
                        nombre = :nombre, descripcion = :descripcion, enlace = :enlace,
                        grupo_id = :grupo_id, reporte_id = :reporte_id, updated_by = :updated_by
                        WHERE id = :id AND activo = 1";
                $this->pdo->prepare($sql)->execute([
                    'nombre' => $data['nombre'],
                    'descripcion' => $data['descripcion'] ?? null,
                    'enlace' => $data['enlace'],
                    'grupo_id' => $data['grupo_id'] ?? null,
                    'reporte_id' => $data['reporte_id'],
                    'updated_by' => $data['updated_by'],
                    'id' => $id
                ]);
                audit_record($this->pdo, $data['updated_by'], 'reporte.update', 'reporte', $id, audit_snapshot('reporte', $before), audit_snapshot('reporte', $this->lockRow($id)));
                return ['success' => true, 'message' => 'Reporte actualizado exitosamente'];
            });
        }, 'Error al actualizar el reporte');
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
        return "SELECT r.id, r.nombre, r.descripcion, r.enlace, r.grupo_id, r.reporte_id, r.created_by, r.updated_by, r.created_at, r.updated_at, r.activo,
                       u.username as created_by_username
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
