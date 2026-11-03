<?php
// UX-07D.4A · Matriz de permisos de informes contra el esquema productivo SINTÉTICO (fixture_prod: 76 usuarios, 26 empresas, 95 informes/82 activos, 102 asignaciones
// individuales, 91 permisos efectivos), usando la instancia de SOLO LECTURA (`appro`, cuenta cervesur_ro_pilot con SELECT por columna). La API no puede escribir:
// los cambios de estado de la prueba los hace `root` directamente en la base desechable y se restauran. Solo red Docker aislada y datos 100 % ficticios.
// Comprueba: (1) la API entrega a CADA usuario exactamente los informes del permiso efectivo calculado en SQL; (2) cada eslabón de la cadena, desactivado por separado,
// corta el acceso (listado, detalle y visor directo); (3) usuarios inactivos, roles históricos y administradores; (4) ningún enlace ni credencial fuera del visor autorizado;
// (5) referencias históricas intactas; (6) las seis tablas quedan idénticas.
$failures = 0;
$root = new PDO('mysql:host=db;dbname=fixture_prod;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function api($method, $path, $token = null) {
    $headers = ['Content-Type: application/json']; if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
    $raw = @file_get_contents('http://appro:8080' . $path, false, stream_context_create(['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 15]]));
    $GLOBALS['last'] = substr((string)$raw, 0, 300);
    $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int)$m[1] : 0;
    return [$status, json_decode((string)$raw, true), (string)$raw];
}
function login($name) { $o = ['http' => ['method' => 'POST', 'header' => 'Content-Type: application/json', 'content' => json_encode(['login' => $name, 'password' => 'synthetic-password']), 'ignore_errors' => true, 'timeout' => 15]]; $r = @file_get_contents('http://appro:8080/api/login', false, stream_context_create($o)); $d = json_decode((string)$r, true); return $d['token'] ?? ''; }
function expectCase($c, $label) { global $failures; if ($c) echo "PASS $label\n"; else { echo "FAIL $label\n  última respuesta: " . ($GLOBALS['last'] ?? '') . "\n"; $failures++; } }
function q($pdo, $sql, $p = []) { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchAll(); }
function one($pdo, $sql, $p = []) { $st = $pdo->prepare($sql); $st->execute($p); return $st->fetchColumn(); }
function listIds($token, $view = 'minimal') { // todas las páginas del catálogo
    $all = []; $page = 1;
    do { [, $d] = api('GET', "/api/reportes?view=$view&per_page=100&page=$page", $token); $chunk = $d['data']['data'] ?? $d['data'] ?? []; foreach ($chunk as $r) $all[] = (int)$r['id']; $more = !empty($d['pagination']['has_more']); $page++; } while ($more && $page < 30);
    sort($all); return $all;
}
function checksums($root) { $o = []; foreach ($root->query('CHECKSUM TABLE user, empresa, reporte, empresa_usuario, empresa_reporte, user_empresa_reporte') as $r) $o[$r['Table']] = $r['Checksum']; return $o; }
const CHAIN = 'FROM user_empresa_reporte uer JOIN user u ON u.id = uer.user_id AND u.is_active = 1 JOIN empresa_reporte er ON er.id = uer.empresa_reporte_id AND er.activo = 1 JOIN empresa e ON e.id = er.empresa_id AND e.activo = 1 JOIN empresa_usuario eu ON eu.empresa_id = e.id AND eu.user_id = u.id AND eu.activo = 1 JOIN reporte r ON r.id = er.reporte_id AND r.activo = 1 WHERE uer.activo = 1';

$sumsBefore = checksums($root);

echo "== 1. Compatibilidad de datos (inventario sintético)\n";
expectCase((int)one($root, 'SELECT COUNT(*) FROM user') === 76 && (int)one($root, 'SELECT COUNT(*) FROM empresa') === 26 && (int)one($root, 'SELECT COUNT(*) FROM reporte') === 95 && (int)one($root, 'SELECT COUNT(*) FROM reporte WHERE activo = 1') === 82, '76 usuarios, 26 empresas, 95 informes (82 activos)');
expectCase((int)one($root, 'SELECT COUNT(*) FROM user_empresa_reporte') === 102 && (int)one($root, 'SELECT COUNT(*) ' . CHAIN) === 91, '102 asignaciones individuales y 91 permisos efectivos (cadena completa activa)');
$rowsPerUserReport = q($root, 'SELECT u.id uid, r.id rid, COUNT(*) n ' . CHAIN . ' GROUP BY u.id, r.id');
expectCase(count($rowsPerUserReport) === 91 && array_sum(array_column($rowsPerUserReport, 'n')) === 91, 'los 91 permisos efectivos corresponden a ' . count($rowsPerUserReport) . ' pares usuario–informe distintos (una sola cadena por par en el inventario sintético)');
expectCase((int)one($root, 'SELECT COUNT(*) FROM reporte WHERE CHAR_LENGTH(reporte_id) > 36') === 57 && (int)one($root, "SELECT COUNT(*) FROM reporte WHERE reporte_id REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\$'") === 3, 'referencias históricas: 57 de más de 36 caracteres y solo 3 UUID canónicos (todos inactivos)');

echo "== 2. La API entrega a CADA usuario exactamente su permiso efectivo\n";
$users = q($root, 'SELECT id, username, role, is_active FROM user ORDER BY id');
$admins = array_filter($users, fn ($u) => strtolower($u['role']) === 'admin' && (int)$u['is_active'] === 1);
$mismatch = []; $visorFail = []; $negFail = []; $inactiveLogged = []; $withAccess = 0; $pairs = 0; $negs = 0; $tokens = [];
$allActive = array_map('intval', array_column(q($root, 'SELECT id FROM reporte WHERE activo = 1 ORDER BY id'), 'id'));
foreach ($users as $u) {
    $token = login($u['username']);
    if ((int)$u['is_active'] !== 1) { if ($token !== '') $inactiveLogged[] = $u['username']; continue; }
    $tokens[$u['username']] = $token;
    $isAdmin = strtolower($u['role']) === 'admin';
    $expected = $isAdmin ? $allActive : array_map('intval', array_column(q($root, 'SELECT DISTINCT r.id ' . CHAIN . ' AND u.id = ? ORDER BY r.id', [$u['id']]), 'id'));
    $got = listIds($token);
    if ($got !== $expected) $mismatch[] = $u['username'] . ' esperado ' . count($expected) . ' obtenido ' . count($got);
    if (!$isAdmin && $expected) $withAccess++;
    foreach ($expected as $rid) { $pairs++; [$st, $d] = api('GET', "/api/reportes/id/$rid/visor", $token); if ($st !== 200 || ($d['data']['id'] ?? 0) !== $rid) $visorFail[] = $u['username'] . "#$rid→$st"; }
    $others = array_slice(array_values(array_diff($allActive, $expected)), 0, 2);
    foreach ($others as $rid) { $negs++; [$st, , $raw] = api('GET', "/api/reportes/id/$rid/visor", $token); if ($st !== 404 || str_contains($raw, '"url"')) $negFail[] = $u['username'] . "#$rid→$st"; [$st2] = api('GET', "/api/reportes/$rid", $token); if ($st2 !== 404) $negFail[] = $u['username'] . "#$rid detalle→$st2"; }
}
expectCase($mismatch === [] && count($tokens) === 62, 'catálogo de los 62 usuarios activos = permiso efectivo calculado en SQL (administradores: los 82 activos)' . ($mismatch ? ' · ' . implode(' | ', array_slice($mismatch, 0, 5)) : ''));
expectCase($withAccess === 61, 'exactamente 61 usuarios no administradores tienen al menos un informe (' . $withAccess . ')');
expectCase($visorFail === [] && $pairs > 90, "visor directo: los $pairs informes de los catálogos se abren (200, id numérico)" . ($visorFail ? ' · ' . implode(', ', array_slice($visorFail, 0, 5)) : ''));
expectCase($negFail === [] && $negs > 100, "acceso directo a informes NO asignados ($negs casos, por visor y por detalle): 404 sin enlace" . ($negFail ? ' · ' . implode(', ', array_slice($negFail, 0, 5)) : ''));
expectCase($inactiveLogged === [] && count($users) - count($tokens) === 14, 'los 14 usuarios inactivos (aunque tengan asignaciones activas) no pueden iniciar sesión' . ($inactiveLogged ? ' · ' . implode(', ', $inactiveLogged) : ''));
$firstAdmin = array_values($admins)[0]['username'];
expectCase(count(listIds($tokens[$firstAdmin])) === 82, 'el administrador ve los 82 informes activos y ninguno inactivo');

echo "== 3. Cada eslabón de la cadena, desactivado por separado, corta el acceso\n";
$chains = q($root, 'SELECT u.id uid, u.username, r.id rid, uer.id uer_id, er.id er_id, e.id eid, eu.id eu_id ' . CHAIN . ' AND u.role = \'usuario\' ORDER BY u.id, r.id');
$count = []; foreach ($chains as $c) { $k = $c['uid'] . '-' . $c['rid']; $count[$k] = ($count[$k] ?? 0) + 1; }
$A = null; foreach ($chains as $c) { if ($count[$c['uid'] . '-' . $c['rid']] === 1) { $A = $c; break; } }
expectCase($A !== null, 'hay un usuario con un informe alcanzable por UNA sola cadena de relaciones (permite probar cada eslabón por separado)'); $tA = $tokens[$A['username']];
$links = [
    ['asignación individual (user_empresa_reporte.activo)', 'user_empresa_reporte', 'activo', 'id', $A['uer_id']],
    ['relación empresa–informe (empresa_reporte.activo)', 'empresa_reporte', 'activo', 'id', $A['er_id']],
    ['empresa (empresa.activo)', 'empresa', 'activo', 'id', $A['eid']],
    ['vínculo usuario–empresa (empresa_usuario.activo)', 'empresa_usuario', 'activo', 'id', $A['eu_id']],
    ['informe (reporte.activo)', 'reporte', 'activo', 'id', $A['rid']],
];
foreach ($links as [$label, $table, $col, $key, $id]) {
    $root->prepare("UPDATE `$table` SET `$col` = 0 WHERE `$key` = ?")->execute([$id]);
    [$stV, , $rawV] = api('GET', "/api/reportes/id/{$A['rid']}/visor", $tA); [$stD] = api('GET', "/api/reportes/{$A['rid']}", $tA);
    $inList = in_array((int)$A['rid'], listIds($tA), true);
    $root->prepare("UPDATE `$table` SET `$col` = 1 WHERE `$key` = ?")->execute([$id]);
    expectCase($stV === 404 && $stD === 404 && !$inList && !str_contains($rawV, '"url"'), "$label inactivo: visor 404, detalle 404, fuera del catálogo y sin enlace");
}
$root->prepare('UPDATE user SET is_active = 0 WHERE id = ?')->execute([$A['uid']]);
[$stU] = api('GET', "/api/reportes/id/{$A['rid']}/visor", $tA); [$stL] = api('GET', '/api/reportes?view=minimal', $tA);
$root->prepare('UPDATE user SET is_active = 1 WHERE id = ?')->execute([$A['uid']]);
expectCase($stU === 401 && $stL === 401, 'usuario desactivado (user.is_active) con token vigente: 401 en visor y catálogo');
[$stOk] = api('GET', "/api/reportes/id/{$A['rid']}/visor", $tA);
expectCase($stOk === 200 && listIds($tA) === array_map('intval', array_column(q($root, 'SELECT DISTINCT r.id ' . CHAIN . ' AND u.id = ? ORDER BY r.id', [$A['uid']]), 'id')), 'al restituir todos los eslabones el acceso vuelve exactamente a lo anterior (control)');
// revocación de la sesión: session_version distinto al del token (lo que haría un cierre de sesión de Flutter/React en modo de escritura)
$root->prepare('UPDATE user SET session_version = session_version + 1 WHERE id = ?')->execute([$A['uid']]);
[$stSv] = api('GET', "/api/reportes/id/{$A['rid']}/visor", $tA);
$root->prepare('UPDATE user SET session_version = session_version - 1 WHERE id = ?')->execute([$A['uid']]);
expectCase($stSv === 401, 'si cambia session_version (cierre global de sesiones) el token anterior deja de valer (401)');

echo "== 4. Roles\n";
$normal = $tokens[$A['username']];
[$stUsers] = api('GET', '/api/users?per_page=5', $normal); [$stUsersAdm] = api('GET', '/api/users?per_page=5', $tokens[$firstAdmin]);
expectCase($stUsersAdm === 200 && $stUsers !== 200, 'el administrador lista usuarios; el rol «usuario» no (' . $stUsers . ')');
[$stEmp, $dEmp] = api('GET', '/api/empresas?per_page=100', $normal); [, $dEmpAdm] = api('GET', '/api/empresas?per_page=100', $tokens[$firstAdmin]);
$nEmp = count($dEmp['data']['data'] ?? $dEmp['data'] ?? []); $nEmpAdm = count($dEmpAdm['data']['data'] ?? $dEmpAdm['data'] ?? []);
expectCase($stEmp === 200 && $nEmp < $nEmpAdm, "el listado de empresas del usuario ($nEmp) es menor que el del administrador ($nEmpAdm): solo sus empresas");
// rol histórico «user» (activo en producción solo para 'usuario'; los 10 'user' del inventario están inactivos): se activa temporalmente uno y se comprueba que es un alias sin privilegios
$legacy = q($root, "SELECT id, username FROM user WHERE role = 'user' ORDER BY id LIMIT 1")[0];
$root->prepare('UPDATE user SET is_active = 1 WHERE id = ?')->execute([$legacy['id']]);
$tL = login($legacy['username']); [$stLegUsers] = api('GET', '/api/users?per_page=5', $tL); $legIds = listIds($tL); $legExpected = array_map('intval', array_column(q($root, 'SELECT DISTINCT r.id ' . CHAIN . ' AND u.id = ? ORDER BY r.id', [$legacy['id']]), 'id'));
$root->prepare('UPDATE user SET is_active = 0 WHERE id = ?')->execute([$legacy['id']]);
expectCase($tL !== '' && $stLegUsers !== 200 && $legIds === $legExpected && count($legIds) < 82, 'rol histórico «user»: inicia sesión si está activo, NO es administrador (no lista usuarios) y ve solo su permiso efectivo (' . count($legIds) . ' informe(s), el calculado en SQL)');
$root->prepare("UPDATE user SET role = 'superadmin' WHERE id = ?")->execute([$A['uid']]);
[$stRole] = api('GET', '/api/users?per_page=5', $tA); [$stRoleV] = api('GET', "/api/reportes/id/{$A['rid']}/visor", $tA);
$root->prepare("UPDATE user SET role = 'usuario' WHERE id = ?")->execute([$A['uid']]);
expectCase($stRole !== 200, 'un rol no reconocido no concede privilegios de administrador (' . $stRole . ')');

echo "== 5. Ninguna exposición accidental de enlaces ni credenciales\n";
$links = array_column(q($root, 'SELECT enlace FROM reporte'), 'enlace');
$exposure = []; $targets = ['/api/reportes?view=minimal&per_page=100', '/api/reportes?per_page=100', '/api/reportes/activos', '/api/empresas?per_page=100', '/api/asignacion?view=minimal&per_page=100', '/api/empresa-reporte-usuario?view=minimal&per_page=100', '/api/user/profile', '/api/stats', "/api/reportes/{$A['rid']}", "/api/reportes/{$A['rid']}?view=form"];
foreach ($targets as $path) { [, , $raw] = api('GET', $path, $normal); foreach ($links as $l) { if ($l !== '' && str_contains($raw, $l)) { $exposure[] = $path; break; } } if (preg_match('/\$2y\$|"password"|powerbi_(tenant|client|clientsecret)|SQLSTATE/i', $raw)) $exposure[] = "$path (credencial)"; }
expectCase($exposure === [], 'ninguna lectura de un usuario normal contiene un enlace de Power BI (solo el visor autorizado lo entrega) ni credenciales' . ($exposure ? ' · ' . implode(', ', array_unique($exposure)) : ''));
[$stV, $dV, $rawV] = api('GET', "/api/reportes/id/{$A['rid']}/visor", $tA);
expectCase($stV === 200 && array_keys($dV['data']) === array_values(array_intersect(['id', 'reporte_id', 'nombre', 'descripcion', 'modo', 'url'], array_keys($dV['data']))) && !preg_match('/\$2y\$|password|created_by|updated_by/i', $rawV), 'el visor devuelve solo id, reporte_id, nombre, descripcion, modo y url');
[$stAdmin, , $rawAdmin] = api('GET', '/api/reportes?per_page=100', $tokens[$firstAdmin]);
echo 'INFO el catálogo ' . (str_contains($rawAdmin, 'enlace') ? 'completo del administrador SÍ incluye el campo enlace (necesario para editar informes)' : 'del administrador no incluye enlace') . "\n";
$noModo = q($root, "SELECT id FROM reporte WHERE activo = 1 AND enlace NOT LIKE 'https://app.powerbi.com/view?r=%' LIMIT 3");
echo 'INFO informes activos con enlace que no es «Publicar en web» (modo privado/no compatible): ' . count($noModo) . "\n";

echo "== 6. Referencias históricas y ausencia de escrituras\n";
$long = q($root, 'SELECT r.id, r.reporte_id ' . CHAIN . ' AND CHAR_LENGTH(r.reporte_id) = 50 LIMIT 1')[0] ?? null;
[$stH, $dH] = api('GET', "/api/reportes/id/{$long['id']}/visor", $tokens[$firstAdmin]);
expectCase($long !== null && $stH === 200 && $dH['data']['reporte_id'] === $long['reporte_id'] && strlen($dH['data']['reporte_id']) === 50, 'la referencia histórica de 50 caracteres se conserva intacta en el visor');
foreach (['POST' => '/api/reportes', 'PUT' => "/api/reportes/{$A['rid']}", 'DELETE' => "/api/empresa-reporte-usuario/{$A['uer_id']}"] as $m => $p) { [$stW] = api($m, $p, $tokens[$firstAdmin]); if ($stW !== 403) { expectCase(false, "$m $p debe ser 403 (solo lectura)"); } }
expectCase(checksums($root) === $sumsBefore, 'las seis tablas quedan idénticas bit a bit tras toda la matriz (la API no escribió; los cambios de estado se restauraron)');
echo $failures === 0 ? "\nAll permission-matrix cases passed\n" : "\n$failures case(s) failed\n";
exit($failures === 0 ? 0 : 1);
