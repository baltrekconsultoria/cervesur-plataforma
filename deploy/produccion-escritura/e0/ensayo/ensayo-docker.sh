#!/usr/bin/env bash
# Cervesur · UX-08H · Ensayo en Docker de E0 y de las migraciones (SOLO para el equipo de desarrollo; NO se empaqueta; no toca ningun servidor real).
# Reproduce produccion: MySQL 8.0 con --log-bin y log_bin_trust_function_creators=0, esquema de tests/isolated/productive_fixture.php + seed_writes.php (base fixture_e0),
# y un contenedor "servidor" (Ubuntu 24.04 con mysql, php, ss, openssl...) que comparte la red de MySQL (el cliente conecta por 127.0.0.1 como en produccion).
# Uso (Git Bash, desde cualquier carpeta):  bash deploy/produccion-escritura/e0/ensayo/ensayo-docker.sh [escenario...]   (sin argumentos: todos)
# Escenarios: estatico e0 simular respaldo fallos sinsuper aplicar idempotencia revertir
# Imagenes: mysql:8.0, cervesur-ci-php:local, cervesur-ensayo-ubuntu:local.  Limpia sus contenedores/red/temporales al terminar (trap).
set -u
export MSYS_NO_PATHCONV=1
AQUI="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
RAIZ="$(cd "$AQUI/../../../.." && pwd)"
RAIZ_W="$(cd "$RAIZ" && pwd -W 2>/dev/null || pwd)"
NET=cervesur-e0net; DBC=cervesur-e0-db; HOSTC=cervesur-e0-host
ROOTPW="clave-sintetica-de-ensayo"; DBAPW="dba-sintetica-de-ensayo"
FIX=fixture_e0
PKG="$(mktemp -d)"
SNAPS="$(mktemp -d)"
PASAN=0; FALLAN=0
HX_ENV=()
AUTH="ensayo docker autorizado por prueba"

limpiar() { docker rm -f "$HOSTC" "$DBC" >/dev/null 2>&1; docker network rm "$NET" >/dev/null 2>&1; rm -rf "$PKG" "$SNAPS"; }
trap limpiar EXIT

pasa() { PASAN=$((PASAN + 1)); echo "  [OK]    $1"; }
falla() { FALLAN=$((FALLAN + 1)); echo "  [FALLO] $1"; }
chk_eq() { if [ "$2" = "$3" ]; then pasa "$1 (= $3)"; else falla "$1 (obtenido '$2', esperado '$3')"; fi; }
chk_cmd() { local d="$1"; shift; if "$@" >/dev/null 2>&1; then pasa "$d"; else falla "$d"; fi; }

# hx <orden>: ejecuta en el "servidor" como root con el MySQL del ensayo
hx() { docker exec -e MYSQL_PWD="$ROOTPW" -e CERVESUR_MYSQL_CMD="mysql -h 127.0.0.1 -uroot" -e CERVESUR_MYSQLDUMP_CMD="mysqldump -h 127.0.0.1 -uroot" "${HX_ENV[@]}" "$HOSTC" bash -c "$1"; }
hxrc() { hx "$1 >/dev/null 2>&1; echo \$?"; }
sql() { docker exec -e MYSQL_PWD="$ROOTPW" "$HOSTC" mysql -h 127.0.0.1 -uroot -N -B -e "$1"; }
snap() { hx "bash /srv/ensayo/snap.sh $FIX"; }
titulo() { echo; echo "################ $*"; }
negocio() { grep -E '^fixture_e0\.(user|empresa|reporte|empresa_usuario|empresa_reporte|user_empresa_reporte)[[:space:]]' "$1"; }

