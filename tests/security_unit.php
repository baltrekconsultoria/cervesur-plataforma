<?php
// Run locally with: php tests/security_unit.php. Never uses network or a database.
require_once __DIR__ . '/../backend/models/EmpresaModel.php';
require_once __DIR__ . '/../backend/controllers/PowerBIController.php';
require_once __DIR__ . '/../backend/lib/jwt.php';

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
