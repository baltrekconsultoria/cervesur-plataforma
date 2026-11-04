#!/bin/bash
# MySQL y Apache locales de la réplica de ensayo de la fase 2A. Política de contraseñas IGUAL a la del servidor real (validate_password MEDIUM). Solo en la réplica.
service mysql start >/dev/null 2>&1
for _ in $(seq 1 60); do mysqladmin ping --silent >/dev/null 2>&1 && break; sleep 1; done
mysql -e "INSTALL COMPONENT 'file://component_validate_password'" >/dev/null 2>&1
mysql -e "SET GLOBAL validate_password.policy = MEDIUM; SET GLOBAL validate_password.length = 8; SET GLOBAL validate_password.mixed_case_count = 1; SET GLOBAL validate_password.number_count = 1; SET GLOBAL validate_password.special_char_count = 1; SET GLOBAL validate_password.check_user_name = ON" >/dev/null 2>&1
exec apache2ctl -D FOREGROUND
