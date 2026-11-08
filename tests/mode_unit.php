<?php
// UX-07D.3A · Modo de operación (solo lectura / diagnóstico). Sin red ni base de datos: php tests/mode_unit.php
require_once __DIR__ . '/../backend/lib/mode.php';

function check($condition, $name) {
    if (!$condition) { fwrite(STDERR, "FAIL: $name\n"); exit(1); }
    echo "PASS: $name\n";
}
function evaluate(array $env) { return app_mode_evaluate($env); }
function refused(array $env) { return evaluate($env)['mode'] === null; }
$LONG = str_repeat('a', 40);
$TOKEN = str_repeat('t', 40);

// ---------------------------------------------------------------- configuración (fail-closed)
check(evaluate([])['mode'] === 'read_write', 'sin CERVESUR_MODE el comportamiento histórico (read_write) se conserva en entornos de prueba');
check(evaluate(['CERVESUR_MODE' => '  ', 'DB_NAME' => 'cervesur_react_prueba'])['mode'] === 'read_write', 'valor vacío = histórico, en una base que no es productiva');
check(refused(['CERVESUR_MODE' => 'solo_lectura']) && refused(['CERVESUR_MODE' => 'READ_ONLY']) && refused(['CERVESUR_MODE' => 'readonly']) && refused(['CERVESUR_MODE' => 'true']), 'un valor desconocido NO se interpreta: se rechaza (ni siquiera cambiando mayúsculas)');
check(refused(['DB_NAME' => 'cervesur_portalbi']) && refused(['DB_NAME' => 'CERVESUR_PORTALBI', 'CERVESUR_MODE' => 'read_write']), 'la base productiva sin CERVESUR_MODE (o en read_write) se rechaza: no se abre por omisión ni por mayúsculas');
check(refused(['DB_NAME' => 'otra_base_real', 'CERVESUR_PRODUCTION_DBS' => 'x, otra_base_real']), 'CERVESUR_PRODUCTION_DBS amplía la lista de bases protegidas');
check(evaluate(['DB_NAME' => 'cervesur_portalbi', 'CERVESUR_MODE' => 'read_only', 'JWT_SECRET' => $LONG])['mode'] === 'read_only', 'la base productiva se abre en read_only con secretos propios');
check(refused(['DB_NAME' => 'cervesur_portalbi', 'CERVESUR_MODE' => 'read_only', 'JWT_SECRET' => $LONG, 'CERVESUR_ALLOW_WRITABLE_DB_ACCOUNT' => 'SI']), 'sobre la base productiva nunca se admite una cuenta con escritura, ni con el permiso de pruebas');
check(evaluate(['DB_NAME' => 'fixture', 'CERVESUR_MODE' => 'read_only', 'JWT_SECRET' => $LONG, 'CERVESUR_ALLOW_WRITABLE_DB_ACCOUNT' => 'SI'])['mode'] === 'read_only', 'en una base sintética el permiso de cuenta con escritura existe solo para pruebas');
check(refused(['CERVESUR_MODE' => 'read_only']) && refused(['CERVESUR_MODE' => 'read_only', 'JWT_SECRET' => 'corto']), 'read_only sin JWT_SECRET propio de 32+ caracteres se rechaza');
check(refused(['CERVESUR_MODE' => 'diagnostico']) && refused(['CERVESUR_MODE' => 'diagnostico', 'CERVESUR_DIAG_TOKEN' => 'corto']), 'diagnóstico sin token de 32+ caracteres se rechaza');
check(refused(['CERVESUR_MODE' => 'diagnostico', 'CERVESUR_DIAG_TOKEN' => $LONG, 'JWT_SECRET' => $LONG]), 'el token de diagnóstico no puede ser el JWT_SECRET');
check(evaluate(['CERVESUR_MODE' => 'diagnostico', 'CERVESUR_DIAG_TOKEN' => $TOKEN])['mode'] === 'diagnostico', 'diagnóstico con token propio es válido (no necesita JWT_SECRET: no hay inicio de sesión)');
check(!in_array(evaluate(['CERVESUR_MODE' => 'diagnostico'])['errors'], [[]], true), 'toda configuración rechazada informa la causa para el log interno');

// ---------------------------------------------------------------- puerta de rutas
check(app_mode_route_gate('read_write', 'DELETE', '/api/reportes/1') === null, 'read_write no restringe (entornos de prueba)');
$writes = [['POST', '/api/reportes'], ['PUT', '/api/reportes/1'], ['DELETE', '/api/reportes/1'], ['POST', '/api/register'], ['PUT', '/api/user/profile'], ['PUT', '/api/user/profile/3'], ['POST', '/api/user/avatar'],
    ['PUT', '/api/user/3/role'], ['POST', '/api/empresas'], ['PUT', '/api/empresas/1'], ['DELETE', '/api/empresas/1'], ['POST', '/api/asignacion'], ['PUT', '/api/asignacion/1'], ['DELETE', '/api/asignacion/1'],
    ['POST', '/api/empresa-usuario'], ['PUT', '/api/empresa-usuario/1'], ['DELETE', '/api/empresa-usuario/1'], ['POST', '/api/empresa-reporte-usuario'], ['PUT', '/api/empresa-reporte-usuario/1'], ['DELETE', '/api/empresa-reporte-usuario/1'],
    ['PATCH', '/api/reportes/1'], ['TRACE', '/api/reportes'], ['POST', '/api/desconocida'], ['POST', '/api/login/'], ['post', '/api/reportes']];
