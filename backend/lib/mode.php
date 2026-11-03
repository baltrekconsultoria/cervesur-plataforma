<?php
/**
 * Modo de operación del backend (UX-07D.3A). FAIL-CLOSED y verificable.
 *
 *   read_write   (predeterminado cuando CERVESUR_MODE no existe) comportamiento histórico de los entornos de prueba/sintéticos.
 *   read_only    ninguna escritura: ni administrativa, ni last_login, ni session_version. Las lecturas siguen autorizadas por usuario/empresa/informe.
 *   diagnostico  solo /api/ping y dos endpoints de AGREGADOS (token propio); sin login, sin registros personales, sin enlaces, sin hashes.
 *   piloto_visual  catálogo real de empresas e informes para UN operador autorizado (UX-07D.4): autenticación propia, consultas de un catálogo cerrado,
 *                  enlaces solo en el visor y solo de los informes autorizados; no consulta la tabla user.
 *   piloto_admin   consulta ADMINISTRATIVA de solo lectura (UX-07E.B): solo cuentas de rol admin autorizadas en la lista propia de la instancia; mismas tres conexiones y
 *                  capas que piloto_usuarios, rutas de consulta administrativas y NINGUNA escritura sobre la base de Flutter.
 *   piloto_usuarios  usuarios REALES de Cervesur (UX-07D.4B): contraseñas bcrypt verificadas con una cuenta de solo lectura que únicamente ve la tabla user, lista blanca,
 *                  sesiones revocables y auditoría en una base de estado PROPIA, permisos individuales por empresa/informe y HTTPS obligatorio.
 *
 * Capas independientes (cada una detiene una escritura aunque otra falle):
 *   1) puerta de rutas      app_mode_route_gate(): método + lista blanca, antes de cualquier controlador;
 *   2) guardia de SQL       GuardedPDO: solo SELECT/SHOW/WITH; rechaza varias sentencias, INTO OUTFILE y bloqueos de lectura;
 *   3) transacción de sesión SET SESSION TRANSACTION READ ONLY: MySQL rechaza cualquier escritura de la conexión (error 1792);
 *   4) cuenta de BD         al arrancar se leen sus privilegios (SHOW GRANTS) y se REHÚSA servir si tiene alguno distinto de SELECT/USAGE;
 *   5) la propia cuenta     con permisos SELECT por columna (database/schema/admin-requiere-autorizacion/).
 * Configuración incompleta o contradictoria => HTTP 503 sin tocar la base de datos. Nunca se "adivina" un modo.
 */
require_once __DIR__ . '/env.php';

const APP_MODES = ['read_write', 'read_only', 'diagnostico', 'piloto_visual', 'piloto_usuarios', 'piloto_admin', 'admin_escritura'];
/** Bases de datos reales de Cervesur: jamás se abren en modo de escritura. Ampliable con CERVESUR_PRODUCTION_DBS (lista separada por comas). */
const APP_PRODUCTION_DATABASES = ['cervesur_portalbi'];

/** Una escritura intentada en modo de solo lectura. Hereda de PDOException para que los modelos existentes la traten como cualquier error de BD. */
class ReadOnlyViolation extends PDOException {}

/** Bases protegidas (en minúsculas): las fijas del código más CERVESUR_PRODUCTION_DBS. */
function app_production_databases(array $env) {
    $production = array_map('strtolower', APP_PRODUCTION_DATABASES);
    foreach (explode(',', (string)($env['CERVESUR_PRODUCTION_DBS'] ?? '')) as $name) {
        if (trim($name) !== '') {
            $production[] = strtolower(trim($name));
        }
    }
    return $production;
}

/**
 * Evalúa la configuración sin efectos secundarios. Devuelve ['mode' => string|null, 'errors' => string[]].
 * @param array<string,string|false|null> $env variables de entorno (normalmente getenv())
 */
