<?php
// UX-08A.users: fixture SINTÉTICO `fixture_u` (tipos productivos + UNIQUE reales de user.username/email) para las pruebas de
// administración de usuarios. Base desechable del MySQL aislado; todos los valores son inventados. NO crea `audit_log`: la prueba
// aplica la migración real (database/migrations/UX-08B-audit-log.up.sql).
require __DIR__ . '/productive_fixture.php';
$root = new PDO('mysql:host=db;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$root->exec('DROP DATABASE IF EXISTS fixture_u');
$root->exec('CREATE DATABASE fixture_u CHARACTER SET utf8mb4');
$pdo = new PDO('mysql:host=db;dbname=fixture_u;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
productive_fixture_ddl($pdo);

foreach ([
    'ALTER TABLE user ADD UNIQUE uq_user_username (username), ADD UNIQUE uq_user_email (email)',
    'ALTER TABLE empresa_usuario ADD UNIQUE uq_eu (empresa_id, user_id)',
    'ALTER TABLE empresa_reporte ADD UNIQUE uq_er (empresa_id, reporte_id)',
    'ALTER TABLE user_empresa_reporte ADD UNIQUE uq_uer (user_id, empresa_reporte_id)',
] as $ddl) {
    $pdo->exec($ddl);
}

// Usuarios: 1 y 2 administradores; 3 usuario; 4 con el rol heredado `usuario`. Contraseña de todos: synthetic-password.
$hash = password_hash('synthetic-password', PASSWORD_DEFAULT);
$u = $pdo->prepare('INSERT INTO user (id, username, email, password, first_name, last_name, phone, role, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 1)');
foreach ([[1, 'admin', 'admin'], [2, 'admin2', 'admin'], [3, 'usera', 'user'], [4, 'userb', 'usuario']] as [$id, $name, $role]) {
    $u->execute([$id, $name, "$name@example.invalid", $hash, 'Demo', ucfirst($name), '555-0100', $role]);
}

// Accesos de `usera` (id 3): empresa 1 activa, empresa 2 INACTIVA; informes 1 (activo) y 2 (activo, pero de la empresa inactiva).
$pdo->exec("INSERT INTO empresa (id,nombre,razon_social,ruc,activo) VALUES (1,'Empresa U1','Empresa U1','30000000001',1),(2,'Empresa U2 inactiva','Empresa U2','30000000002',0)");
$link = fn ($n) => 'https://app.powerbi.com/reportEmbed?reportId=aaaaaaaa-0000-4000-8000-00000000000' . $n . '&groupId=bbbbbbbb-0000-4000-8000-000000000001';
$pdo->exec("INSERT INTO reporte (id,nombre,descripcion,enlace,grupo_id,reporte_id,created_by,activo) VALUES
  (1,'Informe U1','','" . $link(1) . "','bbbbbbbb-0000-4000-8000-000000000001','aaaaaaaa-0000-4000-8000-000000000001',1,1),
  (2,'Informe U2','','" . $link(2) . "','bbbbbbbb-0000-4000-8000-000000000001','aaaaaaaa-0000-4000-8000-000000000002',1,1)");
$pdo->exec('INSERT INTO empresa_usuario (id,empresa_id,user_id,activo) VALUES (1,1,3,1),(2,2,3,1)');
$pdo->exec('INSERT INTO empresa_reporte (id,empresa_id,reporte_id,activo) VALUES (1,1,1,1),(2,2,2,1)');
$pdo->exec('INSERT INTO user_empresa_reporte (id,user_id,empresa_reporte_id,activo) VALUES (1,3,1,1),(2,3,2,1)');
echo "Fixture de administracion de usuarios (fixture_u) cargado\n";
