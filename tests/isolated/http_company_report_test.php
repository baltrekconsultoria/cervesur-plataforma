<?php
// UX-07B: administración de empresas, informes y asociaciones empresa-informe. Solo red Docker aislada, datos ficticios y MySQL desechable.
// Se ejecuta DESPUÉS de http_test.php, la paginación, SEC-05A y UX-07A.
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
function tokenOf($name, $password = 'synthetic-password') { [, $d] = requestApi('POST', '/api/login', ['login' => $name, 'password' => $password]); return $d['token'] ?? ''; }
function scalar($pdo, $sql, $params = []) { $st = $pdo->prepare($sql); $st->execute($params); return $st->fetchColumn(); }
function row($pdo, $sql, $params = []) { $st = $pdo->prepare($sql); $st->execute($params); return $st->fetch(); }
function sortedKeys($r) { $k = array_keys($r); sort($k); return $k; }

$admin = tokenOf('admin');
$userA = tokenOf('usera');
$adminId = (int)scalar($pdo, "SELECT id FROM user WHERE username = 'admin'");

// ================= EMPRESAS =================
$company = ['nombre' => 'Empresa C Sintética', 'razon_social' => 'Empresa C Sintética S.A.C.', 'ruc' => '20999999991', 'ciudad' => 'Ciudad C', 'pais' => 'Demo', 'email' => 'contacto.c@example.invalid', 'telefono' => '999 000 111', 'direccion' => 'Calle Falsa 123'];
[$s] = requestApi('POST', '/api/empresas', $company);
expectCase($s === 401, 'anonymous cannot create a company');
[$s] = requestApi('POST', '/api/empresas', $company, $userA);
expectCase($s === 403 && (int)scalar($pdo, "SELECT COUNT(*) FROM empresa WHERE ruc = '20999999991'") === 0, 'non-admin cannot create a company');
foreach ([['nombre', ''], ['razon_social', ''], ['ruc', '123'], ['ruc', '2099999999A'], ['email', 'no-es-correo']] as [$field, $bad]) {
    [$s, $d] = requestApi('POST', '/api/empresas', [$field => $bad] + $company, $admin);
    expectCase(($d['success'] ?? true) === false && (int)scalar($pdo, "SELECT COUNT(*) FROM empresa WHERE ruc = '20999999991'") === 0, "company with invalid $field is rejected and nothing is written");
}
[$s, $d] = requestApi('POST', '/api/empresas', $company, $admin);
$cid = (int)($d['id'] ?? 0);
expectCase(($d['success'] ?? false) === true && $cid > 0, 'admin creates a company');
$stored = row($pdo, 'SELECT * FROM empresa WHERE id = ?', [$cid]);
expectCase($stored && (int)$stored['activo'] === 1 && (int)$stored['created_by'] === $adminId && $stored['ruc'] === '20999999991' && $stored['powerbi_clientsecret'] === null, 'the company is stored active, with the creator and without Power BI credentials');
[$s, $d] = requestApi('POST', '/api/empresas', ['nombre' => 'Otra'] + $company, $admin);
expectCase(($d['success'] ?? true) === false && stripos($d['message'] ?? '', 'RUC') !== false && (int)scalar($pdo, "SELECT COUNT(*) FROM empresa WHERE ruc = '20999999991'") === 1, 'a duplicated RUC is rejected');

[$s, $d, $raw] = requestApi('GET', "/api/empresas/$cid?view=form", null, $admin);
expectCase($s === 200 && sortedKeys($d['data'] ?? []) === ['activo', 'ciudad', 'direccion', 'email', 'id', 'nombre', 'pais', 'razon_social', 'ruc', 'telefono'] && stripos($raw, 'powerbi') === false, 'view=form returns the editable fields and no Power BI data');
[$s] = requestApi('GET', "/api/empresas/$cid?view=form", null, $userA);
expectCase($s === 403, 'a normal user cannot request view=form');
[$s] = requestApi('GET', "/api/empresas/$cid?view=otra", null, $admin);
expectCase($s === 400, 'an unknown view is rejected');
[$s, $d] = requestApi('GET', '/api/empresas/1?view=minimal', null, $userA);
expectCase($s === 200 && !isset($d['data']['email']) && !isset($d['data']['telefono']), 'view=minimal keeps hiding contact data from normal users');

