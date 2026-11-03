-- UX-07D.3A · PROCEDIMIENTO ADMINISTRATIVO — REQUIERE AUTORIZACIÓN EXPLÍCITA Y UNA DECISIÓN APROBADA SOBRE HTTPS, TRATAMIENTO DE DATOS Y AUTENTICACIÓN.
-- NO crear esta cuenta en la primera modalidad (diagnóstico): el login real necesita leer user.password (hash bcrypt) en el servidor.
--
-- Cuenta del PILOTO AUTENTICADO de solo lectura (modo read_only). Sigue siendo SOLO SELECT por columna:
--   · INCLUYE user.password (el backend lo necesita para password_verify; el hash nunca sale del servidor: ni a respuestas, ni a registros, ni a otras bases).
--   · INCLUYE reporte.enlace (el visor entrega al usuario AUTORIZADO el enlace «Publicar en web», público por naturaleza).
--   · EXCLUYE empresa.powerbi_tenant, empresa.powerbi_client y empresa.powerbi_clientsecret (credenciales de Power BI del cliente; el endpoint heredado
--     de tokens que las usaba está bloqueado en este modo).
--   · SIN INSERT/UPDATE/DELETE/DDL/EXECUTE, SIN GRANT OPTION, sin permiso para escribir last_login ni session_version.
-- Marcadores: __BASE__ y __HOST__ (la clave, política MEDIUM, solo figura en la sentencia CREATE USER). Revertir con 99-revocar-cuenta-piloto.sql; verificar con 02b-verificar-privilegios-piloto.sql.

CREATE USER 'cervesur_ro_pilot'@'__HOST__' IDENTIFIED BY '__CLAVE_PILOTO__' WITH MAX_USER_CONNECTIONS 10;

GRANT SELECT (id, username, email, password, first_name, last_name, phone, avatar, role, last_login, is_active, session_version, created_at, updated_at) ON `__BASE__`.`user` TO 'cervesur_ro_pilot'@'__HOST__';
GRANT SELECT (id, nombre, razon_social, ruc, email, telefono, website, direccion, ciudad, pais, logo, contacto_principal, created_by, updated_by, created_at, updated_at, activo) ON `__BASE__`.`empresa` TO 'cervesur_ro_pilot'@'__HOST__';
GRANT SELECT (id, nombre, descripcion, enlace, grupo_id, reporte_id, created_by, updated_by, created_at, updated_at, activo) ON `__BASE__`.`reporte` TO 'cervesur_ro_pilot'@'__HOST__';
GRANT SELECT (id, empresa_id, user_id, notas, created_by, updated_by, created_at, updated_at, activo) ON `__BASE__`.`empresa_usuario` TO 'cervesur_ro_pilot'@'__HOST__';
GRANT SELECT (id, empresa_id, reporte_id, notas, created_by, updated_by, created_at, updated_at, activo) ON `__BASE__`.`empresa_reporte` TO 'cervesur_ro_pilot'@'__HOST__';
GRANT SELECT (id, user_id, empresa_reporte_id, notas, created_by, updated_by, created_at, updated_at, activo) ON `__BASE__`.`user_empresa_reporte` TO 'cervesur_ro_pilot'@'__HOST__';
