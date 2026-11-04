#!/usr/bin/env bash
# Instala el entorno React de ESCRITURA ADMINISTRATIVA de QA (UX-08H) en un sitio exclusivo, accesible SOLO por túnel SSH (127.0.0.1:<puerto>).
#   sudo bash scripts/instalar.sh --simular --puerto N                          comprobaciones previas de SOLO LECTURA + resumen GO/NO-GO, sin cambiar nada
#   sudo bash scripts/instalar.sh --puerto N                                    comprobaciones + plan + confirmación escrita + instalación
#   sudo bash scripts/instalar.sh --puerto N --escrituras usuarios,empresas     interruptores de escritura que arrancan ENCENDIDOS (por defecto solo «usuarios»; el resto, apagado)
#   sudo bash scripts/instalar.sh --puerto N --aplicar-migracion-grupo-nullable  además aplica UX-08B3 (por defecto NO: la QA arranca como producción, grupo_id NOT NULL)
# El puerto es SIEMPRE explícito (también CERVESUR_QAE_PUERTO): jamás se elige ni se cambia en silencio. Se rechaza si es de otro portal (80, 443, 8443,
# 8088, 8089, 8090-8094), ssh o MySQL, si algo escucha en él, o si cualquier configuración de Apache lo menciona.
# NO modifica Flutter (80), los React de 443/8443/8088/8089/8090-8094, los módulos de Apache, ninguna base ni usuario MySQL existente, ni la configuración global.
# admin_escritura jamás se configura en 443/8443: la simulación exige que ningún portal publicado tenga escrituras ni ese modo encendidos.
# Aborta SIN cambiar nada si algún nombre, puerto, ruta, base o usuario ya existe. Si un paso falla, revierte SOLO lo que este instalador creó.
set -euo pipefail
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
SIMULAR=0
MIGRACION_B3=0
ARG_PUERTO=''
ESCRITURAS=${CERVESUR_QAE_ESCRITURAS:-usuarios}
while [ "$#" -gt 0 ]; do
  case "$1" in
    --simular) SIMULAR=1 ;;
    --aplicar-migracion-grupo-nullable) MIGRACION_B3=1 ;;
    --puerto) [ "$#" -ge 2 ] || { printf 'FALLO  --puerto necesita un valor.\n' >&2; exit 1; }; ARG_PUERTO=$2; shift ;;
    --puerto=*) ARG_PUERTO=${1#--puerto=} ;;
    --escrituras) [ "$#" -ge 2 ] || { printf 'FALLO  --escrituras necesita un valor.\n' >&2; exit 1; }; ESCRITURAS=$2; shift ;;
    --escrituras=*) ESCRITURAS=${1#--escrituras=} ;;
    *) printf 'FALLO  Opción desconocida: %s (use --puerto N, --simular, --escrituras lista y/o --aplicar-migracion-grupo-nullable)\n' "$1" >&2; exit 1 ;;
  esac
  shift
done
if [ -n "$ARG_PUERTO" ]; then
  if [ -n "${CERVESUR_QAE_PUERTO:-}" ] && [ "$CERVESUR_QAE_PUERTO" != "$ARG_PUERTO" ]; then printf 'FALLO  --puerto (%s) y CERVESUR_QAE_PUERTO (%s) no coinciden. No se hace nada.\n' "$ARG_PUERTO" "$CERVESUR_QAE_PUERTO" >&2; exit 1; fi
  export CERVESUR_QAE_PUERTO=$ARG_PUERTO
fi
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
requerir_root
bloquear_ejecucion "${BASH_SOURCE[0]}"
lista_escrituras_valida "$ESCRITURAS" || abortar "--escrituras debe ser una lista sin repetidos de: empresas, reportes, accesos, usuarios (recibido: «$ESCRITURAS»). No se modificó nada."
export CERVESUR_QAE_ESCRITURAS=$ESCRITURAS
if ! (validar_puerto); then   # explícito, bien formado y que no sea de otro portal
  echo "   RESULTADO: NO-GO (puerto no admitido: $([ -n "$PUERTO" ] && echo "$PUERTO" || echo "sin indicar")). No se modificó nada."
  exit 1
fi
export CERVESUR_QAE_PUERTO=$PUERTO
verificar_no_colision   # rechaza cervesur_portalbi (o cualquier nombre que lo contenga) y toda colisión con los demás portales

