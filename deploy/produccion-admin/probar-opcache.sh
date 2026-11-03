#!/usr/bin/env bash
# Prueba LOCAL de opcache-solo-lectura.sh con árboles de configuración simulados. No toca ningún servidor.
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"; T="$(mktemp -d)"; trap 'rm -rf "$T"' EXIT
pass=0; fail=0
chk() { local d=$1; shift; if "$@"; then echo "PASS  $d"; pass=$((pass + 1)); else echo "FAIL  $d"; fail=$((fail + 1)); fi; }
montar() { # $1 = contenido de 10-opcache.ini, $2 = php.ini extra, $3 = directiva de Apache
  rm -rf "$T/php" "$T/ap"; mkdir -p "$T/php/8.3/apache2/conf.d" "$T/ap/mods-enabled" "$T/f" "$T/b"
  : > "$T/ap/mods-enabled/php8.3.load"; printf '%s\n' "${2:-; php.ini}" > "$T/php/8.3/apache2/php.ini"; printf '%s\n' "$1" > "$T/php/8.3/apache2/conf.d/10-opcache.ini"
  [ -n "${3:-}" ] && printf '%s\n' "$3" > "$T/ap/mods-enabled/zz.conf"; true
}
correr() { CERVESUR_PHP_ETC="$T/php" CERVESUR_APACHE_ETC="$T/ap" CERVESUR_ADMIN_FRONT="$T/f" CERVESUR_ADMIN_BACK="$T/b" bash "$HERE/opcache-solo-lectura.sh" "$@" 2>&1; }
ok_go() { grep -q "^RESULTADO: GO" <<<"$1"; }; ok_nogo() { grep -q "^RESULTADO: NO-GO" <<<"$1"; }
montar 'zend_extension=opcache'; o=$(correr); chk "valores por defecto de Ubuntu (opcache cargado, revalida cada 2 s): espera mínima 4 s = la de actualizar.sh → GO" ok_go "$o"
o=$(correr --espera 3); chk "una espera de 3 s con freq=2 NO basta → NO-GO y pide CERVESUR_ESPERA_OPCACHE=4" bash -c 'grep -q "^RESULTADO: NO-GO" <<<"$1" && grep -q "CERVESUR_ESPERA_OPCACHE=4" <<<"$1"' _ "$o"
montar $'zend_extension=opcache\nopcache.enable=1\nopcache.revalidate_freq=0\nopcache.file_update_protection=0'; o=$(correr); chk "freq=0 → espera mínima 2 s → GO con 4 s" ok_go "$o"
montar $'zend_extension=opcache\nopcache.validate_timestamps=0'; o=$(correr --espera 30); chk "validate_timestamps=0 → NO-GO aunque se espere (hace falta recarga suave aprobada)" bash -c 'grep -q "^RESULTADO: NO-GO" <<<"$1" && grep -q "recarga SUAVE" <<<"$1"' _ "$o"
montar $'zend_extension=opcache\nopcache.enable=0'; o=$(correr); chk "opcache.enable=0 → GO (sin caché de código)" ok_go "$o"
montar 'extension=otra'; o=$(correr); chk "opcache no cargado en la configuración de Apache → GO" ok_go "$o"
montar $'zend_extension=opcache\nopcache.revalidate_freq=60' $'; php.ini\nopcache.revalidate_freq=2'; o=$(correr --espera 4); chk "el conf.d posterior gana al php.ini (freq=60) → NO-GO y pide esperar ≥ 62 s" bash -c 'grep -q "^RESULTADO: NO-GO" <<<"$1" && grep -q "CERVESUR_ESPERA_OPCACHE=62" <<<"$1"' _ "$o"
montar $'zend_extension=opcache\nopcache.revalidate_freq=0\nopcache.file_update_protection=0' '' 'php_admin_value opcache.validate_timestamps 0'; o=$(correr); chk "php_admin_value de Apache que apaga la validación → NO-GO y se avisa de la sobrescritura" bash -c 'grep -q "^RESULTADO: NO-GO" <<<"$1" && grep -q "AVISO: hay sobrescrituras" <<<"$1"' _ "$o"
montar 'zend_extension=opcache'; rm -rf "$T/php/8.3"; o=$(correr); chk "sin configuración de PHP para Apache → NO-GO (no adivina)" ok_nogo "$o"
montar 'zend_extension=opcache'; antes=$(find "$T" -type f | sort | xargs sha256sum | sha256sum); correr >/dev/null; despues=$(find "$T" -type f | sort | xargs sha256sum | sha256sum)
chk "el script no creó ni modificó nada en el árbol simulado" test "$antes" = "$despues"
echo; echo "$pass PASS / $fail FAIL"; [ "$fail" -eq 0 ]
