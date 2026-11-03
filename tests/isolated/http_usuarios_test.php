<?php
// UX-07D.4B · Piloto con USUARIOS REALES (aquí SINTÉTICOS): autenticación con los hashes bcrypt existentes, lista blanca, sesiones revocables en una base de estado PROPIA,
// permisos individuales por empresa/informe, bloqueo por intentos, HTTPS obligatorio y ausencia de escrituras en la base de Flutter. Red Docker aislada, datos 100 % ficticios.
//   appusr    : visor «TODOS» (HTTP permitido solo por CERVESUR_INSECURE_TESTS sobre una base no protegida)   appusr0 : ningún visor autorizado
//   appusrthr : instancia aparte (otro secreto = otro espacio de huellas) para el bloqueo por intentos      appusrhttps : SIN el permiso de HTTP (exige TLS)
//   appusrbad1/2 : configuraciones inválidas (fail-closed)
// Entorno: TEST_DB_PASS, U_AUTH_PASS, U_CAT_PASS, U_STATE_PASS, U_SECRET.
$failures = 0;
$root = new PDO('mysql:host=db;dbname=fixture_prod;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$st = new PDO('mysql:host=db;dbname=estado_piloto_test;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
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
function login($username, $host = 'appusr', $password = 'synthetic-password') { [, $d] = api($host, 'POST', '/api/login', null, ['login' => $username, 'password' => $password]); return $d['token'] ?? ''; }
function q($pdo, $sql, $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchAll(); }
function one($pdo, $sql, $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }
function walk($host, $path, $token) { $all = []; $page = 1; do { [, $d] = api($host, 'GET', "$path?view=minimal&per_page=100&page=$page", $token); $all = array_merge($all, $d['data'] ?? []); $more = !empty($d['pagination']['has_more']); $page++; } while ($more && $page < 20); return $all; }
function ids($rows) { $o = array_map(fn ($r) => (int)$r['id'], $rows); sort($o); return $o; }
function sums($root) { $o = []; foreach ($root->query('CHECKSUM TABLE user, empresa, reporte, empresa_usuario, empresa_reporte, user_empresa_reporte') as $r) $o[$r['Table']] = $r['Checksum']; return $o; }
function header_of($n) { foreach ($GLOBALS['hdrs'] as $h) if (stripos($h, $n . ':') === 0) return trim(substr($h, strlen($n) + 1)); return null; }
function cli(array $args) { $env = array_merge($_ENV, getenv(), ['DB_HOST' => 'db', 'DB_NAME' => 'fixture_prod', 'DB_USER' => 'cervesur_ro_catalogo', 'DB_PASS' => getenv('U_CAT_PASS'), 'AUTH_DB_USER' => 'cervesur_ro_auth', 'AUTH_DB_PASS' => getenv('U_AUTH_PASS'), 'STATE_DB_NAME' => 'estado_piloto_test', 'STATE_DB_USER' => 'cervesur_react_estado', 'STATE_DB_PASS' => getenv('U_STATE_PASS'), 'CERVESUR_MODE' => 'piloto_usuarios', 'CERVESUR_REQUIRED_MODE' => 'piloto_usuarios', 'CERVESUR_PILOT_SECRET' => getenv('U_SECRET'), 'CERVESUR_INSECURE_TESTS' => 'SI', 'CERVESUR_PILOT_VISOR_IDS' => 'TODOS']);
    $p = proc_open(array_merge([PHP_BINARY, __DIR__ . '/../../backend/cli/piloto-usuarios.php'], $args), [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $env);
    $out = stream_get_contents($pipes[1]); $err = stream_get_contents($pipes[2]); return [proc_close($p), $out . $err]; }
const CHAIN = 'FROM user_empresa_reporte uer JOIN empresa_reporte er ON er.id = uer.empresa_reporte_id AND er.activo = 1 JOIN empresa e ON e.id = er.empresa_id AND e.activo = 1 JOIN empresa_usuario eu ON eu.empresa_id = e.id AND eu.user_id = uer.user_id AND eu.activo = 1 JOIN reporte r ON r.id = er.reporte_id AND r.activo = 1 WHERE uer.activo = 1';
const SENSITIVE = '/\$2y\$|"password"|powerbi_(tenant|client|clientsecret)|SQLSTATE|PDOException|cervesur_ro_|cervesur_react_estado|Stack trace|\/var\/www|\/work\//i';

$sumsBefore = sums($root);
$lastLoginBefore = $root->query('SELECT id, last_login, session_version FROM user ORDER BY id')->fetchAll();
$lista = array_map('intval', array_column(q($root, "SELECT id FROM user WHERE role = 'usuario' AND is_active = 1 AND id <> 65 ORDER BY id"), 'id'));
foreach (array_merge($lista, [1, 66, 67]) as $id) $st->prepare("INSERT INTO usuarios_piloto (user_id, nota, activo, creado_en, creado_por) VALUES (?, 'prueba', 1, UTC_TIMESTAMP(), 'test')")->execute([$id]);

echo "== Fail-closed y HTTPS obligatorio\n";
foreach ([['appusrbad1', 'sin CERVESUR_PILOT_SECRET'], ['appusrbad2', 'base de estado igual a la base de lectura']] as [$h, $label]) {
    [$s, $d, $raw] = api($h, 'GET', '/api/ping');
    expectCase($s === 503 && ($d['code'] ?? '') === 'mode_config' && !preg_match(SENSITIVE, $raw), "$label: 503 genérico, sin detalles");
}
[$s1, $d1] = api('appusrhttps', 'GET', '/api/ping'); [$s2, $d2] = api('appusrhttps', 'POST', '/api/login', null, ['login' => 'syn005', 'password' => 'synthetic-password']);
[$s3] = api('appusrhttps', 'GET', '/api/ping', null, null, ['X-Forwarded-Proto: https']); [$s4] = api('appusrhttps', 'POST', '/api/login', null, ['login' => 'syn005', 'password' => 'synthetic-password'], ['X-Forwarded-Proto: https', 'X-Forwarded-For: 127.0.0.1']);
expectCase($s1 === 403 && ($d1['code'] ?? '') === 'https_requerido' && $s2 === 403 && !isset($d2['token']), 'sin TLS toda petición (incluido el inicio de sesión con credenciales) se rechaza: 403 «https_requerido»');
expectCase($s3 === 403 && $s4 === 403, 'una cabecera X-Forwarded-Proto falsificada desde otra máquina NO sirve (solo se confía en un terminador TLS en loopback)');

echo "== Autenticación: quién entra y quién no\n";
$users = q($root, 'SELECT id, username, email, role, is_active FROM user ORDER BY id');
$tokens = []; $rechazados = []; $mal = [];
foreach ($users as $u) {
    $t = login($u['username']);
    $debe = in_array((int)$u['id'], $lista, true);
    if ($t !== '') $tokens[$u['username']] = $t;
    if ($debe !== ($t !== '')) $mal[] = $u['username'] . ($debe ? ' debía entrar' : ' no debía entrar');
}
expectCase($mal === [] && count($tokens) === count($lista), count($lista) . ' usuarios activos, de rol «usuario» y en la lista blanca inician sesión; NO entran los 14 inactivos (también los de la lista), el administrador (también en la lista), ni el activo que no está en la lista blanca' . ($mal ? ' · ' . implode(', ', array_slice($mal, 0, 5)) : ''));
foreach ([['syn001', 'administrador en la lista blanca'], ['syn066', 'inactivo en la lista blanca'], ['syn067', 'rol «user» histórico pero inactivo'], ['syn065', 'activo fuera de la lista blanca']] as [$n, $label]) expectCase(login($n) === '', "$label: rechazado");
[, $dBadPwd] = api('appusr', 'POST', '/api/login', null, ['login' => 'syn005', 'password' => 'incorrecta']); [, $dNone] = api('appusr', 'POST', '/api/login', null, ['login' => 'no-existe-xyz', 'password' => 'incorrecta']); [, $dOutList] = api('appusr', 'POST', '/api/login', null, ['login' => 'syn065', 'password' => 'synthetic-password']);
expectCase($dBadPwd === $dNone && $dNone === $dOutList && ($dBadPwd['success'] ?? true) === false && !isset($dBadPwd['token']), 'contraseña incorrecta, usuario inexistente y usuario fuera de la lista dan EXACTAMENTE la misma respuesta (no se puede enumerar cuentas)');
$byEmail = login('syn005@example.invalid');
expectCase($byEmail !== '', 'también se puede entrar con el correo, como en Flutter');
$nosession = ['/api/user/profile', '/api/reportes', '/api/reportes/1', '/api/reportes/id/1/visor', '/api/empresas', '/api/asignacion'];
$all401 = true; foreach ($nosession as $p) { [$s, , $raw] = api('appusr', 'GET', $p); if ($s !== 401 || str_contains($raw, '"data"')) $all401 = false; }
expectCase($all401 && api('appusr', 'GET', '/api/reportes', 'x')[0] === 401 && api('appusr', 'GET', '/api/reportes', str_repeat('0', 64))[0] === 401 && api('appusr', 'GET', '/api/reportes', 'a.b.c')[0] === 401, 'ningún endpoint responde sin sesión; tokens inventados, de ceros o con forma de JWT → 401');
[, $prof] = api('appusr', 'GET', '/api/user/profile', $tokens['syn005']);
expectCase(($prof['profile']['username'] ?? '') === 'syn005' && $prof['profile']['email'] === 'syn005@example.invalid' && $prof['profile']['role'] === 'user' && !array_key_exists('password', $prof['profile']) && !str_contains(json_encode($prof), '$2y$'), 'el perfil trae la ficha real del usuario (nombre, correo, rol «user») y nunca la contraseña');

echo "== Permisos individuales: la API entrega a CADA usuario exactamente su permiso efectivo\n";
$allActive = array_map('intval', array_column(q($root, 'SELECT id FROM reporte WHERE activo = 1 ORDER BY id'), 'id'));
$mismatch = []; $companyMismatch = []; $relMismatch = []; $visorFail = []; $negFail = []; $pairs = 0; $negs = 0; $conAcceso = 0; $leakLinks = false;
$links = array_column($root->query('SELECT enlace FROM reporte')->fetchAll(), 'enlace');
foreach ($users as $u) {
    if (!isset($tokens[$u['username']])) continue;
    $uid = (int)$u['id']; $t = $tokens[$u['username']];
    $esperado = array_map('intval', array_column(q($root, 'SELECT DISTINCT r.id ' . CHAIN . ' AND uer.user_id = ? ORDER BY r.id', [$uid]), 'id'));
    $got = walk('appusr', '/api/reportes', $t);
    if (ids($got) !== $esperado) $mismatch[] = $u['username'];
    $empEsperadas = array_map('intval', array_column(q($root, 'SELECT e.id FROM empresa_usuario eu JOIN empresa e ON e.id = eu.empresa_id AND e.activo = 1 WHERE eu.activo = 1 AND eu.user_id = ? ORDER BY e.id', [$uid]), 'id'));
    if (ids(walk('appusr', '/api/empresas', $t)) !== $empEsperadas) $companyMismatch[] = $u['username'];
    $relEsperadas = array_map('intval', array_column(q($root, 'SELECT DISTINCT er.id ' . CHAIN . ' AND uer.user_id = ? ORDER BY er.id', [$uid]), 'id'));
    if (ids(walk('appusr', '/api/asignacion', $t)) !== $relEsperadas) $relMismatch[] = $u['username'];
    if ($esperado) $conAcceso++;
    foreach ($esperado as $rid) { $pairs++; [$s, $d] = api('appusr', 'GET', "/api/reportes/id/$rid/visor", $t); if ($s !== 200 || ($d['data']['id'] ?? 0) !== $rid || empty($d['data']['url'])) $visorFail[] = $u['username'] . "#$rid→$s"; }
    foreach (array_slice(array_values(array_diff($allActive, $esperado)), 0, 2) as $rid) { $negs++; [$s, , $raw] = api('appusr', 'GET', "/api/reportes/id/$rid/visor", $t); [$s2] = api('appusr', 'GET', "/api/reportes/$rid", $t); if ($s !== 404 || $s2 !== 404 || str_contains($raw, '"url"')) $negFail[] = $u['username'] . "#$rid→$s/$s2"; }
}
expectCase($mismatch === [] && $companyMismatch === [] && $relMismatch === [], 'catálogo de informes, empresas y relaciones de los ' . count($tokens) . ' usuarios = lo calculado en SQL con la cadena completa de relaciones activas' . ($mismatch ? ' · informes: ' . implode(',', array_slice($mismatch, 0, 5)) : '') . ($companyMismatch ? ' · empresas: ' . implode(',', array_slice($companyMismatch, 0, 5)) : '') . ($relMismatch ? ' · relaciones: ' . implode(',', array_slice($relMismatch, 0, 5)) : ''));
expectCase($conAcceso >= 55, "$conAcceso de los " . count($tokens) . ' usuarios tienen al menos un informe; ninguno ve el catálogo completo (los 82 activos son de otro rol)');
expectCase($visorFail === [] && $pairs >= 85, "visor: los $pairs informes autorizados de los catálogos se abren con su enlace (200)" . ($visorFail ? ' · ' . implode(', ', array_slice($visorFail, 0, 5)) : ''));
expectCase($negFail === [] && $negs >= 100, "acceso directo a informes NO asignados ($negs casos, visor y detalle): 404 idéntico a un inexistente, sin enlace" . ($negFail ? ' · ' . implode(', ', array_slice($negFail, 0, 5)) : ''));
// UX-07D.5 · Caso de aceptación «cuenta con exactamente DOS informes asignados» (como el usuario piloto real): ve esos dos y solo esos, los dos se abren cuando el enlace está autorizado
// (200) y NO se abren mientras ningún enlace esté autorizado (403, sin enlace); cualquier otro informe, por URL directa, es 404.
$dos = null;
foreach ($users as $u) { if (!isset($tokens[$u['username']])) continue; $r = array_map('intval', array_column(q($root, 'SELECT DISTINCT r.id ' . CHAIN . ' AND uer.user_id = ? ORDER BY r.id', [(int)$u['id']]), 'id')); if (count($r) === 2) { $dos = [$u['username'], $r]; break; } }
if ($dos === null) { expectCase(false, 'la base sintética debe incluir una cuenta con exactamente dos informes asignados'); } else {
    [$nameDos, $dosIds] = $dos; $tDos = $tokens[$nameDos]; $tDos0 = login($nameDos, 'appusr0'); $okDos = ids(walk('appusr', '/api/reportes', $tDos)) === $dosIds;
    foreach ($dosIds as $rid) { [$sA, $dA] = api('appusr', 'GET', "/api/reportes/id/$rid/visor", $tDos); $okDos = $okDos && $sA === 200 && !empty($dA['data']['url']); [$sN, $dN, $rawN] = api('appusr0', 'GET', "/api/reportes/id/$rid/visor", $tDos0); $okDos = $okDos && $sN === 403 && !str_contains($rawN, '"url"') && !isset($dN['data']['url']); }
    $otros = array_values(array_diff($allActive, $dosIds)); $okOtros = count($otros) >= 5;
    foreach ($otros as $rid) { [$sO, , $rawO] = api('appusr', 'GET', "/api/reportes/id/$rid/visor", $tDos); [$sO2] = api('appusr', 'GET', "/api/reportes/$rid", $tDos); if ($sO !== 404 || $sO2 !== 404 || str_contains($rawO, '"url"')) $okOtros = false; }
    expectCase($okDos, "cuenta con exactamente 2 informes ($nameDos): el catálogo trae esos 2; sin enlace autorizado ambos visores dan 403 sin enlace; con el enlace autorizado ambos abren (200)");
    expectCase($okOtros, "la misma cuenta: los " . count($otros) . ' informes activos restantes, por URL directa (visor y detalle), dan 404 idéntico a uno inexistente');
}
// UX-07E.C: el catálogo marca (solo con 0/1) si el visor entrega el enlace de cada informe; jamás el enlace
$fA = walk('appusr', '/api/reportes', login('syn005')); $f0 = walk('appusr0', '/api/reportes', login('syn005', 'appusr0'));
expectCase(count($fA) === 2 && !array_filter($fA, fn ($r) => ($r['visor_autorizado'] ?? -1) !== 1) && !array_filter($f0, fn ($r) => ($r['visor_autorizado'] ?? -1) !== 0) && !str_contains(json_encode($fA) . json_encode($f0), 'enlace'), 'indicador visor_autorizado en el catálogo del trabajador: 1 con «TODOS», 0 sin informes autorizados, sin enlaces');
$leak = false; $catalogBodies = '';
foreach (array_slice($tokens, 0, 8, true) as $t) foreach (['/api/reportes?per_page=100', '/api/empresas?per_page=100', '/api/asignacion?per_page=100', '/api/user/profile'] as $p) { [, , $raw] = api('appusr', 'GET', $p, $t); $catalogBodies .= $raw; }
foreach ($links as $l) if ($l !== '' && str_contains($catalogBodies, $l)) $leak = true;
expectCase(!$leak && !str_contains($catalogBodies, 'enlace') && !preg_match('/https?:|powerbi/i', $catalogBodies), 'el catálogo NUNCA devuelve enlaces de Power BI (ni la palabra «enlace»); solo el visor, tras comprobar el permiso actual');

// usuario con una sola cadena por informe, para cortar eslabón por eslabón
$chains = q($root, "SELECT u.id uid, u.username, r.id rid, uer.id uer_id, er.id er_id, e.id eid, eu.id eu_id FROM user u JOIN user_empresa_reporte uer ON uer.user_id = u.id AND uer.activo = 1 JOIN empresa_reporte er ON er.id = uer.empresa_reporte_id AND er.activo = 1 JOIN empresa e ON e.id = er.empresa_id AND e.activo = 1 JOIN empresa_usuario eu ON eu.empresa_id = e.id AND eu.user_id = u.id AND eu.activo = 1 JOIN reporte r ON r.id = er.reporte_id AND r.activo = 1 WHERE u.role = 'usuario' AND u.is_active = 1 AND u.id <> 65 ORDER BY u.id, r.id");
$cnt = []; foreach ($chains as $c) { $k = $c['uid'] . '-' . $c['rid']; $cnt[$k] = ($cnt[$k] ?? 0) + 1; }
$A = null; foreach ($chains as $c) if ($cnt[$c['uid'] . '-' . $c['rid']] === 1) { $A = $c; break; }
$tA = $tokens[$A['username']];
foreach ([['asignación individual (user_empresa_reporte)', 'user_empresa_reporte', 'id', $A['uer_id']], ['relación empresa–informe (empresa_reporte)', 'empresa_reporte', 'id', $A['er_id']], ['empresa', 'empresa', 'id', $A['eid']], ['vínculo usuario–empresa (empresa_usuario)', 'empresa_usuario', 'id', $A['eu_id']], ['informe', 'reporte', 'id', $A['rid']]] as [$label, $table, $key, $id]) {
    $root->prepare("UPDATE `$table` SET activo = 0 WHERE `$key` = ?")->execute([$id]);
    [$sV, , $rawV] = api('appusr', 'GET', "/api/reportes/id/{$A['rid']}/visor", $tA); [$sD] = api('appusr', 'GET', "/api/reportes/{$A['rid']}", $tA); $in = in_array((int)$A['rid'], ids(walk('appusr', '/api/reportes', $tA)), true);
    $root->prepare("UPDATE `$table` SET activo = 1 WHERE `$key` = ?")->execute([$id]);
    expectCase($sV === 404 && $sD === 404 && !$in && !str_contains($rawV, '"url"'), "$label inactivo(a): visor 404, detalle 404, fuera del catálogo y sin enlace");
}
[$sBack] = api('appusr', 'GET', "/api/reportes/id/{$A['rid']}/visor", $tA);
expectCase($sBack === 200, 'al restituir los eslabones vuelve el acceso con la MISMA sesión (los permisos se evalúan en cada petición)');

// varias empresas / sin informes
$multi = q($root, 'SELECT eu.user_id uid, COUNT(DISTINCT eu.empresa_id) n FROM empresa_usuario eu JOIN empresa e ON e.id = eu.empresa_id AND e.activo = 1 WHERE eu.activo = 1 AND eu.user_id IN (' . implode(',', $lista) . ') GROUP BY eu.user_id HAVING n >= 2 ORDER BY eu.user_id LIMIT 1');
if ($multi) {
    $uidM = (int)$multi[0]['uid']; $nameM = one($root, 'SELECT username FROM user WHERE id = ?', [$uidM]); $tM = $tokens[$nameM];
    $comp = walk('appusr', '/api/empresas', $tM); $rel = walk('appusr', '/api/asignacion', $tM);
    expectCase(count($comp) >= 2 && count(array_unique(array_column($rel, 'empresa_id'))) >= 1, "usuario con varias empresas ($nameM): ve sus " . count($comp) . ' empresas y los informes de la cadena completa de cada una');
} else {
    echo "INFO el inventario sintético no trae un usuario con dos empresas activas: se crea una relación temporal\n";
    $uidM = $lista[0]; $nameM = one($root, 'SELECT username FROM user WHERE id = ?', [$uidM]); $tM = $tokens[$nameM];
    $extra = (int)one($root, 'SELECT id FROM empresa WHERE activo = 1 AND id NOT IN (SELECT empresa_id FROM empresa_usuario WHERE user_id = ' . $uidM . ' AND activo = 1) ORDER BY id LIMIT 1');
    $root->prepare('INSERT INTO empresa_usuario (empresa_id, user_id, activo) VALUES (?, ?, 1)')->execute([$extra, $uidM]); $newEu = (int)$root->lastInsertId();
    $comp = walk('appusr', '/api/empresas', $tM);
    $root->prepare('DELETE FROM empresa_usuario WHERE id = ?')->execute([$newEu]);
    expectCase(count($comp) >= 2 && in_array($extra, ids($comp), true), "usuario con varias empresas ($nameM): la empresa agregada aparece en «Mis empresas» y desaparece al retirarla");
}
$B = $lista[count($lista) - 1]; $nameB = one($root, 'SELECT username FROM user WHERE id = ?', [$B]); $tB = $tokens[$nameB];
$rowsB = array_column(q($root, 'SELECT id FROM user_empresa_reporte WHERE user_id = ? AND activo = 1', [$B]), 'id');
$root->prepare('UPDATE user_empresa_reporte SET activo = 0 WHERE user_id = ?')->execute([$B]);
[, $dB] = api('appusr', 'GET', '/api/reportes?per_page=100', $tB); [$sBv] = api('appusr', 'GET', '/api/reportes/id/' . $allActive[0] . '/visor', $tB); $relB = walk('appusr', '/api/asignacion', $tB); $compB = walk('appusr', '/api/empresas', $tB);
foreach ($rowsB as $r) $root->prepare('UPDATE user_empresa_reporte SET activo = 1 WHERE id = ?')->execute([$r]);
expectCase(($dB['pagination']['total'] ?? -1) === 0 && ($dB['data'] ?? [1]) === [] && $relB === [] && $sBv === 404, "usuario SIN informes ($nameB): catálogo y relaciones vacíos (total 0), cualquier visor → 404" . (count($compB) ? '; sus empresas siguen apareciendo' : ''));

echo "== Sesiones: cierre, revocación, caducidad y cambios en Flutter\n";
$t1 = login('syn006'); $t2 = login('syn006');
[$sl, $dl] = api('appusr', 'POST', '/api/logout', $t1);
$row = $st->query("SELECT revocada_en, motivo_revocacion FROM sesiones WHERE token_hash = '" . hash('sha256', $t1) . "'")->fetch();
expectCase($sl === 200 && ($dl['revoked_server_side'] ?? false) === true && $row['revocada_en'] !== null && $row['motivo_revocacion'] === 'cierre_de_sesion' && api('appusr', 'GET', '/api/reportes', $t1)[0] === 401 && api('appusr', 'POST', '/api/logout', $t1)[0] === 401, 'cerrar sesión REVOCA el token en el servidor (queda registrado y deja de valer); repetirlo → 401');
expectCase(api('appusr', 'GET', '/api/reportes', $t2)[0] === 200, 'cerrar una sesión no cierra las otras del mismo usuario');
$st->exec("UPDATE sesiones SET expira_en = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 1 MINUTE) WHERE token_hash = '" . hash('sha256', $t2) . "'");
expectCase(api('appusr', 'GET', '/api/reportes', $t2)[0] === 401, 'una sesión caducada → 401');
$cambios = [
    ['usuario desactivado en Flutter', 'UPDATE user SET is_active = 0 WHERE id = ?', 'UPDATE user SET is_active = 1 WHERE id = ?'],
    ['session_version cambiada (cierre global de Flutter)', 'UPDATE user SET session_version = session_version + 1 WHERE id = ?', 'UPDATE user SET session_version = session_version - 1 WHERE id = ?'],
    ['contraseña cambiada en Flutter', "UPDATE user SET password = CONCAT('X', SUBSTRING(password, 2)) WHERE id = ?", "UPDATE user SET password = CONCAT('$', SUBSTRING(password, 2)) WHERE id = ?"],
    ['rol cambiado a administrador', "UPDATE user SET role = 'admin' WHERE id = ?", "UPDATE user SET role = 'usuario' WHERE id = ?"],
    ['rol desconocido', "UPDATE user SET role = 'superadmin' WHERE id = ?", "UPDATE user SET role = 'usuario' WHERE id = ?"],
];
$idx = 0;
foreach ($cambios as [$label, $do, $undo]) {
    $uidC = $lista[10 + $idx++]; $nameC = one($root, 'SELECT username FROM user WHERE id = ?', [$uidC]); $tC = login($nameC);
    $ok0 = api('appusr', 'GET', '/api/reportes', $tC)[0] === 200;
    $root->prepare($do)->execute([$uidC]); [$s1c] = api('appusr', 'GET', '/api/reportes', $tC); [$s1v] = api('appusr', 'GET', '/api/reportes/id/' . $allActive[0] . '/visor', $tC);
    $root->prepare($undo)->execute([$uidC]); [$s2c] = api('appusr', 'GET', '/api/reportes', $tC); $nuevo = login($nameC);
    expectCase($ok0 && $s1c === 401 && $s1v === 401 && $s2c === 401 && $nuevo !== '', "$label: la sesión se invalida de inmediato (401) y NO revive al revertir el cambio; una nueva sesión sí es posible");
}
$uidW = $lista[20]; $nameW = one($root, 'SELECT username FROM user WHERE id = ?', [$uidW]); $tW = login($nameW);
$st->prepare('UPDATE usuarios_piloto SET activo = 0 WHERE user_id = ?')->execute([$uidW]); [$sW] = api('appusr', 'GET', '/api/reportes', $tW); $nW = login($nameW);
$st->prepare('UPDATE usuarios_piloto SET activo = 1 WHERE user_id = ?')->execute([$uidW]);
expectCase($sW === 401 && $nW === '' && login($nameW) !== '', 'quitar a un usuario de la lista blanca invalida su sesión y le impide entrar; al volver a agregarlo, entra');

echo "== Administración por CLI (solo escribe en la base de estado)\n";
$uidX = $lista[30]; $nameX = one($root, 'SELECT username FROM user WHERE id = ?', [$uidX]); $tX = login($nameX);
[$c1, $o1] = cli(['revocar', (string)$uidX]);
expectCase($c1 === 0 && api('appusr', 'GET', '/api/reportes', $tX)[0] === 401 && login($nameX) !== '', 'CLI revocar <id>: cierra sus sesiones al instante; puede volver a entrar');
[$c2, $o2] = cli(['quitar', (string)$uidX]); $deniedAfter = login($nameX);
[$c3, $o3] = cli(['agregar', (string)$uidX, 'prueba']);
expectCase($c2 === 0 && $deniedAfter === '' && $c3 === 0 && login($nameX) !== '', 'CLI quitar impide entrar y revoca; CLI agregar lo vuelve a autorizar');
[$c4, $o4] = cli(['agregar', '1']); [$c5, $o5] = cli(['agregar', '66']); [$c6] = cli(['agregar', '99999']); [$c7] = cli(['agregar', 'abc']); [$c8] = cli(['x']);
expectCase($c4 !== 0 && $c5 !== 0 && $c6 !== 0 && $c7 !== 0 && $c8 !== 0 && str_contains($o4, 'administradores') && str_contains($o5, 'inactivo'), 'CLI agregar rechaza administradores, usuarios inactivos, inexistentes, ids inválidos y comandos desconocidos');
[$c9, $o9] = cli(['listar']); [$c10, $o10] = cli(['auditoria']); [$c11, $o11] = cli(['sesiones']);
expectCase($c9 === 0 && $c10 === 0 && $c11 === 0 && !preg_match('/\$2y\$|synthetic-password|https?:/i', $o9 . $o10 . $o11) && str_contains($o10, 'login_ok'), 'CLI listar/auditoría/sesiones no imprimen contraseñas, hashes ni enlaces');
[$c12] = cli(['revocar', 'todos']);
expectCase($c12 === 0 && api('appusr', 'GET', '/api/reportes', $tokens['syn005'])[0] === 401, 'CLI revocar todos cierra todas las sesiones');
$tokens['syn005'] = login('syn005');

echo "== Bloqueo por intentos de acceso\n";
for ($i = 1; $i <= 5; $i++) api('appusrthr', 'POST', '/api/login', null, ['login' => 'syn007', 'password' => 'mala' . $i]);
[$sLock, $dLock] = api('appusrthr', 'POST', '/api/login', null, ['login' => 'syn007', 'password' => 'synthetic-password']); $retry = header_of('Retry-After');
expectCase($sLock === 429 && ($dLock['code'] ?? '') === 'bloqueado' && $retry !== null && (int)$retry > 0 && !isset($dLock['token']), 'tras 5 fallos esa cuenta queda bloqueada (429 + Retry-After) aunque use la clave correcta');
expectCase(login('syn008', 'appusrthr') !== '', 'el bloqueo es por cuenta: otro usuario sigue entrando');
$locks = (int)one($st, 'SELECT COUNT(*) FROM intentos_login WHERE bloqueado_hasta > UNIX_TIMESTAMP()');
expectCase($locks >= 1 && (int)one($st, "SELECT COUNT(*) FROM intentos_login WHERE LENGTH(clave_hash) = 64 AND clave_hash NOT REGEXP '^[0-9a-f]{64}$'") === 0, 'los intentos se guardan solo como huellas HMAC (sin nombres ni direcciones)');
for ($i = 1; $i <= 20; $i++) api('appusrthr', 'POST', '/api/login', null, ['login' => "inexistente$i", 'password' => 'x']);
[$sIp] = api('appusrthr', 'POST', '/api/login', null, ['login' => 'syn009', 'password' => 'synthetic-password']);
expectCase($sIp === 429, 'tras 20 fallos desde una misma dirección se bloquea toda la dirección (429), aunque la cuenta sea válida');
$st->exec('DELETE FROM intentos_login');

echo "== Rutas cerradas y ausencia de escrituras\n";
$tS = $tokens['syn005'];
$denied = [['GET', '/api/users'], ['GET', '/api/user'], ['GET', '/api/stats'], ['GET', '/api/empresa-usuario'], ['GET', '/api/empresa-reporte-usuario'], ['GET', '/api/powerbi/tokens/1'], ['GET', '/api/wiki'], ['GET', '/api/reportes/activos'], ['GET', '/api/empresas/1'], ['GET', '/api/asignacion/1'], ['GET', '/api/reportes/'], ['GET', '/api/ping/'],
    ['POST', '/api/register'], ['POST', '/api/reportes'], ['PUT', '/api/reportes/1'], ['DELETE', '/api/reportes/1'], ['POST', '/api/empresas'], ['PUT', '/api/empresas/1'], ['POST', '/api/asignacion'], ['DELETE', '/api/asignacion/1'], ['PUT', '/api/user/profile'], ['PUT', '/api/user/5/role'], ['POST', '/api/user/avatar'], ['POST', '/api/empresa-usuario'], ['POST', '/api/empresa-reporte-usuario'], ['PATCH', '/api/reportes'], ['OPTIONS', '/api/reportes']];
$bad = []; foreach ($denied as [$m, $p]) { [$s] = api('appusr', $m, $p, $tS, in_array($m, ['POST', 'PUT', 'PATCH']) ? [] : null); if ($s !== 403) $bad[] = "$m $p→$s"; }
expectCase($bad === [], count($denied) . ' rutas/métodos (usuarios, estadísticas, administración, escritura, Power BI heredado, variantes) → 403 con sesión válida' . ($bad ? ' · ' . implode(' | ', $bad) : ''));
expectCase(sums($root) === $sumsBefore && $root->query('SELECT id, last_login, session_version FROM user ORDER BY id')->fetchAll() === $lastLoginBefore, 'tras decenas de inicios de sesión, cierres y revocaciones, las seis tablas de Flutter siguen idénticas bit a bit: last_login y session_version NO se tocan');
$tok0 = login('syn005', 'appusr0'); [$sV0] = api('appusr0', 'GET', '/api/reportes/id/' . ($root->query('SELECT DISTINCT r.id ' . CHAIN . ' AND uer.user_id = 5 ORDER BY r.id LIMIT 1')->fetchColumn()) . '/visor', $tok0); $c0 = walk('appusr0', '/api/reportes', $tok0);
expectCase($sV0 === 403 && count($c0) > 0, 'con ningún informe autorizado para el piloto (valor predeterminado seguro) el catálogo propio se ve y NINGÚN visor entrega enlaces (403 «visor_no_autorizado»)');

echo "== Estado propio: auditoría y ausencia de datos sensibles\n";
$eventos = array_column($st->query('SELECT DISTINCT evento FROM auditoria')->fetchAll(), 'evento');
foreach (['login_ok', 'login_fallido_clave', 'login_fallido_lista_blanca', 'login_fallido_inactivo', 'login_fallido_rol', 'logout', 'visor_abierto', 'visor_denegado', 'sesion_invalidada_session_version', 'sesion_invalidada_clave_cambiada', 'login_bloqueado', 'lista_blanca_agregado'] as $e) if (!in_array($e, $eventos, true)) $faltan[] = $e;
expectCase(empty($faltan), 'la auditoría registra inicios, fallos (con motivo interno), cierres, visores abiertos/denegados, sesiones invalidadas, bloqueos y cambios de lista' . (!empty($faltan) ? ' · FALTAN: ' . implode(', ', $faltan) : ''));
$dump = ''; foreach (['usuarios_piloto', 'sesiones', 'intentos_login', 'auditoria'] as $tbl) foreach ($st->query("SELECT * FROM $tbl")->fetchAll() as $r) $dump .= json_encode($r) . "\n";
expectCase(!preg_match('/\$2y\$|synthetic-password|powerbi|https?:|syn0\d\d|@example|enlace/i', $dump) && !preg_match('/\b\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', $dump) && (int)one($st, 'SELECT COUNT(*) FROM sesiones WHERE LENGTH(token_hash) <> 64') === 0, 'la base de estado NO contiene contraseñas, hashes, enlaces, nombres de usuario ni direcciones IP en claro (solo ids y huellas)');
expectCase(!preg_match(SENSITIVE, $GLOBALS['bodies']) && !str_contains($GLOBALS['bodies'], 'synthetic-password'), 'ninguna respuesta de toda la prueba contiene hashes, la clave «password», credenciales de Power BI, SQL, trazas ni rutas internas');
$sess = (string)$st->query('SELECT token_hash FROM sesiones LIMIT 1')->fetchColumn();
expectCase(!in_array($sess, $tokens, true) && strlen($sess) === 64, 'los tokens se guardan solo como SHA-256: ni la base de estado ni la auditoría permiten reconstruir un token válido');
expectCase(str_contains((string)header_of('Cache-Control'), 'no-store'), 'respuestas con Cache-Control no-store');

echo $failures === 0 ? "\nAll user-pilot cases passed\n" : "\n$failures case(s) failed\n";
exit($failures === 0 ? 0 : 1);
