<?php
// D3 · cierre con lo REAL disponible del legado. Uso: php d3_cierre_legado.php <raiz_correctivos-v6> <dir_salida>
//   R1  jwt.php REAL de produccion (git 1d9cd9b; huella b2fe4af95848 verificada al arrancar): tokens alterados, caducidad, algoritmo.
//   R3  routers v6 + jwt.php REAL + auth_middleware PARCIAL (71 lineas literales de produccion; el resto equivalente). INDICATIVO, nunca PASS:
//       el archivo completo (5079 B, fbdf6253eec6) no esta disponible.
// Veredictos: PASS (solo con archivos reales), INDICATIVO (con reconstruccion parcial), HALLAZGO (comportamiento real que debe decidirse),
//             FAIL, INCONCLUSO. Los datos son sinteticos; sin red.
$raiz = $argv[1]; $salida = $argv[2] ?? sys_get_temp_dir();
$tmp = sys_get_temp_dir() . '/d3c_' . getmypid(); mkdir($tmp);
$M = []; $nreq = 0;
function rec($g, $id, $d, $v, $det = '') { global $M; $M[] = [$g, $id, $d, $v, $det]; if ($v === 'FAIL') { printf("FALLA [%s] %s: %s -> %s\n", $g, $id, $d, $det); } }

