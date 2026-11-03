# Réplica de ENSAYO del servidor para el entorno administrativo de QA (UX-08H): parte de la imagen base local (Ubuntu 24.04 + Apache 2.4 + mod_php 8.3
# + MySQL 8 + «Flutter» simulado en el puerto 80) y le añade los portales SEÑUELO que existen en el servidor real, para que las comprobaciones
# de «byte-identico» y «sin colisión» sean reales:
#   8088 y 8089 (React de prueba y de compatibilidad) · 8090-8093 (ocupados en el servidor real) · 8443 (React admin, TLS) · 443 (React de trabajadores, TLS)
#   y la carpeta /opt/cervesur-react-usuarios (portal publicado de trabajadores, sin el interruptor USER_ADMIN_WRITES)
# más bases y cuentas MySQL señuelo, entre ellas cervesur_portalbi (que el paquete jamás debe tocar).
# NO es el servidor real. Si la imagen base no existe, ensayo-docker.mjs la construye con Dockerfile.base (requiere red).
ARG BASE=cervesur-ensayo-ubuntu:local
FROM ${BASE}
# El servidor real ya tiene mod_headers (lo habilitó el instalador de 8088) y mod_ssl (443/8443).
RUN a2enmod headers ssl >/dev/null \
 && openssl req -x509 -newkey rsa:2048 -nodes -keyout /etc/ssl/private/senuelo.key -out /etc/ssl/certs/senuelo.crt -days 30 -subj "/CN=localhost" >/dev/null 2>&1
COPY decoys/ /etc/apache2/sites-available/
COPY decoy-mysql.sql /root/decoy-mysql.sql
RUN for n in prueba compat admin trabajadores usuarios; do \
      mkdir -p /var/www/cervesur-react-$n/public /opt/cervesur-react-$n/backend \
      && echo "SENUELO-$n" > /var/www/cervesur-react-$n/public/index.html \
      && echo "JWT_SECRET=senuelo-$n-no-es-un-secreto" > /opt/cervesur-react-$n/backend/.env; \
    done \
 && a2ensite cervesur-react-prueba cervesur-react-compat cervesur-react-admin cervesur-react-trabajadores cervesur-react-ocupados >/dev/null
COPY entrypoint.qa.sh /entrypoint.qa.sh
RUN chmod +x /entrypoint.qa.sh
ENTRYPOINT ["/entrypoint.qa.sh"]
