-- UX-07E.B · PILOTO ADMINISTRATIVO DE SOLO LECTURA (127.0.0.1:8093). Mismos privilegios mínimos que el piloto de usuarios (SELECT por columna: 9 columnas de user y 21 de catálogo),
-- pero con cuentas PROPIAS de esta instancia (sufijo _adm) y su propia base de estado, para poder retirarla sin tocar el piloto de usuarios. Derivado mecánicamente de
-- ../piloto-usuarios/ (los nombres de cuenta son lo único que cambia). Ninguna cuenta tiene INSERT/UPDATE/DELETE sobre la base de Flutter.
-- UX-07D.4B · PROCEDIMIENTO ADMINISTRATIVO — REQUIERE AUTORIZACIÓN EXPLÍCITA. Reversión de las TRES cuentas del piloto con usuarios, y de ninguna otra.
-- Sin marcadores: solo afecta a estas cuentas en 127.0.0.1; no puede tocar cervesur_ro_diag, cervesur_ro_visor, otro host ni ninguna otra cuenta. NO elimina la base de estado
-- (sus sesiones y su auditoría pueden ser necesarias); eliminarla sería una decisión administrativa aparte.
DROP USER IF EXISTS 'cervesur_ro_auth_adm'@'127.0.0.1';
DROP USER IF EXISTS 'cervesur_ro_catalogo_adm'@'127.0.0.1';
DROP USER IF EXISTS 'cervesur_react_estado_adm'@'127.0.0.1';
