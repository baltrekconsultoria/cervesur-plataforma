<?php
require_once __DIR__ . '/../lib/pagination.php';


/**
 * Modelo para gestionar la relación usuario ↔ empresa_reporte
 * Tabla: user_empresa_reporte
 */
class EmpresaReporteUsuarioModel {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    private function visibleScope($userId, $role, array &$params) {
        $scope = ' AND er.activo = 1 AND e.activo = 1 AND r.activo = 1'
            . ' AND EXISTS (SELECT 1 FROM empresa_usuario eu'
            . ' WHERE eu.empresa_id = er.empresa_id AND eu.user_id = eru.user_id AND eu.activo = 1)';
        if (strtolower((string)$role) !== 'admin') {
            $params['authorized_user_id'] = (int)$userId;
            $scope .= ' AND eru.user_id = :authorized_user_id';
        }
        return $scope;
    }

    /**
     * Crea una nueva relación usuario ↔ empresa_reporte.
     *
     * @param array $data Datos de la relación:
     *   - user_id (int, requerido): ID del usuario.
     *   - empresa_reporte_id (int, requerido): ID de la asignación empresa_reporte.
     *   - notas (string, opcional): Notas sobre la relación.
     *   - created_by (int, opcional): ID del usuario que crea la relación.
     * @return array Resultado de la operación:
     *   - success (bool): Indica si la operación fue exitosa.
     *   - message (string): Mensaje descriptivo del resultado.
     *   - InsertedId (int, opcional): ID autoincremental del registro si fue creado exitosamente.
     */
    public function create($data) {
        $response = [
            'success' => false,
            'message' => ''
        ];

        // Validación básica
        if (!isset($data['user_id']) || !isset($data['empresa_reporte_id'])) {
            $response['message'] = 'Los campos user_id y empresa_reporte_id son obligatorios';
            return $response;
        }

        $user_id = filter_var($data['user_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $empresa_reporte_id = filter_var($data['empresa_reporte_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($user_id === false || $empresa_reporte_id === false) {
            $response['message'] = 'user_id y empresa_reporte_id deben ser números enteros positivos';
            return $response;
        }

        try {
            // UX-07A: el acceso efectivo exige usuario activo, asignación empresa-informe vigente (empresa e informe activos)
            // y relación vigente del usuario con esa empresa. Una relación ya vigente no se duplica; una retirada se reactiva.
            $valid = $this->pdo->prepare(
                'SELECT er.empresa_id FROM empresa_reporte er'
                . ' INNER JOIN empresa e ON e.id = er.empresa_id AND e.activo = 1'
                . ' INNER JOIN reporte r ON r.id = er.reporte_id AND r.activo = 1'
                . ' WHERE er.id = :er AND er.activo = 1'
                . ' AND EXISTS (SELECT 1 FROM user u WHERE u.id = :u AND u.is_active = 1)'
            );
            $valid->execute([':er' => $empresa_reporte_id, ':u' => $user_id]);
            $empresaId = $valid->fetchColumn();
            if ($empresaId === false) {
                $response['message'] = 'El usuario o la asignación empresa-informe no existe o está inactiva';
                return $response;
            }
            $member = $this->pdo->prepare('SELECT 1 FROM empresa_usuario WHERE empresa_id = :e AND user_id = :u AND activo = 1 LIMIT 1');
            $member->execute([':e' => (int)$empresaId, ':u' => $user_id]);
            if (!$member->fetchColumn()) {
                $response['message'] = 'El usuario no está asignado a la empresa de este informe';
                return $response;
            }
            $existing = $this->pdo->prepare('SELECT id, activo FROM user_empresa_reporte WHERE user_id = :u AND empresa_reporte_id = :er ORDER BY activo DESC, id DESC LIMIT 1');
            $existing->execute([':u' => $user_id, ':er' => $empresa_reporte_id]);
            $found = $existing->fetch(PDO::FETCH_ASSOC);
            if ($found) {
                if ((int)$found['activo'] === 1) {
                    $response['message'] = 'La relación (user_id, empresa_reporte_id) ya existe';
                    return $response;
                }
                $createdBy = isset($data['created_by']) ? filter_var($data['created_by'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : null;
                $reactivate = $this->pdo->prepare('UPDATE user_empresa_reporte SET activo = 1, updated_by = :by WHERE id = :id');
                $reactivate->execute([':by' => $createdBy ?: null, ':id' => (int)$found['id']]);
                $response['success'] = true;
                $response['InsertedId'] = (int)$found['id'];
                $response['message'] = 'Relación usuario-reporte creada correctamente';
                return $response;
            }

            $sql = "INSERT INTO user_empresa_reporte (user_id, empresa_reporte_id, notas, created_by)
					VALUES (:user_id, :empresa_reporte_id, :notas, :created_by)";
            $stmt = $this->pdo->prepare($sql);
            $params = [
                ':user_id' => $user_id,
                ':empresa_reporte_id' => $empresa_reporte_id,
                ':notas' => $data['notas'] ?? null,
                ':created_by' => isset($data['created_by'])
                    ? filter_var($data['created_by'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]])
                    : null
            ];
            $stmt->execute($params);

            $response['success'] = true;
            $response['InsertedId'] = $this->pdo->lastInsertId();
            $response['message'] = 'Relación usuario-reporte creada correctamente';
        } catch (PDOException $e) {
            $driverErrorCode = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : null;
            switch ($driverErrorCode) {
                case 1062:
                    $response['message'] = 'La relación (user_id, empresa_reporte_id) ya existe';
                    break;
                case 1452:
                    $response['message'] = 'El usuario o la asignación empresa_reporte no existe';
                    break;
                case 1048:
                    $response['message'] = 'Faltan campos requeridos';
                    break;
                case 1366:
                    $response['message'] = 'Datos inválidos para el campo';
                    break;
                default:
                    $response['message'] = public_error_message('Error al crear la asignación de reporte al usuario', $e);
            }
        }
        return $response;
    }

    /**
     * Crea una nueva relación entre usuario y reporte de empresa.
     *
     * @param array $data Datos de la relación:
     *   - user_id (int, requerido): ID del usuario.
     *   - empresa_reporte_id (int, requerido): ID de la relación empresa-reporte.
     *   - notas (string, opcional): Notas adicionales sobre la relación.
     *   - created_by (int, opcional): ID del usuario que realiza la acción.
     * @return array Resultado de la operación:
     *   - success (bool): Indica si la operación fue exitosa.
     *   - message (string): Mensaje descriptivo del resultado.
     *   - InsertedId (int, opcional): ID autoincremental del nuevo registro si fue exitoso.
     */
    public function getAll($user_id, $role, $pagination = null) {
        try {
            $params = [];
            $scope = $this->visibleScope($user_id, $role, $params);

            $sql = "SELECT
					eru.id,
					eru.user_id,
					er.empresa_id,
					eru.empresa_reporte_id,
					u.username AS usuario,
					u.first_name AS usuario_nombre,
					u.last_name AS usuario_apellido,
					e.nombre AS empresa_nombre,
					e.ruc AS empresa_ruc,
					r.nombre AS reporte_nombre,
                    r.enlace AS reporte_enlace,
					r.reporte_id,
					r.grupo_id,
					eru.notas,
					CONCAT(uc.first_name, ' ', uc.last_name) AS created_by,
					CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by,
					eru.created_at,
					eru.updated_at,
					eru.activo
				FROM user_empresa_reporte eru
				INNER JOIN empresa_reporte er ON er.id = eru.empresa_reporte_id
				INNER JOIN empresa e ON e.id = er.empresa_id
				INNER JOIN reporte r ON r.id = er.reporte_id
				INNER JOIN user u ON u.id = eru.user_id
				LEFT JOIN user uc ON uc.id = eru.created_by
				LEFT JOIN user uu ON uu.id = eru.updated_by
                WHERE eru.activo = 1 $scope";

            [$rows, $total] = paginated_rows($this->pdo, $sql, $params, 'eru.created_at DESC, eru.id DESC', $pagination);
            foreach ($rows as &$row) {
                $row['activo'] = (bool)$row['activo'];
            }
            return [
                'success' => true,
                'data' => $rows,
                'count' => count($rows),
                'message' => 'Consulta exitosa',
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
     * Obtiene una relación usuario-empresa-reporte por su ID.
     *
     * @param int $id ID de la relación (user_empresa_reporte) a consultar.
     * @return array Resultado de la consulta con las siguientes claves:
     *   - success (bool): Indica si la consulta fue exitosa.
     *   - data (array|null): Datos de la relación encontrada, o null si no existe o está inactiva.
     *   - message (string): Mensaje descriptivo del resultado.
     */
    public function getById($id, $userId, $role) {
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            return ['success' => false, 'data' => null, 'message' => 'ID inválido'];
        }
        try {
            $params = ['id' => $id];
            $scope = $this->visibleScope($userId, $role, $params);
            $sql = "SELECT
					eru.id,
					u.username AS usuario,
					u.first_name AS usuario_nombre,
					u.last_name AS usuario_apellido,
					e.nombre AS empresa_nombre,
					e.ruc AS empresa_ruc,
					r.nombre AS reporte_nombre,
					r.reporte_id,
					r.grupo_id,
					eru.notas,
					CONCAT(uc.first_name, ' ', uc.last_name) AS created_by,
					CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by,
					eru.created_at,
					eru.updated_at,
					eru.activo
				FROM user_empresa_reporte eru
				INNER JOIN empresa_reporte er ON er.id = eru.empresa_reporte_id
				INNER JOIN empresa e ON e.id = er.empresa_id
				INNER JOIN reporte r ON r.id = er.reporte_id
				INNER JOIN user u ON u.id = eru.user_id
				LEFT JOIN user uc ON uc.id = eru.created_by
				LEFT JOIN user uu ON uu.id = eru.updated_by
                WHERE eru.id = :id AND eru.activo = 1 $scope";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $row['activo'] = (bool)$row['activo'];
                return ['success' => true, 'data' => $row, 'message' => 'Relación encontrada'];
            }
            return ['success' => false, 'data' => null, 'message' => 'Relación no encontrada o inactiva'];
        } catch (PDOException $e) {
            return ['success' => false, 'data' => null, 'message' => public_error_message('Error al consultar', $e)];
        }
    }

    /**
     * Obtiene todas las relaciones usuario ↔ empresa_reporte por RUC de empresa.
     *
     * @param string $ruc RUC de la empresa a buscar.
     * @return array Resultado de la consulta con las siguientes claves:
     *   - success (bool): Indica si la consulta fue exitosa.
     *   - data (array): Listado de relaciones encontradas (puede estar vacío).
     *   - count (int): Número de elementos encontrados.
     *   - message (string): Mensaje descriptivo del resultado.
     *
     * Cada elemento del array 'data' contiene:
     *   - id (int): ID de la relación.
     *   - usuario (string): Username del usuario asignado.
     *   - empresa_nombre (string): Nombre de la empresa asociada.
     *   - empresa_ruc (string): RUC de la empresa asociada.
     *   - reporte_nombre (string): Nombre del reporte asociado.
     *   - notas (string|null): Notas adicionales de la relación.
     *   - created_at (string): Fecha y hora de creación.
     *   - updated_at (string|null): Fecha y hora de última actualización.
     *   - activo (bool): Estado de la relación (true = activo).
     */
    public function getByEmpresaRuc($ruc, $userId, $role, $pagination = null) {
        $ruc = is_string($ruc) ? trim($ruc) : '';
        if ($ruc === '') {
            return ['success' => false, 'data' => [], 'message' => 'RUC inválido'];
        }
        try {
            $params = ['ruc' => $ruc];
            $scope = $this->visibleScope($userId, $role, $params);
            $sql = "SELECT
					eru.id,
					u.username AS usuario,
					e.nombre AS empresa_nombre,
					e.ruc AS empresa_ruc,
					r.nombre AS reporte_nombre,
					eru.notas,
					eru.created_at,
					eru.updated_at,
					eru.activo
				FROM user_empresa_reporte eru
				INNER JOIN empresa_reporte er ON er.id = eru.empresa_reporte_id
				INNER JOIN empresa e ON e.id = er.empresa_id
				INNER JOIN reporte r ON r.id = er.reporte_id
				INNER JOIN user u ON u.id = eru.user_id
                WHERE e.ruc = :ruc AND eru.activo = 1 $scope";
            [$rows, $total] = paginated_rows($this->pdo, $sql, $params, 'eru.created_at DESC, eru.id DESC', $pagination);
            foreach ($rows as &$row) {
                $row['activo'] = (bool)$row['activo'];
            }
            return ['success' => true, 'data' => $rows, 'count' => count($rows), 'message' => $rows ? 'Relaciones encontradas' : 'Sin resultados'] + pagination_response($pagination, $total);
        } catch (PDOException $e) {
            return ['success' => false, 'data' => [], 'message' => public_error_message('Error al consultar por RUC', $e)];
        }
    }

    /**
     * Busca relaciones usuario ↔ empresa_reporte por nombre parcial de empresa.
     *
     * @param string $nombre Nombre (o parte del nombre) de la empresa a buscar.
     * @return array Retorna un array con las siguientes claves:
     *   - success (bool): Indica si la consulta fue exitosa.
     *   - data (array): Listado de relaciones encontradas (puede estar vacío).
     *   - count (int): Número de elementos encontrados.
     *   - message (string): Mensaje descriptivo del resultado.
     *
     * Cada elemento del array 'data' contiene:
     *   - id (int): ID de la relación.
     *   - usuario (string): Username del usuario asignado.
     *   - empresa_nombre (string): Nombre de la empresa asociada.
     *   - empresa_ruc (string): RUC de la empresa asociada.
     *   - reporte_nombre (string): Nombre del reporte asociado.
     *   - notas (string|null): Notas sobre la relación (puede ser null).
     *   - created_at (string): Fecha/hora de creación.
     *   - updated_at (string|null): Fecha/hora de última modificación (puede ser null).
     *   - activo (bool): Indica si la relación está activa.
     *
     * Si ocurre algún error, retorna success=false y un mensaje descriptivo.
     */
    public function getByEmpresaNombre($nombre, $userId, $role, $pagination = null) {
        $nombre = is_string($nombre) ? trim($nombre) : '';
        if ($nombre === '') {
            return ['success' => false, 'data' => [], 'message' => 'Nombre de empresa inválido'];
        }
        try {
            $params = ['nombre' => '%' . $nombre . '%'];
            $scope = $this->visibleScope($userId, $role, $params);
            $sql = "SELECT
					eru.id,
					u.id AS usuario_id,
					u.username AS usuario,
					e.nombre AS empresa_nombre,
					e.ruc AS empresa_ruc,
					r.nombre AS reporte_nombre,
					eru.notas,
					eru.created_at,
					eru.updated_at,
					eru.activo
				FROM user_empresa_reporte eru
				INNER JOIN empresa_reporte er ON er.id = eru.empresa_reporte_id
				INNER JOIN empresa e ON e.id = er.empresa_id
				INNER JOIN reporte r ON r.id = er.reporte_id
				INNER JOIN user u ON u.id = eru.user_id
                WHERE e.nombre LIKE :nombre AND eru.activo = 1 $scope";
            [$rows, $total] = paginated_rows($this->pdo, $sql, $params, 'eru.created_at DESC, eru.id DESC', $pagination);
            foreach ($rows as &$row) {
                $row['activo'] = (bool)$row['activo'];
            }
            return ['success' => true, 'data' => $rows, 'count' => count($rows), 'message' => $rows ? 'Relaciones encontradas' : 'Sin resultados'] + pagination_response($pagination, $total);
        } catch (PDOException $e) {
            return ['success' => false, 'data' => [], 'message' => public_error_message('Error al consultar por nombre', $e)];
        }
    }

    /**
     * Obtiene todas las relaciones usuario ↔ empresa_reporte asociadas a un user_id específico.
     *
     * @param int $userId ID del usuario del cual se desean listar las relaciones.
     * @return array Retorna un array con las siguientes claves:
     *   - success (bool): Indica si la consulta fue exitosa.
     *   - data (array): Listado de relaciones encontradas (puede estar vacío).
     *   - count (int): Número de elementos encontrados en el listado.
     *   - message (string): Mensaje descriptivo del proceso.
     *
     * Cada elemento del array 'data' contiene:
     *   - id (int): ID de la relación.
     *   - empresa_nombre (string): Nombre de la empresa asociada.
     *   - reporte_nombre (string): Nombre del reporte asociado.
     *   - reporte_id (int): ID del reporte.
     *   - grupo_id (int): ID del grupo.
     *   - notas (string|null): Notas adicionales sobre la relación.
     *   - created_at (string): Fecha de creación.
     *   - updated_at (string|null): Fecha de última actualización.
     *   - activo (bool): Indica si la relación está activa.
     */
    public function getByUserId($userId, $requestUserId, $role, $pagination = null) {
        $userId = filter_var($userId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($userId === false) {
            return ['success' => false, 'data' => [], 'message' => 'user_id inválido'];
        }
        try {
            $params = ['user_id' => $userId];
            $scope = $this->visibleScope($requestUserId, $role, $params);
            $sql = "SELECT
					eru.id,
					e.nombre AS empresa_nombre,
					r.nombre AS reporte_nombre,
					r.reporte_id,
					r.grupo_id,
					eru.notas,
					eru.created_at,
					eru.updated_at,
					eru.activo
				FROM user_empresa_reporte eru
				INNER JOIN empresa_reporte er ON er.id = eru.empresa_reporte_id
				INNER JOIN empresa e ON e.id = er.empresa_id
				INNER JOIN reporte r ON r.id = er.reporte_id
                WHERE eru.user_id = :user_id AND eru.activo = 1 $scope";
            [$rows, $total] = paginated_rows($this->pdo, $sql, $params, 'eru.created_at DESC, eru.id DESC', $pagination);
            foreach ($rows as &$row) {
                $row['activo'] = (bool)$row['activo'];
            }
            return ['success' => true, 'data' => $rows, 'count' => count($rows), 'message' => 'Consulta exitosa'] + pagination_response($pagination, $total);
        } catch (PDOException $e) {
            return ['success' => false, 'data' => [], 'message' => public_error_message('Error al consultar por usuario', $e)];
        }
    }

    /**
     * Actualiza una relación usuario ↔ empresa_reporte.
     *
     * Permite actualizar los campos: notas, user_id, empresa_reporte_id y updated_by de un registro existente
     * de la tabla user_empresa_reporte, siempre que el registro esté activo.
     *
     * @param int $id ID de la relación a actualizar.
     * @param array $data Datos para actualizar. Puede incluir:
     *   - notas (string, opcional): Nueva nota de la relación.
     *   - user_id (int, opcional): Nuevo ID de usuario.
     *   - empresa_reporte_id (int, opcional): Nuevo ID de empresa_reporte.
     *   - updated_by (int, opcional): ID del usuario que realiza la actualización.
     * @return array Resultado de la operación:
     *   - success (bool): Indica si la actualización fue exitosa.
     *   - message (string): Mensaje descriptivo del resultado.
     */
    public function update($id, $data) {
        $response = ['success' => false, 'message' => ''];
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            $response['message'] = 'ID inválido';
            return $response;
        }

        $allowed = ['notas', 'user_id', 'empresa_reporte_id', 'updated_by'];
        $updateFields = [];
        $params = [];
        foreach ($allowed as $field) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            $value = $data[$field];
            if (in_array($field, ['user_id', 'empresa_reporte_id', 'updated_by'])) {
                $validated = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($validated === false) {
                    $response['message'] = "$field debe ser un número entero positivo";
                    return $response;
                }
                $updateFields[$field] = $validated;
            } else {
                $updateFields[$field] = $value; // notas
            }
        }
        if (empty($updateFields)) {
            $response['message'] = 'No hay campos para actualizar';
            return $response;
        }

        try {
            // Verificar existencia activa
            $exists = $this->pdo->prepare('SELECT id FROM user_empresa_reporte WHERE id = :id AND activo = 1');
            $exists->execute(['id' => $id]);
            if (!$exists->fetch(PDO::FETCH_ASSOC)) {
                $response['message'] = 'Relación no encontrada o ya inactiva';
                return $response;
            }

            $setParts = [];
            foreach ($updateFields as $campo => $valor) {
                $setParts[] = "$campo = :$campo";
                $params[$campo] = $valor;
            }
            $params['id'] = $id;
            $sql = 'UPDATE user_empresa_reporte SET ' . implode(', ', $setParts) . ' WHERE id = :id';
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            $response['success'] = true;
            $response['message'] = 'Relación actualizada correctamente';
        } catch (PDOException $e) {
            $driverErrorCode = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : null;
            switch ($driverErrorCode) {
                case 1062:
                    $response['message'] = 'Ya existe una relación con estos datos';
                    break;
                case 1452:
                    $response['message'] = 'Usuario o asignación empresa_reporte no existe';
                    break;
                default:
                    $response['message'] = public_error_message('Error al actualizar', $e);
            }
        }
        return $response;
    }

    /**
     * Elimina lógicamente (soft delete) una relación usuario ↔ empresa_reporte.
     *
     * Marca el registro como inactivo (activo = 0) en la tabla user_empresa_reporte.
     * Si se proporciona $updated_by, se registra el ID del usuario que realiza la eliminación.
     *
     * @param int $id ID de la relación a eliminar.
     * @param int|null $updated_by (opcional) ID del usuario que elimina la relación.
     * @return array Resultado de la operación:
     *   - success (bool): Indica si la operación fue exitosa.
     *   - message (string): Mensaje descriptivo del resultado.
     */

    public function delete($id, $updated_by = null) {
        $response = ['success' => false, 'message' => ''];
        $id = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) {
            $response['message'] = 'ID inválido';
            return $response;
        }
        $params = ['id' => $id];
        try {
            if ($updated_by !== null) {
                $updated_by = filter_var($updated_by, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($updated_by === false) {
                    $response['message'] = 'updated_by inválido';
                    return $response;
                }
                $sql = 'UPDATE user_empresa_reporte SET activo = 0, updated_by = :updated_by WHERE id = :id AND activo = 1';
                $params['updated_by'] = $updated_by;
            } else {
                $sql = 'UPDATE user_empresa_reporte SET activo = 0 WHERE id = :id AND activo = 1';
            }
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            if ($stmt->rowCount() > 0) {
                $response['success'] = true;
                $response['message'] = 'Relación eliminada correctamente';
            } else {
                $response['message'] = 'Relación no encontrada o ya inactiva';
            }
        } catch (PDOException $e) {
            $response['message'] = public_error_message('Error al eliminar', $e);
        }
        return $response;
    }
}
