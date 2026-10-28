# shellcheck shell=bash
# shellcheck disable=SC2034  # las constantes las usan los scripts que cargan este archivo
# Constantes y utilidades comunes de la instalación de PRUEBA de React por túnel SSH (UX-07D.1B).
# Se carga con «source»; no se ejecuta por sí solo. No contiene contraseñas ni valores de .env.

SITIO=cervesur-react-prueba
PUERTO=8088
DIR_FRONT=/var/www/cervesur-react-prueba
DIR_BACK=/opt/cervesur-react-prueba
DIR_CONF=/etc/apache2/cervesur-react-prueba
SITE_CONF=/etc/apache2/sites-available/cervesur-react-prueba.conf
DIR_RESPALDO=/var/backups/cervesur-react-prueba
DB_NOMBRE=cervesur_react_prueba
DB_USUARIO=cervesur_react
# Comando administrador de MySQL (por defecto «mysql» como root por socket, que es lo habitual en Ubuntu). Puede sustituirse con
# CERVESUR_MYSQL_ADMIN="mysql -u otro_admin -p". Solo se usa para crear/borrar la base y el usuario de PRUEBA.
MYSQL_ADMIN=${CERVESUR_MYSQL_ADMIN:-mysql}
# Portal Flutter existente: SOLO se lee (huella de verificación); jamás se escribe.
FLUTTER_DIR=/var/www/cervesur
FLUTTER_ARCHIVOS=(/etc/apache2/sites-enabled/cervesur.conf /var/www/cervesur/.htaccess)

ok() { printf 'OK     %s\n' "$*"; }
aviso() { printf 'AVISO  %s\n' "$*"; }
fallo() { printf 'FALLO  %s\n' "$*" >&2; }
abortar() { fallo "$*"; exit 1; }
requerir_root() { [ "$(id -u)" -eq 0 ] || abortar "Ejecute este script con sudo."; }
con_systemd() { [ -d /run/systemd/system ]; }

# Módulo de Apache cargado (patrón de grep -E sobre «apache2ctl -M»). La salida se captura antes de filtrar: con «pipefail»,
# un «grep -q» en tubería cierra antes de tiempo y puede dar un falso negativo por SIGPIPE.
modulo_apache() { local salida; salida=$(apache2ctl -M 2>/dev/null) || true; grep -Eq " $1_module " <<<"$salida"; }

# Contraseña del usuario MySQL de prueba. MySQL 8 puede tener validate_password en política MEDIUM (como el servidor de Cervesur: longitud >= 8,
# mayúscula, minúscula, número y carácter especial; ERROR 1819 si no se cumple). El prefijo «Aa1!» garantiza las cuatro clases y openssl aporta
# 192 bits de azar (48 hex). Solo usa caracteres seguros en SQL (comillas simples), en el .env (sin «=» ni «#») y en la cadena DSN de PDO.
generar_clave_mysql() { printf 'Aa1!%s' "$(openssl rand -hex 24)"; }

# Comprueba una contraseña contra la política MEDIUM (se lee de stdin para no exponerla en la línea de comandos). Código 0 si cumple.
clave_cumple_politica_medium() {
  local c; IFS= read -r c
  [ "${#c}" -ge 8 ] && [[ $c =~ [A-Z] ]] && [[ $c =~ [a-z] ]] && [[ $c =~ [0-9] ]] && [[ $c =~ [^A-Za-z0-9] ]] && [[ $c =~ ^[A-Za-z0-9!]+$ ]]
}

# Recarga SUAVE (graceful) de Apache, siempre precedida de configtest. Nunca reinicia el servicio.
apache_recargar() {
  apache2ctl configtest >/dev/null 2>&1 || abortar "apache2ctl configtest falló: no se recarga Apache."
  if con_systemd; then systemctl reload apache2; else apache2ctl graceful; fi
}

mysql_activo() {
  if con_systemd; then systemctl is-active --quiet mysql; else mysqladmin ping --silent >/dev/null 2>&1; fi
}

# Huella del portal Flutter: hash de su configuración, de la lista de archivos (nombre, tamaño, fecha) y NOMBRES de las cabeceras
# HTTP de su página principal. Se calcula antes y después para demostrar que nada cambió. No lee el contenido de ningún .env.
huella_flutter() {
  local f
  for f in "${FLUTTER_ARCHIVOS[@]}"; do
    if [ -f "$f" ]; then sha256sum "$f"; else echo "ausente $f"; fi
  done
  if [ -d "$FLUTTER_DIR" ]; then
    find "$FLUTTER_DIR" -type f -printf '%P %s %T@\n' 2>/dev/null | LC_ALL=C sort | sha256sum | sed 's# *-$#  arbol:/var/www/cervesur#'
  fi
  { curl -sS -m 5 -o /dev/null -D - http://127.0.0.1:80/ 2>/dev/null || echo 'sin-respuesta'; } | tr -d '\r' |
    awk 'NR==1 { print; next } /^[A-Za-z-]+:/ { print "cabecera: " tolower(substr($0, 1, index($0, ":") - 1)) }' | LC_ALL=C sort
}
