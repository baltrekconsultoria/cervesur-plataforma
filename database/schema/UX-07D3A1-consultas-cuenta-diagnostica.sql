-- UX-07D.3A.1 · SQL que ejecutará la CUENTA DE DIAGNÓSTICO (cervesur_ro_diag), generado desde backend/lib/diagnostico_catalogo.php. NO es un script para ejecutar a mano.
-- Es el catálogo CERRADO: la conexión del modo «diagnostico» rechaza cualquier otra sentencia. Se publica para que el operador autorizado lo revise junto a los GRANT de
-- admin-requiere-autorizacion/01-cuenta-diagnostico.sql. Todo son recuentos (COUNT) sobre las 18 columnas concedidas: ningún valor individual sale de la base.
-- El SQL del OPERADOR (preflight, verificación de privilegios y de actividad) está en otros archivos y lo ejecuta una cuenta distinta, nunca esta.
-- Una prueba estática del CI comprueba que este archivo contiene EXACTAMENTE el catálogo.

-- usuarios_total  (se publica si no es un grupo pequeño)
SELECT COUNT(*) FROM user;

-- usuarios_activos  (se publica si no es un grupo pequeño)
SELECT COUNT(*) FROM user WHERE is_active = 1;

-- usuarios_rol_usuario  (solo se informa si coincide con el inventario)
SELECT COUNT(*) FROM user WHERE role = 'usuario';

-- usuarios_rol_usuario_activos  (solo se informa si coincide con el inventario)
SELECT COUNT(*) FROM user WHERE role = 'usuario' AND is_active = 1;

-- usuarios_rol_user  (solo se informa si coincide con el inventario)
SELECT COUNT(*) FROM user WHERE role = 'user';

-- usuarios_rol_user_activos  (solo se informa si coincide con el inventario)
SELECT COUNT(*) FROM user WHERE role = 'user' AND is_active = 1;

-- usuarios_rol_admin  (solo se informa si coincide con el inventario)
SELECT COUNT(*) FROM user WHERE role = 'admin';

-- usuarios_rol_admin_activos  (solo se informa si coincide con el inventario)
SELECT COUNT(*) FROM user WHERE role = 'admin' AND is_active = 1;

-- usuarios_activos_con_session_version_invalida  (solo se informa si coincide con el inventario)
SELECT COUNT(*) FROM user WHERE is_active = 1 AND (session_version IS NULL OR session_version < 1);

-- usuarios_activos_con_rol_no_reconocido  (solo se informa si coincide con el inventario)
SELECT COUNT(*) FROM user WHERE is_active = 1 AND (role IS NULL OR LOWER(TRIM(role)) NOT IN ('admin', 'user', 'usuario'));

-- empresas_total  (se publica si no es un grupo pequeño)
SELECT COUNT(*) FROM empresa;

-- empresas_activas  (se publica si no es un grupo pequeño)
SELECT COUNT(*) FROM empresa WHERE activo = 1;

-- informes_total  (se publica si no es un grupo pequeño)
SELECT COUNT(*) FROM reporte;

-- informes_activos  (se publica si no es un grupo pequeño)
SELECT COUNT(*) FROM reporte WHERE activo = 1;

-- empresa_usuario_total  (se publica si no es un grupo pequeño)
SELECT COUNT(*) FROM empresa_usuario;

-- empresa_usuario_activas  (se publica si no es un grupo pequeño)
SELECT COUNT(*) FROM empresa_usuario WHERE activo = 1;

-- empresa_reporte_total  (se publica si no es un grupo pequeño)
SELECT COUNT(*) FROM empresa_reporte;

-- empresa_reporte_activas  (se publica si no es un grupo pequeño)
SELECT COUNT(*) FROM empresa_reporte WHERE activo = 1;

-- user_empresa_reporte_total  (se publica si no es un grupo pequeño)
SELECT COUNT(*) FROM user_empresa_reporte;

