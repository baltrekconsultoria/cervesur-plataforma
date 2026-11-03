<?php
// UX-07D.4 express · Piloto visual: catálogo real (aquí SINTÉTICO) de empresas e informes para UN operador autorizado, con la cuenta cervesur_ro_visor (15 columnas).
// Instancias (red Docker aislada, base desechable fixture_prod, datos 100 % ficticios):
//   appvis   : piloto, visor autorizado solo para dos informes   appvis0 : visor sin ningún informe autorizado   appvisall : visor «TODOS»
//   appvthr  : instancia aparte para la limitación de intentos   appvbad1/2 : configuraciones inválidas (fail-closed)
// Entorno: TEST_DB_PASS, PILOT_PASS (clave del operador), PILOT_IDS (ids autorizados en appvis: «a,b»).
$failures = 0;
$root = new PDO('mysql:host=db;dbname=fixture_prod;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function api($host, $method, $path, $token = null, $body = null) {
    $headers = ['Content-Type: application/json']; if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
    $o = ['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 20]];
    if ($body !== null) $o['http']['content'] = json_encode($body);
    $raw = @file_get_contents("http://$host:8080$path", false, stream_context_create($o));
    $GLOBALS['last'] = substr((string)$raw, 0, 300); $GLOBALS['hdrs'] = $http_response_header ?? [];
    $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int)$m[1] : 0;
    return [$status, json_decode((string)$raw, true), (string)$raw];
}
function expectCase($c, $label) { global $failures; if ($c) echo "PASS $label\n"; else { echo "FAIL $label\n  última respuesta: " . ($GLOBALS['last'] ?? '') . "\n"; $failures++; } }
function loginOp($host, $pass = null, $user = 'operador') { [, $d] = api($host, 'POST', '/api/login', null, ['login' => $user, 'password' => $pass ?? getenv('PILOT_PASS')]); return $d['token'] ?? ''; }
function walk($host, $path, $token) { $all = []; $page = 1; do { [, $d] = api($host, 'GET', "$path?view=minimal&per_page=100&page=$page", $token); $all = array_merge($all, $d['data'] ?? []); $more = !empty($d['pagination']['has_more']); $page++; } while ($more && $page < 20); return $all; }
function sums($root) { $o = []; foreach ($root->query('CHECKSUM TABLE user, empresa, reporte, empresa_usuario, empresa_reporte, user_empresa_reporte') as $r) $o[$r['Table']] = $r['Checksum']; return $o; }
function header_of($name) { foreach ($GLOBALS['hdrs'] as $h) if (stripos($h, $name . ':') === 0) return trim(substr($h, strlen($name) + 1)); return null; }
const SENSITIVE = '/\$2y\$|"password"|powerbi_(tenant|client|clientsecret)|SQLSTATE|PDOException|cervesur_ro_|Stack trace|\/var\/www|\/work\//i';
$ids = array_map('intval', explode(',', (string)getenv('PILOT_IDS')));
$sumsBefore = sums($root);
$lastLoginBefore = $root->query('SELECT id, last_login, session_version FROM user ORDER BY id')->fetchAll();

echo "== Fail-closed del arranque\n";
foreach ([['appvbad1', 'sin CERVESUR_PILOT_OPERATOR_HASH'], ['appvbad2', 'sin CERVESUR_MODE aunque se exige piloto_visual']] as [$h, $label]) {
    [$st, $d, $raw] = api($h, 'GET', '/api/ping');
    expectCase($st === 503 && ($d['code'] ?? '') === 'mode_config' && !preg_match(SENSITIVE, $raw), "$label: 503 genérico, sin detalles");
}

