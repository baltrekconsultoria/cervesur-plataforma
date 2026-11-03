<?php

require_once __DIR__ . '/../lib/pagination.php';
require_once __DIR__ . '/../lib/status_filter.php';
require_once __DIR__ . '/../lib/write_support.php';
require_once __DIR__ . '/../lib/mode.php';

/**
 * Modelo para la tabla empresa
 * Maneja todas las operaciones de base de datos relacionadas con empresas
 */

class EmpresaModel {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * Obtiene todas las empresas activas
     * @param int $user_id ID del usuario (para permisos)
     * @param string $role Rol del usuario (para permisos de admin)
     * @return array Lista de empresas
     */
    private function visibleEmpresaSql($user_id, $role, &$params) {
        if (strtolower((string)$role) === 'admin') {
            return '';
        }
        $params[':visible_user_id'] = $user_id;
        return ' AND EXISTS (SELECT 1 FROM empresa_usuario eu WHERE eu.empresa_id = e.id AND eu.user_id = :visible_user_id AND eu.activo = 1)';
    }

    private function withoutSecrets($empresa) {
        if (is_array($empresa)) {
            unset($empresa['powerbi_clientsecret']);
        }
        return $empresa;
    }

    /**
     * Columnas de credenciales de Power BI que se leen en los listados. En modo de solo lectura/diagnóstico (UX-07D.3A) NO se leen: la cuenta de base de
     * datos del piloto no tiene permiso sobre ellas y, además, no deben viajar a ningún cliente.
     */
    private function powerbiColumns() {
        return (app_mode_is_read_only() || app_mode() === 'admin_escritura') ? '' : 'e.powerbi_tenant, e.powerbi_client, ';
    }

    public function getAll($user_id, $role = 'user', $pagination = null, &$total = null, $status = 'active') {
        $params = [];
        $sql = 'SELECT e.id, e.nombre, e.razon_social, e.ruc, e.email, e.telefono, e.website, e.direccion, e.ciudad, e.pais, ' . $this->powerbiColumns() . 'e.logo, e.contacto_principal, e.created_by, e.updated_by, e.created_at, e.updated_at, e.activo FROM empresa e WHERE ' . status_sql('e.activo', $status) . $this->visibleEmpresaSql($user_id, $role, $params);
        [$rows, $total] = paginated_rows($this->pdo, $sql, $params, 'e.nombre ASC, e.id ASC', $pagination);
        return array_map([$this, 'withoutSecrets'], $rows);
    }

    public function getById($id, $user_id, $role = 'user') {
        $params = [':id' => $id];
        $sql = 'SELECT e.id, e.nombre, e.razon_social, e.ruc, e.email, e.telefono, e.website, e.direccion, e.ciudad, e.pais, ' . $this->powerbiColumns() . 'e.logo, e.contacto_principal, e.created_by, e.updated_by, e.created_at, e.updated_at, e.activo FROM empresa e WHERE e.id = :id AND e.activo = 1' . $this->visibleEmpresaSql($user_id, $role, $params) . ' LIMIT 1';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $this->withoutSecrets($stmt->fetch(PDO::FETCH_ASSOC));
    }

    /** Campos editables que forman la huella de versión (bloqueo optimista). Nunca incluye `powerbi_*`. */
    const VERSION_FIELDS = FORM_VERSION_FIELDS['empresa_form'];

