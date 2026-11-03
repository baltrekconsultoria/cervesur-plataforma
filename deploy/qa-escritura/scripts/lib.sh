# shellcheck shell=bash
# shellcheck disable=SC2034  # las constantes las usan los scripts que cargan este archivo
# Constantes y utilidades del entorno de ESCRITURA ADMINISTRATIVA de QA aislado (UX-08H), accesible solo por túnel SSH (127.0.0.1:<puerto elegido por el operador>).
# Se carga con «source». No contiene contraseñas ni valores de .env. Todos los recursos son EXCLUSIVOS de este entorno.

SITIO=cervesur-react-escritura-qa
DIR_FRONT=/var/www/cervesur-react-escritura-qa
DIR_BACK=/opt/cervesur-react-escritura-qa
DIR_CONF=/etc/apache2/cervesur-react-escritura-qa
SITE_CONF=/etc/apache2/sites-available/cervesur-react-escritura-qa.conf
SITE_ENABLED=/etc/apache2/sites-enabled/cervesur-react-escritura-qa.conf
DIR_VAR=/var/lib/cervesur-react-escritura-qa
DIR_SESIONES=/var/lib/cervesur-react-escritura-qa/sessions
DIR_RESPALDO=/var/backups/cervesur-react-escritura-qa   # NUNCA se borra: respaldos, línea base y registros de reversión
LOCK_GLOBAL=/run/lock/cervesur-react-escritura-qa.lock       # bloqueo de instalación/reversión (solo en memoria del kernel; el archivo vive en tmpfs)
PUERTO_REGISTRO=$DIR_VAR/puerto                      # puerto de ESTA instalación: lo escribe el instalador y lo leen verificar.sh y revertir.sh
COOKIE_SESION=CERVESUR_REACT_ESCRITURA_QA   # el modo admin_escritura no usa sesión PHP (token opaco); el nombre queda reservado y se vigila que no choque
# Nombre de la base: fijo por defecto. CERVESUR_QAE_DB_NOMBRE existe SOLO para el ensayo de las negativas (la base productiva se rechaza siempre).
DB_NOMBRE=${CERVESUR_QAE_DB_NOMBRE:-cervesur_qa_escritura}
DB_ESTADO=cervesur_qa_escritura_estado    # base de ESTADO propia (lista blanca, sesiones, intentos): distinta de la de negocio
DB_USUARIO=cervesur_qa_esc_w              # cuenta de ESCRITURA (DB_USER del .env)
DB_USUARIO_AUTH=cervesur_qa_esc_auth      # solo lectura, 9 columnas de user (AUTH_DB_USER)
DB_USUARIO_CAT=cervesur_qa_esc_cat        # solo lectura de catálogo (la crea 01-cuentas-lectura.sql junto con la anterior; este modo no la usa, se retira con el resto)
DB_USUARIO_ESTADO=cervesur_qa_esc_estado  # lectura/escritura SOLO en la base de estado (STATE_DB_USER)
USUARIOS_QA=("$DB_USUARIO" "$DB_USUARIO_AUTH" "$DB_USUARIO_CAT" "$DB_USUARIO_ESTADO")
DB_HOST_USUARIO=127.0.0.1
BASE_PRODUCTIVA=cervesur_portalbi
PLANTILLA_CUENTA=database/privileges/UX-08H-cuenta-escritura-produccion.sql.template
SQL_CUENTAS_LECTURA=database/schema/piloto-admin/01-cuentas-lectura.sql
SQL_ESTADO=database/schema/piloto-admin/02-estado-base-y-cuenta.sql
MARCA_NOMBRE=.marca-cervesur-react-escritura-qa
ESTADO_NOMBRE=estado-instalacion
TABLA_MARCA=qa_install_marker
# Informes autorizados al visor del administrador (CERVESUR_PILOT_VISOR_IDS). El id 31 es un informe SINTÉTICO que hace de DB031.
# El id real (72) solo se usa en el piloto real, tras aprobación expresa. Se puede cambiar con CERVESUR_QAE_VISOR_IDS.
VISOR_IDS=${CERVESUR_QAE_VISOR_IDS:-31}
INFORME_SINTETICO_ID=31
# Interruptores de escritura: ADMIN_WRITES_<X>=1 (valor «1» exacto = encendido). USUARIOS_SENSIBLE (rol y contraseña) exige además USUARIOS=1.
CAPACIDADES=(empresas reportes accesos usuarios)
ESCRITURAS_INICIALES=${CERVESUR_QAE_ESCRITURAS:-usuarios}   # lista separada por comas; por defecto solo «usuarios» (el resto apagado)
MYSQL_ADMIN=${CERVESUR_MYSQL_ADMIN:-mysql}

