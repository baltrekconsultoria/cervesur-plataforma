#!/usr/bin/env bash
# Cervesur · UX-08H.1 · PASO A1 · ACTUALIZA el administrador React (8443/8093) de la FASE 1 a la FASE 2A cambiando SOLO EL CÓDIGO. Reversible.
#   sudo bash scripts/actualizar.sh --simular            comprueba todo y NO escribe nada
#   sudo bash scripts/actualizar.sh                      pide escribir ACTUALIZAR-ADMIN-FASE2A; respalda (SHA-256 verificado), reemplaza, verifica y, si algo falla, VUELVE SOLO
#   sudo bash scripts/actualizar.sh --revertir [RESPALDO]  delega en revertir-codigo.sh (restaura el código de un respaldo verificado)
#   Opciones: --sin-credenciales  no hace login en la verificación (verificación PARCIAL: queda declarada como tal)
# Credenciales de la verificación (nunca por argumentos): variables de entorno CERVESUR_E_USUARIO y CERVESUR_E_CLAVE de un administrador autorizado del 8443.
#
# QUÉ HACE: reemplaza /var/www/cervesur-react-admin/public y /opt/cervesur-react-admin/backend por los del paquete. El .env se CONSERVA TAL CUAL (sigue en modo piloto_admin,
# estrictamente de solo lectura). Con el código nuevo y el .env viejo el comportamiento debe ser EL DE LA FASE 1: login y lecturas funcionan, TODA ruta de escritura sigue en 403 y
# /api/admin/capacidades NO existe (403) en piloto_admin. Es lo que comprueba la verificación posterior; si algo falla, el código anterior se restaura automáticamente.
# QUÉ NO HACE: no toca el .env (eso es A2: modo-escritura.sh), Apache (ni recarga ni reinicia: mod_php lee el código nuevo en la siguiente petición), MySQL, Flutter (80), trabajadores
# (443) ni la QA (8094); no ejecuta migraciones ni crea cuentas; no borra jamás respaldos anteriores. No usa el revertir.sh del piloto (es un desinstalador).
# Respaldo: /var/backups/cervesur-react-admin/actualizacion-<sello> (700; public.tgz y backend.tgz 600, SHA256SUMS, sin .env). Se comprueba antes de reemplazar nada.
# Inyección de fallos SOLO PARA ENSAYOS: CERVESUR_INYECTAR_FALLO=respaldo|manifiesto|respaldo-corrupto|frontend|backend|verificacion (solo hace que el paso FALLE).
set -uo pipefail
case ${1:-} in --ayuda|-h|--help) sed -n '2,/^set -/{/^set -/!s/^# \{0,1\}//p}' "${BASH_SOURCE[0]}"; exit 0 ;; esac
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
requerir_root

MODO=actualizar; SIN_CRED=0
while [ $# -gt 0 ]; do
  case $1 in
    --simular) MODO=simular; shift ;;
    --sin-credenciales) SIN_CRED=1; shift ;;
    --revertir) shift; if [ $# -gt 0 ]; then exec bash "$PAQ/scripts/revertir-codigo.sh" --respaldo "$1"; else exec bash "$PAQ/scripts/revertir-codigo.sh"; fi ;;
    *) abortar "Argumento desconocido: $1 (use --ayuda)" ;;
  esac
done

