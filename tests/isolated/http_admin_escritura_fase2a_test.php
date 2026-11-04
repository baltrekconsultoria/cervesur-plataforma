<?php
// UX-08H.1 · FASE 2A sobre una base marcada como PRODUCTIVA (CERVESUR_PRODUCTION_DBS=fixture_aw) y con la cuenta de la fase 2A (sin escritura sobre `user`).
// Instancia `appawp`: ADMIN_WRITES_EMPRESAS/REPORTES/ACCESOS/USUARIOS/USUARIOS_SENSIBLE = 1 a propósito (el servidor debe negar Usuarios igualmente).
// Se ejecuta DENTRO del contenedor de la instancia (loopback + X-Forwarded-Proto: https): una base productiva rechaza CERVESUR_INSECURE_TESTS=SI, así que la instancia va como en producción.
// Entorno: TEST_DB_PASS, W_WRITE2A_PASS.
$failures = 0;
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$root = new PDO('mysql:host=db;dbname=fixture_aw;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), $opts);
function api($method, $path, $token = null, $body = null) {
    $h = ['Content-Type: application/json', 'X-API-Contract: 2', 'X-Forwarded-Proto: https'];
    if ($token) $h[] = 'Authorization: Bearer ' . $token;
    $o = ['http' => ['method' => $method, 'header' => implode("\r\n", $h), 'ignore_errors' => true, 'timeout' => 20]];
    if ($body !== null) $o['http']['content'] = json_encode($body);
    $raw = (string)@file_get_contents("http://127.0.0.1:8080$path", false, stream_context_create($o));
    $GLOBALS['last'] = substr($raw, 0, 300);
    $st = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int)$m[1] : 0;
    return [$st, json_decode($raw, true), $raw];
}
function expectCase($c, $label) { global $failures; if ($c) echo "PASS $label\n"; else { echo "FAIL $label\n  última respuesta: " . ($GLOBALS['last'] ?? '') . "\n"; $failures++; } }
function one($pdo, $sql, $p = []) { $s = $pdo->prepare($sql); $s->execute($p); return $s->fetchColumn(); }
function sumUser($root) { return $root->query('CHECKSUM TABLE user')->fetch()['Checksum']; }

[, $d] = api('POST', '/api/login', null, ['login' => 'admin', 'password' => 'synthetic-password']);
$t = $d['token'] ?? '';
expectCase($t !== '', 'la instancia con la cuenta de la fase 2A arranca (SHOW GRANTS aceptado) y el administrador entra');
[, $d] = api('GET', '/api/admin/capacidades', $t);
expectCase($d['data']['writes'] === ['empresas' => true, 'reportes' => true, 'accesos' => true, 'usuarios' => false] && $d['data']['users_sensitive'] === false, 'capacidades: empresas/reportes/accesos sí; usuarios NO aunque el .env los encienda');

echo "== Fase 2B bloqueada en tres capas\n";
$before = sumUser($root);
$calls = [
    ['POST', '/api/register', ['first_name' => 'X', 'last_name' => 'Y', 'username' => 'bloqueado', 'email' => 'b@example.invalid', 'password' => 'long-enough-password']],
    ['PUT', '/api/users/2', ['phone' => '1']], ['POST', '/api/users/2/deactivate', []], ['POST', '/api/users/2/reactivate', []],
    ['PUT', '/api/user/2/role', ['role' => 'admin']], ['PUT', '/api/users/2/password', ['password' => 'another-long-password']],
];
$bad = [];
foreach ($calls as [$m, $p, $b]) { [$s, $r] = api($m, $p, $t, $b); if ($s !== 403 || ($r['code'] ?? '') !== 'fase_2b_bloqueada') $bad[] = "$m $p => $s"; }
expectCase(!$bad && sumUser($root) === $before, 'capa 1: las 6 rutas de escritura de usuarios → 403 «fase_2b_bloqueada» y la tabla user no cambia (checksum)' . ($bad ? ' · ' . implode(', ', $bad) : ''));
expectCase((int)one($root, "SELECT COUNT(*) FROM user WHERE username = 'bloqueado'") === 0, '…no se creó el usuario');
$w = new PDO('mysql:host=db;dbname=fixture_aw;charset=utf8mb4', 'cervesur_escritura2a_aw', getenv('W_WRITE2A_PASS'), $opts);
$den = function ($sql) use ($w) { try { $w->query($sql); return false; } catch (PDOException $e) { return true; } };
expectCase($den("UPDATE user SET phone = 'x' WHERE id = 2") && $den("UPDATE user SET is_active = 0 WHERE id = 2") && $den("UPDATE user SET role = 'admin' WHERE id = 2") && $den("INSERT INTO user (username, email, role) VALUES ('q','q@example.invalid','user')"), 'capa 3: la cuenta de la fase 2A no puede escribir `user` ni con SQL directo');
$grants = implode("\n", array_map(fn ($r) => $r[0], $w->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_NUM)));
$userGrants = preg_grep('/ON `fixture_aw`\.`user`/', explode("\n", $grants));
$userWrites = str_replace('UPDATE (`last_login`)', '', implode(' ', $userGrants));
expectCase(!preg_match('/INSERT|UPDATE|DELETE/', $userWrites) && !preg_match('/\b(DELETE|ALL PRIVILEGES|GRANT OPTION|TRIGGER|DROP|ALTER|CREATE)\b/', $grants), 'SHOW GRANTS: sobre `user` solo SELECT y UPDATE(last_login); sin DELETE/ALL/GRANT OPTION/TRIGGER/DDL');

