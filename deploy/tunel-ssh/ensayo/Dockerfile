# Réplica de ENSAYO del servidor (Ubuntu 24.04 + Apache 2.4 + mod_php 8.3 + MySQL 8 locales) con un portal «Flutter» simulado en el puerto 80.
# SOLO para probar scripts/instalar.sh, verificar.sh y revertir.sh en local (deploy/tunel-ssh/ensayo-docker.mjs). No es el servidor real.
FROM ubuntu:24.04
ENV DEBIAN_FRONTEND=noninteractive
RUN apt-get update && apt-get install -y --no-install-recommends apache2 libapache2-mod-php8.3 php8.3-cli php8.3-mysql php8.3-mbstring mysql-server curl openssl socat iproute2 ca-certificates \
 && rm -rf /var/lib/apt/lists/*
# Estado declarado del servidor real: módulos alias, php y rewrite activos; headers NO habilitado.
RUN a2enmod rewrite alias >/dev/null && a2dismod headers >/dev/null 2>&1 || true
# Portal Flutter simulado: /var/www/cervesur/{frontend,backend,.htaccess} y /etc/apache2/sites-enabled/cervesur.conf (puerto 80, HTTP).
COPY flutter/ /var/www/cervesur/
COPY flutter-vhost.conf /etc/apache2/sites-available/cervesur.conf
RUN a2dissite 000-default >/dev/null && a2ensite cervesur >/dev/null && echo 'ServerName localhost' > /etc/apache2/conf-available/servername.conf && a2enconf servername >/dev/null \
 && chown -R root:root /var/www/cervesur
COPY entrypoint.sh /entrypoint.sh
RUN chmod +x /entrypoint.sh
ENTRYPOINT ["/entrypoint.sh"]
