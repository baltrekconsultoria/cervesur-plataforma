<?php
// Biblioteca del fixture SINTÉTICO con la FORMA del esquema productivo y los AGREGADOS observados en producción (UX-07D.2B/2C).
// La usan tests/isolated/seed_productive.php (pruebas HTTP) y el sembrador del entorno de compatibilidad (deploy/tunel-ssh-compat).
// Todos los valores son inventados: no hay usuarios, correos, RUC, enlaces, hashes ni identificadores reales. Tipos (inventario de solo lectura):
//   reporte.id INT UNSIGNED · enlace VARCHAR(500) NOT NULL · grupo_id VARCHAR(50) NOT NULL · reporte_id VARCHAR(50) NOT NULL ·
//   activo TINYINT(1) NOT NULL · user.username VARCHAR(50) · user.role VARCHAR(30) · user.session_version INT UNSIGNED.

/** Crea las seis tablas con los tipos productivos (la base debe estar vacía). */
function productive_fixture_ddl(PDO $pdo): void {
    $ts = 'created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP';
    $pdo->exec("CREATE TABLE user (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50) NOT NULL, email VARCHAR(100), password VARCHAR(255), first_name VARCHAR(50), last_name VARCHAR(50), phone VARCHAR(30), avatar VARCHAR(255), role VARCHAR(30) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, session_version INT UNSIGNED NOT NULL DEFAULT 1, last_login DATETIME NULL, $ts)");
    $pdo->exec("CREATE TABLE empresa (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(100), razon_social VARCHAR(100), ruc VARCHAR(20), email VARCHAR(100), telefono VARCHAR(30), website VARCHAR(100), direccion VARCHAR(100), ciudad VARCHAR(100), pais VARCHAR(50), powerbi_tenant VARCHAR(100), powerbi_client VARCHAR(100), powerbi_clientsecret VARCHAR(100), logo VARCHAR(100), contacto_principal VARCHAR(100), created_by INT UNSIGNED NULL, updated_by INT UNSIGNED NULL, $ts, activo TINYINT(1) NOT NULL DEFAULT 1)");
    $pdo->exec("CREATE TABLE reporte (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(100), descripcion TEXT, enlace VARCHAR(500) NOT NULL, grupo_id VARCHAR(50) NOT NULL, reporte_id VARCHAR(50) NOT NULL, created_by INT UNSIGNED NULL, updated_by INT UNSIGNED NULL, $ts, activo TINYINT(1) NOT NULL DEFAULT 1)");
    $pdo->exec("CREATE TABLE empresa_usuario (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, empresa_id INT UNSIGNED, user_id INT UNSIGNED, notas TEXT, created_by INT UNSIGNED NULL, updated_by INT UNSIGNED NULL, $ts, activo TINYINT(1) NOT NULL DEFAULT 1)");
    $pdo->exec("CREATE TABLE empresa_reporte (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, empresa_id INT UNSIGNED, reporte_id INT UNSIGNED, notas TEXT, created_by INT UNSIGNED NULL, updated_by INT UNSIGNED NULL, $ts, activo TINYINT(1) NOT NULL DEFAULT 1)");
    $pdo->exec("CREATE TABLE user_empresa_reporte (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED, empresa_reporte_id INT UNSIGNED, notas TEXT, created_by INT UNSIGNED NULL, updated_by INT UNSIGNED NULL, $ts, activo TINYINT(1) NOT NULL DEFAULT 1)");
}

/**
 * Inserta los datos sintéticos. `$hashFor(int $userId): string` decide el hash de contraseña de cada usuario (el fixture de pruebas usa la contraseña
 * pública del repositorio; el instalador usa contraseñas aleatorias o hashes inutilizables).
 */
