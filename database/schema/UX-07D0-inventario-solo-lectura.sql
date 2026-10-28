-- UX-07D.0 · Inventario de SOLO LECTURA para contrastar la base productiva con lo que exige el portal React. Solo SELECT.
-- Devuelve únicamente estructura y recuentos agregados: sin nombres, correos, teléfonos ni enlaces.
-- NO ejecutar contra MySQL real sin autorización expresa; entregue solo la salida agregada.

-- 1) Tablas y columnas requeridas (compare con tests/isolated/seed.php)
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY, EXTRA
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('user', 'empresa', 'reporte', 'empresa_usuario', 'empresa_reporte', 'user_empresa_reporte')
ORDER BY TABLE_NAME, ORDINAL_POSITION;

-- 2) Claves primarias, únicas y foráneas
SELECT TABLE_NAME, CONSTRAINT_NAME, CONSTRAINT_TYPE FROM information_schema.TABLE_CONSTRAINTS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('user', 'empresa', 'reporte', 'empresa_usuario', 'empresa_reporte', 'user_empresa_reporte') ORDER BY TABLE_NAME;
SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE
WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL AND TABLE_NAME IN ('empresa_usuario', 'empresa_reporte', 'user_empresa_reporte');

-- 3) Roles y estados de usuarios (agregado). Los roles distintos de admin/user/usuario NO podrán iniciar sesión (SEC-05A).
SELECT CONCAT('[', role, ']') AS rol_exacto, COUNT(*) AS usuarios, SUM(is_active = 1) AS activos, SUM(is_active = 0) AS inactivos,
  CASE WHEN LOWER(TRIM(role)) IN ('admin', 'user', 'usuario') THEN 'ACEPTADO' ELSE 'RECHAZADO' END AS decision
FROM `user` GROUP BY role ORDER BY usuarios DESC;
SELECT COUNT(*) AS activos_que_perderian_acceso FROM `user` WHERE is_active = 1 AND (role IS NULL OR LOWER(TRIM(role)) NOT IN ('admin', 'user', 'usuario'));
SELECT COUNT(*) AS activos_con_session_version_invalida FROM `user` WHERE is_active = 1 AND (session_version IS NULL OR session_version < 1);

-- 4) Estados de empresas, informes y relaciones
SELECT 'empresa' AS tabla, COUNT(*) AS total, SUM(activo = 1) AS activos FROM empresa
UNION ALL SELECT 'reporte', COUNT(*), SUM(activo = 1) FROM reporte
UNION ALL SELECT 'empresa_usuario', COUNT(*), SUM(activo = 1) FROM empresa_usuario
UNION ALL SELECT 'empresa_reporte', COUNT(*), SUM(activo = 1) FROM empresa_reporte
UNION ALL SELECT 'user_empresa_reporte', COUNT(*), SUM(activo = 1) FROM user_empresa_reporte;

-- 5) Incompatibilidades con las validaciones nuevas
SELECT SUM(ruc NOT REGEXP '^[0-9]{11}$') AS empresas_ruc_no_11_digitos, SUM(CHAR_LENGTH(nombre) > 100) AS empresas_nombre_mayor_100 FROM empresa WHERE activo = 1;
SELECT COUNT(*) AS rucs_repetidos FROM (SELECT ruc FROM empresa WHERE activo = 1 GROUP BY ruc HAVING COUNT(*) > 1) AS x;
SELECT COUNT(*) AS emails_repetidos FROM (SELECT LOWER(email) FROM `user` GROUP BY LOWER(email) HAVING COUNT(*) > 1) AS x;
SELECT COUNT(*) AS relaciones_empresa_reporte_duplicadas FROM (SELECT empresa_id, reporte_id FROM empresa_reporte WHERE activo = 1 GROUP BY empresa_id, reporte_id HAVING COUNT(*) > 1) AS x;

-- 6) grupo_id nulable y longitud real de enlace: ver database/schema/UX-07D0-precondiciones-migracion.sql
