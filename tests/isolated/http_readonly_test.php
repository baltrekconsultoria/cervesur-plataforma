<?php
// UX-07D.3A: modo de solo lectura (read_only) y de diagnóstico agregado, contra el esquema productivo SINTÉTICO (fixture_prod) con cuentas MySQL de solo lectura
// creadas con el procedimiento administrativo real (permisos SELECT por columna). Solo red Docker aislada y datos ficticios.
//   appro   : read_only    · cuenta cervesur_ro_pilot     appdiag : diagnostico · cuenta cervesur_ro_diag
//   appbad1 : CERVESUR_MODE inválido   appbad2 : base productiva sin modo   appbad3 : read_only con una cuenta que SÍ puede escribir (root)
$failures = 0;
$root = new PDO('mysql:host=db;dbname=fixture_prod;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function accountPdo($user, $pass) { return new PDO('mysql:host=db;dbname=fixture_prod;charset=utf8mb4', $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]); }
$DIAG_TOKEN = (string)getenv('RO_DIAG_TOKEN');

function api($host, $method, $path, $body = null, $token = null, $extra = []) {
    $headers = ['Content-Type: application/json'];
    if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
    foreach ($extra as $h) $headers[] = $h;
    $options = ['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 10]];
    if ($body !== null) $options['http']['content'] = json_encode($body);
    $raw = @file_get_contents("http://$host:8080$path", false, stream_context_create($options));
    $GLOBALS['lastResponse'] = substr((string)$raw, 0, 300);
    $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int)$m[1] : 0;
    return [$status, json_decode((string)$raw, true), (string)$raw];
}
function expectCase($condition, $label) {
    global $failures;
    if ($condition) echo "PASS $label\n"; else { echo "FAIL $label\n  última respuesta: " . ($GLOBALS['lastResponse'] ?? '') . "\n"; $failures++; }
}
function rows($pdo, $sql, $params = []) { $st = $pdo->prepare($sql); $st->execute($params); return $st->fetchAll(); }
function scalar($pdo, $sql, $params = []) { $st = $pdo->prepare($sql); $st->execute($params); return $st->fetchColumn(); }
function loginRo($name) { [, $d] = api('appro', 'POST', '/api/login', ['login' => $name, 'password' => 'synthetic-password']); return $d['token'] ?? ''; }
function checksums($root) { $out = []; foreach ($root->query('CHECKSUM TABLE user, empresa, reporte, empresa_usuario, empresa_reporte, user_empresa_reporte') as $r) $out[$r['Table']] = $r['Checksum']; return $out; }
function sqlError($pdo, $sql) { try { $pdo->exec($sql); return null; } catch (PDOException $e) { return (int)($e->errorInfo[1] ?? 0); } }
function sqlErrorQuery($pdo, $sql) { try { $pdo->query($sql)->fetchAll(); return null; } catch (PDOException $e) { return (int)($e->errorInfo[1] ?? 0); } }
const DENIED = [1142, 1143, 1044, 1227, 1045, 1370, 1410, 1792];
const SENSITIVE = '/\$2y\$|"password"|powerbi_(tenant|client|clientsecret)|SQLSTATE|PDOException|cervesur_ro_|Stack trace|\/var\/www|\/work\//i';

$EFFECTIVE = 'FROM user_empresa_reporte uer JOIN user u ON u.id = uer.user_id AND u.is_active = 1 JOIN empresa_reporte er ON er.id = uer.empresa_reporte_id AND er.activo = 1 JOIN empresa e ON e.id = er.empresa_id AND e.activo = 1 JOIN empresa_usuario eu ON eu.empresa_id = e.id AND eu.user_id = u.id AND eu.activo = 1 JOIN reporte r ON r.id = er.reporte_id AND r.activo = 1 WHERE uer.activo = 1';

echo "== Configuración fail-closed: el servicio NO arranca si la configuración es inválida o peligrosa\n";
foreach ([['appbad1', 'CERVESUR_MODE con un valor desconocido'], ['appbad2', 'base productiva sin CERVESUR_MODE (no se abre por omisión)'], ['appbad3', 'read_only con una cuenta de BD que SÍ puede escribir']] as [$host, $label]) {
    [$st, $d, $raw] = api($host, 'GET', '/api/ping');
    expectCase($st === 503 && ($d['code'] ?? '') === 'mode_config' && !preg_match(SENSITIVE, $raw) && !preg_match('/ALL PRIVILEGES|INSERT|root/i', $raw), "$label → 503 genérico, sin detalle de privilegios ni de configuración");
    [$st] = api($host, 'POST', '/api/login', ['login' => 'syn001', 'password' => 'synthetic-password']);
    expectCase($st === 503, "$label → tampoco permite iniciar sesión");
}

echo "== Cuentas MySQL de solo lectura (procedimiento administrativo real)\n";
$pilot = accountPdo('cervesur_ro_pilot', (string)getenv('RO_PILOT_PASS'));
$diag = accountPdo('cervesur_ro_diag', (string)getenv('RO_DIAG_PASS'));
$tables = ['user', 'empresa', 'reporte', 'empresa_usuario', 'empresa_reporte', 'user_empresa_reporte'];
$deniedWrites = 0; $totalWrites = 0;
foreach ([['pilot', $pilot], ['diag', $diag]] as [$name, $pdo]) {
    foreach ($tables as $t) {
        foreach (["INSERT INTO `$t` (id) VALUES (999999)", "UPDATE `$t` SET id = id WHERE id = 0", "DELETE FROM `$t` WHERE id = 0", "TRUNCATE TABLE `$t`", "ALTER TABLE `$t` ADD COLUMN zz INT", "DROP TABLE `$t`"] as $sql) {
            $totalWrites++; if (in_array(sqlError($pdo, $sql), DENIED, true)) $deniedWrites++; else echo "  NO RECHAZADO ($name): $sql\n";
        }
    }
    foreach (['CREATE TABLE zz_prueba (a INT)', 'CREATE DATABASE zz_prueba', "CREATE USER 'zz'@'%' IDENTIFIED BY 'Aa1!xxxxxxxx'", "GRANT SELECT ON *.* TO 'zz'@'%'", 'UPDATE user SET last_login = NOW()', 'UPDATE user SET session_version = session_version + 1', 'DROP DATABASE fixture_prod'] as $sql) {
        $totalWrites++; if (in_array(sqlError($pdo, $sql), DENIED, true)) $deniedWrites++; else echo "  NO RECHAZADO ($name): $sql\n";
    }
}
expectCase($deniedWrites === $totalWrites, "las dos cuentas rechazan INSERT, UPDATE, DELETE, TRUNCATE, ALTER, DROP, CREATE, GRANT, last_login y session_version ($totalWrites intentos, todos denegados)");
$forbiddenForDiag = ['SELECT password FROM user', 'SELECT email FROM user', 'SELECT * FROM user', 'SELECT phone FROM user', 'SELECT enlace FROM reporte', 'SELECT reporte_id FROM reporte', 'SELECT grupo_id FROM reporte', 'SELECT powerbi_tenant FROM empresa', 'SELECT * FROM empresa', 'SELECT nombre FROM empresa', 'SELECT * FROM reporte'];
$okDiag = 0; foreach ($forbiddenForDiag as $sql) { if (in_array(sqlErrorQuery($diag, $sql), [1143, 1142], true)) $okDiag++; else echo "  LEÍDO (diag): $sql\n"; }
expectCase($okDiag === count($forbiddenForDiag), 'la cuenta de diagnóstico NO puede leer password, correos, teléfonos, enlaces, referencias, grupos, nombres ni credenciales de Power BI (' . $okDiag . ' lecturas denegadas)');
expectCase(sqlErrorQuery($diag, 'SELECT COUNT(*) FROM user WHERE is_active = 1') === null && sqlErrorQuery($diag, 'SELECT id, role, is_active, session_version FROM user') === null, 'la cuenta de diagnóstico sí puede leer las columnas mínimas concedidas');
$forbiddenForPilot = ['SELECT powerbi_tenant FROM empresa', 'SELECT powerbi_client FROM empresa', 'SELECT powerbi_clientsecret FROM empresa', 'SELECT * FROM empresa'];
$okPilot = 0; foreach ($forbiddenForPilot as $sql) { if (in_array(sqlErrorQuery($pilot, $sql), [1143, 1142], true)) $okPilot++; }
expectCase($okPilot === count($forbiddenForPilot) && sqlErrorQuery($pilot, 'SELECT id, username, password FROM user') === null, 'la cuenta del piloto no puede leer las credenciales de Power BI de las empresas (SELECT * incluido); sí lee el hash para password_verify');
// Capa 3, independiente de los privilegios: incluso root, con la sesión en solo lectura, no puede escribir (error 1792)
$rootRo = accountPdo('root', getenv('TEST_DB_PASS')); $rootRo->exec('SET SESSION TRANSACTION READ ONLY');
expectCase(sqlError($rootRo, 'UPDATE user SET last_login = NOW() WHERE id = 5') === 1792 && sqlError($rootRo, 'DELETE FROM reporte WHERE id = 1') === 1792, 'SET SESSION TRANSACTION READ ONLY: incluso una cuenta con TODOS los privilegios recibe el error 1792 al escribir (capa independiente de los permisos)');

echo "== Procedimientos de verificación de privilegios (02 diagnóstico y 02b piloto, independientes)\n";
$runSql = function (string $file, array $map) use ($root) {
    $sql = preg_replace('/^\s*--.*$/m', '', strtr((string)file_get_contents(__DIR__ . '/../../database/schema/admin-requiere-autorizacion/' . $file), $map));
    $out = []; foreach (array_values(array_filter(array_map('trim', explode(';', $sql)))) as $statement) $out[] = $root->query($statement)->fetchAll(); return $out;
};
// el archivo del diagnóstico fija el host 127.0.0.1 y la base cervesur_portalbi: el ensayo crea las cuentas en '%' y en fixture_prod
$r02 = $runSql('02-verificar-privilegios-diagnostico.sql', ['127.0.0.1' => '%', 'cervesur_portalbi' => 'fixture_prod']);
expectCase(count($r02) === 9 && count($r02[0]) > 0, '02 (diagnóstico): SHOW GRANTS muestra las concesiones de la cuenta de diagnóstico');
expectCase(count($r02[1]) === 0 && count($r02[2]) === 0 && count($r02[3]) === 0 && count($r02[4]) === 0, '02 (diagnóstico): ningún privilegio de escritura ni de administración (global, base, tabla ni columna) y nada con GRANT OPTION');
expectCase(count($r02[5]) === 0, '02 (diagnóstico): ninguna columna sensible concedida');
$porTabla = array_column($r02[6], 'columnas', 'TABLE_NAME'); ksort($porTabla);
expectCase($porTabla == ['empresa' => 2, 'empresa_reporte' => 4, 'empresa_usuario' => 3, 'reporte' => 2, 'user' => 4, 'user_empresa_reporte' => 3] && (int)$r02[7][0]['total_columnas_concedidas'] === 18, '02 (diagnóstico): columnas concedidas por tabla 2+4+3+2+4+3 = 18 (' . json_encode($porTabla) . ')');
$r02b = $runSql('02b-verificar-privilegios-piloto.sql', ['__HOST__' => '%']);
expectCase(count($r02b[0]) > 0 && count($r02b[1]) === 0 && count($r02b[2]) === 0 && count($r02b[3]) === 0 && count($r02b[4]) === 0, '02b (piloto): concesiones visibles y ningún privilegio de escritura ni de administración');
$sens = array_map(fn ($r) => $r['TABLE_NAME'] . '.' . $r['COLUMN_NAME'], $r02b[5]); sort($sens);
expectCase($sens === ['reporte.enlace', 'user.password'], '02b (piloto): columnas sensibles concedidas: solo user.password y reporte.enlace (' . json_encode($sens) . ')');

// ------------------------------------------------------------------------------------------------------------ read_only
echo "== Modo read_only (cuenta MySQL con SELECT por columna)\n";
// Estado inicial limpio: la suite productiva anterior (modo escritura) inició sesiones sobre esta misma base y dejó last_login/session_version modificados.
$root->exec('UPDATE user SET last_login = NULL, session_version = 1');
$before = checksums($root);
$lastLoginBefore = rows($root, 'SELECT id, last_login, session_version FROM user ORDER BY id');
$A = rows($root, "SELECT u.id user_id, u.username, r.id rid, r.enlace, uer.id uer_id, er.id er_id, e.id emp_id $EFFECTIVE ORDER BY r.id LIMIT 1")[0];
$foreignId = (int)scalar($root, 'SELECT id FROM reporte WHERE activo = 1 AND id NOT IN (SELECT er2.reporte_id FROM user_empresa_reporte uer2 JOIN empresa_reporte er2 ON er2.id = uer2.empresa_reporte_id WHERE uer2.user_id = ? AND uer2.activo = 1) ORDER BY id LIMIT 1', [$A['user_id']]);
[, $ping] = api('appro', 'GET', '/api/ping');
$admin = loginRo('syn001'); $user = loginRo($A['username']);
expectCase(($ping['pong'] ?? false) === true && $admin !== '' && $user !== '', 'login con hashes bcrypt sintéticos funciona en read_only (administrador y usuario con permiso efectivo)');
[$st, $d] = api('appro', 'POST', '/api/login', ['login' => 'syn066', 'password' => 'synthetic-password']);
expectCase(($d['success'] ?? true) === false && $st === 200, 'un usuario inactivo sigue bloqueado');
[$st, $d] = api('appro', 'POST', '/api/login', ['login' => 'syn001', 'password' => 'incorrecta']);
expectCase(($d['success'] ?? true) === false, 'una contraseña incorrecta sigue siendo rechazada');
$after = rows($root, 'SELECT id, last_login, session_version FROM user ORDER BY id');
expectCase($after === $lastLoginBefore && (int)scalar($root, 'SELECT COUNT(*) FROM user WHERE last_login IS NOT NULL') === 0, 'iniciar sesión NO actualizó last_login ni session_version (la base quedó idéntica)');

$readPaths = ['/api/user/profile', '/api/users?status=all&per_page=100', '/api/user', '/api/user/profile/5', '/api/empresas?per_page=100', '/api/empresas?view=minimal&per_page=100', '/api/empresas/1', '/api/empresas/1?view=form', '/api/empresas/buscar/Empresa', '/api/empresas/ciudad/Ciudad',
    '/api/reportes?per_page=100', '/api/reportes?view=minimal&per_page=100', '/api/reportes/activos', '/api/reportes/1', '/api/reportes/1?view=form', '/api/reportes/id/1/visor', '/api/reportes/grupoid/bbbbbbbb-cccc-4ddd-8eee-000000000003', '/api/asignacion?view=minimal&per_page=100', '/api/asignacion/1', '/api/asignacion/empresa/1', '/api/asignacion/reporte/1',
    '/api/empresa-usuario?per_page=100', '/api/empresa-usuario/1', '/api/empresa-usuario/empresa/1', '/api/empresa-reporte-usuario?view=minimal&per_page=100', '/api/empresa-reporte-usuario/1', '/api/empresa-reporte-usuario/empresa/1', '/api/empresa-reporte-usuario/user/5', '/api/stats'];
$errors5xx = []; $leaks = []; $okReads = 0;
foreach ($readPaths as $path) {
    [$st, , $raw] = api('appro', 'GET', $path, null, $admin);
    if ($st >= 500 || $st === 0) $errors5xx[] = "$path → $st";
    if (preg_match(SENSITIVE, $raw)) $leaks[] = $path;
    if ($st === 200) $okReads++;
}
expectCase($errors5xx === [], 'las ' . count($readPaths) . ' lecturas del administrador responden sin error de servidor con la cuenta de columnas limitadas (' . $okReads . ' con 200)' . ($errors5xx ? ' · ' . implode(', ', $errors5xx) : ''));
expectCase($leaks === [], 'ninguna respuesta de lectura contiene hashes, la clave «password», credenciales de Power BI, SQL, trazas, rutas internas ni nombres de cuenta' . ($leaks ? ' · ' . implode(', ', $leaks) : ''));
$userLeaks = []; foreach (['/api/user/profile', '/api/reportes?view=minimal&per_page=100', '/api/reportes?per_page=100', '/api/empresas?per_page=100', '/api/asignacion?view=minimal&per_page=100', "/api/reportes/id/{$A['rid']}/visor", '/api/empresa-reporte-usuario?per_page=100', '/api/stats'] as $path) { [, , $raw] = api('appro', 'GET', $path, null, $user); if (preg_match(SENSITIVE, $raw)) $userLeaks[] = $path; }
expectCase($userLeaks === [], 'tampoco las respuestas para un usuario normal contienen datos sensibles');
[, $empresasUser] = api('appro', 'GET', '/api/empresas?per_page=100', null, $user);
expectCase(!str_contains(json_encode($empresasUser), 'powerbi_'), 'el listado de empresas ya NO incluye powerbi_tenant ni powerbi_client (antes se devolvían a usuarios no administradores)');

[$st, $d] = api('appro', 'GET', "/api/reportes/id/{$A['rid']}/visor", null, $user);
expectCase($st === 200 && $d['data']['url'] === $A['enlace'], 'usuario con permiso efectivo: el visor entrega el informe autorizado');
[$stF, , $rawF] = api('appro', 'GET', "/api/reportes/id/$foreignId/visor", null, $user); [$stN, , $rawN] = api('appro', 'GET', '/api/reportes/id/4000000000/visor', null, $user);
expectCase($stF === 404 && $rawF === $rawN, 'acceso directo a un informe no autorizado → 404 idéntico al de un informe inexistente');
$grantsBefore = scalar($root, 'SELECT activo FROM user_empresa_reporte WHERE id = ?', [$A['uer_id']]);
$root->prepare('UPDATE user_empresa_reporte SET activo = 0 WHERE id = ?')->execute([$A['uer_id']]);
[$stR, , $rawR] = api('appro', 'GET', "/api/reportes/id/{$A['rid']}/visor", null, $user);
$root->prepare('UPDATE user_empresa_reporte SET activo = 1 WHERE id = ?')->execute([$A['uer_id']]);
expectCase($stR === 404 && !str_contains($rawR, $A['enlace']), 'una asignación revocada no entrega el enlace (404, sin URL)');
$root->prepare('UPDATE user SET is_active = 0 WHERE id = ?')->execute([$A['user_id']]);
[$stI] = api('appro', 'GET', "/api/reportes/id/{$A['rid']}/visor", null, $user);
$root->prepare('UPDATE user SET is_active = 1 WHERE id = ?')->execute([$A['user_id']]);
expectCase($stI === 401, 'un usuario desactivado con token vigente pierde el acceso de inmediato');
[$stL, $dL] = api('appro', 'GET', '/api/powerbi/tokens/1', null, $admin);
expectCase($stL === 403 && ($dL['code'] ?? '') === 'legacy_blocked', 'el endpoint heredado de tokens OAuth de Power BI está bloqueado por el propio backend');

$checksBefore = checksums($root);
$writes = [['POST', '/api/reportes', ['nombre' => 'X', 'enlace' => 'https://app.powerbi.com/view?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDE']], ['PUT', '/api/reportes/1', ['nombre' => 'X']], ['DELETE', '/api/reportes/1', null], ['PATCH', '/api/reportes/1', ['nombre' => 'X']],
    ['POST', '/api/register', ['first_name' => 'a', 'last_name' => 'b', 'username' => 'zz', 'email' => 'zz@example.invalid', 'password' => 'Zz1!xxxxxxx']], ['PUT', '/api/user/profile', ['first_name' => 'Z']], ['PUT', '/api/user/profile/5', ['first_name' => 'Z']], ['POST', '/api/user/avatar', []], ['PUT', '/api/user/5/role', ['role' => 'admin']],
    ['POST', '/api/empresas', ['nombre' => 'Z', 'razon_social' => 'Z', 'ruc' => '20999999999']], ['PUT', '/api/empresas/1', ['ciudad' => 'Z']], ['DELETE', '/api/empresas/1', null], ['POST', '/api/asignacion', ['empresa_id' => 6, 'reporte_id' => 1]], ['PUT', '/api/asignacion/1', ['notas' => 'z']], ['DELETE', '/api/asignacion/1', null],
    ['POST', '/api/empresa-usuario', ['user_id' => 5, 'empresa_id' => 6]], ['PUT', '/api/empresa-usuario/1', ['notas' => 'z']], ['DELETE', '/api/empresa-usuario/1', null], ['POST', '/api/empresa-reporte-usuario', ['user_id' => 5, 'empresa_reporte_id' => 2]], ['PUT', '/api/empresa-reporte-usuario/1', ['notas' => 'z']], ['DELETE', '/api/empresa-reporte-usuario/1', null], ['POST', '/api/desconocido', []]];
$blocked = 0; $bad = [];
foreach ([[$admin, 'administrador'], [$user, 'usuario']] as [$tok, $who]) {
    foreach ($writes as [$method, $path, $body]) { [$st, $d] = api('appro', $method, $path, $body, $tok); if ($st === 403 && ($d['code'] ?? '') === 'read_only') $blocked++; else $bad[] = "$who $method $path → $st"; }
}
expectCase($blocked === count($writes) * 2 && $bad === [], 'las ' . count($writes) . ' operaciones de escritura (con token de administrador y de usuario) responden 403 «read_only» ' . ($bad ? '· FALLAN: ' . implode(' | ', $bad) : ''));
[$stNo] = api('appro', 'POST', '/api/reportes', ['nombre' => 'X'], null);
expectCase($stNo === 403, 'la puerta de rutas rechaza la escritura incluso sin token (antes de la autenticación)');
expectCase(checksums($root) === $checksBefore && checksums($root) === $before, 'las seis tablas quedaron BIT A BIT idénticas tras todas las escrituras rechazadas (ningún borrado en cascada ni SET NULL)');
[$stOut, $dOut] = api('appro', 'POST', '/api/logout', null, $user);
$svAfter = rows($root, 'SELECT id, session_version FROM user ORDER BY id');
expectCase($stOut === 200 && ($dOut['revoked_server_side'] ?? true) === false && array_column($svAfter, 'session_version', 'id') === array_column($lastLoginBefore, 'session_version', 'id'), 'cerrar sesión NO modifica session_version (el servidor no revoca; el token expira solo) y lo declara');
[$stAfterLogout] = api('appro', 'GET', '/api/user/profile', null, $user);
expectCase($stAfterLogout === 200, 'consecuencia documentada: tras «logout» el token sigue siendo válido hasta que expira (por eso JWT_EXPIRATION debe ser corto en el piloto)');
[$stBad, $dBad, $rawBad] = api('appro', 'POST', '/api/login', ['login' => "x' OR '1'='1", 'password' => str_repeat('A', 5000)]);
[$stJ, , $rawJ] = api('appro', 'POST', '/api/login', null);
expectCase(!preg_match(SENSITIVE, $rawBad . $rawJ) && $stBad < 500, 'entradas hostiles al login no producen errores con SQL, hashes ni nombres de cuenta');
[$stId, , $rawId] = api('appro', 'GET', '/api/reportes/id/1%27%20OR%20%271%27=%271/visor', null, $admin);
expectCase($stId === 404 && !preg_match(SENSITIVE, $rawId), 'ids hostiles → 404 sin detalle');

// ------------------------------------------------------------------------------------------------------------ diagnostico
echo "== Modo diagnóstico (agregados, sin login)\n";
[$st] = api('appdiag', 'GET', '/api/ping');
expectCase($st === 200, 'ping responde');
[$st, , $raw] = api('appdiag', 'GET', '/api/diagnostico/resumen');
expectCase($st === 401 && !preg_match('/medidas/', $raw), 'sin token → 401 y ningún dato');
[$st] = api('appdiag', 'GET', '/api/diagnostico/resumen', null, null, ['X-Diagnostic-Token: ' . str_repeat('x', 40)]);
expectCase($st === 401, 'token incorrecto → 401');
[$st] = api('appdiag', 'GET', '/api/diagnostico/resumen', null, $admin, ['X-Diagnostic-Token: ']);
expectCase($st === 401, 'un JWT de usuario no sirve como token de diagnóstico');
[$st, $d, $raw] = api('appdiag', 'GET', '/api/diagnostico/resumen', null, null, ["X-Diagnostic-Token: $DIAG_TOKEN"]);
expectCase($st === 200 && $d['controles']['modo'] === 'diagnostico' && $d['controles']['sesion_de_base_de_datos_en_solo_lectura'] === true && $d['controles']['cuenta_de_base_de_datos_sin_privilegios_de_escritura'] === true, 'el resumen confirma: modo diagnóstico, sesión MySQL de solo lectura y cuenta sin privilegios de escritura');
$expected = require __DIR__ . '/../../backend/lib/inventario_esperado.php';
$reserved = $d['reservadas_por_proteccion_de_grupos_pequenos'] ?? [];
$names = array_merge(array_keys($d['medidas']), $reserved); sort($names); $expectedNames = array_keys($expected); sort($expectedNames);
expectCase($st === 200 && $names === $expectedNames && array_intersect_key($expected, $d['medidas']) === $d['medidas'] && count($d['medidas']) > 0, 'las magnitudes PUBLICADAS coinciden con el inventario real (' . count($d['medidas']) . ' publicadas) y las otras ' . count($reserved) . ' se reservan por nombre, sin valor');
expectCase(!array_intersect($reserved, array_keys($d['medidas'])) && in_array('usuarios_rol_admin', $reserved, true) && in_array('asignaciones_activas_de_usuarios_inactivos', $reserved, true) && !isset($d['medidas']['usuarios_rol_admin']), 'roles y anomalías (grupos pequeños) y las cifras de las que se deducirían por resta NO aparecen en las medidas');
expectCase(!preg_match('/example\.invalid|syn0|powerbi|https?:|\$2y\$|@|password|enlace|nombre|email/i', $raw), 'el resumen contiene solo números, booleanos y nombres de métrica: ningún registro personal, enlace ni hash');
[$st, $d, $raw] = api('appdiag', 'GET', '/api/diagnostico/conciliacion', null, null, ["X-Diagnostic-Token: $DIAG_TOKEN"]);
$withValue = array_filter($d['detalle'], fn ($r) => array_key_exists('observado', $r));
expectCase($st === 200 && $d['coincide'] === true && $d['desviaciones'] === 0 && count($d['detalle']) === count($expected) && !array_filter($d['detalle'], fn ($r) => $r['ok'] !== true), 'la conciliación técnica no detecta desviaciones (' . count($expected) . ' métricas)');
expectCase(count($withValue) < count($d['detalle']) && !array_filter($d['detalle'], fn ($r) => in_array($r['metrica'], ['usuarios_rol_admin', 'usuarios_rol_user', 'asignaciones_activas_de_usuarios_inactivos'], true) && (isset($r['observado']) || isset($r['esperado']))), 'en la conciliación, las magnitudes de grupos pequeños informan solo «ok», nunca su valor');
$root->prepare('UPDATE user SET is_active = 0 WHERE id = ?')->execute([$A['user_id']]);
[, $d2] = api('appdiag', 'GET', '/api/diagnostico/conciliacion', null, null, ["X-Diagnostic-Token: $DIAG_TOKEN"]);
$root->prepare('UPDATE user SET is_active = 1 WHERE id = ?')->execute([$A['user_id']]);
$devs = array_values(array_filter($d2['detalle'], fn ($r) => !$r['ok']));
expectCase($d2['coincide'] === false && $d2['desviaciones'] >= 1 && !preg_match('/syn0|@/', json_encode($devs)) && !array_filter($devs, fn ($r) => isset($r['observado']) && in_array($r['metrica'], ['usuarios_rol_usuario', 'usuarios_rol_usuario_activos'], true)), 'una desviación (usuario desactivado) se detecta y se informa SOLO con números (sin revelar el valor de los grupos reservados)');
$deniedAll = 0; $total = 0;
foreach ([['POST', '/api/login'], ['GET', '/api/reportes'], ['GET', '/api/users'], ['GET', '/api/empresas'], ['GET', '/api/user/profile'], ['GET', '/api/reportes/id/1/visor'], ['GET', '/api/stats'], ['POST', '/api/reportes'], ['DELETE', '/api/reportes/1'], ['GET', '/api/powerbi/tokens/1'], ['POST', '/api/diagnostico/resumen'], ['GET', '/api/diagnostico/otro'], ['GET', '/api/ping/'], ['GET', '/api/diagnostico/resumen/'], ['GET', '/api/diagnostico/conciliacion/'], ['HEAD', '/api/diagnostico/resumen'], ['OPTIONS', '/api/diagnostico/resumen'], ['PUT', '/api/diagnostico/resumen'], ['DELETE', '/api/diagnostico/conciliacion'],
    ['POST', '/api/logout'], ['POST', '/api/register'], ['GET', '/api/user'], ['GET', '/api/user/profile/5'], ['GET', '/api/empresas/1'], ['GET', '/api/reportes/id/1/visor'], ['GET', '/api/reportes/grupoid/bbbbbbbb-cccc-4ddd-8eee-000000000003'], ['GET', '/api/asignacion'], ['GET', '/api/empresa-usuario'], ['GET', '/api/empresa-reporte-usuario'], ['GET', '/api/wiki'], ['GET', '/api/powerbi/tokens/1'], ['GET', '/'], ['GET', '/index.php']] as [$m, $p]) {
    $total++; [$s, $dd] = api('appdiag', $m, $p, $m === 'GET' ? null : [], $admin, ["X-Diagnostic-Token: $DIAG_TOKEN"]); if ($s === 403) $deniedAll++; else echo "  $m $p → $s\n";
}
expectCase($deniedAll === $total, 'en diagnóstico, cualquier otro endpoint (login, registro, logout, catálogos, informes, visor, escrituras, tokens heredados, rutas con barra final, HEAD/OPTIONS/PUT/DELETE) responde 403 aunque se envíe el token correcto (' . $total . ' casos)');
[$stLog, $dLog, $rawLog] = api('appdiag', 'POST', '/api/login', ['login' => 'syn001', 'password' => 'synthetic-password']);
expectCase($stLog === 403 && !str_contains($rawLog, 'token') && !preg_match(SENSITIVE, $rawLog), 'en diagnóstico un login con credenciales válidas NO se atiende (403, sin token): no se leen hashes');
expectCase(checksums($root) === $before, 'tras todas las pruebas de diagnóstico la base sigue idéntica');

echo "== Procedimiento de verificación de ausencia de escrituras (04, performance_schema)\n";
$sql04 = strtr(preg_replace('/^\s*--.*$/m', '', (string)file_get_contents(__DIR__ . '/../../database/schema/admin-requiere-autorizacion/04-verificar-ausencia-de-escrituras.sql')), ['__HOST__' => '%', '127.0.0.1' => '%']);
$run04 = function () use ($root, $sql04) { $r = []; foreach (array_values(array_filter(array_map('trim', explode(';', $sql04)))) as $statement) $r[] = $root->query($statement)->fetchAll(); return $r; };
$r04 = $run04();
// índices: 0 performance_schema/uptime · 1 instrumentos · 2 consumidores · 3 actores · 4 actividad · 5 escrituras · 6 errores · 7-8 SHOW GRANTS · 9 roles · 10 mysql.user · 11 proxies · 12 servidor
expectCase(count($r04) === 14, '04: el procedimiento se ejecuta completo (14 consultas)');
// LIMITACIÓN: el backend usa sentencias PREPARADAS; un intento de escritura así NO sale como statement/sql/update sino como statement/com/Prepare con error.
$diagPrep = accountPdo('cervesur_ro_diag', (string)getenv('RO_DIAG_PASS'));
$sqlUpdateAntes = (int)scalar($root, "SELECT COALESCE(SUM(COUNT_STAR), 0) FROM performance_schema.events_statements_summary_by_user_by_event_name WHERE USER = 'cervesur_ro_diag' AND EVENT_NAME = 'statement/sql/update'");
$prepErrAntes = (int)scalar($root, "SELECT COALESCE(SUM(SUM_ERRORS), 0) FROM performance_schema.events_statements_summary_by_user_by_event_name WHERE USER = 'cervesur_ro_diag' AND EVENT_NAME = 'statement/com/Prepare'");
$prepDenied = false; try { $diagPrep->setAttribute(PDO::ATTR_EMULATE_PREPARES, false); $diagPrep->prepare('UPDATE user SET role = role WHERE id = ?'); } catch (PDOException $e) { $prepDenied = in_array((int)($e->errorInfo[1] ?? 0), DENIED, true); }
$sqlUpdateDespues = (int)scalar($root, "SELECT COALESCE(SUM(COUNT_STAR), 0) FROM performance_schema.events_statements_summary_by_user_by_event_name WHERE USER = 'cervesur_ro_diag' AND EVENT_NAME = 'statement/sql/update'");
$prepErrDespues = (int)scalar($root, "SELECT COALESCE(SUM(SUM_ERRORS), 0) FROM performance_schema.events_statements_summary_by_user_by_event_name WHERE USER = 'cervesur_ro_diag' AND EVENT_NAME = 'statement/com/Prepare'");
expectCase($prepDenied && $sqlUpdateDespues === $sqlUpdateAntes && $prepErrDespues === $prepErrAntes + 1, 'LÍMITE: un intento de UPDATE con sentencia PREPARADA (como las del backend) se deniega, NO incrementa statement/sql/update y aparece solo como statement/com/Prepare con error → la consulta 3 no basta; hay que mirar también las consultas 7a/7b');
$r04 = $run04();
expectCase((int)$r04[0][0]['performance_schema_activo'] === 1 && (int)$r04[1][0]['instrumentos_statement_habilitados'] > 0 && count(array_filter($r04[2], fn ($c) => $c['habilitado'] === 'YES')) >= 2 && count($r04[3]) > 0, '04: las CONDICIONES DE VALIDEZ se cumplen (performance_schema activo, instrumentos y consumidores habilitados, setup_actors presente)');
$detected = array_values(array_unique(array_column($r04[5], 'tipo_de_sentencia')));
expectCase(count($r04[4]) > 0 && count($r04[5]) > 0, '04: detecta los INTENTOS de escritura que esta propia prueba hizo con las cuentas (' . implode(', ', array_slice($detected, 0, 4)) . '…), también cuando MySQL los rechazó');
expectCase(array_sum(array_map(fn ($r) => (int)$r['con_error'], $r04[5])) > 0, '04: los intentos rechazados por permisos aparecen con SUM_ERRORS > 0 (se distinguen de escrituras logradas)');
expectCase(count($r04[8]) === 0 && count($r04[10]) === 0 && count(array_filter($r04[9], fn ($u) => $u['USER'] === '')) === 0, '04: ningún rol ni proxy aplicable a las cuentas ni cuenta anónima que las suplante (privilegios efectivos = los de SHOW GRANTS)');
$writeGrants = function (array $rows) { $bad = []; foreach ($rows as $g) { $line = implode(' ', $g); if (preg_match('/^GRANT\s+(.+?)\s+ON\s/is', $line, $m)) { foreach (explode(',', preg_replace('/\([^)]*\)/', '', $m[1])) as $priv) { if (!in_array(strtoupper(trim($priv)), ['SELECT', 'USAGE'], true)) $bad[] = $priv; } } if (stripos($line, 'WITH GRANT OPTION') !== false) $bad[] = 'GRANT OPTION'; } return $bad; };
expectCase($writeGrants($r04[7]) === [], '04: SHOW GRANTS de la cuenta de diagnóstico sin ningún privilegio de escritura ni de administración');
// LÍMITE demostrado: un resultado vacío NO prueba «cero escrituras». Si los contadores se reinician, las escrituras intentadas desaparecen y la consulta 3 da 0 filas.
$root->exec('TRUNCATE performance_schema.events_statements_summary_by_user_by_event_name');
$after = $run04();
expectCase(count($after) === 14 && count($after[5]) === 0 && count($after[4]) === 0 && count($after[12]) === 0 && count($after[13]) === 0, 'LÍMITE: tras reiniciar los contadores (TRUNCATE o reinicio de MySQL) la consulta de escrituras devuelve 0 filas aunque antes hubo intentos → un resultado vacío por sí solo NO demuestra «cero escrituras»');
expectCase(count($after[7]) > 0 && $writeGrants($after[7]) === [], 'y por eso la evidencia que sí vale es la de privilegios efectivos (SHOW GRANTS / 02), que no depende de los contadores');

echo "== Sin la cuenta del piloto (la verificación del diagnóstico es independiente)
";
// El 02 del diagnóstico NO falla si el piloto no existe: se prueba eliminando la cuenta del piloto del entorno de ensayo y ejecutando solo el 02 del diagnóstico
$root->exec("DROP USER IF EXISTS 'cervesur_ro_pilot'@'%'");
$r02sinPiloto = $runSql('02-verificar-privilegios-diagnostico.sql', ['127.0.0.1' => '%', 'cervesur_portalbi' => 'fixture_prod']);
expectCase(count($r02sinPiloto) === 9 && count($r02sinPiloto[0]) > 0 && (int)$r02sinPiloto[7][0]['total_columnas_concedidas'] === 18, '02 (diagnóstico) se ejecuta completo y correcto aunque la cuenta del piloto NO exista');
$r04sinPiloto = (function () use ($root) { $sql = preg_replace('/^\s*--.*$/m', '', (string)file_get_contents(__DIR__ . '/../../database/schema/admin-requiere-autorizacion/04-verificar-ausencia-de-escrituras.sql')); $n = 0; foreach (array_values(array_filter(array_map('trim', explode(';', strtr($sql, ['127.0.0.1' => '%'])))) ) as $s) { $root->query($s)->fetchAll(); $n++; } return $n; })();
expectCase($r04sinPiloto === 14, '04 se ejecuta completo (14 consultas) sin la cuenta del piloto');

echo $failures === 0 ? "\nAll read-only access cases passed\n" : "\n$failures case(s) failed\n";
exit($failures === 0 ? 0 : 1);
