#!/usr/bin/env bash
# Recuperación y rotación del token de diagnóstico (UX-07D.3B). El token vive SOLO en el .env del sitio (640 root:www-data); jamás en el paquete, URLs ni logs.
#   sudo bash scripts/token.sh --mostrar   imprime el token UNA vez, por la salida estándar (úselo solo en una terminal de confianza)
#   sudo bash scripts/token.sh --rotar     genera uno nuevo, lo sustituye de forma atómica y lo imprime una vez; el anterior deja de valer al instante
# Sin recarga de Apache: el backend lee el .env en cada petición. Pide confirmación escrita (o CERVESUR_RO_CONFIRMAR=SI).
set -uo pipefail
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
requerir_root
[ -f "$ENV_FILE" ] || abortar "El diagnóstico no está instalado ($ENV_FILE no existe)."
accion=${1:-}
confirmar() { [ "${CERVESUR_RO_CONFIRMAR:-}" = "SI" ] || { read -r -p "Se mostrará un secreto por pantalla. Escriba MOSTRAR-TOKEN para continuar: " r; [ "$r" = "MOSTRAR-TOKEN" ] || abortar "Cancelado."; }; }
case $accion in
  --mostrar)
    confirmar
    t=$(valor_env CERVESUR_DIAG_TOKEN); token_valido <<<"$t" || abortar "El token del .env no tiene el formato esperado."
    echo "$t" ;;
  --rotar)
    confirmar
    nuevo=$(generar_token); token_valido <<<"$nuevo" || abortar "Token generado no válido."
    tmp=$(mktemp "$DIR_BACKEND/.env.nuevo.XXXXXX") || abortar "No se pudo crear el archivo temporal."
    chmod 600 "$tmp"
    while IFS= read -r linea; do
      case $linea in CERVESUR_DIAG_TOKEN=*) printf 'CERVESUR_DIAG_TOKEN=%s\n' "$nuevo" ;; *) printf '%s\n' "$linea" ;; esac
    done < "$ENV_FILE" > "$tmp"
    chown root:www-data "$tmp"; chmod 640 "$tmp"
    mv -f "$tmp" "$ENV_FILE"
    ok "token rotado; el anterior ya no es válido" >&2
    echo "$nuevo"; unset nuevo ;;
  *) abortar "Uso: token.sh --mostrar | --rotar" ;;
esac
