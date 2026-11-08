<?php
/**
 * Catálogo CERRADO de las únicas sentencias SQL que el modo «diagnostico» puede ejecutar (UX-07D.3A.1).
 *
 * En ese modo la conexión rechaza cualquier SQL cuyo texto (con los espacios normalizados) no figure aquí, de modo que ni un error de código ni una
 * manipulación de la API pueden ejecutar SQL arbitrario. La lista de columnas que necesita la cuenta cervesur_ro_diag SE DEDUCE de este catálogo:
 * tests/isolated/privilegios_minimos_test.php comprueba que cada columna concedida es imprescindible y que ninguna otra hace falta.
 *
 * Cada medida declara su visibilidad:
 *   'publica'            se muestra el número (si es 0 o al menos APP_DIAG_MIN_CELL; una cifra de 1 a APP_DIAG_MIN_CELL-1 se oculta);
 *   'solo_coincidencia'  grupo pequeño o que permitiría deducir uno por diferencia: solo se informa SI coincide con el inventario, nunca su valor.
 * Las parejas de 'subconjuntos' ([conjunto, subconjunto]) se vigilan en ejecución: si su diferencia está entre 1 y APP_DIAG_MIN_CELL-1 se reservan ambas.
 */
const APP_DIAG_MIN_CELL = 5;

/** @return array<string,array{sql:string,visibilidad:string}> */
function diagnostico_catalogo_medidas() {
    $effective = 'FROM user_empresa_reporte uer'
        . ' INNER JOIN user u ON u.id = uer.user_id AND u.is_active = 1'
        . ' INNER JOIN empresa_reporte er ON er.id = uer.empresa_reporte_id AND er.activo = 1'
        . ' INNER JOIN empresa e ON e.id = er.empresa_id AND e.activo = 1'
        . ' INNER JOIN empresa_usuario eu ON eu.empresa_id = e.id AND eu.user_id = u.id AND eu.activo = 1'
        . ' INNER JOIN reporte r ON r.id = er.reporte_id AND r.activo = 1'
        . ' WHERE uer.activo = 1';
    $vigentes = 'FROM empresa_reporte er INNER JOIN empresa e ON e.id = er.empresa_id AND e.activo = 1'
        . ' INNER JOIN reporte r ON r.id = er.reporte_id AND r.activo = 1 WHERE er.activo = 1';
    $p = 'publica';
    $c = 'solo_coincidencia';
    return [
        'usuarios_total' => ['sql' => 'SELECT COUNT(*) FROM user', 'visibilidad' => $p],
        'usuarios_activos' => ['sql' => 'SELECT COUNT(*) FROM user WHERE is_active = 1', 'visibilidad' => $p],
        'usuarios_rol_usuario' => ['sql' => "SELECT COUNT(*) FROM user WHERE role = 'usuario'", 'visibilidad' => $c],
        'usuarios_rol_usuario_activos' => ['sql' => "SELECT COUNT(*) FROM user WHERE role = 'usuario' AND is_active = 1", 'visibilidad' => $c],
        'usuarios_rol_user' => ['sql' => "SELECT COUNT(*) FROM user WHERE role = 'user'", 'visibilidad' => $c],
        'usuarios_rol_user_activos' => ['sql' => "SELECT COUNT(*) FROM user WHERE role = 'user' AND is_active = 1", 'visibilidad' => $c],
        'usuarios_rol_admin' => ['sql' => "SELECT COUNT(*) FROM user WHERE role = 'admin'", 'visibilidad' => $c],
        'usuarios_rol_admin_activos' => ['sql' => "SELECT COUNT(*) FROM user WHERE role = 'admin' AND is_active = 1", 'visibilidad' => $c],
        'usuarios_activos_con_session_version_invalida' => ['sql' => 'SELECT COUNT(*) FROM user WHERE is_active = 1 AND (session_version IS NULL OR session_version < 1)', 'visibilidad' => $c],
        'usuarios_activos_con_rol_no_reconocido' => ['sql' => "SELECT COUNT(*) FROM user WHERE is_active = 1 AND (role IS NULL OR LOWER(TRIM(role)) NOT IN ('admin', 'user', 'usuario'))", 'visibilidad' => $c],
        'empresas_total' => ['sql' => 'SELECT COUNT(*) FROM empresa', 'visibilidad' => $p],
        'empresas_activas' => ['sql' => 'SELECT COUNT(*) FROM empresa WHERE activo = 1', 'visibilidad' => $p],
        'informes_total' => ['sql' => 'SELECT COUNT(*) FROM reporte', 'visibilidad' => $p],
        'informes_activos' => ['sql' => 'SELECT COUNT(*) FROM reporte WHERE activo = 1', 'visibilidad' => $p],
        'empresa_usuario_total' => ['sql' => 'SELECT COUNT(*) FROM empresa_usuario', 'visibilidad' => $p],
        'empresa_usuario_activas' => ['sql' => 'SELECT COUNT(*) FROM empresa_usuario WHERE activo = 1', 'visibilidad' => $p],
        'empresa_reporte_total' => ['sql' => 'SELECT COUNT(*) FROM empresa_reporte', 'visibilidad' => $p],
        'empresa_reporte_activas' => ['sql' => 'SELECT COUNT(*) FROM empresa_reporte WHERE activo = 1', 'visibilidad' => $p],
        'user_empresa_reporte_total' => ['sql' => 'SELECT COUNT(*) FROM user_empresa_reporte', 'visibilidad' => $p],
        'user_empresa_reporte_activas' => ['sql' => 'SELECT COUNT(*) FROM user_empresa_reporte WHERE activo = 1', 'visibilidad' => $p],
        'relaciones_empresa_informe_vigentes' => ['sql' => "SELECT COUNT(*) $vigentes", 'visibilidad' => $p],
        'informes_vinculados' => ['sql' => "SELECT COUNT(DISTINCT er.reporte_id) $vigentes", 'visibilidad' => $p],
        'empresas_con_informes_vigentes' => ['sql' => "SELECT COUNT(DISTINCT er.empresa_id) $vigentes", 'visibilidad' => $p],
        'permisos_efectivos' => ['sql' => "SELECT COUNT(*) $effective", 'visibilidad' => $p],
        'usuarios_con_acceso_efectivo' => ['sql' => "SELECT COUNT(DISTINCT u.id) $effective", 'visibilidad' => $p],
        'informes_con_usuarios_autorizados' => ['sql' => "SELECT COUNT(DISTINCT r.id) $effective", 'visibilidad' => $p],
        'asignaciones_activas_de_usuarios_inactivos' => ['sql' => 'SELECT COUNT(*) FROM user_empresa_reporte uer INNER JOIN user u ON u.id = uer.user_id WHERE uer.activo = 1 AND u.is_active = 0', 'visibilidad' => $c],
    ];
}

