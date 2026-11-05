#!/usr/bin/env bash
# Cervesur · UX-08I · FASE 2B · Instalador del CÓDIGO 2B sobre la Fase 2A ya operando (RC2 f539c9f). Solo código: NO toca el .env, MySQL, Apache ni los otros portales.
#   sudo bash scripts/instalar-codigo-2b.sh --simular                       (por omisión) comprueba todo y no escribe nada
#   sudo bash scripts/instalar-codigo-2b.sh --aplicar                       respaldo verificado → reemplazo → verificación de la fase 2A sin regresiones → (si falla) restauración automática
#   sudo bash scripts/instalar-codigo-2b.sh --revertir RESPALDO_DIR         restaura un respaldo creado por este script (public.tgz + backend.tgz con su SHA256SUMS)
# Condición de seguridad: el .env debe estar en admin_escritura y con ADMIN_WRITES_USUARIOS / _SENSIBLE sin definir o distintos de 1. El código 2B con esos interruptores apagados se comporta como la fase 2A:
# Usuarios responde 403 «fase_2b_bloqueada». Encender Usuarios es OTRO paso (interruptor-usuarios.sh), con privilegios verificados antes.
# Credenciales opcionales (solo entorno): CERVESUR_E_USUARIO / CERVESUR_E_CLAVE de un administrador autorizado del 8443; sin ellas la verificación posterior es PARCIAL y lo declara.
# Salida: 0 bien · 1 bloqueado antes de escribir · 2 uso · 20 falló y se restauró · 21 falló y NO se pudo restaurar (intervención manual: use el respaldo indicado)
set -uo pipefail
case ${1:-} in --ayuda|-h|--help) sed -n '2,/^set -/{/^set -/!s/^# \{0,1\}//p}' "${BASH_SOURCE[0]}"; exit 0 ;; esac
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"

MODO=simular; REVERTIR_DIR=""
while [ $# -gt 0 ]; do
  case $1 in
    --simular) MODO=simular; shift ;;
    --aplicar) MODO=aplicar; shift ;;
    --revertir) MODO=revertir; REVERTIR_DIR=${2:-}; shift 2 ;;
    *) echo "Argumento desconocido: $1 (use --ayuda)" >&2; exit 2 ;;
  esac
done
[ "$MODO" = simular ] || requerir_root

sello=$(sello_utc)
NUEVO_PUBLIC="$DIR_FRONT/.nuevo-public-$sello"; NUEVO_BACKEND="$DIR_BACK/.nuevo-backend-$sello"
PREV_PUBLIC="$DIR_FRONT/.previo-public-$sello"; PREV_BACKEND="$DIR_BACK/.previo-backend-$sello"
copiar_solo_lectura() { cp -R --no-preserve=all "$1" "$2" && chown -R root:root "$2" && find "$2" -type d -exec chmod 755 {} + && find "$2" -type f -exec chmod 644 {} +; }
intercambiar() { mv "$1" "$3" && mv "$2" "$1" || { [ -d "$1" ] || mv "$3" "$1"; return 1; }; }
# ¿Lo instalado (backend/ sin .env y public/) coincide archivo por archivo con un manifiesto?
identidad_con() { # manifiesto
  local m=$1 lb lp
  lb=$(grep -E '^[0-9a-f]{64} [ *](./)?backend/' "$m") && lp=$(grep -E '^[0-9a-f]{64} [ *](./)?public/' "$m") || return 1
  (cd "$DIR_BACK" && sha256sum -c --quiet <<<"$lb") >/dev/null 2>&1 && (cd "$DIR_FRONT" && sha256sum -c --quiet <<<"$lp") >/dev/null 2>&1 || return 1
  [ "$(find "$DIR_BACKEND" -type f ! -path "$ENV_FILE" | wc -l)" = "$(wc -l <<<"$lb")" ] && [ "$(find "$DIR_PUBLIC" -type f | wc -l)" = "$(wc -l <<<"$lp")" ]
}
MANIFIESTO_RC2=$(find "$PAQ/base" -maxdepth 1 -name 'MANIFIESTO-FASE2A-*.sha256' 2>/dev/null | head -2)
usuarios_apagados() { [ "$(env_valor_actual ADMIN_WRITES_USUARIOS)" != 1 ] && [ "$(env_valor_actual ADMIN_WRITES_USUARIOS_SENSIBLE)" != 1 ]; }

