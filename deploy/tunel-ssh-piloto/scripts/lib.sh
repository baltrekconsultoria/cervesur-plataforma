# shellcheck shell=bash
# shellcheck disable=SC2034  # las constantes las usan los scripts que cargan este archivo
# Constantes y utilidades del PILOTO VISUAL con informes reales (UX-07D.4), accesible solo por túnel SSH (127.0.0.1:8091).
# Se carga con «source». No contiene contraseñas ni valores de .env. Todos los recursos son EXCLUSIVOS de este entorno.
# Este instalador NUNCA crea, modifica ni elimina bases, usuarios ni privilegios de MySQL: la cuenta cervesur_ro_visor se crea aparte (admin-mysql/).

SITIO=cervesur-react-piloto
PUERTO=8091
DIR_FRONT=/var/www/cervesur-react-piloto
DIR_PUBLIC=/var/www/cervesur-react-piloto/public
DIR_BACK=/opt/cervesur-react-piloto
DIR_BACKEND=/opt/cervesur-react-piloto/backend
DIR_CONF=/etc/apache2/cervesur-react-piloto
SITE_CONF=/etc/apache2/sites-available/cervesur-react-piloto.conf
SITE_ENABLED=/etc/apache2/sites-enabled/cervesur-react-piloto.conf
DIR_VAR=/var/lib/cervesur-react-piloto
DIR_ESTADO=/var/lib/cervesur-react-piloto/estado
DIR_SESIONES=/var/lib/cervesur-react-piloto/sessions
DIR_RESPALDO=/var/backups/cervesur-react-piloto
ENV_FILE=/opt/cervesur-react-piloto/backend/.env
CLAVE_OPERADOR_FILE=/opt/cervesur-react-piloto/operador.clave   # fuera del DocumentRoot y del backend; root:root 600; se elimina al revertir
COOKIE_SESION=CERVESUR_REACT_PILOTO
OPERADOR=operador
DB_NOMBRE=cervesur_portalbi          # base de LECTURA (productiva): este instalador solo comprueba que existe
DB_USUARIO=cervesur_ro_visor         # cuenta de solo lectura (15 columnas) creada por el procedimiento administrativo independiente
MYSQL_ADMIN=${CERVESUR_MYSQL_ADMIN:-mysql}   # solo para LEER metadatos (existencia de bases y cuentas); nunca se escribe con él

# --- Recursos de los OTROS portales: solo se LEEN (huella) o se comparan para garantizar que no hay colisión. Jamás se escriben. ---
FLUTTER_DIR=/var/www/cervesur
FLUTTER_ARCHIVOS=(/etc/apache2/sites-enabled/cervesur.conf /var/www/cervesur/.htaccess)
PUERTO_FLUTTER=80
PUERTO_PRUEBA=8088
PUERTO_COMPAT=8089
PUERTO_DIAG=8090
SITIO_PRUEBA=cervesur-react-prueba
SITIO_COMPAT=cervesur-react-compat
SITIO_DIAG=cervesur-react-ro
SITE_CONF_PRUEBA=/etc/apache2/sites-available/cervesur-react-prueba.conf
SITE_ENABLED_PRUEBA=/etc/apache2/sites-enabled/cervesur-react-prueba.conf
SITE_CONF_COMPAT=/etc/apache2/sites-available/cervesur-react-compat.conf
SITE_ENABLED_COMPAT=/etc/apache2/sites-enabled/cervesur-react-compat.conf
SITE_CONF_DIAG=/etc/apache2/sites-available/cervesur-react-ro.conf
SITE_ENABLED_DIAG=/etc/apache2/sites-enabled/cervesur-react-ro.conf
DIR_FRONT_PRUEBA=/var/www/cervesur-react-prueba
DIR_BACK_PRUEBA=/opt/cervesur-react-prueba
DIR_CONF_PRUEBA=/etc/apache2/cervesur-react-prueba
DIR_RESPALDO_PRUEBA=/var/backups/cervesur-react-prueba
DIR_FRONT_COMPAT=/var/www/cervesur-react-compat
DIR_BACK_COMPAT=/opt/cervesur-react-compat
DIR_CONF_COMPAT=/etc/apache2/cervesur-react-compat
DIR_VAR_COMPAT=/var/lib/cervesur-react-compat
DIR_RESPALDO_COMPAT=/var/backups/cervesur-react-compat
DIR_BACK_DIAG=/opt/cervesur-react-ro
DIR_CONF_DIAG=/etc/apache2/cervesur-react-ro
DIR_VAR_DIAG=/var/lib/cervesur-react-ro
DIR_RESPALDO_DIAG=/var/backups/cervesur-react-ro
DB_PRUEBA=cervesur_react_prueba
DB_COMPAT=cervesur_react_compat_qa
DB_USUARIO_PRUEBA=cervesur_react
DB_USUARIO_COMPAT=cervesur_react_compat
DB_USUARIO_DIAG=cervesur_ro_diag
COOKIE_SESION_PRUEBA=CERVESUR_REACT
COOKIE_SESION_COMPAT=CERVESUR_REACT_COMPAT
COOKIE_SESION_DIAG=CERVESUR_REACT_RO

