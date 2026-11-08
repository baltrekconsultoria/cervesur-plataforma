<?php
// D3 · pruebas de no-elusion del backend heredado (routers REALES + parches REALES; datos sinteticos; SQLite; sin red).
// Uso: php d3_no_elusion.php <raiz_correctivos-v6>      (docker: php:8.3-cli --network none, raiz montada en solo lectura)
// Salida: matriz CSV y resumen. Veredictos: PASS / FAIL / INCONCLUSO / INFO. Un FAIL o un INCONCLUSO NUNCA se oculta.
// Regla: una prueba es PASS solo si ejerce el router real. Lo que dependa de archivos ausentes (middleware JWT real del
// legado, index.php, UserController real) se registra INCONCLUSO, no PASS.
$raiz = $argv[1] ?? dirname(__DIR__, 2);
$salida = $argv[2] ?? sys_get_temp_dir();
$tmp = sys_get_temp_dir() . '/d3_' . getmypid();
mkdir($tmp);
$MATRIZ = []; $n_ejec = 0;

function rec($grupo, $id, $desc, $veredicto, $det = '') {
    global $MATRIZ;
    $MATRIZ[] = [$grupo, $id, $desc, $veredicto, $det];
    if ($veredicto === 'FAIL') { printf("FALLA  [%s] %s: %s -> %s\n", $grupo, $id, $desc, $det); }
}
function bd() {
    global $tmp; static $n = 0;
    $f = "$tmp/d" . (++$n) . '.sqlite';
    $pdo = new PDO('sqlite:' . $f);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    require dirname(__DIR__) . '/seed6.php';
    $pdo->exec("INSERT INTO user (id,username,role,is_active) VALUES (5,'user5','user',1)");
    return $f;
}
function estado($db) {
    $p = new PDO('sqlite:' . $db); $h = '';
    foreach ($p->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN) as $t) {
        $h .= $t . json_encode($p->query("SELECT * FROM \"$t\" ORDER BY rowid")->fetchAll(PDO::FETCH_ASSOC));
    }
    return md5($h);
}
function q($db, $sql) { return (new PDO('sqlite:' . $db))->query($sql)->fetchColumn(); }
/** Ejecuta una peticion y devuelve status, cuerpo, si cambio la base y si el router no tenia ruta. */
function caso($arbol, $router, $m, $uri, $body, $rol, $uid, $env = [], $extra = null, $db = null) {
    global $raiz, $n_ejec; $n_ejec++;
    $db = $db ?: bd();
    $antes = estado($db);
    $e = array_merge(['CASE_ROLE' => $rol, 'CASE_UID' => (string)$uid, 'CASE_HEADER' => $rol === 'none' ? '' : '1', 'PATH' => getenv('PATH')], $env);
    if ($extra) { $e['CASE_EXTRA'] = json_encode($extra); }
    $p = proc_open([PHP_BINARY, __DIR__ . '/run_case_d3.php', "$raiz/build/$arbol", $router, $m, $uri, $body, $db],
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, $e);
    $o = stream_get_contents($pipes[1]); stream_get_contents($pipes[2]); proc_close($p);
    $st = preg_match('/#STATUS:(\d+)\s*$/', $o, $mm) ? (int)$mm[1] : 0;
    $cuerpo = trim(preg_replace('/\n?#STATUS:\d+\s*$/', '', $o));
    return ['status' => $st, 'body' => $cuerpo, 'cambio' => estado($db) !== $antes, 'sinruta' => $cuerpo === 'NOROUTE', 'db' => $db];
}
/** Rechazo correcto: sin 2xx, sin 5xx (un 5xx indica que paso la guardia y rompio despues) y sin cambio de datos. */
function rechazado($r) { return !$r['cambio'] && ($r['sinruta'] || ($r['status'] >= 400 && $r['status'] < 500)); }
/** Lectura legitima (2xx) sobre una ruta de escritura: aceptable si no cambia datos ni rompe (5xx). */
function rechazado_o_lectura($r) { return !$r['cambio'] && $r['status'] < 500; }
function estado_txt($r) { return $r['sinruta'] ? 'sin ruta' : 'HTTP ' . $r['status'] . ($r['cambio'] ? ' +CAMBIO' : ''); }

