#!/usr/bin/env bash
# Cervesur · UX-08H.1 · PASO B · INTERRUPTORES de escritura del administrador, UNO A LA VEZ y con puntos de control. Cambia SOLO el .env (atómico, con respaldo 600).
#   sudo bash scripts/interruptores.sh --estado
#   sudo bash scripts/interruptores.sh --habilitar empresas|reportes|accesos
#   sudo bash scripts/interruptores.sh --marcar-verificado empresas|reportes|accesos --evidencia ARCHIVO
#   sudo bash scripts/interruptores.sh --apagar empresas|reportes|accesos      |      --apagar-todo
# ORDEN OBLIGATORIO: empresas → reportes → accesos. No se habilita el siguiente hasta marcar el anterior como VERIFICADO con --marcar-verificado, que exige como evidencia la salida
#   guardada de «verificar-posdespliegue.sh --etapa <modulo> --confirmar-operacion … » (debe contener «RESULTADO-ETAPA: PASS <modulo>» y «OPERACION-CONFIRMADA:»), es decir, UNA operación
#   real autorizada ya hecha y comprobada en audit_log. La evidencia se copia (600) a /var/backups/cervesur-react-admin/evidencias y se registra su SHA-256 en
#   /var/lib/cervesur-react-admin/escritura-estado.txt. (Es un control de proceso, no una barrera frente a root.)
# USUARIOS (FASE 2B) NO SE PUEDE HABILITAR: «usuarios» y «usuarios-sensible» se rechazan SIEMPRE; si el .env contiene ADMIN_WRITES_USUARIOS* se informa como ERROR. El backend, además, los
#   niega sobre la base productiva en tres capas. Desbloquearlos exige una versión nueva del código, no un interruptor.
# --habilitar: precondiciones (modo admin_escritura, sin claves prohibidas, etapa anterior verificada, Apache deja PASAR PUT/DELETE al backend) → respaldo del .env → cambio atómico →
#   verificar-posdespliegue.sh --etapa <modulo> --linea-base (capacidades exactas, módulos apagados en 403, usuarios 403 fase_2b_bloqueada, lecturas) → si algo falla, restaura el
#   .env anterior y lo verifica. Toma la línea base de recuentos para la operación real (--confirmar-operacion). No recarga Apache: mod_php relee el .env en cada petición.
# Credenciales de la verificación (nunca por argumentos): CERVESUR_E_USUARIO / CERVESUR_E_CLAVE. --habilitar las EXIGE; --apagar y --apagar-todo funcionan sin ellas (apagar es siempre seguro) y
#   declaran que la verificación se omitió.
set -uo pipefail
case ${1:-} in --ayuda|-h|--help) sed -n '2,/^set -/{/^set -/!s/^# \{0,1\}//p}' "${BASH_SOURCE[0]}"; exit 0 ;; esac
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
requerir_root
trap api_limpiar EXIT

ACCION=""; MODULO=""; EVIDENCIA=""
bloqueo_usuarios() { fallo "«$1»: la administración de USUARIOS (fase 2B) está BLOQUEADA. No se puede habilitar con este kit ni con configuración: exige una versión nueva del código y otra autorización."; exit 2; }
while [ $# -gt 0 ]; do
  case $1 in
    --estado) ACCION=estado; shift ;;
    --habilitar|--apagar|--marcar-verificado) ACCION=${1#--}; MODULO=${2:-}; [ -n "$MODULO" ] || abortar "$1 necesita un módulo."; shift 2 ;;
    --apagar-todo) ACCION=apagar-todo; shift ;;
    --evidencia) EVIDENCIA=${2:-}; shift 2 ;;
    *) case $1 in usuarios*|ADMIN_WRITES_USUARIOS*) bloqueo_usuarios "$1" ;; esac; abortar "Argumento desconocido: $1 (use --ayuda)" ;;
  esac
