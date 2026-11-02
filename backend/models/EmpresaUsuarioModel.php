<?php
require_once __DIR__ . '/../lib/pagination.php';
require_once __DIR__ . '/../lib/write_support.php';


class EmpresaUsuarioModel {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    private function visibleScope($userId, $role, array &$params) {
        if (strtolower((string)$role) === 'admin') {
            return '';
        }
        $params['authorized_user_id'] = (int)$userId;
        return ' AND eu.user_id = :authorized_user_id';
    }

    /**
     * Otorga a un usuario la pertenencia a una empresa (UX-08A).
     *
     * Transaccional y auditada: usuario y empresa deben existir y estar activos (se bloquean con FOR UPDATE en orden
     * canónico user -> empresa); una relación vigente no se duplica (409 `duplicate`) y una retirada se REACTIVA (misma fila).
     *
     * @param array $data empresa_id, user_id (obligatorios), notas (opcional), created_by (actor).
     * @return array success, message, InsertedId; en fallo, `conflict` (duplicate | state | notfound | internal).
     */
    public function create($data) {
        $response = ['success' => false, 'message' => ''];

        if (!isset($data['empresa_id']) || !isset($data['user_id'])) {
            $response['message'] = 'Los campos empresa_id y user_id son obligatorios';
            return $response;
        }
        $empresa_id = positive_int($data['empresa_id']);
        $user_id = positive_int($data['user_id']);
        if ($empresa_id === null || $user_id === null) {
            $response['message'] = 'empresa_id y user_id deben ser números enteros positivos';
            return $response;
        }
        $notas = $data['notas'] ?? null;
        if ($notas !== null && !is_scalar($notas)) {
            $response['message'] = 'notas debe ser texto';
            return $response;
        }
        $actor = positive_int($data['created_by'] ?? null);

        return run_write(function () use ($empresa_id, $user_id, $notas, $actor) {
            return db_tx($this->pdo, function () use ($empresa_id, $user_id, $notas, $actor) {
                $this->lockParents($user_id, $empresa_id);
                $existing = $this->pdo->prepare('SELECT id, activo FROM empresa_usuario WHERE empresa_id = :e AND user_id = :u ORDER BY activo DESC, id DESC LIMIT 1');
                $existing->execute([':e' => $empresa_id, ':u' => $user_id]);
                $found = $existing->fetch(PDO::FETCH_ASSOC);
                if ($found) {
                    $before = relation_lock($this->pdo, 'empresa_usuario', (int)$found['id']);
                    if ($before && (int)$before['activo'] === 1) {
                        throw new WriteConflict('duplicate', 'Esta relación ya existe');
                    }
                    $this->pdo->prepare('UPDATE empresa_usuario SET activo = 1, updated_by = :by WHERE id = :id')->execute([':by' => $actor, ':id' => (int)$found['id']]);
                    audit_record($this->pdo, $actor, 'empresa_usuario.reactivate', 'empresa_usuario', (int)$found['id'],
                        audit_snapshot('empresa_usuario', $before), audit_snapshot('empresa_usuario', relation_lock($this->pdo, 'empresa_usuario', (int)$found['id'])));
                    return ['success' => true, 'message' => 'Usuario asignado a la empresa exitosamente', 'InsertedId' => (int)$found['id']];
                }
                try {
                    $this->pdo->prepare('INSERT INTO empresa_usuario (empresa_id, user_id, notas, created_by) VALUES (:e, :u, :n, :by)')
                        ->execute([':e' => $empresa_id, ':u' => $user_id, ':n' => $notas === null ? null : (string)$notas, ':by' => $actor]);
                } catch (PDOException $e) {
                    if (is_duplicate_key($e)) {
                        throw new WriteConflict('duplicate', 'Esta relación ya existe');
                    }
                    throw $e;
                }
                $id = (int)$this->pdo->lastInsertId();
                audit_record($this->pdo, $actor, 'empresa_usuario.create', 'empresa_usuario', $id, null,
                    audit_snapshot('empresa_usuario', relation_lock($this->pdo, 'empresa_usuario', $id)));
                return ['success' => true, 'message' => 'Usuario asignado a la empresa exitosamente', 'InsertedId' => $id];
            });
        }, 'Error al asignar el usuario a la empresa');
    }

