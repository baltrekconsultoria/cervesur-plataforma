#!/usr/bin/env bash
# Informes cuyo ENLACE puede entregar el visor del piloto (UX-07D.4). Los enlaces de «Publicar en web» son públicos para quien los conozca: el portal solo
# controla su descubrimiento. Por eso el visor entrega el enlace ÚNICAMENTE de los informes que el operador autorizó expresamente para este piloto.
#   sudo bash scripts/informes.sh --listar                 muestra la autorización vigente (NINGUNO, TODOS o la lista de ids)
#   sudo bash scripts/informes.sh --autorizar 12,15,40     autoriza esos ids (pide escribir AUTORIZO-INFORMES)
#   sudo bash scripts/informes.sh --autorizar TODOS        autoriza todos los informes activos (pide escribir AUTORIZO-TODOS-LOS-INFORMES)
#   sudo bash scripts/informes.sh --retirar                no autoriza ninguno (el catálogo se sigue viendo; ningún visor entrega enlaces)
# Retirar una autorización impide que el portal vuelva a entregar el enlace, pero NO invalida un enlace que alguien ya conozca: la revocación efectiva de un
# enlace público exige eliminarlo en Power BI (afecta también a Flutter). Sin recarga de Apache. Se puede automatizar con CERVESUR_PILOTO_CONFIRMAR_INFORMES=<frase>.
set -uo pipefail
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
requerir_root
[ -f "$ENV_FILE" ] || abortar "El piloto no está instalado ($ENV_FILE no existe)."
actual=$(valor_env CERVESUR_PILOT_VISOR_IDS); [ -n "$actual" ] || actual=NINGUNO
case ${1:-} in
  --listar) echo "Informes con enlace autorizado en el visor: $actual" ;;
  --autorizar)
    lista=${2:-}
    [[ $lista =~ ^(TODOS|[1-9][0-9]{0,9}(,[1-9][0-9]{0,9}){0,499})$ ]] || abortar "Indique TODOS o una lista de ids numéricos separados por comas (p. ej. 12,15,40)."
    frase="AUTORIZO-INFORMES"; [ "$lista" != "TODOS" ] || frase="AUTORIZO-TODOS-LOS-INFORMES"
    if [ "${CERVESUR_PILOTO_CONFIRMAR_INFORMES:-}" != "$frase" ]; then
      echo "El piloto mostrará los enlaces públicos de: $lista"
      read -r -p "Confirme que ese contenido está autorizado para este piloto escribiendo $frase: " r
      [ "$r" = "$frase" ] || abortar "Cancelado. No se modificó nada."
    fi
    reemplazar_env CERVESUR_PILOT_VISOR_IDS "$lista" || abortar "No se pudo actualizar el .env."
    ok "autorizados: $lista" ;;
  --retirar)
    reemplazar_env CERVESUR_PILOT_VISOR_IDS "" || abortar "No se pudo actualizar el .env."
    ok "ningún informe autorizado: el visor deja de entregar enlaces" ;;
  *) abortar "Uso: informes.sh --listar | --autorizar <ids|TODOS> | --retirar" ;;
esac
