#!/usr/bin/env bash
# Cervesur · UX-08H.1 · VERIFICACIÓN POSDESPLIEGUE por etapa (lista PASS / FAIL). No escribe NINGÚN dato de negocio ni de auditoría.
#   sudo bash scripts/verificar-posdespliegue.sh --etapa A1|A2|empresas|reportes|accesos [--sin-credenciales] [--linea-base]
#   sudo bash scripts/verificar-posdespliegue.sh --etapa empresas --confirmar-operacion ACCION --tabla TABLA --delta N
# Credenciales (nunca por argumentos): CERVESUR_E_USUARIO y CERVESUR_E_CLAVE de un administrador autorizado del 8443. Sin ellas (o con --sin-credenciales) las comprobaciones que
# necesitan sesión salen como OMIT y la etapa lo declara («verificación PARCIAL»); las que no la necesitan (puertas de rutas, .env, base) se hacen igual.
# ETAPAS:
#   A1        código nuevo con el .env de la fase 1 (piloto_admin): login y lecturas funcionan; TODA ruta de escritura sigue en 403; /api/admin/capacidades NO existe (403).
#   A2        modo admin_escritura con todo apagado: capacidades todas false; escrituras de empresas/informes/accesos 403 «escritura_desactivada»; usuarios 403 «fase_2b_bloqueada»;
#             lecturas intactas; autorización del visor sin cambios (huella de CERVESUR_PILOT_VISOR_IDS igual a la de A2); audit_log con sus 2 triggers.
#   empresas|reportes|accesos   solo están encendidos los módulos hasta ese (empresas ⊂ reportes ⊂ accesos); los apagados dan 403; usuarios sigue bloqueado.
# SONDAS DE ESCRITURA: usan siempre el id inexistente 999999999 y el cuerpo vacío «{}»; si una puerta fallara, el controlador rechazaría por validación o «no existe»: no pueden escribir.
#   En módulos ENCENDIDOS solo se sondean PUT/DELETE/reactivar de ese id inexistente (nunca un POST de alta). En los apagados o de usuarios, la puerta de rutas responde antes de
#   cualquier controlador. Un 403 sin «code» (HTML) lo emite Apache: el kit lo distingue del 403 del backend y lo declara.
# --confirmar-operacion ACCION --tabla T --delta N (tras la ÚNICA operación real autorizada de un módulo): lee la tabla audit_log por el socket del DBA (solo SELECT) y exige
#   exactamente UNA fila nueva desde la línea base, con esa acción, y que el recuento de la tabla T cambió exactamente N respecto de la línea base (la tomó el interruptor al encender el
#   módulo, o --linea-base). Imprime solo acción, entidad, id, actor y hora: nunca before_json / after_json.
# Última línea: «RESULTADO-ETAPA: PASS|FAIL <etapa>» (y «OPERACION-CONFIRMADA: …» si procede). Guarde la salida como evidencia: interruptores.sh --marcar-verificado la exige.
set -uo pipefail
case ${1:-} in --ayuda|-h|--help) sed -n '2,/^set -/{/^set -/!s/^# \{0,1\}//p}' "${BASH_SOURCE[0]}"; exit 0 ;; esac
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"
requerir_root
trap api_limpiar EXIT

ETAPA=""; SIN_CRED=0; NUEVA_LINEA_BASE=0; ACCION=""; TABLA=""; DELTA=""
while [ $# -gt 0 ]; do
  case $1 in
    --etapa) ETAPA=${2:-}; shift 2 ;;
    --sin-credenciales) SIN_CRED=1; shift ;;
    --linea-base) NUEVA_LINEA_BASE=1; shift ;;
    --confirmar-operacion) ACCION=${2:-}; shift 2 ;;
    --tabla) TABLA=${2:-}; shift 2 ;;
    --delta) DELTA=${2:-}; shift 2 ;;
    *) abortar "Argumento desconocido: $1 (use --ayuda)" ;;
  esac