function app_mode_evaluate(array $env) {
    $errors = [];
    $raw = trim((string)($env['CERVESUR_MODE'] ?? ''));
    $mode = $raw === '' ? 'read_write' : $raw;
    if (!in_array($mode, APP_MODES, true)) {
        return ['mode' => null, 'errors' => ['CERVESUR_MODE no es un valor válido']];
    }

    // Modo EXIGIDO por el despliegue (sitio 8090: CERVESUR_REQUIRED_MODE=diagnostico). Con él, un modo ausente, inválido o distinto impide el arranque:
    // la ausencia de CERVESUR_MODE ya no equivale a read_write.
    $required = trim((string)($env['CERVESUR_REQUIRED_MODE'] ?? ''));
    if ($required !== '') {
        if (!in_array($required, APP_MODES, true)) {
            return ['mode' => null, 'errors' => ['CERVESUR_REQUIRED_MODE no es un valor válido']];
        }
        if ($raw !== $required) {
            return ['mode' => null, 'errors' => ['CERVESUR_MODE debe ser exactamente el modo exigido por el despliegue y no puede faltar']];
        }
    }

    // El nombre de la base se usa en el DSN: ningún carácter que permita inyectar parámetros (; = espacios, comillas, etc.). Una base con otro nombre
    // legítimo nunca lo necesita; así la comprobación de «base productiva» no puede esquivarse con un valor tan creativo como «x;dbname=cervesur_portalbi».
    $dbRaw = (string)($env['DB_NAME'] ?? '');
    if ($dbRaw !== '' && !preg_match('/^[A-Za-z0-9_$-]{1,64}$/D', $dbRaw)) {
        return ['mode' => null, 'errors' => ['DB_NAME contiene caracteres no permitidos']];
    }
    $db = strtolower($dbRaw);
    $isProduction = $db !== '' && in_array($db, app_production_databases($env), true);

    if ($mode === 'read_write' && $isProduction) {
        $errors[] = 'Una base de datos productiva solo puede abrirse en modo de solo lectura';
    }
    if ($mode !== 'read_write') {
        if (strtoupper(trim((string)($env['CERVESUR_ALLOW_WRITABLE_DB_ACCOUNT'] ?? ''))) === 'SI' && $isProduction) {
            $errors[] = 'No se admite una cuenta de base de datos con escritura sobre una base productiva';
        }
    }
    if ($mode === 'read_only' && strlen((string)($env['JWT_SECRET'] ?? '')) < 32) {
        $errors[] = 'El modo de solo lectura con inicio de sesión exige un JWT_SECRET propio de al menos 32 caracteres';
    }
    if ($mode === 'piloto_visual') {
        if (strlen((string)($env['JWT_SECRET'] ?? '')) < 32) {
            $errors[] = 'El piloto exige un JWT_SECRET propio de al menos 32 caracteres';
        }
        if (!preg_match('/^\$2y\$\d\d\$[.\/A-Za-z0-9]{53}$/D', (string)($env['CERVESUR_PILOT_OPERATOR_HASH'] ?? ''))) {
            $errors[] = 'El piloto exige CERVESUR_PILOT_OPERATOR_HASH (bcrypt)';
        }
        if (!preg_match('~^/[A-Za-z0-9_./-]{3,200}$~D', (string)($env['CERVESUR_PILOT_STATE_DIR'] ?? ''))) {
            $errors[] = 'El piloto exige CERVESUR_PILOT_STATE_DIR (ruta absoluta)';
        }
        if (!preg_match('/^(|TODOS|[1-9][0-9]{0,9}(,[1-9][0-9]{0,9}){0,499})$/D', (string)($env['CERVESUR_PILOT_VISOR_IDS'] ?? ''))) {
            $errors[] = 'CERVESUR_PILOT_VISOR_IDS debe ser vacío, TODOS o una lista de ids numéricos';
        }
    }
    if ($mode === 'piloto_usuarios' || $mode === 'piloto_admin' || $mode === 'admin_escritura') {
        if (strlen((string)($env['CERVESUR_PILOT_SECRET'] ?? '')) < 32) {
            $errors[] = 'El piloto con usuarios exige CERVESUR_PILOT_SECRET propio de al menos 32 caracteres';
        }
        $stateDb = (string)($env['STATE_DB_NAME'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_$-]{1,64}$/D', $stateDb)) {
            $errors[] = 'El piloto con usuarios exige STATE_DB_NAME (base de estado propia)';
        } elseif (strtolower($stateDb) === $db || in_array(strtolower($stateDb), app_production_databases($env), true)) {
            $errors[] = 'La base de estado debe ser distinta de la base de lectura y no puede ser una base protegida';
        }
        if (!preg_match('/^(|TODOS|[1-9][0-9]{0,9}(,[1-9][0-9]{0,9}){0,499})$/D', (string)($env['CERVESUR_PILOT_VISOR_IDS'] ?? ''))) {
            $errors[] = 'CERVESUR_PILOT_VISOR_IDS debe ser vacío, TODOS o una lista de ids numéricos';
        }
        // HTTPS es obligatorio con credenciales reales. La única excepción es la prueba local sobre una base NO protegida (nunca la productiva).
        if (strtoupper(trim((string)($env['CERVESUR_INSECURE_TESTS'] ?? ''))) === 'SI' && $isProduction) {
            $errors[] = 'No se admite HTTP sin cifrar con una base productiva';
        }
    }
    if ($mode === 'admin_escritura') {
        // UX-08H: configuración propia del modo con escrituras (auditoría obligatoria, vistas mínimas, interruptores válidos, sin cuenta con escritura por bandera).
        $errors = array_merge($errors, admin_write_config_errors($env));
    }
    if ($mode === 'diagnostico') {
        $token = (string)($env['CERVESUR_DIAG_TOKEN'] ?? '');
        if (strlen($token) < 32) {
            $errors[] = 'El modo de diagnóstico exige CERVESUR_DIAG_TOKEN de al menos 32 caracteres';
        }
        if ($token !== '' && $token === (string)($env['JWT_SECRET'] ?? '')) {
            $errors[] = 'CERVESUR_DIAG_TOKEN no puede coincidir con JWT_SECRET';
        }
    }
    return ['mode' => $errors ? null : $mode, 'errors' => $errors];
}