$blocked = 0; foreach ($writes as [$m, $u]) { $g = app_mode_route_gate('read_only', $m, $u); if ($g && $g['status'] === 403 && $g['code'] === 'read_only' && strtoupper($m) !== 'GET') { $blocked++; } }
check($blocked === count($writes) - 1, 'read_only bloquea todo método de escritura y desconocido (el único permitido de la lista es POST /api/login/, normalizado)');
check(app_mode_route_gate('read_only', 'POST', '/api/login') === null && app_mode_route_gate('read_only', 'POST', '/api/logout') === null, 'read_only permite login y logout (que en este modo no escriben)');
check(app_mode_route_gate('read_only', 'GET', '/api/reportes') === null && app_mode_route_gate('read_only', 'HEAD', '/api/reportes/id/5/visor') === null && app_mode_route_gate('read_only', 'OPTIONS', '/api/reportes') === null, 'read_only permite las lecturas');
check(app_mode_route_gate('read_only', 'GET', '/api/powerbi/tokens/1')['code'] === 'legacy_blocked' && app_mode_route_gate('read_only', 'POST', '/api/powerbi/x')['code'] === 'legacy_blocked' && app_mode_route_gate('diagnostico', 'GET', '/api/powerbi/tokens/1')['code'] === 'legacy_blocked', 'el endpoint heredado de tokens de Power BI se bloquea en todo modo restringido');
check(app_mode_route_gate('diagnostico', 'GET', '/api/ping') === null && app_mode_route_gate('diagnostico', 'GET', '/api/diagnostico/resumen') === null && app_mode_route_gate('diagnostico', 'GET', '/api/diagnostico/conciliacion') === null, 'diagnóstico solo permite ping y sus dos endpoints agregados, por GET');
$notAllowed = [['POST', '/api/login'], ['POST', '/api/logout'], ['POST', '/api/register'], ['GET', '/api/reportes'], ['GET', '/api/users'], ['GET', '/api/empresas'], ['GET', '/api/user/profile'], ['GET', '/api/reportes/id/1/visor'], ['GET', '/api/reportes/grupoid/x'], ['GET', '/api/stats'],
    ['GET', '/api/asignacion'], ['GET', '/api/empresa-usuario'], ['GET', '/api/empresa-reporte-usuario'], ['GET', '/api/wiki'], ['GET', '/api/'], ['GET', '/'], ['POST', '/api/diagnostico/resumen'], ['GET', '/api/diagnostico/otro'],
    ['HEAD', '/api/ping'], ['HEAD', '/api/diagnostico/resumen'], ['OPTIONS', '/api/ping'], ['PUT', '/api/diagnostico/resumen'], ['DELETE', '/api/diagnostico/conciliacion'], ['GET', '/api/ping/'], ['GET', '/api/diagnostico/resumen/'], ['GET', '//api/ping'],
    ['GET', '/API/PING'], ['GET', '/api/diagnostico/resumen/../../reportes'], ['GET', '/api/diagnostico/../reportes'], ['GET', '/api/ping%2f'], ['GET', '/api/diagnostico/resumen%00'], ['GET', ''], ['GET', null]];
$denied = 0; $wrongCode = [];
foreach ($notAllowed as [$m, $u]) { $g = app_mode_route_gate('diagnostico', $m, $u); if (is_array($g) && $g['status'] === 403) { $denied++; } else { $wrongCode[] = "$m $u"; } }
check($denied === count($notAllowed), 'diagnóstico rechaza (403) login, registro, logout, catálogos, informes, visor, estadísticas, escrituras, HEAD/OPTIONS, rutas con barra final, mayúsculas o saltos de directorio (' . $denied . ' casos' . ($wrongCode ? '; permitidos por error: ' . implode(', ', $wrongCode) : '') . ')');

// ---------------------------------------------------------------- guardia de SQL
$reads = ['SELECT 1', '  select * from user', "SELECT id FROM user WHERE role = 'admin'\n  AND is_active = 1;", '(SELECT 1) UNION (SELECT 2)', 'SELECT COUNT(*) FROM user u INNER JOIN empresa e ON e.id = u.id WHERE u.id = ?', 'SHOW GRANTS FOR CURRENT_USER()', 'show grants for current_user()', 'SET SESSION TRANSACTION READ ONLY',
    'SELECT DATABASE()', 'SELECT @@session.transaction_read_only', 'SELECT updated_at, created_by FROM reporte WHERE nombre LIKE ?'];
$okReads = 0; foreach ($reads as $sql) { try { app_sql_guard($sql); $okReads++; } catch (ReadOnlyViolation $e) { fwrite(STDERR, "rechazada: $sql\n"); } }
check($okReads === count($reads), 'la guardia deja pasar las consultas de lectura legítimas y las dos sentencias de control (' . $okReads . ' casos)');
// Premisa verificada por el motor en la prueba Docker (http_readonly_test.php): MySQL acepta «WITH … UPDATE/DELETE». Por eso WITH ya no se acepta.
$cteWrites = ['WITH c AS (SELECT 1 AS id) UPDATE user JOIN c ON c.id = user.id SET role = \'admin\'', 'WITH c AS (SELECT 1) DELETE FROM user WHERE id IN (SELECT * FROM c)', 'INSERT INTO user (id) WITH c AS (SELECT 1 AS id) SELECT id FROM c', 'with c as (select 1) update user set role = 1', "WITH\nc AS (SELECT 1)\nINSERT INTO t SELECT * FROM c", 'WITH RECURSIVE c AS (SELECT 1 UNION ALL SELECT n + 1 FROM c WHERE n < 5) SELECT * FROM c',
    '(WITH c AS (SELECT 1) SELECT * FROM c)', 'SELECT * FROM (WITH c AS (SELECT 1) SELECT * FROM c) x', 'SELECT 1 FROM user GROUP BY id WITH ROLLUP'];
