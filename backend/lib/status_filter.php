<?php
/**
 * Filtro de estado de los listados administrativos (UX-08A).
 *
 * Contrato (aditivo, compatible con clientes anteriores):
 *  - Sin `status`, el listado responde exactamente como antes: solo registros activos.
 *  - `status=active|inactive|all`. Cualquier otro valor -> HTTP 400.
 *  - `inactive` y `all` solo para administradores (HTTP 403 al resto): un usuario normal nunca ve registros inactivos.
 */
const STATUS_FILTER_VALUES = ['active', 'inactive', 'all'];

/** Lee y valida `status`. Responde 400/403 y termina si no es válido o no está permitido. */
function require_status_filter($role) {
    if (!array_key_exists('status', $_GET)) {
        return 'active';
    }
    $status = $_GET['status'];
    if (!is_string($status) || !in_array($status, STATUS_FILTER_VALUES, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'El parámetro status solo admite los valores active, inactive o all']);
        exit;
    }
    if ($status !== 'active' && strtolower((string)$role) !== 'admin') {
        http_response_code(403);
        echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
        exit;
    }
    return $status;
}

/** Condición SQL (siempre una expresión booleana) para una columna de estado 0/1. */
function status_sql($column, $status) {
    if ($status === 'inactive') {
        return 'COALESCE(' . $column . ', 0) <> 1';
    }
    return $status === 'all' ? '1 = 1' : $column . ' = 1';
}
