#!/usr/bin/env bash
# Cervesur · UX-08I · FASE 2B · Privilegios de Usuarios para la cuenta de escritura. Ejecuta el DBA con sudo (socket de MySQL). NADIE lo ha ejecutado en producción.
#   sudo bash scripts/privilegios-usuarios.sh --etapa no-sensibles|sensibles --simular                     (por omisión) comprueba y muestra EXACTAMENTE qué se ejecutaría; no escribe nada
#   sudo bash scripts/privilegios-usuarios.sh --etapa no-sensibles|sensibles --verificar                   solo lectura: SHOW GRANTS (y la vista de alta) deben corresponder a la etapa
#   sudo bash scripts/privilegios-usuarios.sh --etapa fase2a --verificar                                   solo lectura: la cuenta está como en la fase 2A (nada que escribir sobre `user`; sin vista ni cuenta DEFINER)
#   sudo bash scripts/privilegios-usuarios.sh --etapa no-sensibles|sensibles --aplicar --respaldo ARCHIVO.sql.gz --confirmo-autorizacion "TEXTO"
#   sudo bash scripts/privilegios-usuarios.sh --etapa no-sensibles|sensibles --revertir --confirmo-autorizacion "TEXTO"
# Etapas (ADITIVAS y separadas): no-sensibles = crea la vista de alta v_user_alta (WITH CHECK OPTION) y su cuenta DEFINER bloqueada, y concede a la cuenta de escritura: INSERT SOLO sobre la vista y
#   UPDATE de datos (sin email, sin role, sin password). La cuenta de escritura NO conserva INSERT sobre la tabla `user`. sensibles = además rol y contraseña (P5: otra autorización; requiere no-sensibles).
# Opciones: --cuenta NOMBRE (cervesur_escritura_adm) · --host-cuenta HOST (127.0.0.1) · --db NOMBRE (cervesur_portalbi) · --cuenta-vista NOMBRE (cervesur_vista_alta) · --ayuda
# AVISOS: GRANT/REVOKE/CREATE VIEW son DDL NO transaccionales (no hay ROLLBACK): un fallo a medias deja un estado parcial; este script lo detecta, intenta revertir la etapa y lo informa.
#         No existe prueba de restauración del respaldo real: el respaldo se comprueba (gzip -t, tamaño, antigüedad) pero NO se ha restaurado nunca. Los privilegios se revierten con --revertir, no restaurando.
#         La vista y la cuenta DEFINER NO entran en un mysqldump de tablas: respáldelas con SHOW CREATE VIEW y SHOW GRANTS (README-FASE2B.md). Requiere MySQL >= 8.0.18 (CREATE USER ... RANDOM PASSWORD).
# Salida: 0 bien · 2 uso · 10 bloqueos antes de escribir · 20 fallo al aplicar/revertir · 30 verificación fallida.
set -uo pipefail
case ${1:-} in --ayuda|-h|--help) sed -n '2,/^set -/{/^set -/!s/^# \{0,1\}//p}' "${BASH_SOURCE[0]}"; exit 0 ;; esac
PAQ="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
# shellcheck source=lib.sh
source "$PAQ/scripts/lib.sh"

ETAPA=""; MODO=simular; CUENTA="$CUENTA_ESCRITURA_DEFECTO"; HOST_CUENTA="$HOST_CUENTA_DEFECTO"; DB="$DB_NOMBRE"; RESPALDO=""; CONFIRMO=""; CUENTA_VISTA="cervesur_vista_alta"
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
    --cuenta-vista) CUENTA_VISTA=${2:-}; shift 2 ;;
    --respaldo) RESPALDO=${2:-}; shift 2 ;;
    --confirmo-autorizacion) CONFIRMO=${2:-}; shift 2 ;;
    *) echo "Argumento desconocido: $1 (use --ayuda)" >&2; exit 2 ;;
  esac