    /** Bloquea usuario y empresa (orden canónico) y exige que existan y estén activos. */
    private function lockParents($userId, $empresaId) {
        relation_require_active($this->pdo, 'user', $userId, ['Usuario no encontrado', 'El usuario está inactivo']);
        relation_require_active($this->pdo, 'empresa', $empresaId, ['Empresa no encontrada', 'La empresa está inactiva']);
    }

    /** Reactiva una relación retirada. Exige que usuario y empresa estén activos; una relación ya activa es un conflicto de estado. */
    public function reactivate($relacion_id, $actor) {
        $id = positive_int($relacion_id);
        if ($id === null) {
            return ['success' => false, 'message' => 'ID de relación inválido'];
        }
        $actor = positive_int($actor);
        return run_write(function () use ($id, $actor) {
            // Lectura previa SIN bloqueo solo para conocer los padres y bloquearlos antes que la fila (orden canónico).
            $pre = $this->pdo->prepare('SELECT user_id, empresa_id FROM empresa_usuario WHERE id = :id');
            $pre->execute([':id' => $id]);
            $ids = $pre->fetch(PDO::FETCH_ASSOC);
            if (!$ids) {
                throw new WriteNotFound('Relación empresa-usuario no encontrada');
            }
            return db_tx($this->pdo, function () use ($id, $actor, $ids) {
                $this->lockParents((int)$ids['user_id'], (int)$ids['empresa_id']);
                $before = relation_lock($this->pdo, 'empresa_usuario', $id);
                if (!$before) {
                    throw new WriteNotFound('Relación empresa-usuario no encontrada');
                }
                if ((int)$before['user_id'] !== (int)$ids['user_id'] || (int)$before['empresa_id'] !== (int)$ids['empresa_id']) {
                    throw new WriteConflict('stale', 'La relación cambió mientras se procesaba. Recargue e intente de nuevo.');
                }
                if ((int)$before['activo'] === 1) {
                    throw new WriteConflict('state', 'La relación ya está activa');
                }
                $this->pdo->prepare('UPDATE empresa_usuario SET activo = 1, updated_by = :by WHERE id = :id')->execute([':by' => $actor, ':id' => $id]);
                audit_record($this->pdo, $actor, 'empresa_usuario.reactivate', 'empresa_usuario', $id,
                    audit_snapshot('empresa_usuario', $before), audit_snapshot('empresa_usuario', relation_lock($this->pdo, 'empresa_usuario', $id)));
                return ['success' => true, 'message' => 'Relación empresa-usuario reactivada correctamente'];
            });
        }, 'Error al reactivar la relación empresa-usuario');
    }


    /**
     * Obtiene todas las relaciones de la tabla empresa_usuario.
     * @return array Resultado con las filas encontradas o error.
     */
    public function getAll($userId, $role, $pagination = null) {
        $response = [
            'success' => false,
            'data' => [],
            'message' => ''
        ];
        try {
            $params = [];
            $scope = $this->visibleScope($userId, $role, $params);
            $sql = "SELECT
                        eu.id,
                        eu.user_id,
                        eu.empresa_id,
                        e.nombre AS empresa_nombre,
                        e.ruc AS empresa_ruc,
                        u.first_name AS usuario_nombre,
                        u.last_name AS usuario_apellido,
                        u.username AS usuario,
                        eu.notas,
                        CONCAT(uc.first_name, ' ', uc.last_name) AS created_by,
                        CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by,
                        eu.created_at,
                        eu.updated_at,
                        eu.activo
                    FROM empresa_usuario eu
                    INNER JOIN empresa e ON e.id = eu.empresa_id
                    INNER JOIN user u ON u.id = eu.user_id
                    LEFT JOIN user uc ON uc.id = eu.created_by
                    LEFT JOIN user uu ON uu.id = eu.updated_by
                    WHERE eu.activo = 1 $scope";
            [$rows, $total] = paginated_rows($this->pdo, $sql, $params, 'eu.created_at DESC, eu.id DESC', $pagination);

            // Procesar resultados: convertir activo a booleano y normalizar
            foreach ($rows as &$row) {
                $row['activo'] = (bool)$row['activo'];
            }

            $response['success'] = true;
            $response['data'] = $rows;
            $response['count'] = count($rows);
            $response += pagination_response($pagination, $total);
            $response['message'] = 'Consulta exitosa';
        } catch (PDOException $e) {
            $response['success'] = false;
            $response['message'] = public_error_message('Error al consultar las relaciones empresa-usuario', $e);
        }
        return $response;
    }

