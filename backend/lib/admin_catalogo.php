<?php
/**
 * Catálogos CERRADOS de SQL del modo «piloto_admin» (UX-07E.B): consulta administrativa de SOLO LECTURA sobre la base de Flutter.
 *
 * Reutiliza el diseño de tres conexiones del piloto de usuarios, con cuentas MySQL PROPIAS de esta instancia (mismos privilegios mínimos que las del piloto de usuarios):
 *   1) AUTENTICACIÓN  SELECT por columna sobre user (9 columnas). El listado administrativo NUNCA selecciona `password` ni `session_version`.
 *   2) CATÁLOGO       SELECT por columna sobre empresa, reporte, empresa_reporte, empresa_usuario y user_empresa_reporte (21 columnas). No ve la tabla user.
 *   3) ESTADO PROPIO  SELECT/INSERT/UPDATE/DELETE solo en la base de estado de esta instancia (lista de administradores autorizados, sesiones, intentos, auditoría).
 * Ninguna sentencia escribe en la base de Flutter: ninguna cuenta de esta instancia tiene INSERT/UPDATE/DELETE sobre cervesur_portalbi.
 *
 * Las filas con la forma del backend de Flutter (mismas rutas y campos) para que el frontend administrativo existente funcione sin cambios. Donde la tabla no tiene
 * `id` propio (empresa_usuario, user_empresa_reporte), el `id` del listado es una clave compuesta «usuario-empresa» / «usuario-relación»: sirve para mostrar, no para escribir.
 */
require_once __DIR__ . '/usuarios_catalogo.php';

const ADMIN_MAX_PER_PAGE = 100;

/** @return array<string,string> Consultas de la conexión de CATÁLOGO. */
function admin_catalogo_consultas() {
    return [
        'empresas_total' => 'SELECT COUNT(*) FROM empresa',
        'empresas_pagina' => 'SELECT id, nombre, ciudad, ruc, activo FROM empresa ORDER BY id LIMIT ? OFFSET ?',
        'reportes_total' => 'SELECT COUNT(*) FROM reporte',
        'reportes_pagina' => 'SELECT id, reporte_id, nombre, descripcion, activo FROM reporte ORDER BY id LIMIT ? OFFSET ?',
        'reporte_detalle' => 'SELECT id, reporte_id, nombre, descripcion, activo FROM reporte WHERE id = ?',
        // UX-08G: un informe INACTIVO no entrega su URL aunque su id esté en la lista autorizada (igual que el piloto visual): la consulta no lo devuelve y el visor responde 404.
        'reporte_visor' => 'SELECT id, reporte_id, nombre, descripcion, enlace FROM reporte WHERE id = ? AND activo = 1',
        'relaciones_total' => 'SELECT COUNT(*) FROM empresa_reporte',
        'relaciones_pagina' => 'SELECT id, empresa_id, reporte_id, activo FROM empresa_reporte ORDER BY id LIMIT ? OFFSET ?',
        'empresa_usuario_total' => 'SELECT COUNT(*) FROM empresa_usuario WHERE activo = 1',
        'empresa_usuario_pagina' => "SELECT CONCAT(user_id, '-', empresa_id) AS id, user_id, empresa_id, activo FROM empresa_usuario WHERE activo = 1 ORDER BY user_id, empresa_id LIMIT ? OFFSET ?",
        'accesos_total' => 'SELECT COUNT(*) FROM user_empresa_reporte uer INNER JOIN empresa_reporte er ON er.id = uer.empresa_reporte_id WHERE uer.activo = 1',
        'accesos_pagina' => "SELECT CONCAT(uer.user_id, '-', uer.empresa_reporte_id) AS id, uer.user_id, er.empresa_id, uer.empresa_reporte_id, r.reporte_id FROM user_empresa_reporte uer INNER JOIN empresa_reporte er ON er.id = uer.empresa_reporte_id INNER JOIN reporte r ON r.id = er.reporte_id WHERE uer.activo = 1 ORDER BY uer.user_id, uer.empresa_reporte_id LIMIT ? OFFSET ?",
    ];
}

function admin_catalogo_sentencias() {
    $all = array_values(admin_catalogo_consultas());
    foreach (usuarios_catalogo_controles() as $sql) { $all[] = $sql; }
    $all[] = 'SET SESSION TRANSACTION READ ONLY';
    return $all;
}

/** @return array<string,string> Consultas de la conexión de AUTENTICACIÓN: el inicio de sesión (como el piloto de usuarios) y el listado SIN contraseña. */
function admin_auth_consultas() {
    return usuarios_auth_consultas() + [
        'usuarios_total' => 'SELECT COUNT(*) FROM user WHERE is_active IN (?, ?)',
        'usuarios_pagina' => 'SELECT id, username, email, first_name, last_name, role, is_active FROM user WHERE is_active IN (?, ?) ORDER BY id LIMIT ? OFFSET ?',
    ];
}

function admin_auth_sentencias() {
    $all = array_values(admin_auth_consultas());
    foreach (usuarios_catalogo_controles() as $sql) { $all[] = $sql; }
    $all[] = 'SET SESSION TRANSACTION READ ONLY';
    return $all;
}
