#!/usr/bin/env bash
# Interruptores de ESCRITURA del entorno de QA «admin_escritura» (UX-08H). Cambia SOLO el .env de ESTA instancia; no toca Apache, MySQL ni ningún otro portal.
#   sudo bash scripts/interruptor-escrituras.sh --estado
#   sudo bash scripts/interruptor-escrituras.sh --encender empresas|reportes|accesos|usuarios|usuarios-sensible [...]
#   sudo bash scripts/interruptor-escrituras.sh --apagar   empresas|reportes|accesos|usuarios|usuarios-sensible|todo [...]
#   sudo bash scripts/interruptor-escrituras.sh --horas N          (ADMIN_USER_REACTIVATE_HOURS, entero de 0 a 720)
# Reglas del código (backend/lib/admin_escritura.php): solo el valor exacto «1» enciende; «usuarios-sensible» (cambio de rol y de contraseña) exige «usuarios» encendido
# (si no, el backend rehúsa servir con 503); por eso apagar «usuarios» apaga también «usuarios-sensible». No hace falta recargar Apache: mod_php vuelve a leer el .env en CADA petición.
# Tras el cambio comprueba que /api/ping sigue respondiendo (configuración válida); si no, restaura el .env anterior. No imprime ningún valor secreto del .env.
set -uo pipefail
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
requerir_root
validar_puerto
[ -f "$ENV_FILE" ] || abortar "No hay una instalación de este entorno ($ENV_FILE no existe)."
[ "$(valor_env CERVESUR_MODE)" = admin_escritura ] || abortar "El .env instalado no está en modo admin_escritura: no se toca."

clave_de() { case "$1" in empresas) echo ADMIN_WRITES_EMPRESAS ;; reportes) echo ADMIN_WRITES_REPORTES ;; accesos) echo ADMIN_WRITES_ACCESOS ;; usuarios) echo ADMIN_WRITES_USUARIOS ;; usuarios-sensible) echo ADMIN_WRITES_USUARIOS_SENSIBLE ;; *) return 1 ;; esac; }
encendida() { [ "$(valor_env "$(clave_de "$1")")" = 1 ]; }
mostrar_estado() {
  local c
  printf '%-22s %-12s\n' INTERRUPTOR ESTADO
  for c in empresas reportes accesos usuarios; do printf '%-22s %-12s\n' "$c" "$(encendida "$c" && echo ENCENDIDO || echo apagado)"; done
  printf '%-22s %-12s\n' usuarios-sensible "$(encendida usuarios-sensible && encendida usuarios && echo ENCENDIDO || echo apagado)"
  printf '%-22s %-12s\n' "horas-reactivacion" "$(valor_env ADMIN_USER_REACTIVATE_HOURS || true)"
}

declare -A NUEVO=()   # clave del .env -> valor deseado
OPS=()
[ "$#" -gt 0 ] || abortar "Indique --estado, --encender, --apagar o --horas (ver la cabecera del script)."
accion=''
while [ "$#" -gt 0 ]; do
  case "$1" in
    --estado) mostrar_estado; exit 0 ;;
    --encender|--apagar) accion=$1 ;;
    --horas)
      [ "$#" -ge 2 ] && [[ $2 =~ ^[0-9]{1,3}$ ]] && [ "$((10#$2))" -le 720 ] || abortar "--horas necesita un entero de 0 a 720."
      NUEVO[ADMIN_USER_REACTIVATE_HOURS]=$((10#$2)); OPS+=("horas=$((10#$2))"); shift; accion='' ;;
    todo) [ "$accion" = --apagar ] || abortar "«todo» solo vale con --apagar."
      for c in empresas reportes accesos usuarios usuarios-sensible; do NUEVO[$(clave_de "$c")]=0; OPS+=("$c=0"); done ;;
    *)
      [ -n "$accion" ] || abortar "Argumento no esperado: $1"
      k=$(clave_de "$1") || abortar "Interruptor desconocido: «$1» (empresas, reportes, accesos, usuarios, usuarios-sensible)."
      if [ "$accion" = --encender ]; then NUEVO[$k]=1; OPS+=("$1=1"); else NUEVO[$k]=0; OPS+=("$1=0"); fi ;;
  esac
  shift
