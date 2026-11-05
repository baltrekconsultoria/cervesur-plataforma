<?php
// UX-08I · FASE 2B (Usuarios) contra el backend REAL, sobre una base marcada como PRODUCTIVA (CERVESUR_PRODUCTION_DBS=fixture_aw) y con las cuentas ADITIVAS reales de las plantillas UX-08I.
// Uso (DENTRO del contenedor de la instancia, por loopback + X-Forwarded-Proto: https; una base productiva rechaza CERVESUR_INSECURE_TESTS):
//   php http_admin_escritura_fase2b_test.php nosens   instancia con la cuenta 2B «no sensibles» y ADMIN_WRITES_USUARIOS=1 (sensible apagado)
//   php http_admin_escritura_fase2b_test.php sens     instancia con la cuenta 2B «sensibles» y ADMIN_WRITES_USUARIOS=1 + ADMIN_WRITES_USUARIOS_SENSIBLE=1
//   php http_admin_escritura_fase2b_test.php mal      instancias mal configuradas (deben negarse a arrancar con 503): se consulta por nombre de host de contenedor
// Entorno: TEST_DB_PASS, W_WRITE2A_PASS (clave de las cuentas de la fase 2B de prueba).
$role = $argv[1] ?? 'nosens';
$failures = 0;
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$root = new PDO('mysql:host=db;dbname=fixture_aw;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), $opts);
$rootSrv = new PDO('mysql:host=db;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), $opts);
$estado = new PDO('mysql:host=db;dbname=estado_aw;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), $opts);
$acct = $role === 'sens' ? 'cervesur_escritura2bs_aw' : 'cervesur_escritura2b_aw';
$bodies = '';
// cada ejecución parte limpia de los usuarios creados por la anterior (la bitácora no se toca: es de solo inserción)
if ($role !== 'mal') { $root->exec("DELETE FROM user WHERE username IN ('nuevo2b','admin2b','sinaud') OR username LIKE 'forzado%'"); $estado->exec('DELETE FROM usuarios_piloto WHERE user_id <> 1'); }
function api($method, $path, $token = null, $body = null, $host = '127.0.0.1') {
    $h = ['Content-Type: application/json', 'X-API-Contract: 2', 'X-Forwarded-Proto: https'];
    if ($token) $h[] = 'Authorization: Bearer ' . $token;
    $o = ['http' => ['method' => $method, 'header' => implode("\r\n", $h), 'ignore_errors' => true, 'timeout' => 20]];
    if ($body !== null) $o['http']['content'] = json_encode($body);
    $raw = (string)@file_get_contents("http://$host:8080$path", false, stream_context_create($o));
    $GLOBALS['last'] = substr($raw, 0, 300);
    $GLOBALS['bodies'] .= $raw;
    $st = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int)$m[1] : 0;
    return [$st, json_decode($raw, true), $raw];
}
function expectCase($c, $label) { global $failures; if ($c) echo "PASS $label\n"; else { echo "FAIL $label\n  última respuesta: " . ($GLOBALS['last'] ?? '') . "\n"; $failures++; } }
function one($pdo, $sql, $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }
function rows($pdo, $sql, $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); }
function login($u, $p = 'synthetic-password') { [, $d] = api('POST', '/api/login', null, ['login' => $u, 'password' => $p]); return $d['token'] ?? ''; }
function audits($root, $uid, $action) { return (int)one($root, "SELECT COUNT(*) FROM audit_log WHERE entity = 'user' AND entity_id = ? AND action = ?", [$uid, $action]); }
const LEAK = '/\$2y\$|"password"|SQLSTATE|PDOException|cervesur_(escritura|ro_|react_)|Stack trace|\/var\/www|\/work\//i';

if ($role === 'mal') {
    echo "== Configuración realmente incoherente: el arranque se niega a servir (503)\n";
    [$s, $d, $raw] = api('GET', '/api/ping', null, null, 'appawz');
    expectCase($s === 503 && ($d['code'] ?? '') === 'mode_config' && !preg_match(LEAK, $raw), 'cuenta con UPDATE(username) sobre user (columna no prevista) → 503 sin detalles internos');
    echo "\n" . ($failures === 0 ? 'All admin_escritura fase 2B (mal configuradas) checks passed' : "$failures check(s) FAILED") . "\n";
    exit($failures === 0 ? 0 : 1);
}
if ($role === 'staged') {
    // Se ejecuta DENTRO de appawx (cuenta con permisos no sensibles YA concedidos, ADMIN_WRITES_USUARIOS ausente): la transición P4a no debe interrumpir nada.
    echo "== Transición de privilegios (P4a): plantillas aplicadas ANTES del interruptor\n";
    $tx = login('admin');
    expectCase($tx !== '', 'el backend SIGUE sirviendo (sin 503) con los permisos de usuarios ya concedidos y el interruptor ausente: Empresas, Informes y Accesos no se interrumpen');
    [$s1, $d1] = api('POST', '/api/register', $tx, ['first_name' => 'X', 'last_name' => 'Y', 'username' => 'escalonado', 'email' => 'escalonado@example.invalid', 'password' => 'una-contrasena-larga-1']);
    [$s2, $d2] = api('PUT', '/api/user/2/role', $tx, ['role' => 'admin']);
    [$s3, $d3] = api('PUT', '/api/users/2', $tx, ['phone' => '1']);
    expectCase($s1 === 403 && ($d1['code'] ?? '') === 'fase_2b_bloqueada' && $s2 === 403 && $s3 === 403 && (int)one($root, "SELECT COUNT(*) FROM user WHERE username = 'escalonado'") === 0, 'Usuarios sigue bloqueado por la ruta (403 fase_2b_bloqueada): la capacidad efectiva NO cambió');
    [, $dc] = api('GET', '/api/admin/capacidades', $tx);
    expectCase(($dc['data']['writes']['usuarios'] ?? null) === false && ($dc['data']['writes']['empresas'] ?? null) === true && ($dc['data']['users_sensitive'] ?? null) === false, 'capacidades efectivas: usuarios=false y users_sensitive=false; empresas siguen encendidas');
    [$se] = api('POST', '/api/empresas', $tx, ['nombre' => 'Escalonada SA', 'razon_social' => 'Escalonada SA', 'ruc' => '20444000009', 'ciudad' => 'X', 'pais' => 'Y']);
    expectCase($se === 200, 'una escritura de Empresas funciona durante la transición');
    echo "\n" . ($failures === 0 ? 'All admin_escritura fase 2B (staged) checks passed' : "$failures check(s) FAILED") . "\n";
    exit($failures === 0 ? 0 : 1);
}

$t = login('admin');
expectCase($t !== '', "la instancia $role arranca (SHOW GRANTS cruzado con los interruptores aceptado) y el administrador entra");
[, $d] = api('GET', '/api/admin/capacidades', $t);
expectCase(($d['data']['writes']['usuarios'] ?? null) === true && ($d['data']['users_sensitive'] ?? null) === ($role === 'sens'), 'capacidades: usuarios encendido; sensible ' . ($role === 'sens' ? 'encendido' : 'APAGADO'));

echo "== Privilegios reales de la cuenta (plantillas aditivas)\n";
$w = new PDO('mysql:host=db;dbname=fixture_aw;charset=utf8mb4', $acct, getenv('W_WRITE2A_PASS'), $opts);
$den = function ($sql) use ($w) { try { $q = $w->query($sql); if ($q->columnCount() > 0) { $q->fetchAll(); } return false; } catch (PDOException $e) { return true; } };
expectCase($den("UPDATE user SET username = 'x' WHERE id = 2") && $den('DELETE FROM user WHERE id = 2') && $den('DELETE FROM audit_log') && $den("UPDATE audit_log SET action = 'x'") && $den('DELETE FROM empresa WHERE id = 1'), 'sin UPDATE de username, sin DELETE de user/audit_log/empresa, sin UPDATE de audit_log');
if ($role === 'nosens') {
    expectCase($den("UPDATE user SET role = 'admin' WHERE id = 2") && $den("UPDATE user SET password = 'x' WHERE id = 2"), 'cuenta NO sensible: el motor niega UPDATE de role y de password');
} else {
    expectCase(!$den("UPDATE user SET role = 'user' WHERE id = 0") && !$den("UPDATE user SET password = '' WHERE id = 0"), 'cuenta sensible: UPDATE(role, password) concedido');
}
expectCase($den('SELECT password FROM user WHERE id = 1') && $den('SELECT powerbi_clientsecret FROM empresa WHERE id = 1'), 'user.password y empresa.powerbi_* siguen ilegibles para la cuenta');
$grants = implode("\n", array_map(fn ($r) => $r[0], $w->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_NUM)));
expectCase(!preg_match('/\b(DELETE|ALL PRIVILEGES|GRANT OPTION|TRIGGER|DROP|ALTER|CREATE|FILE|SUPER)\b/', $grants), 'SHOW GRANTS: sin DELETE, ALL, GRANT OPTION, TRIGGER, DDL ni globales');

