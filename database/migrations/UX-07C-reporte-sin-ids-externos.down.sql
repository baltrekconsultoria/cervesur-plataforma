-- UX-07C · REVERSA de UX-07C-reporte-sin-ids-externos.up.sql
-- SEGURA: en modo SQL estricto (por defecto en MySQL 8) cada ALTER FALLA, sin modificar nada, si existe algún informe sin grupo
-- o algún enlace de más de 255 caracteres. Hay que corregir o dar de baja esos informes antes de revertir; la reversa nunca inventa
-- ni trunca datos.
-- Asume que antes de la migración `grupo_id` era obligatoria; si no lo era, omita la segunda sentencia.
ALTER TABLE reporte MODIFY enlace VARCHAR(255) NULL;
ALTER TABLE reporte MODIFY grupo_id VARCHAR(36) NOT NULL;
