<?php
// UX-08I · Prototipo «alta por vista»: manejo de errores y recuperación ante restricciones SQL. Se ejecuta DENTRO de `appawb` (cuenta 2B, ADMIN_WRITES_USUARIOS=1).
// Rompe a propósito la vista (restricción más estricta; vista inexistente), comprueba que la API falla de forma genérica y sin residuos, y que recrearla desde la plantilla restablece el servicio.
// Entorno: TEST_DB_PASS.
$failures = 0;
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$root = new PDO('mysql:host=db;dbname=fixture_aw;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), $opts);
function api($method, $path, $token = null, $body = null) {
    $h = ['Content-Type: application/json', 'X-API-Contract: 2', 'X-Forwarded-Proto: https'];
    if ($token) $h[] = 'Authorization: Bearer ' . $token;
    $o = ['http' => ['method' => $method, 'header' => implode("\r\n", $h), 'ignore_errors' => true, 'timeout' => 20]];
    if ($body !== null) $o['http']['content'] = json_encode($body);
    $raw = (string)@file_get_contents("http://127.0.0.1:8080$path", false, stream_context_create($o));
    $st = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int)$m[1] : 0;
    return [$st, json_decode($raw, true), $raw];
}
function ok($c, $m) { global $failures; echo ($c ? 'PASS ' : 'FAIL ') . $m . "\n"; if (!$c) $failures++; }
$vistaOk = trim(preg_replace('/^\s*--.*$/m', '', strtr((string)file_get_contents('/work/database/privileges/UX-08I-alta-usuarios-vista.up.sql.template'), ['{DB}' => 'fixture_aw'])));
$vistaOk = rtrim($vistaOk, "; \n");
const LEAK = '/SQLSTATE|PDOException|Stack trace|CHECK OPTION|v_user_alta|cervesur_(escritura|ro_|react_)|\/work\//i';
$root->exec("DELETE FROM user WHERE username LIKE 'vistaerr_%'");
[, $d] = api('POST', '/api/login', null, ['login' => 'admin', 'password' => 'synthetic-password']); $t = $d['token'] ?? '';
$alta = fn ($n) => ['first_name' => 'V', 'last_name' => 'E', 'username' => "vistaerr_$n", 'email' => "vistaerr_$n@example.invalid", 'password' => 'Aa1!vista-errores-x'];
$a0 = (int)$root->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();

[$s] = api('POST', '/api/register', $t, $alta('base'));
ok($t !== '' && $s === 200 && $root->query("SELECT role FROM user WHERE username='vistaerr_base'")->fetchColumn() === 'user', 'alta legítima de React por la vista: 200, rol «user»');
$a1 = (int)$root->query('SELECT COUNT(*) FROM audit_log')->fetchColumn();
ok($a1 === $a0 + 1, 'exactamente una auditoría');

echo "== Restricción más estricta en la vista (el motor rechaza el alta legítima)\n";
$root->exec('CREATE OR REPLACE SQL SECURITY DEFINER VIEW fixture_aw.v_user_alta AS SELECT username, email, first_name, last_name, phone, password, role, is_active, session_version, created_at, updated_at FROM fixture_aw.user WHERE role = \'ninguno\' AND is_active = 1 AND session_version = 1 WITH CHECK OPTION');
[$s, , $raw] = api('POST', '/api/register', $t, $alta('estricta'));
ok($s >= 500 && !preg_match(LEAK, $raw), "el alta falla con 5xx GENÉRICO (HTTP $s), sin SQLSTATE ni nombres de objetos");
ok((int)$root->query("SELECT COUNT(*) FROM user WHERE username='vistaerr_estricta'")->fetchColumn() === 0 && (int)$root->query('SELECT COUNT(*) FROM audit_log')->fetchColumn() === $a1, 'sin usuario a medias y sin fila de auditoría (transacción revertida)');
ok(api('GET', '/api/ping')[0] === 200 && api('GET', '/api/empresas?view=minimal&per_page=1&page=1', $t)[0] === 200, 'el resto del administrador sigue sirviendo (ping y lecturas)');
[$se] = api('POST', '/api/empresas', $t, ['nombre' => 'Con vista rota', 'razon_social' => 'Con vista rota SAC', 'ruc' => '20444000010', 'ciudad' => 'X', 'pais' => 'Y']);
ok($se === 200, 'Empresas sigue escribiendo con la vista rota');

echo "== Vista inexistente\n";
$root->exec('DROP VIEW fixture_aw.v_user_alta');
[$s, , $raw] = api('POST', '/api/register', $t, $alta('sinvista'));
ok($s >= 500 && !preg_match(LEAK, $raw) && api('GET', '/api/ping')[0] === 200, "vista inexistente: 5xx genérico (HTTP $s) y el administrador sigue sirviendo");
[$s2] = api('PUT', '/api/users/2', $t, ['phone' => '555-7777']);
ok($s2 === 200, 'la edición de usuarios (que no usa la vista) sigue funcionando');

echo "== Recuperación: recrear la vista desde la plantilla\n";
$root->exec($vistaOk);
[$s] = api('POST', '/api/register', $t, $alta('recuperada'));
ok($s === 200 && $root->query("SELECT role FROM user WHERE username='vistaerr_recuperada'")->fetchColumn() === 'user', 'recreada la vista, el alta vuelve a funcionar SIN reconfigurar nada (los permisos de la cuenta sobreviven al DROP/CREATE)');
$root->exec("DELETE FROM user WHERE username LIKE 'vistaerr_%'");
echo $failures === 0 ? "\nAll p4a vista errores checks passed\n" : "\n$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
