#!/usr/bin/env bash
# Prueba LOCAL de los scripts C-5 (no toca ningún servidor): Docker, sin red hacia fuera, datos sintéticos.
#  - c5-solo-lectura.sh   : registros de Apache sintéticos (incluye una cadena de consulta con un "secreto" y una línea rota).
#  - c5-dba-solo-lectura.sql.template : MySQL 8.0 con un esquema sintético; se comprueba que ningún valor sensible sale y que nada cambia.
# Uso:  bash deploy/auditoria/probar-c5.sh      (requiere docker y las imágenes php:8.3-cli y mysql:8.0 ya descargadas)
set -u
AQUI="$(cd "$(dirname "$0")" && pwd)"
export MSYS_NO_PATHCONV=1
ruta() { if command -v cygpath >/dev/null 2>&1; then cygpath -w "$1"; else echo "$1"; fi; }
T="$(mktemp -d)"; trap 'rm -rf "$T"; docker rm -f c5-mysql-prueba >/dev/null 2>&1' EXIT
fallos=0; total=0
chk() { total=$((total+1)); if [ "$2" = "1" ]; then echo "OK     $1"; else echo "FALLA  $1"; fallos=$((fallos+1)); fi; }

# ---------------- 1) script de shell ----------------
mkdir -p "$T/logs"
cat > "$T/logs/access.log" <<'EOF'
34.74.1.5 - - [05/Nov/2026:10:00:00 +0000] "GET /api/powerbi/tokens/12?x=CLAVE-SECRETA-1 HTTP/1.1" 200 512 "-" "axios/1.7.2"
34.74.1.9 - - [05/Nov/2026:10:00:05 +0000] "GET /api/powerbi/tokens/13 HTTP/1.1" 200 512 "-" "axios/1.7.2"
192.168.200.30 - - [05/Nov/2026:11:00:00 +0000] "POST /api/register HTTP/1.1" 200 512 "-" "Mozilla/5.0"
203.0.113.7 - - [06/Nov/2026:09:00:00 +0000] "POST /api/register HTTP/1.1" 200 512 "-" "python-requests/2.31"
34.74.1.5 - - [06/Nov/2026:09:10:00 +0000] "PUT /api/empresas/3 HTTP/1.1" 200 512 "-" "axios/1.7.2"
34.74.1.5 - - [06/Nov/2026:09:10:02 +0000] "PUT /api/user/profile/3 HTTP/1.1" 200 512 "-" "axios/1.7.2"
198.51.100.9 - - [06/Nov/2026:09:12:00 +0000] "GET /api/powerbi/tokens/1 HTTP/1.1" 200 512 "-" "zgrab/0.x"
2001:db8::1 - - [06/Nov/2026:09:13:00 +0000] "GET /index.html HTTP/1.1" 200 512 "-" "Mozilla/5.0"
linea-rota-sin-formato
EOF
printf '%s\n' '34.74.2.2 - - [12/Oct/2026:08:00:00 +0000] "GET /api/powerbi/tokens/20 HTTP/1.1" 200 512 "-" "axios/1.6.0"' | gzip -n > "$T/logs/access.log.2.gz"
printf 'x\n' > "$T/logs/error.log"
SAL="$(docker run --rm -i --network none -v "$(ruta "$T/logs"):/var/log/apache2:ro" php:8.3-cli bash -s -- /var/log/apache2/access.log /var/log/apache2/access.log.2.gz /var/log/apache2/error.log /etc/passwd < "$AQUI/c5-solo-lectura.sh" 2>&1)"
chk "shell: termina y agrega K1 (4 llamadas, 2026-10 y 2026-11)" "$(echo "$SAL" | grep -A2 '^\[K1_tokens_powerbi\] total 4' | grep -q 'mes 2026-10: 1' && echo 1 || echo 0)"
chk "shell: A3 registro POST = 2 y clasifica cliente navegador y herramienta" "$(echo "$SAL" | grep -A8 '^\[A3_registro_POST\] total 2' | grep -q 'cliente herramienta: 1' && echo 1 || echo 0)"
chk "shell: reconoce el cliente axios y el escaner" "$( (echo "$SAL" | grep -q 'cliente axios_o_node' && echo "$SAL" | grep -q 'cliente escaner') && echo 1 || echo 0)"
chk "shell: rechaza error.log y /etc/passwd" "$( (echo "$SAL" | grep -q 'RECHAZADO.*error.log' && echo "$SAL" | grep -q 'RECHAZADO.*passwd') && echo 1 || echo 0)"
chk "shell: descarta la linea rota y lo declara" "$(echo "$SAL" | grep -q 'malformadas (descartadas): 1' && echo 1 || echo 0)"
chk "shell: la cadena de consulta con el 'secreto' NO sale" "$(echo "$SAL" | grep -q 'CLAVE-SECRETA' && echo 0 || echo 1)"
chk "shell: ninguna IP completa sale (solo /24)" "$(echo "$SAL" | grep -Eq '(34\.74\.1\.5|192\.168\.200\.30|203\.0\.113\.7|198\.51\.100\.9)([^0-9/]|$)' && echo 0 || echo 1)"
chk "shell: ninguna ruta completa sale" "$(echo "$SAL" | grep -q '/api/powerbi/tokens/' && echo 0 || echo 1)"
docker run --rm -i --network none php:8.3-cli bash -s -- /etc/passwd < "$AQUI/c5-solo-lectura.sh" >/dev/null 2>&1; RCS=$?
chk "shell: sin archivos validos sale con codigo 3" "$( [ $RCS -eq 3 ] && echo 1 || echo 0)"
chk "shell: no contiene sudo/escrituras/rm/mv/cp/tee fuera de comentarios" "$(grep -v '^[[:space:]]*#' "$AQUI/c5-solo-lectura.sh" | grep -Eq '(^|[ ;|&(])(sudo|rm|mv|cp|tee|dd|chmod|chown|mkdir|touch)[ ]|>>|[^2]>[ ]*/(var|etc|tmp|home)' && echo 0 || echo 1)"

