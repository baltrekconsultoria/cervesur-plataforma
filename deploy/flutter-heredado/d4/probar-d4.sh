#!/usr/bin/env bash
# Prueba del script D4 con datos 100 % sinteticos en un contenedor sin red. Verifica casos buenos y malos y que NUNCA se imprima un valor.
# Uso: bash probar-d4.sh
set -u
export MSYS_NO_PATHCONV=1
AQUI="$(cd "$(dirname "$0")" && pwd)"
ruta() { if command -v cygpath >/dev/null 2>&1; then cygpath -w "$1"; else echo "$1"; fi; }
cat > "$AQUI/.casos.sh" <<'EOS'
set -u
S=/work/d4-atestacion-solo-lectura.sh
falla=0; total=0
chk() { total=$((total+1)); if [ "$1" = "$2" ]; then echo "OK    $3"; else echo "FALLA $3 (esperado $1, obtenido $2)"; falla=$((falla+1)); fi; }
mk() { # nombre  jwt_secret  jwt_exp  literal_en_router  modo  registro
  d=/tmp/$1; rm -rf $d; mkdir -p $d/router $d/lib $d/controllers
  { [ -n "$2" ] && echo "JWT_SECRET=$2"; [ -n "$3" ] && echo "JWT_EXPIRATION=$3"; true; } > $d/.env; chmod 600 $d/.env
  printf '<?php\n$s = getenv("JWT_SECRET") ?: %s;\n' "'$4'" > $d/router/ReporteRouter.php
  printf '<?php\nif (!defined("POWERBI_AUTH_MODE")) { define("POWERBI_AUTH_MODE", "%s"); }\n' "$5" > $d/lib/powerbi_authz.php
  if [ "$6" = si ]; then printf '<?php\nif (!defined("REGISTRO_ABIERTO") || REGISTRO_ABIERTO !== true) { exit; }\n' > $d/router/auth.php; else printf '<?php\n// registro abierto\n' > $d/router/auth.php; fi
}
BUENO=$(head -c 48 /dev/urandom | base64 | tr -d '/+=\n' | cut -c1-48)
mk bueno "$BUENO" 3600 'literal-publico-sintetico-0001' retirar si
SAL=$(bash $S --raiz /tmp/bueno --expiracion-prevista 3600); RC=$?
chk 0 "$RC" "caso bueno: sin FAIL"
echo "$SAL" | grep -q "D4-06 .*coincidencias con el JWT_SECRET real: 0" && r=si || r=no; chk si "$r" "caso bueno: 0 coincidencias con el literal"
echo "$SAL" | grep -q "D4-08 .*PENDIENTE\|PENDIENTE.*D4-08 " && r=si || r=no; chk si "$r" "caso bueno: K1 queda PENDIENTE hasta la prueba externa (no se aprueba sola)"
echo "$SAL" | grep -qF "$BUENO" && r=si || r=no; chk no "$r" "NO imprime el secreto"
echo "$SAL" | grep -qF "literal-publico-sintetico-0001" && r=si || r=no; chk no "$r" "NO imprime el literal del router"
mk corto "abc123XYZ" 3600 'otro-literal' retirar si
bash $S --raiz /tmp/corto --expiracion-prevista 3600 | grep -q "FAIL.*D4-03" && r=si || r=no; chk si "$r" "secreto corto: FAIL D4-03"
mk igual "literal-publico-sintetico-0002-ABCDEFGHIJKLMNOP" 3600 'literal-publico-sintetico-0002-ABCDEFGHIJKLMNOP' retirar si
SAL=$(bash $S --raiz /tmp/igual --expiracion-prevista 3600); echo "$SAL" | grep -q "FAIL.*D4-06" && r=si || r=no; chk si "$r" "secreto IGUAL al literal por defecto: FAIL D4-06"
echo "$SAL" | grep -qF "literal-publico-sintetico-0002" && r=si || r=no; chk no "$r" "NO imprime el secreto igual al literal"
mk debil "supersecret" 3600 'x' retirar si
bash $S --raiz /tmp/debil --expiracion-prevista 3600 | grep -q "FAIL.*D4-04" && r=si || r=no; chk si "$r" "valor de ejemplo conocido: FAIL D4-04"
mk sinjwt "" 3600 'x' retirar si
bash $S --raiz /tmp/sinjwt --expiracion-prevista 3600 | grep -q "PENDIENTE.*D4-02" && r=si || r=no; chk si "$r" "JWT_SECRET ausente del .env: PENDIENTE (no PASS)"
mk exp "$BUENO" 86400 'x' retirar si
bash $S --raiz /tmp/exp --expiracion-prevista 3600 | grep -q "FAIL.*D4-07" && r=si || r=no; chk si "$r" "JWT_EXPIRATION distinto del previsto: FAIL D4-07"
mk expnum "$BUENO" abc 'x' retirar si
bash $S --raiz /tmp/expnum --expiracion-prevista 3600 | grep -q "FAIL.*D4-07" && r=si || r=no; chk si "$r" "JWT_EXPIRATION no numerico: FAIL D4-07"
mk obs "$BUENO" 3600 'x' observar si
bash $S --raiz /tmp/obs --expiracion-prevista 3600 | grep -q "FAIL.*D4-08 " && r=si || r=no; chk si "$r" "modo observar: FAIL D4-08"
mk reg "$BUENO" 3600 'x' retirar no
bash $S --raiz /tmp/reg --expiracion-prevista 3600 | grep -q "FAIL.*D4-09" && r=si || r=no; chk si "$r" "F-10 ausente: FAIL D4-09"
chmod 644 /tmp/bueno/.env; bash $S --raiz /tmp/bueno --expiracion-prevista 3600 | grep -q "FAIL.*D4-01" && r=si || r=no; chk si "$r" ".env legible por otros: FAIL D4-01"
bash $S --raiz /tmp/bueno 2>/dev/null; chk 2 "$?" "sin --expiracion-prevista: error de uso"
# solo lectura: ningun archivo cambia
a=$(find /tmp/bueno -type f -exec sha256sum {} + | sort | sha256sum); bash $S --raiz /tmp/bueno --expiracion-prevista 3600 >/dev/null; b=$(find /tmp/bueno -type f -exec sha256sum {} + | sort | sha256sum); chk "$a" "$b" "el script no modifica ningun archivo"
echo "RESULTADO D4: $((total-falla))/$total OK, $falla fallas"; [ $falla -eq 0 ]
EOS
docker run --rm --network none -v "$(ruta "$AQUI"):/work:ro" php:8.3-cli bash /work/.casos.sh
RC=$?; rm -f "$AQUI/.casos.sh"; exit $RC
