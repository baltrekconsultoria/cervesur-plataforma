#!/usr/bin/env bash
# Instala el PILOTO CON USUARIOS REALES (UX-07D.4B.1) en un sitio exclusivo, accesible SOLO por túnel SSH y SOLO por HTTPS (https://127.0.0.1:8092).
#   sudo bash scripts/instalar.sh --simular [--claves-archivo RUTA]                  preflights A y B de solo lectura, sin cambiar nada
#   sudo bash scripts/instalar.sh --claves-archivo RUTA [--informes-autorizados L] [--tls-cert CRT --tls-clave KEY]
#                                                                                   preflights + plan + confirmación escrita + instalación
# --informes-autorizados:  NINGUNO (predeterminado: el catálogo se ve, ningún visor entrega enlaces) · lista de ids «12,15,40» · TODOS.
#   Autorizar informes exige una SEGUNDA confirmación escrita: quien instala declara que ese contenido puede mostrarse a los usuarios del piloto.
# --tls-cert/--tls-clave: certificado emitido por una CA. Sin ellos se genera un certificado AUTOFIRMADO DE PRUEBA para localhost (solo para acceso privado por túnel).
# NO crea, modifica ni elimina NINGÚN usuario, base ni privilegio de MySQL: las tres cuentas y la base de estado deben existir ya (procedimiento admin-mysql/).
# NO modifica Flutter (80), 8088, 8089, 8090, 8091, los módulos de Apache (mod_ssl debe estar ya cargado) ni la configuración global.
# La lista blanca arranca VACÍA: nadie puede iniciar sesión hasta que se autorice a cada persona con scripts/usuarios.sh.
# Si un paso falla, revierte automáticamente SOLO lo que creó este instalador.
# Código de salida de --simular: 0 = infraestructura y acceso PASS · 3 = infraestructura PASS y acceso PENDIENTE (nunca un PASS ficticio) · 1 = fallo.
set -euo pipefail
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
SIMULAR=0
ARCHIVO_CLAVES=${CERVESUR_USUARIOS_CLAVES_FILE:-}
INFORMES=NINGUNO
TLS_CERT=""; TLS_CLAVE=""
while [ $# -gt 0 ]; do
  case $1 in
    --simular) SIMULAR=1; shift ;;
    --claves-archivo) ARCHIVO_CLAVES=${2:-}; shift 2 ;;
    --informes-autorizados) INFORMES=${2:-}; shift 2 ;;
    --tls-cert) TLS_CERT=${2:-}; shift 2 ;;
    --tls-clave) TLS_CLAVE=${2:-}; shift 2 ;;
    *) abortar "Argumento desconocido: $1" ;;
  esac
done
requerir_root
verificar_no_colision
[[ $INFORMES =~ ^(NINGUNO|TODOS|[1-9][0-9]{0,9}(,[1-9][0-9]{0,9}){0,499})$ ]] || abortar "--informes-autorizados debe ser NINGUNO, TODOS o una lista de ids numéricos separados por comas."
{ [ -z "$TLS_CERT" ] && [ -z "$TLS_CLAVE" ]; } || { [ -n "$TLS_CERT" ] && [ -n "$TLS_CLAVE" ]; } || abortar "--tls-cert y --tls-clave van juntos."

ARGS_CLAVE=(); [ -z "$ARCHIVO_CLAVES" ] || ARGS_CLAVE=(--claves-archivo "$ARCHIVO_CLAVES")
ARGS_TLS=(); [ -z "$TLS_CERT" ] || ARGS_TLS=(--tls-cert "$TLS_CERT" --tls-clave "$TLS_CLAVE")
rc_infra=0; bash "$PAQ/scripts/preflight-infra.sh" "${ARGS_TLS[@]}" || rc_infra=$?
rc_acceso=0; bash "$PAQ/scripts/preflight-acceso.sh" "${ARGS_CLAVE[@]}" || rc_acceso=$?

[ "$rc_infra" -eq 0 ] || abortar "El preflight de infraestructura falló. No se modificó nada."
if [ "$SIMULAR" -eq 1 ]; then
  echo
  echo "Modo --simular: fin. No se modificó nada."
  case $rc_acceso in
    0) echo "Resumen: infraestructura PASS · acceso PASS. Puede instalar."; exit 0 ;;
    3) echo "Resumen: infraestructura PASS · acceso PENDIENTE (las cuentas, la base de estado o sus claves aún no están disponibles). NO se puede instalar todavía."; exit 3 ;;
    *) echo "Resumen: infraestructura PASS · acceso FAIL." >&2; exit 1 ;;
  esac