$cteRejected = 0; foreach ($cteWrites as $sql) { try { app_sql_guard($sql); fwrite(STDERR, "PASÓ (mal): $sql\n"); } catch (ReadOnlyViolation $e) { $cteRejected++; } }
check($cteRejected === count($cteWrites), 'WITH … UPDATE / DELETE / INSERT y cualquier WITH se rechazan: empezar por WITH no es prueba de lectura (' . $cteRejected . ' casos)');
$writesSql = ['INSERT INTO user VALUES (1)', 'update user set role = 1', 'DELETE FROM reporte', 'REPLACE INTO reporte VALUES (1)', 'CREATE TABLE x (a INT)', 'ALTER TABLE reporte ADD c INT', 'DROP TABLE user', 'TRUNCATE reporte', 'CALL purge()', 'GRANT ALL ON *.* TO x',
    'SELECT 1; DELETE FROM user', 'SELECT 1; SELECT 2', "SELECT * FROM user INTO OUTFILE '/tmp/x'", 'SELECT * FROM user FOR UPDATE', 'SELECT * FROM user LOCK IN SHARE MODE', 'SELECT GET_LOCK(\'a\', 1)', '/* SELECT */ DELETE FROM user', "-- SELECT\nDELETE FROM user", 'SET GLOBAL read_only = 0', 'SET SESSION TRANSACTION READ WRITE', 'START TRANSACTION', 'LOAD DATA INFILE \'x\' INTO TABLE user', 'LOCK TABLES user WRITE', 'UPDATE user SET session_version = session_version + 1 WHERE id = 1', "SELECT 'x -- y'; DELETE FROM user", "SELECT 1 -- c
; DELETE FROM user", "SELECT 1 /* ; */ ; DROP TABLE user"];
$rejected = 0; foreach ($writesSql as $sql) { try { app_sql_guard($sql); fwrite(STDERR, "PASÓ (mal): $sql\n"); } catch (ReadOnlyViolation $e) { $rejected++; } }
check($rejected === count($writesSql), 'la guardia rechaza INSERT, UPDATE, DELETE, DDL, CALL, varias sentencias, comentarios disfrazados, INTO OUTFILE, bloqueos y cambios de modo (' . $rejected . ' casos)');
check((new ReadOnlyViolation('x')) instanceof PDOException, 'ReadOnlyViolation es una PDOException: los modelos existentes la tratan como un error de BD, sin romper la respuesta');

// ---------------------------------------------------------------- privilegios de la cuenta
$readOnlyGrants = ['GRANT USAGE ON *.* TO `ro`@`%`', "GRANT SELECT (`id`, `role`, `is_active`) ON `cervesur_portalbi`.`user` TO `ro`@`%`", 'GRANT SELECT (`id`, `activo`) ON `cervesur_portalbi`.`empresa` TO `ro`@`%`', 'GRANT SELECT ON `db`.`t` TO `ro`@`%`'];
check(app_grants_violations($readOnlyGrants) === [], 'una cuenta con USAGE y SELECT (también por columna) se considera de solo lectura');
$privileges = ['INSERT', 'UPDATE', 'DELETE', 'CREATE', 'DROP', 'ALTER', 'INDEX', 'TRIGGER', 'EXECUTE', 'REFERENCES', 'FILE', 'SUPER', 'PROCESS', 'CREATE USER', 'LOCK TABLES', 'RELOAD', 'REPLICATION SLAVE', 'SHOW DATABASES', 'ALL PRIVILEGES', 'EVENT'];
$detected = 0;
foreach ($privileges as $privilege) {
    $v = app_grants_violations(["GRANT $privilege ON `db`.* TO `u`@`%`"]);
    if ($v !== [$privilege]) { check(false, "privilegio $privilege detectado como violación (obtenido: " . json_encode($v) . ')'); }
    $detected++;
}
check($detected === count($privileges), 'cada privilegio distinto de SELECT/USAGE (' . $detected . ' comprobados) se detecta como violación');
check(app_grants_violations(["GRANT SELECT, INSERT (`a`) ON `db`.`t` TO `u`@`%`"]) === ['INSERT'], 'INSERT por columna también es violación aunque vaya junto a SELECT');
check(app_grants_violations(["GRANT SELECT ON `db`.* TO `u`@`%` WITH GRANT OPTION"]) === ['GRANT OPTION'], 'WITH GRANT OPTION es violación');
check(app_grants_violations(['GRANT `rol_admin`@`%` TO `u`@`%`']) === ['concesión no auditable'], 'una concesión de rol no se puede auditar y se rechaza (fail-closed)');
check(app_grants_violations(['GRANT SELECT ON *.* TO `u`@`%`', 'GRANT UPDATE, DELETE ON `db`.`reporte` TO `u`@`%`']) === ['UPDATE', 'DELETE'], 'basta una concesión de escritura entre varias para rechazar la cuenta');

// ---------------------------------------------------------------- UX-07D.3A.1: modo EXIGIDO por el despliegue (sitio 8090) y nombre de base
$site = ['CERVESUR_REQUIRED_MODE' => 'diagnostico', 'CERVESUR_DIAG_TOKEN' => $TOKEN, 'DB_NAME' => 'cervesur_portalbi'];
check(evaluate($site + ['CERVESUR_MODE' => 'diagnostico'])['mode'] === 'diagnostico', 'sitio con modo exigido: diagnostico + token propio arranca');
check(refused($site) && refused($site + ['CERVESUR_MODE' => '']) && refused($site + ['CERVESUR_MODE' => '  ']), 'con modo exigido, un CERVESUR_MODE AUSENTE o vacío impide el arranque (no se asume read_write)');
check(refused($site + ['CERVESUR_MODE' => 'read_only', 'JWT_SECRET' => $LONG]) && refused($site + ['CERVESUR_MODE' => 'read_write']) && refused($site + ['CERVESUR_MODE' => 'Diagnostico']) && refused($site + ['CERVESUR_MODE' => 'diagnostico ']) === false, 'con modo exigido, cualquier modo distinto de «diagnostico» impide el arranque (el espacio final se recorta, no se interpreta otra cosa)');
check(refused($site + ['CERVESUR_MODE' => 'solo_lectura']) && refused(['CERVESUR_REQUIRED_MODE' => 'otro', 'CERVESUR_MODE' => 'diagnostico', 'CERVESUR_DIAG_TOKEN' => $TOKEN]), 'un modo exigido o un modo desconocido no se interpretan: se rechazan');
$badNames = ['cervesur_portalbi;dbname=otra', 'x;host=evil', 'cervesur_portalbi ', ' cervesur_portalbi', "cervesur_portalbi\n", 'cervesur`portalbi', 'a=b', 'cervesur_portalbi\\', str_repeat('a', 65), "cervesur_portalbi\0", 'cervesur.portalbi', '../cervesur_portalbi'];
$badRejected = 0; foreach ($badNames as $name) { if (refused(['CERVESUR_MODE' => 'diagnostico', 'CERVESUR_DIAG_TOKEN' => $TOKEN, 'DB_NAME' => $name]) && refused(['CERVESUR_MODE' => 'read_write', 'DB_NAME' => $name])) { $badRejected++; } else { fwrite(STDERR, "aceptado (mal): " . json_encode($name) . "\n"); } }
check($badRejected === count($badNames), 'un DB_NAME con caracteres que permitirían inyectar parámetros del DSN o esquivar la comparación se rechaza en todos los modos (' . $badRejected . ' casos)');
check(refused(['CERVESUR_MODE' => 'read_write', 'DB_NAME' => 'CerVesur_PortalBI']) && refused(['CERVESUR_MODE' => 'read_write', 'DB_NAME' => 'x', 'CERVESUR_PRODUCTION_DBS' => ' X ']), 'la detección de la base productiva ignora mayúsculas y espacios en la lista configurable');
check(evaluate(['CERVESUR_MODE' => 'read_write', 'DB_NAME' => 'cervesur_react_prueba'])['mode'] === 'read_write' && evaluate(['CERVESUR_MODE' => 'read_write', 'DB_NAME' => 'cervesur_react_compat_qa'])['mode'] === 'read_write' && evaluate(['DB_NAME' => 'fixture_prod'])['mode'] === 'read_write', 'los entornos de prueba existentes (8088, 8089, fixtures) conservan su comportamiento');

// ---------------------------------------------------------------- UX-07D.3A.1: guardia adversarial
$adversarial = [
    // verbos y estructura
    'INSERT INTO user (id) VALUES (1)', 'REPLACE INTO user (id) VALUES (1)', 'DELETE FROM user', 'UPDATE user SET role = 1', 'DELETE user FROM user JOIN empresa ON 1', 'UPDATE user JOIN empresa ON 1 SET user.role = 1',
    'TABLE user', 'VALUES ROW(1)', 'EXPLAIN SELECT 1', 'EXPLAIN ANALYZE SELECT 1', 'DESCRIBE user', 'HANDLER user OPEN', 'DO SLEEP(1)', 'CALL p()', 'PREPARE s FROM \'DELETE FROM user\'', 'EXECUTE s', 'DEALLOCATE PREPARE s',
    'SET @a = 1', 'SET GLOBAL read_only = 0', 'SET PERSIST read_only = 0', 'SET SESSION TRANSACTION READ WRITE', 'SET TRANSACTION READ WRITE', 'SET SESSION transaction_read_only = 0', 'START TRANSACTION READ WRITE', 'BEGIN', 'COMMIT', 'ROLLBACK', 'XA START \'a\'',
    'USE mysql', 'SHOW DATABASES', 'SHOW TABLES', 'SHOW GRANTS', 'SHOW GRANTS FOR root', 'SHOW GRANTS FOR CURRENT_USER() USING admin', 'SHOW CREATE TABLE user', 'LOCK TABLES user READ', 'UNLOCK TABLES', 'FLUSH PRIVILEGES', 'KILL 1', 'RESET MASTER',
    'GRANT ALL ON *.* TO x', 'CREATE USER x', 'ALTER USER root IDENTIFIED BY \'x\'', 'DROP DATABASE x', 'LOAD DATA INFILE \'/etc/passwd\' INTO TABLE user', 'INSTALL PLUGIN x SONAME \'x.so\'',
    // varias sentencias y comentarios
    'SELECT 1; DELETE FROM user', 'SELECT 1;DELETE FROM user', "SELECT 1\n;\nDELETE FROM user", 'SELECT 1; SELECT 2', 'SELECT 1 /* ; */ ; DROP TABLE user', "SELECT 'x'; DELETE FROM user -- '", 'SELECT 1 -- c', "SELECT 1 -- c\n; DELETE FROM user", 'SELECT 1 # c', 'SELECT /* c */ 1',
    'SELECT /*!50000 1 */', '/*!50000 DELETE FROM user */', 'SELECT 1 /*!50000 ; DELETE FROM user */', 'SELECT /*+ SET_VAR(sql_mode=\'\') */ 1', '/* SELECT */ DELETE FROM user', "/* a */ /* b */ UPDATE user SET role = 1", "-- SELECT\nDELETE FROM user", '#SELECT' . "\n" . 'DELETE FROM user',
    // efectos laterales dentro de un SELECT
    "SELECT * FROM user INTO OUTFILE '/tmp/x'", "SELECT * FROM user INTO DUMPFILE '/tmp/x'", 'SELECT id INTO @v FROM user', 'SELECT @a := 1', 'SELECT * FROM user FOR UPDATE', 'SELECT * FROM user FOR SHARE', 'SELECT * FROM user LOCK IN SHARE MODE', 'SELECT * FROM user FOR UPDATE SKIP LOCKED',
    "SELECT GET_LOCK('a', 1)", "SELECT release_lock('a')", 'SELECT SLEEP(10)', 'SELECT BENCHMARK(1000000000, SHA1(\'a\'))', "SELECT LOAD_FILE('/etc/passwd')", 'SELECT @@global.read_only', 'SELECT @@persist.x', 'SELECT * FROM user PROCEDURE ANALYSE()',
    // ofuscación
    "SELECT\x00 1", "SELECT 1\x1b", "\xEF\xBB\xBFSELECT 1", "SELECT '\xC3\x28'", 'select/**/1', "SELECT\t1; DELETE FROM user", 'ＳＥＬＥＣＴ 1', '', '   ', ';', '(DELETE FROM user)',
    "SELECT 1 FROM user WHERE id = 1 OR 1=1; DROP TABLE user", "  \n\t DELETE FROM user", 'DELETE/**/FROM user',
];
$passed = [];
foreach ($adversarial as $sql) { try { app_sql_guard($sql); $passed[] = json_encode($sql); } catch (ReadOnlyViolation $e) { } }
check($passed === [] || (fwrite(STDERR, 'Pasaron: ' . implode(' | ', $passed) . "\n") && false), 'guardia: ' . count($adversarial) . ' sentencias adversariales (escrituras, CTE, comentarios, varias sentencias, efectos laterales, ofuscación) rechazadas');

// ---------------------------------------------------------------- UX-07D.3A.1: catálogo cerrado del modo diagnostico
require_once __DIR__ . '/../backend/lib/diagnostico_catalogo.php';
$catalog = app_sql_catalog(diagnostico_catalogo_sentencias());
$all = diagnostico_catalogo_sentencias();
$accepted = 0; foreach ($all as $sql) { try { app_sql_check($sql, $catalog); $accepted++; } catch (ReadOnlyViolation $e) { fwrite(STDERR, "catálogo rechaza lo suyo: $sql\n"); } }
check($accepted === count($all) && count($all) === 27 + 3 + 1, 'el catálogo contiene exactamente las 27 medidas, las 3 sentencias de control y la fijación de solo lectura, y todas pasan la guardia (' . $accepted . ')');
check(app_sql_check($all[0] . "  \n", $catalog) === null && app_sql_check(str_replace(' ', "\n  ", $all[0]) . ';', $catalog) === null, 'el catálogo ignora solo diferencias de espacios y el «;» final');
$outside = ['SELECT COUNT(*) FROM reporte WHERE enlace IS NOT NULL', 'SELECT password FROM user', 'SELECT * FROM user', 'SELECT id FROM user', 'SELECT COUNT(*) FROM user WHERE is_active = 1 OR 1 = 1', 'select count(*) from user', 'SELECT COUNT(*) FROM user WHERE is_active = 1 AND id = ?',
    'SELECT COUNT(*) FROM user -- ', 'SELECT 1', 'SELECT NOW()', 'SELECT VERSION()', 'SELECT * FROM information_schema.tables', 'SELECT COUNT(*) FROM mysql.user', 'SHOW GRANTS FOR CURRENT_USER', 'SHOW DATABASES', 'SELECT @@version', 'SELECT DATABASE() ', "SELECT COUNT(*) FROM user WHERE role = 'admin' "];
$outsideRejected = 0; $outsideBad = [];
foreach ($outside as $sql) { try { app_sql_check($sql, $catalog); if (isset($catalog[app_sql_normalize($sql)])) { continue; } $outsideBad[] = $sql; } catch (ReadOnlyViolation $e) { $outsideRejected++; } }
// «SELECT DATABASE() » y «... role = 'admin' » con espacio final SON las del catálogo (se normalizan): se cuentan aparte
$inCatalog = count($outside) - $outsideRejected - count($outsideBad);
check($outsideBad === [] && $outsideRejected === count($outside) - $inCatalog && $inCatalog === 2, 'con catálogo, cualquier SELECT que no figure literalmente se rechaza, aunque sea de lectura y pase la guardia general (' . $outsideRejected . ' rechazadas; solo las 2 que son el catálogo con espacio final pasan)');
check((function () { $c = app_sql_catalog(['SELECT 1']); try { app_sql_check('DELETE FROM user', $c); return false; } catch (ReadOnlyViolation $e) { return true; } })(), 'con catálogo, una escritura sigue rechazada por la guardia general antes de consultar el catálogo');
foreach (diagnostico_catalogo_medidas() as $name => $measure) { if (!in_array($measure['visibilidad'], ['publica', 'solo_coincidencia'], true)) { check(false, "medida $name sin visibilidad válida"); } }
$inventory = require __DIR__ . '/../backend/lib/inventario_esperado.php';
$inventoryNames = array_keys($inventory); $catalogNames = array_keys(diagnostico_catalogo_medidas()); sort($inventoryNames); sort($catalogNames);
check($inventoryNames === $catalogNames && count($catalogNames) === 27, 'el catálogo y el inventario esperado miden exactamente las mismas 27 magnitudes');
$mentioned = [];
foreach (diagnostico_catalogo_medidas() as $measure) { if (preg_match_all('/\b(password|email|phone|first_name|last_name|username|avatar|enlace|grupo_id|nombre|ruc|powerbi_\w+|logo|descripcion|notas)\b/i', $measure['sql'], $m)) { $mentioned = array_merge($mentioned, $m[0]); } }
check($mentioned === [], 'ninguna consulta del catálogo menciona columnas personales, de enlaces ni de credenciales');

// ---------------------------------------------------------------- UX-07D.3A.1: grupos pequeños
require_once __DIR__ . '/../backend/controllers/DiagnosticoController.php';
$real = $inventory;
$hidden = DiagnosticoController::reservadas($real);
check(!in_array('usuarios_total', $hidden, true) && !in_array('informes_total', $hidden, true) && in_array('usuarios_rol_admin', $hidden, true) && in_array('asignaciones_activas_de_usuarios_inactivos', $hidden, true), 'las magnitudes grandes se publican; roles y anomalías (grupos pequeños) se reservan');
$subsetsHidden = DiagnosticoController::reservadas(['usuarios_total' => 76, 'usuarios_activos' => 74] + array_fill_keys(array_keys($real), 100));
check(in_array('usuarios_total', $subsetsHidden, true) && in_array('usuarios_activos', $subsetsHidden, true), 'si total − activos es un grupo de 1 a 4 personas, ambas cifras se reservan (no se deduce restando)');
$noSmall = DiagnosticoController::reservadas(['usuarios_total' => 76, 'usuarios_activos' => 62] + array_fill_keys(array_keys($real), 100));
check(!in_array('usuarios_total', $noSmall, true) && !in_array('usuarios_activos', $noSmall, true), 'una diferencia de 14 usuarios no se reserva');
$zero = DiagnosticoController::reservadas(['informes_total' => 10, 'informes_activos' => 10] + array_fill_keys(array_keys($real), 100));
check(!in_array('informes_total', $zero, true), 'una diferencia de 0 no identifica a nadie y no se reserva');
$tiny = DiagnosticoController::reservadas(['empresas_activas' => 3] + array_fill_keys(array_keys($real), 100));
check(in_array('empresas_activas', $tiny, true), 'un recuento de 1 a 4 nunca se publica');

// ---------------------------------------------------------------- UX-07D.4: piloto visual
$HASH = '$2y$10$' . str_repeat('a', 53);
$pilot = ['CERVESUR_MODE' => 'piloto_visual', 'CERVESUR_REQUIRED_MODE' => 'piloto_visual', 'DB_NAME' => 'cervesur_portalbi', 'JWT_SECRET' => $LONG, 'CERVESUR_PILOT_OPERATOR_HASH' => $HASH, 'CERVESUR_PILOT_STATE_DIR' => '/var/lib/cervesur-react-piloto/estado', 'CERVESUR_PILOT_VISOR_IDS' => ''];
check(evaluate($pilot)['mode'] === 'piloto_visual', 'piloto visual: configuración completa válida sobre la base productiva');
$missing = 0; foreach (['JWT_SECRET', 'CERVESUR_PILOT_OPERATOR_HASH', 'CERVESUR_PILOT_STATE_DIR', 'CERVESUR_MODE'] as $k) { $e = $pilot; unset($e[$k]); if (refused($e)) $missing++; }
check($missing === 4, 'piloto visual: sin JWT propio, sin hash del operador, sin carpeta de estado o sin modo (con modo exigido) NO arranca');
$weak = 0; foreach ([['JWT_SECRET', 'corto'], ['CERVESUR_PILOT_OPERATOR_HASH', 'texto-plano'], ['CERVESUR_PILOT_OPERATOR_HASH', '$2y$10$corto'], ['CERVESUR_PILOT_STATE_DIR', 'relativa/ruta'], ['CERVESUR_PILOT_STATE_DIR', '/tmp; rm'], ['CERVESUR_PILOT_VISOR_IDS', 'todos'], ['CERVESUR_PILOT_VISOR_IDS', '1,2,x'], ['CERVESUR_PILOT_VISOR_IDS', '0'], ['CERVESUR_PILOT_VISOR_IDS', '1;2'], ['CERVESUR_PILOT_VISOR_IDS', '*']] as [$k, $v]) { if (refused([$k => $v] + $pilot)) $weak++; }
check($weak === 10, 'piloto visual: JWT corto, hash que no es bcrypt, rutas inseguras y listas de informes mal formadas se rechazan (10 casos)');
check(evaluate(['CERVESUR_PILOT_VISOR_IDS' => 'TODOS'] + $pilot)['mode'] === 'piloto_visual' && evaluate(['CERVESUR_PILOT_VISOR_IDS' => '5,12,300'] + $pilot)['mode'] === 'piloto_visual', 'piloto visual: «TODOS» y listas de ids numéricos son válidos');
check(refused(['CERVESUR_ALLOW_WRITABLE_DB_ACCOUNT' => 'SI'] + $pilot) && refused(['CERVESUR_MODE' => 'read_only'] + $pilot) && refused(['CERVESUR_MODE' => 'diagnostico', 'CERVESUR_DIAG_TOKEN' => $TOKEN] + $pilot), 'piloto visual: con modo exigido, otro modo o una cuenta con escritura sobre la base productiva impiden el arranque');
$okRoutes = [['GET', '/api/ping'], ['GET', '/api/user/profile'], ['GET', '/api/reportes'], ['GET', '/api/reportes/12'], ['GET', '/api/reportes/id/12/visor'], ['GET', '/api/empresas'], ['GET', '/api/asignacion'], ['POST', '/api/login'], ['POST', '/api/logout']];
$allowedOk = 0; foreach ($okRoutes as [$m, $u]) if (app_mode_route_gate('piloto_visual', $m, $u) === null) $allowedOk++;
check($allowedOk === count($okRoutes), 'piloto visual: las ' . count($okRoutes) . ' rutas del catálogo, el visor y la sesión están permitidas');
$closed = [['GET', '/api/users'], ['GET', '/api/user'], ['GET', '/api/stats'], ['GET', '/api/empresa-usuario'], ['GET', '/api/empresa-reporte-usuario'], ['GET', '/api/reportes/activos'], ['GET', '/api/reportes/grupoid/aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'], ['GET', '/api/empresas/1'], ['GET', '/api/asignacion/1'], ['GET', '/api/reportes/id/abc/visor'], ['GET', '/api/reportes/id/0/visor'], ['GET', '/api/reportes/0'], ['GET', '/api/reportes/007'],
    ['GET', '/api/reportes/'], ['GET', '/api/ping/'], ['GET', '//api/ping'], ['GET', '/API/PING'], ['GET', '/api/reportes/1/../2'], ['GET', '/'], ['GET', '/.env'], ['GET', null], ['POST', '/api/register'], ['POST', '/api/reportes'], ['PUT', '/api/reportes/1'], ['DELETE', '/api/reportes/1'], ['PATCH', '/api/reportes'], ['OPTIONS', '/api/reportes'], ['HEAD', '/api/reportes'], ['POST', '/api/login/'], ['POST', '/api/empresas'], ['GET', '/api/powerbi/tokens/1'], ['GET', '/api/reportes/id/12/visor/']];
$closedOk = 0; $closedBad = []; foreach ($closed as [$m, $u]) { $g = app_mode_route_gate('piloto_visual', $m, $u); if (is_array($g) && $g['status'] === 403) $closedOk++; else $closedBad[] = "$m $u"; }
check($closedOk === count($closed), 'piloto visual: usuarios, estadísticas, asignaciones de usuarios, variantes de ruta, ids malformados, escrituras y métodos distintos de GET/POST → 403 (' . $closedOk . ' casos)' . ($closedBad ? ' · ' . implode(', ', $closedBad) : ''));
require_once __DIR__ . '/../backend/lib/piloto_catalogo.php';
$pc = app_sql_catalog(piloto_catalogo_sentencias()); $pcOk = 0; foreach (piloto_catalogo_sentencias() as $sql) { try { app_sql_check($sql, $pc); $pcOk++; } catch (ReadOnlyViolation $e) { fwrite(STDERR, "catálogo piloto rechaza lo suyo: $sql\n"); } }
check($pcOk === count(piloto_catalogo_sentencias()) && count(piloto_catalogo_consultas()) === 8, 'piloto visual: las 8 consultas del catálogo y los controles pasan la guardia');
$pcOut = 0; foreach (['SELECT enlace FROM reporte', 'SELECT * FROM reporte', 'SELECT password FROM user', 'SELECT id FROM user', 'SELECT id, nombre FROM reporte WHERE activo = 1', 'SELECT COUNT(*) FROM reporte', 'SELECT id, reporte_id, nombre, descripcion, activo FROM reporte WHERE activo = 1 OR 1 = 1 ORDER BY id LIMIT ? OFFSET ?'] as $sql) { try { app_sql_check($sql, $pc); } catch (ReadOnlyViolation $e) { $pcOut++; } }
check($pcOut === 7, 'piloto visual: cualquier otra consulta (incluso de lectura, con enlace o de la tabla user) se rechaza antes de llegar a MySQL');
$enlaceEn = array_keys(array_filter(piloto_catalogo_consultas(), fn ($sql) => preg_match('/\benlace\b/', $sql)));
check($enlaceEn === ['reporte_visor'], 'la columna enlace solo aparece en la consulta del visor');
check(!preg_match('/\buser\b|password|powerbi|email|telefono/i', implode(' ', piloto_catalogo_consultas())), 'ninguna consulta del piloto toca la tabla user ni columnas de credenciales o contacto');

// ---------------------------------------------------------------- UX-07D.4B: piloto con usuarios reales
$usr = ['CERVESUR_MODE' => 'piloto_usuarios', 'CERVESUR_REQUIRED_MODE' => 'piloto_usuarios', 'DB_NAME' => 'cervesur_portalbi', 'JWT_SECRET' => $LONG, 'CERVESUR_PILOT_SECRET' => str_repeat('p', 40), 'STATE_DB_NAME' => 'cervesur_react_estado', 'CERVESUR_PILOT_VISOR_IDS' => ''];
check(evaluate($usr)['mode'] === 'piloto_usuarios', 'piloto con usuarios: configuración completa válida sobre la base productiva');
$usrBad = 0; foreach ([['CERVESUR_PILOT_SECRET', 'corto'], ['STATE_DB_NAME', ''], ['STATE_DB_NAME', 'cervesur_portalbi'], ['STATE_DB_NAME', 'x; DROP'], ['CERVESUR_PILOT_VISOR_IDS', 'todos'], ['CERVESUR_INSECURE_TESTS', 'SI']] as [$k, $v]) { if (refused([$k => $v] + $usr)) $usrBad++; }
check($usrBad === 6, 'piloto con usuarios: secreto corto, base de estado ausente/igual a la productiva/mal formada, visor mal formado y HTTP inseguro con la base productiva se rechazan');
$e = $usr; unset($e['CERVESUR_PILOT_SECRET']); check(refused($e), 'piloto con usuarios: sin CERVESUR_PILOT_SECRET no arranca');
check(evaluate(['CERVESUR_INSECURE_TESTS' => 'SI', 'DB_NAME' => 'fixture_x', 'STATE_DB_NAME' => 'estado_x'] + $usr)['mode'] === 'piloto_usuarios', 'HTTP de prueba solo se admite sobre una base sintética');
check(app_https_ok(['HTTPS' => 'on'], '') && !app_https_ok([], '') && !app_https_ok(['HTTPS' => 'off'], '') && app_https_ok(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'https'], '') && !app_https_ok(['REMOTE_ADDR' => '203.0.113.9', 'HTTP_X_FORWARDED_PROTO' => 'https'], '') && !app_https_ok(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_PROTO' => 'http'], ''), 'HTTPS: directo o X-Forwarded-Proto solo desde loopback; nunca por una cabecera remota');
$allowedU = 0; foreach ($okRoutes as [$m, $u]) if (app_mode_route_gate('piloto_usuarios', $m, $u) === null) $allowedU++;
check($allowedU === count($okRoutes), 'piloto con usuarios: las mismas rutas de consulta que el piloto visual');
$closedU = 0; foreach ($closed as [$m, $u]) { $g = app_mode_route_gate('piloto_usuarios', $m, $u); if (is_array($g) && $g['status'] === 403) $closedU++; }
check($closedU === count($closed), 'piloto con usuarios: toda ruta fuera del catálogo o con escritura → 403 (' . $closedU . ' casos)');
require_once __DIR__ . '/../backend/lib/usuarios_catalogo.php';
$ucat = usuarios_catalogo_consultas();
$sinVisor = array_diff_key($ucat, ['reporte_visor' => 1]);
check(!preg_match('/\buser\b|password|powerbi|email|telefono|\benlace\b/i', implode(' ', $sinVisor)) && count(preg_grep('/\benlace\b/', $ucat)) === 1, 'el catálogo de usuarios no toca la tabla user ni powerbi y «enlace» solo está en el visor');
$uauth = usuarios_auth_consultas();
check(count($uauth) === 2 && !preg_match('/powerbi|enlace/i', implode(' ', $uauth)), 'la cuenta de autenticación solo tiene 2 consultas sobre user, sin enlaces');

// ---- UX-07E.B · piloto administrativo de solo lectura
$adm = ['CERVESUR_MODE' => 'piloto_admin', 'CERVESUR_REQUIRED_MODE' => 'piloto_admin'] + $usr;
check(evaluate($adm)['mode'] === 'piloto_admin', 'piloto administrativo: configuración completa válida sobre la base productiva');
$admBad = 0; foreach ([['CERVESUR_PILOT_SECRET', 'corto'], ['STATE_DB_NAME', ''], ['STATE_DB_NAME', 'cervesur_portalbi'], ['CERVESUR_PILOT_VISOR_IDS', 'todos'], ['CERVESUR_INSECURE_TESTS', 'SI']] as [$k, $v]) { if (refused([$k => $v] + $adm)) $admBad++; }
check($admBad === 5, 'piloto administrativo: secreto corto, base de estado ausente/productiva, visor mal formado y HTTP inseguro con la base productiva se rechazan');
check(refused(['CERVESUR_REQUIRED_MODE' => 'piloto_usuarios'] + $adm) && refused(['CERVESUR_REQUIRED_MODE' => 'piloto_admin', 'CERVESUR_MODE' => 'piloto_usuarios'] + $adm), 'el modo exigido impide arrancar el piloto administrativo como el de usuarios (y al revés)');
$okAdmin = [['GET', '/api/ping'], ['GET', '/api/user/profile'], ['GET', '/api/users'], ['GET', '/api/empresas'], ['GET', '/api/reportes'], ['GET', '/api/reportes/12'], ['GET', '/api/reportes/id/12/visor'], ['GET', '/api/asignacion'], ['GET', '/api/empresa-usuario'], ['GET', '/api/empresa-reporte-usuario'], ['POST', '/api/login'], ['POST', '/api/logout']];
$allowedA = 0; foreach ($okAdmin as [$m, $u]) if (app_mode_route_gate('piloto_admin', $m, $u) === null) $allowedA++;
check($allowedA === count($okAdmin), 'piloto administrativo: las 10 rutas de consulta y el inicio/cierre de sesión pasan la puerta de rutas');
$closedAdmin = [['POST', '/api/register'], ['POST', '/api/users'], ['PUT', '/api/users'], ['DELETE', '/api/users'], ['PUT', '/api/user/1/role'], ['PUT', '/api/user/profile'], ['POST', '/api/user/avatar'], ['POST', '/api/reportes'], ['PUT', '/api/reportes/1'], ['DELETE', '/api/reportes/1'], ['POST', '/api/empresas'],
    ['PUT', '/api/empresas/1'], ['DELETE', '/api/empresas/1'], ['POST', '/api/asignacion'], ['DELETE', '/api/asignacion/1'], ['POST', '/api/empresa-usuario'], ['DELETE', '/api/empresa-reporte-usuario/1'], ['GET', '/api/stats'], ['GET', '/api/empresas/1'], ['GET', '/api/powerbi/tokens/1'],
    ['GET', '/api/users/'], ['GET', '/api/ping/'], ['GET', '//api/users'], ['GET', '/API/users'], ['GET', '/api/users/1'], ['GET', '/api/reportes/activos'], ['GET', '/api/wiki'], ['OPTIONS', '/api/users'], ['PATCH', '/api/users'], ['TRACE', '/api/users'], ['HEAD', '/api/users']];
$closedA = 0; foreach ($closedAdmin as [$m, $u]) { $g = app_mode_route_gate('piloto_admin', $m, $u); if (is_array($g) && $g['status'] === 403) $closedA++; }
check($closedA === count($closedAdmin), 'piloto administrativo: toda escritura, formulario, ruta heredada o variante de ruta → 403 (' . $closedA . ' casos)');
require_once __DIR__ . '/../backend/lib/admin_catalogo.php';
$acat = admin_catalogo_consultas(); $aauth = admin_auth_consultas();
check(!preg_match('/\buser\b|password|powerbi|email|telefono/i', implode(' ', $acat)) && count(preg_grep('/\benlace\b/', $acat)) === 1 && isset($acat['reporte_visor']) && preg_match('/\benlace\b/', $acat['reporte_visor']), 'el catálogo administrativo no toca la tabla user ni powerbi, y «enlace» solo está en la consulta del visor');
check(count(preg_grep('/\bpassword\b/i', array_diff_key($aauth, ['usuario_por_login' => 1, 'usuario_por_id' => 1]))) === 0 && !preg_match('/session_version|phone|avatar|powerbi|enlace/i', $aauth['usuarios_pagina']), 'el listado administrativo de usuarios nunca selecciona password, session_version ni teléfono; solo el inicio de sesión lee el hash');
$todas = array_merge(admin_catalogo_sentencias(), admin_auth_sentencias()); $escribe = 0; foreach ($todas as $q) { if (!preg_match('/^(SELECT|SET SESSION TRANSACTION READ ONLY|SHOW GRANTS FOR CURRENT_USER\(\))/i', trim($q))) $escribe++; }
check($escribe === 0, 'ninguna sentencia del catálogo administrativo es distinta de SELECT, SHOW GRANTS o SET SESSION TRANSACTION READ ONLY');
$uc = app_sql_catalog(usuarios_catalogo_sentencias()); $ucOut = 0; foreach (['SELECT password FROM user', 'SELECT enlace FROM reporte', 'SELECT * FROM reporte', 'UPDATE user SET role = 1'] as $sql) { try { app_sql_check($sql, $uc); } catch (ReadOnlyViolation $e) { $ucOut++; } }
check($ucOut === 4, 'el catálogo de usuarios rechaza lo que no está en la lista cerrada');

echo "All mode checks passed\n";