done
case $ETAPA in no-sensibles|sensibles|password-reset|fase2a) ;; *) echo "--etapa debe ser no-sensibles, sensibles o fase2a." >&2; exit 2 ;; esac
if [ "$ETAPA" = fase2a ] && [ "$MODO" != verificar ]; then echo "La etapa fase2a solo admite --verificar." >&2; exit 2; fi
[[ $CUENTA =~ ^cervesur_escritura[a-z0-9_]{0,20}$ ]] || { echo "--cuenta debe empezar por cervesur_escritura (minúsculas, dígitos y _)." >&2; exit 2; }
[[ $CUENTA_VISTA =~ ^cervesur_vista_alta[a-z0-9_]{0,10}$ ]] || { echo "--cuenta-vista debe empezar por cervesur_vista_alta (minúsculas, dígitos y _; máx. 21 caracteres)." >&2; exit 2; }
[[ $HOST_CUENTA =~ ^[A-Za-z0-9_.%:-]{1,60}$ ]] || { echo "--host-cuenta inválido." >&2; exit 2; }
[[ $DB =~ ^[A-Za-z0-9_]{1,64}$ ]] || { echo "--db inválido." >&2; exit 2; }
for p in root cervesur_portalbi_admin mysql.sys mysql.session mysql.infoschema debian-sys-maint; do { [ "$CUENTA" = "$p" ] || [ "$CUENTA_VISTA" = "$p" ]; } && { echo "--cuenta/--cuenta-vista no puede ser una cuenta protegida." >&2; exit 2; }; done
[ "$MODO" = simular ] || [ "$MODO" = verificar ] || requerir_root

REPO="$PAQ/fase2b/repo"
if [ "$ETAPA" = no-sensibles ]; then
  # Orden: la vista y su DEFINER ANTES de conceder INSERT sobre ella; al revertir, PRIMERO se revoca a la cuenta y DESPUÉS se elimina la vista (MySQL no borra los privilegios huérfanos de una vista eliminada).
  UPS=("$REPO/UX-08I-alta-usuarios-vista.up.sql.template" "$REPO/UX-08I-usuarios-no-sensibles.up.sql.template")
  DOWNS=("$REPO/UX-08I-usuarios-no-sensibles.down.sql.template" "$REPO/UX-08I-alta-usuarios-vista.down.sql.template")
elif [ "$ETAPA" = password-reset ]; then
  UPS=("$REPO/UX-09C-p5r-password-reset.up.sql.template"); DOWNS=("$REPO/UX-09C-p5r-password-reset.down.sql.template")
else
  UPS=("$REPO/UX-08I-usuarios-$ETAPA.up.sql.template"); DOWNS=("$REPO/UX-08I-usuarios-$ETAPA.down.sql.template")
fi
BLOQUEOS=0
bloquear() { fallo "$*"; BLOQUEOS=$((BLOQUEOS + 1)); }

# Columnas esperadas (ordenadas) por estado de la cuenta. NUNCA hay INSERT sobre `user` ni UPDATE(email): el alta es solo por la vista y el correo no se edita desde React.
VISTA_COLS="created_at email first_name is_active last_name password phone role session_version updated_at username"
COL_UPDATE_2A="last_login"
COL_UPDATE_NOSENS="first_name is_active last_login last_name phone session_version updated_at"
COL_UPDATE_SENS="first_name is_active last_login last_name password phone role session_version updated_at"
COL_UPDATE_RESET="first_name is_active last_login last_name password phone session_version updated_at"