restaurar_desde() { # directorio de respaldo ya verificado
  local r=$1
  [ -s "$r/public.tgz" ] && [ -s "$r/backend.tgz" ] && (cd "$r" && sha256sum -c SHA256SUMS --quiet) >/dev/null 2>&1 || { fallo "el respaldo $r no verifica (SHA256SUMS)"; return 1; }
  local tmp; tmp=$(mktemp -d "$DIR_FRONT/.restaurar-XXXXXX") || return 1
  tar xzf "$r/public.tgz" -C "$tmp" && tar xzf "$r/backend.tgz" -C "$tmp" && [ -d "$tmp/public" ] && [ -d "$tmp/backend" ] || { rm -rf "$tmp"; fallo "no se pudo extraer el respaldo"; return 1; }
  cp -p "$ENV_FILE" "$tmp/env.guardado" || { rm -rf "$tmp"; return 1; }
  copiar_solo_lectura "$tmp/public" "$tmp/public.listo" && copiar_solo_lectura "$tmp/backend" "$tmp/backend.listo" || { rm -rf "$tmp"; return 1; }
  cp -p "$tmp/env.guardado" "$tmp/backend.listo/.env" && chown root:www-data "$tmp/backend.listo/.env" && chmod 640 "$tmp/backend.listo/.env" || { rm -rf "$tmp"; return 1; }
  local p="$DIR_FRONT/.sustituido-public-$sello" b="$DIR_BACK/.sustituido-backend-$sello"
  mv "$DIR_PUBLIC" "$p" && mv "$tmp/public.listo" "$DIR_PUBLIC" && mv "$DIR_BACKEND" "$b" && mv "$tmp/backend.listo" "$DIR_BACKEND" || { fallo "falló el reemplazo durante la restauración"; return 1; }
  rm -rf "$tmp" "$p" "$b"
  return 0
}

echo "== UX-08I · Instalación del código 2B (modo $MODO)"
if [ "$MODO" = revertir ]; then
  [ -d "$REVERTIR_DIR" ] || abortar "Indique el directorio de respaldo: --revertir $DIR_RESPALDO/codigo-2b-<sello>"
  case $REVERTIR_DIR in "$DIR_RESPALDO"/codigo-2b-*) ;; *) abortar "El respaldo debe estar en $DIR_RESPALDO/codigo-2b-*" ;; esac
  usuarios_apagados || abortar "Usuarios está ENCENDIDO en el .env: apáguelo (interruptor-usuarios.sh apagar) antes de cambiar el código."
  env_antes=$(sha256sum < "$ENV_FILE")
  restaurar_desde "$REVERTIR_DIR" || abortar "No se pudo restaurar desde $REVERTIR_DIR: la aplicación quedó como estaba o en estado a revisar a mano."
  [ "$(sha256sum < "$ENV_FILE")" = "$env_antes" ] && ok ".env intacto (mismo SHA-256)" || fallo ".env CAMBIÓ durante la restauración: revíselo"
  [ -n "$MANIFIESTO_RC2" ] && identidad_con "$(head -1 <<<"$MANIFIESTO_RC2")" && ok "código restaurado: idéntico a la Fase 2A (manifiesto del paquete)" || aviso "no se pudo comprobar la identidad contra el manifiesto 2A (¿el respaldo es de otra versión?)"
  exit 0
fi

