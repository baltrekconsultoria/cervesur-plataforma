<?php
// UX-09B · Reactivación de usuarios con RELOJ SIMULADO (modelo UserModel contra MySQL real; datos sintéticos; ningún servidor productivo).
//
// La espera de reactivación (24 h por defecto) la calcula MySQL: TIMESTAMPDIFF(SECOND, MAX(occurred_at), CURRENT_TIMESTAMP(3)) sobre la última auditoría
// `user.deactivate` del usuario. `audit_log` es de solo inserción (triggers), así que el paso del tiempo se simula FECHANDO hacia atrás la auditoría de desactivación
// que se inserta directamente (como root) en el caso de prueba, no modificando filas existentes.
// Uso (dentro del contenedor, con MySQL accesible en el host «db»):  php reactivacion_reloj_test.php      Entorno: TEST_DB_PASS, REPO (ruta del repositorio, por defecto /w)
$repo = getenv('REPO') ?: '/w';
$failures = 0; $total = 0;
function ok($c, $m) { global $failures, $total; $total++; echo ($c ? 'PASS ' : 'FAIL ') . $m . "\n"; if (!$c) { $failures++; } }
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
$pass = getenv('TEST_DB_PASS');
$srv = null;
for ($i = 0; $i < 60 && $srv === null; $i++) { try { $srv = new PDO('mysql:host=db;charset=utf8mb4', 'root', $pass, $opts); } catch (PDOException $e) { sleep(2); } }
if ($srv === null) { echo "FAIL no se pudo conectar a MySQL de prueba (entorno)
"; exit(2); }
$srv->exec('DROP DATABASE IF EXISTS reloj_t'); $srv->exec('CREATE DATABASE reloj_t CHARACTER SET utf8mb4');
$pdo = new PDO('mysql:host=db;dbname=reloj_t;charset=utf8mb4', 'root', $pass, $opts);
$pdo->exec("CREATE TABLE user (id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT, first_name VARCHAR(50), last_name VARCHAR(50), username VARCHAR(50), email VARCHAR(100), phone VARCHAR(20), password VARCHAR(255), role VARCHAR(20), is_active TINYINT DEFAULT 1, session_version INT DEFAULT 1, last_login DATETIME NULL, created_at DATETIME, updated_at DATETIME)");
foreach (['UX-08B-audit-log.up.sql', 'UX-08B-audit-log-immutable.up.sql'] as $mig) {
    $sql = file_get_contents("$repo/database/migrations/$mig");
    // los scripts pueden traer DELIMITER para los triggers; se ejecutan sentencia a sentencia
    $sql = preg_replace('/^--.*$/m', '', $sql);
    if (preg_match('/DELIMITER/i', $sql)) {
        foreach (preg_split('/DELIMITER\s+\S+/i', $sql) as $bloque) { foreach (array_filter(array_map('trim', preg_split('/\$\$|\/\//', $bloque))) as $s) { $s = rtrim(trim($s), ';'); if ($s !== '') { $pdo->exec($s); } } }
    } else {
        foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $s) { $s = rtrim($s, ';'); if ($s !== '') { $pdo->exec($s); } }
    }
}
ok((int)$pdo->query("SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema='reloj_t' AND event_object_table='audit_log'")->fetchColumn() >= 2, 'audit_log creada con sus triggers de solo inserción');

// ---- el modelo bajo prueba (sin servidor HTTP)
putenv('CERVESUR_MODE=admin_escritura');
putenv('AUDIT_LOG_MODE=required'); // admin_escritura exige auditoría obligatoria (lib/admin_escritura.php:72)
if (!function_exists('app_mode')) { function app_mode() { return 'admin_escritura'; } }
require "$repo/backend/lib/admin_escritura.php";
require "$repo/backend/models/UserModel.php";
$m = new UserModel($pdo);

