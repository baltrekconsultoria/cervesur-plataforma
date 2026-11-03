#!/usr/bin/env bash
# Cervesur · UX-08F.1 · ¿Está APAGADO el interruptor USER_ADMIN_WRITES en un portal publicado? SOLO LECTURA.
#   bash interruptor-escrituras.sh [instancia ...]      (por defecto: /opt/cervesur-react-usuarios /opt/cervesur-react-admin)
# Una «instancia» es la carpeta del portal (con backend/ dentro) o la propia carpeta backend. Para leer un .env de modo 640 root:www-data use sudo.
#
# Imprime SOLO: instancia · estado efectivo · fuente · PASS/FAIL. No imprime el contenido de ningún .env ni ningún otro valor.
# Réplica de la regla del código (backend/lib/env.php + write_support.php):
#   - loadEnv() omite las líneas que empiezan por «#», parte cada línea en el PRIMER «=» y recorta nombre y valor (sin quitar comillas);
#     si el nombre se repite, GANA la última (putenv); lo definido en .env pisa lo que Apache haya puesto en el entorno.
#   - Las escrituras existen únicamente con USER_ADMIN_WRITES exactamente «1».
# Fuentes que se comprueban: (1) código desplegado (interruptor presente y comparación estricta), (2) .env de la instancia,
# (3) cualquier mención en /etc/apache2 (SetEnv, PassEnv), /etc/apache2/envvars, unidades systemd y .htaccess de la instancia.
# Cualquier cosa que no se pueda demostrar apagada es FAIL (NO-GO para instalar la QA). Nada se modifica.
set -u
KEY=USER_ADMIN_WRITES
APACHE_DIR=${CERVESUR_APACHE_DIR:-/etc/apache2}
SYSTEMD_DIRS=${CERVESUR_SYSTEMD_DIRS:-"/etc/systemd/system /lib/systemd/system /usr/lib/systemd/system"}
if [ "$#" -eq 0 ]; then set -- /opt/cervesur-react-usuarios /opt/cervesur-react-admin; fi

# Valor efectivo en el .env, con las reglas de loadEnv(): imprime «sin-definir», «uno» o «distinto-de-uno».
estado_env() {
  local f=$1
  [ -r "$f" ] || { if [ -e "$f" ]; then echo ilegible; else echo "sin-definir"; fi; return; }
  tr -d '\r' < "$f" | awk -v key="$KEY" '
    function trim(s) { sub(/^[ \t]+/, "", s); sub(/[ \t]+$/, "", s); return s }
    { linea = trim($0); if (substr(linea, 1, 1) == "#" || index($0, "=") == 0) next
      pos = index($0, "="); nombre = trim(substr($0, 1, pos - 1)); valor = trim(substr($0, pos + 1))
      if (nombre == key) { definido = 1; ultimo = valor } }
    END { if (!definido) print "sin-definir"; else if (ultimo == "1") print "uno"; else print "distinto-de-uno" }'
}
menciones() { # cuenta archivos (no líneas) que mencionan la clave; nunca imprime su contenido
  local n=0 d
  for d in "$@"; do [ -e "$d" ] || continue; n=$((n + $(grep -rIlsF -- "$KEY" "$d" 2>/dev/null | wc -l))); done
  echo "$n"
}

fallos=0
printf '%-40s %-34s %-34s %s\n' INSTANCIA ESTADO-EFECTIVO FUENTE RESULTADO
for inst in "$@"; do
  base=${inst%/}; [ -d "$base/backend" ] && back=$base/backend || back=$base
  if [ ! -d "$back" ]; then printf '%-40s %-34s %-34s %s\n' "$inst" "no-se-puede-demostrar" "carpeta ausente" FAIL; fallos=$((fallos + 1)); continue; fi
  codigo=$back/lib/write_support.php
  if [ ! -r "$codigo" ]; then
    # Código anterior a UX-08: no contiene las rutas de escritura de usuarios que el interruptor gobierna.
    codigo_estado="sin-interruptor"
  elif grep -qF "getenv('$KEY') === '1'" "$codigo"; then codigo_estado="estricto"
  else codigo_estado="no-estricto"; fi
  env_estado=$(estado_env "$back/.env")
  # shellcheck disable=SC2086  # SYSTEMD_DIRS es una lista separada por espacios a propósito
  apache=$(menciones "$APACHE_DIR" $SYSTEMD_DIRS "$base/.htaccess" "$back/.htaccess" "$base/public/.htaccess")
  fuente=""; efectivo=apagado; res=PASS
  case "$codigo_estado" in
    no-estricto) efectivo="no-se-puede-demostrar"; fuente="codigo:comparacion-no-estricta"; res=FAIL ;;
    sin-interruptor) fuente="codigo:sin-interruptor(UX-08-no-desplegado)" ;;
    estricto) fuente="codigo:defecto-apagado" ;;
  esac
  case "$env_estado" in
    uno) efectivo=ENCENDIDO; fuente="$fuente,.env:definida=1"; res=FAIL ;;
    distinto-de-uno) fuente="$fuente,.env:definida!=1" ;;
    ilegible) efectivo="no-se-puede-demostrar"; fuente="$fuente,.env:ilegible(use-sudo)"; res=FAIL ;;
    *) fuente="$fuente,.env:sin-definir" ;;
  esac
  if [ "$apache" -gt 0 ]; then
    [ "$efectivo" = apagado ] && efectivo="no-se-puede-demostrar"
    fuente="$fuente,apache/systemd/.htaccess:$apache-archivo(s)-la-mencionan"; res=FAIL
  fi
  [ "$res" = PASS ] || fallos=$((fallos + 1))
  printf '%-40s %-34s %-34s %s\n' "$inst" "$efectivo" "$fuente" "$res"
done
echo
if [ "$fallos" -eq 0 ]; then echo "RESULTADO: PASS (USER_ADMIN_WRITES apagado y sin otra fuente que pueda encenderlo en ninguna instancia)"; exit 0; fi
echo "RESULTADO: FAIL en $fallos instancia(s): NO se puede demostrar que el interruptor esté apagado → NO-GO para instalar la QA."
echo "Si el FAIL viene de una mención en Apache/systemd/.htaccess, revise a mano ESA línea (sin pegar secretos) antes de decidir."
exit 1
