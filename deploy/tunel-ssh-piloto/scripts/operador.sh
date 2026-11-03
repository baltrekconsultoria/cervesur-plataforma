#!/usr/bin/env bash
# Credenciales del OPERADOR del piloto visual (UX-07D.4). El operador no es un usuario del sistema: su clave nace al instalar, solo su hash bcrypt vive en el .env
# (640 root:www-data) y la clave en /opt/cervesur-react-piloto/operador.clave (root 600). Nada de esto va en el paquete, en URLs ni en logs.
#   sudo bash scripts/operador.sh --mostrar            imprime la clave UNA vez por la salida estándar (úselo en una terminal de confianza)
#   sudo bash scripts/operador.sh --rotar-clave        genera una clave nueva y un secreto JWT nuevo: la clave anterior y TODAS las sesiones dejan de valer al instante
#   sudo bash scripts/operador.sh --revocar-sesiones   rota solo el secreto JWT: cierra todas las sesiones abiertas (la clave sigue igual)
#   sudo bash scripts/operador.sh --desbloquear        borra el bloqueo por intentos fallidos (5 fallos = 15 min)
# Sin recarga de Apache: el backend lee el .env en cada petición. --mostrar y --rotar-clave piden confirmación escrita (o CERVESUR_PILOTO_CONFIRMAR=SI).
set -uo pipefail
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
requerir_root
[ -f "$ENV_FILE" ] || abortar "El piloto no está instalado ($ENV_FILE no existe)."
confirmar() { [ "${CERVESUR_PILOTO_CONFIRMAR:-}" = "SI" ] || { read -r -p "Escriba CLAVE-OPERADOR para continuar: " r; [ "$r" = "CLAVE-OPERADOR" ] || abortar "Cancelado."; }; }
case ${1:-} in
  --mostrar)
    confirmar
    c=$(head -n 1 "$CLAVE_OPERADOR_FILE" 2>/dev/null) || abortar "No existe la clave del operador."
    clave_operador_valida <<<"$c" || abortar "La clave del archivo no tiene el formato esperado."
    echo "$c" ;;
  --rotar-clave)
    confirmar
    nueva=$(generar_clave_operador); clave_operador_valida <<<"$nueva" || abortar "Clave generada no válida."
    hash=$(hash_bcrypt "$nueva"); [[ $hash == '$2y$'* ]] || abortar "No se pudo generar el hash."
    jwt=$(generar_secreto); secreto_valido <<<"$jwt" || abortar "Secreto generado no válido."
    reemplazar_env CERVESUR_PILOT_OPERATOR_HASH "$hash" && reemplazar_env JWT_SECRET "$jwt" || abortar "No se pudo actualizar el .env."
    ( umask 077; printf '%s\n' "$nueva" > "$CLAVE_OPERADOR_FILE.nuevo" ) && chown root:root "$CLAVE_OPERADOR_FILE.nuevo" && chmod 600 "$CLAVE_OPERADOR_FILE.nuevo" && mv -f "$CLAVE_OPERADOR_FILE.nuevo" "$CLAVE_OPERADOR_FILE"
    unset nueva hash jwt
    ok "clave y secreto JWT rotados: la clave anterior y todas las sesiones ya no valen. Recupere la nueva con --mostrar." ;;
  --revocar-sesiones)
    jwt=$(generar_secreto); secreto_valido <<<"$jwt" || abortar "Secreto generado no válido."
    reemplazar_env JWT_SECRET "$jwt" || abortar "No se pudo actualizar el .env."
    unset jwt
    ok "secreto JWT rotado: todas las sesiones abiertas quedaron cerradas" ;;
  --desbloquear)
    rm -f -- "$DIR_ESTADO/login-fallos.json"
    ok "bloqueo por intentos fallidos eliminado" ;;
  *) abortar "Uso: operador.sh --mostrar | --rotar-clave | --revocar-sesiones | --desbloquear" ;;
esac
