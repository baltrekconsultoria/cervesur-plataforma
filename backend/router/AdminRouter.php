<?php

/**
 * Router del PILOTO ADMINISTRATIVO DE SOLO LECTURA (UX-07E.B). Solo existe en el modo «piloto_admin»; la puerta de rutas ya dejó pasar únicamente estas rutas exactas:
 *   POST /api/login · POST /api/logout · GET /api/ping · GET /api/user/profile
 *   GET /api/users · GET /api/empresas · GET /api/reportes · GET /api/reportes/{id} · GET /api/reportes/id/{id}/visor
 *   GET /api/asignacion · GET /api/empresa-usuario · GET /api/empresa-reporte-usuario
 *
 * Quién entra: SOLO un usuario existente, activo, de rol «admin» y presente en la lista de administradores autorizados de ESTA instancia (base de estado propia; se agrega por CLI,
 * nunca por HTTP). Un trabajador (rol «user») recibe la misma respuesta genérica que una contraseña incorrecta. La sesión, el bloqueo por intentos y la auditoría son los del piloto
 * de usuarios (lib/pilot_sesion.php) y se revalidan en CADA petición: quitar a un administrador de la lista, desactivarlo, cambiar su rol, su session_version o su contraseña
 * en Flutter cierra su sesión en la siguiente petición.
 *
 * Solo LECTURA: ninguna ruta escribe en la base de Flutter y ninguna de las cuentas de esta instancia tiene privilegios de escritura sobre ella (cuentas distintas, SELECT por
 * columna, sesión MySQL de solo lectura, catálogo cerrado de sentencias). Los listados nunca incluyen contraseñas ni `enlace`; el enlace solo lo entrega el visor y solo para los
 * informes autorizados para el piloto (CERVESUR_PILOT_VISOR_IDS).
 */
require_once __DIR__ . '/../lib/pilot_sesion.php';

/** Listado paginado con la forma del backend de Flutter. $clave = 'data' o 'users'. */
function adm_listado(PDO $pdo, $totalKey, $pageKey, array $enteros, $evento, array $params = [], $clave = 'data', ?array $consultas = null, $marcarVisor = false) {
    $sql = $consultas ?? admin_catalogo_consultas();
    [$page, $per, $offset] = usr_pagina();
    $t = $pdo->prepare($sql[$totalKey]);
    $i = 1; foreach ($params as $v) { $t->bindValue($i++, $v, PDO::PARAM_INT); }
    $t->execute();
    $total = (int)$t->fetchColumn();
    $st = $pdo->prepare($sql[$pageKey]);
    $i = 1; foreach ($params as $v) { $st->bindValue($i++, $v, PDO::PARAM_INT); }
    $st->bindValue($i++, $per, PDO::PARAM_INT); $st->bindValue($i, $offset, PDO::PARAM_INT);
    $st->execute();
    $rows = array_map(function ($r) use ($enteros, $marcarVisor) { foreach ($enteros as $k) { if (array_key_exists($k, $r)) { $r[$k] = (int)$r[$k]; } } return $marcarVisor ? usr_marcar_visor($r) : $r; }, $st->fetchAll(PDO::FETCH_ASSOC));
    return [$clave => $rows, 'total' => $total, 'page' => $page, 'per' => $per, 'offset' => $offset, 'evento' => $evento];
}

function adm_responder_listado(array $r, $clave = 'data') {
    $body = ['success' => true, $clave => $r[$clave], 'count' => count($r[$clave]), 'pagination' => ['page' => $r['page'], 'per_page' => $r['per'], 'total' => $r['total'], 'total_pages' => (int)ceil($r['total'] / $r['per']), 'has_more' => ($r['offset'] + $r['per']) < $r['total']]];
    usr_responder(200, $body);
}

