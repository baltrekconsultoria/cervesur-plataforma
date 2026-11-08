#!/usr/bin/env bash
# D4 · ATESTACION DE SOLO LECTURA del backend heredado (Flutter). NO modifica nada, NO hace peticiones de red, NO imprime valores.
#
# Uso:  bash d4-atestacion-solo-lectura.sh --expiracion-prevista SEGUNDOS [--raiz /var/www/cervesur/backend] [--env /ruta/.env]
#
# Que hace: lee el .env y los archivos PHP del backend y emite, por cada comprobacion, PASS / FAIL / PENDIENTE y un detalle SIN valores
# (solo si/no, longitudes en tramos, nombres de archivo y numeros de linea). Los secretos se comparan EN MEMORIA y nunca se escriben.
# Que NO hace: no ejecuta PHP del sitio, no consulta la base, no llama a Azure, no escribe archivos, no toca Apache.
# Requisitos: bash, php (CLI) y sha256sum. Pensado para ejecutarlo la persona de Infraestructura con su cuenta nominal.
set -u
export LC_ALL=C
RAIZ=/var/www/cervesur/backend; ENVF=''; EXPECT=''
while [ $# -gt 0 ]; do
  case "$1" in
    --raiz) RAIZ="$2"; shift 2;;
    --env) ENVF="$2"; shift 2;;
    --expiracion-prevista) EXPECT="$2"; shift 2;;
    *) echo "argumento desconocido: $1" >&2; exit 2;;
  esac
done
[ -n "$ENVF" ] || ENVF="$RAIZ/.env"
case "$EXPECT" in ''|*[!0-9]*) echo "Falta --expiracion-prevista SEGUNDOS (numero entero). Lo decide el responsable funcional." >&2; exit 2;; esac
command -v php >/dev/null 2>&1 || { echo "Se necesita php (CLI)" >&2; exit 2; }
[ -d "$RAIZ/router" ] || { echo "No existe $RAIZ/router: revise --raiz" >&2; exit 2; }

echo "D4 · atestacion de solo lectura · $(date -u +%Y-%m-%dT%H:%M:%SZ) · raiz=$RAIZ"
PASS=0; FAIL=0; PEND=0
emit() { # estado id texto
  printf '%-10s %-6s %s\n' "$1" "$2" "$3"
  case "$1" in PASS) PASS=$((PASS+1));; FAIL) FAIL=$((FAIL+1));; *) PEND=$((PEND+1));; esac
}

# --- D4-01 archivo .env: existencia y permisos (modo octal; no es secreto) --------------------------------------------------
if [ -r "$ENVF" ]; then
  MODO=$(stat -c %a "$ENVF" 2>/dev/null || echo '?')
  OTROS=${MODO: -1}
  case "$OTROS" in 0) emit PASS D4-01 ".env legible por quien ejecuta; modo $MODO (sin acceso para 'otros')";; '?') emit PENDIENTE D4-01 "no se pudo leer el modo del .env";; *) emit FAIL D4-01 ".env con acceso para 'otros' (modo $MODO)";; esac
else
  emit PENDIENTE D4-01 "el .env no existe o no es legible para esta cuenta ($ENVF): infraestructura debe indicar donde se define JWT_SECRET"
fi

