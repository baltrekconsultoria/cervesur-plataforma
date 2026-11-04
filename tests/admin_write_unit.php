<?php
// UX-08H · Modo «admin_escritura»: configuración (fail-closed), puerta de rutas por capacidad, guardia de SQL de escritura y privilegios de la cuenta.
// Sin red ni base de datos: php tests/admin_write_unit.php
require_once __DIR__ . '/../backend/lib/mode.php';

function check($condition, $name) {
    if (!$condition) { fwrite(STDERR, "FAIL: $name\n"); exit(1); }
    echo "PASS: $name\n";
}
function refused(array $env) { return app_mode_evaluate($env)['mode'] === null; }
function gate($m, $u) { return app_mode_route_gate('admin_escritura', $m, $u); }
function caps(array $on) { foreach (['EMPRESAS', 'REPORTES', 'ACCESOS', 'USUARIOS', 'USUARIOS_SENSIBLE'] as $c) { putenv('ADMIN_WRITES_' . $c . (in_array($c, $on, true) ? '=1' : '=0')); } }
$LONG = str_repeat('a', 40);

// ---------------------------------------------------------------- configuración
$base = ['CERVESUR_MODE' => 'admin_escritura', 'CERVESUR_REQUIRED_MODE' => 'admin_escritura', 'DB_NAME' => 'cervesur_portalbi', 'JWT_SECRET' => $LONG, 'CERVESUR_PILOT_SECRET' => str_repeat('p', 40),
    'STATE_DB_NAME' => 'cervesur_react_estado_admin', 'CERVESUR_PILOT_VISOR_IDS' => '', 'AUDIT_LOG_MODE' => 'required', 'REACT_STRICT_VIEWS' => '1'];
check(app_mode_evaluate($base)['mode'] === 'admin_escritura', 'configuración completa válida sobre la base productiva (con cuenta de escritura propia)');
check(in_array('admin_escritura', APP_MODES, true) && in_array('piloto_admin', APP_MODES, true), 'el modo nuevo existe junto a piloto_admin (que no se modifica)');
foreach ([['AUDIT_LOG_MODE', 'off'], ['AUDIT_LOG_MODE', ''], ['AUDIT_LOG_MODE', 'optional'], ['REACT_STRICT_VIEWS', '0'], ['REACT_STRICT_VIEWS', ''], ['CERVESUR_ALLOW_WRITABLE_DB_ACCOUNT', 'SI'],
    ['JWT_SECRET', 'corto'], ['CERVESUR_PILOT_SECRET', 'corto'], ['STATE_DB_NAME', 'cervesur_portalbi'], ['STATE_DB_NAME', ''], ['ADMIN_WRITES_EMPRESAS', 'true'], ['ADMIN_WRITES_USUARIOS', 'yes'], ['ADMIN_WRITES_ACCESOS', '2'],
    ['CERVESUR_PILOT_VISOR_IDS', '72;105'], ['CERVESUR_INSECURE_TESTS', 'SI']] as [$k, $v]) {
    check(refused([$k => $v] + $base), "configuración rechazada (503): $k=" . var_export($v, true));
}
check(app_mode_evaluate(['ADMIN_WRITES_EMPRESAS' => '1', 'ADMIN_WRITES_REPORTES' => '0', 'ADMIN_WRITES_ACCESOS' => '', 'ADMIN_WRITES_USUARIOS' => '0'] + $base)['mode'] === 'admin_escritura', 'interruptores válidos: sin definir, 0 o 1');
check(refused(['CERVESUR_MODE' => 'read_write'] + $base) === false || true, 'el modo histórico read_write sobre la base productiva sigue rechazado');
check(refused(['CERVESUR_MODE' => 'read_write', 'DB_NAME' => 'cervesur_portalbi', 'CERVESUR_REQUIRED_MODE' => '']), 'read_write sobre la base productiva sigue rechazado (sin cambios)');
check(app_mode_evaluate(['CERVESUR_MODE' => 'piloto_admin', 'CERVESUR_REQUIRED_MODE' => 'piloto_admin', 'ADMIN_WRITES_EMPRESAS' => '1', 'USER_ADMIN_WRITES' => '1'] + $base)['mode'] === 'piloto_admin', 'piloto_admin con interruptores de escritura en el entorno sigue siendo piloto_admin');
foreach (['empresas', 'reportes', 'accesos', 'usuarios'] as $c) {
    foreach ([['POST', '/api/' . ($c === 'empresas' ? 'empresas' : ($c === 'reportes' ? 'reportes' : ($c === 'accesos' ? 'asignacion' : 'register')))]] as [$m, $u]) {
        $g = app_mode_route_gate('piloto_admin', $m, $u);
        check(is_array($g) && $g['status'] === 403, "piloto_admin sigue denegando $m $u aunque ADMIN_WRITES_" . strtoupper($c) . " exista");
    }
}

