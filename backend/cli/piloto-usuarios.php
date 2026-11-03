<?php
/**
 * Administración del piloto con usuarios reales (UX-07D.4B). SOLO línea de comandos (nunca HTTP). Escribe únicamente en la base de ESTADO propia del piloto
 * (lista blanca, sesiones, intentos); jamás en la base de Flutter. No imprime contraseñas, hashes, tokens ni enlaces.
 *
 *   php backend/cli/piloto-usuarios.php listar                       lista blanca vigente
 *   php backend/cli/piloto-usuarios.php agregar <user_id> [nota]     autoriza a un usuario existente, activo y de rol «user» (comprueba su ficha con la cuenta de solo lectura)
 *   php backend/cli/piloto-usuarios.php quitar <user_id>             lo saca de la lista y REVOCA sus sesiones
 *   php backend/cli/piloto-usuarios.php revocar <user_id>|todos      cierra las sesiones de un usuario o de todos
 *   php backend/cli/piloto-usuarios.php sesiones                     sesiones vigentes (usuario y fechas)
 *   php backend/cli/piloto-usuarios.php desbloquear <usuario|correo> levanta el bloqueo por intentos fallidos de ese acceso
 *   php backend/cli/piloto-usuarios.php auditoria                    últimos 50 eventos
 * Lee la configuración del backend/.env del sitio (mismo modo piloto_usuarios, mismas cuentas).
 */
if (PHP_SAPI !== 'cli') {
    exit(1);
}
require __DIR__ . '/../lib/db.php'; // arranca el modo (fail-closed) y abre las tres conexiones; en CLI el estado admite además las sentencias de administración
if (!in_array(app_mode(), ['piloto_usuarios', 'piloto_admin'], true)) {
    fwrite(STDERR, "El backend no está en modo piloto_usuarios ni piloto_admin.\n");
    exit(1);
}
$rolModo = app_mode() === 'piloto_admin' ? 'admin' : 'user'; // la instancia administrativa admite SOLO administradores; la de usuarios, SOLO trabajadores
[$script, $cmd, $arg1, $arg2] = array_pad($argv, 4, null);
$fail = function ($msg) { fwrite(STDERR, "ERROR: $msg\n"); exit(1); };
$entero = function ($v) use ($fail) { if (!is_string($v) || !preg_match('/^[1-9][0-9]{0,9}$/', $v)) { $fail('Indique un user_id numérico válido.'); } return (int)$v; };

switch ($cmd) {
    case 'listar':
        foreach (estado_ejecutar($pdoEstado, 'lista_blanca_listar', [])->fetchAll(PDO::FETCH_ASSOC) as $r) { echo sprintf("%d\t%s\t%s\t%s\n", $r['user_id'], $r['activo'] ? 'activo' : 'quitado', $r['creado_en'], $r['nota'] ?? ''); }
        break;
    case 'agregar':
        $id = $entero($arg1);
        $st = $pdoAuth->prepare(usuarios_auth_consultas()['usuario_por_id']); $st->bindValue(1, $id, PDO::PARAM_INT); $st->execute();
        $u = $st->fetch(PDO::FETCH_ASSOC);
        if (!$u) { $fail('El usuario no existe.'); }
        if ((int)$u['is_active'] !== 1) { $fail('El usuario está inactivo: no se agrega.'); }
        require_once __DIR__ . '/../lib/roles.php';
        if (normalize_role($u['role'] ?? null) !== $rolModo) { $fail($rolModo === 'admin' ? 'El rol del usuario no es «admin»: esta instancia solo admite administradores.' : 'El rol del usuario no es «user»: los administradores no participan en este piloto.'); }
        estado_ejecutar($pdoEstado, 'lista_blanca_agregar', [$id, mb_substr((string)$arg2, 0, 120), 'cli']);
        estado_auditar($pdoEstado, 'lista_blanca_agregado', $id);
        echo "Agregado a la lista blanca: user_id $id\n";
        break;
    case 'quitar':
        $id = $entero($arg1);
        estado_ejecutar($pdoEstado, 'lista_blanca_quitar', [$id]);
        estado_ejecutar($pdoEstado, 'sesiones_revocar_usuario', ['quitado_de_lista_blanca', $id]);
        estado_auditar($pdoEstado, 'lista_blanca_quitado', $id);
        echo "Quitado de la lista blanca y sesiones revocadas: user_id $id\n";
        break;
    case 'revocar':
        if ($arg1 === 'todos') {
            $n = estado_ejecutar($pdoEstado, 'sesiones_revocar_todas', ['revocacion_total_cli'])->rowCount();
            estado_auditar($pdoEstado, 'revocacion_total');
        } else {
            $id = $entero($arg1);
            $n = estado_ejecutar($pdoEstado, 'sesiones_revocar_usuario', ['revocacion_cli', $id])->rowCount();
            estado_auditar($pdoEstado, 'revocacion_usuario', $id);
        }
        echo "Sesiones revocadas: $n\n";
        break;
    case 'sesiones':
        foreach (estado_ejecutar($pdoEstado, 'sesiones_activas', [])->fetchAll(PDO::FETCH_ASSOC) as $r) { echo sprintf("%d\t%s\t%s\n", $r['user_id'], $r['emitida_en'], $r['expira_en']); }
        break;
    case 'desbloquear':
        if (!is_string($arg1) || $arg1 === '') { $fail('Indique el usuario o correo.'); }
        estado_limpiar_fallos($pdoEstado, $arg1);
        estado_auditar($pdoEstado, 'desbloqueo', null, null, $arg1);
        echo "Bloqueo levantado.\n";
        break;
    case 'auditoria':
        foreach (estado_ejecutar($pdoEstado, 'auditoria_ultimos', [])->fetchAll(PDO::FETCH_ASSOC) as $r) { echo sprintf("%d\t%s\t%s\t%s\t%s\n", $r['id'], $r['ocurrido_en'], $r['evento'], $r['user_id'] ?? '-', $r['reporte_id'] ?? '-'); }
        break;
    default:
        $fail('Uso: listar | agregar <user_id> [nota] | quitar <user_id> | revocar <user_id|todos> | sesiones | desbloquear <usuario> | auditoria');
}
