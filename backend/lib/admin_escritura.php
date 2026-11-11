<?php
/**
 * Modo «admin_escritura» (UX-08H · fase 2): administración con ESCRITURAS sobre la base de negocio, explícito y separado de «piloto_admin».
 *
 * «piloto_admin» sigue siendo estrictamente de solo lectura (cinco capas, sin interruptor que lo convierta en escritura). Este modo es OTRO modo, exigido por el despliegue
 * (CERVESUR_REQUIRED_MODE=admin_escritura), con:
 *   1) la autenticación del piloto (sesión opaca, lista blanca de administradores, revalidación de rol/estado/session_version/contraseña en CADA petición);
 *   2) una cuenta MySQL de ESCRITURA propia y mínima (SELECT/INSERT/UPDATE por columnas; jamás DELETE, DDL ni GRANT OPTION), verificada con SHOW GRANTS al arrancar;
 *   3) un guardia de SQL de escritura (WriteGuardedPDO): solo SELECT, INSERT/UPDATE de tablas de la lista cerrada, nunca en `audit_log` salvo INSERT;
 *   4) una puerta de rutas con capacidades independientes (empresas, reportes, accesos, usuarios) que se leen del ENTORNO en cada petición (apagables en el servidor);
 *   5) auditoría obligatoria (AUDIT_LOG_MODE=required) en la MISMA transacción que el cambio: si falla, no se confirma.
 * Configuración incompleta o contradictoria => 503 sin tocar la base. Nada de aquí se activa en «piloto_admin».
 */

/** Tablas de negocio en las que la cuenta de escritura puede INSERT/UPDATE (audit_log: solo INSERT). */
const ADMIN_WRITE_TABLES = ['user', 'v_user_alta', 'empresa', 'reporte', 'empresa_usuario', 'empresa_reporte', 'user_empresa_reporte', 'audit_log'];
const ADMIN_WRITE_CAPABILITIES = ['empresas', 'reportes', 'accesos', 'usuarios'];
/**
 * Fase 2B · usuarios compartidos con Flutter. `usuarios_sensible` (cambio de ROL y de CONTRASEÑA) es un interruptor APARTE y exige también `usuarios`:
 * Flutter toma el rol del JWT y acepta tokens antiguos sin session_version, así que ni un cambio de rol ni uno de contraseña invalidan esas sesiones.
 * Sin este interruptor, la fase 2B solo ofrece: alta, edición de datos personales, desactivar y reactivar (con espera, ver admin_user_reactivate_hours).
 */
const ADMIN_WRITE_GATES = ['empresas', 'reportes', 'accesos', 'usuarios', 'usuarios_sensible', 'usuarios_password_reset'];
/** Horas mínimas entre desactivar y reactivar a un usuario (por defecto 24 = vida de un token de Flutter). Evita que reactivar reviva sesiones antiguas. */
function admin_user_reactivate_hours() {
    $v = getenv('ADMIN_USER_REACTIVATE_HOURS');
    return ($v !== false && $v !== '' && ctype_digit($v) && (int)$v <= 720) ? (int)$v : 24;
}

/** Una sentencia de escritura que el guardia no admite. Hereda de PDOException para que los modelos la traten como cualquier error de BD. */
class WriteViolation extends PDOException {}

/**
 * FASE 2B · USUARIOS BLOQUEADOS POR DEFECTO SOBRE LA BASE PRODUCTIVA (UX-08I). Con la base de negocio real (cervesur_portalbi o CERVESUR_PRODUCTION_DBS) toda escritura sobre `user`
 * queda negada mientras el servidor no tenga EXACTAMENTE `ADMIN_WRITES_USUARIOS=1`, en CUATRO capas independientes:
 *   (1) la puerta de rutas (403 `fase_2b_bloqueada`), (2) el guardia de SQL (ni INSERT ni UPDATE de `user`; `role` y `password` además exigen el interruptor sensible),
 *   (3) los privilegios de la cuenta MySQL (la de la fase 2A solo tiene UPDATE(last_login); las plantillas 2B son aditivas y separan lo sensible) y
 *   (4) la aserción de arranque, que cruza SHOW GRANTS con los interruptores (ver admin_write_grants_violations).
 * Sin interruptor el comportamiento es el de la fase 2A. En bases desechables de prueba no aplica.
 */
