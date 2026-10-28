-- UX-07D.3A · Comparación ESTRUCTURAL entre la base real (Flutter) y lo que exige el backend de React. SOLO LECTURA: únicamente SELECT sobre
-- information_schema, variables del servidor y AGREGADOS. No devuelve nombres, correos, teléfonos, RUC, enlaces, identificadores de informes ni contenido
-- de contraseñas (solo el identificador del algoritmo y la longitud). NO se ha ejecutado contra ninguna base real: requiere autorización y revisión humana.
-- Pensado para ejecutarse con una cuenta de solo lectura sobre la base productiva (`cervesur_portalbi`). La salida se entrega ya agregada.

-- 1) Servidor y modo SQL (el modo estricto decide si un valor demasiado largo falla o se trunca)
SELECT VERSION() AS version_mysql, @@sql_mode AS sql_mode, @@character_set_database AS charset_bd, @@collation_database AS collation_bd, @@transaction_isolation AS aislamiento;

-- 2) TODAS las tablas de la base (React solo usa seis; así se descubren las que usa Flutter y React ignora). Metadatos, sin contenido.
SELECT TABLE_NAME, TABLE_TYPE, ENGINE, TABLE_COLLATION, TABLE_ROWS AS filas_aprox, AUTO_INCREMENT, ROUND((DATA_LENGTH + INDEX_LENGTH) / 1048576, 2) AS mb
FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME;

-- 3) Columnas que el backend de React espera (derivadas del DDL del fixture) y que NO existen en la base real
SELECT e.tabla, e.columna AS columna_ausente_en_la_base_real
FROM (
  SELECT 'user' AS tabla, 'id' AS columna
  UNION ALL SELECT 'user' AS tabla, 'username' AS columna
  UNION ALL SELECT 'user' AS tabla, 'email' AS columna
  UNION ALL SELECT 'user' AS tabla, 'password' AS columna
  UNION ALL SELECT 'user' AS tabla, 'first_name' AS columna
  UNION ALL SELECT 'user' AS tabla, 'last_name' AS columna
  UNION ALL SELECT 'user' AS tabla, 'phone' AS columna
  UNION ALL SELECT 'user' AS tabla, 'avatar' AS columna
  UNION ALL SELECT 'user' AS tabla, 'role' AS columna
  UNION ALL SELECT 'user' AS tabla, 'is_active' AS columna
  UNION ALL SELECT 'user' AS tabla, 'session_version' AS columna
  UNION ALL SELECT 'user' AS tabla, 'last_login' AS columna
  UNION ALL SELECT 'user' AS tabla, 'created_at' AS columna
  UNION ALL SELECT 'user' AS tabla, 'updated_at' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'id' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'nombre' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'razon_social' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'ruc' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'email' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'telefono' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'website' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'direccion' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'ciudad' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'pais' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'powerbi_tenant' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'powerbi_client' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'powerbi_clientsecret' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'logo' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'contacto_principal' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'created_by' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'updated_by' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'created_at' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'updated_at' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'activo' AS columna
  UNION ALL SELECT 'reporte' AS tabla, 'id' AS columna
  UNION ALL SELECT 'reporte' AS tabla, 'nombre' AS columna
  UNION ALL SELECT 'reporte' AS tabla, 'descripcion' AS columna
  UNION ALL SELECT 'reporte' AS tabla, 'enlace' AS columna
  UNION ALL SELECT 'reporte' AS tabla, 'grupo_id' AS columna
  UNION ALL SELECT 'reporte' AS tabla, 'reporte_id' AS columna
  UNION ALL SELECT 'reporte' AS tabla, 'created_by' AS columna
  UNION ALL SELECT 'reporte' AS tabla, 'updated_by' AS columna
  UNION ALL SELECT 'reporte' AS tabla, 'created_at' AS columna
  UNION ALL SELECT 'reporte' AS tabla, 'updated_at' AS columna
  UNION ALL SELECT 'reporte' AS tabla, 'activo' AS columna
  UNION ALL SELECT 'empresa_usuario' AS tabla, 'id' AS columna
  UNION ALL SELECT 'empresa_usuario' AS tabla, 'empresa_id' AS columna
  UNION ALL SELECT 'empresa_usuario' AS tabla, 'user_id' AS columna
  UNION ALL SELECT 'empresa_usuario' AS tabla, 'notas' AS columna
  UNION ALL SELECT 'empresa_usuario' AS tabla, 'created_by' AS columna
  UNION ALL SELECT 'empresa_usuario' AS tabla, 'updated_by' AS columna
  UNION ALL SELECT 'empresa_usuario' AS tabla, 'created_at' AS columna
  UNION ALL SELECT 'empresa_usuario' AS tabla, 'updated_at' AS columna
  UNION ALL SELECT 'empresa_usuario' AS tabla, 'activo' AS columna
  UNION ALL SELECT 'empresa_reporte' AS tabla, 'id' AS columna
  UNION ALL SELECT 'empresa_reporte' AS tabla, 'empresa_id' AS columna
  UNION ALL SELECT 'empresa_reporte' AS tabla, 'reporte_id' AS columna
  UNION ALL SELECT 'empresa_reporte' AS tabla, 'notas' AS columna
  UNION ALL SELECT 'empresa_reporte' AS tabla, 'created_by' AS columna
  UNION ALL SELECT 'empresa_reporte' AS tabla, 'updated_by' AS columna
  UNION ALL SELECT 'empresa_reporte' AS tabla, 'created_at' AS columna
  UNION ALL SELECT 'empresa_reporte' AS tabla, 'updated_at' AS columna
  UNION ALL SELECT 'empresa_reporte' AS tabla, 'activo' AS columna
  UNION ALL SELECT 'user_empresa_reporte' AS tabla, 'id' AS columna
  UNION ALL SELECT 'user_empresa_reporte' AS tabla, 'user_id' AS columna
  UNION ALL SELECT 'user_empresa_reporte' AS tabla, 'empresa_reporte_id' AS columna
  UNION ALL SELECT 'user_empresa_reporte' AS tabla, 'notas' AS columna
  UNION ALL SELECT 'user_empresa_reporte' AS tabla, 'created_by' AS columna
  UNION ALL SELECT 'user_empresa_reporte' AS tabla, 'updated_by' AS columna
  UNION ALL SELECT 'user_empresa_reporte' AS tabla, 'created_at' AS columna
  UNION ALL SELECT 'user_empresa_reporte' AS tabla, 'updated_at' AS columna
  UNION ALL SELECT 'user_empresa_reporte' AS tabla, 'activo' AS columna
) AS e
LEFT JOIN information_schema.COLUMNS c ON c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME = e.tabla AND c.COLUMN_NAME = e.columna
WHERE c.COLUMN_NAME IS NULL;