done
[ -n "$ACCION" ] || abortar "Indique una acción (use --ayuda)."
case $MODULO in usuarios*|USUARIOS*|ADMIN_WRITES_USUARIOS*) bloqueo_usuarios "$MODULO" ;; esac
if [ -n "$MODULO" ]; then case " ${MODULOS_ORDEN[*]} " in *" $MODULO "*) ;; *) abortar "Módulo desconocido: «$MODULO» (empresas, reportes o accesos)." ;; esac; fi
[ -f "$ENV_FILE" ] || abortar "No existe $ENV_FILE."

clave_de() { printf 'ADMIN_WRITES_%s' "${1^^}"; }
encendido() { [ "$(env_valor_actual "$(clave_de "$1")")" = 1 ]; }
verificado() { [ -f "$ARCHIVO_ESTADO" ] && grep -q "^$1	verificado	" "$ARCHIVO_ESTADO"; }
indice() { local i; for i in "${!MODULOS_ORDEN[@]}"; do [ "${MODULOS_ORDEN[$i]}" = "$1" ] && { echo "$i"; return; }; done; }
etapa_de() { # n módulos encendidos (prefijo) → nombre de etapa
  case $1 in 0) echo A2 ;; 1) echo empresas ;; 2) echo reportes ;; 3) echo accesos ;; esac
}
encendidos_lista() { local m out=""; for m in "${MODULOS_ORDEN[@]}"; do encendido "$m" && out="$out $m"; done; echo "${out# }"; }
registrar() { ( umask 077; printf '%s\t%s\t%s\t%s\n' "$(date -u +%FT%TZ)" "${SUDO_USER:-${USER:-root}}" "$1" "$2" >> "$DIR_VAR/interruptores.log" ) 2>/dev/null; }

estado() {
  local m v
  echo "Modo del .env: $(modo_env) (se exige admin_escritura para operar)"
  printf '%-12s %-11s %s\n' MODULO INTERRUPTOR VERIFICADO
  for m in "${MODULOS_ORDEN[@]}"; do
    v=no; verificado "$m" && v="sí ($(grep "^$m	verificado	" "$ARCHIVO_ESTADO" | cut -f3))"
    printf '%-12s %-11s %s\n' "$m" "$(encendido "$m" && echo ENCENDIDO || echo apagado)" "$v"
  done
  printf '%-12s %-11s %s\n' usuarios BLOQUEADO "fase 2B: bloqueada en código (3 capas) y rechazada por este kit"
  local prohibidas; prohibidas=$(env_claves_prohibidas | paste -sd, -)
  if [ -n "$prohibidas" ]; then fallo "ERROR: el .env contiene claves prohibidas: $prohibidas (la fase 2B sigue bloqueada; modo-escritura.sh las elimina)."; return 1; fi
  return 0
}
[ "$ACCION" != estado ] || { estado; exit $?; }

# ---- A partir de aquí se modifica el .env: precondiciones comunes
[ "$(modo_env)" = admin_escritura ] && [ "$(env_valor_actual CERVESUR_REQUIRED_MODE)" = admin_escritura ] || abortar "El .env no está en modo admin_escritura: haga antes A2 (modo-escritura.sh)."
prohibidas=$(env_claves_prohibidas | paste -sd, -)
[ -z "$prohibidas" ] || abortar "ERROR: el .env contiene claves prohibidas ($prohibidas). La fase 2B sigue bloqueada: corríjalas (modo-escritura.sh --revertir-env o edición manual con respaldo) antes de usar este script."

# Verificación directa de un conjunto de módulos encendidos (login + capacidades exactas); usada al apagar
verificar_conjunto() { # modulo...
  if ! credenciales_presentes; then aviso "verificación OMITIDA (sin CERVESUR_E_USUARIO / CERVESUR_E_CLAVE): compruebe /api/admin/capacidades a mano."; return 0; fi
  sleep 1
  api_login || { fallo "el login falla tras el cambio"; return 1; }
  capacidades_son "$@" || { fallo "capacidades inesperadas tras el cambio"; return 1; }
  ok "capacidades verificadas: encendidos = ${*:-ninguno}; usuarios = false"
}
restaurar_env() { # respaldo, etiqueta de verificación
  fallo "Se restaura el .env anterior."
  env_instalar "$1" || { fallo "NO se pudo restaurar el .env automáticamente: copie a mano $1 sobre $ENV_FILE (640 root:www-data)."; return 1; }
  ok ".env anterior restaurado"
}