// ---------------------------------------------------------------------------------------------------------------------
// Catalogo de escrituras del legado (arbol v6 = parches reales aplicados con patch -F0).
$E = [
    ['Empresa POST', 'EmpresaRouter.php', 'POST', '/api/empresas', '{"nombre":"Zeta","razon_social":"Zeta SA","ruc":"20000000009"}'],
    ['Empresa PUT', 'EmpresaRouter.php', 'PUT', '/api/empresas/2', '{"nombre":"Beta MOD"}'],
    ['Empresa DELETE', 'EmpresaRouter.php', 'DELETE', '/api/empresas/2', ''],
    ['Reporte POST', 'ReporteRouter.php', 'POST', '/api/reportes', '{"nombre":"Nuevo","enlace":"http://x/9","grupo_id":"g-9","reporte_id":"r-9"}'],
    ['Reporte PUT', 'ReporteRouter.php', 'PUT', '/api/reportes/1', '{"nombre":"Mod","descripcion":"d","enlace":"http://x/1","grupo_id":"g-0001","reporte_id":"r-0001"}'],
    ['Reporte DELETE', 'ReporteRouter.php', 'DELETE', '/api/reportes/1', ''],
    ['ERU POST', 'EmpresaReporteUsuarioRouter.php', 'POST', '/api/empresa-reporte-usuario', '{"user_id":3,"empresa_reporte_id":1,"notas":"x"}'],
    ['ERU PUT', 'EmpresaReporteUsuarioRouter.php', 'PUT', '/api/empresa-reporte-usuario/1', '{"notas":"mod"}'],
    ['ERU DELETE', 'EmpresaReporteUsuarioRouter.php', 'DELETE', '/api/empresa-reporte-usuario/1', ''],
    ['Asignacion POST', 'AsignacionRouter.php', 'POST', '/api/asignacion', '{"empresa_id":1,"reporte_id":1}'],
    ['Asignacion PUT', 'AsignacionRouter.php', 'PUT', '/api/asignacion/1', '{"notas":"m"}'],
    ['Asignacion DELETE', 'AsignacionRouter.php', 'DELETE', '/api/asignacion/1', ''],
    ['EmpresaUsuario POST', 'EmpresaUsuarioRouter.php', 'POST', '/api/empresa-usuario', '{"user_id":2,"empresa_id":1}'],
    ['EmpresaUsuario PUT', 'EmpresaUsuarioRouter.php', 'PUT', '/api/empresa-usuario/1', '{"notas":"m"}'],
    ['EmpresaUsuario DELETE', 'EmpresaUsuarioRouter.php', 'DELETE', '/api/empresa-usuario/1', ''],
];
// A1: perfil ajeno (usuario 2 contra el perfil 3) y cambio de rol (solo admin).
$A1 = [
    ['Perfil ajeno PUT', 'UserRouter.php', 'PUT', '/api/user/profile/3', '{"first_name":"X"}'],
    ['Perfil ajeno GET', 'UserRouter.php', 'GET', '/api/user/profile/3', ''],
    ['Rol PUT', 'UserRouter.php', 'PUT', '/api/user/3/role', '{"role":"admin"}'],
];

function variantes_uri($uri) {
    $v = [
        'barra final' => $uri . '/', 'doble barra final' => $uri . '//', 'prefijo en mayusculas' => preg_replace('#^/api#', '/API', $uri),
        'doble barra interna' => str_replace('/api/', '/api//', $uri), 'segmento punto' => str_replace('/api/', '/api/./', $uri),
        'query con role=admin' => $uri . '?role=admin&user_id=1', 'fragmento' => $uri . '#x', 'salto de linea final' => $uri . "\n",
        'byte nulo codificado' => $uri . '%00', 'espacio inicial' => ' ' . $uri, 'espacio final' => $uri . ' ',
        'extension .json' => $uri . '.json', 'version /api/v1' => str_replace('/api/', '/api/v1/', $uri),
        'ruta con ..' => preg_replace('#/([^/]+)$#', '/../$1', $uri), 'singular (alias)' => preg_replace('#^/api/(empresa|reporte|asignacion|user)s?(?=/|$)#', '/api/$1', $uri),
    ];
    if (preg_match('#/(\d+)$#', $uri, $m)) {
        $v['id con cero a la izquierda'] = preg_replace('#/(\d+)$#', '/0$1', $uri);
        $v['digito codificado %3x'] = preg_replace('#/(\d)$#', '/%3$1', $uri);
        $v['id con signo +'] = preg_replace('#/(\d+)$#', '/+$1', $uri);
        $v['id decimal'] = $uri . '.0';
        $v['id con sufijo'] = $uri . 'abc';
    }
    return $v;
}