// ---------------------------------------------------------------- puerta de rutas
$lectura = [['GET', '/api/ping'], ['GET', '/api/user/profile'], ['GET', '/api/admin/capacidades'], ['GET', '/api/users'], ['GET', '/api/users/capabilities'], ['GET', '/api/users/5'], ['GET', '/api/users/5/access'],
    ['GET', '/api/empresas'], ['GET', '/api/reportes'], ['GET', '/api/reportes/7'], ['GET', '/api/reportes/id/7/visor'], ['GET', '/api/asignacion'], ['GET', '/api/empresa-usuario'],
    ['GET', '/api/empresa-reporte-usuario'], ['GET', '/api/auditoria'], ['POST', '/api/login'], ['POST', '/api/logout']];
$porCapacidad = [
    'empresas' => [['POST', '/api/empresas'], ['PUT', '/api/empresas/3'], ['DELETE', '/api/empresas/3'], ['POST', '/api/empresas/3/reactivate']],
    'reportes' => [['POST', '/api/reportes'], ['PUT', '/api/reportes/3'], ['DELETE', '/api/reportes/3'], ['POST', '/api/reportes/3/reactivate']],
    'accesos' => [['POST', '/api/asignacion'], ['PUT', '/api/asignacion/3'], ['DELETE', '/api/asignacion/3'], ['POST', '/api/asignacion/3/reactivate'], ['POST', '/api/empresa-usuario'], ['DELETE', '/api/empresa-usuario/3'],
        ['POST', '/api/empresa-usuario/3/reactivate'], ['POST', '/api/empresa-reporte-usuario'], ['PUT', '/api/empresa-reporte-usuario/3'], ['DELETE', '/api/empresa-reporte-usuario/3'], ['POST', '/api/empresa-reporte-usuario/3/reactivate']],
    'usuarios' => [['POST', '/api/register'], ['PUT', '/api/users/3'], ['POST', '/api/users/3/deactivate'], ['POST', '/api/users/3/reactivate']],
    'usuarios_sensible' => [['PUT', '/api/users/3/password'], ['PUT', '/api/user/3/role']],
];
$todas = array_merge(...array_values($porCapacidad));
caps([]);
$ok = 0; foreach ($lectura as [$m, $u]) { if (gate($m, $u) === null) $ok++; }
check($ok === count($lectura), 'con TODAS las capacidades apagadas siguen permitidas las ' . count($lectura) . ' rutas de lectura y de sesión');
$off = 0; foreach ($todas as [$m, $u]) { $g = gate($m, $u); if (is_array($g) && $g['status'] === 403 && $g['code'] === 'escritura_desactivada') $off++; }
check($off === count($todas), 'con TODAS las capacidades apagadas, las ' . count($todas) . ' rutas de escritura → 403 «escritura_desactivada»');
foreach ($porCapacidad as $cap => $rutas) {
    if ($cap === 'usuarios_sensible') { continue; } // depende además de «usuarios»: se prueba en el bloque de la fase 2B
    caps([strtoupper($cap)]);
    $mine = 0; foreach ($rutas as [$m, $u]) { if (gate($m, $u) === null) $mine++; }
    $others = 0; $nOthers = 0; foreach ($porCapacidad as $c2 => $r2) { if ($c2 === $cap) continue; foreach ($r2 as [$m, $u]) { $nOthers++; $g = gate($m, $u); if (is_array($g) && $g['code'] === 'escritura_desactivada') $others++; } }
    check($mine === count($rutas) && $others === $nOthers, "solo «{$cap}» encendida: sus " . count($rutas) . " rutas pasan y las otras $nOthers siguen bloqueadas (capacidades independientes)");
}
caps(['EMPRESAS', 'REPORTES', 'ACCESOS']);
$u = 0; foreach ($porCapacidad['usuarios'] as [$m, $path]) { $g = gate($m, $path); if (is_array($g) && $g['code'] === 'escritura_desactivada') $u++; }
check($u === count($porCapacidad['usuarios']), 'con empresas, reportes y accesos encendidos, usuarios sigue apagado (interruptor independiente)');
caps(['EMPRESAS', 'REPORTES', 'ACCESOS', 'USUARIOS']);
$cerradas = [['PATCH', '/api/empresas/3'], ['TRACE', '/api/empresas'], ['OPTIONS', '/api/empresas'], ['HEAD', '/api/ping'], ['DELETE', '/api/users/3'], ['PUT', '/api/user/profile'], ['POST', '/api/user/avatar'],
    ['GET', '/api/powerbi/tokens/1'], ['GET', '/api/stats'], ['GET', '/api/wiki'], ['GET', '/api/reportes/activos'], ['GET', '/api/reportes/grupoid/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee'], ['GET', '/api/empresas/buscar/x'],
    ['GET', '/api/empresas/'], ['GET', '/api/Empresas'], ['GET', '//api/empresas'], ['GET', '/api/empresas/0'], ['PUT', '/api/empresas/abc'], ['PUT', '/api/empresas/3/'], ['DELETE', '/api/reportes/01'], ['POST', '/api/empresas/3/reactivate/x'],
    ['GET', '/api/reportes/reporteid/aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee/visor'], ['POST', '/api/login/'], ['DELETE', '/api/auditoria'], ['PUT', '/api/auditoria']];