sello=$(sello_utc)
NUEVO_PUBLIC="$DIR_FRONT/.nuevo-public-$sello"; NUEVO_BACKEND="$DIR_BACK/.nuevo-backend-$sello"
PREV_PUBLIC="$DIR_FRONT/.previo-public-$sello"; PREV_BACKEND="$DIR_BACK/.previo-backend-$sello"
MANIFIESTO_BASE="$PAQ/base/MANIFIESTO-FASE1-$BASE_FASE1_COMMIT.sha256"
copiar_solo_lectura() { cp -R --no-preserve=all "$1" "$2" && chown -R root:root "$2" && find "$2" -type d -exec chmod 755 {} + && find "$2" -type f -exec chmod 644 {} +; }
intercambiar() { # actual nueva previa: dos renombrados; si falla el segundo, se deshace el primero
  mv "$1" "$3" && mv "$2" "$1" || { [ -d "$1" ] || mv "$3" "$1"; return 1; }
}
huella_apache() { { find /etc/apache2 \( -type f -o -type l \) -print0 | LC_ALL=C sort -z | xargs -0 sha256sum 2>/dev/null; find /etc/apache2 -type l -printf '%p -> %l\n' | LC_ALL=C sort; } | sha256sum | cut -c1-64; }
# ¿Lo instalado coincide archivo por archivo con un manifiesto (backend/ y public/; sin .env, sin sobrantes)?
identidad_con() { # manifiesto
  local m=$1 lb lp
  lb=$(grep -E '^[0-9a-f]{64} [ *](./)?backend/' "$m") && lp=$(grep -E '^[0-9a-f]{64} [ *](./)?public/' "$m") || return 1
  (cd "$DIR_BACK" && sha256sum -c --quiet <<<"$lb") >/dev/null 2>&1 && (cd "$DIR_FRONT" && sha256sum -c --quiet <<<"$lp") >/dev/null 2>&1 || return 1
  [ "$(find "$DIR_BACKEND" -type f ! -path "$ENV_FILE" | wc -l)" = "$(wc -l <<<"$lb")" ] && [ "$(find "$DIR_PUBLIC" -type f | wc -l)" = "$(wc -l <<<"$lp")" ]
}

precondiciones() {
  echo "== Precondiciones (solo lectura)"
  [ -d "$DIR_PUBLIC" ] && [ -d "$DIR_BACKEND" ] && [ -f "$ENV_FILE" ] || abortar "El administrador no está instalado (faltan $DIR_PUBLIC, $DIR_BACKEND o el .env): use el instalador de la fase 1."
  [ -e "$SITE_ENABLED" ] || abortar "El sitio $SITIO no está habilitado."
  [ "$(env_valor_actual CERVESUR_MODE)" = piloto_admin ] && [ "$(env_valor_actual CERVESUR_REQUIRED_MODE)" = piloto_admin ] || abortar "El .env no está en modo piloto_admin: A1 solo actualiza código sobre la fase 1. (¿Ya se hizo A2? Para volver, use revertir-codigo.sh.)"
  prohibidas=$(env_claves_prohibidas | paste -sd, -)
  [ -z "$prohibidas" ] || abortar "El .env contiene claves prohibidas ($prohibidas): la fase 2B sigue bloqueada y no se actualiza un sitio con HTTP de pruebas. Corríjalo antes."
  for m in EMPRESAS REPORTES ACCESOS; do [ "$(env_valor_actual "ADMIN_WRITES_$m")" != 1 ] || abortar "ADMIN_WRITES_$m=1 en un .env de piloto_admin: estado incoherente."; done
  [ -f "$PAQ/SHA256SUMS" ] && (cd "$PAQ" && sha256sum -c SHA256SUMS --quiet) || abortar "La integridad del paquete (SHA256SUMS) no se verifica."
  [ -d "$PAQ/public" ] && [ -d "$PAQ/backend" ] && [ -f "$MANIFIESTO_BASE" ] || abortar "El paquete no contiene public/, backend/ y el manifiesto de la fase 1."
  prohibidos=$(find "$PAQ" \( -name '.env*' -o -name '*.key' -o -name '*.pem' -o -name '*.crt' \) -print -quit); [ -z "$prohibidos" ] || abortar "El paquete contiene .env, claves o certificados: no es válido."
  grep -q '"pilotMode": *"admin-write"' "$PAQ/public/build-info.json" 2>/dev/null || abortar "La aplicación del paquete no es la compilación admin-write."
  mysql_activo || abortar "MySQL no está activo."
  respuesta=$(curl -sS -m 10 "${CURL_TLS[@]}" "$URL_ADMIN/api/ping"); grep -q '"pong":true' <<<"$respuesta" || abortar "El administrador actual no responde por HTTPS: no se actualiza algo que ya está caído."
  # Identidad EN DISCO de lo instalado: debe ser exactamente la fase 1 (o ya el candidato).
  if identidad_con "$PAQ/SHA256SUMS"; then ok "lo instalado YA es este paquete: no hay nada que actualizar."; exit 0; fi
  identidad_con "$MANIFIESTO_BASE" || abortar "Lo instalado NO coincide archivo por archivo con la fase 1 ($BASE_FASE1_COMMIT): hay deriva o cambios locales. NO-GO hasta clasificarlos (preflight-fase2a.sh los lista)."
  ok "lo instalado es exactamente la fase 1 ($BASE_FASE1_COMMIT)"
  if command -v php >/dev/null 2>&1; then
    while IFS= read -r f; do php -l "$f" >/dev/null 2>&1 || abortar "Error de sintaxis PHP en el paquete (${f#"$PAQ"/}): no se actualiza nada."; done < <(find "$PAQ/backend" -name '*.php' -not -path '*/vendor/*' | LC_ALL=C sort)
    ok "sintaxis PHP correcta en todo el backend del paquete"
  else aviso "php (CLI) no disponible: no se pudo comprobar la sintaxis del paquete"; fi
  if [ "$SIN_CRED" = 0 ]; then credenciales_presentes || abortar "Faltan CERVESUR_E_USUARIO / CERVESUR_E_CLAVE: sin login la verificación del comportamiento queda incompleta. Defínalas o use --sin-credenciales (verificación PARCIAL)."
  else aviso "--sin-credenciales: la verificación NO hará login (comprobaciones de API autenticadas omitidas)."; fi
  escuchas=$(ss -ltnH "sport = :$PUERTO" | awk '{print $4}'); [ -n "$escuchas" ] || abortar "Nada escucha en el puerto $PUERTO."
  ! grep -qvE "^127\.0\.0\.1:$PUERTO\$" <<<"$escuchas" || abortar "El puerto $PUERTO escucha fuera de loopback."
  ok "el puerto $PUERTO sigue solo en loopback"
  bash "$PAQ/scripts/comprobar-otros-portales.sh" >/dev/null 2>&1 || abortar "Flutter (80), trabajadores (443) o la QA (8094) no responden: se detiene antes de tocar nada."
  ok "Flutter (80), trabajadores (443) y QA (8094) responden"
}

