-- UX-07D.3A · PROCEDIMIENTO ADMINISTRATIVO — REQUIERE AUTORIZACIÓN EXPLÍCITA. NO EJECUTAR sin ella (modifica privilegios de MySQL).
--
-- Cuenta de DIAGNÓSTICO de solo lectura para el piloto «diagnostico» (127.0.0.1:8090). Principio de mínimo privilegio:
--   · SOLO SELECT, y SOLO sobre las columnas indispensables para los recuentos agregados (permisos por columna).
--   · SIN acceso a user.password, user.email, user.phone, empresa.powerbi_*, reporte.enlace, reporte.reporte_id ni a ninguna otra columna.
--   · SIN INSERT/UPDATE/DELETE/DDL/EXECUTE, SIN GRANT OPTION. Máximo 5 conexiones simultáneas.
-- Marcadores que el operador sustituye al ejecutar (la clave NO se guarda en el repositorio):
--   __BASE__   base de datos (producción: cervesur_portalbi)        __HOST__   host de la conexión (p. ej. 127.0.0.1 o localhost)
--   (el marcador de la clave figura solo en la sentencia CREATE USER de abajo; ningún comentario del archivo generado contiene la clave)
-- LISTA EXACTA: se deduce de backend/lib/diagnostico_catalogo.php y tests/isolated/privilegios_minimos_test.php comprueba que cada columna es imprescindible (quitar una rompe algún diagnóstico) y que no falta ninguna.
-- Revertir con 99-revocar-cuenta-diagnostico.sql (solo esta cuenta). Después de ejecutarlo, verificar con 02-verificar-privilegios-diagnostico.sql.

CREATE USER 'cervesur_ro_diag'@'__HOST__' IDENTIFIED BY '__CLAVE_DIAGNOSTICO__' WITH MAX_USER_CONNECTIONS 5;

GRANT SELECT (id, role, is_active, session_version) ON `__BASE__`.`user` TO 'cervesur_ro_diag'@'__HOST__';
GRANT SELECT (id, activo) ON `__BASE__`.`empresa` TO 'cervesur_ro_diag'@'__HOST__';
GRANT SELECT (id, activo) ON `__BASE__`.`reporte` TO 'cervesur_ro_diag'@'__HOST__';
GRANT SELECT (empresa_id, user_id, activo) ON `__BASE__`.`empresa_usuario` TO 'cervesur_ro_diag'@'__HOST__';
GRANT SELECT (id, empresa_id, reporte_id, activo) ON `__BASE__`.`empresa_reporte` TO 'cervesur_ro_diag'@'__HOST__';
GRANT SELECT (user_id, empresa_reporte_id, activo) ON `__BASE__`.`user_empresa_reporte` TO 'cervesur_ro_diag'@'__HOST__';
