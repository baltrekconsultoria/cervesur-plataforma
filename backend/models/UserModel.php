<?php

require_once __DIR__ . '/../lib/pagination.php';
require_once __DIR__ . '/../lib/write_support.php';
require_once __DIR__ . '/../lib/roles.php';

/**
 * Modelo para la tabla user
 * Maneja todas las operaciones de base de datos relacionadas con usuarios
 */
class UserModel {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Obtiene el perfil completo de un usuario por ID (sin password por seguridad)
     * @param int $user_id ID del usuario
     * @return array Arreglo estructurado con success, data/message
     */
    public function getProfileById($user_id) {
        try {
            // Validar que el user_id sea un entero positivo
            $user_id = filter_var($user_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($user_id === false) {
                return [
                    'success' => false,
                    'message' => 'ID de usuario inválido'
                ];
            }

            $sql = "SELECT id, first_name, last_name, username, email, phone, avatar, role, last_login, is_active FROM user WHERE id = :user_id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['user_id' => $user_id]);
            $profile = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($profile) {
                // Convertir is_active a booleano
                $profile['is_active'] = (bool)$profile['is_active'];

                return [
                    'success' => true,
                    'data' => $profile
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Usuario no encontrado'
                ];
            }
        } catch (PDOException $e) {
            error_log("Error en UserModel::getProfileById: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al obtener el perfil del usuario'
            ];
        }
    }

    /**
     * Obtiene datos básicos del usuario por ID
     * @param int $user_id ID del usuario
     * @return array Arreglo estructurado con success, data/message
     */
    public function getBasicInfoById($user_id) {
        try {
            // Validar que el user_id sea un entero positivo
            $user_id = filter_var($user_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($user_id === false) {
                return [
                    'success' => false,
                    'message' => 'ID de usuario inválido'
                ];
            }

            $sql = "SELECT id, username, email, first_name, last_name, role FROM user WHERE id = :user_id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['user_id' => $user_id]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                return [
                    'success' => true,
                    'data' => $user
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Usuario no encontrado'
                ];
            }
        } catch (PDOException $e) {
            error_log("Error en UserModel::getBasicInfoById: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al obtener los datos del usuario'
            ];
        }
    }