identidad_disco() { # lo instalado == SHA256SUMS del paquete (todo backend/ y public/)
  local lista
  lista=$(grep -E '^[0-9a-f]{64} [ *](./)?backend/' "$PAQ/SHA256SUMS") && [ -n "$lista" ] && (cd "$DIR_BACK" && sha256sum -c --quiet <<<"$lista") >/dev/null 2>&1 || return 1
  lista=$(grep -E '^[0-9a-f]{64} [ *](./)?public/' "$PAQ/SHA256SUMS") && [ -n "$lista" ] && (cd "$DIR_FRONT" && sha256sum -c --quiet <<<"$lista") >/dev/null 2>&1
}
verificar_post() { # $1 = archivo de salida
  espera_opcache
  identidad_disco || { echo "FAIL identidad en disco: lo instalado NO coincide con el SHA256SUMS del paquete" > "$1"; return 1; }
  CERVESUR_OMITIR_LINEA_BASE=1 bash "$PAQ/scripts/verificar-fase1.sh" >"$1" 2>&1; local rc=$?
  [ "$rc" -eq 0 ] || { grep -E '^FAIL' "$1" | head -10; return 1; }
  local extra=(); [ "$SIN_CRED" = 1 ] && extra=(--sin-credenciales)
  bash "$PAQ/scripts/verificar-posdespliegue.sh" --etapa A1 "${extra[@]}" >>"$1" 2>&1; rc=$?
  [ "$rc" -eq 0 ] || { grep -E '^FAIL' "$1" | head -10; return 1; }
  return 0
}

precondiciones
env_antes=$(sha256sum < "$ENV_FILE"); apache_antes=$(huella_apache)
echo "== Qué se reemplazaría"
echo "   $DIR_PUBLIC   ← $PAQ/public"
echo "   $DIR_BACKEND  ← $PAQ/backend   (el .env se conserva tal cual: sigue en piloto_admin)"
echo "   respaldo en   $DIR_RESPALDO/actualizacion-$sello (sin .env)"
if [ "$MODO" = simular ]; then ok "SIMULACIÓN terminada: no se escribió nada. Código 0 = se puede actualizar."; exit 0; fi