# Prueba dinamica de que el script de shell no escribe: raiz del contenedor de SOLO LECTURA y sin directorio temporal.
SALRO="$(docker run --rm -i --network none --read-only -v "$(ruta "$T/logs"):/var/log/apache2:ro" php:8.3-cli bash -s -- /var/log/apache2/access.log /var/log/apache2/access.log.2.gz < "$AQUI/c5-solo-lectura.sh" 2>&1; echo "RC=$?")"
chk "shell: con la raiz del contenedor en SOLO LECTURA termina bien y sin errores de escritura" "$( (echo "$SALRO" | grep -q 'RC=0' && ! echo "$SALRO" | grep -qiE 'read-only file system|permission denied|cannot create|no space') && echo 1 || echo 0)"
chk "shell: no usa apache2ctl ni ejecuta programas de la aplicacion (solo php -v)" "$(grep -v '^[[:space:]]*#' "$AQUI/c5-solo-lectura.sh" | grep -qE '(^|[|;&(]|[$]\()[[:space:]]*(apache2ctl|apachectl|composer|mysql|mysqldump|curl|wget|nc|ssh|scp|php[[:space:]]+-[rf])[[:space:]]' && echo 0 || echo 1)"

# ---------------- 2) script SQL ----------------
PRIMERAS="$(grep -v '^[[:space:]]*--' "$AQUI/c5-dba-solo-lectura.sql.template" | tr '\n' ' ' | tr ';' '\n' | sed 's/^[[:space:]]*//' | awk 'NF {print toupper($1)}' | sort -u | tr '\n' ' ')"
chk "sql: solo SET, START, SELECT, SHOW y COMMIT (primeras palabras: $PRIMERAS)" "$(echo " $PRIMERAS" | grep -Eq '^( (SET|START|SELECT|SHOW|COMMIT))+ *$' && echo 1 || echo 0)"
chk "sql: no selecciona columnas de contrasena, hash, nombre ni telefono" "$(grep -v '^[[:space:]]*--' "$AQUI/c5-dba-solo-lectura.sql.template" | grep -Eiq '(^|[^a-z_])(password|authentication_string|first_name|last_name|phone)([^a-z_]|$)' && echo 0 || echo 1)"
docker rm -f c5-mysql-prueba >/dev/null 2>&1
docker run -d --name c5-mysql-prueba --network none -e MYSQL_ROOT_PASSWORD=ficticia-prueba mysql:8.0 >/dev/null 2>&1
# MySQL arranca dos veces (servidor temporal de inicializacion y servidor definitivo): se espera al segundo «ready for connections»
for i in $(seq 1 90); do [ "$(docker logs c5-mysql-prueba 2>&1 | grep -c 'ready for connections')" -ge 2 ] && docker exec c5-mysql-prueba mysqladmin ping -uroot -pficticia-prueba --silent >/dev/null 2>&1 && break; sleep 2; done
docker exec -i c5-mysql-prueba mysql -uroot -pficticia-prueba 2>/dev/null <<'EOF'
CREATE DATABASE cervesur_portalbi; USE cervesur_portalbi;
CREATE TABLE user (id INT UNSIGNED PRIMARY KEY AUTO_INCREMENT, first_name VARCHAR(50), last_name VARCHAR(50), username VARCHAR(50), email VARCHAR(100), phone VARCHAR(20), password VARCHAR(255), role VARCHAR(20), is_active TINYINT DEFAULT 1, session_version INT DEFAULT 1, created_at DATETIME, updated_at DATETIME, UNIQUE KEY uq_user (username));
INSERT INTO user (first_name,last_name,username,email,phone,password,role,created_at) VALUES
 ('Nombre-Secreto','Apellido-Secreto','ana','correo-secreto@example.test','999000111','HASH-FICTICIO-NO-SALIR','usuario',NOW() - INTERVAL 40 DAY),
 ('N','A','beto','correo-secreto@example.test','1','HASH2','admin',NOW() - INTERVAL 5 DAY),
 ('N','A','caro','otro@example.test','2','HASH3','user',NULL);