preparar_entorno() {
  titulo "Entorno: MySQL 8.0 con log_bin=1, log_bin_trust_function_creators=0"
  docker network create "$NET" >/dev/null
  docker run -d --name "$DBC" --network "$NET" --network-alias db -e MYSQL_ROOT_PASSWORD="$ROOTPW" mysql:8.0 \
    --log-bin --server-id=1 --binlog-format=ROW --log-bin-trust-function-creators=0 >/dev/null
  local i=0
  while [ "$i" -lt 60 ]; do docker exec "$DBC" mysqladmin ping -h 127.0.0.1 -uroot -p"$ROOTPW" --silent >/dev/null 2>&1 && break; i=$((i + 1)); sleep 2; done
  docker run -d --name "$HOSTC" --network "container:$DBC" --entrypoint sleep cervesur-ensayo-ubuntu:local infinity >/dev/null
  sql "SELECT CONCAT('version=', @@version, ' log_bin=', @@log_bin, ' trust=', @@log_bin_trust_function_creators, ' binlog_format=', @@binlog_format)"
  docker run --rm --network "$NET" --entrypoint php -e TEST_DB_PASS="$ROOTPW" -e FIXTURE_DB="$FIX" -v "$RAIZ_W/tests/isolated:/t:ro" cervesur-ci-php:local /t/seed_writes.php
  # paquete tal como lo armaria el empaquetador (sin e0/ensayo)
  mkdir -p "$PKG/e0" "$PKG/migraciones"
  (cd "$RAIZ/deploy/produccion-escritura/e0" && tar --exclude=./ensayo -cf - .) | (cd "$PKG/e0" && tar -xf -)
  (cd "$RAIZ/deploy/produccion-escritura/migraciones" && tar -cf - .) | (cd "$PKG/migraciones" && tar -xf -)
  mkdir -p "$PKG/migraciones/repo" "$PKG/e0/repo"
  cp "$RAIZ"/database/migrations/UX-08B-audit-log.up.sql "$RAIZ"/database/migrations/UX-08B-audit-log.down.sql "$RAIZ"/database/migrations/UX-08B-audit-log-immutable.up.sql \
     "$RAIZ"/database/migrations/UX-08B-audit-log-immutable.down.sql "$RAIZ"/database/privileges/UX-08H1-cuenta-escritura-fase2a.sql.template "$PKG/migraciones/repo/"
  cp "$RAIZ"/database/migrations/UX-08B-audit-log-immutable.up.sql "$PKG/e0/repo/"
  (cd "$PKG/migraciones/repo" && sha256sum UX-08B-audit-log.up.sql UX-08B-audit-log.down.sql UX-08B-audit-log-immutable.up.sql UX-08B-audit-log-immutable.down.sql UX-08H1-cuenta-escritura-fase2a.sql.template >SHA256SUMS)
  docker exec "$HOSTC" mkdir -p /srv/paquete /srv/ensayo
  docker cp "$(cygpath -m "$PKG")/e0" "$HOSTC:/srv/paquete/e0"; docker cp "$(cygpath -m "$PKG")/migraciones" "$HOSTC:/srv/paquete/migraciones"
  docker cp "$(cygpath -m "$AQUI")/snap.sh" "$HOSTC:/srv/ensayo/snap.sh"; docker cp "$(cygpath -m "$AQUI")/servidor-ficticio.sh" "$HOSTC:/srv/ensayo/servidor-ficticio.sh"
  hx 'chmod 755 /srv/paquete/e0/*.sh /srv/paquete/migraciones/*.sh; ls /srv/paquete/e0 /srv/paquete/migraciones | tr "\n" " "; echo'
  hx "bash /srv/ensayo/servidor-ficticio.sh $FIX"
}

e_estatico() {
  titulo "Pruebas estaticas dentro del servidor (sin MySQL)"
  hx 'cd /srv/paquete/e0 && bash probar-e0.sh | tail -2; cd ../migraciones && bash probar-migraciones.sh | tail -2'
  chk_eq "probar-e0.sh rc" "$(hxrc 'cd /srv/paquete/e0 && bash probar-e0.sh')" 0
  chk_eq "probar-migraciones.sh rc" "$(hxrc 'cd /srv/paquete/migraciones && bash probar-migraciones.sh')" 0
  chk_eq "bash -n sobre todos los scripts del paquete" "$(hx 'n=0; for f in /srv/paquete/*/*.sh; do bash -n "$f" || n=1; done; echo $n')" 0
}

