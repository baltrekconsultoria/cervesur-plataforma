<?php
// Pruebas HTTP de paginación de servidor, vistas mínimas y compatibilidad (UX-06B.0).
// Solo la red Docker aislada e identidades sintéticas. Requiere seed.php + seed_pagination.php.
$failures = 0; $cases = 0;
function requestApi($method, $path, $token = null) {
    $headers = ["Content-Type: application/json"];
    if ($token !== null) $headers[] = "Authorization: Bearer " . $token;
    $options = ['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 10]];
    $raw = file_get_contents('http://app:8080' . $path, false, stream_context_create($options));
    $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int)$m[1] : 0;
    return [$status, json_decode($raw ?: '', true), $raw ?: ''];
}
function expectCase($condition, $label) {
    global $failures, $cases; $cases++;
    if ($condition) echo "PASS $label\n"; else { echo "FAIL $label\n"; $failures++; }
}
function loginAs($name) {
    $raw = file_get_contents('http://app:8080/api/login', false, stream_context_create(['http' => ['method' => 'POST', 'header' => "Content-Type: application/json", 'content' => json_encode(['login' => $name, 'password' => 'synthetic-password']), 'ignore_errors' => true]]));
    return json_decode($raw, true)['token'] ?? '';
}
/** Filas de cualquier listado (cubre la doble envoltura de /api/asignacion y la clave `users`). */
function rowsOf($json) {
    if (isset($json['data']['data']) && is_array($json['data']['data'])) return $json['data']['data'];
    if (isset($json['users']) && is_array($json['users'])) return $json['users'];
    return isset($json['data']) && is_array($json['data']) ? $json['data'] : [];
}
function metaOf($json) { return $json['pagination'] ?? $json['data']['pagination'] ?? null; }
/** Recorre todas las páginas; devuelve filas, metas y banderas de coherencia. */
function walk($path, $token, $perPage, $extra = '') {
    $rows = []; $metas = []; $page = 1; $ok = true; $sep = strpos($path, '?') === false ? '?' : '&';
    do {
        [$s, $j] = requestApi('GET', "{$path}{$sep}per_page={$perPage}&page={$page}{$extra}", $token);
        $meta = metaOf($j);
        if ($s !== 200 || $meta === null) { $ok = false; break; }
        $metas[] = $meta; $rows = array_merge($rows, rowsOf($j)); $page++;
    } while ($meta['has_more'] && $page < 400);
    return ['rows' => $rows, 'metas' => $metas, 'ok' => $ok];
}
function ids($rows) { return array_map(fn($r) => $r['id'], $rows); }
function ordered($rows) { // created_at DESC, id DESC
    for ($i = 1; $i < count($rows); $i++) {
        $a = $rows[$i - 1]; $b = $rows[$i];
        if (($a['created_at'] ?? '') < ($b['created_at'] ?? '')) return false;
        if (($a['created_at'] ?? '') === ($b['created_at'] ?? '') && $a['id'] < $b['id']) return false;
    }
    return true;
}

$admin = loginAs('admin');
$users = ['pg0' => 0, 'pg1' => 1, 'pg20' => 20, 'pg21' => 21, 'pg45' => 45, 'pg105' => 105];
$tokens = []; foreach (array_merge($users, ['pgmix' => 5]) as $name => $_) $tokens[$name] = loginAs($name);
$userIdByName = ['pg0' => 101, 'pg1' => 102, 'pg20' => 103, 'pg21' => 104, 'pg45' => 105, 'pg105' => 106, 'pgmix' => 107];
expectCase(count(array_filter($tokens)) === 7 && $admin !== '', 'synthetic pagination users can log in');

