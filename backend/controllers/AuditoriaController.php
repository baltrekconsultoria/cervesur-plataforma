<?php
/**
 * Controlador del historial de cambios administrativos (solo lectura, solo administradores).
 * Valida los filtros y delega en el modelo; nunca devuelve texto de SQL.
 */
require_once __DIR__ . '/../models/AuditoriaModel.php';

class AuditoriaController {
    private $model;

    public function __construct($pdo) {
        $this->model = new AuditoriaModel($pdo);
    }

    /**
     * @param array $query Normalmente $_GET (entity, entity_id, correlation_id)
     * @param array $pagination resultado de require_pagination()
     * @return array [int $httpStatus, array $body]
     */
    public function history(array $query, array $pagination) {
        $filters = ['entity' => null, 'entity_id' => null, 'correlation_id' => null];

        if (array_key_exists('entity', $query)) {
            $entity = $query['entity'];
            if (!is_string($entity) || ($entity !== 'access' && !in_array($entity, AuditoriaModel::ENTITIES, true))) {
                return [400, ['success' => false, 'message' => 'El parámetro entity no es válido']];
            }
            $filters['entity'] = $entity;
        }
        if (array_key_exists('entity_id', $query)) {
            $entityId = is_string($query['entity_id']) ? filter_var($query['entity_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) : false;
            if ($entityId === false) {
                return [400, ['success' => false, 'message' => 'El parámetro entity_id debe ser un entero positivo']];
            }
            $filters['entity_id'] = $entityId;
        }
        if (array_key_exists('correlation_id', $query)) {
            if (!is_string($query['correlation_id']) || !preg_match('/^[0-9a-fA-F]{32}$/', $query['correlation_id'])) {
                return [400, ['success' => false, 'message' => 'El parámetro correlation_id debe tener 32 caracteres hexadecimales']];
            }
            $filters['correlation_id'] = $query['correlation_id'];
        }

        try {
            if (!$this->model->available()) {
                return [200, ['success' => true, 'data' => [], 'count' => 0, 'audit_available' => false] + pagination_response($pagination, 0)];
            }
            [$rows, $total] = $this->model->search($filters, $pagination);
            return [200, ['success' => true, 'data' => $rows, 'count' => count($rows), 'audit_available' => true] + pagination_response($pagination, $total)];
        } catch (Throwable $e) {
            error_log('Error al consultar el historial: ' . $e->getMessage());
            return [500, ['success' => false, 'message' => 'Error al consultar el historial']];
        }
    }
}
