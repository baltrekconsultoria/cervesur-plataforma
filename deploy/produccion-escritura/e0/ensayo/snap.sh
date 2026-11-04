#!/usr/bin/env bash
# Ensayo (corre DENTRO del contenedor «servidor»): instantánea de la base para probar que un script no cambió nada.
# Imprime CHECKSUM TABLE de todas las tablas base de la base indicada, conteos de objetos de information_schema, número de cuentas y una huella por cuenta de sus privilegios.
# Usa MYSQL_PWD del entorno. Solo lee.
set -u
FIX="${1:-fixture_e0}"
S=(mysql -h 127.0.0.1 -uroot -N -B)
for t in $("${S[@]}" -e "SELECT table_name FROM information_schema.tables WHERE table_schema='$FIX' AND table_type='BASE TABLE' ORDER BY 1"); do
  "${S[@]}" -e "CHECKSUM TABLE $FIX.$t"
done
"${S[@]}" -e "
SELECT 'objetos_tablas', COUNT(*) FROM information_schema.tables WHERE table_schema='$FIX'
UNION ALL SELECT 'objetos_triggers', COUNT(*) FROM information_schema.triggers WHERE trigger_schema='$FIX'
UNION ALL SELECT 'objetos_rutinas', COUNT(*) FROM information_schema.routines WHERE routine_schema='$FIX'
UNION ALL SELECT 'objetos_vistas', COUNT(*) FROM information_schema.views WHERE table_schema='$FIX'
UNION ALL SELECT 'objetos_columnas', COUNT(*) FROM information_schema.columns WHERE table_schema='$FIX'
UNION ALL SELECT 'objetos_indices', COUNT(*) FROM information_schema.statistics WHERE table_schema='$FIX'
UNION ALL SELECT 'objetos_fks', COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema='$FIX'
UNION ALL SELECT 'esquemas', COUNT(*) FROM information_schema.schemata
UNION ALL SELECT 'cuentas', COUNT(*) FROM mysql.user"
while IFS=$'\t' read -r u h; do
  printf 'grants %s@%s ' "$u" "$h"
  "${S[@]}" -e "SHOW GRANTS FOR '$u'@'$h'" | md5sum | cut -c1-12
done < <("${S[@]}" -e "SELECT user, host FROM mysql.user ORDER BY user, host")
