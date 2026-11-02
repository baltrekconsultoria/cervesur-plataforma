<?php
// Run locally with: php tests/security_unit.php. Never uses network or a database.
require_once __DIR__ . '/../backend/models/EmpresaModel.php';
require_once __DIR__ . '/../backend/controllers/PowerBIController.php';
require_once __DIR__ . '/../backend/lib/jwt.php';
require_once __DIR__ . '/../backend/lib/roles.php';
require_once __DIR__ . '/../backend/lib/errors.php';
require_once __DIR__ . '/../backend/lib/powerbi_link.php';
require_once __DIR__ . '/../backend/lib/admin_viewer_allowlist.php';

function check($condition, $name) {
    if (!$condition) { fwrite(STDERR, "FAIL: $name\n"); exit(1); }
    echo "PASS: $name\n";
}
class FakeStatement {
    public $sql;
    public $params;
    public function __construct($sql) { $this->sql = $sql; }
    public function execute($params = []) { $this->params = $params; return true; }
    public function fetchAll($mode = null) { return [['id' => 1, 'nombre' => 'Empresa ficticia']]; }
    public function fetch($mode = null) { return ['id' => 1, 'nombre' => 'Empresa ficticia']; }
}
class FakePdo {
    public $statement;
    public function prepare($sql) { return $this->statement = new FakeStatement($sql); }
}
$pdo = new FakePdo();
$model = new EmpresaModel($pdo);
$company = $model->getAll(7, 'user');
check(strpos($pdo->statement->sql, 'eu.activo = 1') !== false, 'company membership condition');
check(($pdo->statement->params[':visible_user_id'] ?? null) === 7, 'company scoped to requesting user');
check(!array_key_exists('powerbi_clientsecret', $company[0]), 'company output excludes secret');
$model->getAll(7, 'admin');
check(strpos($pdo->statement->sql, 'empresa_usuario eu') === false, 'administrator company query');
$powerbi = new PowerBIModel($pdo);
$powerbi->getCredentialsEmpresaReporte(5, 7, 'user');
check(strpos($pdo->statement->sql, 'uer.activo = 1') !== false, 'active report assignment condition');
check(strpos($pdo->statement->sql, 'eu.activo = 1') !== false, 'active company assignment condition');
check(($pdo->statement->params[':user_id'] ?? null) === 7, 'report scoped to requesting user');
$called = 0;
$controller = new PowerBIController($pdo, function ($url, $context) use (&$called) {
    $called++;
    return json_encode(['access_token' => 'synthetic-test-value']);
});
$result = $controller->getAccessToken('synthetic-tenant', 'synthetic-client', 'synthetic-secret');
check($called === 1 && $result['success'] === true, 'provider simulation returns success');
$controller = new PowerBIController($pdo, function () { return json_encode(['error_description' => 'internal synthetic detail']); });
$result = $controller->getAccessToken('synthetic-tenant', 'synthetic-client', 'synthetic-secret');
check($result['success'] === false && strpos(json_encode($result), 'internal synthetic detail') === false, 'provider error is sanitized');

$jwtSecret = 'synthetic-unit-jwt-secret';
$validPayload = [
    'user_id' => 7,
    'username' => 'synthetic-user',
    'email' => 'synthetic@example.invalid',
    'session_version' => 1,
    'exp' => time() + 60,
];
$validToken = generate_jwt($validPayload, $jwtSecret);
check(validate_jwt($validToken, $jwtSecret) === $validPayload, 'strict JWT accepts complete active-session claims');

$expiredPayload = $validPayload;
$expiredPayload['exp'] = time() - 1;
check(validate_jwt(generate_jwt($expiredPayload, $jwtSecret), $jwtSecret) === false, 'strict JWT rejects expired token');

$missingExpiration = $validPayload;
unset($missingExpiration['exp']);
check(validate_jwt(generate_jwt($missingExpiration, $jwtSecret), $jwtSecret) === false, 'strict JWT rejects missing expiration');

$missingSessionVersion = $validPayload;
unset($missingSessionVersion['session_version']);
check(validate_jwt(generate_jwt($missingSessionVersion, $jwtSecret), $jwtSecret) === false, 'strict JWT rejects missing session version');

$missingIdentity = $validPayload;
unset($missingIdentity['email']);
check(validate_jwt(generate_jwt($missingIdentity, $jwtSecret), $jwtSecret) === false, 'strict JWT rejects incomplete identity');

check(validate_jwt($validToken, 'different-synthetic-secret') === false, 'strict JWT rejects invalid signature');

