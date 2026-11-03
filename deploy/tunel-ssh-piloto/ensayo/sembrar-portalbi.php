<?php
// SOLO PARA EL ENSAYO DOCKER (no va en el paquete). Crea en el MySQL DESECHABLE del contenedor una base llamada igual que la productiva (cervesur_portalbi) con el
// fixture SINTÉTICO de UX-07D.2B/2C: mismas tablas, tipos, claves foráneas y agregados que el inventario, con datos 100 % ficticios y hashes inutilizables.
// Uso (dentro del contenedor, como root): php sembrar-portalbi.php <productive_fixture.php>
// Rechaza cualquier host que no sea el socket local del contenedor. Jamás se usa fuera del ensayo.
if (PHP_SAPI !== 'cli') { exit(1); }
$fixture = $argv[1] ?? '';
if (!is_file($fixture)) { fwrite(STDERR, "Uso: php sembrar-portalbi.php <productive_fixture.php>\n"); exit(2); }
require $fixture;
$pdo = new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;charset=utf8mb4', 'root', '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
if ((int)$pdo->query("SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME = 'cervesur_portalbi'")->fetchColumn() !== 0) { fwrite(STDERR, "La base ya existe en el contenedor.\n"); exit(3); }
$pdo->exec('CREATE DATABASE cervesur_portalbi CHARACTER SET utf8mb4');
$pdo->exec('USE cervesur_portalbi');
productive_fixture_ddl($pdo);
productive_fixture_load($pdo, fn (int $id): string => password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT));
$bad = productive_fixture_deviations($pdo);
if ($bad) { foreach ($bad as $line) fwrite(STDERR, "DESVÍO $line\n"); exit(4); }
echo "Base sintética cervesur_portalbi creada en el contenedor de ensayo\n";