-- 4) Definición completa de las columnas de las seis tablas (tipo, nulabilidad, valor por defecto, extras, juego de caracteres)
SELECT TABLE_NAME, ORDINAL_POSITION, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA, CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_KEY
FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('user', 'empresa', 'reporte', 'empresa_usuario', 'empresa_reporte', 'user_empresa_reporte') ORDER BY TABLE_NAME, ORDINAL_POSITION;

-- 5) Columnas que existen en la base real y el backend de React no conoce (Flutter las puede usar: no deben perderse ni invalidarse)
SELECT c.TABLE_NAME, c.COLUMN_NAME, c.COLUMN_TYPE, c.IS_NULLABLE, c.COLUMN_DEFAULT
FROM information_schema.COLUMNS c
LEFT JOIN (
  SELECT 'user' AS tabla, 'id' AS columna
  UNION ALL SELECT 'user' AS tabla, 'username' AS columna
  UNION ALL SELECT 'user' AS tabla, 'email' AS columna
  UNION ALL SELECT 'user' AS tabla, 'password' AS columna
  UNION ALL SELECT 'user' AS tabla, 'first_name' AS columna
  UNION ALL SELECT 'user' AS tabla, 'last_name' AS columna
  UNION ALL SELECT 'user' AS tabla, 'phone' AS columna
  UNION ALL SELECT 'user' AS tabla, 'avatar' AS columna
  UNION ALL SELECT 'user' AS tabla, 'role' AS columna
  UNION ALL SELECT 'user' AS tabla, 'is_active' AS columna
  UNION ALL SELECT 'user' AS tabla, 'session_version' AS columna
  UNION ALL SELECT 'user' AS tabla, 'last_login' AS columna
  UNION ALL SELECT 'user' AS tabla, 'created_at' AS columna
  UNION ALL SELECT 'user' AS tabla, 'updated_at' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'id' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'nombre' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'razon_social' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'ruc' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'email' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'telefono' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'website' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'direccion' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'ciudad' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'pais' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'powerbi_tenant' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'powerbi_client' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'powerbi_clientsecret' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'logo' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'contacto_principal' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'created_by' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'updated_by' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'created_at' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'updated_at' AS columna
  UNION ALL SELECT 'empresa' AS tabla, 'activo' AS columna
  UNION ALL SELECT 'reporte' AS tabla, 'id' AS columna
  UNION ALL SELECT 'reporte' AS tabla, 'nombre' AS columna
  UNION ALL SELECT 'reporte' AS tabla, 'descripcion' AS columna
  UNION ALL SELECT 'reporte' AS tabla, 'enlace' AS columna
  UNION ALL SELECT 'reporte' AS tabla, 'grupo_id' AS columna
  UNION ALL SELECT 'reporte' AS tabla, 'reporte_id' AS columna
  UNION ALL SELECT 'reporte' AS tabla, 'created_by' AS columna
  UNION ALL SELECT 'reporte' AS tabla, 'updated_by' AS columna
  UNION ALL SELECT 'reporte' AS tabla, 'created_at' AS columna
  UNION ALL SELECT 'reporte' AS tabla, 'updated_at' AS columna
  UNION ALL SELECT 'reporte' AS tabla, 'activo' AS columna
  UNION ALL SELECT 'empresa_usuario' AS tabla, 'id' AS columna
  UNION ALL SELECT 'empresa_usuario' AS tabla, 'empresa_id' AS columna
  UNION ALL SELECT 'empresa_usuario' AS tabla, 'user_id' AS columna
  UNION ALL SELECT 'empresa_usuario' AS tabla, 'notas' AS columna
  UNION ALL SELECT 'empresa_usuario' AS tabla, 'created_by' AS columna
  UNION ALL SELECT 'empresa_usuario' AS tabla, 'updated_by' AS columna
  UNION ALL SELECT 'empresa_usuario' AS tabla, 'created_at' AS columna
  UNION ALL SELECT 'empresa_usuario' AS tabla, 'updated_at' AS columna
  UNION ALL SELECT 'empresa_usuario' AS tabla, 'activo' AS columna
  UNION ALL SELECT 'empresa_reporte' AS tabla, 'id' AS columna
  UNION ALL SELECT 'empresa_reporte' AS tabla, 'empresa_id' AS columna
  UNION ALL SELECT 'empresa_reporte' AS tabla, 'reporte_id' AS columna
  UNION ALL SELECT 'empresa_reporte' AS tabla, 'notas' AS columna
  UNION ALL SELECT 'empresa_reporte' AS tabla, 'created_by' AS columna
  UNION ALL SELECT 'empresa_reporte' AS tabla, 'updated_by' AS columna
  UNION ALL SELECT 'empresa_reporte' AS tabla, 'created_at' AS columna
  UNION ALL SELECT 'empresa_reporte' AS tabla, 'updated_at' AS columna
  UNION ALL SELECT 'empresa_reporte' AS tabla, 'activo' AS columna
  UNION ALL SELECT 'user_empresa_reporte' AS tabla, 'id' AS columna
  UNION ALL SELECT 'user_empresa_reporte' AS tabla, 'user_id' AS columna
  UNION ALL SELECT 'user_empresa_reporte' AS tabla, 'empresa_reporte_id' AS columna
  UNION ALL SELECT 'user_empresa_reporte' AS tabla, 'notas' AS columna
  UNION ALL SELECT 'user_empresa_reporte' AS tabla, 'created_by' AS columna
  UNION ALL SELECT 'user_empresa_reporte' AS tabla, 'updated_by' AS columna
  UNION ALL SELECT 'user_empresa_reporte' AS tabla, 'created_at' AS columna
  UNION ALL SELECT 'user_empresa_reporte' AS tabla, 'updated_at' AS columna
  UNION ALL SELECT 'user_empresa_reporte' AS tabla, 'activo' AS columna
) AS e ON e.tabla = c.TABLE_NAME AND e.columna = c.COLUMN_NAME
WHERE c.TABLE_SCHEMA = DATABASE() AND c.TABLE_NAME IN ('user', 'empresa', 'reporte', 'empresa_usuario', 'empresa_reporte', 'user_empresa_reporte') AND e.columna IS NULL ORDER BY c.TABLE_NAME, c.ORDINAL_POSITION;

