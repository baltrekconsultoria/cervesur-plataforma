<?php
/** Postflight QA sintetico, sin MySQL ni PII de clientes. */
declare(strict_types=1);
require_once __DIR__ . '/../../ops/migracion/usuarios_postflight.php';

function testcheck(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException('TEST_' . $label);
}
function testreject(callable $call, string $expected): void {
    try { $call(); } catch (RuntimeException $e) {
        testcheck($e->getMessage() === $expected, 'RECHAZO');
        return;
    }
    throw new RuntimeException('TEST_DEBIO_RECHAZAR');
}

$snapshot = ['schema'=>'cervesur-usuarios-privados-v1',
    'origin'=>'react', 'database'=>'cervesur_portalbi',
    'includes_passwords'=>false,'users'=>[]];
$rows = [];
$proposals = [];
$hash = password_hash('synthetic-test-only-NOT-A-PRODUCTION-CREDENTIAL', PASSWORD_DEFAULT);
for ($i = 1; $i <= 76; $i++) {
    $active = $i <= 62 ? 1 : 0;
    $snapshot['users'][] = [
        'id'=>$i,'first_name'=>'Nombre','last_name'=>'Apellido',
        'username'=>"existente$i", 'email'=>"existente$i@example.test",
        'phone'=>null, 'role'=>'user', 'activo'=>$active];
    $rows[] = [
        'id'=>$i, 'first_name'=>'Nombre','last_name'=>'Apellido',
        'username'=>"existente$i", 'email'=>"existente$i@example.test",
        'phone'=>null, 'password'=>$hash, 'role'=>'user',
        'is_active'=>$active,'session_version'=>1];
}
for ($i = 77; $i <= 190; $i++) {
    $proposals[] = [
        'origen_id'=>$i,'first_name'=>'Nombre','last_name'=>'Apellido',
        'username'=>"nuevo$i", 'email'=>"nuevo$i@example.test",
        'phone'=>null,'role_origen'=>'usuario','activo_origen'=>1,
        'rol_propuesto'=>'user','activo_propuesto'=>0,
        'requiere_inicializacion_credenciales'=>true];
    $rows[] = [
        'id'=>$i,'first_name'=>'Nombre','last_name'=>'Apellido',
        'username'=>"nuevo$i", 'email'=>"nuevo$i@example.test",
        'phone'=>null, 'password'=>$hash, 'role'=>'user',
        'is_active'=>0,'session_version'=>1];
}
$plan = [
    'schema'=>'cervesur-plan-usuarios-v1', 'modo'=>'SIMULACION_SIN_ESCRITURAS',
    'politica'=>'EXISTENTES_INTACTOS_ALTAS_INACTIVAS', 'no_contiene_passwords'=>true,
    'resumen'=>[
        'origen_total'=>190,'destino_total'=>76,'coincidentes_conservados'=>76,
        'sin_correo_coincidente'=>114,'candidatos_inactivos'=>114,
        'revision_requerida'=>0,'destino_no_presente_en_origen'=>0,
        'aplicacion_autorizada'=>false,'mysql_modificado'=>false],
    'creaciones_propuestas_privadas'=>$proposals, 'revisiones_privadas'=>[],
];
$ok = postflightUsers($rows,$plan,$snapshot);
testcheck($ok['usuarios_totales'] === 190 && $ok['usuarios_activos'] === 62, 'PASO');
$bad = $rows;
$bad[76]['is_active'] = 1;
testreject(fn()=>postflightUsers($bad,$plan,$snapshot), 'POST_NUEVO_INVALIDO');
$bad = $rows;
$bad[0]['role'] = 'admin';
testreject(fn()=>postflightUsers($bad,$plan,$snapshot), 'POST_ORIGINALES_MODIFICADOS');
$bad = $rows;
array_pop($bad);
testreject(fn()=>postflightUsers($bad,$plan,$snapshot), 'POST_TOTAL');
$bad = $rows;
$bad[77]['email'] = $bad[76]['email'];
testreject(fn()=>postflightUsers($bad,$plan,$snapshot), 'POST_UNICIDAD');

echo "CERVESUR_POSTFLIGHT_QA=PASS\nCASOS=5\nMYSQL_CONSULTADO=NO\nSIN_PII_REAL=SI\n";
