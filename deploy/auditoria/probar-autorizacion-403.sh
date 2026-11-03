#!/usr/bin/env bash
# Prueba LOCAL de autorizacion-403-solo-lectura.sh con un backend «desplegado» simulado que contiene enlaces, GUID y secretos de mentira.
# Comprueba: (1) encuentra el 403, la lista de aprobación, la variable de entorno, el archivo de datos y la tabla; (2) NO imprime URL, GUID,
# secretos ni valores; (3) no modifica el directorio inspeccionado. Uso:  bash deploy/auditoria/probar-autorizacion-403.sh
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
mkdir -p "$T/srv/lib" "$T/srv/router"
cat > "$T/srv/lib/piloto_catalogo.php" <<'PHP'
<?php
const PILOTO_APROBADOS = [72, 73];
$secret = 'SECRETO-DE-MENTIRA-ABCDEFGHIJKLMNOPQRSTUV123456';
$enlace = 'https://app.powerbi.com/reportEmbed?reportId=11111111-2222-3333-4444-555555555555&groupId=aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';
function reporte_aprobado($id) { return in_array((int)$id, PILOTO_APROBADOS, true); }
$lista = json_decode(file_get_contents('/opt/datos/aprobados.json'), true);
$extra = getenv('PILOTO_INFORMES_APROBADOS');
$st = $pdo->query('SELECT reporte_id FROM informes_aprobados WHERE activo = 1');
PHP
cat > "$T/srv/router/AdminRouter.php" <<'PHP'
<?php
if (!reporte_aprobado($id)) { http_response_code(403); echo json_encode(['message' => 'Informe no aprobado para el piloto']); exit; }
$token = 'Bearer ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789ABCDEF'; // 403 con token: línea sensible, no debe mostrarse
$pdo = new PDO('mysql:host=127.0.0.1;dbname=cervesur_piloto_estado', $u, $p);
PHP
before=$(find "$T/srv" -type f -exec sha256sum {} + | LC_ALL=C sort | sha256sum)
out=$(bash "$HERE/autorizacion-403-solo-lectura.sh" "$T/srv" 2>&1); rc=$?
after=$(find "$T/srv" -type f -exec sha256sum {} + | LC_ALL=C sort | sha256sum)
pass=0; fail=0
chk() { local d=$1; shift; if "$@"; then echo "PASS  $d"; pass=$((pass + 1)); else echo "FAIL  $d"; fail=$((fail + 1)); fi; }
tiene() { grep -qF -- "$1" <<<"$out"; }
no_tiene() { ! grep -qF -- "$1" <<<"$out"; }
chk "termina bien" [ "$rc" -eq 0 ]
chk "muestra la línea que emite el 403" tiene "http_response_code(403)"
chk "muestra la lista de aprobación con los ids (no son secretos)" tiene "PILOTO_APROBADOS = [72, 73]"
chk "muestra la variable de entorno que alimenta la lista" tiene "PILOTO_INFORMES_APROBADOS"
chk "muestra el archivo de datos leído" tiene "aprobados.json"
chk "muestra la tabla consultada" tiene "informes_aprobados"
chk "muestra el nombre de la base de estado" tiene "dbname=cervesur_piloto_estado"
chk "NO imprime ninguna URL (se reemplaza por <url-oculta>)" no_tiene "powerbi.com"
chk "NO imprime GUID" no_tiene "11111111-2222"
sin_secretos() { ! grep -qE "SECRETO-DE-MENTIRA|ABCDEFGHIJKLMNOPQRSTUV|Bearer" <<<"$out"; }
chk "NO imprime el secreto ni el token de mentira" sin_secretos
chk "cuenta las líneas sensibles que no muestra" tiene "líneas con palabras sensibles no mostradas: 1"
chk "no modifica el directorio inspeccionado" [ "$before" = "$after" ]
echo; echo "$pass PASS / $fail FAIL"
[ "$fail" -eq 0 ]
