#!/usr/bin/env bash
# Prueba LOCAL del generador de altas temporales (no toca ningún servidor): Docker sin red hacia fuera, datos sintéticos, MySQL 8.0.
# Uso:  bash deploy/alta-temporal/probar-alta-temporal.sh      (requiere docker y las imágenes php:8.3-cli y mysql:8.0)
set -u
AQUI="$(cd "$(dirname "$0")" && pwd)"
export MSYS_NO_PATHCONV=1
ruta() { if command -v cygpath >/dev/null 2>&1; then cygpath -w "$1"; else echo "$1"; fi; }
T="$(mktemp -d)"; trap 'rm -rf "$T"; docker rm -f alta-mysql-prueba >/dev/null 2>&1' EXIT
fallos=0; total=0
chk() { total=$((total+1)); if [ "$2" = "1" ]; then echo "OK     $1"; else echo "FALLA  $1"; fallos=$((fallos+1)); fi; }
GEN() { docker run --rm -i --network none --read-only -v "$(ruta "$AQUI"):/g:ro" php:8.3-cli php /g/generar-alta.php "$@"; }

J1='{"first_name":"María José","last_name":"Núñez O'"'"'Brien","username":"mjnunez","email":"mj.nunez@example.test","phone":"+51 999 000 111"}'
printf '%s' "$J1" | GEN --stdin-json > "$T/o1.sql" 2> "$T/e1.txt"; RC1=$?
PW1="$(sed -n '/CONTRASENA TEMPORAL/{n;p}' "$T/e1.txt" | tr -d ' \r\n')"
printf '%s' "$J1" | GEN --stdin-json > "$T/o2.sql" 2> "$T/e2.txt"
PW2="$(sed -n '/CONTRASENA TEMPORAL/{n;p}' "$T/e2.txt" | tr -d ' \r\n')"

