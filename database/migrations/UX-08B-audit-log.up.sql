-- UX-08B: bitácora de auditoría de escrituras administrativas.
-- Tabla de SOPORTE (no de negocio): no duplica usuarios, empresas, informes ni asignaciones.
-- Sin clave foránea hacia `user`: una fila de auditoría no debe borrarse en cascada si se elimina un usuario,
-- y `user.id` es INT UNSIGNED en producción pero INT en algunos entornos de prueba.
-- NO se aplica automáticamente. Antes: respaldo y autorización expresa. Reversión: UX-08B-audit-log.down.sql.
CREATE TABLE IF NOT EXISTS audit_log (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    correlation_id CHAR(32)        NOT NULL,
    occurred_at    DATETIME(3)     NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    actor_user_id  INT UNSIGNED    NOT NULL,
    action         VARCHAR(40)     NOT NULL,
    entity         VARCHAR(40)     NOT NULL,
    entity_id      INT UNSIGNED    NOT NULL,
    before_json    JSON            NULL,
    after_json     JSON            NULL,
    request_ip     VARCHAR(45)     NULL,
    PRIMARY KEY (id),
    KEY idx_audit_entity (entity, entity_id, occurred_at),
    KEY idx_audit_actor (actor_user_id, occurred_at),
    KEY idx_audit_correlation (correlation_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