function admin_users_locked() {
    return admin_is_production_db() && getenv('ADMIN_WRITES_USUARIOS') !== '1';
}

/** ¿La base configurada es una base de negocio productiva (cervesur_portalbi o CERVESUR_PRODUCTION_DBS)? */
function admin_is_production_db() {
    $production = app_production_databases(['CERVESUR_PRODUCTION_DBS' => getenv('CERVESUR_PRODUCTION_DBS')]);
    return in_array(strtolower(trim((string)getenv('DB_NAME'))), $production, true);
}

/** ¿Capacidad de escritura encendida en el servidor? Solo el valor exacto «1»; se lee del entorno en cada petición. */
function admin_write_capability_on($capability) {
    if (in_array($capability, ['usuarios', 'usuarios_sensible', 'usuarios_password_reset'], true) && admin_users_locked()) {
        return false; // fase 2B bloqueada sobre la base productiva
    }
    // P5-R: no existe autorizacion para cambiar roles.
    if ($capability === 'usuarios_sensible') { return false; }
    if ($capability === 'usuarios_password_reset') {
        return getenv('ADMIN_WRITES_USUARIOS') === '1'
            && getenv('ADMIN_WRITES_USUARIOS_PASSWORD_RESET') === '1'
            && getenv('ADMIN_P5R_FLUTTER_401_VERIFIED') === '1'
            && getenv('ADMIN_WRITES_USUARIOS_SENSIBLE') !== '1';
    }
    return getenv('ADMIN_WRITES_' . strtoupper((string)$capability)) === '1';
}

/** Capacidades efectivas (para la interfaz): lo que el servidor permite AHORA. */
function admin_write_capabilities() {
    $out = [];
    foreach (ADMIN_WRITE_CAPABILITIES as $c) { $out[$c] = admin_write_capability_on($c); }
    return $out;
}

/** Errores de configuración propios de este modo (se suman a los comunes del piloto con usuarios). @param array<string,string|false|null> $env */
function admin_write_config_errors(array $env) {
    $errors = [];
    if (trim((string)($env['AUDIT_LOG_MODE'] ?? '')) !== 'required') {
        $errors[] = 'admin_escritura exige AUDIT_LOG_MODE=required (la auditoría obligatoria no puede apagarse)';
    }
    if (trim((string)($env['REACT_STRICT_VIEWS'] ?? '')) !== '1') {
        $errors[] = 'admin_escritura exige REACT_STRICT_VIEWS=1 (vistas mínimas por defecto)';
    }
    if (trim((string)($env['CERVESUR_ALLOW_WRITABLE_DB_ACCOUNT'] ?? '')) !== '') {
        $errors[] = 'CERVESUR_ALLOW_WRITABLE_DB_ACCOUNT no se admite en admin_escritura (la cuenta de escritura se valida por sus privilegios)';
    }
    if (strlen((string)($env['JWT_SECRET'] ?? '')) < 32) {
        $errors[] = 'admin_escritura exige un JWT_SECRET propio de al menos 32 caracteres (los routers de UX-08 lo comprueban aunque la identidad venga de la sesión del piloto)';
    }
    foreach (ADMIN_WRITE_GATES as $c) {
        $v = (string)($env['ADMIN_WRITES_' . strtoupper($c)] ?? '');
        if (!in_array($v, ['', '0', '1'], true)) {
            $errors[] = 'ADMIN_WRITES_' . strtoupper($c) . ' debe estar sin definir, 0 o 1';
        }
    }
    if ((string)($env['ADMIN_WRITES_USUARIOS_SENSIBLE'] ?? '') === '1') {
        $errors[] = 'P5-R prohibe ADMIN_WRITES_USUARIOS_SENSIBLE=1';
    }
    $reset = (string)($env['ADMIN_WRITES_USUARIOS_PASSWORD_RESET'] ?? '');
    $legacyQa = (string)($env['ADMIN_P5R_FLUTTER_401_VERIFIED'] ?? '');
    if (!in_array($legacyQa, ['', '0', '1'], true)) {
        $errors[] = 'ADMIN_P5R_FLUTTER_401_VERIFIED debe ser 0, 1 o no definido';
    }
    if ($reset === '1' && ((string)($env['ADMIN_WRITES_USUARIOS'] ?? '') !== '1' || $legacyQa !== '1')) {
        $errors[] = 'P5-R requiere Usuarios ON y QA Flutter 401 antes del reset';
    }
    $h = (string)($env['ADMIN_USER_REACTIVATE_HOURS'] ?? '');
    if ($h !== '' && (!ctype_digit($h) || (int)$h > 720)) {
        $errors[] = 'ADMIN_USER_REACTIVATE_HOURS debe ser un entero entre 0 y 720';
    }
    return $errors;
}