echo "== Alta de usuario\n";
$new = ['first_name' => 'Nuevo', 'last_name' => 'Usuario', 'username' => 'nuevo2b', 'email' => 'nuevo2b@example.invalid', 'phone' => '555-0100', 'password' => 'una-contrasena-larga-1'];
[$s, $d] = api('POST', '/api/register', $t, $new);
$uid = (int)($d['id'] ?? 0);
$row = rows($root, 'SELECT role, is_active, session_version, password FROM user WHERE id = ?', [$uid])[0] ?? [];
expectCase($s === 200 && $uid > 0 && $row['role'] === 'user' && (int)$row['is_active'] === 1 && (int)$row['session_version'] === 1 && password_verify($new['password'], $row['password']), 'alta: rol user, activo, session_version 1 y contraseña solo como hash verificable');
expectCase(audits($root, $uid, 'user.create') === 1, 'exactamente una auditoría user.create');
$aj = (string)one($root, "SELECT CONCAT_WS('|', before_json, after_json) FROM audit_log WHERE entity = 'user' AND entity_id = ? AND action = 'user.create'", [$uid]);
expectCase(!str_contains($aj, $new['password']) && !str_contains($aj, '$2y$') && !str_contains($aj, '555-0100') && !str_contains($aj, 'nuevo2b'), 'la auditoría del alta no contiene contraseña, hash ni datos personales');
[$s] = api('POST', '/api/register', $t, $new);
expectCase($s === 409 && (int)one($root, "SELECT COUNT(*) FROM user WHERE username = 'nuevo2b'") === 1 && audits($root, $uid, 'user.create') === 1, 'reintento del mismo alta: 409, una sola fila y una sola auditoría');
foreach (['role' => 'admin', 'is_active' => 0, 'session_version' => 9, 'id' => 77] as $k => $v) {
    [$s] = api('POST', '/api/register', $t, array_merge($new, ['username' => 'forzado' . $k, 'email' => "forzado$k@example.invalid", $k => $v]));
    expectCase($s === 400 && (int)one($root, "SELECT COUNT(*) FROM user WHERE username = ?", ['forzado' . $k]) === 0, "alta con «$k» en el cuerpo: 400 y no se crea nada (no hay autoescalamiento por el alta)");
}