# --- Recursos de los OTROS portales: solo se LEEN (huella). Jamás se escriben. ---
PUERTO_FLUTTER=80
# Puertos que ya usan los portales del servidor real (Apache: 80, 443, 8443, 8088, 8089, 8090..8093; 8094 queda reservado al QA administrativo aislado) y servicios que jamás se comparten (ssh, MySQL).
# Un puerto de esta lista se rechaza SIEMPRE, escuche algo o no en este instante.
PUERTOS_AJENOS=(80 443 8443 8088 8089 8090 8091 8092 8093 8094)
PUERTOS_RESERVADOS=(22 3306 33060)
PUERTOS_HTTP_SONDA=(80 8088 8089)      # sondeo HTTP en claro (solo si escuchan)
PUERTOS_HTTPS_SONDA=(443 8443)         # sondeo HTTPS (curl -k, solo si escuchan)
COOKIES_AJENAS=(CERVESUR_REACT CERVESUR_REACT_COMPAT CERVESUR_REACT_ADMIN_QA)
DIRS_AJENOS=(/var/www/cervesur /var/www/cervesur-react-prueba /opt/cervesur-react-prueba /var/www/cervesur-react-compat /opt/cervesur-react-compat /var/www/cervesur-react-admin-qa /opt/cervesur-react-admin-qa /var/lib/cervesur-react-admin-qa /var/backups/cervesur-react-admin-qa)
ARBOLES_HUELLA=(/var/www /opt)

# --- Puerto del entorno: SIEMPRE explícito (--puerto N o CERVESUR_QAE_PUERTO), nunca elegido ni cambiado en silencio ---
# Si ya hay una instalación registrada, manda el puerto registrado y un valor distinto aborta (la reversión usa EXACTAMENTE el puerto instalado).
PUERTO=${CERVESUR_QAE_PUERTO:-}
if [ -r "$PUERTO_REGISTRO" ]; then
  _registrado=$(tr -d '[:space:]' < "$PUERTO_REGISTRO")
  if [ -n "$PUERTO" ] && [ "$PUERTO" != "$_registrado" ]; then
    printf 'FALLO  El puerto indicado (%s) no coincide con el de la instalación registrada (%s). No se hace nada.\n' "$PUERTO" "$_registrado" >&2
    exit 1
  fi
  PUERTO=$_registrado
fi
unset _registrado

ok() { printf 'OK     %s\n' "$*"; }
aviso() { printf 'AVISO  %s\n' "$*"; }
fallo() { printf 'FALLO  %s\n' "$*" >&2; }
abortar() { fallo "$*"; exit 1; }
requerir_root() { [ "$(id -u)" -eq 0 ] || abortar "Ejecute este script con sudo."; }
con_systemd() { [ -d /run/systemd/system ]; }

# Impide dos ejecuciones simultáneas sin crear ningún archivo (bloquea el propio script).
bloquear_ejecucion() { exec 9<"$1"; flock -n 9 || abortar "Otra ejecución de este paquete está en curso."; }

# Bloqueo global de instalación/reversión. El instalador lo hereda al revertir un intento fallido (CERVESUR_QAE_BLOQUEO_HEREDADO=1).
bloqueo_global() { [ "${CERVESUR_QAE_BLOQUEO_HEREDADO:-}" = 1 ] && return 0; exec 8>"$LOCK_GLOBAL" && flock -n 8; }

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
# shellcheck disable=SC2086  # MYSQL_ADMIN puede contener opciones
mysql_admin() { $MYSQL_ADMIN --default-character-set=utf8mb4 "$@"; }

# Puerto bien formado: entero decimal canónico de 1024 a 65535 (sin ceros a la izquierda, signos, espacios ni otros caracteres).
puerto_valido() { [[ ${1:-} =~ ^[1-9][0-9]{3,4}$ ]] && [ "$1" -le 65535 ] && [ "$1" -ge 1024 ]; }
puerto_conocido() { local p; for p in "${PUERTOS_AJENOS[@]}" "${PUERTOS_RESERVADOS[@]}"; do [ "$1" != "$p" ] || return 0; done; return 1; }
validar_puerto() { # aborta si falta, es de otro portal o servicio, o está mal formado; la comprobación de «libre» es de instalar.sh
  [ -n "$PUERTO" ] || abortar "Falta el puerto: indique --puerto N (1024-65535; candidato propuesto: 8095, pendiente de verificar en el servidor). No se elige ninguno por usted."
  if [[ $PUERTO =~ ^[0-9]+$ ]] && puerto_conocido "$((10#$PUERTO))"; then
    abortar "RECHAZADO: el puerto $PUERTO lo usa o reserva otro servicio del servidor (${PUERTOS_AJENOS[*]} · ${PUERTOS_RESERVADOS[*]}). Elija otro con --puerto."
  fi
  puerto_valido "$PUERTO" || abortar "Puerto no válido: «$PUERTO» (entero de 1024 a 65535, sin espacios ni ceros a la izquierda)."
}

