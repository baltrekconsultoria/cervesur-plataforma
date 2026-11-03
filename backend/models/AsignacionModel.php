<?php

require_once __DIR__ . '/../lib/pagination.php';
require_once __DIR__ . '/../lib/write_support.php';
require_once __DIR__ . '/../lib/status_filter.php';

/**
 * Modelo para la tabla empresa_reporte
 * Maneja las operaciones de base de datos para asignaciones de reportes a empresas
 */

class AsignacionModel {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    private function visibleScope($userId, $role, array &$params) {
        if (strtolower((string)$role) === 'admin') {
            return '';
        }
        $params['authorized_assignment_user_id'] = (int)$userId;
        return ' AND EXISTS (SELECT 1 FROM user_empresa_reporte eru'
            . ' INNER JOIN empresa_usuario eu ON eu.empresa_id = er.empresa_id'
            . ' AND eu.user_id = eru.user_id AND eu.activo = 1'
            . ' WHERE eru.empresa_reporte_id = er.id AND eru.user_id = :authorized_assignment_user_id'
            . ' AND eru.activo = 1)';
    }

    /**
     * Obtener todas las asignaciones activas
     */
    public function getAll($userId, $role, $pagination = null, $status = 'active') {
        try {
            $params = [];
            $scope = $this->visibleScope($userId, $role, $params);
            // Vigente = relación, empresa e informe activos. `inactive` es su complemento; `all` no filtra.
            $vigente = 'er.activo = 1 AND e.activo = 1 AND r.activo = 1';
            $statusSql = $status === 'all' ? '1 = 1' : ($status === 'inactive' ? "NOT ($vigente)" : $vigente);
            $sql = "SELECT
                        er.id,
                        er.empresa_id,
                        e.nombre as empresa_nombre,
                        er.reporte_id,
                        r.nombre as reporte_nombre,
                        er.notas,
                        CONCAT(uc.first_name, ' ', uc.last_name) AS created_by,
                        CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by,
                        er.created_at,
                        er.updated_at,
                        er.activo
                    FROM empresa_reporte er
                    INNER JOIN empresa e ON e.id = er.empresa_id
                    INNER JOIN reporte r ON r.id = er.reporte_id
                    LEFT JOIN user uc ON uc.id = er.created_by
                    LEFT JOIN user uu ON uu.id = er.updated_by
                    WHERE $statusSql $scope";

            [$rows, $total] = paginated_rows($this->pdo, $sql, $params, 'er.created_at DESC, er.id DESC', $pagination);
            foreach ($rows as &$row) {
                $row['activo'] = (bool)$row['activo'];
            }
            return [
                'success' => true,
                'data' => $rows,
                'count' => count($rows),
                'message' => 'Consulta exitosa'
            ] + pagination_response($pagination, $total);
        } catch (PDOException $e) {
            return [
                'success' => false,
                'data' => [],
                'message' => public_error_message('Error al listar relaciones', $e)
            ];
        }
    }

    /**
     * Obtener asignación por ID
     */
    public function getById($id, $userId, $role) {
        try {
            $params = ['id' => $id];
            $scope = $this->visibleScope($userId, $role, $params);
            $sql = "SELECT er.id, er.empresa_id, er.reporte_id, er.notas, er.created_by, er.updated_by, er.created_at, er.updated_at, er.activo, e.nombre as empresa_nombre, r.nombre as reporte_nombre
                    FROM empresa_reporte er
                    INNER JOIN empresa e ON er.empresa_id = e.id
                    INNER JOIN reporte r ON er.reporte_id = r.id
                    WHERE er.id = :id AND er.activo = 1 AND e.activo = 1 AND r.activo = 1 $scope";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new Exception(public_error_message('Error al obtener asignación', $e));
        }
    }

