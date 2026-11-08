<?php

/**
 * Router del PILOTO VISUAL (UX-07D.4 express). Solo existe en el modo «piloto_visual»; la puerta de rutas ya dejó pasar únicamente estas rutas exactas:
 *   POST /api/login · POST /api/logout · GET /api/user/profile · GET /api/reportes · GET /api/reportes/{id} · GET /api/reportes/id/{id}/visor
 *   GET /api/empresas · GET /api/asignacion
 * Un único OPERADOR AUTORIZADO se autentica con una contraseña propia (hash bcrypt en el .env del sitio): no se usa ni se consulta la tabla `user`, no se
 * simula la identidad de ningún usuario productivo y no se usan contraseñas de empleados. La sesión es un JWT firmado con un secreto exclusivo y de corta duración;
 * no hay estado en MySQL (cerrar sesión borra el token del navegador; revocar todas las sesiones = rotar el secreto, ver scripts/operador.sh).
 *
 * Datos: solo las consultas de lib/piloto_catalogo.php (catálogo cerrado, columnas mínimas). Los listados NUNCA llevan `enlace`; el visor solo lo entrega para
 * los informes que el operador autorizó expresamente (CERVESUR_PILOT_VISOR_IDS) y solo si es un enlace «Publicar en web».
 */
require_once __DIR__ . '/../lib/jwt.php';
require_once __DIR__ . '/../lib/powerbi_link.php';
require_once __DIR__ . '/../lib/piloto_catalogo.php';

header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