sql_dba() { $MYSQL_ADMIN -N -B "$@"; }
cuenta_sql() { printf "'%s'@'%s'" "$CUENTA" "$HOST_CUENTA"; }
grants_actuales() { sql_dba -e "SHOW GRANTS FOR $(cuenta_sql)" 2>/dev/null; }
# Columnas de un privilegio (SELECT|INSERT|UPDATE) sobre un objeto de DB en un texto de SHOW GRANTS, ordenadas y sin comillas. "TABLA" si es de tabla completa; vacío si no hay.
columnas_en() { # texto objeto priv
  local texto=$1 obj=$2 g=$3 linea cols=""
  linea=$(grep -F "ON \`$DB\`.\`$obj\`" <<<"$texto" || true)
  [ -n "$linea" ] || { printf ''; return 0; }
  cols=$(sed -nE "s/.*[ ,]$g \(([^)]*)\).*/\1/p" <<<"$linea" | tr -d '\`' | tr ',' '\n' | tr -d ' ' | sed '/^$/d' | LC_ALL=C sort -u | paste -sd' ' -)
  if [ -z "$cols" ] && grep -qE "GRANT[^(]*\b$g\b[^(]*ON" <<<"$linea"; then cols=TABLA; fi
  printf '%s' "$cols"
}
columnas_de() { columnas_en "$GRANTS" user "$1"; } # priv
# Privilegios de TABLA COMPLETA (sin columnas) que el texto concede sobre un objeto, p. ej. «INSERT»
privs_de() { sed -nE "s/^GRANT (.*) ON \`$DB\`\.\`$2\` TO .*/\1/p" <<<"$1" | paste -sd',' - | sed 's/, */,/g'; } # texto objeto
# ¿Existen la vista o la cuenta DEFINER? (solo lectura)
vista_existe() { [ "$(sql_dba -e "SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='v_user_alta'" 2>/dev/null)" = 1 ]; }
definer_existe() { [ "$(sql_dba -e "SELECT COUNT(*) FROM mysql.user WHERE User='$CUENTA_VISTA' AND Host='localhost'" 2>/dev/null)" = 1 ]; }
vista_ausente() { ! vista_existe && ! definer_existe; }
# Problemas de la vista de alta y de su cuenta DEFINER (vacío = es EXACTAMENTE la prevista). Comprueba lo que el DBA debe poder asegurar: insertable, WITH CHECK OPTION vigente, DEFINER administrado y mínimo.
vista_problemas() {
  local p="" fila cols def resto sin_cols
  if ! vista_existe; then printf 'vista-ausente'; return 0; fi
  fila=$(sql_dba -e "SELECT CONCAT_WS('|', CHECK_OPTION, IS_UPDATABLE, SECURITY_TYPE, DEFINER) FROM information_schema.VIEWS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='v_user_alta'" 2>/dev/null)
  [ "$fila" = "CASCADED|YES|DEFINER|$CUENTA_VISTA@localhost" ] || p="$p vista-alterada(check/insertable/definer: ${fila:-?})"
  cols=$(sql_dba -e "SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='v_user_alta'" 2>/dev/null | LC_ALL=C sort | paste -sd' ' -)
  [ "$cols" = "$VISTA_COLS" ] || p="$p vista-columnas-distintas"
  def=$(sql_dba -e "SELECT VIEW_DEFINITION FROM information_schema.VIEWS WHERE TABLE_SCHEMA='$DB' AND TABLE_NAME='v_user_alta'" 2>/dev/null)
  { grep -qF "\`role\` in ('user','usuario')" <<<"$def" && grep -qF "\`is_active\` = 1" <<<"$def" && grep -qF "\`session_version\` = 1" <<<"$def" && ! grep -qiE '\bor\b' <<<"$def"; } || p="$p vista-where-distinto"
  if ! definer_existe; then p="$p definer-ausente"
  else
    [ "$(sql_dba -e "SELECT account_locked FROM mysql.user WHERE User='$CUENTA_VISTA' AND Host='localhost'" 2>/dev/null)" = Y ] || p="$p definer-no-bloqueado"
    resto=$(sql_dba -e "SHOW GRANTS FOR '$CUENTA_VISTA'@'localhost'" 2>/dev/null | grep -v '^GRANT USAGE ON \*\.\*' || true)
    [ "$(columnas_en "$resto" user SELECT)" = "$VISTA_COLS" ] && [ "$(columnas_en "$resto" user INSERT)" = "$VISTA_COLS" ] || p="$p definer-sin-sus-columnas"
    sin_cols=$(sed -E 's/\([^)]*\)//g' <<<"$resto")
    grep -qE '\b(UPDATE|DELETE|ALL|OPTION|DROP|ALTER|CREATE|EXECUTE|TRIGGER|VIEW|REFERENCES|INDEX|FILE|SUPER)\b' <<<"$sin_cols" && p="$p definer-con-privilegios-de-más"
    grep -E '^GRANT ' <<<"$resto" | grep -vqF "ON \`$DB\`.\`user\`" && p="$p definer-con-otros-objetos"
    grep -qi 'WITH GRANT OPTION' <<<"$resto" && p="$p definer-GRANT-OPTION"
  fi
  printf '%s' "${p# }"
}
estado_cuenta() { # → fase2a | no-sensibles | sensibles | otro
  local ins upd vp
  ins=$(columnas_de INSERT); upd=$(columnas_de UPDATE); vp=$(privs_de "$GRANTS" v_user_alta)
  if [ -z "$ins" ] && [ -z "$vp" ] && [ "$upd" = "$COL_UPDATE_2A" ] && vista_ausente; then echo fase2a
  elif [ -z "$ins" ] && [ "$vp" = INSERT ] && [ -z "$(vista_problemas)" ]; then
    if [ "$upd" = "$COL_UPDATE_NOSENS" ]; then echo no-sensibles; elif [ "$upd" = "$COL_UPDATE_SENS" ]; then echo sensibles; elif [ "$upd" = "$COL_UPDATE_RESET" ]; then echo password-reset; else echo otro; fi
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
  [ -z "$(columnas_de INSERT)" ] || v="$v INSERT@user(el alta es solo por la vista)"
  case "$(columnas_de UPDATE)" in *email*) v="$v UPDATE(email)" ;; esac
  { [ -z "$(privs_de "$GRANTS" v_user_alta)" ] || [ "$(privs_de "$GRANTS" v_user_alta)" = INSERT ]; } || v="$v v_user_alta-con-más-que-INSERT"
  printf '%s' "${v# }"
}
# ¿$1 es subconjunto de $2? (listas separadas por espacio)
subconjunto() { local x; for x in $1; do case " $2 " in *" $x "*) ;; *) return 1 ;; esac; done; return 0; }
# Estado PARCIAL de la 2B (GRANT/REVOKE/CREATE VIEW no son transaccionales): algo de las plantillas aplicado, pero nunca columnas ajenas a ellas ni INSERT sobre `user`
parcial_2b() {
  local ins upd vp; ins=$(columnas_de INSERT); upd=$(columnas_de UPDATE); vp=$(privs_de "$GRANTS" v_user_alta)
  [ -z "$ins" ] && [ "$upd" != TABLA ] && subconjunto "$upd" "$COL_UPDATE_SENS" && { [ -z "$vp" ] || [ "$vp" = INSERT ]; } && [ "$(estado_cuenta)" != fase2a ]
}
sentencias() { # plantillas... → sentencias (una por línea) con marcadores sustituidos
  local f
  for f in "$@"; do grep -vE '^[[:space:]]*--' "$f"; done | tr '\n' ' ' | sed -e 's/;[[:space:]]*/;\n/g' | sed -e "s/{DB}/$DB/g" -e "s/{USER}/$CUENTA/g" -e "s/{HOST}/$HOST_CUENTA/g" -e "s/{VISTA}/$CUENTA_VISTA/g" -e 's/^[[:space:]]*//' | sed '/^$/d'
}