    /**
     * Obtiene una relación empresa-usuario por su ID.
     *
     * @param int $relacion_id ID de la relación empresa-usuario a buscar
     * @return array Respuesta con los datos de la relación o mensaje de error
     */
    public function getById($relacion_id, $userId, $role) {
        $response = [
            'success' => false,
            'data' => null,
            'message' => ''
        ];

        // Validación más estricta: convertir y validar que sea un entero positivo
        $relacion_id = filter_var($relacion_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($relacion_id === false) {
            $response['message'] = 'ID de relación inválido';
            return $response;
        }

        try {
            $params = ['id' => $relacion_id];
            $scope = $this->visibleScope($userId, $role, $params);
            $sql = "SELECT
                        eu.id,
                        e.nombre AS empresa_nombre,
                        e.ruc AS empresa_ruc,
                        u.first_name AS usuario_nombre,
                        u.last_name AS usuario_apellido,
                        u.username AS usuario,
                        eu.notas,
                        CONCAT(uc.first_name, ' ', uc.last_name) AS created_by,
                        CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by,
                        eu.created_at,
                        eu.updated_at,
                        eu.activo
                    FROM empresa_usuario eu
                    INNER JOIN empresa e ON e.id = eu.empresa_id
                    INNER JOIN user u ON u.id = eu.user_id
                    LEFT JOIN user uc ON uc.id = eu.created_by
                    LEFT JOIN user uu ON uu.id = eu.updated_by
                    WHERE eu.id = :id AND eu.activo = 1 $scope";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($row) {
                $row['activo'] = (bool)$row['activo'];
                $response['success'] = true;
                $response['data'] = $row;
                $response['message'] = 'Relación encontrada';
            } else {
                $response['message'] = 'Relación empresa-usuario no encontrada o inactiva';
            }
        } catch (PDOException $e) {
            $response['message'] = public_error_message('Error al consultar la relación empresa-usuario', $e);
        }

        return $response;
    }

    public function getByEmpresaRuc($empresa_ruc, $userId, $role, $pagination = null) {
        $response = [
            'success' => false,
            'data' => [],
            'message' => ''
        ];

        // Validación mejorada: trim y verificar que no esté vacío después de limpiar
        $empresa_ruc = is_string($empresa_ruc) ? trim($empresa_ruc) : '';
        if (empty($empresa_ruc)) {
            $response['message'] = 'RUC de empresa inválido';
            return $response;
        }

        try {
            $params = ['ruc' => $empresa_ruc];
            $scope = $this->visibleScope($userId, $role, $params);
            $sql = "SELECT
                        eu.id,
                        e.nombre AS empresa_nombre,
                        e.ruc AS empresa_ruc,
                        u.first_name AS usuario_nombre,
                        u.last_name AS usuario_apellido,
                        u.username AS usuario,
                        u.id AS usuario_id,
                        eu.notas,
                        CONCAT(uc.first_name, ' ', uc.last_name) AS created_by,
                        CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by,
                        eu.created_at,
                        eu.updated_at,
                        eu.activo
                    FROM empresa_usuario eu
                    INNER JOIN empresa e ON e.id = eu.empresa_id
                    INNER JOIN user u ON u.id = eu.user_id
                    LEFT JOIN user uc ON uc.id = eu.created_by
                    LEFT JOIN user uu ON uu.id = eu.updated_by
                    WHERE e.ruc = :ruc AND eu.activo = 1 $scope";
            [$rows, $total] = paginated_rows($this->pdo, $sql, $params, 'eu.created_at DESC, eu.id DESC', $pagination);

            // Procesar resultados: convertir activo a booleano para consistencia
            foreach ($rows as &$row) {
                $row['activo'] = (bool)$row['activo'];
            }

            if ($rows || ($total !== null && $total > 0)) {
                $response['success'] = true;
                $response['data'] = $rows;
                $response['count'] = count($rows);
            $response += pagination_response($pagination, $total);
                $response['message'] = 'Relación encontrada';
            } else {
                $response['message'] = 'No se encontraron relaciones activas para el RUC proporcionado';
            }
        } catch (PDOException $e) {
            $response['message'] = public_error_message('Error al consultar la(s) relación(es) empresa-usuario', $e);
        }

        return $response;
    }