    /**
     * Obtener asignaciones por empresa
     */
    public function getByEmpresa($empresa_id, $userId, $role) {
        try {
            $params = ['empresa_id' => $empresa_id];
            $scope = $this->visibleScope($userId, $role, $params);
            $sql = "SELECT er.id, er.empresa_id, er.reporte_id, er.notas, er.created_by, er.updated_by, er.created_at, er.updated_at, er.activo, e.nombre as empresa_nombre, r.nombre as reporte_nombre
                    FROM empresa_reporte er
                    INNER JOIN empresa e ON er.empresa_id = e.id
                    INNER JOIN reporte r ON er.reporte_id = r.id
                    WHERE er.empresa_id = :empresa_id AND er.activo = 1 AND e.activo = 1 AND r.activo = 1 $scope
                    ORDER BY er.created_at DESC";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new Exception(public_error_message('Error al obtener asignaciones por empresa', $e));
        }
    }

    /**
     * Obtener asignaciones por reporte
     */
    public function getByReporte($reporte_id, $userId, $role) {
        try {
            $params = ['reporte_id' => $reporte_id];
            $scope = $this->visibleScope($userId, $role, $params);
            $sql = "SELECT er.id, er.empresa_id, er.reporte_id, er.notas, er.created_by, er.updated_by, er.created_at, er.updated_at, er.activo, e.nombre as empresa_nombre, r.nombre as reporte_nombre
                    FROM empresa_reporte er
                    INNER JOIN empresa e ON er.empresa_id = e.id
                    INNER JOIN reporte r ON er.reporte_id = r.id
                    WHERE er.reporte_id = :reporte_id AND er.activo = 1 AND e.activo = 1 AND r.activo = 1 $scope
                    ORDER BY er.created_at DESC";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            throw new Exception(public_error_message('Error al obtener asignaciones por reporte', $e));
        }
    }

    /**
     * Asocia un informe a una empresa (UX-08A). Transaccional y auditada.
     *
     * Empresa e informe deben existir y estar activos (se bloquean en orden canónico empresa -> reporte). Una asociación
     * vigente no se duplica (`duplicate`); una desvinculada se REACTIVA (misma fila, nunca un duplicado).
     *
     * @param array $data empresa_id, reporte_id, notas, created_by
     * @return array success, message, id (cadena, como siempre); en fallo, `conflict`.
     */
    public function create($data) {
        $empresaId = positive_int($data['empresa_id'] ?? null);
        $reporteId = positive_int($data['reporte_id'] ?? null);
        if ($empresaId === null || $reporteId === null) {
            return ['success' => false, 'message' => 'empresa_id y reporte_id deben ser números enteros positivos'];
        }
        $notas = isset($data['notas']) && is_scalar($data['notas']) ? (string)$data['notas'] : '';
        $actor = positive_int($data['created_by'] ?? null);

        return run_write(function () use ($empresaId, $reporteId, $notas, $actor) {
            return db_tx($this->pdo, function () use ($empresaId, $reporteId, $notas, $actor) {
                $this->lockParents($empresaId, $reporteId);
                $existing = $this->pdo->prepare('SELECT id FROM empresa_reporte WHERE empresa_id = :e AND reporte_id = :r ORDER BY activo DESC, id DESC LIMIT 1');
                $existing->execute([':e' => $empresaId, ':r' => $reporteId]);
                $foundId = $existing->fetchColumn();
                if ($foundId !== false) {
                    $before = relation_lock($this->pdo, 'empresa_reporte', (int)$foundId);
                    if ($before && (int)$before['activo'] === 1) {
                        throw new WriteConflict('duplicate', 'Ya existe una asignación para esta empresa y reporte');
                    }
                    $this->pdo->prepare('UPDATE empresa_reporte SET activo = 1, updated_by = :by, updated_at = NOW() WHERE id = :id')->execute([':by' => $actor, ':id' => (int)$foundId]);
                    audit_record($this->pdo, $actor, 'empresa_reporte.reactivate', 'empresa_reporte', (int)$foundId,
                        audit_snapshot('empresa_reporte', $before), audit_snapshot('empresa_reporte', relation_lock($this->pdo, 'empresa_reporte', (int)$foundId)));
                    return ['success' => true, 'message' => 'Asignación creada exitosamente', 'id' => (string)$foundId];
                }
                try {
                    $this->pdo->prepare('INSERT INTO empresa_reporte (empresa_id, reporte_id, notas, created_by, created_at) VALUES (:e, :r, :n, :by, NOW())')
                        ->execute([':e' => $empresaId, ':r' => $reporteId, ':n' => $notas, ':by' => $actor]);
                } catch (PDOException $e) {
                    if (is_duplicate_key($e)) {
                        throw new WriteConflict('duplicate', 'Ya existe una asignación para esta empresa y reporte');
                    }
                    throw $e;
                }
                $id = (int)$this->pdo->lastInsertId();
                audit_record($this->pdo, $actor, 'empresa_reporte.create', 'empresa_reporte', $id, null,
                    audit_snapshot('empresa_reporte', relation_lock($this->pdo, 'empresa_reporte', $id)));
                return ['success' => true, 'message' => 'Asignación creada exitosamente', 'id' => (string)$id];
            });
        }, 'Error al crear asignación');
    }

