#!/usr/bin/env bash
# ACTUALIZA el entorno de QA «admin_escritura» YA INSTALADO con una versión nueva del paquete (UX-08H). Reversible. Adaptado de deploy/tunel-ssh-piloto-usuarios/scripts/actualizar.sh.
#   sudo bash scripts/actualizar.sh --simular           comprueba todo y NO escribe nada
#   sudo bash scripts/actualizar.sh                     pide escribir ACTUALIZAR-QA-ESCRITURA; respalda (SHA-256 verificado), reemplaza la aplicación y el backend, verifica y, si algo falla, VUELVE SOLO
#   sudo bash scripts/actualizar.sh --revertir RESPALDO restaura el respaldo indicado (carpeta creada por una actualización anterior)
# Requiere CERVESUR_QAE_ADMIN_PASSWORD (la clave de syn001 impresa al instalar) para la verificación posterior: sin ella NO se actualiza (nunca se verifica a medias).
# Solo reemplaza $DIR_FRONT/public y $DIR_BACK/backend (el .env —incluidos los interruptores de escritura—, las bases, las cuentas MySQL, la lista blanca y la configuración de Apache
# NO se tocan). No reinicia ni recarga Apache (mod_php lee el código nuevo en la siguiente petición). Compara la huella de los demás portales antes y después.
# El respaldo no incluye el .env (no se copian secretos) y NUNCA se borra.
set -uo pipefail
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
requerir_root
validar_puerto

DIR_PUBLIC="$DIR_FRONT/public"; DIR_BACKEND="$DIR_BACK/backend"
MODO=actualizar; RESPALDO_ORIGEN=""
while [ $# -gt 0 ]; do
  case $1 in
    --simular) MODO=simular; shift ;;
    --revertir) MODO=revertir; RESPALDO_ORIGEN=${2:-}; [ -n "$RESPALDO_ORIGEN" ] || abortar "--revertir necesita la carpeta del respaldo."; shift 2 ;;
    *) abortar "Argumento desconocido: $1" ;;
  esac
done
if [ "$MODO" != simular ]; then bloquear_ejecucion "${BASH_SOURCE[0]}"; bloqueo_global || abortar "Hay otra instalación, actualización o reversión de este entorno en curso ($LOCK_GLOBAL). No se modificó nada."; fi

sello=$(date -u +%Y%m%d-%H%M%SZ)
NUEVO_PUBLIC="$DIR_FRONT/.nuevo-public-$sello"; NUEVO_BACKEND="$DIR_BACK/.nuevo-backend-$sello"
PREV_PUBLIC="$DIR_FRONT/.previo-public-$sello"; PREV_BACKEND="$DIR_BACK/.previo-backend-$sello"

# Cada paso se comprueba (el script no usa «set -e»).
copiar_solo_lectura() { cp -R --no-preserve=all "$1" "$2" && chown -R root:root "$2" && find "$2" -type d -exec chmod 755 {} + && find "$2" -type f -exec chmod 644 {} +; }

# Inyección de fallos SOLO PARA ENSAYOS (CERVESUR_QAE_INYECTAR_FALLO=respaldo-dir|manifiesto|respaldo-corrupto|frontend|backend|verificacion): provoca un fallo controlado en ese paso.
# Jamás omite ni relaja una comprobación: lo único que puede hacer es que un paso FALLE (el procedimiento debe terminar en error y dejar todo como estaba).
inyectar() { [ "${CERVESUR_QAE_INYECTAR_FALLO:-}" = "$1" ]; }

# Reemplazo por renombrado (dos pasos: cada uno es atómico; en medio hay un instante con la carpeta ausente, de milisegundos).
intercambiar() { # carpeta_actual carpeta_nueva carpeta_previa
  mv "$1" "$3" && mv "$2" "$1" || { [ -d "$1" ] || mv "$3" "$1"; return 1; }
}

