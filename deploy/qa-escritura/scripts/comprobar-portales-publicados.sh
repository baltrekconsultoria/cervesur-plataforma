#!/usr/bin/env bash
# Cervesur · UX-08H · ¿Están APAGADAS las escrituras administrativas en los portales PUBLICADOS (443/8443)? SOLO LECTURA.
#   bash comprobar-portales-publicados.sh [instancia ...]      (por defecto: /opt/cervesur-react-usuarios /opt/cervesur-react-admin)
# Una «instancia» es la carpeta del portal (con backend/ dentro) o la propia carpeta backend. Para leer un .env de modo 640 root:www-data use sudo.
#
# Imprime SOLO: instancia · estado efectivo · fuente · PASS/FAIL. No imprime el contenido de ningún .env ni ningún otro valor.
# Réplica de la regla del código (backend/lib/env.php): loadEnv() omite las líneas que empiezan por «#», parte cada línea en el PRIMER «=» y recorta
# nombre y valor (sin quitar comillas); si el nombre se repite, GANA la última; lo definido en .env pisa lo que Apache haya puesto en el entorno.
# Se vigila, en cada instancia publicada, que NINGUNA de estas claves esté encendida:
#   USER_ADMIN_WRITES=1 · ADMIN_WRITES_{EMPRESAS,REPORTES,ACCESOS,USUARIOS,USUARIOS_SENSIBLE}=1 · CERVESUR_MODE=admin_escritura · CERVESUR_REQUIRED_MODE=admin_escritura
# (admin_escritura NO debe configurarse jamás en 443/8443). Además, cualquier mención de esas claves en Apache (SetEnv/PassEnv), systemd o .htaccess
# se trata como «no se puede demostrar apagado». Cualquier cosa que no se pueda demostrar apagada es FAIL (NO-GO para instalar la QA). Nada se modifica.
set -u
APACHE_DIR=${CERVESUR_APACHE_DIR:-/etc/apache2}
SYSTEMD_DIRS=${CERVESUR_SYSTEMD_DIRS:-"/etc/systemd/system /lib/systemd/system /usr/lib/systemd/system"}
CLAVES_UNO="USER_ADMIN_WRITES ADMIN_WRITES_EMPRESAS ADMIN_WRITES_REPORTES ADMIN_WRITES_ACCESOS ADMIN_WRITES_USUARIOS ADMIN_WRITES_USUARIOS_SENSIBLE"
PATRON_MENCION='USER_ADMIN_WRITES|ADMIN_WRITES_|admin_escritura'
if [ "$#" -eq 0 ]; then set -- /opt/cervesur-react-usuarios /opt/cervesur-react-admin; fi

# Lista de motivos por los que el .env deja algo ENCENDIDO (reglas de loadEnv()): imprime una palabra por clave encendida; «ilegible» si no se puede leer.
claves_encendidas() {
  local f=$1
  [ -r "$f" ] || { if [ -e "$f" ]; then echo ilegible; fi; return; }
  tr -d '\r' < "$f" | awk -v claves="$CLAVES_UNO" '
    function trim(s) { sub(/^[ \t]+/, "", s); sub(/[ \t]+$/, "", s); return s }
    { linea = trim($0); if (substr(linea, 1, 1) == "#" || index($0, "=") == 0) next
      pos = index($0, "="); nombre = trim(substr($0, 1, pos - 1)); valor = trim(substr($0, pos + 1)); ultimo[nombre] = valor }
    END { n = split(claves, k, " "); for (i = 1; i <= n; i++) if (ultimo[k[i]] == "1") print k[i]
          if (ultimo["CERVESUR_MODE"] == "admin_escritura" || ultimo["CERVESUR_REQUIRED_MODE"] == "admin_escritura") print "modo-admin_escritura" }'
}
menciones() { # cuenta archivos (no líneas) que mencionan las claves; nunca imprime su contenido
  local n=0 d
  for d in "$@"; do [ -e "$d" ] || continue; n=$((n + $(grep -rIlsE -- "$PATRON_MENCION" "$d" 2>/dev/null | wc -l))); done
  echo "$n"
}

fallos=0
printf '%-40s %-34s %-34s %s\n' INSTANCIA ESTADO-EFECTIVO FUENTE RESULTADO
for inst in "$@"; do
  base=${inst%/}; [ -d "$base/backend" ] && back=$base/backend || back=$base
  if [ ! -d "$back" ]; then printf '%-40s %-34s %-34s %s\n' "$inst" "no-se-puede-demostrar" "carpeta ausente" FAIL; fallos=$((fallos + 1)); continue; fi
  encendidas=$(claves_encendidas "$back/.env")
  # shellcheck disable=SC2086  # SYSTEMD_DIRS es una lista separada por espacios a propósito
  apache=$(menciones "$APACHE_DIR" $SYSTEMD_DIRS "$base/.htaccess" "$back/.htaccess" "$base/public/.htaccess")
  fuente=".env:sin-escrituras"; efectivo=apagado; res=PASS
  if [ "$encendidas" = ilegible ]; then efectivo="no-se-puede-demostrar"; fuente=".env:ilegible(use-sudo)"; res=FAIL
  elif [ -n "$encendidas" ]; then efectivo=ENCENDIDO; fuente=".env:$(tr '\n' ',' <<<"$encendidas" | sed 's/,$//')"; res=FAIL; fi
  if [ "$apache" -gt 0 ]; then
    [ "$efectivo" = apagado ] && efectivo="no-se-puede-demostrar"
    fuente="$fuente,apache/systemd/.htaccess:$apache-archivo(s)-la-mencionan"; res=FAIL
  fi
  [ "$res" = PASS ] || fallos=$((fallos + 1))
  printf '%-40s %-34s %-34s %s\n' "$inst" "$efectivo" "$fuente" "$res"
done
echo
if [ "$fallos" -eq 0 ]; then echo "RESULTADO: PASS (ninguna escritura administrativa ni admin_escritura en los portales publicados)"; exit 0; fi
echo "RESULTADO: FAIL en $fallos instancia(s): NO se puede demostrar que las escrituras estén apagadas → NO-GO para instalar la QA."
echo "Si el FAIL viene de una mención en Apache/systemd/.htaccess, revise a mano ESA línea (sin pegar secretos) antes de decidir."
exit 1
