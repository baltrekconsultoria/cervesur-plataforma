<?php
/**
 * Estado PROPIO del piloto con usuarios reales (UX-07D.4B): lista blanca, sesiones revocables, intentos de acceso y auditoría.
 * Vive en una base de datos SEPARADA de cervesur_portalbi (cervesur_react_estado) con una cuenta que solo tiene SELECT/INSERT/UPDATE/DELETE ahí.
 * Es la única conexión del piloto que escribe, y solo ejecuta las sentencias de este catálogo cerrado (cualquier otra se rechaza antes de llegar a MySQL).
 * No guarda contraseñas, hashes de contraseña, enlaces de Power BI ni direcciones IP en claro: solo huellas HMAC.
 */
require_once __DIR__ . '/mode.php';

/** @return array<string,string> */
function estado_catalogo_consultas() {
    return [
        'lista_blanca_activa' => 'SELECT user_id FROM usuarios_piloto WHERE user_id = ? AND activo = 1',
        'sesion_crear' => 'INSERT INTO sesiones (token_hash, user_id, session_version, huella_clave, emitida_en, expira_en, ip_hash) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL ? SECOND), ?)',
        'sesion_vigente' => 'SELECT user_id, session_version, huella_clave FROM sesiones WHERE token_hash = ? AND revocada_en IS NULL AND expira_en > UTC_TIMESTAMP()',
        'sesion_revocar' => 'UPDATE sesiones SET revocada_en = UTC_TIMESTAMP(), motivo_revocacion = ? WHERE token_hash = ? AND revocada_en IS NULL',
        'sesiones_revocar_usuario' => 'UPDATE sesiones SET revocada_en = UTC_TIMESTAMP(), motivo_revocacion = ? WHERE user_id = ? AND revocada_en IS NULL',
        'sesiones_purgar' => 'DELETE FROM sesiones WHERE expira_en < DATE_SUB(UTC_TIMESTAMP(), INTERVAL 7 DAY)',
        'intentos_leer' => 'SELECT fallos, primer_fallo, bloqueado_hasta FROM intentos_login WHERE clave_hash = ?',
        'intentos_guardar' => 'INSERT INTO intentos_login (clave_hash, tipo, fallos, primer_fallo, bloqueado_hasta) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE fallos = VALUES(fallos), primer_fallo = VALUES(primer_fallo), bloqueado_hasta = VALUES(bloqueado_hasta)',
        'intentos_borrar' => 'DELETE FROM intentos_login WHERE clave_hash = ?',
        'auditoria_registrar' => 'INSERT INTO auditoria (ocurrido_en, evento, user_id, reporte_id, clave_hash, ip_hash) VALUES (UTC_TIMESTAMP(), ?, ?, ?, ?, ?)',
    ];
}

/** Sentencias de administración: SOLO las admite la CLI (backend/cli/piloto-usuarios.php), nunca el proceso web. */
function estado_catalogo_administracion() {
    return [
        'lista_blanca_listar' => 'SELECT user_id, nota, activo, creado_en FROM usuarios_piloto ORDER BY user_id',
        'lista_blanca_agregar' => 'INSERT INTO usuarios_piloto (user_id, nota, activo, creado_en, creado_por) VALUES (?, ?, 1, UTC_TIMESTAMP(), ?) ON DUPLICATE KEY UPDATE activo = 1, nota = VALUES(nota)',
        'lista_blanca_quitar' => 'UPDATE usuarios_piloto SET activo = 0 WHERE user_id = ?',
        'sesiones_activas' => 'SELECT user_id, emitida_en, expira_en FROM sesiones WHERE revocada_en IS NULL AND expira_en > UTC_TIMESTAMP() ORDER BY emitida_en',
        'sesiones_revocar_todas' => 'UPDATE sesiones SET revocada_en = UTC_TIMESTAMP(), motivo_revocacion = ? WHERE revocada_en IS NULL',
        'auditoria_ultimos' => 'SELECT id, ocurrido_en, evento, user_id, reporte_id FROM auditoria ORDER BY id DESC LIMIT 50',
    ];
}

function estado_sentencias($admin = false) {
    $all = array_values(estado_catalogo_consultas());
    if ($admin) {
        foreach (estado_catalogo_administracion() as $sql) { $all[] = $sql; }
    }
    return $all;
}

/** PDO de la base de estado: solo ejecuta sentencias del catálogo (texto normalizado idéntico). */
class EstadoPDO extends PDO {
    /** @var array<string,true> */
    private $permitidas = [];

    public function fijarCatalogo(array $sentencias) {
        if ($this->permitidas !== []) {
            throw new ReadOnlyViolation('El catálogo de estado ya está fijado');
        }
        $this->permitidas = app_sql_catalog($sentencias);
    }

    private function revisar($sql) {
        $n = app_sql_normalize($sql);
        if (!isset($this->permitidas[$n]) && !in_array($n, ['SELECT DATABASE()', 'SHOW GRANTS FOR CURRENT_USER()'], true)) {
            throw new ReadOnlyViolation('Sentencia fuera del catálogo del estado del piloto');
        }
    }