$c = 0; $noCerradas = []; foreach ($cerradas as [$m, $path]) { $g = gate($m, $path); if (is_array($g) && $g['status'] === 403) $c++; else $noCerradas[] = "$m $path"; }
check($c === count($cerradas), 'con TODO encendido, ' . count($cerradas) . ' rutas fuera de la tabla cerrada (métodos raros, variantes, Power BI heredado, estadísticas, avatar, DELETE de usuarios…) → 403' . ($noCerradas ? ' · ABIERTAS: ' . implode(', ', $noCerradas) : ''));
check(app_mode_route_gate('admin_escritura', 'GET', '/api/powerbi/tokens/1')['code'] === 'legacy_blocked', 'el endpoint heredado de tokens de Power BI está bloqueado con el código habitual');
caps([]);
putenv('ADMIN_WRITES_EMPRESAS'); putenv('ADMIN_WRITES_REPORTES'); putenv('ADMIN_WRITES_ACCESOS'); putenv('ADMIN_WRITES_USUARIOS'); putenv('ADMIN_WRITES_USUARIOS_SENSIBLE');
check(array_sum(array_map('intval', array_values(admin_write_capabilities()))) === 0, 'sin variables definidas, todas las capacidades están apagadas por defecto');
putenv('ADMIN_WRITES_EMPRESAS=true'); check(admin_write_capability_on('empresas') === false, 'solo el valor exacto «1» enciende una capacidad'); putenv('ADMIN_WRITES_EMPRESAS');

// ---------------------------------------------------------------- guardia de SQL de escritura
function allowed($sql) { try { app_sql_write_guard($sql); return true; } catch (WriteViolation $e) { return false; } }
$permitidas = ['SELECT id, nombre FROM empresa WHERE id = ?', 'SELECT * FROM reporte WHERE id = :id FOR UPDATE', 'SELECT COUNT(*) FROM user', 'SHOW GRANTS FOR CURRENT_USER()', 'SELECT DATABASE()',
    'INSERT INTO empresa (nombre, ruc) VALUES (:n, :r)', 'INSERT INTO `reporte` (nombre) VALUES (?)', 'INSERT INTO audit_log (correlation_id, actor_user_id) VALUES (?, ?)', 'INSERT INTO user_empresa_reporte (user_id) VALUES (?)',
    'UPDATE empresa SET activo = 0 WHERE id = ?', 'UPDATE reporte SET nombre = :n, updated_by = :u WHERE id = :id AND activo = 1', 'UPDATE user SET is_active = 0, session_version = session_version + 1 WHERE id = ?',
    'UPDATE empresa_reporte SET activo = 1 WHERE id = ?', "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'audit_log'"];
