<?php
// Compatibilidad: tokens con la FORMA exacta que emite el login del legado (AuthController.php:52-60, texto literal de produccion)
// frente al middleware ESTRICTO REAL de RC1c (paquete 2f88cfb, SEC-04B). Sirve para decidir, ANTES de tocar nada, que flujos de Flutter
// se afectarian si el legado adoptara esa validacion. Uso: php d3_compat_rc1c.php <raiz_correctivos-v6> <dir_salida>
$raiz = $argv[1]; $salida = $argv[2] ?? sys_get_temp_dir();
foreach (['jwt.php' => '40e30e2d8d4d', 'roles.php' => 'c9a60cab4454', 'auth_middleware.php' => '2c626f40e29a'] as $f => $h) {
    if (strpos(hash_file('sha256', "$raiz/reales/rc1c/$f"), $h) !== 0) { echo "ABORTA: $f no coincide con RC1c 2f88cfb\n"; exit(2); }
}
require "$raiz/reales/rc1c/jwt.php"; require "$raiz/reales/rc1c/roles.php"; require "$raiz/reales/rc1c/auth_middleware.php";
if (!function_exists('getallheaders')) { function getallheaders() { return isset($_SERVER['HTTP_AUTHORIZATION']) ? ['Authorization' => $_SERVER['HTTP_AUTHORIZATION']] : []; } }
$S = 'secreto-sintetico-compat-0123456789abcdef0123456789';
$pdo = new PDO('sqlite::memory:'); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE user (id INTEGER PRIMARY KEY, role TEXT, is_active INTEGER, session_version INTEGER)");
$pdo->exec("INSERT INTO user VALUES (1,'admin',1,1),(2,'user',1,1),(3,'usuario',1,1),(4,'Administrador',1,1),(5,'user',1,7),(6,'user',0,1)");
$M = [];
function caso($id, $desc, $tok, $espera, $imp) {
    global $S, $M;
    $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer ' . $tok;
    $r = authenticate_user($S);
    $ok = (bool)$r;
    $M[] = [$id, $desc, $ok ? 'ACEPTADO' : 'RECHAZADO', ($ok === $espera) ? 'COMPATIBLE' : 'INCOMPATIBLE', $imp, $ok ? $r['role'] : ''];
}
function p($uid, $rol, array $x = []) {
    $b = ['user_id' => $uid, 'username' => "u$uid", 'email' => "u$uid@x.invalid", 'role' => $rol, 'session_version' => 1, 'exp' => time() + 3600];
    $b = array_merge($b, $x);
    foreach ($x as $k => $v) { if ($v === '__q__') unset($b[$k]); }
    return $b;
}
// forma EXACTA del login: user_id entero, session_version entero, exp entero (login emite siempre los tres)
caso('K1', 'login actual, usuario user', generate_jwt(p(2, 'user'), $S), true, 'Flujo normal de Flutter: sin impacto');
caso('K2', 'login actual, administrador', generate_jwt(p(1, 'admin'), $S), true, 'Sin impacto');
caso('K3', "login actual, rol heredado 'usuario' (62 de 76 cuentas productivas segun C-5 previo)", generate_jwt(p(3, 'usuario'), $S), true, "Sin impacto: 'usuario' se trata como usuario basico");
caso('K4', 'token con session_version vigente distinto de 1 (cuenta con sesiones revocadas antes)', generate_jwt(p(5, 'user', ['session_version' => 7]), $S), true, 'Sin impacto');
caso('K5', 'session_version desfasado (sesion revocada)', generate_jwt(p(5, 'user', ['session_version' => 1]), $S), false, 'Debe rechazarse (es el objetivo)');
caso('K6', 'usuario inactivo', generate_jwt(p(6, 'user'), $S), false, 'Debe rechazarse (es el objetivo)');
caso('K7', 'rol del TOKEN admin, rol en base user (degradado)', generate_jwt(p(2, 'admin'), $S), true, 'Se acepta como sesion pero con el rol de la BASE (user): pierde permisos de inmediato');
// diferencias de tipo que dependen del PHP/driver del servidor del legado
caso('T1', 'user_id como TEXTO ("2")', generate_jwt(p('2', 'user'), $S), true, 'RIESGO DE COMPATIBILIDAD: si el driver del servidor devuelve ids como texto, el login emitiria user_id texto');
caso('T2', 'session_version como TEXTO ("1")', generate_jwt(p(2, 'user', ['session_version' => '1']), $S), true, 'RIESGO DE COMPATIBILIDAD: el login hace (int)$user[session_version]; es entero');
// tokens que el login actual NO emite (pre-existentes o forjados)
caso('A1', 'token antiguo SIN session_version', generate_jwt(p(2, 'user', ['session_version' => '__q__']), $S), true, 'AFECTA a tokens emitidos antes de que existiera session_version: se cerraria su sesion (relogin) una sola vez');
caso('A2', 'token SIN exp', generate_jwt(p(2, 'user', ['exp' => '__q__']), $S), true, 'El login siempre emite exp; solo afectaria a tokens forjados');
caso('A3', 'token SIN user_id', generate_jwt(p(2, 'user', ['user_id' => '__q__']), $S), true, 'El login siempre emite user_id; solo afectaria a tokens forjados');
caso('R1', "usuario con rol de base inesperado ('Administrador')", generate_jwt(p(4, 'Administrador'), $S), true, "AFECTA a cualquier cuenta con un rol distinto de admin/user/usuario: perderia acceso. Se verifica con la distribucion de roles de C-5 SQL antes de cualquier cambio");
$ver = ['COMPATIBLE' => 0, 'INCOMPATIBLE' => 0];
$csv = fopen("$salida/matriz-d3-compatibilidad.csv", 'w'); fputcsv($csv, ['caso', 'descripcion', 'resultado', 'compat', 'impacto', 'rol_efectivo'], ';');
foreach ($M as $m) { fputcsv($csv, $m, ';'); $ver[$m[3]]++; printf("%-3s %-11s %-12s %s | %s\n", $m[0], $m[2], $m[3], $m[1], $m[4]); }
fclose($csv);
printf("\nCOMPATIBILIDAD: %d compatibles, %d incompatibles con la expectativa\n", $ver['COMPATIBLE'], $ver['INCOMPATIBLE']);
