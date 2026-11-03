-- UX-07E.B · PILOTO ADMINISTRATIVO DE SOLO LECTURA (127.0.0.1:8093). Mismos privilegios mínimos que el piloto de usuarios (SELECT por columna: 9 columnas de user y 21 de catálogo),
-- pero con cuentas PROPIAS de esta instancia (sufijo _adm) y su propia base de estado, para poder retirarla sin tocar el piloto de usuarios. Derivado mecánicamente de
-- ../piloto-usuarios/ (los nombres de cuenta son lo único que cambia). Ninguna cuenta tiene INSERT/UPDATE/DELETE sobre la base de Flutter.
-- UX-07D.4B · PROCEDIMIENTO ADMINISTRATIVO — REQUIERE AUTORIZACIÓN EXPLÍCITA. NO EJECUTAR sin ella (modifica privilegios de MySQL). Preparado para REVISIÓN: no se ha ejecutado en ningún servidor.
--
-- Dos cuentas de SOLO LECTURA sobre la base de Flutter para el piloto con usuarios reales. Son DISTINTAS entre sí y de cervesur_ro_diag y cervesur_ro_visor (que no se tocan):
--   cervesur_ro_auth_adm      9 columnas de la tabla user (id, username, email, password, first_name, last_name, role, is_active, session_version). Es la ÚNICA que puede leer el hash bcrypt
--                         (solo para password_verify y para ligar la sesión a la contraseña vigente). No ve ninguna otra tabla.
--   cervesur_ro_catalogo_adm  21 columnas (6+5+4+3+3) de reporte, empresa, empresa_reporte, empresa_usuario y user_empresa_reporte. NO ve la tabla user ni credenciales de Power BI.
--                         reporte.enlace solo lo lee la consulta del visor, tras comprobar el permiso del usuario.
--   · SOLO SELECT por columna. SIN INSERT/UPDATE/DELETE/DDL/EXECUTE, SIN GRANT OPTION. Host fijo 127.0.0.1 (el backend conecta por TCP local). Máximo 5 conexiones cada una.
-- Marcadores que sustituye preparar-sql-cuentas.sh: la base y el host. Cada clave figura únicamente en su sentencia CREATE USER; ningún comentario del archivo generado la contiene.
-- Revertir con 99-revocar-cuentas.sql (solo estas tres cuentas del piloto). Verificar con 03-verificar-privilegios.sql.

CREATE USER 'cervesur_ro_auth_adm'@'__HOST__' IDENTIFIED BY '__CLAVE_AUTH__' WITH MAX_USER_CONNECTIONS 5;
GRANT SELECT (id, username, email, password, first_name, last_name, role, is_active, session_version) ON `__BASE__`.`user` TO 'cervesur_ro_auth_adm'@'__HOST__';

CREATE USER 'cervesur_ro_catalogo_adm'@'__HOST__' IDENTIFIED BY '__CLAVE_CATALOGO__' WITH MAX_USER_CONNECTIONS 5;
GRANT SELECT (id, reporte_id, nombre, descripcion, activo, enlace) ON `__BASE__`.`reporte` TO 'cervesur_ro_catalogo_adm'@'__HOST__';
GRANT SELECT (id, nombre, ciudad, ruc, activo) ON `__BASE__`.`empresa` TO 'cervesur_ro_catalogo_adm'@'__HOST__';
GRANT SELECT (id, empresa_id, reporte_id, activo) ON `__BASE__`.`empresa_reporte` TO 'cervesur_ro_catalogo_adm'@'__HOST__';
GRANT SELECT (empresa_id, user_id, activo) ON `__BASE__`.`empresa_usuario` TO 'cervesur_ro_catalogo_adm'@'__HOST__';
GRANT SELECT (user_id, empresa_reporte_id, activo) ON `__BASE__`.`user_empresa_reporte` TO 'cervesur_ro_catalogo_adm'@'__HOST__';