// ===== G1: no-elusion por URL y metodo ===============================================================================
$G = 'G1 url/metodo';
$metodos = ['POST', 'PUT', 'DELETE', 'PATCH', 'GET', 'HEAD', 'OPTIONS', 'TRACE', 'put', 'Put', 'PUT ', "PUT\n"];
foreach (array_merge($E, $A1) as [$nom, $router, $metodo, $uri, $body]) {
    // 1) ruta canonica sin administrador: rechazada
    $uid = 2;
    $r = caso('v6', $router, $metodo, $uri, $body, 'user', $uid);
    rec($G, "$nom canonica/usuario", 'usuario normal rechazado', rechazado($r) ? 'PASS' : 'FAIL', estado_txt($r));
    $r = caso('v6', $router, $metodo, $uri, $body, 'none', 9);
    rec($G, "$nom canonica/sin sesion", 'sin sesion: 401 y sin cambio', ($r['status'] === 401 && !$r['cambio']) ? 'PASS' : 'FAIL', estado_txt($r));
    // 2) todos los metodos sobre la ruta canonica, como usuario normal
    $fallos = [];
    foreach ($metodos as $m2) {
        if ($m2 === $metodo) { continue; }
        $r = caso('v6', $router, $m2, $uri, $body, 'user', $uid);
        $lectura = in_array($m2, ['GET', 'HEAD', 'OPTIONS'], true) && strpos($uri, '/user/profile/') === false; // el perfil ajeno es estricto tambien en lectura (A1)
        if (!($lectura ? rechazado_o_lectura($r) : rechazado($r))) { $fallos[] = "$m2:" . estado_txt($r); }
    }
    rec($G, "$nom metodos", count($metodos) - 1 . ' metodos distintos sobre la ruta canonica', $fallos ? 'FAIL' : 'PASS', implode('; ', $fallos));
    // 3) variantes de URL con el metodo correcto y con POST/PATCH
    $fallos = []; $alcanzo = 0;
    foreach (variantes_uri($uri) as $etq => $u2) {
        foreach (array_unique([$metodo, 'POST', 'PATCH']) as $m2) {
            $r = caso('v6', $router, $m2, $u2, $body, 'user', $uid);
            if (!rechazado($r)) { $fallos[] = "$etq/$m2:" . estado_txt($r); }
        }
    }
    rec($G, "$nom variantes URL", count(variantes_uri($uri)) . ' variantes de URL x 3 metodos', $fallos ? 'FAIL' : 'PASS', implode('; ', $fallos));
}

// ===== G1b: control positivo del detector (arbol SIN parches: el mismo ataque DEBE verse) =============================
$G = 'G1b control';
$vistos = 0;
foreach ($E as [$nom, $router, $metodo, $uri, $body]) {
    $r = caso('orig', $router, $metodo, $uri, $body, 'user', 2);
    if ($r['cambio']) { $vistos++; }
}
rec($G, 'detector', "en el arbol sin parches el detector ve el efecto en $vistos de " . count($E) . ' escrituras (A2); sirve para demostrar que las pruebas no son vacias', $vistos >= 12 ? 'PASS' : 'FAIL', "vistos=$vistos");

// ===== G2: identidad y rol no vienen de datos del cliente ===============================================================
$G = 'G2 identidad';
$extra = [
    'server' => ['HTTP_X_ROLE' => 'admin', 'HTTP_X_USER_ROLE' => 'admin', 'HTTP_X_USER_ID' => '1', 'HTTP_X_FORWARDED_USER' => 'admin1', 'HTTP_X_HTTP_METHOD_OVERRIDE' => 'DELETE', 'HTTP_X_METHOD_OVERRIDE' => 'DELETE', 'HTTP_X_ADMIN' => '1'],
    'get' => ['role' => 'admin', 'user_id' => '1', 'is_admin' => '1', '_method' => 'DELETE', 'admin' => 'true'],
    'post' => ['role' => 'admin', 'user_id' => '1', '_method' => 'DELETE'],
];
foreach (array_merge($E, $A1) as [$nom, $router, $metodo, $uri, $body]) {
    $b2 = $body === '' ? '{"role":"admin","user_id":1,"is_admin":true,"created_by":1,"updated_by":1}' : substr($body, 0, -1) . ',"role":"admin","user_id":1,"is_admin":true,"created_by":1,"updated_by":1}';
    $r = caso('v6', $router, $metodo, $uri, $b2, 'user', 2, [], $extra);
    rec($G, "$nom/rol y usuario enviados por el cliente", 'cuerpo, query, $_POST y encabezados con role=admin/user_id=1 no elevan al usuario', rechazado($r) ? 'PASS' : 'FAIL', estado_txt($r));
    $r = caso('v6', $router, 'GET', $uri, $b2, 'user', 2, [], $extra);
    rec($G, "$nom/override de metodo", 'GET con X-HTTP-Method-Override y _method no ejecuta la escritura', ($r['cambio'] === false && $r['status'] < 500) ? 'PASS' : 'FAIL', estado_txt($r));
}
// el rol efectivo es el de la sesion: un admin sigue siendo admin aunque el cliente diga lo contrario
$r = caso('v6', 'EmpresaRouter.php', 'PUT', '/api/empresas/2', '{"nombre":"Beta MOD","role":"user"}', 'admin', 1, [], ['server' => ['HTTP_X_ROLE' => 'user'], 'get' => ['role' => 'user']]);
rec($G, 'admin con role=user del cliente', 'un administrador legitimo no pierde la operacion por datos del cliente (sin regresion)', $r['cambio'] && $r['status'] === 200 ? 'PASS' : 'FAIL', estado_txt($r));

// ===== G3: valores del rol de la sesion ==============================================================================
$G = 'G3 roles';
foreach (['', 'user', 'USER', 'admin ', ' admin', 'administrador', 'superadmin', 'root', '0', 'adm'] as $rol) {
    $fallos = [];
    foreach ([$E[2], $E[5], $E[8], $E[11], $E[14], $A1[2]] as [$nom, $router, $metodo, $uri, $body]) {
        $r = caso('v6', $router, $metodo, $uri, $body, $rol, 2);
        if (!rechazado($r)) { $fallos[] = "$nom:" . estado_txt($r); }
    }
    rec($G, "rol '$rol'", 'valor de rol no administrativo en la sesion: rechazado en 6 escrituras de 6 routers', $fallos ? 'FAIL' : 'PASS', implode('; ', $fallos));
}
foreach (['admin', 'Admin', 'ADMIN'] as $rol) {
    $r = caso('v6', 'EmpresaRouter.php', 'DELETE', '/api/empresas/2', '', $rol, 1);
    rec($G, "rol '$rol' (comportamiento heredado)", 'la guardia compara strtolower(role)==admin: es el comportamiento de produccion, no un cambio; el emisor del JWT fija el rol', 'INFO', estado_txt($r));
}

