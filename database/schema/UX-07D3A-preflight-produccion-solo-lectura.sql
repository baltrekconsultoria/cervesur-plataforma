-- >>> SQL DEL OPERADOR (UX-07D.3A.1). Lo ejecuta una persona AUTORIZADA con SU PROPIA cuenta de administración o de lectura, NUNCA la cuenta cervesur_ro_diag: esa cuenta no puede
-- >>> leer user.password ni information_schema de otras tablas y solo ejecuta el catálogo cerrado de database/schema/UX-07D3A1-consultas-cuenta-diagnostica.sql.
-- >>> Este preflight es previo al piloto y no forma parte de la aplicación web.
-- UX-07D.3A · PREFLIGHT PRODUCTIVO para el piloto de solo lectura. SOLO LECTURA (únicamente SELECT sobre las tablas del portal e information_schema).
-- Devuelve ESTADÍSTICAS AGREGADAS: nunca nombres, correos, teléfonos, RUC, enlaces, identificadores de informes ni hashes. NO se ha ejecutado contra ninguna
-- base real: requiere autorización y revisión humana. Debe ejecutarlo un administrador con una cuenta que pueda leer estas columnas (incluida user.password
-- solo para CONTAR el formato del algoritmo; el contenido del hash no se muestra). Las operaciones administrativas (CREATE USER / GRANT) NO están aquí:
-- están separadas en database/schema/admin-requiere-autorizacion/ y requieren autorización explícita.
-- Se espera que TODAS las filas de las secciones 1 a 4 digan «OK»; cualquier «DESVIACIÓN» se investiga antes de continuar.

