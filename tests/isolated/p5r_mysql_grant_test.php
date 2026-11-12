<?php
/**
 * P5-R: MySQL 8 real en contenedor EFIMERO y datos sinteticos.
 * NUNCA ejecutar sobre MySQL productivo. Exige host y CI.
 */
declare(strict_types=1);
$host = getenv('P5R_QA_HOST');
$rootPass = getenv('TEST_DB_PASS');
if ($host !== 'p5rqa-db' || !$rootPass || getenv('CI') !== 'true') {
    fwrite(STDERR, "P5R_MYSQL=NO_GO_HOST_O_CI\n");
    exit(2);
}
$db = 'fixture_p5r_ci';
$account = 'cervesur_escritura_p5r_ci';
$quote = chr(96);
$table = $quote . $db . $quote . '.' . $quote . 'user' . $quote;
$accountPass = bin2hex(random_bytes(24));
$pdo = new PDO('mysql:host=p5rqa-db;charset=utf8mb4', 'root', $rootPass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false
]);
$checks = 0;
function ensureP5R(bool $ok, string $name): void {
    global $checks;
    if (!$ok) { throw new RuntimeException("P5R_MYSQL_FAIL: $name"); }
    echo "PASS $name\n";
    $checks++;
}
function deniedP5R(PDO $pdo, string $sql): bool {
    try { $pdo->exec($sql); return false; }
    catch (PDOException $exception) { return true; }
}
function fixtureTemplate(string $name, string $db, string $user): string {
    $path = __DIR__ . '/../../database/privileges/' . $name;
    $sql = file_get_contents($path);
    if ($sql === false) { throw new RuntimeException('Plantilla no disponible'); }
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    $sql = trim(strtr($sql, [
        '{DB}' => $db, '{USER}' => $user, '{HOST}' => '%'
    ]));
    if ($sql === '' || substr_count($sql, ';') !== 1 || !str_ends_with($sql, ';')) {
        throw new RuntimeException('Plantilla alterada: se esperaba una instruccion');
    }
    return $sql;
}
try {
    $pdo->exec("CREATE DATABASE " . $quote . $db . $quote . " CHARACTER SET utf8mb4");
    $pdo->exec("CREATE TABLE $table (
        id INT NOT NULL PRIMARY KEY,
        first_name VARCHAR(50) NOT NULL, last_name VARCHAR(50) NOT NULL,
        phone VARCHAR(20) NULL, email VARCHAR(100) NOT NULL,
        role VARCHAR(20) NOT NULL, password VARCHAR(255) NOT NULL,
        is_active TINYINT NOT NULL DEFAULT 1,
        session_version INT NOT NULL DEFAULT 1,
        updated_at DATETIME NULL, last_login DATETIME NULL
    ) ENGINE=InnoDB");
    $pdo->exec("INSERT INTO $table (id,first_name,last_name,email,role,password)
      VALUES (42,'QA','User','p5r@example.invalid','user','FAKE_OLD')");
    $pdo->exec("CREATE USER '$account'@'%' IDENTIFIED BY " . $pdo->quote($accountPass));
    $pdo->exec("GRANT SELECT (id, role, is_active, session_version) ON $table TO '$account'@'%'");
    $pdo->exec("GRANT UPDATE (first_name, last_name, phone, is_active, session_version, updated_at, last_login) ON $table TO '$account'@'%'");
    $restricted = new PDO("mysql:host=p5rqa-db;dbname=$db;charset=utf8mb4", $account, $accountPass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false
    ]);
    ensureP5R(deniedP5R($restricted, "UPDATE user SET password='FAKE_1' WHERE id=42"), 'sin GRANT: UPDATE(password) denegado');
    ensureP5R(deniedP5R($restricted, "UPDATE user SET role='admin' WHERE id=42"), 'sin GRANT: UPDATE(role) denegado');
    ensureP5R(deniedP5R($restricted, "SELECT password FROM user WHERE id=42"), 'sin GRANT: SELECT(password) denegado');

    $pdo->exec(fixtureTemplate('UX-09C-p5r-password-reset.up.sql.template', $db, $account));
    $grants = $pdo->query("SHOW GRANTS FOR '$account'@'%'")->fetchAll(PDO::FETCH_COLUMN);
    require_once __DIR__ . '/../../backend/lib/admin_escritura.php';
    ensureP5R(admin_write_grants_violations($grants, $db, [
        'usuarios'=>true, 'sensible'=>false, 'password_reset'=>true
    ]) === [], 'SHOW GRANTS P5-R sin violaciones de columnas');
    ensureP5R($restricted->exec("UPDATE user SET password='FAKE_NEW', session_version=session_version+1 WHERE id=42") === 1,
        'con GRANT: UPDATE(password) y version funcionan');
    ensureP5R(deniedP5R($restricted, "UPDATE user SET role='admin' WHERE id=42"), 'con GRANT: UPDATE(role) sigue denegado');
    ensureP5R(deniedP5R($restricted, "UPDATE user SET email='bad@example.invalid' WHERE id=42"), 'con GRANT: UPDATE(email) denegado');
    ensureP5R(deniedP5R($restricted, "SELECT password FROM user WHERE id=42"), 'con GRANT: SELECT(password) denegado');
    ensureP5R(deniedP5R($restricted, "DELETE FROM user WHERE id=42"), 'con GRANT: DELETE(user) denegado');
    ensureP5R($pdo->query("SELECT role FROM $table WHERE id=42")->fetchColumn() === 'user',
        'rol permanece estandar');
    ensureP5R((int)$pdo->query("SELECT session_version FROM $table WHERE id=42")->fetchColumn() === 2,
        'session_version incremento una vez');

    $pdo->exec("GRANT UPDATE (role) ON $table TO '$account'@'%'");
    $wrong = $pdo->query("SHOW GRANTS FOR '$account'@'%'")->fetchAll(PDO::FETCH_COLUMN);
    ensureP5R(admin_write_grants_violations($wrong, $db, [
        'usuarios'=>true,'sensible'=>false,'password_reset'=>true
    ]) !== [], 'detector rechaza privilegio extrano UPDATE(role)');
    $pdo->exec("REVOKE UPDATE (role) ON $table FROM '$account'@'%'");

    $pdo->exec(fixtureTemplate('UX-09C-p5r-password-reset.down.sql.template', $db, $account));
    ensureP5R(deniedP5R($restricted, "UPDATE user SET password='FAKE_BACK' WHERE id=42"), 'rollback: UPDATE(password) revocado');
    ensureP5R(!deniedP5R($restricted, "UPDATE user SET first_name='QA2' WHERE id=42"),
        'rollback: U-BASIC UPDATE(first_name) permanece operativo');
    ensureP5R(deniedP5R($restricted, "UPDATE user SET role='admin' WHERE id=42"),
        'rollback: UPDATE(role) sigue denegado');

    echo "P5R_MYSQL=PASS_$checks\nMYSQL_REAL_DESECHABLE=SI\nPRODUCCION_CONTACTADA=NO\n";
} catch (Throwable $e) {
    fwrite(STDERR, "P5R_MYSQL=FAIL: " . $e->getMessage() . "\n");
    exit(1);
}
