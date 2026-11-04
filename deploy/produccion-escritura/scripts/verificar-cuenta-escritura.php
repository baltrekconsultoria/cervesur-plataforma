<?php
// UX-08H.1 · Comprueba los privilegios de la CUENTA DE ESCRITURA DE LA FASE 2A con las MISMAS reglas que el backend aplica al arrancar
// (admin_write_grants_violations de backend/lib/admin_escritura.php: solo SELECT/INSERT/UPDATE/USAGE, sin GRANT OPTION, solo la base de negocio y, sobre audit_log, solo
// SELECT + INSERT) MÁS las reglas propias de la fase 2A (la fase 2B sigue bloqueada): ningún privilegio a nivel de base de datos, INSERT/UPDATE de `user` prohibidos salvo
// UPDATE(last_login), sin DELETE/ALL/TRIGGER/DDL.
// Uso:  SHOW GRANTS FOR '<cuenta>'@'<host>' | php verificar-cuenta-escritura.php <directorio-del-backend> <base-de-negocio>
// Lee las concesiones por la entrada estándar (una por línea). No recibe ni imprime ninguna contraseña. Salida: una violación por línea; código 0 = cuenta correcta, 1 = violaciones.
if (PHP_SAPI !== 'cli') { exit(2); }
$backend = $argv[1] ?? '';
$db = strtolower($argv[2] ?? '');
if (!is_file($backend . '/lib/mode.php') || $db === '') { fwrite(STDERR, "Uso: php verificar-cuenta-escritura.php <backend> <base>\n"); exit(2); }
require $backend . '/lib/mode.php';

$grants = array_values(array_filter(array_map('trim', explode("\n", (string)stream_get_contents(STDIN))), 'strlen'));
if (!$grants) { echo "sin concesiones (la cuenta no existe o no se pudo leer)\n"; exit(1); }
$violations = admin_write_grants_violations($grants, $db);

foreach ($grants as $line) {
    if (!preg_match('/^GRANT\s+(.+?)\s+ON\s+(.+?)\s+TO\s+/is', $line, $m)) { continue; }
    $parts = explode('.', strtolower(str_replace('`', '', trim($m[2]))));
    if (($parts[0] ?? '') !== $db) { continue; }
    $table = $parts[1] ?? '*';
    if ($table === '*') { $violations[] = 'privilegios a nivel de base de datos (solo se admiten por tabla/columna)'; continue; }
    // Privilegios con sus columnas: «SELECT (a, b), UPDATE (c)» → [['SELECT', ['a','b']], ['UPDATE', ['c']]]
    $items = [];
    foreach (preg_split('/,(?![^(]*\))/', $m[1]) as $chunk) {
        if (!preg_match('/^\s*([A-Za-z ]+?)\s*(?:\(([^)]*)\))?\s*$/', $chunk, $c)) { $violations[] = 'concesión no auditable'; continue; }
        $cols = isset($c[2]) ? array_values(array_filter(array_map(fn ($x) => strtolower(trim(str_replace('`', '', $x))), explode(',', $c[2])), 'strlen')) : [];
        $items[] = [strtoupper($c[1]), $cols];
    }
    foreach ($items as [$priv, $cols]) {
        if (in_array($priv, ['DELETE', 'ALL', 'ALL PRIVILEGES', 'TRIGGER', 'DROP', 'ALTER', 'CREATE', 'GRANT OPTION'], true)) { $violations[] = "$priv@$table"; }
        if ($table === 'user') {
            if ($priv === 'INSERT') { $violations[] = 'INSERT@user (fase 2B bloqueada)'; }
            if ($priv === 'UPDATE' && $cols !== ['last_login']) { $violations[] = 'UPDATE@user solo se admite sobre (last_login)'; }
        }
    }
}
$violations = array_values(array_unique($violations));
foreach ($violations as $v) { echo $v, "\n"; }
exit($violations ? 1 : 0);