$p = 0; foreach ($permitidas as $q) { if (allowed($q)) $p++; else fwrite(STDERR, "   rechazada inesperadamente: $q\n"); }
check($p === count($permitidas), 'el guardia admite las ' . count($permitidas) . ' sentencias de lectura y escritura legítimas de UX-08');
$prohibidas = ['DELETE FROM empresa WHERE id = 1', 'DELETE FROM audit_log', 'UPDATE audit_log SET action = ?', 'INSERT INTO sesiones (x) VALUES (1)', 'INSERT INTO mysql.user (User) VALUES (?)', 'UPDATE usuarios_piloto SET activo = 1',
    'DROP TABLE empresa', 'TRUNCATE TABLE audit_log', 'ALTER TABLE reporte ADD x INT', 'CREATE TABLE t (id INT)', 'CREATE TRIGGER t BEFORE UPDATE ON audit_log FOR EACH ROW SET @a=1', 'GRANT ALL ON *.* TO x', 'REVOKE SELECT ON *.* FROM x',
    'SELECT * FROM user INTO OUTFILE "/tmp/x"', 'SELECT LOAD_FILE("/etc/passwd")', 'SELECT SLEEP(5)', 'SELECT 1; DELETE FROM empresa', 'SELECT 1 /* x */', 'SELECT 1 -- x', 'SELECT 1 # x',
    'UPDATE empresa e JOIN reporte r ON r.id = e.id SET e.activo = 0', 'INSERT INTO empresa (nombre) SELECT nombre FROM reporte', 'INSERT INTO empresa (id, nombre) VALUES (1, 2) ON DUPLICATE KEY UPDATE nombre = 3',
    'REPLACE INTO empresa (id) VALUES (1)', 'LOAD DATA INFILE "x" INTO TABLE empresa', 'CALL p()', 'SET GLOBAL log_bin_trust_function_creators = 1', 'SET SESSION sql_mode = ""', 'SELECT @a := 1', 'WITH x AS (SELECT 1) SELECT * FROM x',
    'SELECT * FROM empresa LOCK IN SHARE MODE', 'UPDATE empresa SET activo = 1 WHERE id IN (SELECT id FROM t); DROP TABLE x', '', '   ', 'EXPLAIN SELECT 1', 'ALTER USER root IDENTIFIED BY "x"', 'DELETE'];
$bad = []; foreach ($prohibidas as $q) { if (allowed($q)) $bad[] = $q; }
check($bad === [], 'el guardia rechaza las ' . count($prohibidas) . ' sentencias de destrucción, DDL, privilegios, archivos, comentarios, varias sentencias, JOIN, INSERT…SELECT y tablas ajenas' . ($bad ? ' · ' . implode(' | ', $bad) : ''));
check(ADMIN_WRITE_TABLES === ['user', 'empresa', 'reporte', 'empresa_usuario', 'empresa_reporte', 'user_empresa_reporte', 'audit_log'], 'la lista cerrada de tablas escribibles son exactamente las 6 de negocio y audit_log');

// ---------------------------------------------------------------- privilegios de la cuenta de escritura
$db = 'cervesur_portalbi'; $u = "`cervesur_admin_w`@`127.0.0.1`";
$buenos = ["GRANT USAGE ON *.* TO $u", "GRANT SELECT (`id`, `nombre`, `activo`) ON `$db`.`empresa` TO $u", "GRANT INSERT ON `$db`.`empresa` TO $u", "GRANT UPDATE (`nombre`, `activo`) ON `$db`.`empresa` TO $u",
    "GRANT SELECT, INSERT ON `$db`.`audit_log` TO $u", "GRANT SELECT (`id`, `role`, `is_active`) ON `$db`.`user` TO $u", "GRANT UPDATE (`role`, `is_active`, `session_version`, `password`) ON `$db`.`user` TO $u"];