# ¿$1 es igual a $2 o está contenido (como ruta) en $2, o a la inversa?
rutas_solapadas() { case "$1/" in "$2/"*) return 0 ;; esac; case "$2/" in "$1/"*) return 0 ;; esac; return 1; }

# --- Guardas de nombres: la base productiva y los demás portales jamás pueden coincidir con este entorno ---
nombre_base_valido() { # $1 = nombre; aborta si es la base productiva, la contiene o no es un identificador simple
  local n=$1
  [ "$n" != "$BASE_PRODUCTIVA" ] || abortar "RECHAZADO: la base «$n» es la base productiva."
  case "$n" in *portalbi*) abortar "RECHAZADO: el nombre de base «$n» contiene «portalbi» (base productiva)." ;; esac
  [[ $n =~ ^[a-z][a-z0-9_]{2,40}$ ]] || abortar "Nombre de base no válido: «$n» (solo minúsculas, números y guion bajo)."
}
verificar_no_colision() {
  local propio otro p
  nombre_base_valido "$DB_NOMBRE"; nombre_base_valido "$DB_ESTADO"
  [ "$DB_NOMBRE" != "$DB_ESTADO" ] || abortar "La base de estado debe ser distinta de la base de negocio."
  [ -z "$PUERTO" ] || validar_puerto
  for p in "${COOKIES_AJENAS[@]}"; do [ "$COOKIE_SESION" != "$p" ] || abortar "Colisión del nombre de cookie de sesión con otro portal ($p)."; done
  for propio in "$DIR_FRONT" "$DIR_BACK" "$DIR_CONF" "$DIR_VAR" "$DIR_RESPALDO" "$SITE_CONF" "$SITE_ENABLED"; do
    for otro in "${DIRS_AJENOS[@]}" /etc/apache2/sites-enabled/cervesur.conf /etc/apache2/sites-available/cervesur.conf; do
      ! rutas_solapadas "$propio" "$otro" || abortar "Colisión de rutas: $propio se solapa con $otro."
    done
  done
}

# --- Huellas: hash de lo que NO debe cambiar. Se calculan antes y después para demostrar que los demás portales no se tocaron. ---
# No leen el contenido de ningún .env ni datos de ninguna base: solo metadatos (nombre, tamaño, fecha, permisos) y los archivos de configuración de Apache.
arbol_digest() { # directorio (se excluye lo que pertenece a este entorno)
  [ -d "$1" ] || { echo "ausente $1"; return 0; }
  find "$1" \( -path "$DIR_FRONT" -o -path "$DIR_BACK" \) -prune -o -type f -printf '%P %s %T@ %m %u:%g\n' 2>/dev/null | LC_ALL=C sort | sha256sum | sed "s# *-\$#  arbol:$1#"
}
huella_apache() { # byte a byte: TODOS los archivos de /etc/apache2 salvo los de este entorno (Flutter 80, 443, 8443, 8088, 8089, ports.conf, módulos…)
  local f
  find /etc/apache2 \( -path "$DIR_CONF" -o -path "$DIR_CONF/*" -o -name "$SITIO.conf" \) -prune -o \( -type f -o -type l \) -print 2>/dev/null | LC_ALL=C sort |
    while IFS= read -r f; do if [ -L "$f" ]; then echo "enlace $f -> $(readlink "$f")"; else sha256sum "$f"; fi; done
}
cabeceras_http() { # url [opciones de curl]: línea de estado + NOMBRES de cabecera + hash del cuerpo
  local url=$1 crudo cuerpo; shift
  crudo=$(curl -sS -m 6 "$@" -o /dev/null -D - "$url" 2>/dev/null | tr -d '\r') || crudo='sin-respuesta'
  [ -n "$crudo" ] || crudo='sin-respuesta'
  printf '%s\n' "$crudo" | awk 'NR==1 { print; next } /^[A-Za-z-]+:/ { print "cabecera: " tolower(substr($0, 1, index($0, ":") - 1)) }' | LC_ALL=C sort
  cuerpo=$(curl -sS -m 6 "$@" "$url" 2>/dev/null | sha256sum | cut -c1-64) || cuerpo='sin-cuerpo'
  echo "cuerpo: $cuerpo"
}
escucha_puerto() { [ -n "$(ss -ltnH "sport = :$1" 2>/dev/null)" ]; }
huella_http_ajenos() {
  local p
  for p in "${PUERTOS_HTTP_SONDA[@]}"; do if escucha_puerto "$p"; then echo "http:$p"; cabeceras_http "http://127.0.0.1:$p/"; else echo "http:$p no-escucha"; fi; done
  for p in "${PUERTOS_HTTPS_SONDA[@]}"; do if escucha_puerto "$p"; then echo "https:$p"; cabeceras_http "https://127.0.0.1:$p/" -k; else echo "https:$p no-escucha"; fi; done
}
huella_mysql() { # metadatos: bases, cuentas y sus privilegios (de todas las cuentas salvo las cuatro de este entorno)
  echo "bases:"; (mysql_admin -N -e 'SHOW DATABASES' 2>/dev/null || echo 'mysql-no-disponible') | grep -vxF -e "$DB_NOMBRE" -e "$DB_ESTADO" | LC_ALL=C sort
  echo "cuentas:"
  local cuentas c
  cuentas=$(mysql_admin -N -e "SELECT CONCAT(QUOTE(user), '@', QUOTE(host)) FROM mysql.user WHERE user NOT IN ('$DB_USUARIO', '$DB_USUARIO_AUTH', '$DB_USUARIO_CAT', '$DB_USUARIO_ESTADO') ORDER BY user, host" 2>/dev/null) || cuentas=''
  printf '%s\n' "$cuentas"
  echo "privilegios:"
  while IFS= read -r c; do
    [ -n "$c" ] || continue
    echo "## $c"; mysql_admin -N -e "SHOW GRANTS FOR $c" 2>/dev/null | LC_ALL=C sort
  done <<<"$cuentas"
}
huella_servidor() {
  local a
  local -; set +e   # una consulta sin resultados (grep -v, ausencia de un portal) no es un error: la huella solo describe
  echo "puertos:"; ss -ltnH 2>/dev/null | awk '{print $4}' | grep -v ":$PUERTO\$" | LC_ALL=C sort -u
  echo "modulos-apache:"; apache2ctl -M 2>/dev/null | LC_ALL=C sort | sha256sum | cut -c1-64
  echo "apache-/etc/apache2 (byte a byte, sin lo de este entorno):"; huella_apache
  echo "respuestas HTTP de los demás portales:"; huella_http_ajenos
  echo "metadatos de arboles:"; for a in "${ARBOLES_HUELLA[@]}"; do arbol_digest "$a"; done
  echo "carpetas de primer nivel:"
  for a in /var/www /opt /var/lib /etc/apache2 /var/backups; do
    echo "[$a]"; find "$a" -mindepth 1 -maxdepth 1 -printf '%f\n' 2>/dev/null | grep -vFx -e "$SITIO" -e "${DIR_RESPALDO##*/}" -e "$SITIO.conf" | LC_ALL=C sort
  done
  echo "mysql:"; huella_mysql
  return 0
}