/* ---- Listados con LIMIT 20 heredado: sin parámetros, con parámetros, extremos ---- */
$endpoints = ['/api/empresa-usuario' => 'empresa-usuario', '/api/empresa-reporte-usuario' => 'empresa-reporte-usuario'];
$perUserIds = [];
foreach ($endpoints as $path => $label) {
    foreach ($users as $name => $n) {
        $t = $tokens[$name];
        [$s, $j] = requestApi('GET', $path, $t); $meta = metaOf($j);
        expectCase($s === 200 && count(rowsOf($j)) === min($n, 20) && $meta !== null && $meta['total'] === $n && $meta['per_page'] === 20 && $meta['has_more'] === ($n > 20), "$label legacy default for $name: first " . min($n, 20) . " rows, total $n, has_more " . ($n > 20 ? 'true' : 'false'));
        expectCase(isset($j['success'], $j['data'], $j['count'], $j['message']) && $j['count'] === count($j['data']), "$label legacy keys preserved for $name");
        foreach ([10, 20, 100] as $pp) {
            $w = walk($path, $t, $pp);
            $got = ids($w['rows']);
            $pages = max(1, (int)ceil($n / $pp));
            $lastCount = $n === 0 ? 0 : $n - $pp * ($pages - 1);
            $consistent = $w['ok'] && count($got) === $n && count(array_unique($got)) === $n && count($w['metas']) === $pages
                && !in_array(false, array_map(fn($m) => $m['total'] === $n && $m['per_page'] === $pp, $w['metas']), true)
                && $w['metas'][count($w['metas']) - 1]['has_more'] === false;
            expectCase($consistent, "$label $name per_page=$pp: $pages page(s), $n unique rows, constant total");
            if ($pp === 10) $perUserIds[$label][$name] = $got;
            expectCase(ordered($w['rows']), "$label $name per_page=$pp: stable deterministic order (created_at DESC, id DESC)");
        }
        [$s, $j] = requestApi('GET', "$path?page=" . ($n + 50) . "&per_page=10", $t);
        expectCase($s === 200 && ($j['success'] ?? false) === true && rowsOf($j) === [] && metaOf($j)['total'] === $n && metaOf($j)['has_more'] === false, "$label $name: page beyond the end returns empty data with consistent total");
    }
    // Aislamiento: ningún usuario ve filas de otro.
    $all = []; $overlap = false;
    foreach ($perUserIds[$label] as $name => $got) { foreach ($got as $id) { if (isset($all[$id])) $overlap = true; $all[$id] = $name; } }
    expectCase(!$overlap && count($all) === array_sum($users), "$label: users see disjoint rows (no cross access), " . array_sum($users) . ' in total');
    // Administrador: ve todo (superconjunto) con total coherente.
    $w = walk($path, $admin, 100); $adminIds = ids($w['rows']);
    $metaTotal = $w['metas'][0]['total'] ?? -1;
    expectCase($w['ok'] && count($adminIds) === $metaTotal && count(array_unique($adminIds)) === $metaTotal && $metaTotal >= array_sum($users), "$label administrator walks all {$metaTotal} authorized rows without duplicates");
    expectCase(count(array_diff(array_keys($all), $adminIds)) === 0, "$label administrator set contains every user's rows");
    // Parámetros inválidos: 400, nunca ignorados.
    foreach (['page=0', 'page=-1', 'page=abc', 'page=1.5', 'page=1000001', 'per_page=0', 'per_page=101', 'per_page=-5', 'per_page=abc', 'per_page%5B%5D=5', 'page=%201x'] as $bad) {
        [$s, $j] = requestApi('GET', "$path?$bad", $tokens['pg20']);
        expectCase($s === 400 && ($j['success'] ?? true) === false && isset($j['message']), "$label rejects invalid parameter ($bad) with 400");
    }
    [$s] = requestApi('GET', "$path?per_page=5", null);
    expectCase($s === 401, "$label requires authentication even with pagination parameters");
}