echo "== Fase 2A completa sobre la misma instancia\n";
[$s, $d] = api('POST', '/api/empresas', $t, ['nombre' => 'Prod Sim', 'razon_social' => 'Prod Sim SA', 'ruc' => '20777000001', 'ciudad' => 'Lima', 'pais' => 'Perú']);
$eid = (int)($d['id'] ?? 0);
expectCase($s === 200 && $eid > 0, 'empresa: alta');
[$s] = api('DELETE', "/api/empresas/$eid", $t);
[$s2] = api('POST', "/api/empresas/$eid/reactivate", $t, []);
expectCase($s === 200 && $s2 === 200, 'empresa: baja lógica y reactivación');
$link = 'https://app.powerbi.com/reportEmbed?reportId=ffffffff-0000-4000-8000-000000000001&groupId=bbbbbbbb-0000-4000-8000-000000000001';
[$s, $d] = api('POST', '/api/reportes', $t, ['nombre' => 'Informe Prod', 'descripcion' => 'd', 'enlace' => $link]);
$rid = (int)($d['data']['id'] ?? 0);
expectCase($s === 200 && $rid > 0, 'informe: alta');
[$s] = api('POST', '/api/asignacion', $t, ['empresa_id' => $eid, 'reporte_id' => $rid]);
$er = (int)one($root, 'SELECT id FROM empresa_reporte WHERE empresa_id = ? AND reporte_id = ?', [$eid, $rid]);
expectCase(in_array($s, [200, 201], true) && $er > 0, 'asignación empresa–informe');
[$s] = api('POST', '/api/empresa-usuario', $t, ['user_id' => 3, 'empresa_id' => $eid]);
$eu = (int)one($root, 'SELECT id FROM empresa_usuario WHERE user_id = 3 AND empresa_id = ?', [$eid]);
[$s2] = api('POST', '/api/empresa-reporte-usuario', $t, ['user_id' => 3, 'empresa_reporte_id' => $er]);
expectCase($s === 200 && $eu > 0 && in_array($s2, [200, 201], true), 'acceso de un usuario a la empresa y al informe (se escribe la relación; `user` no)');
[$s] = api('DELETE', "/api/empresa-usuario/$eu", $t);
[$s2] = api('POST', "/api/empresa-usuario/$eu/reactivate", $t, []);
expectCase($s === 200 && $s2 === 200, 'revocar y reactivar el acceso');
[$s] = api('DELETE', "/api/asignacion/$er", $t);
expectCase($s === 200, 'baja lógica de la asignación (requiere updated_at en la cuenta)');
$n = (int)one($root, "SELECT COUNT(*) FROM audit_log WHERE entity IN ('empresa','reporte','empresa_reporte','empresa_usuario','user_empresa_reporte')");
expectCase($n >= 9, "cada operación quedó auditada ($n filas)");
expectCase(sumUser($root) === $before, 'al terminar, la tabla user sigue idéntica');

echo "\n" . ($failures === 0 ? 'All admin_escritura fase 2A checks passed' : "$failures check(s) FAILED") . "\n";
exit($failures === 0 ? 0 : 1);