echo "== Autenticación independiente del operador (sin usuarios productivos)\n";
$catalogPaths = ['/api/user/profile', '/api/reportes', '/api/reportes/1', '/api/reportes/id/1/visor', '/api/empresas', '/api/asignacion'];
$all401 = true; foreach ($catalogPaths as $p) { [$st, , $raw] = api('appvis', 'GET', $p); if ($st !== 401 || str_contains($raw, '"data"')) $all401 = false; }
expectCase($all401, 'NINGÚN endpoint del catálogo responde sin sesión (401 y ningún dato): perfil, informes, detalle, visor, empresas, relaciones');
expectCase(api('appvis', 'GET', '/api/reportes', 'abc.def.ghi')[0] === 401 && api('appvis', 'GET', '/api/reportes', 'x')[0] === 401, 'un token inventado o malformado → 401');
[, $dBad] = api('appvis', 'POST', '/api/login', null, ['login' => 'operador', 'password' => 'incorrecta']);
[, $dUser] = api('appvis', 'POST', '/api/login', null, ['login' => 'syn001', 'password' => 'synthetic-password']);
[, $dEmail] = api('appvis', 'POST', '/api/login', null, ['login' => 'syn005@example.invalid', 'password' => 'synthetic-password']);
expectCase(($dBad['success'] ?? true) === false && ($dUser['success'] ?? true) === false && ($dEmail['success'] ?? true) === false && !isset($dUser['token']), 'contraseña incorrecta, y credenciales de usuarios del sistema (syn001, correo de empleado) → rechazadas: no se simula ni se acepta ninguna identidad productiva');
$t0 = microtime(true); api('appvis', 'POST', '/api/login', null, ['login' => 'nadie', 'password' => 'x']); $elapsed = microtime(true) - $t0;
expectCase($elapsed >= 0.4, 'cada fallo cuesta ≥ 0,4 s (freno)');
$tok = loginOp('appvis');
[$stP, $dP] = api('appvis', 'GET', '/api/user/profile', $tok);
expectCase($tok !== '' && $stP === 200 && $dP['profile']['role'] === 'user' && $dP['profile']['username'] === 'operador' && !array_key_exists('password', $dP['profile']), 'el operador entra con su clave propia; el perfil es sintético (rol «user» para la interfaz, sin datos de ningún usuario real)');
$payload = json_decode(base64_decode(strtr(explode('.', $tok)[1], '-_', '+/')), true);
expectCase(($payload['pilot'] ?? '') === 'visual' && $payload['exp'] - time() <= 28800 && $payload['exp'] - time() > 60, 'el token es de piloto, de corta duración (≤ 8 h) y no contiene claves');
$other = loginOp('appvis0'); // otra instancia: secreto JWT distinto
expectCase(api('appvis', 'GET', '/api/reportes', $other)[0] === 401, 'el token de otro sitio (otro secreto JWT) no sirve');
$parts = explode('.', $tok); $tampered = $parts[0] . '.' . rtrim(strtr(base64_encode(json_encode(['user_id' => 1, 'username' => 'operador', 'email' => 'operador@piloto.invalid', 'session_version' => 1, 'pilot' => 'visual', 'exp' => time() + 99999])), '+/', '-_'), '=') . '.' . $parts[2];
expectCase(api('appvis', 'GET', '/api/reportes', $tampered)[0] === 401, 'un token con el cuerpo alterado (exp ampliada) → 401');
[$stLo, $dLo] = api('appvis', 'POST', '/api/logout', $tok);
expectCase($stLo === 200 && ($dLo['success'] ?? false) === true, 'cerrar sesión responde 200 (sin escribir en MySQL)');

echo "== Limitación de intentos\n";
for ($i = 1; $i <= 5; $i++) api('appvthr', 'POST', '/api/login', null, ['login' => 'operador', 'password' => 'mal' . $i]);
[$stLock, $dLock] = api('appvthr', 'POST', '/api/login', null, ['login' => 'operador', 'password' => getenv('PILOT_PASS')]);
expectCase($stLock === 429 && ($dLock['code'] ?? '') === 'bloqueado' && header_of('Retry-After') !== null && !isset($dLock['token']), 'tras 5 fallos el operador queda bloqueado 15 min (429 + Retry-After) aunque use la clave correcta');

