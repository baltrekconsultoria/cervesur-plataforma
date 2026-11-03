-- UX-07E.B · PILOTO ADMINISTRATIVO DE SOLO LECTURA (127.0.0.1:8093). Mismos privilegios mínimos que el piloto de usuarios (SELECT por columna: 9 columnas de user y 21 de catálogo),
-- pero con cuentas PROPIAS de esta instancia (sufijo _adm) y su propia base de estado, para poder retirarla sin tocar el piloto de usuarios. Derivado mecánicamente de
-- ../piloto-usuarios/ (los nombres de cuenta son lo único que cambia). Ninguna cuenta tiene INSERT/UPDATE/DELETE sobre la base de Flutter.
-- UX-07D.4B · PROCEDIMIENTO ADMINISTRATIVO — REQUIERE AUTORIZACIÓN EXPLÍCITA. NO EJECUTAR sin ella. Preparado para REVISIÓN: no se ha ejecutado en ningún servidor.
--
-- Base de ESTADO PROPIA del piloto con usuarios reales: lista blanca, sesiones revocables, intentos de acceso y auditoría. Es una base NUEVA y SEPARADA de cervesur_portalbi:
-- no contiene contraseñas, hashes de contraseña, enlaces de Power BI ni direcciones IP en claro (solo huellas HMAC). No tiene claves foráneas hacia la base de Flutter.
-- La cuenta cervesur_react_estado_adm tiene SELECT/INSERT/UPDATE/DELETE ÚNICAMENTE en esta base (nada en cervesur_portalbi, nada global, sin DDL ni GRANT OPTION).
-- Marcadores que sustituye preparar-sql-cuentas.sh: la base de estado y el host. La clave figura únicamente en la sentencia CREATE USER.
-- Esta es la única parte del piloto que escribe, y solo en SU base. Revertir con 99-revocar-cuentas.sql (no elimina la base: eliminarla es una decisión administrativa aparte).

CREATE DATABASE IF NOT EXISTS `__BASE_ESTADO__` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `__BASE_ESTADO__`.`usuarios_piloto` (
  user_id INT UNSIGNED NOT NULL PRIMARY KEY,
  nota VARCHAR(120) NULL,
  activo TINYINT(1) NOT NULL DEFAULT 1,
  creado_en DATETIME NOT NULL,
  creado_por VARCHAR(60) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `__BASE_ESTADO__`.`sesiones` (
  token_hash CHAR(64) NOT NULL PRIMARY KEY,
  user_id INT UNSIGNED NOT NULL,
  session_version INT UNSIGNED NOT NULL,
  huella_clave CHAR(64) NOT NULL,
  emitida_en DATETIME NOT NULL,
  expira_en DATETIME NOT NULL,
  revocada_en DATETIME NULL,
  motivo_revocacion VARCHAR(60) NULL,
  ip_hash CHAR(64) NOT NULL,
  KEY idx_sesiones_usuario (user_id, revocada_en),
  KEY idx_sesiones_expira (expira_en)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `__BASE_ESTADO__`.`intentos_login` (
  clave_hash CHAR(64) NOT NULL PRIMARY KEY,
  tipo VARCHAR(5) NOT NULL,
  fallos INT UNSIGNED NOT NULL,
  primer_fallo INT UNSIGNED NOT NULL,
  bloqueado_hasta INT UNSIGNED NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS `__BASE_ESTADO__`.`auditoria` (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ocurrido_en DATETIME NOT NULL,
  evento VARCHAR(60) NOT NULL,
  user_id INT UNSIGNED NULL,
  reporte_id INT UNSIGNED NULL,
  clave_hash CHAR(64) NULL,
  ip_hash CHAR(64) NOT NULL,
  KEY idx_auditoria_fecha (ocurrido_en),
  KEY idx_auditoria_usuario (user_id)
) ENGINE=InnoDB;

CREATE USER 'cervesur_react_estado_adm'@'__HOST__' IDENTIFIED BY '__CLAVE_ESTADO__' WITH MAX_USER_CONNECTIONS 10;
GRANT SELECT, INSERT, UPDATE, DELETE ON `__BASE_ESTADO__`.* TO 'cervesur_react_estado_adm'@'__HOST__';