done
case $ETAPA in A1|A2|empresas|reportes|accesos) ;; *) abortar "--etapa debe ser A1, A2, empresas, reportes o accesos." ;; esac
TABLAS_NEGOCIO=(empresa reporte empresa_usuario empresa_reporte user_empresa_reporte user)
if [ -n "$ACCION$TABLA$DELTA" ]; then
  [[ $ACCION =~ ^[A-Za-z0-9_.:-]{1,40}$ ]] || abortar "--confirmar-operacion necesita la acción tal como figura en audit_log (letras, números, _ . : -)."
  [[ $DELTA =~ ^-?[0-9]{1,6}$ ]] || abortar "--delta necesita un entero (puede ser 0 o negativo)."
  case " ${TABLAS_NEGOCIO[*]} audit_log " in *" $TABLA "*) ;; *) abortar "--tabla debe ser una de: ${TABLAS_NEGOCIO[*]}." ;; esac
  case $ETAPA in A1|A2) abortar "--confirmar-operacion solo se usa en las etapas de Paso B (empresas, reportes, accesos)." ;; esac
fi
DB=$DB_NOMBRE
pass=0; fail=0; omit=0; parcial=0
p() { printf 'PASS  %s\n' "$*"; pass=$((pass + 1)); }
f() { printf 'FAIL  %s\n' "$*"; fail=$((fail + 1)); }
o() { printf 'OMIT  %s\n' "$*"; omit=$((omit + 1)); parcial=1; }
chk() { local texto=$1; shift; if "$@" >/dev/null 2>&1; then p "$texto"; else f "$texto"; fi; }
estado_dir="$DIR_VAR"
LB_ARCHIVO="$estado_dir/linea-base-$ETAPA.txt"

echo "################ VERIFICACIÓN POSDESPLIEGUE · etapa $ETAPA ################"
echo "== Servicio y .env"
respuesta=$(curl -sS -m 10 "${CURL_TLS[@]}" "$URL_ADMIN/api/ping" 2>/dev/null); chk "el administrador responde por HTTPS ($PUERTO)" grep -q '"pong":true' <<<"$respuesta"
chk ".env 640 root:www-data" test "$(stat -c '%a %U:%G' "$ENV_FILE")" = "640 root:www-data"
prohibidas=$(env_claves_prohibidas | paste -sd, -)
if [ -z "$prohibidas" ]; then p "el .env no contiene ADMIN_WRITES_USUARIOS*, USER_ADMIN_WRITES, CERVESUR_INSECURE_TESTS ni CERVESUR_ALLOW_WRITABLE_DB_ACCOUNT"; else f "el .env contiene claves PROHIBIDAS: $prohibidas (la fase fase 2B sigue bloqueada)"; fi

declare -A ENCENDIDO=([empresas]=0 [reportes]=0 [accesos]=0)
case $ETAPA in
  empresas) ENCENDIDO[empresas]=1 ;;
  reportes) ENCENDIDO[empresas]=1; ENCENDIDO[reportes]=1 ;;
  accesos) ENCENDIDO[empresas]=1; ENCENDIDO[reportes]=1; ENCENDIDO[accesos]=1 ;;
esac
encendidos=""; for m in "${MODULOS_ORDEN[@]}"; do [ "${ENCENDIDO[$m]}" = 1 ] && encendidos="$encendidos $m"; done

echo "== Sesión"
CON_SESION=0
if [ "$SIN_CRED" = 1 ]; then o "sin credenciales (--sin-credenciales): no se hace login"
elif ! credenciales_presentes; then o "CERVESUR_E_USUARIO / CERVESUR_E_CLAVE sin definir: no se hace login"
elif api_login; then p "login del administrador autorizado (token opaco de 256 bits)"; CON_SESION=1
else f "el login del administrador NO funciona"; fi
if [ "$CON_SESION" = 1 ]; then
  api_pedir GET /api/user/profile; chk "perfil con rol admin" test "$API_STATUS" = 200 -a "$(grep -c '"role":"admin"' <<<"$API_CUERPO")" -ge 1
  for ruta in "/api/empresas?view=minimal&per_page=1&page=1" "/api/reportes?view=minimal&per_page=1&page=1" "/api/asignacion?view=minimal&per_page=1&page=1" "/api/users?status=all&per_page=1&page=1"; do
    api_pedir GET "$ruta"; chk "lectura intacta: GET ${ruta%%\?*} → 200" test "$API_STATUS" = 200
  done
fi