    /**
     * Obtiene relaciones empresa-usuario filtrando por nombre de empresa (búsqueda parcial)
     * @param string $empresa_nombre Nombre (o parte del nombre) de la empresa a buscar
     * @return array Resultado de la consulta
     */
    public function getByEmpresaNombre($empresa_nombre, $userId, $role, $pagination = null) {
        $response = [
            'success' => false,
            'data' => [],
            'message' => ''
        ];

        // Validación mejorada: trim y verificar que no esté vacío después de limpiar
        $empresa_nombre = is_string($empresa_nombre) ? trim($empresa_nombre) : '';
        if (empty($empresa_nombre)) {
            $response['message'] = 'El nombre de la empresa es requerido';
            return $response;
        }

        try {
            $params = ['empresa_nombre' => '%' . $empresa_nombre . '%'];
            $scope = $this->visibleScope($userId, $role, $params);
            $sql = "SELECT
                        eu.id,
                        e.nombre AS empresa_nombre,
                        e.ruc AS empresa_ruc,
                        u.first_name AS usuario_nombre,
                        u.last_name AS usuario_apellido,
                        u.username AS usuario,
                        eu.notas,
                        CONCAT(uc.first_name, ' ', uc.last_name) AS created_by,
                        CONCAT(uu.first_name, ' ', uu.last_name) AS updated_by,
                        eu.created_at,
                        eu.updated_at,
                        eu.activo
                    FROM empresa_usuario eu
                    INNER JOIN empresa e ON e.id = eu.empresa_id
                    INNER JOIN user u ON u.id = eu.user_id
                    LEFT JOIN user uc ON uc.id = eu.created_by
                    LEFT JOIN user uu ON uu.id = eu.updated_by
                    WHERE e.nombre LIKE :empresa_nombre AND eu.activo = 1 $scope";
            [$rows, $total] = paginated_rows($this->pdo, $sql, $params, 'eu.created_at DESC, eu.id DESC', $pagination);

            // Procesar resultados: convertir activo a booleano para consistencia
            foreach ($rows as &$row) {
                $row['activo'] = (bool)$row['activo'];
            }

            if ($rows || ($total !== null && $total > 0)) {
                $response['success'] = true;
                $response['data'] = $rows;
                $response['count'] = count($rows);
            $response += pagination_response($pagination, $total);
                $response['message'] = 'Relaciones encontradas';
            } else {
                $response['message'] = 'No se encontraron relaciones activas para el nombre de empresa proporcionado';
            }
        } catch (PDOException $e) {
            $response['message'] = public_error_message('Error al consultar relaciones por nombre de empresa', $e);
        }

        return $response;
    }

