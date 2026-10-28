<?php
// Siembra el entorno de COMPATIBILIDAD PRODUCTIVA (UX-07D.2C). Uso (lo invoca scripts/pasos-instalacion.sh):
//   php sembrar-compat.php <directorio-del-backend> <productive_fixture.php>
// Lee las credenciales del backend/.env de ESTE entorno (nunca las muestra). Crea el esquema con los TIPOS productivos, carga datos 100 % ficticios con los
// agregados observados en producción y aplica una migración MÍNIMA y EXCLUSIVA de este esquema sintético: `reporte.grupo_id` pasa a admitir NULL conservando
// VARCHAR(50). Esa migración NO existe como archivo de producción y NO debe aplicarse en producción. Imprime UNA vez las contraseñas generadas de las cuentas
// de prueba (en la base solo queda su hash); las demás cuentas sintéticas reciben un hash inutilizable.
// Guardas: solo contra 127.0.0.1/localhost, solo la base «cervesur_react_compat_qa» y solo si está VACÍA.
if (PHP_SAPI !== 'cli') { exit(1); }
[$script, $backend, $fixture] = $argv + [null, null, null];
if (!$backend || !$fixture || !is_file("$backend/lib/env.php") || !is_file($fixture)) { fwrite(STDERR, "Uso: php sembrar-compat.php <backend> <productive_fixture.php>\n"); exit(2); }

require "$backend/lib/env.php"; // carga $backend/.env con putenv()
require $fixture;
$host = getenv('DB_HOST'); $db = getenv('DB_NAME'); $user = getenv('DB_USER'); $pass = getenv('DB_PASS');
if (!in_array($host, ['localhost', '127.0.0.1'], true)) { fwrite(STDERR, "Rechazado: DB_HOST debe ser localhost o 127.0.0.1.\n"); exit(3); }
if ($db !== 'cervesur_react_compat_qa') { fwrite(STDERR, "Rechazado: solo se siembra la base cervesur_react_compat_qa.\n"); exit(3); }
if (!$user || !$pass) { fwrite(STDERR, "Rechazado: credenciales incompletas en el .env.\n"); exit(3); }

$pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
if ((int) $pdo->query('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')->fetchColumn() !== 0) {
    fwrite(STDERR, "Rechazado: la base de compatibilidad no está vacía.\n"); exit(3);
}

// Cuentas con contraseña aleatoria: administrador (syn001) y tres usuarios con accesos (syn005-syn007). Las demás: hash de un valor aleatorio descartado.
$cuentas = [1 => 'syn001', 5 => 'syn005', 6 => 'syn006', 7 => 'syn007'];
$claves = []; $hashes = [];
foreach ($cuentas as $id => $username) { $claves[$username] = bin2hex(random_bytes(10)); $hashes[$id] = password_hash($claves[$username], PASSWORD_DEFAULT); }
$inutilizable = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);

productive_fixture_ddl($pdo);
productive_fixture_load($pdo, fn (int $id): string => $hashes[$id] ?? $inutilizable);
$bad = productive_fixture_deviations($pdo);
if ($bad) { foreach ($bad as $line) fwrite(STDERR, "DESVÍO $line\n"); exit(4); }

// --- Estado 1: registros históricos (grupo_id NOT NULL, como en producción). Se guarda una huella para comprobar que la migración no los altera.
$col = fn (string $c) => $pdo->query("SELECT CONCAT(COLUMN_TYPE, '|', IS_NULLABLE) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'reporte' AND COLUMN_NAME = '$c'")->fetchColumn();
$huella = fn () => $pdo->query('SELECT COUNT(*), SUM(CRC32(grupo_id)), SUM(CRC32(reporte_id)), SUM(CRC32(enlace)), SUM(grupo_id IS NULL) FROM reporte')->fetch(PDO::FETCH_NUM);
if ($col('grupo_id') !== 'varchar(50)|NO') { fwrite(STDERR, "El esquema inicial no es el productivo (grupo_id).\n"); exit(4); }
$antes = $huella();

// --- Migración MÍNIMA y exclusiva del esquema sintético: permitir NULL en grupo_id conservando VARCHAR(50). NO se aplica en producción.
$pdo->exec('ALTER TABLE reporte MODIFY grupo_id VARCHAR(50) NULL');

// --- Estado 2: grupo_id admite NULL, longitud 50, el resto del esquema intacto y los históricos idénticos.
if ($col('grupo_id') !== 'varchar(50)|YES' || $col('reporte_id') !== 'varchar(50)|NO' || $col('enlace') !== 'varchar(500)|NO') { fwrite(STDERR, "La migración mínima no dejó el esquema esperado.\n"); exit(4); }
if ($huella() !== $antes) { fwrite(STDERR, "La migración alteró datos históricos.\n"); exit(4); }
if (productive_fixture_deviations($pdo)) { fwrite(STDERR, "Los agregados cambiaron tras la migración.\n"); exit(4); }

fwrite(STDERR, "Entorno de compatibilidad sembrado: esquema productivo sintético + migración mínima de grupo_id (VARCHAR(50) NULL), históricos intactos.\n");
foreach ($claves as $usuario => $clave) { echo "CUENTA $usuario $clave\n"; }
