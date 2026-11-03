<?php
// Clasifica el resultado de GET /api/diagnostico/conciliacion (UX-07D.3B). No accede a ninguna base ni modifica nada: solo interpreta el JSON agregado.
// Uso: php conciliar.php <código-http> <archivo-con-la-respuesta> [--json]
// Categorías:
//   OPERATIVO    cambio operativo esperado: Flutter sigue operativo (altas, bajas, asignaciones). Confirmar con el equipo funcional; NO corregir.
//   ESTRUCTURAL  diferencia estructural: la forma de los datos no es la del inventario (p. ej. TODAS las medidas difieren: otra base o esquema).
//   PERMISOS     diferencia de permisos: el endpoint rechaza (401/403) o la cuenta no puede ejecutar una consulta (ejecutar preflight-acceso.sh).
//   CONSULTA     error de consulta o de servicio (HTTP distinto de 200, JSON inválido).
//   SEGURIDAD    riesgo de seguridad: la sesión no es de solo lectura, la cuenta puede escribir, o anomalías de acceso/roles/administradores.
[$script, $code, $file, $flag] = $argv + [null, '000', '', ''];
$raw = is_file($file) ? (string)file_get_contents($file) : '';
$data = json_decode($raw, true);
$salida = ['http' => (int)$code, 'coincide' => null, 'diferencias' => [], 'categorias' => []];

function terminar(array $salida, $json, $estado) {
    if ($json) {
        echo json_encode($salida, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), "\n";
    } else {
        echo "== Conciliación con la línea base del inventario (solo agregados)\n";
        if ($salida['coincide'] === true && !$salida['diferencias']) { echo "COINCIDE  las medidas coinciden con la línea base.\n"; }
        foreach ($salida['diferencias'] as $d) { echo sprintf("DIFERENCIA  %-48s %-12s %s\n", $d['metrica'], $d['categoria'], $d['accion']); }
        foreach ($salida['categorias'] as $c => $n) { echo "RESUMEN  $c: $n\n"; }
        if (!empty($salida['mensaje'])) { echo $salida['mensaje'], "\n"; }
        echo "NOTA  La línea base es histórica. Ninguna diferencia se corrige automáticamente ni se asume corrupción.\n";
    }
    exit($estado);
}

if ((int)$code !== 200 || !is_array($data) || ($data['success'] ?? false) !== true) {
    $categoria = in_array((int)$code, [401, 403], true) ? 'PERMISOS' : 'CONSULTA';
    $salida['categorias'][$categoria] = 1;
    $salida['mensaje'] = "ERROR  la conciliación no se pudo obtener (HTTP $code). Categoría: $categoria. Revise el log de Apache y ejecute preflight-acceso.sh.";
    terminar($salida, $flag === '--json', 11);
}

$salida['coincide'] = ($data['coincide'] ?? false) === true;
$controles = $data['controles'] ?? [];
if (($controles['sesion_de_base_de_datos_en_solo_lectura'] ?? false) !== true || ($controles['cuenta_de_base_de_datos_sin_privilegios_de_escritura'] ?? false) !== true || ($controles['modo'] ?? '') !== 'diagnostico') {
    $salida['categorias']['SEGURIDAD'] = ($salida['categorias']['SEGURIDAD'] ?? 0) + 1;
    $salida['diferencias'][] = ['metrica' => 'controles de solo lectura', 'categoria' => 'SEGURIDAD', 'accion' => 'DETENER el uso del diagnóstico y revisar la cuenta y la sesión (revertir.sh y 02/04)'];
}
$detalle = $data['detalle'] ?? [];
$desviadas = array_values(array_filter($detalle, fn ($r) => ($r['ok'] ?? true) !== true));
$seguridad = ['usuarios_activos_con_session_version_invalida', 'usuarios_activos_con_rol_no_reconocido', 'asignaciones_activas_de_usuarios_inactivos', 'usuarios_rol_admin', 'usuarios_rol_admin_activos'];
$todas = count($detalle) > 0 && count($desviadas) === count($detalle);
foreach ($desviadas as $r) {
    $m = (string)($r['metrica'] ?? '?');
    if ($todas) { $cat = 'ESTRUCTURAL'; $acc = 'todas las medidas difieren: ¿otra base o esquema? Ejecutar la comparación estructural de solo lectura'; }
    elseif (in_array($m, $seguridad, true)) { $cat = 'SEGURIDAD'; $acc = 'revisar con el responsable de seguridad (accesos de inactivos, roles o administradores); no corregir desde aquí'; }
    else { $cat = 'OPERATIVO'; $acc = 'cambio probable por la operación de Flutter; confirmar con el equipo funcional'; }
    if (isset($r['esperado'], $r['observado'])) { $acc .= sprintf(' (línea base %d, ahora %d)', $r['esperado'], $r['observado']); }
    $salida['diferencias'][] = ['metrica' => $m, 'categoria' => $cat, 'accion' => $acc];
    $salida['categorias'][$cat] = ($salida['categorias'][$cat] ?? 0) + 1;
}
terminar($salida, $flag === '--json', $salida['diferencias'] ? 10 : 0);