/**
 * Tabla CERRADA de rutas de este modo: [método, expresión sobre la ruta EXACTA (sin normalizar), capacidad|null].
 * `null` = lectura o sesión. Todo lo que no figure aquí se rechaza (403) antes de cualquier controlador.
 * @return array<int,array{0:string,1:string,2:?string}>
 */
function admin_write_routes() {
    $id = '[1-9][0-9]{0,9}';
    return [
        // sesión y lectura
        ['POST', '~^/api/login$~D', null], ['POST', '~^/api/logout$~D', null],
        ['GET', '~^/api/ping$~D', null], ['GET', '~^/api/user/profile$~D', null], ['GET', '~^/api/admin/capacidades$~D', null],
        ['GET', '~^/api/users$~D', null], ['GET', '~^/api/users/capabilities$~D', null], ['GET', "~^/api/users/$id\$~D", null], ['GET', "~^/api/users/$id/access\$~D", null],
        ['GET', '~^/api/empresas$~D', null], ['GET', "~^/api/empresas/$id\$~D", null], ['GET', '~^/api/reportes$~D', null], ['GET', "~^/api/reportes/$id\$~D", null], ['GET', "~^/api/reportes/id/$id/visor\$~D", null],
        ['GET', '~^/api/asignacion$~D', null], ['GET', '~^/api/empresa-usuario$~D', null], ['GET', '~^/api/empresa-reporte-usuario$~D', null], ['GET', '~^/api/auditoria$~D', null],
        // empresas
        ['POST', '~^/api/empresas$~D', 'empresas'], ['PUT', "~^/api/empresas/$id\$~D", 'empresas'], ['DELETE', "~^/api/empresas/$id\$~D", 'empresas'], ['POST', "~^/api/empresas/$id/reactivate\$~D", 'empresas'],
        // reportes
        ['POST', '~^/api/reportes$~D', 'reportes'], ['PUT', "~^/api/reportes/$id\$~D", 'reportes'], ['DELETE', "~^/api/reportes/$id\$~D", 'reportes'], ['POST', "~^/api/reportes/$id/reactivate\$~D", 'reportes'],
        // accesos y asignaciones
        ['POST', '~^/api/asignacion$~D', 'accesos'], ['PUT', "~^/api/asignacion/$id\$~D", 'accesos'], ['DELETE', "~^/api/asignacion/$id\$~D", 'accesos'], ['POST', "~^/api/asignacion/$id/reactivate\$~D", 'accesos'],
        ['POST', '~^/api/empresa-usuario$~D', 'accesos'], ['DELETE', "~^/api/empresa-usuario/$id\$~D", 'accesos'], ['POST', "~^/api/empresa-usuario/$id/reactivate\$~D", 'accesos'],
        ['POST', '~^/api/empresa-reporte-usuario$~D', 'accesos'], ['PUT', "~^/api/empresa-reporte-usuario/$id\$~D", 'accesos'], ['DELETE', "~^/api/empresa-reporte-usuario/$id\$~D", 'accesos'], ['POST', "~^/api/empresa-reporte-usuario/$id/reactivate\$~D", 'accesos'],
        // usuarios (capacidad INDEPENDIENTE; fase 2B)
        ['POST', '~^/api/register$~D', 'usuarios'], ['PUT', "~^/api/users/$id\$~D", 'usuarios'], ['POST', "~^/api/users/$id/(deactivate|reactivate)\$~D", 'usuarios'],
        ['PUT', "~^/api/users/$id/password\$~D", 'usuarios_password_reset'], // NO incluye cambio de rol
    ];
}

