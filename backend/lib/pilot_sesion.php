<?php
/**
 * Funciones COMPARTIDAS de los pilotos con credenciales reales (UX-07D.4B «piloto_usuarios» y UX-07E.B «piloto_admin»): inicio de sesión con password_verify sobre el hash
 * existente, sesiones opacas revocables, bloqueo por intentos, auditoría y listados paginados. No se ejecuta nada al incluirlo (solo define funciones y cabeceras).
 * Cada router (UsuariosRouter.php / AdminRouter.php) decide qué rutas atiende. Lo que sigue es el texto original del router de usuarios:
 */

/**
 * Router del PILOTO CON USUARIOS REALES (UX-07D.4B). Solo existe en el modo «piloto_usuarios»; la puerta de rutas ya dejó pasar únicamente estas rutas exactas:
 *   POST /api/login · POST /api/logout · GET /api/ping · GET /api/user/profile · GET /api/reportes · GET /api/reportes/{id} · GET /api/reportes/id/{id}/visor
 *   GET /api/empresas · GET /api/asignacion
 *
 * Autenticación: la contraseña del usuario se verifica con password_verify() contra su hash bcrypt EXISTENTE (cuenta de solo lectura que solo ve la tabla user). Nada se
 * copia ni se registra. Un usuario entra solo si: existe, está activo, su rol se reconoce y es «user» (los administradores no entran en este piloto), su session_version es
 * válida, y figura en la lista blanca del piloto. No se escribe en la base de Flutter: ni last_login ni session_version (las sesiones viven en la base de estado propia).
 *
 * Sesión: token opaco de 256 bits cuyo hash SHA-256 se guarda en la base de estado; caduca (1 h por defecto, máx. 8 h) y se revoca al cerrar sesión, al desactivar al
 * usuario, al cambiar su session_version o su contraseña en Flutter, al quitarlo de la lista blanca o por la CLI. Se revalida en CADA petición.
 *
 * Permisos: los catálogos filtran por la cadena completa de relaciones activas del usuario autenticado (lib/usuarios_catalogo.php). Los listados NUNCA llevan `enlace`;
 * el visor lo entrega solo tras comprobar el permiso actual y solo para los informes autorizados para el piloto (CERVESUR_PILOT_VISOR_IDS).
 */
require_once __DIR__ . '/powerbi_link.php';
require_once __DIR__ . '/roles.php';

header('Cache-Control: no-store, no-cache, must-revalidate, private');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
// HSTS solo si el operador lo pide (acceso corporativo con un nombre propio). En el acceso privado por túnel el host es «localhost»: HSTS afecta a TODOS los puertos de ese host
// y obligaría al navegador a usar HTTPS también en los túneles de los otros portales.
if (getenv('CERVESUR_PILOT_HSTS') === 'SI' && app_https_ok($_SERVER, '') && !preg_match('/^(localhost|[0-9]{1,3}(\.[0-9]{1,3}){3}|\[::1\])(:[0-9]+)?$/i', (string)($_SERVER['HTTP_HOST'] ?? ''))) {
    header('Strict-Transport-Security: max-age=31536000');
}

