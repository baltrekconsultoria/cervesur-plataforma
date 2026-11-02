<?php
// UX-07C: registro de informes por enlace, identificadores internos/externos y configuración autorizada del visor.
// Solo red Docker aislada, datos ficticios y MySQL desechable. Se ejecuta DESPUÉS de las demás suites HTTP.
$failures = 0;
$pdo = new PDO('mysql:host=db;dbname=fixture;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

function requestApi($method, $path, $body = null, $token = null) {
    $headers = ["Content-Type: application/json"];
    if ($token !== null) $headers[] = "Authorization: Bearer " . $token;
    $options = ['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 5]];
    if ($body !== null) $options['http']['content'] = json_encode($body);
    $raw = file_get_contents('http://app:8080' . $path, false, stream_context_create($options));
    $GLOBALS['lastResponse'] = substr($raw ?: '', 0, 400);
    $GLOBALS['lastHeaders'] = $http_response_header ?? [];
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
function headerValue($name) { foreach ($GLOBALS['lastHeaders'] as $h) if (stripos($h, $name . ':') === 0) return trim(substr($h, strlen($name) + 1)); return null; }
function runSqlFile($pdo, $path) {
    $sql = preg_replace('/^\s*--.*$/m', '', (string)file_get_contents($path));
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) $pdo->exec($statement);
}

$UUID_RE = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';
$PUBLIC = 'https://app.powerbi.com/view?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDE';
$PUBLIC2 = 'https://app.powerbi.com/view?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDI&pageName=ReportSection1';
// UX-08B: en producción `reporte.enlace` es UNIQUE; editar un informe para usar el enlace de otro es un conflicto, así que la edición usa un enlace propio.
$PUBLIC3 = 'https://app.powerbi.com/view?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDM';
$EMBED_ID = '77777777-8888-4999-8aaa-bbbbbbbbbbbb'; $EMBED_GROUP = 'cccccccc-dddd-4eee-8fff-000000000000';
$EMBED = "https://app.powerbi.com/reportEmbed?reportId=$EMBED_ID&groupId=$EMBED_GROUP&autoAuth=true";
$admin = tokenOf('admin');
$userA = tokenOf('usera');
$adminId = (int)scalar($pdo, "SELECT id FROM user WHERE username = 'admin'");

// ================= MIGRACIÓN (esquema que podría tener producción: grupo_id obligatorio y enlace de 255) =================
$migration = '/work/database/migrations/UX-07C-reporte-sin-ids-externos.up.sql';
$rollback = '/work/database/migrations/UX-07C-reporte-sin-ids-externos.down.sql';
expectCase(is_readable($migration) && is_readable($rollback), 'the UX-07C migration and its rollback are versioned');
$pdo->exec('ALTER TABLE reporte MODIFY grupo_id VARCHAR(36) NOT NULL');
$pdo->exec('ALTER TABLE reporte MODIFY enlace VARCHAR(255) NULL');
$before = (int)scalar($pdo, 'SELECT COUNT(*) FROM reporte');
[$s, $d] = requestApi('POST', '/api/reportes', ['nombre' => 'Sin migrar', 'enlace' => $PUBLIC], $admin);
expectCase(($d['success'] ?? true) === false && (int)scalar($pdo, 'SELECT COUNT(*) FROM reporte') === $before && !preg_match('/SQLSTATE|grupo_id|Column|PDO/i', $GLOBALS['lastResponse']), 'before the migration a link-only report is refused without leaking database detail');
$longToken = str_repeat('A', 400);
[$s, $d] = requestApi('POST', '/api/reportes', ['nombre' => 'Enlace largo', 'enlace' => "https://app.powerbi.com/view?r=$longToken"], $admin);
expectCase(($d['success'] ?? true) === false && stripos($d['message'] ?? '', 'migraci') !== false, 'before the migration a link longer than the column explains that the migration is required');
runSqlFile($pdo, $migration);
expectCase(scalar($pdo, "SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reporte' AND COLUMN_NAME = 'grupo_id'") === 'YES' && (int)scalar($pdo, "SELECT CHARACTER_MAXIMUM_LENGTH FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reporte' AND COLUMN_NAME = 'enlace'") === 1024, 'the migration makes grupo_id optional and widens the link to 1024');
expectCase((int)scalar($pdo, 'SELECT COUNT(*) FROM reporte') === $before, 'the migration keeps every existing row');
[$s, $d] = requestApi('POST', '/api/reportes', ['nombre' => 'Enlace largo', 'enlace' => "https://app.powerbi.com/view?r=$longToken"], $admin);
$longId = (int)($d['data']['id'] ?? 0);
expectCase(($d['success'] ?? false) === true && $longId > 0, 'after the migration a long public link is accepted');
[$s, $d] = requestApi('POST', '/api/reportes', ['nombre' => 'Rollback', 'enlace' => $PUBLIC], $admin);
$rollbackId = (int)($d['data']['id'] ?? 0);
$failed = false; try { runSqlFile($pdo, $rollback); } catch (PDOException $e) { $failed = true; }
expectCase($failed && scalar($pdo, "SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reporte' AND COLUMN_NAME = 'grupo_id'") !== false, 'the rollback refuses to run while link-only reports exist (no silent data loss)');
$pdo->prepare('DELETE FROM reporte WHERE id IN (?, ?)')->execute([$longId, $rollbackId]);
$failedAgain = false; try { runSqlFile($pdo, $rollback); } catch (PDOException $e) { $failedAgain = true; }
expectCase(!$failedAgain && scalar($pdo, "SELECT IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reporte' AND COLUMN_NAME = 'grupo_id'") === 'NO', 'the rollback works once no link-only report remains');
runSqlFile($pdo, $migration);
expectCase((int)scalar($pdo, "SELECT COUNT(*) FROM reporte WHERE id IN ($longId, $rollbackId)") === 0, 'cleanup of the migration probes');

// ================= REGISTRO POR ENLACE =================
$count = (int)scalar($pdo, 'SELECT COUNT(*) FROM reporte');
foreach ([
    'dominio externo' => 'https://example.invalid/view?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDE',
    'HTTP' => 'http://app.powerbi.com/view?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDE',
    'iframe pegado' => '<iframe src="https://app.powerbi.com/view?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDE"></iframe>',
    'host suplantado' => 'https://app.powerbi.com.evil.example/view?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDE',
    'userinfo' => 'https://app.powerbi.com@evil.example/view?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDE',
    'redirección en parámetro' => 'https://app.powerbi.com/view?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDE&redirect=https://evil.example',
    'javascript' => 'javascript:alert(1)',
    'inserción sin reportId' => 'https://app.powerbi.com/reportEmbed?groupId=cccccccc-dddd-4eee-8fff-000000000000',
    'enlace vacío' => '',
] as $label => $bad) {
    [$s, $d] = requestApi('POST', '/api/reportes', ['nombre' => 'Malicioso', 'enlace' => $bad], $admin);
    expectCase(($d['success'] ?? true) === false && (int)scalar($pdo, 'SELECT COUNT(*) FROM reporte') === $count, "unsafe or unsupported link is rejected and nothing is written ($label)");
}
[$s] = requestApi('POST', '/api/reportes', ['nombre' => 'X', 'enlace' => $PUBLIC], $userA);
expectCase($s === 403, 'non-admin cannot register reports');

[$s, $d] = requestApi('POST', '/api/reportes', ['nombre' => 'Informe público C', 'descripcion' => 'Solo enlace', 'enlace' => $PUBLIC], $admin);
$pubId = (int)($d['data']['id'] ?? 0);
$pub = row($pdo, 'SELECT * FROM reporte WHERE id = ?', [$pubId]);
expectCase(($d['success'] ?? false) === true && $pubId > 0 && $pub['enlace'] === $PUBLIC, 'a report is registered with only name, description and public link');
expectCase($pub['grupo_id'] === null && preg_match($UUID_RE, (string)$pub['reporte_id']) === 1 && (int)$pub['created_by'] === $adminId, 'no Power BI ids are invented: group stays NULL and the reference is an internal random UUID v4');
[$s, $d] = requestApi('POST', '/api/reportes', ['nombre' => 'Otro público', 'enlace' => $PUBLIC2], $admin);
$pub2Id = (int)($d['data']['id'] ?? 0);
expectCase(($d['success'] ?? false) === true && scalar($pdo, 'SELECT reporte_id FROM reporte WHERE id = ?', [$pub2Id]) !== $pub['reporte_id'], 'every link-only report gets its own internal reference');
[$s, $d] = requestApi('POST', '/api/reportes', ['nombre' => 'Informe inserción', 'enlace' => str_replace($EMBED_ID, strtoupper($EMBED_ID), $EMBED)], $admin);
$embId = (int)($d['data']['id'] ?? 0);
$emb = row($pdo, 'SELECT * FROM reporte WHERE id = ?', [$embId]);
expectCase(($d['success'] ?? false) === true && $emb['reporte_id'] === $EMBED_ID && $emb['grupo_id'] === $EMBED_GROUP, 'ids present in an embed link are extracted (lowercase) and stored');
[$s, $d] = requestApi('POST', '/api/reportes', ['nombre' => 'Duplicado inserción', 'enlace' => $EMBED], $admin);
expectCase(($d['success'] ?? true) === false && stripos($d['message'] ?? '', 'ID de Power BI') !== false, 'a second report with the same Power BI report id is rejected');
[$s, $d] = requestApi('POST', '/api/reportes', ['nombre' => 'Portal', 'enlace' => 'https://app.powerbi.com/reports/99999999-8888-4777-8666-555555555555'], $admin);
$portalRow = row($pdo, 'SELECT * FROM reporte WHERE id = ?', [(int)($d['data']['id'] ?? 0)]);
expectCase(($d['success'] ?? false) === true && $portalRow['reporte_id'] === '99999999-8888-4777-8666-555555555555' && $portalRow['grupo_id'] === null, 'a normal report link yields the report id and no group id');
[$s, $d] = requestApi('POST', '/api/reportes', ['nombre' => 'Contradictorio', 'enlace' => $EMBED, 'reporte_id' => '12345678-1234-4234-8234-123456789012'], $admin);
expectCase(($d['success'] ?? true) === false, 'explicit ids that contradict the link are rejected');

// ================= EDICIÓN: identidad estable =================
[$s, $d] = requestApi('POST', '/api/empresas', ['nombre' => 'Empresa Visor 1', 'razon_social' => 'Empresa Visor 1 S.A.C.', 'ruc' => '20888888881'], $admin);
$c1 = (int)$d['id'];
[$s, $d] = requestApi('POST', '/api/empresas', ['nombre' => 'Empresa Visor 2', 'razon_social' => 'Empresa Visor 2 S.A.C.', 'ruc' => '20888888882'], $admin);
$c2 = (int)$d['id'];
[$s, $d] = requestApi('POST', '/api/asignacion', ['empresa_id' => $c1, 'reporte_id' => $pubId], $admin);
$link1 = (int)($d['data']['id'] ?? 0);
expectCase($link1 > 0, 'a link-only report can be associated with a company (numeric ids)');
$refBefore = $pub['reporte_id'];
[$s, $d] = requestApi('PUT', "/api/reportes/$pubId", ['nombre' => 'Informe público C (editado)', 'descripcion' => 'Editado', 'enlace' => $PUBLIC3], $admin);
$pubAfter = row($pdo, 'SELECT * FROM reporte WHERE id = ?', [$pubId]);
expectCase(($d['success'] ?? false) === true && $pubAfter['enlace'] === $PUBLIC3 && (int)$pubAfter['id'] === $pubId, 'the public link can be edited');
expectCase($pubAfter['reporte_id'] === $refBefore && $pubAfter['grupo_id'] === null, 'editing the link keeps the internal reference and invents nothing');
expectCase((int)scalar($pdo, 'SELECT activo FROM empresa_reporte WHERE id = ?', [$link1]) === 1 && (int)scalar($pdo, 'SELECT reporte_id FROM empresa_reporte WHERE id = ?', [$link1]) === $pubId, 'the association survives the link edit (it uses the numeric id)');
[$s, $d] = requestApi('PUT', "/api/reportes/$pubId", ['nombre' => 'Informe público C (editado)', 'enlace' => "https://app.powerbi.com/reportEmbed?reportId=55555555-6666-4777-8888-999999999999"], $admin);
$pubEmbed = row($pdo, 'SELECT * FROM reporte WHERE id = ?', [$pubId]);
expectCase(($d['success'] ?? false) === true && $pubEmbed['reporte_id'] === '55555555-6666-4777-8888-999999999999' && (int)$pubEmbed['id'] === $pubId && (int)scalar($pdo, 'SELECT activo FROM empresa_reporte WHERE id = ?', [$link1]) === 1, 'switching to an embed link takes the ids from the link and keeps the numeric identity and the association');
requestApi('PUT', "/api/reportes/$pubId", ['nombre' => 'Informe público C', 'enlace' => $PUBLIC], $admin);
expectCase(scalar($pdo, 'SELECT reporte_id FROM reporte WHERE id = ?', [$pubId]) === '55555555-6666-4777-8888-999999999999', 'switching back to a public link keeps the stored reference (stable routes)');

// ================= VISOR: autorización =================
$ref = scalar($pdo, 'SELECT reporte_id FROM reporte WHERE id = ?', [$pubId]);
[$s] = requestApi('GET', "/api/reportes/reporteid/$ref/visor");
expectCase($s === 401, 'the viewer configuration requires authentication');
[$s, $d] = requestApi('POST', '/api/register', ['first_name' => 'Visor', 'last_name' => 'Uno', 'username' => 'visor.uno', 'email' => 'visor.uno@example.invalid', 'password' => 'synthetic-password'], $admin);
[$s, $d] = requestApi('POST', '/api/register', ['first_name' => 'Visor', 'last_name' => 'Dos', 'username' => 'visor.dos', 'email' => 'visor.dos@example.invalid', 'password' => 'synthetic-password'], $admin);
$u1 = (int)scalar($pdo, "SELECT id FROM user WHERE username = 'visor.uno'"); $u2 = (int)scalar($pdo, "SELECT id FROM user WHERE username = 'visor.dos'");
requestApi('POST', '/api/empresa-usuario', ['user_id' => $u1, 'empresa_id' => $c1], $admin);
requestApi('POST', '/api/empresa-usuario', ['user_id' => $u2, 'empresa_id' => $c2], $admin);
$tok1 = tokenOf('visor.uno'); $tok2 = tokenOf('visor.dos');
[$s, $d] = requestApi('GET', "/api/reportes/reporteid/$ref/visor", null, $tok1);
expectCase($s === 404 && ($d['success'] ?? true) === false, 'a user without an assignment gets no viewer configuration');
$notFoundBody = $GLOBALS['lastResponse'];
[$s, $d] = requestApi('GET', '/api/reportes/reporteid/00000000-0000-4000-8000-00000000dead/visor', null, $tok1);
expectCase($s === 404 && $GLOBALS['lastResponse'] === $notFoundBody, 'a non-existent report is indistinguishable from an unauthorized one');
[$s, $d] = requestApi('POST', '/api/empresa-reporte-usuario', ['user_id' => $u1, 'empresa_reporte_id' => $link1], $admin);
$access1 = (int)($d['InsertedId'] ?? 0);
expectCase(($d['success'] ?? false) === true, 'access to the link-only report is granted to the user');
[$s, $d, $raw] = requestApi('GET', "/api/reportes/reporteid/$ref/visor", null, $tok1);
expectCase($s === 200 && ($d['data']['modo'] ?? '') === 'publico' && ($d['data']['url'] ?? '') === $PUBLIC && ($d['data']['nombre'] ?? '') === 'Informe público C', 'the authorized user receives the public viewer configuration');
expectCase(sortedKeys($d['data'] ?? []) === ['descripcion', 'modo', 'nombre', 'reporte_id', 'url'] && !preg_match('/secret|token|password|powerbi_|tenant|client/i', $raw), 'the response contains only what the viewer needs (no secrets, credentials or tokens)');
expectCase(stripos((string)headerValue('Cache-Control'), 'no-store') !== false && stripos((string)headerValue('Pragma'), 'no-cache') !== false, 'the viewer configuration is served with no-store');
[$s, $d] = requestApi('GET', "/api/reportes/reporteid/$ref/visor", null, $tok2);
expectCase($s === 404, 'another user (other company) cannot obtain it');
[$s, $d] = requestApi('GET', "/api/reportes/reporteid/$ref/visor", null, $admin);
expectCase($s === 200 && ($d['data']['modo'] ?? '') === 'publico', 'an administrator can open any active report');
[$s, $d, $raw] = requestApi('GET', '/api/reportes?view=minimal&per_page=100', null, $tok1);
expectCase($s === 200 && strpos($raw, 'powerbi.com') === false && strpos($raw, 'enlace') === false, 'the normal catalog (minimal view) never carries the link');

// compartido entre empresas: los permisos se evalúan por empresa
[$s, $d] = requestApi('POST', '/api/asignacion', ['empresa_id' => $c2, 'reporte_id' => $pubId], $admin);
$link2 = (int)($d['data']['id'] ?? 0);
[$s, $d] = requestApi('POST', '/api/empresa-reporte-usuario', ['user_id' => $u2, 'empresa_reporte_id' => $link2], $admin);
[$s] = requestApi('GET', "/api/reportes/reporteid/$ref/visor", null, $tok2);
expectCase($s === 200, 'a report shared by two companies is visible to the user of the second company');
[$s, $d] = requestApi('DELETE', "/api/asignacion/$link1", null, $admin);
[$s] = requestApi('GET', "/api/reportes/reporteid/$ref/visor", null, $tok1);
$s2 = requestApi('GET', "/api/reportes/reporteid/$ref/visor", null, $tok2)[0];
expectCase($s === 404 && $s2 === 200, 'unlinking the report from company 1 blocks only company 1 users');
requestApi('POST', '/api/asignacion', ['empresa_id' => $c1, 'reporte_id' => $pubId], $admin);
[$s] = requestApi('GET', "/api/reportes/reporteid/$ref/visor", null, $tok1);
expectCase($s === 200, 're-associating restores the previous access (same relation reactivated)');

// acceso retirado
[$s, $d] = requestApi('DELETE', "/api/empresa-reporte-usuario/$access1", null, $admin);
[$s] = requestApi('GET', "/api/reportes/reporteid/$ref/visor", null, $tok1);
expectCase($s === 404, 'a revoked access blocks new viewer requests');
requestApi('POST', '/api/empresa-reporte-usuario', ['user_id' => $u1, 'empresa_reporte_id' => $link1], $admin);
[$s] = requestApi('GET', "/api/reportes/reporteid/$ref/visor", null, $tok1);
expectCase($s === 200, 'granting the access again restores the viewer');

// empresa inactiva, usuario inactivo, informe inactivo
$pdo->prepare('UPDATE empresa SET activo = 0 WHERE id = ?')->execute([$c1]);
[$s] = requestApi('GET', "/api/reportes/reporteid/$ref/visor", null, $tok1);
expectCase($s === 404, 'an inactive company blocks the viewer');
$pdo->prepare('UPDATE empresa SET activo = 1 WHERE id = ?')->execute([$c1]);
$pdo->prepare('UPDATE user SET is_active = 0 WHERE id = ?')->execute([$u1]);
[$s] = requestApi('GET', "/api/reportes/reporteid/$ref/visor", null, $tok1);
expectCase($s === 401, 'an inactive user cannot use an existing session');
$pdo->prepare('UPDATE user SET is_active = 1 WHERE id = ?')->execute([$u1]);

// modos privado y no compatible
[$s, $d] = requestApi('POST', '/api/asignacion', ['empresa_id' => $c1, 'reporte_id' => $embId], $admin);
$embLink = (int)($d['data']['id'] ?? 0);
requestApi('POST', '/api/empresa-reporte-usuario', ['user_id' => $u1, 'empresa_reporte_id' => $embLink], $admin);
[$s, $d, $raw] = requestApi('GET', "/api/reportes/reporteid/$EMBED_ID/visor", null, $tok1);
expectCase($s === 200 && ($d['data']['modo'] ?? '') === 'privado' && !isset($d['data']['url']) && strpos($raw, 'app.powerbi.com') === false, 'an authenticated-embed report reports "privado" and never leaks its link');
[$s, $d] = requestApi('GET', '/api/powerbi/tokens/' . $embLink, null, $tok1);
expectCase($s === 200, 'the legacy token endpoint keeps its historical behaviour for reports that do have Power BI ids (the React viewer never calls it)');
[$s] = requestApi('GET', "/api/powerbi/tokens/$link2", null, $tok2);
expectCase($s === 409, 'the legacy token endpoint refuses link-only reports (no Microsoft call is attempted)');
$legacyRef = scalar($pdo, "SELECT reporte_id FROM reporte WHERE nombre = 'Reporte A'");
$legacyEr = (int)scalar($pdo, "SELECT er.id FROM empresa_reporte er JOIN reporte r ON r.id = er.reporte_id WHERE r.nombre = 'Reporte A' AND er.activo = 1 LIMIT 1");
[$s, $d, $raw] = requestApi('GET', "/api/reportes/reporteid/$legacyRef/visor", null, $admin);
expectCase($s === 200 && ($d['data']['modo'] ?? '') === 'no_compatible' && !isset($d['data']['url']) && strpos($raw, 'example.invalid') === false, 'a legacy report whose stored link is not a supported Power BI link is "no_compatible" and its link is not returned');

// informe dado de baja
[$s, $d] = requestApi('DELETE', "/api/reportes/$pubId", null, $admin);
[$s] = requestApi('GET', "/api/reportes/reporteid/$ref/visor", null, $tok1);
$s2 = requestApi('GET', "/api/reportes/reporteid/$ref/visor", null, $admin)[0];
expectCase($s === 404 && $s2 === 404, 'a deactivated report cannot be opened by anyone');

// compatibilidad del contrato histórico
[$s, $d] = requestApi('GET', "/api/reportes/reporteid/$EMBED_ID", null, $tok1);
expectCase($s === 200 && ($d['data']['reporte_id'] ?? '') === $EMBED_ID, 'the historical /reporteid/{uuid} route keeps working');
[$s, $d] = requestApi('GET', "/api/reportes/reporteid/$EMBED_ID/visorx", null, $tok1);
expectCase($s === 404, 'unknown sub-routes are not served');

echo $failures === 0 ? "\nAll viewer cases passed\n" : "\n$failures viewer case(s) failed\n";
exit($failures === 0 ? 0 : 1);