# Sonda de escritura inofensiva: método, ruta; devuelve el estado en API_STATUS
sonda() { # "METODO /ruta"
  local metodo=${1%% *} ruta=${1#* } cuerpo=''
  case $metodo in POST|PUT) cuerpo='{}' ;; esac
  api_pedir "$metodo" "$ruta" "$cuerpo"
}
apache_bloquea=0
sondas_403() { # modulo codigo → todas las rutas del módulo responden 403 con ese «code» (el 403 sin «code» es de Apache y se declara aparte)
  local m=$1 codigo=$2 r malas="" apache=""
  while IFS= read -r r; do
    sonda "$r"
    if api_es_403 "$codigo"; then continue
    elif api_es_403_apache; then apache="$apache [$r]"; apache_bloquea=1
    else malas="$malas [$r → $API_STATUS]"; fi
  done < <(rutas_de "$m")
  if [ -n "$malas" ]; then f "escrituras de $m: se esperaba 403 «$codigo» en todas y respondieron:$malas"
  else
    p "escrituras de $m: 403 «$codigo» (la puerta de rutas responde antes de cualquier controlador)"
    [ -z "$apache" ] || printf 'AVISO Apache (no el backend) rechazó con 403 HTML: %s → PUT/DELETE no llegan a PHP (ver README, «Apache»)\n' "$apache"
  fi
}

case $ETAPA in
A1)
  echo "== A1 · comportamiento de la fase 1 con el código nuevo"
  chk ".env sigue en modo piloto_admin (CERVESUR_MODE = CERVESUR_REQUIRED_MODE)" test "$(modo_env)" = piloto_admin -a "$(env_valor_actual CERVESUR_REQUIRED_MODE)" = piloto_admin
  chk "el .env no define ninguna ADMIN_WRITES_* encendida" test "$(grep -cE '^ADMIN_WRITES_[A-Z_]+=1' "$ENV_FILE")" = 0
  chk "build-info.json de la aplicación: admin-write" grep -q '"pilotMode": *"admin-write"' "$DIR_PUBLIC/build-info.json"
  # Cada ruta de escritura (todas las de la tabla del modo nuevo) sigue en 403 en piloto_admin; /api/admin/capacidades y el historial NO existen en este modo.
  api_pedir GET /api/admin/capacidades; chk "/api/admin/capacidades NO es accesible en piloto_admin (403)" test "$API_STATUS" = 403
  api_pedir GET /api/auditoria; chk "/api/auditoria NO es accesible en piloto_admin (403)" test "$API_STATUS" = 403
  malas=""; for m in empresas reportes accesos usuarios; do while IFS= read -r r; do sonda "$r"; [ "$API_STATUS" = 403 ] || malas="$malas [$r → $API_STATUS]"; done < <(rutas_de "$m"); done
  if [ -z "$malas" ]; then p "TODAS las rutas de escritura (empresas, informes, accesos y usuarios) siguen en 403 en piloto_admin"; else f "rutas de escritura que NO dieron 403:$malas"; fi
  ;;
A2)
  echo "== A2 · modo admin_escritura con TODAS las escrituras apagadas"
  chk "CERVESUR_MODE = CERVESUR_REQUIRED_MODE = admin_escritura" test "$(modo_env)" = admin_escritura -a "$(env_valor_actual CERVESUR_REQUIRED_MODE)" = admin_escritura
  chk "AUDIT_LOG_MODE=required y REACT_STRICT_VIEWS=1" test "$(env_valor_actual AUDIT_LOG_MODE)" = required -a "$(env_valor_actual REACT_STRICT_VIEWS)" = 1
  for m in EMPRESAS REPORTES ACCESOS; do chk "ADMIN_WRITES_$m=0" test "$(env_valor_actual "ADMIN_WRITES_$m")" = 0; done
  case "$(env_valor_actual DB_USER)" in cervesur_ro_*|cervesur_portalbi_admin) f "DB_USER es una cuenta de lectura o la de Flutter, no la cuenta de escritura de la fase 2A" ;; *) p "DB_USER es la cuenta propia de escritura (no la de Flutter ni cervesur_ro_*)" ;; esac
  ;;
*)
  echo "== $ETAPA · módulos encendidos:${encendidos:- ninguno}"
  chk "CERVESUR_MODE = admin_escritura" test "$(modo_env)" = admin_escritura
  for m in "${MODULOS_ORDEN[@]}"; do
    k=ADMIN_WRITES_${m^^}; esperado=0; [ "${ENCENDIDO[$m]}" = 1 ] && esperado=1
    chk "$k=$esperado en el .env" test "$(env_valor_actual "$k")" = "$esperado"
  done
  ;;
