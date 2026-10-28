<?php
require_once __DIR__ . '/../lib/pagination.php';


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
     * Crea una nueva relación empresa-usuario.
     *
     * @param array $data Datos de la relación:
     *   - empresa_id (int, requerido): ID de la empresa.
     *   - user_id (int, requerido): ID del usuario.
     *   - notas (string, opcional): Notas adicionales sobre la relación.
     *   - created_by (int, opcional): ID del usuario que realiza la acción.
     * @return array Resultado de la operación:
     *   - success (bool): Indica si la operación fue exitosa.
     *   - message (string): Mensaje descriptivo del resultado.
     *   - InsertedId (int, opcional): ID autoincremental del nuevo registro si fue exitoso.
     */
    public function create($data) {
        $response = [
            'success' => false,
            'message' => ''
        ];

        // Validación básica de entrada
        if (!isset($data['empresa_id']) || !isset($data['user_id'])) {
            $response['message'] = 'Los campos empresa_id y user_id son obligatorios';
            return $response;
        }

        // Convertir a enteros y validar que sean positivos
        $empresa_id = filter_var($data['empresa_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $user_id = filter_var($data['user_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($empresa_id === false || $user_id === false) {
            $response['message'] = 'empresa_id y user_id deben ser números enteros positivos';
            return $response;
        }

        try {
            $sql = "INSERT INTO empresa_usuario (empresa_id, user_id, notas, created_by)
                    VALUES (:empresa_id, :user_id, :notas, :created_by)";

            $stmt = $this->pdo->prepare($sql);

            // Preparar los datos con valores por defecto
            $params = [
                ':empresa_id' => $empresa_id,
                ':user_id' => $user_id,
                ':notas' => $data['notas'] ?? null,
                ':created_by' => $data['created_by'] ?? null
            ];

            $result = $stmt->execute($params);

            if ($result) {
                $response['success'] = true;
                $response['InsertedId'] = $this->pdo->lastInsertId();
                $response['message'] = 'Usuario asignado a la empresa exitosamente';
            } else {
                $response['success'] = false;
                $response['message'] = 'Error al asignar el usuario a la empresa';
            }
        } catch (PDOException $e) {
            // Para MySQL, el código de error específico está en errorInfo[1]
            $driverErrorCode = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : null;
            $response['success'] = false;

            switch ($driverErrorCode) {
                case 1452:
                    $response['message'] = 'La empresa o el usuario especificado no existe';
                    break;
                case 1062:
                    $response['message'] = 'Esta relación ya existe';
                    break;
                case 1048:
                    $response['message'] = 'Faltan campos requeridos';
                    break;
                case 1366:
                    $response['message'] = 'Datos inválidos para el campo';
                    break;
                default:
                    // SQLSTATE y código del driver solo van al log interno, nunca a la respuesta
                    $response['message'] = public_error_message('Error interno al asignar el usuario a la empresa', $e);
                    break;
            }
        }
        return $response;
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
     * Actualiza una relación empresa-usuario existente.
     * Permite modificar campos como empresa_id, user_id, notas y updated_by.
     * Nota: updated_at se actualiza automáticamente por el campo ON UPDATE CURRENT_TIMESTAMP de la BD.
     *
     * @param int $relacion_id ID de la relación a actualizar
     * @param array $data Datos actualizados: empresa_id, user_id, notas, updated_by
     * @return array Resultado de la operación:
     *   - success (bool): Indica si la operación fue exitosa.
     *   - message (string): Mensaje descriptivo del resultado.
     */
    public function update($relacion_id, $data) {
        $response = [
            'success' => false,
            'message' => ''
        ];

        // Validación más estricta: convertir y validar que sea un entero positivo
        $relacion_id = filter_var($relacion_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($relacion_id === false) {
            $response['message'] = 'ID de relación inválido';
            return $response;
        }

        // Validar que al menos uno de los campos esté presente
        $campos_modificables = ['empresa_id', 'user_id', 'notas', 'updated_by'];
        $updateFields = [];
        $params = [];

        // Procesar cada campo modificable con validación de tipos
        foreach ($campos_modificables as $campo) {
            if (isset($data[$campo])) {
                $valor = $data[$campo];

                // Validar campos que deben ser enteros positivos
                if (in_array($campo, ['empresa_id', 'user_id', 'updated_by'])) {
                    $valor_validado = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                    if ($valor_validado === false) {
                        $response['message'] = "$campo debe ser un número entero positivo";
                        return $response;
                    }
                    $updateFields[$campo] = $valor_validado;
                } else {
                    // Para notas, permitir null o string
                    $updateFields[$campo] = $valor;
                }
            }
        }

        if (empty($updateFields)) {
            $response['message'] = 'No hay campos para actualizar';
            return $response;
        }

        try {
            // Verificar si la relación existe y está activa
            $sql_check = "SELECT id FROM empresa_usuario WHERE id = :id AND activo = 1";
            $stmt_check = $this->pdo->prepare($sql_check);
            $stmt_check->execute(['id' => $relacion_id]);
            $exists = $stmt_check->fetch(PDO::FETCH_ASSOC);

            if (!$exists) {
                $response['message'] = 'Relación empresa-usuario no encontrada o ya inactiva';
                return $response;
            }

            // Construir set dinámico
            $setParts = [];
            foreach ($updateFields as $campo => $valor) {
                $setParts[] = "$campo = :$campo";
                $params[$campo] = $valor;
            }

            // No es necesario actualizar updated_at explícitamente, la BD lo hace automáticamente
            $sql = "UPDATE empresa_usuario SET " . implode(', ', $setParts) . " WHERE id = :id";
            $params['id'] = $relacion_id;

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            $response['success'] = true;
            $response['message'] = 'Relación empresa-usuario actualizada correctamente';
        } catch (PDOException $e) {
            // Manejo de errores específicos de MySQL
            $driverErrorCode = isset($e->errorInfo[1]) ? (int)$e->errorInfo[1] : null;
            switch ($driverErrorCode) {
                case 1452:
                    $response['message'] = 'La empresa o el usuario especificado no existe';
                    break;
                case 1062:
                    $response['message'] = 'Ya existe una relación con estos datos';
                    break;
                case 1048:
                    $response['message'] = 'Faltan campos requeridos';
                    break;
                case 1366:
                    $response['message'] = 'Datos inválidos para el campo';
                    break;
                default:
                    $response['message'] = public_error_message('Error al actualizar la relación empresa-usuario', $e);
                    break;
            }
        }

        return $response;
    }

    /**
     * Elimina (desactiva) una relación empresa-usuario por ID mediante soft delete.
     * Realiza un UPDATE para marcar el registro como inactivo sin eliminarlo físicamente.
     *
     * @param int $relacion_id ID de la relación en empresa_usuario
     * @param int|null $updated_by ID del usuario que realiza la acción (opcional)
     * @return array Resultado de la operación:
     *   - success (bool): Indica si la operación fue exitosa.
     *   - message (string): Mensaje descriptivo del resultado.
     */
    public function delete($relacion_id, $updated_by = null) {
        $response = [
            'success' => false,
            'message' => ''
        ];

        // Validación consistente con otras funciones del modelo
        $relacion_id = filter_var($relacion_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($relacion_id === false) {
            $response['message'] = 'ID de relación inválido';
            return $response;
        }

        try {
            // Construir SQL dinámicamente según si updated_by está presente
            if ($updated_by !== null) {
                // Validar que updated_by sea un entero positivo
                $updated_by = filter_var($updated_by, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
                if ($updated_by === false) {
                    $response['message'] = 'ID de usuario inválido';
                    return $response;
                }

                $sql = "UPDATE empresa_usuario SET activo = 0, updated_by = :updated_by
                        WHERE id = :id AND activo = 1";
                $params = [
                    'id' => $relacion_id,
                    'updated_by' => $updated_by
                ];
            } else {
                $sql = "UPDATE empresa_usuario SET activo = 0
                        WHERE id = :id AND activo = 1";
                $params = [
                    'id' => $relacion_id
                ];
            }

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);

            // Verificar si se afectó alguna fila (optimización: una sola consulta)
            if ($stmt->rowCount() > 0) {
                $response['success'] = true;
                $response['message'] = 'Relación empresa-usuario eliminada correctamente';
            } else {
                $response['message'] = 'Relación empresa-usuario no encontrada o ya inactiva';
            }
        } catch (PDOException $e) {
            $response['message'] = public_error_message('Error al eliminar relación empresa-usuario', $e);
        }

        return $response;
    }
}