/** Parejas [conjunto, subconjunto] cuya diferencia, si fuese un grupo pequeño, identificaría a unos pocos registros. */
function diagnostico_catalogo_subconjuntos() {
    return [
        ['usuarios_total', 'usuarios_activos'], ['empresas_total', 'empresas_activas'], ['informes_total', 'informes_activos'],
        ['empresa_usuario_total', 'empresa_usuario_activas'], ['empresa_reporte_total', 'empresa_reporte_activas'], ['user_empresa_reporte_total', 'user_empresa_reporte_activas'],
        ['usuarios_activos', 'usuarios_con_acceso_efectivo'], ['empresas_activas', 'empresas_con_informes_vigentes'], ['informes_activos', 'informes_vinculados'],
        ['informes_activos', 'informes_con_usuarios_autorizados'], ['informes_vinculados', 'informes_con_usuarios_autorizados'],
        ['empresa_reporte_activas', 'relaciones_empresa_informe_vigentes'], ['user_empresa_reporte_activas', 'permisos_efectivos'],
    ];
}

/** Sentencias de control (sin tablas del portal). */
function diagnostico_catalogo_controles() {
    return [
        'sesion_solo_lectura' => 'SELECT @@session.transaction_read_only',
        'privilegios_de_la_cuenta' => 'SHOW GRANTS FOR CURRENT_USER()',
        'base_conectada' => 'SELECT DATABASE()',
    ];
}

/** Todas las sentencias autorizadas en diagnóstico (incluye la de arranque que fija la sesión en solo lectura). */
function diagnostico_catalogo_sentencias() {
    $all = array_column(diagnostico_catalogo_medidas(), 'sql');
    foreach (diagnostico_catalogo_controles() as $sql) {
        $all[] = $sql;
    }
    $all[] = 'SET SESSION TRANSACTION READ ONLY';
    return $all;
}
