-- UX-07D.3B.1 · Verificación de SOLO LECTURA de la cuenta del PILOTO AUTENTICADO cervesur_ro_pilot (FUTURA: no se crea hasta aprobar HTTPS, datos y autenticación).
-- Solo SELECT / SHOW. REQUIERE AUTORIZACIÓN: la ejecuta un operador autorizado. NO forma parte del paquete del diagnóstico 8090 y NO debe ejecutarse mientras la cuenta no exista (SHOW GRANTS falla).
-- Marcador: __HOST__ (el mismo que se usó en 03-cuenta-piloto-autenticado.sql).

SHOW GRANTS FOR 'cervesur_ro_pilot'@'__HOST__';

-- Privilegios de ESCRITURA o administración a cualquier nivel. ESPERADO: 0 filas en cada consulta.
SELECT GRANTEE, PRIVILEGE_TYPE, IS_GRANTABLE FROM information_schema.USER_PRIVILEGES
 WHERE GRANTEE = '''cervesur_ro_pilot''@''__HOST__''' AND PRIVILEGE_TYPE <> 'USAGE';
SELECT GRANTEE, TABLE_SCHEMA, PRIVILEGE_TYPE, IS_GRANTABLE FROM information_schema.SCHEMA_PRIVILEGES
 WHERE GRANTEE = '''cervesur_ro_pilot''@''__HOST__''';
SELECT GRANTEE, TABLE_SCHEMA, TABLE_NAME, PRIVILEGE_TYPE, IS_GRANTABLE FROM information_schema.TABLE_PRIVILEGES
 WHERE GRANTEE = '''cervesur_ro_pilot''@''__HOST__''';
SELECT GRANTEE, TABLE_NAME, PRIVILEGE_TYPE, COUNT(*) AS columnas FROM information_schema.COLUMN_PRIVILEGES
 WHERE GRANTEE = '''cervesur_ro_pilot''@''__HOST__''' AND (PRIVILEGE_TYPE <> 'SELECT' OR IS_GRANTABLE <> 'NO') GROUP BY GRANTEE, TABLE_NAME, PRIVILEGE_TYPE;

-- Columnas SENSIBLES concedidas. ESPERADO: exactamente user.password y reporte.enlace (nunca empresa.powerbi_*).
SELECT GRANTEE, TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMN_PRIVILEGES
 WHERE GRANTEE = '''cervesur_ro_pilot''@''__HOST__''' AND ((TABLE_NAME = 'user' AND COLUMN_NAME = 'password')
    OR (TABLE_NAME = 'empresa' AND COLUMN_NAME LIKE 'powerbi\_%') OR (TABLE_NAME = 'reporte' AND COLUMN_NAME = 'enlace'))
 ORDER BY TABLE_NAME, COLUMN_NAME;