precondiciones() {
  echo "== Precondiciones (solo lectura)"
  [ -d "$DIR_PUBLIC" ] && [ -d "$DIR_BACKEND" ] && [ -f "$ENV_FILE" ] || abortar "El entorno no está instalado (faltan $DIR_PUBLIC, $DIR_BACKEND o el .env): use instalar.sh."
  [ -e "$SITE_ENABLED" ] || abortar "El sitio $SITIO no está habilitado."
  [ "$(valor_env CERVESUR_MODE)" = "admin_escritura" ] && [ "$(valor_env CERVESUR_REQUIRED_MODE)" = "admin_escritura" ] || abortar "El .env instalado no está en modo admin_escritura."
  [ -z "$(valor_env USER_ADMIN_WRITES)" ] || abortar "El .env instalado define USER_ADMIN_WRITES: no es una instalación válida de este entorno."
  [ -n "${CERVESUR_QAE_ADMIN_PASSWORD:-}" ] || abortar "Falta CERVESUR_QAE_ADMIN_PASSWORD (clave de syn001): sin ella la verificación posterior sería incompleta. No se modificó nada."
  [ -f "$PAQ/SHA256SUMS" ] && (cd "$PAQ" && sha256sum -c SHA256SUMS --quiet) || abortar "La integridad del paquete (SHA256SUMS) no se verifica."
  [ -d "$PAQ/public" ] && [ -d "$PAQ/backend" ] || abortar "El paquete no contiene public/ y backend/."
  prohibidos=$(find "$PAQ" \( -name '.env*' -o -name '*.key' -o -name '*.pem' -o -name '*.crt' \) -print -quit); [ -z "$prohibidos" ] || abortar "El paquete contiene .env, claves o certificados: no es válido."
  grep -q '"pilotMode": *"admin-write"' "$PAQ/public/build-info.json" 2>/dev/null || abortar "La aplicación del paquete no es la compilación admin-write."
  [ -f "$PAQ/backend/lib/admin_escritura.php" ] || abortar "El backend del paquete no trae el modo admin_escritura."
  mysql_activo || abortar "MySQL no está activo."
  respuesta=$(curl -sS -m 10 "http://127.0.0.1:$PUERTO/api/ping"); grep -q '"pong":true' <<<"$respuesta" || abortar "El entorno actual no responde: no se actualiza algo que ya está caído (revise primero con verificar.sh)."
  # Sintaxis de TODO el backend del paquete ANTES de tocar nada (un archivo roto no debe llegar a producción).
  if command -v php >/dev/null 2>&1; then
    while IFS= read -r f; do php -l "$f" >/dev/null 2>&1 || abortar "Error de sintaxis PHP en el paquete (${f#"$PAQ"/}): no se actualiza nada."; done < <(find "$PAQ/backend" -name '*.php' -not -path '*/vendor/*' | LC_ALL=C sort)
    ok "sintaxis PHP correcta en todo el backend del paquete"
  else aviso "php (CLI) no disponible: no se pudo comprobar la sintaxis del backend del paquete"; fi
  ok "entorno instalado, sano y en modo admin_escritura; paquete íntegro y sin secretos"
  if ! cmp -s "$PAQ/apache/react-escritura-qa-common.conf" "$DIR_CONF/react-escritura-qa-common.conf"; then
    aviso "la configuración de Apache del paquete DIFIERE de la instalada. Esta actualización NO la cambia (requiere aprobación específica y una recarga)."
  else ok "la configuración de Apache instalada coincide con la del paquete"; fi
  escuchas=$(ss -ltnH "sport = :$PUERTO" | awk '{print $4}'); [ -n "$escuchas" ] || abortar "Nada escucha en el puerto $PUERTO."
  ! grep -qvE "^127\.0\.0\.1:$PUERTO\$" <<<"$escuchas" || abortar "El puerto $PUERTO escucha fuera de loopback."
  ok "el puerto $PUERTO sigue solo en loopback"
}

espera_opcache() { sleep "${CERVESUR_QAE_ESPERA_OPCACHE:-4}"; }   # opcache revalida cada ~2 s; hay que esperar antes de verificar CUALQUIER cambio de código

# Identidad EN DISCO de lo instalado frente al paquete (todo backend/ y public/ listados en su SHA256SUMS; el .env no figura).
identidad_disco() {
  local lista
  lista=$(grep -E '^[0-9a-f]{64} [ *](./)?backend/' "$PAQ/SHA256SUMS") && [ -n "$lista" ] && (cd "$DIR_BACK" && sha256sum -c --quiet <<<"$lista") >/dev/null 2>&1 || return 1
  lista=$(grep -E '^[0-9a-f]{64} [ *](./)?public/' "$PAQ/SHA256SUMS") && [ -n "$lista" ] && (cd "$DIR_FRONT" && sha256sum -c --quiet <<<"$lista") >/dev/null 2>&1
}

