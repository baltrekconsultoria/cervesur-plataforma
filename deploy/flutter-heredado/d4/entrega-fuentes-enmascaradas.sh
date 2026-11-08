#!/usr/bin/env bash
# Copia de SOLO LECTURA de los fuentes PHP del backend heredado que faltan para cerrar D3, con los secretos ENMASCARADOS.
# No modifica el backend: escribe unicamente en el directorio de destino, que DEBE estar fuera de la raiz del backend.
#
# Uso:  bash entrega-fuentes-enmascaradas.sh --destino /ruta/fuera/del/backend [--raiz /var/www/cervesur/backend] [--completo]
#
# Por cada archivo: guarda una copia con los literales secretos reemplazados por <MASK> y registra el SHA-256 y los bytes del ORIGINAL
# (para contrastar con la tabla de trazabilidad). Enmascara: el literal por defecto de JWT_SECRET (getenv('JWT_SECRET') ?: '...') y
# cualquier asignacion literal a claves llamadas PASS/PASSWORD/SECRET/TOKEN/API_KEY/PWD. Nunca copia .env ni archivos que no sean .php.
set -u
export LC_ALL=C
RAIZ=/var/www/cervesur/backend; DEST=''; COMPLETO=0
while [ $# -gt 0 ]; do
  case "$1" in
    --raiz) RAIZ="$2"; shift 2;;
    --destino) DEST="$2"; shift 2;;
    --completo) COMPLETO=1; shift;;
    *) echo "argumento desconocido: $1" >&2; exit 2;;
  esac
done
[ -n "$DEST" ] || { echo "Falta --destino" >&2; exit 2; }
command -v php >/dev/null 2>&1 || { echo "Se necesita php (CLI)" >&2; exit 2; }
RAIZ_R=$(cd "$RAIZ" 2>/dev/null && pwd) || { echo "No existe la raiz $RAIZ" >&2; exit 2; }
mkdir -p "$DEST" || exit 2
DEST_R=$(cd "$DEST" && pwd)
case "$DEST_R/" in "$RAIZ_R"/*) echo "El destino no puede estar dentro del backend ($RAIZ_R)" >&2; exit 2;; esac
if [ -n "$(ls -A "$DEST_R" 2>/dev/null)" ]; then echo "El destino debe estar vacio: $DEST_R" >&2; exit 2; fi

MINIMO="lib/auth_middleware.php lib/db.php lib/jwt.php lib/env.php router/index.php controllers/EmpresaController.php models/EmpresaModel.php controllers/UserController.php models/UserModel.php router/StatsRouter.php router/wiki.php"
RESTO="controllers/AuthController.php controllers/RegisterController.php controllers/ReporteController.php models/ReporteModel.php controllers/EmpresaReporteUsuarioController.php models/EmpresaReporteUsuarioModel.php models/AsignacionModel.php models/EmpresaUsuarioModel.php router/ReporteRouter.php router/EmpresaReporteUsuarioRouter.php router/UserRouter.php router/auth.php"
LISTA="$MINIMO"; [ "$COMPLETO" -eq 1 ] && LISTA="$MINIMO $RESTO"

{ echo "# SHA-256 completo y bytes de los ORIGINALES (antes de enmascarar) · $(date -u +%Y-%m-%dT%H:%M:%SZ)"; } > "$DEST_R/HUELLAS-ORIGINALES.txt"
N=0; FALTAN=''
for r in $LISTA; do
  F="$RAIZ_R/$r"
  if [ ! -f "$F" ]; then FALTAN="$FALTAN $r"; continue; fi
  printf '%s  %s  %s\n' "$(sha256sum "$F" | cut -d' ' -f1)" "$(stat -c %s "$F")" "$r" >> "$DEST_R/HUELLAS-ORIGINALES.txt"
  mkdir -p "$DEST_R/$(dirname "$r")"
  php -r '
    $t = file_get_contents($argv[1]); $n = 0;
    // 1) literal por defecto de JWT_SECRET:  JWT_SECRET\x27) ?: \x27....\x27
    $t = preg_replace_callback("/(JWT_SECRET[\x27\"]\s*\)\s*\?:\s*)([\x27\"])(.*?)\\2/s", function ($m) use (&$n) { $n++; return $m[1] . $m[2] . "<MASK>" . $m[2]; }, $t);
    // 2) asignaciones literales a claves sensibles:  PASS/PASSWORD/SECRET/TOKEN/API_KEY/PWD  =>/=  "valor"
    $t = preg_replace_callback("/((?:PASS|PASSWORD|SECRET|TOKEN|API_?KEY|PWD)[\x27\"]?\s*(?:=>|=)\s*)([\x27\"])((?!<MASK>)[^\x27\"\r\n]{3,})\\2/i", function ($m) use (&$n) { $n++; return $m[1] . $m[2] . "<MASK>" . $m[2]; }, $t);
    file_put_contents($argv[2], $t);
    fwrite(STDERR, $n . "\n");
  ' "$F" "$DEST_R/$r" 2>"$DEST_R/.n"
  M=$(cat "$DEST_R/.n"); rm -f "$DEST_R/.n"
  printf '%s  %s  %s  (literales enmascarados: %s)\n' "$(sha256sum "$DEST_R/$r" | cut -c1-12)" "$(stat -c %s "$DEST_R/$r")" "$r" "$M" >> "$DEST_R/HUELLAS-ENMASCARADOS.txt"
  N=$((N+1))
done
echo "Archivos copiados: $N. Destino: $DEST_R"
[ -n "$FALTAN" ] && echo "No existen en el servidor (informar): $FALTAN"
echo "REVISE el contenido antes de enviarlo; no incluya el .env ni ningun otro archivo."
