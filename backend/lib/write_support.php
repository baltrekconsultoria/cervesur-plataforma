<?php
/**
 * Capa común de escrituras administrativas (UX-08B).
 *
 *  - Transacciones explícitas (`db_tx`): commit si todo sale bien, rollback ante cualquier excepción.
 *  - Conflictos tipados (`WriteConflict`) en lugar de errores SQL genéricos; el 1062 de un UNIQUE se traduce a «duplicado».
 *  - Bloqueo optimista sin cambiar el esquema: `row_version()` es una huella de los campos editables. El cliente la recibe en
 *    `view=form` y la devuelve como `version`; si la fila cambió mientras tanto, la escritura se rechaza con 409.
 *  - Auditoría (`audit_record`) en la MISMA transacción que el cambio: si falla, el cambio se revierte.
 *  - Contrato de estados HTTP versionado: sin la cabecera `X-API-Contract: 2` los errores conservan el formato heredado
 *    (HTTP 200 + `success:false`) para no alterar a Flutter; con ella usan 400/404/409/500.
 *
 * Nunca se devuelve al cliente texto de SQL ni de excepciones internas.
 */

/** Campos que forman la huella de versión de cada formulario administrativo (una sola definición para vista y modelo). Nunca incluye `powerbi_*`. */
const FORM_VERSION_FIELDS = [
    'empresa_form' => ['nombre', 'razon_social', 'ruc', 'email', 'telefono', 'website', 'direccion', 'ciudad', 'pais', 'contacto_principal', 'activo'],
    'reporte_form' => ['nombre', 'descripcion', 'enlace', 'grupo_id', 'reporte_id', 'activo'],
    // Usuarios (UX-08A.users): nunca incluye contraseña ni session_version.
    'user_form' => ['first_name', 'last_name', 'phone', 'email', 'role', 'is_active'],
];

/** Interruptor de las escrituras administrativas de usuarios: solo con USER_ADMIN_WRITES exactamente `1` (por defecto, apagado). */
function user_admin_writes_enabled() {
    return getenv('USER_ADMIN_WRITES') === '1';
}

/** Conflicto de negocio o de concurrencia. `kind`: duplicate | stale | state. */
class WriteConflict extends RuntimeException {
    public $kind;
    /** Mensaje redactado en el código para el usuario. Es lo único que puede llegar a una respuesta HTTP (nunca el mensaje interno de la excepción). */
    public $clientMessage;
    public function __construct($kind, $message) {
        parent::__construct($message);
        $this->kind = $kind;
        $this->clientMessage = $message;
    }
}

/** Recurso inexistente. */
class WriteNotFound extends RuntimeException {
    /** Mensaje redactado en el código para el usuario (ver WriteConflict). */
    public $clientMessage;
    public function __construct($message) {
        parent::__construct($message);
        $this->clientMessage = $message;
    }
}

/** ¿El cliente pidió el contrato 2 (códigos HTTP estrictos)? */
function strict_contract() {
    return isset($_SERVER['HTTP_X_API_CONTRACT']) && (int)$_SERVER['HTTP_X_API_CONTRACT'] >= 2;
}

/** Identificador de correlación de la petición (también en la cabecera de respuesta y en cada fila de auditoría). */
function request_correlation_id() {
    static $id = null;
    if ($id === null) {
        $id = bin2hex(random_bytes(16));
    }
    return $id;
}

/**
 * Ejecuta `$fn` dentro de una transacción. Si ya hay una abierta, la reutiliza (no anida).
 * Las excepciones (WriteConflict, WriteNotFound, PDOException…) revierten y se vuelven a lanzar.
 */