$pdo->prepare("UPDATE empresa SET powerbi_tenant = 'tenant-sintetico', powerbi_client = 'client-sintetico', powerbi_clientsecret = 'fixture-private-ux07b' WHERE id = ?")->execute([$cid]);
[$s] = requestApi('PUT', "/api/empresas/$cid", ['ciudad' => 'Nueva Ciudad'], $userA);
expectCase($s === 403 && scalar($pdo, 'SELECT ciudad FROM empresa WHERE id = ?', [$cid]) === 'Ciudad C', 'non-admin cannot edit a company');
[$s, $d] = requestApi('PUT', "/api/empresas/$cid", ['ciudad' => 'Nueva Ciudad', 'telefono' => '988 777 666', 'email' => 'nuevo@example.invalid'], $admin);
$after = row($pdo, 'SELECT * FROM empresa WHERE id = ?', [$cid]);
expectCase(($d['success'] ?? false) === true && $after['ciudad'] === 'Nueva Ciudad' && $after['telefono'] === '988 777 666' && (int)$after['updated_by'] === $adminId, 'admin edits non-sensitive company data and the editor is recorded');
expectCase($after['powerbi_tenant'] === 'tenant-sintetico' && $after['powerbi_client'] === 'client-sintetico' && $after['powerbi_clientsecret'] === 'fixture-private-ux07b', 'editing without Power BI fields leaves the Power BI data untouched');
foreach (['nombre', 'razon_social'] as $field) {
    [$s, $d] = requestApi('PUT', "/api/empresas/$cid", [$field => '   '], $admin);
    expectCase(($d['success'] ?? true) === false && trim((string)scalar($pdo, "SELECT $field FROM empresa WHERE id = ?", [$cid])) !== '', "a required field ($field) cannot be emptied on edit");
}
[$s, $d] = requestApi('PUT', "/api/empresas/$cid", ['ruc' => '00000000001'], $admin);
expectCase(($d['success'] ?? true) === false && scalar($pdo, 'SELECT ruc FROM empresa WHERE id = ?', [$cid]) === '20999999991', 'editing to the RUC of another company is rejected');
[$s, $d] = requestApi('PUT', "/api/empresas/$cid", ['ruc' => '20999999991', 'nombre' => 'Empresa C Renombrada'], $admin);
expectCase(($d['success'] ?? false) === true && scalar($pdo, 'SELECT nombre FROM empresa WHERE id = ?', [$cid]) === 'Empresa C Renombrada', 'keeping its own RUC while editing is allowed');
[$s, $d] = requestApi('PUT', '/api/empresas/987654', ['ciudad' => 'X'], $admin);
expectCase(($d['success'] ?? true) === false, 'editing a non-existent company fails');

// ================= INFORMES =================
$uuidReport = '11111111-2222-3333-4444-555555555555';
$uuidGroup = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
// UX-07C: el informe se registra con su enlace; los identificadores se extraen del enlace (inserción), no se piden aparte.
$report = ['nombre' => 'Informe Sintético C', 'descripcion' => 'Prueba UX-07B', 'enlace' => 'https://app.powerbi.com/reportEmbed?reportId=' . strtoupper($uuidReport) . '&groupId=' . $uuidGroup];
[$s] = requestApi('POST', '/api/reportes', $report, $userA);
expectCase($s === 403, 'non-admin cannot create a report');
$beforeCount = (int)scalar($pdo, 'SELECT COUNT(*) FROM reporte');
foreach ([['nombre', ''], ['enlace', ''], ['enlace', 'esto no es una url'], ['enlace', 'https://example.invalid/x'], ['enlace', 'http://app.powerbi.com/reportEmbed?reportId=' . $uuidReport], ['enlace', 'https://app.powerbi.com/reportEmbed?reportId=123'], ['reporte_id', '123']] as [$field, $bad]) {
    [$s, $d] = requestApi('POST', '/api/reportes', [$field => $bad] + $report, $admin);
    expectCase(($d['success'] ?? true) === false && (int)scalar($pdo, 'SELECT COUNT(*) FROM reporte') === $beforeCount, "report with invalid $field is rejected and nothing is written");
}
[$s, $d] = requestApi('POST', '/api/reportes', $report, $admin);
$rid = (int)($d['data']['id'] ?? 0);
expectCase(($d['success'] ?? false) === true && $rid > 0, 'admin creates a report and receives its internal id');
$stored = row($pdo, 'SELECT * FROM reporte WHERE id = ?', [$rid]);
expectCase($stored && $stored['reporte_id'] === $uuidReport && (int)$stored['activo'] === 1 && (int)$stored['created_by'] === $adminId, 'the Power BI UUID is stored in lowercase, active, with the creator');
[$s, $d] = requestApi('POST', '/api/reportes', ['nombre' => 'Duplicado'] + $report, $admin);
expectCase(($d['success'] ?? true) === false && stripos($d['message'] ?? '', 'ID de Power BI') !== false && (int)scalar($pdo, 'SELECT COUNT(*) FROM reporte WHERE reporte_id = ?', [$uuidReport]) === 1, 'a duplicated Power BI UUID is rejected');

