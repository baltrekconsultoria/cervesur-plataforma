-- UX-07D.0 · Precondiciones de la migración UX-07C. SOLO LECTURA (únicamente SELECT). Sin datos personales.
-- NO ejecutar contra MySQL real sin autorización expresa.

-- A) Esquema actual de `reporte` (antes de migrar)
SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, CHARACTER_MAXIMUM_LENGTH AS longitud
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reporte' AND COLUMN_NAME IN ('grupo_id', 'reporte_id', 'enlace', 'nombre', 'descripcion', 'activo');

-- B) Impacto de la migración (UP): no modifica filas. Informa cuántas filas existen y la longitud máxima real de los enlaces.
SELECT COUNT(*) AS informes, SUM(activo = 1) AS activos, MAX(CHAR_LENGTH(enlace)) AS enlace_max_actual, SUM(grupo_id IS NULL) AS sin_grupo FROM reporte;

-- C) Reversa (DOWN): ambos recuentos DEBEN ser 0 para que el ALTER de reversa no falle. Si no lo son, NO revierta.
SELECT SUM(grupo_id IS NULL) AS informes_sin_grupo_bloquean_reversa, SUM(CHAR_LENGTH(enlace) > 255) AS enlaces_mayores_255_bloquean_reversa FROM reporte;

-- D) Datos existentes que las validaciones nuevas rechazarían al EDITAR (no impiden usar el informe, pero sí guardarlo sin corregir)
SELECT
  SUM(enlace NOT LIKE 'https://app.powerbi.com/%') AS enlace_fuera_de_app_powerbi,
  SUM(enlace IS NULL OR enlace = '') AS enlace_vacio,
  SUM(reporte_id NOT REGEXP '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$') AS reporte_id_no_uuid,
  SUM(grupo_id IS NOT NULL AND grupo_id NOT REGEXP '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$') AS grupo_id_no_uuid,
  SUM(CHAR_LENGTH(nombre) > 100) AS nombre_mayor_100
FROM reporte WHERE activo = 1;

-- E) UUID de informe repetidos entre informes activos (la validación nueva rechaza duplicados al crear/editar)
SELECT COUNT(*) AS uuids_repetidos FROM (SELECT LOWER(reporte_id) AS u FROM reporte WHERE activo = 1 GROUP BY LOWER(reporte_id) HAVING COUNT(*) > 1) AS x;
