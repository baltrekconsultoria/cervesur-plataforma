<?php
/** QA sintetico: NO DB, NO PII real, NO INSERT. */
declare(strict_types=1);
require_once __DIR__ . '/../../ops/migracion/usuarios_import_controlado.php';

function syntheticUser(int $i): array {
    return ['id' => $i, 'email' => "existente{$i}@example.test",
        'username' => "ex{$i}", 'role' => 'user', 'activo' => 1];
}
function syntheticPlan(): array {
    $rows = [];
    for ($i = 1; $i <= 114; $i++) {
        $rows[] = [
            'origen_id' => $i + 76, 'first_name' => 'Nombre',
            'last_name' => 'Apellido', 'username' => "nuevo{$i}",
            'email' => "nuevo{$i}@example.test", 'phone' => null,
            'role_origen' => 'usuario', 'activo_origen' => 1,
            'rol_propuesto' => 'user', 'activo_propuesto' => 0,
            'requiere_inicializacion_credenciales' => true,
        ];
    }
    return [
        'schema' => 'cervesur-plan-usuarios-v1',
        'modo' => 'SIMULACION_SIN_ESCRITURAS',
        'politica' => 'EXISTENTES_INTACTOS_ALTAS_INACTIVAS',
        'no_contiene_passwords' => true,
        'resumen' => [
            'origen_total' => 190, 'destino_total' => 76,
            'coincidentes_conservados' => 76, 'candidatos_inactivos' => 114,
            'sin_correo_coincidente' => 114, 'revision_requerida' => 0,
            'destino_no_presente_en_origen' => 0, 'aplicacion_autorizada' => false,
            'mysql_modificado' => false,
        ],
        'revisiones_privadas' => [],
        'creaciones_propuestas_privadas' => $rows,
    ];
}
function expectGuard(bool $condition, string $code): void {
    if (!$condition) throw new RuntimeException('TEST_' . $code);
}
function expectReject(callable $callback, string $expected): void {
    try {
        $callback();
    } catch (RuntimeException $e) {
        expectGuard($e->getMessage() === $expected, 'RECHAZO_DIFERENTE');
        return;
    }
    throw new RuntimeException('TEST_SIN_RECHAZO');
}

$snapshot = [
    'schema' => 'cervesur-usuarios-privados-v1',
    'origin' => 'react', 'database' => 'cervesur_portalbi',
    'includes_passwords' => false, 'users' => [],
];
for ($i=1; $i<=76; $i++) $snapshot['users'][] = syntheticUser($i);
$plan = syntheticPlan();
expectGuard(count(validatePlan($plan, $snapshot)) === 114, 'PLAN_VALIDO');
$current = [];
foreach ($snapshot['users'] as $u) {
    $current[] = ['id' => $u['id'], 'email' => $u['email'],
        'username' => $u['username'], 'role' => $u['role'], 'is_active' => $u['activo']];
}
baselineUsers($current, $snapshot);

// Cambios de estado y cuentas existentes no pasan inadvertidos.
$current[0]['is_active'] = 0;
expectReject(fn() => baselineUsers($current, $snapshot), 'USUARIO_EXISTENTE_CAMBIO');

// No puede proponer administradores ni nuevas cuentas activas.
$bad = $plan;
$bad['creaciones_propuestas_privadas'][0]['rol_propuesto'] = 'admin';
expectReject(fn() => validatePlan($bad, $snapshot), 'CANDIDATO_INSEGURO');

$bad = $plan;
$bad['creaciones_propuestas_privadas'][0]['activo_propuesto'] = 1;
expectReject(fn() => validatePlan($bad, $snapshot), 'CANDIDATO_INSEGURO');

// Dos nuevos usuarios con mismo email / usuario: STOP.
$bad = $plan;
$bad['creaciones_propuestas_privadas'][1]['email'] = $bad['creaciones_propuestas_privadas'][0]['email'];
expectReject(fn() => validatePlan($bad, $snapshot), 'COLISION_O_DATOS_INVALIDOS');

$bad = $plan;
$bad['creaciones_propuestas_privadas'][1]['username'] = $bad['creaciones_propuestas_privadas'][0]['username'];
expectReject(fn() => validatePlan($bad, $snapshot), 'COLISION_O_DATOS_INVALIDOS');

// No puede reutilizar email ni username de cuentas existentes.
$bad = $plan;
$bad['creaciones_propuestas_privadas'][1]['email'] = $snapshot['users'][0]['email'];
expectReject(fn() => validatePlan($bad, $snapshot), 'COLISION_O_DATOS_INVALIDOS');

// Un nombre de usuario original puede contener simbolos imprimibles no
// admitidos por el formulario NUEVO; el backend admite login por username.
$legacy = $plan;
$legacy['creaciones_propuestas_privadas'][0]['username'] = 'ventas+sur.01';
expectGuard(count(validatePlan($legacy, $snapshot)) === 114, 'LEGACY_PRINTABLE');

// Pero caracteres de control o Unicode malformado NO deben ingresar.
$bad = $plan;
$bad['creaciones_propuestas_privadas'][0]['username'] = "nombre\\nsur";
expectReject(fn() => validatePlan($bad, $snapshot), 'COLISION_O_DATOS_INVALIDOS');

$bad = $plan;
$bad['creaciones_propuestas_privadas'][0]['username'] = str_repeat('x', 51);
expectReject(fn() => validatePlan($bad, $snapshot), 'COLISION_O_DATOS_INVALIDOS');

$bad = $plan;
$bad['creaciones_propuestas_privadas'][0]['username'] = 'nombre' . chr(0) . 'sur';
expectReject(fn() => validatePlan($bad, $snapshot), 'COLISION_O_DATOS_INVALIDOS');

// No permitir alias cruzados dentro de los 114 candidatos.
$bad = $plan;
$bad['creaciones_propuestas_privadas'][1]['username'] = $bad['creaciones_propuestas_privadas'][0]['email'];
expectReject(fn() => validatePlan($bad, $snapshot), 'COLISION_O_DATOS_INVALIDOS');

echo "CERVESUR_IMPORT_QA=PASS\\nCASOS=12\\nMYSQL_MODIFICADO=NO\\nSIN_PII_REAL=SI\\n";