try {
    if ($method === 'GET' && $uri === '/api/ping') {
        usr_responder(200, ['pong' => true]);
    }
    if ($method === 'POST' && $uri === '/api/login') {
        usr_login($pdoAuth, $pdoEstado);
    }
    [$u, $tokenHash] = usr_requerir_sesion($pdoAuth, $pdoEstado);
    $uid = (int)$u['id'];
    if ($method === 'POST' && $uri === '/api/logout') {
        estado_ejecutar($pdoEstado, 'sesion_revocar', ['cierre_de_sesion', $tokenHash]);
        estado_auditar($pdoEstado, 'logout', $uid);
        usr_responder(200, ['success' => true, 'message' => 'Sesión cerrada y revocada en el servidor.', 'revoked_server_side' => true]);
    }
    if ($method === 'GET' && $uri === '/api/user/profile') {
        usr_responder(200, ['success' => true, 'profile' => usr_perfil($u)]);
    }
    // Se audita la consulta de cada listado (solo la primera página, para no llenar la auditoría): quién consultó qué y cuándo; nunca el contenido.
    $audit = function ($evento) use ($pdoEstado, $uid) { if ((int)($_GET['page'] ?? 1) === 1) { estado_auditar($pdoEstado, $evento, $uid); } };
    if ($method === 'GET' && $uri === '/api/users') {
        $estado = strtolower((string)($_GET['status'] ?? 'active'));
        $rango = $estado === 'all' ? [0, 1] : ($estado === 'inactive' ? [0, 0] : [1, 1]);
        $r = adm_listado($pdoAuth, 'usuarios_total', 'usuarios_pagina', ['id', 'is_active'], 'admin_usuarios', $rango, 'users', admin_auth_consultas());
        $audit('admin_usuarios');
        adm_responder_listado($r, 'users');
    }
    $listados = [
        '/api/empresas' => ['empresas_total', 'empresas_pagina', ['id', 'activo'], 'admin_empresas'],
        '/api/reportes' => ['reportes_total', 'reportes_pagina', ['id', 'activo'], 'admin_informes'],
        '/api/asignacion' => ['relaciones_total', 'relaciones_pagina', ['id', 'empresa_id', 'reporte_id', 'activo'], 'admin_asignaciones'],
        '/api/empresa-usuario' => ['empresa_usuario_total', 'empresa_usuario_pagina', ['user_id', 'empresa_id', 'activo'], 'admin_empresa_usuario'],
        '/api/empresa-reporte-usuario' => ['accesos_total', 'accesos_pagina', ['user_id', 'empresa_id', 'empresa_reporte_id'], 'admin_accesos'],
    ];
    if ($method === 'GET' && isset($listados[$uri])) {
        [$t, $p, $ints, $ev] = $listados[$uri];
        $r = adm_listado($pdo, $t, $p, $ints, $ev, [], 'data', null, $uri === '/api/reportes');
        $audit($ev);
        adm_responder_listado($r);
    }
    if ($method === 'GET' && preg_match('~^/api/reportes/([1-9][0-9]{0,9})$~D', $uri, $m)) {
        $st = $pdo->prepare(admin_catalogo_consultas()['reporte_detalle']); $st->bindValue(1, (int)$m[1], PDO::PARAM_INT); $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) { usr_responder(404, ['success' => false, 'message' => 'Reporte no encontrado']); }
        $row['id'] = (int)$row['id']; $row['activo'] = (int)$row['activo'];
        usr_responder(200, ['success' => true, 'data' => usr_marcar_visor($row)]);
    }
    if ($method === 'GET' && preg_match('~^/api/reportes/id/([1-9][0-9]{0,9})/visor$~D', $uri, $m)) {
        $st = $pdo->prepare(admin_catalogo_consultas()['reporte_visor']); $st->bindValue(1, (int)$m[1], PDO::PARAM_INT); $st->execute();
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            estado_auditar($pdoEstado, 'visor_denegado', $uid, (int)$m[1]);
            usr_responder(404, ['success' => false, 'message' => 'Reporte no encontrado']);
        }
        if (!usr_visor_autorizado($row['id'])) {
            estado_auditar($pdoEstado, 'visor_no_autorizado_piloto', $uid, (int)$row['id']);
            usr_responder(403, ['success' => false, 'code' => 'visor_no_autorizado', 'message' => 'Este informe aún no está autorizado para el piloto']);
        }
        $link = classify_powerbi_link($row['enlace'] ?? null);
        $data = ['id' => (int)$row['id'], 'reporte_id' => $row['reporte_id'], 'nombre' => $row['nombre'], 'descripcion' => $row['descripcion'] ?? '', 'modo' => 'no_compatible'];
        if ($link['ok'] && $link['kind'] === 'public') { $data['modo'] = 'publico'; $data['url'] = trim((string)$row['enlace']); }
        elseif ($link['ok']) { $data['modo'] = 'privado'; }
        estado_auditar($pdoEstado, 'visor_abierto', $uid, (int)$row['id']); // nunca el enlace
        usr_responder(200, ['success' => true, 'data' => $data]);
    }
    usr_responder(404, ['error' => 'Not found']);
} catch (Throwable $e) {
    error_log('[cervesur] Error en el piloto administrativo: ' . get_class($e) . ' ' . (int)($e instanceof PDOException ? ($e->errorInfo[1] ?? 0) : 0));
    usr_responder(500, ['success' => false, 'message' => 'Error interno del servidor']);
}