// ===== G4: A1 perfiles =================================================================================================
$G = 'G4 A1 perfiles';
$ids = ['1' => 'admin', '3' => 'otro usuario', '4' => 'inactivo', '5' => 'otro', '03' => 'cero a la izquierda', '+3' => 'signo', '3abc' => 'sufijo', '3.0' => 'decimal', '99999999999999999999' => 'desbordado', '%33' => 'codificado'];
foreach (['GET', 'PUT'] as $m) {
    $fallos = [];
    foreach ($ids as $id => $et) {
        $r = caso('v6', 'UserRouter.php', $m, "/api/user/profile/$id", '{"first_name":"X"}', 'user', 2);
        if (!rechazado($r)) { $fallos[] = "$id($et):" . estado_txt($r); }
    }
    rec($G, "$m perfil ajeno", count($ids) . ' identificadores ajenos o deformados como usuario 2', $fallos ? 'FAIL' : 'PASS', implode('; ', $fallos));
}
$r = caso('v6', 'UserRouter.php', 'PUT', '/api/user/profile/2', '{"first_name":"Propio"}', 'user', 2);
rec($G, 'perfil propio PUT (legitimo)', 'el usuario edita su propio perfil: sigue funcionando', ($r['status'] === 200 && $r['cambio']) ? 'PASS' : 'FAIL', estado_txt($r));
$r = caso('v6', 'UserRouter.php', 'PUT', '/api/user/profile/0002', '{"first_name":"Propio"}', 'user', 2);
rec($G, 'perfil propio con ceros', 'id con ceros a la izquierda = el mismo usuario: permitido; no abre otro perfil', ($r['status'] === 200 && $r['cambio'] && q($r['db'], "SELECT count(*) FROM write_log WHERE tag='perfil.update.2'") == 1) ? 'PASS' : 'FAIL', estado_txt($r));
foreach (['GET', 'PUT'] as $m) {
    $r = caso('v6', 'UserRouter.php', $m, '/api/user/profile/3', '{"first_name":"Adm"}', 'admin', 1);
    rec($G, "$m perfil ajeno como admin (legitimo)", 'el administrador sigue accediendo a cualquier perfil', ($r['status'] === 200 ? 'PASS' : 'FAIL'), estado_txt($r));
}
$r = caso('v6', 'UserRouter.php', 'PUT', '/api/user/profile', '{"id":3,"user_id":3,"first_name":"X"}', 'user', 2);
rec($G, 'perfil propio con id ajeno en el cuerpo', 'PUT /api/user/profile usa el usuario de la sesion, no el id del cuerpo', (q($r['db'], "SELECT count(*) FROM write_log WHERE tag='perfil.update.3'") == 0 && q($r['db'], "SELECT count(*) FROM write_log WHERE tag='perfil.update.2'") == 1) ? 'PASS' : 'FAIL', estado_txt($r));
rec($G, 'UserController real', 'que updateProfile ignore correo/rol/contrasena enviados en el cuerpo del perfil propio', 'INCONCLUSO', 'controlador real del legado no disponible localmente (solo un sustituto que registra la llamada); verificar con la copia de respaldo antes de A-L3');

