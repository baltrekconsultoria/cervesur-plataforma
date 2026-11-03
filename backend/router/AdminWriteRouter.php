<?php

/**
 * Router de «admin_escritura» (UX-08H · fase 2). Solo existe en ese modo; la puerta de rutas (admin_write_routes) ya dejó pasar únicamente rutas EXACTAS y comprobó que la
 * capacidad de escritura de cada una (ADMIN_WRITES_EMPRESAS | REPORTES | ACCESOS | USUARIOS) esté encendida en el servidor.
 *
 * Qué hace, en este orden:
 *   1) ping, inicio y cierre de sesión del piloto (sesión opaca, bloqueo por intentos, auditoría del piloto), igual que «piloto_admin»;
 *   2) exige una sesión válida en TODA otra ruta: administrador activo, de rol «admin», en la lista blanca de la instancia, con la misma session_version y contraseña;
 *   3) fija la identidad del actor (ADM_WRITE_USER) para los routers de UX-08, que ya no aceptan JWT en este modo (ver authenticate_user);
 *   4) entrega el enlace de Power BI SOLO por el visor y con la MISMA política del piloto (CERVESUR_PILOT_VISOR_IDS, informe activo, auditoría). Ninguna otra respuesta lo lleva;
 *   5) delega en los routers de UX-08 (empresas, informes, accesos, usuarios, historial), que ya hacen cada escritura con transacción + auditoría en la misma transacción.
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
    // Identidad para los routers de UX-08 (rol siempre «admin»: usr_requerir_sesion rechazó a cualquier otro).
    $GLOBALS['ADM_WRITE_USER'] = ['user_id' => $uid, 'username' => (string)$u['username'], 'email' => (string)$u['email'], 'role' => 'admin'];

    if ($method === 'GET' && $uri === '/api/admin/capacidades') {
        usr_responder(200, ['success' => true, 'data' => ['writes' => admin_write_capabilities(), 'users_sensitive' => admin_write_capability_on('usuarios_sensible'), 'reactivate_after_hours' => admin_user_reactivate_hours(), 'audit' => true, 'mode' => 'admin_escritura']]);
    }

    // Visor: misma política que «piloto_admin» (lista del servidor + informe ACTIVO + auditoría; nunca el enlace en otra ruta).
    if ($method === 'GET' && preg_match('~^/api/reportes/id/([1-9][0-9]{0,9})/visor$~D', $uri, $m)) {
        $st = $pdo->prepare('SELECT id, reporte_id, nombre, descripcion, enlace FROM reporte WHERE id = ? AND activo = 1');
        $st->bindValue(1, (int)$m[1], PDO::PARAM_INT);
        $st->execute();
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

    // Routers de UX-08 (cada uno vuelve a comprobar el rol y aplica sus propias validaciones; las escrituras son transaccionales y auditadas).
    require_once __DIR__ . '/UserRouter.php';
    require_once __DIR__ . '/ReporteRouter.php';
    require_once __DIR__ . '/EmpresaRouter.php';
    require_once __DIR__ . '/EmpresaUsuarioRouter.php';
    require_once __DIR__ . '/EmpresaReporteUsuarioRouter.php';
    require_once __DIR__ . '/AsignacionRouter.php';
    require_once __DIR__ . '/AuditoriaRouter.php';
    if ($method === 'POST' && $uri === '/api/register') {
        require_once __DIR__ . '/../lib/register_handler.php';
        handle_register_request($pdo, $GLOBALS['ADM_WRITE_USER']);
    }
    usr_responder(404, ['error' => 'Not found']);
} catch (Throwable $e) {
    error_log('[cervesur] Error en admin_escritura: ' . get_class($e) . ' ' . (int)($e instanceof PDOException ? ($e->errorInfo[1] ?? 0) : 0));
    usr_responder(500, ['success' => false, 'message' => 'Error interno del servidor']);
}