/* ---- pgmix: relaciones que NO deben contarse ---- */
[$s, $j] = requestApi('GET', '/api/empresa-reporte-usuario?per_page=100', $tokens['pgmix']);
expectCase(metaOf($j)['total'] === 5 && count(rowsOf($j)) === 5, 'pgmix: user-report relations count only active ones on active company/report/relation (inactive user-report, inactive company, inactive report and inactive company-report excluded)');
[$s, $j] = requestApi('GET', '/api/empresa-usuario?per_page=100', $tokens['pgmix']);
expectCase(metaOf($j)['total'] === 6, 'KNOWN: pgmix company-user list still includes the active relation to an inactive company (total 6, 3 inactive relations excluded) - backlog item');
[$s, $j] = requestApi('GET', '/api/empresas?per_page=100', $tokens['pgmix']);
expectCase(metaOf($j)['total'] === 5, 'pgmix: /api/empresas excludes the inactive company (5 active)');
[$s, $j] = requestApi('GET', '/api/reportes?per_page=100&view=minimal', $tokens['pgmix']);
expectCase(metaOf($j)['total'] === 5, 'pgmix: /api/reportes excludes inactive report, inactive company, inactive company-report and inactive user relation (5)');

/* ---- Búsquedas relacionadas (LIMIT 20 heredado) y por usuario ---- */
foreach (['/api/empresa-usuario/empresa/PG%20Empresa' => 'company-user search', '/api/empresa-reporte-usuario/empresa/PG%20Empresa' => 'user-report search'] as $path => $label) {
    [$s, $j] = requestApi('GET', $path, $tokens['pg105']);
    expectCase($s === 200 && count(rowsOf($j)) === 20 && metaOf($j)['total'] === 105 && metaOf($j)['has_more'] === true, "$label (pg105) legacy default: 20 rows, real total 105");
    $w = walk($path, $tokens['pg105'], 25);
    expectCase($w['ok'] && count($w['rows']) === 105 && count(array_unique(ids($w['rows']))) === 105 && count($w['metas']) === 5, "$label (pg105) per_page=25: 5 pages, 105 unique rows");
    [$s, $j] = requestApi('GET', str_replace('PG%20Empresa', 'PG%20Empresa%20050', $path), $tokens['pg1']);
    expectCase(rowsOf($j) === [] , "$label (pg1) cannot discover another user's company by name");
    [$s, $j] = requestApi('GET', $path . '?per_page=1000', $tokens['pg1']);
    expectCase($s === 400, "$label rejects per_page above the maximum");
}
foreach (['/api/empresa-usuario/ruc/PG000000050' => 'company-user by RUC', '/api/empresa-reporte-usuario/ruc/PG000000050' => 'user-report by RUC'] as $path => $label) {
    [$s, $j] = requestApi('GET', $path, $tokens['pg105']);
    expectCase($s === 200 && count(rowsOf($j)) === 1, "$label returns the authorized row for pg105 without parameters (unbounded legacy)");
    [$s, $j] = requestApi('GET', $path . '?per_page=1', $tokens['pg105']);
    expectCase(metaOf($j)['total'] === 1 && metaOf($j)['has_more'] === false, "$label accepts pagination");
    [$s, $j] = requestApi('GET', $path, $tokens['pg1']);
    expectCase(rowsOf($j) === [], "$label returns nothing for a user without that relation");
}
[$s, $j] = requestApi('GET', '/api/empresa-reporte-usuario/user/106?per_page=40&page=3', $tokens['pg105']);
expectCase($s === 200 && count(rowsOf($j)) === 25 && metaOf($j)['total'] === 105 && metaOf($j)['has_more'] === false, 'by-user listing: last page (page 3 of per_page 40) has 25 rows');
[$s, $j] = requestApi('GET', '/api/empresa-reporte-usuario/user/106', $tokens['pg105']);
expectCase($s === 200 && count(rowsOf($j)) === 105 && !isset($j['pagination']), 'by-user listing without parameters stays unbounded and unchanged (compatibility)');
[$s] = requestApi('GET', '/api/empresa-reporte-usuario/user/105?per_page=5', $tokens['pg105']);
expectCase($s === 403, 'by-user listing: another user id is still 403 with pagination parameters');