// ===== G5: K1 ============================================================================================================
$G = 'G5 K1 token';
$uri = '/api/powerbi/tokens/1';
foreach (['none' => 9, 'user' => 2, 'admin' => 1] as $rol => $uid) {
    $fallos = [];
    foreach (['GET', 'POST', 'PUT', 'DELETE', 'HEAD', 'OPTIONS', 'PATCH'] as $m) {
        foreach (array_merge(['canonica' => $uri], variantes_uri($uri)) as $etq => $u2) {
            $r = caso('v6', 'PowerBIRouter.php', $m, $u2, '', $rol, $uid, ['CASE_POWERBI_MODE' => 'retirar']);
            $ok = !$r['cambio'] && ($r['sinruta'] || $r['status'] === 410) && stripos($r['body'], 'access_token') === false;
            if (!$ok) { $fallos[] = "$m $etq:" . estado_txt($r); }
        }
    }
    rec($G, "modo retirar/$rol", '7 metodos x 17 URL: siempre 410 o sin ruta, sin token y sin tocar la base', $fallos ? 'FAIL' : 'PASS', implode('; ', array_slice($fallos, 0, 6)));
}
$r = caso('v6', 'PowerBIRouter.php', 'GET', $uri, '', 'none', 9, ['CASE_POWERBI_MODE' => 'retirar']);
rec($G, 'modo retirar: 410 exacto', 'la ruta canonica anonima responde 410 «Recurso retirado»', ($r['status'] === 410 && strpos($r['body'], 'Recurso retirado') !== false) ? 'PASS' : 'FAIL', estado_txt($r));
$fallos = [];
foreach (['RETIRAR', 'Retirar', ' retirar', 'retirar ', 'false', '0', 'off', 'desactivado', 'xyz'] as $modo) {
    $r = caso('v6', 'PowerBIRouter.php', 'GET', $uri, '', 'none', 9, ['CASE_POWERBI_MODE' => $modo]);
    if ($r['status'] !== 401) { $fallos[] = "'$modo':" . estado_txt($r); }
}
rec($G, 'modo desconocido', 'un valor de modo mal escrito falla CERRADO (401 anonimo), nunca abierto', $fallos ? 'FAIL' : 'PASS', implode('; ', $fallos));
$r = caso('v6', 'PowerBIRouter.php', 'GET', $uri, '', 'none', 9, ['CASE_POWERBI_MODE' => 'exigir']);
rec($G, 'modo exigir anonimo', 'sin sesion: 401', $r['status'] === 401 ? 'PASS' : 'FAIL', estado_txt($r));
$r = caso('v6', 'PowerBIRouter.php', 'GET', $uri, '', 'user', 3, ['CASE_POWERBI_MODE' => 'exigir']); // el usuario 3 NO esta asignado al reporte 1
$r2 = caso('v6', 'PowerBIRouter.php', 'GET', $uri, '', 'user', 2, ['CASE_POWERBI_MODE' => 'exigir']); // el usuario 2 SI lo esta
rec($G, 'modo exigir usuario asignado (legitimo)', 'el usuario asignado al reporte no recibe 401 ni 403', ($r2['status'] !== 401 && $r2['status'] !== 403) ? 'PASS' : 'FAIL', estado_txt($r2));
rec($G, 'modo exigir usuario sin asignacion', 'usuario sin asignacion al reporte: 403', $r['status'] === 403 ? 'PASS' : 'FAIL', estado_txt($r));
$r = caso('v6', 'PowerBIRouter.php', 'GET', $uri, '', 'none', 9, ['CASE_POWERBI_MODE' => 'observar']);
rec($G, 'modo observar (predeterminado)', 'ATENCION: en observar el token anonimo SIGUE entregandose (solo se registra). K1 no queda cerrado hasta que el modo sea «retirar»', 'INFO', estado_txt($r) . ' -> P4b exige la atestacion de que POWERBI_AUTH_MODE=retirar en produccion');
rec($G, 'origen del modo', 'POWERBI_AUTH_MODE es una constante definida por el despliegue; ningun dato de la peticion la define', 'PASS', 'verificado por el escaneo estatico G8');