errores=0
declare -A ESTADO=()   # resumen por área para el informe final GO/NO-GO (solo texto en memoria: la simulación no escribe archivos)
chk() { local descripcion=$1; shift; if "$@" >/dev/null 2>&1; then ok "$descripcion"; else fallo "$descripcion"; errores=$((errores + 1)); fi; }
chka() { local area=$1; shift; local antes=$errores; chk "$@"; if [ "$errores" -eq "$antes" ]; then ESTADO[$area]=OK; else ESTADO[$area]=FALLO; fi; }  # igual que chk y anota el área
sin_nada_en_puerto() { [ -z "$(ss -ltnH "sport = :$PUERTO")" ]; }
oyentes_puerto() { ss -ltnH "sport = :$PUERTO" | awk '{print $4}' | LC_ALL=C sort -u | tr '\n' ' '; }
vhost_con_puerto() { local salida; salida=$(apache2ctl -S 2>&1) || true; ! grep -qE "[: ]$PUERTO( |\$)" <<<"$salida"; }
version_mysql_minima() { local v; v=$(mysql_admin -N -e 'SELECT VERSION()' | sed -E 's/^([0-9]+\.[0-9]+\.[0-9]+).*/\1/'); [ "$(printf '8.0.21\n%s\n' "$v" | sort -V | head -1)" = "8.0.21" ]; }
# Triggers con el registro binario activo: sin log_bin_trust_function_creators=1, MySQL exige SUPER a quien crea un trigger (error 1419).
# Aquí solo se LEEN las variables y los privilegios de la cuenta administradora: no se cambia ninguna variable global ni se concede nada.
variable_mysql() { mysql_admin -N -e "SELECT @@GLOBAL.$1"; }
admin_puede_crear_triggers() {
  local binlog trust grants
  binlog=$(variable_mysql log_bin) || return 1; trust=$(variable_mysql log_bin_trust_function_creators) || return 1
  if [ "$binlog" = 0 ] || [ "$trust" = 1 ]; then return 0; fi
  grants=$(mysql_admin -N -e 'SHOW GRANTS FOR CURRENT_USER()') || return 1
  grep -qE 'GRANT ALL PRIVILEGES ON \*\.\*|GRANT [^`]*[ ,]SUPER[ ,]' <<<"$grants"
}
PORTALES_PUBLICADOS=${CERVESUR_QAE_PORTALES_PUBLICADOS:-/opt/cervesur-react-usuarios /opt/cervesur-react-admin}
# shellcheck disable=SC2086  # la lista de instancias se separa por espacios a propósito
interruptores_publicados() { bash "$PAQ/scripts/comprobar-portales-publicados.sh" $PORTALES_PUBLICADOS; }   # lectura pura: sale con error si no se puede demostrar apagado
no_existe() { [ ! -e "$1" ] && [ ! -L "$1" ]; }
mysql_tcp_local() { local escuchas; escuchas=$(ss -ltnH 'sport = :3306' | awk '{print $4}'); grep -qE '^(127\.0\.0\.1|0\.0\.0\.0|\*|\[::\]):3306$' <<<"$escuchas"; }
base_inexistente() { [ -z "$(mysql_admin -N -e "SHOW DATABASES LIKE '$DB_NOMBRE'")" ] && [ -z "$(mysql_admin -N -e "SHOW DATABASES LIKE '$DB_ESTADO'")" ]; }
usuarios_inexistentes() { [ "$(mysql_admin -N -e "SELECT COUNT(*) FROM mysql.user WHERE user IN ('$DB_USUARIO', '$DB_USUARIO_AUTH', '$DB_USUARIO_CAT', '$DB_USUARIO_ESTADO')")" = "0" ]; }
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
chka mysql "acceso administrador a MySQL (CERVESUR_MYSQL_ADMIN si no es root por socket)" mysql_admin -N -e 'SELECT 1'
chka mysql "MySQL 8.0.21 o superior (necesario para marcar la cuenta con ATTRIBUTE/COMMENT)" version_mysql_minima
chka mysql "triggers con binlog: la cuenta administradora puede crearlos (log_bin=$(variable_mysql log_bin 2>/dev/null), log_bin_trust_function_creators=$(variable_mysql log_bin_trust_function_creators 2>/dev/null); sin trust=1 hace falta SUPER, solo durante la instalación)" admin_puede_crear_triggers
chka base "las bases $DB_NOMBRE y $DB_ESTADO no existen todavía" base_inexistente
chka cuenta "ninguna de las cuentas MySQL $DB_USUARIO, $DB_USUARIO_AUTH, $DB_USUARIO_CAT y $DB_USUARIO_ESTADO existe todavía (en ningún host)" usuarios_inexistentes
# Los respaldos (DIR_RESPALDO) nunca se borran y pueden existir de intentos anteriores: cada intento añade su propio archivo y renueva la línea base.
for ruta in "$DIR_FRONT" "$DIR_BACK" "$DIR_CONF" "$DIR_VAR" "$SITE_CONF" "$SITE_ENABLED"; do chka rutas "no existe $ruta" no_existe "$ruta"; done
chka puerto "puerto $PUERTO libre: nada escucha en él (en ninguna interfaz) [ahora escuchan: $(oyentes_puerto)]" sin_nada_en_puerto
chka puerto "ninguna configuración de Apache menciona el puerto $PUERTO" config_sin_mencion "(^|[^0-9])$PUERTO([^0-9]|\$)"
chka puerto "ningún VirtualHost de Apache (apache2ctl -S) usa el puerto $PUERTO" vhost_con_puerto
chk "ninguna configuración de Apache usa la cookie $COOKIE_SESION" config_sin_mencion "$COOKIE_SESION"
echo "-- Escrituras (USER_ADMIN_WRITES, ADMIN_WRITES_*) y modo admin_escritura en los portales publicados (lectura; sin valores ni .env):"
# shellcheck disable=SC2086
bash "$PAQ/scripts/comprobar-portales-publicados.sh" $PORTALES_PUBLICADOS 2>&1 | sed 's/^/       /' || true   # muestra la tabla (instancia · estado · fuente · resultado); no imprime valores
chka interruptores "escrituras y modo admin_escritura demostrablemente APAGADOS en los portales publicados (si no se puede demostrar: NO-GO)" interruptores_publicados
chk "espacio libre suficiente en /var y /opt (>= 500 MB)" bash -c "$(declare -f espacio_libre); espacio_libre /var && espacio_libre /opt"
chk "contenido mínimo del paquete (frontend, backend, migraciones, SQL de cuentas y de estado, plantilla de la cuenta de escritura)" archivos_del_paquete public/index.html backend/api.php backend/lib/admin_escritura.php "$PLANTILLA_CUENTA" "$SQL_CUENTAS_LECTURA" "$SQL_ESTADO" database/migrations/UX-08B-audit-log.up.sql database/migrations/UX-08B-audit-log-immutable.up.sql database/migrations/UX-08B3-reporte-grupo-nullable.up.sql scripts/sembrar-qa-escritura.php scripts/actualizar.sh scripts/interruptor-escrituras.sh
chka paquete "integridad del paquete (SHA256SUMS)" bash -c "cd '$PAQ' && sha256sum -c SHA256SUMS --quiet"
for p in "${PUERTOS_AJENOS[@]}"; do if escucha_puerto "$p"; then ok "portal en el puerto $p presente: solo se lee su huella"; else aviso "nada escucha en el puerto $p (se omite su huella)"; fi; done