ok() { printf 'OK     %s\n' "$*"; }
aviso() { printf 'AVISO  %s\n' "$*"; }
fallo() { printf 'FALLO  %s\n' "$*" >&2; }
abortar() { fallo "$*"; exit 1; }
requerir_root() { [ "$(id -u)" -eq 0 ] || abortar "Ejecute este script con sudo."; }
con_systemd() { [ -d /run/systemd/system ]; }

# Módulo de Apache cargado. La salida se captura antes de filtrar: con «pipefail», un «grep -q» en tubería puede dar un falso negativo por SIGPIPE.
modulo_apache() { local salida; salida=$(apache2ctl -M 2>/dev/null) || true; grep -Eq " $1_module " <<<"$salida"; }

# Recarga SUAVE (graceful) de Apache, siempre precedida de configtest. Nunca reinicia el servicio y nunca habilita/deshabilita módulos.
apache_recargar() {
  apache2ctl configtest >/dev/null 2>&1 || abortar "apache2ctl configtest falló: no se recarga Apache."
  if con_systemd; then systemctl reload apache2; else apache2ctl graceful; fi
}

mysql_activo() {
  if con_systemd; then systemctl is-active --quiet mysql; else mysqladmin ping --silent >/dev/null 2>&1; fi
}

# ¿$1 es igual a $2 o está contenido (como ruta) en $2, o a la inversa? Sirve para detectar colisiones de rutas entre portales.
rutas_solapadas() { case "$1/" in "$2/"*) return 0 ;; esac; case "$2/" in "$1/"*) return 0 ;; esac; return 1; }

# Garantiza que NINGÚN recurso de este entorno coincide ni se solapa con los de Flutter, 8088, 8089 ni el diagnóstico 8090.
verificar_no_colision() {
  local propio otro p
  for p in "$PUERTO_FLUTTER" "$PUERTO_PRUEBA" "$PUERTO_COMPAT" "$PUERTO_DIAG"; do [ "$PUERTO" != "$p" ] || abortar "Colisión de puertos: $PUERTO coincide con otro portal."; done
  for p in "$SITIO_PRUEBA" "$SITIO_COMPAT" "$SITIO_DIAG"; do [ "$SITIO" != "$p" ] || abortar "Colisión de nombre de sitio Apache con otro portal."; done
  for p in "$COOKIE_SESION_PRUEBA" "$COOKIE_SESION_COMPAT" "$COOKIE_SESION_DIAG"; do [ "$COOKIE_SESION" != "$p" ] || abortar "Colisión del nombre de cookie de sesión con otro portal."; done
  for p in "$DB_USUARIO_PRUEBA" "$DB_USUARIO_COMPAT" "$DB_USUARIO_DIAG"; do [ "$DB_USUARIO" != "$p" ] || abortar "Colisión de usuario MySQL con otro portal (la cuenta del piloto debe ser propia)."; done
  [ "$DB_NOMBRE" != "$DB_PRUEBA" ] && [ "$DB_NOMBRE" != "$DB_COMPAT" ] || abortar "La base de lectura coincide con la base de otro portal React."
  for propio in "$DIR_FRONT" "$DIR_BACK" "$DIR_CONF" "$DIR_VAR" "$DIR_RESPALDO" "$SITE_CONF" "$SITE_ENABLED"; do
    for otro in "$FLUTTER_DIR" "$DIR_FRONT_PRUEBA" "$DIR_BACK_PRUEBA" "$DIR_CONF_PRUEBA" "$DIR_RESPALDO_PRUEBA" "$SITE_CONF_PRUEBA" "$SITE_ENABLED_PRUEBA" \
      "$DIR_FRONT_COMPAT" "$DIR_BACK_COMPAT" "$DIR_CONF_COMPAT" "$DIR_VAR_COMPAT" "$DIR_RESPALDO_COMPAT" "$SITE_CONF_COMPAT" "$SITE_ENABLED_COMPAT" \
      "$DIR_BACK_DIAG" "$DIR_CONF_DIAG" "$DIR_VAR_DIAG" "$DIR_RESPALDO_DIAG" "$SITE_CONF_DIAG" "$SITE_ENABLED_DIAG" "/etc/apache2/sites-enabled/cervesur.conf"; do
      ! rutas_solapadas "$propio" "$otro" || abortar "Colisión de rutas: $propio se solapa con $otro."
    done
  done
}

