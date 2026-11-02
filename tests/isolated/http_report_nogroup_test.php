<?php
// UX-08B3: informes registrados solo por su enlace «Publicar en la web» (sin grupo de Power BI) con `reporte.grupo_id` NULL.
// Migración mínima UX-08B3-reporte-grupo-nullable.{up,down}.sql (solo grupo_id: VARCHAR(50) NOT NULL <-> NULL). NO se aplica en producción.
// Solo red Docker aislada, identidades ficticias y MySQL desechable. Instancia `appw` (base `fixture_w`, DDL productivo, UNIQUE reales)
// con AUDIT_LOG_MODE=required y REACT_STRICT_VIEWS=1. Se ejecuta el ÚLTIMO (después de http_writes_test y http_access_writes_test):
// al terminar RESTAURA `grupo_id NOT NULL`, borra (SQL directo, base desechable) las filas que añade y revierte `audit_log`.
//
// NO VERIFICADO (fuera del alcance de esta prueba): cómo trata la app Flutter un `grupo_id` NULL (parseo JSON tipado, `String` no nulable,
// rutas /reportes/grupoid/{uuid}, flujo /api/powerbi/* que exige grupo_id y reporte_id). El backend solo se ha probado por HTTP.
// QA propuesto antes de producción: (1) con la migración aplicada SOLO en un entorno de pruebas, crear en React un informe por enlace
// «Publicar en la web»; (2) abrir la app Flutter con un trabajador asignado y comprobar listado, detalle y apertura del informe sin
// cierres inesperados; (3) revisar el log de errores de Flutter por TypeError/null en `grupo_id`; (4) confirmar que Flutter no usa
// /api/powerbi/* para ese informe (ese flujo exige grupo_id y responde error controlado); (5) si Flutter falla, no aplicar la migración.
$failures = 0;
$pdo = new PDO('mysql:host=db;dbname=fixture_w;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
const BASE = 'http://appw:8080';
const UP = 'database/migrations/UX-08B3-reporte-grupo-nullable.up.sql';
const DOWN = 'database/migrations/UX-08B3-reporte-grupo-nullable.down.sql';
const UUID4 = '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/';

function requestApi($method, $path, $body = null, $token = null, $strict = false) {
    $headers = ['Content-Type: application/json'];
    if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
    if ($strict) $headers[] = 'X-API-Contract: 2';
    $options = ['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 10]];
    if ($body !== null) $options['http']['content'] = json_encode($body);
    $raw = file_get_contents(BASE . $path, false, stream_context_create($options));
    $GLOBALS['lastResponse'] = substr($raw ?: '', 0, 400);
    $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int)$m[1] : 0;
    return [$status, json_decode($raw ?: '', true), $raw ?: ''];
}
function expectCase($condition, $label) {
    global $failures;
    if ($condition) echo "PASS $label\n"; else { echo "FAIL $label\n  última respuesta: " . ($GLOBALS['lastResponse'] ?? '') . "\n"; $failures++; }
}
function tokenOf($name) { [, $d] = requestApi('POST', '/api/login', ['login' => $name, 'password' => 'synthetic-password']); return $d['token'] ?? ''; }
function one($pdo, $sql, $params = []) { $st = $pdo->prepare($sql); $st->execute($params); return $st->fetch(); }
function count_of($pdo, $table, $where = '1=1', $params = []) { return (int)one($pdo, "SELECT COUNT(*) c FROM $table WHERE $where", $params)['c']; }
function audits($pdo, $id) { $st = $pdo->prepare("SELECT * FROM audit_log WHERE entity = 'reporte' AND entity_id = ? ORDER BY id"); $st->execute([$id]); return $st->fetchAll(); }
function publicLink($n) { return 'https://app.powerbi.com/view?r=' . str_pad('NoGroupToken' . $n, 24, 'x') . '_-'; }
function embedLink($n) { return 'https://app.powerbi.com/reportEmbed?reportId=dddddddd-0000-4000-8000-0000000000' . sprintf('%02d', $n) . '&groupId=bbbbbbbb-0000-4000-8000-000000000002'; }

/** Hash por fila (todas las columnas) de las filas de `reporte`, clave = id. */
function rowHashes($pdo) {
    $out = [];
    foreach ($pdo->query('SELECT * FROM reporte ORDER BY id')->fetchAll() as $row) $out[$row['id']] = md5(json_encode($row));
    return $out;
}
/** Estructura de `reporte` (columnas + índices + FKs) tal como la ve information_schema. */
function schemaOf($pdo) {
    $cols = $pdo->query("SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, CHARACTER_SET_NAME, COLLATION_NAME, COLUMN_DEFAULT, COLUMN_KEY, EXTRA FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reporte' ORDER BY ORDINAL_POSITION")->fetchAll();
    $idx = $pdo->query("SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reporte' ORDER BY INDEX_NAME, SEQ_IN_INDEX")->fetchAll();
    $fks = $pdo->query("SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reporte' ORDER BY CONSTRAINT_NAME, COLUMN_NAME")->fetchAll();
    return ['cols' => $cols, 'idx' => $idx, 'fks' => $fks];
}
function col($pdo, $name) { return one($pdo, "SELECT COLUMN_TYPE, IS_NULLABLE, CHARACTER_SET_NAME, COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reporte' AND COLUMN_NAME = ?", [$name]); }
/** Sentencias de un archivo SQL (sin comentarios `--`), tal como las ejecutaría el cliente mysql una a una. */
function statementsOf($file) {
    $sql = implode("\n", array_filter(explode("\n", file_get_contents($file)), fn ($l) => strncmp(ltrim($l), '--', 2) !== 0));
    return array_values(array_filter(array_map('trim', explode(";\n", $sql . "\n"))));
}
/** Ejecuta un archivo sentencia por sentencia y se detiene en el primer error. Devuelve el código de error MySQL (0 = sin error). */
function runSql($pdo, $file) {
    foreach (statementsOf($file) as $stmt) {
        try { $st = $pdo->query($stmt); if ($st) { $st->fetchAll(); $st->closeCursor(); } }
        catch (PDOException $e) { return (int)($e->errorInfo[1] ?? -1); }
    }
    return 0;
}

$admin = tokenOf('admin');
$worker = tokenOf('usera');
$other = tokenOf('userb');
$sqlMode = one($pdo, 'SELECT @@SESSION.sql_mode m')['m'];
$restoreMode = fn () => $pdo->exec('SET SESSION sql_mode = ' . $pdo->quote($sqlMode));
$pdo->exec('DROP TRIGGER IF EXISTS audit_block');
$pdo->exec('DROP TABLE IF EXISTS audit_log');
$baseline = rowHashes($pdo);                       // filas históricas (con grupo)
$schema0 = schemaOf($pdo);
$created = [];

/* ---------- 0. Archivos versionados y esquema productivo de partida ---------- */
expectCase(is_readable(UP) && is_readable(DOWN), 'the UX-08B3 migration pair is versioned');
$upStatements = statementsOf(UP);
expectCase(count($upStatements) === 1 && $upStatements[0] === 'ALTER TABLE reporte MODIFY grupo_id VARCHAR(50) NULL', 'the up migration is a single ALTER touching only grupo_id');
$g0 = col($pdo, 'grupo_id');
expectCase($g0['COLUMN_TYPE'] === 'varchar(50)' && $g0['IS_NULLABLE'] === 'NO', 'starting schema is the productive one: grupo_id varchar(50) NOT NULL');
expectCase(col($pdo, 'enlace')['COLUMN_TYPE'] === 'varchar(500)' && col($pdo, 'enlace')['IS_NULLABLE'] === 'NO', 'starting schema: enlace varchar(500) NOT NULL');
$pdo->exec(file_get_contents('database/migrations/UX-08B-audit-log.up.sql'));

/* ---------- 1. Esquema antiguo: se rechaza con el mensaje explicativo ---------- */
$before = count_of($pdo, 'reporte');
[$s, $d] = requestApi('POST', '/api/reportes', ['nombre' => 'Sin grupo', 'descripcion' => 'x', 'enlace' => publicLink(1)], $admin);
expectCase($s === 200 && ($d['success'] ?? true) === false && str_contains($d['message'] ?? '', 'ID del grupo') && str_contains($d['message'] ?? '', 'No se inventan identificadores'), 'old schema: a public link without group is rejected with the explanatory message');
[$s] = requestApi('POST', '/api/reportes', ['nombre' => 'Sin grupo', 'descripcion' => 'x', 'enlace' => publicLink(1)], $admin, true);
expectCase($s >= 400 && $s < 500 && $s !== 409, 'old schema, contract 2: the rejection is a validation error (4xx)');
expectCase(count_of($pdo, 'reporte') === $before && count_of($pdo, 'audit_log') === 0 && rowHashes($pdo) === $baseline, 'old schema: nothing inserted, no audit row, historic rows intact');

/* ---------- 2. Migración UP ---------- */
expectCase(runSql($pdo, UP) === 0, 'the up migration runs statement by statement without error');
$schema1 = schemaOf($pdo);
$g1 = col($pdo, 'grupo_id');
expectCase($g1['COLUMN_TYPE'] === 'varchar(50)' && $g1['IS_NULLABLE'] === 'YES' && $g1['CHARACTER_SET_NAME'] === $g0['CHARACTER_SET_NAME'] && $g1['COLLATION_NAME'] === $g0['COLLATION_NAME'], 'after up: grupo_id is varchar(50) NULL with the same charset and collation');
$expected = $schema0;
foreach ($expected['cols'] as &$c) if ($c['COLUMN_NAME'] === 'grupo_id') $c['IS_NULLABLE'] = 'YES';
unset($c);
expectCase($schema1 === $expected, 'after up: every other column, the UNIQUE indexes (enlace, reporte_id), indexes and FKs are exactly as before');
expectCase(rowHashes($pdo) === $baseline, 'after up: historic rows are byte-for-byte unchanged (per-row hash)');
expectCase(runSql($pdo, UP) === 0 && schemaOf($pdo) === $schema1 && rowHashes($pdo) === $baseline, 'the up migration is idempotent (re-run changes nothing)');

/* ---------- 3. Alta sin grupo ---------- */
[$s, $d] = requestApi('POST', '/api/reportes', ['nombre' => 'Informe web', 'descripcion' => 'Solo enlace', 'enlace' => publicLink(1)], $admin);
$rid = (int)($d['data']['id'] ?? 0); $created[] = $rid;
$row = $rid ? one($pdo, 'SELECT * FROM reporte WHERE id = ?', [$rid]) : [];
expectCase($s === 200 && ($d['success'] ?? false) === true && $rid > 0, 'a public-link report without group is created');
expectCase(array_key_exists('grupo_id', $row) && $row['grupo_id'] === null && preg_match(UUID4, $row['reporte_id'] ?? '') === 1 && (int)($row['activo'] ?? 0) === 1 && $row['reporte_id'] === ($d['data']['reporte_id'] ?? ''), 'stored with grupo_id NULL, an internal UUID v4 reporte_id and activo=1 (no group invented)');
$log = audits($pdo, $rid);
$after = json_decode($log[0]['after_json'] ?? '{}', true);
expectCase(count($log) === 1 && $log[0]['action'] === 'reporte.create' && array_key_exists('grupo_id', $after) && $after['grupo_id'] === null && $after['reporte_id'] === $row['reporte_id'] && !isset($after['enlace']) && strpos($log[0]['after_json'], 'powerbi.com') === false, 'the audit row exists, records grupo_id null and never the link');
$ref1 = $row['reporte_id'];
[$s, $d] = requestApi('POST', '/api/reportes', ['nombre' => 'Copia', 'descripcion' => 'x', 'enlace' => publicLink(1)], $admin);
expectCase($s === 200 && ($d['success'] ?? true) === false && ($d['message'] ?? '') === 'Ya existe un informe con ese enlace', 'legacy contract: a duplicate link is 200 + success:false');
[$s] = requestApi('POST', '/api/reportes', ['nombre' => 'Copia', 'descripcion' => 'x', 'enlace' => publicLink(1)], $admin, true);
expectCase($s === 409 && count_of($pdo, 'reporte', 'enlace = ?', [publicLink(1)]) === 1, 'contract 2: a duplicate link is HTTP 409 and no extra row exists');
[$s] = requestApi('POST', '/api/reportes', ['nombre' => 'Dup uuid', 'descripcion' => 'x', 'enlace' => publicLink(2), 'reporte_id' => $ref1], $admin, true);
expectCase($s === 409 && count_of($pdo, 'reporte', 'enlace = ?', [publicLink(2)]) === 0, 'contract 2: an explicit reporte_id that already exists is HTTP 409');
[$s, $d] = requestApi('POST', '/api/reportes', ['nombre' => 'Con grupo', 'descripcion' => 'x', 'enlace' => embedLink(1)], $admin, true);
$rid2 = (int)($d['data']['id'] ?? 0); $created[] = $rid2;
$row2 = $rid2 ? one($pdo, 'SELECT * FROM reporte WHERE id = ?', [$rid2]) : [];
expectCase($s === 200 && ($row2['grupo_id'] ?? '') === 'bbbbbbbb-0000-4000-8000-000000000002' && ($row2['reporte_id'] ?? '') === 'dddddddd-0000-4000-8000-000000000001', 'an embed link that carries its group still stores that group and its report id');
[$s, $d] = requestApi('POST', '/api/reportes', ['nombre' => 'Grupo explícito', 'descripcion' => 'x', 'enlace' => publicLink(3), 'grupo_id' => 'bbbbbbbb-0000-4000-8000-000000000009'], $admin, true);
$ridE = (int)($d['data']['id'] ?? 0); $created[] = $ridE;
expectCase($s === 200 && $ridE > 0 && one($pdo, 'SELECT grupo_id FROM reporte WHERE id = ?', [$ridE])['grupo_id'] === 'bbbbbbbb-0000-4000-8000-000000000009', 'a public link with an explicit valid grupo_id keeps that group');
$long = 'https://app.powerbi.com/view?r=' . str_repeat('A', 520);
$n = count_of($pdo, 'reporte');
[$s, $d] = requestApi('POST', '/api/reportes', ['nombre' => 'Largo', 'descripcion' => 'x', 'enlace' => $long], $admin);
expectCase(($d['success'] ?? true) === false && str_contains($d['message'] ?? '', 'supera el máximo') && str_contains($d['message'] ?? '', '500') && count_of($pdo, 'reporte') === $n && stripos($d['message'] ?? '', 'SQLSTATE') === false, 'a link longer than the real column (500) gets a clear validation error and nothing is inserted');
[$s] = requestApi('POST', '/api/reportes', ['nombre' => 'Largo', 'descripcion' => 'x', 'enlace' => $long], $admin, true);
expectCase($s >= 400 && $s < 500 && $s !== 409 && count_of($pdo, 'reporte') === $n, 'contract 2: the over-long link is a 4xx validation error');

/* ---------- 4. Edición, baja, reactivación, vistas y visor ---------- */
[, $form] = requestApi('GET', "/api/reportes/$rid?view=form", null, $admin);
expectCase(array_key_exists('grupo_id', $form['data'] ?? []) && $form['data']['grupo_id'] === null && preg_match('/^[0-9a-f]{16}$/', $form['data']['version'] ?? '') === 1 && ($form['data']['reporte_id'] ?? '') === $ref1, 'admin form view: grupo_id null, reporte_id and a version');
$rv = $form['data']['version'];
[$s] = requestApi('PUT', "/api/reportes/$rid", ['nombre' => 'Informe web editado', 'descripcion' => 'y', 'enlace' => publicLink(10), 'version' => $rv], $admin, true);
$row = one($pdo, 'SELECT * FROM reporte WHERE id = ?', [$rid]);
expectCase($s === 200 && $row['nombre'] === 'Informe web editado' && $row['grupo_id'] === null && $row['reporte_id'] === $ref1 && $row['enlace'] === publicLink(10), 'editing a NULL-group report keeps grupo_id NULL and keeps its reporte_id');
[$s] = requestApi('PUT', "/api/reportes/$rid", ['nombre' => 'Obsoleto', 'descripcion' => 'y', 'enlace' => publicLink(10), 'version' => $rv], $admin, true);
expectCase($s === 409 && one($pdo, 'SELECT nombre FROM reporte WHERE id = ?', [$rid])['nombre'] === 'Informe web editado', 'a stale edit of a NULL-group report is HTTP 409');
$log = audits($pdo, $rid);
$last = end($log); $b = json_decode($last['before_json'], true); $a = json_decode($last['after_json'], true);
expectCase($last['action'] === 'reporte.update' && array_key_exists('grupo_id', $b) && $b['grupo_id'] === null && $a['grupo_id'] === null && $a['reporte_id'] === $ref1, 'the update audit row records grupo_id null before and after');
[, $form] = requestApi('GET', "/api/reportes/$rid?view=form", null, $admin);
expectCase($form['data']['grupo_id'] === null && $form['data']['version'] !== $rv, 'the form version changes after the edit and grupo_id stays null');
[$s, $d] = requestApi('GET', '/api/reportes?per_page=100', null, $admin);
$listed = array_values(array_filter($d['data'] ?? [], fn ($r) => (int)$r['id'] === $rid));
expectCase($s === 200 && count($listed) === 1 && !isset($listed[0]['enlace']), 'admin list includes the NULL-group report without exposing its link');
[$s, $d] = requestApi('GET', '/api/reportes?per_page=100&view=minimal', null, $admin);
expectCase($s === 200 && in_array($rid, array_map('intval', array_column($d['data'] ?? [], 'id')), true), 'view=minimal list includes the NULL-group report');
[$s, $d] = requestApi('GET', "/api/reportes/$rid", null, $admin);
expectCase($s === 200 && (int)($d['data']['id'] ?? 0) === $rid, 'detail of a NULL-group report works');
[$s, $d] = requestApi('GET', '/api/reportes/grupoid/bbbbbbbb-0000-4000-8000-000000000002', null, $admin);
$ids = array_map('intval', array_column($d['data'] ?? [], 'id'));
expectCase($s === 200 && in_array($rid2, $ids, true) && !in_array($rid, $ids, true), 'the historic /grupoid/{uuid} route matches the report with that group and never a NULL-group report');
// Asignación (SQL directo, base desechable): empresa 1 + usera.
$pdo->exec("INSERT INTO empresa_reporte (id, empresa_id, reporte_id, activo) VALUES (2001, 1, $rid, 1)");
$pdo->exec('INSERT INTO user_empresa_reporte (id, user_id, empresa_reporte_id, activo) VALUES (2001, 2, 2001, 1)');
[$s, $d] = requestApi('GET', "/api/reportes/id/$rid/visor", null, $worker);
expectCase($s === 200 && ($d['data']['modo'] ?? '') === 'publico' && ($d['data']['url'] ?? '') === publicLink(10) && ($d['data']['reporte_id'] ?? '') === $ref1 && (int)($d['data']['id'] ?? 0) === $rid, 'viewer by numeric id: an assigned worker gets modo=publico for the NULL-group report');
[$s, $d] = requestApi('GET', "/api/reportes/reporteid/$ref1/visor", null, $worker);
expectCase($s === 200 && ($d['data']['modo'] ?? '') === 'publico', 'viewer by internal reporte_id (UUID) works for the NULL-group report');
[$s, $d] = requestApi('GET', "/api/reportes/id/$rid/visor", null, $admin);
expectCase($s === 200 && ($d['data']['modo'] ?? '') === 'publico', 'viewer: the admin is not restricted on this instance (allow-list unset)');
[$s] = requestApi('GET', "/api/reportes/id/$rid/visor", null, $other);
expectCase($s === 404, 'viewer: an unassigned worker gets 404');
[$s] = requestApi('GET', "/api/reportes/id/$rid/visor", null, null);
expectCase($s === 401, 'viewer: no token is 401');
[$s, $d] = requestApi('GET', '/api/reportes?per_page=100', null, $worker);
expectCase($s === 200 && array_map('intval', array_column($d['data'] ?? [], 'id')) === [1, $rid] || $s === 200 && in_array($rid, array_map('intval', array_column($d['data'] ?? [], 'id')), true), 'worker list (strict default view) includes the assigned NULL-group report');
[$s] = requestApi('DELETE', "/api/reportes/$rid", null, $admin, true);
expectCase($s === 200 && (int)one($pdo, 'SELECT activo FROM reporte WHERE id = ?', [$rid])['activo'] === 0, 'a NULL-group report is deactivated logically (row kept)');
[$s] = requestApi('GET', "/api/reportes/id/$rid/visor", null, $worker);
expectCase($s === 404, 'viewer: a deactivated NULL-group report is 404');
[$s] = requestApi('POST', "/api/reportes/$rid/reactivate", [], $admin, true);
$row = one($pdo, 'SELECT * FROM reporte WHERE id = ?', [$rid]);
expectCase($s === 200 && (int)$row['activo'] === 1 && $row['grupo_id'] === null && $row['reporte_id'] === $ref1, 'reactivation keeps grupo_id NULL and the same reporte_id');
expectCase(array_slice(array_column(audits($pdo, $rid), 'action'), -2) === ['reporte.deactivate', 'reporte.reactivate'], 'deactivation and reactivation are audited for the NULL-group report');
[$s, $d] = requestApi('POST', '/api/reportes', ['nombre' => 'Segundo sin grupo', 'descripcion' => 'x', 'enlace' => publicLink(4)], $admin, true);
$ridN = (int)($d['data']['id'] ?? 0); $created[] = $ridN;
expectCase($s === 200 && $ridN > 0 && count_of($pdo, 'reporte', 'grupo_id IS NULL') === 2, 'several NULL-group reports coexist (no UNIQUE on grupo_id)');
[$s] = requestApi('GET', "/api/reportes/id/$ridN/visor", null, $worker);
expectCase($s === 404, 'viewer: a second NULL-group report the worker is not assigned to is 404');

/* ---------- 5. Migración DOWN: se niega mientras existan filas NULL ---------- */
$tableBefore = rowHashes($pdo);
$schemaBefore = schemaOf($pdo);
$nulls = count_of($pdo, 'reporte', 'grupo_id IS NULL');
$code = runSql($pdo, DOWN);
expectCase($nulls === 2 && $code === 1242, 'down migration REFUSES while NULL-group reports exist (error 1242 raised by the guard)');
expectCase(schemaOf($pdo) === $schemaBefore && col($pdo, 'grupo_id')['IS_NULLABLE'] === 'YES', 'refused down: the schema is still nullable');
expectCase(rowHashes($pdo) === $tableBefore && count_of($pdo, 'reporte', 'grupo_id IS NULL') === 2 && count_of($pdo, 'reporte', "grupo_id = ''") === 0, 'refused down: every row hash is unchanged (no NULL coerced, nothing deleted)');
$restoreMode();
// Respaldo si la guarda se saltara (carrera): el modo estricto impide convertir NULL en cadena vacía.
$pdo->exec("SET SESSION sql_mode = ''");
$stmts = statementsOf(DOWN);
$pdo->exec($stmts[1]);   // SET SESSION sql_mode estricto (sin ejecutar la guarda)
$code2 = 0;
try { $pdo->exec($stmts[2]); } catch (PDOException $e) { $code2 = (int)$e->errorInfo[1]; }
expectCase($code2 === 1138 && col($pdo, 'grupo_id')['IS_NULLABLE'] === 'YES' && rowHashes($pdo) === $tableBefore, 'even without the guard, the strict-mode ALTER fails (1138) and never coerces NULL into an empty string');
$restoreMode();

/* ---------- 6. Retirar las filas NULL (SQL directo, base desechable) y revertir ---------- */
$pdo->exec('DELETE FROM user_empresa_reporte WHERE id = 2001');
$pdo->exec('DELETE FROM empresa_reporte WHERE id = 2001');
$pdo->exec('DELETE FROM reporte WHERE grupo_id IS NULL');
$histNow = rowHashes($pdo);
expectCase(count_of($pdo, 'reporte', 'grupo_id IS NULL') === 0 && array_intersect_key($histNow, $baseline) === $baseline, 'cleanup of the NULL-group rows leaves the historic rows untouched');
expectCase(runSql($pdo, DOWN) === 0, 'down migration succeeds once no NULL-group row exists');
$restoreMode();
$g2 = col($pdo, 'grupo_id');
expectCase($g2['IS_NULLABLE'] === 'NO' && $g2['COLUMN_TYPE'] === 'varchar(50)' && $g2['COLLATION_NAME'] === $g0['COLLATION_NAME'], 'after down: grupo_id is varchar(50) NOT NULL again');
expectCase(schemaOf($pdo) === $schema0, 'after down: the whole reporte structure equals the starting one');
expectCase(rowHashes($pdo) === $histNow, 'after down: every remaining row hash is unchanged');
expectCase(runSql($pdo, UP) === 0 && col($pdo, 'grupo_id')['IS_NULLABLE'] === 'YES', 'up can be re-applied after a down');
[$s, $d] = requestApi('POST', '/api/reportes', ['nombre' => 'Reaplicado', 'descripcion' => 'x', 'enlace' => publicLink(5)], $admin, true);
$ridR = (int)($d['data']['id'] ?? 0); $created[] = $ridR;
expectCase($s === 200 && $ridR > 0 && one($pdo, 'SELECT grupo_id FROM reporte WHERE id = ?', [$ridR])['grupo_id'] === null, 'after re-applying up, a no-group report is accepted again');
$pdo->exec("DELETE FROM reporte WHERE id = $ridR");
expectCase(runSql($pdo, DOWN) === 0 && col($pdo, 'grupo_id')['IS_NULLABLE'] === 'NO', 'final down restores grupo_id NOT NULL for the other suites');
$restoreMode();

/* ---------- 7. Limpieza y estado final ---------- */
$pdo->exec(file_get_contents('database/migrations/UX-08B-audit-log.down.sql'));
$created = array_values(array_filter(array_map('intval', $created)));
if ($created) $pdo->exec('DELETE FROM reporte WHERE id IN (' . implode(',', $created) . ')');
expectCase(schemaOf($pdo) === $schema0 && rowHashes($pdo) === $baseline, 'final state: schema and reporte rows exactly as found, audit_log removed');
expectCase(count_of($pdo, 'empresa_reporte', 'id >= 2000') === 0 && count_of($pdo, 'user_empresa_reporte', 'id >= 2000') === 0, 'the access rows added by this suite were removed');

echo $failures === 0 ? "ALL PASSED\n" : "$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