    /** Bloquea empresa e informe (orden canónico) y exige que existan y estén activos. */
    private function lockParents($empresaId, $reporteId) {
        relation_require_active($this->pdo, 'empresa', $empresaId, ['Empresa no encontrada', 'La empresa está inactiva']);
        relation_require_active($this->pdo, 'reporte', $reporteId, ['Reporte no encontrado', 'El reporte está inactivo']);
    }

    /** Reactiva una asociación desvinculada. Exige empresa e informe activos; una asociación ya activa es un conflicto de estado. */
    public function reactivate($id, $actor) {
        $id = positive_int($id);
        if ($id === null) {
            return ['success' => false, 'message' => 'ID de asignación inválido'];
        }
        $actor = positive_int($actor);
        return run_write(function () use ($id, $actor) {
            $pre = $this->pdo->prepare('SELECT empresa_id, reporte_id FROM empresa_reporte WHERE id = :id');
            $pre->execute([':id' => $id]);
            $ids = $pre->fetch(PDO::FETCH_ASSOC);
            if (!$ids) {
                throw new WriteNotFound('Asignación no encontrada');
            }
            return db_tx($this->pdo, function () use ($id, $actor, $ids) {
                $this->lockParents((int)$ids['empresa_id'], (int)$ids['reporte_id']);
                $before = relation_lock($this->pdo, 'empresa_reporte', $id);
                if (!$before) {
                    throw new WriteNotFound('Asignación no encontrada');
                }
                if ((int)$before['empresa_id'] !== (int)$ids['empresa_id'] || (int)$before['reporte_id'] !== (int)$ids['reporte_id']) {
                    throw new WriteConflict('stale', 'La asignación cambió mientras se procesaba. Recargue e intente de nuevo.');
                }
                if ((int)$before['activo'] === 1) {
                    throw new WriteConflict('state', 'La asignación ya está activa');
                }
                $this->pdo->prepare('UPDATE empresa_reporte SET activo = 1, updated_by = :by, updated_at = NOW() WHERE id = :id')->execute([':by' => $actor, ':id' => $id]);
                audit_record($this->pdo, $actor, 'empresa_reporte.reactivate', 'empresa_reporte', $id,
                    audit_snapshot('empresa_reporte', $before), audit_snapshot('empresa_reporte', relation_lock($this->pdo, 'empresa_reporte', $id)));
                return ['success' => true, 'message' => 'Asignación reactivada exitosamente'];
            });
        }, 'Error al reactivar la asignación');
    }