    public function prepare(string $query, array $options = []): PDOStatement|false { $this->revisar($query); return parent::prepare($query, $options); }
    public function exec(string $statement): int|false { $this->revisar($statement); return parent::exec($statement); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false { $this->revisar($query); return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs); }
}

/**
 * Privilegios de la cuenta de estado: solo USAGE global y SELECT/INSERT/UPDATE/DELETE sobre SU base. Cualquier otra cosa (otra base, DDL, GRANT OPTION, roles) → violación.
 * @param string[] $grants
 * @return string[]
 */
function estado_grants_violations(array $grants, $stateDb) {
    $violations = [];
    foreach ($grants as $line) {
        if (!preg_match('/^GRANT\s+(.+?)\s+ON\s+(.+?)\s+TO\s+/is', trim((string)$line), $m)) { $violations[] = 'concesión no auditable'; continue; }
        $privs = array_map(fn ($p) => strtoupper(trim($p)), explode(',', preg_replace('/\([^)]*\)/', '', $m[1])));
        $on = str_replace('`', '', trim($m[2]));
        if ($on === '*.*') {
            foreach ($privs as $p) { if ($p !== 'USAGE') { $violations[] = "global:$p"; } }
        } elseif ($on === $stateDb . '.*') {
            foreach ($privs as $p) { if (!in_array($p, ['SELECT', 'INSERT', 'UPDATE', 'DELETE'], true)) { $violations[] = $p; } }
        } else {
            $violations[] = "otra base: $on";
        }
        if (preg_match('/\bWITH\s+GRANT\s+OPTION\b/i', (string)$line)) { $violations[] = 'GRANT OPTION'; }
    }
    return array_values(array_unique($violations));
}

// ---------------------------------------------------------------- operaciones (todas parametrizadas; ningún secreto sale de aquí)
function estado_huella($valor) { return hash_hmac('sha256', (string)$valor, (string)getenv('CERVESUR_PILOT_SECRET')); }

function estado_ip() { return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'); }

function estado_ejecutar(PDO $pdo, $clave, array $params) {
    $st = $pdo->prepare(estado_catalogo_consultas()[$clave] ?? estado_catalogo_administracion()[$clave]);
    $i = 1;
    foreach ($params as $v) { $st->bindValue($i++, $v, is_int($v) ? PDO::PARAM_INT : (is_null($v) ? PDO::PARAM_NULL : PDO::PARAM_STR)); }
    $st->execute();
    return $st;
}

function estado_auditar(PDO $pdo, $evento, $userId = null, $reporteId = null, $login = null) {
    estado_ejecutar($pdo, 'auditoria_registrar', [$evento, $userId, $reporteId, $login === null ? null : estado_huella(strtolower($login)), estado_huella(estado_ip())]);
}

const ESTADO_MAX_FALLOS_LOGIN = 5;
const ESTADO_MAX_FALLOS_IP = 20;
const ESTADO_VENTANA = 900;
const ESTADO_BLOQUEO = 900;

/** Segundos de bloqueo restantes para esta cuenta o esta IP (0 = libre). */
function estado_bloqueo(PDO $pdo, $login) {
    $ahora = time(); $restante = 0;
    foreach ([estado_huella('login:' . strtolower($login)), estado_huella('ip:' . estado_ip())] as $clave) {
        $fila = estado_ejecutar($pdo, 'intentos_leer', [$clave])->fetch(PDO::FETCH_ASSOC);
        if ($fila && $fila['bloqueado_hasta'] !== null && (int)$fila['bloqueado_hasta'] > $ahora) { $restante = max($restante, (int)$fila['bloqueado_hasta'] - $ahora); }
    }
    return $restante;
}

function estado_registrar_fallo(PDO $pdo, $login) {
    $ahora = time();
    foreach ([['login', estado_huella('login:' . strtolower($login)), ESTADO_MAX_FALLOS_LOGIN], ['ip', estado_huella('ip:' . estado_ip()), (int)(getenv('CERVESUR_PILOT_MAX_FALLOS_IP') ?: ESTADO_MAX_FALLOS_IP)]] as [$tipo, $clave, $max]) {
        $fila = estado_ejecutar($pdo, 'intentos_leer', [$clave])->fetch(PDO::FETCH_ASSOC);
        $fallos = 1; $primer = $ahora;
        if ($fila && (int)$fila['primer_fallo'] + ESTADO_VENTANA >= $ahora) { $fallos = (int)$fila['fallos'] + 1; $primer = (int)$fila['primer_fallo']; }
        estado_ejecutar($pdo, 'intentos_guardar', [$clave, $tipo, $fallos, $primer, $fallos >= $max ? $ahora + ESTADO_BLOQUEO : null]);
    }
}

function estado_limpiar_fallos(PDO $pdo, $login) {
    estado_ejecutar($pdo, 'intentos_borrar', [estado_huella('login:' . strtolower($login))]);
}