echo "== UX-08I · Privilegios de Usuarios · etapa=$ETAPA · modo=$MODO · cuenta=$CUENTA@$HOST_CUENTA · base=$DB · DEFINER de la vista=$CUENTA_VISTA@localhost"
echo "== 1. Integridad de las plantillas"
if [ "$ETAPA" != fase2a ]; then
  [ -f "$PAQ/fase2b/repo/SHA256SUMS" ] && (cd "$PAQ/fase2b/repo" && sha256sum -c SHA256SUMS --quiet) >/dev/null 2>&1 && ok "plantillas íntegras (SHA256SUMS de fase2b/repo)" || bloquear "las plantillas de fase2b/repo no verifican con su SHA256SUMS"
  for f in "${UPS[@]}" "${DOWNS[@]}"; do [ -f "$f" ] || bloquear "falta la plantilla $(basename "$f")"; done
fi
echo "== 2. Cuenta y estado actual (solo lectura)"
n=$(sql_dba -e "SELECT COUNT(*) FROM mysql.user WHERE User='$CUENTA' AND Host='$HOST_CUENTA'" 2>/dev/null || echo ERR)
[ "$n" = 1 ] && ok "la cuenta existe" || bloquear "la cuenta $CUENTA@$HOST_CUENTA no existe o no se pudo consultar (resultado: $n)"
GRANTS=$(grants_actuales || true)
[ -n "$GRANTS" ] || bloquear "SHOW GRANTS no devolvió nada"
ACTUAL=$(estado_cuenta)
ok "estado actual de la cuenta (privilegios + vista de alta): $ACTUAL"
V=$(violaciones_generales)
[ -z "$V" ] && ok "sin privilegios de más (sin DELETE/ALL/DDL/GRANT OPTION/otra base/globales; sin INSERT@user ni UPDATE(email); audit_log solo SELECT+INSERT)" || bloquear "la cuenta tiene privilegios de más: $V"
t=$(sql_dba -e "SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE EVENT_OBJECT_SCHEMA='$DB' AND EVENT_OBJECT_TABLE='audit_log'" 2>/dev/null || echo ERR)
[ "$t" = 2 ] && ok "audit_log conserva sus 2 triggers de solo inserción" || bloquear "audit_log no tiene exactamente 2 triggers (resultado: $t)"
if [ "$ETAPA" != fase2a ] && [ "$ACTUAL" != fase2a ]; then
  VP=$(vista_problemas)
  [ -z "$VP" ] && ok "vista v_user_alta: insertable, WITH CHECK OPTION vigente, solo role user/usuario, DEFINER $CUENTA_VISTA@localhost bloqueado y con SELECT/INSERT por columnas únicamente" || aviso "vista de alta: $VP"
