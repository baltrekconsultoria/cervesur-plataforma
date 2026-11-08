#!/usr/bin/env bash
# Instala el DIAGNÓSTICO AGREGADO DE SOLO LECTURA (UX-07D.3B) en un sitio exclusivo, accesible SOLO por túnel SSH (127.0.0.1:8090).
#   sudo bash scripts/instalar.sh --simular [--clave-archivo RUTA]   preflights A y B de solo lectura, sin cambiar nada
#   sudo bash scripts/instalar.sh --clave-archivo RUTA               preflights + plan + confirmación escrita + instalación
# NO crea, modifica ni elimina NINGÚN usuario, base ni privilegio de MySQL: la cuenta cervesur_ro_diag debe existir ya (procedimiento admin-mysql/).
# NO modifica Flutter (80), el React de prueba (8088), el de compatibilidad (8089), los módulos de Apache ni la configuración global.
# Si un paso falla, revierte automáticamente SOLO lo que creó este instalador.
# Código de salida de --simular: 0 = infraestructura y acceso PASS · 3 = infraestructura PASS y acceso PENDIENTE (nunca un PASS ficticio) · 1 = fallo.
set -euo pipefail
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
SIMULAR=0
ARCHIVO_CLAVE=${CERVESUR_RO_DIAG_PASS_FILE:-}
while [ $# -gt 0 ]; do
  case $1 in
    --simular) SIMULAR=1; shift ;;
    --clave-archivo) ARCHIVO_CLAVE=${2:-}; shift 2 ;;
    *) abortar "Argumento desconocido: $1" ;;
  esac
done
requerir_root
verificar_no_colision

ARGS_CLAVE=(); [ -z "$ARCHIVO_CLAVE" ] || ARGS_CLAVE=(--clave-archivo "$ARCHIVO_CLAVE")
rc_infra=0; bash "$PAQ/scripts/preflight-infra.sh" || rc_infra=$?
rc_acceso=0; bash "$PAQ/scripts/preflight-acceso.sh" "${ARGS_CLAVE[@]}" || rc_acceso=$?

[ "$rc_infra" -eq 0 ] || abortar "El preflight de infraestructura falló. No se modificó nada."
if [ "$SIMULAR" -eq 1 ]; then
  echo
  echo "Modo --simular: fin. No se modificó nada."
  case $rc_acceso in
    0) echo "Resumen: infraestructura PASS · acceso PASS. Puede instalar."; exit 0 ;;
    3) echo "Resumen: infraestructura PASS · acceso PENDIENTE (la cuenta o su clave aún no están disponibles). NO se puede instalar todavía."; exit 3 ;;
    *) echo "Resumen: infraestructura PASS · acceso FAIL." >&2; exit 1 ;;
  esac
fi
case $rc_acceso in
  0) ;;
  3) abortar "El preflight de acceso está PENDIENTE (falta la cuenta $DB_USUARIO o su clave). No se instala sin comprobar el acceso. No se modificó nada." ;;
  *) abortar "El preflight de acceso falló. No se modificó nada." ;;
esac

cat <<PLAN

== Plan (nada se ha modificado todavía)
  - copia de seguridad de /etc/apache2 e inventario/huella de Flutter, 8088, 8089, sitios, puertos, módulos, bases y cuentas (solo metadatos) en $DIR_RESPALDO
  - carpetas nuevas: $DIR_BACK (backend y .env), $DIR_VAR (docroot vacío y sesiones) ; configuración en $DIR_CONF y $SITE_CONF
  - token de diagnóstico NUEVO de 256 bits, guardado solo en $ENV_FILE (640 root:www-data); no se imprime
  - sitio Apache solo en 127.0.0.1:$PUERTO ; recarga suave ; NO se habilitan ni deshabilitan módulos ; NO se reinicia Apache
  - base de lectura $DB_NOMBRE con la cuenta $DB_USUARIO (solo SELECT por columna) ; NO se crea ni modifica ninguna cuenta ni privilegio de MySQL
  - NO se modifica Flutter, 8088 ni 8089 ; NO se abre ningún puerto en la red ; NO hay inicio de sesión de usuarios
PLAN
if [ "${CERVESUR_RO_CONFIRMAR:-}" != "SI" ]; then
  read -r -p "Escriba INSTALAR-8090 para continuar: " respuesta
  [ "$respuesta" = "INSTALAR-8090" ] || abortar "Cancelado. No se modificó nada."
fi

echo "== Respaldo, inventario y línea base"
install -d -m 700 "$DIR_RESPALDO"
sello=$(date +%Y%m%d-%H%M%S)
tar czf "$DIR_RESPALDO/apache2-etc-$sello.tgz" -C / etc/apache2 2>/dev/null
huella_portales > "$DIR_RESPALDO/linea-base-portales.txt"
{ echo "# sitios y puertos"; apache2ctl -S 2>&1 | grep -vi 'warning' || true; ss -ltnH 2>/dev/null | awk '{print $4}' | LC_ALL=C sort -u; echo "# módulos de Apache"; apache2ctl -M 2>/dev/null | LC_ALL=C sort; } > "$DIR_RESPALDO/inventario-apache-$sello.txt"
# shellcheck disable=SC2086
{ echo "# bases (metadatos)"; $MYSQL_ADMIN -N -e 'SHOW DATABASES' 2>/dev/null || true; echo "# cuentas (usuario, host)"; $MYSQL_ADMIN -N -e 'SELECT user, host FROM mysql.user' 2>/dev/null || true; } > "$DIR_RESPALDO/inventario-mysql-$sello.txt"
chmod 600 "$DIR_RESPALDO"/*
ok "respaldo e inventarios en $DIR_RESPALDO (permisos 700; los respaldos no se borran jamás)"

if ! bash "$PAQ/scripts/pasos-instalacion.sh" "$PAQ" "$ARCHIVO_CLAVE"; then
  fallo "La instalación falló: se revierte automáticamente SOLO lo creado por este instalador."
  CERVESUR_RO_CONFIRMAR=SI bash "$PAQ/scripts/revertir.sh" || true
  exit 1
fi

echo
bash "$PAQ/scripts/verificar.sh" ||
  { fallo "La verificación falló. El sitio sigue instalado para diagnóstico; para deshacerlo: sudo bash scripts/revertir.sh"; exit 1; }
echo
echo "Instalación del diagnóstico completada. El token NO se ha mostrado: recupérelo con  sudo bash scripts/token.sh --mostrar"
echo "Acceso: ssh -N -L 18091:127.0.0.1:$PUERTO <usuario>@<servidor>  y consultar http://localhost:18091/api/diagnostico/resumen con la cabecera X-Diagnostic-Token"