done
[ "${#NUEVO[@]}" -gt 0 ] || abortar "No se indicó ningún interruptor."

# Reglas de coherencia con el estado resultante (el que quedaría en el .env).
resultado() { local k=$1; if [ -n "${NUEVO[$k]+x}" ]; then echo "${NUEVO[$k]}"; else valor_env "$k"; fi; }
if [ "$(resultado ADMIN_WRITES_USUARIOS)" != 1 ] && [ "$(resultado ADMIN_WRITES_USUARIOS_SENSIBLE)" = 1 ]; then
  if [ "${NUEVO[ADMIN_WRITES_USUARIOS]:-}" = 0 ]; then NUEVO[ADMIN_WRITES_USUARIOS_SENSIBLE]=0; aviso "usuarios queda apagado: usuarios-sensible se apaga también (el backend exige usuarios=1 para lo sensible)."
  else abortar "usuarios-sensible exige usuarios encendido. No se cambió nada."; fi
fi
bloquear_ejecucion "${BASH_SOURCE[0]}"

# Escritura atómica: .env nuevo en la misma carpeta (root:www-data 640) y renombrado; el anterior se guarda (root, 600) para restaurarlo si la configuración resulta inválida.
previo=$DIR_VAR/.env.previo
cp -p "$ENV_FILE" "$previo" && chmod 600 "$previo" || abortar "No se pudo guardar el .env anterior: no se cambió nada."
nuevo=$(mktemp "$DIR_BACK/backend/.env.XXXXXX") || abortar "No se pudo crear el .env nuevo: no se cambió nada."
trap 'rm -f "$nuevo"' EXIT
pares=''
for k in "${!NUEVO[@]}"; do pares="$pares$k=${NUEVO[$k]}"$'\n'; done
PARES="$pares" awk '
  BEGIN { n = split(ENVIRON["PARES"], p, "\n"); for (i = 1; i <= n; i++) if (p[i] != "") { pos = index(p[i], "="); mapa[substr(p[i], 1, pos - 1)] = substr(p[i], pos + 1) } }
  { linea = $0; sub(/\r$/, "", linea); pos = index(linea, "="); nombre = ""
    if (pos) { nombre = substr(linea, 1, pos - 1); gsub(/^[ \t]+|[ \t]+$/, "", nombre) }
    if (pos && substr(nombre, 1, 1) != "#" && (nombre in mapa)) next
    print $0 }
  END { for (k in mapa) print k "=" mapa[k] }' "$ENV_FILE" > "$nuevo" || abortar "No se pudo preparar el .env nuevo: no se cambió nada."
chown root:www-data "$nuevo" && chmod 640 "$nuevo" && mv -f "$nuevo" "$ENV_FILE" || abortar "No se pudo instalar el .env nuevo: no se cambió nada."

respuesta=$(curl -sS -m 10 "http://127.0.0.1:$PUERTO/api/ping" 2>/dev/null || true)
if ! grep -q '"pong":true' <<<"$respuesta"; then
  cp -p "$previo" "$ENV_FILE" && chown root:www-data "$ENV_FILE" && chmod 640 "$ENV_FILE"
  fallo "Con la configuración nueva /api/ping no responde (503 de configuración): se restauró el .env anterior."
  exit 1
fi
rm -f "$previo"
printf '%s %s\n' "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "${OPS[*]}" >> "$DIR_VAR/interruptor.log"
ok "cambios aplicados en el .env (${OPS[*]}); mod_php los lee en la siguiente petición (sin recargar Apache)"
mostrar_estado
