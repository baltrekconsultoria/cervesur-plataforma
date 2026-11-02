-- Reversión de UX-08B-audit-log.up.sql. Solo elimina la bitácora (datos de soporte); no toca tablas de negocio.
-- Antes de ejecutarla, exporte la tabla si necesita conservar el historial.
DROP TABLE IF EXISTS audit_log;