esac

# shellcheck disable=SC2154  # CAP_<modulo> las asigna capacidades_son (lib.sh) con nombres dinámicos
if [ "$ETAPA" != A1 ]; then
  echo "== Capacidades efectivas (GET /api/admin/capacidades)"
  if [ "$CON_SESION" = 1 ]; then
    if capacidades_son $encendidos; then p "capacidades exactas: empresas=$CAP_empresas reportes=$CAP_reportes accesos=$CAP_accesos usuarios=false users_sensitive=false"
    else f "capacidades inesperadas (leído: empresas=$CAP_empresas reportes=$CAP_reportes accesos=$CAP_accesos usuarios=$CAP_usuarios sensible=$CAP_SENSIBLE; esperado:${encendidos:- todo apagado}, usuarios=false)"; fi
  else o "capacidades efectivas: requiere sesión"; fi
  echo "== Puertas de rutas (no necesitan sesión: el backend responde antes de autenticar)"
  for m in "${MODULOS_ORDEN[@]}"; do
    if [ "${ENCENDIDO[$m]}" = 0 ]; then sondas_403 "$m" escritura_desactivada
    else
      # módulo encendido: solo PUT/DELETE/reactivar del id inexistente (jamás un alta). Debe llegar al controlador (4xx distinto de 403) y no al servidor roto (5xx).
      malas=""; apache=""
      while IFS= read -r r; do
        case ${r%% *} in POST) [[ $r == */reactivate ]] || continue ;; esac
        sonda "$r"
        if api_es_403_apache; then apache="$apache [$r]"; elif [ "$API_STATUS" -ge 400 ] 2>/dev/null && [ "$API_STATUS" -lt 500 ] && [ "$API_STATUS" != 403 ] && [ "$API_STATUS" != 401 ]; then :; elif [ "$API_STATUS" = 403 ] && ! api_es_403 escritura_desactivada && ! api_es_403 fase_2b_bloqueada; then :; else malas="$malas [$r → $API_STATUS]"; fi
      done < <(rutas_de "$m")
      if [ -n "$apache" ]; then f "módulo $m encendido pero Apache rechaza con 403 HTML:$apache → PUT/DELETE no llegan a PHP: aplique el cambio de Apache (README) o apague el módulo"
      elif [ -n "$malas" ]; then f "módulo $m encendido: respuestas inesperadas del controlador:$malas"
      else p "módulo $m encendido: sus rutas llegan al controlador (id inexistente → 4xx, nada escrito)"; fi
    fi
  done
  sondas_403 usuarios fase_2b_bloqueada
  if [ "$apache_bloquea" = 1 ]; then printf 'AVISO En esta etapa Apache bloquea PUT/DELETE: es seguro, pero impide el Paso B hasta el cambio de Apache autorizado.\n'; fi
  echo "== Base de datos (DBA local, solo SELECT)"
  if mysql_activo; then
    chk "audit_log existe" test "$(dba -D "$DB" -e "SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='audit_log'" 2>/dev/null)" = 1
    chk "audit_log tiene sus 2 triggers de solo inserción" test "$(dba -D "$DB" -e "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='$DB' AND EVENT_OBJECT_TABLE='audit_log'" 2>/dev/null)" = 2
  else f "MySQL no responde al DBA local"; fi
fi

if [ "$ETAPA" = A2 ]; then
  echo "== Autorización del visor (DB031 / id 72 sin cambios)"
  a2=$estado_dir/a2-linea-base.txt
  if [ -f "$a2" ]; then
    previo=$(sed -n 's/^visor-ids-huella=//p' "$a2" | head -1)
    chk "CERVESUR_PILOT_VISOR_IDS idéntico al de antes de A2 (huella igual; nadie recibió autorización nueva)" test "$previo" = "$(env_huella_valor CERVESUR_PILOT_VISOR_IDS)"
    for k in AUTH_DB_USER STATE_DB_NAME STATE_DB_USER CERVESUR_PILOT_SECRET AUTH_DB_PASS STATE_DB_PASS; do
      previo=$(sed -n "s/^$k-huella=//p" "$a2" | head -1); [ -z "$previo" ] || chk "$k conservado (huella igual)" test "$previo" = "$(env_huella_valor "$k")"
    done
  else o "no existe $a2 (lo escribe modo-escritura.sh): no se puede comparar la autorización del visor"; fi
