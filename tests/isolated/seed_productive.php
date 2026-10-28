<?php
// Fixture SINTÉTICO con la forma del esquema productivo para las pruebas HTTP (UX-07D.2B). Base desechable `fixture_prod` en el MySQL aislado.
// El contenido vive en productive_fixture.php (compartido con el entorno de compatibilidad de UX-07D.2C).
require __DIR__ . '/productive_fixture.php';
$root = new PDO('mysql:host=db;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$root->exec('DROP DATABASE IF EXISTS fixture_prod');
$root->exec('CREATE DATABASE fixture_prod CHARACTER SET utf8mb4');
$pdo = new PDO('mysql:host=db;dbname=fixture_prod;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
productive_fixture_ddl($pdo);
$hash = password_hash('synthetic-password', PASSWORD_DEFAULT);
productive_fixture_load($pdo, fn () => $hash);
$bad = productive_fixture_deviations($pdo);
foreach ($bad as $line) fwrite(STDERR, "DESVÍO $line
");
if ($bad) exit(1);
echo "Fixture productivo sintético cargado: agregados verificados
";
