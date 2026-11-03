<?php
/**
 * Catálogo CERRADO de las únicas sentencias SQL del modo «piloto_visual» (UX-07D.4 express).
 *
 * El piloto muestra a UN operador autorizado el catálogo real de empresas e informes y, solo para los informes que el operador haya autorizado,
 * el enlace del visor. No consulta la tabla `user` (ni hashes) ni ninguna columna de credenciales de Power BI. La conexión rechaza cualquier SQL
 * cuyo texto (con los espacios normalizados) no figure aquí. La lista de columnas de la cuenta cervesur_ro_visor SE DEDUCE de este catálogo
 * (15 columnas); tests/isolated/piloto_privilegios_test.php prueba con MySQL real que cada columna es necesaria y que ninguna otra hace falta.
 *
 *   reporte(id, reporte_id, nombre, descripcion, activo, enlace)         ← `enlace` SOLO en 'reporte_visor'
 *   empresa(id, nombre, ciudad, ruc, activo)
 *   empresa_reporte(id, empresa_id, reporte_id, activo)
 */
const PILOTO_MAX_PER_PAGE = 100;

/** @return array<string,string> */
function piloto_catalogo_consultas() {
    return [
        'reportes_total' => 'SELECT COUNT(*) FROM reporte WHERE activo = 1',
        'reportes_pagina' => 'SELECT id, reporte_id, nombre, descripcion, activo FROM reporte WHERE activo = 1 ORDER BY id LIMIT ? OFFSET ?',
        'reporte_detalle' => 'SELECT id, reporte_id, nombre, descripcion, activo FROM reporte WHERE id = ? AND activo = 1',
        'reporte_visor' => 'SELECT id, reporte_id, nombre, descripcion, enlace FROM reporte WHERE id = ? AND activo = 1',
        'empresas_total' => 'SELECT COUNT(*) FROM empresa WHERE activo = 1',
        'empresas_pagina' => 'SELECT id, nombre, ciudad, ruc, activo FROM empresa WHERE activo = 1 ORDER BY id LIMIT ? OFFSET ?',
        'relaciones_total' => 'SELECT COUNT(*) FROM empresa_reporte er INNER JOIN empresa e ON e.id = er.empresa_id AND e.activo = 1 INNER JOIN reporte r ON r.id = er.reporte_id AND r.activo = 1 WHERE er.activo = 1',
        'relaciones_pagina' => 'SELECT er.id, er.empresa_id, er.reporte_id, er.activo FROM empresa_reporte er INNER JOIN empresa e ON e.id = er.empresa_id AND e.activo = 1 INNER JOIN reporte r ON r.id = er.reporte_id AND r.activo = 1 WHERE er.activo = 1 ORDER BY er.id LIMIT ? OFFSET ?',
    ];
}

/** Sentencias de control (sin tablas del portal). */
function piloto_catalogo_controles() {
    return [
        'sesion_solo_lectura' => 'SELECT @@session.transaction_read_only',
        'privilegios_de_la_cuenta' => 'SHOW GRANTS FOR CURRENT_USER()',
        'base_conectada' => 'SELECT DATABASE()',
    ];
}

/** Todas las sentencias autorizadas en el modo piloto (incluye la fijación de la sesión en solo lectura). */
function piloto_catalogo_sentencias() {
    $all = array_values(piloto_catalogo_consultas());
    foreach (piloto_catalogo_controles() as $sql) {
        $all[] = $sql;
    }
    $all[] = 'SET SESSION TRANSACTION READ ONLY';
    return $all;
}