$hash = password_hash('sintetica', PASSWORD_DEFAULT);
$ins = $pdo->prepare("INSERT INTO user (username, email, password, first_name, last_name, role, is_active, session_version, created_at, updated_at) VALUES (?, ?, ?, 'T', ?, ?, ?, 1, NOW(), NOW())");
foreach ([['adm1', 'admin', 1], ['adm2', 'admin', 1]] as [$u, $r, $a]) { $ins->execute([$u, "$u@example.invalid", $hash, $u, $r, $a]); }
$adm = (int)$pdo->query("SELECT id FROM user WHERE username='adm1'")->fetchColumn();
function nuevo_inactivo($pdo, $ins, $hash, $nombre) { $ins->execute([$nombre, "$nombre@example.invalid", $hash, $nombre, 'user', 0]); return (int)$pdo->query("SELECT id FROM user WHERE username='$nombre'")->fetchColumn(); }
/** Deja la «última desactivación auditada» a `$horas` horas en el pasado (INSERT; la bitácora no se modifica). */
function desactivacion_hace($pdo, $id, $horas, $segundos = 0) {
    $pdo->prepare("INSERT INTO audit_log (correlation_id, occurred_at, actor_user_id, action, entity, entity_id, before_json, after_json) VALUES (MD5(RAND()), CURRENT_TIMESTAMP(3) - INTERVAL ? SECOND, 1, 'user.deactivate', 'user', ?, '{}', '{}')")
        ->execute([$horas * 3600 + $segundos, $id]);
}
/** setActive devuelve el resultado de run_write(): éxito, o success:false con `conflict` (state = 409) y el mensaje. */
function intentar($m, $id, $adm) { try { $r = $m->setActive($id, true, $adm); } catch (Throwable $e) { $r = ['success' => false, 'conflict' => 'excepcion', 'message' => $e->getMessage()]; } return ['ok' => !empty($r['success']), 'r' => $r, 'espera' => ($r['conflict'] ?? '') === 'state' && strpos((string)($r['message'] ?? ''), 'Debe esperar') !== false]; }
function activo($pdo, $id) { return (int)$pdo->query("SELECT is_active FROM user WHERE id=$id")->fetchColumn(); }
function auditorias($pdo, $id, $accion) { return (int)$pdo->query("SELECT COUNT(*) FROM audit_log WHERE entity='user' AND entity_id=$id AND action='$accion'")->fetchColumn(); }

echo "== Columnas reales de audit_log (verificación del arnés)\n";
$cols = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_schema='reloj_t' AND table_name='audit_log'")->fetchAll(PDO::FETCH_COLUMN);
ok(in_array('occurred_at', $cols, true) && in_array('action', $cols, true) && in_array('entity_id', $cols, true), 'audit_log tiene occurred_at, action y entity_id (columnas que usa la espera)');

echo "== 1. Antes de 24 h: se rechaza sin cambios ni auditoría\n";
$u1 = nuevo_inactivo($pdo, $ins, $hash, 'u_23h'); desactivacion_hace($pdo, $u1, 23);
$r = intentar($m, $u1, $adm);
ok(!$r['ok'] && $r['espera'] && activo($pdo, $u1) === 0 && auditorias($pdo, $u1, 'user.reactivate') === 0, 'desactivado hace 23 h: reactivar da conflicto de estado (409) con el mensaje «Debe esperar 24 h…», sigue inactivo y no hay auditoría de reactivación');
$u2 = nuevo_inactivo($pdo, $ins, $hash, 'u_justo_antes'); desactivacion_hace($pdo, $u2, 24, -60);
$r = intentar($m, $u2, $adm);
ok(!$r['ok'] && $r['espera'] && activo($pdo, $u2) === 0, 'desactivado hace 23 h 59 min: todavía se rechaza por la espera (límite exacto)');

