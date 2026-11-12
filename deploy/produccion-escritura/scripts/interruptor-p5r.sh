#!/usr/bin/env bash
# Cervesur P5-R: habilita SOLO el restablecimiento de password para usuarios estandar.
# Nunca habilita ADMIN_WRITES_USUARIOS_SENSIBLE (P5 general/roles).
# Uso: estado | encender --autorizacion "DBA, funcional, fecha" | apagar
set -uo pipefail
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
# 8443 es el sitio productivo de administracion actual. El lib heredado usa 8093.
# Nunca tomar silenciosamente su puerto: podria verificar otro portal.
P5R_API_PORT="${CERVESUR_P5R_API_PORT:-8443}"
case "$P5R_API_PORT" in 8443) ;; *) abortar "Puerto de API P5-R no autorizado (solo 8443)";; esac
# Certificado corporativo SAN IP 192.168.200.12 firmado por Cervesur CA interna.
# Exigir cadena de confianza; no utilizar certificado de prueba localhost ni -k.
P5R_CA_FILE="${CERVESUR_P5R_CA_FILE:-/etc/ssl/certs/ca-certificates.crt}"
URL_ADMIN="https://192.168.200.12:$P5R_API_PORT"
CURL_TLS=(--noproxy '*' --cacert "$P5R_CA_FILE")
trap api_limpiar EXIT

ACCION="${1:-}"; shift || true
AUTORIZACION=""
while [ $# -gt 0 ]; do
  case $1 in
    --autorizacion) AUTORIZACION="${2:-}"; shift 2 ;;
    *) echo "P5R_ERROR=ARGUMENTO_INVALIDO" >&2; exit 2 ;;
  esac
done
case "$ACCION" in estado|encender|apagar) ;; *) echo "Uso: estado | encender --autorizacion TEXTO | apagar" >&2; exit 2 ;; esac
if [ "$ACCION" = estado ]; then
  echo "P5R_USUARIOS=$(env_valor_actual ADMIN_WRITES_USUARIOS)"
  echo "P5R_PASSWORD_RESET=$(env_valor_actual ADMIN_WRITES_USUARIOS_PASSWORD_RESET)"
  echo "P5R_SENSIBLE=$(env_valor_actual ADMIN_WRITES_USUARIOS_SENSIBLE)"
  exit 0
fi
requerir_root
[ -r "$P5R_CA_FILE" ] || abortar "No puede leer la CA confiable de Cervesur para verificar TLS 8443."

# Verifica SOLO capacidades devueltas por el backend admin HTTPS, no usa JWT heredado.
p5r_capacidades() { # esperado: true | false
  credenciales_presentes || return 1
  api_login || return 1
  capacidades_leer || return 1
  [ "$CAP_usuarios" = true ] && [ "$CAP_SENSIBLE" = false ] || return 1
  api_pedir GET /api/admin/capacidades
  [ "$API_STATUS" = 200 ] || return 1
  grep -Eq '"users_password_reset"[[:space:]]*:[[:space:]]*'"$1" <<<"$API_CUERPO"
}

if [ "$ACCION" = apagar ]; then
  env_respaldar "antes-apagar-p5r" || { echo "P5R_OFF=NO_GO_RESPALDO"; exit 1; }
  env_aplicar "" "ADMIN_WRITES_USUARIOS_PASSWORD_RESET" || { echo "P5R_OFF=NO_GO_ENV"; exit 1; }
  echo "P5R_OFF=PASS_FLAG_ELIMINADO"
  # No reactivar ante error de API: dejar P5R apagado es mas seguro.
  if credenciales_presentes; then
    espera_opcache
    p5r_capacidades false && echo "P5R_OFF_API=PASS" || echo "P5R_OFF_API=PENDIENTE_REVISION"
  fi
  exit 0
fi

[ "${CERVESUR_CONFIRMAR_P5R:-}" = SI ] || abortar "Falta CERVESUR_CONFIRMAR_P5R=SI (autorizacion real DBA/funcional)."
[ "${#AUTORIZACION}" -ge 10 ] || abortar "--autorizacion debe identificar aprobadores, fecha y alcance."
[ "$(env_valor_actual CERVESUR_MODE)" = admin_escritura ] || abortar "P5-R requiere modo admin_escritura."
[ "$(env_valor_actual ADMIN_WRITES_USUARIOS)" = 1 ] || abortar "P5-R requiere U-BASIC activo."
[ "$(env_valor_actual ADMIN_WRITES_USUARIOS_SENSIBLE)" != 1 ] || abortar "P5 general debe permanecer apagado."
[ "$(env_valor_actual ADMIN_WRITES_USUARIOS_PASSWORD_RESET)" != 1 ] || abortar "P5-R ya encendido."
credenciales_presentes || abortar "Faltan credenciales del administrador 8443 para verificar capacidades."
bash "$PAQ/scripts/privilegios-usuarios.sh" --etapa password-reset --verificar | tail -4 | grep -q "RESULTADO-PRIVILEGIOS: PASS password-reset" || abortar "GRANT exclusivo de password no verificado."
p5r_capacidades false || abortar "El backend no declara U-BASIC ON, sensible OFF, P5-R OFF."
env_respaldar "antes-encender-p5r" || abortar "No se pudo respaldar .env"
PREVIO="$ENV_RESPALDO_RUTA"
env_aplicar "ADMIN_WRITES_USUARIOS_PASSWORD_RESET=1" "" || { env_instalar "$PREVIO"; abortar "No se pudo fijar el flag P5-R"; }
espera_opcache
if ! p5r_capacidades true; then
  env_instalar "$PREVIO" && echo "P5R_ROLLBACK_ENV=PASS" || echo "P5R_ROLLBACK_ENV=NO_GO_MANUAL"
  echo "P5R_ACTIVACION=NO_GO_CAPACIDADES"
  exit 1
fi
echo "P5R_ACTIVACION=PASS"
echo "P5R_USUARIOS=ON"
echo "P5R_PASSWORD_RESET=ON"
echo "P5R_CAMBIO_ROLES=OFF"
echo "P5R_AUTORIZACION_REGISTRADA=REFERENCIA_NO_VERIFICADA_AUTOMATICAMENTE"
