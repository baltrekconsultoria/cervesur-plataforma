<?php
/* P5-R: prueba aislada de capacidades sin acceso a MySQL ni cuentas reales. */
function app_production_databases($config) { return ['cervesur_portalbi']; }
require_once __DIR__ . '/../../backend/lib/admin_escritura.php';

$passed = 0; $failed = 0;
function test_p5r($name, $condition) {
    global $passed, $failed;
    if ($condition) { $passed++; echo "PASS: $name\n"; }
    else { $failed++; echo "FAIL: $name\n"; }
}
function p5r_denies($sql) {
    try { app_sql_write_guard($sql); return false; }
    catch (WriteViolation $e) { return true; }
}
function p5r_status($path) {
    $gate = app_mode_write_gate('PUT', $path);
    return $gate['status'] ?? 200;
}
putenv('DB_NAME=cervesur_portalbi');
foreach (['ADMIN_WRITES_USUARIOS','ADMIN_WRITES_USUARIOS_PASSWORD_RESET','ADMIN_WRITES_USUARIOS_SENSIBLE','ADMIN_P5R_FLUTTER_401_VERIFIED'] as $flag) { putenv($flag); }

test_p5r('reset OFF por omision', !admin_write_capability_on('usuarios_password_reset'));
test_p5r('ruta reset OFF 403', p5r_status('/api/users/2/password') === 403);
test_p5r('ruta rol OFF 403', p5r_status('/api/user/2/role') === 403);

putenv('ADMIN_WRITES_USUARIOS=1');
test_p5r('U-BASIC ON no activa password', !admin_write_capability_on('usuarios_password_reset'));
putenv('ADMIN_WRITES_USUARIOS_PASSWORD_RESET=1');
test_p5r('sin QA Flutter password OFF', !admin_write_capability_on('usuarios_password_reset'));
putenv('ADMIN_P5R_FLUTTER_401_VERIFIED=1');
test_p5r('password ON con los 3 gates', admin_write_capability_on('usuarios_password_reset'));
test_p5r('ruta password ON', p5r_status('/api/users/2/password') === 200);
test_p5r('ruta rol sigue OFF', p5r_status('/api/user/2/role') === 403);
test_p5r('capacidad vieja sensible siempre OFF', !admin_write_capability_on('usuarios_sensible'));

$sql = 'UPDATE user SET password = :p, session_version = session_version + 1, updated_at = NOW() WHERE id = :id';
$accepted = true;
try { app_sql_write_guard($sql); } catch (WriteViolation $e) { $accepted = false; }
test_p5r('UPDATE password + session_version exacto', $accepted);
test_p5r('password sin session_version rechazado', p5r_denies('UPDATE user SET password = :p WHERE id = :id'));
test_p5r('UPDATE rol rechazado', p5r_denies('UPDATE user SET role = :r WHERE id = :id'));
test_p5r('UPDATE combinado rechazado', p5r_denies('UPDATE user SET password = :p, role = :r WHERE id = :id'));

$q = chr(96);
$grants = ["GRANT UPDATE (role) ON {$q}cervesur_portalbi{$q}.{$q}user{$q} TO 'qa'@'127.0.0.1'"];
$issues = admin_write_grants_violations($grants, 'cervesur_portalbi',
    ['usuarios' => true, 'passwordReset' => true], true);
test_p5r('GRANT UPDATE(role) fatal', in_array('UPDATE@user:role-prohibido-P5R', $issues, true));

putenv('ADMIN_WRITES_USUARIOS_SENSIBLE=1');
test_p5r('flag antiguo sensible no habilita password', !admin_write_capability_on('usuarios_password_reset'));
test_p5r('flag antiguo sensible reporta error', count(admin_write_config_errors([
    'AUDIT_LOG_MODE' => 'required',
    'REACT_STRICT_VIEWS' => '1',
    'JWT_SECRET' => str_repeat('Q', 40),
    'ADMIN_WRITES_USUARIOS' => '1',
    'ADMIN_WRITES_USUARIOS_PASSWORD_RESET' => '1',
    'ADMIN_P5R_FLUTTER_401_VERIFIED' => '1',
    'ADMIN_WRITES_USUARIOS_SENSIBLE' => '1'
])) > 0);
putenv('ADMIN_WRITES_USUARIOS_SENSIBLE');
putenv('ADMIN_WRITES_USUARIOS_PASSWORD_RESET');
test_p5r('apagar reset rechaza SQL', p5r_denies($sql));
test_p5r('apagar reset rechaza ruta', p5r_status('/api/users/2/password') === 403);

echo "P5R_GATE_PASS=$passed\nP5R_GATE_FAIL=$failed\n";
exit($failed === 0 ? 0 : 1);
