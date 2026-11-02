<?php
/**
 * Lista de informes que el rol administrador puede abrir en el visor (UX-08C, piloto administrativo).
 *
 * Configuración de servidor, no de la base de datos: ADMIN_VIEWER_ALLOWED_REPORT_IDS="72,105" (ids numéricos de `reporte.id`).
 *  - Variable no definida: sin restricción adicional (comportamiento de siempre; no cambia otras instancias).
 *  - Variable definida: el administrador solo abre los informes de la lista. Vacía = ninguno.
 *  - Cualquier entrada que no sea un id numérico válido invalida TODA la lista (falla cerrada: ningún informe).
 * Solo restringe al rol administrador; los trabajadores siguen sujetos únicamente a su asignación individual.
 * Un informe inactivo o inexistente ya responde 404 antes de consultar esta lista, aunque figure en ella.
 */
const ADMIN_VIEWER_ENV = 'ADMIN_VIEWER_ALLOWED_REPORT_IDS';

/** @return int[]|null null = sin restricción configurada. */
function admin_viewer_allowlist() {
    $raw = getenv(ADMIN_VIEWER_ENV);
    if ($raw === false) {
        return null;
    }
    $ids = [];
    foreach (explode(',', (string)$raw) as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        if (!preg_match('/^[1-9][0-9]{0,9}$/', $part) || (int)$part > 4294967295) {
            return [];
        }
        $ids[(int)$part] = true;
    }
    return array_keys($ids);
}

/** ¿Puede este rol abrir el visor de este informe (según la lista del piloto administrativo)? */
function admin_viewer_permits($role, $reportId) {
    if (strtolower((string)$role) !== 'admin') {
        return true;
    }
    $allowed = admin_viewer_allowlist();
    return $allowed === null || in_array((int)$reportId, $allowed, true);
}
