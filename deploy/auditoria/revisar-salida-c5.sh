#!/usr/bin/env bash
# Revisa un archivo de SALIDA de C-5 antes de compartirlo: detecta valores que no deberían estar (hashes, tokens, claves, correos)
# SIN imprimir el contenido encontrado: solo número de línea y tipo. Solo lectura; no modifica el archivo.
# Uso:  bash revisar-salida-c5.sh salida-c5-sistema-<fecha>.txt   |   bash revisar-salida-c5.sh salida-c5-dba-<fecha>.txt
# Código de salida: 0 = sin coincidencias · 1 = hay líneas que revisar y censurar a mano · 2 = uso incorrecto
f="${1:-}"
if [ -z "$f" ] || [ ! -r "$f" ]; then echo "Uso: bash revisar-salida-c5.sh <archivo_de_salida>"; exit 2; fi
alertas=0
chequear() {  # tipo, expresion (ERE)
  local n
  n=$(LC_ALL=C grep -nE -- "$2" "$f" | cut -d: -f1 | tr '\n' ' ')
  if [ -n "$n" ]; then printf 'ALERTA  %-34s lineas: %s\n' "$1" "$n"; alertas=1; fi
}
chequear "hash de contrasena (bcrypt)"        '\$2[aby]\$[0-9]{2}\$'
chequear "token JWT"                          'eyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}'
chequear "cabecera Bearer/Authorization"      '([Bb]earer[[:space:]]+[A-Za-z0-9._-]{10,}|[Aa]uthorization:)'
chequear "secuencia larga (clave, token, hash)" '[A-Za-z0-9+/=_]{40,}'
chequear "correo electronico"                 '[A-Za-z0-9._%+-]+@[A-Za-z0-9-]+\.[A-Za-z]{2,}'
chequear "asignacion de clave/secreto/token"  '([Pp]assword|[Pp]asswd|[Pp]wd|[Ss]ecret|[Tt]oken|[Aa]pi_?[Kk]ey)[[:space:]]*[=:][[:space:]]*[^[:space:]]+'
chequear "bloque de clave privada"            'BEGIN [A-Z ]*PRIVATE KEY'
if [ "$alertas" -eq 0 ]; then
  echo "PASS: sin coincidencias. (Revisar igualmente a ojo antes de enviar; esta comprobacion no sustituye la lectura.)"
  exit 0
fi
echo "Hay lineas para revisar: abra el archivo en esos numeros de linea y sustituya el valor por [CENSURADO] si es un valor sensible."
echo "Los nombres de columna (p. ej. «password» en SHOW CREATE TABLE) no son valores: no se censuran."
exit 1