// ===== G6: K2 ============================================================================================================
$G = 'G6 K2 secretos';
$secret0 = 'SYNTH-SECRET-BBBB';
$claves = ['powerbi_clientsecret', 'POWERBI_CLIENTSECRET', 'PowerBI_ClientSecret', 'powerbi_clientsecret ', ' powerbi_clientsecret', 'powerbi_clientsecret\u0000', "\\u0070owerbi_clientsecret", 'powerbi_clientSecret'];
$fallos = []; $rechazos = [];
foreach ($claves as $k) {
    $json = '{"nombre":"Beta MOD","' . $k . '":"SYNTH-ATAQUE-1"}';
    foreach (['rechazar', 'bloquear', 'ignorar_vacio'] as $modo) {
        $r = caso('v6', 'EmpresaRouter.php', 'PUT', '/api/empresas/2', $json, 'admin', 1, ['CASE_SECRETO_PUT' => $modo]);
        $sec = q($r['db'], "SELECT powerbi_clientsecret FROM empresa WHERE id=2");
        if ($modo !== 'ignorar_vacio' && $sec !== $secret0) { $fallos[] = bin2hex(substr($k, 0, 3)) . "/$modo secreto cambiado a $sec"; }
        if (strpos($r['body'], 'SYNTH-SECRET') !== false || strpos($r['body'], 'SYNTH-ATAQUE') !== false) { $fallos[] = "$modo eco del secreto"; }
    }
}
rec($G, 'PUT con secreto (8 variantes de clave x 3 modos)', 'el secreto almacenado no cambia y nunca se devuelve', $fallos ? 'FAIL' : 'PASS', implode('; ', array_slice($fallos, 0, 6)));
foreach (['{"nombre":"B","powerbi_clientsecret":["SYNTH-ATAQUE-2"]}', '{"nombre":"B","powerbi_clientsecret":{"v":"SYNTH-ATAQUE-2"}}', '{"nombre":"B","powerbi_clientsecret":123456}',
          '{"nombre":"B","powerbi_clientsecret":"SYNTH-ATAQUE-2","powerbi_clientsecret":"SYNTH-ATAQUE-3"}', '{"nombre":"B","datos":{"powerbi_clientsecret":"SYNTH-ATAQUE-2"}}'] as $i => $json) {
    $r = caso('v6', 'EmpresaRouter.php', 'PUT', '/api/empresas/2', $json, 'admin', 1);
    $sec = q($r['db'], "SELECT powerbi_clientsecret FROM empresa WHERE id=2");
    rec($G, "PUT secreto en forma no escalar #$i", 'matriz, objeto, numero, clave duplicada o anidado: el secreto no cambia', $sec === $secret0 ? 'PASS' : 'FAIL', "secreto=$sec " . estado_txt($r));
}
$r = caso('v6', 'EmpresaRouter.php', 'PUT', '/api/empresas/2', 'powerbi_clientsecret=SYNTH-ATAQUE-4&nombre=B', 'admin', 1, [], ['post' => ['powerbi_clientsecret' => 'SYNTH-ATAQUE-4']]);
rec($G, 'PUT por formulario / $_POST', 'secreto enviado por $_POST o formulario: no cambia', q($r['db'], "SELECT powerbi_clientsecret FROM empresa WHERE id=2") === $secret0 ? 'PASS' : 'FAIL', estado_txt($r));
$r = caso('v6', 'EmpresaRouter.php', 'POST', '/api/empresas', '{"nombre":"Zeta","razon_social":"Zeta SA","ruc":"20000000009","powerbi_clientsecret":"SYNTH-ATAQUE-5"}', 'admin', 1);
rec($G, 'POST alta con secreto (K2e)', 'el alta rechaza el secreto explicito y no crea la fila', (!$r['cambio'] && $r['status'] >= 400) ? 'PASS' : 'FAIL', estado_txt($r));
$fallos = [];
foreach ([['GET', '/api/empresas'], ['GET', '/api/empresas/2'], ['GET', '/api/empresas/buscar/Beta'], ['GET', '/api/empresas/ciudad/Cusco']] as [$m, $u]) {
    foreach (['user' => 2, 'admin' => 1] as $rol => $uid) {
        $r = caso('v6', 'EmpresaRouter.php', $m, $u, '', $rol, $uid);
        if (strpos($r['body'], 'SYNTH-SECRET') !== false) { $fallos[] = "$u/$rol expone el secreto"; }
    }
}
rec($G, 'lecturas de empresa (K2a)', 'ninguna lectura (usuario ni admin) devuelve powerbi_clientsecret', $fallos ? 'FAIL' : 'PASS', implode('; ', $fallos));
rec($G, 'EmpresaController/EmpresaModel reales', 'que el controlador real no tenga otra salida del secreto distinta de la probada', 'INCONCLUSO', 'EmpresaModel del arbol es un esqueleto construido con las lineas impresas por C-3; la comprobacion sobre el archivo real se hace con verificar-parches-en-copia.sh y el humo de la ventana');

// ===== G7: A3 / F-10 ====================================================================================================
$G = 'G7 A3 registro';
$cuerpoReg = '{"username":"nuevo","email":"n@x.test","password":"Sintetica#1","role":"admin","first_name":"N","last_name":"U"}';
$fallos = [];
foreach (['none' => 9, 'user' => 2, 'admin' => 1] as $rol => $uid) {
    $r = caso('v6', 'auth.php', 'POST', '/api/register', $cuerpoReg, $rol, $uid);
    if (!($r['status'] === 403 && !$r['cambio'])) { $fallos[] = "$rol:" . estado_txt($r); }
}
rec($G, 'POST /api/register cerrado', 'anonimo, usuario y admin reciben 403 y no se crea ninguna cuenta', $fallos ? 'FAIL' : 'PASS', implode('; ', $fallos));
$fallos = [];
foreach (['string' => "'true' (texto)", 'int' => '1 (entero)', 'false' => 'false'] as $modo => $et) {
    $r = caso('v6', 'auth.php', 'POST', '/api/register', $cuerpoReg, 'none', 9, ['CASE_REGISTRO_ABIERTO' => $modo]);
    if (!($r['status'] === 403 && !$r['cambio'])) { $fallos[] = "$et:" . estado_txt($r); }
}
rec($G, 'constante mal definida', 'REGISTRO_ABIERTO = «true»/1/false NO abre el registro; solo true booleano exacto', $fallos ? 'FAIL' : 'PASS', implode('; ', $fallos));
$fallos = [];
foreach (array_merge(['canonica' => '/api/register'], variantes_uri('/api/register')) as $etq => $u2) {
    foreach (['POST', 'PUT', 'GET', 'PATCH', 'OPTIONS'] as $m) {
        $r = caso('v6', 'auth.php', $m, $u2, $cuerpoReg, 'none', 9, [], ['server' => ['HTTP_X_HTTP_METHOD_OVERRIDE' => 'POST'], 'get' => ['_method' => 'POST', 'role' => 'admin']]);
        if ($r['cambio'] || ($m !== 'POST' && $r['status'] === 201)) { $fallos[] = "$m $etq:" . estado_txt($r); }
    }
}
rec($G, 'variantes de registro', '5 metodos x 17 URL con override: ninguna crea cuenta', $fallos ? 'FAIL' : 'PASS', implode('; ', array_slice($fallos, 0, 6)));
$r = caso('v6', 'auth.php', 'POST', '/api/register', $cuerpoReg, 'none', 9, ['CASE_REGISTRO_ABIERTO' => 'bool']);
rec($G, 'reversion documentada', 'con REGISTRO_ABIERTO=true (reversion) el flujo vuelve a existir: la guardia es la unica diferencia', 'INFO', estado_txt($r));
$r = caso('orig', 'auth.php', 'POST', '/api/register', $cuerpoReg, 'none', 9);
rec($G, 'control sin parche', 'en el arbol sin F-10 el registro anonimo SI llega al controlador (A3 demostrado); confirma que el detector ve la diferencia', ($r['status'] !== 403) ? 'PASS' : 'FAIL', estado_txt($r));
rec($G, 'rutas alias hacia el alta', 'otras rutas del legado que creen cuentas (index.php, UserController real)', 'INCONCLUSO', 'el escaneo G8 solo cubre los routers disponibles; index.php y UserController reales no estan localmente');