-- 1) Compatibilidad de tablas y columnas: columnas que las consultas del modo de solo lectura necesitan y que NO existen (esperado: 0 filas)
SELECT 'columna requerida ausente' AS control, e.tabla, e.columna
FROM (
    SELECT 'empresa' AS tabla, 'activo' AS columna
    UNION ALL SELECT 'empresa' AS tabla, 'ciudad' AS columna
    UNION ALL SELECT 'empresa' AS tabla, 'contacto_principal' AS columna
    UNION ALL SELECT 'empresa' AS tabla, 'created_at' AS columna
    UNION ALL SELECT 'empresa' AS tabla, 'created_by' AS columna
    UNION ALL SELECT 'empresa' AS tabla, 'direccion' AS columna
    UNION ALL SELECT 'empresa' AS tabla, 'email' AS columna
    UNION ALL SELECT 'empresa' AS tabla, 'id' AS columna
    UNION ALL SELECT 'empresa' AS tabla, 'logo' AS columna
    UNION ALL SELECT 'empresa' AS tabla, 'nombre' AS columna
    UNION ALL SELECT 'empresa' AS tabla, 'pais' AS columna
    UNION ALL SELECT 'empresa' AS tabla, 'razon_social' AS columna
    UNION ALL SELECT 'empresa' AS tabla, 'ruc' AS columna
    UNION ALL SELECT 'empresa' AS tabla, 'telefono' AS columna
    UNION ALL SELECT 'empresa' AS tabla, 'updated_at' AS columna
    UNION ALL SELECT 'empresa' AS tabla, 'updated_by' AS columna
    UNION ALL SELECT 'empresa' AS tabla, 'website' AS columna
    UNION ALL SELECT 'empresa_reporte' AS tabla, 'activo' AS columna
    UNION ALL SELECT 'empresa_reporte' AS tabla, 'created_at' AS columna
    UNION ALL SELECT 'empresa_reporte' AS tabla, 'created_by' AS columna
    UNION ALL SELECT 'empresa_reporte' AS tabla, 'empresa_id' AS columna
    UNION ALL SELECT 'empresa_reporte' AS tabla, 'id' AS columna
    UNION ALL SELECT 'empresa_reporte' AS tabla, 'notas' AS columna
    UNION ALL SELECT 'empresa_reporte' AS tabla, 'reporte_id' AS columna
    UNION ALL SELECT 'empresa_reporte' AS tabla, 'updated_at' AS columna
    UNION ALL SELECT 'empresa_reporte' AS tabla, 'updated_by' AS columna
    UNION ALL SELECT 'empresa_usuario' AS tabla, 'activo' AS columna
    UNION ALL SELECT 'empresa_usuario' AS tabla, 'created_at' AS columna
    UNION ALL SELECT 'empresa_usuario' AS tabla, 'created_by' AS columna
    UNION ALL SELECT 'empresa_usuario' AS tabla, 'empresa_id' AS columna
    UNION ALL SELECT 'empresa_usuario' AS tabla, 'id' AS columna
    UNION ALL SELECT 'empresa_usuario' AS tabla, 'notas' AS columna
    UNION ALL SELECT 'empresa_usuario' AS tabla, 'updated_at' AS columna
    UNION ALL SELECT 'empresa_usuario' AS tabla, 'updated_by' AS columna
    UNION ALL SELECT 'empresa_usuario' AS tabla, 'user_id' AS columna
    UNION ALL SELECT 'reporte' AS tabla, 'activo' AS columna
    UNION ALL SELECT 'reporte' AS tabla, 'created_at' AS columna
    UNION ALL SELECT 'reporte' AS tabla, 'created_by' AS columna
    UNION ALL SELECT 'reporte' AS tabla, 'descripcion' AS columna
    UNION ALL SELECT 'reporte' AS tabla, 'enlace' AS columna
    UNION ALL SELECT 'reporte' AS tabla, 'grupo_id' AS columna
    UNION ALL SELECT 'reporte' AS tabla, 'id' AS columna
    UNION ALL SELECT 'reporte' AS tabla, 'nombre' AS columna
    UNION ALL SELECT 'reporte' AS tabla, 'reporte_id' AS columna
    UNION ALL SELECT 'reporte' AS tabla, 'updated_at' AS columna
    UNION ALL SELECT 'reporte' AS tabla, 'updated_by' AS columna
    UNION ALL SELECT 'user' AS tabla, 'avatar' AS columna
    UNION ALL SELECT 'user' AS tabla, 'created_at' AS columna
    UNION ALL SELECT 'user' AS tabla, 'email' AS columna
    UNION ALL SELECT 'user' AS tabla, 'first_name' AS columna
    UNION ALL SELECT 'user' AS tabla, 'id' AS columna
    UNION ALL SELECT 'user' AS tabla, 'is_active' AS columna
    UNION ALL SELECT 'user' AS tabla, 'last_login' AS columna
    UNION ALL SELECT 'user' AS tabla, 'last_name' AS columna
    UNION ALL SELECT 'user' AS tabla, 'password' AS columna
    UNION ALL SELECT 'user' AS tabla, 'phone' AS columna
    UNION ALL SELECT 'user' AS tabla, 'role' AS columna
    UNION ALL SELECT 'user' AS tabla, 'session_version' AS columna
    UNION ALL SELECT 'user' AS tabla, 'updated_at' AS columna
    UNION ALL SELECT 'user' AS tabla, 'username' AS columna
    UNION ALL SELECT 'user_empresa_reporte' AS tabla, 'activo' AS columna
    UNION ALL SELECT 'user_empresa_reporte' AS tabla, 'created_at' AS columna
    UNION ALL SELECT 'user_empresa_reporte' AS tabla, 'created_by' AS columna
    UNION ALL SELECT 'user_empresa_reporte' AS tabla, 'empresa_reporte_id' AS columna
    UNION ALL SELECT 'user_empresa_reporte' AS tabla, 'id' AS columna
    UNION ALL SELECT 'user_empresa_reporte' AS tabla, 'notas' AS columna
    UNION ALL SELECT 'user_empresa_reporte' AS tabla, 'updated_at' AS columna
    UNION ALL SELECT 'user_empresa_reporte' AS tabla, 'updated_by' AS columna
    UNION ALL SELECT 'user_empresa_reporte' AS tabla, 'user_id' AS columna
) AS e
LEFT JOIN information_schema.COLUMNS c ON c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = e.tabla AND c.COLUMN_NAME = e.columna
WHERE c.COLUMN_NAME IS NULL;