e_e0() {
  titulo "E0 contra el MySQL del ensayo"
  snap >"$SNAPS/antes-e0.txt"
  local sha
  sha="$(hx 'sha256sum /var/www/cervesur-react-admin/SHA256SUMS | cut -c1-12')"
  echo "sha esperado (calculado del SHA256SUMS ficticio): $sha"
  hx "rm -rf /tmp/ev-e0; bash /srv/paquete/e0/e0-auditoria-fase2a.sh --db $FIX --esperado-sha $sha --paquete /tmp/candidato --evidencia /tmp/ev-e0 --umbral-mb 10 --cuentas-escritura-esperadas root" | tail -42
  hx 'ls /tmp/ev-e0 | tr "\n" " "; echo; for f in /tmp/ev-e0/*.txt; do tail -1 $f | grep -q "^EXIT_CODE=" || echo SIN-EXIT-CODE $f; done; echo "(todos los archivos terminan en EXIT_CODE=)"'
  snap >"$SNAPS/despues-e0.txt"
  titulo "E0: extractos de la evidencia"
  hx 'sed -n 1,16p /tmp/ev-e0/01-admin-8443.txt; echo ....; tail -14 /tmp/ev-e0/03-esquema.txt; echo ....; sed -n 1,30p /tmp/ev-e0/12-flutter-escrituras.txt'
  titulo "E0: la evidencia no contiene secretos, enlaces ni valores del .env"
  hx 'cd /tmp/ev-e0; for s in SecretoSinteticoDeEnsayo jwt-secreto-sintetico "app.powerbi.com" eyJrIjoi aaaaaaaa-0000 bbbbbbbb-0000 synthetic-password fixture-private-w1 tenant-w1 client-w1; do if grep -rqF -- "$s" .; then echo "FUGA: $s"; else echo "limpio: $s"; fi; done; cat 98-residuos-sensibles.txt | head -3; stat -c "modo carpeta=%a" .; stat -c "modo archivo=%a" 01-admin-8443.txt'
  chk_eq "sin fugas de los valores sinteticos en la evidencia" "$(hx 'cd /tmp/ev-e0; n=0; for s in SecretoSinteticoDeEnsayo jwt-secreto-sintetico app.powerbi.com eyJrIjoi aaaaaaaa-0000 fixture-private-w1 tenant-w1; do grep -rqF -- "$s" . && n=$((n+1)); done; echo $n')" 0
  chk_cmd "USER_ADMIN_WRITES ausente detectado" hx 'grep -q "USER_ADMIN_WRITES ausente" /tmp/ev-e0/01-admin-8443.txt'
  titulo "E0: variantes (sha equivocado, USER_ADMIN_WRITES presente, paquete con error de sintaxis) deben dar FAIL"
  hx 'cp /opt/cervesur-react-admin/backend/.env /tmp/env.bak; echo "USER_ADMIN_WRITES=1" >> /opt/cervesur-react-admin/backend/.env; echo "<?php function (" > /tmp/candidato/lib/roto.php
bash /srv/paquete/e0/e0-auditoria-fase2a.sh --db '"$FIX"' --esperado-sha 3f859210c5c4 --paquete /tmp/candidato --evidencia /tmp/ev-e0b --umbral-mb 10 >/dev/null 2>&1; grep -E "^ITEM (1|11) +FAIL" /tmp/ev-e0b/99-resumen-E0.txt | cut -c1-150; grep RESULTADO= /tmp/ev-e0b/99-resumen-E0.txt
cp /tmp/env.bak /opt/cervesur-react-admin/backend/.env; rm -f /tmp/candidato/lib/roto.php'
  if cmp -s "$SNAPS/antes-e0.txt" "$SNAPS/despues-e0.txt"; then pasa "E0 no cambio nada: CHECKSUM de todas las tablas + conteos de objetos + cuentas + huella de grants identicos antes/despues"; else falla "E0 cambio algo"; diff "$SNAPS/antes-e0.txt" "$SNAPS/despues-e0.txt"; fi
  echo "instantanea previa:"; sed 's/^/   /' "$SNAPS/antes-e0.txt" | head -24
}