case $ACCION in
habilitar)
  i=$(indice "$MODULO")
  encendido "$MODULO" && { ok "«$MODULO» ya está encendido: no hay nada que cambiar."; exit 0; }
  previos=""; for ((j = 0; j < i; j++)); do previos="$previos ${MODULOS_ORDEN[$j]}"; done
  for ((j = 0; j < ${#MODULOS_ORDEN[@]}; j++)); do
    m=${MODULOS_ORDEN[$j]}
    if [ "$j" -lt "$i" ]; then
      encendido "$m" || abortar "Orden obligatorio: antes de «$MODULO» debe estar encendido «$m»."
      verificado "$m" || abortar "Orden obligatorio: «$m» todavía NO está marcado como verificado. Haga su operación autorizada, guarde la salida de verificar-posdespliegue.sh --etapa $m --confirmar-operacion … y ejecute --marcar-verificado $m --evidencia ARCHIVO."
    elif [ "$j" -gt "$i" ]; then encendido "$m" && abortar "«$m» está encendido pero no debería estarlo antes que «$MODULO»: apáguelo (--apagar $m) o revise el estado."; fi
  done
  credenciales_presentes || abortar "Faltan CERVESUR_E_USUARIO / CERVESUR_E_CLAVE: sin login no se puede verificar el cambio, y no se habilita nada sin verificar."
  api_login || abortar "El login con CERVESUR_E_USUARIO / CERVESUR_E_CLAVE falla: no se modifica nada."
  # Apache debe dejar pasar PUT/DELETE al backend: con el módulo apagado, el backend responde 403 JSON «escritura_desactivada»; un 403 HTML es de Apache.
  # shellcheck disable=SC2034  # TOKEN lo usa api_pedir (lib.sh): se vacía para que la sonda parta sin sesión previa
  TOKEN=''
  ruta_sonda=$MODULO; [ "$MODULO" != accesos ] || ruta_sonda=asignacion
  api_pedir PUT "/api/$ruta_sonda/999999999" '{}'
  if api_es_403_apache; then abortar "Apache rechaza PUT/DELETE con 403 antes de llegar a PHP (<LimitExcept GET POST>): con el módulo encendido sus pantallas fallarían. NO se habilita. Aplique el cambio de Apache aprobado (README, «Apache»; scripts/apache-metodos-escritura.sh) y repita."; fi
  api_es_403 escritura_desactivada || abortar "La sonda de PUT al módulo apagado no dio 403 «escritura_desactivada» (dio $API_STATUS): estado inesperado, no se habilita."
  env_respaldar "B-$MODULO-previo" || abortar "No se pudo respaldar el .env: no se modificó nada."
  PREVIO=$ENV_RESPALDO_RUTA
  env_aplicar "$(clave_de "$MODULO")=1" "" || { restaurar_env "$PREVIO"; abortar "No se pudo escribir el .env."; }
  registrar habilitar "$MODULO"
  echo "== Verificación de la etapa $MODULO"
  sleep 1
  if "${BASH:-bash}" "$PAQ/scripts/verificar-posdespliegue.sh" --etapa "$MODULO" --linea-base | grep -E '^(FAIL|AVISO|RESULTADO|OMIT|[0-9]+ PASS|==)'; [ "${PIPESTATUS[0]}" -eq 0 ]; then
    ok "«$MODULO» ENCENDIDO y verificado. Línea base de recuentos tomada. Ahora: UNA operación real autorizada y luego"
    echo "   sudo bash scripts/verificar-posdespliegue.sh --etapa $MODULO --confirmar-operacion <accion> --tabla <tabla> --delta <n> | tee evidencia-$MODULO.txt"
    echo "   sudo bash scripts/interruptores.sh --marcar-verificado $MODULO --evidencia evidencia-$MODULO.txt"
    exit 0
  fi
  restaurar_env "$PREVIO"; registrar habilitar-fallida-restaurado "$MODULO"
  prev_etapa=$(etapa_de "$i")
  if "${BASH:-bash}" "$PAQ/scripts/verificar-posdespliegue.sh" --etapa "$prev_etapa" >/dev/null 2>&1; then ok "estado anterior ($prev_etapa) verificado"; else fallo "el estado anterior ($prev_etapa) NO pasa su verificación: revise a mano. Respaldo: $PREVIO"; fi
  exit 1
  ;;
apagar|apagar-todo)
  if [ "$ACCION" = apagar-todo ]; then pares="ADMIN_WRITES_EMPRESAS=0
ADMIN_WRITES_REPORTES=0
ADMIN_WRITES_ACCESOS=0"; resto=""
  else
    pares="$(clave_de "$MODULO")=0"
    resto=""; for m in "${MODULOS_ORDEN[@]}"; do [ "$m" = "$MODULO" ] || ! encendido "$m" || resto="$resto $m"; done; resto=${resto# }
  fi
  env_respaldar "B-apagar-previo" || abortar "No se pudo respaldar el .env: no se modificó nada."
  PREVIO=$ENV_RESPALDO_RUTA
  env_aplicar "$pares" "ADMIN_WRITES_USUARIOS ADMIN_WRITES_USUARIOS_SENSIBLE" || { restaurar_env "$PREVIO"; abortar "No se pudo escribir el .env."; }
  registrar "$ACCION" "${MODULO:-todo}"
  ok "interruptor(es) apagado(s) en el .env (respaldo previo: $PREVIO)"
  # shellcheck disable=SC2086  # $resto es una lista de módulos separados por espacios
  if verificar_conjunto $resto; then exit 0; fi
  fallo "La verificación tras apagar FALLÓ, pero el .env ya quedó con las escrituras APAGADAS (lo seguro). Se conserva; revise a mano. Respaldo previo: $PREVIO"; exit 1
  ;;
marcar-verificado)
  encendido "$MODULO" || abortar "«$MODULO» no está encendido: no se puede marcar como verificado."
  [ -n "$EVIDENCIA" ] || abortar "--marcar-verificado exige --evidencia ARCHIVO (salida guardada de verificar-posdespliegue.sh --etapa $MODULO --confirmar-operacion …)."
  [ -f "$EVIDENCIA" ] && [ ! -L "$EVIDENCIA" ] && [ -s "$EVIDENCIA" ] && [ "$(stat -c %s "$EVIDENCIA")" -le 1048576 ] || abortar "La evidencia debe ser un archivo regular, no vacío y de hasta 1 MB."
  grep -q "^RESULTADO-ETAPA: PASS $MODULO\$" "$EVIDENCIA" || abortar "La evidencia no contiene «RESULTADO-ETAPA: PASS $MODULO»."
  grep -q '^OPERACION-CONFIRMADA: ' "$EVIDENCIA" || abortar "La evidencia no contiene «OPERACION-CONFIRMADA:» (la operación real autorizada comprobada en audit_log)."
  i=$(indice "$MODULO")
  if [ "$i" -gt 0 ]; then verificado "${MODULOS_ORDEN[$((i - 1))]}" || abortar "Antes debe estar verificado «${MODULOS_ORDEN[$((i - 1))]}»."; fi
  install -d -m 700 "$DIR_RESPALDO/evidencias" || abortar "No se pudo crear la carpeta de evidencias."
  copia="$DIR_RESPALDO/evidencias/$MODULO-$(sello_utc).txt"
  ( umask 077; cp "$EVIDENCIA" "$copia" ) && chmod 600 "$copia" || abortar "No se pudo copiar la evidencia."
  ( umask 077; printf '%s\tverificado\t%s\t%s\t%s\n' "$MODULO" "$(date -u +%FT%TZ)" "$(sha256sum "$copia" | cut -c1-64)" "$copia" >> "$ARCHIVO_ESTADO" ) || abortar "No se pudo registrar el estado."
  registrar marcar-verificado "$MODULO"
  ok "«$MODULO» marcado como VERIFICADO (evidencia copiada a $copia). Siguiente módulo permitido: ${MODULOS_ORDEN[$((i + 1))]:-ninguno (fin del Paso B)}."
  ;;
esac