echo "== Precondiciones (solo lectura)"
[ -d "$DIR_PUBLIC" ] && [ -d "$DIR_BACKEND" ] && [ -f "$ENV_FILE" ] || abortar "El administrador no está instalado."
[ -f "$PAQ/SHA256SUMS" ] && (cd "$PAQ" && sha256sum -c SHA256SUMS --quiet) >/dev/null 2>&1 || abortar "La integridad del paquete (SHA256SUMS) no se verifica."
ok "paquete íntegro (SHA256SUMS)"
[ "$(wc -l <<<"$MANIFIESTO_RC2")" = 1 ] && [ -n "$MANIFIESTO_RC2" ] || abortar "El paquete debe traer exactamente un base/MANIFIESTO-FASE2A-*.sha256."
MANIFIESTO_RC2=$(head -1 <<<"$MANIFIESTO_RC2")
[ "$(env_valor_actual CERVESUR_MODE)" = admin_escritura ] && [ "$(env_valor_actual CERVESUR_REQUIRED_MODE)" = admin_escritura ] || abortar "El .env no está en admin_escritura: el código 2B se instala sobre la Fase 2A ya operando."
usuarios_apagados || abortar "ADMIN_WRITES_USUARIOS o _SENSIBLE están en 1 en el .env: apáguelos ANTES de actualizar el código."
ok "Usuarios apagado en el .env"
if identidad_con "$MANIFIESTO_RC2"; then ok "lo instalado es exactamente la Fase 2A (RC2): archivo por archivo"
else abortar "Lo instalado NO coincide con la Fase 2A (RC2): hay deriva, cambios locales o ya es otra versión. NO-GO hasta clasificarlo."; fi
if command -v php >/dev/null 2>&1; then
  while IFS= read -r f; do php -l "$f" >/dev/null 2>&1 || abortar "Error de sintaxis PHP en $f"; done < <(find "$PAQ/backend" -name '*.php' -type f)
  ok "sintaxis PHP correcta en todo el backend del paquete"
else aviso "php (CLI) no disponible: no se pudo comprobar la sintaxis"; fi
escuchas=$(ss -ltnH "sport = :$PUERTO" | awk '{print $4}'); [ -n "$escuchas" ] || abortar "Nada escucha en el puerto $PUERTO."
! grep -qvE "^127\.0\.0\.1:$PUERTO\$" <<<"$escuchas" || abortar "El puerto $PUERTO escucha fuera de loopback."
ok "el puerto $PUERTO sigue solo en loopback"
bash "$PAQ/scripts/comprobar-otros-portales.sh" >/dev/null 2>&1 || abortar "Flutter (80), trabajadores (443) o la QA (8094) no responden: se detiene antes de tocar nada."
ok "Flutter (80), trabajadores (443) y QA (8094) responden"
env_antes=$(sha256sum < "$ENV_FILE")
echo "== Qué se reemplazaría"
echo "   $DIR_PUBLIC  ← $PAQ/public"; echo "   $DIR_BACKEND ← $PAQ/backend   (el .env se conserva tal cual)"; echo "   respaldo en  $DIR_RESPALDO/codigo-2b-$sello"
if [ "$MODO" = simular ]; then ok "SIMULACIÓN terminada: no se escribió nada. Código 0 = se puede instalar."; exit 0; fi
if [ "${CERVESUR_CONFIRMAR_ACTUALIZAR:-}" != "SI" ]; then read -r -p "Escriba INSTALAR-CODIGO-2B para continuar: " conf; [ "$conf" = "INSTALAR-CODIGO-2B" ] || abortar "Cancelado. No se modificó nada."; fi
tmp_otros=$(mktemp) || abortar "No se pudo crear un temporal."
trap 'rm -f "$tmp_otros"' EXIT
bash "$PAQ/scripts/comprobar-otros-portales.sh" --guardar "$tmp_otros" >/dev/null 2>&1 || abortar "No se pudo guardar la huella de los otros portales: no se modificó nada."

echo "== Respaldo (ningún reemplazo empieza sin uno completo y verificado)"
RESP="$DIR_RESPALDO/codigo-2b-$sello"
respaldo_fallo() { abortar "Respaldo NO creado o no verificable ($1): NO se modificó nada. Lo que haya en $RESP es evidencia y no se borra."; }
install -d -m 700 "$DIR_RESPALDO" || respaldo_fallo "no se pudo crear $DIR_RESPALDO"
[ ! -e "$RESP" ] || respaldo_fallo "ya existe $RESP"
mkdir -m 700 "$RESP" || respaldo_fallo "no se pudo crear $RESP"
tar czf "$RESP/public.tgz" -C "$DIR_FRONT" public || respaldo_fallo "tar de la aplicación"
tar czf "$RESP/backend.tgz" -C "$DIR_BACK" --exclude=backend/.env backend || respaldo_fallo "tar del backend"
(cd "$RESP" && sha256sum public.tgz backend.tgz > SHA256SUMS) || respaldo_fallo "SHA256SUMS"
chmod 600 "$RESP"/public.tgz "$RESP"/backend.tgz "$RESP"/SHA256SUMS
(cd "$RESP" && sha256sum -c SHA256SUMS --quiet) >/dev/null 2>&1 || respaldo_fallo "SHA-256 no coincide"
! tar tzf "$RESP/backend.tgz" | grep -q '/\.env' || respaldo_fallo "el respaldo contiene un .env"
[ "$(tar tzf "$RESP/backend.tgz" | grep -vc '/$')" = "$(find "$DIR_BACKEND" -type f ! -path "$ENV_FILE" | wc -l)" ] || respaldo_fallo "backend.tgz incompleto"
ok "respaldo en $RESP verificado (SHA-256, completo, sin .env); los respaldos no se borran jamás"