M="bash /srv/paquete/migraciones/aplicar-migraciones.sh --db $FIX --bitacora-base /tmp/bit"
RESP='$(ls /tmp/resp/*.sql.gz | head -1)'
APLICA="--aplicar --respaldo $RESP --confirmo-autorizacion '$AUTH'"

e_simular() {
  titulo "aplicar-migraciones.sh --simular (no debe cambiar nada)"
  snap >"$SNAPS/antes-sim.txt"
  hx "$M --simular" 2>&1 | cut -c1-230
  chk_eq "--simular sin respaldo: rc (sin bloqueos; solo avisa)" "$(hxrc "$M --simular")" 0
  snap >"$SNAPS/despues-sim.txt"
  if cmp -s "$SNAPS/antes-sim.txt" "$SNAPS/despues-sim.txt"; then pasa "--simular no cambio nada (CHECKSUM + conteos + cuentas + grants identicos)"; else falla "--simular cambio algo"; diff "$SNAPS/antes-sim.txt" "$SNAPS/despues-sim.txt"; fi
  chk_eq "--simular no creo bitacora ni carpeta de secretos" "$(hx 'test -e /tmp/bit; echo $?')" 1
}

e_respaldo() {
  titulo "crear-respaldo.sh --probar-restauracion"
  hx "bash /srv/paquete/migraciones/crear-respaldo.sh --db $FIX --destino /tmp/resp --probar-restauracion" 2>&1 | cut -c1-200
  hx 'ls -l /tmp/resp; stat -c "%a %n" /tmp/resp /tmp/resp/*; cat /tmp/resp/*.manifest'
  chk_eq "esquema temporal de verificacion eliminado" "$(sql "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name LIKE 'cervesur_verif%'")" 0
  chk_eq "crear-respaldo rc (segundo respaldo, sin borrar el anterior)" "$(hxrc "sleep 1; bash /srv/paquete/migraciones/crear-respaldo.sh --db $FIX --destino /tmp/resp")" 0
  chk_eq "hay dos respaldos (el segundo no borra el primero)" "$(hx 'ls /tmp/resp/*.sql.gz | wc -l')" 2
}

e_fallos() {
  titulo "Inyeccion de fallos (cada uno debe bloquear ANTES de escribir; nada cambia)"
  # el ensayo anterior pudo borrar manifiestos; se rehace un respaldo limpio
  hx 'rm -rf /tmp/resp'
  hx "bash /srv/paquete/migraciones/crear-respaldo.sh --db $FIX --destino /tmp/resp --probar-restauracion" >/dev/null 2>&1
  snap >"$SNAPS/antes-fallos.txt"
  local f
  for f in respaldo-corrupto sin-super esquema-distinto cuenta-existe; do
    echo "--- CERVESUR_INYECTAR_FALLO=$f"
    HX_ENV=(-e CERVESUR_INYECTAR_FALLO="$f")
    hx "$M $APLICA" 2>&1 | grep -E 'FAIL|ensayo|RESULTADO|ABORT' | cut -c1-220
    chk_eq "$f -> rc (bloqueo antes de escribir)" "$(hxrc "$M $APLICA")" 10
    HX_ENV=()
  done
  echo "--- sin --respaldo"
  chk_eq "--aplicar sin --respaldo -> rc" "$(hxrc "$M --aplicar --confirmo-autorizacion '$AUTH'")" 10
  echo "--- sin --confirmo-autorizacion"
  chk_eq "--aplicar sin --confirmo-autorizacion -> rc" "$(hxrc "$M --aplicar --respaldo $RESP")" 10
  echo "--- manifiesto con checksum alterado"
  hx 'cp /tmp/resp/*.manifest /tmp/m.bak; sed -i "s/^tabla.empresa.checksum=.*/tabla.empresa.checksum=1/" /tmp/resp/*.manifest'
  hx "$M $APLICA" 2>&1 | grep -E 'FAIL|CAMBI' | cut -c1-200
  chk_eq "checksum distinto -> rc" "$(hxrc "$M $APLICA")" 10
  hx 'cp /tmp/m.bak /tmp/resp/*.manifest'
  echo "--- archivo del respaldo alterado (SHA-256 distinto)"
  hx 'cp /tmp/resp/*.sql.gz /tmp/r.bak; printf x >> /tmp/resp/*.sql.gz'
  hx "$M $APLICA" 2>&1 | grep -E 'FAIL' | cut -c1-200
  chk_eq "archivo alterado -> rc" "$(hxrc "$M $APLICA")" 10
  hx 'cp /tmp/r.bak /tmp/resp/*.sql.gz'
  echo "--- tabla de negocio modificada despues del respaldo"
  sql "UPDATE $FIX.empresa SET ciudad='Cambiada' WHERE id=3"
  hx "$M $APLICA" 2>&1 | grep -E 'CAMBI|FAIL' | cut -c1-200
  chk_eq "tabla cambiada tras el respaldo -> rc (tolerancia cero)" "$(hxrc "$M $APLICA")" 10
  sql "UPDATE $FIX.empresa SET ciudad='Ciudad C' WHERE id=3"
  snap >"$SNAPS/despues-fallos.txt"
  if cmp -s "$SNAPS/antes-fallos.txt" "$SNAPS/despues-fallos.txt"; then pasa "ningun fallo cambio la base (CHECKSUM + objetos + cuentas + grants identicos)"; else falla "los fallos cambiaron algo"; diff "$SNAPS/antes-fallos.txt" "$SNAPS/despues-fallos.txt"; fi
  chk_eq "no existe audit_log" "$(sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$FIX' AND table_name='audit_log'")" 0
  chk_eq "no existe la cuenta de escritura" "$(sql "SELECT COUNT(*) FROM mysql.user WHERE user LIKE 'cervesur_escritura%'")" 0
}