function piloto_responder($status, array $body) {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function piloto_usuario_operador() {
    $u = (string)getenv('CERVESUR_PILOT_OPERATOR_USER');
    return $u !== '' ? $u : 'operador';
}

function piloto_perfil() {
    return ['id' => 'operador', 'username' => piloto_usuario_operador(), 'email' => 'operador@piloto.invalid', 'first_name' => 'Operador', 'last_name' => 'autorizado', 'phone' => '', 'role' => 'user', 'is_active' => 1];
}

/** Ids de informes cuyo enlace puede entregar el visor. '' = ninguno · 'TODOS' = todos los activos · lista de ids. */
function piloto_visor_autorizado($id) {
    $cfg = trim((string)getenv('CERVESUR_PILOT_VISOR_IDS'));
    if ($cfg === 'TODOS') {
        return true;
    }
    return $cfg !== '' && in_array((string)$id, explode(',', $cfg), true);
}

// ---------------------------------------------------------------- limitación de intentos (archivo propio; nunca MySQL)
function piloto_estado_archivo() { return rtrim((string)getenv('CERVESUR_PILOT_STATE_DIR'), '/') . '/login-fallos.json'; }

/** @return array{count:int,first:int,locked:int} */
function piloto_fallos_leer() {
    $raw = @file_get_contents(piloto_estado_archivo());
    $d = is_string($raw) ? json_decode($raw, true) : null;
    return is_array($d) ? ['count' => (int)($d['count'] ?? 0), 'first' => (int)($d['first'] ?? 0), 'locked' => (int)($d['locked'] ?? 0)] : ['count' => 0, 'first' => 0, 'locked' => 0];
}
function piloto_fallos_guardar(array $d) {
    $h = @fopen(piloto_estado_archivo(), 'c');
    if ($h && flock($h, LOCK_EX)) {
        ftruncate($h, 0);
        fwrite($h, json_encode($d));
        fflush($h);
        flock($h, LOCK_UN);
    }
    if ($h) {
        fclose($h);
    }
}
const PILOTO_MAX_FALLOS = 5;
const PILOTO_VENTANA = 900;
const PILOTO_BLOQUEO = 900;

function piloto_login() {
    $now = time();
    $f = piloto_fallos_leer();
    if ($f['locked'] > $now) {
        header('Retry-After: ' . ($f['locked'] - $now));
        piloto_responder(429, ['success' => false, 'message' => 'Demasiados intentos. Espere unos minutos.', 'code' => 'bloqueado']);
    }
    $data = json_decode((string)file_get_contents('php://input'), true);
    $login = is_array($data) && is_string($data['login'] ?? null) ? $data['login'] : '';
    $password = is_array($data) && is_string($data['password'] ?? null) ? $data['password'] : '';
    // Siempre se ejecuta password_verify (también si el usuario no coincide) para no revelar por tiempo si el nombre existe.
    $hash = (string)getenv('CERVESUR_PILOT_OPERATOR_HASH');
    $claveOk = strlen($password) <= 256 && password_verify($password, $hash);
    $ok = $claveOk && hash_equals(piloto_usuario_operador(), $login);
    if (!$ok) {
        if ($f['first'] === 0 || $now - $f['first'] > PILOTO_VENTANA) {
            $f = ['count' => 0, 'first' => $now, 'locked' => 0];
        }
        $f['count']++;
        if ($f['count'] >= PILOTO_MAX_FALLOS) {
            $f['locked'] = $now + PILOTO_BLOQUEO;
        }
        piloto_fallos_guardar($f);
        usleep(500000);
        error_log('[cervesur] Piloto: inicio de sesión rechazado');
        piloto_responder(200, ['success' => false, 'message' => 'Credenciales inválidas']);
    }
    piloto_fallos_guardar(['count' => 0, 'first' => 0, 'locked' => 0]);
    $seconds = (int)getenv('CERVESUR_PILOT_SESSION_SECONDS');
    $seconds = $seconds >= 300 && $seconds <= 28800 ? $seconds : 3600;
    $perfil = piloto_perfil();
    $token = generate_jwt(['user_id' => 1, 'username' => $perfil['username'], 'email' => $perfil['email'], 'role' => 'operador', 'session_version' => 1, 'pilot' => 'visual', 'exp' => $now + $seconds], (string)getenv('JWT_SECRET'));
    error_log('[cervesur] Piloto: sesión de operador iniciada');
    piloto_responder(200, ['success' => true, 'message' => 'Login exitoso', 'token' => $token, 'user' => $perfil]);
}

function piloto_requerir_operador() {
    $headers = getallheaders();
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    $payload = preg_match('/Bearer\s+(.*)$/i', $auth, $m) ? validate_jwt($m[1], (string)getenv('JWT_SECRET')) : false;
    if (!$payload || ($payload['pilot'] ?? null) !== 'visual' || !hash_equals(piloto_usuario_operador(), (string)$payload['username'])) {
        piloto_responder(401, ['success' => false, 'message' => 'No autorizado. Token inválido o expirado.']);
    }
    return $payload;
}

function piloto_pagina() {
    $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
    $per = filter_var($_GET['per_page'] ?? PILOTO_MAX_PER_PAGE, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => PILOTO_MAX_PER_PAGE]]);
    if ($page === false || $per === false || !is_string($_GET['view'] ?? 'minimal') || ($_GET['view'] ?? 'minimal') !== 'minimal') {
        piloto_responder(400, ['success' => false, 'message' => 'Parámetros de paginación o vista no válidos']);
    }
    return [$page, $per, ($page - 1) * $per];
}

function piloto_listado(PDO $pdo, $totalKey, $pageKey, array $enteros) {
    $sql = piloto_catalogo_consultas();
    [$page, $per, $offset] = piloto_pagina();
    $total = (int)$pdo->query($sql[$totalKey])->fetchColumn();
    $st = $pdo->prepare($sql[$pageKey]);
    $st->bindValue(1, $per, PDO::PARAM_INT);
    $st->bindValue(2, $offset, PDO::PARAM_INT);
    $st->execute();
    $rows = array_map(function ($r) use ($enteros) {
        foreach ($enteros as $k) {
            $r[$k] = (int)$r[$k];
        }
        return $r;
    }, $st->fetchAll(PDO::FETCH_ASSOC));
    piloto_responder(200, ['success' => true, 'data' => $rows, 'count' => count($rows), 'pagination' => ['page' => $page, 'per_page' => $per, 'total' => $total, 'total_pages' => (int)ceil($total / $per), 'has_more' => ($offset + $per) < $total]]);
}

