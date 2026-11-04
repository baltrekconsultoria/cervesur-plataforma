#!/usr/bin/env bash
# Cervesur · UX-08H.1 · REVERTIR EL CÓDIGO del administrador a la FASE 1 (restaura public/ y backend/ de un respaldo verificado de actualizar.sh). Reversible: no borra nada.
#   sudo bash scripts/revertir-codigo.sh --simular
#   sudo bash scripts/revertir-codigo.sh [--respaldo CARPETA] [--env-respaldo ARCHIVO] [--sin-credenciales]
# TRES CLASES DE RECUPERACIÓN (distintas; este script es SOLO la 1):
#   1. RESTAURAR CÓDIGO (este script): vuelve el administrador a la fase 1 (3f859210c5c4). Si el .env está en admin_escritura, restaura TAMBIÉN el .env previo a A2 (modo piloto_admin),
#      porque el código viejo no conoce ese modo y se negaría a arrancar (503); si no hay un respaldo de ese .env válido (env/MANIFIESTO.txt con SHA-256) y en modo piloto_admin, SE NIEGA
#      y explica qué hacer. Verifica después el comportamiento de la fase 1 (verificar-fase1.sh). No toca MySQL, audit_log, Flutter (80), trabajadores (443) ni QA (8094).
#   2. APAGAR ESCRITURAS (segundos, sin tocar código ni datos): apagar-escrituras.sh o interruptores.sh --apagar-todo. Es lo primero ante cualquier duda.
#   3. RECUPERAR DATOS: procedimiento APARTE y MANUAL, nunca automático (respaldo de las tablas de negocio + audit_log, que dice qué cambió, quién y antes/después sin secretos; los .down.sql de las
#      migraciones solo con autorización expresa y segunda confirmación: audit_log es evidencia). Las bajas son lógicas (se deshacen reactivando).
# Este script NO es el revertir.sh del piloto (que es un desinstalador): aquí nunca se desinstala nada. Mantiene todos los respaldos, incluido el de lo que reemplaza.
# El respaldo se elige con --respaldo o, por defecto, el más reciente actualizacion-* creado por actualizar.sh (INFO.txt «fase A1») cuyo SHA256SUMS coincide.
set -uo pipefail
case ${1:-} in --ayuda|-h|--help) sed -n '2,/^set -/{/^set -/!s/^# \{0,1\}//p}' "${BASH_SOURCE[0]}"; exit 0 ;; esac
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
requerir_root

MODO=real; RESPALDO_ORIGEN=""; ENV_RESPALDO=""; SIN_CRED=0
while [ $# -gt 0 ]; do
  case $1 in
    --simular) MODO=simular; shift ;;
    --respaldo) RESPALDO_ORIGEN=${2:-}; [ -n "$RESPALDO_ORIGEN" ] || abortar "--respaldo necesita la carpeta."; shift 2 ;;
    --env-respaldo) ENV_RESPALDO=${2:-}; [ -n "$ENV_RESPALDO" ] || abortar "--env-respaldo necesita el archivo."; shift 2 ;;
    --sin-credenciales) SIN_CRED=1; shift ;;
    *) abortar "Argumento desconocido: $1 (use --ayuda)" ;;
  esac
done

sello=$(sello_utc)
PREV_PUBLIC="$DIR_FRONT/.previo-public-$sello"; PREV_BACKEND="$DIR_BACK/.previo-backend-$sello"
MANIFIESTO_BASE="$PAQ/base/MANIFIESTO-FASE1-$BASE_FASE1_COMMIT.sha256"
intercambiar() { mv "$1" "$3" && mv "$2" "$1" || { [ -d "$1" ] || mv "$3" "$1"; return 1; }; }
identidad_con() { # manifiesto
  local m=$1 lb lp
  lb=$(grep -E '^[0-9a-f]{64} [ *](./)?backend/' "$m") && lp=$(grep -E '^[0-9a-f]{64} [ *](./)?public/' "$m") || return 1
  (cd "$DIR_BACK" && sha256sum -c --quiet <<<"$lb") >/dev/null 2>&1 && (cd "$DIR_FRONT" && sha256sum -c --quiet <<<"$lp") >/dev/null 2>&1 || return 1
  [ "$(find "$DIR_BACKEND" -type f ! -path "$ENV_FILE" | wc -l)" = "$(wc -l <<<"$lb")" ] && [ "$(find "$DIR_PUBLIC" -type f | wc -l)" = "$(wc -l <<<"$lp")" ]
}
[ -d "$DIR_PUBLIC" ] && [ -d "$DIR_BACKEND" ] && [ -f "$ENV_FILE" ] || abortar "El administrador no está instalado."
[ -f "$MANIFIESTO_BASE" ] || abortar "Falta $MANIFIESTO_BASE en el paquete."