[$s, $d, $raw] = requestApi('GET', "/api/reportes/$rid?view=form", null, $admin);
expectCase($s === 200 && sortedKeys($d['data'] ?? []) === ['activo', 'descripcion', 'enlace', 'grupo_id', 'id', 'nombre', 'reporte_id'] && (int)$d['data']['id'] === $rid, 'view=form returns the editable fields with the internal id and the UUID');
[$s] = requestApi('GET', "/api/reportes/reporteid/$uuidReport?view=form", null, $admin);
expectCase($s === 200, 'view=form also works by Power BI UUID for admins');
[$s] = requestApi('GET', "/api/reportes/$rid?view=form", null, $userA);
expectCase($s === 403, 'a normal user cannot request the report form view (no technical link)');
[$s] = requestApi('GET', "/api/reportes/reporteid/$uuidReport?view=form", null, $userA);
expectCase($s === 403, 'a normal user cannot request the form view by UUID either');
[$s, $d, $raw] = requestApi('GET', '/api/reportes?view=minimal&per_page=100', null, $userA);
expectCase($s === 200 && strpos($raw, 'enlace') === false && strpos($raw, 'example.invalid') === false, 'normal users never receive report links in the minimal view');

[$s] = requestApi('PUT', "/api/reportes/$rid", ['nombre' => 'X'] + $report, $userA);
expectCase($s === 403, 'non-admin cannot edit a report');
[$s, $d] = requestApi('PUT', "/api/reportes/$rid", ['nombre' => 'Informe Sintético C v2', 'descripcion' => 'Editado'] + $report, $admin);
$after = row($pdo, 'SELECT * FROM reporte WHERE id = ?', [$rid]);
expectCase(($d['success'] ?? false) === true && $after['nombre'] === 'Informe Sintético C v2' && $after['descripcion'] === 'Editado', 'admin edits a report');
expectCase((int)$after['updated_by'] === $adminId, 'the editor is recorded in updated_by (previously always NULL)');
[$s, $d] = requestApi('PUT', "/api/reportes/$rid", ['nombre' => 'Solo nombre'], $admin);
expectCase(($d['success'] ?? true) === false && scalar($pdo, 'SELECT nombre FROM reporte WHERE id = ?', [$rid]) === 'Informe Sintético C v2', 'a partial edit is rejected with a clear message and changes nothing');
[$s, $d] = requestApi('PUT', "/api/reportes/$rid", ['enlace' => 'https://app.powerbi.com/reportEmbed?reportId=00000000-0000-0000-0000-000000000011'] + $report, $admin);
expectCase(($d['success'] ?? true) === false && scalar($pdo, 'SELECT reporte_id FROM reporte WHERE id = ?', [$rid]) === $uuidReport, 'editing to the UUID of another report is rejected');
[$s, $d] = requestApi('PUT', '/api/reportes/987654', $report, $admin);
expectCase(($d['success'] ?? true) === false, 'editing a non-existent report fails');
[$s, $d] = requestApi('PUT', "/api/reportes/$rid", ['enlace' => 'https://app.powerbi.com/reportEmbed?reportId=11111111-2222-3333-4444-555555555556&groupId=' . $uuidGroup] + $report, $admin);
expectCase(($d['success'] ?? false) === true && scalar($pdo, 'SELECT reporte_id FROM reporte WHERE id = ?', [$rid]) === '11111111-2222-3333-4444-555555555556', 'the Power BI UUID of a report can be corrected');
requestApi('PUT', "/api/reportes/$rid", $report, $admin);