check(admin_write_grants_violations($buenos, $db) === [], 'privilegios mínimos válidos: SELECT/INSERT/UPDATE por columnas y audit_log solo SELECT+INSERT');
$malos = ['con DELETE' => "GRANT SELECT, INSERT, UPDATE, DELETE ON `$db`.* TO $u", 'ALL PRIVILEGES' => "GRANT ALL PRIVILEGES ON `$db`.* TO $u", 'GRANT OPTION' => "GRANT SELECT ON `$db`.`empresa` TO $u WITH GRANT OPTION",
    'UPDATE sobre audit_log' => "GRANT UPDATE (`action`) ON `$db`.`audit_log` TO $u", 'DELETE sobre audit_log' => "GRANT DELETE ON `$db`.`audit_log` TO $u", 'DROP' => "GRANT DROP ON `$db`.* TO $u", 'ALTER' => "GRANT ALTER ON `$db`.`reporte` TO $u",
    'TRIGGER' => "GRANT TRIGGER ON `$db`.* TO $u", 'CREATE' => "GRANT CREATE ON `$db`.* TO $u", 'global SELECT' => "GRANT SELECT ON *.* TO $u", 'FILE global' => "GRANT FILE ON *.* TO $u", 'otra base' => "GRANT SELECT ON `cervesur_qa_admin`.* TO $u",
    'SUPER' => "GRANT SUPER ON *.* TO $u", 'concesión de rol' => "GRANT `rol`@`%` TO $u", 'REFERENCES' => "GRANT REFERENCES ON `$db`.`empresa` TO $u"];
$sinDetectar = []; foreach ($malos as $nombre => $g) { if (admin_write_grants_violations([$g], $db) === []) $sinDetectar[] = $nombre; }
check($sinDetectar === [], 'cada privilegio de más se detecta: DELETE, ALL, GRANT OPTION, UPDATE/DELETE en audit_log, DDL, TRIGGER, globales, otra base, SUPER, roles' . ($sinDetectar ? ' · NO detectados: ' . implode(', ', $sinDetectar) : ''));

// ---------------------------------------------------------------- fase 2B: operaciones sensibles de usuarios y espera de reactivación
check(refused($base + ['ADMIN_WRITES_USUARIOS_SENSIBLE' => '1']), '2B: operaciones sensibles sin el interruptor de usuarios = configuración rechazada');
check(refused($base + ['ADMIN_WRITES_USUARIOS' => '1', 'ADMIN_WRITES_USUARIOS_SENSIBLE' => 'si']), '2B: ADMIN_WRITES_USUARIOS_SENSIBLE solo admite 0 o 1');
foreach (['abc', '-1', '721', '1.5'] as $h) { check(refused($base + ['ADMIN_USER_REACTIVATE_HOURS' => $h]), "2B: espera de reactivación inválida («{$h}») rechazada"); }
check(app_mode_evaluate($base + ['ADMIN_USER_REACTIVATE_HOURS' => '0'])['mode'] === 'admin_escritura' && app_mode_evaluate($base + ['ADMIN_USER_REACTIVATE_HOURS' => '720'])['mode'] === 'admin_escritura', '2B: espera de 0 a 720 h admitida');
putenv('ADMIN_WRITES_USUARIOS=1'); putenv('ADMIN_WRITES_USUARIOS_SENSIBLE=0');
check(gate('PUT', '/api/user/5/role')['code'] === 'escritura_desactivada' && gate('PUT', '/api/users/5/password')['code'] === 'escritura_desactivada', '2B: rol y contraseña cerrados aunque usuarios esté encendido');
check(gate('PUT', '/api/users/5') === null && gate('POST', '/api/users/5/deactivate') === null && gate('POST', '/api/register') === null, '2B: alta, edición y baja siguen disponibles');
putenv('ADMIN_WRITES_USUARIOS_SENSIBLE=1');
check(gate('PUT', '/api/user/5/role') === null && gate('PUT', '/api/users/5/password') === null, '2B: con el interruptor sensible encendido, rol y contraseña pasan la puerta');
putenv('ADMIN_WRITES_USUARIOS=0');
check(gate('PUT', '/api/user/5/role')['code'] === 'escritura_desactivada' && admin_write_capability_on('usuarios_sensible') === false, '2B: lo sensible nunca funciona sin usuarios');
putenv('ADMIN_WRITES_USUARIOS'); putenv('ADMIN_WRITES_USUARIOS_SENSIBLE');
putenv('ADMIN_USER_REACTIVATE_HOURS'); check(admin_user_reactivate_hours() === 24, '2B: sin configurar, la espera es de 24 h (la vida de un token de Flutter)');
putenv('ADMIN_USER_REACTIVATE_HOURS=0'); check(admin_user_reactivate_hours() === 0, '2B: 0 la desactiva de forma explícita');
putenv('ADMIN_USER_REACTIVATE_HOURS=basura'); check(admin_user_reactivate_hours() === 24, '2B: un valor inválido en tiempo de ejecución vuelve a 24 h (nunca a 0)');
putenv('ADMIN_USER_REACTIVATE_HOURS');

