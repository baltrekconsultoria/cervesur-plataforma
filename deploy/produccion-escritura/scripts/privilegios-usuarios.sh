#!/usr/bin/env bash
# Cervesur · UX-08I · FASE 2B · Privilegios de Usuarios para la cuenta de escritura. Ejecuta el DBA con sudo (socket de MySQL). NADIE lo ha ejecutado en producción.
#   sudo bash scripts/privilegios-usuarios.sh --etapa no-sensibles|sensibles --simular                     (por omisión) comprueba y muestra EXACTAMENTE qué se ejecutaría; no escribe nada
#   sudo bash scripts/privilegios-usuarios.sh --etapa no-sensibles|sensibles --verificar                   solo lectura: SHOW GRANTS debe corresponder a la etapa
#   sudo bash scripts/privilegios-usuarios.sh --etapa fase2a --verificar                                   solo lectura: la cuenta está como en la fase 2A (nada que escribir sobre `user`)
#   sudo bash scripts/privilegios-usuarios.sh --etapa no-sensibles|sensibles --aplicar --respaldo ARCHIVO.sql.gz --confirmo-autorizacion "TEXTO"
#   sudo bash scripts/privilegios-usuarios.sh --etapa no-sensibles|sensibles --revertir --confirmo-autorizacion "TEXTO"
# Etapas (ADITIVAS y separadas): no-sensibles = alta, edición de datos, desactivar/reactivar. sensibles = además rol y contraseña (P5: otra autorización; requiere no-sensibles).
# Opciones: --cuenta NOMBRE (cervesur_escritura_adm) · --host-cuenta HOST (127.0.0.1) · --db NOMBRE (cervesur_portalbi) · --ayuda
# AVISOS: GRANT/REVOKE son DDL NO transaccionales (no hay ROLLBACK): un fallo a medias deja un estado parcial; este script lo detecta, intenta revertir la etapa y lo informa.
#         No existe prueba de restauración del respaldo: el respaldo se comprueba (gzip -t, tamaño, antigüedad) pero NO se ha restaurado nunca. Los privilegios se revierten con --revertir, no restaurando.
# Salida: 0 bien · 2 uso · 10 bloqueos antes de escribir · 20 fallo al aplicar/revertir · 30 verificación fallida.
set -uo pipefail
case ${1:-} in --ayuda|-h|--help) sed -n '2,/^set -/{/^set -/!s/^# \{0,1\}//p}' "${BASH_SOURCE[0]}"; exit 0 ;; esac
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"

ETAPA=""; MODO=simular; CUENTA="$CUENTA_ESCRITURA_DEFECTO"; HOST_CUENTA="$HOST_CUENTA_DEFECTO"; DB="$DB_NOMBRE"; RESPALDO=""; CONFIRMO=""
while [ $# -gt 0 ]; do
  case $1 in
    --etapa) ETAPA=${2:-}; shift 2 ;;
    --simular) MODO=simular; shift ;;
    --verificar) MODO=verificar; shift ;;
    --aplicar) MODO=aplicar; shift ;;
    --revertir) MODO=revertir; shift ;;
    --cuenta) CUENTA=${2:-}; shift 2 ;;
    --host-cuenta) HOST_CUENTA=${2:-}; shift 2 ;;
    --db) DB=${2:-}; shift 2 ;;
    --respaldo) RESPALDO=${2:-}; shift 2 ;;
    --confirmo-autorizacion) CONFIRMO=${2:-}; shift 2 ;;
    *) echo "Argumento desconocido: $1 (use --ayuda)" >&2; exit 2 ;;
  esac
done
case $ETAPA in no-sensibles|sensibles|fase2a) ;; *) echo "--etapa debe ser no-sensibles, sensibles o fase2a." >&2; exit 2 ;; esac
if [ "$ETAPA" = fase2a ] && [ "$MODO" != verificar ]; then echo "La etapa fase2a solo admite --verificar." >&2; exit 2; fi
[[ $CUENTA =~ ^cervesur_escritura[a-z0-9_]{0,20}$ ]] || { echo "--cuenta debe empezar por cervesur_escritura (minúsculas, dígitos y _)." >&2; exit 2; }
[[ $HOST_CUENTA =~ ^[A-Za-z0-9_.%:-]{1,60}$ ]] || { echo "--host-cuenta inválido." >&2; exit 2; }
[[ $DB =~ ^[A-Za-z0-9_]{1,64}$ ]] || { echo "--db inválido." >&2; exit 2; }
for p in root cervesur_portalbi_admin mysql.sys mysql.session mysql.infoschema debian-sys-maint; do [ "$CUENTA" = "$p" ] && { echo "--cuenta no puede ser una cuenta protegida." >&2; exit 2; }; done
[ "$MODO" = simular ] || [ "$MODO" = verificar ] || requerir_root

