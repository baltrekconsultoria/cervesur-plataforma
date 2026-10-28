-- UX-07D.2B · Comprobaciones de SOLO LECTURA que complementan el inventario de UX-07D.0/07D.2A. Solo SELECT y solo AGREGADOS:
-- no devuelven nombres, correos, RUC, enlaces ni identificadores. NO se han ejecutado contra ninguna base real: requieren autorización.

-- 1) ¿Los enlaces activos «Publicar en web» son compatibles con la validación del visor y de la edición?
--    El visor y PHP aceptan en /view solo los parámetros r, pageName, navContentPaneEnabled, filterPaneEnabled y ctid (sin repetirlos).
--    Esta consulta es una APROXIMACIÓN (no detecta parámetros repetidos); la fuente de verdad es lib/powerbi_link.php.
SELECT
  COUNT(*) AS enlaces_activos,
  SUM(enlace REGEXP '^https://app\\.powerbi\\.com/view\\?r=[A-Za-z0-9_-]{16,1000}(=|%3D|%3d){0,2}(&(pageName=[A-Za-z0-9]{1,64}|navContentPaneEnabled=(true|false)|filterPaneEnabled=(true|false)|ctid=[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}))*$') AS aparentemente_compatibles,
  SUM(enlace NOT REGEXP '^https://app\\.powerbi\\.com/view\\?r=[A-Za-z0-9_-]{16,1000}(=|%3D|%3d){0,2}(&(pageName=[A-Za-z0-9]{1,64}|navContentPaneEnabled=(true|false)|filterPaneEnabled=(true|false)|ctid=[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}))*$') AS posiblemente_no_compatibles
FROM reporte WHERE activo = 1;

-- 2) Enlaces con parámetros FUERA de la lista permitida (se mostrarían como «no compatible» y su edición se rechazaría).
SELECT
  SUM(REGEXP_REPLACE(SUBSTRING_INDEX(enlace, '?', -1), '(^|&)(r|pageName|navContentPaneEnabled|filterPaneEnabled|ctid)=[^&]*', '') <> '') AS con_parametros_no_permitidos,
  SUM(enlace LIKE '%&pageName=%') AS con_pageName,
  SUM(enlace LIKE '%navContentPaneEnabled=%') AS con_navContentPaneEnabled,
  SUM(enlace LIKE '%filterPaneEnabled=%') AS con_filterPaneEnabled,
  SUM(enlace LIKE '%ctid=%') AS con_ctid
FROM reporte WHERE activo = 1;

-- 3) Definición real de las columnas que el inventario no precisó (se usan para validar longitudes sin truncar datos).
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND ((TABLE_NAME = 'reporte' AND COLUMN_NAME IN ('nombre', 'descripcion', 'created_by', 'updated_by'))
    OR (TABLE_NAME = 'user' AND COLUMN_NAME IN ('username', 'email', 'first_name', 'last_name', 'phone', 'password'))
    OR (TABLE_NAME = 'empresa' AND COLUMN_NAME IN ('nombre', 'razon_social', 'ruc', 'email')));

-- 4) Datos existentes que las validaciones nuevas rechazarían al EDITAR (solo conteos de informes activos).
SELECT
  SUM(CHAR_LENGTH(nombre) > 100) AS nombre_mayor_100,
  MAX(CHAR_LENGTH(nombre)) AS nombre_maximo,
  MAX(CHAR_LENGTH(descripcion)) AS descripcion_maxima,
  SUM(nombre IS NULL OR TRIM(nombre) = '') AS nombre_vacio
FROM reporte WHERE activo = 1;

-- 5) Usuarios cuyo nombre de usuario superaría validaciones de longitud al recrearse o editarse (conteo agregado).
SELECT MAX(CHAR_LENGTH(username)) AS username_maximo, SUM(CHAR_LENGTH(username) > 50) AS username_mayor_50 FROM `user`;

-- 6) Informes activos cuya referencia histórica coincide con la de OTRO informe activo (React no los une: usa reporte.id).
--    Esperado según el inventario: 0. Se deja como control de regresión.
SELECT COUNT(*) AS referencias_repetidas_entre_activos
FROM (SELECT reporte_id FROM reporte WHERE activo = 1 GROUP BY reporte_id HAVING COUNT(*) > 1) AS x;