if [ "${CERVESUR_CONFIRMAR_ACTUALIZAR:-}" != "SI" ]; then
  read -r -p "Escriba ACTUALIZAR-ADMIN-FASE2A para continuar: " conf; [ "$conf" = "ACTUALIZAR-ADMIN-FASE2A" ] || abortar "Cancelado. No se modificó nada."
fi
tmp_otros=$(mktemp) || abortar "No se pudo crear un archivo temporal."
salida=$(mktemp) || abortar "No se pudo crear un archivo temporal."
trap 'rm -f "$tmp_otros" "$salida"' EXIT
bash "$PAQ/scripts/comprobar-otros-portales.sh" --guardar "$tmp_otros" >/dev/null 2>&1 || abortar "No se pudo guardar la huella de los otros portales: no se modificó nada."

# ---- Respaldo: NINGÚN reemplazo empieza sin un respaldo creado, completo y verificado.
echo "== Respaldo"
RESP="$DIR_RESPALDO/actualizacion-$sello"
respaldo_fallo() { abortar "Respaldo NO creado o no verificable ($1): NO se modificó la aplicación ni el backend publicados. Los respaldos anteriores no se tocaron; lo que haya quedado en $RESP es evidencia y no se borra."; }
if inyectar respaldo; then false; else install -d -m 700 "$DIR_RESPALDO"; fi || respaldo_fallo "no se pudo crear $DIR_RESPALDO"
[ ! -e "$RESP" ] || respaldo_fallo "ya existe $RESP (no se sobrescribe un respaldo)"
mkdir -m 700 "$RESP" || respaldo_fallo "no se pudo crear $RESP"
tar czf "$RESP/public.tgz" -C "$DIR_FRONT" public || respaldo_fallo "tar de la aplicación"
tar czf "$RESP/backend.tgz" -C "$DIR_BACK" --exclude=backend/.env backend || respaldo_fallo "tar del backend"
if inyectar manifiesto; then false; else (cd "$RESP" && sha256sum public.tgz backend.tgz > SHA256SUMS); fi || respaldo_fallo "no se pudo generar SHA256SUMS"
if inyectar respaldo-corrupto; then echo "x" >> "$RESP/public.tgz"; fi
chmod 600 "$RESP/public.tgz" "$RESP/backend.tgz" "$RESP/SHA256SUMS" || respaldo_fallo "permisos de los archivos del respaldo"
{ printf 'actualizacion %s\nfase A1 (solo codigo; .env intacto, modo piloto_admin)\npaquete %s\nbase %s\n' "$sello" "$(basename "$PAQ")" "$BASE_FASE1_COMMIT"; printf 'env-sha256 %s\n' "${env_antes%% *}"; } > "$RESP/INFO.txt" && chmod 600 "$RESP/INFO.txt" || respaldo_fallo "INFO.txt"
[ "$(wc -l < "$RESP/SHA256SUMS")" = "2" ] || respaldo_fallo "el manifiesto SHA256SUMS no tiene exactamente 2 entradas"
(cd "$RESP" && sha256sum -c SHA256SUMS --quiet) >/dev/null 2>&1 || respaldo_fallo "SHA-256 de public.tgz o backend.tgz no coincide"
[ "$(stat -c %a "$RESP"):$(stat -c %a "$RESP/public.tgz"):$(stat -c %a "$RESP/backend.tgz"):$(stat -c %a "$RESP/SHA256SUMS")" = "700:600:600:600" ] || respaldo_fallo "permisos 700/600 incorrectos"
lista_pub=$(tar tzf "$RESP/public.tgz") || respaldo_fallo "no se puede listar public.tgz"
lista_bk=$(tar tzf "$RESP/backend.tgz") || respaldo_fallo "no se puede listar backend.tgz"
grep -q '^public/' <<<"$lista_pub" && grep -q '^backend/' <<<"$lista_bk" || respaldo_fallo "estructura de los archivos tar"
[ "$(grep -vc '/$' <<<"$lista_pub")" = "$(find "$DIR_PUBLIC" -type f | wc -l)" ] || respaldo_fallo "public.tgz no contiene todos los archivos de la aplicación"
[ "$(grep -vc '/$' <<<"$lista_bk")" = "$(find "$DIR_BACKEND" -type f ! -path "$ENV_FILE" | wc -l)" ] || respaldo_fallo "backend.tgz no contiene todos los archivos del backend"
! grep -q '/\.env' <<<"$lista_bk" || respaldo_fallo "el respaldo del backend contiene un .env"
ok "respaldo en $RESP: SHA-256 de ambos archivos verificado, completo, permisos 700/600, sin .env; los respaldos no se borran jamás"