REPO="$PAQ/fase2b/repo"
UP="$REPO/UX-08I-usuarios-$ETAPA.up.sql.template"; DOWN="$REPO/UX-08I-usuarios-$ETAPA.down.sql.template"
BLOQUEOS=0
bloquear() { fallo "$*"; BLOQUEOS=$((BLOQUEOS + 1)); }

# Columnas esperadas sobre `user` (ordenadas) por estado de la cuenta
COL_INSERT_NOSENS="created_at email first_name is_active last_name password phone role session_version updated_at username"
COL_UPDATE_2A="last_login"
COL_UPDATE_NOSENS="email first_name is_active last_login last_name phone session_version updated_at"
COL_UPDATE_SENS="email first_name is_active last_login last_name password phone role session_version updated_at"

sql_dba() { $MYSQL_ADMIN -N -B "$@"; }
cuenta_sql() { printf "'%s'@'%s'" "$CUENTA" "$HOST_CUENTA"; }
grants_actuales() { sql_dba -e "SHOW GRANTS FOR $(cuenta_sql)" 2>/dev/null; }
# Columnas de un privilegio (INSERT|UPDATE) sobre DB.user en la salida de SHOW GRANTS, ordenadas y sin comillas. "TABLA" si es de tabla completa.
columnas_de() { # priv
  local g=$1 linea cols=""
  linea=$(grep -F "ON \`$DB\`.\`user\`" <<<"$GRANTS" || true)
  [ -n "$linea" ] || { printf ''; return 0; }
  cols=$(sed -nE "s/.*[ ,]$g \(([^)]*)\).*/\1/p" <<<"$linea" | tr -d '\`' | tr ',' '\n' | tr -d ' ' | sed '/^$/d' | LC_ALL=C sort -u | paste -sd' ' -)
  if [ -z "$cols" ] && grep -qE "GRANT[^(]*\b$g\b[^(]*ON" <<<"$linea"; then cols=TABLA; fi
  printf '%s' "$cols"
}
estado_cuenta() { # → fase2a | no-sensibles | sensibles | otro
  local ins upd
  ins=$(columnas_de INSERT); upd=$(columnas_de UPDATE)
  if [ -z "$ins" ] && [ "$upd" = "$COL_UPDATE_2A" ]; then echo fase2a
  elif [ "$ins" = "$COL_INSERT_NOSENS" ] && [ "$upd" = "$COL_UPDATE_NOSENS" ]; then echo no-sensibles
  elif [ "$ins" = "$COL_INSERT_NOSENS" ] && [ "$upd" = "$COL_UPDATE_SENS" ]; then echo sensibles
  else echo otro; fi
}
# Violaciones generales (cualquier etapa): lo que la cuenta NUNCA debe tener
violaciones_generales() {
  local v=""
  grep -qiE '\b(DELETE|ALL PRIVILEGES|TRIGGER|DROP|ALTER|CREATE|FILE|SUPER|REFERENCES|INDEX|EXECUTE)\b' <<<"$GRANTS" && v="$v privilegio-de-más(DELETE/ALL/DDL/FILE/SUPER...)"
  grep -qi 'WITH GRANT OPTION' <<<"$GRANTS" && v="$v GRANT-OPTION"
  grep -E '^GRANT ' <<<"$GRANTS" | grep -vE "ON \`$DB\`\.|ON \*\.\*" | grep -q . && v="$v otra-base"
  grep -E '^GRANT ' <<<"$GRANTS" | grep -E "ON \*\.\*" | grep -vqE 'GRANT USAGE ON' && v="$v global"
  grep -F "ON \`$DB\`.\`audit_log\`" <<<"$GRANTS" | grep -qE '\b(UPDATE|DELETE)\b' && v="$v audit_log-modificable"
  [ -z "$(columnas_de DELETE)" ] || v="$v DELETE@user"
  printf '%s' "${v# }"
}
sentencias() { # plantilla → sentencias (una por línea) con marcadores sustituidos
  grep -vE '^[[:space:]]*--' "$1" | tr '\n' ' ' | sed -e 's/;[[:space:]]*/;\n/g' | sed -e "s/{DB}/$DB/g" -e "s/{USER}/$CUENTA/g" -e "s/{HOST}/$HOST_CUENTA/g" -e 's/^[[:space:]]*//' | sed '/^$/d'
}

echo "== UX-08I · Privilegios de Usuarios · etapa=$ETAPA · modo=$MODO · cuenta=$CUENTA@$HOST_CUENTA · base=$DB"
echo "== 1. Integridad de las plantillas"
if [ "$ETAPA" != fase2a ]; then
  [ -f "$PAQ/fase2b/repo/SHA256SUMS" ] && (cd "$PAQ/fase2b/repo" && sha256sum -c SHA256SUMS --quiet) >/dev/null 2>&1 && ok "plantillas íntegras (SHA256SUMS de fase2b/repo)" || bloquear "las plantillas de fase2b/repo no verifican con su SHA256SUMS"
  [ -f "$UP" ] && [ -f "$DOWN" ] || bloquear "faltan las plantillas de la etapa $ETAPA"