-- 6) Índices (incluye únicos) de las seis tablas
SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, INDEX_TYPE
FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME IN ('user', 'empresa', 'reporte', 'empresa_usuario', 'empresa_reporte', 'user_empresa_reporte') ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;

-- 7) Claves primarias, únicas y foráneas, con sus reglas de borrado y actualización
SELECT tc.TABLE_NAME, tc.CONSTRAINT_NAME, tc.CONSTRAINT_TYPE, k.COLUMN_NAME, k.REFERENCED_TABLE_NAME, k.REFERENCED_COLUMN_NAME, r.DELETE_RULE, r.UPDATE_RULE
FROM information_schema.TABLE_CONSTRAINTS tc
LEFT JOIN information_schema.KEY_COLUMN_USAGE k ON k.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND k.TABLE_NAME = tc.TABLE_NAME AND k.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
LEFT JOIN information_schema.REFERENTIAL_CONSTRAINTS r ON r.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = tc.CONSTRAINT_NAME AND r.TABLE_NAME = tc.TABLE_NAME
WHERE tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME IN ('user', 'empresa', 'reporte', 'empresa_usuario', 'empresa_reporte', 'user_empresa_reporte') ORDER BY tc.TABLE_NAME, tc.CONSTRAINT_NAME, k.ORDINAL_POSITION;

