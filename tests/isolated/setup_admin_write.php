<?php
// UX-08H: prepara en el MySQL DESECHABLE de las pruebas la instancia «admin_escritura» (fase 2), sobre la base sintética `fixture_aw` (ver seed_writes.php con FIXTURE_DB=fixture_aw):
//   1) migraciones reales de auditoría (UX-08B audit_log + UX-08B.2 triggers de solo inserción);
//   2) cuentas de lectura de autenticación y base de estado propias, ejecutando EXACTAMENTE database/schema/piloto-admin/01 y 02 con las cuentas renombradas (_aw) para no chocar con las del piloto;
//   3) la cuenta de ESCRITURA, ejecutando EXACTAMENTE la plantilla database/privileges/UX-08H-cuenta-escritura-produccion.sql.template;
//   4) la lista blanca de la instancia (usuarios_piloto) con el administrador id 1.
// Entorno: TEST_DB_PASS (root), W_AUTH_PASS, W_CAT_PASS, W_STATE_PASS, W_WRITE_PASS (claves generadas por el ejecutor; nunca fijas en el repositorio).
$root = new PDO('mysql:host=db;charset=utf8mb4', 'root', getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$base = 'fixture_aw';
$estado = 'estado_aw';
foreach (['W_AUTH_PASS', 'W_CAT_PASS', 'W_STATE_PASS', 'W_WRITE_PASS'] as $k) {
    if ((string)getenv($k) === '') { fwrite(STDERR, "Falta la clave $k" . PHP_EOL); exit(1); }
}
$statements = function (string $sql): array {
    $sql = preg_replace('/^\s*--.*$/m', '', $sql);
    return array_values(array_filter(array_map('trim', explode(';', $sql))));
};
$n = 0;
// 1) Auditoría: tabla y triggers reales sobre la base de la instancia.
$root->exec("USE `$base`");
foreach (['UX-08B-audit-log.up.sql', 'UX-08B-audit-log-immutable.up.sql'] as $file) {
    foreach ($statements((string)file_get_contents(__DIR__ . '/../../database/migrations/' . $file)) as $s) { $root->exec($s); $n++; }
}
// 1b) UX-08I (P4a): vista de ALTA de usuarios con WITH CHECK OPTION, creada por el DBA con la plantilla real. Debe existir ANTES de conceder INSERT sobre ella.
$root->exec("DROP USER IF EXISTS 'cervesur_vista_alta_aw'@'localhost'");
$vista = strtr((string)file_get_contents(__DIR__ . '/../../database/privileges/UX-08I-alta-usuarios-vista.up.sql.template'), ['{DB}' => $base, '{VISTA}' => 'cervesur_vista_alta_aw']);
// CREATE USER ... RANDOM PASSWORD devuelve la clave generada (de una cuenta bloqueada que nadie usa): se consume el resultado para poder seguir ejecutando sentencias
foreach ($statements($vista) as $s) { $r = $root->query($s); if ($r) { $r->fetchAll(); $r->closeCursor(); } $n++; }
// 2) Cuentas de lectura y base de estado propias (renombradas con sufijo _aw).
foreach (['cervesur_ro_auth_aw', 'cervesur_ro_catalogo_aw', 'cervesur_react_estado_aw', 'cervesur_escritura_aw', 'cervesur_escritura2a_aw'] as $u) { $root->exec("DROP USER IF EXISTS '$u'@'%'"); }
$root->exec("DROP DATABASE IF EXISTS `$estado`");
$map = ['__BASE__' => $base, '__BASE_ESTADO__' => $estado, '__HOST__' => '%', '__CLAVE_AUTH__' => getenv('W_AUTH_PASS'), '__CLAVE_CATALOGO__' => getenv('W_CAT_PASS'), '__CLAVE_ESTADO__' => getenv('W_STATE_PASS')];
foreach (['01-cuentas-lectura.sql', '02-estado-base-y-cuenta.sql'] as $file) {
    $sql = strtr((string)file_get_contents(__DIR__ . '/../../database/schema/piloto-admin/' . $file), $map);
    $sql = str_replace(['cervesur_ro_auth_adm', 'cervesur_ro_catalogo_adm', 'cervesur_react_estado_adm'], ['cervesur_ro_auth_aw', 'cervesur_ro_catalogo_aw', 'cervesur_react_estado_aw'], $sql);
    foreach ($statements($sql) as $s) { $root->exec($s); $n++; }
}
// 3) Cuenta de escritura desde la plantilla real.
$tpl = strtr((string)file_get_contents(__DIR__ . '/../../database/privileges/UX-08H-cuenta-escritura-produccion.sql.template'),
    ['{DB}' => $base, '{USER}' => 'cervesur_escritura_aw', '{HOST}' => '%', '{PASS}' => getenv('W_WRITE_PASS')]);
foreach ($statements($tpl) as $s) { $root->exec($s); $n++; }
// 3b) UX-08H.1: cuenta de la FASE 2A (sin escritura sobre `user`), desde su plantilla real. Opcional: solo si llega W_WRITE2A_PASS.
if ((string)getenv('W_WRITE2A_PASS') !== '') {
    $tpl2a = strtr((string)file_get_contents(__DIR__ . '/../../database/privileges/UX-08H1-cuenta-escritura-fase2a.sql.template'),
        ['{DB}' => $base, '{USER}' => 'cervesur_escritura2a_aw', '{HOST}' => '%', '{PASS}' => getenv('W_WRITE2A_PASS')]);
    foreach ($statements($tpl2a) as $s) { $root->exec($s); $n++; }
    // 3c) UX-08I: cuentas de la FASE 2B = plantilla 2A + plantillas ADITIVAS reales. 2b: usuarios NO sensibles; 2bs: además rol y contraseña. Misma clave de prueba que la 2A.
    $noSens = (string)file_get_contents(__DIR__ . '/../../database/privileges/UX-08I-usuarios-no-sensibles.up.sql.template');
    $sens = (string)file_get_contents(__DIR__ . '/../../database/privileges/UX-08I-usuarios-sensibles.up.sql.template');
    foreach (['cervesur_escritura2b_aw' => [$noSens], 'cervesur_escritura2bs_aw' => [$noSens, $sens]] as $acct => $layers) {
        $root->exec("DROP USER IF EXISTS '$acct'@'%'");
        $map2b = ['{DB}' => $base, '{USER}' => $acct, '{HOST}' => '%', '{PASS}' => getenv('W_WRITE2A_PASS')];
        foreach (array_merge([(string)file_get_contents(__DIR__ . '/../../database/privileges/UX-08H1-cuenta-escritura-fase2a.sql.template')], $layers) as $tplText) {
            foreach ($statements(strtr($tplText, $map2b)) as $s) { $root->exec($s); $n++; }
        }
    }
    // 3d) UX-08I (P4a): cuenta DEFECTUOSA a propósito (2A + UPDATE(username) sobre user): el arranque debe rechazarla (503) aunque tolere las concesiones escalonadas.
    $root->exec("DROP USER IF EXISTS 'cervesur_escritura2bx_aw'@'%'");
    $mapX = ['{DB}' => $base, '{USER}' => 'cervesur_escritura2bx_aw', '{HOST}' => '%', '{PASS}' => getenv('W_WRITE2A_PASS')];
    foreach ($statements(strtr((string)file_get_contents(__DIR__ . '/../../database/privileges/UX-08H1-cuenta-escritura-fase2a.sql.template'), $mapX)) as $s) { $root->exec($s); $n++; }
    $root->exec("GRANT UPDATE (username) ON `$base`.`user` TO 'cervesur_escritura2bx_aw'@'%'");
}
// 4) Lista blanca de la instancia.
$st = new PDO("mysql:host=db;dbname=$estado;charset=utf8mb4", 'root', getenv('TEST_DB_PASS'), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
$st->exec("INSERT INTO usuarios_piloto (user_id, nota, activo, creado_en, creado_por) VALUES (1, 'prueba', 1, UTC_TIMESTAMP(), 'test')");
echo "Instancia admin_escritura preparada: auditoría, cuentas (_aw), cuenta de escritura y lista blanca ($n sentencias)" . PHP_EOL;