function productive_fixture_load(PDO $pdo, callable $hashFor): void {
    $pdo->beginTransaction();

    // ------------------------------------------------------------------ usuarios: 76 (62 activos)
    //  ids 1-4 admin (solo el 1 activo) · 5-65 usuario activos (61) · 66 usuario inactivo · 67-76 'user' inactivos (10)
    $insUser = $pdo->prepare('INSERT INTO user (id, username, email, password, first_name, last_name, role, is_active) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    for ($id = 1; $id <= 76; $id++) {
        [$role, $active] = $id <= 4 ? ['admin', $id === 1 ? 1 : 0] : ($id <= 65 ? ['usuario', 1] : ($id === 66 ? ['usuario', 0] : ['user', 0]));
        $insUser->execute([$id, sprintf('syn%03d', $id), sprintf('syn%03d@example.invalid', $id), $hashFor($id), 'Sintético', sprintf('Usuario%03d', $id), $role, $active]);
    }

    // ------------------------------------------------------------------ empresas: 26 (6 activas: 1-5 con informes, 6 sin informes)
    $insCo = $pdo->prepare('INSERT INTO empresa (id, nombre, razon_social, ruc, ciudad, pais, activo) VALUES (?, ?, ?, ?, ?, ?, ?)');
    for ($id = 1; $id <= 26; $id++) {
        $insCo->execute([$id, sprintf('Empresa sintética %02d', $id), sprintf('Empresa sintética %02d', $id), sprintf('9000000%04d', $id), 'Ciudad ficticia', 'Demo', $id <= 6 ? 1 : 0]);
    }

    // ------------------------------------------------------------------ informes: 95 (82 activos), referencias históricas NO UUID
    //  1-56 referencia larga (37-50 car.) activos · 57-82 otro formato (<=36) activos · 83 larga inactivo · 84-92 otro formato inactivos · 93-95 UUID inactivos
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-';
    $longRef = function (int $id) use ($alphabet): string { $len = 37 + ($id % 14); $s = sprintf('SYN-LARGA-%04d-', $id); while (strlen($s) < $len) $s .= $alphabet[(strlen($s) * 7 + $id) % strlen($alphabet)]; return substr($s, 0, $len); };
    $otherRef = function (int $id): string {
        switch ($id % 4) {
            case 0: return sprintf('SYN-REF-%05d', $id);
            case 1: return (string)(10000000 + $id);
            case 2: return sprintf('syn_grupo_%03d_informe', $id);
            default: return substr(sprintf('SYN-OTRO-FORMATO-%03d-', $id) . str_repeat('x', 30), 0, 24 + ($id % 13));
        }
    };
    $uuidRef = fn (int $id): string => sprintf('aaaaaaaa-bbbb-4ccc-8ddd-%012d', $id);
    $token = function (int $id, int $len): string { $s = ''; $seed = base64_encode("SINTETICO-CERVESUR-$id-"); $seed = strtr(rtrim($seed, '='), '+/', '-_'); while (strlen($s) < $len) $s .= $seed; return substr($s, 0, $len); };
    $insRep = $pdo->prepare('INSERT INTO reporte (id, nombre, descripcion, enlace, grupo_id, reporte_id, created_by, activo) VALUES (?, ?, ?, ?, ?, ?, 1, ?)');
    for ($id = 1; $id <= 95; $id++) {
        if ($id <= 56 || $id === 83) $ref = $longRef($id);
        elseif ($id >= 93) $ref = $uuidRef($id);
        else $ref = $otherRef($id);
        $grupo = $id === 1 ? 'syn-grupo-0123456789-0123456789-0123' : ($id % 2 ? sprintf('bbbbbbbb-cccc-4ddd-8eee-%012d', $id) : sprintf('SYN-GRP-%03d', $id));
        $link = 'https://app.powerbi.com/view?r=' . $token($id, $id === 2 ? 120 : 40);   // el informe 2 alcanza 151 caracteres
        $insRep->execute([$id, sprintf('Informe sintético %03d', $id), "Descripción ficticia $id", $link, $grupo, $ref, $id <= 82 ? 1 : 0]);
    }

    // ------------------------------------------------------------------ empresa_reporte: 103 (100 activas, 84 vigentes)
    $company = fn (int $k): int => ($k % 5) + 1;         // empresa (1-5) de la relación vigente k (0-83)
    $insEr = $pdo->prepare('INSERT INTO empresa_reporte (id, empresa_id, reporte_id, activo) VALUES (?, ?, ?, ?)');
    for ($k = 0; $k < 82; $k++) $insEr->execute([$k + 1, $company($k), $k + 1, 1]);          // 82 informes activos, uno por relación
    $insEr->execute([83, $company(0) % 5 + 1, 1, 1]);                                        // el informe 1 también en otra empresa
    $insEr->execute([84, $company(1) % 5 + 1, 2, 1]);                                        // el informe 2 también en otra empresa
    for ($i = 0; $i < 14; $i++) $insEr->execute([85 + $i, 7 + $i, 1 + $i, 1]);              // activas, pero de EMPRESAS inactivas
    $insEr->execute([99, 1, 83, 1]); $insEr->execute([100, 1, 84, 1]);                       // activas, pero de INFORMES inactivos
    for ($i = 0; $i < 3; $i++) $insEr->execute([101 + $i, 6, 20 + $i, 0]);                  // relaciones revocadas

    // ------------------------------------------------------------------ user_empresa_reporte: 102 (93 activas = 91 efectivas + 2 de usuarios inactivos)
    //  Usuarios efectivos: ids 5-65 (u = 0..60). Cada uno pertenece a UNA empresa: ($u % 5) + 1.
    $insUer = $pdo->prepare('INSERT INTO user_empresa_reporte (user_id, empresa_reporte_id, activo) VALUES (?, ?, ?)');
    $uer = [];
    for ($u = 0; $u <= 60; $u++) $uer[] = [$u + 5, $u + 1];                                  // 61: relación e = u
    for ($e = 61; $e <= 78; $e++) $uer[] = [($e - 60) + 5, $e + 1];                          // 18: informes 62-79 (misma empresa que el usuario)
    for ($u = 0; $u <= 11; $u++) $uer[] = [$u + 5, $u + 5 + 1];                              // 12: segundo informe de la misma empresa
    foreach ($uer as [$user, $er]) $insUer->execute([$user, $er, 1]);
    $insUer->execute([66, 1, 1]); $insUer->execute([67, 2, 1]);                              // 2 activas de usuarios INACTIVOS
    for ($i = 0; $i < 9; $i++) $insUer->execute([20 + $i + 5, 20 + $i + 10 + 1, 0]);        // 9 retiradas

    // ------------------------------------------------------------------ empresa_usuario: 82 (79 activas)
    $insEu = $pdo->prepare('INSERT INTO empresa_usuario (empresa_id, user_id, activo) VALUES (?, ?, ?)');
    for ($u = 0; $u <= 60; $u++) $insEu->execute([($u % 5) + 1, $u + 5, 1]);                // 61 vínculos efectivos
    $insEu->execute([$company(0), 66, 1]); $insEu->execute([$company(1), 67, 1]);          // 2 vínculos de los usuarios inactivos
    for ($u = 0; $u <= 15; $u++) $insEu->execute([6, $u + 5, 1]);                           // 16 vínculos a la empresa activa sin informes
    for ($u = 0; $u <= 2; $u++) $insEu->execute([7, $u + 5, 0]);                            // 3 vínculos retirados

    $pdo->commit();
}