# ---- Elegir y validar el respaldo de código
if [ -z "$RESPALDO_ORIGEN" ]; then
  for d in $(ls -1d "$DIR_RESPALDO"/actualizacion-* 2>/dev/null | LC_ALL=C sort -r); do
    if grep -q '^fase A1' "$d/INFO.txt" 2>/dev/null; then RESPALDO_ORIGEN=$d; break; fi
  done
  [ -n "$RESPALDO_ORIGEN" ] || abortar "No hay ningún respaldo actualizacion-* creado por actualizar.sh (INFO.txt «fase A1») en $DIR_RESPALDO. Indique --respaldo."
fi
[ -d "$RESPALDO_ORIGEN" ] && [ -f "$RESPALDO_ORIGEN/public.tgz" ] && [ -f "$RESPALDO_ORIGEN/backend.tgz" ] && [ -f "$RESPALDO_ORIGEN/SHA256SUMS" ] || abortar "El respaldo indicado no es válido."
grep -q '^fase A1' "$RESPALDO_ORIGEN/INFO.txt" 2>/dev/null || abortar "El respaldo no fue creado por actualizar.sh de la fase 2A (falta «fase A1» en INFO.txt): no se usa."
[ "$(wc -l < "$RESPALDO_ORIGEN/SHA256SUMS")" = 2 ] && (cd "$RESPALDO_ORIGEN" && sha256sum -c SHA256SUMS --quiet) >/dev/null 2>&1 || abortar "El respaldo está corrupto (SHA256SUMS): no se usa."
ok "respaldo de código verificado: $RESPALDO_ORIGEN"

# ---- .env: ¿el código viejo puede arrancar con el .env actual?
ENV_A_USAR=""   # vacío = se conserva el .env actual
modo_actual=$(modo_env)
if [ "$modo_actual" != piloto_admin ]; then
  if [ -z "$ENV_RESPALDO" ] && [ -f "$DIR_ENV_RESPALDO/ultimo-A2-previo" ]; then ENV_RESPALDO="$DIR_ENV_RESPALDO/$(head -1 "$DIR_ENV_RESPALDO/ultimo-A2-previo")"; fi
  if [ -z "$ENV_RESPALDO" ] || ! env_respaldo_valido "$ENV_RESPALDO" || [ "$(env_valor_de "$ENV_RESPALDO" CERVESUR_MODE)" != piloto_admin ]; then
    fallo "El .env está en modo «$(printf '%s' "$modo_actual" | cut -c1-20)» y el código de la fase 1 no lo conoce: se negaría a arrancar (503)."
    fallo "No hay un respaldo de .env VÁLIDO en modo piloto_admin (se busca el que dejó modo-escritura.sh en $DIR_ENV_RESPALDO/ultimo-A2-previo)."
    abortar "No se modificó nada. Indique --env-respaldo ARCHIVO (un env-* listado en $DIR_ENV_RESPALDO/MANIFIESTO.txt con modo piloto_admin) o apague antes con apagar-escrituras.sh y restaure a mano."
  fi
  ENV_A_USAR=$ENV_RESPALDO
  ok ".env: se restaurará el respaldo $(basename "$ENV_A_USAR") (modo piloto_admin); el actual quedará respaldado"
else ok ".env: ya está en piloto_admin; se conserva tal cual"; fi

echo "== Qué se restauraría"
echo "   $DIR_PUBLIC   ← $RESPALDO_ORIGEN/public.tgz"
if [ -n "$ENV_A_USAR" ]; then texto_env="respaldo piloto_admin"; else texto_env="el actual"; fi
echo "   $DIR_BACKEND  ← $RESPALDO_ORIGEN/backend.tgz   (+ .env: $texto_env)"
if [ "$MODO" = simular ]; then ok "SIMULACIÓN terminada: no se escribió nada."; exit 0; fi
if [ "${CERVESUR_CONFIRMAR_REVERTIR:-}" != "SI" ]; then
  read -r -p "Escriba REVERTIR-CODIGO-FASE2A para restaurar la fase 1: " c; [ "$c" = "REVERTIR-CODIGO-FASE2A" ] || abortar "Cancelado. No se modificó nada."
fi

