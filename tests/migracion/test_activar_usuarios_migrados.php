<?php
/**
 * Synthetic QA for 114 imported accounts activation, no DB nor real PII.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../ops/migracion/activar_usuarios_migrados.php';

function actAssert(bool $condition, string $name): void {
    if (!$condition) throw new RuntimeException('QA_' . $name);
}
function actReject(callable $attempt, string $reason): void {
    try { $attempt(); } catch (RuntimeException $e) {
        actAssert($e->getMessage() === $reason, 'ERROR_DIFERENTE');
        return;
    }
    throw new RuntimeException('QA_NO_RECHAZO');
}

$snapshot = ['schema'=>'cervesur-usuarios-privados-v1','origin'=>'react',
    'database'=>'cervesur_portalbi','includes_passwords'=>false,'users'=>[]];
$rows = [];
$creations = [];
$hash = password_hash('synthetic-NOT-a-real-credential', PASSWORD_DEFAULT);
for ($i = 1; $i <= 76; $i++) {
    $isActive = $i <= 62 ? 1 : 0;
    $snapshot['users'][] = [
        'id'=>$i, 'first_name'=>'Usuario','last_name'=>'Original',
        'username'=>"existente$i", 'email'=>"existente$i@example.test", 'phone'=>null,
        'role'=>'user', 'activo'=>$isActive,
    ];
    $rows[] = [
        'id'=>$i, 'first_name'=>'Usuario','last_name'=>'Original',
        'username'=>"existente$i", 'email'=>"existente$i@example.test", 'phone'=>null,
        'role'=>'user', 'is_active'=>$isActive,'session_version'=>1,'password'=>$hash,
    ];
}
for ($i = 77; $i <= 190; $i++) {
    $creations[] = [
        'origen_id'=>$i,'first_name'=>'Usuario','last_name'=>'Migrado',
        'username'=>"migrado$i",'email'=>"migrado$i@example.test",'phone'=>null,
        'role_origen'=>'usuario','activo_origen'=>1,'rol_propuesto'=>'user',
        'activo_propuesto'=>0,'requiere_inicializacion_credenciales'=>true,
    ];
    $rows[] = [
        'id'=>$i,'first_name'=>'Usuario','last_name'=>'Migrado',
        'username'=>"migrado$i",'email'=>"migrado$i@example.test",'phone'=>null,
        'role'=>'user','is_active'=>0,'session_version'=>1,'password'=>$hash,
    ];
}
$plan = [
    'schema'=>'cervesur-plan-usuarios-v1',
    'modo'=>'SIMULACION_SIN_ESCRITURAS',
    'politica'=>'EXISTENTES_INTACTOS_ALTAS_INACTIVAS','no_contiene_passwords'=>true,
    'resumen'=>[
        'origen_total'=>190,'destino_total'=>76,'coincidentes_conservados'=>76,
        'candidatos_inactivos'=>114,'sin_correo_coincidente'=>114,'revision_requerida'=>0,
        'destino_no_presente_en_origen'=>0,'aplicacion_autorizada'=>false,
        'mysql_modificado'=>false,
    ],
    'creaciones_propuestas_privadas'=>$creations,
    'revisiones_privadas'=>[],
];
$targets = activationValidate($rows, $plan, $snapshot, false);
actAssert(count($targets)===114 && $targets[0]['id']===77, 'CHECK_114');
actReject(fn()=>activationValidate($rows, $plan, $snapshot, true), 'ACT_CUENTA_INESPERADA');
$activated = $rows;
for ($i=76; $i<190; $i++) {
    $activated[$i]['is_active']=1;
    $activated[$i]['session_version']=2;
}
actAssert(count(activationValidate($activated, $plan, $snapshot, true))===114, 'ACTIVOS_114');

$bad = $rows;
$bad[0]['is_active']=0;
actReject(fn()=>activationValidate($bad,$plan,$snapshot,false),'ACT_EXISTENTES_CAMBIADOS');
$bad = $rows;
$bad[76]['role']='admin';
actReject(fn()=>activationValidate($bad,$plan,$snapshot,false),'ACT_CUENTA_INESPERADA');
$bad = $rows;
$bad[76]['session_version']=2;
actReject(fn()=>activationValidate($bad,$plan,$snapshot,false),'ACT_CUENTA_INESPERADA');
$bad = $rows;
$bad[76]['email']=$bad[0]['email'];
actReject(fn()=>activationValidate($bad,$plan,$snapshot,false),'ACT_UNICIDAD');
$bad = $rows;
array_pop($bad);
actReject(fn()=>activationValidate($bad,$plan,$snapshot,false),'ACT_TOTAL');
$bad = $activated;
$bad[76]['is_active']=0;
actReject(fn()=>activationValidate($bad,$plan,$snapshot,true),'ACT_CUENTA_INESPERADA');
$bad = $activated;
$bad[76]['session_version']=3;
actReject(fn()=>activationValidate($bad,$plan,$snapshot,true),'ACT_CUENTA_INESPERADA');

echo "CERVESUR_ACTIVACION_QA=PASS\nCASOS=10\nDB_CONSULTADA=NO\nDATOS_REALES=NO\n";