/** Lista de desvíos respecto de los agregados productivos conocidos (vacía si el fixture es consistente). */
function productive_fixture_deviations(PDO $pdo): array {
    // Autoverificación de agregados
    $q = fn (string $sql) => (int)$pdo->query($sql)->fetchColumn();
    $expected = [
        'usuarios' => ['SELECT COUNT(*) FROM user', 76], 'usuarios activos' => ['SELECT COUNT(*) FROM user WHERE is_active = 1', 62],
        'rol usuario' => ["SELECT COUNT(*) FROM user WHERE role = 'usuario'", 62], 'rol usuario activos' => ["SELECT COUNT(*) FROM user WHERE role = 'usuario' AND is_active = 1", 61],
        'rol user' => ["SELECT COUNT(*) FROM user WHERE role = 'user'", 10], 'rol user activos' => ["SELECT COUNT(*) FROM user WHERE role = 'user' AND is_active = 1", 0],
        'rol admin' => ["SELECT COUNT(*) FROM user WHERE role = 'admin'", 4], 'rol admin activos' => ["SELECT COUNT(*) FROM user WHERE role = 'admin' AND is_active = 1", 1],
        'empresas' => ['SELECT COUNT(*) FROM empresa', 26], 'empresas activas' => ['SELECT COUNT(*) FROM empresa WHERE activo = 1', 6],
        'informes' => ['SELECT COUNT(*) FROM reporte', 95], 'informes activos' => ['SELECT COUNT(*) FROM reporte WHERE activo = 1', 82],
        'reporte_id > 36' => ['SELECT COUNT(*) FROM reporte WHERE CHAR_LENGTH(reporte_id) > 36', 57], 'reporte_id > 36 activos' => ['SELECT COUNT(*) FROM reporte WHERE CHAR_LENGTH(reporte_id) > 36 AND activo = 1', 56],
        'reporte_id otro formato' => ["SELECT COUNT(*) FROM reporte WHERE CHAR_LENGTH(reporte_id) <= 36 AND reporte_id NOT REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'", 35],
        'reporte_id otro formato activos' => ["SELECT COUNT(*) FROM reporte WHERE CHAR_LENGTH(reporte_id) <= 36 AND reporte_id NOT REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' AND activo = 1", 26],
        'reporte_id UUID canónico' => ["SELECT COUNT(*) FROM reporte WHERE reporte_id REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'", 3],
        'reporte_id UUID canónico activos' => ["SELECT COUNT(*) FROM reporte WHERE reporte_id REGEXP '^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$' AND activo = 1", 0],
        'reporte_id duplicados entre activos' => ['SELECT COUNT(*) FROM (SELECT reporte_id FROM reporte WHERE activo = 1 GROUP BY reporte_id HAVING COUNT(*) > 1) x', 0],
        'enlaces activos view?r=' => ["SELECT COUNT(*) FROM reporte WHERE activo = 1 AND enlace LIKE 'https://app.powerbi.com/view?r=%'", 82],
        'enlace máx.' => ['SELECT MAX(CHAR_LENGTH(enlace)) FROM reporte', 151], 'grupo_id máx.' => ['SELECT MAX(CHAR_LENGTH(grupo_id)) FROM reporte', 36], 'reporte_id máx.' => ['SELECT MAX(CHAR_LENGTH(reporte_id)) FROM reporte', 50],
        'empresa_usuario' => ['SELECT COUNT(*) FROM empresa_usuario', 82], 'empresa_usuario activas' => ['SELECT COUNT(*) FROM empresa_usuario WHERE activo = 1', 79],
        'empresa_reporte' => ['SELECT COUNT(*) FROM empresa_reporte', 103], 'empresa_reporte activas' => ['SELECT COUNT(*) FROM empresa_reporte WHERE activo = 1', 100],
        'user_empresa_reporte' => ['SELECT COUNT(*) FROM user_empresa_reporte', 102], 'user_empresa_reporte activas' => ['SELECT COUNT(*) FROM user_empresa_reporte WHERE activo = 1', 93],
        'relaciones empresa-informe vigentes' => ['SELECT COUNT(*) FROM empresa_reporte er JOIN empresa e ON e.id = er.empresa_id AND e.activo = 1 JOIN reporte r ON r.id = er.reporte_id AND r.activo = 1 WHERE er.activo = 1', 84],
        'informes vinculados' => ['SELECT COUNT(DISTINCT er.reporte_id) FROM empresa_reporte er JOIN empresa e ON e.id = er.empresa_id AND e.activo = 1 JOIN reporte r ON r.id = er.reporte_id AND r.activo = 1 WHERE er.activo = 1', 82],
        'empresas con informes vigentes' => ['SELECT COUNT(DISTINCT er.empresa_id) FROM empresa_reporte er JOIN empresa e ON e.id = er.empresa_id AND e.activo = 1 JOIN reporte r ON r.id = er.reporte_id AND r.activo = 1 WHERE er.activo = 1', 5],
    ];
    $effective = 'FROM user_empresa_reporte uer JOIN user u ON u.id = uer.user_id AND u.is_active = 1 JOIN empresa_reporte er ON er.id = uer.empresa_reporte_id AND er.activo = 1 JOIN empresa e ON e.id = er.empresa_id AND e.activo = 1 JOIN empresa_usuario eu ON eu.empresa_id = e.id AND eu.user_id = u.id AND eu.activo = 1 JOIN reporte r ON r.id = er.reporte_id AND r.activo = 1 WHERE uer.activo = 1';
    $expected += [
        'permisos individuales efectivos' => ["SELECT COUNT(*) $effective", 91], 'usuarios con acceso efectivo' => ["SELECT COUNT(DISTINCT u.id) $effective", 61],
        'informes con usuarios asignados' => ["SELECT COUNT(DISTINCT r.id) $effective", 79],
        'asignaciones activas de usuarios inactivos' => ['SELECT COUNT(*) FROM user_empresa_reporte uer JOIN user u ON u.id = uer.user_id WHERE uer.activo = 1 AND u.is_active = 0', 2],
    ];
    $bad = [];
    foreach ($expected as $name => [$sql, $want]) {
        $got = $q($sql);
        if ($got !== $want) $bad[] = "$name: esperado $want, obtenido $got";
    }
    return $bad;
}