estado_area() { printf '%s' "${ESTADO[$1]:-OK}"; }
resumen_previo() {
  local libre; if sin_nada_en_puerto; then libre="libre (nada escucha)"; else libre="OCUPADO (escuchan: $(oyentes_puerto))"; fi
  if puerto_conocido "$PUERTO"; then libre="de otro portal o servicio (rechazado)"; fi
  echo
  echo "== Resumen previo ($([ "$SIMULAR" -eq 1 ] && echo simulación || echo instalación))"
  printf '   %-24s %s  [%s]\n' "Puerto solicitado" "127.0.0.1:$PUERTO" "$(estado_area puerto) · $libre"
  printf '   %-24s %s  [%s]\n' "Bases QA" "$DB_NOMBRE + $DB_ESTADO (estado)" "$(estado_area base)"
  printf '   %-24s %s  [%s]\n' "Cuentas QA" "$DB_USUARIO (escritura) · $DB_USUARIO_AUTH · $DB_USUARIO_CAT · $DB_USUARIO_ESTADO @$DB_HOST_USUARIO" "$(estado_area cuenta)"
  printf '   %-24s %s  [%s]\n' "MySQL" "$(mysql_admin -N -e 'SELECT VERSION()' 2>/dev/null) · log_bin=$(variable_mysql log_bin 2>/dev/null) · trust_function_creators=$(variable_mysql log_bin_trust_function_creators 2>/dev/null)" "$(estado_area mysql)"
  printf '   %-24s %s  [%s]\n' "Rutas y nombres" "carpetas, sitio y estado del entorno" "$(estado_area rutas)"
  printf '   %-24s %s  [%s]\n' "Dependencias HTTP" "Apache (configtest, alias, rewrite, php, headers)" "$([ "$errores" -eq 0 ] && echo OK || echo "revise los FALLO de arriba")"
  printf '   %-24s %s  [%s]\n' "Paquete" "integridad SHA256SUMS" "$(estado_area paquete)"
  printf '   %-24s %s  [%s]\n' "Portales publicados" "escrituras y admin_escritura apagados en 443/8443" "$(estado_area interruptores)"
  printf '   %-24s %s\n' "Escrituras de la QA" "encendidas al instalar: $ESCRITURAS (el resto apagado) · modo admin_escritura · AUDIT_LOG_MODE=required · REACT_STRICT_VIEWS=1 · USER_ADMIN_WRITES ausente"

}
resumen_previo
if [ "$errores" -ne 0 ]; then
  echo "   RESULTADO: NO-GO ($errores comprobación(es) previas fallaron)"
  abortar "$errores comprobación(es) previas fallaron. No se modificó nada."