-- 8) Lógica en la propia base que podría tocar los datos (disparadores, rutinas, vistas, eventos): nombre y tipo, sin datos
SELECT 'trigger' AS tipo, TRIGGER_NAME AS nombre, EVENT_OBJECT_TABLE AS tabla, CONCAT(ACTION_TIMING, ' ', EVENT_MANIPULATION) AS detalle FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA = DATABASE()
UNION ALL SELECT 'rutina', ROUTINE_NAME, NULL, ROUTINE_TYPE FROM information_schema.ROUTINES WHERE ROUTINE_SCHEMA = DATABASE()
UNION ALL SELECT 'vista', TABLE_NAME, NULL, IS_UPDATABLE FROM information_schema.VIEWS WHERE TABLE_SCHEMA = DATABASE()
UNION ALL SELECT 'evento', EVENT_NAME, NULL, STATUS FROM information_schema.EVENTS WHERE EVENT_SCHEMA = DATABASE();

-- 9) Contraseñas: SOLO el identificador del algoritmo (p. ej. «$2y$») y la longitud, agregados. El backend de React exige hashes de password_hash() de PHP.
SELECT LEFT(`password`, 4) AS prefijo_algoritmo, CHAR_LENGTH(`password`) AS longitud, is_active, COUNT(*) AS usuarios FROM `user` GROUP BY LEFT(`password`, 4), CHAR_LENGTH(`password`), is_active ORDER BY usuarios DESC;
SELECT SUM(`password` IS NULL OR `password` = '') AS sin_contrasena FROM `user`;

-- 10) session_version: el login de React exige un entero >= 1; el cierre de sesión lo INCREMENTA (columna que Flutter también podría usar)
SELECT is_active, COUNT(*) AS usuarios, SUM(session_version IS NULL OR session_version < 1) AS invalidos, MIN(session_version) AS minimo, MAX(session_version) AS maximo FROM `user` GROUP BY is_active;