/** Puerta de rutas (capa 1) de «admin_escritura». null = continúa; si no, ['status','code','message']. */
function app_mode_write_gate($method, $uri) {
    $method = strtoupper((string)$method);
    if (!is_string($uri)) {
        return ['status' => 403, 'code' => 'admin_escritura', 'message' => 'Ruta no disponible en este modo'];
    }
    foreach (admin_write_routes() as [$m, $pattern, $capability]) {
        if ($m === $method && preg_match($pattern, $uri)) {
            if (in_array($capability, ['usuarios', 'usuarios_sensible', 'usuarios_password_reset'], true) && admin_users_locked()) {
                return ['status' => 403, 'code' => 'fase_2b_bloqueada', 'message' => 'La administración de usuarios no está disponible en esta versión'];
            }
            if ($capability !== null && !admin_write_capability_on($capability)) {
                return ['status' => 403, 'code' => 'escritura_desactivada', 'message' => 'Esta operación está desactivada en el servidor'];
            }
            return null;
        }
    }
    return ['status' => 403, 'code' => 'admin_escritura', 'message' => 'Ruta no disponible en este modo'];
}

/**
 * Privilegios de la CUENTA DE ESCRITURA (SHOW GRANTS). Devuelve las violaciones: solo SELECT, INSERT, UPDATE y USAGE; nada con GRANT OPTION;
 * nada fuera de la base de negocio (salvo USAGE global); y sobre `audit_log` solo SELECT e INSERT (la bitácora no se modifica ni se borra).
 * Fase 2B: sobre `user` los privilegios de escritura se cruzan con los interruptores del servidor ($flags = ['usuarios' => bool, 'sensible' => bool]; null = entorno actual):
 * INSERT/UPDATE de toda la tabla nunca; INSERT o UPDATE por columnas solo con «usuarios»; UPDATE de `role` o `password` solo con «sensible».
 * La única escritura siempre admitida sobre `user` es UPDATE(last_login) (bloqueo de fila de la fase 2A).
 * @param string[] $grants
 * @param array{usuarios:bool,sensible:bool}|null $flags
 */
function admin_write_grants_violations(array $grants, $db, $flags = null, $tolerateStaged = false) {
    $violations = [];
    $db = strtolower((string)$db);
    if ($flags === null) { // null = entorno actual; false = sin cruce (bases desechables de prueba)
        $flags = ['usuarios' => getenv('ADMIN_WRITES_USUARIOS') === '1', 'passwordReset' => admin_write_capability_on('usuarios_password_reset')];
    }
    foreach ($grants as $line) {
        $line = trim((string)$line);
        if (!preg_match('/^GRANT\s+(.+?)\s+ON\s+(.+?)\s+TO\s+/is', $line, $m)) {
            $violations[] = 'concesión no auditable';
            continue;
        }
        $object = strtolower(str_replace('`', '', trim($m[2])));
        $privileges = array_map(function ($p) { return strtoupper(trim($p)); }, explode(',', preg_replace('/\([^)]*\)/', '', $m[1])));
        if (preg_match('/\bWITH\s+GRANT\s+OPTION\b/i', $line)) { $violations[] = 'GRANT OPTION'; }
        if ($object === '*.*') {
            foreach ($privileges as $p) { if ($p !== 'USAGE') { $violations[] = "global:$p"; } }
            continue;
        }
        $parts = explode('.', $object);
        if (($parts[0] ?? '') !== $db) { $violations[] = 'otra base: ' . $parts[0]; continue; }
        $allowed = ['SELECT', 'INSERT', 'UPDATE', 'USAGE'];
        if (($parts[1] ?? '') === 'audit_log') { $allowed = ['SELECT', 'INSERT', 'USAGE']; }
        if (($parts[1] ?? '') === 'v_user_alta') { $allowed = ['INSERT', 'USAGE']; } // la vista de alta: solo INSERT
        foreach ($privileges as $p) { if (!in_array($p, $allowed, true)) { $violations[] = $p . (($parts[1] ?? '*') === '*' ? '' : '@' . $parts[1]); } }
        if ($flags !== false && (($parts[1] ?? '') === 'user' || ($parts[1] ?? '*') === '*')) {
            foreach (admin_grant_columns($m[1]) as $priv => $columns) {
                if ($priv !== 'INSERT' && $priv !== 'UPDATE') { continue; }
                if (($parts[1] ?? '*') === '*') { $violations[] = "$priv@*(incluye user)"; continue; }
                if ($columns === null) { $violations[] = "$priv@user:tabla completa"; continue; }
                if ($priv === 'UPDATE' && $columns === ['last_login']) { continue; }
                if ($priv === 'INSERT') { $violations[] = 'INSERT@user:el alta es solo por v_user_alta'; continue; }
                // `email` NO es escribible por la cuenta de React (UX-08I): cambiarlo podría facilitar la recuperación de una cuenta ajena en Flutter
                $previstas = ['first_name', 'last_name', 'phone', 'is_active', 'session_version', 'updated_at', 'last_login', 'password', 'role'];
                if (array_diff($columns, $previstas)) { $violations[] = "$priv@user:columnas no previstas"; continue; }
                if ($priv === 'UPDATE' && in_array('role', $columns, true)) { $violations[] = 'UPDATE@user:role-prohibido-P5R'; continue; }
                // Escalonado (UX-08I, ensayo P4a): las plantillas se aplican ANTES de encender el interruptor. Con el interruptor apagado esas concesiones no cambian lo que se puede hacer
                // (la puerta de rutas y el guardia de SQL siguen cerrados), así que no deben tumbar Empresas, Informes ni Accesos con un 503.
                if ($tolerateStaged) { continue; }
                if (!$flags['usuarios']) { $violations[] = "$priv@user:sin ADMIN_WRITES_USUARIOS"; continue; }
                if (in_array('password', $columns, true) && $priv === 'UPDATE' && !($flags['passwordReset'] ?? false)) { $violations[] = 'UPDATE@user:password sin permiso P5-R'; }
            }
        }
    }
    return array_values(array_unique($violations));
}

