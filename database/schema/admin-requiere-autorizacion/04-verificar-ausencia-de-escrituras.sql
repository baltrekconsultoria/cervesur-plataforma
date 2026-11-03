-- UX-07D.3A.1 · Evidencia (NO prueba) de que las cuentas del piloto no han emitido sentencias de escritura. Solo SELECT / SHOW. LO EJECUTA UN OPERADOR
-- AUTORIZADO CON SU PROPIA CUENTA (nunca cervesur_ro_diag ni cervesur_ro_pilot: no pueden leer performance_schema ni mysql). Requiere autorización.
-- No muestra datos de las tablas del portal ni texto de sentencias: solo contadores por cuenta y tipo de sentencia.
--
-- IMPORTANTE — UN RESULTADO VACÍO EN LA CONSULTA 3 NO DEMUESTRA «CERO ESCRITURAS». Solo significa «ningún contador de escritura de esa cuenta». Lea las
-- consultas 1 y 2 ANTES: si alguna condición de la consulta 1 falla, el resultado vacío no tiene valor. La prueba de verdad es la combinación de:
--   (a) privilegios efectivos sin escritura (consultas 5 y 6 y 02-verificar-privilegios-diagnostico.sql y 02b-verificar-privilegios-piloto.sql), que impiden la escritura aunque se intente;
--   (b) sesión y cuenta que lo comprueban al arrancar (/api/diagnostico/resumen), y
--   (c) estos contadores, que solo añaden evidencia de uso.
--
-- Qué miden los contadores (limitaciones):
--   · events_statements_summary_by_user_by_event_name agrega por NOMBRE DE CUENTA (USER), no por host: cubre cervesur_ro_* desde cualquier host.
--   · Se reinician al reiniciar MySQL y con TRUNCATE de performance_schema; no cubren el tiempo anterior. Compare siempre con la hora de arranque (consulta 2).
--   · Dependen de que performance_schema esté activo y de que estén habilitados los instrumentos statement/% y el consumidor thread_instrumentation
--     (consulta 1); si setup_actors excluye a la cuenta, no se registra nada.
--   · Una sentencia RECHAZADA por permisos también incrementa su contador de tipo (INSERT, UPDATE…) y SUM_ERRORS: por eso un intento fallido aparece como
--     «veces > 0» con «con_error = veces». Esto sirve para detectar INTENTOS, no solo escrituras logradas.
--   · No ve lo que ocurra con OTRAS cuentas (p. ej. la de Flutter): no sirve para atribuir cambios en las tablas. Comparar sumas de verificación de las
--     tablas tampoco sirve en producción, porque Flutter sigue escribiendo.
--   · LAS SENTENCIAS PREPARADAS (protocolo binario, las que usa el backend) salen como statement/com/Prepare|Execute, no por verbo: ver la consulta 7. Las de procedimientos almacenados salen como statement/sp/*; las clasificadas como statement/com/* son comandos del protocolo.
--     Una cuenta sin EXECUTE ni escritura no puede originarlas, pero este recuento no las atribuye a un archivo del repositorio.
--   · Si se eliminó y recreó la cuenta, su historial se perdió (cuenta nueva = contadores desde cero).

-- 1) CONDICIONES DE VALIDEZ. ESPERADO: performance_schema = 1; las dos consultas siguientes devuelven los instrumentos/consumidores habilitados; setup_actors incluye a las cuentas.
SELECT @@performance_schema AS performance_schema_activo, NOW() AS ahora, DATE_SUB(NOW(), INTERVAL VARIABLE_VALUE SECOND) AS mysql_activo_desde
  FROM performance_schema.global_status WHERE VARIABLE_NAME = 'Uptime';
SELECT COUNT(*) AS instrumentos_statement_habilitados, SUM(TIMED = 'YES') AS con_tiempo
  FROM performance_schema.setup_instruments WHERE NAME LIKE 'statement/%' AND ENABLED = 'YES';
SELECT NAME AS consumidor, ENABLED AS habilitado FROM performance_schema.setup_consumers
 WHERE NAME IN ('global_instrumentation', 'thread_instrumentation', 'statements_digest');
SELECT HOST AS host_cubierto, USER AS usuario_cubierto, ENABLED AS habilitado, HISTORY AS historial FROM performance_schema.setup_actors;

-- 2) Qué hicieron las cuentas del piloto desde el último arranque de MySQL (esperado: solo SELECT, SHOW y sentencias de sesión como SET)
SELECT USER AS cuenta, EVENT_NAME AS tipo_de_sentencia, COUNT_STAR AS veces, SUM_ERRORS AS con_error
FROM performance_schema.events_statements_summary_by_user_by_event_name
WHERE USER IN ('cervesur_ro_diag', 'cervesur_ro_pilot') AND COUNT_STAR > 0 ORDER BY USER, COUNT_STAR DESC;

-- 3) Sentencias de ESCRITURA, administración o bloqueo emitidas o INTENTADAS por las cuentas del piloto. ESPERADO: 0 filas (válido SOLO si la consulta 1 es válida).
SELECT USER AS cuenta, EVENT_NAME AS tipo_de_sentencia, COUNT_STAR AS veces, SUM_ROWS_AFFECTED AS filas_afectadas, SUM_ERRORS AS con_error
FROM performance_schema.events_statements_summary_by_user_by_event_name
WHERE USER IN ('cervesur_ro_diag', 'cervesur_ro_pilot') AND COUNT_STAR > 0
  AND EVENT_NAME REGEXP '^statement/sql/(insert|insert_select|update|update_multi|delete|delete_multi|replace|replace_select|truncate|load|call_procedure|create_.*|alter_.*|drop_.*|rename_.*|grant|revoke|set_password|lock_tables|flush|kill|do|handler_.*|xa_.*|begin|commit|rollback|savepoint|release_savepoint)$'
ORDER BY USER, EVENT_NAME;

-- 4) Sentencias con error de las cuentas del piloto (útil para detectar un uso indebido; no distingue escritura de lectura fallida)
SELECT USER AS cuenta, SUM(SUM_ERRORS) AS sentencias_con_error, SUM(COUNT_STAR) AS sentencias_totales
FROM performance_schema.events_statements_summary_by_user_by_event_name WHERE USER IN ('cervesur_ro_diag', 'cervesur_ro_pilot') GROUP BY USER;

-- 5) Privilegios EFECTIVOS: todo lo que cada cuenta puede hacer, sin la ayuda de roles ni cuentas homónimas. ESPERADO: solo SELECT (global/base/tabla/columna) y USAGE.
-- La cuenta del piloto (si existiera) se verifica con 02b-verificar-privilegios-piloto.sql: aquí un SHOW GRANTS de una cuenta inexistente fallaría.
SHOW GRANTS FOR 'cervesur_ro_diag'@'127.0.0.1';
SELECT FROM_USER, FROM_HOST, TO_USER, TO_HOST FROM mysql.role_edges WHERE TO_USER IN ('cervesur_ro_diag', 'cervesur_ro_pilot');
SELECT USER, HOST, ACCOUNT_LOCKED, PASSWORD_EXPIRED FROM mysql.user WHERE USER IN ('cervesur_ro_diag', 'cervesur_ro_pilot') OR USER = '';
SELECT USER, HOST, PROXIED_USER, PROXIED_HOST FROM mysql.proxies_priv WHERE PROXIED_USER IN ('cervesur_ro_diag', 'cervesur_ro_pilot') OR USER IN ('cervesur_ro_diag', 'cervesur_ro_pilot');

-- 6) Comprobación del servidor: escrituras permitidas a nivel de servidor y modo de solo lectura global (informativo)
SELECT @@global.read_only AS read_only_global, @@global.super_read_only AS super_read_only_global, @@global.log_bin AS binlog_activo, @@global.general_log AS general_log;

-- 7) SENTENCIAS PREPARADAS (el backend usa prepare/execute nativos): LIMITACIÓN IMPORTANTE. Una sentencia enviada con el protocolo binario se registra como
--    statement/com/Prepare y statement/com/Execute, NO como statement/sql/insert|update|delete: la consulta 3 NO la clasifica por verbo. Por eso, además de la 3:
--    a) cualquier evento de la cuenta con filas afectadas > 0 es una modificación de datos (un SELECT afecta 0 filas). ESPERADO: 0 filas.
SELECT USER AS cuenta, EVENT_NAME AS tipo_de_sentencia, COUNT_STAR AS veces, SUM_ROWS_AFFECTED AS filas_afectadas
FROM performance_schema.events_statements_summary_by_user_by_event_name
WHERE USER IN ('cervesur_ro_diag', 'cervesur_ro_pilot') AND SUM_ROWS_AFFECTED > 0 ORDER BY USER, EVENT_NAME;
--    b) preparaciones/ejecuciones con error de la cuenta: un intento de escritura denegado por permisos aparece aquí (com/Prepare con SUM_ERRORS > 0) y no como «update».
--       Revise cada cuenta con errores: pueden ser intentos de escritura o lecturas fallidas; no se distinguen sin el texto de la sentencia.
SELECT USER AS cuenta, EVENT_NAME AS tipo_de_sentencia, COUNT_STAR AS veces, SUM_ERRORS AS con_error
FROM performance_schema.events_statements_summary_by_user_by_event_name
WHERE USER IN ('cervesur_ro_diag', 'cervesur_ro_pilot') AND EVENT_NAME IN ('statement/com/Prepare', 'statement/com/Execute') AND SUM_ERRORS > 0 ORDER BY USER, EVENT_NAME;
--    c) Para atribuir el TEXTO de cada sentencia a la cuenta hace falta habilitar el consumidor events_statements_history_long (desactivado por defecto) ANTES del uso;
--       no se activa aquí porque modificaría la configuración de MySQL.