// ================= ASOCIACIONES EMPRESA-INFORME =================
[$s, $d] = requestApi('POST', '/api/empresas', ['nombre' => 'Empresa D Sintética', 'razon_social' => 'Empresa D S.A.C.', 'ruc' => '20999999992'], $admin);
$did = (int)($d['id'] ?? 0);
[$s] = requestApi('POST', '/api/asignacion', ['empresa_id' => $cid, 'reporte_id' => $rid], $userA);
expectCase($s === 403, 'non-admin cannot associate a report with a company');
[$s, $d] = requestApi('POST', '/api/asignacion', ['empresa_id' => $cid, 'reporte_id' => $rid], $admin);
$linkC = (int)($d['data']['id'] ?? 0);
expectCase($s === 201 && ($d['success'] ?? false) === true && $linkC > 0 && (int)scalar($pdo, 'SELECT COUNT(*) FROM empresa_reporte WHERE empresa_id = ? AND reporte_id = ? AND activo = 1', [$cid, $rid]) === 1, 'admin associates the report with Empresa C using the numeric ids');
[$s, $d] = requestApi('POST', '/api/asignacion', ['empresa_id' => $cid, 'reporte_id' => $rid], $admin);
expectCase($s === 400 && ($d['success'] ?? true) === false && stripos($d['message'] ?? '', 'Ya existe') !== false && (int)scalar($pdo, 'SELECT COUNT(*) FROM empresa_reporte WHERE empresa_id = ? AND reporte_id = ?', [$cid, $rid]) === 1, 'a duplicate association is rejected with a specific message and not written');
[$s, $d] = requestApi('POST', '/api/asignacion', ['empresa_id' => $did, 'reporte_id' => $rid], $admin);
$linkD = (int)($d['data']['id'] ?? 0);
expectCase(($d['success'] ?? false) === true && $linkD > 0 && $linkD !== $linkC, 'the same report can belong to several companies');
[$s, $d] = requestApi('GET', '/api/asignacion?view=minimal&per_page=100', null, $admin);
$pairs = array_map(fn($r) => $r['empresa_id'] . ':' . $r['reporte_id'] . ':' . $r['id'], array_filter($d['data']['data'] ?? [], fn($r) => (int)$r['reporte_id'] === $rid));
sort($pairs);
$expected = ["$cid:$rid:$linkC", "$did:$rid:$linkD"]; sort($expected);
expectCase($pairs === $expected, 'the association list exposes the relation id, the company id and the numeric report id');
foreach ([['empresa_id' => 987654, 'reporte_id' => $rid], ['empresa_id' => $cid, 'reporte_id' => 987654], ['empresa_id' => $cid, 'reporte_id' => $uuidReport], ['empresa_id' => 'abc', 'reporte_id' => $rid]] as $i => $bad) {
    [$s, $d] = requestApi('POST', '/api/asignacion', $bad, $admin);
    expectCase(($d['success'] ?? true) === false, "an association with unknown or mismatched identifiers is rejected (case $i)");
}
$pdo->exec("INSERT INTO empresa (nombre, razon_social, ruc, activo) VALUES ('Inactiva UX07B', 'Inactiva', '20999999993', 0)");
$inactiveCompany = (int)$pdo->lastInsertId();
[$s, $d] = requestApi('POST', '/api/asignacion', ['empresa_id' => $inactiveCompany, 'reporte_id' => $rid], $admin);
expectCase(($d['success'] ?? true) === false, 'an inactive company cannot receive reports');

