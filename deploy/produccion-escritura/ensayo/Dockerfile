# Réplica de ENSAYO del servidor para la FASE 2A del administrador (UX-08H.1): parte de la imagen base local (Ubuntu 24.04 + Apache 2.4 + mod_php 8.3 + MySQL 8 +
# «Flutter» simulado en el puerto 80; la construye ensayo-docker.mjs con deploy/qa-escritura/ensayo/Dockerfile.base si no existe) y le añade los portales SEÑUELO que existen en el
# servidor real y que el kit jamás debe tocar: React de trabajadores (443, TLS) y la QA aislada (8094). El administrador (8093) NO es señuelo: lo instala el paquete BASE de la fase 1.
# NO es el servidor real.
ARG BASE=cervesur-ensayo-ubuntu:local
FROM ${BASE}
RUN a2enmod headers ssl >/dev/null \
 && openssl req -x509 -newkey rsa:2048 -nodes -keyout /etc/ssl/private/senuelo.key -out /etc/ssl/certs/senuelo.crt -days 30 -subj "/CN=localhost" >/dev/null 2>&1
COPY decoys/ /etc/apache2/sites-available/
RUN mkdir -p /var/www/cervesur-react-trabajadores/public /opt/cervesur-react-trabajadores/backend /var/www/cervesur-qa-8094/public /opt/cervesur-qa-8094/backend \
 && echo "SENUELO-trabajadores" > /var/www/cervesur-react-trabajadores/public/index.html && echo "JWT_SECRET=senuelo-trabajadores-no-es-un-secreto" > /opt/cervesur-react-trabajadores/backend/.env \
 && echo "SENUELO-qa-8094" > /var/www/cervesur-qa-8094/public/index.html && echo "JWT_SECRET=senuelo-qa-no-es-un-secreto" > /opt/cervesur-qa-8094/backend/.env \
 && a2ensite cervesur-react-trabajadores cervesur-qa-8094 cervesur-ocupados >/dev/null
COPY entrypoint.sh /entrypoint.produccion.sh
RUN chmod +x /entrypoint.produccion.sh
ENTRYPOINT ["/entrypoint.produccion.sh"]