function db_tx(PDO $pdo, callable $fn) {
    if ($pdo->inTransaction()) {
        return $fn();
    }
    $pdo->beginTransaction();
    try {
        $result = $fn();
        $pdo->commit();
        return $result;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/** ¿Es el error de «clave duplicada» (1062) de MySQL? */
function is_duplicate_key(PDOException $e) {
    return isset($e->errorInfo[1]) && (int)$e->errorInfo[1] === 1062;
}

/** Huella (16 hex) del estado editable de una fila. Cambia si cambia cualquiera de los campos. */
function row_version(array $row, array $fields) {
    $values = [];
    foreach ($fields as $field) {
        $values[$field] = array_key_exists($field, $row) && $row[$field] !== null ? (string)$row[$field] : null;
    }
    return substr(hash('sha256', json_encode($values, JSON_UNESCAPED_UNICODE)), 0, 16);
}

/** Si el cliente envió `version`, debe coincidir con la fila bloqueada; si no, 409. Sin `version` no se exige (contrato heredado). */
function assert_version($expected, array $lockedRow, array $fields) {
    if ($expected === null || $expected === '') {
        return;
    }
    if (!is_string($expected) || !hash_equals(row_version($lockedRow, $fields), $expected)) {
        throw new WriteConflict('stale', 'El registro cambió mientras lo editaba. Recargue e intente de nuevo.');
    }
}

/* ------------------------------------------------------------------ auditoría */

/** Entidades de relación cuyos identificadores se guardan como enteros en la bitácora. */
const AUDIT_INT_ENTITIES = ['empresa_usuario' => true, 'empresa_reporte' => true, 'user_empresa_reporte' => true];

/** `required`: toda escritura exige poder registrar la auditoría (falla cerrada). `off` (por defecto): no se registra. */
function audit_mode() {
    $mode = getenv('AUDIT_LOG_MODE');
    return $mode === 'required' ? 'required' : 'off';
}

/**
 * Instantánea PERMITIDA de una fila. Lista blanca por entidad: nunca contraseñas, tokens, `powerbi_*` ni el enlace en claro
 * (de este solo se guarda un resumen y su tipo, porque un enlace «Publicar en la web» es en sí mismo el acceso al informe).
 */
function audit_snapshot($entity, $row) {
    if (!is_array($row)) {
        return null;
    }
    if ($entity === 'user') {
        // Usuarios: solo `role` e `is_active` como valores. Los datos personales NO se guardan: el correo solo como huella.
        // Nunca contraseña, hash ni session_version.
        $out = [];
        if (array_key_exists('role', $row)) {
            $out['role'] = $row['role'] === null ? null : (string)$row['role'];
        }
        if (array_key_exists('is_active', $row)) {
            $out['is_active'] = (int)$row['is_active'];
        }
        if (array_key_exists('email', $row)) {
            $out['email_sha256_12'] = $row['email'] === null ? null : substr(hash('sha256', strtolower((string)$row['email'])), 0, 12);
        }
        return $out;
    }
    $allowed = [
        'empresa' => ['nombre', 'razon_social', 'ruc', 'email', 'telefono', 'website', 'direccion', 'ciudad', 'pais', 'contacto_principal', 'activo'],
        'reporte' => ['nombre', 'descripcion', 'grupo_id', 'reporte_id', 'activo'],
        // Relaciones de acceso (UX-08A): solo identificadores y estado; nunca notas ni datos de personas.
        'empresa_usuario' => ['user_id', 'empresa_id', 'activo'],
        'empresa_reporte' => ['empresa_id', 'reporte_id', 'activo'],
        'user_empresa_reporte' => ['user_id', 'empresa_reporte_id', 'activo'],
    ][$entity] ?? [];
    $out = [];
    foreach ($allowed as $field) {
        if (array_key_exists($field, $row)) {
            $out[$field] = $row[$field] === null ? null
                : (is_numeric($row[$field]) && ($field === 'activo' || substr($field, -3) === '_id' && isset(AUDIT_INT_ENTITIES[$entity])) ? (int)$row[$field] : (string)$row[$field]);
        }
    }
    if ($entity === 'reporte' && array_key_exists('enlace', $row)) {
        $out['enlace_sha256_12'] = $row['enlace'] === null ? null : substr(hash('sha256', (string)$row['enlace']), 0, 12);
    }
    return $out;
}

/** Campos que cambiaron entre dos instantáneas (solo claves; sirve para dejar rastro de cambios en `powerbi_*` sin guardar valores). */
function audit_changed_keys($before, $after) {
    $keys = array_unique(array_merge(array_keys((array)$before), array_keys((array)$after)));
    return array_values(array_filter($keys, function ($k) use ($before, $after) {
        return ($before[$k] ?? null) !== ($after[$k] ?? null);
    }));
}

/**
 * Registra una fila de auditoría. Debe llamarse DENTRO de la transacción del cambio.
 * Con AUDIT_LOG_MODE=required, si la tabla no existe o el INSERT falla, la excepción propaga y el cambio se revierte.
 */
function audit_record(PDO $pdo, $actorId, $action, $entity, $entityId, $before, $after, array $extra = []) {
    if (audit_mode() !== 'required') {
        return;
    }
    $after = $after === null ? null : $after + $extra;
    $stmt = $pdo->prepare('INSERT INTO audit_log (correlation_id, actor_user_id, action, entity, entity_id, before_json, after_json, request_ip)'
        . ' VALUES (:c, :a, :ac, :e, :i, :b, :af, :ip)');
    $stmt->execute([
        ':c' => request_correlation_id(),
        ':a' => (int)$actorId,
        ':ac' => $action,
        ':e' => $entity,
        ':i' => (int)$entityId,
        ':b' => $before === null ? null : json_encode($before, JSON_UNESCAPED_UNICODE),
        ':af' => $after === null ? null : json_encode($after, JSON_UNESCAPED_UNICODE),
        ':ip' => substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
    ]);
}

/* ------------------------------------------------------------------ respuesta */

/**
 * Convierte el resultado de un modelo en respuesta HTTP.
 * Éxito: 200. Los fallos llevan `conflict`: stale | state (condiciones nuevas) -> siempre 409; duplicate | notfound | internal |
 * sin tipo (validación) -> formato heredado (200 + success:false) salvo que el cliente pida el contrato 2 (409 / 404 / 500 / 400).
 */
function write_respond(array $response, $legacyFailureStatus = 200, $successStatus = 200) {
    header('X-Correlation-Id: ' . request_correlation_id());
    if (!empty($response['success'])) {
        $response['correlation_id'] = request_correlation_id();
        if ($successStatus !== 200) {
            http_response_code($successStatus);
        }
        echo json_encode($response);
        return;
    }
    $kind = $response['conflict'] ?? null;
    unset($response['conflict']);
    if ($kind === 'stale' || $kind === 'state') {
        http_response_code(409);
    } elseif (strict_contract()) {
        http_response_code(['duplicate' => 409, 'notfound' => 404, 'internal' => 500][$kind] ?? 400);
    } elseif ($legacyFailureStatus !== 200) {
        // Rutas heredadas que ya respondían 400 ante un fallo (p. ej. /api/asignacion) lo conservan sin el contrato 2.
        http_response_code($legacyFailureStatus);
    }
    echo json_encode($response);
}

/**
 * Ejecuta una operación de escritura y la traduce a un resultado de modelo, sin filtrar detalles internos.
 * `$fn` devuelve el arreglo de éxito; las excepciones tipadas se convierten en fallos con `conflict`.
 */
function run_write(callable $fn, $failureMessage) {
    try {
        try {
            return $fn();
        } catch (PDOException $e) {
            // Deadlock (1213) o espera de bloqueo agotada (1205): MySQL ya revirtió la transacción; se reintenta UNA vez.
            if (isset($e->errorInfo[1]) && in_array((int)$e->errorInfo[1], [1213, 1205], true)) {
                return $fn();
            }
            throw $e;
        }
    } catch (WriteConflict $e) {
        return ['success' => false, 'message' => $e->clientMessage, 'conflict' => $e->kind];
    } catch (WriteNotFound $e) {
        return ['success' => false, 'message' => $e->clientMessage, 'conflict' => 'notfound'];
    } catch (PDOException $e) {
        if (is_duplicate_key($e)) {
            return ['success' => false, 'message' => 'Ya existe un registro con esos datos', 'conflict' => 'duplicate'];
        }
        error_log($failureMessage . ': ' . $e->getMessage());
        return ['success' => false, 'message' => $failureMessage, 'conflict' => 'internal'];
    } catch (Throwable $e) {
        error_log($failureMessage . ': ' . $e->getMessage());
        return ['success' => false, 'message' => $failureMessage, 'conflict' => 'internal'];
    }
}

/* ------------------------------------------------------------------ relaciones de acceso (UX-08A) */

/** Columnas mínimas que se bloquean de cada tabla (lista blanca: el nombre de tabla nunca viene del cliente). */
const RELATION_LOCK_COLUMNS = [
    'user' => 'id, is_active',
    'empresa' => 'id, activo',
    'reporte' => 'id, activo',
    'empresa_reporte' => 'id, empresa_id, reporte_id, activo',
    'empresa_usuario' => 'id, empresa_id, user_id, activo',
    'user_empresa_reporte' => 'id, user_id, empresa_reporte_id, activo',
];

/** Fila bloqueada (`FOR UPDATE`) por id dentro de la transacción en curso, o null si no existe. */
function relation_lock(PDO $pdo, $table, $id) {
    if (!isset(RELATION_LOCK_COLUMNS[$table])) {
        throw new LogicException('Tabla no permitida');
    }
    $stmt = $pdo->prepare('SELECT ' . RELATION_LOCK_COLUMNS[$table] . ' FROM `' . $table . '` WHERE id = :id FOR UPDATE');
    $stmt->execute(['id' => (int)$id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

/** ¿La fila está vigente? (`user` usa `is_active`; el resto, `activo`). */
function relation_is_active($table, array $row) {
    return (int)($table === 'user' ? ($row['is_active'] ?? 0) : ($row['activo'] ?? 0)) === 1;
}

/**
 * Bloquea un padre y exige que exista y esté activo. Inexistente -> WriteNotFound; inactivo -> WriteConflict('state').
 * `$labels` = [mensaje de inexistente, mensaje de inactivo].
 */
function relation_require_active(PDO $pdo, $table, $id, array $labels) {
    $row = relation_lock($pdo, $table, $id);
    if (!$row) {
        throw new WriteNotFound($labels[0]);
    }
    if (!relation_is_active($table, $row)) {
        throw new WriteConflict('state', $labels[1]);
    }
    return $row;
}

/** Id positivo estricto (entero o cadena de dígitos) o null. */
function positive_int($value) {
    if (is_bool($value) || is_array($value) || $value === null) {
        return null;
    }
    $v = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $v === false ? null : $v;
}
