<?php
/** P5-SEC01: pruebas de extraccion Authorization sin datos ni servicios reales. */
require_once __DIR__ . '/../candidato/p5_legacy_adapter.php';
$secret = 'P5_synthetic_adapter_only_2026';
$passed = 0; $failed = 0;
function p5_expect($label, $ok) {
    global $passed, $failed;
    if ($ok) { $passed++; echo "PASS: $label\n"; }
    else { $failed++; echo "FAIL: $label\n"; }
}
$h = ['typ' => 'JWT', 'alg' => 'HS256'];
$c = ['user_id' => 1, 'session_version' => 1, 'exp' => time() + 600,
    'username' => 'test', 'email' => 'test@qa.invalid'];
$e = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
$payload = $e(json_encode($c));
$header = $e(json_encode($h));
$message = $header . '.' . $payload;
$token = $message . '.' . $e(hash_hmac('sha256', $message, $secret, true));
p5_expect('Authorization valido', p5_legacy_extract_bearer(['Authorization' => 'Bearer ' . $token]) === $token);
p5_expect('authorization minuscula', p5_legacy_extract_bearer(['authorization' => 'bearer ' . $token]) === $token);
p5_expect('falta cabecera', p5_legacy_extract_bearer([]) === false);
p5_expect('esquema incorrecto', p5_legacy_extract_bearer(['Authorization' => 'Basic ' . $token]) === false);
p5_expect('token con espacio', p5_legacy_extract_bearer(['Authorization' => 'Bearer ' . $token . ' otro']) === false);
p5_expect('token con salto', p5_legacy_extract_bearer(['Authorization' => "Bearer $token\r\nX-Test: 1"]) === false);
p5_expect('cabeceras duplicadas', p5_legacy_extract_bearer([
    'Authorization' => 'Bearer ' . $token, 'authorization' => 'Bearer ' . $token
]) === false);
p5_expect('cabecera como lista', p5_legacy_extract_bearer(['Authorization' => ['Bearer ' . $token]]) === false);
p5_expect('token largo rechazado', p5_legacy_extract_bearer(['Authorization' => 'Bearer ' . str_repeat('a', 8300)]) === false);
p5_expect('claim de cuerpo no se usa', p5_legacy_extract_bearer(['X-User-Id' => 1, 'X-Role' => 'admin']) === false);
echo "P5_SEC01_ADAPTER=$passed PASS / $failed FAIL\n";
exit($failed === 0 && $passed === 10 ? 0 : 1);
