-- UX-07C · REVERSA de UX-07C-reporte-sin-ids-externos.up.sql
-- UNA SOLA sentencia ALTER (atómica): en modo SQL estricto (por defecto en MySQL 8) FALLA ENTERA, sin modificar nada, si existe algún
-- informe sin grupo o algún enlace de más de 255 caracteres. Nunca queda una reversión parcial; nunca se inventan ni truncan datos.
-- Antes: ejecute database/schema/UX-07D0-precondiciones-migracion.sql (sección C) y corrija o dé de baja esos informes.
-- Asume que antes de la migración `grupo_id` era obligatoria y `enlace` de 255; ajuste si el esquema original era distinto.
ALTER TABLE reporte MODIFY grupo_id VARCHAR(36) NOT NULL, MODIFY enlace VARCHAR(255) NULL;