-- user_empresa_reporte_activas  (se publica si no es un grupo pequeño)
SELECT COUNT(*) FROM user_empresa_reporte WHERE activo = 1;

-- relaciones_empresa_informe_vigentes  (se publica si no es un grupo pequeño)
SELECT COUNT(*) FROM empresa_reporte er INNER JOIN empresa e ON e.id = er.empresa_id AND e.activo = 1 INNER JOIN reporte r ON r.id = er.reporte_id AND r.activo = 1 WHERE er.activo = 1;

-- informes_vinculados  (se publica si no es un grupo pequeño)
SELECT COUNT(DISTINCT er.reporte_id) FROM empresa_reporte er INNER JOIN empresa e ON e.id = er.empresa_id AND e.activo = 1 INNER JOIN reporte r ON r.id = er.reporte_id AND r.activo = 1 WHERE er.activo = 1;

-- empresas_con_informes_vigentes  (se publica si no es un grupo pequeño)
SELECT COUNT(DISTINCT er.empresa_id) FROM empresa_reporte er INNER JOIN empresa e ON e.id = er.empresa_id AND e.activo = 1 INNER JOIN reporte r ON r.id = er.reporte_id AND r.activo = 1 WHERE er.activo = 1;

-- permisos_efectivos  (se publica si no es un grupo pequeño)
SELECT COUNT(*) FROM user_empresa_reporte uer INNER JOIN user u ON u.id = uer.user_id AND u.is_active = 1 INNER JOIN empresa_reporte er ON er.id = uer.empresa_reporte_id AND er.activo = 1 INNER JOIN empresa e ON e.id = er.empresa_id AND e.activo = 1 INNER JOIN empresa_usuario eu ON eu.empresa_id = e.id AND eu.user_id = u.id AND eu.activo = 1 INNER JOIN reporte r ON r.id = er.reporte_id AND r.activo = 1 WHERE uer.activo = 1;

-- usuarios_con_acceso_efectivo  (se publica si no es un grupo pequeño)
SELECT COUNT(DISTINCT u.id) FROM user_empresa_reporte uer INNER JOIN user u ON u.id = uer.user_id AND u.is_active = 1 INNER JOIN empresa_reporte er ON er.id = uer.empresa_reporte_id AND er.activo = 1 INNER JOIN empresa e ON e.id = er.empresa_id AND e.activo = 1 INNER JOIN empresa_usuario eu ON eu.empresa_id = e.id AND eu.user_id = u.id AND eu.activo = 1 INNER JOIN reporte r ON r.id = er.reporte_id AND r.activo = 1 WHERE uer.activo = 1;

-- informes_con_usuarios_autorizados  (se publica si no es un grupo pequeño)
SELECT COUNT(DISTINCT r.id) FROM user_empresa_reporte uer INNER JOIN user u ON u.id = uer.user_id AND u.is_active = 1 INNER JOIN empresa_reporte er ON er.id = uer.empresa_reporte_id AND er.activo = 1 INNER JOIN empresa e ON e.id = er.empresa_id AND e.activo = 1 INNER JOIN empresa_usuario eu ON eu.empresa_id = e.id AND eu.user_id = u.id AND eu.activo = 1 INNER JOIN reporte r ON r.id = er.reporte_id AND r.activo = 1 WHERE uer.activo = 1;

-- asignaciones_activas_de_usuarios_inactivos  (solo se informa si coincide con el inventario)
SELECT COUNT(*) FROM user_empresa_reporte uer INNER JOIN user u ON u.id = uer.user_id WHERE uer.activo = 1 AND u.is_active = 0;

-- controles de arranque y de estado
-- sesion_solo_lectura
SELECT @@session.transaction_read_only;

-- privilegios_de_la_cuenta
SHOW GRANTS FOR CURRENT_USER();

-- base_conectada
SELECT DATABASE();

-- fijación de la sesión en solo lectura
SET SESSION TRANSACTION READ ONLY;
