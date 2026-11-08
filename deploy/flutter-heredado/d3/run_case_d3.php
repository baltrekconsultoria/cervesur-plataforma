<?php
// Ejecuta UNA peticion contra el router REAL (con los parches reales aplicados) de un arbol.
// Uso: php run_case_d3.php <arbol> <router> <METODO> <uri> <body> <db>
// Entorno: CASE_ROLE, CASE_UID, CASE_HEADER (1 = trae Authorization), CASE_POWERBI_MODE, CASE_SECRETO_PUT,
//          CASE_REGISTRO_ABIERTO = bool | string | int | uno (como se define la constante), CASE_EXTRA (JSON: server, get, post).
// LIMITE CONOCIDO: lib/auth_middleware.php es un SUSTITUTO (el real del legado no esta disponible localmente):
// la identidad sale de CASE_ROLE/CASE_UID. $method y $uri los fija el llamador (index.php real tampoco esta).
list(, $dir, $router, $method, $uri, $body, $dbfile) = $argv;
$GLOBALS['__body'] = $body;
class ShimPhpInput {
    private $pos = 0; public $context;
    public function stream_open($p, $m, $o, &$op) { return true; }
    public function stream_read($n) { $r = substr($GLOBALS['__body'], $this->pos, $n); $this->pos += strlen($r); return $r; }
    public function stream_eof() { return $this->pos >= strlen($GLOBALS['__body']); }
    public function stream_stat() { return []; }
}
stream_wrapper_unregister('php');
stream_wrapper_register('php', 'ShimPhpInput');
if (getenv('CASE_HEADER') === '1') { $_SERVER['HTTP_AUTHORIZATION'] = 'Bearer sintetico'; }
if (getenv('CASE_AUTHORIZATION') !== false && getenv('CASE_AUTHORIZATION') !== '') { $_SERVER['HTTP_AUTHORIZATION'] = getenv('CASE_AUTHORIZATION'); } // arbol v6parcial: token real firmado
$extra = json_decode((string)getenv('CASE_EXTRA'), true) ?: [];
foreach (($extra['server'] ?? []) as $k => $v) { $_SERVER[$k] = $v; }
foreach (($extra['get'] ?? []) as $k => $v) { $_GET[$k] = $v; $_REQUEST[$k] = $v; }
foreach (($extra['post'] ?? []) as $k => $v) { $_POST[$k] = $v; $_REQUEST[$k] = $v; }
if (getenv('CASE_POWERBI_MODE') !== false && getenv('CASE_POWERBI_MODE') !== '') { define('POWERBI_AUTH_MODE', getenv('CASE_POWERBI_MODE')); }
if (getenv('CASE_SECRETO_PUT')) { define('EMPRESA_SECRETO_PUT', getenv('CASE_SECRETO_PUT')); }
if (getenv('CASE_SECRETO_POST')) { define('EMPRESA_SECRETO_POST', getenv('CASE_SECRETO_POST')); }
switch (getenv('CASE_REGISTRO_ABIERTO')) {
    case 'bool': define('REGISTRO_ABIERTO', true); break;
    case 'string': define('REGISTRO_ABIERTO', 'true'); break;
    case 'int': define('REGISTRO_ABIERTO', 1); break;
    case 'false': define('REGISTRO_ABIERTO', false); break;
}
register_shutdown_function(function () { echo "\n#STATUS:" . (http_response_code() ?: 200); });
$pdo = new PDO('sqlite:' . $dbfile);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->sqliteCreateFunction('CONCAT', function (...$a) { return implode('', $a); });
ini_set('error_log', 'php://stderr');
error_reporting(E_ALL & ~E_WARNING & ~E_NOTICE & ~E_DEPRECATED);
$_SERVER['REQUEST_METHOD'] = $method;
$jwt_secret = 'x';
require $dir . '/backend/router/' . $router;
echo "NOROUTE";