-- 2) Cantidades reales frente al inventario confirmado (usuarios, empresas, informes, relaciones, permisos efectivos)
SELECT control, esperado, observado, IF(esperado = observado, 'OK', 'DESVIACIÓN') AS resultado FROM (
  SELECT 'usuarios_total' AS control, 76 AS esperado, (SELECT COUNT(*) FROM `user`) AS observado
  UNION ALL SELECT 'usuarios_activos' AS control, 62 AS esperado, (SELECT COUNT(*) FROM `user` WHERE is_active = 1) AS observado
  UNION ALL SELECT 'usuarios_rol_usuario' AS control, 62 AS esperado, (SELECT COUNT(*) FROM `user` WHERE role = 'usuario') AS observado
  UNION ALL SELECT 'usuarios_rol_usuario_activos' AS control, 61 AS esperado, (SELECT COUNT(*) FROM `user` WHERE role = 'usuario' AND is_active = 1) AS observado
  UNION ALL SELECT 'usuarios_rol_user' AS control, 10 AS esperado, (SELECT COUNT(*) FROM `user` WHERE role = 'user') AS observado
  UNION ALL SELECT 'usuarios_rol_user_activos' AS control, 0 AS esperado, (SELECT COUNT(*) FROM `user` WHERE role = 'user' AND is_active = 1) AS observado
  UNION ALL SELECT 'usuarios_rol_admin' AS control, 4 AS esperado, (SELECT COUNT(*) FROM `user` WHERE role = 'admin') AS observado
  UNION ALL SELECT 'usuarios_rol_admin_activos' AS control, 1 AS esperado, (SELECT COUNT(*) FROM `user` WHERE role = 'admin' AND is_active = 1) AS observado
  UNION ALL SELECT 'usuarios_activos_con_session_version_invalida' AS control, 0 AS esperado, (SELECT COUNT(*) FROM `user` WHERE is_active = 1 AND (session_version IS NULL OR session_version < 1)) AS observado
  UNION ALL SELECT 'usuarios_activos_con_rol_no_reconocido' AS control, 0 AS esperado, (SELECT COUNT(*) FROM `user` WHERE is_active = 1 AND (role IS NULL OR LOWER(TRIM(role)) NOT IN ('admin', 'user', 'usuario'))) AS observado
  UNION ALL SELECT 'empresas_total' AS control, 26 AS esperado, (SELECT COUNT(*) FROM empresa) AS observado
  UNION ALL SELECT 'empresas_activas' AS control, 6 AS esperado, (SELECT COUNT(*) FROM empresa WHERE activo = 1) AS observado
  UNION ALL SELECT 'informes_total' AS control, 95 AS esperado, (SELECT COUNT(*) FROM reporte) AS observado
  UNION ALL SELECT 'informes_activos' AS control, 82 AS esperado, (SELECT COUNT(*) FROM reporte WHERE activo = 1) AS observado
  UNION ALL SELECT 'empresa_usuario_total' AS control, 82 AS esperado, (SELECT COUNT(*) FROM empresa_usuario) AS observado
  UNION ALL SELECT 'empresa_usuario_activas' AS control, 79 AS esperado, (SELECT COUNT(*) FROM empresa_usuario WHERE activo = 1) AS observado
  UNION ALL SELECT 'empresa_reporte_total' AS control, 103 AS esperado, (SELECT COUNT(*) FROM empresa_reporte) AS observado
  UNION ALL SELECT 'empresa_reporte_activas' AS control, 100 AS esperado, (SELECT COUNT(*) FROM empresa_reporte WHERE activo = 1) AS observado
  UNION ALL SELECT 'user_empresa_reporte_total' AS control, 102 AS esperado, (SELECT COUNT(*) FROM user_empresa_reporte) AS observado
  UNION ALL SELECT 'user_empresa_reporte_activas' AS control, 93 AS esperado, (SELECT COUNT(*) FROM user_empresa_reporte WHERE activo = 1) AS observado
  UNION ALL SELECT 'relaciones_empresa_informe_vigentes' AS control, 84 AS esperado, (SELECT COUNT(*) FROM empresa_reporte er JOIN empresa e ON e.id = er.empresa_id AND e.activo = 1 JOIN reporte r ON r.id = er.reporte_id AND r.activo = 1 WHERE er.activo = 1) AS observado
  UNION ALL SELECT 'informes_vinculados' AS control, 82 AS esperado, (SELECT COUNT(DISTINCT er.reporte_id) FROM empresa_reporte er JOIN empresa e ON e.id = er.empresa_id AND e.activo = 1 JOIN reporte r ON r.id = er.reporte_id AND r.activo = 1 WHERE er.activo = 1) AS observado
  UNION ALL SELECT 'empresas_con_informes_vigentes' AS control, 5 AS esperado, (SELECT COUNT(DISTINCT er.empresa_id) FROM empresa_reporte er JOIN empresa e ON e.id = er.empresa_id AND e.activo = 1 JOIN reporte r ON r.id = er.reporte_id AND r.activo = 1 WHERE er.activo = 1) AS observado
  UNION ALL SELECT 'permisos_efectivos' AS control, 91 AS esperado, (SELECT COUNT(*) FROM user_empresa_reporte uer JOIN `user` u ON u.id = uer.user_id AND u.is_active = 1 JOIN empresa_reporte er ON er.id = uer.empresa_reporte_id AND er.activo = 1 JOIN empresa e ON e.id = er.empresa_id AND e.activo = 1 JOIN empresa_usuario eu ON eu.empresa_id = e.id AND eu.user_id = u.id AND eu.activo = 1 JOIN reporte r ON r.id = er.reporte_id AND r.activo = 1 WHERE uer.activo = 1) AS observado
  UNION ALL SELECT 'usuarios_con_acceso_efectivo' AS control, 61 AS esperado, (SELECT COUNT(DISTINCT u.id) FROM user_empresa_reporte uer JOIN `user` u ON u.id = uer.user_id AND u.is_active = 1 JOIN empresa_reporte er ON er.id = uer.empresa_reporte_id AND er.activo = 1 JOIN empresa e ON e.id = er.empresa_id AND e.activo = 1 JOIN empresa_usuario eu ON eu.empresa_id = e.id AND eu.user_id = u.id AND eu.activo = 1 JOIN reporte r ON r.id = er.reporte_id AND r.activo = 1 WHERE uer.activo = 1) AS observado
  UNION ALL SELECT 'informes_con_usuarios_autorizados' AS control, 79 AS esperado, (SELECT COUNT(DISTINCT r.id) FROM user_empresa_reporte uer JOIN `user` u ON u.id = uer.user_id AND u.is_active = 1 JOIN empresa_reporte er ON er.id = uer.empresa_reporte_id AND er.activo = 1 JOIN empresa e ON e.id = er.empresa_id AND e.activo = 1 JOIN empresa_usuario eu ON eu.empresa_id = e.id AND eu.user_id = u.id AND eu.activo = 1 JOIN reporte r ON r.id = er.reporte_id AND r.activo = 1 WHERE uer.activo = 1) AS observado
  UNION ALL SELECT 'asignaciones_activas_de_usuarios_inactivos' AS control, 2 AS esperado, (SELECT COUNT(*) FROM user_empresa_reporte uer JOIN `user` u ON u.id = uer.user_id WHERE uer.activo = 1 AND u.is_active = 0) AS observado
) AS x ORDER BY control;

