-- UX-07D.3B.1 · PROCEDIMIENTO ADMINISTRATIVO — REQUIERE AUTORIZACIÓN EXPLÍCITA. Reversión de la cuenta de DIAGNÓSTICO, y de ninguna otra.
-- Elimina ÚNICAMENTE la cuenta 'cervesur_ro_diag'@'127.0.0.1'. No contiene marcadores y no puede afectar a cervesur_ro_pilot, a otro host del mismo usuario ni a ninguna otra cuenta.
-- (Si se creó la cuenta con otro host, este archivo no la alcanza a propósito: use una sentencia revisada para ese host concreto.)
DROP USER IF EXISTS 'cervesur_ro_diag'@'127.0.0.1';