volver_atras() {
  fallo "La actualización falló: se restaura el código anterior (el .env no se tocó)."
  [ -s "$salida" ] && cp "$salida" "$RESP/verificacion-fallida.log" 2>/dev/null && chmod 600 "$RESP/verificacion-fallida.log" 2>/dev/null
  [ -d "$PREV_PUBLIC" ] && { rm -rf "$DIR_PUBLIC"; mv "$PREV_PUBLIC" "$DIR_PUBLIC"; }
  [ -d "$PREV_BACKEND" ] && { rm -rf "$DIR_BACKEND"; mv "$PREV_BACKEND" "$DIR_BACKEND"; }
  rm -rf "$NUEVO_PUBLIC" "$NUEVO_BACKEND"
  espera_opcache
  if identidad_con "$MANIFIESTO_BASE" && CERVESUR_OMITIR_LINEA_BASE=1 bash "$PAQ/scripts/verificar-fase1.sh" >/dev/null 2>&1; then ok "código de la fase 1 restaurado (idéntico al manifiesto) y verificado"
  else fallo "código anterior restaurado, pero la comprobación posterior falla: revise a mano (respaldo en $RESP)."; fi
  exit 1
}

echo "== Reemplazo"
copiar_solo_lectura "$PAQ/public" "$NUEVO_PUBLIC" && copiar_solo_lectura "$PAQ/backend" "$NUEVO_BACKEND" || { rm -rf "$NUEVO_PUBLIC" "$NUEVO_BACKEND"; abortar "No se pudo preparar la versión nueva: no se cambió nada."; }
cp -p "$ENV_FILE" "$NUEVO_BACKEND/.env" && chown root:www-data "$NUEVO_BACKEND/.env" && chmod 640 "$NUEVO_BACKEND/.env" || { rm -rf "$NUEVO_PUBLIC" "$NUEVO_BACKEND"; abortar "No se pudo conservar el .env: no se cambió nada."; }
if inyectar frontend; then false; else intercambiar "$DIR_PUBLIC" "$NUEVO_PUBLIC" "$PREV_PUBLIC"; fi || volver_atras
if inyectar backend; then false; else intercambiar "$DIR_BACKEND" "$NUEVO_BACKEND" "$PREV_BACKEND"; fi || volver_atras
ok "aplicación y backend reemplazados (el .env conserva su contenido y permisos)"

echo "== Verificación (comportamiento de la fase 1 con el código nuevo)"
if inyectar verificacion; then echo "FAIL verificación forzada (ensayo)" > "$salida"; false; else verificar_post "$salida"; fi || volver_atras
ok "verificar-fase1.sh y verificar-posdespliegue --etapa A1 sin fallos"
[ "$(sha256sum < "$ENV_FILE")" = "$env_antes" ] && [ "$(stat -c '%a %U:%G' "$ENV_FILE")" = "640 root:www-data" ] || { fallo "el .env cambió o perdió sus permisos"; volver_atras; }
ok "el .env quedó exactamente igual (modo piloto_admin, 640 root:www-data)"
[ "$(huella_apache)" = "$apache_antes" ] || { fallo "la configuración de Apache cambió (no la causa este script)"; volver_atras; }
ok "Apache byte a byte idéntico (sin reinicio ni recarga)"
bash "$PAQ/scripts/comprobar-otros-portales.sh" --comparar "$tmp_otros" || { fallo "la huella de los otros portales cambió"; volver_atras; }
rm -rf "$PREV_PUBLIC" "$PREV_BACKEND"
ok "A1 COMPLETO. El .env sigue en piloto_admin. Siguiente: migraciones y cuenta (DBA) y luego modo-escritura.sh (A2). Para volver atrás: sudo bash scripts/revertir-codigo.sh --respaldo $RESP"
