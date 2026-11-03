#!/usr/bin/env bash
# ACTUALIZA un piloto con usuarios YA INSTALADO (127.0.0.1:8092) con una versión nueva del paquete (UX-07D.5). Reversible.
#   sudo bash scripts/actualizar.sh --simular           comprueba todo y NO escribe nada
#   sudo bash scripts/actualizar.sh                     pide escribir ACTUALIZAR-8092; respalda, reemplaza la aplicación y el backend, verifica y, si algo falla, VUELVE SOLO
#   sudo bash scripts/actualizar.sh --revertir RESPALDO restaura el respaldo indicado (carpeta creada por una actualización anterior)
# Solo reemplaza /var/www/cervesur-react-usuarios/public y /opt/cervesur-react-usuarios/backend (el .env, el certificado, la base de estado, las cuentas MySQL, la lista blanca
# y la configuración de Apache NO se tocan). No reinicia ni recarga Apache (mod_php lee el código nuevo en la siguiente petición y el .htaccess se evalúa por petición).
# No toca Flutter, 8088, 8089, 8090 ni 8091: compara la huella de todos ellos antes y después. El respaldo no incluye el .env (no se copian secretos).
set -uo pipefail
export CERVESUR_OMITIR_LINEA_BASE=1   # esta operación compara la huella de los demás portales ANTES y DESPUÉS; la línea base de la instalación puede ser anterior a otros portales
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
requerir_root

MODO=actualizar; RESPALDO_ORIGEN=""
while [ $# -gt 0 ]; do
  case $1 in
    --simular) MODO=simular; shift ;;
    --revertir) MODO=revertir; RESPALDO_ORIGEN=${2:-}; [ -n "$RESPALDO_ORIGEN" ] || abortar "--revertir necesita la carpeta del respaldo."; shift 2 ;;
    *) abortar "Argumento desconocido: $1" ;;
  esac
done

sello=$(date -u +%Y%m%d-%H%M%SZ)
NUEVO_PUBLIC="$DIR_FRONT/.nuevo-public-$sello"; NUEVO_BACKEND="$DIR_BACK/.nuevo-backend-$sello"
PREV_PUBLIC="$DIR_FRONT/.previo-public-$sello"; PREV_BACKEND="$DIR_BACK/.previo-backend-$sello"

copiar_solo_lectura() { cp -R --no-preserve=all "$1" "$2"; chown -R root:root "$2"; find "$2" -type d -exec chmod 755 {} +; find "$2" -type f -exec chmod 644 {} +; }

# Reemplazo por renombrado (dos pasos: cada uno es atómico; en medio hay un instante con la carpeta ausente, de milisegundos).
intercambiar() { # carpeta_actual carpeta_nueva carpeta_previa
  mv "$1" "$3" && mv "$2" "$1" || { [ -d "$1" ] || mv "$3" "$1"; return 1; }
}

