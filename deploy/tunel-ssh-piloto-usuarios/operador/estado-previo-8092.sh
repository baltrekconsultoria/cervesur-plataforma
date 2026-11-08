#!/usr/bin/env bash
# ESTADO PREVIO / POSTERIOR del piloto 8092 (UX-07D.6). SOLO LECTURA. No se incluye en el paquete aprobado (no altera su SHA-256).
#   sudo bash estado-previo-8092.sh > /root/estado-8092-ANTES.txt     (y de nuevo > ...-DESPUES.txt; luego: diff)
# No imprime contraseñas, tokens, enlaces ni correos: del .env solo muestra los NOMBRES de las claves y los valores no secretos de modo.
set -uo pipefail
ENV=/opt/cervesur-react-usuarios/backend/.env
echo "== fecha";            date -u +%FT%TZ
echo "== apache";           systemctl is-active apache2 2>/dev/null; apache2ctl -v 2>/dev/null | head -1
echo "== pid de apache (no debe cambiar tras actualizar)"; cat /var/run/apache2/apache2.pid 2>/dev/null
echo "== puertos escuchando"; ss -ltnH 2>/dev/null | awk '{print $4}' | LC_ALL=C sort -u
echo "== HTTP de los portales (código)"
for p in 80 8088 8089 8090 8091; do printf '%s ' "$p"; curl -s -m 8 -o /dev/null -w '%{http_code}\n' "http://127.0.0.1:$p/"; done
printf '8092(https) '; curl -sS -m 8 --cacert /etc/apache2/cervesur-react-usuarios/tls/servidor.crt --resolve localhost:8092:127.0.0.1 -o /dev/null -w '%{http_code}\n' https://localhost:8092/ 2>&1
echo "== ping 8092"; curl -sS -m 8 --cacert /etc/apache2/cervesur-react-usuarios/tls/servidor.crt --resolve localhost:8092:127.0.0.1 https://localhost:8092/api/ping 2>&1 | head -c 200; echo
echo "== cabeceras de la aplicación (HSTS no debe aparecer para localhost)"
curl -sS -m 8 -D - -o /dev/null --cacert /etc/apache2/cervesur-react-usuarios/tls/servidor.crt --resolve localhost:8092:127.0.0.1 https://localhost:8092/ 2>&1 | tr -d '\r' | grep -iE '^(HTTP|strict-transport|content-security|x-frame|x-content)' | cut -c1-120
echo "== versión instalada (huellas de archivos de la aplicación y del backend; sin .env)"
(cd /var/www/cervesur-react-usuarios/public && find . -type f -print0 | sort -z | xargs -0 sha256sum | sha256sum | cut -c1-64 | sed 's/^/public  /')
(cd /opt/cervesur-react-usuarios/backend && find . -type f ! -name .env -print0 | sort -z | xargs -0 sha256sum | sha256sum | cut -c1-64 | sed 's/^/backend /')
grep -o '"pilotMode": *"[a-z]*"' /var/www/cervesur-react-usuarios/public/build-info.json 2>/dev/null
ls /var/www/cervesur-react-usuarios/public/assets 2>/dev/null | head -5
echo "== .env (solo nombres y modo; sin valores secretos)"
sed -n 's/^\([A-Z_]*\)=.*/\1/p' "$ENV" | sort | tr '\n' ' '; echo
grep -E '^(CERVESUR_MODE|CERVESUR_REQUIRED_MODE|DB_NAME|STATE_DB_NAME|DB_USER|AUTH_DB_USER|STATE_DB_USER|CERVESUR_PILOT_SESSION_SECONDS|CERVESUR_PILOT_MAX_FALLOS_IP)=' "$ENV"
printf 'CERVESUR_PILOT_VISOR_IDS='; sed -n 's/^CERVESUR_PILOT_VISOR_IDS=//p' "$ENV"
printf 'CERVESUR_INSECURE_TESTS definido: '; grep -q '^CERVESUR_INSECURE_TESTS=.\+' "$ENV" && echo SI || echo NO
echo "== .env permisos"; stat -c '%a %U:%G' "$ENV"
echo "== lista blanca (ids y estado; sin notas)"
mysql -N -e "SELECT user_id, activo FROM cervesur_react_estado.usuarios_piloto ORDER BY user_id" 2>/dev/null
echo "== respaldos existentes"; ls -1 /var/backups/cervesur-react-usuarios 2>/dev/null
echo "== certificado (vencimiento y huella; sin clave)"
openssl x509 -in /etc/apache2/cervesur-react-usuarios/tls/servidor.crt -noout -enddate -fingerprint -sha256 2>/dev/null
stat -c '%a %U:%G %n' /etc/apache2/cervesur-react-usuarios/tls/servidor.key 2>/dev/null
echo "== configuración de Apache del piloto (huella; la actualización NO debe cambiarla)"
sha256sum /etc/apache2/sites-available/cervesur-react-usuarios.conf /etc/apache2/cervesur-react-usuarios/react-usuarios-common.conf 2>/dev/null | cut -c1-64
echo "== otros portales (huella de configuración)"
sha256sum /etc/apache2/sites-available/cervesur*.conf 2>/dev/null | cut -c1-64,65-
echo "== bases y cuentas (metadatos)"
mysql -N -e "SHOW DATABASES" 2>/dev/null | grep -E '^(cervesur_portalbi|cervesur_react_estado)$'
mysql -N -e "SELECT user, host FROM mysql.user WHERE user LIKE 'cervesur%'" 2>/dev/null | sort
echo "== tablas productivas (CHECKSUM; deben ser idénticos antes y después)"
mysql -N -e "CHECKSUM TABLE cervesur_portalbi.user, cervesur_portalbi.empresa, cervesur_portalbi.reporte, cervesur_portalbi.empresa_usuario, cervesur_portalbi.empresa_reporte, cervesur_portalbi.user_empresa_reporte" 2>/dev/null