fi
echo "== 3. Interruptores del .env (solo se lee la presencia/valor exacto de dos claves)"
U_ENV=$(env_valor_actual ADMIN_WRITES_USUARIOS); S_ENV=$(env_valor_actual ADMIN_WRITES_USUARIOS_SENSIBLE); R_ENV=$(env_valor_actual ADMIN_WRITES_USUARIOS_PASSWORD_RESET)

case $MODO in
  verificar)
    if [ "$ETAPA" = "$ACTUAL" ] && [ "$BLOQUEOS" = 0 ]; then ok "VERIFICACIÓN: la cuenta corresponde a la etapa $ETAPA"; echo "RESULTADO-PRIVILEGIOS: PASS $ETAPA"; exit 0; fi
    fallo "VERIFICACIÓN: se esperaba la etapa $ETAPA y la cuenta está en «$ACTUAL» (bloqueos: $BLOQUEOS)"; echo "RESULTADO-PRIVILEGIOS: FAIL $ETAPA"; exit 30 ;;
  simular|aplicar)
    case $ETAPA in
      no-sensibles)
        [ "$ACTUAL" = fase2a ] || bloquear "no-sensibles solo se aplica sobre una cuenta en estado fase2a, sin vista ni cuenta DEFINER previas (está en «$ACTUAL»)"
        [ "$U_ENV" != 1 ] || bloquear "el .env ya tiene ADMIN_WRITES_USUARIOS=1: apáguelo antes de tocar privilegios"
        ver=$(sql_dba -e "SELECT VERSION()" 2>/dev/null || true)
        if [[ $ver =~ ^([0-9]+)\.([0-9]+)\.([0-9]+) ]] && [[ $ver != *MariaDB* ]] && { [ "${BASH_REMATCH[1]}" -gt 8 ] || { [ "${BASH_REMATCH[1]}" = 8 ] && { [ "${BASH_REMATCH[2]}" -gt 0 ] || [ "${BASH_REMATCH[3]}" -ge 18 ]; }; }; }; then ok "MySQL $ver admite CREATE USER ... RANDOM PASSWORD"
        else bloquear "se requiere MySQL >= 8.0.18 (CREATE USER ... RANDOM PASSWORD); versión detectada: «${ver:-desconocida}»"; fi ;;
      password-reset)
        [ "$ACTUAL" = no-sensibles ] || bloquear "P5-R exige no-sensibles verificado y sin P5 general"
        [ "$R_ENV" != 1 ] || bloquear "apague P5-R antes de cambiar privilegios"
        [ "$S_ENV" != 1 ] || bloquear "P5 general no puede estar activo" ;;
      sensibles)
        [ "$ACTUAL" = no-sensibles ] || bloquear "sensibles exige la etapa no-sensibles ya aplicada y verificada (está en «$ACTUAL»)"
        [ "$S_ENV" != 1 ] || bloquear "el .env ya tiene ADMIN_WRITES_USUARIOS_SENSIBLE=1: apáguelo antes de tocar privilegios" ;;
    esac ;;
  revertir)
    case $ETAPA in
      no-sensibles) { [ "$ACTUAL" = no-sensibles ] || { [ "$ACTUAL" = otro ] && parcial_2b && ! grep -qE '(password|role)' <<<"$(columnas_de UPDATE)"; }; } || bloquear "no hay nada que revertir: la cuenta está en «$ACTUAL» (si está en «sensibles», revierta primero esa etapa; un estado parcial de no-sensibles SÍ se puede revertir)"
                    [ "$U_ENV" != 1 ] || bloquear "apague ADMIN_WRITES_USUARIOS (interruptor-usuarios.sh apagar) antes de revertir privilegios" ;;
      password-reset) [ "$ACTUAL" = password-reset ] || bloquear "P5-R exige etapa password-reset para revertir"
                  [ "$R_ENV" != 1 ] || bloquear "apague ADMIN_WRITES_USUARIOS_PASSWORD_RESET antes de revertir" ;;
      sensibles) { [ "$ACTUAL" = sensibles ] || { [ "$ACTUAL" = otro ] && parcial_2b && grep -qE '(password|role)' <<<"$(columnas_de UPDATE)"; }; } || bloquear "no hay nada que revertir: la cuenta está en «$ACTUAL»"
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