echo "== Edición de datos no sensibles\n";
[, $d] = api('GET', "/api/users/$uid", $t);
$v1 = $d['data']['version'] ?? '';
expectCase($v1 !== '' && !str_contains(json_encode($d), 'password'), 'el detalle trae versión y ningún campo de contraseña');
[$s] = api('PUT', "/api/users/$uid", $t, ['phone' => '555-0111', 'version' => $v1, 'role' => 'admin', 'username' => 'robado', 'is_active' => 0, 'password' => 'otra-contrasena-larga']);
$row = rows($root, 'SELECT username, role, is_active, phone FROM user WHERE id = ?', [$uid])[0];
expectCase($s === 200 && $row['phone'] === '555-0111' && $row['username'] === 'nuevo2b' && $row['role'] === 'user' && (int)$row['is_active'] === 1, 'PUT solo cambia datos personales; role, username, is_active y password del cuerpo se ignoran');
expectCase(audits($root, $uid, 'user.update') === 1, 'exactamente una auditoría user.update');
$root->exec("UPDATE user SET first_name = 'Cambiado por Flutter' WHERE id = $uid"); // otra aplicación (Flutter) escribe a la vez
[$s] = api('PUT', "/api/users/$uid", $t, ['phone' => '555-0222', 'version' => $v1]);
expectCase($s === 409 && one($root, 'SELECT phone FROM user WHERE id = ?', [$uid]) === '555-0111' && audits($root, $uid, 'user.update') === 1, 'carrera con Flutter: versión vencida → 409, sin cambio y sin auditoría extra');
[, $d] = api('GET', "/api/users/$uid", $t); $v2 = $d['data']['version'];
[$s, $d] = api('PUT', "/api/users/$uid", $t, ['phone' => '555-0111', 'version' => $v2]);
expectCase($s === 200 && audits($root, $uid, 'user.update') === 1, 'edición sin cambios reales: éxito sin escribir ni auditar');
[$s] = api('PUT', '/api/users/2', $t, ['email' => 'nuevo2b@example.invalid']);
expectCase($s === 409, 'email duplicado: 409');
[$s] = api('PUT', '/api/users/99999', $t, ['phone' => '1']);
expectCase($s === 404, 'usuario inexistente: 404');

