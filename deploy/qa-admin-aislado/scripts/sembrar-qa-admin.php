<?php
// Sembrador del entorno ADMINISTRATIVO DE QA (UX-08F). Dos modos (los invoca scripts/pasos-instalacion.sh):
//   php sembrar-qa-admin.php sql <salida.sql> <id-informe-DB031>   genera el SQL del esquema productivo SINTÉTICO (no toca ninguna base)
//   php sembrar-qa-admin.php verificar <directorio-del-backend>      lee con la cuenta de la aplicación (.env) y comprueba los agregados
// Basado en la lógica de tests/isolated/productive_fixture.php (copiada aquí para que el paquete sea autónomo): mismos tipos productivos,
// mismos agregados observados (76 usuarios, 62 activos; roles admin/user/usuario...) y las claves UNIQUE productivas
// (user.username/email, empresa.ruc, reporte.enlace/reporte_id, empresa_usuario, empresa_reporte, user_empresa_reporte).
// Todo es ficticio: no hay usuarios, correos, RUC, enlaces ni hashes reales. Contraseñas: aleatorias por instalación para syn001 (admin),
// syn005, syn006 (rol «usuario») y syn007 (rol heredado «user»); se imprimen UNA vez en la salida estándar. Las demás cuentas
// reciben el hash de un valor aleatorio descartado (inutilizable). Nada de esto se guarda en el paquete.
//
// Ajustes propios de la QA sobre el fixture (documentados; el modo «verificar» exige exactamente estos desvíos y ningún otro):
//   - syn007 (id 7, activo) pasa a rol «user» para poder probar con cuentas ACTIVAS los tres valores de rol (admin, user, usuario).
//   - el informe sintético <id-informe-DB031> se renombra «DB031-SINTETICO (QA)»: hace las veces de DB031 en la lista del visor del administrador.
//     El id real (72) NO existe aquí; solo se usa en el piloto real, tras aprobación expresa.
if (PHP_SAPI !== 'cli') { exit(1); }

/** Escapa un valor como literal SQL (el cliente mysql debe usar utf8mb4: el archivo empieza con SET NAMES). */
function lit($v): string {
    if ($v === null) return 'NULL';
    if (is_int($v)) return (string) $v;
    return "'" . strtr((string) $v, ['\\' => '\\\\', "'" => "\\'", "\0" => '\\0', "\n" => '\\n', "\r" => '\\r', "\x1a" => '\\Z']) . "'";
}
/** @param list<string> $out */
function ins(array &$out, string $table, array $cols, array $vals): void {
    $out[] = "INSERT INTO $table (" . implode(', ', $cols) . ') VALUES (' . implode(', ', array_map('lit', $vals)) . ');';
}

