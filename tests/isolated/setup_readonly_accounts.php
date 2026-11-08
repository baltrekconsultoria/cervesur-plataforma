<?php
// UX-07D.3A: crea las cuentas de solo lectura en el MySQL DESECHABLE de las pruebas ejecutando EXACTAMENTE los archivos del procedimiento administrativo
// (database/schema/admin-requiere-autorizacion/), con los marcadores sustituidos. Así se prueban los permisos por columna reales que se pedirán en producción.
// Entorno: TEST_DB_PASS (root del MySQL desechable), RO_DIAG_PASS y RO_PILOT_PASS (claves generadas por el ejecutor de pruebas; nunca fijas en el repositorio).
$root = new PDO('mysql:host=db;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$dir = __DIR__ . '/../../database/schema/admin-requiere-autorizacion/';
$replace = ['__BASE__' => 'fixture_prod', '__HOST__' => '%', '__CLAVE_DIAGNOSTICO__' => (string)getenv('RO_DIAG_PASS'), '__CLAVE_PILOTO__' => (string)getenv('RO_PILOT_PASS')];
if ($replace['__CLAVE_DIAGNOSTICO__'] === '' || $replace['__CLAVE_PILOTO__'] === '') { fwrite(STDERR, "Faltan RO_DIAG_PASS / RO_PILOT_PASS\n"); exit(1); }

function statements(string $sql): array {
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    return array_values(array_filter(array_map('trim', explode(';', $sql))));
}
$root->exec("DROP USER IF EXISTS 'cervesur_ro_diag'@'%'");
$root->exec("DROP USER IF EXISTS 'cervesur_ro_pilot'@'%'");
$n = 0;
foreach (['01-cuenta-diagnostico.sql', '03-cuenta-piloto-autenticado.sql'] as $file) {
    foreach (statements(strtr((string)file_get_contents($dir . $file), $replace)) as $statement) { $root->exec($statement); $n++; }
}
echo "Cuentas de solo lectura creadas con el procedimiento administrativo ($n sentencias)\n";
