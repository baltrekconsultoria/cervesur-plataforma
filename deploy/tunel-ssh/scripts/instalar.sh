#!/usr/bin/env bash
# Instala el portal React de PRUEBA en un sitio independiente, accesible SOLO por túnel SSH (127.0.0.1:8088).
#   sudo bash scripts/instalar.sh --simular    comprobaciones previas de solo lectura, sin cambiar nada
#   sudo bash scripts/instalar.sh              comprobaciones + confirmación escrita + instalación
# No modifica /var/www/cervesur, cervesur.conf ni la base productiva. Si un paso falla, revierte automáticamente.
set -euo pipefail
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
SIMULAR=0
[ "${1:-}" = "--simular" ] && SIMULAR=1
requerir_root

errores=0
chk() { local descripcion=$1; shift; if "$@" >/dev/null 2>&1; then ok "$descripcion"; else fallo "$descripcion"; errores=$((errores + 1)); fi; }
sin_nada_en_puerto() { [ -z "$(ss -ltnH "sport = :$PUERTO")" ]; }
no_existe() { [ ! -e "$1" ]; }
mysql_tcp_local() { local escuchas; escuchas=$(ss -ltnH 'sport = :3306' | awk '{print $4}'); grep -qE '^(127\.0\.0\.1|0\.0\.0\.0|\*|\[::\]):3306$' <<<"$escuchas"; }
base_inexistente() { [ -z "$($MYSQL_ADMIN -N -e "SHOW DATABASES LIKE '$DB_NOMBRE'")" ]; }
usuario_inexistente() { [ "$($MYSQL_ADMIN -N -e "SELECT COUNT(*) FROM mysql.user WHERE user = '$DB_USUARIO'")" = "0" ]; }
espacio_libre() { [ "$(df --output=avail -k "$1" | tail -1 | tr -d ' ')" -ge 512000 ]; }

echo "== 1. Comprobaciones previas (solo lectura)"
if grep -q 'VERSION_ID="24.04"' /etc/os-release; then ok "Ubuntu 24.04"; else aviso "No es Ubuntu 24.04: revise antes de continuar"; fi
chk "Apache: configuración actual válida (configtest)" apache2ctl configtest
chk "mod_alias activo" modulo_apache alias
chk "mod_rewrite activo" modulo_apache rewrite
chk "PHP integrado en Apache (mod_php)" modulo_apache 'php[0-9]*'
chk "PHP de línea de comandos con pdo_mysql" php -r 'exit(extension_loaded("pdo_mysql") ? 0 : 1);'
chk "openssl y curl disponibles" bash -c "command -v openssl && command -v curl"
chk "MySQL local en ejecución (esta instalación necesita un servidor local)" mysql_activo
# shellcheck disable=SC2086
chk "acceso administrador a MySQL (CERVESUR_MYSQL_ADMIN si no es root por socket)" $MYSQL_ADMIN -N -e 'SELECT 1'
chk "MySQL escucha en 127.0.0.1:3306 (el backend conecta por TCP local, sin depender de permisos del socket)" mysql_tcp_local
chk "la base $DB_NOMBRE no existe todavía" base_inexistente
chk "el usuario $DB_USUARIO no existe todavía" usuario_inexistente
for ruta in "$DIR_FRONT" "$DIR_BACK" "$DIR_CONF" "$SITE_CONF"; do chk "no existe $ruta" no_existe "$ruta"; done
chk "puerto $PUERTO libre" sin_nada_en_puerto
chk "ninguna configuración de Apache menciona el puerto $PUERTO" bash -c "! grep -RqsE '(^|[^0-9])$PUERTO([^0-9]|\$)' /etc/apache2/ports.conf /etc/apache2/sites-enabled /etc/apache2/conf-enabled"
chk "espacio libre suficiente en /var y /opt (>= 500 MB)" bash -c "$(declare -f espacio_libre); espacio_libre /var && espacio_libre /opt"
chk "integridad del paquete (SHA256SUMS)" bash -c "cd '$PAQ' && sha256sum -c SHA256SUMS --quiet"
if [ -d "$FLUTTER_DIR" ]; then ok "portal Flutter presente en $FLUTTER_DIR (solo se lee su huella)"; else aviso "No existe $FLUTTER_DIR"; fi

