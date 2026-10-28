<?php
// Datos sintéticos ADICIONALES para QA de integración React + PHP.
// Se ejecuta tras tests/isolated/seed.php, solo contra el MySQL desechable del contenedor aislado.
// Contraseña de todas las cuentas: la misma contraseña sintética pública del fixture del repositorio.
$pdo = new PDO('mysql:host=db;dbname=fixture;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$hash = password_hash('synthetic-password', PASSWORD_DEFAULT);

// (UX-06B.0) El esquema del fixture del repositorio ya incluye user.created_at/updated_at: no hace falta ALTER.

// Usuarios: 8 = usuario con un informe vinculado a DOS empresas (catálogo UX-06B.0).
// Usuarios: 5 = rol heredado "usuario"; 6 = rol inesperado; 7 = usuario sin asignaciones.
$stmt = $pdo->prepare('INSERT INTO user (id, username, email, password, first_name, last_name, role, is_active, session_version) VALUES (?, ?, ?, ?, ?, ?, ?, 1, 1)');
foreach ([[5, 'legacy', 'legacy@example.invalid', 'usuario'], [6, 'weird', 'weird@example.invalid', 'superadmin'], [7, 'nobody', 'nobody@example.invalid', 'user'], [8, 'multi', 'multi@example.invalid', 'user']] as $u) {
    $stmt->execute([$u[0], $u[1], $u[2], $hash, 'Demo', ucfirst($u[1]), $u[3]]);
}

// Empresa inactiva a la que usera sigue "vinculada": no debe aparecer.
$pdo->exec("INSERT INTO empresa (id,nombre,razon_social,ruc,ciudad,pais,powerbi_tenant,powerbi_client,powerbi_clientsecret,activo) VALUES (3,'Empresa C (inactiva)','Empresa C','00000000003','Ciudad C','Demo','tenant-c','client-c','fixture-private-value-c',0)");
$pdo->exec('INSERT INTO empresa_usuario (id,empresa_id,user_id,activo) VALUES (4,3,1,1),(5,1,5,1)');

// Reportes adicionales de la Empresa A para probar búsqueda y filtros sobre datos de PHP.
$pdo->exec("INSERT INTO reporte (id,nombre,descripcion,enlace,grupo_id,reporte_id,created_by,activo) VALUES
 (4,'Ventas regionales','Seguimiento comercial por región','https://example.invalid/d','00000000-0000-0000-0000-000000000001','00000000-0000-0000-0000-000000000044',1,1),
 (5,'Inventario norte','Cobertura y rotación de inventario','https://example.invalid/e','00000000-0000-0000-0000-000000000002','00000000-0000-0000-0000-000000000055',1,1),
 (6,'Flujo semanal','Proyección financiera semanal','https://example.invalid/f','00000000-0000-0000-0000-000000000003','00000000-0000-0000-0000-000000000066',1,1),
 (7,'Reporte retirado','Informe inactivo: no debe listarse','https://example.invalid/g','00000000-0000-0000-0000-000000000001','00000000-0000-0000-0000-000000000077',1,0)");
$pdo->exec('INSERT INTO empresa_reporte (id,empresa_id,reporte_id,activo) VALUES (5,1,4,1),(6,1,5,1),(7,1,6,1),(8,1,7,1)');
// usera (1): 4,5,6 vigentes y 7 sobre un reporte inactivo. legacy (5): solo Reporte A.
$pdo->exec('INSERT INTO user_empresa_reporte (id,user_id,empresa_reporte_id,activo) VALUES (5,1,5,1),(6,1,6,1),(7,1,7,1),(8,1,8,1),(9,5,1,1)');
// multi (8): Reporte A en Empresa A y «Ventas regionales» (informe 4) en Empresa A y en Empresa B (UX-06B.0).
$pdo->exec('INSERT INTO empresa_usuario (id,empresa_id,user_id,activo) VALUES (10,1,8,1),(11,2,8,1)');
$pdo->exec('INSERT INTO empresa_reporte (id,empresa_id,reporte_id,activo) VALUES (9,2,4,1)');
$pdo->exec('INSERT INTO user_empresa_reporte (id,user_id,empresa_reporte_id,activo) VALUES (10,8,1,1),(11,8,5,1),(12,8,9,1)');
// UX-07C: enlaces SINTÉTICOS con la forma de «Publicar en web» (no son informes reales; el visor QA los abre en una página local).
// «Reporte C» (3) y «Ventas regionales» (4) conservan su enlace antiguo NO compatible y «Inventario norte» (5) es una inserción autenticada: así se ven los tres modos.
$publicLinks = [1 => 'U0lOVEVUSUNPLVJFUE9SVEUtMDAwMQ', 2 => 'U0lOVEVUSUNPLVJFUE9SVEUtMDAwMg', 6 => 'U0lOVEVUSUNPLVJFUE9SVEUtMDAwNg'];
$setLink = $pdo->prepare('UPDATE reporte SET enlace = ? WHERE id = ?');
foreach ($publicLinks as $id => $token) {
    $setLink->execute(['https://app.powerbi.com/view?r=' . $token, $id]);
}
$setLink->execute(['https://app.powerbi.com/reportEmbed?reportId=00000000-0000-0000-0000-000000000055&groupId=00000000-0000-0000-0000-000000000002', 5]);
echo "Synthetic QA fixtures added\n";