/**
 * Privilegios de una concesión con sus columnas: «SELECT (a,b), UPDATE (c)» → ['SELECT' => ['a','b'], 'UPDATE' => ['c']]; sin lista de columnas = null (tabla completa).
 * @return array<string,string[]|null>
 */
function admin_grant_columns($privilegeList) {
    $out = [];
    $depth = 0; $buffer = ''; $items = [];
    foreach (str_split((string)$privilegeList) as $ch) {
        if ($ch === '(') { $depth++; }
        if ($ch === ')') { $depth--; }
        if ($ch === ',' && $depth === 0) { $items[] = $buffer; $buffer = ''; continue; }
        $buffer .= $ch;
    }
    $items[] = $buffer;
    foreach ($items as $item) {
        if (!preg_match('/^\s*([A-Za-z ]+?)\s*(?:\(([^)]*)\))?\s*$/', $item, $x)) { continue; }
        $priv = strtoupper(trim($x[1]));
        if (isset($x[2]) && $x[2] !== '') {
            $cols = array_values(array_filter(array_map(function ($c) { return strtolower(trim(str_replace('`', '', $c))); }, explode(',', $x[2])), 'strlen'));
            $out[$priv] = array_values(array_unique(array_merge($out[$priv] ?? [], $cols)));
        } else {
            $out[$priv] = null;
        }
    }
    return $out;
}

/** Capa 4 en ejecución: rehúsa servir (503) si la cuenta de escritura tiene privilegios de más. */
function admin_write_assert_account(PDO $pdo) {
    $db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
    $grants = $pdo->query('SHOW GRANTS FOR CURRENT_USER()')->fetchAll(PDO::FETCH_COLUMN, 0);
    // Sobre una base productiva los privilegios de `user` se cruzan con los interruptores del servidor; en bases desechables de prueba la cuenta amplia de QA es legítima.
    $flags = admin_is_production_db() ? null : false; // false = sin cruce con interruptores
    $violations = admin_write_grants_violations($grants, $db, $flags, true);
    if ($violations) {
        app_mode_refuse(['La cuenta de escritura tiene privilegios no permitidos: ' . implode(', ', $violations)]);
    }
}