fi
echo "== 2. Cuenta y estado actual (solo lectura)"
n=$(sql_dba -e "SELECT COUNT(*) FROM mysql.user WHERE User='$CUENTA' AND Host='$HOST_CUENTA'" 2>/dev/null || echo ERR)
[ "$n" = 1 ] && ok "la cuenta existe" || bloquear "la cuenta $CUENTA@$HOST_CUENTA no existe o no se pudo consultar (resultado: $n)"
GRANTS=$(grants_actuales || true)
[ -n "$GRANTS" ] || bloquear "SHOW GRANTS no devolvió nada"
ACTUAL=$(estado_cuenta)
ok "estado actual de la cuenta sobre \`user\`: $ACTUAL"
V=$(violaciones_generales)
[ -z "$V" ] && ok "sin privilegios de más (sin DELETE/ALL/DDL/GRANT OPTION/otra base/globales; audit_log solo SELECT+INSERT)" || bloquear "la cuenta tiene privilegios de más: $V"
t=$(sql_dba -e "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA='$DB' AND EVENT_OBJECT_TABLE='audit_log'" 2>/dev/null || echo ERR)
[ "$t" = 2 ] && ok "audit_log conserva sus 2 triggers de solo inserción" || bloquear "audit_log no tiene exactamente 2 triggers (resultado: $t)"
echo "== 3. Interruptores del .env (solo se lee la presencia/valor exacto de dos claves)"
U_ENV=$(env_valor_actual ADMIN_WRITES_USUARIOS); S_ENV=$(env_valor_actual ADMIN_WRITES_USUARIOS_SENSIBLE)

case $MODO in
  verificar)
    if [ "$ETAPA" = "$ACTUAL" ] && [ "$BLOQUEOS" = 0 ]; then ok "VERIFICACIÓN: la cuenta corresponde a la etapa $ETAPA"; echo "RESULTADO-PRIVILEGIOS: PASS $ETAPA"; exit 0; fi
    fallo "VERIFICACIÓN: se esperaba la etapa $ETAPA y la cuenta está en «$ACTUAL» (bloqueos: $BLOQUEOS)"; echo "RESULTADO-PRIVILEGIOS: FAIL $ETAPA"; exit 30 ;;
  simular|aplicar)
    case $ETAPA in
      no-sensibles)
        [ "$ACTUAL" = fase2a ] || bloquear "no-sensibles solo se aplica sobre una cuenta en estado fase2a (está en «$ACTUAL»)"
        [ "$U_ENV" != 1 ] || bloquear "el .env ya tiene ADMIN_WRITES_USUARIOS=1: apáguelo antes de tocar privilegios" ;;
      sensibles)
        [ "$ACTUAL" = no-sensibles ] || bloquear "sensibles exige la etapa no-sensibles ya aplicada y verificada (está en «$ACTUAL»)"
        [ "$S_ENV" != 1 ] || bloquear "el .env ya tiene ADMIN_WRITES_USUARIOS_SENSIBLE=1: apáguelo antes de tocar privilegios" ;;
    esac ;;
  revertir)
    case $ETAPA in
      no-sensibles) [ "$ACTUAL" = no-sensibles ] || bloquear "no hay nada que revertir: la cuenta está en «$ACTUAL» (si está en «sensibles», revierta primero esa etapa)"
                    [ "$U_ENV" != 1 ] || bloquear "apague ADMIN_WRITES_USUARIOS (interruptor-usuarios.sh apagar) antes de revertir privilegios" ;;
      sensibles) [ "$ACTUAL" = sensibles ] || bloquear "no hay nada que revertir: la cuenta está en «$ACTUAL»"
                 [ "$S_ENV" != 1 ] || bloquear "apague ADMIN_WRITES_USUARIOS_SENSIBLE antes de revertir privilegios" ;;
    esac ;;
esac