# --- Contraseña del usuario MySQL (política validate_password MEDIUM: >= 8, mayúscula, minúscula, número y carácter especial) ---
# «Aa1!» garantiza las cuatro clases y openssl aporta 192 bits de azar. Solo caracteres seguros en SQL, en el .env y en la cadena DSN de PDO.
generar_clave_mysql() { printf 'Aa1!%s' "$(openssl rand -hex 24)"; }
clave_cumple_politica_medium() {
  local c; IFS= read -r c
  [ "${#c}" -ge 8 ] && [[ $c =~ [A-Z] ]] && [[ $c =~ [a-z] ]] && [[ $c =~ [0-9] ]] && [[ $c =~ [^A-Za-z0-9] ]] && [[ $c =~ ^[A-Za-z0-9!]+$ ]]
}

# --- Marcas de instalación: lo que NO tiene la marca de ESTA instalación jamás se borra ---
marca_token() { [ -f "$DIR_VAR/$MARCA_NOMBRE" ] && cut -d: -f2 "$DIR_VAR/$MARCA_NOMBRE"; }
directorio_marcado() { # $1 = directorio, $2 = token
  [ -f "$1/$MARCA_NOMBRE" ] && [ "$(cut -d: -f2 "$1/$MARCA_NOMBRE")" = "$2" ]
}

# --- .env de la instancia (solo lectura de claves NO secretas; ningún script imprime secretos) ---
ENV_FILE=$DIR_BACK/backend/.env
valor_env() { [ -r "$ENV_FILE" ] && grep -E "^$1=" "$ENV_FILE" | tail -1 | cut -d= -f2-; }   # regla de loadEnv(): la última definición gana
huella_portales() { huella_servidor; }
# Lista «a,b» de capacidades → ¿es válida? (solo empresas, reportes, accesos y usuarios; sin repetidos ni vacíos)
lista_escrituras_valida() {
  local x vistos=" "
  [ -n "$1" ] || return 1
  IFS=, read -ra _l <<<"$1"
  for x in "${_l[@]}"; do
    case "$x" in empresas|reportes|accesos|usuarios) ;; *) return 1 ;; esac
    case "$vistos" in *" $x "*) return 1 ;; esac
    vistos="$vistos$x "
  done
}
