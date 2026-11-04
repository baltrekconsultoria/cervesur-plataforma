# shellcheck shell=bash
# shellcheck disable=SC2034  # las constantes las usan los scripts que cargan este archivo
# Constantes y utilidades del PILOTO ADMINISTRATIVO DE SOLO LECTURA (UX-07D.4B.1), accesible solo por túnel SSH y SOLO por HTTPS (127.0.0.1:8093).
# Se carga con «source». No contiene contraseñas ni valores de .env. Todos los recursos son EXCLUSIVOS de este entorno.
# Este instalador NUNCA crea, modifica ni elimina bases, usuarios ni privilegios de MySQL: las tres cuentas y la base de estado se crean aparte (admin-mysql/).

SITIO=cervesur-react-admin
PUERTO=8093
DIR_FRONT=/var/www/cervesur-react-admin
DIR_PUBLIC=/var/www/cervesur-react-admin/public
DIR_BACK=/opt/cervesur-react-admin
DIR_BACKEND=/opt/cervesur-react-admin/backend
DIR_CONF=/etc/apache2/cervesur-react-admin
DIR_TLS=/etc/apache2/cervesur-react-admin/tls          # certificado y clave privada del sitio (la clave: root:root 600; jamás en el paquete)
CERT_FILE=/etc/apache2/cervesur-react-admin/tls/servidor.crt
KEY_FILE=/etc/apache2/cervesur-react-admin/tls/servidor.key
SITE_CONF=/etc/apache2/sites-available/cervesur-react-admin.conf
SITE_ENABLED=/etc/apache2/sites-enabled/cervesur-react-admin.conf
DIR_VAR=/var/lib/cervesur-react-admin
DIR_SESIONES=/var/lib/cervesur-react-admin/sessions    # el modo no abre sesiones PHP (token opaco en la base de estado); la carpeta aísla open_basedir
DIR_RESPALDO=/var/backups/cervesur-react-admin
ENV_FILE=/opt/cervesur-react-admin/backend/.env
COOKIE_SESION=CERVESUR_REACT_ADMIN
DB_NOMBRE=cervesur_portalbi            # base de LECTURA (productiva): solo se comprueba que existe
DB_ESTADO=cervesur_react_estado_admin        # base de ESTADO propia del piloto (sesiones, lista blanca, intentos, auditoría)
DB_USUARIO_CATALOGO=cervesur_ro_catalogo_adm
DB_USUARIO_AUTH=cervesur_ro_auth_adm
DB_USUARIO_ESTADO=cervesur_react_estado_adm
MYSQL_ADMIN=${CERVESUR_MYSQL_ADMIN:-mysql}   # solo para LEER metadatos (existencia de bases y cuentas); nunca se escribe con él

