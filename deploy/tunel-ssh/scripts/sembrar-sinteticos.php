<?php
// Datos SINTÉTICOS para la base de PRUEBA del portal React (UX-07D.1B). Uso (lo invoca scripts/instalar.sh):
//   php sembrar-sinteticos.php <directorio-del-backend> <migracion-UX-07C-up.sql>
// Lee las credenciales del backend/.env de PRUEBA (nunca las muestra). Imprime UNA vez las contraseñas generadas de las cuentas
// sintéticas (solo se guardan como hash en la base). Reglas de seguridad: solo corre contra localhost, solo contra la base
// «cervesur_react_prueba» y solo si está VACÍA; en cualquier otro caso se niega sin tocar nada.
if (PHP_SAPI !== 'cli') { exit(1); }
[$script, $backend, $migracion] = $argv + [null, null, null];
if (!$backend || !$migracion || !is_file("$backend/lib/env.php") || !is_file($migracion)) { fwrite(STDERR, "Uso: php sembrar-sinteticos.php <backend> <migracion.up.sql>\n"); exit(2); }

require "$backend/lib/env.php"; // carga $backend/.env con putenv()
$host = getenv('DB_HOST'); $db = getenv('DB_NAME'); $user = getenv('DB_USER'); $pass = getenv('DB_PASS');
if (!in_array($host, ['localhost', '127.0.0.1'], true)) { fwrite(STDERR, "Rechazado: DB_HOST debe ser localhost o 127.0.0.1.\n"); exit(3); }
if ($db !== 'cervesur_react_prueba') { fwrite(STDERR, "Rechazado: solo se siembra la base cervesur_react_prueba.\n"); exit(3); }
if (!$user || !$pass) { fwrite(STDERR, "Rechazado: credenciales incompletas en el .env de prueba.\n"); exit(3); }

$pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
if ((int) $pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()")->fetchColumn() !== 0) {
    fwrite(STDERR, "Rechazado: la base de prueba no está vacía.\n"); exit(3);
}

// Esquema: el mismo del fixture sintético del repositorio (tests/isolated/seed.php), único esquema respaldado por evidencia.
$ddl = [
"CREATE TABLE user (id INT AUTO_INCREMENT PRIMARY KEY, username VARCHAR(64), email VARCHAR(100), password VARCHAR(255), first_name VARCHAR(50), last_name VARCHAR(50), phone VARCHAR(30), avatar VARCHAR(255), role VARCHAR(20), is_active TINYINT DEFAULT 1, session_version INT DEFAULT 1, last_login DATETIME NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
"CREATE TABLE empresa (id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(100), razon_social VARCHAR(100), ruc VARCHAR(20), email VARCHAR(100), telefono VARCHAR(30), website VARCHAR(100), direccion VARCHAR(100), ciudad VARCHAR(100), pais VARCHAR(50), powerbi_tenant VARCHAR(100), powerbi_client VARCHAR(100), powerbi_clientsecret VARCHAR(100), logo VARCHAR(100), contacto_principal VARCHAR(100), created_by INT, updated_by INT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, activo TINYINT DEFAULT 1)",
"CREATE TABLE reporte (id INT AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(100), descripcion TEXT, enlace VARCHAR(255), grupo_id VARCHAR(36), reporte_id VARCHAR(36), created_by INT, updated_by INT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, activo TINYINT DEFAULT 1)",
"CREATE TABLE empresa_usuario (id INT AUTO_INCREMENT PRIMARY KEY, empresa_id INT, user_id INT, notas TEXT, created_by INT NULL, updated_by INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, activo TINYINT DEFAULT 1)",
"CREATE TABLE empresa_reporte (id INT AUTO_INCREMENT PRIMARY KEY, empresa_id INT, reporte_id INT, notas TEXT, created_by INT NULL, updated_by INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, activo TINYINT DEFAULT 1)",
"CREATE TABLE user_empresa_reporte (id INT AUTO_INCREMENT PRIMARY KEY, user_id INT, empresa_reporte_id INT, notas TEXT, created_by INT NULL, updated_by INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, activo TINYINT DEFAULT 1)",
];
foreach ($ddl as $sql) { $pdo->exec($sql); }

// Migración UX-07C (subida): informes registrados solo por su enlace público. Es la base de PRUEBA, no la productiva.
$sql = trim(implode("\n", array_filter(file($migracion, FILE_IGNORE_NEW_LINES), fn ($l) => !str_starts_with(ltrim($l), '--'))));
if (substr_count($sql, ';') !== 1) { fwrite(STDERR, "La migración debe ser una sola sentencia.\n"); exit(4); }
$pdo->exec($sql);

$cuentas = [[1, 'admin', 'admin'], [2, 'usera', 'user'], [3, 'userb', 'user']];
$insert = $pdo->prepare('INSERT INTO user (id, username, email, password, first_name, last_name, role) VALUES (?, ?, ?, ?, ?, ?, ?)');
$claves = [];
foreach ($cuentas as [$id, $username, $role]) {
    $claves[$username] = bin2hex(random_bytes(10));
    $insert->execute([$id, $username, "$username@example.invalid", password_hash($claves[$username], PASSWORD_DEFAULT), 'Prueba', ucfirst($username), $role]);
}
$pdo->exec("INSERT INTO empresa (id, nombre, razon_social, ruc, ciudad, pais, activo) VALUES (1, 'Empresa de prueba A', 'Empresa de prueba A', '00000000001', 'Ciudad A', 'Demo', 1), (2, 'Empresa de prueba B', 'Empresa de prueba B', '00000000002', 'Ciudad B', 'Demo', 1)");
$pdo->exec("INSERT INTO reporte (id, nombre, descripcion, enlace, grupo_id, reporte_id, created_by, activo) VALUES
 (1, 'Informe sintético A', 'Sin enlace público real', 'https://example.invalid/a', NULL, '00000000-0000-4000-8000-000000000011', 1, 1),
 (2, 'Informe sintético B', 'Sin enlace público real', 'https://example.invalid/b', NULL, '00000000-0000-4000-8000-000000000022', 1, 1)");
$pdo->exec('INSERT INTO empresa_usuario (id, empresa_id, user_id, activo) VALUES (1, 1, 2, 1), (2, 2, 3, 1)');
$pdo->exec('INSERT INTO empresa_reporte (id, empresa_id, reporte_id, activo) VALUES (1, 1, 1, 1), (2, 2, 2, 1)');
$pdo->exec('INSERT INTO user_empresa_reporte (id, user_id, empresa_reporte_id, activo) VALUES (1, 2, 1, 1), (2, 3, 2, 1)');

fwrite(STDERR, "Base de prueba sembrada (esquema + migración UX-07C + datos sintéticos).\n");
foreach ($claves as $usuario => $clave) { echo "CUENTA $usuario $clave\n"; }