fi
echo "   RESULTADO: GO (todas las comprobaciones previas pasaron)"

cat <<PLAN

== Plan (nada se ha modificado todavía)
  - copia de seguridad de /etc/apache2 y huella de los demás portales, del servidor y de MySQL en $DIR_RESPALDO
  - carpetas nuevas con marca de instalación: $DIR_VAR (sesiones 700), $DIR_FRONT, $DIR_BACK ; configuración en $DIR_CONF y $SITE_CONF
  - bases nuevas $DB_NOMBRE (datos 100 % sintéticos, DDL productivo + claves UNIQUE productivas, migraciones UX-08B y UX-08B.2) y $DB_ESTADO (estado propio: lista blanca, sesiones, intentos)
  - migración UX-08B3 (grupo_id NULL): $([ "$MIGRACION_B3" -eq 1 ] && echo "SE APLICA (pedido con --aplicar-migracion-grupo-nullable)" || echo "NO se aplica (la QA arranca como producción, grupo_id NOT NULL)")
  - cuentas MySQL nuevas @$DB_HOST_USUARIO: $DB_USUARIO (ESCRITURA: plantilla UX-08H, SELECT/INSERT/UPDATE por columnas; sin DELETE, ALL PRIVILEGES, GRANT OPTION ni TRIGGER),
    $DB_USUARIO_AUTH y $DB_USUARIO_CAT (solo lectura por columnas: scripts 01 de piloto-admin), $DB_USUARIO_ESTADO (solo la base de estado: script 02)
  - modo del backend: CERVESUR_MODE=admin_escritura ; escrituras encendidas al arrancar: $ESCRITURAS (las demás y lo sensible, apagados; cámbielas con scripts/interruptor-escrituras.sh)
  - sitio Apache solo en 127.0.0.1:$PUERTO (UNA directiva «Listen 127.0.0.1:$PUERTO» dentro del sitio nuevo; no se edita ports.conf) ; recarga suave ;
    NO se habilitan ni deshabilitan módulos ; NO se reinicia Apache  [registrar un puerto nuevo en Apache es una operación que exige su aprobación expresa]
  - el puerto $PUERTO se registra en $PUERTO_REGISTRO: verificar.sh y revertir.sh usan EXACTAMENTE ese puerto
  - NO se toca ningún otro portal, ni la base $BASE_PRODUCTIVA, ni se concede nada sobre ellos ; NO se abre ningún puerto en la red
  - informe sintético que hace de DB031: reporte.id = $VISOR_IDS (el id real 72 es solo para el piloto real, tras aprobación expresa)

  ********************************************************************************************************
  * AVISO: este entorno es el ÚNICO con escrituras administrativas (modo admin_escritura) y es SOLO QA.   *
  * admin_escritura, USER_ADMIN_WRITES y ADMIN_WRITES_* deben seguir APAGADOS/ausentes en 443 y 8443.     *
  ********************************************************************************************************
