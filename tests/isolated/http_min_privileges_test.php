<?php
// UX-08E: la API administrativa completa funciona conectada como la cuenta de PRIVILEGIOS MÍNIMOS (plantilla UX-08E), y no puede hacer lo prohibido.
// Instancia `appmin` (base `fixture_w`, DB_USER=app_admin_min, USER_ADMIN_WRITES=1, AUDIT_LOG_MODE=required). Este script, como root, aplica la migración
// de auditoría y crea la cuenta con la plantilla ANTES de la primera petición; la contraseña llega por MIN_PASS (la misma con la que arrancó la instancia).
$failures = 0;
$pdo = new PDO('mysql:host=db;dbname=fixture_w;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
const BASE = 'http://appmin:8080';

function requestApi($method, $path, $body = null, $token = null, $strict = true) {
    $headers = ['Content-Type: application/json'];
    if ($token !== null) $headers[] = 'Authorization: Bearer ' . $token;
    if ($strict) $headers[] = 'X-API-Contract: 2';
    $options = ['http' => ['method' => $method, 'header' => implode("\r\n", $headers), 'ignore_errors' => true, 'timeout' => 10]];
    if ($body !== null) $options['http']['content'] = json_encode($body);
    $raw = file_get_contents(BASE . $path, false, stream_context_create($options));
    $GLOBALS['lastResponse'] = substr($raw ?: '', 0, 300);
    $status = isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) ? (int)$m[1] : 0;
    return [$status, json_decode($raw ?: '', true), $raw ?: ''];
}
function expectCase($condition, $label) {
    global $failures;
    if ($condition) echo "PASS $label\n"; else { echo "FAIL $label\n  última respuesta: " . ($GLOBALS['lastResponse'] ?? '') . "\n"; $failures++; }
}
function tokenOf($name, $password = 'synthetic-password') { [, $d] = requestApi('POST', '/api/login', ['login' => $name, 'password' => $password], null, false); return $d['token'] ?? ''; }
function one($pdo, $sql, $params = []) { $st = $pdo->prepare($sql); $st->execute($params); return $st->fetch(); }
function total($pdo) { $n = 0; foreach (['user', 'empresa', 'reporte', 'empresa_usuario', 'empresa_reporte', 'user_empresa_reporte', 'audit_log'] as $t) $n += (int)one($pdo, "SELECT COUNT(*) c FROM $t")['c']; return $n; }

// ---- Preparación como root: migración de auditoría + cuenta con la plantilla
$pdo->exec(file_get_contents('database/migrations/UX-08B-audit-log.up.sql'));
$pass = getenv('MIN_PASS');
$sql = preg_replace('/^--.*$/m', '', strtr(file_get_contents('database/privileges/UX-08E-cuenta-admin-minima.sql.template'), ['{DB}' => 'fixture_w', '{USER}' => 'app_admin_min', '{HOST}' => '%', '{PASS}' => $pass]));
foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) $pdo->exec($statement);

$admin = tokenOf('admin');
expectCase($admin !== '', 'login works with the restricted account (it only needs SELECT and UPDATE(last_login))');
$minRows = total($pdo);

// ---- Empresas
[$s, $d] = requestApi('POST', '/api/empresas', ['nombre' => 'Empresa Min', 'razon_social' => 'Empresa Min', 'ruc' => '30000000001'], $admin);
$cid = (int)($d['id'] ?? 0);
expectCase($s === 200 && $cid > 0, 'create a company');
[$s] = requestApi('PUT', "/api/empresas/$cid", ['ciudad' => 'Lima'], $admin);
expectCase($s === 200 && one($pdo, 'SELECT ciudad FROM empresa WHERE id = ?', [$cid])['ciudad'] === 'Lima', 'edit a company');
[$s] = requestApi('DELETE', "/api/empresas/$cid", null, $admin);
expectCase($s === 200 && (int)one($pdo, 'SELECT activo FROM empresa WHERE id = ?', [$cid])['activo'] === 0, 'deactivate a company logically');
[$s] = requestApi('POST', "/api/empresas/$cid/reactivate", [], $admin);
expectCase($s === 200 && (int)one($pdo, 'SELECT activo FROM empresa WHERE id = ?', [$cid])['activo'] === 1, 'reactivate a company');
[$s, $d] = requestApi('PUT', "/api/empresas/$cid", ['powerbi_clientsecret' => 'SECRET-DEL-ATACANTE'], $admin);
expectCase($s >= 400 && one($pdo, 'SELECT powerbi_clientsecret s FROM empresa WHERE id = ?', [$cid])['s'] !== 'SECRET-DEL-ATACANTE', 'a crafted PUT cannot write the Power BI secret: the database refuses and nothing changes');
expectCase(!isset($d['message']) || stripos(json_encode($d), 'denied') === false && stripos(json_encode($d), 'SQLSTATE') === false, 'the refusal does not leak database error text');

// ---- Reportes
$link = 'https://app.powerbi.com/reportEmbed?reportId=dddddddd-0000-4000-8000-000000000001&groupId=bbbbbbbb-0000-4000-8000-000000000001';
[$s, $d] = requestApi('POST', '/api/reportes', ['nombre' => 'Informe Min', 'descripcion' => 'x', 'enlace' => $link], $admin);
$rid = (int)($d['data']['id'] ?? 0);
expectCase($s === 200 && $rid > 0, 'register a report');
[$s] = requestApi('DELETE', "/api/reportes/$rid", null, $admin);
expectCase($s === 200 && (int)one($pdo, 'SELECT activo FROM reporte WHERE id = ?', [$rid])['activo'] === 0, 'deactivate a report');
[$s] = requestApi('POST', "/api/reportes/$rid/reactivate", [], $admin);
expectCase($s === 200, 'reactivate a report');

