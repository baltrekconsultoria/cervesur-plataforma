#!/usr/bin/env bash
# Instala el entorno React ADMINISTRATIVO DE QA (UX-08F) en un sitio exclusivo, accesible SOLO por túnel SSH (127.0.0.1:8090).
#   sudo bash scripts/instalar.sh --simular                          comprobaciones previas de solo lectura, sin cambiar nada
#   sudo bash scripts/instalar.sh                                    comprobaciones + plan + confirmación escrita + instalación
#   sudo bash scripts/instalar.sh --aplicar-migracion-grupo-nullable  además aplica UX-08B3 (por defecto NO: la QA arranca como producción, grupo_id NOT NULL)
# NO modifica Flutter (80), los React de 443/8443/8088/8089, los módulos de Apache, ninguna base ni usuario MySQL existente, ni la configuración global.
# Aborta SIN cambiar nada si algún nombre, puerto, ruta, base o usuario ya existe. Si un paso falla, revierte SOLO lo que este instalador creó.
set -euo pipefail
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
SIMULAR=0
MIGRACION_B3=0
for arg in "$@"; do
  case "$arg" in
    --simular) SIMULAR=1 ;;
    --aplicar-migracion-grupo-nullable) MIGRACION_B3=1 ;;
    *) abortar "Opción desconocida: $arg (use --simular y/o --aplicar-migracion-grupo-nullable)" ;;
  esac
done
requerir_root
bloquear_ejecucion "${BASH_SOURCE[0]}"
verificar_no_colision   # rechaza cervesur_portalbi (o cualquier nombre que lo contenga) y toda colisión con los demás portales

errores=0
chk() { local descripcion=$1; shift; if "$@" >/dev/null 2>&1; then ok "$descripcion"; else fallo "$descripcion"; errores=$((errores + 1)); fi; }
sin_nada_en_puerto() { [ -z "$(ss -ltnH "sport = :$PUERTO")" ]; }
no_existe() { [ ! -e "$1" ] && [ ! -L "$1" ]; }
mysql_tcp_local() { local escuchas; escuchas=$(ss -ltnH 'sport = :3306' | awk '{print $4}'); grep -qE '^(127\.0\.0\.1|0\.0\.0\.0|\*|\[::\]):3306$' <<<"$escuchas"; }
base_inexistente() { [ -z "$(mysql_admin -N -e "SHOW DATABASES LIKE '$DB_NOMBRE'")" ]; }
usuario_inexistente() { [ "$(mysql_admin -N -e "SELECT COUNT(*) FROM mysql.user WHERE user = '$DB_USUARIO'")" = "0" ]; }
espacio_libre() { [ "$(df --output=avail -k "$1" | tail -1 | tr -d ' ')" -ge 512000 ]; }
config_sin_mencion() { ! grep -RqsE "$1" /etc/apache2/ports.conf /etc/apache2/sites-enabled /etc/apache2/sites-available /etc/apache2/conf-enabled /etc/apache2/mods-enabled; }
archivos_del_paquete() { local f; for f in "$@"; do [ -s "$PAQ/$f" ] || return 1; done; }