LISTA=("${UPS[@]}"); [ "$MODO" = revertir ] && LISTA=("${DOWNS[@]}")
echo "== 4. Sentencias que se ejecutarían (sin contraseñas: ninguna lleva claves)"
if [ -f "${LISTA[0]}" ]; then sentencias "${LISTA[@]}" | sed 's/^/   /'; fi
if [ "$BLOQUEOS" != 0 ]; then fallo "$BLOQUEOS bloqueo(s): NO se escribe nada."; exit 10; fi
if [ "$MODO" = simular ]; then ok "SIMULACIÓN terminada: no se escribió nada. Código 0 = se puede aplicar la etapa $ETAPA."; exit 0; fi

# ---- escritura (aplicar / revertir) ----
SELLO=$(sello_utc); EVID="$DIR_RESPALDO/privilegios-$ETAPA-$MODO-$SELLO-$$"
install -d -m 700 "$DIR_RESPALDO" && mkdir -m 700 "$EVID" || { fallo "no se pudo crear la carpeta de evidencia $EVID"; exit 10; }
printf '%s\n' "$GRANTS" > "$EVID/grants-antes.txt"; chmod 600 "$EVID/grants-antes.txt"
{ vista_existe && sql_dba -e "SHOW CREATE VIEW \`$DB\`.\`v_user_alta\`" 2>/dev/null; } > "$EVID/vista-antes.txt" || true; chmod 600 "$EVID/vista-antes.txt"
printf 'etapa %s\nmodo %s\ncuenta %s@%s\ncuenta-vista %s@localhost\nbase %s\nautorizacion %s\nrespaldo %s\n' "$ETAPA" "$MODO" "$CUENTA" "$HOST_CUENTA" "$CUENTA_VISTA" "$DB" "$CONFIRMO" "${RESPALDO:-n/a}" > "$EVID/INFO.txt"; chmod 600 "$EVID/INFO.txt"
ok "evidencia previa en $EVID (grants-antes.txt, vista-antes.txt)"
fallo_parcial=0
while IFS= read -r s; do
  [ -n "$s" ] || continue
  if inyectar privilegios && [ "$fallo_parcial" = 0 ] && grep -q 'UPDATE' <<<"$s"; then fallo_parcial=1; fallo "(ensayo) fallo inyectado en: ${s:0:60}…"; break; fi
  if ! $MYSQL_ADMIN -e "$s" >"$EVID/salida.txt" 2>&1; then
    # al REVERTIR un estado parcial, quitar lo que no está concedido o ya no existe no es un error (MySQL: 1147 «no such grant», 1146 «no existe la tabla»)
    if [ "$MODO" = revertir ] && grep -qiE 'no such grant|1147|1146' "$EVID/salida.txt"; then ok "(ya revocado) ${s:0:60}…"; continue; fi
    fallo_parcial=1; fallo "falló la sentencia: ${s:0:80}…"; break
  fi
  : > "$EVID/salida.txt"   # CREATE USER ... RANDOM PASSWORD imprime una clave de una cuenta bloqueada que no se usa: no se conserva
  ok "ejecutada: ${s:0:70}…"