echo "== 2. Pasadas las 24 h: se acepta, con una auditoría y sesión revocada\n";
$u3 = nuevo_inactivo($pdo, $ins, $hash, 'u_25h'); desactivacion_hace($pdo, $u3, 25);
$sv0 = (int)$pdo->query("SELECT session_version FROM user WHERE id=$u3")->fetchColumn();
$r = intentar($m, $u3, $adm);
$sv1 = (int)$pdo->query("SELECT session_version FROM user WHERE id=$u3")->fetchColumn();
ok($r['ok'] && activo($pdo, $u3) === 1, 'desactivado hace 25 h: reactivar funciona y el usuario queda activo');
ok(auditorias($pdo, $u3, 'user.reactivate') === 1, 'exactamente UNA auditoría user.reactivate');
ok($sv1 === $sv0 + 1, 'session_version aumenta en 1 (los tokens anteriores no reviven)');
$u4 = nuevo_inactivo($pdo, $ins, $hash, 'u_24h1s'); desactivacion_hace($pdo, $u4, 24, 1);
$r = intentar($m, $u4, $adm);
ok($r['ok'] && activo($pdo, $u4) === 1, 'desactivado hace 24 h y 1 s: se acepta (límite exacto)');

echo "== 3. Reglas alrededor del reloj\n";
$u5 = nuevo_inactivo($pdo, $ins, $hash, 'u_dos_desact'); desactivacion_hace($pdo, $u5, 30); desactivacion_hace($pdo, $u5, 2);
$r = intentar($m, $u5, $adm);
ok(!$r['ok'] && $r['espera'] && activo($pdo, $u5) === 0, 'cuenta la ÚLTIMA desactivación (hubo una hace 30 h y otra hace 2 h): se rechaza');
$r2 = intentar($m, $u3, $adm);
ok(!$r2['ok'] && ($r2['r']['conflict'] ?? '') === 'state' && !$r2['espera'] && auditorias($pdo, $u3, 'user.reactivate') === 1, 'reactivar un usuario ya activo: conflicto («ya está activo») y sin auditoría duplicada');
$u6 = nuevo_inactivo($pdo, $ins, $hash, 'u_sin_historial');
$r = intentar($m, $u6, $adm);
ok($r['ok'] && activo($pdo, $u6) === 1, 'sin desactivación auditada por esta vía (NULL): se acepta (la espera protege sesiones emitidas antes de una desactivación hecha por la aplicación)');
putenv('ADMIN_USER_REACTIVATE_HOURS=0');
$u7 = nuevo_inactivo($pdo, $ins, $hash, 'u_espera_0'); desactivacion_hace($pdo, $u7, 0, 5);
$r = intentar($m, $u7, $adm);
ok($r['ok'], 'con ADMIN_USER_REACTIVATE_HOURS=0 la espera se desactiva');
putenv('ADMIN_USER_REACTIVATE_HOURS=48');
$u8 = nuevo_inactivo($pdo, $ins, $hash, 'u_espera_48'); desactivacion_hace($pdo, $u8, 30);
$r = intentar($m, $u8, $adm);
ok(!$r['ok'] && $r['espera'] && activo($pdo, $u8) === 0, 'con ADMIN_USER_REACTIVATE_HOURS=48, hace 30 h aún se rechaza');
putenv('ADMIN_USER_REACTIVATE_HOURS');

putenv('AUDIT_LOG_MODE=off');
$u9 = nuevo_inactivo($pdo, $ins, $hash, 'u_sin_auditoria'); desactivacion_hace($pdo, $u9, 1);
$r = intentar($m, $u9, $adm);
ok(!$r['ok'] && $r['espera'], 'CONTROL: con AUDIT_LOG_MODE distinto de required la espera igual se aplica (lee las filas ya existentes de audit_log); lo que se pierde es la escritura de auditoría, por eso admin_escritura exige required');
putenv('AUDIT_LOG_MODE=required');

echo "== 4. Sin reactivarse a sí mismo por la vía de la espera, y la bitácora sigue inmutable\n";
try { $pdo->exec("UPDATE audit_log SET action='x' LIMIT 1"); $mut = true; } catch (Throwable $e) { $mut = false; }
ok(!$mut, 'la bitácora rechaza UPDATE (por eso el reloj se simula INSERTANDO filas fechadas, no modificándolas)');

echo "\nRESULTADO REACTIVACION: " . ($total - $failures) . "/$total OK, $failures fallas\n";
$srv->exec('DROP DATABASE IF EXISTS reloj_t');
exit($failures ? 1 : 0);
