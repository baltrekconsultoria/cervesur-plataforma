<?php

/**
 * Router del PILOTO CON USUARIOS REALES (UX-07D.4B). Solo existe en el modo «piloto_usuarios». Toda la lógica de sesión, login y listados vive en lib/pilot_sesion.php;
 * aquí solo se decide qué rutas exactas se atienden: POST /api/login · POST /api/logout · GET /api/ping · GET /api/user/profile · GET /api/reportes · GET /api/reportes/{id}
 * · GET /api/reportes/id/{id}/visor · GET /api/empresas · GET /api/asignacion (la puerta de rutas ya dejó pasar únicamente esas).
 */
require_once __DIR__ . '/../lib/pilot_sesion.php';

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
    if ($method === 'GET' && $uri === '/api/reportes') { usr_listado($pdo, $uid, 'reportes_total', 'reportes_pagina', ['id', 'activo'], true); }
    if ($method === 'GET' && $uri === '/api/empresas') { usr_listado($pdo, $uid, 'empresas_total', 'empresas_pagina', ['id', 'activo']); }
    if ($method === 'GET' && $uri === '/api/asignacion') { usr_listado($pdo, $uid, 'relaciones_total', 'relaciones_pagina', ['id', 'empresa_id', 'reporte_id', 'activo']); }
    if ($method === 'GET' && preg_match('~^/api/reportes/([1-9][0-9]{0,9})$~D', $uri, $m)) {
        $row = usr_un_informe($pdo, 'reporte_detalle', $uid, $m[1]);
        if (!$row) { usr_responder(404, ['success' => false, 'message' => 'Reporte no encontrado']); }
        $row['id'] = (int)$row['id']; $row['activo'] = (int)$row['activo'];
        usr_responder(200, ['success' => true, 'data' => usr_marcar_visor($row)]);
    }
    if ($method === 'GET' && preg_match('~^/api/reportes/id/([1-9][0-9]{0,9})/visor$~D', $uri, $m)) {
        // El permiso se comprueba AHORA, con la cadena completa de relaciones activas; un informe ajeno es indistinguible de uno inexistente.
        $row = usr_un_informe($pdo, 'reporte_visor', $uid, $m[1]);
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
    error_log('[cervesur] Error en el piloto con usuarios: ' . get_class($e) . ' ' . (int)($e instanceof PDOException ? ($e->errorInfo[1] ?? 0) : 0));
    usr_responder(500, ['success' => false, 'message' => 'Error interno del servidor']);
}