# --- Recursos de los OTROS portales: solo se LEEN (huella) o se comparan para garantizar que no hay colisión. Jamás se escriben. ---
FLUTTER_DIR=/var/www/cervesur
FLUTTER_ARCHIVOS=(/etc/apache2/sites-enabled/cervesur.conf /var/www/cervesur/.htaccess)
PUERTO_FLUTTER=80
PUERTO_PRUEBA=8088
PUERTO_COMPAT=8089
PUERTO_DIAG=8090
PUERTO_VISUAL=8091
SITIO_PRUEBA=cervesur-react-prueba
SITIO_COMPAT=cervesur-react-compat
SITIO_DIAG=cervesur-react-ro
SITIO_VISUAL=cervesur-react-piloto
SITE_CONF_PRUEBA=/etc/apache2/sites-available/cervesur-react-prueba.conf
SITE_ENABLED_PRUEBA=/etc/apache2/sites-enabled/cervesur-react-prueba.conf
SITE_CONF_COMPAT=/etc/apache2/sites-available/cervesur-react-compat.conf
SITE_ENABLED_COMPAT=/etc/apache2/sites-enabled/cervesur-react-compat.conf
SITE_CONF_DIAG=/etc/apache2/sites-available/cervesur-react-ro.conf
SITE_ENABLED_DIAG=/etc/apache2/sites-enabled/cervesur-react-ro.conf
SITE_CONF_VISUAL=/etc/apache2/sites-available/cervesur-react-piloto.conf
SITE_ENABLED_VISUAL=/etc/apache2/sites-enabled/cervesur-react-piloto.conf
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
DIR_FRONT_VISUAL=/var/www/cervesur-react-piloto
DIR_BACK_VISUAL=/opt/cervesur-react-piloto
DIR_CONF_VISUAL=/etc/apache2/cervesur-react-piloto
DIR_VAR_VISUAL=/var/lib/cervesur-react-piloto
DIR_RESPALDO_VISUAL=/var/backups/cervesur-react-piloto
DB_PRUEBA=cervesur_react_prueba
DB_COMPAT=cervesur_react_compat_qa
DB_USUARIO_PRUEBA=cervesur_react
DB_USUARIO_COMPAT=cervesur_react_compat
DB_USUARIO_DIAG=cervesur_ro_diag
DB_USUARIO_VISUAL=cervesur_ro_visor
COOKIE_SESION_PRUEBA=CERVESUR_REACT
COOKIE_SESION_COMPAT=CERVESUR_REACT_COMPAT
COOKIE_SESION_DIAG=CERVESUR_REACT_RO
COOKIE_SESION_VISUAL=CERVESUR_REACT_PILOTO

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
  for p in "$PUERTO_FLUTTER" "$PUERTO_PRUEBA" "$PUERTO_COMPAT" "$PUERTO_DIAG" "$PUERTO_VISUAL"; do [ "$PUERTO" != "$p" ] || abortar "Colisión de puertos: $PUERTO coincide con otro portal."; done
  for p in "$SITIO_PRUEBA" "$SITIO_COMPAT" "$SITIO_DIAG" "$SITIO_VISUAL"; do [ "$SITIO" != "$p" ] || abortar "Colisión de nombre de sitio Apache con otro portal."; done
  for p in "$COOKIE_SESION_PRUEBA" "$COOKIE_SESION_COMPAT" "$COOKIE_SESION_DIAG" "$COOKIE_SESION_VISUAL"; do [ "$COOKIE_SESION" != "$p" ] || abortar "Colisión del nombre de cookie de sesión con otro portal."; done
  for p in "$DB_USUARIO_PRUEBA" "$DB_USUARIO_COMPAT" "$DB_USUARIO_DIAG" "$DB_USUARIO_VISUAL"; do
    for propio in "$DB_USUARIO_CATALOGO" "$DB_USUARIO_AUTH" "$DB_USUARIO_ESTADO"; do [ "$propio" != "$p" ] || abortar "Colisión de usuario MySQL con otro portal (las cuentas del piloto deben ser propias)."; done
  done
  [ "$DB_NOMBRE" != "$DB_PRUEBA" ] && [ "$DB_NOMBRE" != "$DB_COMPAT" ] || abortar "La base de lectura coincide con la base de otro portal React."
  [ "$DB_ESTADO" != "$DB_NOMBRE" ] && [ "$DB_ESTADO" != "$DB_PRUEBA" ] && [ "$DB_ESTADO" != "$DB_COMPAT" ] || abortar "La base de estado debe ser propia y distinta de las bases de los demás portales."
  for propio in "$DIR_FRONT" "$DIR_BACK" "$DIR_CONF" "$DIR_VAR" "$DIR_RESPALDO" "$SITE_CONF" "$SITE_ENABLED"; do
    for otro in "$FLUTTER_DIR" "$DIR_FRONT_PRUEBA" "$DIR_BACK_PRUEBA" "$DIR_CONF_PRUEBA" "$DIR_RESPALDO_PRUEBA" "$SITE_CONF_PRUEBA" "$SITE_ENABLED_PRUEBA" \
      "$DIR_FRONT_COMPAT" "$DIR_BACK_COMPAT" "$DIR_CONF_COMPAT" "$DIR_VAR_COMPAT" "$DIR_RESPALDO_COMPAT" "$SITE_CONF_COMPAT" "$SITE_ENABLED_COMPAT" \
      "$DIR_BACK_DIAG" "$DIR_CONF_DIAG" "$DIR_VAR_DIAG" "$DIR_RESPALDO_DIAG" "$SITE_CONF_DIAG" "$SITE_ENABLED_DIAG" \
      "$DIR_FRONT_VISUAL" "$DIR_BACK_VISUAL" "$DIR_CONF_VISUAL" "$DIR_VAR_VISUAL" "$DIR_RESPALDO_VISUAL" "$SITE_CONF_VISUAL" "$SITE_ENABLED_VISUAL" "/etc/apache2/sites-enabled/cervesur.conf"; do
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
huella_visual() {
  if [ ! -e "$SITE_ENABLED_VISUAL" ]; then echo "piloto-visual-8091: no instalado"; return 0; fi
  sha256sum "$SITE_CONF_VISUAL" "$DIR_CONF_VISUAL/react-piloto-common.conf" 2>/dev/null
  arbol_digest "$DIR_FRONT_VISUAL"; arbol_digest "$DIR_BACK_VISUAL"
  cabeceras_http "http://127.0.0.1:$PUERTO_VISUAL/api/ping"
}
huella_servidor() {
  echo "puertos:"; ss -ltnH 2>/dev/null | awk '{print $4}' | grep -v ":$PUERTO\$" | LC_ALL=C sort -u
  echo "modulos-apache:"; apache2ctl -M 2>/dev/null | LC_ALL=C sort | sha256sum | cut -c1-64
  echo "sitios-habilitados:"; local sitio; for sitio in /etc/apache2/sites-enabled/*; do [ "${sitio##*/}" = "cervesur-react-admin.conf" ] || echo "${sitio##*/}"; done | LC_ALL=C sort
  echo "bases (metadatos):"; ($MYSQL_ADMIN -N -e 'SHOW DATABASES' 2>/dev/null || echo 'mysql-no-disponible') | LC_ALL=C sort
  echo "usuarios mysql (metadatos):"; ($MYSQL_ADMIN -N -e 'SELECT user, host FROM mysql.user' 2>/dev/null || echo 'mysql-no-disponible') | LC_ALL=C sort
}
huella_usuarios_8092() { # piloto de usuarios (solo se LEE su huella; no se toca)
  curl -sk -m 8 -o /dev/null -D - https://127.0.0.1:8092/api/ping 2>/dev/null | tr -d '\r' | awk 'NR==1{print} NR>1{split($0,a,":"); print tolower(a[1])}' | LC_ALL=C sort
  for d in /var/www/cervesur-react-usuarios/public /opt/cervesur-react-usuarios/backend; do [ -d "$d" ] && arbol_digest "$d" || echo "ausente:$d"; done
}
huella_portales() {
  echo "== flutter"; huella_flutter
  echo "== react-8088"; huella_react_prueba
  echo "== react-8089"; huella_react_compat
  echo "== diagnostico-8090"; huella_diag
  echo "== piloto-visual-8091"; huella_visual
  echo "== piloto-usuarios-8092"; huella_usuarios_8092
  echo "== servidor"; huella_servidor
}