// Efecto real en el usuario: acceso -> desvincular -> reasociar
requestApi('POST', '/api/register', ['first_name' => 'Operador', 'last_name' => 'C', 'username' => 'operador.c', 'email' => 'operador.c@example.invalid', 'password' => 'synthetic-password'], $admin);
$uid = (int)scalar($pdo, "SELECT id FROM user WHERE username = 'operador.c'");
requestApi('POST', '/api/empresa-usuario', ['user_id' => $uid, 'empresa_id' => $cid], $admin);
[$s, $d] = requestApi('POST', '/api/empresa-reporte-usuario', ['user_id' => $uid, 'empresa_reporte_id' => $linkC], $admin);
expectCase(($d['success'] ?? false) === true, 'the user receives access to the newly registered report through the UX-07A flow');
$operator = tokenOf('operador.c');
[$s, $d] = requestApi('GET', '/api/reportes?view=minimal&per_page=100', null, $operator);
expectCase(array_column($d['data'] ?? [], 'nombre') === ['Informe Sintético C'], 'the user sees only the new report');
[$s] = requestApi('GET', '/api/reportes/reporteid/00000000-0000-0000-0000-000000000011?view=minimal', null, $operator);
expectCase($s === 404 || $s === 403, "the user cannot open reports of other companies ($s)");

[$s, $d] = requestApi('DELETE', "/api/asignacion/$linkC", null, $userA);
expectCase($s === 403, 'non-admin cannot unlink a report');
[$s, $d] = requestApi('DELETE', "/api/asignacion/$linkC", null, $admin);
expectCase(($d['success'] ?? false) === true && (int)scalar($pdo, 'SELECT activo FROM empresa_reporte WHERE id = ?', [$linkC]) === 0 && (int)scalar($pdo, 'SELECT COUNT(*) FROM empresa_reporte WHERE id = ?', [$linkC]) === 1, 'unlinking is a soft delete');
[$s, $d] = requestApi('DELETE', "/api/asignacion/$linkC", null, $admin);
expectCase(($d['success'] ?? true) === false, 'unlinking twice reports failure');
[$s, $d] = requestApi('GET', '/api/reportes?view=minimal&per_page=100', null, $operator);
expectCase($s === 200 && count($d['data'] ?? []) === 0, 'after unlinking, the report disappears for the user');
[$s, $d] = requestApi('GET', "/api/reportes/reporteid/$uuidReport?view=minimal", null, $operator);
expectCase($s === 404 || $s === 403, "after unlinking, the user cannot open it ($s)");
[$s, $d] = requestApi('GET', '/api/reportes?view=minimal&per_page=100', null, $admin);
expectCase(in_array('Informe Sintético C', array_column($d['data'] ?? [], 'nombre'), true), 'the admin still sees the report (it is not deleted, only unlinked)');
[$s, $d] = requestApi('POST', '/api/asignacion', ['empresa_id' => $cid, 'reporte_id' => $rid], $admin);
expectCase($s === 201 && ($d['success'] ?? false) === true && (int)($d['data']['id'] ?? 0) === $linkC && (int)scalar($pdo, 'SELECT COUNT(*) FROM empresa_reporte WHERE empresa_id = ? AND reporte_id = ?', [$cid, $rid]) === 1 && (int)scalar($pdo, 'SELECT activo FROM empresa_reporte WHERE id = ?', [$linkC]) === 1, 're-associating reactivates the same relation instead of duplicating it');
[$s, $d] = requestApi('GET', '/api/reportes?view=minimal&per_page=100', null, $operator);
expectCase(array_column($d['data'] ?? [], 'nombre') === ['Informe Sintético C'], 'the access previously granted to the user is effective again after re-associating');

// Sin filtraciones técnicas
$pdo->exec('RENAME TABLE `empresa_reporte` TO `empresa_reporte__off`');
[, , $a] = requestApi('POST', '/api/asignacion', ['empresa_id' => $did, 'reporte_id' => $rid], $admin);
[, , $b] = requestApi('GET', '/api/asignacion?view=minimal', null, $admin);
$pdo->exec('RENAME TABLE `empresa_reporte__off` TO `empresa_reporte`');
expectCase(!preg_match('/SQLSTATE|PDOException|doesn.t exist|Base table|\.php|\bSELECT\b|fixture/i', $a . $b), 'database failures on the association paths reveal no internal detail');

echo $failures === 0 ? "\nAll company/report admin cases passed\n" : "\n$failures company/report admin case(s) failed\n";
exit($failures === 0 ? 0 : 1);