// ---- R1: jwt.php REAL -------------------------------------------------------------------------------------------------------------
$jwtf = "$raiz/reales/legado/jwt.php";
$real = hash('sha256', file_get_contents($jwtf));
if (strpos($real, 'b2fe4af95848') !== 0 || filesize($jwtf) !== 2842) { echo "ABORTA: jwt.php no coincide con la huella de produccion\n"; exit(2); }
require $jwtf;
$S = 'secreto-sintetico-D3-0123456789abcdef0123456789';
$G = 'R1 jwt.php real';
function b64($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function firmar($hdr, $pl, $s, $algo = 'sha256') { $a = b64(json_encode($hdr)) . '.' . b64(json_encode($pl)); return $a . '.' . b64(hash_hmac($algo, $a, $s, true)); }
$pl = ['user_id' => 1, 'username' => 'u', 'email' => 'u@x.invalid', 'role' => 'user', 'session_version' => 1, 'exp' => time() + 600];
$H = ['typ' => 'JWT', 'alg' => 'HS256'];
$casos = [
    ['token valido', generate_jwt($pl, $S), true, 'PASS'],
    ['firma de otro secreto', generate_jwt($pl, 'otro'), false, 'PASS'],
    ['payload alterado sin refirmar', (function () use ($pl, $S) { [$h, $p, $s] = explode('.', generate_jwt($pl, $S)); $x = $pl; $x['role'] = 'admin'; return $h . '.' . b64(json_encode($x)) . '.' . $s; })(), false, 'PASS'],
    ['alg=none', b64('{"typ":"JWT","alg":"none"}') . '.' . b64(json_encode($pl)) . '.', false, 'PASS'],
    ['alg=none con firma', firmar(['typ' => 'JWT', 'alg' => 'none'], $pl, $S), false, 'PASS'],
    ['alg=HS512 (cabecera) firmado HS512', firmar(['typ' => 'JWT', 'alg' => 'HS512'], $pl, $S, 'sha512'), false, 'PASS'],
    ['sin alg', firmar(['typ' => 'JWT'], $pl, $S), false, 'PASS'],
    ['firma vacia', explode('.', generate_jwt($pl, $S))[0] . '.' . explode('.', generate_jwt($pl, $S))[1] . '.', false, 'PASS'],
    ['dos partes', 'a.b', false, 'PASS'],
    ['cuatro partes', generate_jwt($pl, $S) . '.x', false, 'PASS'],
    ['caducado (exp pasado)', generate_jwt(array_merge($pl, ['exp' => time() - 5]), $S), false, 'PASS'],
    ['exp = 0', generate_jwt(array_merge($pl, ['exp' => 0]), $S), false, 'PASS'],
    ['cadena enorme (70 KB)', str_repeat('A', 70000), false, 'PASS'],
    ['vacio', '', false, 'PASS'],
];
foreach ($casos as [$n, $tok, $espera, $v]) {
    $r = validate_jwt($tok, $S);
    rec($G, $n, $espera ? 'se acepta' : 'se rechaza', (bool)$r === $espera ? $v : 'FAIL', $r ? 'aceptado' : 'rechazado');
}
// comportamientos REALES que requieren estar firmados con el secreto (D4): se registran como HALLAZGO, no como fallo
foreach ([
    ['sin exp (nunca caduca)', array_diff_key($pl, ['exp' => 1])],
    ['exp = null (nunca caduca)', array_merge($pl, ['exp' => null])],
    ['exp = "abc" (comparacion de texto: nunca caduca)', array_merge($pl, ['exp' => 'abc'])],
    ['exp = false', array_merge($pl, ['exp' => false])],
] as [$n, $x]) {
    $r = validate_jwt(generate_jwt($x, $S), $S);
    rec($G, $n, 'token firmado con el secreto y exp ausente o no numerico', $r ? 'HALLAZGO' : 'PASS', $r ? 'ACEPTADO: solo lo emite quien tiene JWT_SECRET; el login siempre emite exp numerico' : 'rechazado');
}
$r = validate_jwt(firmar($H, 123, $S), $S);
rec($G, 'payload no es un objeto (123)', 'token firmado cuyo payload es un entero', ($r === 123) ? 'HALLAZGO' : 'PASS', is_array($r) ? 'array' : var_export($r, true) . ' (validate_jwt devuelve un valor no-array y el middleware lo trata como sesion sin user_id)');

// ---- R3: routers v6 + jwt real + middleware PARCIAL -----------------------------------------------------------------------------------
$G = 'R3 extremo a extremo (INDICATIVO)';
function bd() {
    global $tmp; static $n = 0;
    $f = "$tmp/d" . (++$n) . '.sqlite';
    $pdo = new PDO('sqlite:' . $f); $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    require __DIR__ . '/../seed6.php';
    $pdo->exec("INSERT INTO user (id,username,role,is_active) VALUES (5,'user5','user',1)");
    return [$f, $pdo];
}
function estado($db) {
    $p = new PDO('sqlite:' . $db); $h = '';
    foreach ($p->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $t) { $h .= $t . json_encode($p->query("SELECT * FROM \"$t\" ORDER BY rowid")->fetchAll(PDO::FETCH_ASSOC)); }
    return md5($h);
}
function peticion($router, $m, $uri, $body, $token, $db, $secret) {
    global $raiz, $nreq; $nreq++;
    $a = estado($db);
    $env = ['PATH' => getenv('PATH'), 'JWT_SECRET' => $secret, 'CASE_ROLE' => 'real', 'CASE_UID' => '0'];
    if ($token !== null) { $env['CASE_AUTHORIZATION'] = 'Bearer ' . $token; }
    $p = proc_open([PHP_BINARY, __DIR__ . '/run_case_d3.php', "$raiz/build/v6parcial", $router, $m, $uri, $body, $db], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pp, null, $env);
    $o = stream_get_contents($pp[1]); stream_get_contents($pp[2]); proc_close($p);
    $st = preg_match('/#STATUS:(\d+)\s*$/', $o, $mm) ? (int)$mm[1] : 0;
    return ['status' => $st, 'cambio' => estado($db) !== $a, 'body' => $o];
}
function tok($uid, $rol, $sv, $S, array $x = []) {
    $p = array_merge(['user_id' => $uid, 'username' => "user$uid", 'email' => "user$uid@x.invalid", 'role' => $rol, 'session_version' => $sv, 'exp' => time() + 600], $x);
    foreach ($x as $k => $v) { if ($v === '__quitar__') { unset($p[$k]); } }
    return generate_jwt($p, $S);
}
const RUTA = ['EmpresaRouter.php', 'DELETE', '/api/empresas/2', '']; // guardia de administrador (F-4/A2)

[$db, $pdo] = bd();
$r = peticion(...[...RUTA, tok(1, 'admin', 1, $S), $db, $S]);
rec($G, 'admin legitimo con token real firmado', 'DELETE /api/empresas/2 funciona', ($r['cambio'] && $r['status'] === 200) ? 'INDICATIVO' : 'FAIL', "HTTP {$r['status']}");
[$db, $pdo] = bd();
$r = peticion(...[...RUTA, tok(2, 'user', 1, $S), $db, $S]);
rec($G, 'usuario normal', 'rechazado 403, sin cambios', (!$r['cambio'] && $r['status'] === 403) ? 'INDICATIVO' : 'FAIL', "HTTP {$r['status']}");
[$db, $pdo] = bd();
$r = peticion(...[...RUTA, null, $db, $S]);
rec($G, 'sin Authorization', '401, sin cambios', (!$r['cambio'] && $r['status'] === 401) ? 'INDICATIVO' : 'FAIL', "HTTP {$r['status']}");
[$db, $pdo] = bd();
$r = peticion(...[...RUTA, tok(1, 'admin', 1, 'secreto-ajeno'), $db, $S]);
rec($G, 'rol admin firmado con otro secreto', '401, sin cambios', (!$r['cambio'] && $r['status'] === 401) ? 'INDICATIVO' : 'FAIL', "HTTP {$r['status']}");
[$db, $pdo] = bd(); $pdo->exec("UPDATE user SET is_active = 0 WHERE id = 1");
$r = peticion(...[...RUTA, tok(1, 'admin', 1, $S), $db, $S]);
rec($G, 'administrador DESACTIVADO con token vigente', '401, sin cambios (la baja surte efecto de inmediato)', (!$r['cambio'] && $r['status'] === 401) ? 'INDICATIVO' : 'FAIL', "HTTP {$r['status']}");
[$db, $pdo] = bd(); $pdo->exec("UPDATE user SET session_version = 2 WHERE id = 1");
$r = peticion(...[...RUTA, tok(1, 'admin', 1, $S), $db, $S]);
rec($G, 'sesion REVOCADA (session_version cambiado) con token que lo trae', '401, sin cambios', (!$r['cambio'] && $r['status'] === 401) ? 'INDICATIVO' : 'FAIL', "HTTP {$r['status']}");
[$db, $pdo] = bd(); $pdo->exec("DELETE FROM user WHERE id = 1");
$r = peticion(...[...RUTA, tok(1, 'admin', 1, $S), $db, $S]);
rec($G, 'usuario ELIMINADO con token vigente', '401, sin cambios', (!$r['cambio'] && $r['status'] === 401) ? 'INDICATIVO' : 'FAIL', "HTTP {$r['status']}");
[$db, $pdo] = bd(); $pdo->exec("UPDATE user SET session_version = 5 WHERE id = 1");
$r = peticion(...[...RUTA, tok(1, 'admin', 0, $S, ['session_version' => '__quitar__']), $db, $S]);
rec($G, 'token SIN session_version tras revocar la sesion', 'el codigo lo acepta «por compatibilidad con tokens antiguos» (lineas 111-118 literales): la revocacion NO alcanza a estos tokens', ($r['cambio'] && $r['status'] === 200) ? 'HALLAZGO' : 'INDICATIVO', "HTTP {$r['status']} cambio=" . var_export($r['cambio'], true));
[$db, $pdo] = bd(); $pdo->exec("UPDATE user SET role = 'user' WHERE id = 2");
$r = peticion(...[...RUTA, tok(2, 'admin', 1, $S), $db, $S]);
rec($G, 'usuario DEGRADADO de admin a user, token anterior (rol admin en el token)', 'el rol sale del TOKEN (linea 131 literal): conserva el permiso de administrador hasta que caduque', ($r['cambio'] && $r['status'] === 200) ? 'HALLAZGO' : 'INDICATIVO', "HTTP {$r['status']} cambio=" . var_export($r['cambio'], true));
[$db, $pdo] = bd(); $pdo->exec("UPDATE user SET role = 'user', session_version = session_version + 1 WHERE id = 2");
$r = peticion(...[...RUTA, tok(2, 'admin', 1, $S), $db, $S]);
rec($G, 'CAMBIO DE ROL con incremento de session_version en la MISMA sentencia (control compensatorio), token anterior con session_version', '401, sin cambios: el token que conservaba el rol anterior deja de valer', (!$r['cambio'] && $r['status'] === 401) ? 'INDICATIVO' : 'FAIL', "HTTP {$r['status']}");
[$db, $pdo] = bd(); $pdo->exec("UPDATE user SET role = 'admin' WHERE id = 2");
$r = peticion(...[...RUTA, tok(2, 'user', 1, $S), $db, $S]);
rec($G, 'usuario PROMOVIDO a admin, token anterior (rol user)', 'no se eleva por la base: sigue como usuario hasta reiniciar sesion', (!$r['cambio'] && $r['status'] === 403) ? 'INDICATIVO' : 'FAIL', "HTTP {$r['status']}");
[$db, $pdo] = bd(); $pdo->exec("UPDATE user SET is_active = 0 WHERE id = 1");
$r = peticion(...[...RUTA, tok(1, 'admin', 1, $S, ['user_id' => '__quitar__']), $db, $S]);
rec($G, 'token firmado SIN user_id (administrador inactivo)', 'sin user_id no se consulta la base (linea 84 literal): se acepta aunque el usuario este inactivo. Exige JWT_SECRET', ($r['cambio'] && $r['status'] === 200) ? 'HALLAZGO' : 'INDICATIVO', "HTTP {$r['status']} cambio=" . var_export($r['cambio'], true));
[$db, $pdo] = bd();
$r = peticion('UserRouter.php', 'PUT', '/api/user/3/role', '{"role":"admin"}', tok(2, 'user', 1, $S), $db, $S);
rec($G, 'cambio de rol (legado) como usuario normal', '403, sin cambios', (!$r['cambio'] && $r['status'] === 403) ? 'INDICATIVO' : 'FAIL', "HTTP {$r['status']}");
[$db, $pdo] = bd();
$r = peticion('UserRouter.php', 'PUT', '/api/user/profile/3', '{"first_name":"X"}', tok(2, 'user', 1, $S), $db, $S);
rec($G, 'perfil ajeno (A1) con token real', '403, sin cambios', (!$r['cambio'] && $r['status'] === 403) ? 'INDICATIVO' : 'FAIL', "HTTP {$r['status']}");

// ---- R5: front controller (index.php LITERAL de produccion: metodo = REQUEST_METHOD; ruta = parse_url(REQUEST_URI, PATH)) ------------------
$G = 'R5 front controller (INDICATIVO)';
$idx = file_get_contents("$raiz/reales/legado/index_literal.php");
rec($G, 'index.php no lee override de metodo ni parametros del cliente', 'solo REQUEST_METHOD y REQUEST_URI; ningun HTTP_X_*, $_GET, $_POST ni $_REQUEST', (!preg_match('/HTTP_X_|\$_GET|\$_POST|\$_REQUEST|_method/', $idx) && substr_count($idx, "\$_SERVER['REQUEST_METHOD']") === 1) ? 'INDICATIVO' : 'FAIL', 'texto literal 1519 B (produccion 1520 B, sha 7add7e450c6a): NO es exacto');
function variantes($u) {
    $v = [$u . '/', $u . '//', preg_replace('#^/api#', '/API', $u), str_replace('/api/', '/api//', $u), str_replace('/api/', '/api/./', $u), $u . '?role=admin&user_id=1', $u . '#x', $u . "\n", $u . '%00', ' ' . $u, $u . ' ', $u . '.json', str_replace('/api/', '/api/v1/', $u), '//host' . $u, 'http://host' . $u, $u . ';x=1', $u . '?/api/empresas/2'];
    if (preg_match('#/(\d+)$#', $u)) { $v[] = preg_replace('#/(\d+)$#', '/0$1', $u); $v[] = preg_replace('#/(\d)$#', '/%3$1', $u); $v[] = preg_replace('#/(\d+)$#', '/+$1', $u); }
    return $v;
}
$cat = [
    ['EmpresaRouter.php', 'POST', '/api/empresas', '{"nombre":"Zeta","razon_social":"Zeta SA","ruc":"20000000009"}'], ['EmpresaRouter.php', 'PUT', '/api/empresas/2', '{"nombre":"Beta MOD"}'], ['EmpresaRouter.php', 'DELETE', '/api/empresas/2', ''],
    ['ReporteRouter.php', 'POST', '/api/reportes', '{"nombre":"N","enlace":"http://x/9","grupo_id":"g-9","reporte_id":"r-9"}'], ['ReporteRouter.php', 'PUT', '/api/reportes/1', '{"nombre":"Mod","enlace":"http://x/1","grupo_id":"g","reporte_id":"r"}'], ['ReporteRouter.php', 'DELETE', '/api/reportes/1', ''],
    ['EmpresaReporteUsuarioRouter.php', 'POST', '/api/empresa-reporte-usuario', '{"user_id":3,"empresa_reporte_id":1}'], ['EmpresaReporteUsuarioRouter.php', 'PUT', '/api/empresa-reporte-usuario/1', '{"notas":"m"}'], ['EmpresaReporteUsuarioRouter.php', 'DELETE', '/api/empresa-reporte-usuario/1', ''],
    ['AsignacionRouter.php', 'POST', '/api/asignacion', '{"empresa_id":1,"reporte_id":1}'], ['AsignacionRouter.php', 'PUT', '/api/asignacion/1', '{"notas":"m"}'], ['AsignacionRouter.php', 'DELETE', '/api/asignacion/1', ''],
    ['EmpresaUsuarioRouter.php', 'POST', '/api/empresa-usuario', '{"user_id":2,"empresa_id":1}'], ['EmpresaUsuarioRouter.php', 'PUT', '/api/empresa-usuario/1', '{"notas":"m"}'], ['EmpresaUsuarioRouter.php', 'DELETE', '/api/empresa-usuario/1', ''],
    ['UserRouter.php', 'PUT', '/api/user/profile/3', '{"first_name":"X"}'], ['UserRouter.php', 'PUT', '/api/user/3/role', '{"role":"admin"}'],
];
$tUsr = tok(2, 'user', 1, $S); $abiertas = []; $n = 0; $sinruta = 0;
foreach ($cat as [$router, $m, $uri, $body]) {
    foreach (variantes($uri) as $v) {
        $u = parse_url($v, PHP_URL_PATH);   // exactamente lo que hace index.php
        if (!is_string($u)) { $sinruta++; continue; }
        [$db, $pdo] = bd();
        $r = peticion($router, $m, $u, $body, $tUsr, $db, $S); $n++;
        if (strpos($r['body'], 'NOROUTE') !== false) { $sinruta++; }
        if ($r['cambio'] || ($r['status'] >= 200 && $r['status'] < 300 && strpos($r['body'], 'NOROUTE') === false) || $r['status'] >= 500) { $abiertas[] = "$m $v => $u : HTTP {$r['status']}" . ($r['cambio'] ? ' +CAMBIO' : ''); }
    }
}
rec($G, "17 escrituras x variantes de URL tras parse_url ($n peticiones, $sinruta sin ruta)", 'usuario normal con token REAL firmado: ninguna variante cambia datos ni da 2xx/5xx', $abiertas ? 'FAIL' : 'INDICATIVO', implode('; ', array_slice($abiertas, 0, 5)));

// ---- salida -------------------------------------------------------------------------------------------------------------------------
$cuenta = [];
foreach ($M as $m) { $cuenta[$m[3]] = ($cuenta[$m[3]] ?? 0) + 1; }
$csv = fopen("$salida/matriz-d3-cierre-legado.csv", 'w'); fputcsv($csv, ['grupo', 'caso', 'descripcion', 'veredicto', 'evidencia'], ';');
foreach ($M as $m) { fputcsv($csv, $m, ';'); } fclose($csv);
printf("\n==== D3 cierre legado: %d casos (%d peticiones a routers) ====\n", count($M), $nreq);
ksort($cuenta); foreach ($cuenta as $k => $v) { printf("  %-10s %d\n", $k, $v); }
exit(($cuenta['FAIL'] ?? 0) === 0 ? 0 : 1);
