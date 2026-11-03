#!/usr/bin/env bash
# Cervesur · UX-08F.1 · ¿Puede usarse un puerto candidato para el entorno de QA en 127.0.0.1? SOLO LECTURA.
#   bash puerto-candidato-solo-lectura.sh [puerto]        (por defecto 8094, que es solo un CANDIDATO pendiente de verificar)
# Comprueba, sin modificar nada: (1) listeners en cualquier interfaz, (2) menciones en Apache (Listen, VirtualHost, ports.conf, conf-*),
# (3) apache2ctl -S, (4) menciones en el resto de /etc, /opt y /var/www (otros servicios que lo tengan configurado), (5) si se puede
# vincular un socket a 127.0.0.1:<puerto> (se abre y se cierra al instante; no queda ningún listener). No imprime contenido de archivos de
# configuración: solo los nombres de archivo y el número de línea. Con sudo se ven más archivos; sin sudo el resultado puede ser incompleto
# y se declara. Un puerto de la lista de los portales actuales (80, 443, 8443, 8088-8093) se rechaza siempre.
set -u
P="${1:-8094}"
[[ $P =~ ^[1-9][0-9]{3,4}$ ]] && [ "$P" -ge 1024 ] && [ "$P" -le 65535 ] || { echo "Puerto no válido: $P"; exit 2; }
SUDO=""; [ "$(id -u)" -eq 0 ] || SUDO="sudo -n"
$SUDO true 2>/dev/null || { SUDO=""; echo "AVISO: sin sudo sin contraseña; se verá solo lo que su usuario puede leer (resultado posiblemente incompleto)."; }
RE="(^|[^0-9])$P([^0-9]|\$)"
bad=0; unknown=0
echo "################ PUERTO CANDIDATO $P (solo lectura) ################"
for p in 80 443 8443 8088 8089 8090 8091 8092 8093 22 3306 33060; do [ "$p" = "$P" ] && { echo "RECHAZADO: $P es un puerto de otro portal o servicio del servidor"; bad=1; }; done

echo; echo "== 1. Listeners actuales (cualquier interfaz) =="
l=$(ss -ltnH "sport = :$P" 2>/dev/null | awk '{print $4}' | sort -u | tr '\n' ' ')
if [ -n "$l" ]; then echo "OCUPADO: escuchan $l"; bad=1; else echo "Nada escucha en el puerto $P"; fi
echo "Todos los puertos en escucha, para contexto:"; ss -ltnH 2>/dev/null | awk '{print "  " $4}' | sort -u

echo; echo "== 2. Menciones en la configuración de Apache (archivo:línea; sin contenido) =="
m=$($SUDO grep -rnE "$RE" /etc/apache2/ports.conf /etc/apache2/sites-available /etc/apache2/sites-enabled /etc/apache2/conf-available /etc/apache2/conf-enabled /etc/apache2/mods-enabled /etc/apache2/apache2.conf 2>/dev/null | cut -d: -f1,2 | sort -u)
if [ -n "$m" ]; then echo "$m"; echo "OCUPADO en la configuración de Apache"; bad=1; else echo "Ninguna mención en /etc/apache2"; fi

echo; echo "== 3. apache2ctl -S =="
s=$($SUDO apache2ctl -S 2>&1 | grep -nE "[: ]$P( |\$)")
if [ -n "$s" ]; then echo "$s"; bad=1; else echo "Ningún VirtualHost activo usa el puerto $P"; fi

echo; echo "== 4. Otros servicios con el puerto configurado (nombres de archivo; sin contenido) =="
o=$($SUDO grep -rIlE "$RE" /etc /opt /var/www /srv /usr/local/etc 2>/dev/null | grep -vE '^/etc/(ssl|ca-certificates|alternatives|apparmor|mime|services|protocols|ssh/moduli)|\.(png|jpg|gz|map|woff2?)$|^/etc/apache2/' | head -15)
if [ -n "$o" ]; then echo "$o"; echo "REVISAR a mano si alguno de esos archivos reserva el puerto (puede ser una coincidencia numérica)"; unknown=1; else echo "Ninguna otra mención en /etc, /opt, /var/www, /srv"; fi
command -v nginx >/dev/null 2>&1 && { $SUDO nginx -T 2>/dev/null | grep -nE "$RE" | cut -c1-60 | head -5; }
for u in $($SUDO systemctl list-units --type=socket --no-legend 2>/dev/null | awk '{print $1}'); do $SUDO systemctl cat "$u" 2>/dev/null | grep -qE "$RE" && { echo "unidad de socket que lo menciona: $u"; bad=1; }; done

echo; echo "== 5. ¿Se puede vincular a 127.0.0.1:$P? (se abre y se cierra al instante) =="
if command -v python3 >/dev/null 2>&1; then
  if ! python3 - "$P" <<'PY'
import socket, sys
p = int(sys.argv[1]); s = socket.socket()
try:
    s.bind(("127.0.0.1", p)); print("Se puede vincular a 127.0.0.1:%d" % p)
except OSError as e:
    print("NO se puede vincular a 127.0.0.1:%d: %s" % (p, e)); sys.exit(1)
finally:
    s.close()
PY
  then bad=1; fi
else echo "(python3 no disponible: comprobación omitida)"; unknown=1; fi

echo
if [ "$bad" -ne 0 ]; then echo "RESULTADO: NO-GO para el puerto $P (elija otro candidato y repita)"; exit 1; fi
if [ "$unknown" -ne 0 ]; then echo "RESULTADO: CANDIDATO con puntos por revisar a mano (ver arriba); no es una aprobación"; exit 0; fi
echo "RESULTADO: el puerto $P parece LIBRE para 127.0.0.1. Es un candidato: la aprobación definitiva la da la simulación de instalar.sh y la persona responsable."
echo "(nada fue modificado)"