echo "== Desactivar / reactivar (cierra la sesión)\n";
$sv = (int)one($root, 'SELECT session_version FROM user WHERE id = ?', [$uid]);
[$s] = api('POST', "/api/users/$uid/deactivate", $t, []);
expectCase($s === 200 && (int)one($root, 'SELECT is_active FROM user WHERE id = ?', [$uid]) === 0 && (int)one($root, 'SELECT session_version FROM user WHERE id = ?', [$uid]) === $sv + 1 && audits($root, $uid, 'user.deactivate') === 1, 'desactivar: is_active 0, session_version +1 y una auditoría');
[$s] = api('POST', "/api/users/$uid/deactivate", $t, []);
expectCase($s === 409 && audits($root, $uid, 'user.deactivate') === 1, 'desactivar dos veces: 409 y sin auditoría duplicada');
[$s, $d] = api('POST', "/api/users/$uid/reactivate", $t, []);
expectCase($s === 409 && (int)one($root, 'SELECT is_active FROM user WHERE id = ?', [$uid]) === 0 && audits($root, $uid, 'user.reactivate') === 0, 'reactivar antes de la espera de 24 h: 409 (protege sesiones antiguas de Flutter), sin cambio ni auditoría');
[$s] = api('POST', '/api/users/1/deactivate', $t, []);
expectCase($s === 409 && (int)one($root, 'SELECT is_active FROM user WHERE id = 1') === 1, 'el administrador no puede desactivarse a sí mismo (ni dejar al sistema sin administrador): 409');

echo "== Operaciones sensibles\n";
$before = rows($root, 'SELECT id, role, password, session_version FROM user ORDER BY id');
if ($role === 'nosens') {
    foreach ([['PUT', '/api/user/2/role', ['role' => 'admin']], ['PUT', '/api/users/2/password', ['password' => 'otra-contrasena-larga']]] as [$m, $p, $b]) {
        [$s, $d] = api($m, $p, $t, $b);
        expectCase($s === 403 && ($d['code'] ?? '') === 'escritura_desactivada', "$m $p → 403 escritura_desactivada");
    }
    expectCase(rows($root, 'SELECT id, role, password, session_version FROM user ORDER BY id') === $before, 'rol y contraseña de todos los usuarios intactos');
} else {
    // un segundo administrador de prueba, con sesión propia, para comprobar que un cambio sensible la cierra
    $new2 = ['first_name' => 'Segundo', 'last_name' => 'Admin', 'username' => 'admin2b', 'email' => 'admin2b@example.invalid', 'password' => 'clave-admin-dos-larga-1'];
    [, $d] = api('POST', '/api/register', $t, $new2); $a2 = (int)$d['id'];
    [$s] = api('PUT', "/api/user/$a2/role", $t, ['role' => 'admin']);
    expectCase($s === 200 && one($root, 'SELECT role FROM user WHERE id = ?', [$a2]) === 'admin' && audits($root, $a2, 'user.role') === 1, 'rol: user → admin con una auditoría user.role');
    $estado->exec("INSERT INTO usuarios_piloto (user_id, nota, activo, creado_en, creado_por) VALUES ($a2, 'prueba 2b', 1, UTC_TIMESTAMP(), 'test')");
    $t2 = login('admin2b', 'clave-admin-dos-larga-1');
    [$s] = api('GET', '/api/users', $t2);
    expectCase($t2 !== '' && $s === 200, 'el segundo administrador inicia sesión');
    [$s] = api('PUT', "/api/users/$a2/password", $t, ['password' => 'clave-admin-dos-NUEVA-2']);
    $hashOk = password_verify('clave-admin-dos-NUEVA-2', (string)one($root, 'SELECT password FROM user WHERE id = ?', [$a2]));
    [$s2] = api('GET', '/api/users', $t2);
    expectCase($s === 200 && $hashOk && $s2 === 401 && audits($root, $a2, 'user.password') === 1, 'contraseña: nuevo hash, una auditoría y la sesión anterior del usuario queda INVALIDADA (401)');
    $aj = (string)one($root, "SELECT CONCAT_WS('|', before_json, after_json) FROM audit_log WHERE entity_id = ? AND action = 'user.password'", [$a2]);
    expectCase(!str_contains($aj, 'NUEVA') && !str_contains($aj, '$2y$'), 'la auditoría de la contraseña no contiene contraseña ni hash');
    $t2 = login('admin2b', 'clave-admin-dos-NUEVA-2');
    [$s] = api('PUT', "/api/user/$a2/role", $t, ['role' => 'user']);
    [$s2] = api('GET', '/api/users', $t2);
    expectCase($s === 200 && $s2 >= 401 && $s2 <= 403, 'degradar a user cierra la sesión del administrador degradado');
    [$s] = api('PUT', '/api/user/1/role', $t, ['role' => 'user']);
    expectCase($s === 409 && one($root, 'SELECT role FROM user WHERE id = 1') === 'admin', 'no se puede cambiar el propio rol (anti autoescalamiento / último administrador)');
    [$s] = api('PUT', '/api/users/1/password', $t, ['password' => 'mi-propia-clave-larga-1']);
    expectCase($s === 409 && password_verify('synthetic-password', (string)one($root, 'SELECT password FROM user WHERE id = 1')), 'no se puede cambiar la propia contraseña desde el panel');
    [$s] = api('PUT', "/api/user/$uid/role", $t, ['role' => 'superadmin']);
    expectCase($s === 400, 'rol fuera de la lista (superadmin): 400');
    foreach (['corta', str_repeat('x', 73)] as $bad) { [$s] = api('PUT', "/api/users/$uid/password", $t, ['password' => $bad]); expectCase($s === 400, 'contraseña fuera de 12–72 bytes: 400'); }
}

