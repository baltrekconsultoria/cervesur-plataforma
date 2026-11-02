<?php
// UX-08C: lista de informes que el administrador puede abrir en el visor. Solo red Docker aislada, identidades ficticias y MySQL desechable.
// Usa DOS instancias del mismo backend sobre la misma base: `app` (sin ADMIN_VIEWER_ALLOWED_REPORT_IDS) y `appadmin` (ADMIN_VIEWER_ALLOWED_REPORT_IDS=1,903).
// Se ejecuta DESPUÉS del resto de suites HTTP del fixture; inserta registros propios (ids 903+) y los elimina al terminar.
$failures = 0;
$pdo = new PDO('mysql:host=db;dbname=fixture;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);

function requestApi($host, $method, $path, $body = null, $token = null) {
    $headers = ["Content-Type: application/json"];
    if ($token !== null) $headers[] = "Authorization: Bearer " . $token;
    $options = ['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 5]];
    if ($body !== null) $options['http']['content'] = json_encode($body);
    $raw = file_get_contents("http://$host:8080" . $path, false, stream_context_create($options));
    $GLOBALS['lastResponse'] = substr($raw ?: '', 0, 300);
    $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int)$m[1] : 0;
    return [$status, json_decode($raw ?: '', true), $raw ?: ''];
}
function expectCase($condition, $label) {
    global $failures;
    if ($condition) echo "PASS $label\n"; else { echo "FAIL $label\n  última respuesta: " . ($GLOBALS['lastResponse'] ?? '') . "\n"; $failures++; }
}
function tokenOf($host, $name, $password = 'synthetic-password') {
    [, $d] = requestApi($host, 'POST', '/api/login', ['login' => $name, 'password' => $password]);
    return $d['token'] ?? '';
}
function snapshot($pdo) {
    $out = [];
    foreach (['user', 'empresa', 'reporte', 'empresa_usuario', 'empresa_reporte', 'user_empresa_reporte'] as $t) {
        $out[$t] = md5(json_encode($pdo->query("SELECT * FROM $t ORDER BY id")->fetchAll()));
    }
    return $out;
}

// ---------- Datos propios ----------
// 903: informe INACTIVO que figura en la lista. 906: informe activo que NO figura en la lista y al que un trabajador sí tiene acceso.
$pdo->exec("INSERT INTO reporte (id,nombre,enlace,grupo_id,reporte_id,created_by,activo) VALUES
  (903,'Informe Inactivo UX08C','https://example.invalid/i','00000000-0000-0000-0000-000000000903','aaaaaaaa-0000-4000-8000-000000000903',1,0),
  (906,'Informe Trabajador UX08C','https://example.invalid/w','00000000-0000-0000-0000-000000000906','aaaaaaaa-0000-4000-8000-000000000906',1,1)");
$pdo->exec("INSERT INTO empresa (id,nombre,razon_social,ruc,ciudad,pais,activo) VALUES (905,'Empresa UX08C','Empresa UX08C','00000000905','Ciudad','Demo',1)");
$pdo->exec('INSERT INTO empresa_reporte (id,empresa_id,reporte_id,activo) VALUES (905,905,906,1)');
$pdo->exec('INSERT INTO empresa_usuario (id,empresa_id,user_id,activo) VALUES (905,905,1,1)');
$pdo->exec('INSERT INTO user_empresa_reporte (id,user_id,empresa_reporte_id,activo) VALUES (905,1,905,1)');

$admin = tokenOf('appadmin', 'admin');
$adminOpen = tokenOf('app', 'admin');
$worker = tokenOf('appadmin', 'usera');
// Después de iniciar sesión: el login actualiza `last_login`, que no forma parte de lo que esta prueba vigila.
$before = snapshot($pdo);
$UUID1 = '00000000-0000-0000-0000-000000000011';
$UUID906 = 'aaaaaaaa-0000-4000-8000-000000000906';
$UUID903 = 'aaaaaaaa-0000-4000-8000-000000000903';

// ---------- Instancia con lista (appadmin permite 1 y 903) ----------
[$s, $d] = requestApi('appadmin', 'GET', '/api/reportes/id/1/visor', null, $admin);
expectCase($s === 200 && ($d['success'] ?? false) === true && ($d['data']['id'] ?? 0) === 1, 'admin opens an approved report by numeric id');
[$s, $d] = requestApi('appadmin', 'GET', "/api/reportes/reporteid/$UUID1/visor", null, $admin);
expectCase($s === 200 && ($d['success'] ?? false) === true, 'admin opens an approved report by historical reference');
[$s, $d, $raw] = requestApi('appadmin', 'GET', '/api/reportes/id/906/visor', null, $admin);
expectCase($s === 403 && ($d['success'] ?? true) === false && strpos($raw, 'example.invalid') === false && strpos($raw, '"url"') === false && strpos($raw, 'enlace') === false, 'admin gets 403 without any link for an active report that is not approved (numeric id)');
[$s, , $raw] = requestApi('appadmin', 'GET', "/api/reportes/reporteid/$UUID906/visor", null, $admin);
expectCase($s === 403 && strpos($raw, 'example.invalid') === false, 'admin gets 403 for a report that is not approved (historical reference)');
[$s] = requestApi('appadmin', 'GET', '/api/reportes/id/903/visor', null, $admin);
expectCase($s === 404, 'an inactive report is denied (404) even if its id is on the list');
[$s] = requestApi('appadmin', 'GET', "/api/reportes/reporteid/$UUID903/visor", null, $admin);
expectCase($s === 404, 'an inactive report is denied (404) by historical reference too');
[$s] = requestApi('appadmin', 'GET', '/api/reportes/id/999999/visor', null, $admin);
expectCase($s === 404, 'a nonexistent report is 404, not 403');
[$s] = requestApi('appadmin', 'GET', '/api/reportes/id/1/visor');
expectCase($s === 401, 'the viewer still requires authentication');

// ---------- Los trabajadores no cambian ----------
[$s, $d] = requestApi('appadmin', 'GET', '/api/reportes/id/906/visor', null, $worker);
expectCase($s === 200 && ($d['data']['id'] ?? 0) === 906, 'a worker with an individual assignment still opens a report that is not on the admin list');
[$s] = requestApi('appadmin', 'GET', '/api/reportes/id/2/visor', null, $worker);
expectCase($s === 404, 'a worker without assignment still gets 404');

// ---------- Sin lista configurada (instancia app): comportamiento de siempre ----------
[$s] = requestApi('app', 'GET', '/api/reportes/id/906/visor', null, $adminOpen);
expectCase($s === 200, 'without the setting the admin keeps the previous behaviour (no extra restriction)');
[$s] = requestApi('app', 'GET', '/api/reportes/id/903/visor', null, $adminOpen);
expectCase($s === 404, 'without the setting an inactive report is still 404');

// ---------- No hay aprobación masiva ni escritura ----------
[$s, $d] = requestApi('appadmin', 'GET', '/api/reportes/id/1/visor?all=1&allow=906', null, $admin);
expectCase($s === 200, 'query parameters cannot widen the list (the approved report is unaffected)');
[$s] = requestApi('appadmin', 'GET', '/api/reportes/id/906/visor?all=1&allow=906&ADMIN_VIEWER_ALLOWED_REPORT_IDS=906', null, $admin);
expectCase($s === 403, 'query parameters cannot add a report to the list');
expectCase(snapshot($pdo) === $before, 'reading through both instances leaves the data unchanged');

// ---------- Limpieza ----------
$pdo->exec('DELETE FROM user_empresa_reporte WHERE id = 905');
$pdo->exec('DELETE FROM empresa_usuario WHERE id = 905');
$pdo->exec('DELETE FROM empresa_reporte WHERE id = 905');
$pdo->exec('DELETE FROM empresa WHERE id = 905');
$pdo->exec('DELETE FROM reporte WHERE id IN (903,906)');

echo $failures === 0 ? "ALL PASSED\n" : "$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