/* ---- Listados sin LIMIT previo: opt-in, mismo total que el listado completo ---- */
foreach (['/api/users?status=all', '/api/reportes', '/api/empresas', '/api/asignacion'] as $path) {
    [$s, $full] = requestApi('GET', $path, $admin);
    $fullCount = count(rowsOf($full));
    expectCase($s === 200 && !isset($full['pagination']) && !isset($full['data']['pagination']) && $fullCount >= (strpos($path, '/api/users') === 0 ? 10 : 100), "$path without parameters is unbounded and unchanged ($fullCount rows, no pagination block)");
    $w = walk($path, $admin, 30);
    $gotIds = ids($w['rows']);
    expectCase($w['ok'] && count($gotIds) === $fullCount && count(array_unique($gotIds)) === $fullCount && ($w['metas'][0]['total'] ?? -1) === $fullCount, "$path per_page=30: server total equals the full unpaginated list ($fullCount), no duplicates");
    expectCase(array_values(array_unique($gotIds)) == array_values(array_unique(ids(rowsOf($full)))) || count(array_diff($gotIds, ids(rowsOf($full)))) === 0, "$path: paginated rows are exactly the rows of the full list");
    foreach (['page=0', 'per_page=101', 'per_page=x'] as $bad) { [$s] = requestApi('GET', (strpos($path, '?') ? "$path&$bad" : "$path?$bad"), $admin); expectCase($s === 400, "$path rejects $bad"); }
}
[$s, $j] = requestApi('GET', '/api/users?status=all&per_page=3&page=2', $admin);
expectCase(isset($j['users'], $j['total'], $j['pagination']) && count($j['users']) === 3, '/api/users keeps its legacy keys (users, total, filter) and adds pagination');
[$s] = requestApi('GET', '/api/users?per_page=5', $tokens['pg1']);
expectCase($s === 403, '/api/users with pagination is still administrator-only');
foreach (['/api/reportes' => 'pg105', '/api/empresas' => 'pg105', '/api/asignacion' => 'pg105'] as $path => $name) {
    $w = walk($path, $tokens[$name], 50);
    expectCase($w['ok'] && count($w['rows']) === 105 && $w['metas'][0]['total'] === 105 && count($w['metas']) === 3, "$path as pg105 per_page=50: 3 pages, exactly the 105 authorized rows");
    $w1 = walk($path, $tokens['pg1'], 50);
    expectCase($w1['ok'] && count($w1['rows']) === 1 && $w1['metas'][0]['total'] === 1, "$path as pg1: exactly 1 authorized row (no cross access)");
    $w0 = requestApi('GET', "$path?per_page=50", $tokens['pg0']);
    expectCase($w0[0] === 200 && rowsOf($w0[1]) === [] && metaOf($w0[1])['total'] === 0 && metaOf($w0[1])['has_more'] === false, "$path as pg0 (0 relations): empty page, total 0");
}