fi
case $rc_acceso in
  0) ;;
  3) abortar "El preflight de acceso está PENDIENTE (faltan las cuentas, la base de estado o sus claves). No se instala sin comprobar el acceso. No se modificó nada." ;;
  *) abortar "El preflight de acceso falló. No se modificó nada." ;;
esac

cat <<PLAN

== Plan (nada se ha modificado todavía)
  - copia de seguridad de /etc/apache2 e inventario/huella de Flutter, 8088, 8089, 8090, 8091, sitios, puertos, módulos, bases y cuentas (solo metadatos) en $DIR_RESPALDO
  - carpetas nuevas: $DIR_FRONT (aplicación), $DIR_BACK (backend y .env), $DIR_VAR (sesiones PHP aisladas) ; configuración y TLS en $DIR_CONF ; sitio en $SITE_CONF
  - TLS: $( [ -n "$TLS_CERT" ] && echo "certificado propio (validado: vigencia, nombre, clave)" || echo "certificado AUTOFIRMADO DE PRUEBA para localhost (solo acceso privado por túnel)" ) ; la clave privada se queda en el servidor (root 600)
  - sitio Apache SOLO en 127.0.0.1:$PUERTO con TLS 1.2/1.3 ; HTTP sin cifrar rechazado por Apache ; cabeceras de protocolo del cliente eliminadas ; recarga suave ; NO se habilitan ni deshabilitan módulos ; NO se reinicia Apache
  - base de lectura $DB_NOMBRE con $DB_USUARIO_CATALOGO (21 columnas) y $DB_USUARIO_AUTH (9 columnas de user) ; estado en $DB_ESTADO con $DB_USUARIO_ESTADO (DML solo ahí) ; NO se crea ni modifica ninguna cuenta ni privilegio
  - secretos propios generados al azar (no se muestran) ; la lista blanca arranca VACÍA: nadie entra hasta que se autorice con scripts/usuarios.sh
  - informes con enlace autorizado para el visor: $INFORMES
  - NO se modifica Flutter, 8088, 8089, 8090 ni 8091 ; NO se abre ningún puerto en la red ; HTTP queda rechazado
PLAN
if [ "${CERVESUR_USUARIOS_CONFIRMAR:-}" != "SI" ]; then
  read -r -p "Escriba INSTALAR-8092 para continuar: " respuesta || respuesta=""
  [ "$respuesta" = "INSTALAR-8092" ] || abortar "Cancelado. No se modificó nada."
fi
if [ "$INFORMES" != "NINGUNO" ]; then
  # Visualizar enlaces REALES exige declarar que el contenido seleccionado está autorizado para este piloto.
  frase="AUTORIZO-INFORMES"; [ "$INFORMES" != "TODOS" ] || frase="AUTORIZO-TODOS-LOS-INFORMES"
  if [ "${CERVESUR_USUARIOS_CONFIRMAR_INFORMES:-}" != "$frase" ]; then
    echo "Los enlaces de «Publicar en web» son públicos para quien los conozca: el piloto los mostrará SOLO en: $INFORMES."
    read -r -p "Confirme que ese contenido está autorizado para este piloto escribiendo $frase: " respuesta || respuesta=""
    [ "$respuesta" = "$frase" ] || abortar "Cancelado. No se modificó nada."
  fi
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

if ! bash "$PAQ/scripts/pasos-instalacion.sh" "$PAQ" "$ARCHIVO_CLAVES" "$INFORMES" "$TLS_CERT" "$TLS_CLAVE"; then
  fallo "La instalación falló: se revierte automáticamente SOLO lo creado por este instalador."
  CERVESUR_USUARIOS_CONFIRMAR=SI bash "$PAQ/scripts/revertir.sh" || true
  exit 1
fi

echo
bash "$PAQ/scripts/verificar.sh" "${ARGS_CLAVE[@]}" ||
  { fallo "La verificación falló. El sitio sigue instalado para diagnóstico; para deshacerlo: sudo bash scripts/revertir.sh"; exit 1; }
echo
echo "Instalación del piloto completada. NADIE puede iniciar sesión todavía: la lista blanca está vacía."
echo "Autorice a cada persona con:  sudo bash scripts/usuarios.sh agregar <user_id> \"nota\""
echo "Acceso privado: ssh -N -L 18093:127.0.0.1:$PUERTO <usuario>@<servidor>  y abrir https://localhost:18093  (importe antes el certificado: sudo bash scripts/tls.sh publico)"