echo "== Catálogo real (aquí sintético): columnas mínimas, sin enlaces\n";
$reports = walk('appvis', '/api/reportes', $tok); $companies = walk('appvis', '/api/empresas', $tok); $relations = walk('appvis', '/api/asignacion', $tok);
$sqlRel = (int)$root->query('SELECT COUNT(*) FROM empresa_reporte er JOIN empresa e ON e.id = er.empresa_id AND e.activo = 1 JOIN reporte r ON r.id = er.reporte_id AND r.activo = 1 WHERE er.activo = 1')->fetchColumn();
expectCase(count($reports) === 82 && count($companies) === 6 && count($relations) === $sqlRel, '82 informes activos, 6 empresas activas y ' . $sqlRel . ' relaciones vigentes (= SQL); ningún inactivo');
expectCase(array_column($reports, 'id') === array_map('intval', array_column($root->query('SELECT id FROM reporte WHERE activo = 1 ORDER BY id')->fetchAll(), 'id')), 'los informes se listan por id numérico, completos y ordenados');
$keys = fn ($rows) => array_values(array_unique(array_map(fn ($r) => implode(',', array_keys($r)), $rows)));
expectCase($keys($reports) === ['id,reporte_id,nombre,descripcion,activo'] && $keys($companies) === ['id,nombre,ciudad,ruc,activo'] && $keys($relations) === ['id,empresa_id,reporte_id,activo'], 'cada listado devuelve exactamente las columnas del catálogo (informes: sin enlace)');
expectCase(array_sum(array_map(fn ($r) => is_int($r['reporte_id']) ? 1 : 0, $relations)) === count($relations) && array_sum(array_map(fn ($r) => is_string($r['reporte_id']) ? 1 : 0, $reports)) === count($reports), 'tipos correctos: en relaciones reporte_id es el id numérico; en informes es la referencia histórica (texto)');
$allBodies = '';
foreach (['/api/reportes?per_page=100', '/api/reportes?view=minimal&per_page=100&page=1', '/api/empresas?per_page=100', '/api/asignacion?per_page=100', '/api/reportes/' . $ids[0], '/api/user/profile'] as $p) { [, , $raw] = api('appvis', 'GET', $p, $tok); $allBodies .= $raw; }
$links = array_column($root->query('SELECT enlace FROM reporte')->fetchAll(), 'enlace');
$leak = false; foreach ($links as $l) if ($l !== '' && str_contains($allBodies, $l)) $leak = true;
expectCase(!$leak && !preg_match(SENSITIVE, $allBodies) && !str_contains($allBodies, 'enlace'), 'ningún listado ni detalle contiene un enlace de Power BI (ni la palabra «enlace»), hashes ni credenciales');
expectCase(api('appvis', 'GET', '/api/reportes?view=form', $tok)[0] === 400 && api('appvis', 'GET', '/api/reportes?per_page=1000', $tok)[0] === 400, 'view=form y per_page fuera de rango → 400 (no hay vista de edición con enlace)');
[$stD, $dD] = api('appvis', 'GET', '/api/reportes/' . $ids[0] . '?view=form', $tok);
expectCase($stD === 200 && !isset($dD['data']['enlace']) && array_keys($dD['data']) === ['id', 'reporte_id', 'nombre', 'descripcion', 'activo'], 'el detalle ignora cualquier vista: siempre mínimo y sin enlace');
expectCase(api('appvis', 'GET', '/api/reportes/83', $tok)[0] === 404 && api('appvis', 'GET', '/api/reportes/4000000000', $tok)[0] === 404, 'informe inactivo (83) o inexistente → 404');
[, , $rawH] = api('appvis', 'GET', '/api/reportes', $tok);
expectCase(str_contains((string)header_of('Cache-Control'), 'no-store') && header_of('X-Content-Type-Options') === 'nosniff' && header_of('Set-Cookie') === null, 'respuestas con Cache-Control no-store, nosniff y sin cookies');