echo "== 1. Comprobaciones previas (solo lectura)"
if grep -q 'VERSION_ID="24.04"' /etc/os-release; then ok "Ubuntu 24.04"; else aviso "No es Ubuntu 24.04: revise antes de continuar"; fi
ok "nombre de base «$DB_NOMBRE» admitido (no es ni contiene cervesur_portalbi) y sin colisión de nombres, rutas, puertos ni cookie con los demás portales"
chk "Apache: configuración actual válida (configtest)" apache2ctl configtest
chk "mod_alias activo" modulo_apache alias
chk "mod_rewrite activo" modulo_apache rewrite
chk "PHP integrado en Apache (mod_php)" modulo_apache 'php[0-9]*'
chk "mod_headers YA cargado (este instalador no habilita ni deshabilita módulos)" modulo_apache headers
# shellcheck disable=SC2016  # el código PHP va entre comillas simples a propósito
chk "PHP con las extensiones que usa el backend (pdo_mysql, mbstring, json, openssl)" php -r 'foreach (["pdo_mysql", "mbstring", "json", "openssl"] as $e) { if (!extension_loaded($e)) { fwrite(STDERR, "falta $e
"); exit(1); } }'
chk "openssl, curl, flock y ss disponibles" bash -c "command -v openssl && command -v curl && command -v flock && command -v ss"
chk "MySQL local en ejecución" mysql_activo
chk "MySQL escucha en 127.0.0.1:3306 (el backend conecta por TCP local)" mysql_tcp_local
chk "acceso administrador a MySQL (CERVESUR_MYSQL_ADMIN si no es root por socket)" mysql_admin -N -e 'SELECT 1'
chk "la base $DB_NOMBRE no existe todavía" base_inexistente
chk "ninguna cuenta MySQL llamada $DB_USUARIO existe todavía (en ningún host)" usuario_inexistente
# Los respaldos (DIR_RESPALDO) nunca se borran y pueden existir de intentos anteriores: cada intento añade su propio archivo y renueva la línea base.
for ruta in "$DIR_FRONT" "$DIR_BACK" "$DIR_CONF" "$DIR_VAR" "$SITE_CONF" "$SITE_ENABLED"; do chk "no existe $ruta" no_existe "$ruta"; done
chk "puerto $PUERTO libre" sin_nada_en_puerto
chk "ninguna configuración de Apache menciona el puerto $PUERTO" config_sin_mencion "(^|[^0-9])$PUERTO([^0-9]|\$)"
chk "ninguna configuración de Apache usa la cookie $COOKIE_SESION" config_sin_mencion "$COOKIE_SESION"
chk "espacio libre suficiente en /var y /opt (>= 500 MB)" bash -c "$(declare -f espacio_libre); espacio_libre /var && espacio_libre /opt"
chk "contenido mínimo del paquete (frontend, backend, migraciones y plantilla de cuenta)" archivos_del_paquete public/index.html backend/api.php "$PLANTILLA_CUENTA" database/migrations/UX-08B-audit-log.up.sql database/migrations/UX-08B-audit-log-immutable.up.sql database/migrations/UX-08B3-reporte-grupo-nullable.up.sql scripts/sembrar-qa-admin.php
chk "integridad del paquete (SHA256SUMS)" bash -c "cd '$PAQ' && sha256sum -c SHA256SUMS --quiet"
for p in "${PUERTOS_AJENOS[@]}"; do if escucha_puerto "$p"; then ok "portal en el puerto $p presente: solo se lee su huella"; else aviso "nada escucha en el puerto $p (se omite su huella)"; fi; done

[ "$errores" -eq 0 ] || abortar "$errores comprobación(es) previas fallaron. No se modificó nada."

cat <<PLAN

== Plan (nada se ha modificado todavía)
  - copia de seguridad de /etc/apache2 y huella de los demás portales, del servidor y de MySQL en $DIR_RESPALDO
  - carpetas nuevas con marca de instalación: $DIR_VAR (sesiones 700), $DIR_FRONT, $DIR_BACK ; configuración en $DIR_CONF y $SITE_CONF
  - base nueva $DB_NOMBRE (datos 100 % sintéticos, DDL productivo + claves UNIQUE productivas, migraciones UX-08B y UX-08B.2)
  - migración UX-08B3 (grupo_id NULL): $([ "$MIGRACION_B3" -eq 1 ] && echo "SE APLICA (pedido con --aplicar-migracion-grupo-nullable)" || echo "NO se aplica (la QA arranca como producción, grupo_id NOT NULL)")
  - cuenta MySQL nueva $DB_USUARIO@$DB_HOST_USUARIO creada desde la plantilla de privilegios mínimos (sin DELETE, sin ALL PRIVILEGES, sin GRANT OPTION)
  - sitio Apache solo en 127.0.0.1:$PUERTO ; recarga suave ; NO se habilitan ni deshabilitan módulos ; NO se reinicia Apache
  - NO se toca ningún otro portal, ni la base $BASE_PRODUCTIVA, ni se concede nada sobre ellos ; NO se abre ningún puerto en la red
  - informe sintético que hace de DB031: reporte.id = $VISOR_IDS (el id real 72 es solo para el piloto real, tras aprobación expresa)

  *****************************************************************************************************
  * AVISO: este entorno arranca con USER_ADMIN_WRITES=1 (escrituras administrativas) SOLO PARA LA QA.   *
  * Esa variable debe permanecer APAGADA (ausente o distinta de 1) en los portales publicados 443/8443. *
  *****************************************************************************************************