function usr_responder($status, array $body) {
    http_response_code($status);
    echo json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function usr_visor_autorizado($id) {
    $cfg = trim((string)getenv('CERVESUR_PILOT_VISOR_IDS'));
    return $cfg === 'TODOS' || ($cfg !== '' && in_array((string)$id, array_map('trim', explode(',', $cfg)), true));
}

/** Rol que puede entrar en este modo: el piloto de usuarios admite SOLO «user»; el piloto administrativo (UX-07E.B) admite SOLO «admin». */
function usr_rol_modo() { return in_array(app_mode(), ['piloto_admin', 'admin_escritura'], true) ? 'admin' : 'user'; }

function usr_perfil(array $u) {
    return ['id' => (string)$u['id'], 'username' => $u['username'], 'email' => $u['email'], 'first_name' => $u['first_name'], 'last_name' => $u['last_name'], 'phone' => '', 'role' => usr_rol_modo(), 'is_active' => 1];
}

/** Hash bcrypt ficticio con el mismo coste: password_verify se ejecuta SIEMPRE, exista o no el usuario, para no revelar por tiempo si la cuenta existe. */
function usr_hash_ficticio() { return '$2y$10$abcdefghijklmnopqrstuuwy5ZB4iV5ibtqGCQ1u1C0lKXm0yn7G2'; }

/** ¿El usuario cumple TODAS las condiciones de acceso? Devuelve null si sí, o el motivo (solo para auditoría; al cliente nunca se detalla). */
function usr_motivo_rechazo($u, PDO $estado) {
    if (!$u) { return 'inexistente'; }
    if ((int)$u['is_active'] !== 1) { return 'inactivo'; }
    if (normalize_role($u['role'] ?? null) !== usr_rol_modo()) { return 'rol'; }
    if (filter_var($u['session_version'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false) { return 'session_version'; }
    if (!estado_ejecutar($estado, 'lista_blanca_activa', [(int)$u['id']])->fetchColumn()) { return 'lista_blanca'; }
    return null;
}

function usr_login(PDO $auth, PDO $estado) {
    $data = json_decode((string)file_get_contents('php://input'), true);
    $login = is_array($data) && is_string($data['login'] ?? null) ? trim($data['login']) : '';
    $password = is_array($data) && is_string($data['password'] ?? null) ? $data['password'] : '';
    if ($login === '' || strlen($login) > 100 || strlen($password) > 256) {
        usleep(400000);
        usr_responder(200, ['success' => false, 'message' => 'Credenciales inválidas']);
    }
    $espera = estado_bloqueo($estado, $login);
    if ($espera > 0) {
        header('Retry-After: ' . $espera);
        estado_auditar($estado, 'login_bloqueado', null, null, $login);
        usr_responder(429, ['success' => false, 'message' => 'Demasiados intentos. Espere unos minutos.', 'code' => 'bloqueado']);
    }
    $st = $auth->prepare(usuarios_auth_consultas()['usuario_por_login']);
    $st->bindValue(1, $login); $st->bindValue(2, $login); $st->execute();
    $u = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $claveOk = password_verify($password, $u && is_string($u['password']) ? $u['password'] : usr_hash_ficticio());
    $motivo = $claveOk ? usr_motivo_rechazo($u, $estado) : 'clave';
    if ($u === null) { $motivo = $claveOk ? 'inexistente' : 'clave'; }
    if ($motivo !== null) {
        estado_registrar_fallo($estado, $login);
        estado_auditar($estado, 'login_fallido_' . $motivo, $u ? (int)$u['id'] : null, null, $login);
        usleep(400000);
        usr_responder(200, ['success' => false, 'message' => 'Credenciales inválidas']);
    }
    estado_limpiar_fallos($estado, $login);
    $segundos = (int)getenv('CERVESUR_PILOT_SESSION_SECONDS');
    $segundos = $segundos >= 300 && $segundos <= 28800 ? $segundos : 3600;
    $token = bin2hex(random_bytes(32));
    estado_ejecutar($estado, 'sesion_crear', [hash('sha256', $token), (int)$u['id'], (int)$u['session_version'], estado_huella($u['password']), $segundos, estado_huella(estado_ip())]);
    if (random_int(1, 20) === 1) { estado_ejecutar($estado, 'sesiones_purgar', []); }
    estado_auditar($estado, 'login_ok', (int)$u['id'], null, $login);
    usr_responder(200, ['success' => true, 'message' => 'Login exitoso', 'token' => $token, 'user' => usr_perfil($u)]);
}

/** Valida la sesión en CADA petición. Devuelve [fila de usuario, hash del token]. */
function usr_requerir_sesion(PDO $auth, PDO $estado) {
    $headers = getallheaders();
    $h = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    $token = preg_match('/^Bearer\s+([0-9a-f]{64})$/i', trim($h), $m) ? strtolower($m[1]) : '';
    $hash = $token !== '' ? hash('sha256', $token) : '';
    $s = $hash !== '' ? estado_ejecutar($estado, 'sesion_vigente', [$hash])->fetch(PDO::FETCH_ASSOC) : false;
    if (!$s) { usr_responder(401, ['success' => false, 'message' => 'No autorizado. Token inválido o expirado.']); }
    $st = $auth->prepare(usuarios_auth_consultas()['usuario_por_id']);
    $st->bindValue(1, (int)$s['user_id'], PDO::PARAM_INT); $st->execute();
    $u = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $motivo = usr_motivo_rechazo($u, $estado);
    if ($motivo === null && ((int)$u['session_version'] !== (int)$s['session_version'])) { $motivo = 'session_version'; }
    if ($motivo === null && !hash_equals((string)$s['huella_clave'], estado_huella($u['password']))) { $motivo = 'clave_cambiada'; }
    if ($motivo !== null) {
        estado_ejecutar($estado, 'sesion_revocar', ['automatica_' . $motivo, $hash]);
        estado_auditar($estado, 'sesion_invalidada_' . $motivo, (int)$s['user_id']);
        usr_responder(401, ['success' => false, 'message' => 'No autorizado. Token inválido o expirado.']);
    }
    return [$u, $hash];
}

function usr_pagina() {
    $page = filter_var($_GET['page'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
    $per = filter_var($_GET['per_page'] ?? USUARIOS_MAX_PER_PAGE, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => USUARIOS_MAX_PER_PAGE]]);
    if ($page === false || $per === false || !is_string($_GET['view'] ?? 'minimal') || ($_GET['view'] ?? 'minimal') !== 'minimal') {
        usr_responder(400, ['success' => false, 'message' => 'Parámetros de paginación o vista no válidos']);
    }
    return [$page, $per, ($page - 1) * $per];
}

/** Añade `visor_autorizado` (0/1) a una fila de informe: indica si el piloto entrega el enlace de ese informe. Es solo un indicador; jamás se incluye el enlace. */
function usr_marcar_visor(array $r) { $r['visor_autorizado'] = usr_visor_autorizado($r['id']) ? 1 : 0; return $r; }

function usr_listado(PDO $cat, $userId, $totalKey, $pageKey, array $enteros, $marcarVisor = false) {
    $sql = usuarios_catalogo_consultas();
    [$page, $per, $offset] = usr_pagina();
    $t = $cat->prepare($sql[$totalKey]); $t->bindValue(1, $userId, PDO::PARAM_INT); $t->execute();
    $total = (int)$t->fetchColumn();
    $st = $cat->prepare($sql[$pageKey]);
    $st->bindValue(1, $userId, PDO::PARAM_INT); $st->bindValue(2, $per, PDO::PARAM_INT); $st->bindValue(3, $offset, PDO::PARAM_INT); $st->execute();
    $rows = array_map(function ($r) use ($enteros, $marcarVisor) { foreach ($enteros as $k) { $r[$k] = (int)$r[$k]; } return $marcarVisor ? usr_marcar_visor($r) : $r; }, $st->fetchAll(PDO::FETCH_ASSOC));
    usr_responder(200, ['success' => true, 'data' => $rows, 'count' => count($rows), 'pagination' => ['page' => $page, 'per_page' => $per, 'total' => $total, 'total_pages' => (int)ceil($total / $per), 'has_more' => ($offset + $per) < $total]]);
}

/** @return array<string,mixed>|null */
function usr_un_informe(PDO $cat, $clave, $userId, $reporteId) {
    $st = $cat->prepare(usuarios_catalogo_consultas()[$clave]);
    $st->bindValue(1, $userId, PDO::PARAM_INT); $st->bindValue(2, (int)$reporteId, PDO::PARAM_INT); $st->execute();
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}