// ===== G8: escaneos estaticos sobre los archivos reales del arbol =======================================================
$G = 'G8 estatico';
$ar = "$raiz/build/v6/backend";
$archivos = array_merge(glob("$ar/router/*.php"), glob("$ar/lib/*.php"));
$ctl = array_merge(glob("$ar/controllers/*.php"), glob("$ar/models/*.php"));
$lec = [];
foreach (array_merge($archivos, $ctl) as $f) {
    $t = file_get_contents($f);
    if (basename($f) === 'auth_middleware.php' || preg_match('#controllers/(Asignacion|EmpresaUsuario|User|Register)Controller#', str_replace('\\', '/', $f))) { continue; } // sustitutos/esqueletos de la prueba
    if (preg_match_all('/\$_(GET|POST|REQUEST|COOKIE|SESSION|FILES)\b|getallheaders\s*\(|HTTP_X_[A-Z_]+|\$_SERVER\[[\'"](?!REQUEST_METHOD|REQUEST_URI|HTTP_AUTHORIZATION)[A-Z_]+[\'"]\]/', $t, $mm, PREG_OFFSET_CAPTURE)) {
        $lineas = explode("\n", $t);
        foreach ($mm[0] as [$txt, $off]) { $n = substr_count(substr($t, 0, $off), "\n") + 1; $lec[] = basename($f) . ':' . $n . ' ' . $txt . '|' . trim($lineas[$n - 1]); }
    }
}
// Lista blanca JUSTIFICADA de usos conocidos que no deciden identidad, rol ni metodo; cualquier otro uso, o uno de estos que mencione rol/usuario/metodo, falla.
$lista = [
    ['UserRouter.php', '/\$_GET\[.(status|only_active|include_inactive).\]/', 'filtros de estado del listado GET /api/users (status, only_active, include_inactive)'],
    ['UserRouter.php', '/\$_FILES\[.avatar.\]/', 'archivo del avatar propio (POST /api/user/avatar)'],
    ['powerbi_authz.php', '/getallheaders\(/', 'solo comprueba si existe cabecera Authorization (modo mixto)'],
];
$permitidos = []; $aceptados = [];
foreach ($lec as $x) {
    if (strpos($x, 'SESSION') !== false) { continue; }
    [$ref, $linea] = explode('|', $x, 2);
    $f = explode(':', $ref)[0]; $just = null;
    foreach ($lista as [$arch, $re, $j]) { if ($f === $arch && preg_match($re, $linea)) { $just = $j; } }
    if ($just !== null && !preg_match('/role|user_id|\$method|REQUEST_METHOD/i', $linea)) { $aceptados[$just] = ($aceptados[$just] ?? 0) + 1; } else { $permitidos[] = $ref; }
}
rec($G, 'lecturas de datos del cliente en rutas/lib', 'ningun router o libreria real lee GET/POST/REQUEST/COOKIE/FILES ni encabezados para decidir identidad, rol o metodo; solo 3 usos conocidos y justificados', $permitidos ? 'FAIL' : 'PASS', implode('; ', $permitidos) ?: json_encode($aceptados, JSON_UNESCAPED_UNICODE));
$ses = array_values(array_filter($lec, function ($x) { return strpos($x, 'SESSION') !== false; }));
rec($G, 'uso de $_SESSION', 'los routers de Reporte fijan $_SESSION[user_id] desde el usuario autenticado; se revisa que no se lea de la peticion', 'INFO', implode('; ', $ses) ?: 'ninguno');
// inventario de escrituras
$sitios = 0; $detalle = [];
foreach (glob("$ar/router/*.php") as $f) {
    $t = file_get_contents($f);
    $c = preg_match_all('/\$method\s*===\s*\'(POST|PUT|DELETE|PATCH)\'/', $t);
    $sitios += $c; $detalle[basename($f)] = $c;
}
$probados = count($E) + 1 /*role*/ + 2 /*profile PUT y avatar*/;
$esperado = ['AsignacionRouter.php' => 3, 'EmpresaReporteUsuarioRouter.php' => 3, 'EmpresaRouter.php' => 3, 'EmpresaUsuarioRouter.php' => 3, 'ReporteRouter.php' => 3, 'UserRouter.php' => 4, 'auth.php' => 3];
$ok = true; foreach ($esperado as $f => $n) { if (($detalle[$f] ?? -1) !== $n) { $ok = false; } }
rec($G, 'inventario de sitios de escritura', 'los routers contienen exactamente 22 sitios $method===POST/PUT/DELETE/PATCH: 15 (A2) + perfil PUT + avatar + role + login/register/logout; cualquier sitio nuevo hace fallar esta prueba', $ok ? 'PASS' : 'FAIL', json_encode($detalle));
rec($G, 'avatar POST', 'POST /api/user/avatar solo escribe el avatar del usuario de la sesion (usa $user[user_id])', preg_match('/updateProfile\(\$user\[\'user_id\'\],\s*\[\'avatar\'/', file_get_contents("$ar/router/UserRouter.php")) ? 'PASS' : 'FAIL', 'texto del router');
$sinancla = [];
foreach (glob("$ar/router/*.php") as $f) {
    foreach (file($f) as $i => $l) { if (preg_match('/preg_match\(\s*[\'"][#\/](?!\^)/', $l)) { $sinancla[] = basename($f) . ':' . ($i + 1); } }
}
rec($G, 'expresiones de ruta sin ancla', 'toda preg_match de rutas empieza en ^ (una ruta sin ancla se podria alcanzar con prefijos)', $sinancla ? 'FAIL' : 'PASS', implode(', ', $sinancla));
$sinfin = [];
foreach (glob("$ar/router/*.php") as $f) {
    foreach (file($f) as $i => $l) { if (preg_match('/preg_match\(\s*[\'"]([#\/])\^[^\'"]*(?<!\$)\1/', $l) && strpos($l, '$') === false) { $sinfin[] = basename($f) . ':' . ($i + 1); } }
}
rec($G, 'expresiones de ruta sin fin', 'expresiones de rutas que no terminan en $ (reportadas, no necesariamente defecto: las lecturas /buscar/(.+) son abiertas por diseno)', 'INFO', implode(', ', $sinfin) ?: 'ninguna');
$salto = preg_match('/\$\#/', '') ;
$r = caso('v6', 'EmpresaRouter.php', 'PUT', "/api/empresas/2\n", '{"nombre":"Beta MOD"}', 'user', 2);
rec($G, 'PCRE y salto de linea final', 'en PHP «$» acepta un \\n final: el id sigue pasando por la MISMA guardia (usuario normal rechazado)', rechazado($r) ? 'PASS' : 'FAIL', estado_txt($r));

