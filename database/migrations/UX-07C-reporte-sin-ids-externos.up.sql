-- UX-07C · Informes registrados solo por su enlace público (sin identificadores de Power BI)
-- MIGRACIÓN OPCIONAL Y REVERSIBLE. NO EJECUTAR en producción sin autorización, copia de seguridad y revisión.
-- Probada únicamente en MySQL sintético (tests/isolated/http_viewer_test.php).
--
-- 1) `grupo_id` pasa a admitir NULL: un informe de «Publicar en web» no tiene grupo (workspace) de Power BI y NO se inventa uno.
--    Los registros existentes no cambian (todos conservan su grupo).
-- 2) `enlace` se amplía a 1024 caracteres: los enlaces de publicación en web pueden superar los 255 actuales.
--    Los enlaces existentes no cambian. La aplicación consulta el tamaño real de la columna: sin esta migración sigue funcionando,
--    pero rechaza con un mensaje claro los enlaces demasiado largos y los informes sin grupo.
-- `reporte_id` conserva su significado histórico (referencia UUID única y estable del informe, usada en las rutas /reporteid/{uuid});
-- para informes sin identificadores de Power BI contiene una referencia interna de Cervesur (UUID v4) y `grupo_id` queda NULL.
ALTER TABLE reporte MODIFY grupo_id VARCHAR(36) NULL;
ALTER TABLE reporte MODIFY enlace VARCHAR(1024) NULL;