    /**
     * Actualizar asignación vigente (empresa, informe y notas). Transaccional y auditada; los nuevos extremos deben estar activos.
     */
    public function update($id, $data) {
        $id = positive_int($id);
        $empresaId = positive_int($data['empresa_id'] ?? null);
        $reporteId = positive_int($data['reporte_id'] ?? null);
        if ($id === null || $empresaId === null || $reporteId === null) {
            return ['success' => false, 'message' => 'Datos de asignación inválidos'];
        }
        $notas = isset($data['notas']) && is_scalar($data['notas']) ? (string)$data['notas'] : '';
        $actor = positive_int($data['updated_by'] ?? null);

        return run_write(function () use ($id, $empresaId, $reporteId, $notas, $actor) {
            $pre = $this->pdo->prepare('SELECT empresa_id, reporte_id FROM empresa_reporte WHERE id = :id');
            $pre->execute([':id' => $id]);
            $ids = $pre->fetch(PDO::FETCH_ASSOC);
            if (!$ids) {
                throw new WriteNotFound('Asignación no encontrada o no se pudo actualizar');
            }
            return db_tx($this->pdo, function () use ($id, $empresaId, $reporteId, $notas, $actor, $ids) {
                if ($empresaId !== (int)$ids['empresa_id'] || $reporteId !== (int)$ids['reporte_id']) {
                    $this->lockParents($empresaId, $reporteId);
                }
                $before = relation_lock($this->pdo, 'empresa_reporte', $id);
                if (!$before || (int)$before['activo'] !== 1) {
                    throw new WriteNotFound('Asignación no encontrada o no se pudo actualizar');
                }
                if ((int)$before['empresa_id'] !== (int)$ids['empresa_id'] || (int)$before['reporte_id'] !== (int)$ids['reporte_id']) {
                    throw new WriteConflict('stale', 'La asignación cambió mientras se procesaba. Recargue e intente de nuevo.');
                }
                try {
                    $this->pdo->prepare('UPDATE empresa_reporte SET empresa_id = :e, reporte_id = :r, notas = :n, updated_by = :by, updated_at = NOW() WHERE id = :id')
                        ->execute([':e' => $empresaId, ':r' => $reporteId, ':n' => $notas, ':by' => $actor, ':id' => $id]);
                } catch (PDOException $e) {
                    if (is_duplicate_key($e)) {
                        throw new WriteConflict('duplicate', 'Ya existe una asignación para esta empresa y reporte');
                    }
                    throw $e;
                }
                audit_record($this->pdo, $actor, 'empresa_reporte.update', 'empresa_reporte', $id,
                    audit_snapshot('empresa_reporte', $before), audit_snapshot('empresa_reporte', relation_lock($this->pdo, 'empresa_reporte', $id)));
                return ['success' => true, 'message' => 'Asignación actualizada exitosamente'];
            });
        }, 'Error al actualizar asignación');
    }

    /**
     * Desvincula (baja lógica, `activo = 0`) una asignación empresa-informe. Nunca borra la fila ni toca otras tablas.
     * Transaccional y auditada (`empresa_reporte.revoke`). Inexistente o ya desvinculada es `notfound`.
     */
    public function delete($id, $deleted_by) {
        $id = positive_int($id);
        if ($id === null) {
            return ['success' => false, 'message' => 'ID de asignación inválido'];
        }
        $actor = positive_int($deleted_by);
        return run_write(function () use ($id, $actor) {
            return db_tx($this->pdo, function () use ($id, $actor) {
                $before = relation_lock($this->pdo, 'empresa_reporte', $id);
                if (!$before || (int)$before['activo'] !== 1) {
                    throw new WriteNotFound('Asignación no encontrada o no se pudo eliminar');
                }
                $this->pdo->prepare('UPDATE empresa_reporte SET activo = 0, updated_by = :by, updated_at = NOW() WHERE id = :id')->execute([':by' => $actor, ':id' => $id]);
                audit_record($this->pdo, $actor, 'empresa_reporte.revoke', 'empresa_reporte', $id,
                    audit_snapshot('empresa_reporte', $before), audit_snapshot('empresa_reporte', relation_lock($this->pdo, 'empresa_reporte', $id)));
                return ['success' => true, 'message' => 'Asignación eliminada exitosamente'];
            });
        }, 'Error al eliminar asignación');
    }


    /**
     * Verificar si existe la empresa
     */
    public function empresaExists($empresa_id) {
        try {
            $sql = "SELECT id FROM empresa WHERE id = :id AND activo = 1";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['id' => $empresa_id]);

            return $stmt->fetch() !== false;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Verificar si existe el reporte
     */
    public function reporteExists($reporte_id) {
        try {
            $sql = "SELECT id FROM reporte WHERE id = :id AND activo = 1";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['id' => $reporte_id]);

            return $stmt->fetch() !== false;
        } catch (PDOException $e) {
            return false;
        }
    }
}