# --- Secretos del piloto (se generan al instalar; nunca se muestran ni van en argumentos) ---
generar_secreto() { openssl rand -hex 32; }
secreto_valido() { local t; IFS= read -r t; [[ $t =~ ^[0-9a-f]{64}$ ]]; }
# Lee un valor del .env de este entorno SIN mostrarlo (se usa en variables locales; nunca en argumentos de comandos).
valor_env() { [ -r "$ENV_FILE" ] && sed -n "s/^$1=//p" "$ENV_FILE" | head -1; }
# Lee KEY=VALOR de un archivo de claves (formato del generador de SQL). El valor llega por la salida estándar de la función, nunca por argumentos.
valor_de_archivo() { sed -n "s/^$2=//p" "$1" | head -1; }
# Sustituye UNA variable del .env de forma atómica (archivo temporal en la misma carpeta + mv), conservando modo 640 y propietario root:www-data.
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

# --- TLS: el certificado debe ser válido ANTES de aceptar una sola contraseña ---
# ¿La clave privada corresponde al certificado? (se comparan las claves públicas; la privada nunca se imprime)
tls_clave_corresponde() { # crt key
  [ "$(openssl x509 -in "$1" -noout -pubkey 2>/dev/null | sha256sum)" = "$(openssl pkey -in "$2" -pubout 2>/dev/null | sha256sum)" ]
}
# ¿El certificado cubre el nombre de acceso ($3, por defecto localhost) y no caduca en menos de $2 días?
tls_certificado_vigente() { # crt dias [nombre]
  openssl x509 -in "$1" -noout -checkend $(( $2 * 86400 )) >/dev/null 2>&1 && openssl x509 -in "$1" -noout -checkhost "${3:-localhost}" 2>/dev/null | grep -q 'does match'
}
# ¿La clave privada es de root y no legible por otros?
tls_clave_protegida() { local p; p=$(stat -c '%a %U' "$1"); [ "$p" = "600 root" ] || [ "$p" = "400 root" ]; }
# ¿Se ofrece SOLO TLS 1.2/1.3? (sondeo local: TLS 1.0/1.1 deben fallar; 1.2 y 1.3 deben funcionar)
tls_solo_moderno() { # puerto
  openssl s_client -connect "127.0.0.1:$1" -tls1_3 </dev/null >/dev/null 2>&1 \
    && openssl s_client -connect "127.0.0.1:$1" -tls1_2 </dev/null >/dev/null 2>&1 \
    && ! openssl s_client -connect "127.0.0.1:$1" -tls1_1 </dev/null >/dev/null 2>&1 \
    && ! openssl s_client -connect "127.0.0.1:$1" -tls1 </dev/null >/dev/null 2>&1
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

# =====================================================================================================================================
# UX-08H.1 · FASE 2A · utilidades añadidas a la copia del lib.sh del paquete de la fase 1 (3f859210c5c4). Todo lo anterior es idéntico al original.
# Reglas: ningún valor del .env se imprime nunca; las contraseñas no viajan en argumentos (variables de entorno, archivos 600 o stdin);
# toda edición del .env es atómica (archivo temporal en la misma carpeta + mv) y va precedida de un respaldo 600 que jamás se borra.
# =====================================================================================================================================
BASE_FASE1_COMMIT=3f859210c5c4
DIR_ENV_RESPALDO="$DIR_RESPALDO/env"                   # respaldos del .env (contienen secretos: 700/600; verificar-fase1.sh no los recorre)
ARCHIVO_ESTADO="$DIR_VAR/escritura-estado.txt"         # Paso B: qué módulos están verificados (con la huella de su evidencia)
MODULOS_ORDEN=(empresas reportes accesos)              # orden OBLIGATORIO de apertura; «usuarios» (fase 2B) no existe aquí
CLAVES_USUARIOS=(ADMIN_WRITES_USUARIOS ADMIN_WRITES_USUARIOS_SENSIBLE)
URL_ADMIN="https://localhost:$PUERTO"
CURL_TLS=(--cacert "$CERT_FILE" --resolve "localhost:$PUERTO:127.0.0.1")
# Cuenta de escritura de la fase 2A (nombre y host del procedimiento de migraciones; se pueden cambiar con --cuenta / --host-cuenta)
CUENTA_ESCRITURA_DEFECTO=cervesur_escritura_adm
HOST_CUENTA_DEFECTO=127.0.0.1

sello_utc() { date -u +%Y%m%d-%H%M%SZ; }
# Inyección de fallos SOLO PARA ENSAYOS (CERVESUR_INYECTAR_FALLO=respaldo|manifiesto|frontend|backend|verificacion|env-invalido|cuenta-sobreprivilegiada).
# Solo puede hacer que un paso FALLE; jamás omite ni relaja una comprobación.
inyectar() { [ "${CERVESUR_INYECTAR_FALLO:-}" = "$1" ]; }
# ¿La clave existe en el .env (con cualquier valor)? Nunca imprime valores.
env_presente() { [ -r "$ENV_FILE" ] && tr -d '\r' < "$ENV_FILE" | grep -qE "^[[:space:]]*$1[[:space:]]*="; }
env_valor_de() { # archivo clave
  [ -r "$1" ] && tr -d '\r' < "$1" | awk -F= -v k="$2" '$1 == k { sub(/^[^=]*=/, ""); v = $0 } END { print v }'
}
env_valor_actual() { env_valor_de "$ENV_FILE" "$1"; }
# Huella (SHA-256, 16 hex) de un valor del .env: sirve para comparar sin imprimir el valor.
env_huella_valor() { local v; v=$(env_valor_actual "$1"); printf '%s' "$v" | sha256sum | cut -c1-16; }
# Claves que la fase 2A prohíbe en el .env (la fase 2B sigue bloqueada). Imprime sus NOMBRES, nunca sus valores.
env_claves_prohibidas() {
  local k
  for k in "${CLAVES_USUARIOS[@]}" USER_ADMIN_WRITES CERVESUR_INSECURE_TESTS CERVESUR_ALLOW_WRITABLE_DB_ACCOUNT; do
    if env_presente "$k"; then printf '%s\n' "$k"; fi
  done
  return 0
}
modo_env() { env_valor_actual CERVESUR_MODE; }

# Respaldo del .env: <DIR_RESPALDO>/env/env-<etiqueta>-<sello> (700/600, jamás se borra). Deja la ruta en ENV_RESPALDO_RUTA y anota su huella (no su contenido) en el manifiesto.
ENV_RESPALDO_RUTA=""
env_respaldar() { # etiqueta
  local destino
  install -d -m 700 "$DIR_RESPALDO" "$DIR_ENV_RESPALDO" || return 1
  destino="$DIR_ENV_RESPALDO/env-$1-$(sello_utc)"
  [ ! -e "$destino" ] || destino="$destino-$$"
  ( umask 077; cp -p "$ENV_FILE" "$destino" ) || return 1
  chown root:root "$destino" && chmod 600 "$destino" || return 1
  [ "$(sha256sum < "$ENV_FILE")" = "$(sha256sum < "$destino")" ] || return 1
  printf '%s\t%s\tmodo=%s\n' "$(date -u +%FT%TZ)" "$(sha256sum "$destino" | cut -c1-64)  $(basename "$destino")" "$(env_valor_de "$destino" CERVESUR_MODE)" >> "$DIR_ENV_RESPALDO/MANIFIESTO.txt" || return 1
  chmod 600 "$DIR_ENV_RESPALDO/MANIFIESTO.txt"
  ENV_RESPALDO_RUTA=$destino
}
# Instala un archivo como .env de forma atómica (root:www-data 640).
env_instalar() { # origen
  local tmp
  tmp=$(mktemp "$DIR_BACKEND/.env.nuevo.XXXXXX") || return 1
  if ! { cat "$1" > "$tmp" && chown root:www-data "$tmp" && chmod 640 "$tmp" && mv -f "$tmp" "$ENV_FILE"; }; then rm -f "$tmp"; return 1; fi
}
# Aplica cambios al .env de forma atómica. PARES = líneas «CLAVE=valor» (sustituyen o se añaden al final, en ese orden); BORRAR = claves separadas por espacios (se eliminan).
# Los valores viajan por el entorno de awk (no por argumentos). El resto del archivo (comentarios incluidos) queda tal cual.
env_aplicar() { # pares borrar
  local tmp
  tmp=$(mktemp "$DIR_BACKEND/.env.nuevo.XXXXXX") || return 1
  PARES="$1" BORRAR="${2:-}" awk '
    BEGIN { n = split(ENVIRON["PARES"], p, "\n"); for (i = 1; i <= n; i++) if (p[i] != "") { pos = index(p[i], "="); k = substr(p[i], 1, pos - 1); mapa[k] = substr(p[i], pos + 1); orden[++m] = k }
            b = split(ENVIRON["BORRAR"], q, " "); for (i = 1; i <= b; i++) quitar[q[i]] = 1 }
    { linea = $0; sub(/\r$/, "", linea); pos = index(linea, "="); nombre = ""
      if (pos) { nombre = substr(linea, 1, pos - 1); gsub(/^[ \t]+|[ \t]+$/, "", nombre) }
      if (pos && substr(nombre, 1, 1) != "#" && ((nombre in mapa) || (nombre in quitar))) next
      print linea }
    END { for (i = 1; i <= m; i++) print orden[i] "=" mapa[orden[i]] }' "$ENV_FILE" > "$tmp" || { rm -f "$tmp"; return 1; }
  if ! { chown root:www-data "$tmp" && chmod 640 "$tmp" && mv -f "$tmp" "$ENV_FILE"; }; then rm -f "$tmp"; return 1; fi
}

espera_opcache() { sleep "${CERVESUR_ESPERA_OPCACHE:-4}"; }   # opcache (mod_php) revalida cada ~2 s: hay que esperar antes de verificar cualquier cambio de código

# ---- API local del administrador (HTTPS validado contra el certificado propio del sitio). Credenciales SOLO por entorno: CERVESUR_E_USUARIO / CERVESUR_E_CLAVE.
TOKEN=""; API_STATUS=""; API_CUERPO=""
API_TMP=""
api_iniciar() {
  [ -z "$API_TMP" ] || return 0
  API_TMP=$(mktemp -d) || return 1
}
api_limpiar() { [ -z "$API_TMP" ] || rm -rf "$API_TMP"; }   # cada script la incluye en su trap EXIT
credenciales_presentes() { [ -n "${CERVESUR_E_USUARIO:-}" ] && [ -n "${CERVESUR_E_CLAVE:-}" ]; }
json_escapar() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'; }
api_login() {
  api_iniciar || return 1
  TOKEN=""
  credenciales_presentes || return 1
  printf '{"login":"%s","password":"%s"}' "$(json_escapar "$CERVESUR_E_USUARIO")" "$(json_escapar "$CERVESUR_E_CLAVE")" \
    | curl -sS -m 20 "${CURL_TLS[@]}" -H 'Content-Type: application/json' --data @- "$URL_ADMIN/api/login" -o "$API_TMP/login.json" 2>/dev/null || return 1
  TOKEN=$(sed -n 's/.*"token":"\([0-9a-f]\{64\}\)".*/\1/p' "$API_TMP/login.json" | head -1)
  [ -n "$TOKEN" ]
}
# api_pedir METODO RUTA [CUERPO-JSON] → API_STATUS y API_CUERPO. El token va por -K (no aparece en la lista de procesos).
api_pedir() {
  api_iniciar || return 1
  local metodo=$1 ruta=$2 cuerpo=${3:-}
  local args=(-sS -m 25 "${CURL_TLS[@]}" -X "$metodo" -o "$API_TMP/cuerpo.out" -w '%{http_code}')
  [ "$metodo" = GET ] || args+=(-H 'X-API-Contract: 2')
  [ -z "$cuerpo" ] || args+=(-H 'Content-Type: application/json' --data-binary "$cuerpo")
  if [ -n "$TOKEN" ]; then
    API_STATUS=$(curl "${args[@]}" -K <(printf 'header = "Authorization: Bearer %s"\n' "$TOKEN") "$URL_ADMIN$ruta" 2>/dev/null) || API_STATUS=000
  else
    API_STATUS=$(curl "${args[@]}" "$URL_ADMIN$ruta" 2>/dev/null) || API_STATUS=000
  fi
  API_CUERPO=$(cat "$API_TMP/cuerpo.out" 2>/dev/null)
  return 0
}
# ¿La última respuesta es un 403 EMITIDO POR EL BACKEND (JSON con ese «code»)? Un 403 sin «code» es de Apache, no del backend.
api_es_403() { [ "$API_STATUS" = 403 ] && grep -q "\"code\" *: *\"$1\"" <<<"$API_CUERPO"; }
api_es_403_apache() { [ "$API_STATUS" = 403 ] && ! grep -q '"code"' <<<"$API_CUERPO"; }
# Capacidades efectivas (GET /api/admin/capacidades): CAP_<modulo>=true|false y CAP_SENSIBLE. Devuelve 1 si la respuesta no es la esperada.
CAP_empresas=""; CAP_reportes=""; CAP_accesos=""; CAP_usuarios=""; CAP_SENSIBLE=""
capacidades_leer() {
  api_pedir GET /api/admin/capacidades
  [ "$API_STATUS" = 200 ] || return 1
  local writes m v
  writes=$(sed -n 's/.*"writes":{\([^}]*\)}.*/\1/p' <<<"$API_CUERPO")
  [ -n "$writes" ] || return 1
  for m in empresas reportes accesos usuarios; do
    v=$(sed -n "s/.*\"$m\":\(true\|false\).*/\1/p" <<<"$writes"); [ -n "$v" ] || return 1
    printf -v "CAP_$m" '%s' "$v"
  done
  CAP_SENSIBLE=$(sed -n 's/.*"users_sensitive":\(true\|false\).*/\1/p' <<<"$API_CUERPO"); [ -n "$CAP_SENSIBLE" ]
}
# ¿Las capacidades son EXACTAMENTE las esperadas? (lista de módulos encendidos; usuarios y lo sensible siempre apagados)
capacidades_son() { # modulo...
  local m esperado actual v
  capacidades_leer || return 1
  for m in empresas reportes accesos; do
    esperado=false; case " $* " in *" $m "*) esperado=true ;; esac
    v="CAP_$m"; actual=${!v}
    [ "$actual" = "$esperado" ] || return 1
  done
  [ "$CAP_usuarios" = false ] && [ "$CAP_SENSIBLE" = false ]
}
# Sondas de escritura INOFENSIVAS: id 999999999 (inexistente) y cuerpo vacío «{}». Si una puerta fallara, el controlador rechazaría por validación o «no existe»: no pueden escribir.
RUTAS_ESCRITURA_EMPRESAS=("POST /api/empresas" "PUT /api/empresas/999999999" "DELETE /api/empresas/999999999" "POST /api/empresas/999999999/reactivate")
RUTAS_ESCRITURA_REPORTES=("POST /api/reportes" "PUT /api/reportes/999999999" "DELETE /api/reportes/999999999" "POST /api/reportes/999999999/reactivate")
RUTAS_ESCRITURA_ACCESOS=("POST /api/asignacion" "PUT /api/asignacion/999999999" "DELETE /api/asignacion/999999999" "POST /api/asignacion/999999999/reactivate" "POST /api/empresa-usuario" "DELETE /api/empresa-usuario/999999999" "POST /api/empresa-usuario/999999999/reactivate" "POST /api/empresa-reporte-usuario" "PUT /api/empresa-reporte-usuario/999999999" "DELETE /api/empresa-reporte-usuario/999999999" "POST /api/empresa-reporte-usuario/999999999/reactivate")
RUTAS_USUARIOS=("POST /api/register" "PUT /api/users/999999999" "POST /api/users/999999999/deactivate" "POST /api/users/999999999/reactivate" "PUT /api/users/999999999/password" "PUT /api/user/999999999/role")
rutas_de() { # modulo → rutas de sonda
  case $1 in
    empresas) printf '%s\n' "${RUTAS_ESCRITURA_EMPRESAS[@]}" ;;
    reportes) printf '%s\n' "${RUTAS_ESCRITURA_REPORTES[@]}" ;;
    accesos) printf '%s\n' "${RUTAS_ESCRITURA_ACCESOS[@]}" ;;
    usuarios) printf '%s\n' "${RUTAS_USUARIOS[@]}" ;;
  esac
}