try {
    if ($method === 'POST' && $uri === '/api/login') {
        piloto_login();
    }
    if ($method === 'POST' && $uri === '/api/logout') {
        piloto_requerir_operador();
        piloto_responder(200, ['success' => true, 'message' => 'Sesión cerrada en el navegador. El token caduca por sí solo.']);
    }
    if ($method === 'GET' && $uri === '/api/ping') {
        piloto_responder(200, ['pong' => true]);
    }
    piloto_requerir_operador();
    $sql = piloto_catalogo_consultas();
    if ($method === 'GET' && $uri === '/api/user/profile') {
        piloto_responder(200, ['success' => true, 'profile' => piloto_perfil()]);
    }
    if ($method === 'GET' && $uri === '/api/reportes') {
        piloto_listado($pdo, 'reportes_total', 'reportes_pagina', ['id', 'activo']); // reporte_id es la referencia histórica (texto)
    }
    if ($method === 'GET' && $uri === '/api/empresas') {
        piloto_listado($pdo, 'empresas_total', 'empresas_pagina', ['id', 'activo']);
    }
    if ($method === 'GET' && $uri === '/api/asignacion') {
        piloto_listado($pdo, 'relaciones_total', 'relaciones_pagina', ['id', 'empresa_id', 'reporte_id', 'activo']); // aquí reporte_id es el id NUMÉRICO del informe
    }
    if ($method === 'GET' && preg_match('~^/api/reportes/([1-9][0-9]{0,9})$~D', $uri, $m)) {
        $st = $pdo->prepare($sql['reporte_detalle']);
        $st->execute([$m[1]]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            piloto_responder(404, ['success' => false, 'message' => 'Reporte no encontrado']);
        }
        $row['id'] = (int)$row['id'];
        $row['activo'] = (int)$row['activo'];
        piloto_responder(200, ['success' => true, 'data' => $row]);
    }
    if ($method === 'GET' && preg_match('~^/api/reportes/id/([1-9][0-9]{0,9})/visor$~D', $uri, $m)) {
        $st = $pdo->prepare($sql['reporte_visor']);
        $st->execute([$m[1]]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            piloto_responder(404, ['success' => false, 'message' => 'Reporte no encontrado']);
        }
        if (!piloto_visor_autorizado($row['id'])) {
            error_log('[cervesur] Piloto: visor denegado (informe no autorizado) id=' . (int)$row['id']);
            piloto_responder(403, ['success' => false, 'code' => 'visor_no_autorizado', 'message' => 'Este informe aún no está autorizado para el piloto']);
        }
        $link = classify_powerbi_link($row['enlace'] ?? null);
        $data = ['id' => (int)$row['id'], 'reporte_id' => $row['reporte_id'], 'nombre' => $row['nombre'], 'descripcion' => $row['descripcion'] ?? '', 'modo' => 'no_compatible'];
        if ($link['ok'] && $link['kind'] === 'public') {
            $data['modo'] = 'publico';
            $data['url'] = trim((string)$row['enlace']);
        } elseif ($link['ok']) {
            $data['modo'] = 'privado';
        }
        error_log('[cervesur] Piloto: visor abierto id=' . (int)$row['id'] . ' modo=' . $data['modo']); // nunca el enlace
        piloto_responder(200, ['success' => true, 'data' => $data]);
    }
    piloto_responder(404, ['error' => 'Not found']);
} catch (Throwable $e) {
    error_log('[cervesur] Error en el piloto: ' . get_class($e));
    piloto_responder(500, ['success' => false, 'message' => 'Error interno del servidor']);
}
