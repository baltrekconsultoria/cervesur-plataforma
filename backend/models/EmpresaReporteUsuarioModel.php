<?php
require_once __DIR__ . '/../lib/pagination.php';
require_once __DIR__ . '/../lib/write_support.php';


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
     * Otorga a un usuario acceso a un informe de una empresa (UX-08A). Transaccional y auditada.
     *
     * Reglas de compatibilidad entre relaciones intermedias (todas dentro de la transacción, con FOR UPDATE en orden
     * canónico user -> empresa -> reporte -> empresa_reporte -> empresa_usuario):
     *  - usuario existente y activo;
     *  - asignación empresa-informe existente y activa, con empresa e informe activos;
     *  - relación empresa-usuario ACTIVA entre ese usuario y esa empresa.
     * Una relación vigente no se duplica (`duplicate`); una retirada se REACTIVA (misma fila).
     *
     * @param array $data user_id, empresa_reporte_id (obligatorios), notas, created_by (actor)
     * @return array success, message, InsertedId; en fallo, `conflict` (duplicate | state | notfound | internal)
     */
    public function create($data) {
        $response = ['success' => false, 'message' => ''];

        if (!isset($data['user_id']) || !isset($data['empresa_reporte_id'])) {
            $response['message'] = 'Los campos user_id y empresa_reporte_id son obligatorios';
            return $response;
        }
        $user_id = positive_int($data['user_id']);
        $empresa_reporte_id = positive_int($data['empresa_reporte_id']);
        if ($user_id === null || $empresa_reporte_id === null) {
            $response['message'] = 'user_id y empresa_reporte_id deben ser números enteros positivos';
            return $response;
        }
        $notas = $data['notas'] ?? null;
        if ($notas !== null && !is_scalar($notas)) {
            $response['message'] = 'notas debe ser texto';
            return $response;
        }
        $actor = positive_int($data['created_by'] ?? null);

        return run_write(function () use ($user_id, $empresa_reporte_id, $notas, $actor) {
            $pre = $this->preChain($empresa_reporte_id);
            return db_tx($this->pdo, function () use ($user_id, $empresa_reporte_id, $notas, $actor, $pre) {
                $this->lockChain($user_id, $empresa_reporte_id, $pre);
                $existing = $this->pdo->prepare('SELECT id FROM user_empresa_reporte WHERE user_id = :u AND empresa_reporte_id = :er ORDER BY activo DESC, id DESC LIMIT 1');
                $existing->execute([':u' => $user_id, ':er' => $empresa_reporte_id]);
                $foundId = $existing->fetchColumn();
                if ($foundId !== false) {
                    $before = relation_lock($this->pdo, 'user_empresa_reporte', (int)$foundId);
                    if ($before && (int)$before['activo'] === 1) {
                        throw new WriteConflict('duplicate', 'La relación (user_id, empresa_reporte_id) ya existe');
                    }
                    $this->pdo->prepare('UPDATE user_empresa_reporte SET activo = 1, updated_by = :by WHERE id = :id')->execute([':by' => $actor, ':id' => (int)$foundId]);
                    audit_record($this->pdo, $actor, 'user_empresa_reporte.reactivate', 'user_empresa_reporte', (int)$foundId,
                        audit_snapshot('user_empresa_reporte', $before), audit_snapshot('user_empresa_reporte', relation_lock($this->pdo, 'user_empresa_reporte', (int)$foundId)));
                    return ['success' => true, 'message' => 'Relación usuario-reporte creada correctamente', 'InsertedId' => (int)$foundId];
                }
                try {
                    $this->pdo->prepare('INSERT INTO user_empresa_reporte (user_id, empresa_reporte_id, notas, created_by) VALUES (:u, :er, :n, :by)')
                        ->execute([':u' => $user_id, ':er' => $empresa_reporte_id, ':n' => $notas === null ? null : (string)$notas, ':by' => $actor]);
                } catch (PDOException $e) {
                    if (is_duplicate_key($e)) {
                        throw new WriteConflict('duplicate', 'La relación (user_id, empresa_reporte_id) ya existe');
                    }
                    throw $e;
                }
                $id = (int)$this->pdo->lastInsertId();
                audit_record($this->pdo, $actor, 'user_empresa_reporte.create', 'user_empresa_reporte', $id, null,
                    audit_snapshot('user_empresa_reporte', relation_lock($this->pdo, 'user_empresa_reporte', $id)));
                return ['success' => true, 'message' => 'Relación usuario-reporte creada correctamente', 'InsertedId' => $id];
            });
        }, 'Error al crear la asignación de reporte al usuario');
    }

    /** Lectura previa SIN bloqueo (fuera de la transacción) de empresa e informe de una asignación, para bloquear en orden canónico. */
    private function preChain($empresaReporteId) {
        $stmt = $this->pdo->prepare('SELECT empresa_id, reporte_id FROM empresa_reporte WHERE id = :id');
        $stmt->execute([':id' => $empresaReporteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            throw new WriteNotFound('La asignación empresa-informe no existe');
        }
        return ['empresa_id' => (int)$row['empresa_id'], 'reporte_id' => (int)$row['reporte_id']];
    }

    /**
     * Bloquea y valida la cadena de padres de un acceso individual. Debe ejecutarse dentro de la transacción y ANTES de
     * bloquear la fila de la relación. Lanza WriteNotFound (inexistente) o WriteConflict('state'|'stale').
     */
    private function lockChain($userId, $empresaReporteId, array $pre) {
        $user = relation_lock($this->pdo, 'user', $userId);
        $empresa = relation_lock($this->pdo, 'empresa', $pre['empresa_id']);
        $reporte = relation_lock($this->pdo, 'reporte', $pre['reporte_id']);
        $er = relation_lock($this->pdo, 'empresa_reporte', $empresaReporteId);
        $member = $this->pdo->prepare('SELECT id, activo FROM empresa_usuario WHERE empresa_id = :e AND user_id = :u FOR UPDATE');
        $member->execute([':e' => $pre['empresa_id'], ':u' => $userId]);
        $eu = $member->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            throw new WriteNotFound('Usuario no encontrado');
        }
        if (!relation_is_active('user', $user)) {
            throw new WriteConflict('state', 'El usuario está inactivo');
        }
        if (!$er) {
            throw new WriteNotFound('La asignación empresa-informe no existe');
        }
        if ((int)$er['empresa_id'] !== $pre['empresa_id'] || (int)$er['reporte_id'] !== $pre['reporte_id']) {
            throw new WriteConflict('stale', 'La asignación empresa-informe cambió mientras se procesaba. Recargue e intente de nuevo.');
        }
        if (!relation_is_active('empresa_reporte', $er)) {
            throw new WriteConflict('state', 'La asignación empresa-informe está inactiva');
        }
        if (!$empresa || !relation_is_active('empresa', $empresa)) {
            throw new WriteConflict('state', 'La empresa de este informe está inactiva');
        }
        if (!$reporte || !relation_is_active('reporte', $reporte)) {
            throw new WriteConflict('state', 'El informe está inactivo');
        }
        if (!$eu || (int)$eu['activo'] !== 1) {
            throw new WriteConflict('state', 'El usuario no está asignado a la empresa de este informe');
        }
    }

    /** Reactiva un acceso retirado. Exige la misma cadena vigente que otorgarlo; un acceso ya activo es un conflicto de estado. */
    public function reactivate($id, $actor) {
        $id = positive_int($id);
        if ($id === null) {
            return ['success' => false, 'message' => 'ID inválido'];
        }
        $actor = positive_int($actor);
        return run_write(function () use ($id, $actor) {
            $stmt = $this->pdo->prepare('SELECT user_id, empresa_reporte_id FROM user_empresa_reporte WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $ids = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$ids) {
                throw new WriteNotFound('Relación no encontrada');
            }
            $pre = $this->preChain((int)$ids['empresa_reporte_id']);
            return db_tx($this->pdo, function () use ($id, $actor, $ids, $pre) {
                $this->lockChain((int)$ids['user_id'], (int)$ids['empresa_reporte_id'], $pre);
                $before = relation_lock($this->pdo, 'user_empresa_reporte', $id);
                if (!$before) {
                    throw new WriteNotFound('Relación no encontrada');
                }
                if ((int)$before['user_id'] !== (int)$ids['user_id'] || (int)$before['empresa_reporte_id'] !== (int)$ids['empresa_reporte_id']) {
                    throw new WriteConflict('stale', 'La relación cambió mientras se procesaba. Recargue e intente de nuevo.');
                }
                if ((int)$before['activo'] === 1) {
                    throw new WriteConflict('state', 'La relación ya está activa');
                }
                $this->pdo->prepare('UPDATE user_empresa_reporte SET activo = 1, updated_by = :by WHERE id = :id')->execute([':by' => $actor, ':id' => $id]);
                audit_record($this->pdo, $actor, 'user_empresa_reporte.reactivate', 'user_empresa_reporte', $id,
                    audit_snapshot('user_empresa_reporte', $before), audit_snapshot('user_empresa_reporte', relation_lock($this->pdo, 'user_empresa_reporte', $id)));
                return ['success' => true, 'message' => 'Relación reactivada correctamente'];
            });
        }, 'Error al reactivar la relación usuario-reporte');
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
    public function getAll($user_id, $role, $pagination = null, $status = 'active') {
        try {
            $params = [];
            $scope = $this->visibleScope($user_id, $role, $params);
            // Vigente = acceso, relación, empresa e informe activos y usuario relacionado con la empresa.
            // `inactive` es su complemento; `all` no filtra. Solo administradores llegan aquí con un valor distinto de `active`.
            $vigente = 'eru.activo = 1' . $scope;
            $statusSql = $status === 'all' ? '1 = 1' : ($status === 'inactive' ? "NOT ($vigente)" : $vigente);

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
                WHERE $statusSql";

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
     * Actualiza un acceso individual vigente. Transaccional y auditada.
     *
     * Solo se aceptan `notas`, `user_id` y `empresa_reporte_id`; cualquier otro campo se ignora (`updated_by` lo fija el
     * controlador con el administrador autenticado). Si cambia un extremo, la nueva cadena debe ser compatible
     * (mismas reglas que otorgar el acceso).
     */
    public function update($id, $data) {
        $response = ['success' => false, 'message' => ''];
        $id = positive_int($id);
        if ($id === null) {
            $response['message'] = 'ID inválido';
            return $response;
        }
        $fields = [];
        foreach (['user_id', 'empresa_reporte_id'] as $field) {
            if (array_key_exists($field, $data)) {
                $validated = positive_int($data[$field]);
                if ($validated === null) {
                    $response['message'] = "$field debe ser un número entero positivo";
                    return $response;
                }
                $fields[$field] = $validated;
            }
        }
        if (array_key_exists('notas', $data)) {
            if ($data['notas'] !== null && !is_scalar($data['notas'])) {
                $response['message'] = 'notas debe ser texto';
                return $response;
            }
            $fields['notas'] = $data['notas'] === null ? null : (string)$data['notas'];
        }
        if (empty($fields)) {
            $response['message'] = 'No hay campos para actualizar';
            return $response;
        }
        $actor = positive_int($data['updated_by'] ?? null);

        return run_write(function () use ($id, $fields, $actor) {
            $stmt = $this->pdo->prepare('SELECT user_id, empresa_reporte_id FROM user_empresa_reporte WHERE id = :id');
            $stmt->execute([':id' => $id]);
            $ids = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$ids) {
                throw new WriteNotFound('Relación no encontrada o ya inactiva');
            }
            $newUser = $fields['user_id'] ?? (int)$ids['user_id'];
            $newEr = $fields['empresa_reporte_id'] ?? (int)$ids['empresa_reporte_id'];
            $moved = $newUser !== (int)$ids['user_id'] || $newEr !== (int)$ids['empresa_reporte_id'];
            $pre = $moved ? $this->preChain($newEr) : null;
            return db_tx($this->pdo, function () use ($id, $fields, $actor, $ids, $newUser, $newEr, $moved, $pre) {
                if ($moved) {
                    $this->lockChain($newUser, $newEr, $pre);
                }
                $before = relation_lock($this->pdo, 'user_empresa_reporte', $id);
                if (!$before || (int)$before['activo'] !== 1) {
                    throw new WriteNotFound('Relación no encontrada o ya inactiva');
                }
                if ((int)$before['user_id'] !== (int)$ids['user_id'] || (int)$before['empresa_reporte_id'] !== (int)$ids['empresa_reporte_id']) {
                    throw new WriteConflict('stale', 'La relación cambió mientras se procesaba. Recargue e intente de nuevo.');
                }
                $set = ['updated_by = :by'];
                $params = ['id' => $id, 'by' => $actor];
                foreach ($fields as $field => $value) {
                    $set[] = "$field = :$field";
                    $params[$field] = $value;
                }
                try {
                    $this->pdo->prepare('UPDATE user_empresa_reporte SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($params);
                } catch (PDOException $e) {
                    if (is_duplicate_key($e)) {
                        throw new WriteConflict('duplicate', 'Ya existe una relación con estos datos');
                    }
                    throw $e;
                }
                audit_record($this->pdo, $actor, 'user_empresa_reporte.update', 'user_empresa_reporte', $id,
                    audit_snapshot('user_empresa_reporte', $before), audit_snapshot('user_empresa_reporte', relation_lock($this->pdo, 'user_empresa_reporte', $id)));
                return ['success' => true, 'message' => 'Relación actualizada correctamente'];
            });
        }, 'Error al actualizar');
    }

    /**
     * Retira (baja lógica, `activo = 0`) un acceso individual. No borra la fila ni toca otras tablas.
     * Transaccional y auditada (`user_empresa_reporte.revoke`). Inexistente o ya retirado es `notfound`.
     */
    public function delete($id, $updated_by = null) {
        $id = positive_int($id);
        if ($id === null) {
            return ['success' => false, 'message' => 'ID inválido'];
        }
        $actor = null;
        if ($updated_by !== null) {
            $actor = positive_int($updated_by);
            if ($actor === null) {
                return ['success' => false, 'message' => 'updated_by inválido'];
            }
        }
        return run_write(function () use ($id, $actor) {
            return db_tx($this->pdo, function () use ($id, $actor) {
                $before = relation_lock($this->pdo, 'user_empresa_reporte', $id);
                if (!$before || (int)$before['activo'] !== 1) {
                    throw new WriteNotFound('Relación no encontrada o ya inactiva');
                }
                $this->pdo->prepare('UPDATE user_empresa_reporte SET activo = 0, updated_by = :by WHERE id = :id')->execute([':by' => $actor, ':id' => $id]);
                audit_record($this->pdo, $actor, 'user_empresa_reporte.revoke', 'user_empresa_reporte', $id,
                    audit_snapshot('user_empresa_reporte', $before), audit_snapshot('user_empresa_reporte', relation_lock($this->pdo, 'user_empresa_reporte', $id)));
                return ['success' => true, 'message' => 'Relación eliminada correctamente'];
            });
        }, 'Error al eliminar');
    }
}
