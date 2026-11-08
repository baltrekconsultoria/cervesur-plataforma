<?php

require_once __DIR__ . '/../lib/pagination.php';
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
        return app_mode_is_read_only() ? '' : 'e.powerbi_tenant, e.powerbi_client, ';
    }

    public function getAll($user_id, $role = 'user', $pagination = null, &$total = null) {
        $params = [];
        $sql = 'SELECT e.id, e.nombre, e.razon_social, e.ruc, e.email, e.telefono, e.website, e.direccion, e.ciudad, e.pais, ' . $this->powerbiColumns() . 'e.logo, e.contacto_principal, e.created_by, e.updated_by, e.created_at, e.updated_at, e.activo FROM empresa e WHERE e.activo = 1' . $this->visibleEmpresaSql($user_id, $role, $params);
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

    /**
     * Crea una nueva empresa
     * @param array $data Datos de la empresa
     * @return array Resultado de la creación
     */
    public function create($data) {
        try {
            $sql = "INSERT INTO empresa (
                            nombre, razon_social, ruc, email, telefono,
                            website, direccion, ciudad, pais, powerbi_tenant,
                            powerbi_client, powerbi_clientsecret, logo, contacto_principal, created_by

                        ) VALUES (
                            :nombre, :razon_social, :ruc, :email, :telefono,
                            :website, :direccion, :ciudad, :pais, :powerbi_tenant,
                            :powerbi_client, :powerbi_clientsecret, :logo, :contacto_principal, :created_by
                        )";

            $stmt = $this->pdo->prepare($sql);
            $result = $stmt->execute([
                'nombre' => $data['nombre'],
                'razon_social' => $data['razon_social'],
                'ruc' => $data['ruc'],
                'email' => $data['email'] ?? null,
                'telefono' => $data['telefono'] ?? null,
                'website' => $data['website'] ?? null,
                'direccion' => $data['direccion'] ?? null,
                'ciudad' => $data['ciudad'] ?? null,
                'pais' => $data['pais'] ?? null,
                'powerbi_tenant' => $data['powerbi_tenant'] ?? null,
                'powerbi_client' => $data['powerbi_client'] ?? null,
                'powerbi_clientsecret' => $data['powerbi_clientsecret'] ?? null,
                'logo' => $data['logo'] ?? null,
                'contacto_principal' => $data['contacto_principal'] ?? null,
                'created_by' => $data['created_by']
            ]);

            if ($result) {
                return [
                    'success' => true,
                    'id' => $this->pdo->lastInsertId(),
                    'message' => 'Empresa creada exitosamente'
                ];
            } else {
                return [
                    'success' => false,
                    'message' => 'Error al crear la empresa'
                ];
            }
        } catch (PDOException $e) {
            error_log("Error en EmpresaModel::create: " . $e->getMessage());
            return [
                'success' => false,
                'message' => 'Error al crear la empresa'
            ];
        }
    }

    /**
     * Actualiza una empresa existente
     * @param int $id ID de la empresa
     * @param array $data Datos a actualizar
     * @param int $user_id ID del usuario (para verificar permisos)
     * @param string $role Rol del usuario (para permisos de admin)
     * @return array Resultado de la actualización
     */
    public function update($id, $data, $user_id, $role = 'user') {
        // Verificar que la empresa existe
        $empresa = $this->getById($id, $user_id, $role);
        if (!$empresa) {
            return [
                'success' => false,
                'message' => 'Empresa no encontrada'
            ];
        }

        // Construir query dinámicamente basado en los campos proporcionados
        $updateFields = [];
        $params = ['id' => $id, 'updated_by' => $user_id];

        $fields = [
            'nombre',
            'razon_social',
            'ruc',
            'email',
            'telefono',
            'website',
            'direccion',
            'ciudad',
            'pais',
            'powerbi_tenant',
            'powerbi_client',
            'powerbi_clientsecret',
            'logo',
            'contacto_principal'
        ];

        foreach ($fields as $field) {
            if (isset($data[$field])) {
                $updateFields[] = "$field = :$field";

                $params[$field] = $data[$field];
            }
        }

        if (empty($updateFields)) {
            return [
                'success' => false,
                'message' => 'No se proporcionaron campos para actualizar'
            ];
        }

        $updateFields[] = 'updated_by = :updated_by';
        $sql = "UPDATE empresa SET " . implode(', ', $updateFields) . " WHERE id = :id";

        $stmt = $this->pdo->prepare($sql);
        $result = $stmt->execute($params);

        if ($result) {
            return [
                'success' => true,
                'message' => 'Empresa actualizada exitosamente'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Error al actualizar la empresa'
            ];
        }
    }

    /**
     * Elimina una empresa (soft delete - marca como inactivo)
     * @param int $id ID de la empresa
     * @param int $user_id ID del usuario (para verificar permisos)
     * @param string $role Rol del usuario (para permisos de admin)
     * @return array Resultado de la eliminación
     */
    public function delete($id, $user_id, $role = 'user') {
        // Verificar que la empresa existe
        $empresa = $this->getById($id, $user_id, $role);
        if (!$empresa) {
            return [
                'success' => false,
                'message' => 'Empresa no encontrada'
            ];
        }

        $sql = "UPDATE empresa SET activo = 0, updated_by = :updated_by WHERE id = :id";
        $stmt = $this->pdo->prepare($sql);
        $result = $stmt->execute([
            'id' => $id,
            'updated_by' => $user_id
        ]);

        if ($result) {
            return [
                'success' => true,
                'message' => 'Empresa eliminada exitosamente'
            ];
        } else {
            return [
                'success' => false,
                'message' => 'Error al eliminar la empresa'
            ];
        }
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