# Habilitar mod_headers es un cambio global: si Flutter ya tiene directivas «Header» dentro de <IfModule mod_headers.c>, hoy están
# inactivas y se activarían. Se detecta antes de tocar nada. (-R y no -r: sites-enabled y conf-enabled solo contienen enlaces simbólicos.)
if ! modulo_apache headers; then
  coincidencias=$(grep -RIlsE '^[[:space:]]*(Request)?Header[[:space:]]' /etc/apache2/sites-enabled /etc/apache2/conf-enabled /etc/apache2/apache2.conf "$FLUTTER_DIR/.htaccess" "$FLUTTER_DIR/frontend/.htaccess" "$FLUTTER_DIR/backend/.htaccess" 2>/dev/null || true)
  if [ -n "$coincidencias" ] && [ "${CERVESUR_ACEPTAR_HEADERS:-}" != "SI" ]; then
    fallo "Estos archivos tienen directivas Header que hoy están inactivas y se activarían al habilitar mod_headers:"
    # shellcheck disable=SC2086  # lista de archivos sin espacios
    printf '        %s\n' $coincidencias >&2
    fallo "Revíselos; si es aceptable, repita con CERVESUR_ACEPTAR_HEADERS=SI."
    errores=$((errores + 1))
  else
    ok "habilitar mod_headers no activa directivas de Flutter (no hay Header inactivos en su configuración)"
  fi
fi

[ "$errores" -eq 0 ] || abortar "$errores comprobación(es) previas fallaron. No se modificó nada."

cat <<PLAN

== Plan (nada se ha modificado todavía)
  - copia de seguridad de /etc/apache2 y huella de Flutter en $DIR_RESPALDO
  - carpetas nuevas: $DIR_FRONT y $DIR_BACK ; configuración en $DIR_CONF y $SITE_CONF
  - base de datos nueva $DB_NOMBRE y usuario $DB_USUARIO@localhost (datos sintéticos, migración UX-07C solo en esa base)
  - mod_headers (si no estaba habilitado) y sitio Apache solo en 127.0.0.1:$PUERTO ; recarga suave, sin reinicio
  - NO se modifica /var/www/cervesur ni cervesur.conf ; NO se abre ningún puerto en la red
PLAN
if [ "$SIMULAR" -eq 1 ]; then echo "Modo --simular: fin. No se modificó nada."; exit 0; fi
if [ "${CERVESUR_CONFIRMAR:-}" != "SI" ]; then
  read -r -p "Escriba INSTALAR para continuar: " respuesta
  [ "$respuesta" = "INSTALAR" ] || abortar "Cancelado. No se modificó nada."
fi

echo "== Respaldo y línea base"
install -d -m 700 "$DIR_RESPALDO"
tar czf "$DIR_RESPALDO/apache2-etc-$(date +%Y%m%d-%H%M%S).tgz" -C / etc/apache2 2>/dev/null
huella_flutter > "$DIR_RESPALDO/linea-base-flutter.txt"
ss -ltnH | awk '{print $4}' | LC_ALL=C sort -u > "$DIR_RESPALDO/puertos-antes.txt"
ok "respaldo y línea base en $DIR_RESPALDO (permisos 700)"

cuentas=$(mktemp)
trap 'rm -f "$cuentas"' EXIT
if ! bash "$PAQ/scripts/pasos-instalacion.sh" "$PAQ" "$cuentas"; then
  fallo "La instalación falló: se revierte automáticamente."
  CERVESUR_CONFIRMAR=SI bash "$PAQ/scripts/revertir.sh" || true
  exit 1
fi

echo
echo "== Cuentas SINTÉTICAS de prueba (se muestran UNA sola vez; en la base solo existe su hash. Guárdelas en un gestor de contraseñas)"
cat "$cuentas"
echo
CERVESUR_PRUEBA_ADMIN_PASSWORD=$(awk '$2 == "admin" { print $3 }' "$cuentas") bash "$PAQ/scripts/verificar.sh" ||
  { fallo "La verificación falló. El sitio sigue instalado para diagnóstico; para deshacerlo: sudo bash scripts/revertir.sh"; exit 1; }
echo
echo "Instalación de prueba completada. Acceso: ssh -N -L 18089:127.0.0.1:$PUERTO <usuario>@<servidor>  y abrir http://localhost:18089"
