<?php

/**
 * Filtro de salida y control de entrada para Empresas (correctivo K2: K2a + K2c + K2d).
 * Solo actua sobre la capa HTTP: los modelos internos (p. ej. PowerBIModel) siguen leyendo la base.
 *
 * Salida (K2a): dos capas independientes.
 *  1. Exclusion: se quita, a cualquier profundidad y sin importar el rol, toda clave cuyo nombre indique una credencial.
 *  2. Lista de campos publicos (opcional): si EMPRESA_CAMPOS_PUBLICOS no esta vacia, las filas de empresa solo conservan esos campos.
 *
 * Entrada, modo EMPRESA_SECRETO_PUT para PUT /api/empresas/{id}:
 *  - rechazar      : K2d (por defecto). Un valor vacio, de solo espacios, enmascarado o null se IGNORA y los demas campos se guardan.
 *                    Cualquier otro valor es un intento explicito de cambiar la credencial: se responde con rechazo (HTTP
 *                    EMPRESA_SECRETO_RECHAZO_HTTP, JSON {success:false,message}) ANTES de tocar la base: no hay escritura parcial.
 *  - bloquear      : K2c. El secreto se descarta en silencio y el resto se guarda (la respuesta dice exito).
 *  - ignorar_vacio : K2b. Solo se descartan los valores vacios o enmascarados; un valor real SI se guarda.
 *  - permitir      : comportamiento original.
 * Un valor desconocido se trata como 'rechazar' (falla cerrado).
 *
 * Alta (K2e), modo EMPRESA_SECRETO_POST para POST /api/empresas: igual que K2d pero sobre el alta. Un valor vacio, enmascarado o null
 * se descarta (la columna queda NULL); cualquier otro se rechaza antes de crear la empresa. No depende de ninguna guardia de rol.
 *
 * Ni las respuestas ni el registro contienen nunca el valor recibido: solo identificadores numericos.
 * El cambio de credenciales se hace por el procedimiento administrativo separado y autorizado, no por este CRUD.
 */

if (!defined('EMPRESA_SECRETO_PUT')) {
    define('EMPRESA_SECRETO_PUT', 'rechazar');
}
if (!defined('EMPRESA_CAMPOS_PUBLICOS')) {
    define('EMPRESA_CAMPOS_PUBLICOS', '');
}
if (!defined('EMPRESA_SECRETO_RECHAZO_HTTP')) {
    // 4xx para que el cliente Flutter lo muestre como «Observación» y no como «Guardado» (decide por 2xx / no 2xx).
    define('EMPRESA_SECRETO_RECHAZO_HTTP', 400);
}
if (!defined('EMPRESA_SECRETO_POST')) {
    // K2e: 'rechazar' (por defecto) = el alta de una empresa no puede fijar credenciales; 'permitir' = comportamiento original
    define('EMPRESA_SECRETO_POST', 'rechazar');
}
if (!defined('EMPRESA_SECRETO_MENSAJE_ALTA')) {
    define('EMPRESA_SECRETO_MENSAJE_ALTA', 'Las credenciales de Power BI no pueden establecerse desde esta pantalla. Solicite su alta al administrador autorizado.');
}
if (!defined('EMPRESA_SECRETO_MENSAJE')) {
    define('EMPRESA_SECRETO_MENSAJE', 'Las credenciales de Power BI no pueden modificarse desde esta pantalla. Solicite el cambio al administrador autorizado.');
}

function empresa_clave_sensible($clave) {
    if (!is_string($clave)) {
        return false;
    }
    return preg_match('/(secret|passw|pwd|contrase|clave|credencial|credential|api[_-]?key|private[_-]?key|token|bearer|authorization)/i', $clave) === 1;
}

function empresa_es_fila($valor) {
    return is_array($valor) && array_key_exists('id', $valor) && array_key_exists('ruc', $valor);
}

function empresa_campos_publicos() {
    $lista = array_filter(array_map('trim', explode(',', (string)EMPRESA_CAMPOS_PUBLICOS)), 'strlen');
    return array_fill_keys($lista, true);
}

function empresa_sanitizar_respuesta($valor) {
    if (!is_array($valor)) {
        return $valor;
    }
    $publicos = empresa_campos_publicos();
    if ($publicos && empresa_es_fila($valor)) {
        $valor = array_intersect_key($valor, $publicos);
    }
    $limpio = [];
    foreach ($valor as $clave => $v) {
        if (empresa_clave_sensible($clave)) {
            continue;
        }
        $limpio[$clave] = is_array($v) ? empresa_sanitizar_respuesta($v) : $v;
    }
    return $limpio;
}

/** Texto vacio, solo espacios, enmascarado o una cadena que representa «sin valor». */
function empresa_secreto_texto_vacio($t) {
    $t = trim((string)$t);
    if ($t === '' || preg_match('/^[*\x{2022}\x{25CF}.\-_x]+$/u', $t) === 1) {
        return true;
    }
    return in_array(strtolower($t), ['null', 'undefined', 'none'], true);
}

/** Comportamiento de K2b/K2c: lo que no es texto cuenta como descartable. */
function empresa_secreto_descartable($v) {
    if (!is_string($v)) {
        return true;
    }
    return empresa_secreto_texto_vacio($v);
}

/** K2d: solo null y texto vacio o enmascarado se ignoran; cualquier otra cosa es un intento explicito. */
function empresa_secreto_sin_valor($v) {
    if ($v === null) {
        return true;
    }
    return is_string($v) && empresa_secreto_texto_vacio($v);
}

function empresa_limpiar_entrada($datos, $user_id = null, $empresa_id = null) {
    if (!is_array($datos) || !array_key_exists('powerbi_clientsecret', $datos) || EMPRESA_SECRETO_PUT === 'permitir') {
        return $datos;
    }
    $modo = EMPRESA_SECRETO_PUT;
    if ($modo !== 'bloquear' && $modo !== 'ignorar_vacio') {
        // K2d (y valor desconocido)
        if (!empresa_secreto_sin_valor($datos['powerbi_clientsecret'])) {
            error_log(sprintf('[empresa] cambio de powerbi_clientsecret rechazado (usuario=%d empresa=%d)', (int)$user_id, (int)$empresa_id));
            http_response_code((int)EMPRESA_SECRETO_RECHAZO_HTTP);
            echo json_encode(['success' => false, 'message' => EMPRESA_SECRETO_MENSAJE]);
            exit;
        }
        unset($datos['powerbi_clientsecret']);
        return $datos;
    }
    if ($modo === 'bloquear' || empresa_secreto_descartable($datos['powerbi_clientsecret'])) {
        unset($datos['powerbi_clientsecret']);
        error_log('[empresa] powerbi_clientsecret ignorado en PUT (modo ' . $modo . ')');
    }
    return $datos;
}

/** K2e: el alta de una empresa no fija credenciales por el CRUD ordinario (no altera empresas existentes). */
function empresa_limpiar_alta($datos, $user_id = null) {
    if (!is_array($datos) || !array_key_exists('powerbi_clientsecret', $datos) || EMPRESA_SECRETO_POST === 'permitir') {
        return $datos;
    }
    if (!empresa_secreto_sin_valor($datos['powerbi_clientsecret'])) {
        error_log(sprintf('[empresa] alta con powerbi_clientsecret rechazada (usuario=%d)', (int)$user_id));
        http_response_code((int)EMPRESA_SECRETO_RECHAZO_HTTP);
        echo json_encode(['success' => false, 'message' => EMPRESA_SECRETO_MENSAJE_ALTA]);
        exit;
    }
    unset($datos['powerbi_clientsecret']);
    return $datos;
}