PLAN
if [ "$SIMULAR" -eq 1 ]; then echo "Modo --simular: fin. No se modificó nada."; exit 0; fi
if [ "${CERVESUR_QA_ADMIN_CONFIRMAR:-}" != "SI" ]; then
  read -r -p "Escriba INSTALAR-QA-ADMIN para continuar: " respuesta
  [ "$respuesta" = "INSTALAR-QA-ADMIN" ] || abortar "Cancelado. No se modificó nada."
fi

echo "== Respaldo y línea base del servidor y de los demás portales"
install -d -m 700 "$DIR_RESPALDO"
tar czf "$DIR_RESPALDO/apache2-etc-$(date +%Y%m%d-%H%M%S).tgz" -C / etc/apache2 2>/dev/null
huella_servidor > "$DIR_RESPALDO/linea-base-portales.txt"
cp "$DIR_RESPALDO/linea-base-portales.txt" "$DIR_RESPALDO/linea-base-$(date +%Y%m%d-%H%M%S).txt"
ok "respaldo y línea base en $DIR_RESPALDO (permisos 700)"

tmp=$(mktemp -d)
trap 'rm -rf "$tmp"' EXIT
chmod 700 "$tmp"
pasos=("$PAQ/scripts/pasos-instalacion.sh" "$PAQ" "$tmp/cuentas")
[ "$MIGRACION_B3" -eq 1 ] && pasos+=(--con-grupo-nullable)
if ! bash "${pasos[@]}"; then
  fallo "La instalación falló: se revierte automáticamente SOLO lo creado por este instalador."
  CERVESUR_QA_ADMIN_CONFIRMAR=SI bash "$PAQ/scripts/revertir.sh" || true
  exit 1
fi

echo
echo "== Cuentas SINTÉTICAS de QA (se muestran UNA sola vez; en la base solo existe su hash. Guárdelas en un gestor de contraseñas)"
echo "   syn001 = administrador (rol admin) · syn005, syn006 = trabajadores (rol usuario) · syn007 = trabajador (rol user, valor heredado)"
cat "$tmp/cuentas"
echo
CERVESUR_QA_ADMIN_PASSWORD=$(awk '$2 == "syn001" { print $3 }' "$tmp/cuentas") \
CERVESUR_QA_USUARIO_PASSWORD=$(awk '$2 == "syn005" { print $3 }' "$tmp/cuentas") \
CERVESUR_QA_USER_PASSWORD=$(awk '$2 == "syn007" { print $3 }' "$tmp/cuentas") \
CERVESUR_QA_VERIF_INICIAL=1 bash "$PAQ/scripts/verificar.sh" || {
  fallo "La verificación falló (NO-GO). El sitio sigue instalado para diagnóstico; para deshacerlo: sudo bash scripts/revertir.sh"; exit 1; }
echo
echo "Instalación completada. Acceso: ssh -N -L 18091:127.0.0.1:$PUERTO <usuario>@<servidor>  y abrir http://localhost:18091"
echo "RECORDATORIO: USER_ADMIN_WRITES=1 es exclusivo de este entorno de QA; NO lo active en los portales 443/8443."
