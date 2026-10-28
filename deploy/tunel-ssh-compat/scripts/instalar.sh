#!/usr/bin/env bash
# Instala el entorno React de COMPATIBILIDAD PRODUCTIVA (UX-07D.2C) en un sitio exclusivo, accesible SOLO por túnel SSH (127.0.0.1:8089).
#   sudo bash scripts/instalar.sh --simular    comprobaciones previas de solo lectura, sin cambiar nada
#   sudo bash scripts/instalar.sh              comprobaciones + plan + confirmación escrita + instalación
# NO modifica Flutter (puerto 80), el React de prueba (127.0.0.1:8088), los módulos de Apache, ninguna base ni usuario MySQL existente
# ni la configuración global. Si un paso falla, revierte automáticamente SOLO lo que creó este instalador.
set -euo pipefail
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
SIMULAR=0
[ "${1:-}" = "--simular" ] && SIMULAR=1
requerir_root
verificar_no_colision

errores=0
chk() { local descripcion=$1; shift; if "$@" >/dev/null 2>&1; then ok "$descripcion"; else fallo "$descripcion"; errores=$((errores + 1)); fi; }
sin_nada_en_puerto() { [ -z "$(ss -ltnH "sport = :$PUERTO")" ]; }
no_existe() { [ ! -e "$1" ] && [ ! -L "$1" ]; }
mysql_tcp_local() { local escuchas; escuchas=$(ss -ltnH 'sport = :3306' | awk '{print $4}'); grep -qE '^(127\.0\.0\.1|0\.0\.0\.0|\*|\[::\]):3306$' <<<"$escuchas"; }
base_inexistente() { [ -z "$($MYSQL_ADMIN -N -e "SHOW DATABASES LIKE '$DB_NOMBRE'")" ]; }
usuario_inexistente() { [ "$($MYSQL_ADMIN -N -e "SELECT COUNT(*) FROM mysql.user WHERE user = '$DB_USUARIO'")" = "0" ]; }
espacio_libre() { [ "$(df --output=avail -k "$1" | tail -1 | tr -d ' ')" -ge 512000 ]; }

echo "== 1. Comprobaciones previas (solo lectura)"
if grep -q 'VERSION_ID="24.04"' /etc/os-release; then ok "Ubuntu 24.04"; else aviso "No es Ubuntu 24.04: revise antes de continuar"; fi
ok "sin colisión de nombres, rutas, puertos, base, usuario ni cookie con Flutter (80) y el React de prueba (8088)"
chk "Apache: configuración actual válida (configtest)" apache2ctl configtest
chk "mod_alias activo" modulo_apache alias
chk "mod_rewrite activo" modulo_apache rewrite
chk "PHP integrado en Apache (mod_php)" modulo_apache 'php[0-9]*'
chk "mod_headers YA cargado (lo habilitó el instalador de 8088; este instalador no lo toca jamás)" modulo_apache headers
# shellcheck disable=SC2016  # el código PHP va entre comillas simples a propósito
chk "PHP con las extensiones que usa el backend (pdo_mysql, mbstring, json, openssl)" php -r 'foreach (["pdo_mysql", "mbstring", "json", "openssl"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "falta $e
"); exit(1); } }'
chk "openssl y curl disponibles" bash -c "command -v openssl && command -v curl"
chk "MySQL local en ejecución" mysql_activo
chk "MySQL escucha en 127.0.0.1:3306 (el backend conecta por TCP local)" mysql_tcp_local
# shellcheck disable=SC2086
chk "acceso administrador a MySQL (CERVESUR_MYSQL_ADMIN si no es root por socket)" $MYSQL_ADMIN -N -e 'SELECT 1'
chk "la base $DB_NOMBRE no existe todavía" base_inexistente
chk "el usuario MySQL $DB_USUARIO no existe todavía" usuario_inexistente
# Los respaldos (DIR_RESPALDO) nunca se borran y pueden existir de intentos anteriores: cada intento añade su propio archivo y renueva la línea base.
for ruta in "$DIR_FRONT" "$DIR_BACK" "$DIR_CONF" "$DIR_VAR" "$SITE_CONF" "$SITE_ENABLED"; do chk "no existe $ruta" no_existe "$ruta"; done
chk "puerto $PUERTO libre" sin_nada_en_puerto
chk "ninguna configuración de Apache menciona el puerto $PUERTO" bash -c "! grep -RqsE '(^|[^0-9])$PUERTO([^0-9]|\$)' /etc/apache2/ports.conf /etc/apache2/sites-enabled /etc/apache2/conf-enabled"
chk "espacio libre suficiente en /var y /opt (>= 500 MB)" bash -c "$(declare -f espacio_libre); espacio_libre /var && espacio_libre /opt"
chk "integridad del paquete (SHA256SUMS)" bash -c "cd '$PAQ' && sha256sum -c SHA256SUMS --quiet"
if [ -d "$FLUTTER_DIR" ]; then ok "portal Flutter presente en $FLUTTER_DIR (solo se lee su huella)"; else aviso "No existe $FLUTTER_DIR"; fi
if [ -e "$SITE_ENABLED_PRUEBA" ]; then ok "React de prueba (8088) instalado: solo se lee su huella"; else aviso "El React de prueba (8088) no está instalado: se omite su huella"; fi

