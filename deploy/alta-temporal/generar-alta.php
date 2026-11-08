<?php
/**
 * Generador del alta TEMPORAL de un usuario del backend heredado (UX-09A).
 *
 * Es un procedimiento PUENTE entre el cierre del registro público (F-10) y el alta de React (P4b/P4c). No es una función nueva de la aplicación.
 *
 * Qué hace: valida los datos, genera una contraseña temporal aleatoria, calcula su hash bcrypt (igual que RegisterController:
 * password_hash(..., PASSWORD_DEFAULT)) y IMPRIME una sentencia SQL para que la revise y la ejecute el DBA. La contraseña sale UNA sola vez,
 * por el flujo de errores (STDERR); la SQL sale por la salida estándar y NO contiene la contraseña, solo su hash.
 * Qué NO hace: no se conecta a ninguna base, no escribe archivos, no recibe datos por la línea de comandos (no quedan en el historial),
 * no registra la contraseña y no ejecuta nada.
 *
 * Uso interactivo (el DBA o el operador autorizado):    php generar-alta.php
 * Uso en pruebas (JSON por la entrada estándar):         php generar-alta.php --stdin-json < datos.json
 *
 * Códigos de salida: 0 correcto · 2 datos inválidos · 3 entrada ilegible.
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}

const LONGITUD_CLAVE = 20;
const ALFABETO = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789!#$%*+-=?@';

function error_salida($mensaje, $codigo) {
    fwrite(STDERR, "ERROR: $mensaje\n");
    exit($codigo);
}

function longitud($v) {
    return function_exists('mb_strlen') ? mb_strlen($v, 'UTF-8') : strlen($v);
}

function validar(array $d) {
    $problemas = [];
    foreach (['first_name', 'last_name', 'username', 'email'] as $campo) {
        if (!isset($d[$campo]) || trim((string)$d[$campo]) === '') {
            $problemas[] = "falta $campo";
        }
    }
    if ($problemas) {
        return $problemas;
    }
    foreach (['first_name', 'last_name'] as $campo) {
        if (longitud($d[$campo]) > 50) {
            $problemas[] = "$campo supera 50 caracteres (confirmar contra C-5 SQL, seccion 3)";
        }
        if (preg_match('/[\x00-\x1F\x7F]/', $d[$campo])) {
            $problemas[] = "$campo contiene caracteres de control";
        }
    }
    if (!preg_match('/^[A-Za-z0-9._-]{3,50}$/', $d['username'])) {
        $problemas[] = 'username: 3 a 50 caracteres entre letras, digitos, punto, guion y guion bajo';
    }
    if (strlen($d['email']) > 100 || !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
        $problemas[] = 'email invalido o de mas de 100 caracteres';
    }
    if (isset($d['phone']) && $d['phone'] !== '' && !preg_match('/^[0-9+() -]{6,20}$/', $d['phone'])) {
        $problemas[] = 'phone: 6 a 20 caracteres entre digitos, +, parentesis, espacio y guion';
    }
    return $problemas;
}

function clave_temporal() {
    $clave = '';
    $max = strlen(ALFABETO) - 1;
    for ($i = 0; $i < LONGITUD_CLAVE; $i++) {
        $clave .= ALFABETO[random_int(0, $max)];
    }
    return $clave;
}

/** Texto como expresion SQL segura: CONVERT(UNHEX('..') USING utf8mb4). Evita cualquier problema de comillas o de inyeccion. */
function sql_texto($v) {
    return "CONVERT(UNHEX('" . strtoupper(bin2hex($v)) . "') USING utf8mb4)";
}

// ---------------- lectura de datos ----------------
$campos = ['first_name' => 'Nombre(s)', 'last_name' => 'Apellido(s)', 'username' => 'Usuario (username)', 'email' => 'Correo', 'phone' => 'Telefono (opcional)'];
$datos = [];
if (in_array('--stdin-json', $argv, true)) {
    $datos = json_decode((string)stream_get_contents(STDIN), true);
    if (!is_array($datos)) {
        error_salida('entrada JSON ilegible', 3);
    }
} else {
    fwrite(STDERR, "Alta temporal de usuario. Los datos NO se guardan en ningun archivo.\n");
    foreach ($campos as $k => $etiqueta) {
        fwrite(STDERR, "$etiqueta: ");
        $linea = fgets(STDIN);
        if ($linea === false) {
            error_salida('entrada interrumpida', 3);
        }
        $datos[$k] = trim($linea);
    }
}
foreach ($campos as $k => $_) {
    $datos[$k] = isset($datos[$k]) ? trim((string)$datos[$k]) : '';
}
$problemas = validar($datos);
if ($problemas) {
    error_salida(implode('; ', $problemas), 2);
}

// ---------------- credenciales y SQL ----------------
$clave = clave_temporal();
$hash = password_hash($clave, PASSWORD_DEFAULT);
if (!is_string($hash) || strpos($hash, '$2y$') !== 0) {
    error_salida('no se pudo generar un hash bcrypt', 3);
}
$fn = sql_texto($datos['first_name']);
$ln = sql_texto($datos['last_name']);
$un = sql_texto($datos['username']);
$em = sql_texto($datos['email']);
$ph = $datos['phone'] === '' ? 'NULL' : sql_texto($datos['phone']);

echo "-- ALTA TEMPORAL DE USUARIO (procedimiento puente UX-09A). Revisar antes de ejecutar. NO contiene la contrasena, solo su hash.\n";
echo "-- Ejecutar con la cuenta del DBA sobre cervesur_portalbi. Rol fijo 'usuario' (igual que RegisterController). Quien lo ejecuta registra la alta en el registro de altas.\n";
echo "-- Control de duplicados incluido: si el correo o el usuario ya existen no se inserta nada (filas_insertadas = 0).\n";
echo "START TRANSACTION;\n";
echo "INSERT INTO user (first_name, last_name, username, email, phone, password, role, created_at, updated_at)\n";
echo "SELECT $fn, $ln, $un, $em, $ph, '$hash', 'usuario', NOW(), NOW() FROM DUAL\n";
echo " WHERE NOT EXISTS (SELECT 1 FROM user WHERE email = $em OR username = $un);\n";
echo "SELECT ROW_COUNT() AS filas_insertadas;   -- debe ser 1; si es 0 el usuario o el correo ya existian: ROLLBACK\n";
echo "SELECT id, username, role, is_active, session_version, created_at FROM user WHERE username = $un;   -- sin hash\n";
echo "-- Si filas_insertadas = 1 y la fila es la esperada:  COMMIT;     En cualquier otro caso:  ROLLBACK;\n";

fwrite(STDERR, "\n=====================================================================\n");
fwrite(STDERR, "CONTRASENA TEMPORAL (se muestra UNA sola vez; no se guarda en ningun lugar):\n");
fwrite(STDERR, "    $clave\n");
fwrite(STDERR, "Entregarla solo por el canal seguro acordado. Despues de entregarla, limpiar la pantalla (clear) y no dejar el registro de sesion activo.\n");
fwrite(STDERR, "=====================================================================\n");
exit(0);