if [ "$MODO" = aplicar ]; then
  [ ${#CONFIRMO} -ge 10 ] || bloquear "--confirmo-autorizacion exige un texto (≥10 caracteres) con quién autorizó y cuándo (DBA y negocio)"
  if [ -z "$RESPALDO" ] || [ ! -s "$RESPALDO" ]; then bloquear "--respaldo ARCHIVO.sql.gz es obligatorio y debe existir"
  else
    gzip -t "$RESPALDO" 2>/dev/null && ok "el respaldo es un gzip íntegro (NO se ha restaurado nunca: no hay prueba de restauración)" || bloquear "el respaldo no es un gzip íntegro"
    [ "$(find "$RESPALDO" -mmin -1440 | wc -l)" = 1 ] || bloquear "el respaldo tiene más de 24 h: haga uno nuevo antes de tocar privilegios"
  fi
fi
if [ "$MODO" = revertir ]; then [ ${#CONFIRMO} -ge 10 ] || bloquear "--confirmo-autorizacion exige un texto (≥10 caracteres)"; fi

PLANTILLA=$UP; [ "$MODO" = revertir ] && PLANTILLA=$DOWN
echo "== 4. Sentencias que se ejecutarían (sin contraseñas: ninguna lleva claves)"
if [ -f "$PLANTILLA" ]; then sentencias "$PLANTILLA" | sed 's/^/   /'; fi
if [ "$BLOQUEOS" != 0 ]; then fallo "$BLOQUEOS bloqueo(s): NO se escribe nada."; exit 10; fi
if [ "$MODO" = simular ]; then ok "SIMULACIÓN terminada: no se escribió nada. Código 0 = se puede aplicar la etapa $ETAPA."; exit 0; fi

# ---- escritura (aplicar / revertir) ----
SELLO=$(sello_utc); EVID="$DIR_RESPALDO/privilegios-$ETAPA-$MODO-$SELLO-$$"
install -d -m 700 "$DIR_RESPALDO" && mkdir -m 700 "$EVID" || { fallo "no se pudo crear la carpeta de evidencia $EVID"; exit 10; }
printf '%s\n' "$GRANTS" > "$EVID/grants-antes.txt"; chmod 600 "$EVID/grants-antes.txt"
printf 'etapa %s\nmodo %s\ncuenta %s@%s\nbase %s\nautorizacion %s\nrespaldo %s\n' "$ETAPA" "$MODO" "$CUENTA" "$HOST_CUENTA" "$DB" "$CONFIRMO" "${RESPALDO:-n/a}" > "$EVID/INFO.txt"; chmod 600 "$EVID/INFO.txt"
ok "evidencia previa en $EVID (grants-antes.txt)"
fallo_parcial=0
while IFS= read -r s; do
  [ -n "$s" ] || continue
  if inyectar privilegios && [ "$fallo_parcial" = 0 ] && grep -q 'UPDATE' <<<"$s"; then fallo_parcial=1; fallo "(ensayo) fallo inyectado en: ${s:0:60}…"; break; fi
  if ! $MYSQL_ADMIN -e "$s" >"$EVID/salida.txt" 2>&1; then fallo_parcial=1; fallo "falló la sentencia: ${s:0:80}…"; break; fi
  ok "ejecutada: ${s:0:70}…"
done < <(sentencias "$PLANTILLA")
if [ "$fallo_parcial" = 1 ]; then
  fallo "ESTADO POSIBLEMENTE PARCIAL (GRANT/REVOKE no son transaccionales). Se intenta volver al estado previo."
  if [ "$MODO" = aplicar ]; then
    while IFS= read -r s; do [ -n "$s" ] && $MYSQL_ADMIN -e "$s" >/dev/null 2>&1; done < <(sentencias "$DOWN")
  fi
  GRANTS=$(grants_actuales || true); printf '%s\n' "$GRANTS" > "$EVID/grants-despues-del-fallo.txt"; chmod 600 "$EVID/grants-despues-del-fallo.txt"
  fallo "estado tras el intento de reversión: $(estado_cuenta) (esperado antes: $ACTUAL). Evidencia en $EVID"
  exit 20
fi
GRANTS=$(grants_actuales || true); printf '%s\n' "$GRANTS" > "$EVID/grants-despues.txt"; chmod 600 "$EVID/grants-despues.txt"
FIN=$(estado_cuenta); V=$(violaciones_generales)
case $MODO:$ETAPA in
  aplicar:no-sensibles) ESPERADO=no-sensibles ;; aplicar:sensibles) ESPERADO=sensibles ;;
  revertir:no-sensibles) ESPERADO=fase2a ;; revertir:sensibles) ESPERADO=no-sensibles ;;
esac
if [ "$FIN" = "$ESPERADO" ] && [ -z "$V" ]; then ok "VERIFICACIÓN POSTERIOR: la cuenta está en «$FIN», sin privilegios de más"; echo "RESULTADO-PRIVILEGIOS: PASS $MODO $ETAPA"; exit 0; fi
fallo "VERIFICACIÓN POSTERIOR FALLIDA: estado «$FIN» (esperado «$ESPERADO»), violaciones: ${V:-ninguna}. Evidencia en $EVID. Revierta con --revertir o escale al DBA."
echo "RESULTADO-PRIVILEGIOS: FAIL $MODO $ETAPA"; exit 30
