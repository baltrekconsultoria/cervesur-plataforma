<?php
// UX-07D.2B: compatibilidad con el esquema y los identificadores PRODUCTIVOS (reporte_id de hasta 50 caracteres, no UUID; grupo_id NOT NULL;
// enlace VARCHAR(500)). Solo red Docker aislada, base desechable `fixture_prod` y datos 100 % ficticios (tests/isolated/seed_productive.php).
// Se ejecuta contra una segunda instancia del backend (`appprod`) que apunta a `fixture_prod`; la base `fixture` de las demás suites no se toca.
$failures = 0;
$pdo = new PDO('mysql:host=db;dbname=fixture_prod;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

function requestApi($method, $path, $body = null, $token = null) {
    $headers = ["Content-Type: application/json"];
    if ($token !== null) $headers[] = "Authorization: Bearer " . $token;
    $options = ['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 8]];
    if ($body !== null) $options['http']['content'] = json_encode($body);
    $raw = file_get_contents('http://appprod:8080' . $path, false, stream_context_create($options));
    $GLOBALS['lastResponse'] = substr($raw ?: '', 0, 400);
    $GLOBALS['lastHeaders'] = $http_response_header ?? [];
    $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int)$m[1] : 0;
    return [$status, json_decode($raw ?: '', true), $raw ?: ''];
}
function expectCase($condition, $label) {
    global $failures;
    if ($condition) echo "PASS $label\n"; else { echo "FAIL $label\n  última respuesta: " . ($GLOBALS['lastResponse'] ?? '') . "\n"; $failures++; }
}
function tokenOf($name) { [, $d] = requestApi('POST', '/api/login', ['login' => $name, 'password' => 'synthetic-password']); return $d['token'] ?? ''; }
function scalar($pdo, $sql, $params = []) { $st = $pdo->prepare($sql); $st->execute($params); return $st->fetchColumn(); }
function row($pdo, $sql, $params = []) { $st = $pdo->prepare($sql); $st->execute($params); return $st->fetch(); }
function rows($pdo, $sql, $params = []) { $st = $pdo->prepare($sql); $st->execute($params); return $st->fetchAll(); }
function headerValue($name) { foreach ($GLOBALS['lastHeaders'] as $h) if (stripos($h, $name . ':') === 0) return trim(substr($h, strlen($name) + 1)); return null; }
function sortedKeys($r) { $k = array_keys($r); sort($k); return $k; }
function walk($path, $token) { // todas las páginas de un listado ?view=minimal
    $all = []; $page = 1;
    do {
        [, $d] = requestApi('GET', $path . (str_contains($path, '?') ? '&' : '?') . "per_page=100&page=$page", null, $token);
        $chunk = $d['data']['data'] ?? $d['data'] ?? []; $all = array_merge($all, $chunk); $more = !empty($d['pagination']['has_more']); $page++;
    } while ($more && $page < 20);
    return $all;
}

$EFFECTIVE = 'FROM user_empresa_reporte uer JOIN user u ON u.id = uer.user_id AND u.is_active = 1 JOIN empresa_reporte er ON er.id = uer.empresa_reporte_id AND er.activo = 1 JOIN empresa e ON e.id = er.empresa_id AND e.activo = 1 JOIN empresa_usuario eu ON eu.empresa_id = e.id AND eu.user_id = u.id AND eu.activo = 1 JOIN reporte r ON r.id = er.reporte_id AND r.activo = 1 WHERE uer.activo = 1';

echo "== Inventario sintético (formas y agregados de producción)\n";
expectCase((int)scalar($pdo, 'SELECT COUNT(*) FROM user') === 76 && (int)scalar($pdo, 'SELECT COUNT(*) FROM user WHERE is_active = 1') === 62, 'usuarios: 76 totales, 62 activos');
expectCase((int)scalar($pdo, 'SELECT COUNT(*) FROM empresa') === 26 && (int)scalar($pdo, 'SELECT COUNT(*) FROM empresa WHERE activo = 1') === 6, 'empresas: 26 totales, 6 activas');
expectCase((int)scalar($pdo, 'SELECT COUNT(*) FROM reporte') === 95 && (int)scalar($pdo, 'SELECT COUNT(*) FROM reporte WHERE activo = 1') === 82, 'informes: 95 totales, 82 activos');
expectCase((int)scalar($pdo, 'SELECT COUNT(*) FROM reporte WHERE CHAR_LENGTH(reporte_id) > 36') === 57 && (int)scalar($pdo, 'SELECT COUNT(*) FROM reporte WHERE CHAR_LENGTH(reporte_id) > 36 AND activo = 1') === 56, 'reporte_id mayores de 36 caracteres: 57 (56 activos)');
expectCase((int)scalar($pdo, 'SELECT MAX(CHAR_LENGTH(reporte_id)) FROM reporte') === 50, 'longitud máxima de reporte_id: 50');
expectCase((int)scalar($pdo, "SELECT COUNT(*) FROM reporte WHERE activo = 1 AND reporte_id REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'") === 0, 'ningún informe activo tiene un UUID canónico');
expectCase((int)scalar($pdo, $q = "SELECT COUNT(*) $EFFECTIVE") === 91 && (int)scalar($pdo, "SELECT COUNT(DISTINCT u.id) $EFFECTIVE") === 61 && (int)scalar($pdo, "SELECT COUNT(DISTINCT r.id) $EFFECTIVE") === 79, 'permisos individuales efectivos: 91 (61 usuarios, 79 informes)');
expectCase((int)scalar($pdo, 'SELECT COUNT(*) FROM user_empresa_reporte WHERE activo = 1') === 93 && (int)scalar($pdo, 'SELECT COUNT(*) FROM user_empresa_reporte uer JOIN user u ON u.id = uer.user_id WHERE uer.activo = 1 AND u.is_active = 0') === 2, 'asignaciones activas: 93, de las cuales 2 son de usuarios inactivos');
expectCase(scalar($pdo, "SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reporte' AND COLUMN_NAME = 'grupo_id'") === 'NO' && (int)scalar($pdo, "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reporte' AND COLUMN_NAME = 'enlace'") === 500, 'esquema: grupo_id NOT NULL y enlace VARCHAR(500) (UX-07C NO aplicada)');

$admin = tokenOf('syn001');
// Usuario con permiso efectivo sobre un informe de referencia de 50 caracteres, y un informe activo AJENO a ese usuario.
$A = row($pdo, "SELECT u.id user_id, u.username, r.id rid, r.reporte_id, r.grupo_id, r.enlace, uer.id uer_id, er.id er_id, e.id emp_id $EFFECTIVE AND CHAR_LENGTH(r.reporte_id) = 50 ORDER BY r.id LIMIT 1");
$B = row($pdo, "SELECT r.id rid FROM reporte r WHERE r.activo = 1 AND r.id NOT IN (SELECT r2.id FROM user_empresa_reporte uer JOIN empresa_reporte er ON er.id = uer.empresa_reporte_id JOIN reporte r2 ON r2.id = er.reporte_id WHERE uer.user_id = ? AND uer.activo = 1) ORDER BY r.id LIMIT 1", [$A['user_id']]);
$userA = tokenOf($A['username']);
expectCase($admin !== '' && $userA !== '' && strlen($A['reporte_id']) === 50 && !preg_match('/^[0-9a-f]{8}-/', $A['reporte_id']), "datos de referencia: informe {$A['rid']} con reporte_id de 50 caracteres no UUID; usuario {$A['username']} con permiso efectivo");

echo "== Visor por ID numérico (GET /api/reportes/id/{id}/visor)\n";
[$st, $d, $raw] = requestApi('GET', "/api/reportes/id/{$A['rid']}/visor", null, $admin);
expectCase($st === 200 && ($d['success'] ?? false) === true && $d['data']['id'] === (int)$A['rid'] && $d['data']['reporte_id'] === $A['reporte_id'] && $d['data']['modo'] === 'publico' && $d['data']['url'] === $A['enlace'], 'administrador: 200, id numérico, reporte_id histórico de 50 caracteres sin alterar y enlace público');
expectCase(sortedKeys($d['data']) === ['descripcion', 'id', 'modo', 'nombre', 'reporte_id', 'url'], 'la respuesta contiene solo id, reporte_id, nombre, descripcion, modo y url');
expectCase(str_contains((string)headerValue('Cache-Control'), 'no-store') && headerValue('X-Content-Type-Options') === 'nosniff', 'la respuesta del visor no se cachea (no-store, nosniff)');
[$st, $d] = requestApi('GET', "/api/reportes/id/{$A['rid']}/visor", null, $userA);
expectCase($st === 200 && $d['data']['url'] === $A['enlace'] && $d['data']['reporte_id'] === $A['reporte_id'], 'usuario con permiso efectivo: 200 con el mismo informe');
[$st, $d] = requestApi('GET', "/api/reportes/reporteid/{$A['reporte_id']}/visor", null, $userA);
expectCase($st === 404, 'la ruta histórica por UUID sigue sin aceptar referencias de 50 caracteres (404): por eso React usa el id numérico');
[$st, $d, $raw] = requestApi('GET', "/api/reportes/id/{$A['rid']}", null, $userA);
expectCase($st === 404 && !str_contains($raw, $A['enlace']), 'GET /api/reportes/id/{id} (sin /visor) no existe como ruta pública y no entrega el enlace');

echo "== Rutas históricas por UUID: intactas\n";
$uuidRow = row($pdo, "SELECT id, reporte_id, enlace FROM reporte WHERE reporte_id REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' ORDER BY id LIMIT 1");
[$st] = requestApi('GET', "/api/reportes/reporteid/{$uuidRow['reporte_id']}/visor", null, $admin);
expectCase($st === 404, 'informe UUID inactivo: 404 por la ruta histórica');
$pdo->prepare('UPDATE reporte SET activo = 1 WHERE id = ?')->execute([$uuidRow['id']]);
[$st, $d] = requestApi('GET', "/api/reportes/reporteid/{$uuidRow['reporte_id']}/visor", null, $admin);
expectCase($st === 200 && $d['data']['reporte_id'] === $uuidRow['reporte_id'] && $d['data']['url'] === $uuidRow['enlace'] && !array_key_exists('id', $d['data']), 'informe UUID activo: la ruta histórica /reporteid/{uuid}/visor responde igual que antes (sin campo id nuevo)');
[$st, $d] = requestApi('GET', "/api/reportes/reporteid/{$uuidRow['reporte_id']}?view=minimal", null, $admin);
expectCase($st === 200 && $d['data']['id'] == $uuidRow['id'], 'la ruta histórica /reporteid/{uuid} del detalle sigue funcionando');
[$st, $d] = requestApi('GET', "/api/reportes/id/{$uuidRow['id']}/visor", null, $admin);
expectCase($st === 200 && $d['data']['reporte_id'] === $uuidRow['reporte_id'], 'el mismo informe también se abre por su id numérico');
$pdo->prepare('UPDATE reporte SET activo = 0 WHERE id = ?')->execute([$uuidRow['id']]);

echo "== Autorización (10 escenarios obligatorios)\n";
// 3. usuario sin permiso: respuesta idéntica a un informe inexistente y sin enlace
[$st3, $d3, $raw3] = requestApi('GET', "/api/reportes/id/{$B['rid']}/visor", null, $userA);
[$stN, $dN, $rawN] = requestApi('GET', '/api/reportes/id/4000000000/visor', null, $userA);
$foreignLink = scalar($pdo, 'SELECT enlace FROM reporte WHERE id = ?', [$B['rid']]);
expectCase($st3 === 404 && $raw3 === $rawN && !str_contains($raw3, $foreignLink) && !str_contains($raw3, 'url'), '3. usuario sin permiso: 404 idéntico al de un informe inexistente, sin enlace');
// 4. usuario inactivo con asignación todavía activa
$inactive = row($pdo, 'SELECT u.id, u.username, uer.id uer_id, er.reporte_id rid FROM user_empresa_reporte uer JOIN user u ON u.id = uer.user_id JOIN empresa_reporte er ON er.id = uer.empresa_reporte_id WHERE uer.activo = 1 AND u.is_active = 0 ORDER BY u.id LIMIT 1');
expectCase(tokenOf($inactive['username']) === '', '4. usuario inactivo con asignación activa: no puede iniciar sesión');
$pdo->prepare('UPDATE user SET is_active = 0 WHERE id = ?')->execute([$A['user_id']]);
[$st] = requestApi('GET', "/api/reportes/id/{$A['rid']}/visor", null, $userA);
$pdo->prepare('UPDATE user SET is_active = 1 WHERE id = ?')->execute([$A['user_id']]);
expectCase($st === 401, '4b. un usuario desactivado con token vigente pierde el acceso de inmediato (401) aunque su asignación siga activa');
[$st] = requestApi('GET', "/api/reportes/id/{$A['rid']}/visor", null, $userA);
expectCase($st === 200, '4c. al reactivarlo recupera el acceso');
// 5. informe inactivo
$pdo->prepare('UPDATE reporte SET activo = 0 WHERE id = ?')->execute([$A['rid']]);
[$stU] = requestApi('GET', "/api/reportes/id/{$A['rid']}/visor", null, $userA); [$stA] = requestApi('GET', "/api/reportes/id/{$A['rid']}/visor", null, $admin);
$pdo->prepare('UPDATE reporte SET activo = 1 WHERE id = ?')->execute([$A['rid']]);
expectCase($stU === 404 && $stA === 404, '5. informe inactivo: 404 para el usuario y también para el administrador');
[$st] = requestApi('GET', '/api/reportes/id/83/visor', null, $admin);
expectCase($st === 404, '5b. informe inactivo sembrado (id 83, referencia larga): 404');
// 6. empresa inactiva
$pdo->prepare('UPDATE empresa SET activo = 0 WHERE id = ?')->execute([$A['emp_id']]);
[$st] = requestApi('GET', "/api/reportes/id/{$A['rid']}/visor", null, $userA);
$pdo->prepare('UPDATE empresa SET activo = 1 WHERE id = ?')->execute([$A['emp_id']]);
expectCase($st === 404, '6. empresa inactiva: 404');
// 6b. vínculo usuario–empresa revocado
$pdo->prepare('UPDATE empresa_usuario SET activo = 0 WHERE user_id = ? AND empresa_id = ?')->execute([$A['user_id'], $A['emp_id']]);
[$st] = requestApi('GET', "/api/reportes/id/{$A['rid']}/visor", null, $userA);
$pdo->prepare('UPDATE empresa_usuario SET activo = 1 WHERE user_id = ? AND empresa_id = ?')->execute([$A['user_id'], $A['emp_id']]);
expectCase($st === 404, '6b. vínculo usuario–empresa revocado: 404');
// 7. relación empresa–informe revocada
$pdo->prepare('UPDATE empresa_reporte SET activo = 0 WHERE id = ?')->execute([$A['er_id']]);
[$st] = requestApi('GET', "/api/reportes/id/{$A['rid']}/visor", null, $userA);
$pdo->prepare('UPDATE empresa_reporte SET activo = 1 WHERE id = ?')->execute([$A['er_id']]);
expectCase($st === 404, '7. relación empresa–informe revocada: 404');
// 8-9. asignación individual revocada por la API y acceso directo por URL después
[$stList, $before] = requestApi('GET', '/api/reportes?view=minimal&per_page=100', null, $userA);
$idsBefore = array_map(fn ($r) => (int)$r['id'], walk('/api/reportes?view=minimal', $userA));
[$stDel, $del] = requestApi('DELETE', "/api/empresa-reporte-usuario/{$A['uer_id']}", null, $admin);
expectCase($stDel === 200 && ($del['success'] ?? false) === true, '8. el administrador revoca la asignación individual por la API');
[$st, $d, $raw] = requestApi('GET', "/api/reportes/id/{$A['rid']}/visor", null, $userA);
expectCase($st === 404 && !str_contains($raw, $A['enlace']), '9. acceso directo por URL después de la revocación: 404 y sin enlace (el servidor, no la interfaz)');
$idsAfter = array_map(fn ($r) => (int)$r['id'], walk('/api/reportes?view=minimal', $userA));
expectCase(in_array((int)$A['rid'], $idsBefore, true) && !in_array((int)$A['rid'], $idsAfter, true) && count($idsAfter) === count($idsBefore) - 1, '9b. el informe revocado desaparece del catálogo del usuario');
[$st] = requestApi('GET', "/api/reportes/{$A['rid']}", null, $userA);
expectCase($st === 404, '9c. el detalle numérico /api/reportes/{id} también responde 404 tras la revocación');
$pdo->prepare('UPDATE user_empresa_reporte SET activo = 1 WHERE id = ?')->execute([$A['uer_id']]);
[$st] = requestApi('GET', "/api/reportes/id/{$A['rid']}/visor", null, $userA);
expectCase($st === 200, '9d. al restituir la asignación vuelve el acceso (control)');
// 10. id inexistente o malformado
$bad = ['0', '-1', 'abc', '1.5', '007', '99999999999', '4294967296', '4294967295', '1e3', '%20', '1%2F2', '1%20OR%201%3D1', '0x10', '%EF%BC%91'];
$allNotFound = true; $leak = false;
foreach ($bad as $id) {
    [$st, $d, $raw] = requestApi('GET', "/api/reportes/id/$id/visor", null, $admin);
    if ($st !== 404 || ($d['success'] ?? null) !== false) { $allNotFound = false; echo "  id '$id' -> HTTP $st\n"; }
    if (str_contains($raw, 'url') || str_contains($raw, 'powerbi')) $leak = true;
}
expectCase($allNotFound && !$leak, '10. ids malformados o inexistentes (' . count($bad) . ' casos, incluidos 0, negativo, decimal, ceros a la izquierda, fuera de rango INT UNSIGNED, inyección): siempre 404 JSON sin datos');
[$st] = requestApi('GET', "/api/reportes/id/{$A['rid']}/visor"); expectCase($st === 401, 'sin token: 401');
[$st] = requestApi('GET', "/api/reportes/id/{$A['rid']}/visor", null, 'token-invalido'); expectCase($st === 401, 'token inválido: 401');

echo "== Catálogo con identidad numérica\n";
$effIds = array_map('intval', array_column(rows($pdo, "SELECT DISTINCT r.id $EFFECTIVE AND u.id = ? ORDER BY r.id", [$A['user_id']]), 'id'));
$list = walk('/api/reportes?view=minimal', $userA);
$gotIds = array_map(fn ($r) => (int)$r['id'], $list); sort($gotIds);
expectCase($gotIds === $effIds, 'el catálogo del usuario contiene exactamente sus informes efectivos (' . count($effIds) . ')');
expectCase(count(array_filter($list, fn ($r) => strlen($r['reporte_id']) === 50)) >= 1 && !array_key_exists('enlace', $list[0]), 'cada fila trae id numérico y la referencia histórica tal cual (hasta 50 caracteres); sin enlace en la vista mínima');
$all = walk('/api/reportes?view=minimal', $admin);
expectCase(count($all) === 82 && count(array_unique(array_column($all, 'id'))) === 82, 'administrador: 82 informes activos, ids únicos (la paginación recorre todo)');

echo "== Relaciones empresa–informe y accesos: unión por id numérico\n";
$rel = walk('/api/asignacion?view=minimal', $admin);
$acc = walk('/api/empresa-reporte-usuario?view=minimal', $admin);
$relById = []; foreach ($rel as $r) $relById[(int)$r['id']] = (int)$r['reporte_id'];
$mapOk = count($acc) > 0; $mismatch = 0;
foreach ($acc as $a) { $want = scalar($pdo, 'SELECT er.reporte_id FROM empresa_reporte er WHERE er.id = ?', [$a['empresa_reporte_id']]); if (!isset($relById[(int)$a['empresa_reporte_id']]) || $relById[(int)$a['empresa_reporte_id']] !== (int)$want) $mismatch++; }
expectCase($mapOk && $mismatch === 0, 'cada acceso (' . count($acc) . ') se une con su informe NUMÉRICO por empresa_reporte_id, sin pasar por el reporte_id histórico');
expectCase(count($rel) === 84, 'relaciones empresa–informe vigentes visibles: 84');
$inactiveUsers = array_map('intval', array_column(rows($pdo, 'SELECT id FROM user WHERE is_active = 0'), 'id'));
$ofInactive = array_filter($acc, fn ($a) => in_array((int)$a['user_id'], $inactiveUsers, true));
expectCase(count($acc) === 93 && count($ofInactive) === 2, 'el listado administrativo trae las 93 asignaciones activas: 91 efectivas + 2 de usuarios inactivos (la interfaz las marca; el servidor ya les niega el acceso)');

echo "== Edición de informes históricos (no UUID)\n";
$H = row($pdo, 'SELECT id, nombre, descripcion, enlace, reporte_id, grupo_id FROM reporte WHERE id = ?', [$A['rid']]);
$adminId = (int)scalar($pdo, "SELECT id FROM user WHERE username = 'syn001'");
$newLink = 'https://app.powerbi.com/view?r=U0lOVEVUSUNPLUVEQ0lPTi0wMDAx&pageName=ReportSection';
[$st, $d] = requestApi('PUT', "/api/reportes/{$H['id']}", ['nombre' => 'Nombre editado sintético', 'descripcion' => 'Descripción editada', 'enlace' => $H['enlace']], $admin);
$after = row($pdo, 'SELECT nombre, descripcion, enlace, reporte_id, grupo_id, updated_by FROM reporte WHERE id = ?', [$H['id']]);
expectCase(($d['success'] ?? false) === true && $after['nombre'] === 'Nombre editado sintético' && $after['reporte_id'] === $H['reporte_id'] && $after['grupo_id'] === $H['grupo_id'] && (int)$after['updated_by'] === $adminId, 'editar nombre y descripción NO altera reporte_id (50 caracteres) ni grupo_id');
[$st, $d] = requestApi('PUT', "/api/reportes/{$H['id']}", ['nombre' => 'Nombre editado sintético', 'descripcion' => 'Descripción editada', 'enlace' => $newLink], $admin);
$after = row($pdo, 'SELECT enlace, reporte_id, grupo_id FROM reporte WHERE id = ?', [$H['id']]);
expectCase(($d['success'] ?? false) === true && $after['enlace'] === $newLink && $after['reporte_id'] === $H['reporte_id'] && $after['grupo_id'] === $H['grupo_id'], 'cambiar el enlace público tampoco regenera reporte_id ni grupo_id');
[$st, $d] = requestApi('PUT', "/api/reportes/{$H['id']}", ['nombre' => 'X', 'descripcion' => '', 'enlace' => $H['enlace'], 'reporte_id' => 'SYN-REF-00001'], $admin);
$after = row($pdo, 'SELECT nombre, reporte_id FROM reporte WHERE id = ?', [$H['id']]);
expectCase(($d['success'] ?? true) === false && str_contains($d['message'] ?? '', 'UUID') && $after['reporte_id'] === $H['reporte_id'] && $after['nombre'] === 'Nombre editado sintético', 'la validación estricta se mantiene: un reporte_id explícito que no es UUID se rechaza y no cambia nada');
[$st, $d] = requestApi('PUT', "/api/reportes/{$H['id']}", ['nombre' => str_repeat('N', 101), 'enlace' => $H['enlace']], $admin);
expectCase(($d['success'] ?? true) === false && scalar($pdo, 'SELECT nombre FROM reporte WHERE id = ?', [$H['id']]) === 'Nombre editado sintético', 'un nombre de más de 100 caracteres se rechaza (nada se trunca)');
$O = row($pdo, "SELECT id, enlace, reporte_id, grupo_id FROM reporte WHERE activo = 1 AND CHAR_LENGTH(reporte_id) <= 36 ORDER BY id LIMIT 1 OFFSET 7");
[$st, $d] = requestApi('PUT', "/api/reportes/{$O['id']}", ['nombre' => 'Otro formato editado', 'descripcion' => '', 'enlace' => $O['enlace']], $admin);
$afterO = row($pdo, 'SELECT reporte_id, grupo_id FROM reporte WHERE id = ?', [$O['id']]);
expectCase(($d['success'] ?? false) === true && $afterO['reporte_id'] === $O['reporte_id'] && $afterO['grupo_id'] === $O['grupo_id'], 'informe de otro formato no UUID (≤ 36 caracteres): la edición conserva reporte_id y grupo_id');
[, $detail] = requestApi('GET', "/api/reportes/{$H['id']}?view=form", null, $admin);
expectCase(($detail['data']['reporte_id'] ?? null) === $H['reporte_id'] && ($detail['data']['id'] ?? null) == $H['id'], 'el formulario recibe id numérico y referencia histórica por separado');

echo "== Creación con grupo_id NOT NULL\n";
$count = (int)scalar($pdo, 'SELECT COUNT(*) FROM reporte');
[$st, $d] = requestApi('POST', '/api/reportes', ['nombre' => 'Nuevo público sintético', 'descripcion' => '', 'enlace' => 'https://app.powerbi.com/view?r=U0lOVEVUSUNPLU5VRVZPLTAwMDE'], $admin);
expectCase(($d['success'] ?? true) === false && str_contains($d['message'] ?? '', 'ID del grupo') && str_contains($d['message'] ?? '', 'UX-07C') && !preg_match('/SQLSTATE|grupo_id|Column|PDO/i', $GLOBALS['lastResponse']) && (int)scalar($pdo, 'SELECT COUNT(*) FROM reporte') === $count, '«Publicar en web» nuevo: se rechaza con un mensaje claro; no se inventa grupo_id ni se inserta nada');
$rid = '77777777-8888-4999-8aaa-bbbbbbbbbbbb'; $gid = 'cccccccc-dddd-4eee-8fff-000000000000';
[$st, $d] = requestApi('POST', '/api/reportes', ['nombre' => 'Inserción sintética', 'descripcion' => '', 'enlace' => "https://app.powerbi.com/reportEmbed?reportId=$rid&groupId=$gid&autoAuth=true"], $admin);
$newId = $d['data']['id'] ?? null; $created = $newId ? row($pdo, 'SELECT reporte_id, grupo_id FROM reporte WHERE id = ?', [$newId]) : null;
expectCase(($d['success'] ?? false) === true && $created && $created['reporte_id'] === $rid && $created['grupo_id'] === $gid, 'enlace de inserción (trae reportId y groupId): se crea con los identificadores del enlace');
if ($newId) {
    [$st, $d] = requestApi('GET', "/api/reportes/id/$newId/visor", null, $admin);
    expectCase($st === 200 && $d['data']['modo'] === 'privado' && !array_key_exists('url', $d['data']), 'el visor numérico del informe de inserción indica modo privado y no entrega enlace');
    $pdo->prepare('DELETE FROM reporte WHERE id = ?')->execute([$newId]);
}
[$st, $d] = requestApi('POST', '/api/reportes', ['nombre' => 'Enlace largo', 'enlace' => 'https://app.powerbi.com/view?r=' . str_repeat('A', 500)], $admin);
expectCase(($d['success'] ?? true) === false && str_contains($d['message'] ?? '', '500') && (int)scalar($pdo, 'SELECT COUNT(*) FROM reporte') === $count, 'un enlace que supera VARCHAR(500) se rechaza con el límite real (nada se trunca)');
expectCase(scalar($pdo, "SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reporte' AND COLUMN_NAME = 'grupo_id'") === 'NO', 'tras las pruebas el esquema sigue intacto: ninguna migración se aplicó');

echo $failures === 0 ? "\nAll productive-compatibility cases passed\n" : "\n$failures case(s) failed\n";
exit($failures === 0 ? 0 : 1);
