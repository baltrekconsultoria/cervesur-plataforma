#!/usr/bin/env bash
# Cervesur · UX-08 · Pendientes de la auditoría del backend ORIGINAL (Flutter). SOLO LECTURA.
# Cierra: P1 (consulta completa del login), P2 (expiración y validaciones del JWT), P5/P6 (edición, alta, activación y desactivación de usuarios en el
# original), P7 (transacciones) y P8 (cuenta y privilegios reales de cada conexión PHP). No repite el mapa del servidor ya obtenido.
#
# NO imprime: .env, contraseñas, claves JWT, tokens, hashes ni valores de datos. De cada archivo solo se muestran líneas coincidentes (con número de
# línea, recortadas) y se omiten las que mencionan «secret». Revise la salida antes de compartirla.
# USO:  bash flutter-original-solo-lectura.sh > flutter-original.txt 2>&1
set -u
ORIGINAL="${ORIGINAL:-/var/www/cervesur/backend}"
BACKENDS="${BACKENDS:-/var/www/cervesur/backend /opt/cervesur-react-usuarios/backend /opt/cervesur-react-admin/backend}"
section() { printf '\n==================== %s ====================\n' "$1"; }
try() { "$@" 2>&1 || echo "(sin permiso o no disponible: $*)"; }
[ -d "$ORIGINAL" ] || { echo "No existe $ORIGINAL"; exit 2; }
cd "$ORIGINAL" || exit 2

section "P1 · Consultas de usuario por login (¿filtran is_active?)"
grep -n -E "function |is_active|FROM user|WHERE" models/UserModel.php | cut -c1-150
echo "-- Uso en el login:"; grep -n -E "getUserByLogin|findByLogin|is_active|password_verify|!\\\$user" controllers/AuthController.php | cut -c1-150

section "P2 · Expiración y validaciones del JWT (se omiten líneas con «secret»)"
grep -n -i -E "exp|ttl|lifetime|time\(\)|iat|nbf|alg|validate" controllers/AuthController.php lib/*.php | grep -v -i secret | cut -c1-150
echo "-- Claves de configuración relacionadas (solo nombres, valores ocultos):"
for d in $BACKENDS; do echo "$d:"; try sudo sed -E 's/^([A-Za-z_][A-Za-z0-9_]*)=.*/\1=<oculto>/' "$d/.env" | grep -i -E "JWT|EXP|TTL|SESSION" | sed 's/^/  /'; done

section "P5/P6 · ¿Existen altas, ediciones, activación o desactivación de usuarios en el original?"
grep -rn -E "INSERT INTO user|UPDATE user|is_active *=|session_version|password_hash" --include=*.php . | cut -c1-150
echo "-- Rutas del original que tocan usuarios:"; grep -rn -E "/api/(user|users|register)" router/ | cut -c1-140

section "P7 · Transacciones (conteo por archivo)"
grep -rn -c -E "beginTransaction|->commit\(|rollBack" --include=*.php . | grep -v ":0"
echo "(si no aparece ningún archivo, el original no usa transacciones)"

section "P8 · Cuenta y base de cada conexión PHP (solo nombres) y privilegios reales"
for d in $BACKENDS; do echo "== $d"; try sudo grep -E '^DB_(USER|NAME|HOST)=' "$d/.env" | sed 's/^/  /'; done
echo "-- SHOW GRANTS de las cuentas cervesur* (sin contraseñas):"
try sudo mysql -N -e "SELECT CONCAT('SHOW GRANTS FOR ', QUOTE(user), '@', QUOTE(host), ';') FROM mysql.user WHERE user LIKE 'cervesur%'" | try sudo mysql -N
echo "-- Privilegios por tabla/columna concedidos a cuentas cervesur* (metadatos):"
try sudo mysql -e "SELECT user, host, db, table_name, table_priv FROM mysql.tables_priv WHERE user LIKE 'cervesur%' ORDER BY user, db, table_name"

section "FIN (nada fue modificado)"
