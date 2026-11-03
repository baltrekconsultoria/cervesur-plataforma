# shellcheck shell=bash
# shellcheck disable=SC2034  # las constantes las usan los scripts que cargan este archivo
# Constantes y utilidades del DIAGNÓSTICO AGREGADO DE SOLO LECTURA (UX-07D.3B), accesible solo por túnel SSH (127.0.0.1:8090).
# Se carga con «source». No contiene contraseñas ni valores de .env. Todos los recursos son EXCLUSIVOS de este entorno.
# Este instalador NUNCA crea, modifica ni elimina bases, usuarios ni privilegios de MySQL: la cuenta cervesur_ro_diag se crea aparte (admin-mysql/).

SITIO=cervesur-react-ro
PUERTO=8090
DIR_BACK=/opt/cervesur-react-ro
DIR_BACKEND=/opt/cervesur-react-ro/backend
DIR_CONF=/etc/apache2/cervesur-react-ro
SITE_CONF=/etc/apache2/sites-available/cervesur-react-ro.conf
SITE_ENABLED=/etc/apache2/sites-enabled/cervesur-react-ro.conf
DIR_VAR=/var/lib/cervesur-react-ro
DIR_DOCROOT=/var/lib/cervesur-react-ro/docroot
DIR_SESIONES=/var/lib/cervesur-react-ro/sessions
DIR_RESPALDO=/var/backups/cervesur-react-ro
ENV_FILE=/opt/cervesur-react-ro/backend/.env
COOKIE_SESION=CERVESUR_REACT_RO
DB_NOMBRE=cervesur_portalbi          # base de LECTURA (productiva): este instalador solo comprueba que existe
DB_USUARIO=cervesur_ro_diag          # cuenta de solo lectura creada por el procedimiento administrativo independiente
MYSQL_ADMIN=${CERVESUR_MYSQL_ADMIN:-mysql}   # solo para LEER metadatos (existencia de bases y cuentas); nunca se escribe con él

# --- Recursos de los OTROS portales: solo se LEEN (huella) o se comparan para garantizar que no hay colisión. Jamás se escriben. ---
FLUTTER_DIR=/var/www/cervesur
FLUTTER_ARCHIVOS=(/etc/apache2/sites-enabled/cervesur.conf /var/www/cervesur/.htaccess)
PUERTO_FLUTTER=80
PUERTO_PRUEBA=8088
PUERTO_COMPAT=8089
SITIO_PRUEBA=cervesur-react-prueba
SITIO_COMPAT=cervesur-react-compat
SITE_CONF_PRUEBA=/etc/apache2/sites-available/cervesur-react-prueba.conf
SITE_ENABLED_PRUEBA=/etc/apache2/sites-enabled/cervesur-react-prueba.conf
SITE_CONF_COMPAT=/etc/apache2/sites-available/cervesur-react-compat.conf
SITE_ENABLED_COMPAT=/etc/apache2/sites-enabled/cervesur-react-compat.conf
DIR_FRONT_PRUEBA=/var/www/cervesur-react-prueba
DIR_BACK_PRUEBA=/opt/cervesur-react-prueba
DIR_CONF_PRUEBA=/etc/apache2/cervesur-react-prueba
DIR_RESPALDO_PRUEBA=/var/backups/cervesur-react-prueba
DIR_FRONT_COMPAT=/var/www/cervesur-react-compat
DIR_BACK_COMPAT=/opt/cervesur-react-compat
DIR_CONF_COMPAT=/etc/apache2/cervesur-react-compat
DIR_VAR_COMPAT=/var/lib/cervesur-react-compat
DIR_RESPALDO_COMPAT=/var/backups/cervesur-react-compat
DB_PRUEBA=cervesur_react_prueba
DB_COMPAT=cervesur_react_compat_qa
DB_USUARIO_PRUEBA=cervesur_react
DB_USUARIO_COMPAT=cervesur_react_compat
COOKIE_SESION_PRUEBA=CERVESUR_REACT
COOKIE_SESION_COMPAT=CERVESUR_REACT_COMPAT

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

