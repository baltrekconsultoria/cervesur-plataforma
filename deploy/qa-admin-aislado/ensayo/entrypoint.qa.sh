#!/bin/bash
# MySQL y Apache locales de la réplica de ensayo del entorno administrativo de QA.
# Los señuelos de MySQL se cargan ANTES de activar la política de contraseñas; después se activa validate_password MEDIUM (igual que el servidor real).
# socat emula el túnel SSH (ssh -L): 0.0.0.0:18092 del contenedor -> 127.0.0.1:8090 (solo para que el ensayo llegue desde el anfitrión).
service mysql start >/dev/null 2>&1
for _ in $(seq 1 60); do mysqladmin ping --silent >/dev/null 2>&1 && break; sleep 1; done
mysql < /root/decoy-mysql.sql
mysql -e "INSTALL COMPONENT 'file://component_validate_password'" >/dev/null 2>&1
mysql -e "SET GLOBAL validate_password.policy = MEDIUM; SET GLOBAL validate_password.length = 8; SET GLOBAL validate_password.mixed_case_count = 1; SET GLOBAL validate_password.number_count = 1; SET GLOBAL validate_password.special_char_count = 1; SET GLOBAL validate_password.check_user_name = ON" >/dev/null 2>&1
socat TCP-LISTEN:18092,fork,reuseaddr TCP:127.0.0.1:8090 &
exec apache2ctl -D FOREGROUND
