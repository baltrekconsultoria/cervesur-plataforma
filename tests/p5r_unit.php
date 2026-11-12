<?php
/** P5-R: suite unitaria aislada sin DB, red ni secretos. */
declare(strict_types=1);
function app_production_databases(array $extra = []): array { return ['cervesur_portalbi']; }
require __DIR__ . '/../backend/lib/admin_escritura.php';
$passed = 0;
$failed = 0;
function assertP5R(string $name, bool $ok): void {
    global $passed, $failed;
    if ($ok) { $passed++; echo "PASS $name\n"; }
    else { $failed++; echo "FAIL $name\n"; }
}
function envP5R(bool $user, bool $reset, bool $sensible = false): void {
    putenv('DB_NAME=cervesur_portalbi');
    putenv('ADMIN_WRITES_USUARIOS=' . ($user ? '1' : '0'));
    putenv('ADMIN_WRITES_USUARIOS_PASSWORD_RESET=' . ($reset ? '1' : '0'));
    putenv('ADMIN_WRITES_USUARIOS_SENSIBLE=' . ($sensible ? '1' : '0'));
}
function p5r_sql($sql): bool {
    try { app_sql_write_guard($sql); return true; }
    catch (WriteViolation $e) { return false; }
}
envP5R(true, false);
assertP5R('reset OFF bloquea ruta', (app_mode_write_gate('PUT', '/api/users/42/password')['status'] ?? 0) === 403);
assertP5R('reset OFF bloquea UPDATE(password)', !p5r_sql('UPDATE user SET password = :p, session_version = session_version + 1, updated_at = NOW() WHERE id = :id'));
envP5R(true, true);
assertP5R('reset ON abre solo ruta password', app_mode_write_gate('PUT', '/api/users/42/password') === null);
assertP5R('reset ON no abre ruta role', (app_mode_write_gate('PUT', '/api/user/42/role')['status'] ?? 0) === 403);
assertP5R('reset ON no permite alias de password', (app_mode_write_gate('PUT', '/api/users/42/password/')['status'] ?? 0) === 403);
assertP5R('reset ON no permite POST password', (app_mode_write_gate('POST', '/api/users/42/password')['status'] ?? 0) === 403);
assertP5R('reset ON no permite UPDATE role', !p5r_sql('UPDATE user SET role = :role, session_version = session_version + 1 WHERE id = :id'));
assertP5R('reset ON permite UPDATE solo password', p5r_sql('UPDATE user SET password = :p, session_version = session_version + 1, updated_at = NOW() WHERE id = :id'));
assertP5R('P5-R bloquea omitir session_version', !p5r_sql('UPDATE user SET password = :p WHERE id = :id'));
assertP5R('reset ON bloquea UPDATE role combinado', !p5r_sql('UPDATE user SET password = :p, role = :role WHERE id = :id'));
envP5R(false, true);
assertP5R('U-BASIC OFF bloquea reset', (app_mode_write_gate('PUT', '/api/users/42/password')['status'] ?? 0) === 403);
$cfg = ['AUDIT_LOG_MODE' => 'required', 'REACT_STRICT_VIEWS' => '1', 'JWT_SECRET' => str_repeat('x', 40), 'ADMIN_WRITES_USUARIOS_PASSWORD_RESET' => '1', 'ADMIN_WRITES_USUARIOS' => '0'];
assertP5R('config rechaza reset sin usuarios', count(admin_write_config_errors($cfg)) > 0);
$cfg['ADMIN_WRITES_USUARIOS'] = '1';
$cfg['ADMIN_WRITES_USUARIOS_SENSIBLE'] = '1';
assertP5R('config rechaza P5 completo + P5-R', count(admin_write_config_errors($cfg)) > 0);
$cfg['ADMIN_WRITES_USUARIOS_SENSIBLE'] = '0';
assertP5R('config acepta solo P5-R', admin_write_config_errors($cfg) === []);
$base = "GRANT UPDATE (first_name, last_name, phone, is_active, session_version, updated_at, last_login, password) ON `cervesur_portalbi`.`user` TO 'cervesur_escritura_adm'@'127.0.0.1'";
$flags = ['usuarios' => true, 'sensible' => false, 'password_reset' => true];
assertP5R('P5-R valida grant password sin role', admin_write_grants_violations([$base], 'cervesur_portalbi', $flags) === []);
assertP5R('P5-R rechaza UPDATE(role) en grant', count(admin_write_grants_violations([str_replace('password)', 'password, role)', $base)], 'cervesur_portalbi', $flags)) > 0);
$flags['password_reset'] = false;
assertP5R('P5-R OFF rechaza grant password', count(admin_write_grants_violations([$base], 'cervesur_portalbi', $flags)) > 0);
echo "P5R_UNIT=" . ($failed === 0 ? "PASS_$passed" : "FAIL_$failed") . "\n";
echo "PRODUCCION_CONTACTADA=NO\n";
exit($failed === 0 ? 0 : 1);