CREATE TABLE empresa (id INT PRIMARY KEY AUTO_INCREMENT, nombre VARCHAR(50), ruc VARCHAR(11), powerbi_tenant VARCHAR(80), powerbi_client VARCHAR(80), powerbi_clientsecret VARCHAR(255), activo TINYINT DEFAULT 1);
INSERT INTO empresa (nombre,ruc,powerbi_tenant,powerbi_client,powerbi_clientsecret) VALUES ('E1','20000000001','t1','c1','SYNTH-SECRET-DB'),('E2','20000000002','t1','c1',''),('E3','20000000003',NULL,NULL,NULL);
CREATE TABLE reporte (id INT PRIMARY KEY AUTO_INCREMENT, nombre VARCHAR(50), enlace VARCHAR(500), activo TINYINT DEFAULT 1);
INSERT INTO reporte (nombre,enlace) VALUES ('R1','https://app.powerbi.com/view?r=ENLACE-FICTICIO-XYZ'),('R2','https://app.powerbi.com/reportEmbed?reportId=zzz'),('R3',''),('R4','https://otra.example.test/x');
CREATE TABLE empresa_reporte (id INT PRIMARY KEY AUTO_INCREMENT, empresa_id INT, reporte_id INT, FOREIGN KEY (empresa_id) REFERENCES empresa(id), FOREIGN KEY (reporte_id) REFERENCES reporte(id));
CREATE TABLE user_empresa_reporte (id INT PRIMARY KEY AUTO_INCREMENT, user_id INT UNSIGNED, empresa_reporte_id INT, UNIQUE KEY uq_u (user_id, empresa_reporte_id), FOREIGN KEY (user_id) REFERENCES user(id), FOREIGN KEY (empresa_reporte_id) REFERENCES empresa_reporte(id));
CREATE TABLE audit_log (id INT PRIMARY KEY AUTO_INCREMENT, accion VARCHAR(20));
CREATE TRIGGER audit_log_bu BEFORE UPDATE ON audit_log FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='solo insercion';
EOF
# Registro de TODAS las sentencias que llegan al servidor durante la ejecucion (general_log en tabla)
docker exec c5-mysql-prueba mysql -uroot -pficticia-prueba -e "SET GLOBAL log_output='TABLE'; TRUNCATE mysql.general_log; SET GLOBAL general_log='ON';" 2>/dev/null
ANTES="$(docker exec c5-mysql-prueba mysql -uroot -pficticia-prueba -N -e "SELECT CONCAT((SELECT COUNT(*) FROM cervesur_portalbi.user),'|',(SELECT COUNT(*) FROM cervesur_portalbi.empresa),'|',(SELECT SUM(CRC32(CONCAT(IFNULL(powerbi_clientsecret,''),nombre))) FROM cervesur_portalbi.empresa))" 2>/dev/null)"
SQLOUT="$(docker exec -i c5-mysql-prueba mysql --table -uroot -pficticia-prueba < "$AQUI/c5-dba-solo-lectura.sql.template" 2>&1)"; RC=$?
DESPUES="$(docker exec c5-mysql-prueba mysql -uroot -pficticia-prueba -N -e "SELECT CONCAT((SELECT COUNT(*) FROM cervesur_portalbi.user),'|',(SELECT COUNT(*) FROM cervesur_portalbi.empresa),'|',(SELECT SUM(CRC32(CONCAT(IFNULL(powerbi_clientsecret,''),nombre))) FROM cervesur_portalbi.empresa))" 2>/dev/null)"
VERBOS="$(docker exec c5-mysql-prueba mysql -uroot -pficticia-prueba -N -e "SET GLOBAL general_log='OFF'; SELECT DISTINCT UPPER(SUBSTRING_INDEX(TRIM(CONVERT(argument USING utf8mb4)), ' ', 1)) FROM mysql.general_log WHERE command_type='Query' AND user_host LIKE 'root%' AND CONVERT(argument USING utf8mb4) NOT LIKE '%general_log%' ORDER BY 1" 2>/dev/null | tr '\n' ' ')"
chk "sql: el registro del servidor muestra que SOLO llegaron sentencias de lectura (verbos: $VERBOS)" "$(echo " $VERBOS" | grep -Eq '^( (SET|START|SELECT|SHOW|COMMIT))+ *$' && echo 1 || echo 0)"
chk "sql: se ejecuta sin errores en MySQL 8.0 (codigo 0, sin 'ERROR')" "$( [ $RC -eq 0 ] && ! echo "$SQLOUT" | grep -q '^ERROR' && echo 1 || echo 0)"
chk "sql: informa la version del servidor y el tamano" "$( (echo "$SQLOUT" | grep -q '8\.0\.' && echo "$SQLOUT" | grep -q 'mb') && echo 1 || echo 0)"
chk "sql: cuentas por rol/estado y altas por mes" "$( (echo "$SQLOUT" | grep -q 'cuentas' && echo "$SQLOUT" | grep -q 'altas') && echo 1 || echo 0)"
chk "sql: detecta el correo duplicado sintetico (1 grupo)" "$(echo "$SQLOUT" | grep -A3 'correo duplicado (mismo valor)' | grep -q '| *1 *|' && echo 1 || echo 0)"
chk "sql: R-PUB agregado: 1 publicar_en_la_web, 1 incrustado_autenticado, 1 vacio, 1 otra_plataforma" "$( (echo "$SQLOUT" | grep -q 'publicar_en_la_web' && echo "$SQLOUT" | grep -q 'incrustado_autenticado' && echo "$SQLOUT" | grep -q 'otra_plataforma') && echo 1 || echo 0)"
chk "sql: D-5 secretos: 3 empresas, 1 con secreto" "$(echo "$SQLOUT" | grep -A3 'con_secreto' | grep -q '| *3 *| *1 *|' && echo 1 || echo 0)"
chk "sql: ningun valor sensible sale (hash, correo, nombre, telefono, enlace, secreto)" "$(echo "$SQLOUT" | grep -Eq 'HASH-FICTICIO|HASH2|correo-secreto|otro@example|Nombre-Secreto|Apellido-Secreto|999000111|ENLACE-FICTICIO|SYNTH-SECRET-DB|ficticia-prueba' && echo 0 || echo 1)"
chk "sql: no existe la cuenta cervesur_vista_alta (requisito de P4a)" "$(echo "$SQLOUT" | grep -A2 'cervesur_vista_alta NO debe existir' | grep -q '| *0 *|' && echo 1 || echo 0)"
chk "sql: muestra los disparadores y las claves foraneas" "$( (echo "$SQLOUT" | grep -q 'audit_log_bu' && echo "$SQLOUT" | grep -q 'user_empresa_reporte') && echo 1 || echo 0)"
chk "sql: nada cambio en la base (filas y sumas de control iguales)" "$( [ -n "$ANTES" ] && [ "$ANTES" = "$DESPUES" ] && echo 1 || echo 0)"
# la sesion es de solo lectura: una escritura dentro de la misma transaccion debe fallar
ESC="$( { cat "$AQUI/c5-dba-solo-lectura.sql.template" | sed -n '/^SET SESSION/,/^START TRANSACTION/p'; echo "UPDATE cervesur_portalbi.empresa SET nombre='X';"; } | docker exec -i c5-mysql-prueba mysql -uroot -pficticia-prueba 2>&1 )"
chk "sql: la transaccion es de solo lectura (un UPDATE en esa sesion es rechazado)" "$(echo "$ESC" | grep -qi 'read-only\|read only' && echo 1 || echo 0)"

