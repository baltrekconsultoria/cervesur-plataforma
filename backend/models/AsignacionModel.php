<?php

require_once __DIR__ . '/../lib/pagination.php';

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
    public function getAll($userId, $role, $pagination = null) {
        try {
            $params = [];
            $scope = $this->visibleScope($userId, $role, $params);
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
                    WHERE er.activo = 1 AND e.activo = 1 AND r.activo = 1 $scope";

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
            $sql = "SELECT er.*, e.nombre as empresa_nombre, r.nombre as reporte_nombre
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
            $sql = "SELECT er.*, e.nombre as empresa_nombre, r.nombre as reporte_nombre
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
            $sql = "SELECT er.*, e.nombre as empresa_nombre, r.nombre as reporte_nombre
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
     * Crear nueva asignación
     */
    public function create($data) {
        try {
            // Verificar si ya existe la asignación
            $sqlCheck = "SELECT id, activo FROM empresa_reporte
                        WHERE empresa_id = :empresa_id
                        AND reporte_id = :reporte_id
                        ORDER BY activo DESC, id DESC LIMIT 1";

            $stmtCheck = $this->pdo->prepare($sqlCheck);
            $stmtCheck->execute([
                'empresa_id' => $data['empresa_id'],
                'reporte_id' => $data['reporte_id']
            ]);

            $found = $stmtCheck->fetch(PDO::FETCH_ASSOC);
            if ($found) {
                if ((int)$found['activo'] === 1) {
                    throw new DomainException("Ya existe una asignación para esta empresa y reporte");
                }
                // UX-07B: una asignación desvinculada se reactiva en lugar de duplicarse (con clave única, el INSERT fallaría).
                $reactivate = $this->pdo->prepare('UPDATE empresa_reporte SET activo = 1, updated_by = :by, updated_at = NOW() WHERE id = :id');
                $reactivate->execute(['by' => $data['created_by'], 'id' => (int)$found['id']]);
                return (string)$found['id'];
            }

            $sql = "INSERT INTO empresa_reporte (empresa_id, reporte_id, notas, created_by, created_at)
                    VALUES (:empresa_id, :reporte_id, :notas, :created_by, NOW())";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'empresa_id' => $data['empresa_id'],
                'reporte_id' => $data['reporte_id'],
                'notas' => $data['notas'] ?? '',
                'created_by' => $data['created_by']
            ]);

            return $this->pdo->lastInsertId();
        } catch (PDOException $e) {
            throw new Exception(public_error_message('Error al crear asignación', $e));
        }
    }

    /**
     * Actualizar asignación
     */
    public function update($id, $data) {
        try {
            $sql = "UPDATE empresa_reporte SET
                    empresa_id = :empresa_id,
                    reporte_id = :reporte_id,
                    notas = :notas,
                    updated_by = :updated_by,
                    updated_at = NOW()
                    WHERE id = :id AND activo = 1";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'empresa_id' => $data['empresa_id'],
                'reporte_id' => $data['reporte_id'],
                'notas' => $data['notas'] ?? '',
                'updated_by' => $data['updated_by'],
                'id' => $id
            ]);

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            throw new Exception(public_error_message('Error al actualizar asignación', $e));
        }
    }

    /**
     * Eliminar asignación (soft delete)
     */
    public function delete($id, $deleted_by) {
        try {
            $sql = "UPDATE empresa_reporte SET
                    activo = 0,
                    updated_by = :updated_by,
                    updated_at = NOW()
                    WHERE id = :id AND activo = 1";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'updated_by' => $deleted_by,
                'id' => $id
            ]);

            return $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            throw new Exception(public_error_message('Error al eliminar asignación', $e));
        }
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