# --- Huellas: hash de lo que NO debe cambiar. Se calculan antes y después para demostrar que los demás portales no se tocaron. ---
# No leen el contenido de ningún .env (solo nombre, tamaño, fecha y permisos) ni datos de ninguna base: solo metadatos de MySQL.
arbol_digest() { # directorio
  [ -d "$1" ] || { echo "ausente $1"; return 0; }
  find "$1" -type f -printf '%P %s %T@ %m %u:%g\n' 2>/dev/null | LC_ALL=C sort | sha256sum | sed "s# *-\$#  arbol:$1#"
}
cabeceras_http() { # url: línea de estado + NOMBRES de cabecera (en minúsculas) + hash del cuerpo
  local url=$1 crudo cuerpo
  crudo=$(curl -sS -m 6 -o /dev/null -D - "$url" 2>/dev/null | tr -d '\r') || crudo='sin-respuesta'
  [ -n "$crudo" ] || crudo='sin-respuesta'
  printf '%s\n' "$crudo" | awk 'NR==1 { print; next } /^[A-Za-z-]+:/ { print "cabecera: " tolower(substr($0, 1, index($0, ":") - 1)) }' | LC_ALL=C sort
  cuerpo=$(curl -sS -m 6 "$url" 2>/dev/null | sha256sum | cut -c1-64) || cuerpo='sin-cuerpo'
  echo "cuerpo: $cuerpo"
}
huella_flutter() {
  local f
  for f in "${FLUTTER_ARCHIVOS[@]}"; do
    if [ -f "$f" ]; then sha256sum "$f"; else echo "ausente $f"; fi
  done
  arbol_digest "$FLUTTER_DIR"
  cabeceras_http "http://127.0.0.1:$PUERTO_FLUTTER/"
}
huella_react_prueba() {
  if [ ! -e "$SITE_ENABLED_PRUEBA" ]; then echo "react-8088: no instalado"; return 0; fi
  sha256sum "$SITE_CONF_PRUEBA" "$DIR_CONF_PRUEBA/react-common.conf" 2>/dev/null
  arbol_digest "$DIR_FRONT_PRUEBA"; arbol_digest "$DIR_BACK_PRUEBA"
  cabeceras_http "http://127.0.0.1:$PUERTO_PRUEBA/"; cabeceras_http "http://127.0.0.1:$PUERTO_PRUEBA/api/ping"
}
huella_react_compat() {
  if [ ! -e "$SITE_ENABLED_COMPAT" ]; then echo "react-8089: no instalado"; return 0; fi
  sha256sum "$SITE_CONF_COMPAT" "$DIR_CONF_COMPAT/react-compat-common.conf" 2>/dev/null
  arbol_digest "$DIR_FRONT_COMPAT"; arbol_digest "$DIR_BACK_COMPAT"
  cabeceras_http "http://127.0.0.1:$PUERTO_COMPAT/"; cabeceras_http "http://127.0.0.1:$PUERTO_COMPAT/api/ping"
}
huella_diag() {
  if [ ! -e "$SITE_ENABLED_DIAG" ]; then echo "diagnostico-8090: no instalado"; return 0; fi
  sha256sum "$SITE_CONF_DIAG" "$DIR_CONF_DIAG/react-ro-common.conf" 2>/dev/null
  arbol_digest "$DIR_BACK_DIAG"
  cabeceras_http "http://127.0.0.1:$PUERTO_DIAG/api/ping"
}
huella_servidor() {
  echo "puertos:"; ss -ltnH 2>/dev/null | awk '{print $4}' | grep -v ":$PUERTO\$" | LC_ALL=C sort -u
  echo "modulos-apache:"; apache2ctl -M 2>/dev/null | LC_ALL=C sort | sha256sum | cut -c1-64
  echo "sitios-habilitados:"; local sitio; for sitio in /etc/apache2/sites-enabled/*; do [ "${sitio##*/}" = "cervesur-react-piloto.conf" ] || echo "${sitio##*/}"; done | LC_ALL=C sort
  echo "bases (metadatos):"; ($MYSQL_ADMIN -N -e 'SHOW DATABASES' 2>/dev/null || echo 'mysql-no-disponible') | LC_ALL=C sort
  echo "usuarios mysql (metadatos):"; ($MYSQL_ADMIN -N -e 'SELECT user, host FROM mysql.user' 2>/dev/null || echo 'mysql-no-disponible') | LC_ALL=C sort
}
huella_portales() {
  echo "== flutter"; huella_flutter
  echo "== react-8088"; huella_react_prueba
  echo "== react-8089"; huella_react_compat
  echo "== diagnostico-8090"; huella_diag
  echo "== servidor"; huella_servidor
}

