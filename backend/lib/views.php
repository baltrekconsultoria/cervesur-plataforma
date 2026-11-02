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
require_once __DIR__ . '/write_support.php';

const VIEW_MINIMAL_FIELDS = [
    'reporte' => ['id', 'reporte_id', 'nombre', 'descripcion', 'activo'],
    'empresa' => ['id', 'nombre', 'razon_social', 'ruc', 'ciudad', 'pais', 'logo', 'activo'],
    'asignacion' => ['id', 'empresa_id', 'empresa_nombre', 'reporte_id', 'reporte_nombre', 'activo'],
    // UX-07A (administración de accesos): solo identificadores y etiquetas; sin `reporte_enlace`, `created_by*` ni notas.
    // UX-07B (formularios de edición, SOLO administradores): datos no sensibles. Nunca incluye powerbi_* ni secretos;
    // el informe incluye su enlace porque es un dato que el administrador debe poder corregir.
    'empresa_form' => ['id', 'nombre', 'razon_social', 'ruc', 'email', 'telefono', 'direccion', 'ciudad', 'pais', 'activo', 'version'],
    'reporte_form' => ['id', 'nombre', 'descripcion', 'enlace', 'grupo_id', 'reporte_id', 'activo', 'version'],
    'empresa_usuario' => ['id', 'user_id', 'empresa_id', 'activo'],
    'empresa_reporte_usuario' => ['id', 'user_id', 'empresa_id', 'empresa_reporte_id', 'reporte_id', 'activo'],
];

/** Valida `view`: null (sin vista), 'minimal', o responde 400 y termina. */
function require_view(array $allowed = ['minimal']) {
    if (!array_key_exists('view', $_GET)) {
        // UX-08B: en las instancias React (REACT_STRICT_VIEWS=1) la respuesta por defecto es la mínima: sin `enlace`, `created_by*` ni `powerbi_*`.
        // Flutter usa su propia instancia (sin la variable) y conserva la respuesta completa.
        return getenv('REACT_STRICT_VIEWS') === '1' && in_array('minimal', $allowed, true) ? 'minimal' : null;
    }
    if (!is_string($_GET['view']) || !in_array($_GET['view'], $allowed, true)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'El parámetro view solo admite el valor ' . implode(' o ', array_map(function ($v) { return '"' . $v . '"'; }, $allowed))]);
        exit;
    }
    return $_GET['view'];
}

/**
 * Vista de un detalle (UX-07B): `minimal` para cualquiera; `form` solo para administradores (403 al resto).
 * Devuelve [vista, recurso] para apply_view()/run_with_view().
 */
function require_detail_view($role, $resource) {
    $view = require_view(['minimal', 'form']);
    if ($view === 'form') {
        if (strtolower((string)$role) !== 'admin') {
            http_response_code(403);
            echo json_encode(['success' => false, 'message' => 'Acceso denegado']);
            exit;
        }
        return [$view, $resource . '_form'];
    }
    return [$view, $resource];
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
    // UX-08B: los formularios incluyen la versión de la fila (bloqueo optimista); se calcula ANTES de proyectar.
    if (isset(FORM_VERSION_FIELDS[$resource]) && is_array($response['data']) && !array_is_list($response['data'])) {
        $response['data']['version'] = row_version($response['data'], FORM_VERSION_FIELDS[$resource]);
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
