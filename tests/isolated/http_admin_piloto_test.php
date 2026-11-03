<?php
// UX-07E.B · PILOTO ADMINISTRATIVO DE SOLO LECTURA (datos 100 % SINTÉTICOS, red Docker aislada). Comprueba: quién entra (solo administradores autorizados en la lista propia), separación de
// roles, protección de endpoints, listados de usuarios/empresas/informes/asignaciones iguales a SQL y sin contraseñas ni enlaces, ausencia total de escrituras (HTTP y a nivel de cuenta
// MySQL), revocación inmediata de sesiones, bloqueo por intentos, HTTPS obligatorio y auditoría sin datos sensibles.
//   appadm : visor «TODOS» (HTTP solo por CERVESUR_INSECURE_TESTS sobre una base no protegida)   appadm0 : ningún visor   appadmthr : bloqueo por intentos (otro secreto)
//   appadmhttps : SIN el permiso de HTTP (exige TLS)   appadmbad : configuración inválida (fail-closed)
// Entorno: TEST_DB_PASS, A_AUTH_PASS, A_CAT_PASS, A_STATE_PASS, A_SECRET.
$failures = 0;
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$root = new PDO('mysql:host=db;dbname=fixture_prod;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), $opts);
$st = new PDO('mysql:host=db;dbname=estado_admin_test;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), $opts);
$bodies = '';
function api($host, $method, $path, $token = null, $body = null, $extra = []) {
    $headers = ['Content-Type: application/json']; if ($token !== null && $token !== '') $headers[] = 'Authorization: Bearer ' . $token;
    foreach ($extra as $h) $headers[] = $h;
    $o = ['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 20]];
    if ($body !== null) $o['http']['content'] = json_encode($body);
    $raw = @file_get_contents("http://$host:8080$path", false, stream_context_create($o));
    $GLOBALS['last'] = substr((string)$raw, 0, 300); $GLOBALS['hdrs'] = $http_response_header ?? []; $GLOBALS['bodies'] .= (string)$raw;
    $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int)$m[1] : 0;
    return [$status, json_decode((string)$raw, true), (string)$raw];
}
function expectCase($c, $label) { global $failures; if ($c) echo "PASS $label\n"; else { echo "FAIL $label\n  última respuesta: " . ($GLOBALS['last'] ?? '') . "\n"; $failures++; } }
function login($username, $host = 'appadm', $password = 'synthetic-password') { [, $d] = api($host, 'POST', '/api/login', null, ['login' => $username, 'password' => $password]); return $d['token'] ?? ''; }
function q($pdo, $sql, $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); }
function one($pdo, $sql, $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }
function walk($host, $path, $token, $query = 'view=minimal', $key = 'data') { $all = []; $page = 1; do { [, $d] = api($host, 'GET', "$path?$query&per_page=100&page=$page", $token); $all = array_merge($all, $d[$key] ?? []); $more = !empty($d['pagination']['has_more']); $page++; } while ($more && $page < 30); return $all; }
function sums($root) { $o = []; foreach ($root->query('CHECKSUM TABLE user, empresa, reporte, empresa_usuario, empresa_reporte, user_empresa_reporte') as $r) $o[$r['Table']] = $r['Checksum']; return $o; }
function cli(array $args) {
    $env = array_merge($_ENV, getenv(), ['DB_HOST' => 'db', 'DB_NAME' => 'fixture_prod', 'DB_USER' => 'cervesur_ro_catalogo_adm', 'DB_PASS' => getenv('A_CAT_PASS'), 'AUTH_DB_USER' => 'cervesur_ro_auth_adm', 'AUTH_DB_PASS' => getenv('A_AUTH_PASS'),
        'STATE_DB_NAME' => 'estado_admin_test', 'STATE_DB_USER' => 'cervesur_react_estado_adm', 'STATE_DB_PASS' => getenv('A_STATE_PASS'), 'CERVESUR_MODE' => 'piloto_admin', 'CERVESUR_REQUIRED_MODE' => 'piloto_admin',
        'CERVESUR_PILOT_SECRET' => getenv('A_SECRET'), 'CERVESUR_INSECURE_TESTS' => 'SI', 'CERVESUR_PILOT_VISOR_IDS' => 'TODOS']);
    $p = proc_open(array_merge([PHP_BINARY, __DIR__ . '/../../backend/cli/piloto-usuarios.php'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); return [proc_close($p), $out . $err]; }
const SENSITIVE = '/\$2y\$|"password"|session_version|powerbi_(tenant|client|clientsecret)|SQLSTATE|PDOException|cervesur_ro_|cervesur_react_estado|Stack trace|\/var\/www|\/work\//i';
const ADMIN_OK = 1; const ADMIN_INACTIVO = 2; const ADMIN_FUERA = 90; const TRABAJADOR = 5;

// Administrador ACTIVO que NO figura en la lista de la instancia (en el fixture solo el id 1 es administrador activo): lo crea la prueba y lo borra al final.
$root->exec("DELETE FROM user WHERE id = 90");
$root->prepare('INSERT INTO user (id, username, email, password, first_name, last_name, role, is_active) SELECT 90, ?, ?, password, ?, ?, ?, 1 FROM user WHERE id = 1')->execute(['syn090', 'syn090@example.invalid', 'Sintético', 'Usuario090', 'admin']);
$sumsBefore = sums($root);
$lastLoginBefore = $root->query('SELECT id, last_login, session_version FROM user ORDER BY id')->fetchAll();
foreach ([ADMIN_OK, ADMIN_INACTIVO, TRABAJADOR] as $id) $st->prepare("INSERT INTO usuarios_piloto (user_id, nota, activo, creado_en, creado_por) VALUES (?, 'prueba', 1, UTC_TIMESTAMP(), 'test')")->execute([$id]); // id 3 (admin) NO está; 5 (trabajador) se cuela a propósito
$adm = 'syn001';

echo "== Fail-closed y HTTPS obligatorio\n";
[$s, $d, $raw] = api('appadmbad', 'GET', '/api/ping');
expectCase($s === 503 && ($d['code'] ?? '') === 'mode_config' && !preg_match(SENSITIVE, $raw), 'configuración inválida (base de estado = base de lectura): 503 genérico, sin detalles');
[$s1, $d1] = api('appadmhttps', 'GET', '/api/ping'); [$s2, $d2] = api('appadmhttps', 'POST', '/api/login', null, ['login' => $adm, 'password' => 'synthetic-password']);
[$s3] = api('appadmhttps', 'GET', '/api/ping', null, null, ['X-Forwarded-Proto: https']); [$s4] = api('appadmhttps', 'POST', '/api/login', null, ['login' => $adm, 'password' => 'synthetic-password'], ['X-Forwarded-Proto: https', 'X-Forwarded-For: 127.0.0.1']);
expectCase($s1 === 403 && ($d1['code'] ?? '') === 'https_requerido' && $s2 === 403 && !isset($d2['token']) && $s3 === 403 && $s4 === 403, 'sin TLS toda petición (login incluido) se rechaza (403 https_requerido); X-Forwarded-Proto falsificado desde otra máquina no sirve');

echo "== Autenticación: solo administradores autorizados\n";
$tAdm = login($adm);
expectCase($tAdm !== '' && preg_match('/^[0-9a-f]{64}$/', $tAdm) === 1, 'administrador activo y autorizado en la lista de la instancia: inicia sesión (token opaco de 256 bits)');
[, $perfil] = api('appadm', 'GET', '/api/user/profile', $tAdm);
expectCase(($perfil['profile']['role'] ?? '') === 'admin' && ($perfil['profile']['username'] ?? '') === $adm && !str_contains(json_encode($perfil), 'password'), 'el perfil trae rol «admin» y nunca la contraseña');
$bad = [];
foreach ([[ADMIN_INACTIVO, 'syn002', 'administrador INACTIVO aunque esté en la lista'], [ADMIN_FUERA, 'syn090', 'administrador ACTIVO fuera de la lista de la instancia'], [TRABAJADOR, 'syn005', 'TRABAJADOR (rol usuario) aunque se haya colado en la lista'], [66, 'syn066', 'trabajador inactivo'], [70, 'syn070', 'rol «user» inactivo']] as [$id, $name, $why]) { if (login($name) !== '') $bad[] = $why; }
expectCase($bad === [], 'quedan rechazados: administrador inactivo, administrador no autorizado, trabajador (rol usuario) aunque esté en la lista, y cuentas inactivas' . ($bad ? ' · ' . implode(', ', $bad) : ''));
[, $dBad] = api('appadm', 'POST', '/api/login', null, ['login' => $adm, 'password' => 'incorrecta']); [, $dWorker] = api('appadm', 'POST', '/api/login', null, ['login' => 'syn005', 'password' => 'synthetic-password']); [, $dNone] = api('appadm', 'POST', '/api/login', null, ['login' => 'nadie', 'password' => 'x']);
expectCase(json_encode($dBad) === json_encode($dWorker) && json_encode($dWorker) === json_encode($dNone) && ($dBad['success'] ?? true) === false, 'contraseña incorrecta, trabajador e inexistente dan EXACTAMENTE la misma respuesta (no se enumera quién es administrador)');
expectCase(login('syn001@example.invalid') !== '', 'también se puede entrar con el correo, como en Flutter');
$noAuth = ['/api/users', '/api/empresas', '/api/reportes', '/api/reportes/1', '/api/reportes/id/1/visor', '/api/asignacion', '/api/empresa-usuario', '/api/empresa-reporte-usuario', '/api/user/profile'];
$all401 = true; foreach ($noAuth as $p) { foreach ([null, 'x', 'a.b.c', str_repeat('0', 64)] as $tk) { [$s, , $raw] = api('appadm', 'GET', $p, $tk); if ($s !== 401 || str_contains($raw, '"data"') || str_contains($raw, '"users"')) $all401 = false; } }
expectCase($all401, 'NINGÚN endpoint responde sin sesión ni con tokens inventados (401, sin datos)');

echo "== Consultas administrativas = SQL, sin contraseñas ni enlaces\n";
$usersAll = walk('appadm', '/api/users', $tAdm, 'status=all', 'users');
$uSql = q($root, 'SELECT id, username, email, first_name, last_name, role, is_active FROM user ORDER BY id');
$norm = fn ($rows) => array_map(fn ($r) => ['id' => (int)$r['id'], 'username' => $r['username'], 'email' => $r['email'], 'role' => $r['role'], 'is_active' => (int)$r['is_active']], $rows);
expectCase(count($usersAll) === count($uSql) && count($uSql) === 77 && $norm($usersAll) === $norm($uSql), 'GET /api/users?status=all devuelve los 77 usuarios (activos e inactivos) con los mismos datos que SQL');
$keys = array_keys($usersAll[0] ?? []); sort($keys);
expectCase($keys === ['email', 'first_name', 'id', 'is_active', 'last_name', 'role', 'username'], 'cada usuario trae SOLO id, usuario, correo, nombres, rol y estado: ni contraseña, ni hash, ni session_version, ni teléfono');
$act = walk('appadm', '/api/users', $tAdm, 'status=active', 'users'); $ina = walk('appadm', '/api/users', $tAdm, 'status=inactive', 'users'); $def = walk('appadm', '/api/users', $tAdm, 'x=1', 'users');
expectCase(count($act) === (int)one($root, 'SELECT COUNT(*) FROM user WHERE is_active = 1') && count($ina) === (int)one($root, 'SELECT COUNT(*) FROM user WHERE is_active = 0') && count($def) === count($act), 'filtros de estado: activos, inactivos y valor predeterminado (activos) coinciden con SQL');
$emp = walk('appadm', '/api/empresas', $tAdm); $rep = walk('appadm', '/api/reportes', $tAdm); $rel = walk('appadm', '/api/asignacion', $tAdm);
$cmp = fn ($a, $b, $cols) => array_map(fn ($r) => array_intersect_key($r, array_flip($cols)), $a) == array_map(fn ($r) => array_intersect_key(array_map('intval', array_filter($r, 'is_numeric')) + $r, array_flip($cols)), $b);
expectCase(count($emp) === (int)one($root, 'SELECT COUNT(*) FROM empresa') && ids2($emp) === ids2(q($root, 'SELECT id FROM empresa ORDER BY id')), 'empresas: todas (activas e inactivas), iguales a SQL');
expectCase(count($rep) === (int)one($root, 'SELECT COUNT(*) FROM reporte') && ids2($rep) === ids2(q($root, 'SELECT id FROM reporte ORDER BY id')), 'informes: todos (activos e inactivos), iguales a SQL');
expectCase(count($rel) === (int)one($root, 'SELECT COUNT(*) FROM empresa_reporte') && ids2($rel) === ids2(q($root, 'SELECT id FROM empresa_reporte ORDER BY id')), 'relaciones empresa–informe: todas, iguales a SQL');
function ids2($rows) { $o = array_map(fn ($r) => (int)$r['id'], $rows); sort($o); return $o; }
$eu = walk('appadm', '/api/empresa-usuario', $tAdm); $acc = walk('appadm', '/api/empresa-reporte-usuario', $tAdm);
expectCase(count($eu) === (int)one($root, 'SELECT COUNT(*) FROM empresa_usuario WHERE activo = 1') && count($acc) === (int)one($root, 'SELECT COUNT(*) FROM user_empresa_reporte uer INNER JOIN empresa_reporte er ON er.id = uer.empresa_reporte_id WHERE uer.activo = 1'), 'relaciones usuario–empresa y accesos usuario–informe: solo las vigentes (activo = 1), iguales a SQL');
$kAcc = array_keys($acc[0] ?? []); sort($kAcc);
expectCase($kAcc === ['empresa_id', 'empresa_reporte_id', 'id', 'reporte_id', 'user_id'], 'los accesos traen la forma del backend de Flutter (id compuesto «usuario-relación» solo para mostrar)');
$leak = false; $enlaces = array_column(q($root, "SELECT enlace FROM reporte WHERE enlace IS NOT NULL AND enlace <> ''"), 'enlace');
$cat = ''; foreach (['/api/reportes', '/api/empresas', '/api/asignacion', '/api/empresa-usuario', '/api/empresa-reporte-usuario'] as $p) { [, , $r] = api('appadm', 'GET', "$p?view=minimal&per_page=100", $tAdm); $cat .= $r; }
[, , $rUsers] = api('appadm', 'GET', '/api/users?status=all&per_page=100', $tAdm); $cat .= $rUsers;
foreach ($enlaces as $l) if ($l !== '' && str_contains($cat, $l)) $leak = true;
expectCase(!$leak && !str_contains($cat, 'enlace') && !preg_match('/https?:|powerbi|\$2y\$|"password"/i', $cat), 'los listados NUNCA incluyen enlaces de Power BI, hashes ni contraseñas');
[$sv] = api('appadm', 'GET', '/api/reportes?view=form', $tAdm); [$sv2] = api('appadm', 'GET', '/api/users?per_page=1000', $tAdm); [$sv3] = api('appadm', 'GET', '/api/reportes?per_page=1&page=0', $tAdm);
expectCase($sv === 400 && $sv2 === 400 && $sv3 === 400, 'vistas de formulario y paginación fuera de rango (per_page 1000, page 0) se rechazan con 400');

echo "== Visor: enlace solo para informes autorizados\n";
$rid = (int)one($root, 'SELECT id FROM reporte WHERE activo = 1 ORDER BY id LIMIT 1');
[$sV, $dV] = api('appadm', 'GET', "/api/reportes/id/$rid/visor", $tAdm); $tAdm0 = login($adm, 'appadm0'); [$sV0, $dV0, $rV0] = api('appadm0', 'GET', "/api/reportes/id/$rid/visor", $tAdm0); [$sN] = api('appadm', 'GET', '/api/reportes/id/999999/visor', $tAdm);
expectCase($sV === 200 && !empty($dV['data']['url']) && $sV0 === 403 && ($dV0['code'] ?? '') === 'visor_no_autorizado' && !str_contains($rV0, '"url"') && $sN === 404, 'con el informe autorizado el visor entrega el enlace (200); sin autorización 403 sin enlace; inexistente 404');
$ridOff = (int)one($root, 'SELECT id FROM reporte WHERE activo = 0 ORDER BY id LIMIT 1');
[$sOff, , $rOff] = api('appadm', 'GET', "/api/reportes/id/$ridOff/visor", $tAdm);
expectCase($ridOff > 0 && $sOff === 404 && !str_contains($rOff, '"url"') && !preg_match('~https?:|powerbi~i', $rOff), 'UX-08G: un informe INACTIVO no entrega enlace ni con la lista en TODOS (404 sin enlace)');
$ridsOk = [$rid]; $multi = 0; foreach ($ridsOk as $x) { [$sx, $dx] = api('appadm', 'GET', "/api/reportes/id/$x/visor?id=$ridOff&reporte_id=$ridOff", $tAdm); if ($sx === 403 || $sx === 404 || ($dx['data']['id'] ?? $x) !== $x) $multi++; }
expectCase($multi === 0, 'UX-08G: parámetros de la URL (?id=…) no cambian el informe del visor');
[$sD, $dD] = api('appadm', 'GET', "/api/reportes/$rid", $tAdm);
expectCase($sD === 200 && ($dD['data']['id'] ?? 0) === $rid && !array_key_exists('enlace', $dD['data'] ?? []), 'el detalle del informe no incluye el enlace');
[, $lstA] = api('appadm', 'GET', '/api/reportes?view=minimal&per_page=100&page=1', $tAdm); [, $lst0] = api('appadm0', 'GET', '/api/reportes?view=minimal&per_page=100&page=1', $tAdm0);
expectCase(count($lstA['data'] ?? []) > 3 && !array_filter($lstA['data'], fn ($r) => ($r['visor_autorizado'] ?? -1) !== 1) && !array_filter($lst0['data'], fn ($r) => ($r['visor_autorizado'] ?? -1) !== 0) && !str_contains(json_encode($lstA) . json_encode($lst0), 'enlace') && ($dD['data']['visor_autorizado'] ?? -1) === 1, 'indicador visor_autorizado: 1 en todos los informes con «TODOS» y 0 en todos si ninguno está autorizado; es solo un indicador, nunca el enlace');

echo "== Ninguna escritura: HTTP y cuentas MySQL\n";
$denied = [];
foreach ([['POST', '/api/register'], ['POST', '/api/users'], ['PUT', '/api/users/1'], ['DELETE', '/api/users/1'], ['PUT', '/api/user/1/role'], ['PUT', '/api/user/profile'], ['POST', '/api/user/avatar'], ['POST', '/api/reportes'], ['PUT', '/api/reportes/1'], ['DELETE', '/api/reportes/1'],
    ['POST', '/api/empresas'], ['PUT', '/api/empresas/1'], ['DELETE', '/api/empresas/1'], ['POST', '/api/asignacion'], ['PUT', '/api/asignacion/1'], ['DELETE', '/api/asignacion/1'], ['POST', '/api/empresa-usuario'], ['DELETE', '/api/empresa-usuario/1'],
    ['POST', '/api/empresa-reporte-usuario'], ['DELETE', '/api/empresa-reporte-usuario/1'], ['GET', '/api/stats'], ['GET', '/api/empresas/1'], ['GET', '/api/empresas/buscar/x'], ['GET', '/api/reportes/activos'], ['GET', '/api/powerbi/tokens/1'], ['GET', '/api/wiki'],
    ['GET', '/api/user'], ['GET', '/api/user/profile/1'], ['GET', '/api/users/'],
    // UX-08 (versión 1, solo lectura): ninguna ruta nueva de escritura, historial ni capacidades existe en este modo, ni siquiera con USER_ADMIN_WRITES=1 en el entorno de la instancia.
    ['GET', '/api/auditoria'], ['GET', '/api/auditoria?entity=user'], ['GET', '/api/users/capabilities'], ['GET', '/api/users/1'], ['PUT', '/api/users/1/password'], ['POST', '/api/users/1/deactivate'], ['POST', '/api/users/1/reactivate'],
    ['POST', '/api/empresas/1/reactivate'], ['POST', '/api/reportes/1/reactivate'], ['POST', '/api/asignacion/1/reactivate'], ['POST', '/api/empresa-usuario/1/reactivate'], ['POST', '/api/empresa-reporte-usuario/1/reactivate'],
    ['PUT', '/api/empresa-reporte-usuario/1'], ['GET', '/api/empresas/1/form'], ['GET', '/api/ping/'], ['PATCH', '/api/users'], ['OPTIONS', '/api/users'], ['TRACE', '/api/users']] as [$m, $p]) {
    [$s, , $raw] = api('appadm', $m, $p, $tAdm, in_array($m, ['POST', 'PUT', 'PATCH'], true) ? ['x' => 1] : null);
    if ($s !== 403 || preg_match(SENSITIVE, $raw)) $denied[] = "$m $p→$s";
}
expectCase($denied === [], count($denied) . ' rutas/métodos de escritura, formularios, administración heredada y variantes → 403 con sesión de administrador válida' . ($denied ? ' · ' . implode(', ', array_slice($denied, 0, 6)) : ''));
$neg = [];
foreach ([['cervesur_ro_catalogo_adm', 'A_CAT_PASS', "INSERT INTO empresa (nombre) VALUES ('x')"], ['cervesur_ro_catalogo_adm', 'A_CAT_PASS', "UPDATE reporte SET activo = 0"], ['cervesur_ro_catalogo_adm', 'A_CAT_PASS', 'SELECT password FROM user LIMIT 1'], ['cervesur_ro_catalogo_adm', 'A_CAT_PASS', 'SELECT powerbi_clientsecret FROM empresa LIMIT 1'],
    ['cervesur_ro_auth_adm', 'A_AUTH_PASS', "UPDATE user SET role = 'admin' WHERE id = 5"], ['cervesur_ro_auth_adm', 'A_AUTH_PASS', 'DELETE FROM user'], ['cervesur_ro_auth_adm', 'A_AUTH_PASS', 'SELECT phone FROM user LIMIT 1'], ['cervesur_ro_auth_adm', 'A_AUTH_PASS', 'SELECT * FROM empresa'],
    ['cervesur_react_estado_adm', 'A_STATE_PASS', "INSERT INTO fixture_prod.empresa (nombre) VALUES ('x')"], ['cervesur_react_estado_adm', 'A_STATE_PASS', "UPDATE fixture_prod.user SET is_active = 1"], ['cervesur_react_estado_adm', 'A_STATE_PASS', 'SELECT password FROM fixture_prod.user LIMIT 1']] as [$acct, $env, $sql]) {
    try { $c = new PDO('mysql:host=db;dbname=fixture_prod;charset=utf8mb4', $acct, (string)getenv($env), $opts); $c->query($sql)->fetchAll(); $neg[] = "$acct pudo: $sql"; } catch (PDOException $e) { /* esperado: acceso denegado */ }
}
expectCase($neg === [], 'las TRES cuentas de la instancia están denegadas a nivel de MySQL: sin INSERT/UPDATE/DELETE sobre la base de Flutter, sin leer columnas fuera de su lista (contraseña desde catálogo, teléfono, credenciales de Power BI)' . ($neg ? ' · ' . implode(' | ', $neg) : ''));
$g = ['ro' => '', 'estado' => ''];
foreach (['cervesur_ro_auth_adm', 'cervesur_ro_catalogo_adm'] as $u) foreach (q($root, "SHOW GRANTS FOR '$u'@'%'") as $row) $g['ro'] .= array_values($row)[0] . "\n";
foreach (q($root, "SHOW GRANTS FOR 'cervesur_react_estado_adm'@'%'") as $row) $g['estado'] .= array_values($row)[0] . "\n";
expectCase(!preg_match('/\b(INSERT|UPDATE|DELETE|CREATE|DROP|ALTER|FILE|SUPER|PROCESS|ALL PRIVILEGES|GRANT OPTION)\b/i', $g['ro']) && !str_contains($g['estado'], 'fixture_prod') && !preg_match('/GRANT OPTION|ALL PRIVILEGES|\b(CREATE|DROP|ALTER|FILE|SUPER)\b/i', $g['estado']) && str_contains($g['estado'], 'estado_admin_test'), 'SHOW GRANTS: las dos cuentas de lectura solo tienen SELECT; la de estado solo DML en su propia base; ninguna con GRANT OPTION, DDL ni privilegios globales');

echo "== Revocación inmediata de sesiones\n";
$ok = true; $why = [];
$check = function ($label, callable $apply, callable $undo) use (&$ok, &$why, $adm, $root, $st) {
    $t = login($adm); $before = api('appadm', 'GET', '/api/users?per_page=1', $t)[0];
    $apply(); [$after] = api('appadm', 'GET', '/api/users?per_page=1', $t); $undo(); [$revive] = api('appadm', 'GET', '/api/users?per_page=1', $t); $new = login($adm);
    if ($before !== 200 || $after !== 401 || $revive !== 401 || $new === '') { $ok = false; $why[] = "$label($before/$after/$revive)"; }
};
$check('lista', fn () => $st->exec('UPDATE usuarios_piloto SET activo = 0 WHERE user_id = 1'), fn () => $st->exec('UPDATE usuarios_piloto SET activo = 1 WHERE user_id = 1'));
$check('desactivado', fn () => $root->exec('UPDATE user SET is_active = 0 WHERE id = 1'), fn () => $root->exec('UPDATE user SET is_active = 1 WHERE id = 1'));
$check('rol', fn () => $root->exec("UPDATE user SET role = 'usuario' WHERE id = 1"), fn () => $root->exec("UPDATE user SET role = 'admin' WHERE id = 1"));
$check('session_version', fn () => $root->exec('UPDATE user SET session_version = session_version + 1 WHERE id = 1'), fn () => $root->exec('UPDATE user SET session_version = session_version - 1 WHERE id = 1'));
$orig = one($root, 'SELECT password FROM user WHERE id = 1');
$check('contraseña', fn () => $root->prepare('UPDATE user SET password = ? WHERE id = 1')->execute([password_hash('otra-clave-sintetica', PASSWORD_DEFAULT)]), fn () => $root->prepare('UPDATE user SET password = ? WHERE id = 1')->execute([$orig]));
expectCase($ok, 'la sesión se invalida de inmediato (401) y NO revive al revertir: quitar de la lista, desactivar, cambiar rol, cambiar session_version, cambiar contraseña' . ($why ? ' · ' . implode(', ', $why) : ''));
$t1 = login($adm); [$sl, $dl] = api('appadm', 'POST', '/api/logout', $t1); [$sAfter] = api('appadm', 'GET', '/api/users?per_page=1', $t1); $rev = one($st, "SELECT motivo_revocacion FROM sesiones WHERE token_hash = ?", [hash('sha256', $t1)]);
expectCase($sl === 200 && ($dl['revoked_server_side'] ?? false) === true && $sAfter === 401 && $rev === 'cierre_de_sesion', 'cerrar sesión revoca el token en el servidor (reutilizarlo da 401)');
$t2 = login($adm); $t3 = login($adm); api('appadm', 'POST', '/api/logout', $t2);
expectCase(api('appadm', 'GET', '/api/users?per_page=1', $t3)[0] === 200, 'cerrar una sesión no cierra las otras del mismo administrador');
$tExp = login($adm); $st->prepare('UPDATE sesiones SET expira_en = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE token_hash = ?')->execute([hash('sha256', $tExp)]);
expectCase(api('appadm', 'GET', '/api/users?per_page=1', $tExp)[0] === 401, 'una sesión caducada → 401');

echo "== CLI de administración de la lista (solo línea de comandos)\n";
[$c1, $o1] = cli(['agregar', '5']); [$c2, $o2] = cli(['agregar', '2']); [$c3, $o3] = cli(['agregar', '90', 'prueba']); [$c4, $o4] = cli(['agregar', '999']); [$c5] = cli(['quitar', '90']);
expectCase($c1 !== 0 && str_contains($o1, 'admin') && $c2 !== 0 && $c4 !== 0 && $c3 === 0 && $c5 === 0 && !preg_match(SENSITIVE, $o1 . $o2 . $o3 . $o4), 'agregar rechaza trabajadores, administradores inactivos e inexistentes; acepta administradores activos; quitar los saca');
[$c6, $o6] = cli(['listar']); [$c7, $o7] = cli(['auditoria']); [$c8, $o8] = cli(['sesiones']);
expectCase($c6 === 0 && $c7 === 0 && $c8 === 0 && !preg_match('/\$2y\$|synthetic-password|https?:|powerbi/i', $o6 . $o7 . $o8), 'listar/auditoría/sesiones no imprimen contraseñas, hashes ni enlaces');

echo "== Bloqueo por intentos y auditoría\n";
foreach (['syn001', 'syn001', 'syn001', 'syn001', 'syn001'] as $_) login($_, 'appadmthr', 'mala');
[$sLock, , ] = api('appadmthr', 'POST', '/api/login', null, ['login' => 'syn001', 'password' => 'synthetic-password']); $retry = null; foreach ($GLOBALS['hdrs'] as $h) if (stripos($h, 'Retry-After:') === 0) $retry = trim(substr($h, 12));
expectCase($sLock === 429 && $retry !== null && (int)$retry > 0, 'tras 5 fallos la cuenta queda bloqueada (429 + Retry-After) aunque use la clave correcta');
expectCase(!str_contains(json_encode(q($st, 'SELECT clave_hash FROM intentos_login')), 'syn001') && (int)one($st, "SELECT COUNT(*) FROM intentos_login WHERE LENGTH(clave_hash) = 64 AND clave_hash NOT REGEXP '^[0-9a-f]{64}$'") === 0, 'los intentos se guardan solo como huellas HMAC (sin nombres ni direcciones)');
$ev = array_column(q($st, 'SELECT DISTINCT evento FROM auditoria'), 'evento'); $faltan = array_diff(['login_ok', 'login_fallido_clave', 'login_fallido_rol', 'login_fallido_lista_blanca', 'login_fallido_inactivo', 'logout', 'admin_usuarios', 'admin_empresas', 'admin_informes', 'admin_asignaciones', 'admin_accesos', 'visor_abierto', 'visor_no_autorizado_piloto', 'sesion_invalidada_lista_blanca', 'sesion_invalidada_rol', 'sesion_invalidada_clave_cambiada', 'login_bloqueado'], $ev);
expectCase(!$faltan, 'la auditoría registra inicios, fallos con motivo interno (rol, lista, inactivo, clave), cierres, cada consulta administrativa, visores, sesiones invalidadas y bloqueos' . ($faltan ? ' · faltan: ' . implode(',', $faltan) : ''));
$dump = json_encode([q($st, 'SELECT * FROM auditoria'), q($st, 'SELECT * FROM sesiones'), q($st, 'SELECT * FROM usuarios_piloto')]);
expectCase(!preg_match('/\$2y\$|synthetic-password|https?:|powerbi|syn0\d\d|@example/i', $dump) && !preg_match('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', $dump) && (int)one($st, 'SELECT COUNT(*) FROM sesiones WHERE LENGTH(token_hash) <> 64') === 0, 'la base de estado NO contiene contraseñas, hashes, enlaces, nombres de usuario, correos ni direcciones IP en claro (solo ids y huellas)');

echo "== Sin escrituras en la base de Flutter\n";
$sumsAfter = sums($root); $lastLoginAfter = $root->query('SELECT id, last_login, session_version FROM user ORDER BY id')->fetchAll();
$diff = array_keys(array_diff_assoc($sumsAfter, $sumsBefore));
expectCase($diff === ['user'] || $diff === [], 'tras decenas de inicios, cierres, revocaciones y consultas, las tablas de Flutter son idénticas (la única tocada es la que ESTA PRUEBA modificó y restituyó a propósito: user)' . ($diff ? ' · ' . implode(',', $diff) : ''));
$lastChanged = []; foreach ($lastLoginBefore as $i => $b) { if ($b['last_login'] !== $lastLoginAfter[$i]['last_login']) $lastChanged[] = $b['id']; }
expectCase($lastChanged === [], 'last_login no se toca: el piloto no escribe en la base de Flutter');
expectCase(!preg_match('/\$2y\$|"password"|powerbi_(tenant|client|clientsecret)|SQLSTATE|PDOException|Stack trace|\/var\/www|\/work\//i', $GLOBALS['bodies']), 'ninguna respuesta de toda la prueba contiene hashes, la clave «password», credenciales de Power BI, SQL, trazas ni rutas internas');
api('appadm', 'GET', '/api/ping'); $h = null; foreach ($GLOBALS['hdrs'] as $x) if (stripos($x, 'Cache-Control:') === 0) $h = $x;
expectCase($h !== null && stripos($h, 'no-store') !== false, 'respuestas con Cache-Control no-store');

$root->exec('DELETE FROM user WHERE id = 90');
echo $failures === 0 ? "\nTODO CORRECTO\n" : "\n$failures FALLOS\n";
exit($failures === 0 ? 0 : 1);