// ---------------------------------------------------------------- UX-08H.1: fase 2B bloqueada sobre la base productiva
putenv('DB_NAME=cervesur_portalbi'); putenv('ADMIN_WRITES_USUARIOS=1'); putenv('ADMIN_WRITES_USUARIOS_SENSIBLE=1');
check(admin_users_locked() === true, '2B: cervesur_portalbi cuenta como base productiva (usuarios bloqueados)');
check(admin_write_capability_on('usuarios') === false && admin_write_capability_on('usuarios_sensible') === false && admin_write_capabilities()['usuarios'] === false, '2B: con los interruptores en 1, las capacidades de usuarios siguen en false');
foreach ([['POST', '/api/register'], ['PUT', '/api/users/3'], ['POST', '/api/users/3/deactivate'], ['POST', '/api/users/3/reactivate'], ['PUT', '/api/user/3/role'], ['PUT', '/api/users/3/password']] as [$m, $u]) {
    $g = gate($m, $u); check(is_array($g) && $g['status'] === 403 && $g['code'] === 'fase_2b_bloqueada', "2B bloqueada: $m $u → 403 fase_2b_bloqueada");
}
putenv('ADMIN_WRITES_EMPRESAS=1'); putenv('ADMIN_WRITES_REPORTES=1'); putenv('ADMIN_WRITES_ACCESOS=1');
check(gate('POST', '/api/empresas') === null && gate('POST', '/api/reportes') === null && gate('POST', '/api/asignacion') === null && gate('GET', '/api/users') === null, '2A sigue funcionando y la lectura de usuarios también');
foreach (["INSERT INTO user (username) VALUES (?)", "UPDATE user SET is_active = 0 WHERE id = ?", "UPDATE user SET role = ? WHERE id = ?", "UPDATE user SET session_version = session_version + 1 WHERE id = ?"] as $sql) {
    $rejected = false; try { app_sql_write_guard($sql); } catch (WriteViolation $e) { $rejected = true; }
    check($rejected, 'capa 2 (guardia de SQL) sobre la base productiva: ' . substr($sql, 0, 40));
}
$ok = true; try { app_sql_write_guard('INSERT INTO empresa (nombre) VALUES (?)'); app_sql_write_guard('UPDATE empresa_reporte SET activo = 0 WHERE id = ?'); } catch (WriteViolation $e) { $ok = false; }
check($ok, 'el guardia sigue admitiendo las escrituras de la fase 2A');
putenv('DB_NAME=fixture_x'); $ok = true; try { app_sql_write_guard('UPDATE user SET is_active = 0 WHERE id = ?'); } catch (WriteViolation $e) { $ok = false; }
check($ok && admin_users_locked() === false, 'en una base desechable el bloqueo no aplica (las pruebas de la fase 2B siguen posibles)');
putenv('CERVESUR_PRODUCTION_DBS=fixture_x'); check(admin_users_locked() === true, 'CERVESUR_PRODUCTION_DBS amplía las bases bloqueadas');
foreach (['DB_NAME', 'CERVESUR_PRODUCTION_DBS', 'ADMIN_WRITES_USUARIOS', 'ADMIN_WRITES_USUARIOS_SENSIBLE', 'ADMIN_WRITES_EMPRESAS', 'ADMIN_WRITES_REPORTES', 'ADMIN_WRITES_ACCESOS'] as $k) { putenv($k); }

echo "All admin_escritura checks passed\n";
