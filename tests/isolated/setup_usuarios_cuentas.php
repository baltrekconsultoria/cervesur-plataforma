<?php
// UX-07D.4B: crea en el MySQL DESECHABLE de las pruebas las tres cuentas del piloto con usuarios y su base de estado ejecutando EXACTAMENTE los archivos de
// database/schema/piloto-usuarios/ (01 y 02) con los marcadores sustituidos (base fixture_prod, estado estado_piloto_test, host %).
// Entorno: TEST_DB_PASS (root), U_AUTH_PASS, U_CAT_PASS, U_STATE_PASS (claves generadas por el ejecutor; nunca fijas en el repositorio).
$root = new PDO('mysql:host=db;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$map = ['__BASE__' => 'fixture_prod', '__BASE_ESTADO__' => 'estado_piloto_test', '__HOST__' => '%', '__CLAVE_AUTH__' => (string)getenv('U_AUTH_PASS'), '__CLAVE_CATALOGO__' => (string)getenv('U_CAT_PASS'), '__CLAVE_ESTADO__' => (string)getenv('U_STATE_PASS')];
foreach (['__CLAVE_AUTH__', '__CLAVE_CATALOGO__', '__CLAVE_ESTADO__'] as $k) { if ($map[$k] === '') { fwrite(STDERR, "Falta la clave $k\n"); exit(1); } }
foreach (['cervesur_ro_auth', 'cervesur_ro_catalogo', 'cervesur_react_estado'] as $u) { $root->exec("DROP USER IF EXISTS '$u'@'%'"); }
$root->exec('DROP DATABASE IF EXISTS estado_piloto_test');
$n = 0;
foreach (['01-cuentas-lectura.sql', '02-estado-base-y-cuenta.sql'] as $file) {
    $sql = preg_replace('/^\s*--.*$/m', '', strtr((string)file_get_contents(__DIR__ . '/../../database/schema/piloto-usuarios/' . $file), $map));
    foreach (array_values(array_filter(array_map('trim', explode(';', $sql)))) as $statement) { $root->exec($statement); $n++; }
}
echo "Cuentas del piloto con usuarios y base de estado creadas con el procedimiento administrativo ($n sentencias)\n";
