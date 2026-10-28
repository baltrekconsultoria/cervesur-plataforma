# shellcheck shell=bash
# shellcheck disable=SC2034  # las constantes las usan los scripts que cargan este archivo
# Constantes y utilidades del entorno de COMPATIBILIDAD PRODUCTIVA (UX-07D.2C), accesible solo por túnel SSH (127.0.0.1:8089).
# Se carga con «source». No contiene contraseñas ni valores de .env. Todos los recursos son EXCLUSIVOS de este entorno.

SITIO=cervesur-react-compat
PUERTO=8089
DIR_FRONT=/var/www/cervesur-react-compat
DIR_BACK=/opt/cervesur-react-compat
DIR_CONF=/etc/apache2/cervesur-react-compat
SITE_CONF=/etc/apache2/sites-available/cervesur-react-compat.conf
SITE_ENABLED=/etc/apache2/sites-enabled/cervesur-react-compat.conf
DIR_VAR=/var/lib/cervesur-react-compat
DIR_SESIONES=/var/lib/cervesur-react-compat/sessions
DIR_RESPALDO=/var/backups/cervesur-react-compat
DB_NOMBRE=cervesur_react_compat_qa
DB_USUARIO=cervesur_react_compat
COOKIE_SESION=CERVESUR_REACT_COMPAT
MYSQL_ADMIN=${CERVESUR_MYSQL_ADMIN:-mysql}

# --- Recursos de los OTROS portales: solo se LEEN (huella) o se comparan para garantizar que no hay colisión. Jamás se escriben. ---
FLUTTER_DIR=/var/www/cervesur
FLUTTER_ARCHIVOS=(/etc/apache2/sites-enabled/cervesur.conf /var/www/cervesur/.htaccess)
PUERTO_FLUTTER=80
PUERTO_PRUEBA=8088
SITIO_PRUEBA=cervesur-react-prueba
SITE_CONF_PRUEBA=/etc/apache2/sites-available/cervesur-react-prueba.conf
SITE_ENABLED_PRUEBA=/etc/apache2/sites-enabled/cervesur-react-prueba.conf
DIR_FRONT_PRUEBA=/var/www/cervesur-react-prueba
DIR_BACK_PRUEBA=/opt/cervesur-react-prueba
DIR_CONF_PRUEBA=/etc/apache2/cervesur-react-prueba
DIR_RESPALDO_PRUEBA=/var/backups/cervesur-react-prueba
DB_PRUEBA=cervesur_react_prueba
DB_USUARIO_PRUEBA=cervesur_react
COOKIE_SESION_PRUEBA=CERVESUR_REACT

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

# Garantiza que NINGÚN recurso de este entorno coincide ni se solapa con los de Flutter o del React de 8088 (aborta si lo hace).
verificar_no_colision() {
  local propio otro
  [ "$PUERTO" != "$PUERTO_FLUTTER" ] && [ "$PUERTO" != "$PUERTO_PRUEBA" ] || abortar "Colisión de puertos: $PUERTO coincide con otro portal."
  [ "$SITIO" != "$SITIO_PRUEBA" ] || abortar "Colisión de nombre de sitio Apache con el React de 8088."
  [ "$DB_NOMBRE" != "$DB_PRUEBA" ] || abortar "Colisión de nombre de base de datos con el React de 8088."
  [ "$DB_USUARIO" != "$DB_USUARIO_PRUEBA" ] || abortar "Colisión de usuario MySQL con el React de 8088."
  [ "$COOKIE_SESION" != "$COOKIE_SESION_PRUEBA" ] || abortar "Colisión del nombre de cookie de sesión con el React de 8088."
  for propio in "$DIR_FRONT" "$DIR_BACK" "$DIR_CONF" "$DIR_VAR" "$DIR_RESPALDO" "$SITE_CONF" "$SITE_ENABLED"; do
    for otro in "$FLUTTER_DIR" "$DIR_FRONT_PRUEBA" "$DIR_BACK_PRUEBA" "$DIR_CONF_PRUEBA" "$DIR_RESPALDO_PRUEBA" "$SITE_CONF_PRUEBA" "$SITE_ENABLED_PRUEBA" "/etc/apache2/sites-enabled/cervesur.conf"; do
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
huella_servidor() {
  echo "puertos:"; ss -ltnH 2>/dev/null | awk '{print $4}' | grep -v ":$PUERTO\$" | LC_ALL=C sort -u
  echo "modulos-apache:"; apache2ctl -M 2>/dev/null | LC_ALL=C sort | sha256sum | cut -c1-64
  echo "sitios-habilitados:"; local sitio; for sitio in /etc/apache2/sites-enabled/*; do [ "${sitio##*/}" = "cervesur-react-compat.conf" ] || echo "${sitio##*/}"; done | LC_ALL=C sort
  echo "bases (metadatos):"; ($MYSQL_ADMIN -N -e 'SHOW DATABASES' 2>/dev/null || echo 'mysql-no-disponible') | grep -vx "$DB_NOMBRE" | LC_ALL=C sort
  echo "usuarios mysql (metadatos):"; ($MYSQL_ADMIN -N -e 'SELECT user, host FROM mysql.user' 2>/dev/null || echo 'mysql-no-disponible') | grep -v "^$DB_USUARIO\\b" | LC_ALL=C sort
}
huella_portales() {
  echo "== flutter"; huella_flutter
  echo "== react-8088"; huella_react_prueba
  echo "== servidor"; huella_servidor
}

# --- Contraseña del usuario MySQL (política validate_password MEDIUM: >= 8, mayúscula, minúscula, número y carácter especial) ---
# «Aa1!» garantiza las cuatro clases y openssl aporta 192 bits de azar. Solo caracteres seguros en SQL, en el .env y en la cadena DSN de PDO.
generar_clave_mysql() { printf 'Aa1!%s' "$(openssl rand -hex 24)"; }
clave_cumple_politica_medium() {
  local c; IFS= read -r c
  [ "${#c}" -ge 8 ] && [[ $c =~ [A-Z] ]] && [[ $c =~ [a-z] ]] && [[ $c =~ [0-9] ]] && [[ $c =~ [^A-Za-z0-9] ]] && [[ $c =~ ^[A-Za-z0-9!]+$ ]]
}
