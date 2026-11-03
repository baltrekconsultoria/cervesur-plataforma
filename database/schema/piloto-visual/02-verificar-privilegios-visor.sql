-- UX-07D.4 · Verificación de SOLO LECTURA de la cuenta del PILOTO VISUAL cervesur_ro_visor@127.0.0.1. Solo SELECT / SHOW. REQUIERE AUTORIZACIÓN: la ejecuta un operador autorizado.
-- Consulta metadatos de seguridad de MySQL; no modifica nada ni contiene contraseñas. Ejecutar DESPUÉS de crear la cuenta con 01-cuenta-visor.sql.

SHOW GRANTS FOR 'cervesur_ro_visor'@'127.0.0.1';

-- Privilegios de ESCRITURA o administración a cualquier nivel. ESPERADO: 0 filas en cada consulta.
SELECT GRANTEE, PRIVILEGE_TYPE, IS_GRANTABLE FROM information_schema.USER_PRIVILEGES
 WHERE GRANTEE = '''cervesur_ro_visor''@''127.0.0.1''' AND PRIVILEGE_TYPE <> 'USAGE';
SELECT GRANTEE, TABLE_SCHEMA, PRIVILEGE_TYPE, IS_GRANTABLE FROM information_schema.SCHEMA_PRIVILEGES
 WHERE GRANTEE = '''cervesur_ro_visor''@''127.0.0.1''';
SELECT GRANTEE, TABLE_SCHEMA, TABLE_NAME, PRIVILEGE_TYPE, IS_GRANTABLE FROM information_schema.TABLE_PRIVILEGES
 WHERE GRANTEE = '''cervesur_ro_visor''@''127.0.0.1''';
SELECT GRANTEE, TABLE_NAME, PRIVILEGE_TYPE, COUNT(*) AS columnas FROM information_schema.COLUMN_PRIVILEGES
 WHERE GRANTEE = '''cervesur_ro_visor''@''127.0.0.1''' AND (PRIVILEGE_TYPE <> 'SELECT' OR IS_GRANTABLE <> 'NO') GROUP BY GRANTEE, TABLE_NAME, PRIVILEGE_TYPE;

-- Columnas SENSIBLES o ajenas al piloto. ESPERADO: 0 filas (ninguna columna de la tabla user, ni powerbi_*, ni correo/teléfono/dirección).
SELECT GRANTEE, TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMN_PRIVILEGES
 WHERE GRANTEE = '''cervesur_ro_visor''@''127.0.0.1''' AND (TABLE_NAME = 'user' OR (TABLE_NAME = 'empresa' AND COLUMN_NAME IN ('powerbi_tenant', 'powerbi_client', 'powerbi_clientsecret', 'email', 'telefono', 'direccion', 'razon_social', 'contacto_principal')))
 ORDER BY TABLE_NAME, COLUMN_NAME;

-- Columnas concedidas por tabla. ESPERADO: empresa 5 · empresa_reporte 4 · reporte 6  → total 15 (6+5+4)
SELECT TABLE_NAME, COUNT(*) AS columnas, GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) AS columnas_concedidas
FROM information_schema.COLUMN_PRIVILEGES WHERE GRANTEE = '''cervesur_ro_visor''@''127.0.0.1''' GROUP BY TABLE_NAME ORDER BY TABLE_NAME;
SELECT COUNT(*) AS total_columnas_concedidas FROM information_schema.COLUMN_PRIVILEGES WHERE GRANTEE = '''cervesur_ro_visor''@''127.0.0.1''';
