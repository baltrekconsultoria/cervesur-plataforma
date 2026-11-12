# Cervesur P2 – Plan B sin Docker: instancia MySQL temporal propia

## Motivo

El intento de restauración P2 con PR #27 se detuvo en preflight: DOCKER_NO_DISPONIBLE. No se importó SQL ni se alteró MySQL productivo. El respaldo P2 existente se mantiene íntegro y con restoration_tested=false.

El operador confirmó en el host Cervesur:
- /usr/sbin/mysqld disponible;
- cuenta de sistema mysql y runuser presentes;
- 6402 MiB de memoria disponible;
- 13 914 MiB libres en /;
- manifiesto del respaldo presente.

Esta evidencia permite preparar un candidato nativo. No confirma todavía que la versión de mysqld sea MySQL 8.0/8.4 ni que el segundo daemon arranque; esto se comprueba en el preflight y en el ensayo real.

## Enfoque

El script restore_native.py, junto con backup_u_basic.py y restore_verify.py desde el MISMO commit, restaura el respaldo bajo una instancia MySQL segunda, completamente separada:

- Ejecuta mysqld --no-defaults --initialize-insecure SOLO para un datadir temporal aleatorio bajo /tmp/cervesur-p2-qa-*.
- La carpeta se crea 0700 con dueño OS mysql; no usa /var/lib/mysql, /run/mysqld, /etc/mysql ni el socket MySQL productivo.
- Inicializa y arranca el segundo mysqld **directamente con UID/GID de la cuenta Linux mysql** (sin iniciar como root y cambiar de dueño después), usando --no-defaults, --skip-networking, --mysqlx=OFF, --skip-log-bin, un socket privado y parámetros de memoria acotados.
- Todas las invocaciones de mysql/mysqladmin usan --no-defaults, --protocol=socket y --socket=/tmp/cervesur-p2-qa-.../mysql.sock. Nunca se conectan al servidor productivo.
- Importa los dumps .sql.gz existentes por stdin: siete tablas de negocio/audit_log más las tablas del estado del administrador.
- Compara todas las filas y CHECKSUM TABLE contra el manifiesto previamente guardado, exige dos triggers audit y estado de admin restaurado.
- Apaga solamente el proceso hijo que creó y borra el datadir temporal. Si no puede detenerlo y limpiar, aborta sin recibo de éxito.
- Solo tras limpiar crea un recibo privado restore-native-receipt.json con huella SHA del manifiesto original; NO modifica ese manifiesto.
- No permite U-BASIC activo, ni sensible/P5, ni impresión de datos o secretos.

**Limitaciones:** se usa CPU, disco y RAM del servidor productivo para una segunda instancia temporal. No es una VM independiente. Por eso el ensayo requiere ventana supervisada; antes, el preflight exige al menos 3 GiB disponibles de RAM y 2 GiB libres de disco o triple tamaño SQL + reserva. No instala paquetes ni servicios.

## Respaldo existente (no crear otro)

Ruta privada de P2 ya verificado:

/var/backups/cervesur-react-admin/u-basic/u-basic-20261112T120054Z-o88zdhrl

No subir, copiar por chat o correo, ni abrir el SQL en la terminal. Contiene datos de usuarios, hashes de contraseñas y sesiones.

## Pasos PuTTY después de aprobar el PR y pasar CI

1. Descargar desde el commit SHA aprobado los tres scripts backup_u_basic.py, restore_verify.py, restore_native.py. Validar Git blob SHA de cada uno. Instalar solo en /opt/cervesur-u-basic-kit, bajo root 0700/0600, fuera de DocumentRoot.

2. Preflight (solo consulta versión y recursos, verifica backup, variables no secretas y ruta; crea solo lock en /run/lock):

    sudo python3 /opt/cervesur-u-basic-kit/restore_native.py preflight --path /var/backups/cervesur-react-admin/u-basic/u-basic-20261112T120054Z-o88zdhrl

   Resultado esperado: BACKUP_INTEGRIDAD=PASS, MY_SQL_VERSION=8.0.x u 8.4.x, MYSQL_NATIVO=DISPONIBLE, MEMORIA_DISCO=PASS, USUARIOS_ESCRITURA=OFF, SENSIBLES_ESCRITURA=OFF, P2_NATIVE_PREFLIGHT=PASS.

3. Ensayo SOLO con PASS, aprobación y ventana de baja carga:

    sudo python3 /opt/cervesur-u-basic-kit/restore_native.py run --path /var/backups/cervesur-react-admin/u-basic/u-basic-20261112T120054Z-o88zdhrl --confirm CERVESUR_AUTORIZO_P2_MYSQL_NATIVO_AISLADO

   Resultado esperado: MYSQL_NATIVO_AISLADO_INICIO=SI, MYSQL_AISLADO_ELIMINADO=SI, P2_RESTAURACION_NATIVA=PASS, TABLAS_VERIFICADAS=7, AUDIT_TRIGGERS>=2, ESTADO_ADMIN_TABLAS>=1, MYSQL_PRODUCTIVO_MODIFICADO=NO, U_BASIC_ACTIVADO=NO. El recibo se guarda privado en carpeta P2.

Ante cualquier NO-GO, detenerse y NO ejecutar apt-get, docker install, mysql service restart, ni operaciones sobre cervesur_portalbi. Si falla la limpieza del datadir/daemon, revisar manualmente el proceso exacto de QA; nunca usar killall mysqld.

## QA GitHub

- Unit tests prueban el guardia de flags, recursos, versión, construcción de comandos, socket independiente, rechazo de filas/triggers discordantes, limpieza obligatoria ante fallo y recibos privados.
- Integración independiente instala MySQL 8 en el runner desechable de GitHub, crea un mysqld temporal con siete tablas, audit triggers y estado de admin SINTÉTICOS, genera dos dumps ficticios y ensaya la restauración en una SEGUNDA instancia mysql nativa; no toca bases del cliente.

## Puertas que siguen pendientes después de P2

- Recuperación integral de GRANTS/DEFINER/usuarios MySQL, no cubierta por respaldo de tablas y estado.
- QA HTTP user/admin de F10, A1 y A2 con sesiones controladas; cierre D3/D4 y firmas A-L1/L2/L3/L4.
- Continuidad de registro Flutter y SQL SELECT del portal 443 (cuenta diferente).
- P4b U-BASIC sigue OFF. P5 sensible (roles y contraseñas) OFF. Activación con interruptor oficial, aprobación del responsable y operación P4c acotada, nunca ejecutada por este kit.