# ---- Cuenta de escritura: la clave se lee SOLO de un archivo root-only (nunca argumentos ni pantalla)
archivo_root_privado() { # archivo regular, de root y sin acceso para grupo ni otros
  [ -f "$1" ] && [ ! -L "$1" ] && [ "$(stat -c %u "$1")" = 0 ] && [ "$(( 8#$(stat -c %a "$1") & 8#077 ))" = 0 ]
}
clave_de_archivo() { # archivo → clave por stdout (admite CERVESUR_ESCRITURA_PASS=…, CLAVE=…, DB_PASS=… o una única línea con la clave)
  local v
  v=$(tr -d '\r' < "$1" | sed -n -e 's/^\(CERVESUR_ESCRITURA_PASS\|CLAVE\|DB_PASS\|PASSWORD\)=//p' | head -1)
  if [ -z "$v" ] && [ "$(grep -c . "$1")" = 1 ] && ! grep -q '=' "$1"; then v=$(tr -d '\r\n' < "$1"); fi
  printf '%s' "$v"
}
# SQL como el DBA local (socket de MySQL, sin clave). Los scripts de esta carpeta solo lo usan para LEER.
dba() { $MYSQL_ADMIN -N -B "$@"; }
# ¿El archivo es un respaldo de .env creado por env_respaldar (dentro de DIR_ENV_RESPALDO, listado en MANIFIESTO.txt con SHA-256 que coincide)?
env_respaldo_valido() { # archivo
  local linea
  case $1 in "$DIR_ENV_RESPALDO"/env-*) ;; *) return 1 ;; esac
  [ -f "$1" ] && [ ! -L "$1" ] || return 1
  linea=$(grep -F "$(basename "$1")" "$DIR_ENV_RESPALDO/MANIFIESTO.txt" 2>/dev/null | head -1 | cut -f2)
  [ -n "$linea" ] && (cd "$DIR_ENV_RESPALDO" && printf '%s\n' "$linea" | sha256sum -c --quiet) >/dev/null 2>&1
}
