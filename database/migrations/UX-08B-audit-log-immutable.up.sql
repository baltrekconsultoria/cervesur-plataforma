-- UX-08B.2: bitácora de SOLO INSERCIÓN. Complementa UX-08B-audit-log.up.sql (requiere que audit_log exista).
-- Ninguna cuenta, incluida la de la aplicación, puede modificar ni borrar filas de audit_log mientras estos triggers existan.
-- NO impide DROP/TRUNCATE de la tabla ni desactivar los triggers: eso se controla NO concediendo DROP, TRUNCATE ni TRIGGER a la cuenta de la aplicación
-- (ver docs/UX-08B2-INFORME.md, «Auditoría resistente»).
-- NO se aplica automáticamente. Reversión: UX-08B-audit-log-immutable.down.sql.
DROP TRIGGER IF EXISTS audit_log_no_update;
CREATE TRIGGER audit_log_no_update BEFORE UPDATE ON audit_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log es de solo insercion';
DROP TRIGGER IF EXISTS audit_log_no_delete;
CREATE TRIGGER audit_log_no_delete BEFORE DELETE ON audit_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_log es de solo insercion';
