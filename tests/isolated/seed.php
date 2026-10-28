<?php
// Synthetic fixture exclusively for an isolated, disposable MySQL database.
$pdo = new PDO('mysql:host=db;dbname=fixture;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$ddl = [
"CREATE TABLE user (id INT PRIMARY KEY, username VARCHAR(64), email VARCHAR(100), password VARCHAR(255), first_name VARCHAR(50), last_name VARCHAR(50), phone VARCHAR(30), avatar VARCHAR(255), role VARCHAR(20), is_active TINYINT DEFAULT 1, session_version INT DEFAULT 1, last_login DATETIME NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP)",
"CREATE TABLE empresa (id INT PRIMARY KEY, nombre VARCHAR(100), razon_social VARCHAR(100), ruc VARCHAR(20), email VARCHAR(100), telefono VARCHAR(30), website VARCHAR(100), direccion VARCHAR(100), ciudad VARCHAR(100), pais VARCHAR(50), powerbi_tenant VARCHAR(100), powerbi_client VARCHAR(100), powerbi_clientsecret VARCHAR(100), logo VARCHAR(100), contacto_principal VARCHAR(100), created_by INT, updated_by INT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, activo TINYINT DEFAULT 1)",
"CREATE TABLE reporte (id INT PRIMARY KEY, nombre VARCHAR(100), descripcion TEXT, enlace VARCHAR(255), grupo_id VARCHAR(36), reporte_id VARCHAR(36), created_by INT, updated_by INT, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, activo TINYINT DEFAULT 1)",
"CREATE TABLE empresa_usuario (id INT PRIMARY KEY, empresa_id INT, user_id INT, notas TEXT, created_by INT NULL, updated_by INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, activo TINYINT DEFAULT 1)",
"CREATE TABLE empresa_reporte (id INT PRIMARY KEY, empresa_id INT, reporte_id INT, notas TEXT, created_by INT NULL, updated_by INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, activo TINYINT DEFAULT 1)",
"CREATE TABLE user_empresa_reporte (id INT PRIMARY KEY, user_id INT, empresa_reporte_id INT, notas TEXT, created_by INT NULL, updated_by INT NULL, created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP, activo TINYINT DEFAULT 1)"
];
foreach ($ddl as $sql) $pdo->exec($sql);
$stmt = $pdo->prepare('INSERT INTO user (id, username, email, password, first_name, last_name, role) VALUES (?, ?, ?, ?, ?, ?, ?)');
foreach ([[1,'usera','usera@example.invalid','user'],[2,'userb','userb@example.invalid','user'],[3,'admin','admin@example.invalid','admin'],[4,'inactive','inactive@example.invalid','user']] as $u) {
    $stmt->execute([$u[0],$u[1],$u[2],password_hash('synthetic-password', PASSWORD_DEFAULT),'Demo','User',$u[3]]);
}
$pdo->exec('UPDATE user SET is_active = 0 WHERE id = 4');
$pdo->exec("INSERT INTO empresa (id,nombre,razon_social,ruc,ciudad,pais,powerbi_tenant,powerbi_client,powerbi_clientsecret,activo) VALUES (1,'Empresa A','Empresa A','00000000001','Ciudad A','Demo','tenant-a','client-a','fixture-private-value-a',1),(2,'Empresa B','Empresa B','00000000002','Ciudad B','Demo','tenant-b','client-b','fixture-private-value-b',1)");
$pdo->exec("INSERT INTO reporte (id,nombre,enlace,grupo_id,reporte_id,created_by,activo) VALUES (1,'Reporte A','https://example.invalid/a','00000000-0000-0000-0000-000000000001','00000000-0000-0000-0000-000000000011',1,1),(2,'Reporte B','https://example.invalid/b','00000000-0000-0000-0000-000000000002','00000000-0000-0000-0000-000000000022',2,1),(3,'Reporte C','https://example.invalid/c','00000000-0000-0000-0000-000000000003','00000000-0000-0000-0000-000000000033',1,1)");
$pdo->exec('INSERT INTO empresa_usuario (id,empresa_id,user_id,activo) VALUES (1,1,1,1),(2,2,2,1),(3,2,1,0)');
$pdo->exec('INSERT INTO empresa_reporte (id,empresa_id,reporte_id,notas,activo) VALUES (1,1,1,NULL,1),(2,2,2,NULL,1),(3,1,2,NULL,0),(4,1,3,NULL,1)');
$pdo->exec('INSERT INTO user_empresa_reporte (id,user_id,empresa_reporte_id,activo) VALUES (1,1,1,1),(2,2,2,1),(3,1,2,0),(4,1,4,0)');
echo "Synthetic schema and fixtures loaded\n";
