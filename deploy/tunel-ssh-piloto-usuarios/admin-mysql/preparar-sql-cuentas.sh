#!/usr/bin/env bash
# PROCEDIMIENTO ADMINISTRATIVO INDEPENDIENTE (UX-07D.4B.1) · prepara —NO ejecuta— la creación de las TRES cuentas del piloto con usuarios reales y de su base de estado:
#   cervesur_ro_auth (SELECT sobre 9 columnas de user) · cervesur_ro_catalogo (SELECT sobre 21 columnas de catálogo y permisos) · cervesur_react_estado (DML solo en su base)
#   sudo bash admin-mysql/preparar-sql-cuentas.sh [--host 127.0.0.1] [--base cervesur_portalbi] [--base-estado cervesur_react_estado] [--salida /root/cervesur-usuarios]
# Genera, con permisos 600 y SIN mostrar ninguna clave:
#   <salida>.claves  tres líneas CLAVE=valor (CERVESUR_RO_AUTH, CERVESUR_RO_CATALOGO, CERVESUR_REACT_ESTADO), una clave aleatoria DISTINTA por cuenta (la usa instalar.sh --claves-archivo)
#   <salida>.sql     el SQL final (01-cuentas-lectura.sql + 02-estado-base-y-cuenta.sql) para que un ADMINISTRADOR AUTORIZADO lo REVISE y lo ejecute a mano:  mysql < <salida>.sql
# Cada clave aparece UNA sola vez en el .sql: en su sentencia CREATE USER … IDENTIFIED BY. Nunca en comentarios. El script lo COMPRUEBA y, si no se cumple,
# borra los dos archivos y falla. Para REVISAR el SQL sin ver las claves use  admin-mysql/mostrar-sql-redactado.sh  (no use cat/less sobre el .sql).
# Este script NO se conecta a MySQL. Después de ejecutar el SQL: 03-verificar-privilegios.sql y preflight-acceso.sh. Destruir ambos archivos cuando ya no hagan falta
# (las claves quedan en el .env del sitio). Revertir con 99-revocar-cuentas.sql (solo esas tres cuentas; no elimina la base de estado).
set -euo pipefail
AQUI="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HOST=127.0.0.1; BASE=cervesur_portalbi; BASE_ESTADO=cervesur_react_estado; SALIDA=/root/cervesur-usuarios
while [ $# -gt 0 ]; do
  case $1 in
    --host) HOST=$2; shift 2 ;;
    --base) BASE=$2; shift 2 ;;
    --base-estado) BASE_ESTADO=$2; shift 2 ;;
    --salida) SALIDA=$2; shift 2 ;;
    *) echo "Argumento desconocido: $1" >&2; exit 1 ;;
  esac
done
[[ $HOST =~ ^[A-Za-z0-9.:%_-]+$ && $BASE =~ ^[A-Za-z0-9_$-]+$ && $BASE_ESTADO =~ ^[A-Za-z0-9_$-]+$ ]] || { echo "Host o base con caracteres no permitidos." >&2; exit 1; }
[ "$BASE" != "$BASE_ESTADO" ] || { echo "La base de estado debe ser distinta de la base de lectura." >&2; exit 1; }
[ -f "$AQUI/01-cuentas-lectura.sql" ] && [ -f "$AQUI/02-estado-base-y-cuenta.sql" ] || { echo "Faltan 01-cuentas-lectura.sql o 02-estado-base-y-cuenta.sql junto a este script." >&2; exit 1; }
[ ! -e "$SALIDA.claves" ] && [ ! -e "$SALIDA.sql" ] || { echo "Ya existen $SALIDA.claves o $SALIDA.sql: no se sobrescriben." >&2; exit 1; }
umask 077
gen() { echo "Aa1!$(openssl rand -hex 24)"; }
C_AUTH=$(gen); C_CAT=$(gen); C_EST=$(gen)
[ "$C_AUTH" != "$C_CAT" ] && [ "$C_AUTH" != "$C_EST" ] && [ "$C_CAT" != "$C_EST" ] || { echo "Error interno: claves repetidas." >&2; exit 1; }
printf 'CERVESUR_RO_AUTH=%s\nCERVESUR_RO_CATALOGO=%s\nCERVESUR_REACT_ESTADO=%s\n' "$C_AUTH" "$C_CAT" "$C_EST" > "$SALIDA.claves"
# Las claves se sustituyen SOLO en líneas que no son comentario; BASE, BASE_ESTADO y HOST se sustituyen en todo el archivo.
cat "$AQUI/01-cuentas-lectura.sql" "$AQUI/02-estado-base-y-cuenta.sql" |
  sed -e "s|__BASE_ESTADO__|$BASE_ESTADO|g" -e "s|__BASE__|$BASE|g" -e "s|__HOST__|$HOST|g" \
      -e "/^[[:space:]]*--/!s|__CLAVE_AUTH__|$C_AUTH|g" -e "/^[[:space:]]*--/!s|__CLAVE_CATALOGO__|$C_CAT|g" -e "/^[[:space:]]*--/!s|__CLAVE_ESTADO__|$C_EST|g" > "$SALIDA.sql"
chmod 600 "$SALIDA.claves" "$SALIDA.sql"
# Comprobaciones de redacción: cada clave está exactamente en una línea, esa línea es SU CREATE USER, ningún comentario la contiene y no quedan marcadores.
fallo=0
comprobar() { # clave cuenta
  local en_total en_com en_create
  en_total=$(grep -cF -- "$1" "$SALIDA.sql" || true)
  en_com=$(grep -E '^[[:space:]]*--' "$SALIDA.sql" | grep -cF -- "$1" || true)
  en_create=$(grep -E "^CREATE USER '$2'@'[^']+' IDENTIFIED BY '" "$SALIDA.sql" | grep -cF -- "$1" || true)
  [ "$en_total" = "1" ] && [ "$en_com" = "0" ] && [ "$en_create" = "1" ] || fallo=1
}
comprobar "$C_AUTH" cervesur_ro_auth; comprobar "$C_CAT" cervesur_ro_catalogo; comprobar "$C_EST" cervesur_react_estado
[ "$(grep -cE '__[A-Z_]+__' "$SALIDA.sql" || true)" = "0" ] || fallo=1
[ "$(grep -c '^CREATE USER' "$SALIDA.sql")" = "3" ] || fallo=1
if grep -qiE '^(GRANT .* ON \*\.\*|GRANT ALL|.*WITH GRANT OPTION)' "$SALIDA.sql"; then fallo=1; fi
if [ "$fallo" -ne 0 ]; then
  rm -f -- "$SALIDA.claves" "$SALIDA.sql"
  unset C_AUTH C_CAT C_EST
  echo "La redacción de claves no se cumplió: se borraron los archivos. No se generó nada." >&2
  exit 1
fi
unset C_AUTH C_CAT C_EST
echo "Generados (permisos 600, claves NO mostradas):"
echo "  $SALIDA.sql      SQL para revisar con  admin-mysql/mostrar-sql-redactado.sh $SALIDA.sql  y ejecutar a mano por un administrador autorizado"
echo "  $SALIDA.claves   las tres claves (para instalar.sh --claves-archivo); destruir tras la instalación"
echo "No se ha ejecutado nada en MySQL."
