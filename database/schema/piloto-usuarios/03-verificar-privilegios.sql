-- UX-07D.4B · Verificación de SOLO LECTURA de las tres cuentas del piloto con usuarios (127.0.0.1). Solo SELECT / SHOW. REQUIERE AUTORIZACIÓN: la ejecuta un operador autorizado.
-- Ejecutar DESPUÉS de crear las cuentas (01 y 02). No modifica nada ni contiene contraseñas.

SHOW GRANTS FOR 'cervesur_ro_auth'@'127.0.0.1';
SHOW GRANTS FOR 'cervesur_ro_catalogo'@'127.0.0.1';
SHOW GRANTS FOR 'cervesur_react_estado'@'127.0.0.1';

-- Privilegios de ESCRITURA o administración de las dos cuentas de LECTURA, a cualquier nivel. ESPERADO: 0 filas en cada consulta.
SELECT GRANTEE, PRIVILEGE_TYPE, IS_GRANTABLE FROM information_schema.USER_PRIVILEGES
 WHERE GRANTEE IN ('''cervesur_ro_auth''@''127.0.0.1''', '''cervesur_ro_catalogo''@''127.0.0.1''') AND PRIVILEGE_TYPE <> 'USAGE';
SELECT GRANTEE, TABLE_SCHEMA, PRIVILEGE_TYPE FROM information_schema.SCHEMA_PRIVILEGES
 WHERE GRANTEE IN ('''cervesur_ro_auth''@''127.0.0.1''', '''cervesur_ro_catalogo''@''127.0.0.1''');
SELECT GRANTEE, TABLE_SCHEMA, TABLE_NAME, PRIVILEGE_TYPE FROM information_schema.TABLE_PRIVILEGES
 WHERE GRANTEE IN ('''cervesur_ro_auth''@''127.0.0.1''', '''cervesur_ro_catalogo''@''127.0.0.1''');
SELECT GRANTEE, TABLE_NAME, PRIVILEGE_TYPE, COUNT(*) AS columnas FROM information_schema.COLUMN_PRIVILEGES
 WHERE GRANTEE IN ('''cervesur_ro_auth''@''127.0.0.1''', '''cervesur_ro_catalogo''@''127.0.0.1''') AND (PRIVILEGE_TYPE <> 'SELECT' OR IS_GRANTABLE <> 'NO') GROUP BY GRANTEE, TABLE_NAME, PRIVILEGE_TYPE;

-- Separación de funciones. ESPERADO: la cuenta de catálogo NO tiene ninguna columna de user; la de autenticación NO tiene ninguna tabla distinta de user.
SELECT GRANTEE, TABLE_NAME, COUNT(*) AS columnas, GROUP_CONCAT(COLUMN_NAME ORDER BY COLUMN_NAME) AS columnas_concedidas
FROM information_schema.COLUMN_PRIVILEGES WHERE GRANTEE IN ('''cervesur_ro_auth''@''127.0.0.1''', '''cervesur_ro_catalogo''@''127.0.0.1''') GROUP BY GRANTEE, TABLE_NAME ORDER BY GRANTEE, TABLE_NAME;
-- ESPERADO: cervesur_ro_auth 9 · cervesur_ro_catalogo 21 (6+5+4+3+3)
SELECT GRANTEE, COUNT(*) AS total_columnas FROM information_schema.COLUMN_PRIVILEGES
 WHERE GRANTEE IN ('''cervesur_ro_auth''@''127.0.0.1''', '''cervesur_ro_catalogo''@''127.0.0.1''') GROUP BY GRANTEE ORDER BY GRANTEE;
-- Columnas de credenciales de Power BI concedidas a cualquiera de las dos. ESPERADO: 0 filas.
SELECT GRANTEE, TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMN_PRIVILEGES
 WHERE GRANTEE IN ('''cervesur_ro_auth''@''127.0.0.1''', '''cervesur_ro_catalogo''@''127.0.0.1''') AND COLUMN_NAME LIKE 'powerbi\_%';
