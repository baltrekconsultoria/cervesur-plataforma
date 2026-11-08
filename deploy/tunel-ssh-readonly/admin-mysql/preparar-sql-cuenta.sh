#!/usr/bin/env bash
# PROCEDIMIENTO ADMINISTRATIVO INDEPENDIENTE (UX-07D.3B/3B.1) · prepara —NO ejecuta— la creación de la cuenta MySQL de solo lectura cervesur_ro_diag.
#   sudo bash admin-mysql/preparar-sql-cuenta.sh [--host 127.0.0.1] [--base cervesur_portalbi] [--salida /root/cervesur-ro-diag]
# Genera, con permisos 600 y SIN mostrar la clave:
#   <salida>.clave   la clave aleatoria de la cuenta (la usa instalar.sh --clave-archivo)
#   <salida>.sql     el SQL final (CREATE USER + 6 GRANT SELECT por columna) para que un ADMINISTRADOR AUTORIZADO lo REVISE y lo ejecute a mano:
#                      mysql < <salida>.sql
# La clave aparece UNA sola vez en el .sql: en la sentencia CREATE USER … IDENTIFIED BY. Nunca en comentarios. El script lo COMPRUEBA y, si no se cumple,
# borra los dos archivos y falla. Para REVISAR el SQL sin ver la clave use  admin-mysql/mostrar-sql-redactado.sh  (no use cat/less sobre el .sql).
# Este script NO se conecta a MySQL. Después de ejecutar el SQL: 02-verificar-privilegios-diagnostico.sql y preflight-acceso.sh.
# Destruir ambos archivos cuando ya no hagan falta (la clave queda en el .env del sitio). Revertir con 99-revocar-cuenta-diagnostico.sql (solo esa cuenta).
set -euo pipefail
AQUI="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
HOST=127.0.0.1; BASE=cervesur_portalbi; SALIDA=/root/cervesur-ro-diag
while [ $# -gt 0 ]; do
  case $1 in
    --host) HOST=$2; shift 2 ;;
    --base) BASE=$2; shift 2 ;;
    --salida) SALIDA=$2; shift 2 ;;
    *) echo "Argumento desconocido: $1" >&2; exit 1 ;;
  esac
done
[[ $HOST =~ ^[A-Za-z0-9.:%_-]+$ && $BASE =~ ^[A-Za-z0-9_$-]+$ ]] || { echo "Host o base con caracteres no permitidos." >&2; exit 1; }
[ ! -e "$SALIDA.clave" ] && [ ! -e "$SALIDA.sql" ] || { echo "Ya existen $SALIDA.clave o $SALIDA.sql: no se sobrescriben." >&2; exit 1; }
umask 077
CLAVE="Aa1!$(openssl rand -hex 24)"
printf '%s\n' "$CLAVE" > "$SALIDA.clave"
# La clave se sustituye SOLO en líneas que no son comentario; BASE y HOST se sustituyen en todo el archivo.
sed -e "s|__BASE__|$BASE|g" -e "s|__HOST__|$HOST|g" -e "/^[[:space:]]*--/!s|__CLAVE_DIAGNOSTICO__|$CLAVE|g" "$AQUI/01-cuenta-diagnostico.sql" > "$SALIDA.sql"
chmod 600 "$SALIDA.clave" "$SALIDA.sql"
# Comprobaciones de redacción: la clave está exactamente en una línea, esa línea es el CREATE USER, ningún comentario la contiene y no quedan marcadores.
en_total=$(grep -cF -- "$CLAVE" "$SALIDA.sql" || true)
en_comentarios=$(grep -E '^[[:space:]]*--' "$SALIDA.sql" | grep -cF -- "$CLAVE" || true)
en_create=$(grep -E "^CREATE USER 'cervesur_ro_diag'@'[^']+' IDENTIFIED BY '" "$SALIDA.sql" | grep -cF -- "$CLAVE" || true)
marcadores=$(grep -cE '__[A-Z_]+__' "$SALIDA.sql" || true)
if [ "$en_total" != "1" ] || [ "$en_comentarios" != "0" ] || [ "$en_create" != "1" ] || [ "$marcadores" != "0" ]; then
  rm -f -- "$SALIDA.clave" "$SALIDA.sql"
  unset CLAVE
  echo "La verificación de redacción falló (la clave no está solo en CREATE USER, o quedan marcadores): se borraron los archivos generados." >&2
  exit 1
fi
unset CLAVE
echo "Preparados (sin mostrar la clave): $SALIDA.sql y $SALIDA.clave (600)."
echo "Revise el SQL sin ver la clave:  bash $AQUI/mostrar-sql-redactado.sh $SALIDA.sql"
echo "Ejecútelo con un administrador autorizado:  mysql < $SALIDA.sql"