tmp_otros=$(mktemp) || abortar "No se pudo crear un archivo temporal."
tmp=$(mktemp -d "$DIR_FRONT/.restaurar-XXXXXX") || abortar "No se pudo crear la carpeta temporal de restauración: no se modificó nada."
trap 'rm -rf "$tmp"; rm -f "$tmp_otros"; api_limpiar' EXIT
bash "$PAQ/scripts/comprobar-otros-portales.sh" --guardar "$tmp_otros" >/dev/null 2>&1 || aviso "no se pudo guardar la huella de los otros portales (¿alguno no responde?); se continúa, la comparación final se omitirá."
env_respaldar "antes-de-revertir-codigo" || abortar "No se pudo respaldar el .env actual: no se modificó nada."
ok "el .env actual quedó respaldado en $ENV_RESPALDO_RUTA"
tar xzf "$RESPALDO_ORIGEN/public.tgz" -C "$tmp" && tar xzf "$RESPALDO_ORIGEN/backend.tgz" -C "$tmp" || abortar "No se pudo extraer el respaldo: no se modificó nada."
[ -d "$tmp/public" ] && [ -d "$tmp/backend" ] || abortar "El respaldo no tiene la estructura esperada: no se modificó nada."
if [ -n "$ENV_A_USAR" ]; then cp "$ENV_A_USAR" "$tmp/backend/.env" || abortar "No se pudo preparar el .env: no se modificó nada."
else cp -p "$ENV_FILE" "$tmp/backend/.env" || abortar "No se pudo conservar el .env: no se modificó nada."; fi
chown root:www-data "$tmp/backend/.env" && chmod 640 "$tmp/backend/.env" || abortar "No se pudieron fijar los permisos del .env: no se modificó nada."
# Dos reemplazos por renombrado; si el segundo falla, el primero se deshace (nunca queda una mitad restaurada).
intercambiar "$DIR_PUBLIC" "$tmp/public" "$PREV_PUBLIC" || abortar "Falló el reemplazo de la aplicación: no se modificó nada."
if ! intercambiar "$DIR_BACKEND" "$tmp/backend" "$PREV_BACKEND"; then
  mv "$DIR_PUBLIC" "$tmp/public" && mv "$PREV_PUBLIC" "$DIR_PUBLIC" || fallo "NO se pudo deshacer el primer reemplazo: revise $PREV_PUBLIC y $DIR_PUBLIC a mano."
  abortar "Falló el reemplazo del backend; se deshizo el de la aplicación."
fi
ok "código restaurado (aplicación y backend)${ENV_A_USAR:+ y .env piloto_admin}"

espera_opcache
DIAG="$DIR_RESPALDO/reversion-$sello"
salida_ok=0
if identidad_con "$MANIFIESTO_BASE"; then ok "identidad en disco: idéntico a la fase 1 ($BASE_FASE1_COMMIT)"; salida_ok=1; else fallo "lo restaurado NO coincide con el manifiesto de la fase 1"; fi
CERVESUR_OMITIR_LINEA_BASE=1 bash "$PAQ/scripts/verificar-fase1.sh" >"$DIAG.log" 2>&1; rc_verif=$?
if inyectar verificacion; then rc_verif=1; fi
if [ "$rc_verif" -eq 0 ] && [ "$salida_ok" -eq 1 ]; then
  rm -f "$DIAG.log"; rm -rf "$PREV_PUBLIC" "$PREV_BACKEND"
  ok "comportamiento de la fase 1 verificado (verificar-fase1.sh sin fallos)"
else
  mv "$PREV_PUBLIC" "$DIR_FRONT/.diagnostico-public-$sello" 2>/dev/null; mv "$PREV_BACKEND" "$DIR_BACK/.diagnostico-backend-$sello" 2>/dev/null
  chmod 600 "$DIAG.log" 2>/dev/null
  fallo "Código restaurado, pero la verificación posterior FALLÓ. Evidencia conservada: $DIAG.log · $DIR_FRONT/.diagnostico-public-$sello · $DIR_BACK/.diagnostico-backend-$sello (lo que se reemplazó). No se borró nada."
  exit 1
fi
if [ -s "$tmp_otros" ]; then bash "$PAQ/scripts/comprobar-otros-portales.sh" --comparar "$tmp_otros" || aviso "la huella de los otros portales cambió (no la causa este script): revise."; fi
if [ "$SIN_CRED" = 0 ] && credenciales_presentes; then
  if api_login; then
    api_pedir GET "/api/empresas?view=minimal&per_page=1&page=1"
    if [ "$API_STATUS" = 200 ]; then ok "login y lectura del administrador comprobados"; else aviso "el login funciona pero GET /api/empresas dio $API_STATUS"; fi
  else aviso "el login con CERVESUR_E_USUARIO / CERVESUR_E_CLAVE falla tras la reversión: revise"; fi
fi
ok "REVERSIÓN COMPLETA (clase 1). Respaldos conservados: código en $RESPALDO_ORIGEN · .env en $DIR_ENV_RESPALDO. Escrituras: apagadas por construcción (el .env restaurado no las tiene)."