PLAN
if [ "$SIMULAR" -eq 1 ]; then echo "Modo --simular: fin. No se modificó nada."; exit 0; fi
if [ "${CERVESUR_QAE_CONFIRMAR:-}" != "SI" ]; then
  read -r -p "Escriba INSTALAR-QA-ESCRITURA para continuar: " respuesta
  [ "$respuesta" = "INSTALAR-QA-ESCRITURA" ] || abortar "Cancelado. No se modificó nada."
fi
# Bloqueo GLOBAL (independiente de la carpeta del paquete): dos instalaciones o una instalación y una reversión jamás se solapan.
bloqueo_global || abortar "Hay otra instalación o reversión de este entorno en curso ($LOCK_GLOBAL). No se modificó nada."

echo "== Respaldo y línea base del servidor y de los demás portales"
install -d -m 700 "$DIR_RESPALDO"
tar czf "$DIR_RESPALDO/apache2-etc-$(date +%Y%m%d-%H%M%S).tgz" -C / etc/apache2 2>/dev/null
huella_servidor > "$DIR_RESPALDO/linea-base-portales.txt"
cp "$DIR_RESPALDO/linea-base-portales.txt" "$DIR_RESPALDO/linea-base-$(date +%Y%m%d-%H%M%S).txt"
ok "respaldo y línea base en $DIR_RESPALDO (permisos 700)"

# Las contraseñas sintéticas se escriben DENTRO de la carpeta de estado de la instalación (600): si el proceso muere, revertir.sh las elimina con ella.
TOKEN=$(openssl rand -hex 16)
CUENTAS=$DIR_VAR/cuentas-qa.tmp
pasos=("$PAQ/scripts/pasos-instalacion.sh" "$PAQ" "$CUENTAS")
[ "$MIGRACION_B3" -eq 1 ] && pasos+=(--con-grupo-nullable)
rc=0
CERVESUR_QAE_TOKEN=$TOKEN bash "${pasos[@]}" 8>&- || rc=$?   # 8>&-: el hijo no hereda el bloqueo
if [ "$rc" -eq 3 ]; then
  abortar "Ya existe $DIR_VAR (otra instalación en curso o restos): no se creó ni se revirtió nada de este intento."
elif [ "$rc" -ne 0 ]; then
  fallo "La instalación falló: se revierte automáticamente SOLO lo creado por este instalador (token de este intento)."
  CERVESUR_QAE_BLOQUEO_HEREDADO=1 CERVESUR_QAE_TOKEN_ESPERADO=$TOKEN CERVESUR_QAE_CONFIRMAR=SI bash "$PAQ/scripts/revertir.sh" 8>&- || true
  exit 1
fi

echo
echo "== Cuentas SINTÉTICAS de QA (se muestran UNA sola vez; en la base solo existe su hash. Guárdelas en un gestor de contraseñas)"
echo "   syn001 = administrador (rol admin, en la lista blanca del entorno) · syn005, syn006 = trabajadores (rol usuario; NO pueden entrar) · syn007 = trabajador (rol user, heredado; NO puede entrar)"
cat "$CUENTAS"
echo
CERVESUR_QAE_ADMIN_PASSWORD=$(awk '$2 == "syn001" { print $3 }' "$CUENTAS") \
CERVESUR_QAE_USUARIO_PASSWORD=$(awk '$2 == "syn005" { print $3 }' "$CUENTAS") \
CERVESUR_QAE_USER_PASSWORD=$(awk '$2 == "syn007" { print $3 }' "$CUENTAS") \
CERVESUR_QAE_VERIF_INICIAL=1 bash "$PAQ/scripts/verificar.sh" 8>&- || {
  rm -f "$CUENTAS"
  fallo "La verificación falló (NO-GO). El sitio sigue instalado para diagnóstico; para deshacerlo: sudo bash scripts/revertir.sh"; exit 1; }
rm -f "$CUENTAS"
echo
echo "Instalación completada. Acceso: ssh -N -L 18095:127.0.0.1:$PUERTO <usuario>@<servidor>  y abrir http://localhost:18095"
echo "RECORDATORIO: admin_escritura y las escrituras son exclusivas de este entorno de QA; NO los active en los portales 443/8443. Interruptores: sudo bash scripts/interruptor-escrituras.sh --estado"

