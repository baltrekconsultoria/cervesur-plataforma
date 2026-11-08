<?php
// UX-09B · D3 sobre la pila REAL de RC1c: backend/router/index.php + lib/auth_middleware.php + MySQL 8, servidor `appw` (base fixture_w).
// Cabeceras Authorization deformadas, variantes de URL y de metodo, identidad enviada por el cliente y perfil ajeno (A1).
// Solo red Docker aislada, identidades ficticias y MySQL desechable. Ids >= 3000 propios; se eliminan al final.
// Complementa (no repite) http_session_matrix_test.php: tokens caducados, sin session_version, firmados con otro secreto, rol desde MySQL.
$failures = 0; $total = 0;
$pdo = new PDO('mysql:host=db;dbname=fixture_w;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
const BASE = 'http://appw:8080';
$secret = getenv('JWT_SECRET');
if (!$secret) { echo "FAIL JWT_SECRET debe pasarse al contenedor de pruebas\n"; exit(1); }
require 'backend/lib/jwt.php';

/** Peticion HTTP real; $headers es una lista de lineas completas (permite Authorization duplicado o deformado). */
function http($method, $path, array $headers = [], $body = null) {
    $options = ['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 10]];
    if ($body !== null) { $options['http']['content'] = is_string($body) ? $body : json_encode($body); }
    $raw = @file_get_contents(BASE . $path, false, stream_context_create($options));
    $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int)$m[1] : 0;
    $GLOBALS['last'] = substr((string)$raw, 0, 200);
    return [$status, (string)$raw];
}
function ok($cond, $label, $info = false) {
    global $failures, $total; $total++;
    if ($cond) { echo ($info ? "INFO " : "PASS ") . "$label\n"; } else { echo "FAIL $label  -> " . ($GLOBALS['last'] ?? '') . "\n"; $failures++; }
}
function bearer($t) { return ['Authorization: Bearer ' . $t, 'Content-Type: application/json']; }
function usuario($pdo, $id, $rol, $nombre = null) {
    $st = $pdo->prepare('REPLACE INTO user (id, username, email, password, first_name, last_name, role, is_active, session_version) VALUES (?,?,?,?,?,?,?,1,1)');
    $nombre = $nombre ?: "d3u$id";
    $st->execute([$id, $nombre, "$nombre@example.invalid", password_hash('synthetic-password', PASSWORD_DEFAULT), 'Demo', 'D3', $rol]);
}
function token($pdo, $secret, $id, array $over = [], $firma = null) {
    $st = $pdo->prepare('SELECT * FROM user WHERE id = ?'); $st->execute([$id]); $u = $st->fetch();
    $p = array_merge(['user_id' => (int)$u['id'], 'username' => $u['username'], 'email' => $u['email'], 'role' => $u['role'], 'session_version' => (int)$u['session_version'], 'exp' => time() + 600], $over);
    foreach ($over as $k => $v) { if ($v === null) { unset($p[$k]); } }
    return generate_jwt($p, $firma ?? $secret);
}
function b64($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function n_users($pdo) { return (int)$pdo->query('SELECT count(*) FROM user')->fetchColumn(); }
function campo($pdo, $id, $c) { $st = $pdo->prepare("SELECT $c FROM user WHERE id = ?"); $st->execute([$id]); return $st->fetchColumn(); }

// AUDIT_LOG_MODE=required: sin la tabla de auditoria el alta falla CERRADO (correcto). El fixture no la trae: se aplica la migracion real.
if (!(int)$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='fixture_w' AND table_name='audit_log'")->fetchColumn()) {
    $sql = preg_replace('/^--.*$/m', '', file_get_contents('database/migrations/UX-08B-audit-log.up.sql'));
    foreach (array_filter(array_map('trim', explode(";
", $sql))) as $s) { $s = rtrim($s, ';'); if ($s !== '') { $pdo->exec($s); } }
}
function n_audit($pdo) { return (int)$pdo->query('SELECT COUNT(*) FROM audit_log')->fetchColumn(); }
$pdo->exec('DELETE FROM user WHERE id >= 3000');
usuario($pdo, 3001, 'admin'); usuario($pdo, 3002, 'user'); usuario($pdo, 3003, 'user');
$tAdm = token($pdo, $secret, 3001); $tUsr = token($pdo, $secret, 3002);
const SONDA = '/api/empresas?per_page=1';    // exige sesion
const SOLO_ADMIN = '/api/auditoria?per_page=1'; // exige administrador

echo "== A. Cabecera Authorization: ausente, deformada, duplicada o invalida ==\n";
ok(http('GET', SONDA, [])[0] === 401, 'A01 sin cabecera Authorization: 401');
ok(http('GET', SONDA, ['Authorization: '])[0] === 401, 'A02 Authorization vacia: 401');
ok(http('GET', SONDA, ['Authorization: Bearer'])[0] === 401, 'A03 «Bearer» sin token: 401');
ok(http('GET', SONDA, ['Authorization: Bearer '])[0] === 401, 'A04 «Bearer » con espacio y sin token: 401');
ok(http('GET', SONDA, ['Authorization: ' . $tUsr])[0] === 401, 'A05 token sin esquema Bearer: 401');
ok(http('GET', SONDA, ['Authorization: Basic ' . $tUsr])[0] === 401, 'A06 esquema Basic con un token valido: 401');
ok(http('GET', SONDA, ['Authorization: Bearer ' . $tUsr . ' extra'])[0] === 401, 'A07 basura tras el token: 401');
ok(http('GET', SONDA, ['Authorization: Bearer ' . $tUsr . ', Bearer ' . $tUsr])[0] === 401, 'A08 dos tokens en una misma cabecera: 401');
ok(http('GET', SONDA, ['Authorization: Bearer ' . $tUsr . "\t"])[0] === 401 || http('GET', SONDA, ['Authorization: Bearer ' . $tUsr . "\t"])[0] === 200, 'A09 tabulador final (el servidor lo recorta o lo rechaza; nunca 5xx)', true);
[$s] = http('GET', SONDA, ['Authorization: bearer ' . $tUsr]);
ok($s === 200, 'A10 esquema «bearer» en minusculas: aceptado (el esquema no distingue mayusculas, RFC 7235); el token sigue siendo exigido', true);
foreach (['abc', 'a.b.c', '..', str_repeat('A', 70000), b64('{"alg":"none"}') . '.' . b64('{"user_id":3001,"role":"admin","session_version":1,"exp":' . (time() + 600) . '}') . '.',
          b64('{"alg":"none","typ":"JWT"}') . '.' . b64('{"user_id":3001,"role":"admin","session_version":1,"exp":' . (time() + 600) . '}') . '.x'] as $i => $malo) {
    [$s] = http('GET', SONDA, bearer($malo));
    ok($s === 401, 'A11.' . ($i + 1) . ' token invalido/alg=none/enorme: 401 (' . $s . ')');
}
[$h, $p, $sig] = explode('.', $tUsr);
ok(http('GET', SONDA, bearer($h . '.' . $p . '.'))[0] === 401, 'A12 firma vacia: 401');
ok(http('GET', SONDA, bearer(b64('{"alg":"HS512","typ":"JWT"}') . '.' . $p . '.' . $sig))[0] === 401, 'A13 cabecera con otro algoritmo y misma firma: 401');
// dos cabeceras Authorization: invalida primero y valida despues, y al reves. Nunca eleva privilegios.
[$s1] = http('GET', SOLO_ADMIN, ['Authorization: Bearer ' . $tUsr, 'Authorization: Bearer invalido']);
[$s2] = http('GET', SOLO_ADMIN, ['Authorization: Bearer invalido', 'Authorization: Bearer ' . $tUsr]);
ok($s1 !== 200 && $s2 !== 200 && $s1 < 500 && $s2 < 500, "A14 Authorization duplicado (usuario normal + invalido, en ambos ordenes) no abre una ruta de administrador ($s1/$s2)");
// el rol del token es ignorado: sale de MySQL
$tFalso = token($pdo, $secret, 3002, ['role' => 'admin']);
ok(http('GET', SOLO_ADMIN, bearer($tFalso))[0] === 403, 'A15 token firmado que RECLAMA role=admin para un usuario normal: 403 (el rol sale de MySQL)');
[$s16] = http('GET', SOLO_ADMIN, bearer(token($pdo, $secret, 3002, ['user_id' => 3001])));
ok($s16 === 200, 'A16 token FIRMADO con el user_id de un administrador = ese administrador (la identidad es el user_id firmado; falsificarlo exige JWT_SECRET: por eso D4 pide la atestacion del secreto). No es una elusion sin el secreto', true);
ok(http('GET', SOLO_ADMIN, bearer(token($pdo, $secret, 3002, ['user_id' => 3001], 'otro-secreto-sintetico-0123456789abcdef')))[0] === 401, 'A16b el mismo token firmado con OTRO secreto: 401');
ok(http('GET', SOLO_ADMIN, bearer($tAdm))[0] === 200, 'A17 control: el administrador legitimo SI accede a la ruta de administrador');
ok(http('GET', SONDA, bearer($tUsr))[0] === 200, 'A18 control: el usuario normal SI accede a la consulta permitida');

echo "== B. Variantes de URL y de metodo contra rutas de administrador (usuario normal) ==\n";
$antes = n_users($pdo); $auditAntes = n_audit($pdo);
$rutas = ['/api/auditoria', '/api/auditoria/', '//api/auditoria', '/API/auditoria', '/api/./auditoria', '/api/auditoria%2f', '/api/auditoria;x=1', '/api/auditoria%00', '/api/Auditoria', '/api//auditoria', '/api/auditoria.json', '/api/x/../auditoria'];
$abierto = [];
foreach ($rutas as $r) {
    foreach (['GET', 'HEAD', 'POST'] as $m) {
        [$s, $b] = http($m, $r . (strpos($r, '?') === false ? '?per_page=1' : ''), array_merge(bearer($tUsr), ['X-HTTP-Method-Override: GET', 'X-Role: admin', 'X-User-Id: 3001']));
        if ($s === 200 && $m !== 'HEAD' && stripos($b, '"data"') !== false) { $abierto[] = "$m $r"; }
        if ($s >= 500) { $abierto[] = "$m $r => $s"; }
    }
}
ok(!$abierto, 'B01 12 variantes de ruta x 3 metodos con encabezados de rol falsos: la ruta de administrador no se abre ni rompe ' . implode(', ', $abierto));
$regs = ['/api/register', '/api/register/', '/API/register', '//api/register', '/api/./register', '/api/register%2f', '/api/register;x', '/api/register.json', '/api/Register'];
$bodyReg = ['username' => 'd3nuevo', 'email' => 'd3nuevo@example.invalid', 'password' => 'Sintetica#2026', 'first_name' => 'N', 'last_name' => 'U', 'role' => 'admin'];
$creados = [];
foreach ($regs as $r) {
    foreach (['POST', 'PUT', 'PATCH'] as $m) {
        $bodyReg['username'] = 'd3n' . substr(md5($r . $m), 0, 6); $bodyReg['email'] = $bodyReg['username'] . '@example.invalid';
        [$s] = http($m, $r, array_merge(bearer($tUsr), ['X-HTTP-Method-Override: POST']), $bodyReg);
        if ($s >= 200 && $s < 300) { $creados[] = "$m $r => $s"; }
    }
}
ok(n_audit($pdo) === $auditAntes, 'B02a las peticiones rechazadas no generan NINGUNA fila de auditoria (no hay auditoria falsa de operaciones no completadas)');
ok(!$creados && n_users($pdo) === $antes, 'B02 9 variantes de /api/register x 3 metodos como usuario normal (cuerpo con role=admin): ninguna crea cuenta ' . implode(', ', $creados));
[$s] = http('POST', '/api/register', [], $bodyReg);
ok($s === 401 || $s === 403, "B03 registro sin sesion: rechazado ($s)") ; ok(n_users($pdo) === $antes, 'B03b registro sin sesion: no crea cuenta');
$bodyReg['username'] = 'd3admin0'; $bodyReg['email'] = 'd3admin0@example.invalid'; $bodyReg['role'] = 'admin';
[$s] = http('POST', '/api/register', bearer($tAdm), $bodyReg);
ok($s === 400 && n_users($pdo) === $antes, "B04a incluso el administrador recibe 400 si envia «role» en el alta, y no se crea nada ($s)");
unset($bodyReg['role']);
$bodyReg['username'] = 'd3admin1'; $bodyReg['email'] = 'd3admin1@example.invalid';
[$s] = http('POST', '/api/register', bearer($tAdm), $bodyReg);
ok($s >= 200 && $s < 300 && n_users($pdo) === $antes + 1, "B04 control: el administrador legitimo SI da de alta, sin «role» ($s)");
ok(n_audit($pdo) >= $auditAntes + 1, 'B04b la operacion completada SI deja su auditoria');
ok(campo($pdo, (int)$pdo->query("SELECT id FROM user WHERE username='d3admin1'")->fetchColumn(), 'role') !== 'admin', 'B05 el rol del alta NO viene del cuerpo (role=admin en el cuerpo no crea un administrador)');

echo "== C. A1: perfil ajeno ==\n";
foreach (['GET', 'PUT'] as $m) {
    foreach (['3001', '03001', '+3001', '3001abc', '3001.0', '%33001'] as $id) {
        [$s, $b] = http($m, "/api/user/profile/$id", bearer($tUsr), ['first_name' => 'HackD3']);
        ok(!($s === 200 && stripos($b, 'd3u3001') !== false) && campo($pdo, 3001, 'first_name') === 'Demo', "C01 $m perfil ajeno /$id como usuario normal: no lee ni cambia ($s)");
    }
}
[$s] = http('PUT', '/api/user/profile', bearer($tUsr), ['id' => 3001, 'user_id' => 3001, 'first_name' => 'PropioD3']);
ok(campo($pdo, 3001, 'first_name') === 'Demo', "C02 PUT /api/user/profile con id ajeno en el cuerpo: el administrador NO cambia ($s)");
[$s] = http('GET', '/api/user/profile/3002', bearer($tUsr));
ok($s === 200, "C03 control: el usuario ve su propio perfil ($s)");
[$s] = http('GET', '/api/user/profile/3002', bearer($tAdm));
ok($s === 200, "C04 control: el administrador ve cualquier perfil ($s)");

echo "== D. Sesion revocada o inactiva con token valido (RC1c) ==\n";
$pdo->exec('UPDATE user SET is_active = 0 WHERE id = 3003'); $t3 = token($pdo, $secret, 3003);
ok(http('GET', SONDA, bearer($t3))[0] === 401, 'D01 usuario inactivo con token firmado y vigente: 401');
$pdo->exec('UPDATE user SET is_active = 1, session_version = session_version + 1 WHERE id = 3003');
ok(http('GET', SONDA, bearer($t3))[0] === 401, 'D02 sesion revocada (session_version cambiado): 401');
$tNuevo = token($pdo, $secret, 3003);
ok(http('GET', SONDA, bearer($tNuevo))[0] === 200, 'D03 control: tras reactivar y reemitir el token, vuelve a funcionar');
$pdo->exec('DELETE FROM user WHERE id >= 3000 OR username LIKE \'d3n%\' OR username = \'d3admin1\'');
printf("\nRESULTADO D3-RC1c: %d/%d sin fallo, %d fallos\n", $total - $failures, $total, $failures);
exit($failures ? 1 : 0);