fi

# ---- Línea base de recuentos (antes de la operación real) y confirmación de la operación
tabla_cuenta() { dba -D "$DB" -e "SELECT COUNT(*) FROM \`$1\`" 2>/dev/null; }
instantanea() { # → líneas clave=valor
  printf 'audit_max_id=%s\n' "$(dba -D "$DB" -e 'SELECT COALESCE(MAX(id),0) FROM audit_log' 2>/dev/null)"
  local t; for t in "${TABLAS_NEGOCIO[@]}" audit_log; do printf 'cuenta_%s=%s\n' "$t" "$(tabla_cuenta "$t")"; done
}
if [ -z "$ACCION" ] && [ "$ETAPA" != A1 ] && [ "$fail" -eq 0 ] && mysql_activo; then
  if [ ! -f "$LB_ARCHIVO" ] || [ "$NUEVA_LINEA_BASE" = 1 ]; then
    if ( umask 077; instantanea > "$LB_ARCHIVO" ); then echo "== Línea base de recuentos y de audit_log guardada en $LB_ARCHIVO (para --confirmar-operacion)"; fi
  fi
fi
CONFIRMADA=0
if [ -n "$ACCION" ]; then
  echo "== Operación real: acción «$ACCION», tabla $TABLA, delta $DELTA"
  if [ ! -f "$LB_ARCHIVO" ]; then f "no hay línea base ($LB_ARCHIVO): se toma al encender el módulo o con --linea-base ANTES de la operación"
  else
    lb() { sed -n "s/^$1=//p" "$LB_ARCHIVO" | head -1; }
    base_id=$(lb audit_max_id); base_n=$(lb "cuenta_$TABLA")
    [[ $base_id =~ ^[0-9]+$ ]] && [[ $base_n =~ ^[0-9]+$ ]] || f "línea base ilegible"
    nuevas=$(dba -D "$DB" -e "SELECT COUNT(*) FROM audit_log WHERE id > $base_id" 2>/dev/null)
    mismas=$(dba -D "$DB" -e "SELECT COUNT(*) FROM audit_log WHERE id > $base_id AND action = '$ACCION'" 2>/dev/null)
    chk "exactamente UNA fila nueva en audit_log desde la línea base (hay ${nuevas:-?})" test "${nuevas:-x}" = 1
    chk "esa fila tiene la acción «$ACCION»" test "${mismas:-x}" = 1
    fila=$(dba -D "$DB" -e "SELECT action, entity, entity_id, actor_user_id, occurred_at FROM audit_log WHERE id > $base_id ORDER BY id LIMIT 3" 2>/dev/null)
    [ -z "$fila" ] || sed 's/^/       audit_log (acción · entidad · id · actor · hora): /' <<<"$fila"
    ahora=$(tabla_cuenta "$TABLA")
    chk "el recuento de «$TABLA» cambió exactamente $DELTA (antes $base_n, ahora ${ahora:-?})" test "$(( ${ahora:-0} - base_n ))" = "$DELTA"
    for t in "${TABLAS_NEGOCIO[@]}"; do
      [ "$t" = "$TABLA" ] && continue
      b=$(lb "cuenta_$t"); n=$(tabla_cuenta "$t")
      if [ "$t" = user ]; then chk "la tabla user no cambió (fase 2B bloqueada)" test "${n:-x}" = "$b"
      elif [ "${n:-x}" != "$b" ]; then printf 'AVISO la tabla %s también cambió (%s → %s): confirme que es parte de la operación autorizada\n' "$t" "$b" "$n"; fi
    done
    [ "$fail" -eq 0 ] && CONFIRMADA=1
  fi
fi

echo
echo "$pass PASS / $fail FAIL / $omit OMIT"
[ "$parcial" = 0 ] || echo "AVISO: verificación PARCIAL (hay comprobaciones OMIT)."
if [ "$fail" -eq 0 ]; then
  echo "RESULTADO-ETAPA: PASS $ETAPA"
  [ "$CONFIRMADA" = 1 ] && echo "OPERACION-CONFIRMADA: $ACCION $TABLA $DELTA"
  exit 0
fi
echo "RESULTADO-ETAPA: FAIL $ETAPA"; exit 1
