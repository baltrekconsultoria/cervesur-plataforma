-- Reversión de UX-08B-audit-log-immutable.up.sql: quita solo los triggers; los datos de audit_log no se tocan.
DROP TRIGGER IF EXISTS audit_log_no_update;
DROP TRIGGER IF EXISTS audit_log_no_delete;