function ddl(array &$out): void {
    $ts = 'created_at DATETIME DEFAULT CURRENT_TIMESTAMP, updated_at DATETIME DEFAULT CURRENT_TIMESTAMP';
    $out[] = "CREATE TABLE user (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, username VARCHAR(50) NOT NULL, email VARCHAR(100), password VARCHAR(255), first_name VARCHAR(50), last_name VARCHAR(50), phone VARCHAR(30), avatar VARCHAR(255), role VARCHAR(30) NOT NULL, is_active TINYINT(1) NOT NULL DEFAULT 1, session_version INT UNSIGNED NOT NULL DEFAULT 1, last_login DATETIME NULL, $ts);";
    $out[] = "CREATE TABLE empresa (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(100), razon_social VARCHAR(100), ruc VARCHAR(20), email VARCHAR(100), telefono VARCHAR(30), website VARCHAR(100), direccion VARCHAR(100), ciudad VARCHAR(100), pais VARCHAR(50), powerbi_tenant VARCHAR(100), powerbi_client VARCHAR(100), powerbi_clientsecret VARCHAR(100), logo VARCHAR(100), contacto_principal VARCHAR(100), created_by INT UNSIGNED NULL, updated_by INT UNSIGNED NULL, $ts, activo TINYINT(1) NOT NULL DEFAULT 1);";
    $out[] = "CREATE TABLE reporte (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, nombre VARCHAR(100), descripcion TEXT, enlace VARCHAR(500) NOT NULL, grupo_id VARCHAR(50) NOT NULL, reporte_id VARCHAR(50) NOT NULL, created_by INT UNSIGNED NULL, updated_by INT UNSIGNED NULL, $ts, activo TINYINT(1) NOT NULL DEFAULT 1);";
    $out[] = "CREATE TABLE empresa_usuario (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, empresa_id INT UNSIGNED, user_id INT UNSIGNED, notas TEXT, created_by INT UNSIGNED NULL, updated_by INT UNSIGNED NULL, $ts, activo TINYINT(1) NOT NULL DEFAULT 1);";
    $out[] = "CREATE TABLE empresa_reporte (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, empresa_id INT UNSIGNED, reporte_id INT UNSIGNED, notas TEXT, created_by INT UNSIGNED NULL, updated_by INT UNSIGNED NULL, $ts, activo TINYINT(1) NOT NULL DEFAULT 1);";
    $out[] = "CREATE TABLE user_empresa_reporte (id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY, user_id INT UNSIGNED, empresa_reporte_id INT UNSIGNED, notas TEXT, created_by INT UNSIGNED NULL, updated_by INT UNSIGNED NULL, $ts, activo TINYINT(1) NOT NULL DEFAULT 1);";
    // Claves UNIQUE productivas
    $out[] = 'ALTER TABLE user ADD UNIQUE uq_user_username (username), ADD UNIQUE uq_user_email (email);';
    $out[] = 'ALTER TABLE empresa ADD UNIQUE uq_empresa_ruc (ruc);';
    $out[] = 'ALTER TABLE reporte ADD UNIQUE uq_reporte_enlace (enlace), ADD UNIQUE uq_reporte_reporte_id (reporte_id);';
    $out[] = 'ALTER TABLE empresa_usuario ADD UNIQUE uq_eu (empresa_id, user_id);';
    $out[] = 'ALTER TABLE empresa_reporte ADD UNIQUE uq_er (empresa_id, reporte_id);';
    $out[] = 'ALTER TABLE user_empresa_reporte ADD UNIQUE uq_uer (user_id, empresa_reporte_id);';
}

