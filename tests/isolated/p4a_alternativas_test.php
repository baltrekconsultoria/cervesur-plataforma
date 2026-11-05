<?php
// UX-08I · Evaluación previa a P4a: ¿cómo impedir EN LA BASE que la cuenta de React cree usuarios con rol elevado sin afectar a Flutter?
// Compara contra MySQL 8 REAL, en una base temporal (p4a_eval) copiada de `user`: A) trigger BEFORE INSERT, B) procedimiento almacenado, C) vista con WITH CHECK OPTION, D) CHECK constraint.
// Cuentas: eval_react (React: privilegios de la plantilla 2B) y eval_flutter (SIMULA una cuenta amplia de Flutter: ALL sobre la base; su verdadera cuenta/privilegios NO se conocen).
// Entorno: TEST_DB_PASS. Imprime PASS/FAIL por comprobación y una línea INFO por cada hecho medido (contexto de ejecución, mensajes de error).
$failures = 0;
$root = new PDO('mysql:host=db;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pw = 'Ev4l-' . bin2hex(random_bytes(6)) . 'Aa1!';
function ok($c, $m) { global $failures; echo ($c ? 'PASS ' : 'FAIL ') . $m . "\n"; if (!$c) $failures++; }
function info($m) { echo "INFO $m\n"; }
function conn($u, $p) { return new PDO('mysql:host=db;dbname=p4a_eval;charset=utf8mb4', $u, $p, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]); }
function intenta(PDO $c, $sql, $params = []) { try { $s = $c->prepare($sql); $s->execute($params); return [true, '']; } catch (PDOException $e) { return [false, trim(preg_replace('/\s+/', ' ', $e->getMessage()))]; } }
function reset_db(PDO $root, $pw) {
    $root->exec('DROP DATABASE IF EXISTS p4a_eval'); $root->exec('CREATE DATABASE p4a_eval CHARACTER SET utf8mb4');
    $root->exec('CREATE TABLE p4a_eval.user LIKE fixture_aw.user'); $root->exec('CREATE TABLE p4a_eval.eval_log (id INT AUTO_INCREMENT PRIMARY KEY, usr VARCHAR(100), cur VARCHAR(100), rol VARCHAR(30))');
    $root->exec("INSERT INTO p4a_eval.user (username,email,password,first_name,last_name,role,is_active,session_version) VALUES ('admin','admin@example.invalid','h','A','A','admin',1,1),('usera','usera@example.invalid','h','U','A','usuario',1,1)");
    foreach (['eval_react', 'eval_flutter', 'eval_definer'] as $u) $root->exec("DROP USER IF EXISTS '$u'@'%'");
    $root->exec("CREATE USER 'eval_react'@'%' IDENTIFIED BY '$pw'"); $root->exec("CREATE USER 'eval_flutter'@'%' IDENTIFIED BY '$pw'"); $root->exec("CREATE USER 'eval_definer'@'%' IDENTIFIED BY '$pw'");
    $root->exec("GRANT ALL PRIVILEGES ON p4a_eval.* TO 'eval_flutter'@'%'");
    $root->exec("GRANT ALL PRIVILEGES ON p4a_eval.* TO 'eval_definer'@'%'");
}
$cols = '(username, email, first_name, last_name, phone, password, role, is_active, session_version, created_at, updated_at)';
$vals = fn ($role) => "VALUES (:u, :e, 'N', 'N', NULL, 'hash', '$role', 1, 1, NOW(), NOW())";
$p = fn ($n) => [':u' => $n, ':e' => "$n@example.invalid"];

