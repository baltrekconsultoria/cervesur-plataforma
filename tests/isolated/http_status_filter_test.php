<?php
// UX-08A: filtro de estado `status=active|inactive|all` en los listados administrativos. Solo red Docker aislada, identidades ficticias y MySQL desechable.
// Se ejecuta DESPUÉS del resto de suites HTTP del fixture: inserta sus propios registros inactivos (ids 901+) y los elimina al terminar.
$failures = 0;
$pdo = new PDO('mysql:host=db;dbname=fixture;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

function requestApi($method, $path, $body = null, $token = null) {
    $headers = ["Content-Type: application/json"];
    if ($token !== null) $headers[] = "Authorization: Bearer " . $token;
    $options = ['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 5]];
    if ($body !== null) $options['http']['content'] = json_encode($body);
    $raw = file_get_contents('http://app:8080' . $path, false, stream_context_create($options));
    $GLOBALS['lastResponse'] = substr($raw ?: '', 0, 300);
    $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int)$m[1] : 0;
    return [$status, json_decode($raw ?: '', true), $raw ?: ''];
}
function expectCase($condition, $label) {
    global $failures;
    if ($condition) echo "PASS $label\n"; else { echo "FAIL $label\n  última respuesta: " . ($GLOBALS['lastResponse'] ?? '') . "\n"; $failures++; }
}
function tokenOf($name, $password = 'synthetic-password') {
    [, $d] = requestApi('POST', '/api/login', ['login' => $name, 'password' => $password]);
    return $d['token'] ?? '';
}
function snapshot($pdo) {
    $out = [];
    foreach (['user', 'empresa', 'reporte', 'empresa_usuario', 'empresa_reporte', 'user_empresa_reporte'] as $t) {
        $out[$t] = md5(json_encode($pdo->query("SELECT * FROM $t ORDER BY id")->fetchAll()));
    }
    return $out;
}
// /api/asignacion envuelve las filas dos veces (`data.data`).
function rowsOf($d) { $rows = $d['data'] ?? []; return isset($rows['data']) && is_array($rows['data']) ? $rows['data'] : $rows; }
function paginationOf($d) { return $d['pagination'] ?? ($d['data']['pagination'] ?? []); }
function idsOf($d) { $ids = array_map('intval', array_column(rowsOf($d), 'id')); sort($ids); return $ids; }
// Recorre todas las páginas: los fixtures de volumen superan una página.
function fetchAll($path, $status, $token) {
    $ids = [];
    for ($page = 1; $page <= 50; $page++) {
        [$s, $d] = requestApi('GET', "$path?view=minimal&per_page=100&page=$page" . ($status === null ? '' : "&status=$status"), null, $token);
        if ($s !== 200) return null;
        $ids = array_merge($ids, idsOf($d));
        if (empty(paginationOf($d)['has_more'])) break;
    }
    sort($ids);
    return $ids;
}

$admin = tokenOf('admin');
$userA = tokenOf('usera');

// ---------- Datos propios: una empresa y un informe inactivos, y accesos que dependen de ellos ----------
$pdo->exec("INSERT INTO empresa (id,nombre,razon_social,ruc,ciudad,pais,activo) VALUES (901,'Empresa Inactiva UX08','Empresa Inactiva UX08','00000000901','Ciudad X','Demo',0)");
$pdo->exec("INSERT INTO reporte (id,nombre,enlace,grupo_id,reporte_id,created_by,activo) VALUES (901,'Reporte Inactivo UX08','https://example.invalid/x','00000000-0000-0000-0000-000000000901','00000000-0000-0000-0000-000000000902',1,0)");
$pdo->exec('INSERT INTO empresa_reporte (id,empresa_id,reporte_id,activo) VALUES (901,1,901,1),(902,901,1,1)');
$pdo->exec('INSERT INTO empresa_usuario (id,empresa_id,user_id,activo) VALUES (901,901,1,1)');
$pdo->exec('INSERT INTO user_empresa_reporte (id,user_id,empresa_reporte_id,activo) VALUES (901,1,901,1),(902,1,902,1)');
$before = snapshot($pdo);

$endpoints = [
    'empresas' => ['/api/empresas', 901],
    'reportes' => ['/api/reportes', 901],
    'asignacion' => ['/api/asignacion', 901],
    'empresa-reporte-usuario' => ['/api/empresa-reporte-usuario', 901],
];

foreach ($endpoints as $name => [$path, $inactiveId]) {
    $default = fetchAll($path, null, $admin);
    $active = fetchAll($path, 'active', $admin);
    $inactive = fetchAll($path, 'inactive', $admin);
    $all = fetchAll($path, 'all', $admin);
    expectCase($default !== null && $active !== null && $inactive !== null && $all !== null, "$name: admin gets 200 for default, active, inactive and all");
    expectCase($default === $active && !in_array($inactiveId, $default, true), "$name: without status the list is the active one and hides the inactive record");
    expectCase(in_array($inactiveId, $inactive, true) && array_intersect($inactive, $active) === [], "$name: status=inactive returns the inactive record and nothing from the active set");
    $union = array_merge($active, $inactive); sort($union);
    expectCase($all === $union && count($all) === count(array_unique($all)), "$name: status=all is exactly active plus inactive, without duplicates");

    [$s, $page] = requestApi('GET', "$path?view=minimal&per_page=1&page=1&status=all", null, $admin);
    expectCase($s === 200 && count(rowsOf($page)) === 1 && (paginationOf($page)['total'] ?? 0) === count($all) && (paginationOf($page)['has_more'] ?? false) === true, "$name: status=all keeps server pagination (one row per page, full total)");

    [$s] = requestApi('GET', "$path?status=all", null, $userA);
    expectCase($s === 403, "$name: a non-admin cannot ask for status=all");
    [$s] = requestApi('GET', "$path?status=inactive", null, $userA);
    expectCase($s === 403, "$name: a non-admin cannot ask for status=inactive");
    [$s, $own] = requestApi('GET', "$path?status=active", null, $userA);
    expectCase($s === 200 && !in_array($inactiveId, idsOf($own), true), "$name: a non-admin can ask for status=active and never sees the inactive record");
    [$s] = requestApi('GET', "$path?status=todos", null, $admin);
    expectCase($s === 400, "$name: an unknown status value is rejected with 400");
    [$s] = requestApi('GET', "$path?status[]=all", null, $admin);
    expectCase($s === 400, "$name: an array status value is rejected with 400");
    [$s] = requestApi('GET', "$path?status=all");
    expectCase($s === 401, "$name: anonymous requests are rejected before the filter is considered");
}

// ---------- Semántica de «vigente» en accesos ----------
$inactiveAccess = fetchAll('/api/empresa-reporte-usuario', 'inactive', $admin);
expectCase(in_array(901, $inactiveAccess, true), 'access whose report is inactive is not in effect');
expectCase(in_array(902, $inactiveAccess, true), 'access whose company is inactive is not in effect');
expectCase(in_array(3, $inactiveAccess, true) && in_array(4, $inactiveAccess, true), 'revoked accesses (activo = 0) are not in effect');
$activeAccess = fetchAll('/api/empresa-reporte-usuario', 'active', $admin);
expectCase(in_array(1, $activeAccess, true) && in_array(2, $activeAccess, true), 'accesses that satisfy every condition stay in effect');

// ---------- Los listados son de solo lectura ----------
expectCase(snapshot($pdo) === $before, 'reading with every status value leaves the data unchanged');

// ---------- Limpieza ----------
$pdo->exec('DELETE FROM user_empresa_reporte WHERE id IN (901,902)');
$pdo->exec('DELETE FROM empresa_usuario WHERE id = 901');
$pdo->exec('DELETE FROM empresa_reporte WHERE id IN (901,902)');
$pdo->exec('DELETE FROM reporte WHERE id = 901');
$pdo->exec('DELETE FROM empresa WHERE id = 901');

echo $failures === 0 ? "ALL PASSED\n" : "$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