# Cuenta con privilegios limitados (solo SELECT en la base): con --force el script continua y los errores quedan aparte
docker exec c5-mysql-prueba mysql -uroot -pficticia-prueba -e "CREATE USER 'c5limitado'@'localhost' IDENTIFIED BY 'ficticia-limitada'; GRANT SELECT ON cervesur_portalbi.* TO 'c5limitado'@'localhost';" >/dev/null 2>&1
docker exec -i c5-mysql-prueba mysql --table --force -uc5limitado -pficticia-limitada < "$AQUI/c5-dba-solo-lectura.sql.template" > "$T/lim-salida.txt" 2> "$T/lim-errores.txt"
chk "sql: con una cuenta limitada y --force termina la lectura (llega a «FIN») y los errores de privilegio quedan en el archivo aparte" "$( (grep -q 'FIN: nada fue modificado' "$T/lim-salida.txt" && grep -q 'ERROR' "$T/lim-errores.txt") && echo 1 || echo 0)"
chk "sql: la cuenta limitada no pudo ni quiso escribir (nada cambio)" "$( [ "$(docker exec c5-mysql-prueba mysql -uroot -pficticia-prueba -N -e "SELECT COUNT(*) FROM cervesur_portalbi.user" 2>/dev/null)" = "3" ] && echo 1 || echo 0)"