// ================================================================ A) TRIGGER
echo "== A) Trigger BEFORE INSERT sobre user, restringido a la cuenta de React\n";
reset_db($root, $pw);
$root->exec("GRANT SELECT (id, username, email, role, is_active), INSERT (username, email, first_name, last_name, phone, password, role, is_active, session_version, created_at, updated_at) ON p4a_eval.user TO 'eval_react'@'%'");
$root->exec("CREATE DEFINER='root'@'localhost' TRIGGER p4a_eval.trg_user_rol BEFORE INSERT ON p4a_eval.user FOR EACH ROW BEGIN
  INSERT INTO p4a_eval.eval_log (usr, cur, rol) VALUES (USER(), CURRENT_USER(), NEW.role);
  IF SUBSTRING_INDEX(USER(), '@', 1) = 'eval_react' AND NEW.role NOT IN ('user', 'usuario') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'rol no permitido para la cuenta de React'; END IF; END");
$react = conn('eval_react', $pw); $flutter = conn('eval_flutter', $pw);
[$a1] = intenta($react, "INSERT INTO user $cols " . $vals('user'), $p('a_ok1'));
[$a2, $m2] = intenta($react, "INSERT INTO user $cols " . $vals('admin'), $p('a_bad1'));
[$a3] = intenta($flutter, "INSERT INTO user $cols " . $vals('admin'), $p('a_flutter_admin'));
[$a4] = intenta($flutter, "INSERT INTO user $cols " . $vals('usuario'), $p('a_flutter_usuario'));
ok($a1 && !$a2 && $a3 && $a4, 'trigger: React crea «user», React NO crea «admin» (' . $m2 . '), Flutter-simulada crea «admin» y «usuario»');
$log = $root->query('SELECT usr, cur, rol FROM p4a_eval.eval_log ORDER BY id')->fetchAll();
info('contexto DENTRO del trigger (primera inserción de React): USER()=' . $log[0]['usr'] . ' · CURRENT_USER()=' . $log[0]['cur']);
info('contexto DENTRO del trigger (inserción de Flutter-simulada): USER()=' . $log[2]['usr'] . ' · CURRENT_USER()=' . $log[2]['cur']);
ok(str_starts_with($log[0]['usr'], 'eval_react@') && str_starts_with($log[2]['usr'], 'eval_flutter@') && $log[0]['cur'] === $log[2]['cur'], 'USER() identifica a la cuenta que abrió la conexión; CURRENT_USER() devuelve el DEFINER del trigger (igual para ambas) → NO sirve para distinguir cuentas');
[$a5] = intenta($react, "UPDATE user SET role='admin' WHERE username='usera'"); [$a6] = intenta($react, 'DROP TRIGGER trg_user_rol');
ok(!$a5 && !$a6, 'la cuenta de React no puede UPDATE role ni eliminar el trigger (no tiene UPDATE(role) ni TRIGGER)');
[$a7] = intenta($react, "INSERT INTO user $cols " . $vals('ADMIN'), $p('a_bad_case'));
info('inserción con role «ADMIN» (otra capitalización) desde React: ' . ($a7 ? 'PERMITIDA (el collation es insensible a mayúsculas, NOT IN la trata como distinta → atención)' : 'bloqueada'));
// modo de fallo: definer inexistente → TODOS los inserts (incluidos los de Flutter) fallan
$root->exec('DROP TRIGGER p4a_eval.trg_user_rol');
$root->exec("CREATE DEFINER='eval_definer'@'%' TRIGGER p4a_eval.trg_user_rol BEFORE INSERT ON p4a_eval.user FOR EACH ROW BEGIN IF SUBSTRING_INDEX(USER(), '@', 1) = 'eval_react' AND NEW.role NOT IN ('user','usuario') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'x'; END IF; END");
$root->exec("DROP USER 'eval_definer'@'%'");
[$f1, $mf1] = intenta($flutter, "INSERT INTO user $cols " . $vals('usuario'), $p('a_flutter_tras_definer'));
ok(!$f1, 'MODO DE FALLO: si el DEFINER del trigger deja de existir, TODOS los INSERT en user fallan, también los de Flutter (' . substr($mf1, 0, 110) . ')');
// capacidad de mantenimiento de Flutter: ¿ALL sobre la base incluye TRIGGER? (puede reemplazar o eliminar el trigger)
$root->exec("DROP TRIGGER IF EXISTS p4a_eval.trg_user_rol");
$root->exec("CREATE DEFINER='root'@'localhost' TRIGGER p4a_eval.trg_user_rol BEFORE INSERT ON p4a_eval.user FOR EACH ROW BEGIN IF SUBSTRING_INDEX(USER(), '@', 1) = 'eval_react' AND NEW.role NOT IN ('user','usuario') THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'x'; END IF; END");
[$f2] = intenta($flutter, 'DROP TRIGGER trg_user_rol');
info('una cuenta amplia (ALL sobre la base, como se supone la de Flutter) ' . ($f2 ? 'PUEDE eliminar el trigger' : 'no puede eliminar el trigger'));
// trigger y respaldo: mysqldump de las 6 tablas usa --skip-triggers
info('el respaldo de las seis tablas (crear-respaldo.sh) usa --skip-triggers: una restauración NO recuperaría el trigger');

// ================================================================ B) PROCEDIMIENTO
echo "== B) Procedimiento almacenado con rol fijo y EXECUTE mínimo\n";
reset_db($root, $pw);
$root->exec("GRANT SELECT (id, username, email, role, is_active) ON p4a_eval.user TO 'eval_react'@'%'");
$root->exec("CREATE DEFINER='root'@'localhost' PROCEDURE p4a_eval.sp_alta_usuario(IN p_username VARCHAR(50), IN p_email VARCHAR(100), IN p_first VARCHAR(50), IN p_last VARCHAR(50), IN p_phone VARCHAR(30), IN p_hash VARCHAR(255))
  SQL SECURITY DEFINER MODIFIES SQL DATA BEGIN INSERT INTO user (username, email, first_name, last_name, phone, password, role, is_active, session_version, created_at, updated_at) VALUES (p_username, p_email, p_first, p_last, p_phone, p_hash, 'user', 1, 1, NOW(), NOW()); END");
$root->exec("GRANT EXECUTE ON PROCEDURE p4a_eval.sp_alta_usuario TO 'eval_react'@'%'");
$react = conn('eval_react', $pw); $flutter = conn('eval_flutter', $pw);
[$b1, $mb1] = intenta($react, "CALL sp_alta_usuario(:u, :e, 'N', 'N', NULL, 'hash')", $p('b_ok'));
$id = $react->query('SELECT LAST_INSERT_ID() AS i')->fetch()['i'];
ok($b1 && (int)$id > 0, "procedimiento: la cuenta de React crea un usuario (LAST_INSERT_ID tras CALL = $id)");
ok($root->query("SELECT role FROM p4a_eval.user WHERE username='b_ok'")->fetchColumn() === 'user', 'el rol es el fijo del procedimiento («user»); el rol no es un parámetro');
[$b2, $mb2] = intenta($react, "INSERT INTO user $cols " . $vals('admin'), $p('b_bad'));
ok(!$b2, 'INSERT directo en user por la cuenta de React: DENEGADO (' . substr($mb2, 0, 90) . ') → la brecha del valor del rol queda CERRADA en la base');
[$b3] = intenta($flutter, "INSERT INTO user $cols " . $vals('admin'), $p('b_flutter_admin'));
ok($b3, 'Flutter-simulada sigue insertando «admin» directo (la tabla no cambia)');
$react->beginTransaction(); intenta($react, "CALL sp_alta_usuario(:u, :e, 'N', 'N', NULL, 'hash')", $p('b_rollback')); $react->rollBack();
ok((int)$root->query("SELECT COUNT(*) FROM p4a_eval.user WHERE username='b_rollback'")->fetchColumn() === 0, 'dentro de una transacción con auditoría, el ROLLBACK deshace el alta hecha por CALL');
info('requiere cambiar: la aserción de grants (EXECUTE), el guardia de SQL (hoy rechaza «call»), RegisterController (CALL + lastInsertId), pruebas; y respaldar rutinas (crear-respaldo usa --routines=false)');

// ================================================================ C) VISTA CON CHECK OPTION
echo "== C) Vista insertable con WITH CHECK OPTION (INSERT solo por la vista)\n";
reset_db($root, $pw);
$root->exec("CREATE DEFINER='root'@'localhost' SQL SECURITY DEFINER VIEW p4a_eval.v_user_alta AS SELECT username, email, first_name, last_name, phone, password, role, is_active, session_version, created_at, updated_at FROM p4a_eval.user WHERE role IN ('user','usuario') AND is_active = 1 AND session_version = 1 WITH CHECK OPTION");
$root->exec("GRANT SELECT (id, username, email, role, is_active) ON p4a_eval.user TO 'eval_react'@'%'");
$root->exec("GRANT INSERT ON p4a_eval.v_user_alta TO 'eval_react'@'%'");
$react = conn('eval_react', $pw); $flutter = conn('eval_flutter', $pw);
[$c1, $mc1] = intenta($react, "INSERT INTO v_user_alta $cols " . $vals('user'), $p('c_ok'));
$lid = $react->lastInsertId();
ok($c1 && (int)$lid > 0, "vista: alta con rol «user» OK (PDO::lastInsertId = $lid)");
[$c2, $mc2] = intenta($react, "INSERT INTO v_user_alta $cols " . $vals('admin'), $p('c_bad'));
info('alta de «admin» por la vista: ' . substr($mc2, 0, 120));
ok(!$c2, 'vista: alta con rol «admin» RECHAZADA por CHECK OPTION');
[$c2b] = intenta($react, "INSERT INTO v_user_alta $cols " . $vals('ADMIN'), $p('c_bad_case'));
info('alta con role «ADMIN» (capitalización) por la vista: ' . ($c2b ? 'PERMITIDA (collation insensible: IN (\'user\',\'usuario\') compara sin mayúsculas, «ADMIN» no coincide con «user» → debería rechazarse)' : 'rechazada'));
[$c3] = intenta($react, "INSERT INTO v_user_alta $cols VALUES ('c_inact','c_inact@example.invalid','N','N',NULL,'hash','user',0,1,NOW(),NOW())");
[$c4] = intenta($react, "INSERT INTO v_user_alta $cols VALUES ('c_sv','c_sv@example.invalid','N','N',NULL,'hash','user',1,99,NOW(),NOW())");
ok(!$c3 && !$c4, 'vista: is_active = 0 y session_version ≠ 1 también rechazados');
[$c5, $mc5] = intenta($react, "INSERT INTO user $cols " . $vals('user'), $p('c_directo'));
ok(!$c5, 'INSERT directo en la tabla user por la cuenta de React: DENEGADO (' . substr($mc5, 0, 80) . ') → brecha CERRADA en la base');
[$c6] = intenta($react, "INSERT INTO v_user_alta (id, username, role) VALUES (777, 'c_id', 'user')");
ok(!$c6, 'la vista no expone id: no se puede fijar el id');
[$c7] = intenta($react, "UPDATE v_user_alta SET role='admin'"); [$c8] = intenta($react, 'DELETE FROM v_user_alta');
ok(!$c7 && !$c8, 'sin UPDATE ni DELETE sobre la vista');
[$c9] = intenta($flutter, "INSERT INTO user $cols " . $vals('admin'), $p('c_flutter_admin'));
[$c10] = intenta($flutter, "INSERT INTO user $cols " . $vals('usuario'), $p('c_flutter_usuario'));
ok($c9 && $c10, 'Flutter-simulada inserta «admin» y «usuario» directo en la tabla (nada cambia para ella)');
$shown = $root->query("SHOW GRANTS FOR 'eval_react'@'%'")->fetchAll(PDO::FETCH_COLUMN);
info('SHOW GRANTS de la cuenta de React: ' . implode(' | ', array_map(fn ($g) => preg_replace('/ TO .*/', '', $g), $shown)));
info('requiere cambiar: plantilla de privilegios (INSERT sobre la vista), aserción de grants (INSERT@user = violación; INSERT@vista admitido), guardia de SQL y RegisterController (tabla destino v_user_alta); respaldo (las vistas no están en la lista de seis tablas)');

// ================================================================ D) CHECK constraint
echo "== D) CHECK constraint sobre user.role
";
reset_db($root, $pw);
[$d0, $md0] = intenta($root, "ALTER TABLE p4a_eval.user ADD CONSTRAINT chk_role CHECK (role IN ('user','usuario'))");
ok(!$d0, 'CHECK (role IN user/usuario): NO se puede ni crear, porque ya existen administradores en la tabla (' . substr($md0, 0, 90) . ')');
$root->exec("ALTER TABLE p4a_eval.user ADD CONSTRAINT chk_role CHECK (role IN ('user','usuario','admin'))");
$root->exec("GRANT INSERT ON p4a_eval.user TO 'eval_react'@'%'");
$react = conn('eval_react', $pw);
[$d1] = intenta($react, "INSERT INTO user $cols " . $vals('admin'), $p('d_react_admin'));
ok($d1, 'CHECK que admite «admin» (el único posible sin romper Flutter ni los administradores existentes): NO impide que React cree administradores');
info('un CHECK no distingue cuentas: o bloquea también a Flutter y a los administradores existentes, o no protege nada → descartado');

$root->exec('DROP DATABASE p4a_eval'); foreach (['eval_react', 'eval_flutter', 'eval_definer'] as $u) $root->exec("DROP USER IF EXISTS '$u'@'%'");
echo $failures === 0 ? "\nAll p4a alternativas checks passed\n" : "\n$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
