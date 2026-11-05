#!/usr/bin/env bash
# Cervesur · UX-08I · FASE 2B · Interruptor de Usuarios en el .env del 8443. NADIE lo ha ejecutado en producción. Por omisión Usuarios está APAGADO.
#   sudo bash scripts/interruptor-usuarios.sh estado
#   sudo bash scripts/interruptor-usuarios.sh apagar                              apaga TODO lo de usuarios (también lo sensible). Siempre permitido; no necesita autorización.
#   sudo bash scripts/interruptor-usuarios.sh encender --autorizacion "TEXTO"     enciende SOLO lo no sensible (alta, datos, desactivar/reactivar). Exige CERVESUR_CONFIRMAR_USUARIOS=SI
#   sudo bash scripts/interruptor-usuarios.sh sensible                            NO DISPONIBLE: rol y contraseña (P5) siguen apagados hasta su aprobación y pruebas específicas
# Para encender exige, en este orden: código 2B instalado (el backend responde con «fase_2b_bloqueada»), la etapa de privilegios «no-sensibles» verificada
# (privilegios-usuarios.sh --verificar), módulos empresas/reportes/accesos encendidos y verificados, y respaldo del .env. Después verifica las capacidades; si no son las esperadas, apaga solo.
# Credenciales para verificar capacidades (solo entorno): CERVESUR_E_USUARIO / CERVESUR_E_CLAVE. Sin ellas NO se enciende (no habría forma de comprobar el resultado).
# Salida: 0 bien · 1 bloqueado/fallo · 2 uso
set -uo pipefail
case ${1:-} in --ayuda|-h|--help|'') sed -n '2,/^set -/{/^set -/!s/^# \{0,1\}//p}' "${BASH_SOURCE[0]}"; exit 0 ;; esac
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
trap api_limpiar EXIT

ACCION=$1; shift
AUTORIZACION=""
while [ $# -gt 0 ]; do
  case $1 in
    --autorizacion) AUTORIZACION=${2:-}; shift 2 ;;
    *) echo "Argumento desconocido: $1 (use --ayuda)" >&2; exit 2 ;;
  esac
done
case $ACCION in estado|apagar|encender|sensible) ;; *) echo "Acción desconocida: $ACCION" >&2; exit 2 ;; esac
[ "$ACCION" = estado ] || requerir_root

valor() { local v; v=$(env_valor_actual "$1"); printf '%s' "${v:-<sin definir>}"; }
mostrar() { echo "   ADMIN_WRITES_USUARIOS=$(valor ADMIN_WRITES_USUARIOS)   ADMIN_WRITES_USUARIOS_SENSIBLE=$(valor ADMIN_WRITES_USUARIOS_SENSIBLE)   ADMIN_USER_REACTIVATE_HOURS=$(valor ADMIN_USER_REACTIVATE_HOURS)"; }

verificar_capacidades() { # esperado de usuarios: true|false (sensible SIEMPRE false)
  espera_opcache
  credenciales_presentes && api_login || return 2
  capacidades_leer || return 1
  [ "$CAP_usuarios" = "$1" ] && [ "$CAP_SENSIBLE" = false ] && [ "$CAP_empresas" = true ] && [ "$CAP_reportes" = true ] && [ "$CAP_accesos" = true ]
}

apagar() {
  local previo; env_respaldar "antes-apagar-usuarios" || { fallo "no se pudo respaldar el .env: no se modifica nada"; return 1; }; previo=$ENV_RESPALDO_RUTA
  env_aplicar $'ADMIN_WRITES_USUARIOS=0\nADMIN_WRITES_USUARIOS_SENSIBLE=0' "" || { fallo "no se pudo editar el .env (respaldo: $previo)"; return 1; }
  ok "Usuarios APAGADO en el .env (respaldo previo: $previo)"
  verificar_capacidades false; local rc=$?
  case $rc in 0) ok "capacidades verificadas: usuarios=false, sensible=false, empresas/reportes/accesos intactos" ;;
    2) aviso "sin credenciales: no se pudo comprobar por la API (el .env quedó apagado)" ;;
    *) fallo "las capacidades NO son las esperadas tras apagar: revise de inmediato" ; return 1 ;; esac
}