verificar_post() {
  espera_opcache
  identidad_disco || { echo "FAIL identidad en disco: lo instalado NO coincide con el SHA256SUMS del paquete" > "$1"; return 1; }
  bash "$PAQ/scripts/verificar.sh" >"$1" 2>&1; local rc=$?
  [ "$rc" -eq 0 ] || { grep -E '^(FAIL|OMIT)' "$1" | head -10; return 1; }
  return 0
}

if [ "$MODO" = "revertir" ]; then
  [ -d "$RESPALDO_ORIGEN" ] && [ -f "$RESPALDO_ORIGEN/public.tgz" ] && [ -f "$RESPALDO_ORIGEN/backend.tgz" ] || abortar "El respaldo indicado no es válido."
  (cd "$RESPALDO_ORIGEN" && sha256sum -c SHA256SUMS --quiet) || abortar "El respaldo está corrupto (SHA256SUMS)."
  [ -n "${CERVESUR_QAE_ADMIN_PASSWORD:-}" ] || abortar "Falta CERVESUR_QAE_ADMIN_PASSWORD (clave de syn001) para verificar tras restaurar. No se modificó nada."
  if [ "${CERVESUR_QAE_CONFIRMAR:-}" != "SI" ]; then
    read -r -p "Escriba REVERTIR-ACTUALIZACION-QA-ESCRITURA para restaurar la versión anterior: " c; [ "$c" = "REVERTIR-ACTUALIZACION-QA-ESCRITURA" ] || abortar "Cancelado. No se modificó nada."
  fi
  pre=$(huella_portales)
  tmp=$(mktemp -d "$DIR_FRONT/.restaurar-XXXXXX") || abortar "No se pudo crear la carpeta temporal de restauración: no se modificó nada."; trap 'rm -rf "$tmp"' EXIT
  tar xzf "$RESPALDO_ORIGEN/public.tgz" -C "$tmp" && tar xzf "$RESPALDO_ORIGEN/backend.tgz" -C "$tmp" || abortar "No se pudo extraer el respaldo: no se modificó nada."
  [ -d "$tmp/public" ] && [ -d "$tmp/backend" ] || abortar "El respaldo no tiene la estructura esperada: no se modificó nada."
  cp -p "$ENV_FILE" "$tmp/backend/.env" || abortar "No se pudo conservar el .env: no se modificó nada."
  # Dos reemplazos por renombrado; si el segundo falla, el primero se deshace (nunca queda una mitad restaurada).
  intercambiar "$DIR_PUBLIC" "$tmp/public" "$PREV_PUBLIC" || abortar "Falló el reemplazo de la aplicación: no se modificó nada."
  if ! intercambiar "$DIR_BACKEND" "$tmp/backend" "$PREV_BACKEND"; then
    mv "$DIR_PUBLIC" "$tmp/public" && mv "$PREV_PUBLIC" "$DIR_PUBLIC" || fallo "NO se pudo deshacer el primer reemplazo: revise $PREV_PUBLIC y $DIR_PUBLIC a mano."
    abortar "Falló el reemplazo del backend; se deshizo el de la aplicación."
  fi
  { chown root:www-data "$ENV_FILE" && chmod 640 "$ENV_FILE"; } || fallo "No se pudieron fijar el propietario y los permisos del .env: revíselos a mano (root:www-data 640)."
  espera_opcache
  install -d -m 700 "$DIR_RESPALDO" 2>/dev/null
  DIAG="$DIR_RESPALDO/reversion-$sello"
  # El respaldo restaurado no tiene por qué coincidir con el SHA256SUMS de ESTE paquete: aquí se usa el verificador funcional (no la identidad con el paquete).
  bash "$PAQ/scripts/verificar.sh" >"$DIAG.log" 2>&1; rc_verif=$?
  if inyectar verificacion || [ "$rc_verif" -ne 0 ]; then
    # Fallo de la verificación posterior: se CONSERVA la evidencia (la versión que se reemplazó y la salida completa) y el código de salida es distinto de cero.
    mv "$PREV_PUBLIC" "$DIR_FRONT/.diagnostico-public-$sello" 2>/dev/null; mv "$PREV_BACKEND" "$DIR_BACK/.diagnostico-backend-$sello" 2>/dev/null
    chmod 600 "$DIAG.log" 2>/dev/null
    fallo "Versión restaurada, pero la verificación posterior FALLÓ. Evidencia conservada: $DIAG.log · $DIR_FRONT/.diagnostico-public-$sello · $DIR_BACK/.diagnostico-backend-$sello (la versión que se reemplazó). No se borró nada."
    exit 1
  fi
  rm -f "$DIAG.log"; rm -rf "$PREV_PUBLIC" "$PREV_BACKEND"
  [ "$(huella_portales)" = "$pre" ] && ok "los demás portales siguen idénticos" || aviso "la huella de los demás portales cambió: revise (no la causa este script)."
  ok "versión anterior restaurada y verificada"
  exit 0