precondiciones() {
  echo "== Precondiciones (solo lectura)"
  [ -d "$DIR_PUBLIC" ] && [ -d "$DIR_BACKEND" ] && [ -f "$ENV_FILE" ] || abortar "El piloto no está instalado (faltan $DIR_PUBLIC, $DIR_BACKEND o el .env): use instalar.sh."
  [ -e "$SITE_ENABLED" ] || abortar "El sitio $SITIO no está habilitado."
  [ "$(valor_env CERVESUR_MODE)" = "piloto_usuarios" ] && [ "$(valor_env CERVESUR_REQUIRED_MODE)" = "piloto_usuarios" ] || abortar "El .env instalado no está en modo piloto_usuarios."
  [ -z "$(valor_env CERVESUR_INSECURE_TESTS)" ] || abortar "El .env instalado tiene CERVESUR_INSECURE_TESTS: no se actualiza un sitio con HTTP de pruebas."
  [ -f "$PAQ/SHA256SUMS" ] && (cd "$PAQ" && sha256sum -c SHA256SUMS --quiet) || abortar "La integridad del paquete (SHA256SUMS) no se verifica."
  [ -d "$PAQ/public" ] && [ -d "$PAQ/backend" ] || abortar "El paquete no contiene public/ y backend/."
  ! find "$PAQ" \( -name '.env*' -o -name '*.key' -o -name '*.pem' -o -name '*.crt' \) -print -quit | grep -q . || abortar "El paquete contiene .env, claves o certificados: no es válido."
  grep -q '"pilotMode": *"usuarios"' "$PAQ/public/build-info.json" 2>/dev/null || abortar "La aplicación del paquete no es la compilación del piloto con usuarios."
  mysql_activo || abortar "MySQL no está activo."
  curl -sS -m 10 --cacert "$CERT_FILE" --resolve "localhost:$PUERTO:127.0.0.1" "https://localhost:$PUERTO/api/ping" | grep -q '"pong":true' || abortar "El piloto actual no responde por HTTPS: no se actualiza algo que ya está caído (revise primero con verificar.sh)."
  # UX-08G.1: sintaxis de TODO el backend del paquete ANTES de tocar nada (un archivo roto no debe llegar a producción).
  if command -v php >/dev/null 2>&1; then
    while IFS= read -r f; do php -l "$f" >/dev/null 2>&1 || abortar "Error de sintaxis PHP en el paquete (${f#"$PAQ"/}): no se actualiza nada."; done < <(find "$PAQ/backend" -name '*.php' -not -path '*/vendor/*' | LC_ALL=C sort)
    ok "sintaxis PHP correcta en todo el backend del paquete"
  else aviso "php (CLI) no disponible: no se pudo comprobar la sintaxis del backend del paquete"; fi
  ok "piloto instalado, sano y en modo piloto_usuarios; paquete íntegro y sin secretos"
  if ! cmp -s "$PAQ/apache/react-usuarios-common.conf" "$DIR_CONF/react-usuarios-common.conf" || ! cmp -s "$PAQ/apache/cervesur-react-usuarios.conf" "$SITE_CONF"; then
    aviso "la configuración de Apache del paquete DIFIERE de la instalada. Esta actualización NO la cambia (requiere aprobación específica y una recarga)."
  else ok "la configuración de Apache instalada coincide con la del paquete"; fi
  ss -ltnH "sport = :$PUERTO" | awk '{print $4}' | grep -qvE "^127\.0\.0\.1:$PUERTO\$" && abortar "El puerto $PUERTO escucha fuera de loopback."
  ok "el puerto $PUERTO sigue solo en loopback"
}

espera_opcache() { sleep "${CERVESUR_ESPERA_OPCACHE:-4}"; }   # UX-08G.1: opcache revalida cada ~2 s; hay que esperar antes de verificar CUALQUIER cambio de código

verificar_post() {
  # UX-08G.1: opcache (mod_php) revalida los archivos cada ~2 s; sin esta espera la verificación puede atender código ANTIGUO en caché y dar por buena una versión rota.
  espera_opcache
  bash "$PAQ/scripts/verificar.sh" >"$1" 2>&1; local rc=$?
  [ "$rc" -eq 0 ] || { grep -E '^FAIL' "$1" | head -10; return 1; }
  return 0
}

if [ "$MODO" = "revertir" ]; then
  [ -d "$RESPALDO_ORIGEN" ] && [ -f "$RESPALDO_ORIGEN/public.tgz" ] && [ -f "$RESPALDO_ORIGEN/backend.tgz" ] || abortar "El respaldo indicado no es válido."
  (cd "$RESPALDO_ORIGEN" && sha256sum -c SHA256SUMS --quiet) || abortar "El respaldo está corrupto (SHA256SUMS)."
  if [ "${CERVESUR_USUARIOS_CONFIRMAR:-}" != "SI" ]; then
    read -r -p "Escriba REVERTIR-ACTUALIZACION-8092 para restaurar la versión anterior: " c; [ "$c" = "REVERTIR-ACTUALIZACION-8092" ] || abortar "Cancelado. No se modificó nada."
  fi
  pre=$(huella_portales)
  tmp=$(mktemp -d "$DIR_FRONT/.restaurar-XXXXXX"); trap 'rm -rf "$tmp"' EXIT
  tar xzf "$RESPALDO_ORIGEN/public.tgz" -C "$tmp" && tar xzf "$RESPALDO_ORIGEN/backend.tgz" -C "$tmp" || abortar "No se pudo extraer el respaldo."
  [ -d "$tmp/public" ] && [ -d "$tmp/backend" ] || abortar "El respaldo no tiene la estructura esperada."
  cp -p "$ENV_FILE" "$tmp/backend/.env"
  mv "$DIR_PUBLIC" "$PREV_PUBLIC" && mv "$tmp/public" "$DIR_PUBLIC" && mv "$DIR_BACKEND" "$PREV_BACKEND" && mv "$tmp/backend" "$DIR_BACKEND" || abortar "Falló el intercambio; revise $PREV_PUBLIC y $PREV_BACKEND."
  chown root:www-data "$ENV_FILE"; chmod 640 "$ENV_FILE"
  rm -rf "$PREV_PUBLIC" "$PREV_BACKEND"
  [ "$(huella_portales)" = "$pre" ] && ok "los demás portales siguen idénticos" || aviso "la huella de los demás portales cambió: revise (no la causa este script)."
  espera_opcache; bash "$PAQ/scripts/verificar.sh" >/dev/null 2>&1 && ok "versión anterior restaurada y verificada" || aviso "versión restaurada, pero verificar.sh reporta fallos: revise con la salida completa."
  exit 0