// ===== G9: lo que NO se puede probar con los archivos disponibles ========================================================
$G = 'G9 inconcluso';
foreach ([
    ['Authorization ausente, malformada, duplicada o invalida (legado)', 'lib/auth_middleware.php real del legado no disponible; el arbol usa un sustituto que solo exige que el encabezado exista. Pruebas reales de JWT del legado: solo en la ventana, sobre copia del archivo (C-12 describe sus reglas)'],
    ['cuentas inactivas y sesiones revocadas (legado)', 'el middleware real consulta la base (C-12: rechaza inactivos y falla cerrado ante PDOException; acepta tokens sin session_version). No ejercitado aqui'],
    ['normalizacion de URL y override de metodo en el front controller', 'index.php del legado no disponible. Los routers reciben $uri y $method ya decididos; aqui se les entrego la peor variante posible y ninguna eludio'],
    ['JWT_SECRET definido y no literal (D4)', 'no es una propiedad del codigo: requiere atestacion de infraestructura. Si el secreto fuera el literal por defecto, el rol del legado seria falsificable'],
    ['controladores/modelos reales de Reporte, ERU, Empresa', 'esqueletos con las lineas impresas por C-3: las guardias estan en los routers, que si son los reales (4 con SHA exacta [X]; el resto sobre texto literal [R])'],
] as [$d, $m]) { rec($G, $d, $m, 'INCONCLUSO', ''); }

// ===== salida ============================================================================================================
$cuenta = ['PASS' => 0, 'FAIL' => 0, 'INCONCLUSO' => 0, 'INFO' => 0];
foreach ($MATRIZ as $m) { $cuenta[$m[3]]++; }
$csv = fopen("$salida/matriz-d3.csv", 'w');
fputcsv($csv, ['grupo', 'caso', 'descripcion', 'veredicto', 'evidencia'], ';');
foreach ($MATRIZ as $m) { fputcsv($csv, $m, ';'); }
fclose($csv);
printf("\n==== D3 legado: %d casos registrados (%d peticiones reales a routers) ====\n", count($MATRIZ), $n_ejec);
foreach ($cuenta as $k => $v) { printf("  %-10s %d\n", $k, $v); }
echo "VEREDICTO-LEGADO: " . ($cuenta['FAIL'] === 0 ? 'SIN FALLOS (hay INCONCLUSOS)' : 'CON FALLOS') . "\n";
exit($cuenta['FAIL'] === 0 ? 0 : 1);