# Garantiza que NINGÚN recurso de este entorno coincide ni se solapa con los de Flutter, el React de 8088 ni el de compatibilidad 8089.
verificar_no_colision() {
  local propio otro
  [ "$PUERTO" != "$PUERTO_FLUTTER" ] && [ "$PUERTO" != "$PUERTO_PRUEBA" ] && [ "$PUERTO" != "$PUERTO_COMPAT" ] || abortar "Colisión de puertos: $PUERTO coincide con otro portal."
  [ "$SITIO" != "$SITIO_PRUEBA" ] && [ "$SITIO" != "$SITIO_COMPAT" ] || abortar "Colisión de nombre de sitio Apache con otro portal."
  [ "$COOKIE_SESION" != "$COOKIE_SESION_PRUEBA" ] && [ "$COOKIE_SESION" != "$COOKIE_SESION_COMPAT" ] || abortar "Colisión del nombre de cookie de sesión con otro portal."
  [ "$DB_USUARIO" != "$DB_USUARIO_PRUEBA" ] && [ "$DB_USUARIO" != "$DB_USUARIO_COMPAT" ] || abortar "Colisión de usuario MySQL con otro portal."
  [ "$DB_NOMBRE" != "$DB_PRUEBA" ] && [ "$DB_NOMBRE" != "$DB_COMPAT" ] || abortar "La base de lectura coincide con la base de otro portal React."
  for propio in "$DIR_BACK" "$DIR_CONF" "$DIR_VAR" "$DIR_RESPALDO" "$SITE_CONF" "$SITE_ENABLED"; do
    for otro in "$FLUTTER_DIR" "$DIR_FRONT_PRUEBA" "$DIR_BACK_PRUEBA" "$DIR_CONF_PRUEBA" "$DIR_RESPALDO_PRUEBA" "$SITE_CONF_PRUEBA" "$SITE_ENABLED_PRUEBA" \
      "$DIR_FRONT_COMPAT" "$DIR_BACK_COMPAT" "$DIR_CONF_COMPAT" "$DIR_VAR_COMPAT" "$DIR_RESPALDO_COMPAT" "$SITE_CONF_COMPAT" "$SITE_ENABLED_COMPAT" "/etc/apache2/sites-enabled/cervesur.conf"; do
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
  arbol_digest "$DIR_FRONT_PRUEBA"
  arbol_digest "$DIR_BACK_PRUEBA"
  cabeceras_http "http://127.0.0.1:$PUERTO_PRUEBA/"
  cabeceras_http "http://127.0.0.1:$PUERTO_PRUEBA/api/ping"
}
huella_react_compat() {
  if [ ! -e "$SITE_ENABLED_COMPAT" ]; then echo "react-8089: no instalado"; return 0; fi
  sha256sum "$SITE_CONF_COMPAT" "$DIR_CONF_COMPAT/react-compat-common.conf" 2>/dev/null
  arbol_digest "$DIR_FRONT_COMPAT"
  arbol_digest "$DIR_BACK_COMPAT"
  cabeceras_http "http://127.0.0.1:$PUERTO_COMPAT/"
  cabeceras_http "http://127.0.0.1:$PUERTO_COMPAT/api/ping"
}
huella_servidor() {
  echo "puertos:"; ss -ltnH 2>/dev/null | awk '{print $4}' | grep -v ":$PUERTO\$" | LC_ALL=C sort -u
  echo "modulos-apache:"; apache2ctl -M 2>/dev/null | LC_ALL=C sort | sha256sum | cut -c1-64
  echo "sitios-habilitados:"; local sitio; for sitio in /etc/apache2/sites-enabled/*; do [ "${sitio##*/}" = "cervesur-react-ro.conf" ] || echo "${sitio##*/}"; done | LC_ALL=C sort
  echo "bases (metadatos):"; ($MYSQL_ADMIN -N -e 'SHOW DATABASES' 2>/dev/null || echo 'mysql-no-disponible') | LC_ALL=C sort
  echo "usuarios mysql (metadatos):"; ($MYSQL_ADMIN -N -e 'SELECT user, host FROM mysql.user' 2>/dev/null || echo 'mysql-no-disponible') | LC_ALL=C sort
}
huella_portales() {
  echo "== flutter"; huella_flutter
  echo "== react-8088"; huella_react_prueba
  echo "== react-8089"; huella_react_compat
  echo "== servidor"; huella_servidor
}

# --- Token de diagnóstico: 256 bits de azar (64 caracteres hexadecimales). Nunca se imprime salvo en token.sh --mostrar / --rotar. ---
generar_token() { openssl rand -hex 32; }
token_valido() { local t; IFS= read -r t; [[ $t =~ ^[0-9a-f]{64}$ ]]; }
# Lee un valor del .env de este entorno SIN mostrarlo (se usa en variables locales; nunca en argumentos de comandos).
valor_env() { [ -r "$ENV_FILE" ] && sed -n "s/^$1=//p" "$ENV_FILE" | head -1; }
# Ejecuta curl con el token por la entrada estándar (-K -): no aparece en la lista de procesos, ni en la URL, ni en ningún archivo.
curl_con_token() { # argumentos de curl
  local t; t=$(valor_env CERVESUR_DIAG_TOKEN)
  printf 'header = "X-Diagnostic-Token: %s"\n' "$t" | curl -K - "$@"
}