e_sinsuper() {
  titulo "Variante sin SUPER (cuenta del DBA con ALL sobre la base, sin SUPER)"
  sql "CREATE USER IF NOT EXISTS 'dba_ns'@'%' IDENTIFIED BY '$DBAPW'; GRANT ALL ON $FIX.* TO 'dba_ns'@'%' WITH GRANT OPTION; GRANT CREATE USER ON *.* TO 'dba_ns'@'%'; GRANT SELECT ON mysql.user TO 'dba_ns'@'%'; GRANT ALL ON scratch_ns.* TO 'dba_ns'@'%';"
  HX_ENV=(-e MYSQL_PWD="$DBAPW" -e CERVESUR_MYSQL_CMD="mysql -h 127.0.0.1 -udba_ns" -e CERVESUR_MYSQLDUMP_CMD="mysqldump -h 127.0.0.1 -udba_ns")
  hx "$M --simular" 2>&1 | cut -c1-230
  chk_eq "--simular como cuenta sin SUPER -> rc" "$(hxrc "$M --simular")" 10
  titulo "Que pasaria de verdad: M1 y M2 como esa cuenta en una base temporal scratch_ns"
  sql "CREATE DATABASE IF NOT EXISTS scratch_ns"
  hx 'cd /srv/paquete/migraciones/repo; mysql -h 127.0.0.1 -udba_ns scratch_ns < UX-08B-audit-log.up.sql && echo M1-ok-sin-super; mysql -h 127.0.0.1 -udba_ns scratch_ns < UX-08B-audit-log-immutable.up.sql 2>&1 | cut -c1-200; true'
  chk_eq "CREATE TRIGGER sin SUPER falla con ERROR 1419 (el pre-chequeo lo anticipaba)" "$(hx 'mysql -h 127.0.0.1 -udba_ns scratch_ns < /srv/paquete/migraciones/repo/UX-08B-audit-log-immutable.up.sql 2>&1 | grep -c "ERROR 1419"')" 1
  HX_ENV=()
  sql "DROP DATABASE IF EXISTS scratch_ns; DROP USER IF EXISTS 'dba_ns'@'%'"
}