-- 11) Roles exactos (con corchetes para ver espacios) y estados; los roles distintos de admin/user/usuario no podrán iniciar sesión en React
SELECT CONCAT('[', role, ']') AS rol_exacto, is_active, COUNT(*) AS usuarios FROM `user` GROUP BY role, is_active ORDER BY usuarios DESC;

-- 12) Campos de auditoría y huérfanos (conteos): `created_by` que apunta a usuarios inexistentes, relaciones sin padre
SELECT 'reporte.created_by huérfano' AS control, COUNT(*) AS filas FROM reporte r LEFT JOIN `user` u ON u.id = r.created_by WHERE r.created_by IS NOT NULL AND u.id IS NULL
UNION ALL SELECT 'empresa.created_by huérfano', COUNT(*) FROM empresa e LEFT JOIN `user` u ON u.id = e.created_by WHERE e.created_by IS NOT NULL AND u.id IS NULL
UNION ALL SELECT 'empresa_reporte sin empresa', COUNT(*) FROM empresa_reporte er LEFT JOIN empresa e ON e.id = er.empresa_id WHERE e.id IS NULL
UNION ALL SELECT 'empresa_reporte sin informe', COUNT(*) FROM empresa_reporte er LEFT JOIN reporte r ON r.id = er.reporte_id WHERE r.id IS NULL
UNION ALL SELECT 'empresa_usuario sin empresa', COUNT(*) FROM empresa_usuario eu LEFT JOIN empresa e ON e.id = eu.empresa_id WHERE e.id IS NULL
UNION ALL SELECT 'empresa_usuario sin usuario', COUNT(*) FROM empresa_usuario eu LEFT JOIN `user` u ON u.id = eu.user_id WHERE u.id IS NULL
UNION ALL SELECT 'user_empresa_reporte sin relación empresa-informe', COUNT(*) FROM user_empresa_reporte uer LEFT JOIN empresa_reporte er ON er.id = uer.empresa_reporte_id WHERE er.id IS NULL
UNION ALL SELECT 'user_empresa_reporte sin usuario', COUNT(*) FROM user_empresa_reporte uer LEFT JOIN `user` u ON u.id = uer.user_id WHERE u.id IS NULL;

-- 13) Duplicados ACTIVOS que la lógica de React trata como "ya existe" (esperado: 0; si no, hay que decidir antes de unificar)
SELECT 'empresa_reporte (empresa, informe) duplicado' AS control, COUNT(*) AS grupos FROM (SELECT empresa_id, reporte_id FROM empresa_reporte WHERE activo = 1 GROUP BY empresa_id, reporte_id HAVING COUNT(*) > 1) x
UNION ALL SELECT 'empresa_usuario (empresa, usuario) duplicado', COUNT(*) FROM (SELECT empresa_id, user_id FROM empresa_usuario WHERE activo = 1 GROUP BY empresa_id, user_id HAVING COUNT(*) > 1) x
UNION ALL SELECT 'user_empresa_reporte (usuario, relación) duplicado', COUNT(*) FROM (SELECT user_id, empresa_reporte_id FROM user_empresa_reporte WHERE activo = 1 GROUP BY user_id, empresa_reporte_id HAVING COUNT(*) > 1) x
UNION ALL SELECT 'usuario: correo repetido (sin distinguir mayúsculas)', COUNT(*) FROM (SELECT LOWER(email) FROM `user` GROUP BY LOWER(email) HAVING COUNT(*) > 1) x
UNION ALL SELECT 'usuario: username repetido (sin distinguir mayúsculas)', COUNT(*) FROM (SELECT LOWER(username) FROM `user` GROUP BY LOWER(username) HAVING COUNT(*) > 1) x
UNION ALL SELECT 'empresa: RUC activo repetido', COUNT(*) FROM (SELECT ruc FROM empresa WHERE activo = 1 GROUP BY ruc HAVING COUNT(*) > 1) x;

-- 14) Cuenta con la que se ejecuta (para saber qué privilegios tiene hoy la aplicación Flutter si se usa su cuenta; no muestra contraseñas)
SELECT CURRENT_USER() AS cuenta_actual;
SHOW GRANTS FOR CURRENT_USER();