[ "$errores" -eq 0 ] || abortar "$errores comprobación(es) previas fallaron. No se modificó nada."

cat <<PLAN

== Plan (nada se ha modificado todavía)
  - copia de seguridad de /etc/apache2 y huella de Flutter, del React de 8088 y del servidor en $DIR_RESPALDO
  - carpetas nuevas: $DIR_FRONT, $DIR_BACK y $DIR_VAR (sesiones) ; configuración en $DIR_CONF y $SITE_CONF
  - base de datos nueva $DB_NOMBRE y usuario $DB_USUARIO (datos 100 % sintéticos; migración mínima de grupo_id solo en esa base)
  - sitio Apache solo en 127.0.0.1:$PUERTO ; recarga suave ; NO se habilitan ni deshabilitan módulos ; NO se reinicia Apache
  - NO se modifica /var/www/cervesur, el sitio 8088 (ni su base) ; NO se abre ningún puerto en la red ; NO se usa la base productiva
PLAN
if [ "$SIMULAR" -eq 1 ]; then echo "Modo --simular: fin. No se modificó nada."; exit 0; fi
if [ "${CERVESUR_COMPAT_CONFIRMAR:-}" != "SI" ]; then
  read -r -p "Escriba INSTALAR-COMPAT para continuar: " respuesta
  [ "$respuesta" = "INSTALAR-COMPAT" ] || abortar "Cancelado. No se modificó nada."
fi

echo "== Respaldo y línea base de los demás portales"
install -d -m 700 "$DIR_RESPALDO"
tar czf "$DIR_RESPALDO/apache2-etc-$(date +%Y%m%d-%H%M%S).tgz" -C / etc/apache2 2>/dev/null
huella_portales > "$DIR_RESPALDO/linea-base-portales.txt"
ok "respaldo y línea base en $DIR_RESPALDO (permisos 700)"

cuentas=$(mktemp)
trap 'rm -f "$cuentas"' EXIT
if ! bash "$PAQ/scripts/pasos-instalacion.sh" "$PAQ" "$cuentas"; then
  fallo "La instalación falló: se revierte automáticamente SOLO lo creado por este instalador."
  CERVESUR_COMPAT_CONFIRMAR=SI bash "$PAQ/scripts/revertir.sh" || true
  exit 1
fi

echo
echo "== Cuentas SINTÉTICAS de compatibilidad (se muestran UNA sola vez; en la base solo existe su hash. Guárdelas en un gestor de contraseñas)"
echo "   syn001 = administrador · syn005, syn006, syn007 = usuarios con informes asignados"
cat "$cuentas"
echo
CERVESUR_PRUEBA_ADMIN_PASSWORD=$(awk '$2 == "syn001" { print $3 }' "$cuentas") bash "$PAQ/scripts/verificar.sh" ||
  { fallo "La verificación falló. El sitio sigue instalado para diagnóstico; para deshacerlo: sudo bash scripts/revertir.sh"; exit 1; }
echo
echo "Instalación de compatibilidad completada. Acceso: ssh -N -L 18090:127.0.0.1:$PUERTO <usuario>@<servidor>  y abrir http://localhost:18090"