e_aplicar() {
  titulo "--aplicar real (con respaldo verificado)"
  snap >"$SNAPS/antes-ap.txt"
  hx "$M $APLICA" 2>&1 | cut -c1-240
  titulo "Efectos"
  sql "SELECT CONCAT(trigger_name,' ',action_timing,' ',event_manipulation) FROM information_schema.triggers WHERE trigger_schema='$FIX'"
  sql "SHOW GRANTS FOR 'cervesur_escritura_adm'@'127.0.0.1'" | sed -E 's/\(([^)]{60})[^)]*\)/(\1...)/' | cut -c1-170
  titulo "Archivo de clave: solo ruta, modo y dueno; el contenido jamas aparece en las salidas"
  hx 'ls -l /tmp/bit/secretos/; stat -c "%a %U:%G %n" /tmp/bit /tmp/bit/secretos /tmp/bit/secretos/*.clave /tmp/bit/migraciones-*/ /tmp/bit/migraciones-*/bitacora.log'
  chk_eq "clave: modo y dueno" "$(hx 'stat -c %a:%U /tmp/bit/secretos/cervesur_escritura_adm.clave')" "600:root"
  chk_eq "bitacora: carpeta 700 y archivo 600" "$(hx 'd=$(ls -d /tmp/bit/migraciones-* | tail -1); echo $(stat -c %a $d):$(stat -c %a $d/bitacora.log)')" "700:600"
  chk_eq "la clave no aparece en la bitacora" "$(hx 'P=$(sed -n "s/^DB_PASS=//p" /tmp/bit/secretos/cervesur_escritura_adm.clave); grep -rqF -- "$P" /tmp/bit/migraciones-*/ && echo fuga || echo limpia')" limpia
  hx 'P=$(sed -n "s/^DB_PASS=//p" /tmp/bit/secretos/cervesur_escritura_adm.clave); echo "longitud de la clave=${#P}"'
  titulo "Bitacora (sentencias y comprobaciones)"
  hx 'grep -E "sentencia|FAIL|PASS" /tmp/bit/migraciones-*/bitacora.log | cut -c1-200 | head -60'
  snap >"$SNAPS/despues-ap.txt"
  if cmp -s <(negocio "$SNAPS/antes-ap.txt") <(negocio "$SNAPS/despues-ap.txt"); then pasa "las seis tablas de negocio quedaron identicas tras aplicar (CHECKSUM)"; else falla "las tablas de negocio cambiaron"; fi
  titulo "--verificar"
  hx "$M --verificar" 2>&1 | cut -c1-240
  chk_eq "--verificar rc" "$(hxrc "$M --verificar")" 0
  chk_eq "las pruebas transaccionales no dejaron filas (audit_log vacia)" "$(sql "SELECT COUNT(*) FROM $FIX.audit_log")" 0
  echo "AUTO_INCREMENT de audit_log (los INSERT de prueba revertidos consumen ids): $(sql "SELECT auto_increment FROM information_schema.tables WHERE table_schema='$FIX' AND table_name='audit_log'")"
}

e_idempotencia() {
  titulo "Re-ejecutar --aplicar (idempotencia)"
  hx "$M $APLICA" 2>&1 | grep -E 'FAIL|OMIT|omit|RESULTADO' | cut -c1-220
  chk_eq "segundo --aplicar sin --rotar-clave -> rc (la cuenta existe; no se recrea)" "$(hxrc "$M $APLICA")" 10
  hx 'cp /tmp/bit/secretos/cervesur_escritura_adm.clave /tmp/clave-antes'
  hx "$M $APLICA --rotar-clave" 2>&1 | grep -E 'omit|OMIT|rotar|APLICADO|FAIL' | cut -c1-200
  chk_eq "--aplicar --rotar-clave rc (M1 y M2 omitidas; la clave rota)" "$(hxrc "$M $APLICA --rotar-clave")" 0
  chk_eq "sigue habiendo exactamente 2 triggers sobre audit_log" "$(sql "SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema='$FIX' AND event_object_table='audit_log'")" 2
  chk_eq "la clave cambio y la anterior quedo como .anterior-*" "$(hx 'cmp -s /tmp/clave-antes /tmp/bit/secretos/cervesur_escritura_adm.clave && echo igual || echo distinta; ls /tmp/bit/secretos/*.anterior-* | wc -l')" "distinta
2"
  chk_eq "una sola cuenta de escritura" "$(sql "SELECT COUNT(*) FROM mysql.user WHERE user LIKE 'cervesur_escritura%'")" 1
}