volver_atras() {
  fallo "La instalación falló: se restaura el código anterior (el .env no se tocó)."
  [ -d "$PREV_PUBLIC" ] && { rm -rf "$DIR_PUBLIC"; mv "$PREV_PUBLIC" "$DIR_PUBLIC"; }
  [ -d "$PREV_BACKEND" ] && { rm -rf "$DIR_BACKEND"; mv "$PREV_BACKEND" "$DIR_BACKEND"; }
  rm -rf "$NUEVO_PUBLIC" "$NUEVO_BACKEND"
  if identidad_con "$MANIFIESTO_RC2"; then ok "código de la Fase 2A restaurado (idéntico al manifiesto)"; exit 20; fi
  fallo "NO se pudo comprobar la restauración. Use: sudo bash scripts/instalar-codigo-2b.sh --revertir $RESP"; exit 21
}
echo "== Reemplazo"
copiar_solo_lectura "$PAQ/public" "$NUEVO_PUBLIC" || volver_atras
copiar_solo_lectura "$PAQ/backend" "$NUEVO_BACKEND" || volver_atras
cp -p "$ENV_FILE" "$NUEVO_BACKEND/.env" && chown root:www-data "$NUEVO_BACKEND/.env" && chmod 640 "$NUEVO_BACKEND/.env" || volver_atras
intercambiar "$DIR_PUBLIC" "$NUEVO_PUBLIC" "$PREV_PUBLIC" || volver_atras
intercambiar "$DIR_BACKEND" "$NUEVO_BACKEND" "$PREV_BACKEND" || volver_atras
inyectar verificacion && { fallo "(ensayo) fallo inyectado en la verificación"; volver_atras; }
espera_opcache

echo "== Verificación posterior (Fase 2A sin regresiones; Usuarios sigue bloqueado)"
lista=$(grep -E '^[0-9a-f]{64} [ *](./)?backend/' "$PAQ/SHA256SUMS"); (cd "$DIR_BACK" && sha256sum -c --quiet <<<"$lista") >/dev/null 2>&1 || volver_atras
lista=$(grep -E '^[0-9a-f]{64} [ *](./)?public/' "$PAQ/SHA256SUMS"); (cd "$DIR_FRONT" && sha256sum -c --quiet <<<"$lista") >/dev/null 2>&1 || volver_atras
ok "identidad en disco: lo instalado es el paquete 2B"
[ "$(sha256sum < "$ENV_FILE")" = "$env_antes" ] && ok ".env intacto (mismo SHA-256)" || { fallo ".env CAMBIÓ"; volver_atras; }
extra=(); credenciales_presentes || { extra=(--sin-credenciales); aviso "sin CERVESUR_E_USUARIO/CLAVE: verificación PARCIAL (sin login)"; }
salida=$(mktemp); trap 'rm -f "$tmp_otros" "$salida"' EXIT
if bash "$PAQ/scripts/verificar-posdespliegue.sh" --etapa accesos "${extra[@]}" >"$salida" 2>&1; then ok "etapa accesos: empresas, reportes y accesos siguen encendidos y verificados; usuarios 403 «fase_2b_bloqueada»"
else grep -E '^FAIL' "$salida" | head -10; volver_atras; fi
bash "$PAQ/scripts/comprobar-otros-portales.sh" --comparar "$tmp_otros" >/dev/null 2>&1 && ok "Flutter, trabajadores y QA: huella idéntica a la de antes" || { fallo "la huella de otros portales cambió"; volver_atras; }
ok "INSTALACIÓN TERMINADA. Código anterior conservado en $PREV_PUBLIC y $PREV_BACKEND y respaldo en $RESP. Usuarios sigue APAGADO (siguiente paso: privilegios-usuarios.sh --simular)."
echo "RESULTADO-INSTALACION-2B: PASS $RESP"