/** Guardia de SQL de escritura (capa 3). Lanza WriteViolation si la sentencia no es una lectura ni un INSERT/UPDATE permitido. */
function app_sql_write_guard($sql) {
    $text = rtrim(trim((string)$sql), "; \t\r\n");
    $reject = function ($why) { throw new WriteViolation('Sentencia rechazada en admin_escritura: ' . $why); };
    if ($text === '' || preg_match('//u', $text) !== 1 || preg_match('/[^\x09\x0A\x0D\x20-\x7E\x{00A0}-\x{10FFFF}]/u', $text)) { $reject('caracteres no permitidos'); }
    if (strpos($text, '/*') !== false || strpos($text, '*/') !== false || strpos($text, '--') !== false || strpos($text, '#') !== false) { $reject('comentarios'); }
    if (strpos($text, ';') !== false) { $reject('varias sentencias'); }
    $n = preg_replace('/\s+/', ' ', $text);
    // Siempre prohibido, en cualquier sentencia: destrucción, DDL, privilegios, archivos, bloqueos de servidor, asignaciones de variables.
    if (preg_match('/\b(delete|drop|alter|create|truncate|grant|revoke|rename|replace|load|handler|call|lock\s+tables|unlock|outfile|dumpfile|into\s+@|procedure|get_lock|release_lock|sleep|benchmark|load_file|sys_exec|sys_eval)\b|:=|@@(global|persist)\b/i', $n)) {
        $reject('construcción no permitida');
    }
    if (preg_match('/^SHOW GRANTS FOR CURRENT_USER\(\)$/i', $n)) { return; }
    if (preg_match('/^\(*\s*SELECT\b/i', $n)) {
        if (preg_match('/\binto\b|\bwith\b|\bfor\s+share\b|\block\s+in\s+share\s+mode\b/i', $n)) { $reject('SELECT con construcción no permitida'); }
        return;
    }
    if (preg_match('/^INSERT INTO `?([a-z_]+)`?[ (]/i', $n, $m)) {
        if (!in_array(strtolower($m[1]), ADMIN_WRITE_TABLES, true)) { $reject('tabla no permitida para INSERT: ' . $m[1]); }
        if (in_array(strtolower($m[1]), ['user', 'v_user_alta'], true) && admin_users_locked()) { $reject('fase 2B bloqueada: no se escribe `user` sobre la base productiva'); }
        if (strtolower($m[1]) === 'user') { $reject('el alta de usuarios se escribe SOLO por la vista v_user_alta (el motor acota el rol)'); }
        // Defensa en profundidad: además del WITH CHECK OPTION de la vista, el guardia exige la forma EXACTA del alta (rol = literal «user»/«usuario», activo = 1, session_version = 1, sin id).
        if (strtolower($m[1]) === 'v_user_alta' && !preg_match('/^INSERT INTO `?v_user_alta`? \(first_name, last_name, username, email, phone, password, role, is_active, session_version, created_at, updated_at\) VALUES \(:first_name, :last_name, :username, :email, :phone, :password, \'(user|usuario)\', 1, 1, NOW\(\), NOW\(\)\)$/', $n)) { $reject('INSERT en `user` con una forma distinta del alta autorizada (el rol debe ser el literal fijo «user»)'); }
        if (preg_match('/\bon\s+duplicate\b|\bselect\b/i', $n)) { $reject('INSERT ... SELECT / ON DUPLICATE no permitidos'); }
        return;
    }
    if (preg_match('/^UPDATE `?([a-z_]+)`? SET /i', $n, $m)) {
        $t = strtolower($m[1]);
        if (!in_array($t, ADMIN_WRITE_TABLES, true) || $t === 'audit_log') { $reject('tabla no permitida para UPDATE: ' . $m[1]); }
        if ($t === 'user' && admin_users_locked()) { $reject('fase 2B bloqueada: no se escribe `user` sobre la base productiva'); }
        if ($t === 'user') {
            $set = preg_split('/\sWHERE\s/i', $n, 2)[0];
            if (preg_match('/(?:^|[\s,`])role`?\s*=/i', $set)) { $reject('P5-R: cambio de rol prohibido'); }
            if (preg_match('/(?:^|[\s,`])password`?\s*=/i', $set)) {
                if (!admin_write_capability_on('usuarios_password_reset')) { $reject('P5-R: reset deshabilitado'); }
                if ($n !== 'UPDATE user SET password = :p, session_version = session_version + 1, updated_at = NOW() WHERE id = :id') {
                    $reject('P5-R: forma de UPDATE password no permitida');
                }
            }
        }
        if (preg_match('/\bjoin\b/i', $n)) { $reject('UPDATE con JOIN no permitido'); }
        return;
    }
    $reject('solo SELECT, INSERT y UPDATE de la lista cerrada');
}

/** PDO de la cuenta de escritura: cada sentencia pasa por el guardia (el motor, además, la limita por privilegios). */
class WriteGuardedPDO extends PDO {
    public function prepare(string $query, array $options = []): PDOStatement|false {
        app_sql_write_guard($query);
        return parent::prepare($query, $options);
    }
    public function exec(string $statement): int|false {
        app_sql_write_guard($statement);
        return parent::exec($statement);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        app_sql_write_guard($query);
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
}
