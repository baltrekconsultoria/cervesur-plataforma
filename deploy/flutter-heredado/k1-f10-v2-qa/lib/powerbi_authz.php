<?php

/**
 * Autenticacion y autorizacion por reporte para GET /api/powerbi/tokens/{id} (correctivo K1).
 *
 * El {id} de la ruta es empresa_reporte.id. Un usuario no administrador solo puede pedir el token de una
 * asignacion que tenga activa en user_empresa_reporte. No lee ni registra credenciales ni tokens.
 *
 * POWERBI_AUTH_MODE (transicion compatible con el cliente Flutter):
 *  - observar : T0/T2. Comportamiento sin cambios; solo registra en el log si la peticion trae Authorization.
 *  - mixto    : T2b. Con Authorization: se exige token valido y asignacion (o rol admin). Sin Authorization: se
 *               sirve igual (cliente antiguo) y se registra.
 *  - exigir   : T3. Siempre se exige token valido y asignacion (o rol admin).
 *  - retirar  : la ruta deja de existir para todos: responde 410 «Recurso retirado» ANTES de consultar la base o llamar a Azure
 *               (no se emite ningun token, con o sin Authorization). Solo registra la llamada (sin secretos) para comprobar que
 *               nadie la sigue usando. Se aplica cuando se haya verificado que no hay consumidores legitimos.
 * Volver atras = cambiar la constante (o definirla antes de incluir este archivo); no hace falta tocar el router.
 */

if (!defined('POWERBI_AUTH_MODE')) {
    define('POWERBI_AUTH_MODE', 'observar');
}

function powerbi_tiene_authorization() {
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $k) {
        if (!empty($_SERVER[$k])) {
            return true;
        }
    }
    $cabeceras = [];
    if (function_exists('getallheaders')) {
        $cabeceras = getallheaders() ?: [];
    } elseif (function_exists('apache_request_headers')) {
        $cabeceras = apache_request_headers() ?: [];
    }
    foreach ($cabeceras as $nombre => $valor) {
        if (strtolower((string)$nombre) === 'authorization' && $valor !== '') {
            return true;
        }
    }
    return false;
}

function powerbi_registrar($resultado, $er_id, $user_id = null) {
    error_log(sprintf('[powerbi] modo=%s er_id=%d usuario=%s authorization=%s resultado=%s',
        POWERBI_AUTH_MODE, $er_id, $user_id === null ? '-' : (int)$user_id,
        powerbi_tiene_authorization() ? 'si' : 'no', $resultado));
}

function powerbi_usuario_asignado($pdo, $user_id, $er_id) {
    $st = $pdo->prepare('SELECT 1 FROM user_empresa_reporte WHERE user_id = :u AND empresa_reporte_id = :er AND activo = 1');
    $st->execute(['u' => $user_id, 'er' => $er_id]);
    return $st->fetchColumn() !== false;
}

function powerbi_denegar($codigo, $mensaje) {
    http_response_code($codigo);
    echo json_encode(['success' => false, 'message' => $mensaje]);
    exit;
}

/** Devuelve null (sin autenticar, modos observar/mixto) o los datos del usuario autenticado y autorizado. */
function powerbi_autenticar_y_autorizar($pdo, $er_id) {
    $modo = POWERBI_AUTH_MODE;
    if ($modo !== 'observar' && $modo !== 'mixto' && $modo !== 'exigir' && $modo !== 'retirar') {
        $modo = 'exigir'; // valor desconocido: falla cerrado
    }
    if ($modo === 'retirar') {
        powerbi_registrar('retirado', $er_id);
        powerbi_denegar(410, 'Recurso retirado');
    }
    if ($modo === 'observar') {
        powerbi_registrar('observado', $er_id);
        return null;
    }
    if ($modo === 'mixto' && !powerbi_tiene_authorization()) {
        powerbi_registrar('legado_sin_token', $er_id);
        return null;
    }
    $usuario = require_auth(get_jwt_secret_or_fail('PowerBIRouter')); // 401 si el token no es valido
    $uid = (int)$usuario['user_id'];
    if (strtolower((string)$usuario['role']) === 'admin' || powerbi_usuario_asignado($pdo, $uid, $er_id)) {
        powerbi_registrar('autorizado', $er_id, $uid);
        return $usuario;
    }
    powerbi_registrar('denegado', $er_id, $uid);
    powerbi_denegar(403, 'Acceso denegado');
}
