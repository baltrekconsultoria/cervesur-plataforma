<?php
/**
 * Vistas de respuesta (UX-06B.0): `?view=minimal`.
 *
 * Es OPT-IN: sin el parámetro, cada endpoint responde exactamente como antes (clientes anteriores, p. ej. la
 * aplicación Flutter, no se ven afectados). Con `view=minimal` la respuesta se proyecta a una lista blanca de
 * campos destinada a la biblioteca de informes: sin `enlace`, sin `created_by*`, sin `powerbi_*` y sin datos
 * de contacto. La proyección ocurre DESPUÉS de la autorización y solo puede quitar campos; los datos completos
 * siguen disponibles internamente para los servicios autorizados (p. ej. Power BI).
 */
const VIEW_MINIMAL_FIELDS = [
    'reporte' => ['id', 'reporte_id', 'nombre', 'descripcion', 'activo'],
    'empresa' => ['id', 'nombre', 'razon_social', 'ruc', 'ciudad', 'pais', 'logo', 'activo'],
    'asignacion' => ['id', 'empresa_id', 'empresa_nombre', 'reporte_id', 'reporte_nombre', 'activo'],
];

/** Valida `view`: null (sin vista), 'minimal', o responde 400 y termina. */
function require_view() {
    if (!array_key_exists('view', $_GET)) {
        return null;
    }
    if ($_GET['view'] !== 'minimal') {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'El parámetro view solo admite el valor "minimal"']);
        exit;
    }
    return 'minimal';
}

function project_view_row(array $row, array $allowed) {
    return array_intersect_key($row, array_flip($allowed));
}

function project_view_data($data, array $allowed) {
    if (!is_array($data)) {
        return $data;
    }
    if (array_key_exists('data', $data) && is_array($data['data'])) {
        $data['data'] = project_view_data($data['data'], $allowed);
        return $data;
    }
    if (array_is_list($data)) {
        return array_map(function ($row) use ($allowed) {
            return is_array($row) ? project_view_row($row, $allowed) : $row;
        }, $data);
    }
    return project_view_row($data, $allowed);
}

/** Aplica la vista a una respuesta ya autorizada (arreglo con `data`). */
function apply_view(array $response, $view, $resource) {
    if ($view === null || !isset($response['data'])) {
        return $response;
    }
    $response['data'] = project_view_data($response['data'], VIEW_MINIMAL_FIELDS[$resource]);
    return $response;
}

/** Para controladores que imprimen directamente: captura la salida y la proyecta. */
function run_with_view($view, $resource, callable $handler) {
    if ($view === null) {
        $handler();
        return;
    }
    ob_start();
    $handler();
    $raw = ob_get_clean();
    $json = json_decode($raw, true);
    echo is_array($json) ? json_encode(apply_view($json, $view, $resource)) : $raw;
}