    /**
     * Actualiza una relación empresa-usuario vigente (notas y/o extremos). Transaccional y auditada.
     * Solo se aceptan `empresa_id`, `user_id` y `notas`; cualquier otro campo se ignora. Si cambia un extremo, el nuevo
     * usuario/empresa deben existir y estar activos.
     *
     * @param int $relacion_id ID de la relación a actualizar
     * @param array $data empresa_id, user_id, notas; `updated_by` lo fija el controlador con el usuario autenticado
     */
    public function update($relacion_id, $data) {
        $response = ['success' => false, 'message' => ''];
        $relacion_id = positive_int($relacion_id);
        if ($relacion_id === null) {
            $response['message'] = 'ID de relación inválido';
            return $response;
        }
        $fields = [];
        foreach (['empresa_id', 'user_id'] as $campo) {
            if (isset($data[$campo])) {
                $valor = positive_int($data[$campo]);
                if ($valor === null) {
                    $response['message'] = "$campo debe ser un número entero positivo";
                    return $response;
                }
                $fields[$campo] = $valor;
            }
        }
        if (isset($data['notas'])) {
            if (!is_scalar($data['notas'])) {
                $response['message'] = 'notas debe ser texto';
                return $response;
            }
            $fields['notas'] = (string)$data['notas'];
        }
        if (empty($fields)) {
            $response['message'] = 'No hay campos para actualizar';
            return $response;
        }
        $actor = positive_int($data['updated_by'] ?? null);

        return run_write(function () use ($relacion_id, $fields, $actor) {
            $pre = $this->pdo->prepare('SELECT user_id, empresa_id FROM empresa_usuario WHERE id = :id');
            $pre->execute([':id' => $relacion_id]);
            $ids = $pre->fetch(PDO::FETCH_ASSOC);
            if (!$ids) {
                throw new WriteNotFound('Relación empresa-usuario no encontrada o ya inactiva');
            }
            return db_tx($this->pdo, function () use ($relacion_id, $fields, $actor, $ids) {
                $newUser = $fields['user_id'] ?? (int)$ids['user_id'];
                $newEmpresa = $fields['empresa_id'] ?? (int)$ids['empresa_id'];
                $moved = $newUser !== (int)$ids['user_id'] || $newEmpresa !== (int)$ids['empresa_id'];
                if ($moved) {
                    $this->lockParents($newUser, $newEmpresa);
                }
                $before = relation_lock($this->pdo, 'empresa_usuario', $relacion_id);
                if (!$before || (int)$before['activo'] !== 1) {
                    throw new WriteNotFound('Relación empresa-usuario no encontrada o ya inactiva');
                }
                if ((int)$before['user_id'] !== (int)$ids['user_id'] || (int)$before['empresa_id'] !== (int)$ids['empresa_id']) {
                    throw new WriteConflict('stale', 'La relación cambió mientras se procesaba. Recargue e intente de nuevo.');
                }
                $set = [];
                $params = ['id' => $relacion_id, 'by' => $actor];
                foreach ($fields as $campo => $valor) {
                    $set[] = "$campo = :$campo";
                    $params[$campo] = $valor;
                }
                $set[] = 'updated_by = :by';
                try {
                    $this->pdo->prepare('UPDATE empresa_usuario SET ' . implode(', ', $set) . ' WHERE id = :id')->execute($params);
                } catch (PDOException $e) {
                    if (is_duplicate_key($e)) {
                        throw new WriteConflict('duplicate', 'Ya existe una relación con estos datos');
                    }
                    throw $e;
                }
                audit_record($this->pdo, $actor, 'empresa_usuario.update', 'empresa_usuario', $relacion_id,
                    audit_snapshot('empresa_usuario', $before), audit_snapshot('empresa_usuario', relation_lock($this->pdo, 'empresa_usuario', $relacion_id)));
                return ['success' => true, 'message' => 'Relación empresa-usuario actualizada correctamente'];
            });
        }, 'Error al actualizar la relación empresa-usuario');
    }

    /**
     * Retira (baja lógica, `activo = 0`) una relación empresa-usuario. Nunca borra la fila ni toca otras tablas.
     * Transaccional y auditada (`empresa_usuario.revoke`). Una relación inexistente o ya retirada es `notfound`.
     */
    public function delete($relacion_id, $updated_by = null) {
        $relacion_id = positive_int($relacion_id);
        if ($relacion_id === null) {
            return ['success' => false, 'message' => 'ID de relación inválido'];
        }
        $actor = null;
        if ($updated_by !== null) {
            $actor = positive_int($updated_by);
            if ($actor === null) {
                return ['success' => false, 'message' => 'ID de usuario inválido'];
            }
        }
        return run_write(function () use ($relacion_id, $actor) {
            return db_tx($this->pdo, function () use ($relacion_id, $actor) {
                $before = relation_lock($this->pdo, 'empresa_usuario', $relacion_id);
                if (!$before || (int)$before['activo'] !== 1) {
                    throw new WriteNotFound('Relación empresa-usuario no encontrada o ya inactiva');
                }
                $this->pdo->prepare('UPDATE empresa_usuario SET activo = 0, updated_by = :by WHERE id = :id')->execute([':by' => $actor, ':id' => $relacion_id]);
                audit_record($this->pdo, $actor, 'empresa_usuario.revoke', 'empresa_usuario', $relacion_id,
                    audit_snapshot('empresa_usuario', $before), audit_snapshot('empresa_usuario', relation_lock($this->pdo, 'empresa_usuario', $relacion_id)));
                return ['success' => true, 'message' => 'Relación empresa-usuario eliminada correctamente'];
            });
        }, 'Error al eliminar relación empresa-usuario');
    }
}
