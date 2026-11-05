<?php
// UX-08I · ¿Puede `UPDATE(email)` desde React facilitar la toma o el bloqueo de una cuenta administrativa en Flutter?
// Se reproduce la CONSULTA DE LOGIN del backend del repositorio (UserModel::getUserByLogin: `WHERE (email = :login OR username = :login) AND is_active = 1 LIMIT 1`, sin ORDER BY),
// que es lo único que se conoce de Flutter. NO se dispone del backend desplegado de Flutter ni de un flujo de recuperación por correo: esto NO lo certifica.
// Entorno: TEST_DB_PASS. Base temporal p4a_correo.
$failures = 0;
$root = new PDO('mysql:host=db;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
function ok($c, $m) { global $failures; echo ($c ? 'PASS ' : 'FAIL ') . $m . "\n"; if (!$c) $failures++; }
function info($m) { echo "INFO $m\n"; }
$root->exec('DROP DATABASE IF EXISTS p4a_correo'); $root->exec('CREATE DATABASE p4a_correo CHARACTER SET utf8mb4'); $root->exec('CREATE TABLE p4a_correo.user LIKE fixture_aw.user');
$h = fn ($p) => password_hash($p, PASSWORD_DEFAULT);
$ins = $root->prepare('INSERT INTO p4a_correo.user (id, username, email, password, first_name, last_name, role, is_active, session_version) VALUES (?,?,?,?,?,?,?,1,1)');
// B (id 1, usuario común) está ANTES que A (id 2, administrador cuyo usuario tiene forma de correo) en el índice
$ins->execute([1, 'bob', 'bob@example.invalid', $h('clave-de-bob-1'), 'B', 'B', 'user']);
$ins->execute([2, 'ceo@cervesur.example', 'ceo.real@example.invalid', $h('clave-del-ceo-1'), 'C', 'C', 'admin']);
$login = function ($l) use ($root) { $s = $root->prepare('SELECT id, username, email, password, role FROM p4a_correo.user WHERE (email = :e OR username = :u) AND is_active = 1 LIMIT 1'); $s->execute(['e' => trim($l), 'u' => trim($l)]); return $s->fetch(); };
$auth = function ($l, $p) use ($login) { $u = $login($l); return $u && password_verify($p, $u['password']) ? $u['username'] : null; };

echo "== 1. Línea base: el administrador entra con su usuario con forma de correo\n";
ok($auth('ceo@cervesur.example', 'clave-del-ceo-1') === 'ceo@cervesur.example', 'login del administrador con su usuario');

echo "== 2. Con UPDATE(email) (permiso que React tenía en el RC1): un administrador de React fija el correo de B = usuario del CEO\n";
$root->exec("UPDATE p4a_correo.user SET email = 'ceo@cervesur.example' WHERE id = 1");
$quien = $auth('ceo@cervesur.example', 'clave-del-ceo-1');
$quienB = $auth('ceo@cervesur.example', 'clave-de-bob-1');
info('login «ceo@cervesur.example» con la clave del CEO → ' . var_export($quien, true) . ' · con la clave de B → ' . var_export($quienB, true));
ok($quien === null, 'DENEGACIÓN DE SERVICIO CONFIRMADA: el CEO deja de poder iniciar sesión con su usuario (la consulta devuelve a B primero y no hay ORDER BY)');
ok($quienB === 'bob', 'y B puede iniciar sesión escribiendo el usuario del CEO con la clave de B (confusión de identidad, no toma de cuenta: sigue autenticando como B)');
ok($quienB !== 'ceo@cervesur.example', 'NO hay toma de cuenta por esta vía: nadie obtiene la identidad del CEO sin su contraseña');

echo "== 3. ¿Y el correo real del CEO? Un cambio de correo del CEO a uno controlado por otra persona\n";
$root->exec("UPDATE p4a_correo.user SET email = 'bob@example.invalid' WHERE id = 1");
$root->exec("UPDATE p4a_correo.user SET email = 'atacante@example.invalid' WHERE id = 2");
ok($auth('atacante@example.invalid', 'clave-del-ceo-1') === 'ceo@cervesur.example' && $auth('atacante@example.invalid', 'otra') === null, 'el correo nuevo pasa a ser un identificador de login del CEO, pero SIGUE exigiendo la contraseña del CEO');
info('RIESGO NO DESCARTABLE sin el backend real de Flutter: si Flutter tiene «olvidé mi contraseña» que envía un enlace/código al CORREO guardado, cambiar el correo del CEO permitiría recibirlo. En el código del repositorio NO existe ese flujo (ver informe), pero el backend DESPLEGADO puede diferir');

echo "== 4. Restricción propuesta: la cuenta de React sin UPDATE(email)\n";
$root->exec("DROP USER IF EXISTS 'p4a_react'@'%'"); $pw = 'Pr-' . bin2hex(random_bytes(6)) . 'Aa1!';
$root->exec("CREATE USER 'p4a_react'@'%' IDENTIFIED BY '$pw'");
$root->exec("GRANT SELECT (id, username, email, role, is_active) ON p4a_correo.user TO 'p4a_react'@'%'");
$root->exec("GRANT UPDATE (first_name, last_name, phone, is_active, session_version, updated_at) ON p4a_correo.user TO 'p4a_react'@'%'");
$r = new PDO('mysql:host=db;dbname=p4a_correo;charset=utf8mb4', 'p4a_react', $pw, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$den = function ($sql) use ($r) { try { $r->exec($sql); return false; } catch (PDOException $e) { return true; } };
ok($den("UPDATE user SET email = 'otro@example.invalid' WHERE id = 2") && !$den("UPDATE user SET phone = '1' WHERE id = 2"), 'sin UPDATE(email) el motor impide cambiar un correo; seguir editando nombre/apellido/teléfono funciona');
$root->exec("DROP USER 'p4a_react'@'%'"); $root->exec('DROP DATABASE p4a_correo');
echo $failures === 0 ? "\nAll p4a correo checks passed\n" : "\n$failures check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