e_revertir() {
  titulo "--revertir-auditoria con la cuenta viva (debe negarse)"
  chk_eq "--revertir-auditoria con la cuenta existente -> rc" "$(hxrc "$M --revertir-auditoria --confirmo-autorizacion '$AUTH'")" 10
  titulo "--revertir-cuenta"
  chk_eq "--revertir-cuenta sin autorizacion -> rc" "$(hxrc "$M --revertir-cuenta")" 10
  hx "$M --revertir-cuenta --confirmo-autorizacion '$AUTH'" 2>&1 | cut -c1-220
  chk_eq "la cuenta ya no existe" "$(sql "SELECT COUNT(*) FROM mysql.user WHERE user LIKE 'cervesur_escritura%'")" 0
  chk_eq "audit_log y sus 2 triggers siguen (la reversion de cuenta no toca la evidencia)" "$(sql "SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema='$FIX' AND event_object_table='audit_log'")" 2
  hx 'ls /tmp/bit/secretos/'
  sql "INSERT INTO $FIX.audit_log (correlation_id, actor_user_id, action, entity, entity_id) VALUES ('ensayoensayoensayoensayo000001', 1, 'demo', 'empresa', 1)"
  titulo "--revertir-auditoria (solo triggers; la fila de evidencia se conserva)"
  hx "$M --revertir-auditoria --confirmo-autorizacion '$AUTH'" 2>&1 | cut -c1-200
  chk_eq "triggers eliminados" "$(sql "SELECT COUNT(*) FROM information_schema.triggers WHERE trigger_schema='$FIX' AND event_object_table='audit_log'")" 0
  chk_eq "audit_log conserva su fila" "$(sql "SELECT COUNT(*) FROM $FIX.audit_log")" 1
  titulo "--revertir-auditoria con la tabla: sin segunda confirmacion NO borra; con ella, vuelca antes"
  chk_eq "sin --segundo-confirmo -> rc (no borra)" "$(hxrc "$M --revertir-auditoria --tambien-borrar-tabla-auditoria --confirmo-autorizacion '$AUTH'")" 10
  chk_eq "la tabla sigue existiendo" "$(sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$FIX' AND table_name='audit_log'")" 1
  hx "$M --revertir-auditoria --tambien-borrar-tabla-auditoria --segundo-confirmo 'BORRAR LA EVIDENCIA DE AUDITORIA' --confirmo-autorizacion '$AUTH'" 2>&1 | cut -c1-220
  chk_eq "tabla audit_log eliminada" "$(sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$FIX' AND table_name='audit_log'")" 0
  chk_eq "volcado previo de la evidencia con su fila" "$(hx 'zcat /tmp/bit/migraciones-*/audit_log-antes-de-borrar-*.sql.gz | grep -c "^INSERT INTO"')" 1
  snap >"$SNAPS/final.txt"
  if cmp -s <(negocio "$SNAPS/antes-e0.txt") <(negocio "$SNAPS/final.txt"); then pasa "las seis tablas de negocio terminaron identicas al inicio (CHECKSUM)"; else falla "las tablas de negocio difieren del inicio"; fi
}

ESCENARIOS=("$@")
[ "${#ESCENARIOS[@]}" -gt 0 ] || ESCENARIOS=(estatico e0 simular respaldo fallos sinsuper aplicar idempotencia revertir)
limpiar
SNAPS="$(mktemp -d)"; PKG="$(mktemp -d)"
preparar_entorno
for e in "${ESCENARIOS[@]}"; do "e_$e"; done
echo
echo "================ RESUMEN DEL ENSAYO: OK=$PASAN FALLO=$FALLAN"
[ "$FALLAN" -eq 0 ]
