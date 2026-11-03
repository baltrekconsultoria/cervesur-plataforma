-- UX-07D.4 · PROCEDIMIENTO ADMINISTRATIVO — REQUIERE AUTORIZACIÓN EXPLÍCITA. NO EJECUTAR sin ella (modifica privilegios de MySQL).
--
-- Cuenta de SOLO LECTURA del PILOTO VISUAL (127.0.0.1:8091): cervesur_ro_visor. Es DISTINTA de cervesur_ro_diag (que no se modifica). Principio de mínimo privilegio:
--   · SOLO SELECT por columna, sobre 15 columnas (6+5+4) que justifican las consultas del catálogo cerrado backend/lib/piloto_catalogo.php.
--   · SIN acceso a la tabla user (ni a user.password ni a ninguna otra columna), SIN empresa.powerbi_*, SIN correos, teléfonos, razón social, notas ni auditoría.
--   · reporte.enlace SOLO lo lee la consulta del visor, que únicamente se ejecuta para los informes que el operador autorizó.
--   · SIN INSERT/UPDATE/DELETE/DDL/EXECUTE, SIN GRANT OPTION. Máximo 5 conexiones simultáneas. Host fijo: 127.0.0.1 (el backend conecta por TCP local).
-- Marcadores que sustituye preparar-sql-cuenta.sh (la clave NO se guarda en el repositorio): el nombre de la base y el host. La clave figura únicamente
-- en la sentencia CREATE USER; ningún comentario del archivo generado la contiene.
-- Revertir con 99-revocar-cuenta-visor.sql (solo esta cuenta). Después de ejecutarlo, verificar con 02-verificar-privilegios-visor.sql.

CREATE USER 'cervesur_ro_visor'@'__HOST__' IDENTIFIED BY '__CLAVE_VISOR__' WITH MAX_USER_CONNECTIONS 5;

GRANT SELECT (id, reporte_id, nombre, descripcion, activo, enlace) ON `__BASE__`.`reporte` TO 'cervesur_ro_visor'@'__HOST__';
GRANT SELECT (id, nombre, ciudad, ruc, activo) ON `__BASE__`.`empresa` TO 'cervesur_ro_visor'@'__HOST__';
GRANT SELECT (id, empresa_id, reporte_id, activo) ON `__BASE__`.`empresa_reporte` TO 'cervesur_ro_visor'@'__HOST__';