    /** Fila actual bloqueada (`FOR UPDATE`) dentro de la transacción en curso, sin columnas de configuración Power BI. */
    private function lockRow($id) {
        $stmt = $this->pdo->prepare('SELECT id, nombre, razon_social, ruc, email, telefono, website, direccion, ciudad, pais, contacto_principal, activo FROM empresa WHERE id = :id FOR UPDATE');
        $stmt->execute(['id' => $id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    /** Huella de versión de una fila (la recibe el cliente en `view=form`). */
    public function versionOf(array $row) {
        return row_version($row, self::VERSION_FIELDS);
    }

    /** Nombres (no valores) de los campos de configuración Power BI presentes en una entrada. */
    private static function powerbiKeys(array $data) {
        return array_values(array_filter(['powerbi_tenant', 'powerbi_client', 'powerbi_clientsecret'], function ($k) use ($data) {
            return isset($data[$k]);
        }));
    }

    /**
     * ¿Existe ya otra empresa (activa o no) con ese RUC? Replica el UNIQUE productivo dentro de la transacción.
     * Lectura SIN bloqueo a propósito: bloquear un hueco de índice hace que dos altas simultáneas se bloqueen entre sí (deadlock);
     * la carrera la decide el UNIQUE real (1062 -> conflicto).
     */
    private function assertRucFree($ruc, $excludeId, $message) {
        $sql = 'SELECT id FROM empresa WHERE ruc = :ruc' . ($excludeId !== null ? ' AND id <> :exclude' : '') . ' LIMIT 1';
        $params = ['ruc' => $ruc] + ($excludeId !== null ? ['exclude' => $excludeId] : []);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        if ($stmt->fetchColumn() !== false) {
            throw new WriteConflict('duplicate', $message);
        }
    }

    /**
     * Crea una nueva empresa (transacción + auditoría; RUC duplicado = conflicto, también ante una carrera).
     * @param array $data Datos de la empresa
     * @return array Resultado de la creación
     */
    public function create($data) {
        return run_write(function () use ($data) {
            return db_tx($this->pdo, function () use ($data) {
                $this->assertRucFree($data['ruc'], null, 'El RUC ya está registrado');
                $cols = ['nombre', 'razon_social', 'ruc', 'email', 'telefono', 'website', 'direccion', 'ciudad', 'pais',
                    'powerbi_tenant', 'powerbi_client', 'powerbi_clientsecret', 'logo', 'contacto_principal'];
                if (app_mode() === 'admin_escritura') {
                    // La cuenta de escritura no tiene permiso sobre powerbi_*: no se incluyen en el INSERT.
                    $cols = array_values(array_filter($cols, function ($c) { return strpos($c, 'powerbi_') !== 0; }));
                }
                $params = ['created_by' => $data['created_by']];
                foreach ($cols as $c) {
                    $params[$c] = $data[$c] ?? null;
                }
                $names = array_merge($cols, ['created_by']);
                $sql = 'INSERT INTO empresa (' . implode(', ', $names) . ') VALUES (:' . implode(', :', $names) . ')';
                $this->pdo->prepare($sql)->execute($params);
                $id = $this->pdo->lastInsertId();
                $powerbi = self::powerbiKeys($data);
                audit_record($this->pdo, $data['created_by'], 'empresa.create', 'empresa', $id, null,
                    audit_snapshot('empresa', $this->lockRow($id)), $powerbi ? ['powerbi_campos' => $powerbi] : []);
                return ['success' => true, 'id' => $id, 'message' => 'Empresa creada exitosamente'];
            });
        }, 'Error al crear la empresa');
    }

    /**
     * Actualiza una empresa existente (transacción + auditoría + bloqueo optimista).
     * Si `$data['version']` viene informado, debe coincidir con la versión actual; si no, conflicto `stale` (409).
     * @param int $id ID de la empresa
     * @param array $data Datos a actualizar
     * @param int $user_id ID del usuario (para verificar permisos)
     * @param string $role Rol del usuario (para permisos de admin)
     * @return array Resultado de la actualización
     */
    public function update($id, $data, $user_id, $role = 'user') {
        return run_write(function () use ($id, $data, $user_id) {
            return db_tx($this->pdo, function () use ($id, $data, $user_id) {
                $before = $this->lockRow($id);
                if (!$before || (int)$before['activo'] !== 1) {
                    throw new WriteNotFound('Empresa no encontrada');
                }
                assert_version($data['version'] ?? null, $before, self::VERSION_FIELDS);

                $updateFields = [];
                $params = ['id' => $id, 'updated_by' => $user_id];
                $fields = ['nombre', 'razon_social', 'ruc', 'email', 'telefono', 'website', 'direccion', 'ciudad', 'pais',
                    'powerbi_tenant', 'powerbi_client', 'powerbi_clientsecret', 'logo', 'contacto_principal'];
                foreach ($fields as $field) {
                    // admin_escritura: la cuenta de escritura no tiene permiso sobre powerbi_* (nunca se escriben desde este modo).
                    if (app_mode() === 'admin_escritura' && strpos($field, 'powerbi_') === 0) {
                        continue;
                    }
                    if (isset($data[$field])) {
                        $updateFields[] = "$field = :$field";
                        $params[$field] = $data[$field];
                    }
                }
                if (empty($updateFields)) {
                    return ['success' => false, 'message' => 'No se proporcionaron campos para actualizar'];
                }
                if (isset($data['ruc'])) {
                    $this->assertRucFree($data['ruc'], (int)$id, 'El RUC ya está registrado en otra empresa');
                }
                $updateFields[] = 'updated_by = :updated_by';
                $this->pdo->prepare('UPDATE empresa SET ' . implode(', ', $updateFields) . ' WHERE id = :id')->execute($params);

                $powerbi = self::powerbiKeys($data);
                audit_record($this->pdo, $user_id, 'empresa.update', 'empresa', $id, audit_snapshot('empresa', $before),
                    audit_snapshot('empresa', $this->lockRow($id)), $powerbi ? ['powerbi_campos' => $powerbi] : []);
                return ['success' => true, 'message' => 'Empresa actualizada exitosamente'];
            });
        }, 'Error al actualizar la empresa');
    }

    /** Cambia `activo` de una empresa dentro de una transacción auditada. */
    private function setActive($id, $user_id, $active, $action, $successMessage) {
        return run_write(function () use ($id, $user_id, $active, $action, $successMessage) {
            return db_tx($this->pdo, function () use ($id, $user_id, $active, $action, $successMessage) {
                $before = $this->lockRow($id);
                if (!$before) {
                    throw new WriteNotFound('Empresa no encontrada');
                }
                if ((int)$before['activo'] === $active) {
                    if ($active === 1) {
                        throw new WriteConflict('state', 'La empresa ya está activa');
                    }
                    throw new WriteNotFound('Empresa no encontrada');
                }
                $this->pdo->prepare('UPDATE empresa SET activo = :a, updated_by = :u WHERE id = :id')->execute(['a' => $active, 'u' => $user_id, 'id' => $id]);
                audit_record($this->pdo, $user_id, $action, 'empresa', $id, audit_snapshot('empresa', $before), audit_snapshot('empresa', $this->lockRow($id)));
                return ['success' => true, 'message' => $successMessage];
            });
        }, 'Error al cambiar el estado de la empresa');
    }

    /**
     * Elimina una empresa (soft delete - marca como inactivo)
     * @param int $id ID de la empresa
     * @param int $user_id ID del usuario (para verificar permisos)
     * @param string $role Rol del usuario (para permisos de admin)
     * @return array Resultado de la eliminación
     */
    public function delete($id, $user_id, $role = 'user') {
        return $this->setActive($id, $user_id, 0, 'empresa.deactivate', 'Empresa eliminada exitosamente');
    }

    /** Reactiva una empresa dada de baja (UX-08B). Una empresa ya activa es un conflicto de estado (409). */
    public function reactivate($id, $user_id) {
        return $this->setActive($id, $user_id, 1, 'empresa.reactivate', 'Empresa reactivada exitosamente');
    }

    /**
     * Busca empresas por nombre o RUC
     * @param string $search Término de búsqueda
     * @param int $user_id ID del usuario
     * @param string $role Rol del usuario
     * @return array Lista de empresas que coinciden
     */
    public function search($search, $user_id, $role = 'user') {
        $params = [':search1' => "%$search%", ':search2' => "%$search%", ':search3' => "%$search%"];
        $sql = 'SELECT e.id, e.nombre, e.razon_social, e.ruc, e.email, e.telefono, e.website, e.direccion, e.ciudad, e.pais, ' . $this->powerbiColumns() . 'e.logo, e.contacto_principal, e.created_by, e.updated_by, e.created_at, e.updated_at, e.activo FROM empresa e WHERE e.activo = 1 AND (e.nombre LIKE :search1 OR e.ruc LIKE :search2 OR e.razon_social LIKE :search3)' . $this->visibleEmpresaSql($user_id, $role, $params) . ' ORDER BY e.nombre ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map([$this, 'withoutSecrets'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    public function getByCity($ciudad, $user_id, $role = 'user') {
        $params = [':ciudad' => $ciudad];
        $sql = 'SELECT e.id, e.nombre, e.razon_social, e.ruc, e.email, e.telefono, e.website, e.direccion, e.ciudad, e.pais, ' . $this->powerbiColumns() . 'e.logo, e.contacto_principal, e.created_by, e.updated_by, e.created_at, e.updated_at, e.activo FROM empresa e WHERE e.activo = 1 AND e.ciudad = :ciudad' . $this->visibleEmpresaSql($user_id, $role, $params) . ' ORDER BY e.nombre ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return array_map([$this, 'withoutSecrets'], $stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    /**
     * Verifica si un RUC ya existe
     * @param string $ruc RUC a verificar
     * @param int $exclude_id ID de empresa a excluir (para updates)
     * @return bool True si existe, false si no
     */
    public function rucExists($ruc, $exclude_id = null) {
        if ($exclude_id) {
            $sql = "SELECT COUNT(*) FROM empresa WHERE ruc = :ruc AND id != :exclude_id AND activo = 1";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['ruc' => $ruc, 'exclude_id' => $exclude_id]);
        } else {
            $sql = "SELECT COUNT(*) FROM empresa WHERE ruc = :ruc AND activo = 1";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute(['ruc' => $ruc]);
        }

        return $stmt->fetchColumn() > 0;
    }

    /**
     * Obtiene el total de empresas activas
     * @return int Total de empresas activas
     */
    public function getTotalCount() {
        try {
            $sql = "SELECT COUNT(*) as total FROM empresa WHERE activo = 1";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            return (int)$result['total'];
        } catch (PDOException $e) {
            error_log("Error en EmpresaModel::getTotalCount: " . $e->getMessage());
            return 0;
        }
    }
}