echo "== UX-08I · Interruptor de Usuarios · acción=$ACCION"
case $ACCION in
  estado) mostrar; exit 0 ;;
  sensible) fallo "Rol y contraseña (P5) NO se pueden encender con este kit: requieren aprobación y pruebas específicas (R3/R4: Flutter no cierra tokens antiguos). Siguen APAGADOS."; exit 1 ;;
  apagar) apagar; exit $? ;;
esac

# ---- encender (solo no sensible) ----
[ "${CERVESUR_CONFIRMAR_USUARIOS:-}" = SI ] || abortar "Falta CERVESUR_CONFIRMAR_USUARIOS=SI (confirmación explícita de que hay autorización de DBA y negocio)."
[ ${#AUTORIZACION} -ge 10 ] || abortar "--autorizacion exige un texto (≥10 caracteres): quién autorizó, cuándo y la operación real prevista."
[ "$(env_valor_actual CERVESUR_MODE)" = admin_escritura ] || abortar "El .env no está en admin_escritura."
[ "$(env_valor_actual ADMIN_WRITES_USUARIOS)" != 1 ] || abortar "ADMIN_WRITES_USUARIOS ya está en 1."
[ "$(env_valor_actual ADMIN_WRITES_USUARIOS_SENSIBLE)" != 1 ] || abortar "ADMIN_WRITES_USUARIOS_SENSIBLE está en 1 con usuarios apagado: estado incoherente; apague antes."
credenciales_presentes || abortar "Defina CERVESUR_E_USUARIO y CERVESUR_E_CLAVE: sin ellas no se puede comprobar el resultado y NO se enciende."
echo "== 1. El código 2B está instalado y Usuarios sigue bloqueado por defecto"
api_login || abortar "No se pudo iniciar sesión con las credenciales del entorno."
api_pedir PUT /api/users/999999999 '{}'
api_es_403 fase_2b_bloqueada || abortar "El backend no responde 403 «fase_2b_bloqueada» a Usuarios (estado HTTP $API_STATUS): ¿está instalado el código 2B y el interruptor apagado?"
ok "backend 2B con Usuarios bloqueado (403 fase_2b_bloqueada)"
echo "== 2. Módulos 2A intactos y privilegios no sensibles verificados"
capacidades_son empresas reportes accesos || abortar "Las capacidades no son 'empresas, reportes, accesos' encendidos con usuarios y sensible apagados."
ok "empresas/reportes/accesos encendidos; usuarios y sensible apagados"
bash "$PAQ/scripts/privilegios-usuarios.sh" --etapa no-sensibles --verificar | tail -3 | grep -q 'RESULTADO-PRIVILEGIOS: PASS' || abortar "Los privilegios de la cuenta NO están en la etapa no-sensibles verificada (ejecute privilegios-usuarios.sh --aplicar --etapa no-sensibles)."
ok "privilegios de la cuenta = etapa no-sensibles (sin rol/contraseña)"
echo "== 3. Encender (con respaldo del .env y vuelta automática)"
env_respaldar "antes-encender-usuarios" || abortar "No se pudo respaldar el .env: no se modifica nada."; previo=$ENV_RESPALDO_RUTA
env_aplicar $'ADMIN_WRITES_USUARIOS=1\nADMIN_WRITES_USUARIOS_SENSIBLE=0' "" || { fallo "no se pudo editar el .env"; env_instalar "$previo"; exit 1; }
printf 'encendido %s\nautorizacion %s\nrespaldo-env %s\n' "$(sello_utc)" "$AUTORIZACION" "$previo" >> "$DIR_VAR/usuarios-estado.txt" 2>/dev/null && chmod 600 "$DIR_VAR/usuarios-estado.txt" 2>/dev/null
verificar_capacidades true; rc=$?
if [ "$rc" != 0 ]; then
  fallo "Las capacidades NO son las esperadas tras encender (usuarios=true, sensible=false): se APAGA y se restaura el .env previo."
  env_instalar "$previo" && ok ".env previo restaurado" || fallo "NO se pudo restaurar el .env previo: apague a mano con 'interruptor-usuarios.sh apagar'"
  exit 1
fi
ok "capacidades verificadas: usuarios=true, rol/contraseña (sensible)=false, empresas/reportes/accesos intactos"
mostrar
echo "Siguiente: UNA operación real autorizada de Usuarios (alta o edición de datos) y comprobar exactamente una fila nueva en audit_log. Para apagar: sudo bash scripts/interruptor-usuarios.sh apagar"
