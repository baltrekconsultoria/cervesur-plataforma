#!/usr/bin/env bash
# Prueba el script de entrega con un backend 100 % sintetico en un contenedor sin red: enmascara, no filtra, no toca el origen, rechaza destinos invalidos.
set -u
export MSYS_NO_PATHCONV=1
AQUI="$(cd "$(dirname "$0")" && pwd)"
ruta() { if command -v cygpath >/dev/null 2>&1; then cygpath -w "$1"; else echo "$1"; fi; }
cat > "$AQUI/.casos2.sh" <<'EOS'
set -u
S=/work/entrega-fuentes-enmascaradas.sh; falla=0; total=0
chk() { total=$((total+1)); if [ "$1" = "$2" ]; then echo "OK    $3"; else echo "FALLA $3 (esperado $1, obtenido $2)"; falla=$((falla+1)); fi; }
R=/tmp/back; rm -rf $R /tmp/out /tmp/out2; mkdir -p $R/lib $R/router $R/controllers $R/models
printf '<?php\n$s = getenv("JWT_SECRET") ?: %s;\n$cfg = ["DB_PASS" => "contrasena-sintetica-xyz", "host" => "db"];\n' "'literal-sintetico-ZZZ-0001'" > $R/router/ReporteRouter.php
printf '<?php\n$p = getenv("DB_PASS");\n$token = "abcdefg-sintetico-token";\n' > $R/lib/db.php
for f in lib/auth_middleware.php lib/jwt.php lib/env.php router/index.php controllers/EmpresaController.php models/EmpresaModel.php controllers/UserController.php models/UserModel.php router/StatsRouter.php router/wiki.php router/UserRouter.php controllers/AuthController.php; do mkdir -p $R/$(dirname $f); echo "<?php // $f" > $R/$f; done
echo 'JWT_SECRET=NUNCA-DEBE-COPIARSE' > $R/.env
a=$(find $R -type f -exec sha256sum {} + | sort | sha256sum)
bash $S --raiz $R --destino /tmp/out --completo > /tmp/salida.txt 2>&1; chk 0 "$?" "ejecucion correcta"
grep -rqF "literal-sintetico-ZZZ-0001" /tmp/out && r=si || r=no; chk no "$r" "el literal por defecto de JWT_SECRET NO aparece en la copia"
grep -rqF "contrasena-sintetica-xyz" /tmp/out && r=si || r=no; chk no "$r" "la contrasena literal NO aparece en la copia"
grep -rqF "abcdefg-sintetico-token" /tmp/out && r=si || r=no; chk no "$r" "el token literal NO aparece en la copia"
grep -qF "<MASK>" /tmp/out/router/ReporteRouter.php && r=si || r=no; chk si "$r" "la marca <MASK> esta en la copia"
grep -rqF "NUNCA-DEBE-COPIARSE" /tmp/out && r=si || r=no; chk no "$r" "el .env no se copia"
[ -f /tmp/out/.env ] && r=si || r=no; chk no "$r" "no existe .env en el destino"
grep -qF "$(sha256sum $R/router/ReporteRouter.php | cut -d' ' -f1)" /tmp/out/HUELLAS-ORIGINALES.txt && r=si || r=no; chk si "$r" "se registra la huella del ORIGINAL"
grep -rqF "literal-sintetico-ZZZ-0001" /tmp/salida.txt /tmp/out/HUELLAS-ORIGINALES.txt /tmp/out/HUELLAS-ENMASCARADOS.txt && r=si || r=no; chk no "$r" "ni la salida ni las huellas contienen el literal"
b=$(find $R -type f -exec sha256sum {} + | sort | sha256sum); chk "$a" "$b" "el backend de origen no cambia"
bash $S --raiz $R --destino $R/dentro >/dev/null 2>&1; chk 2 "$?" "rechaza un destino dentro del backend"
bash $S --raiz $R --destino /tmp/out >/dev/null 2>&1; chk 2 "$?" "rechaza un destino no vacio"
bash $S --raiz /tmp/no-existe --destino /tmp/out2 >/dev/null 2>&1; chk 2 "$?" "rechaza una raiz inexistente"
rm -f $R/lib/jwt.php; bash $S --raiz $R --destino /tmp/out2 2>&1 | grep -q "No existen en el servidor.*lib/jwt.php" && r=si || r=no; chk si "$r" "informa los archivos que no existen"
echo "RESULTADO ENTREGA: $((total-falla))/$total OK, $falla fallas"; [ $falla -eq 0 ]
EOS
docker run --rm --network none -v "$(ruta "$AQUI"):/work:ro" php:8.3-cli bash /work/.casos2.sh
RC=$?; rm -f "$AQUI/.casos2.sh"; exit $RC