# --- D4-02..07: analisis en memoria con PHP (nunca imprime valores) ------------------------------------------------------------
RES=$(php -d display_errors=0 -r '
$env = [];
$f = $argv[1]; $raiz = $argv[2]; $esp = (int)$argv[3];
if (is_readable($f)) {
  foreach (file($f, FILE_IGNORE_NEW_LINES) as $l) {
    $l = trim($l); if ($l === "" || $l[0] === "#") continue;
    if (!preg_match("/^(?:export\s+)?([A-Za-z_][A-Za-z0-9_]*)\s*=\s*(.*)$/", $l, $m)) continue;
    $v = trim($m[2]); if ($v !== "" && ($v[0] === "\"" || $v[0] === "\x27") && substr($v, -1) === $v[0]) $v = substr($v, 1, -1);
    $env[$m[1]] = $v;
  }
}
function out($e, $i, $t) { echo $e, "|", $i, "|", $t, "\n"; }
$s = $env["JWT_SECRET"] ?? null;
if ($s === null) { out("PENDIENTE", "D4-02", "JWT_SECRET no esta en el .env: puede venir del entorno de Apache/PHP-FPM; solo infraestructura puede atestarlo"); }
elseif ($s === "") { out("FAIL", "D4-02", "JWT_SECRET esta definido pero VACIO"); }
else {
  out("PASS", "D4-02", "JWT_SECRET definido en el .env y no vacio");
  $len = strlen($s);
  out($len >= 32 ? "PASS" : "FAIL", "D4-03", "longitud " . ($len >= 64 ? ">= 64" : ($len >= 32 ? "entre 32 y 63" : "< 32 (insuficiente para HS256)")));
  $debiles = ["secret","secreto","changeme","change-me","password","contrasena","jwt_secret","jwtsecret","your-secret-key","your_secret_key","supersecret","clave","123456","qwerty","admin","cervesur","baltrek","test","default"];
  $deb = false; foreach ($debiles as $d) { if (strcasecmp($s, $d) === 0 || stripos($s, $d) === 0 && strlen($s) < 24) $deb = true; }
  out($deb ? "FAIL" : "PASS", "D4-04", $deb ? "coincide con un valor debil o de ejemplo conocido" : "no coincide con valores debiles de ejemplo");
  $dist = count(array_unique(str_split($s)));
  out($dist >= 12 ? "PASS" : "FAIL", "D4-05", "variedad de caracteres " . ($dist >= 12 ? ">= 12 distintos" : "< 12 distintos (baja entropia)"));
  // comparacion con TODOS los literales por defecto presentes en los routers desplegados: ... getenv(\x27JWT_SECRET\x27) ?: \x27<literal>\x27
  $n = 0; $iguales = 0; $archivos = [];
  foreach (array_merge(glob("$raiz/router/*.php") ?: [], glob("$raiz/controllers/*.php") ?: [], glob("$raiz/lib/*.php") ?: []) as $p) {
    $t = @file_get_contents($p); if ($t === false) continue;
    if (preg_match_all("/JWT_SECRET[\x27\"]\s*\)\s*\?:\s*([\x27\"])(.*?)\\1/s", $t, $mm)) {
      foreach ($mm[2] as $lit) { $n++; if (hash_equals(hash("sha256", $lit), hash("sha256", $s))) { $iguales++; $archivos[] = basename($p); } }
    }
  }
  if ($n === 0) out("PASS", "D4-06", "no hay literales por defecto de JWT_SECRET en los archivos desplegados (0 examinados)");
  else out($iguales === 0 ? "PASS" : "FAIL", "D4-06", "literales por defecto examinados: $n; coincidencias con el JWT_SECRET real: $iguales" . ($iguales ? " (" . implode(",", array_unique($archivos)) . ")" : ""));
  out($n > 0 ? "PENDIENTE" : "PASS", "D4-06b", $n > 0 ? "$n rutas del backend aun traen un literal por defecto: si JWT_SECRET faltara en algun momento, esas rutas firmarian con un valor publico; recomendado quitar el literal (cambio de codigo, no de configuracion)" : "sin literales por defecto");
}
$e = $env["JWT_EXPIRATION"] ?? null;
if ($e === null) out("PENDIENTE", "D4-07", "JWT_EXPIRATION no esta en el .env: el codigo usa 86400 s (24 h) por defecto; previsto=$esp");
elseif (!preg_match("/^[0-9]+$/", $e) || (int)$e <= 0) out("FAIL", "D4-07", "JWT_EXPIRATION no es un entero positivo (el codigo caeria a 86400)");
else out((int)$e === $esp ? "PASS" : "FAIL", "D4-07", "JWT_EXPIRATION=" . (int)$e . " s; previsto=$esp s");
' "$ENVF" "$RAIZ" "$EXPECT" 2>/dev/null)
while IFS='|' read -r est id txt; do [ -n "$est" ] && emit "$est" "$id" "$txt"; done <<<"$RES"

# --- D4-08 K1: modo efectivo de POWERBI_AUTH_MODE ------------------------------------------------------------------------
AUTHZ="$RAIZ/lib/powerbi_authz.php"
if [ ! -f "$AUTHZ" ]; then
  emit FAIL D4-08 "lib/powerbi_authz.php no esta instalado: el Lote 2 (K1) no esta aplicado; el token de Power BI sigue sin autenticacion"
else
  DEFS=$(grep -RIn --include='*.php' --include='.env' -E "define\(\s*['\"]POWERBI_AUTH_MODE['\"]" "$RAIZ" 2>/dev/null | sed -E "s/:[[:space:]]*/: /" | cut -c1-200)
  MODOS=$(printf '%s\n' "$DEFS" | grep -oE "POWERBI_AUTH_MODE['\"][[:space:]]*,[[:space:]]*['\"][a-z]+['\"]" | grep -oE "['\"][a-z]+['\"]$" | tr -d "'\"" | sort -u | tr '\n' ' ')
  ENVMODE=$(grep -E '^[[:space:]]*POWERBI_AUTH_MODE[[:space:]]*=' "$ENVF" 2>/dev/null | head -1 | cut -d= -f2- | tr -d "'\" \r")
  echo "           (informativo) definiciones de la constante: $(printf '%s' "$DEFS" | grep -c . ) ; valores literales encontrados: ${MODOS:-ninguno}; POWERBI_AUTH_MODE en .env: ${ENVMODE:-no}"
  if [ "${MODOS% }" = "retirar" ]; then
    emit PENDIENTE D4-08 "el codigo declara 'retirar' como unico valor definido; FALTA la prueba funcional externa (D4-08b) para atestar el modo EFECTIVO"
  else
    emit FAIL D4-08 "el modo 'retirar' NO es el unico valor definido (valores: ${MODOS:-ninguno}); con 'observar' el token anonimo se sigue entregando"
  fi
  emit PENDIENTE D4-08b "prueba funcional (la ejecuta Infraestructura): GET <sitio>/api/powerbi/tokens/0 sin Authorization debe responder 410. Con id 0 NO se emite token en ningun modo (observar da 400, exigir 401). Anote el codigo HTTP"
fi

# --- D4-09 F-10: registro publico cerrado en el router desplegado ------------------------------------------------------------
AUTHP="$RAIZ/router/auth.php"
if grep -q "REGISTRO_ABIERTO" "$AUTHP" 2>/dev/null; then
  ABIERTO=$(grep -RIn --include='*.php' -E "define\(\s*['\"]REGISTRO_ABIERTO['\"]\s*,\s*true" "$RAIZ" 2>/dev/null | wc -l)
  [ "$ABIERTO" -eq 0 ] && emit PASS D4-09 "F-10 instalado en router/auth.php y REGISTRO_ABIERTO no esta definido como true" || emit FAIL D4-09 "F-10 instalado pero REGISTRO_ABIERTO=true definido ($ABIERTO lugar/es): el registro esta ABIERTO"
else
  emit FAIL D4-09 "F-10 NO instalado en router/auth.php: POST /api/register sigue abierto al publico"
fi

# --- D4-10 identidad de los archivos (huellas de 12 caracteres, no son secretos) ---------------------------------------------------
echo "           archivos relevantes (sha256[:12] bytes):"
for r in lib/auth_middleware.php lib/jwt.php router/index.php router/auth.php router/UserRouter.php router/EmpresaRouter.php router/PowerBIRouter.php router/ReporteRouter.php controllers/UserController.php controllers/EmpresaController.php lib/powerbi_authz.php lib/empresa_sanitizer.php; do
  if [ -f "$RAIZ/$r" ]; then printf '           %s  %6s  %s\n' "$(sha256sum "$RAIZ/$r" | cut -c1-12)" "$(stat -c %s "$RAIZ/$r")" "$r"; else printf '           %-12s  %6s  %s\n' "(ausente)" "-" "$r"; fi
done

echo "D4-RESUMEN: $PASS PASS, $FAIL FAIL, $PEND PENDIENTE  (PENDIENTE no es aprobacion)"
[ "$FAIL" -eq 0 ]