/** Respuesta común de rechazo de configuración: sin detalles técnicos ni valores (la causa va al log interno). */
function app_mode_refuse(array $causes) {
    error_log('[cervesur] Modo de operación rechazado: ' . implode(' | ', $causes));
    if (!headers_sent()) {
        http_response_code(503);
        header('Content-Type: application/json');
    }
    echo json_encode(['success' => false, 'message' => 'Servicio no disponible: configuración de modo incompleta o no válida', 'code' => 'mode_config']);
    exit;
}

/**
 * ¿La petición llegó cifrada (TLS)? Se acepta HTTPS directo o un terminador TLS en la MISMA máquina (X-Forwarded-Proto solo si la conexión viene de loopback).
 * Con credenciales reales (piloto_usuarios) toda petición sin TLS se rechaza, salvo la prueba local explícita sobre una base no protegida.
 */
function app_https_ok(array $server, $insecureTests) {
    if (strtoupper(trim((string)$insecureTests)) === 'SI') {
        return true;
    }
    $https = strtolower((string)($server['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off' && $https !== '0') {
        return true;
    }
    $loopback = in_array((string)($server['REMOTE_ADDR'] ?? ''), ['127.0.0.1', '::1'], true);
    return $loopback && strtolower((string)($server['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

/** Evalúa getenv() una vez; rechaza (503) si la configuración es inválida. Debe llamarse ANTES de conectar con la base de datos. */
function app_mode_boot() {
    static $mode = null;
    if ($mode !== null) {
        return $mode;
    }
    $keys = ['CERVESUR_MODE', 'CERVESUR_REQUIRED_MODE', 'DB_NAME', 'CERVESUR_PRODUCTION_DBS', 'CERVESUR_ALLOW_WRITABLE_DB_ACCOUNT', 'JWT_SECRET', 'CERVESUR_DIAG_TOKEN', 'CERVESUR_PILOT_OPERATOR_HASH', 'CERVESUR_PILOT_STATE_DIR', 'CERVESUR_PILOT_VISOR_IDS', 'CERVESUR_PILOT_SECRET', 'STATE_DB_NAME', 'CERVESUR_INSECURE_TESTS', 'AUDIT_LOG_MODE', 'REACT_STRICT_VIEWS', 'ADMIN_WRITES_EMPRESAS', 'ADMIN_WRITES_REPORTES', 'ADMIN_WRITES_ACCESOS', 'ADMIN_WRITES_USUARIOS', 'ADMIN_WRITES_USUARIOS_SENSIBLE', 'ADMIN_USER_REACTIVATE_HOURS'];
    $env = [];
    foreach ($keys as $key) {
        $env[$key] = getenv($key);
    }
    $result = app_mode_evaluate($env);
    if ($result['mode'] === null) {
        app_mode_refuse($result['errors']);
    }
    $mode = $result['mode'];
    return $mode;
}

function app_mode() { return app_mode_boot(); }
function app_mode_is_read_only() { return app_mode() !== 'read_write'; }

/**
 * Puerta de rutas (capa 1). Devuelve null si la petición puede continuar, o ['status', 'code', 'message'] si debe rechazarse.
 * En read_only se rechaza TODO lo que no sea lectura salvo login/logout (que en este modo no escriben). El endpoint heredado de tokens de Power BI
 * (lee credenciales de empresa) está bloqueado en cualquier modo restringido.
 */
function app_mode_route_gate($mode, $method, $uri) {
    if ($mode === 'read_write') {
        return null;
    }
    $method = strtoupper((string)$method);
    if (stripos((string)$uri, '/api/powerbi') !== false) {
        return ['status' => 403, 'code' => 'legacy_blocked', 'message' => 'Endpoint no disponible en este modo'];
    }
    if ($mode === 'admin_escritura') {
        return app_mode_write_gate($method, $uri);
    }
    if ($mode === 'piloto_admin') {
        // Consulta administrativa: solo GET de lectura (rutas EXACTAS, sin normalizar) y el inicio/cierre de sesión. Ninguna escritura, ningún formulario (`view=form`).
        $allowedAdmin = [
            'GET' => ['~^/api/ping$~D', '~^/api/user/profile$~D', '~^/api/users$~D', '~^/api/empresas$~D', '~^/api/reportes$~D', '~^/api/reportes/[1-9][0-9]{0,9}$~D', '~^/api/reportes/id/[1-9][0-9]{0,9}/visor$~D',
                '~^/api/asignacion$~D', '~^/api/empresa-usuario$~D', '~^/api/empresa-reporte-usuario$~D'],
            'POST' => ['~^/api/login$~D', '~^/api/logout$~D'],
        ];
        foreach ($allowedAdmin[$method] ?? [] as $pattern) {
            if (is_string($uri) && preg_match($pattern, $uri)) {
                return null;
            }
        }
        return ['status' => 403, 'code' => 'piloto_admin', 'message' => 'Este entorno solo ofrece la consulta administrativa'];
    }
    if ($mode === 'piloto_visual' || $mode === 'piloto_usuarios') {
        // Lista cerrada de rutas EXACTAS (sin normalizar). El frontend se sirve como archivos estáticos por Apache; aquí solo llega /api.
        $allowed = [
            'GET' => ['~^/api/ping$~D', '~^/api/user/profile$~D', '~^/api/reportes$~D', '~^/api/reportes/[1-9][0-9]{0,9}$~D', '~^/api/reportes/id/[1-9][0-9]{0,9}/visor$~D', '~^/api/empresas$~D', '~^/api/asignacion$~D'],
            'POST' => ['~^/api/login$~D', '~^/api/logout$~D'],
        ];
        foreach ($allowed[$method] ?? [] as $pattern) {
            if (is_string($uri) && preg_match($pattern, $uri)) {
                return null;
            }
        }
        return ['status' => 403, 'code' => 'piloto', 'message' => 'Este entorno solo ofrece la consulta del catálogo'];
    }
    if ($mode === 'diagnostico') {
        // Lista cerrada, método GET y ruta EXACTA (sin normalizar: «/api/ping/», «//api/ping» o mayúsculas no coinciden). Todo lo demás —incluido lo
        // heredado y lo desconocido— se rechaza aquí, antes de cualquier controlador.
        if ($method === 'GET' && in_array($uri, ['/api/ping', '/api/diagnostico/resumen', '/api/diagnostico/conciliacion'], true)) {
            return null;
        }
        return ['status' => 403, 'code' => 'diagnostico', 'message' => 'Este entorno solo ofrece diagnósticos agregados'];
    }
    $path = rtrim((string)$uri, '/');
    if ($path === '') {
        $path = '/';
    }
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return null;
    }
    if ($method === 'POST' && in_array($path, ['/api/login', '/api/logout'], true)) {
        return null;
    }
    return ['status' => 403, 'code' => 'read_only', 'message' => 'Servicio en modo de solo lectura: la operación no está permitida'];
}

/**
 * Guardia de SQL (capa 2): solo consultas de lectura de UNA sola sentencia. Lanza ReadOnlyViolation si no lo es.
 * Los comentarios solo se ignoran AL PRINCIPIO (para identificar el verbo); cualquier «;» en el texto original se rechaza, de modo que ningún
 * comentario ni literal puede ocultar una segunda sentencia.
 */
function app_sql_guard($sql) {
    $text = trim((string)$sql);
    $text = rtrim($text, "; \t\r\n");
    $reject = function ($why) {
        throw new ReadOnlyViolation('Sentencia rechazada en modo de solo lectura: ' . $why);
    };
    if ($text === '' || preg_match('/[^\x09\x0A\x0D\x20-\x7E\x{00A0}-\x{10FFFF}]/u', $text) || preg_match('//u', $text) !== 1) {
        $reject('caracteres no permitidos');
    }
    // Los comentarios NO se interpretan: se rechazan. Un comentario ejecutable (/*!50000 ...*/), una pista (/*+ ... */) o un «-- » pueden alterar lo que
    // MySQL ejecuta sin que un análisis léxico lo vea, y ninguna consulta propia del backend los usa.
    if (strpos($text, '/*') !== false || strpos($text, '*/') !== false || strpos($text, '--') !== false || strpos($text, '#') !== false) {
        $reject('comentarios');
    }
    if (strpos($text, ';') !== false) {
        $reject('varias sentencias');
    }
    // Lista cerrada de verbos. WITH queda fuera a propósito: MySQL admite «WITH … UPDATE» y «WITH … DELETE», así que empezar por WITH no demuestra
    // nada. SHOW solo se admite con el único texto exacto que usa el arranque.
    $normalized = preg_replace('/\s+/', ' ', $text);
    if (preg_match('/^SET SESSION TRANSACTION READ ONLY$/i', $normalized) || preg_match('/^SHOW GRANTS FOR CURRENT_USER\(\)$/i', $normalized)) {
        return;
    }
    if (!preg_match('/^\(*\s*SELECT\b/i', $normalized)) {
        $reject('solo se admiten consultas SELECT');
    }
    // Una consulta que empieza por SELECT, sin «;» ni comentarios, no puede encadenar otra sentencia. Lo que aún puede tener efectos laterales dentro
    // de un SELECT: INTO (archivo o variable), asignación a variables de usuario, bloqueos de filas o de nombre, esperas, lectura de archivos, WITH
    // (CTE o ROLLUP), PROCEDURE y variables de servidor GLOBAL/PERSIST. Se rechazan todas: ninguna consulta propia las usa.
    $forbidden = '/\binto\b|:=|\bfor\s+(update|share)\b|\block\s+in\s+share\s+mode\b|\b(get_lock|release_lock|release_all_locks|is_used_lock|sleep|benchmark|load_file|sys_exec|sys_eval)\s*\(|\bwith\b|\bprocedure\b|@@(global|persist)\b/i';
    if (preg_match($forbidden, $normalized)) {
        $reject('construcción no permitida');
    }
}

/** @param string[] $statements @return array<string,true> */
function app_sql_catalog(array $statements) {
    $catalog = [];
    foreach ($statements as $sql) {
        $catalog[app_sql_normalize($sql)] = true;
    }
    return $catalog;
}

/** Guardia completa: reglas generales y, si hay catálogo cerrado, pertenencia exacta a él. @param array<string,true>|null $catalog */
function app_sql_check($sql, $catalog) {
    app_sql_guard($sql);
    if ($catalog !== null && !isset($catalog[app_sql_normalize($sql)])) {
        throw new ReadOnlyViolation('Sentencia fuera del catálogo autorizado');
    }
}

/** Texto de una sentencia sin diferencias de espacios, para compararla con el catálogo cerrado. */
function app_sql_normalize($sql) {
    return preg_replace('/\s+/', ' ', trim((string)$sql, " \t\r\n;"));
}

/**
 * Privilegios de la cuenta (capa 4). Recibe las filas de SHOW GRANTS y devuelve la lista de violaciones (vacía si la cuenta solo puede LEER).
 * Solo se admiten SELECT (global, por base, por tabla o por columna) y USAGE. Una concesión de rol o con GRANT OPTION no se puede auditar: se rechaza.
 * @param string[] $grants
 */
function app_grants_violations(array $grants) {
    $violations = [];
    foreach ($grants as $line) {
        $line = trim((string)$line);
        if (!preg_match('/^GRANT\s+(.+?)\s+ON\s+(.+?)\s+TO\s+/is', $line, $m)) {
            $violations[] = 'concesión no auditable';
            continue;
        }
        $privileges = preg_replace('/\([^)]*\)/', '', $m[1]);
        foreach (explode(',', $privileges) as $privilege) {
            $privilege = strtoupper(trim($privilege));
            if (!in_array($privilege, ['SELECT', 'USAGE'], true)) {
                $violations[] = $privilege;
            }
        }
        if (preg_match('/\bWITH\s+GRANT\s+OPTION\b/i', $line)) {
            $violations[] = 'GRANT OPTION';
        }
    }
    return array_values(array_unique($violations));
}

/** Capa 4 en ejecución: lee los privilegios reales de la cuenta conectada y rehúsa servir si puede escribir. */
function app_mode_assert_readonly_account(PDO $pdo) {
    $allowWritable = strtoupper(trim((string)getenv('CERVESUR_ALLOW_WRITABLE_DB_ACCOUNT'))) === 'SI';
    $stmt = $pdo->query('SHOW GRANTS FOR CURRENT_USER()');
    $grants = $stmt ? $stmt->fetchAll(PDO::FETCH_COLUMN, 0) : [];
    $violations = app_grants_violations($grants);
    if ($violations && !$allowWritable) {
        app_mode_refuse(['La cuenta de base de datos tiene privilegios distintos de SELECT: ' . implode(', ', $violations)]);
    }
    return $violations;
}

/**
 * Segunda comprobación, YA CONECTADO: la base realmente abierta (SELECT DATABASE()) es la que declara DB_NAME y, si es productiva, el modo no es
 * read_write; y en los modos restringidos la sesión MySQL está de verdad en solo lectura. Rehúsa servir (503) si algo no cuadra.
 */
function app_mode_verify_connection(PDO $pdo, $mode, $escritura = false) {
    $env = ['CERVESUR_PRODUCTION_DBS' => getenv('CERVESUR_PRODUCTION_DBS')];
    $actual = strtolower((string)$pdo->query('SELECT DATABASE()')->fetchColumn());
    if ($actual === '' || $actual !== strtolower((string)getenv('DB_NAME'))) {
        app_mode_refuse(['La base realmente abierta no coincide con DB_NAME']);
    }
    if ($mode === 'read_write' && in_array($actual, app_production_databases($env), true)) {
        app_mode_refuse(['Una base de datos productiva solo puede abrirse en modo de solo lectura (comprobado tras conectar)']);
    }
    if ($mode !== 'read_write' && !$escritura && (int)$pdo->query('SELECT @@session.transaction_read_only')->fetchColumn() !== 1) {
        app_mode_refuse(['La sesión de base de datos no está en solo lectura']);
    }
}

/** PDO que solo deja pasar lecturas (capa 2). */
class GuardedPDO extends PDO {
    /** @var array<string,true>|null Catálogo cerrado (texto normalizado => true). Con catálogo, SOLO se ejecuta SQL que figure en él. */
    private $catalog = null;

    /** Activa el catálogo cerrado (modo diagnóstico). Una vez activado no puede ampliarse ni desactivarse. */
    public function useCatalog(array $statements) {
        if ($this->catalog !== null) {
            throw new ReadOnlyViolation('El catálogo de sentencias ya está fijado');
        }
        $this->catalog = app_sql_catalog($statements);
    }

    private function check($sql) {
        app_sql_check($sql, $this->catalog);
    }

    public function prepare(string $query, array $options = []): PDOStatement|false {
        $this->check($query);
        return parent::prepare($query, $options);
    }

    public function exec(string $statement): int|false {
        $this->check($statement);
        return parent::exec($statement);
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        $this->check($query);
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}

// UX-08H: modo con escrituras (puerta de rutas por capacidades, guardia de SQL de escritura, privilegios de la cuenta). Se carga al final: usa app_mode_refuse().
require_once __DIR__ . '/admin_escritura.php';