echo "== Visor: solo los informes que el operador autorizó\n";
$okId = $ids[0]; $noId = (int)$root->query('SELECT id FROM reporte WHERE activo = 1 AND id NOT IN (' . implode(',', $ids) . ') ORDER BY id LIMIT 1')->fetchColumn();
$link = $root->query("SELECT enlace FROM reporte WHERE id = $okId")->fetchColumn();
[$stV, $dV] = api('appvis', 'GET', "/api/reportes/id/$okId/visor", $tok);
expectCase($stV === 200 && $dV['data']['url'] === $link && $dV['data']['modo'] === 'publico' && $dV['data']['id'] === $okId && array_keys($dV['data']) === ['id', 'reporte_id', 'nombre', 'descripcion', 'modo', 'url'], 'informe autorizado: el visor entrega id, referencia histórica, nombre, descripción, modo y url');
$foreignLink = $root->query("SELECT enlace FROM reporte WHERE id = $noId")->fetchColumn();
[$stN, $dN, $rawN] = api('appvis', 'GET', "/api/reportes/id/$noId/visor", $tok);
expectCase($stN === 403 && ($dN['code'] ?? '') === 'visor_no_autorizado' && !str_contains($rawN, $foreignLink) && !str_contains($rawN, 'url'), 'informe activo NO autorizado para el piloto: 403 «visor_no_autorizado», sin enlace');
expectCase(api('appvis', 'GET', '/api/reportes/id/83/visor', $tok)[0] === 404 && api('appvis', 'GET', '/api/reportes/id/4000000000/visor', $tok)[0] === 404, 'inactivo o inexistente → 404 aunque se pida el visor');
$tok0 = loginOp('appvis0'); $tokAll = loginOp('appvisall');
expectCase(api('appvis0', 'GET', "/api/reportes/id/$okId/visor", $tok0)[0] === 403 && count(walk('appvis0', '/api/reportes', $tok0)) === 82, 'sin ningún informe autorizado (instalación por defecto): el catálogo se ve completo y NINGÚN visor entrega enlace');
[$stAll, $dAll] = api('appvisall', 'GET', "/api/reportes/id/$noId/visor", $tokAll);
expectCase($stAll === 200 && $dAll['data']['url'] === $foreignLink, 'con «TODOS» (autorización amplia explícita) el visor entrega el enlace');
$bad = ['/api/reportes/id/abc/visor', '/api/reportes/id/0/visor', '/api/reportes/id/01/visor', '/api/reportes/id/1.5/visor', '/api/reportes/id/-1/visor', '/api/reportes/id/1%20OR%201%3D1/visor', '/api/reportes/id/99999999999/visor'];
$allClosed = true; foreach ($bad as $p) { [$s, , $r] = api('appvis', 'GET', $p, $tok); if (!in_array($s, [403, 404], true) || str_contains($r, 'url')) { $allClosed = false; echo "  no cerrado: $p → $s\n"; } }
expectCase($allClosed, 'ids malformados en el visor → 403/404 sin enlace');

echo "== Todo lo demás está cerrado (solo consulta)\n";
$denied = [['POST', '/api/register'], ['POST', '/api/reportes'], ['PUT', '/api/reportes/1'], ['DELETE', '/api/reportes/1'], ['POST', '/api/empresas'], ['PUT', '/api/empresas/1'], ['DELETE', '/api/empresas/1'], ['POST', '/api/asignacion'], ['DELETE', '/api/asignacion/1'],
    ['POST', '/api/empresa-usuario'], ['POST', '/api/empresa-reporte-usuario'], ['PUT', '/api/user/profile'], ['PUT', '/api/user/1/role'], ['POST', '/api/user/avatar'], ['GET', '/api/users'], ['GET', '/api/user'], ['GET', '/api/empresa-usuario'], ['GET', '/api/empresa-reporte-usuario'],
    ['GET', '/api/stats'], ['GET', '/api/powerbi/tokens/1'], ['GET', '/api/wiki'], ['GET', '/api/reportes/activos'], ['GET', '/api/reportes/grupoid/aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'], ['GET', '/api/empresas/1'], ['GET', '/api/asignacion/1'], ['GET', '/api/reportes/'], ['GET', '/api/ping/'], ['PATCH', '/api/reportes'], ['OPTIONS', '/api/reportes'], ['HEAD', '/api/reportes'], ['GET', '/'], ['GET', '/.env'], ['GET', '/api.php']];
$denyOk = 0; $denyBad = [];
foreach ($denied as [$m, $p]) { [$s] = api('appvis', $m, $p, $tok, in_array($m, ['POST', 'PUT', 'PATCH']) ? [] : null); if ($s === 403) $denyOk++; else $denyBad[] = "$m $p → $s"; }
expectCase($denyBad === [], count($denied) . ' rutas/métodos de administración, escritura, usuarios, estadísticas, Power BI heredado y variantes → 403 con sesión válida' . ($denyBad ? ' · ' . implode(' | ', $denyBad) : ''));

echo "== Ausencia de escrituras y de lecturas de usuarios\n";
expectCase(sums($root) === $sumsBefore && $root->query('SELECT id, last_login, session_version FROM user ORDER BY id')->fetchAll() === $lastLoginBefore, 'las seis tablas quedan idénticas bit a bit y last_login/session_version no cambian');
expectCase((int)$root->query("SELECT COUNT(*) FROM performance_schema.events_statements_summary_by_user_by_event_name WHERE USER = 'cervesur_ro_visor' AND SUM_ROWS_AFFECTED > 0")->fetchColumn() === 0, 'la cuenta del piloto no ha afectado ninguna fila (evidencia complementaria; no sustituye a los privilegios)');

echo $failures === 0 ? "\nAll pilot cases passed\n" : "\n$failures case(s) failed\n";
exit($failures === 0 ? 0 : 1);