function load(array &$out, callable $hashFor): void {
    $out[] = 'START TRANSACTION;';
    // usuarios: 76 (62 activos). ids 1-4 admin (solo el 1 activo) · 5-65 usuario activos (61) · 66 usuario inactivo · 67-76 'user' inactivos (10)
    for ($id = 1; $id <= 76; $id++) {
        [$role, $active] = $id <= 4 ? ['admin', $id === 1 ? 1 : 0] : ($id <= 65 ? ['usuario', 1] : ($id === 66 ? ['usuario', 0] : ['user', 0]));
        ins($out, 'user', ['id', 'username', 'email', 'password', 'first_name', 'last_name', 'role', 'is_active'], [$id, sprintf('syn%03d', $id), sprintf('syn%03d@example.invalid', $id), $hashFor($id), 'Sintético', sprintf('Usuario%03d', $id), $role, $active]);
    }
    // empresas: 26 (6 activas: 1-5 con informes, 6 sin informes)
    for ($id = 1; $id <= 26; $id++) {
        ins($out, 'empresa', ['id', 'nombre', 'razon_social', 'ruc', 'ciudad', 'pais', 'activo'], [$id, sprintf('Empresa sintética %02d', $id), sprintf('Empresa sintética %02d', $id), sprintf('9000000%04d', $id), 'Ciudad ficticia', 'Demo', $id <= 6 ? 1 : 0]);
    }
    // informes: 95 (82 activos), referencias históricas NO UUID
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_-';
    $longRef = function (int $id) use ($alphabet): string { $len = 37 + ($id % 14); $s = sprintf('SYN-LARGA-%04d-', $id); while (strlen($s) < $len) $s .= $alphabet[(strlen($s) * 7 + $id) % strlen($alphabet)]; return substr($s, 0, $len); };
    $otherRef = function (int $id): string {
        switch ($id % 4) {
            case 0: return sprintf('SYN-REF-%05d', $id);
            case 1: return (string) (10000000 + $id);
            case 2: return sprintf('syn_grupo_%03d_informe', $id);
            default: return substr(sprintf('SYN-OTRO-FORMATO-%03d-', $id) . str_repeat('x', 30), 0, 24 + ($id % 13));
        }
    };
    $uuidRef = fn (int $id): string => sprintf('aaaaaaaa-bbbb-4ccc-8ddd-%012d', $id);
    $token = function (int $id, int $len): string { $s = ''; $seed = base64_encode("SINTETICO-CERVESUR-$id-"); $seed = strtr(rtrim($seed, '='), '+/', '-_'); while (strlen($s) < $len) $s .= $seed; return substr($s, 0, $len); };
    for ($id = 1; $id <= 95; $id++) {
        if ($id <= 56 || $id === 83) $ref = $longRef($id);
        elseif ($id >= 93) $ref = $uuidRef($id);
        else $ref = $otherRef($id);
        $grupo = $id === 1 ? 'syn-grupo-0123456789-0123456789-0123' : ($id % 2 ? sprintf('bbbbbbbb-cccc-4ddd-8eee-%012d', $id) : sprintf('SYN-GRP-%03d', $id));
        $link = 'https://app.powerbi.com/view?r=' . $token($id, $id === 2 ? 120 : 40);
        ins($out, 'reporte', ['id', 'nombre', 'descripcion', 'enlace', 'grupo_id', 'reporte_id', 'created_by', 'activo'], [$id, sprintf('Informe sintético %03d', $id), "Descripción ficticia $id", $link, $grupo, $ref, 1, $id <= 82 ? 1 : 0]);
    }
    // empresa_reporte: 103 (100 activas, 84 vigentes)
    $company = fn (int $k): int => ($k % 5) + 1;
    $er = function (int $id, int $emp, int $rep, int $act) use (&$out): void { ins($out, 'empresa_reporte', ['id', 'empresa_id', 'reporte_id', 'activo'], [$id, $emp, $rep, $act]); };
    for ($k = 0; $k < 82; $k++) $er($k + 1, $company($k), $k + 1, 1);
    $er(83, $company(0) % 5 + 1, 1, 1);
    $er(84, $company(1) % 5 + 1, 2, 1);
    for ($i = 0; $i < 14; $i++) $er(85 + $i, 7 + $i, 1 + $i, 1);
    $er(99, 1, 83, 1); $er(100, 1, 84, 1);
    for ($i = 0; $i < 3; $i++) $er(101 + $i, 6, 20 + $i, 0);
    // user_empresa_reporte: 102 (93 activas = 91 efectivas + 2 de usuarios inactivos)
    $uer = [];
    for ($u = 0; $u <= 60; $u++) $uer[] = [$u + 5, $u + 1];
    for ($e = 61; $e <= 78; $e++) $uer[] = [($e - 60) + 5, $e + 1];
    for ($u = 0; $u <= 11; $u++) $uer[] = [$u + 5, $u + 5 + 1];
    foreach ($uer as [$user, $erId]) ins($out, 'user_empresa_reporte', ['user_id', 'empresa_reporte_id', 'activo'], [$user, $erId, 1]);
    ins($out, 'user_empresa_reporte', ['user_id', 'empresa_reporte_id', 'activo'], [66, 1, 1]);
    ins($out, 'user_empresa_reporte', ['user_id', 'empresa_reporte_id', 'activo'], [67, 2, 1]);
    for ($i = 0; $i < 9; $i++) ins($out, 'user_empresa_reporte', ['user_id', 'empresa_reporte_id', 'activo'], [20 + $i + 5, 20 + $i + 10 + 1, 0]);
    // empresa_usuario: 82 (79 activas)
    $eu = function (int $emp, int $user, int $act) use (&$out): void { ins($out, 'empresa_usuario', ['empresa_id', 'user_id', 'activo'], [$emp, $user, $act]); };
    for ($u = 0; $u <= 60; $u++) $eu(($u % 5) + 1, $u + 5, 1);
    $eu($company(0), 66, 1); $eu($company(1), 67, 1);
    for ($u = 0; $u <= 15; $u++) $eu(6, $u + 5, 1);
    for ($u = 0; $u <= 2; $u++) $eu(7, $u + 5, 0);
    $out[] = 'COMMIT;';
}