-- 3) Formato de contraseñas (el login de React necesita hashes de password_hash() de PHP): solo el identificador del algoritmo y recuentos
SELECT 'usuarios cuyo hash NO es bcrypt ($2y$/$2a$/$2b$) o está vacío' AS control, 0 AS esperado, COUNT(*) AS observado,
       IF(COUNT(*) = 0, 'OK', 'DESVIACIÓN') AS resultado
FROM `user` WHERE `password` IS NULL OR `password` = '' OR LEFT(`password`, 4) NOT IN ('$2y$', '$2a$', '$2b$') OR CHAR_LENGTH(`password`) <> 60;

-- 4) Estado del servidor: si la sesión/servidor ya son de solo lectura (informativo) y la versión
SELECT VERSION() AS version_mysql, @@read_only AS servidor_read_only, @@transaction_read_only AS sesion_read_only, @@sql_mode AS sql_mode;

-- 5) Claves foráneas de las seis tablas y sus reglas (CASCADE / SET NULL): explican qué borraría en cadena una escritura. El piloto NO puede borrar nada.
SELECT TABLE_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, DELETE_RULE, UPDATE_RULE
FROM information_schema.REFERENTIAL_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME IN ('user', 'empresa', 'reporte', 'empresa_usuario', 'empresa_reporte', 'user_empresa_reporte')
ORDER BY TABLE_NAME, CONSTRAINT_NAME;

-- 6) Resumen de reglas: cuántas FK tienen cada regla de borrado (esperado: solo CASCADE / SET NULL / RESTRICT conocidos)
SELECT DELETE_RULE, COUNT(*) AS claves FROM information_schema.REFERENTIAL_CONSTRAINTS
WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME IN ('user', 'empresa', 'reporte', 'empresa_usuario', 'empresa_reporte', 'user_empresa_reporte') GROUP BY DELETE_RULE;

-- 7) Privilegios de las cuentas del piloto (DESPUÉS de que un administrador las cree con el procedimiento autorizado): ver
--    database/schema/admin-requiere-autorizacion/02-verificar-privilegios-diagnostico.sql y 02b-verificar-privilegios-piloto.sql (todo debe indicar «sin escritura»).