    /**
     * Verifica si un usuario existe por ID
     * @param int $user_id ID del usuario
     * @return bool True si existe, false si no
     */
    public function userExists($user_id) {
        try {
            // Validar que el user_id sea un entero positivo
            $user_id = filter_var($user_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($user_id === false) {
                return false;
            }

            $sql = "SELECT COUNT(*) FROM user WHERE id = :user_id";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['user_id' => $user_id]);
            return $stmt->fetchColumn() > 0;
        } catch (PDOException $e) {
            error_log("Error en UserModel::userExists: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Actualiza el último login del usuario
     * @param int $user_id ID del usuario
     * @return bool True si se actualizó correctamente
     */
    public function updateLastLogin($user_id) {
        try {
            // Validar que el user_id sea un entero positivo
            $user_id = filter_var($user_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($user_id === false) {
                return false;
            }

            $sql = "UPDATE user SET last_login = NOW() WHERE id = :user_id";
            $stmt = $this->pdo->prepare($sql);
            return $stmt->execute(['user_id' => $user_id]);
        } catch (PDOException $e) {
            error_log("Error en UserModel::updateLastLogin: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Obtiene todos los usuarios (solo para administradores)
     * @param bool|string $filter Puede ser:
     *   - true: solo usuarios activos (por defecto)
     *   - false: todos los usuarios (activos e inactivos)
     *   - 'inactive': solo usuarios inactivos
     * @return array Arreglo estructurado con success, data/message
     */
    public function getAllUsers($filter = true, $pagination = null) {
        try {
            // Construir query según el filtro
            if ($filter === true || $filter === 'active') {
                // Solo usuarios activos
                $sql = "SELECT id, first_name, last_name, username, email, phone, avatar, role, last_login, is_active, created_at
                        FROM user
                        WHERE is_active = 1";
                $filter_type = 'active';
            } elseif ($filter === 'inactive') {
                // Solo usuarios inactivos
                $sql = "SELECT id, first_name, last_name, username, email, phone, avatar, role, last_login, is_active, created_at
                        FROM user
                        WHERE is_active = 0";
                $filter_type = 'inactive';
            } else {
                // Todos los usuarios (activos e inactivos)
                $sql = "SELECT id, first_name, last_name, username, email, phone, avatar, role, last_login, is_active, created_at
                        FROM user";
                $filter_type = 'all';
            }

            [$users, $total] = paginated_rows($this->pdo, $sql, [], 'created_at DESC, id DESC', $pagination);

            // Convertir is_active a booleano para cada usuario
            foreach ($users as &$user) {
                $user['is_active'] = (bool)$user['is_active'];
            }

            return [
                'success' => true,
                'data' => $users,
                'count' => count($users),
                'filter' => $filter_type
            ] + pagination_response($pagination, $total);
        } catch (PDOException $e) {
            error_log("Error en UserModel::getAllUsers: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al obtener la lista de usuarios'
            ];
        }
    }

    /**
     * Obtiene un usuario por email o username (incluye password para autenticación)
     * @param string $login Email o username
     * @return array Arreglo estructurado con success, data/message
     */
    public function getUserByLogin($login) {
        try {
            // Validar que el login no esté vacío
            if (empty(trim($login))) {
                return [
                    'success' => false,
                    'message' => 'El email o username no puede estar vacío'
                ];
            }

            // Especificar campos explícitamente (incluye password porque se necesita para autenticación)
            $sql = "SELECT id, username, email, password, first_name, last_name, phone, avatar, role, last_login, is_active, created_at
                    FROM user
                    WHERE (email = :email OR username = :username)
                    AND is_active = 1
                    LIMIT 1";

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'email' => trim($login),
                'username' => trim($login)
            ]);

            $user = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($user) {
                // Convertir is_active a booleano
                $user['is_active'] = (bool)$user['is_active'];

                return [
                    'success' => true,
                    'data' => $user
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Usuario no encontrado o inactivo'
                ];
            }
        } catch (PDOException $e) {
            error_log("Error en UserModel::getUserByLogin: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al buscar el usuario'
            ];
        }
    }

    /* ------------------------------------------------------------------ administración de usuarios (UX-08A.users) */

    /** Roles asignables por la API: los valores vigentes. El heredado `usuario` nunca se asigna de nuevo ni se normaliza en la base. */
    const ASSIGNABLE_ROLES = ['admin', 'user'];

    /** Campos que forman la huella de versión del formulario de usuario. */
    const VERSION_FIELDS = FORM_VERSION_FIELDS['user_form'];

    /**
     * Valida y normaliza campos de identidad. `$fields` = campos a validar; `$required` = obligatorios entre ellos.
     * Devuelve [valores limpios, mensaje de error|null]. Solo cadenas: booleanos, números y arreglos se rechazan.
     */
    public static function validateIdentity(array $data, array $fields, array $required) {
        $clean = [];
        foreach ($fields as $field) {
            $present = array_key_exists($field, $data) && $data[$field] !== null;
            if (!$present) {
                if (in_array($field, $required, true)) {
                    return [null, "El campo '$field' es obligatorio"];
                }
                continue;
            }
            if (!is_string($data[$field])) {
                return [null, "El campo '$field' no es válido"];
            }
            $value = trim($data[$field]);
            switch ($field) {
                case 'first_name':
                case 'last_name':
                    if ($value === '') {
                        return [null, "El campo '$field' es obligatorio"];
                    }
                    if (mb_strlen($value, 'UTF-8') > 50) {
                        return [null, "El campo '$field' no puede superar 50 caracteres"];
                    }
                    break;
                case 'phone':
                    if (mb_strlen($value, 'UTF-8') > 30) {
                        return [null, "El campo 'phone' no puede superar 30 caracteres"];
                    }
                    if ($value === '') {
                        $value = null;
                    }
                    break;
                case 'email':
                    if ($value === '') {
                        return [null, "El campo 'email' es obligatorio"];
                    }
                    if (strlen($value) > 100) {
                        return [null, 'El email no puede superar 100 caracteres'];
                    }
                    if (!filter_var($value, FILTER_VALIDATE_EMAIL)) {
                        return [null, 'El formato del email no es válido'];
                    }
                    break;
                case 'username':
                    if ($value === '') {
                        return [null, "El campo 'username' es obligatorio"];
                    }
                    if (strlen($value) > 50) {
                        return [null, 'El nombre de usuario no puede superar 50 caracteres'];
                    }
                    if (!preg_match('/^[A-Za-z0-9._-]+$/', $value)) {
                        return [null, 'El nombre de usuario solo admite letras, números, punto, guion y guion bajo'];
                    }
                    break;
            }
            $clean[$field] = $value;
        }
        return [$clean, null];
    }

    /** Contraseña: cadena de 12 a 72 BYTES (límite de bcrypt). Devuelve el mensaje de error o null si es válida. */
    public static function passwordError($password) {
        if (!is_string($password)) {
            return 'La contraseña no es válida';
        }
        $bytes = strlen($password);
        if ($bytes < 12) {
            return 'La contraseña debe tener al menos 12 caracteres';
        }
        if ($bytes > 72) {
            return 'La contraseña no puede superar 72 bytes';
        }
        return null;
    }

    /** Banderas `<campo>_modificado` entre dos filas (sin valores: la bitácora no guarda datos personales). */
    public static function changedFlags(array $before, array $after) {
        $flags = [];
        foreach (['first_name', 'last_name', 'email', 'phone'] as $f) {
            $flags[$f . '_modificado'] = ($before[$f] ?? null) !== ($after[$f] ?? null);
        }
        return $flags;
    }

    /** Fila bloqueada (`FOR UPDATE`) sin contraseña. */
    private function lockUser($id) {
        $stmt = $this->pdo->prepare('SELECT id, username, email, first_name, last_name, phone, role, is_active FROM user WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => (int)$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /**
     * Bloquea (en orden de id, igual en todas las transacciones: sin interbloqueos entre sí) a TODOS los administradores activos
     * y devuelve sus ids. Regla del «último administrador activo»: se evalúa sobre este conjunto bloqueado.
     */
    private function lockActiveAdmins() {
        $stmt = $this->pdo->query("SELECT id FROM user WHERE is_active = 1 AND LOWER(TRIM(role)) = 'admin' ORDER BY id FOR UPDATE");
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Comprobaciones comunes de las operaciones que pueden dejar al sistema sin administradores. Debe ejecutarse dentro de la
     * transacción. Devuelve [fila bloqueada del objetivo, ids de administradores activos]. El actor debe seguir siendo administrador
     * activo: si otro administrador lo desactivó o degradó a la vez, esta operación ya no es legítima.
     */
    private function lockTargetWithAdminGuard($targetId, $actorId) {
        $admins = $this->lockActiveAdmins();
        $target = $this->lockUser($targetId);
        if (!$target) {
            throw new WriteNotFound('Usuario no encontrado');
        }
        if (!in_array((int)$actorId, $admins, true)) {
            throw new WriteConflict('state', 'Su cuenta ya no es de un administrador activo. Vuelva a iniciar sesión.');
        }
        return [$target, $admins];
    }

    /** Administradores activos que quedarían si `$targetId` dejara de serlo. */
    private static function otherActiveAdmins(array $admins, $targetId) {
        return count(array_filter($admins, function ($id) use ($targetId) { return $id !== (int)$targetId; }));
    }

    /** Detalle administrativo de un usuario: sin contraseña, hash ni session_version. */
    public function getAdminDetail($id) {
        $stmt = $this->pdo->prepare('SELECT id, username, email, first_name, last_name, phone, role, is_active FROM user WHERE id = :id');
        $stmt->execute(['id' => (int)$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return ['success' => false, 'message' => 'Usuario no encontrado', 'conflict' => 'notfound'];
        }
        return ['success' => true, 'data' => [
            'id' => (int)$row['id'],
            'username' => $row['username'],
            'email' => $row['email'],
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'phone' => $row['phone'],
            'role' => $row['role'],
            'is_active' => (int)$row['is_active'] === 1,
            'version' => row_version($row, self::VERSION_FIELDS),
        ]];
    }

    /** Edición administrativa: solo first_name, last_name, phone y email (+ bloqueo optimista por `version`). */
    public function updateAdmin($id, array $clean, $version, $actorId) {
        return run_write(function () use ($id, $clean, $version, $actorId) {
            return db_tx($this->pdo, function () use ($id, $clean, $version, $actorId) {
                $row = $this->lockUser($id);
                if (!$row) {
                    throw new WriteNotFound('Usuario no encontrado');
                }
                assert_version($version, $row, self::VERSION_FIELDS);
                if (isset($clean['email'])) {
                    $dup = $this->pdo->prepare('SELECT id FROM user WHERE email = :e AND id <> :id LIMIT 1');
                    $dup->execute(['e' => $clean['email'], 'id' => (int)$id]);
                    if ($dup->fetchColumn() !== false) {
                        throw new WriteConflict('duplicate', 'El email ya está registrado');
                    }
                }
                $after = array_merge($row, $clean);
                if ($after == $row) {
                    return ['success' => true, 'message' => 'Sin cambios', 'data' => ['id' => (int)$id, 'version' => row_version($row, self::VERSION_FIELDS)]];
                }
                $sets = [];
                $params = ['id' => (int)$id];
                foreach (array_keys($clean) as $field) { // claves ya acotadas a la lista blanca por el controlador
                    $sets[] = "`$field` = :$field";
                    $params[$field] = $clean[$field];
                }
                $this->pdo->prepare('UPDATE user SET ' . implode(', ', $sets) . ', updated_at = NOW() WHERE id = :id')->execute($params);
                audit_record($this->pdo, $actorId, 'user.update', 'user', $id, audit_snapshot('user', $row), audit_snapshot('user', $after), self::changedFlags($row, $after));
                return ['success' => true, 'message' => 'Usuario actualizado correctamente', 'data' => ['id' => (int)$id, 'version' => row_version($after, self::VERSION_FIELDS)]];
            });
        }, 'Error al actualizar el usuario');
    }

    /**
     * Desactiva (`$active = false`) o reactiva un usuario. Siempre incrementa `session_version`: los tokens anteriores no reviven.
     * Nunca borra la fila ni toca sus relaciones de acceso.
     */
    /**
     * admin_escritura (fase 2B): Flutter acepta tokens antiguos sin session_version, así que reactivar a un usuario podría revivir una sesión emitida antes de desactivarlo.
     * Mientras no haya pasado la espera (ADMIN_USER_REACTIVATE_HOURS, por defecto 24 h = vida del token de Flutter) desde la última desactivación auditada, se rechaza (409).
     */
    private function assertReactivationWaitElapsed($userId) {
        if (!function_exists('app_mode') || app_mode() !== 'admin_escritura') {
            return;
        }
        $hours = admin_user_reactivate_hours();
        if ($hours === 0) {
            return;
        }
        $stmt = $this->pdo->prepare("SELECT TIMESTAMPDIFF(SECOND, MAX(occurred_at), CURRENT_TIMESTAMP(3)) FROM audit_log WHERE entity = 'user' AND entity_id = :id AND action = 'user.deactivate'");
        $stmt->execute(['id' => $userId]);
        $elapsed = $stmt->fetchColumn(); // calculado en MySQL: misma zona horaria que occurred_at; NULL = nunca se desactivó por esta vía
        if ($elapsed !== false && $elapsed !== null && (int)$elapsed < $hours * 3600) {
            throw new WriteConflict('state', 'Debe esperar ' . $hours . ' h desde la desactivación para reactivar a este usuario (protege sesiones antiguas que pudieran seguir vigentes)');
        }
    }

    public function setActive($id, $active, $actorId) {
        return run_write(function () use ($id, $active, $actorId) {
            return db_tx($this->pdo, function () use ($id, $active, $actorId) {
                [$target, $admins] = $this->lockTargetWithAdminGuard($id, $actorId);
                if ($active) {
                    if ((int)$target['is_active'] === 1) {
                        throw new WriteConflict('state', 'El usuario ya está activo');
                    }
                    $this->assertReactivationWaitElapsed((int)$target['id']);
                } else {
                    if ((int)$target['id'] === (int)$actorId) {
                        throw new WriteConflict('state', 'No puede desactivar su propia cuenta');
                    }
                    if ((int)$target['is_active'] !== 1) {
                        throw new WriteConflict('state', 'El usuario ya está inactivo');
                    }
                    if (in_array((int)$target['id'], $admins, true) && self::otherActiveAdmins($admins, $target['id']) === 0) {
                        throw new WriteConflict('state', 'No se puede desactivar al último administrador activo');
                    }
                }
                $this->pdo->prepare('UPDATE user SET is_active = :a, session_version = session_version + 1, updated_at = NOW() WHERE id = :id')
                    ->execute(['a' => $active ? 1 : 0, 'id' => (int)$id]);
                $after = array_merge($target, ['is_active' => $active ? 1 : 0]);
                audit_record($this->pdo, $actorId, $active ? 'user.reactivate' : 'user.deactivate', 'user', $id,
                    audit_snapshot('user', $target), audit_snapshot('user', $after), ['session_revocada' => true]);
                return ['success' => true, 'message' => $active ? 'Usuario reactivado correctamente' : 'Usuario desactivado correctamente',
                    'data' => ['id' => (int)$id, 'is_active' => (bool)$active, 'version' => row_version($after, self::VERSION_FIELDS)]];
            });
        }, $active ? 'Error al reactivar el usuario' : 'Error al desactivar el usuario');
    }

    /**
     * Actualiza el rol de un usuario (solo administradores). Transacción + auditoría; solo `admin` o `user`;
     * no su propio rol; no degrada al último administrador activo; incrementa `session_version` del objetivo.
     * Un rol heredado `usuario` almacenado no se toca salvo que se cambie explícitamente.
     */
    public function updateUserRole($target_user_id, $new_role, $actorId = null) {
        $target_user_id = filter_var($target_user_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($target_user_id === false) {
            return ['success' => false, 'message' => 'ID de usuario inválido'];
        }
        if (!is_string($new_role) || !in_array($new_role, array_merge(self::ASSIGNABLE_ROLES, ['usuario']), true)) {
            return ['success' => false, 'message' => 'Rol inválido. Roles permitidos: ' . implode(', ', self::ASSIGNABLE_ROLES)];
        }
        return run_write(function () use ($target_user_id, $new_role, $actorId) {
            return db_tx($this->pdo, function () use ($target_user_id, $new_role, $actorId) {
                [$target, $admins] = $this->lockTargetWithAdminGuard($target_user_id, (int)$actorId);
                if ((int)$target['id'] === (int)$actorId) {
                    throw new WriteConflict('state', 'No puedes cambiar tu propio rol');
                }
                if ($target['role'] === $new_role) {
                    // Idempotente (también para un `usuario` ya almacenado, que se conserva tal cual).
                    return ['success' => true, 'message' => 'Rol del usuario actualizado correctamente'];
                }
                if (!in_array($new_role, self::ASSIGNABLE_ROLES, true)) {
                    throw new WriteConflict('state', 'El rol heredado "usuario" ya no se puede asignar; use "user"');
                }
                $demotes = normalize_role($target['role']) === 'admin' && $new_role !== 'admin';
                if ($demotes && (int)$target['is_active'] === 1 && self::otherActiveAdmins($admins, $target['id']) === 0) {
                    throw new WriteConflict('state', 'No se puede degradar al último administrador activo');
                }
                $this->pdo->prepare('UPDATE user SET role = :r, session_version = session_version + 1, updated_at = NOW() WHERE id = :id')
                    ->execute(['r' => $new_role, 'id' => (int)$target['id']]);
                audit_record($this->pdo, $actorId, 'user.role', 'user', $target['id'],
                    audit_snapshot('user', $target), audit_snapshot('user', array_merge($target, ['role' => $new_role])), ['session_revocada' => true]);
                return ['success' => true, 'message' => 'Rol del usuario actualizado correctamente'];
            });
        }, 'Error al actualizar el rol del usuario');
    }

    /** El administrador fija una contraseña nueva: hash, `session_version` + 1 y una auditoría que NO contiene datos de contraseña. */
    public function setPassword($id, $password, $actorId) {
        return run_write(function () use ($id, $password, $actorId) {
            return db_tx($this->pdo, function () use ($id, $password, $actorId) {
                $row = $this->lockUser($id);
                if (!$row) {
                    throw new WriteNotFound('Usuario no encontrado');
                }
                $this->pdo->prepare('UPDATE user SET password = :p, session_version = session_version + 1, updated_at = NOW() WHERE id = :id')
                    ->execute(['p' => password_hash($password, PASSWORD_DEFAULT), 'id' => (int)$id]);
                audit_record($this->pdo, $actorId, 'user.password', 'user', $id, null, audit_snapshot('user', $row), ['password_changed' => true, 'session_revocada' => true]);
                return ['success' => true, 'message' => 'Contraseña actualizada correctamente'];
            });
        }, 'Error al cambiar la contraseña');
    }

    /**
     * Accesos (solo lectura) de un usuario: empresas e informes asignados, con `effective` y, si no, `reason`.
     * Vigencia = la de las vistas de acceso: usuario, relación empresa-usuario, empresa, relación empresa-informe, informe y asignación activos.
     * Sin enlaces ni campos Power BI.
     */
    public function getAccess($id) {
        $u = $this->pdo->prepare('SELECT id, is_active FROM user WHERE id = :id');
        $u->execute(['id' => (int)$id]);
        $user = $u->fetch(PDO::FETCH_ASSOC);
        if (!$user) {
            return ['success' => false, 'message' => 'Usuario no encontrado', 'conflict' => 'notfound'];
        }
        $userActive = (int)$user['is_active'] === 1;
        $labels = [
            'usuario_inactivo' => 'El usuario está inactivo',
            'sin_relacion_empresa_usuario' => 'El usuario no está vinculado a la empresa',
            'relacion_empresa_usuario_inactiva' => 'La relación usuario-empresa está inactiva',
            'empresa_inactiva' => 'La empresa está inactiva',
            'relacion_empresa_reporte_inexistente' => 'El informe ya no pertenece a la empresa',
            'relacion_empresa_reporte_inactiva' => 'La relación empresa-informe está inactiva',
            'reporte_inactivo' => 'El informe está inactivo',
            'asignacion_inactiva' => 'La asignación del informe está inactiva',
        ];
        $judge = function (array $checks) use ($labels) {
            foreach ($checks as [$ok, $code]) {
                if (!$ok) {
                    return [false, $code, $labels[$code]];
                }
            }
            return [true, null, null];
        };
        $intOrNull = function ($v) { return $v === null ? null : (int)$v; };

        $stmt = $this->pdo->prepare('SELECT eu.id AS relacion_id, eu.activo AS rel_activo, e.id AS empresa_id, e.nombre, e.activo AS empresa_activo'
            . ' FROM empresa_usuario eu LEFT JOIN empresa e ON e.id = eu.empresa_id WHERE eu.user_id = :u ORDER BY e.nombre ASC, eu.id ASC');
        $stmt->execute(['u' => (int)$id]);
        $empresas = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            [$ok, $code, $label] = $judge([
                [$userActive, 'usuario_inactivo'],
                [(int)$r['rel_activo'] === 1, 'relacion_empresa_usuario_inactiva'],
                [$r['empresa_id'] !== null && (int)$r['empresa_activo'] === 1, 'empresa_inactiva'],
            ]);
            $empresas[] = ['relacion_id' => (int)$r['relacion_id'], 'empresa_id' => $intOrNull($r['empresa_id']), 'nombre' => $r['nombre'],
                'activo' => (int)$r['rel_activo'] === 1, 'effective' => $ok, 'reason' => $code, 'reason_label' => $label];
        }

        $stmt = $this->pdo->prepare('SELECT uer.id AS asignacion_id, uer.activo AS asig_activo, er.id AS er_id, er.activo AS er_activo, er.empresa_id,'
            . ' e.nombre AS empresa_nombre, e.activo AS empresa_activo, r.id AS reporte_id, r.nombre AS reporte_nombre, r.activo AS reporte_activo,'
            . ' eu.id AS eu_id, eu.activo AS eu_activo'
            . ' FROM user_empresa_reporte uer'
            . ' LEFT JOIN empresa_reporte er ON er.id = uer.empresa_reporte_id'
            . ' LEFT JOIN empresa e ON e.id = er.empresa_id'
            . ' LEFT JOIN reporte r ON r.id = er.reporte_id'
            . ' LEFT JOIN empresa_usuario eu ON eu.empresa_id = er.empresa_id AND eu.user_id = uer.user_id'
            . ' WHERE uer.user_id = :u ORDER BY e.nombre ASC, r.nombre ASC, uer.id ASC');
        $stmt->execute(['u' => (int)$id]);
        $reportes = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            [$ok, $code, $label] = $judge([
                [$userActive, 'usuario_inactivo'],
                [$r['er_id'] !== null, 'relacion_empresa_reporte_inexistente'],
                [$r['eu_id'] !== null, 'sin_relacion_empresa_usuario'],
                [(int)$r['eu_activo'] === 1, 'relacion_empresa_usuario_inactiva'],
                [(int)$r['empresa_activo'] === 1, 'empresa_inactiva'],
                [(int)$r['er_activo'] === 1, 'relacion_empresa_reporte_inactiva'],
                [(int)$r['reporte_activo'] === 1, 'reporte_inactivo'],
                [(int)$r['asig_activo'] === 1, 'asignacion_inactiva'],
            ]);
            $reportes[] = ['asignacion_id' => (int)$r['asignacion_id'], 'reporte_id' => $intOrNull($r['reporte_id']), 'reporte_nombre' => $r['reporte_nombre'],
                'empresa_id' => $intOrNull($r['empresa_id']), 'empresa_nombre' => $r['empresa_nombre'],
                'activo' => (int)$r['asig_activo'] === 1, 'effective' => $ok, 'reason' => $code, 'reason_label' => $label];
        }
        return ['success' => true, 'data' => ['user_id' => (int)$id, 'user_active' => $userActive, 'empresas' => $empresas, 'reportes' => $reportes]];
    }

    /**
     * Actualiza el perfil de un usuario
     * @param int $user_id ID del usuario
     * @param array $data Datos a actualizar (solo campos permitidos)
     * @return array Resultado de la actualización
     */
    public function updateProfile($user_id, $data) {
        try {
            // Validar que el user_id sea un entero positivo
            $user_id = filter_var($user_id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($user_id === false) {
                return [
                    'success' => false,
                    'message' => 'ID de usuario inválido'
                ];
            }

            // Whitelist de campos permitidos para actualización (seguridad)
            $allowedFields = [
                'first_name',
                'last_name',
                'email',
                'phone',
                'avatar'
            ];

            // Construir query dinámicamente solo con campos permitidos
            $updateFields = [];
            $params = ['user_id' => $user_id];

            foreach ($allowedFields as $field) {
                if (isset($data[$field])) {
                    $updateFields[] = "$field = :$field";
                    $params[$field] = $data[$field];
                }
            }

            if (empty($updateFields)) {
                return [
                    'success' => false,
                    'message' => 'No se proporcionaron campos válidos para actualizar'
                ];
            }

            // Agregar updated_at automáticamente
            // $updateFields[] = 'updated_at = NOW()';

            $sql = "UPDATE user SET " . implode(', ', $updateFields) . " WHERE id = :user_id";

            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute($params);

            if ($result) {
                return [
                    'success' => true,
                    'message' => 'Perfil actualizado correctamente'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Error al actualizar el perfil'
                ];
            }
        } catch (PDOException $e) {
            error_log("Error en UserModel::updateProfile: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al actualizar el perfil del usuario'
            ];
        }
    }
}
