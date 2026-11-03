<?php
// UX-08B: fixture SINTÉTICO `fixture_w` con los tipos productivos Y sus restricciones UNIQUE reales (user.username/email, empresa.ruc,
// reporte.enlace/reporte_id y las tres tablas de relación). Base desechable del MySQL aislado. Todos los valores son inventados.
// NO crea `audit_log`: la prueba aplica la migración real (database/migrations/UX-08B-audit-log.up.sql) para verificarla.
require __DIR__ . '/productive_fixture.php';
$fixtureDb = getenv('FIXTURE_DB') ?: 'fixture_w';
if (!preg_match('/^fixture_[a-z0-9_]{1,20}$/', $fixtureDb)) { fwrite(STDERR, "FIXTURE_DB inválido" . PHP_EOL); exit(2); }
$root = new PDO('mysql:host=db;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$root->exec('DROP DATABASE IF EXISTS ' . $fixtureDb);
$root->exec('CREATE DATABASE ' . $fixtureDb . ' CHARACTER SET utf8mb4');
$pdo = new PDO('mysql:host=db;dbname=' . $fixtureDb . ';charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
productive_fixture_ddl($pdo);

foreach ([
    'ALTER TABLE user ADD UNIQUE uq_user_username (username), ADD UNIQUE uq_user_email (email)',
    'ALTER TABLE empresa ADD UNIQUE uq_empresa_ruc (ruc)',
    'ALTER TABLE reporte ADD UNIQUE uq_reporte_enlace (enlace), ADD UNIQUE uq_reporte_reporte_id (reporte_id)',
    'ALTER TABLE empresa_usuario ADD UNIQUE uq_eu (empresa_id, user_id)',
    'ALTER TABLE empresa_reporte ADD UNIQUE uq_er (empresa_id, reporte_id)',
    'ALTER TABLE user_empresa_reporte ADD UNIQUE uq_uer (user_id, empresa_reporte_id)',
] as $ddl) {
    $pdo->exec($ddl);
}

$hash = password_hash('synthetic-password', PASSWORD_DEFAULT);
$u = $pdo->prepare('INSERT INTO user (id, username, email, password, first_name, last_name, role, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
foreach ([[1, 'admin', 'admin', 1], [2, 'usera', 'user', 1], [3, 'userb', 'usuario', 1]] as [$id, $name, $role, $active]) {
    $u->execute([$id, $name, "$name@example.invalid", $hash, 'Demo', ucfirst($name), $role, $active]);
}

// Empresas: 1 y 2 activas, 3 inactiva. Informes: 1 activo (visible para usera), 2 activo (sin acceso), 3 inactivo.
$pdo->exec("INSERT INTO empresa (id,nombre,razon_social,ruc,ciudad,pais,powerbi_tenant,powerbi_client,powerbi_clientsecret,activo) VALUES
  (1,'Empresa W1','Empresa W1','10000000001','Ciudad A','Demo','tenant-w1','client-w1','fixture-private-w1',1),
  (2,'Empresa W2','Empresa W2','10000000002','Ciudad B','Demo','tenant-w2','client-w2','fixture-private-w2',1),
  (3,'Empresa W3 inactiva','Empresa W3','10000000003','Ciudad C','Demo',NULL,NULL,NULL,0)");
$link = fn ($n) => 'https://app.powerbi.com/reportEmbed?reportId=aaaaaaaa-0000-4000-8000-00000000000' . $n . '&groupId=bbbbbbbb-0000-4000-8000-000000000001';
$pdo->exec("INSERT INTO reporte (id,nombre,descripcion,enlace,grupo_id,reporte_id,created_by,activo) VALUES
  (1,'Informe W1','Visible para usera','" . $link(1) . "','bbbbbbbb-0000-4000-8000-000000000001','aaaaaaaa-0000-4000-8000-000000000001',1,1),
  (2,'Informe W2','Sin acceso para usera','" . $link(2) . "','bbbbbbbb-0000-4000-8000-000000000001','aaaaaaaa-0000-4000-8000-000000000002',1,1),
  (3,'Informe W3 inactivo','Dado de baja','" . $link(3) . "','bbbbbbbb-0000-4000-8000-000000000001','aaaaaaaa-0000-4000-8000-000000000003',1,0)");
$pdo->exec('INSERT INTO empresa_usuario (id,empresa_id,user_id,activo) VALUES (1,1,2,1)');
$pdo->exec('INSERT INTO empresa_reporte (id,empresa_id,reporte_id,activo) VALUES (1,1,1,1),(2,2,2,1)');
$pdo->exec('INSERT INTO user_empresa_reporte (id,user_id,empresa_reporte_id,activo) VALUES (1,2,1,1)');
echo "Fixture de escrituras (con UNIQUE productivos) cargado\n";
