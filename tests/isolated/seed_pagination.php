<?php
// Fixture SINTÉTICO de volumen para las pruebas de paginación. Se ejecuta DESPUÉS de seed.php y de http_test.php,
// exclusivamente contra el MySQL desechable del contenedor aislado. Los ids altos evitan colisiones con seed.php.
//
// Usuarios (contraseña sintética pública del repositorio) y relaciones activas empresa-usuario / usuario-reporte:
//   pg0 = 0, pg1 = 1, pg20 = 20, pg21 = 21, pg45 = 45, pg105 = 105, pgmix = 5 vigentes + relaciones que NO deben contarse.
$pdo = new PDO('mysql:host=db;dbname=fixture;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$hash = password_hash('synthetic-password', PASSWORD_DEFAULT);
$pdo->beginTransaction();

$users = [101 => ['pg0', 0], 102 => ['pg1', 1], 103 => ['pg20', 20], 104 => ['pg21', 21], 105 => ['pg45', 45], 106 => ['pg105', 105], 107 => ['pgmix', 5]];
$insUser = $pdo->prepare('INSERT INTO user (id, username, email, password, first_name, last_name, role) VALUES (?, ?, ?, ?, ?, ?, ?)');
foreach ($users as $id => [$name]) {
    $insUser->execute([$id, $name, $name . '@example.invalid', $hash, 'Paginación', strtoupper($name), 'user']);
}

// 105 empresas y 105 informes sintéticos, relacionados 1 a 1 (empresa_reporte), más casos que NO deben contarse.
$insEmpresa = $pdo->prepare('INSERT INTO empresa (id, nombre, razon_social, ruc, ciudad, pais, powerbi_tenant, powerbi_client, powerbi_clientsecret, activo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
$insReporte = $pdo->prepare('INSERT INTO reporte (id, nombre, descripcion, enlace, grupo_id, reporte_id, created_by, activo) VALUES (?, ?, ?, ?, ?, ?, 1, ?)');
$insEr = $pdo->prepare('INSERT INTO empresa_reporte (id, empresa_id, reporte_id, activo) VALUES (?, ?, ?, ?)');
for ($i = 1; $i <= 105; $i++) {
    $n = sprintf('%03d', $i);
    $insEmpresa->execute([100 + $i, "PG Empresa $n", "PG Empresa $n SAC", sprintf('PG%09d', $i), 'Ciudad PG', 'Demo', "tenant-pg-$n", "client-pg-$n", "fixture-private-pg-$n", 1]);
    $insReporte->execute([100 + $i, "PG Reporte $n", "Informe sintético $n", "https://example.invalid/pg/$n", '00000000-0000-0000-0001-000000000000', sprintf('00000000-0000-0000-0001-%012d', 100 + $i), 1]);
    $insEr->execute([2000 + $i, 100 + $i, 100 + $i, 1]);
}
$insEmpresa->execute([301, 'PG Empresa Inactiva', 'PG Inactiva SAC', 'PG000000301', 'Ciudad PG', 'Demo', 't', 'c', 'fixture-private-pg-301', 0]);
$insReporte->execute([301, 'PG Reporte Inactivo', 'No debe listarse', 'https://example.invalid/pg/301', '00000000-0000-0000-0001-000000000000', '00000000-0000-0000-0001-000000000301', 0]);
$insEr->execute([2301, 301, 101, 1]);   // empresa inactiva
$insEr->execute([2302, 101, 301, 1]);   // informe inactivo
$insEr->execute([2303, 102, 102, 0]);   // relación empresa-informe inactiva

$insEu = $pdo->prepare('INSERT INTO empresa_usuario (id, empresa_id, user_id, created_at, activo) VALUES (?, ?, ?, ?, ?)');
$insUer = $pdo->prepare('INSERT INTO user_empresa_reporte (id, user_id, empresa_reporte_id, created_at, activo) VALUES (?, ?, ?, ?, ?)');
$euId = 10000; $uerId = 20000;
foreach ($users as $userId => [$name, $count]) {
    for ($i = 1; $i <= $count; $i++) {
        // pg45: marcas de tiempo repetidas por grupos de 5 para probar el desempate por id.
        $stamp = $name === 'pg45' ? date('Y-m-d H:i:s', strtotime('2026-02-01 10:00:00') + intdiv($i, 5)) : '2026-01-15 09:00:00';
        $insEu->execute([++$euId, 100 + $i, $userId, $stamp, 1]);
        $insUer->execute([++$uerId, $userId, 2000 + $i, $stamp, 1]);
    }
}
// pgmix: relaciones inactivas y relaciones sobre recursos inactivos (ninguna debe contarse).
foreach ([106, 107, 108] as $e) { $insEu->execute([++$euId, $e, 107, '2026-01-15 09:00:00', 0]); }          // empresa-usuario inactivas
foreach ([2006, 2007, 2008] as $er) { $insUer->execute([++$uerId, 107, $er, '2026-01-15 09:00:00', 0]); }   // usuario-reporte inactivas
$insEu->execute([++$euId, 301, 107, '2026-01-15 09:00:00', 1]);                                             // empresa inactiva
$insUer->execute([++$uerId, 107, 2301, '2026-01-15 09:00:00', 1]);                                          // ...con relación activa
$insUer->execute([++$uerId, 107, 2302, '2026-01-15 09:00:00', 1]);                                          // informe inactivo
$insUer->execute([++$uerId, 107, 2303, '2026-01-15 09:00:00', 1]);                                          // empresa-informe inactiva
$pdo->commit();
echo "Synthetic pagination fixtures loaded\n";
