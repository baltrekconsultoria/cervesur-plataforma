<?php

/**
 * Router de diagnósticos agregados (UX-07D.3A). Solo se incluye en el modo «diagnostico» y solo atiende:
 *   GET /api/diagnostico/resumen        recuentos y controles de ausencia de escritura
 *   GET /api/diagnostico/conciliacion   comparación con el inventario real (solo números)
 * Autenticación: token de diagnóstico propio (cabecera X-Diagnostic-Token), distinto del JWT_SECRET. No hay inicio de sesión de usuarios.
 */
require_once __DIR__ . '/../controllers/DiagnosticoController.php';

if (in_array($uri, ['/api/diagnostico/resumen', '/api/diagnostico/conciliacion'], true)) {
    header('Cache-Control: no-store, no-cache, must-revalidate, private');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');

    $expectedToken = (string)getenv('CERVESUR_DIAG_TOKEN');
    $givenToken = (string)($_SERVER['HTTP_X_DIAGNOSTIC_TOKEN'] ?? '');
    if ($expectedToken === '' || !hash_equals($expectedToken, $givenToken)) {
        usleep(300000); // frena la adivinación del token (el token tiene 32+ caracteres; esto solo encarece cada intento)
        error_log('[cervesur] Diagnóstico: token rechazado');
        http_response_code(401);
        echo json_encode(['success' => false, 'message' => 'No autorizado']);
        exit;
    }

    try {
        $controller = new DiagnosticoController($pdo);
        echo json_encode($uri === '/api/diagnostico/resumen' ? $controller->resumen() : $controller->conciliacion());
    } catch (Throwable $e) {
        error_log('[cervesur] Error en diagnóstico: ' . get_class($e));
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Error interno del servidor']);
    }
    exit;
}