fi

precondiciones
pre=$(huella_portales)
echo "== Qué se reemplazaría"
echo "   $DIR_PUBLIC   ← $PAQ/public"
echo "   $DIR_BACKEND  ← $PAQ/backend   (el .env se conserva tal cual)"
echo "   respaldo en   $DIR_RESPALDO/actualizacion-$sello (sin .env)"
if [ "$MODO" = "simular" ]; then ok "SIMULACIÓN terminada: no se escribió nada. Código 0 = se puede actualizar."; exit 0; fi

if [ "${CERVESUR_USUARIOS_CONFIRMAR:-}" != "SI" ]; then
  read -r -p "Escriba ACTUALIZAR-8092 para continuar: " conf; [ "$conf" = "ACTUALIZAR-8092" ] || abortar "Cancelado. No se modificó nada."
fi

echo "== Respaldo"
RESP="$DIR_RESPALDO/actualizacion-$sello"
install -d -m 700 "$DIR_RESPALDO" "$RESP"
tar czf "$RESP/public.tgz" -C "$DIR_FRONT" public || abortar "No se pudo respaldar la aplicación."
tar czf "$RESP/backend.tgz" -C "$DIR_BACK" --exclude=backend/.env backend || abortar "No se pudo respaldar el backend."
(cd "$RESP" && sha256sum public.tgz backend.tgz > SHA256SUMS && chmod 600 ./* )
printf 'actualizacion %s\npaquete %s\n' "$sello" "$(basename "$PAQ")" > "$RESP/INFO.txt"; chmod 600 "$RESP/INFO.txt"
ok "respaldo en $RESP (permisos 700/600; los respaldos no se borran jamás; no contiene el .env)"

volver_atras() {
  fallo "La actualización falló: se restaura la versión anterior."
  [ -d "$PREV_PUBLIC" ] && { rm -rf "$DIR_PUBLIC"; mv "$PREV_PUBLIC" "$DIR_PUBLIC"; }
  [ -d "$PREV_BACKEND" ] && { rm -rf "$DIR_BACKEND"; mv "$PREV_BACKEND" "$DIR_BACKEND"; }
  rm -rf "$NUEVO_PUBLIC" "$NUEVO_BACKEND"
  espera_opcache; bash "$PAQ/scripts/verificar.sh" >/dev/null 2>&1 && ok "versión anterior restaurada y verificada" || fallo "versión anterior restaurada, pero verificar.sh falla: revise a mano (respaldo en $RESP)."
  exit 1
}

echo "== Reemplazo"
copiar_solo_lectura "$PAQ/public" "$NUEVO_PUBLIC" && copiar_solo_lectura "$PAQ/backend" "$NUEVO_BACKEND" || { rm -rf "$NUEVO_PUBLIC" "$NUEVO_BACKEND"; abortar "No se pudo preparar la versión nueva: no se cambió nada."; }
cp -p "$ENV_FILE" "$NUEVO_BACKEND/.env" && chown root:www-data "$NUEVO_BACKEND/.env" && chmod 640 "$NUEVO_BACKEND/.env" || { rm -rf "$NUEVO_PUBLIC" "$NUEVO_BACKEND"; abortar "No se pudo conservar el .env: no se cambió nada."; }
intercambiar "$DIR_PUBLIC" "$NUEVO_PUBLIC" "$PREV_PUBLIC" || volver_atras
intercambiar "$DIR_BACKEND" "$NUEVO_BACKEND" "$PREV_BACKEND" || volver_atras
ok "aplicación y backend reemplazados (el .env conserva su contenido y permisos)"

echo "== Verificación"
salida=$(mktemp); trap 'rm -f "$salida"' EXIT
verificar_post "$salida" || volver_atras
ok "verificar.sh sin fallos"
[ "$(huella_portales)" = "$pre" ] && ok "Flutter, 8088, 8089, 8090 y 8091 idénticos antes y después" || { fallo "la huella de los demás portales cambió"; volver_atras; }
rm -rf "$PREV_PUBLIC" "$PREV_BACKEND"
ok "ACTUALIZACIÓN COMPLETA. Para volver atrás: sudo bash scripts/actualizar.sh --revertir $RESP"