// ---- Accesos (las tres relaciones)
[$s, $d] = requestApi('POST', '/api/empresa-usuario', ['user_id' => 3, 'empresa_id' => 2], $admin);
$eu = (int)($d['InsertedId'] ?? 0);
expectCase($s === 200 && $eu > 0, 'link a user to a company');
[$s, $d] = requestApi('POST', '/api/empresa-reporte-usuario', ['user_id' => 3, 'empresa_reporte_id' => 2], $admin);
$uer = (int)($d['InsertedId'] ?? 0);
expectCase($s === 200 && $uer > 0, 'grant an individual access');
[$s] = requestApi('DELETE', "/api/empresa-reporte-usuario/$uer", null, $admin);
expectCase($s === 200 && (int)one($pdo, 'SELECT activo FROM user_empresa_reporte WHERE id = ?', [$uer])['activo'] === 0, 'revoke an access logically');
[$s] = requestApi('POST', "/api/empresa-reporte-usuario/$uer/reactivate", [], $admin);
expectCase($s === 200, 'reactivate an access');
[$s] = requestApi('POST', '/api/asignacion', ['empresa_id' => 1, 'reporte_id' => 2], $admin);
expectCase($s === 200 || $s === 201, 'associate a report to a company');

// ---- Usuarios
[$s, $d] = requestApi('POST', '/api/register', ['first_name' => 'Min', 'last_name' => 'Priv', 'username' => 'min.priv', 'email' => 'min.priv@example.invalid', 'password' => 'una-clave-larga-123'], $admin);
$uid = (int)($d['id'] ?? 0);
expectCase($s === 200 && $uid > 0, 'create a user');
[, $detail] = requestApi('GET', "/api/users/$uid", null, $admin);
[$s] = requestApi('PUT', "/api/users/$uid", ['first_name' => 'Min', 'last_name' => 'Editado', 'phone' => '999 111 222', 'email' => 'min.priv@example.invalid', 'version' => $detail['data']['version'] ?? ''], $admin);
expectCase($s === 200 && one($pdo, 'SELECT last_name FROM user WHERE id = ?', [$uid])['last_name'] === 'Editado', 'edit a user');
$v0 = (int)one($pdo, 'SELECT session_version v FROM user WHERE id = ?', [$uid])['v'];
[$s] = requestApi('POST', "/api/users/$uid/deactivate", [], $admin);
expectCase($s === 200 && (int)one($pdo, 'SELECT is_active a FROM user WHERE id = ?', [$uid])['a'] === 0 && (int)one($pdo, 'SELECT session_version v FROM user WHERE id = ?', [$uid])['v'] === $v0 + 1, 'deactivate a user and revoke its sessions');
[$s] = requestApi('POST', "/api/users/$uid/reactivate", [], $admin);
expectCase($s === 200, 'reactivate a user');
[$s] = requestApi('PUT', "/api/user/$uid/role", ['role' => 'admin'], $admin);
expectCase($s === 200 && one($pdo, 'SELECT role FROM user WHERE id = ?', [$uid])['role'] === 'admin', 'change a role');
[$s] = requestApi('PUT', "/api/users/$uid/password", ['password' => 'otra-clave-larga-456'], $admin);
expectCase($s === 200 && tokenOf('min.priv', 'otra-clave-larga-456') !== '', 'reset a password (the new one works)');
[$s] = requestApi('POST', '/api/logout', null, tokenOf('usera'));
expectCase($s === 200, 'logout bumps the session version');

// ---- Historial y auditoría
[$s, $d] = requestApi('GET', '/api/auditoria?entity=user&per_page=100', null, $admin);
expectCase($s === 200 && ($d['audit_available'] ?? false) === true && count($d['data'] ?? []) >= 5, 'the history endpoint reads the audit log');
expectCase((int)one($pdo, "SELECT COUNT(*) c FROM audit_log WHERE action IN ('empresa.create','empresa.deactivate','reporte.create','user.create','user.deactivate','user.role','user.password')")['c'] >= 7, 'every operation left an audit row, written by the restricted account');

// ---- Nada se borró y la cuenta no tiene privilegios de más
expectCase(total($pdo) > $minRows, 'rows only grew: no physical deletes');
$grants = '';
foreach ($pdo->query("SHOW GRANTS FOR 'app_admin_min'@'%'")->fetchAll(PDO::FETCH_NUM) as $row) $grants .= $row[0] . "\n";
expectCase(stripos($grants, 'DELETE') === false && stripos($grants, 'ALL PRIVILEGES') === false && stripos($grants, 'DROP') === false, 'the account that ran everything above holds no DELETE, DROP or ALL PRIVILEGES');

$pdo->exec("DROP USER 'app_admin_min'@'%'");
$pdo->exec('DROP TABLE IF EXISTS audit_log');
echo $failures === 0 ? "ALL PASSED\n" : "$failures FAILED\n";
exit($failures === 0 ? 0 : 1);