// SEC-05A: política de roles
check(normalize_role('admin') === 'admin' && normalize_role(' Admin ') === 'admin' && normalize_role('ADMIN') === 'admin', 'role policy normalises admin');
check(normalize_role('user') === 'user' && normalize_role('usuario') === 'user' && normalize_role('USUARIO') === 'user', "role policy maps legacy 'usuario' to 'user'");
foreach (['', 'root', 'superadmin', 'administrator', 'admin;', 'admin user', null, 1, true, [], ['admin']] as $bad) {
    check(normalize_role($bad) === null, 'role policy rejects ' . json_encode($bad));
}

// SEC-05A: los mensajes públicos no incluyen el detalle de la excepción
$captured = tempnam(sys_get_temp_dir(), 'err');
$previousLog = ini_set('error_log', $captured);
$publicMessage = public_error_message('Error al consultar', new PDOException("SQLSTATE[42S02]: Base table or view not found: 1146 Table 'db.secret' doesn't exist"));
ini_set('error_log', $previousLog === false ? '' : $previousLog);
$logged = (string)file_get_contents($captured);
@unlink($captured);
check($publicMessage === 'Error al consultar', 'public error message is the generic text');
check(strpos($publicMessage, 'SQLSTATE') === false && strpos($publicMessage, 'secret') === false, 'public error message hides database detail');
check(strpos($logged, 'SQLSTATE[42S02]') !== false, 'technical detail is kept in the internal log');

// UX-07C: clasificación de enlaces de Power BI (vectores compartidos con la prueba de React)
$vectors = json_decode((string)file_get_contents(__DIR__ . '/fixtures/link_vectors.json'), true)['cases'];
foreach ($vectors as $case) {
    $r = classify_powerbi_link($case['url']);
    if (isset($case['error'])) {
        check($r['ok'] === false && $r['kind'] === null && $r['report_id'] === null && $r['group_id'] === null && strpos((string)$r['error'], $case['error']) !== false, 'link rejected: ' . $case['name']);
    } else {
        $okIds = ($case['reportId'] ?? null) === $r['report_id'] && ($case['groupId'] ?? null) === $r['group_id'] && ($case['token'] ?? null) === $r['token'];
        check($r['ok'] === true && $r['kind'] === $case['kind'] && $okIds && $r['error'] === null, 'link accepted: ' . $case['name']);
    }
}
check(classify_powerbi_link(null)['ok'] === false && classify_powerbi_link(['https://app.powerbi.com/view?r=U0lOVEVUSUNPLUNFUlZFU1VSLTAwMDE'])['ok'] === false, 'link classification rejects non-string input');
$refs = [generate_report_ref(), generate_report_ref()];
check($refs[0] !== $refs[1] && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $refs[0]) === 1, 'internal report references are random UUID v4 values');

// UX-08C: lista de informes del visor administrativo (configuración de servidor; falla cerrada).
putenv('ADMIN_VIEWER_ALLOWED_REPORT_IDS');
check(admin_viewer_allowlist() === null && admin_viewer_permits('admin', 5), 'viewer allow-list: not configured means no extra restriction');
putenv('ADMIN_VIEWER_ALLOWED_REPORT_IDS=72');
check(admin_viewer_allowlist() === [72], 'viewer allow-list: a single id is parsed');
check(admin_viewer_permits('admin', 72) && admin_viewer_permits('admin', '72'), 'viewer allow-list: admin may open a listed report');
check(!admin_viewer_permits('admin', 73) && !admin_viewer_permits('ADMIN', 73), 'viewer allow-list: admin may not open an unlisted report');
check(admin_viewer_permits('user', 73) && admin_viewer_permits('usuario', 73), 'viewer allow-list: workers are not restricted by the admin list');
putenv('ADMIN_VIEWER_ALLOWED_REPORT_IDS= 72 , 105,72 ');
check(admin_viewer_allowlist() === [72, 105], 'viewer allow-list: spaces and duplicates are normalized');
putenv('ADMIN_VIEWER_ALLOWED_REPORT_IDS=');
check(admin_viewer_allowlist() === [] && !admin_viewer_permits('admin', 72), 'viewer allow-list: an empty value allows no report');
foreach (['72,*', '72,0', '72,-1', '72,abc', '072', '72;105', '4294967296', 'all'] as $bad) {
    putenv('ADMIN_VIEWER_ALLOWED_REPORT_IDS=' . $bad);
    check(admin_viewer_allowlist() === [] && !admin_viewer_permits('admin', 72), 'viewer allow-list: invalid value fails closed: ' . $bad);
}
putenv('ADMIN_VIEWER_ALLOWED_REPORT_IDS');
