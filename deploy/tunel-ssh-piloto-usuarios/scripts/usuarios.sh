#!/usr/bin/env bash
# Operación del piloto con usuarios reales (UX-07D.4B.1): lista blanca, sesiones, bloqueos, auditoría e informes autorizados. Envuelve la CLI del backend, que escribe
# únicamente en la base de ESTADO propia; jamás en la base de Flutter. Ninguna salida contiene contraseñas, hashes, tokens ni enlaces.
#   sudo bash scripts/usuarios.sh listar | sesiones | auditoria
#   sudo bash scripts/usuarios.sh agregar <user_id> ["nota"]      autoriza a un usuario EXISTENTE, activo y de rol «user» (los administradores no entran)
#   sudo bash scripts/usuarios.sh quitar <user_id>                 lo saca de la lista y revoca sus sesiones al instante
#   sudo bash scripts/usuarios.sh revocar <user_id>|todos          cierra las sesiones de una persona o de todas
#   sudo bash scripts/usuarios.sh desbloquear <usuario|correo>     levanta el bloqueo por intentos fallidos de ese acceso
#   sudo bash scripts/usuarios.sh informes --listar | --autorizar <ids|TODOS> | --agregar <ids> | --quitar <ids> | --retirar      informes cuyo ENLACE puede entregar el visor
#     (--autorizar reemplaza la lista; --agregar/--quitar la modifican de a uno o varios; toda modificación queda en /var/backups/<sitio>/autorizaciones-informes.log: fecha, quién, acción e ids; nunca enlaces)
# Los enlaces de «Publicar en web» son públicos para quien los conozca: retirar una autorización impide que el portal vuelva a entregarlo, pero NO invalida un enlace ya conocido.
set -uo pipefail
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
requerir_root
[ -f "$ENV_FILE" ] || abortar "El piloto no está instalado ($ENV_FILE no existe)."
CLI="$DIR_BACKEND/cli/piloto-usuarios.php"
[ -f "$CLI" ] || abortar "No se encuentra la CLI del backend ($CLI)."

# Bitácora de autorizaciones de enlaces (UX-07E.C): quién cambió qué y cuándo. Solo ids y el cambio de la lista; NUNCA enlaces. Archivo de root (600) en la carpeta de respaldos del sitio.
registrar_autorizacion() { # accion ids antes despues
  install -d -m 700 "$DIR_RESPALDO" 2>/dev/null || true
  printf '%s	%s	%s	ids=%s	antes=%s	despues=%s
' "$(date -u +%FT%TZ)" "${SUDO_USER:-${USER:-root}}" "$1" "$2" "$3" "$4" >> "$DIR_RESPALDO/autorizaciones-informes.log" 2>/dev/null && chmod 600 "$DIR_RESPALDO/autorizaciones-informes.log" 2>/dev/null || true
}

case ${1:-} in
  listar|sesiones|auditoria) php "$CLI" "$1" ;;
  agregar|quitar|revocar|desbloquear) [ $# -ge 2 ] || abortar "Falta el argumento."; php "$CLI" "$@" ;;
  informes)
    actual=$(valor_env CERVESUR_PILOT_VISOR_IDS); [ -n "$actual" ] || actual=NINGUNO
    case ${2:-} in
      --listar) echo "Informes con enlace autorizado en el visor: $actual" ;;
      --agregar|--quitar)
        [ "$actual" != "TODOS" ] || abortar "Ya están autorizados TODOS los informes: use --autorizar con una lista o --retirar."
        [[ ${3:-} =~ ^[1-9][0-9]{0,9}(,[1-9][0-9]{0,9}){0,499}$ ]] || abortar "Indique una lista de ids numéricos separados por comas (p. ej. 12,15)."
        previos=""; [ "$actual" = "NINGUNO" ] || previos=$actual
        if [ "${2}" = "--agregar" ]; then nuevos=$(printf '%s
' "${previos//,/$'
'}" "${3//,/$'
'}" | grep -E '^[1-9][0-9]*$' | sort -un | paste -sd, -)
        else nuevos=$(comm -23 <(printf '%s
' "${previos//,/$'
'}" | grep -E '^[1-9][0-9]*$' | sort -u) <(printf '%s
' "${3//,/$'
'}" | sort -u) | sort -n | paste -sd, -); fi
        frase="AUTORIZO-INFORMES"
        if [ "${2}" = "--agregar" ] && [ "${CERVESUR_USUARIOS_CONFIRMAR_INFORMES:-}" != "$frase" ]; then
          echo "El piloto mostrará los enlaces públicos de: ${3}"
          read -r -p "Confirme que ese contenido está autorizado para este piloto escribiendo $frase: " r
          [ "$r" = "$frase" ] || abortar "Cancelado. No se modificó nada."
        fi
        reemplazar_env CERVESUR_PILOT_VISOR_IDS "$nuevos" || abortar "No se pudo actualizar el .env."
        registrar_autorizacion "${2#--}" "${3}" "$actual" "${nuevos:-NINGUNO}"
        ok "autorizados ahora: ${nuevos:-NINGUNO}" ;;
      --autorizar)
        lista=${3:-}
        [[ $lista =~ ^(TODOS|[1-9][0-9]{0,9}(,[1-9][0-9]{0,9}){0,499})$ ]] || abortar "Indique TODOS o una lista de ids numéricos separados por comas (p. ej. 12,15,40)."
        frase="AUTORIZO-INFORMES"; [ "$lista" != "TODOS" ] || frase="AUTORIZO-TODOS-LOS-INFORMES"
        if [ "${CERVESUR_USUARIOS_CONFIRMAR_INFORMES:-}" != "$frase" ]; then
          echo "El piloto mostrará los enlaces públicos de: $lista"
          read -r -p "Confirme que ese contenido está autorizado para este piloto escribiendo $frase: " r
          [ "$r" = "$frase" ] || abortar "Cancelado. No se modificó nada."
        fi
        reemplazar_env CERVESUR_PILOT_VISOR_IDS "$lista" || abortar "No se pudo actualizar el .env."
        registrar_autorizacion autorizar "$lista" "$actual" "$lista"
        ok "autorizados: $lista" ;;
      --retirar)
        reemplazar_env CERVESUR_PILOT_VISOR_IDS "" || abortar "No se pudo actualizar el .env."
        registrar_autorizacion retirar "-" "$actual" "NINGUNO"
        ok "ningún informe autorizado: el visor deja de entregar enlaces" ;;
      *) abortar "Uso: usuarios.sh informes --listar | --autorizar <ids|TODOS> | --agregar <ids> | --quitar <ids> | --retirar" ;;
    esac ;;
  *) abortar "Uso: usuarios.sh listar | sesiones | auditoria | agregar <id> [nota] | quitar <id> | revocar <id|todos> | desbloquear <login> | informes ..." ;;
esac
