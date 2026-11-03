-- UX-07D.3B.1 · Verificación de SOLO LECTURA de la cuenta de DIAGNÓSTICO cervesur_ro_diag@127.0.0.1. Solo SELECT / SHOW. La ejecuta un operador AUTORIZADO con su propia cuenta.
-- Consulta metadatos de seguridad de MySQL (por eso REQUIERE AUTORIZACIÓN); no modifica nada y no contiene contraseñas.
-- Verifica ÚNICAMENTE la cuenta de diagnóstico: NO falla si la cuenta del piloto (cervesur_ro_pilot) todavía no existe (su verificación está en 02b-…piloto.sql).
-- Ejecutar DESPUÉS de crear la cuenta con 01-cuenta-diagnostico.sql.

-- 1) Concesiones exactas de la cuenta. ESPERADO: USAGE y seis GRANT SELECT por columna; nada más.
SHOW GRANTS FOR 'cervesur_ro_diag'@'127.0.0.1';

-- 2) Cualquier privilegio de ESCRITURA o administración a nivel global, de base, de tabla o de columna. ESPERADO: 0 filas en cada consulta.
SELECT GRANTEE, PRIVILEGE_TYPE, IS_GRANTABLE FROM information_schema.USER_PRIVILEGES
 WHERE GRANTEE = '''cervesur_ro_diag''@''127.0.0.1''' AND PRIVILEGE_TYPE <> 'USAGE';
SELECT GRANTEE, TABLE_SCHEMA, PRIVILEGE_TYPE, IS_GRANTABLE FROM information_schema.SCHEMA_PRIVILEGES
 WHERE GRANTEE = '''cervesur_ro_diag''@''127.0.0.1''';
SELECT GRANTEE, TABLE_SCHEMA, TABLE_NAME, PRIVILEGE_TYPE, IS_GRANTABLE FROM information_schema.TABLE_PRIVILEGES
 WHERE GRANTEE = '''cervesur_ro_diag''@''127.0.0.1''';
SELECT GRANTEE, TABLE_NAME, PRIVILEGE_TYPE, COUNT(*) AS columnas FROM information_schema.COLUMN_PRIVILEGES
 WHERE GRANTEE = '''cervesur_ro_diag''@''127.0.0.1''' AND (PRIVILEGE_TYPE <> 'SELECT' OR IS_GRANTABLE <> 'NO') GROUP BY GRANTEE, TABLE_NAME, PRIVILEGE_TYPE;

-- 3) Columnas SENSIBLES concedidas a la cuenta. ESPERADO: 0 filas.
SELECT GRANTEE, TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMN_PRIVILEGES
 WHERE GRANTEE = '''cervesur_ro_diag''@''127.0.0.1''' AND ((TABLE_NAME = 'user' AND COLUMN_NAME = 'password')
    OR (TABLE_NAME = 'empresa' AND COLUMN_NAME LIKE 'powerbi\_%') OR (TABLE_NAME = 'reporte' AND COLUMN_NAME = 'enlace'))
 ORDER BY TABLE_NAME, COLUMN_NAME;

-- 4) Las columnas concedidas, por tabla. ESPERADO (17 NO es correcto; son 18 = 4+2+2+3+4+3):
--    empresa 2 · empresa_reporte 4 · empresa_usuario 3 · reporte 2 · user 4 · user_empresa_reporte 3  → total 18
SELECT TABLE_NAME, COUNT(*) AS columnas, GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) AS columnas_concedidas
FROM information_schema.COLUMN_PRIVILEGES WHERE GRANTEE = '''cervesur_ro_diag''@''127.0.0.1''' GROUP BY TABLE_NAME ORDER BY TABLE_NAME;
SELECT COUNT(*) AS total_columnas_concedidas FROM information_schema.COLUMN_PRIVILEGES WHERE GRANTEE = '''cervesur_ro_diag''@''127.0.0.1''';

-- 5) Cuentas con ACCESO A ESCRITURA sobre la base de lectura (solo para conocer quién más puede escribir; nombres de cuenta, sin datos)
SELECT GRANTEE, TABLE_SCHEMA, PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES
 WHERE TABLE_SCHEMA = 'cervesur_portalbi' AND PRIVILEGE_TYPE IN ('INSERT', 'UPDATE', 'DELETE', 'ALTER', 'DROP') ORDER BY GRANTEE, PRIVILEGE_TYPE;
