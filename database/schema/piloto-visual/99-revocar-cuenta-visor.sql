-- UX-07D.4 · PROCEDIMIENTO ADMINISTRATIVO — REQUIERE AUTORIZACIÓN EXPLÍCITA. Reversión de la cuenta del PILOTO VISUAL, y de ninguna otra.
-- Elimina ÚNICAMENTE 'cervesur_ro_visor'@'127.0.0.1'. Sin marcadores: no puede afectar a cervesur_ro_diag, a otro host ni a ninguna otra cuenta.
DROP USER IF EXISTS 'cervesur_ro_visor'@'127.0.0.1';