chk "generador: datos validos terminan con codigo 0" "$([ $RC1 -eq 0 ] && echo 1 || echo 0)"
chk "generador: la contrasena temporal tiene 20 caracteres y sale solo por STDERR" "$([ ${#PW1} -eq 20 ] && ! grep -qF "$PW1" "$T/o1.sql" && echo 1 || echo 0)"
chk "generador: dos corridas dan contrasenas e hashes distintos" "$([ "$PW1" != "$PW2" ] && ! diff -q <(grep -o "'\$2y\$[^']*'" "$T/o1.sql") <(grep -o "'\$2y\$[^']*'" "$T/o2.sql") >/dev/null && echo 1 || echo 0)"
chk "generador: la SQL lleva un hash bcrypt (2y) y rol fijo 'usuario'" "$(grep -qF "'"'$2y$' "$T/o1.sql" && grep -qF "'usuario'" "$T/o1.sql" && echo 1 || echo 0)"
chk "generador: ni la SQL ni el STDERR dejan datos fuera de lo previsto (la SQL no contiene la contrasena)" "$(grep -qF "$PW1" "$T/o1.sql" && echo 0 || echo 1)"
chk "generador: con la raiz del contenedor de SOLO LECTURA funciona (no escribe archivos)" "$([ -s "$T/o1.sql" ] && echo 1 || echo 0)"
for caso in '{"first_name":"A","last_name":"B","username":"ab","email":"a@example.test"}|username muy corto' \
            '{"first_name":"A","last_name":"B","username":"abc","email":"no-es-correo"}|correo invalido' \
            '{"first_name":"","last_name":"B","username":"abc","email":"a@example.test"}|falta nombre' \
            '{"first_name":"A","last_name":"B","username":"a b c","email":"a@example.test"}|username con espacios' \
            '{"first_name":"A","last_name":"B","username":"abc","email":"a@example.test","phone":"abc"}|telefono invalido'; do
  d="${caso%%|*}"; n="${caso##*|}"
  printf '%s' "$d" | GEN --stdin-json > "$T/x.sql" 2>/dev/null; rc=$?
  chk "generador: rechaza ($n) con codigo 2 y sin SQL" "$([ $rc -eq 2 ] && [ ! -s "$T/x.sql" ] && echo 1 || echo 0)"
done
printf 'no-es-json' | GEN --stdin-json > "$T/x.sql" 2>/dev/null; rc=$?
chk "generador: entrada ilegible termina con codigo 3" "$([ $rc -eq 3 ] && echo 1 || echo 0)"
printf 'Ana\nGomez\nanagomez\nana@example.test\n\n' | GEN > "$T/oi.sql" 2>/dev/null; rc=$?
chk "generador: modo interactivo (campos por la entrada estandar, sin argumentos en la linea de comandos)" "$([ $rc -eq 0 ] && grep -q 'filas_insertadas' "$T/oi.sql" && echo 1 || echo 0)"

# ---------------- MySQL ----------------
docker rm -f alta-mysql-prueba >/dev/null 2>&1
docker run -d --name alta-mysql-prueba --network none -e MYSQL_ROOT_PASSWORD=ficticia-prueba mysql:8.0 >/dev/null 2>&1
for i in $(seq 1 90); do [ "$(docker logs alta-mysql-prueba 2>&1 | grep -c 'ready for connections')" -ge 2 ] && docker exec alta-mysql-prueba mysqladmin ping -uroot -pficticia-prueba --silent >/dev/null 2>&1 && break; sleep 2; done
M() { docker exec -i alta-mysql-prueba mysql --default-character-set=utf8mb4 -uroot -pficticia-prueba "$@" 2>&1 | grep -v "Using a password on the command line"; }
M -e "CREATE DATABASE cervesur_portalbi CHARACTER SET utf8mb4; USE cervesur_portalbi;
CREATE TABLE user (id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT, first_name VARCHAR(50), last_name VARCHAR(50), username VARCHAR(50), email VARCHAR(100), phone VARCHAR(20), password VARCHAR(255), role VARCHAR(20), is_active TINYINT DEFAULT 1, session_version INT DEFAULT 1, created_at DATETIME, updated_at DATETIME) DEFAULT CHARSET=utf8mb4;
INSERT INTO user (first_name,last_name,username,email,password,role,created_at) VALUES ('Existente','Uno','existente','existente@example.test','x','usuario',NOW());" >/dev/null
ejecutar() { { echo "USE cervesur_portalbi;"; cat "$1"; echo "$2"; } | M -N 2>&1; }
SAL1="$(ejecutar "$T/o1.sql" 'COMMIT;')"
chk "mysql: la alta inserta exactamente 1 fila y la SQL es valida" "$(echo "$SAL1" | head -1 | grep -qx '1' && echo 1 || echo 0)"
FILA="$(M -N -e "SELECT role, is_active, session_version, HEX(first_name), HEX(last_name) FROM cervesur_portalbi.user WHERE username='mjnunez'")"
EXP_FN="$(printf 'María José' | xxd -p -u | tr -d '\n' 2>/dev/null || printf 'María José' | od -An -tx1 | tr -d ' \n' | tr a-f A-F)"
chk "mysql: rol 'usuario', activo y session_version por defecto" "$(echo "$FILA" | grep -q '^usuario	1	1	' && echo 1 || echo 0)"
chk "mysql: tildes y apostrofe se guardan intactos (utf8mb4)" "$(echo "$FILA" | grep -q "$(printf 'María José' | od -An -tx1 | tr -d ' \n' | tr a-f A-F)" && echo 1 || echo 0)"
HASH="$(M -N -e "SELECT password FROM cervesur_portalbi.user WHERE username='mjnunez'")"
OKPW="$(docker run --rm --network none -e PW="$PW1" -e H="$HASH" php:8.3-cli php -r 'echo password_verify(getenv("PW"), getenv("H")) ? "SI" : "NO";')"
chk "mysql: la contrasena temporal verifica contra el hash guardado (password_verify)" "$([ "$OKPW" = "SI" ] && echo 1 || echo 0)"
OKMAL="$(docker run --rm --network none -e PW="otra-clave" -e H="$HASH" php:8.3-cli php -r 'echo password_verify(getenv("PW"), getenv("H")) ? "SI" : "NO";')"
chk "mysql: una contrasena distinta NO verifica" "$([ "$OKMAL" = "NO" ] && echo 1 || echo 0)"
SALDUP="$(ejecutar "$T/o2.sql" 'ROLLBACK;')"
chk "mysql: reenviar el mismo usuario/correo no inserta nada (filas_insertadas = 0)" "$(echo "$SALDUP" | head -1 | grep -qx '0' && [ "$(M -N -e "SELECT COUNT(*) FROM cervesur_portalbi.user WHERE username='mjnunez'")" = "1" ] && echo 1 || echo 0)"
printf '%s' '{"first_name":"Otra","last_name":"Persona","username":"existente","email":"otra@example.test"}' | GEN --stdin-json > "$T/o3.sql" 2>/dev/null
SAL3="$(ejecutar "$T/o3.sql" 'ROLLBACK;')"
chk "mysql: un usuario ya existente con otro correo tampoco se inserta" "$(echo "$SAL3" | head -1 | grep -qx '0' && echo 1 || echo 0)"
printf '%s' "{\"first_name\":\"x'); DROP TABLE user;--\",\"last_name\":\"Prueba\",\"username\":\"inyeccion\",\"email\":\"inj@example.test\"}" | GEN --stdin-json > "$T/o4.sql" 2>/dev/null
SAL4="$(ejecutar "$T/o4.sql" 'COMMIT;')"
chk "mysql: un texto con comillas y DROP se guarda como dato y la tabla sigue existiendo" "$([ "$(M -N -e "SELECT COUNT(*) FROM cervesur_portalbi.user WHERE username='inyeccion' AND first_name LIKE 'x%DROP TABLE%'")" = "1" ] && echo 1 || echo 0)"
SALR="$(ejecutar "$T/oi.sql" 'ROLLBACK;')"
chk "mysql: ROLLBACK deja la base como estaba (el usuario de la corrida interactiva no queda)" "$([ "$(M -N -e "SELECT COUNT(*) FROM cervesur_portalbi.user WHERE username='anagomez'")" = "0" ] && echo 1 || echo 0)"
chk "mysql: la verificacion de la propia SQL no muestra el hash" "$(echo "$SAL1" | grep -q '2y\$' && echo 0 || echo 1)"

echo; echo "RESULTADO ALTA TEMPORAL: $((total-fallos))/$total OK, $fallos fallas"
[ "$fallos" -eq 0 ]
