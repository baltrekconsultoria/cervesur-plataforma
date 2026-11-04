#!/usr/bin/env bash
# Cervesur · UX-08H.1 · EMERGENCIA · APAGA todas las escrituras del administrador en segundos. Dependencias mínimas (bash, awk, cp, mv, curl opcional); no carga lib.sh.
#   sudo bash scripts/apagar-escrituras.sh
# QUÉ HACE (y nada más): pone ADMIN_WRITES_EMPRESAS / REPORTES / ACCESOS en 0 en el .env de forma atómica (archivo temporal en la misma carpeta + mv; conserva 640 root:www-data) y
# ELIMINA las claves ADMIN_WRITES_USUARIOS y ADMIN_WRITES_USUARIOS_SENSIBLE si existieran. Antes guarda en /var/backups/cervesur-react-admin/evidencia-apagado-<sello> (700):
#   estado-anterior.txt  (nombres de clave del .env y el valor de las ADMIN_WRITES_*; NUNCA otros valores), hora, usuario, y cola sanitizada del log de errores de Apache
#   (contraseñas, tokens, enlaces y hex largos tapados). Después comprueba /api/admin/capacidades si CERVESUR_E_USUARIO / CERVESUR_E_CLAVE están definidas (todas false); si no, DICE que
#   la verificación se omitió. mod_php relee el .env en cada petición: no hace falta recargar Apache.
# NO TOCA: datos, audit_log, MySQL, código, Apache, el resto del .env, respaldos ni otros portales. No pide confirmación (es la palanca de emergencia). Es idempotente.
# Variables de apoyo (ensayos): CERVESUR_ADMIN_BACK, CERVESUR_ADMIN_RESPALDO, CERVESUR_ADMIN_PUERTO, CERVESUR_ADMIN_CERT, CERVESUR_APACHE_ERROR_LOG, CERVESUR_ADMIN_URL.
set -uo pipefail
case ${1:-} in --ayuda|-h|--help) sed -n '2,/^set -/{/^set -/!s/^# \{0,1\}//p}' "${BASH_SOURCE[0]}"; exit 0 ;; esac
[ "$(id -u)" -eq 0 ] || { echo "FALLO  Ejecute este script con sudo." >&2; exit 1; }
BACK=${CERVESUR_ADMIN_BACK:-/opt/cervesur-react-admin}
ENVF=$BACK/backend/.env
RESPALDO=${CERVESUR_ADMIN_RESPALDO:-/var/backups/cervesur-react-admin}
PUERTO=${CERVESUR_ADMIN_PUERTO:-8093}
CERT=${CERVESUR_ADMIN_CERT:-/etc/apache2/cervesur-react-admin/tls/servidor.crt}
LOG_APACHE=${CERVESUR_APACHE_ERROR_LOG:-/var/log/apache2/cervesur-react-admin-error.log}
URL=${CERVESUR_ADMIN_URL:-https://localhost:$PUERTO}
[ -f "$ENVF" ] || { echo "FALLO  No existe $ENVF." >&2; exit 1; }

sello=$(date -u +%Y%m%d-%H%M%SZ)
EV="$RESPALDO/evidencia-apagado-$sello"
# 1. Evidencia (si falla, SE APAGA IGUAL: la evidencia nunca retrasa ni impide el apagado)
evidencia_ok=1
if install -d -m 700 "$RESPALDO" && mkdir -m 700 "$EV" 2>/dev/null; then
  {
    echo "apagado de emergencia: $sello UTC · usuario: ${SUDO_USER:-${USER:-root}}"
    echo "--- nombres de clave del .env (y valores SOLO de ADMIN_WRITES_* y del modo)"
    tr -d '\r' < "$ENVF" | awk -F= '/^[[:space:]]*#/ || NF == 0 { next } { k = $1; gsub(/^[ \t]+|[ \t]+$/, "", k); if (k ~ /^ADMIN_WRITES_/ || k == "CERVESUR_MODE" || k == "CERVESUR_REQUIRED_MODE" || k == "AUDIT_LOG_MODE") { v = $0; sub(/^[^=]*=/, "", v); print k "=" v } else print k "=(no mostrado)" }'
  } > "$EV/estado-anterior.txt" 2>/dev/null || evidencia_ok=0
  if [ -r "$LOG_APACHE" ]; then
    tail -n 200 "$LOG_APACHE" 2>/dev/null | sed -E 's#https?://[^ "]+#<enlace>#g; s/[Bb]earer [A-Za-z0-9._~+/=-]+/Bearer <token>/g; s/(pass(word)?|secret|token|clave)=[^ &"]+/\1=<oculto>/Ig; s/[0-9a-fA-F]{32,}/<hex>/g' > "$EV/apache-error-cola-sanitizada.txt" 2>/dev/null || true
  fi
  chmod 600 "$EV"/* 2>/dev/null || true
else evidencia_ok=0; echo "AVISO  No se pudo crear la carpeta de evidencia; se apaga igualmente." >&2; fi

# 2. Apagado atómico (lo único imprescindible)
tmp=$(mktemp "$BACK/backend/.env.apagado.XXXXXX") || { echo "FALLO  No se pudo crear el archivo temporal: NO se apagó nada." >&2; exit 1; }
awk '
  { linea = $0; sub(/\r$/, "", linea); pos = index(linea, "="); nombre = ""
    if (pos) { nombre = substr(linea, 1, pos - 1); gsub(/^[ \t]+|[ \t]+$/, "", nombre) }
    if (pos && substr(nombre, 1, 1) != "#") {
      if (nombre == "ADMIN_WRITES_EMPRESAS" || nombre == "ADMIN_WRITES_REPORTES" || nombre == "ADMIN_WRITES_ACCESOS") next
      if (nombre == "ADMIN_WRITES_USUARIOS" || nombre == "ADMIN_WRITES_USUARIOS_SENSIBLE") next
    }
    print linea }
  END { print "ADMIN_WRITES_EMPRESAS=0"; print "ADMIN_WRITES_REPORTES=0"; print "ADMIN_WRITES_ACCESOS=0" }' "$ENVF" > "$tmp" || { rm -f "$tmp"; echo "FALLO  No se pudo preparar el .env: NO se apagó nada." >&2; exit 1; }
if ! { chown root:www-data "$tmp" && chmod 640 "$tmp" && mv -f "$tmp" "$ENVF"; }; then rm -f "$tmp"; echo "FALLO  No se pudo instalar el .env nuevo: NO se apagó nada." >&2; exit 1; fi
echo "OK     ADMIN_WRITES_EMPRESAS/REPORTES/ACCESOS=0 y claves de usuarios eliminadas ($sello UTC)."
[ "$evidencia_ok" = 1 ] && echo "OK     evidencia en $EV (700)" || echo "AVISO  evidencia incompleta o no creada."

# 3. Verificación (si hay credenciales); si no, se dice
if [ -n "${CERVESUR_E_USUARIO:-}" ] && [ -n "${CERVESUR_E_CLAVE:-}" ] && [ -r "$CERT" ]; then
  esc() { printf '%s' "$1" | sed 's/\\/\\\\/g; s/"/\\"/g'; }
  curl_tls=(--cacert "$CERT" --resolve "localhost:$PUERTO:127.0.0.1")
  respuesta=$(printf '{"login":"%s","password":"%s"}' "$(esc "$CERVESUR_E_USUARIO")" "$(esc "$CERVESUR_E_CLAVE")" | curl -sS -m 15 "${curl_tls[@]}" -H 'Content-Type: application/json' --data @- "$URL/api/login" 2>/dev/null)
  token=$(sed -n 's/.*"token":"\([0-9a-f]\{64\}\)".*/\1/p' <<<"$respuesta" | head -1)
  if [ -n "$token" ]; then
    cap=$(curl -sS -m 15 "${curl_tls[@]}" -K <(printf 'header = "Authorization: Bearer %s"\n' "$token") "$URL/api/admin/capacidades" 2>/dev/null)
    writes=$(sed -n 's/.*"writes":{\([^}]*\)}.*/\1/p' <<<"$cap")
    if [ -n "$writes" ] && ! grep -q 'true' <<<"$writes" && grep -q '"users_sensitive":false' <<<"$cap"; then echo "OK     verificado: /api/admin/capacidades → todo false."; exit 0; fi
    echo "FALLO  /api/admin/capacidades NO muestra todo apagado: revise a mano de inmediato." >&2; exit 1
  fi
  echo "AVISO  no se pudo iniciar sesión: la verificación de capacidades se OMITIÓ (el .env sí quedó apagado)." >&2
else
  echo "AVISO  sin CERVESUR_E_USUARIO / CERVESUR_E_CLAVE: la verificación de capacidades se OMITIÓ (el .env sí quedó apagado)."
fi
exit 0