# ---------------- 3) revisor de salidas ----------------
printf '%s\n' "$SAL" > "$T/salida-sistema.txt"; printf '%s\n' "$SQLOUT" > "$T/salida-dba.txt"
bash "$AQUI/revisar-salida-c5.sh" "$T/salida-sistema.txt" >/dev/null 2>&1; R1=$?
bash "$AQUI/revisar-salida-c5.sh" "$T/salida-dba.txt" >/dev/null 2>&1; R2=$?
chk "revisor: la salida limpia del shell da PASS (codigo 0)" "$([ $R1 -eq 0 ] && echo 1 || echo 0)"
chk "revisor: la salida limpia del SQL (con bordes de tabla) da PASS (codigo 0)" "$([ $R2 -eq 0 ] && echo 1 || echo 0)"
{ cat "$T/salida-dba.txt"; echo 'hash $2y$10$abcdefghijklmnopqrstuuABCDEFGHIJKLMNOPQRSTUVWXYZ0123456'; echo 'tok eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.firma123456'; echo 'x QUJDREVGR0hJSktMTU5PUFFSU1RVVldYWVowMTIzNDU2Nzg5YWJjZGVm'; echo 'contacto persona@example.test'; echo 'password = claveficticia'; } > "$T/sucia.txt"
SALR="$(bash "$AQUI/revisar-salida-c5.sh" "$T/sucia.txt" 2>&1)"; R3=$?
chk "revisor: detecta hash, JWT, secuencia larga, correo y asignacion de clave (codigo 1)" "$( [ $R3 -eq 1 ] && [ "$(echo "$SALR" | grep -c '^ALERTA')" -ge 5 ] && echo 1 || echo 0)"
chk "revisor: no imprime los valores encontrados" "$(echo "$SALR" | grep -Eq 'abcdefghijklmnop|eyJhbGci|persona@example|claveficticia|QUJDREVG' && echo 0 || echo 1)"
bash "$AQUI/revisar-salida-c5.sh" >/dev/null 2>&1; R4=$?
chk "revisor: sin argumento termina con codigo 2" "$([ $R4 -eq 2 ] && echo 1 || echo 0)"

echo; echo "RESULTADO C-5: $((total-fallos))/$total OK, $fallos fallas"
[ "$fallos" -eq 0 ]