# --- Credenciales del OPERADOR del piloto (no es un usuario del sistema): clave aleatoria, hash bcrypt en el .env, clave en un archivo root 600 ---
generar_clave_operador() { openssl rand -base64 33 | tr -d '/+=\n' | cut -c1-24; }
clave_operador_valida() { local c; IFS= read -r c; [[ $c =~ ^[A-Za-z0-9]{24}$ ]]; }
generar_secreto() { openssl rand -hex 32; }
secreto_valido() { local t; IFS= read -r t; [[ $t =~ ^[0-9a-f]{64}$ ]]; }
# Hash bcrypt de la clave (la clave llega por el entorno del proceso hijo, nunca por argumentos).
hash_bcrypt() { CLAVE_PARA_HASH=$1 php -r 'echo password_hash(getenv("CLAVE_PARA_HASH"), PASSWORD_BCRYPT);'; }
# Lee un valor del .env de este entorno SIN mostrarlo (se usa en variables locales; nunca en argumentos de comandos).
valor_env() { [ -r "$ENV_FILE" ] && sed -n "s/^$1=//p" "$ENV_FILE" | head -1; }
# Inicia sesión como operador (clave por la entrada estándar) y devuelve el token por la salida estándar; nunca se imprime en pantalla.
token_operador() {
  local clave; clave=$(head -n 1 "$CLAVE_OPERADOR_FILE" 2>/dev/null) || return 1
  printf '{"login":"%s","password":"%s"}' "$OPERADOR" "$clave" | curl -sS -m 15 -H 'Content-Type: application/json' --data @- "http://127.0.0.1:$PUERTO/api/login" | sed -n 's/.*"token":"\([^"]*\)".*/\1/p'
}
# Ejecuta curl con el token por la entrada estándar (-K -): no aparece en la lista de procesos, ni en la URL, ni en ningún archivo.
curl_con_token() { # token, argumentos de curl
  local t=$1; shift
  printf 'header = "Authorization: Bearer %s"\n' "$t" | curl -K - "$@"
}
# Sustituye UNA variable del .env de forma atómica (archivo temporal en la misma carpeta + mv), conservando modo 640 y propietario root:www-data.
# El valor llega como argumento de función (no de otro programa) y nunca se imprime.
reemplazar_env() { # variable valor
  local var=$1 valor=$2 tmp linea hallada=0
  tmp=$(mktemp "$DIR_BACKEND/.env.nuevo.XXXXXX") || return 1
  chmod 600 "$tmp"
  while IFS= read -r linea; do
    case $linea in "$var="*) printf '%s=%s\n' "$var" "$valor"; hallada=1 ;; *) printf '%s\n' "$linea" ;; esac
  done < "$ENV_FILE" > "$tmp"
  [ "$hallada" -eq 1 ] || printf '%s=%s\n' "$var" "$valor" >> "$tmp"
  chown root:www-data "$tmp"; chmod 640 "$tmp"
  mv -f "$tmp" "$ENV_FILE"
}
# ¿La huella actual de los demás portales es igual a la línea base? Tras una recarga suave de Apache los sockets pueden tardar un instante en reabrirse:
# se reintenta unos segundos antes de declarar una diferencia (una diferencia real persiste).
huella_igual_a_linea_base() {
  local base="$DIR_RESPALDO/linea-base-portales.txt" i
  [ -f "$base" ] || return 2
  for i in 1 2 3 4 5 6 7 8 9 10; do
    [ "$(huella_portales)" = "$(cat "$base")" ] && return 0
    sleep 1
  done
  return 1
}
