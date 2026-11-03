-- UX-07D.4B · PROCEDIMIENTO ADMINISTRATIVO — REQUIERE AUTORIZACIÓN EXPLÍCITA. Reversión de las TRES cuentas del piloto con usuarios, y de ninguna otra.
-- Sin marcadores: solo afecta a estas cuentas en 127.0.0.1; no puede tocar cervesur_ro_diag, cervesur_ro_visor, otro host ni ninguna otra cuenta. NO elimina la base de estado
-- (sus sesiones y su auditoría pueden ser necesarias); eliminarla sería una decisión administrativa aparte.
DROP USER IF EXISTS 'cervesur_ro_auth'@'127.0.0.1';
DROP USER IF EXISTS 'cervesur_ro_catalogo'@'127.0.0.1';
DROP USER IF EXISTS 'cervesur_react_estado'@'127.0.0.1';
