<?php
require_once __DIR__ . '/../lib/pagination.php';

/**
 * Consulta del historial de cambios administrativos (tabla `audit_log`, UX-08B).
 * Solo lectura. Nunca expone `request_ip`. Si la tabla no existe (migración sin aplicar) informa `audit_available: false`.
 */
class AuditoriaModel {
    /** Entidades auditadas; `access` es un alias de las tres relaciones de acceso. */
    const ENTITIES = ['empresa', 'reporte', 'user', 'empresa_usuario', 'empresa_reporte', 'user_empresa_reporte'];
    const ACCESS_ENTITIES = ['empresa_usuario', 'empresa_reporte', 'user_empresa_reporte'];

    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /** ¿Existe la tabla de auditoría? (información de esquema; no depende de que haya filas.) */
    public function available() {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log'");
        $stmt->execute();
        return (int)$stmt->fetchColumn() > 0;
    }

    /**
     * @param array $filters entity (string|null, ya validada), entity_id (int|null), correlation_id (string|null)
     * @param array $pagination resultado de parse_pagination()
     * @return array [array $rows, int $total]
     */
    public function search(array $filters, array $pagination) {
        $where = ['1 = 1'];
        $params = [];
        if (($filters['entity'] ?? null) !== null) {
            if ($filters['entity'] === 'access') {
                $names = [];
                foreach (self::ACCESS_ENTITIES as $i => $entity) {
                    $names[] = ':entity' . $i;
                    $params['entity' . $i] = $entity;
                }
                $where[] = 'a.entity IN (' . implode(', ', $names) . ')';
            } else {
                $where[] = 'a.entity = :entity';
                $params['entity'] = $filters['entity'];
            }
        }
        if (($filters['entity_id'] ?? null) !== null) {
            $where[] = 'a.entity_id = :entity_id';
            $params['entity_id'] = (int)$filters['entity_id'];
        }
        if (($filters['correlation_id'] ?? null) !== null) {
            $where[] = 'a.correlation_id = :correlation_id';
            $params['correlation_id'] = strtolower($filters['correlation_id']);
        }
        $sql = "SELECT a.id, a.occurred_at, a.correlation_id, a.actor_user_id,
                    COALESCE(NULLIF(TRIM(CONCAT(COALESCE(u.first_name, ''), ' ', COALESCE(u.last_name, ''))), ''), u.username) AS actor,
                    a.action, a.entity, a.entity_id, a.before_json, a.after_json
                FROM audit_log a
                LEFT JOIN user u ON u.id = a.actor_user_id
                WHERE " . implode(' AND ', $where);
        [$rows, $total] = paginated_rows($this->pdo, $sql, $params, 'a.occurred_at DESC, a.id DESC', $pagination);
        $decode = function ($json) {
            if ($json === null || $json === '') {
                return null;
            }
            $value = json_decode($json);
            return is_object($value) ? $value : null;
        };
        $out = [];
        foreach ($rows as $row) {
            $out[] = [
                'id' => (int)$row['id'],
                'occurred_at' => $row['occurred_at'],
                'correlation_id' => $row['correlation_id'],
                'actor_user_id' => (int)$row['actor_user_id'],
                'actor' => $row['actor'],
                'action' => $row['action'],
                'entity' => $row['entity'],
                'entity_id' => (int)$row['entity_id'],
                'before' => $decode($row['before_json']),
                'after' => $decode($row['after_json']),
            ];
        }
        return [$out, (int)$total];
    }
}
