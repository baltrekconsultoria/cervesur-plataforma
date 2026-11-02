#!/usr/bin/env bash
# Cervesur · UX-08 · Auditoría de SOLO LECTURA del servidor (Flutter + backends React).
#
# QUÉ HACE: lee y muestra información. NO modifica archivos, permisos, servicios ni datos; no reinicia nada; no ejecuta SQL de escritura.
# QUÉ NO IMPRIME: valores de `.env` (solo los nombres de las claves), contraseñas, claves JWT, tokens ni hashes de contraseñas.
# CÓMO USARLO:   bash auditoria-solo-lectura.sh > auditoria.txt 2>&1     (luego REVISE auditoria.txt antes de compartirlo)
# PRIVILEGIOS:   algunas lecturas necesitan sudo (Apache, .env, MySQL por socket). Si falta un permiso, el paso lo indica y continúa;
#                no intente eludirlo.
set -u

BACKENDS="${BACKENDS:-/var/www/cervesur/backend /opt/cervesur-react-usuarios/backend /opt/cervesur-react-admin/backend}"
ORIGINAL="${ORIGINAL:-/var/www/cervesur/backend}"

section() { printf '\n==================== %s ====================\n' "$1"; }
try() { "$@" 2>&1 || echo "(sin permiso o no disponible: $*)"; }

section "0. Fecha y usuario (sin datos personales)"
date -u '+%Y-%m-%dT%H:%M:%SZ'; id -un

section "1. Apache: qué carpeta sirve cada puerto (valores de SetEnv ocultos)"
try sudo apachectl -S | head -40
try sudo grep -rn -E "ServerName|DocumentRoot|Listen|Alias|ProxyPass|<VirtualHost|SetEnv" /etc/apache2/sites-enabled/ /etc/apache2/ports.conf \
  | sed -E 's/(SetEnv +[A-Za-z_]+ +).*/\1<oculto>/'

section "2. Claves de configuración de cada backend (SOLO nombres; valores ocultos)"
for d in $BACKENDS; do
  echo "--- $d"
  if [ -f "$d/.env" ]; then
    try sudo sed -E 's/^([A-Za-z_][A-Za-z0-9_]*)=.*/\1=<oculto>/' "$d/.env" | grep -v '^#' | grep -v '^[[:space:]]*$'
  else
    echo "(no hay .env en $d)"
  fi
done

section "3. Huellas sha256 de los archivos PHP (contenido sin CR) para comparar con docs/UX-08-HUELLAS-BACKEND.txt"
for d in $BACKENDS; do
  echo "--- $d"
  if [ -d "$d" ]; then
    find "$d" -type f -name '*.php' -not -path '*/vendor/*' 2>/dev/null | sort | while read -r f; do
      printf '%s  %s\n' "$(tr -d '\r' < "$f" | sha256sum | cut -c1-64)" "backend/${f#"$d"/}"
    done
  else
    echo "(no existe $d)"
  fi
done

section "4. Backend ORIGINAL ($ORIGINAL): código de sesión (solo líneas coincidentes con número de línea)"
if [ -d "$ORIGINAL" ]; then
  cd "$ORIGINAL" || exit 0
  grep -n -E "session_version|is_active|last_login|password_verify|beginTransaction|rollBack|commit\(|UPDATE user|INSERT INTO user" \
    lib/auth_middleware.php controllers/AuthController.php controllers/UserController.php router/auth.php models/UserModel.php 2>&1 | cut -c1-200
else
  echo "(no existe $ORIGINAL)"
fi

section "5. Backend ORIGINAL: rutas que expone"
if [ -d "$ORIGINAL" ]; then
  grep -rn -E "\\\$uri *(===|==) *'/api|preg_match\('#?\^?/api" "$ORIGINAL/router" 2>/dev/null | cut -c1-160
fi

section "6. Cuentas MySQL que usan los backends y sus privilegios (solo lectura; sin contraseñas)"
for d in $BACKENDS; do
  echo "--- $d"
  user="$(sudo grep -E '^DB_USER=' "$d/.env" 2>/dev/null | head -1 | cut -d= -f2- | tr -d "\"' ")"
  name="$(sudo grep -E '^DB_NAME=' "$d/.env" 2>/dev/null | head -1 | cut -d= -f2- | tr -d "\"' ")"
  host="$(sudo grep -E '^DB_HOST=' "$d/.env" 2>/dev/null | head -1 | cut -d= -f2- | tr -d "\"' ")"
  echo "cuenta=${user:-?}  base=${name:-?}  host_de_conexion=${host:-?}"
done
try sudo mysql -e "SELECT user, host FROM mysql.user ORDER BY user, host;"
for d in $BACKENDS; do
  user="$(sudo grep -E '^DB_USER=' "$d/.env" 2>/dev/null | head -1 | cut -d= -f2- | tr -d "\"' ")"
  user="${user//[^A-Za-z0-9_.-]/}"   # solo caracteres seguros: el nombre se inserta en una sentencia SHOW GRANTS
  [ -z "$user" ] && continue
  echo "--- privilegios de la cuenta '$user' (SHOW GRANTS; solo lectura)"
  # Genera y ejecuta únicamente sentencias SHOW GRANTS para esa cuenta (no se imprime ninguna contraseña).
  try sudo mysql -N -e "SELECT CONCAT('SHOW GRANTS FOR ', QUOTE(user), '@', QUOTE(host), ';') FROM mysql.user WHERE user = '$user';" | try sudo mysql -N
done

section "FIN (nada fue modificado)"