fi

precondiciones
pre=$(huella_portales)
echo "== Qué se reemplazaría"
echo "   $DIR_PUBLIC   ← $PAQ/public"
echo "   $DIR_BACKEND  ← $PAQ/backend   (el .env y sus interruptores se conservan tal cual)"
echo "   respaldo en   $DIR_RESPALDO/actualizacion-$sello (sin .env)"
if [ "$MODO" = "simular" ]; then ok "SIMULACIÓN terminada: no se escribió nada. Código 0 = se puede actualizar."; exit 0; fi

if [ "${CERVESUR_QAE_CONFIRMAR:-}" != "SI" ]; then
  read -r -p "Escriba ACTUALIZAR-QA-ESCRITURA para continuar: " conf; [ "$conf" = "ACTUALIZAR-QA-ESCRITURA" ] || abortar "Cancelado. No se modificó nada."
fi

# ---- Respaldo: NINGÚN reemplazo empieza sin un respaldo creado, completo y verificado.
echo "== Respaldo"
RESP="$DIR_RESPALDO/actualizacion-$sello"
respaldo_fallo() { abortar "Respaldo NO creado o no verificable ($1): NO se modificó la aplicación ni el backend publicados. Los respaldos anteriores no se tocaron; lo que haya quedado en $RESP es evidencia y no se borra."; }
if inyectar respaldo-dir; then false; else install -d -m 700 "$DIR_RESPALDO"; fi || respaldo_fallo "no se pudo crear $DIR_RESPALDO"
[ ! -e "$RESP" ] || respaldo_fallo "ya existe $RESP (no se sobrescribe un respaldo)"
mkdir -m 700 "$RESP" || respaldo_fallo "no se pudo crear $RESP"
tar czf "$RESP/public.tgz" -C "$DIR_FRONT" public || respaldo_fallo "tar de la aplicación"
tar czf "$RESP/backend.tgz" -C "$DIR_BACK" --exclude=backend/.env backend || respaldo_fallo "tar del backend"
if inyectar manifiesto; then false; else (cd "$RESP" && sha256sum public.tgz backend.tgz > SHA256SUMS); fi || respaldo_fallo "no se pudo generar SHA256SUMS"
if inyectar respaldo-corrupto; then echo "x" >> "$RESP/public.tgz"; fi
chmod 600 "$RESP/public.tgz" "$RESP/backend.tgz" "$RESP/SHA256SUMS" || respaldo_fallo "permisos de los archivos del respaldo"
printf 'actualizacion %s\npaquete %s\n' "$sello" "$(basename "$PAQ")" > "$RESP/INFO.txt" && chmod 600 "$RESP/INFO.txt" || respaldo_fallo "INFO.txt"
# Verificación del respaldo: manifiesto con sus DOS entradas, SHA-256 de ambos archivos, estructura, completitud (mismo número de archivos que el disco) y sin .env.
[ "$(wc -l < "$RESP/SHA256SUMS")" = "2" ] || respaldo_fallo "el manifiesto SHA256SUMS no tiene exactamente 2 entradas"
(cd "$RESP" && sha256sum -c SHA256SUMS --quiet) >/dev/null 2>&1 || respaldo_fallo "SHA-256 de public.tgz o backend.tgz no coincide"
[ "$(stat -c %a "$RESP"):$(stat -c %a "$RESP/public.tgz"):$(stat -c %a "$RESP/backend.tgz"):$(stat -c %a "$RESP/SHA256SUMS")" = "700:600:600:600" ] || respaldo_fallo "permisos 700/600 incorrectos"
lista_pub=$(tar tzf "$RESP/public.tgz") || respaldo_fallo "no se puede listar public.tgz"
lista_bk=$(tar tzf "$RESP/backend.tgz") || respaldo_fallo "no se puede listar backend.tgz"
grep -q '^public/' <<<"$lista_pub" && grep -q '^backend/' <<<"$lista_bk" || respaldo_fallo "estructura de los archivos tar"
[ "$(grep -vc '/$' <<<"$lista_pub")" = "$(find "$DIR_PUBLIC" -type f | wc -l)" ] || respaldo_fallo "public.tgz no contiene todos los archivos de la aplicación"
[ "$(grep -vc '/$' <<<"$lista_bk")" = "$(find "$DIR_BACKEND" -type f ! -path "$DIR_BACKEND/.env" | wc -l)" ] || respaldo_fallo "backend.tgz no contiene todos los archivos del backend"
! grep -q '/\.env' <<<"$lista_bk" || respaldo_fallo "el respaldo del backend contiene un .env"
ok "respaldo en $RESP: SHA-256 de ambos archivos verificado, completo, permisos 700/600, sin .env; los respaldos no se borran jamás"