done < <(sentencias "${LISTA[@]}")
if [ "$fallo_parcial" = 1 ]; then
  fallo "ESTADO POSIBLEMENTE PARCIAL (GRANT/REVOKE/CREATE VIEW no son transaccionales). Se intenta volver al estado previo."
  if [ "$MODO" = aplicar ]; then
    while IFS= read -r s; do [ -n "$s" ] && $MYSQL_ADMIN -e "$s" >/dev/null 2>&1; done < <(sentencias "${DOWNS[@]}")
  fi
  GRANTS=$(grants_actuales || true); printf '%s\n' "$GRANTS" > "$EVID/grants-despues-del-fallo.txt"; chmod 600 "$EVID/grants-despues-del-fallo.txt"
  fallo "estado tras el intento de reversión: $(estado_cuenta) (esperado antes: $ACTUAL). Evidencia en $EVID"
  exit 20
fi
GRANTS=$(grants_actuales || true); printf '%s\n' "$GRANTS" > "$EVID/grants-despues.txt"; chmod 600 "$EVID/grants-despues.txt"
{ vista_existe && sql_dba -e "SHOW CREATE VIEW \`$DB\`.\`v_user_alta\`" 2>/dev/null; } > "$EVID/vista-despues.txt" || true; chmod 600 "$EVID/vista-despues.txt"
FIN=$(estado_cuenta); V=$(violaciones_generales)
case $MODO:$ETAPA in
  aplicar:no-sensibles) ESPERADO=no-sensibles ;; aplicar:sensibles) ESPERADO=sensibles ;; aplicar:password-reset) ESPERADO=password-reset ;;
  revertir:no-sensibles) ESPERADO=fase2a ;; revertir:password-reset) ESPERADO=no-sensibles ;;
  revertir:sensibles) if [ "$ACTUAL" = sensibles ]; then ESPERADO=no-sensibles; else ESPERADO=$FIN; grep -qE '(password|role)' <<<"$(columnas_de UPDATE)" && ESPERADO=sin-sensibles; fi ;;
esac
if [ "$FIN" = "$ESPERADO" ] && [ -z "$V" ]; then ok "VERIFICACIÓN POSTERIOR: la cuenta está en «$FIN», sin privilegios de más"; echo "RESULTADO-PRIVILEGIOS: PASS $MODO $ETAPA"; exit 0; fi
fallo "VERIFICACIÓN POSTERIOR FALLIDA: estado «$FIN» (esperado «$ESPERADO»), violaciones: ${V:-ninguna}. Evidencia en $EVID. Revierta con --revertir o escale al DBA."
echo "RESULTADO-PRIVILEGIOS: FAIL $MODO $ETAPA"; exit 30