echo "== Atomicidad: si falla la auditoría no se confirma el cambio\n";
$a0 = (int)one($root, 'SELECT COUNT(*) FROM audit_log');
$rootSrv->exec("REVOKE INSERT ON `fixture_aw`.`audit_log` FROM '$acct'@'%'");
[$s, , $raw] = api('PUT', "/api/users/$uid", $t, ['phone' => '555-9999']);
[$s2] = api('POST', '/api/register', $t, array_merge($new, ['username' => 'sinaud', 'email' => 'sinaud@example.invalid']));
expectCase($s >= 500 && $s2 >= 500 && !preg_match(LEAK, $raw) && one($root, 'SELECT phone FROM user WHERE id = ?', [$uid]) === '555-0111' && (int)one($root, "SELECT COUNT(*) FROM user WHERE username = 'sinaud'") === 0 && (int)one($root, 'SELECT COUNT(*) FROM audit_log') === $a0, 'sin permiso para auditar: edición y alta fallan (5xx genérico) y no queda ningún cambio');
[$s3] = api('POST', "/api/users/$uid/reactivate", $t, []);
$rootSrv->exec("GRANT INSERT ON `fixture_aw`.`audit_log` TO '$acct'@'%'");
[$s] = api('PUT', "/api/users/$uid", $t, ['phone' => '555-9999']);
expectCase($s === 200 && one($root, 'SELECT phone FROM user WHERE id = ?', [$uid]) === '555-9999', 'restablecido el permiso, la misma edición funciona');

echo "== Reversibilidad: quitar el interruptor o los privilegios devuelve el estado de la fase 2A\n";
expectCase((int)one($root, "SELECT COUNT(*) FROM audit_log WHERE entity = 'user'") >= 4 && (int)one($root, "SELECT COUNT(*) FROM audit_log WHERE action LIKE 'user.%' AND entity <> 'user'") === 0, 'toda escritura de usuarios dejó auditoría de entidad «user»');
expectCase(!preg_match(LEAK, preg_replace('/"token":"[^"]+"/', '', $GLOBALS['bodies'])), 'ninguna respuesta de la sesión contiene hash, contraseña, SQLSTATE ni rutas internas');

echo "\n" . ($failures === 0 ? "All admin_escritura fase 2B ($role) checks passed" : "$failures check(s) FAILED") . "\n";
exit($failures === 0 ? 0 : 1);
