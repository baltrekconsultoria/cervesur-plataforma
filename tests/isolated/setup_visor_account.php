<?php
// UX-07D.4: crea la cuenta del piloto visual en el MySQL DESECHABLE de las pruebas ejecutando EXACTAMENTE database/schema/piloto-visual/01-cuenta-visor.sql
// con los marcadores sustituidos (base fixture_prod, host %). Entorno: TEST_DB_PASS (root) y RO_VISOR_PASS (clave generada por el ejecutor de pruebas).
$root = new PDO('mysql:host=db;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$pass = (string)getenv('RO_VISOR_PASS');
if ($pass === '') { fwrite(STDERR, "Falta RO_VISOR_PASS\n"); exit(1); }
$sql = (string)file_get_contents(__DIR__ . '/../../database/schema/piloto-visual/01-cuenta-visor.sql');
$sql = preg_replace('/^\s*--.*$/m', '', strtr($sql, ['__BASE__' => 'fixture_prod', '__HOST__' => '%', '__CLAVE_VISOR__' => $pass]));
$root->exec("DROP USER IF EXISTS 'cervesur_ro_visor'@'%'");
$n = 0; foreach (array_values(array_filter(array_map('trim', explode(';', $sql)))) as $statement) { $root->exec($statement); $n++; }
echo "Cuenta del piloto visual creada con el procedimiento administrativo ($n sentencias)\n";