/** Lista de desvíos respecto de los agregados productivos conocidos (consulta con PDO; lectura solamente). */
function desvios(PDO $pdo): array {
    $q = fn (string $sql) => (int) $pdo->query($sql)->fetchColumn();
    $uuid = "'^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$'";
    $effective = 'FROM user_empresa_reporte uer JOIN user u ON u.id = uer.user_id AND u.is_active = 1 JOIN empresa_reporte er ON er.id = uer.empresa_reporte_id AND er.activo = 1 JOIN empresa e ON e.id = er.empresa_id AND e.activo = 1 JOIN empresa_usuario eu ON eu.empresa_id = e.id AND eu.user_id = u.id AND eu.activo = 1 JOIN reporte r ON r.id = er.reporte_id AND r.activo = 1 WHERE uer.activo = 1';
    $vigentes = 'FROM empresa_reporte er JOIN empresa e ON e.id = er.empresa_id AND e.activo = 1 JOIN reporte r ON r.id = er.reporte_id AND r.activo = 1 WHERE er.activo = 1';
    $expected = [
        'usuarios' => ['SELECT COUNT(*) FROM user', 76], 'usuarios activos' => ['SELECT COUNT(*) FROM user WHERE is_active = 1', 62],
        'rol usuario' => ["SELECT COUNT(*) FROM user WHERE role = 'usuario'", 62], 'rol usuario activos' => ["SELECT COUNT(*) FROM user WHERE role = 'usuario' AND is_active = 1", 61],
        'rol user' => ["SELECT COUNT(*) FROM user WHERE role = 'user'", 10], 'rol user activos' => ["SELECT COUNT(*) FROM user WHERE role = 'user' AND is_active = 1", 0],
        'rol admin' => ["SELECT COUNT(*) FROM user WHERE role = 'admin'", 4], 'rol admin activos' => ["SELECT COUNT(*) FROM user WHERE role = 'admin' AND is_active = 1", 1],
        'empresas' => ['SELECT COUNT(*) FROM empresa', 26], 'empresas activas' => ['SELECT COUNT(*) FROM empresa WHERE activo = 1', 6],
        'informes' => ['SELECT COUNT(*) FROM reporte', 95], 'informes activos' => ['SELECT COUNT(*) FROM reporte WHERE activo = 1', 82],
        'reporte_id > 36' => ['SELECT COUNT(*) FROM reporte WHERE CHAR_LENGTH(reporte_id) > 36', 57], 'reporte_id > 36 activos' => ['SELECT COUNT(*) FROM reporte WHERE CHAR_LENGTH(reporte_id) > 36 AND activo = 1', 56],
        'reporte_id otro formato' => ["SELECT COUNT(*) FROM reporte WHERE CHAR_LENGTH(reporte_id) <= 36 AND reporte_id NOT REGEXP $uuid", 35],
        'reporte_id otro formato activos' => ["SELECT COUNT(*) FROM reporte WHERE CHAR_LENGTH(reporte_id) <= 36 AND reporte_id NOT REGEXP $uuid AND activo = 1", 26],
        'reporte_id UUID canónico' => ["SELECT COUNT(*) FROM reporte WHERE reporte_id REGEXP $uuid", 3],
        'reporte_id UUID canónico activos' => ["SELECT COUNT(*) FROM reporte WHERE reporte_id REGEXP $uuid AND activo = 1", 0],
        'reporte_id duplicados entre activos' => ['SELECT COUNT(*) FROM (SELECT reporte_id FROM reporte WHERE activo = 1 GROUP BY reporte_id HAVING COUNT(*) > 1) x', 0],
        'enlaces activos view?r=' => ["SELECT COUNT(*) FROM reporte WHERE activo = 1 AND enlace LIKE 'https://app.powerbi.com/view?r=%'", 82],
        'enlace máx.' => ['SELECT MAX(CHAR_LENGTH(enlace)) FROM reporte', 151], 'grupo_id máx.' => ['SELECT MAX(CHAR_LENGTH(grupo_id)) FROM reporte', 36], 'reporte_id máx.' => ['SELECT MAX(CHAR_LENGTH(reporte_id)) FROM reporte', 50],
        'empresa_usuario' => ['SELECT COUNT(*) FROM empresa_usuario', 82], 'empresa_usuario activas' => ['SELECT COUNT(*) FROM empresa_usuario WHERE activo = 1', 79],
        'empresa_reporte' => ['SELECT COUNT(*) FROM empresa_reporte', 103], 'empresa_reporte activas' => ['SELECT COUNT(*) FROM empresa_reporte WHERE activo = 1', 100],
        'user_empresa_reporte' => ['SELECT COUNT(*) FROM user_empresa_reporte', 102], 'user_empresa_reporte activas' => ['SELECT COUNT(*) FROM user_empresa_reporte WHERE activo = 1', 93],
        'relaciones empresa-informe vigentes' => ["SELECT COUNT(*) $vigentes", 84], 'informes vinculados' => ["SELECT COUNT(DISTINCT er.reporte_id) $vigentes", 82],
        'empresas con informes vigentes' => ["SELECT COUNT(DISTINCT er.empresa_id) $vigentes", 5],
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

[$script, $mode, $arg1, $arg2] = $argv + [null, null, null, null];

if ($mode === 'sql') {
    $file = $arg1; $dbId = (int) $arg2;
    if (!$file || $dbId < 1 || $dbId > 82) { fwrite(STDERR, "Uso: php sembrar-qa-admin.php sql <salida.sql> <id-informe 1..82>\n"); exit(2); }
    // Cuentas con contraseña aleatoria; las demás: hash de un valor aleatorio descartado.
    $cuentas = [1 => 'syn001', 5 => 'syn005', 6 => 'syn006', 7 => 'syn007'];
    $claves = []; $hashes = [];
    foreach ($cuentas as $id => $username) { $claves[$username] = bin2hex(random_bytes(10)); $hashes[$id] = password_hash($claves[$username], PASSWORD_DEFAULT); }
    $inutilizable = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT);
    $out = ['SET NAMES utf8mb4;'];
    ddl($out);
    load($out, fn (int $id): string => $hashes[$id] ?? $inutilizable);
    // Ajustes propios de la QA (ver cabecera)
    $out[] = "UPDATE user SET role = 'user' WHERE id = 7;";
    $out[] = 'UPDATE reporte SET nombre = ' . lit('DB031-SINTETICO (QA)') . ', descripcion = ' . lit('Informe sintético que hace de DB031 en la QA; el id real (72) solo se usa en el piloto real tras aprobación expresa') . " WHERE id = $dbId;";
    if (file_put_contents($file, implode("\n", $out) . "\n") === false) { fwrite(STDERR, "No se pudo escribir $file\n"); exit(3); }
    chmod($file, 0600);
    fwrite(STDERR, 'SQL sintético generado: ' . count($out) . " sentencias; ninguna base fue tocada.\n");
    foreach ($claves as $usuario => $clave) { echo "CUENTA $usuario $clave\n"; }
    exit(0);
}

if ($mode === 'verificar') {
    $backend = $arg1;
    if (!$backend || !is_file("$backend/lib/env.php")) { fwrite(STDERR, "Uso: php sembrar-qa-admin.php verificar <directorio-del-backend>\n"); exit(2); }
    require "$backend/lib/env.php"; // carga $backend/.env con putenv()
    $host = getenv('DB_HOST'); $db = getenv('DB_NAME'); $user = getenv('DB_USER'); $pass = getenv('DB_PASS');
    if (!in_array($host, ['localhost', '127.0.0.1'], true)) { fwrite(STDERR, "Rechazado: DB_HOST debe ser localhost o 127.0.0.1.\n"); exit(3); }
    if ($db === 'cervesur_portalbi' || strpos((string) $db, 'portalbi') !== false) { fwrite(STDERR, "Rechazado: la base productiva no se toca.\n"); exit(3); }
    if (!$db || !$user || !$pass) { fwrite(STDERR, "Rechazado: credenciales incompletas en el .env.\n"); exit(3); }
    $pdo = new PDO("mysql:host=$host;dbname=$db;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $esperados = ['rol usuario: esperado 62, obtenido 61', 'rol usuario activos: esperado 61, obtenido 60', 'rol user: esperado 10, obtenido 11', 'rol user activos: esperado 0, obtenido 1'];
    $bad = desvios($pdo);
    sort($bad); sort($esperados);
    if ($bad !== $esperados) { foreach ($bad as $line) fwrite(STDERR, "DESVÍO $line\n"); exit(4); }
    fwrite(STDERR, "Agregados sintéticos correctos (76 usuarios, 62 activos; roles admin/user/usuario; 95 informes, 82 activos), salvo los 4 ajustes de QA documentados.\n");
    exit(0);
}

fwrite(STDERR, "Uso: php sembrar-qa-admin.php sql <salida.sql> <id> | verificar <backend>\n");
exit(2);
