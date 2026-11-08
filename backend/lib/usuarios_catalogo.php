<?php
/**
 * Catálogos CERRADOS de SQL del modo «piloto_usuarios» (UX-07D.4B): usuarios reales de Cervesur, con permisos individuales, de solo lectura sobre la base de Flutter.
 *
 * TRES conexiones, cada una con su cuenta MySQL y su catálogo (cualquier otro SQL se rechaza antes de llegar a MySQL):
 *   1) AUTENTICACIÓN  cervesur_ro_auth      SELECT por columna SOLO sobre la tabla user (9 columnas). Es la única que puede leer la contraseña (hash) y solo para
 *                                          password_verify y para ligar la sesión a la contraseña vigente; el hash no se copia, no se devuelve y no se registra.
 *   2) CATÁLOGO       cervesur_ro_catalogo  SELECT por columna sobre empresa, reporte, empresa_reporte, empresa_usuario y user_empresa_reporte (21 columnas). NO puede
 *                                          leer la tabla user ni credenciales de Power BI. `reporte.enlace` solo se lee en la consulta del visor.
 *   3) ESTADO PROPIO  cervesur_react_estado SELECT/INSERT/UPDATE/DELETE únicamente en la base de estado del piloto (sesiones, lista blanca, intentos, auditoría).
 *                                          Es la ÚNICA conexión que escribe y jamás apunta a cervesur_portalbi.
 *
 * Un usuario ve un informe solo si TODA la cadena está activa: user_empresa_reporte (su asignación) → empresa_reporte → empresa → empresa_usuario (su relación con la
 * empresa) → reporte. El estado del propio usuario (activo, rol, session_version) lo revalida la conexión de autenticación en cada petición.
 */
const USUARIOS_MAX_PER_PAGE = 100;

/** Cadena de autorización compartida. El último `?` de cada consulta es siempre el id del usuario autenticado, salvo que se indique otro orden. */
function usuarios_cadena() {
    return 'FROM user_empresa_reporte uer'
        . ' INNER JOIN empresa_reporte er ON er.id = uer.empresa_reporte_id AND er.activo = 1'
        . ' INNER JOIN empresa e ON e.id = er.empresa_id AND e.activo = 1'
        . ' INNER JOIN empresa_usuario eu ON eu.empresa_id = e.id AND eu.user_id = uer.user_id AND eu.activo = 1'
        . ' INNER JOIN reporte r ON r.id = er.reporte_id AND r.activo = 1'
        . ' WHERE uer.activo = 1 AND uer.user_id = ?';
}

/** @return array<string,string> */
function usuarios_catalogo_consultas() {
    $c = usuarios_cadena();
    return [
        'reportes_total' => "SELECT COUNT(DISTINCT r.id) $c",
        'reportes_pagina' => "SELECT DISTINCT r.id, r.reporte_id, r.nombre, r.descripcion, r.activo $c ORDER BY r.id LIMIT ? OFFSET ?",
        'reporte_detalle' => "SELECT DISTINCT r.id, r.reporte_id, r.nombre, r.descripcion, r.activo $c AND r.id = ?",
        'reporte_visor' => "SELECT DISTINCT r.id, r.reporte_id, r.nombre, r.descripcion, r.enlace $c AND r.id = ?",
        'empresas_total' => 'SELECT COUNT(*) FROM empresa_usuario eu INNER JOIN empresa e ON e.id = eu.empresa_id AND e.activo = 1 WHERE eu.activo = 1 AND eu.user_id = ?',
        'empresas_pagina' => 'SELECT e.id, e.nombre, e.ciudad, e.ruc, e.activo FROM empresa_usuario eu INNER JOIN empresa e ON e.id = eu.empresa_id AND e.activo = 1 WHERE eu.activo = 1 AND eu.user_id = ? ORDER BY e.id LIMIT ? OFFSET ?',
        'relaciones_total' => "SELECT COUNT(DISTINCT er.id) $c",
        'relaciones_pagina' => "SELECT DISTINCT er.id, er.empresa_id, er.reporte_id, er.activo $c ORDER BY er.id LIMIT ? OFFSET ?",
    ];
}

/** Sentencias de control comunes a las tres conexiones. */
function usuarios_catalogo_controles() {
    return [
        'sesion_solo_lectura' => 'SELECT @@session.transaction_read_only',
        'privilegios_de_la_cuenta' => 'SHOW GRANTS FOR CURRENT_USER()',
        'base_conectada' => 'SELECT DATABASE()',
    ];
}

function usuarios_catalogo_sentencias() {
    $all = array_values(usuarios_catalogo_consultas());
    foreach (usuarios_catalogo_controles() as $sql) { $all[] = $sql; }
    $all[] = 'SET SESSION TRANSACTION READ ONLY';
    return $all;
}

/** @return array<string,string> Consultas de la conexión de AUTENTICACIÓN (tabla user, 9 columnas). */
function usuarios_auth_consultas() {
    return [
        'usuario_por_login' => 'SELECT id, username, email, password, first_name, last_name, role, is_active, session_version FROM user WHERE username = ? OR email = ? ORDER BY id LIMIT 1',
        'usuario_por_id' => 'SELECT id, username, email, password, first_name, last_name, role, is_active, session_version FROM user WHERE id = ?',
    ];
}

function usuarios_auth_sentencias() {
    $all = array_values(usuarios_auth_consultas());
    foreach (usuarios_catalogo_controles() as $sql) { $all[] = $sql; }
    $all[] = 'SET SESSION TRANSACTION READ ONLY';
    return $all;
}
