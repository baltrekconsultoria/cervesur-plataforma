#!/bin/bash
# MySQL y Apache locales; socat emula el túnel SSH (ssh -L): 0.0.0.0:18089 del contenedor -> 127.0.0.1:8088, como haría sshd en el servidor.
service mysql start >/dev/null 2>&1
for _ in $(seq 1 60); do mysqladmin ping --silent >/dev/null 2>&1 && break; sleep 1; done
# Política de contraseñas IGUAL a la del servidor real (MySQL 8.0.46): validate_password en MEDIUM. Solo en la réplica de ensayo.
mysql -e "INSTALL COMPONENT 'file://component_validate_password'" >/dev/null 2>&1
mysql -e "SET GLOBAL validate_password.policy = MEDIUM; SET GLOBAL validate_password.length = 8; SET GLOBAL validate_password.mixed_case_count = 1; SET GLOBAL validate_password.number_count = 1; SET GLOBAL validate_password.special_char_count = 1; SET GLOBAL validate_password.check_user_name = ON" >/dev/null 2>&1
socat TCP-LISTEN:18089,fork,reuseaddr TCP:127.0.0.1:8088 &
exec apache2ctl -D FOREGROUND