volver_atras() {
  fallo "La actualización falló: se restaura la versión anterior."
  [ -n "${salida:-}" ] && [ -s "$salida" ] && cp "$salida" "$RESP/verificacion-fallida.log" 2>/dev/null && chmod 600 "$RESP/verificacion-fallida.log" 2>/dev/null
  [ -d "$PREV_PUBLIC" ] && { rm -rf "$DIR_PUBLIC"; mv "$PREV_PUBLIC" "$DIR_PUBLIC"; }
  [ -d "$PREV_BACKEND" ] && { rm -rf "$DIR_BACKEND"; mv "$PREV_BACKEND" "$DIR_BACKEND"; }
  rm -rf "$NUEVO_PUBLIC" "$NUEVO_BACKEND"
  espera_opcache; bash "$PAQ/scripts/verificar.sh" >/dev/null 2>&1 && ok "versión anterior restaurada y verificada" || fallo "versión anterior restaurada, pero verificar.sh falla: revise a mano (respaldo en $RESP)."
  exit 1
}

echo "== Reemplazo"
copiar_solo_lectura "$PAQ/public" "$NUEVO_PUBLIC" && copiar_solo_lectura "$PAQ/backend" "$NUEVO_BACKEND" || { rm -rf "$NUEVO_PUBLIC" "$NUEVO_BACKEND"; abortar "No se pudo preparar la versión nueva: no se cambió nada."; }
cp -p "$ENV_FILE" "$NUEVO_BACKEND/.env" && chown root:www-data "$NUEVO_BACKEND/.env" && chmod 640 "$NUEVO_BACKEND/.env" || { rm -rf "$NUEVO_PUBLIC" "$NUEVO_BACKEND"; abortar "No se pudo conservar el .env: no se cambió nada."; }
if inyectar frontend; then false; else intercambiar "$DIR_PUBLIC" "$NUEVO_PUBLIC" "$PREV_PUBLIC"; fi || volver_atras
if inyectar backend; then false; else intercambiar "$DIR_BACKEND" "$NUEVO_BACKEND" "$PREV_BACKEND"; fi || volver_atras
ok "aplicación y backend reemplazados (el .env conserva su contenido, sus interruptores y sus permisos)"

echo "== Verificación"
salida=$(mktemp) || volver_atras; trap 'rm -f "$salida"' EXIT
if inyectar verificacion; then false; else verificar_post "$salida"; fi || volver_atras
ok "verificar.sh sin fallos"
[ "$(huella_portales)" = "$pre" ] && ok "Flutter, 443, 8443, 8088 y 8089 idénticos antes y después" || { fallo "la huella de los demás portales cambió"; volver_atras; }
rm -rf "$PREV_PUBLIC" "$PREV_BACKEND"
ok "ACTUALIZACIÓN COMPLETA. Para volver atrás: sudo bash scripts/actualizar.sh --revertir $RESP"