/* ---- Vistas mínimas: contrato y compatibilidad ---- */
$minimal = [
    '/api/reportes' => ['id', 'reporte_id', 'nombre', 'descripcion', 'activo'],
    '/api/empresas' => ['id', 'nombre', 'razon_social', 'ruc', 'ciudad', 'pais', 'logo', 'activo'],
    '/api/asignacion' => ['id', 'empresa_id', 'empresa_nombre', 'reporte_id', 'reporte_nombre', 'activo'],
];
$forbidden = '/enlace|created_by|updated_by|powerbi_|clientsecret|fixture-private|example\.invalid|notas|telefono|email|contacto_principal|direccion|website/i';
foreach ($minimal as $path => $allowed) {
    foreach (['pg20' => $tokens['pg20'], 'admin' => $admin] as $who => $t) {
        [$s, $j, $raw] = requestApi('GET', "$path?view=minimal&per_page=100", $t);
        $rows = rowsOf($j);
        $keysOk = count($rows) > 0; foreach ($rows as $r) { if (array_diff(array_keys($r), $allowed)) $keysOk = false; }
        expectCase($s === 200 && $keysOk, "$path?view=minimal ($who): only whitelisted fields");
        expectCase(!preg_match($forbidden, $raw), "$path?view=minimal ($who): no technical or contact data anywhere in the response");
        [$s, $j2] = requestApi('GET', "$path?per_page=100", $t);
        expectCase(ids(rowsOf($j2)) === ids($rows), "$path?view=minimal ($who): same authorized rows and order as the full view");
    }
    [$s] = requestApi('GET', "$path?view=full", $tokens['pg20']);
    expectCase($s === 400, "$path rejects an unknown view");
    [$s] = requestApi('GET', "$path?view=minimal", null);
    expectCase($s === 401, "$path?view=minimal still requires authentication");
}
[$s, $j] = requestApi('GET', '/api/reportes?per_page=5', $tokens['pg20']);
expectCase(isset(rowsOf($j)[0]['enlace'], rowsOf($j)[0]['created_by_username']), 'compatibility: /api/reportes without view still returns the full legacy fields for existing clients');
[$s, $j] = requestApi('GET', '/api/empresas?per_page=5', $tokens['pg20']);
expectCase(isset(rowsOf($j)[0]['powerbi_tenant']) && !isset(rowsOf($j)[0]['powerbi_clientsecret']), 'compatibility: /api/empresas without view keeps legacy fields and still never returns the client secret');
[$s, $j] = requestApi('GET', '/api/reportes/reporteid/00000000-0000-0000-0001-000000000120?view=minimal', $tokens['pg20']);
$keys = array_keys($j['data'] ?? []); sort($keys);
expectCase($s === 200 && $keys === ['activo', 'descripcion', 'id', 'nombre', 'reporte_id'], 'single report by UUID supports the minimal view');
[$s, $j] = requestApi('GET', '/api/reportes/reporteid/00000000-0000-0000-0001-000000000150?view=minimal', $tokens['pg20']);
expectCase($s === 404 && !isset($j['data']), 'report outside the user authorization is 404 in minimal view too');
[$s, $j] = requestApi('GET', '/api/empresas/150?view=minimal', $tokens['pg20']);
expectCase(empty($j['success']) && !preg_match('/PG Empresa 150/', json_encode($j)), 'company outside the user authorization is not disclosed in minimal view');

/* ---- Composición provisional del catálogo (reportes + asignaciones + empresas) ---- */
foreach (['pg20', 'pg45', 'pg105', 'pgmix'] as $name) {
    $t = $tokens[$name];
    $rep = walk('/api/reportes', $t, 100, '&view=minimal')['rows'];
    $asg = walk('/api/asignacion', $t, 100, '&view=minimal')['rows'];
    $emp = walk('/api/empresas', $t, 100, '&view=minimal')['rows'];
    $repIds = array_map(fn($r) => $r['id'], $rep); $empIds = array_map(fn($e) => $e['id'], $emp);
    $paired = []; foreach ($asg as $a) { $paired[$a['reporte_id']][] = $a['empresa_id']; }
    $everyReportHasPair = count(array_diff($repIds, array_keys($paired))) === 0;
    $everyPairAuthorized = count(array_diff(array_keys($paired), $repIds)) === 0;
    $everyCompanyKnown = true; foreach ($asg as $a) { if (!in_array($a['empresa_id'], $empIds, true)) $everyCompanyKnown = false; }
    $noDuplicates = count($asg) === count(array_unique(array_map(fn($a) => $a['empresa_id'] . '-' . $a['reporte_id'], $asg)));
    expectCase($everyReportHasPair && $everyPairAuthorized && $everyCompanyKnown && $noDuplicates, "catalog composition for $name: every report has its company pair, every pair is authorized and every company is visible, no duplicated pairs (" . count($rep) . ' reports)');
}

echo "Failures: $failures of $cases\n";
exit($failures > 0 ? 1 : 0);
