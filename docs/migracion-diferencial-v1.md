# Cervesur — Migración diferencial V1: diagnóstico y simulación

**Estado:** candidato en PR borrador, NO desplegado. **Producción:** sin cambios.
Objetivo aprobado: sustituir Flutter/Firebase/hosting remoto por React + PHP + MySQL local, preservando los desarrollos y enlaces Power BI vigentes.

## Inventario probado (12/11/2026)

| Métrica | Legado remoto databalt_portalbi | React local cervesur_portalbi |
|---|---:|---:|
| Usuarios (activos) | 190 (176) | 76 (62) |
| Empresas (activas) | 25 (5) | 26 (6) |
| Reportes (activos) | 264 (169) | 95 (82) |
| Empresa–usuario (activos) | 198 (176) | 82 (79) |
| Empresa–reporte (activos) | 273 (270) | 103 (100) |
| Usuario–empresa–reporte (activos) | 334 (271) | 103 (93) |
| Cadenas completas vigentes por SQL | 226 | 91 |

Legado: entre 271 asignaciones activas, 5 apuntan primero a usuario inactivo y 40 a reporte inactivo; React: 2 apuntan a usuario inactivo. Son motivos exclusivos de primera causa, no una orden de reactivación.

**No inferir** que faltan 114 usuarios únicos ni 135 permisos que deban insertarse; no inferir que los 169 reportes activos antiguos son los 169 dashboards actualizados del checklist.

## Alcance del incremento

- ops/migracion/export_snapshot.php: ejecutable solo CLI, conexión PDO con sesión MySQL READ ONLY, exporta a un archivo privado solo IDs de filas y HMAC-SHA256 de email/username, RUC, ID y URL de reporte, roles, indicadores activos y relaciones. No incluye nombres, correos literales, teléfono, RUC literal, enlaces, passwords, JWT ni credenciales.
- ops/migracion/compare_snapshots.py: compara snapshots con la misma clave HMAC sin conectar a DB ni ejecutar DML. Emite solo conteos y señala colisiones y faltantes para revisión.
- tests/migracion/test_dryrun.py: pruebas sintéticas; jamás datos productivos.
- .github/workflows/migration-dryrun-ci.yml: validación PHP lint y Python unittest.

**Límites:** HMAC seudonimiza, NO anonimiza irreversiblemente; la clave y snapshots son sensibles. La coincidencia de email/RUC/reporte_id no prueba el mismo titular, empresa o reporte: se exige revisión humana. El snapshot no modifica BD, pero crea un archivo privado fuera de la carpeta web. El script todavía no se ha ejecutado contra datos reales. Este incremento NO incluye escritura/migración productiva.

## Procedimiento previsto (NO ejecutar aún sin preflight y autorización)

1. Confirmar acceso CLI/Terminal autorizado en cPanel y Ubuntu. Si cPanel carece de terminal, no publicar este PHP en public_html ni invocarlo por HTTP; preparar otra vía privada.
2. Preparar directorio privado fuera de DocumentRoot y una misma clave aleatoria secreta de 32 bytes o más en cada servidor, en archivos individuales con permisos 0600. Distribuirla por un canal seguro; no pegarla en GitHub, chat o URL.
3. Copiar script solo a carpeta privada. Verificar PHP lint y que el loader de DB corresponde al origen esperado. La conexión MySQL debe permitir SET SESSION TRANSACTION READ ONLY: no omitir esta puerta.
4. Ejemplo de comando para hosting legado, tras aprobar su ejecución:

    php export_snapshot.php --origin=legado --loader=/home/databalt/public_html/backend/lib/env.php --database=databalt_portalbi --key-file=/home/databalt/.cervesur-migration-hmac --out=/home/databalt/PRIVATE_EXPORTS/legado.json

5. Comando de ejemplo en Ubuntu nuevo, usando la misma clave:

    php export_snapshot.php --origin=react --loader=/var/www/cervesur/backend/lib/env.php --database=cervesur_portalbi --key-file=/home/usr_cervesur/.cervesur-migration-hmac --out=/home/usr_cervesur/PRIVATE_EXPORTS/react.json

6. Transferir ambos JSON mediante canal cifrado a equipo privado autorizado y ejecutar:

    python3 compare_snapshots.py --legado legado.json --react react.json --salida=resultado-agregado.json

7. Revisar faltantes, colisiones, links Power BI cambiados, relaciones y usuarios inactivos. El resultado contiene solo conteos. No subir snapshots, claves o archivos con IDs a GitHub, issues ni conversaciones.
8. Diseñar mapeo aprobado de identidades, esquema de activación segura sin contraseñas antiguas, asociaciones con IDs locales, y enlaces finales basados en el checklist actualizado de Power BI.
9. Construir un APLICADOR posterior, separado e idempotente, con respaldo verificado, ensayo de rollback y dry-run de escritura exclusivamente en copia aislada. Cualquier escritura productiva requiere nueva autorización.

## Puertas NO-GO para escritura

- Sin respaldo verificable de ambas fuentes, copia aislada y validación de restricciones/FK.
- Sin correspondencia revisada de cuentas por email/username, colisiones, cambios de correo y duplicados.
- Sin decidir las empresas/reportes realmente vigentes y los enlaces oficiales.
- Sin QA funcional de permisos, QA F10 con usuario del backend local, revisión de grants y seguridad 2B.
- Sin autorización explícita para cambios de BD, merge/deploy o desmantelar Flutter/Firebase.

## Continuidad

Auditorías y decisiones del proyecto: GitHub Issue #17. No registrar PII ni secretos. Este paquete es solo desarrollo y diagnóstico, NO corte productivo.

## cPanel sin SSH: ejecucion CLI mediante Cron Jobs (12/11/2026)

El puerto SSH 22 y el 21098 no respondieron desde la red operativa. cPanel si permite **Cron Jobs** y **Administrador de archivos**.

**Evidencia aportada del hosting legado:** en `/home/databalt/cervesur_cron_private/`, el script de diagnostico `cron_preflight.php` genero `cervesur-cron-preflight-result.txt` con `CERVESUR_CRON_PREFLIGHT=PASS`, PHP CLI **8.1.35**, PDO/MySQL, JSON y HMAC disponibles, carpeta privada escribible y archivo resultado con modo 0600. No se accedio a ninguna BD ni se alteraron datos.

**Limpieza inmediata:** eliminar el cron temporal de 5 minutos una vez obtenido PASS; preservar los archivos de evidencia fuera de `public_html`.

### Siguiente puerta: conectividad de la BD antigua (pendiente)

El script `ops/migracion/cron_db_preflight.php` esta preparado en GitHub, **no desplegado ni ejecutado**. Solo verifica configuracion DB, conexion PDO, sesion MySQL READ ONLY y presencia de seis tablas mediante `information_schema`. Genera un archivo privado `cervesur-db-preflight-result.txt`; no consulta filas de usuarios ni realiza DML.

1. **Primero confirmar la ruta del cargador** en el File Manager remoto: inspeccionar si existe `/home/databalt/public_html/backend/lib/env.php` (mostrar solo nombres de archivos, jamas abrir o compartir `.env`). La ruta es **hipotesis de despliegue**, no un hecho probado del hosting.
2. Si existe y corresponde al **legado**, descargar de la rama del PR `ops/migracion/cron_db_preflight.php` y subirlo a `/home/databalt/cervesur_cron_private/`; no subirlo a `public_html`.
3. Comprobar sintaxis y planificar cron de corta duracion, con una sola salida (el script evita sobrescribir resultados). Usar el PHP CLI ya validado:
   
   ```sh
   /usr/local/bin/php /home/databalt/cervesur_cron_private/cron_db_preflight.php --loader=/home/databalt/public_html/backend/lib/env.php >/dev/null 2>&1
   ```
   
   **NO programar hasta comprobar la ruta del loader**. No incluir contrasenas en cron, GitHub o chat.
4. Abrir solo `cervesur-db-preflight-result.txt` (no contiene secretos), revisar `CERVESUR_DB_PREFLIGHT` y los controles de READ ONLY; eliminar el cron inmediatamente despues.
5. Si la ruta no existe o la base configurada no es `databalt_portalbi`, parar. No intentar otro loader de otra aplicacion a ciegas. Investigar la configuracion correcta sin exponer secretos y/o usar exportaciones autorizadas de phpMyAdmin como plan alternativo.

**No-GO permanente:** ningun script de exportacion real ni aplicador de migracion se ejecuta antes de evaluar el resultado de esta prueba, confirmar esquema y obtener autorizacion para la fase correspondiente.

### Puerta siguiente: clave de conciliacion HMAC (preparada, NO ejecutada)

Tras `CERVESUR_DB_PREFLIGHT=PASS` (12/11/2026 a las 08:05:02 UTC; `DB_CONNECTED=PASS`, `DATABASE_MATCH=PASS`, `READ_ONLY_TRANSACTION=PASS`, `REQUIRED_TABLES_PRESENT=6/6`), se puede preparar el paso de identidad seudonimizada.

- GitHub: `ops/migracion/cron_hmac_bootstrap.php` genera **una sola vez** 32 bytes aleatorios (256 bits) y los guarda como 64 caracteres hex en `/home/databalt/cervesur_cron_private/.cervesur-migration-hmac`, modo 0600. **No** sube ni imprime la clave, y **no** conecta a MySQL.
- Al ejecutarse por Cron Jobs PHP CLI: el resultado permitido es `/home/databalt/cervesur_cron_private/cervesur-hmac-bootstrap-result.txt` (0600). Si la clave ya existe no la sobrescribe. Se debe **eliminar el cron** tras la evidencia, sin repetir el bootstrap.
- Nunca compartir la clave, el archivo oculto ni snapshots de usuarios en GitHub, issues o chats. Para mantener la misma huella HMAC, transferir el archivo secreto directamente por un canal cifrado autorizado a un directorio privado del servidor nuevo y comprobar 0600; **no generar una segunda clave independiente**.
- No ejecutar aun `export_snapshot.php` ni pasar a escritura. Primero obtener el PASS del bootstrap, comprobar sintaxis y ruta, y validar permisos y plan de descarga/transferencia de archivos sensibles.

### Evidencia recibida de bootstrap de clave (12/11/2026)

- `CERVESUR_HMAC_BOOTSTRAP=PASS` a las `2026-11-12T08:14:02Z`.
- `KEY_SIZE=256_BITS`, `KEY_PERMISSIONS=0600`, `SECRET_VALUE_PRINTED=NO`, `DATABASE_ACCESSED=NO`, `PRODUCTIVE_DATA_MODIFIED=NO`.
- La clave local privada `/home/databalt/cervesur_cron_private/.cervesur-migration-hmac` existe. **No copiar su contenido a GitHub/chat.**
- Se observaron **tres cron jobs activos**, incluido `cron_hmac_bootstrap.php` ejecutandose cada minuto. Eliminar los tres en cPanel antes de iniciar cualquier otra ejecucion para prevenir operaciones innecesarias.

### Export del origen legado (preparado, aun NO ejecutado)

Usar `ops/migracion/export_snapshot.php`, compatible con PHP 8.1 y de solo lectura, en la carpeta privada del hosting. Validar contenido descargado de la rama del PR y sintaxis PHP. Como mecanismo one-shot del cron (cada cinco minutos con eliminacion inmediata luego del resultado), la comprobacion de existencia del archivo de estado evita multiples ejecuciones:

```sh
umask 077; test ! -e /home/databalt/cervesur_cron_private/legado-export-status.txt && /usr/local/bin/php /home/databalt/cervesur_cron_private/export_snapshot.php --origin=legado --loader=/home/databalt/public_html/backend/lib/env.php --database=databalt_portalbi --key-file=/home/databalt/cervesur_cron_private/.cervesur-migration-hmac --out=/home/databalt/cervesur_cron_private/legado-snapshot.json > /home/databalt/cervesur_cron_private/legado-export-status.txt 2>&1
```

**Seguridad de archivos:** `legado-snapshot.json` contiene HMAC e IDs internos; constituye informacion sensible seudonimizada y **no se debe subir a conversaciones ni a GitHub**. El archivo `legado-export-status.txt` contiene solo conteos y codigos de estado y si se puede compartir para diagnostico. Ambos quedan fuera de `public_html`. No descargar/copiar la clave salvo a un dispositivo autorizado mediante el canal cifrado necesario para cotejar con el destino.

**NO-GO:** no migrar registros, no cambiar estados/roles/reportes, no exponer un endpoint HTTP, no ejecutar sobre el servidor React hasta completar la etapa de exportacion legado y revisar el resultado.

### Extraccion del legado confirmada y comprobada (12/11/2026)

- La ejecucion de `export_snapshot.php` devolvio `CERVESUR_SNAPSHOT=PASS`, `ORIGEN=legado`, `BASE_MODIFICADA=NO`, `SALIDA_PRIVADA=SI`.
- Conteos: 190 usuarios (176 activos), 25 empresas (5 activas), 264 reportes (169 activos), 198 empresa-usuario (176 activas), 273 empresa-reporte (270 activas), 334 usuario-empresa-reporte (271 activas).
- Se reviso el JSON privado en el entorno de conversacion: estructura `cervesur-snapshot-v1`, seis colecciones con identificadores presentes sin duplicados; 226 cadenas activas validas y unicas y 45 cadenas inactivas por causa principal (5 usuario inactivo, 40 reporte inactivo). No se registraron claves HMAC, hashes o IDs de usuarios en GitHub.
- **Incidente de manejo de datos a vigilar:** el operador adjunto el snapshot privado a la conversacion aunque la guia desaconsejaba hacerlo. Evitar nuevos adjuntos de snapshots, claves o identificadores; trabajar en transferencias directas por canal cifrado y comunicar solo conteos agregados.
- Los tres cron de preparacion y el cron de exportacion del origen han sido eliminados por el operador tras ejecutar las pruebas. La clave privada del legado debe conservarse con permisos 0600 en el hosting.

### Siguiente puerta Ubuntu (pendiente)

`ops/migracion/ubuntu_db_preflight.php` preparado en la misma rama del PR. Probar primero en el host Ubuntu (sin secret HMAC) que:
- PHP CLI y `pdo_mysql` funcionan.
- La ruta `/var/www/cervesur/backend/lib/env.php` existe y corresponde realmente a la nueva plataforma.
- La configuracion DB identifica `cervesur_portalbi` y estan presentes las seis tablas.
- Se puede iniciar transaccion `READ ONLY` sin consultar filas de datos.

**No copiar todavia la clave HMAC ni ejecutar el snapshot del destino hasta confirmar esta puerta**. Para el script usar una carpeta de trabajo privada fuera de `/var/www`, nunca dentro del DocumentRoot. El resultado del preflight se puede compartir porque solo contiene codigos de estado, nunca contrasenas.

### Ubuntu — comprobacion CLI local de preflight (12/11/2026, evidencias operador)

En sesion SSH/PuTTY **del servidor Ubuntu de Cervesur**, el operador ejecuto `whoami`, `pwd`, `php -v | head -n 2`, `php -m | grep -i '^pdo_mysql$'`, `test -f /var/www/cervesur/backend/lib/env.php` y `test -w "$HOME"`.

Resultados observados (sin incluir identificadores confidenciales):
- Usuario de shell: `usr_cervesur`
- HOME / directorio actual: `/home/usr_cervesur` y `PRIVATE_HOME=WRITABLE`
- PHP CLI **8.3.6** (19/oct/2026)
- Extensión `pdo_mysql` presente
- Loader `/var/www/cervesur/backend/lib/env.php`: **LOADER=FOUND**

**Alcance:** la existencia del archivo `env.php` no demuestra por si sola el nombre de base configurado ni capacidad de lectura en MySQL. Falta ejecutar `ops/migracion/ubuntu_db_preflight.php` con `--loader=/var/www/cervesur/backend/lib/env.php` y verificar `CERVESUR_UBUNTU_DB_PREFLIGHT=PASS`, `DATABASE_MATCH=PASS`, `READ_ONLY_TRANSACTION=PASS`, `REQUIRED_TABLES_PRESENT=6/6`.

**Procedimiento recomendado:** transferir el PHP aprobado desde la rama de GitHub a una carpeta privada `/home/usr_cervesur/cervesur_migracion_privada/` utilizando mecanismo autenticado (Git local con acceso verificado o SFTP/WinSCP). Ejecutar primero `php -l` y luego `php ubuntu_db_preflight.php --loader=/var/www/cervesur/backend/lib/env.php`. No cambiar contenido dentro de `/var/www/cervesur`, no copiar todavia la clave HMAC al Ubuntu ni ejecutar el exportador de destino. El codigo de preflight muestra solo estado agregado y no revela credenciales.

### Bloqueo detectado en Ubuntu: lectura de .env (12/11/2026)

Operador ejecuto en Ubuntu (usuario `usr_cervesur`):
- `php -l /home/usr_cervesur/cervesur_migracion_privada/repo/ops/migracion/ubuntu_db_preflight.php` -> **sin errores de sintaxis**.
- `php .../ubuntu_db_preflight.php --loader=/var/www/cervesur/backend/lib/env.php` -> **`CERVESUR_UBUNTU_DB_PREFLIGHT=DB_ENV_INCOMPLETE`**. Antes del estado, PHP emitio warnings `Permission denied` al intentar leer `/var/www/cervesur/backend/.env` desde `backend/lib/env.php` (lineas 11 y 12).
- La aplicacion en produccion no fue modificada; `DB_CONNECTED=NO`, `DATABASE_MATCH=NO`, `READ_ONLY_TRANSACTION=NO`, `REQUIRED_TABLES_PRESENT=UNKNOWN`, `DB_DATA_MODIFIED=NO`, `SECRET_VALUES_PRINTED=NO`.

**Interpretacion correcta:** `env.php` existe, pero la identidad de shell `usr_cervesur` no puede leer `backend/.env`. No hay evidencia de error de credenciales, indisponibilidad MySQL ni divergencia de la BD.

**Correccion en rama del PR:** `ops/migracion/ubuntu_db_preflight.php` ahora comprueba existencia y legibilidad de `backend/.env` antes de invocar el cargador. Si carece de permisos, devuelve **`CERVESUR_UBUNTU_DB_PREFLIGHT=ENV_PERMISSION_DENIED`**, sin warnings ni acceso a los valores secretos. Se incluyo test sintetico de permisos en CI (resultado CI pendiente de verificar).

**Siguiente diagnostico permitido (solo metadatos, NO abrir .env):**
```sh
id
namei -l /var/www/cervesur/backend/.env
stat -c '%U %G %a %n' /var/www/cervesur/backend /var/www/cervesur/backend/.env
test -r /var/www/cervesur/backend/.env && echo ENV_READABLE=YES || echo ENV_READABLE=NO
```

No copiar `.env`, no imprimir su contenido, no cambiar propietario/permisos, no entrar por root ni heredar credenciales sin autorizacion de Infra/DBA. Decidir con responsables la identidad de servicio bajo la que se puede ejecutar una prueba auditada, o solicitar cuenta MySQL de **solo lectura** gestionada por DBA. No ejecutar el exportador de destino ni transferir clave HMAC hasta resolver esta puerta sin debilitar permisos.

**Estado:** Ubuntu DB preflight **NO-GO** por permisos de archivo; legado export **PASS**; migracion productiva sigue no autorizada.

### Ubuntu — causa de denegacion de `.env` demostrada (12/11/2026)

Salida real del operador tras el preflight fallido:
```text
uid=1001(usr_cervesur) gid=1001(usr_cervesur) groups=1001(usr_cervesur),27(sudo)
f: /var/www/cervesur/backend/.env
drwxr-xr-x root     root     /
drwxr-xr-x root     root     var
drwxr-xr-x root     root     www
drwxr-xr-x www-data www-data cervesur
drwxr-xr-x www-data www-data backend
-rw-r----- www-data www-data .env
www-data www-data 755 /var/www/cervesur/backend
www-data www-data 640 /var/www/cervesur/backend/.env
ENV_READABLE=NO
```

**Diagnostico definitivo:** el archivo `backend/.env` es propiedad de `www-data:www-data` con modo `0640`, y el usuario SSH `usr_cervesur` no pertenece al grupo `www-data`. Por ello `php` ejecutado directamente por `usr_cervesur` no puede leerlo. La presencia del grupo `sudo` **no autoriza por si sola** elevar a root o cambiar de identidad para acceder a secretos o ejecutables productivos; requiere confirmar las facultades permitidas y el alcance de operacion.

**Prueba no invasiva siguiente** (sin lectura de secretos ni acceso a base):
```sh
sudo -n -l 2>&1 || true
namei -l /home/usr_cervesur/cervesur_migracion_privada/repo/ops/migracion/ubuntu_db_preflight.php
stat -c '%U %G %a %n' /home/usr_cervesur /home/usr_cervesur/cervesur_migracion_privada /home/usr_cervesur/cervesur_migracion_privada/repo
```

Esta comprobacion solo valida metadatos y sudoers; el comando `sudo -n -l` puede informar que se necesita autenticacion o que la autorizacion esta restringida. Nunca pegar una contraseña de sudo en el chat ni utilizar `sudo cat .env`. Determinar con responsable autorizado si se permite una ejecucion restringida del preflight por la identidad de servicio `www-data`, desde codigo verificado e instalado en ubicacion no publica, o una cuenta de MySQL solo lectura gestionada por DBA. **No ejecutar `sudo -u www-data` ni copiar `.env` hasta completar esta puerta.**

Estado: **Ubuntu preflight bloqueado por permisos**, no por fallo confirmado de MySQL; **NO-GO para snapshot de destino y cambios productivos**.

### Ubuntu — diagnostico de sudo y ruta privada (12/11/2026)

Prueba de operador, sin autenticacion sudo ni cambios de permisos:

```text
sudo -n -l
sudo: a password is required

namei -l /home/usr_cervesur/cervesur_migracion_privada/repo/ops/migracion/ubuntu_db_preflight.php:
  /home/usr_cervesur                     usr_cervesur:usr_cervesur 0750
  cervesur_migracion_privada              usr_cervesur:usr_cervesur 0700
  repo                                    usr_cervesur:usr_cervesur 0775
  ops                                     usr_cervesur:usr_cervesur 0775
  migracion                               usr_cervesur:usr_cervesur 0775
  ubuntu_db_preflight.php                 usr_cervesur:usr_cervesur 0664

stat:
  /home/usr_cervesur                      0750
  /home/usr_cervesur/cervesur_migracion_privada 0700
  /home/usr_cervesur/cervesur_migracion_privada/repo 0775
```

**Interpretacion:**
- `sudo -n -l` solo indica que se requiere autenticacion para listar reglas. **No prueba** autorizacion ni denegacion definitiva.
- El directorio 0700 impide a `www-data` atravesar hasta el PHP y el codigo 0664 dentro de rutas 0775 es modificable por usuario/grupo. No se debe ejecutar ese PHP con identidad `www-data` desde el checkout.
- El `backend/.env` del aplicativo sigue protegido `www-data:www-data 0640`, y debe conservarse igual.

**Siguiente gate propuesto, aun NO ejecutado:** `sudo -l` de manera interactiva, siempre local y sin compartir contrasena; registrar solo reglas que autorizan ejecutar como `www-data` o instalar una copia privilegiada. Si existe autorizacion efectiva de Infra/operador, copiar el preflight revisado a una ubicacion NO publica bajo propiedad `root:root`, permisos directorio 0755 y PHP 0644, con checksum y `php -l` antes de ejecutar como `www-data`; no elevar el propio script a root, no ejecutar la copia modificable en HOME. El servicio `www-data` solo debe recibir la lectura temporal necesaria; no leer ni imprimir `.env`.

Si `sudo -l` no autoriza el mecanismo acotado o el equipo no tiene autorizacion operacional, **NO-GO** a ejecutar como `www-data`: usar DBA para una cuenta SQL de lectura dedicada o pedir una ejecucion supervisada. No modificar permisos, propietarios, grupos, `.env`, cron productivo, flags ni acceso de usuarios. Documentar resultado antes de cualquier otra accion.

### Ubuntu — sudo autenticado, autorizaciones OS confirmadas (12/11/2026)

El operador ejecuto `sudo -l` autenticando localmente, sin compartir contrasena:

```text
Matching Defaults entries for usr_cervesur on presupuestos:
    env_reset, mail_badpass, secure_path=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/snap/bin,
    use_pty
User usr_cervesur may run the following commands on presupuestos:
    (ALL : ALL) ALL
```

**Interpretacion:** la configuracion sudoers permite comandos como cualquier usuario/grupo tras autenticar. Esto **no implica por si mismo** autorizacion de negocio/Infra para hacer cambios productivos; se limita el paso siguiente al preflight previamente acordado y de solo lectura.

**Procedimiento acotado, sin tocar `/var/www/cervesur`:**
1. Actualizar el checkout de la rama PR #23 mediante `git pull --ff-only`; verificar que `git status --porcelain` este limpio. No copiar codigo con modificaciones locales o codigo no revisado.
2. Instalar una copia con `sudo install` en `/usr/local/lib/cervesur-migration/ubuntu_db_preflight.php`, propiedad `root:root`, archivo `0644`, carpeta `0755`. Esta ruta esta fuera de DocumentRoot. Confirmar `sha256sum` contra el archivo del checkout despues de instalarlo y `php -l` en la copia protegida.
3. Ejecutar **solo** `sudo -u www-data -- /usr/bin/php /usr/local/lib/cervesur-migration/ubuntu_db_preflight.php --loader=/var/www/cervesur/backend/lib/env.php`, una vez, **sin sudo root PHP**, sin exponer contenido `.env` ni habilitar cambios de MySQL.
4. Revisar exclusivamente salida agregada. Si no resulta PASS, detener y diagnosticar causa. No instalar exportador, no mover la clave ni habilitar usuarios hasta preflight PASS y siguiente puerta aprobada.

**No-GO:** no usar `sudo cat .env`, no dar mas permisos a `.env`, no copiar secretos, no cambiar ACL, no correr scripts editables bajo `www-data`.

### Ubuntu — instalacion de preflight protegido: PASS (12/11/2026)

Evidencia ejecutada por operador:
- `git pull --ff-only origin feat/migracion-diferencial-dryrun-v1` avanzo por fast-forward desde `f13ae6a` hasta `530faed`; archivos de prueba, documentacion y CI sincronizados.
- Se copio exclusivamente `ops/migracion/ubuntu_db_preflight.php` con `sudo install -d -o root -g root -m 0755 /usr/local/lib/cervesur-migration` y `sudo install -o root -g root -m 0644 ...` a `/usr/local/lib/cervesur-migration/ubuntu_db_preflight.php` (fuera del DocumentRoot).
- `sudo cmp -s "$SRC" "$DST"` -> `COPIA=PASS`.
- `stat -c '%U %G %a %n' "$DST"` -> `root root 644 /usr/local/lib/cervesur-migration/ubuntu_db_preflight.php`.
- `php -l "$DST"` -> `No syntax errors detected`.

**Alcance:** copia protegida instalada y sintaxis validada. **No se dispone aun de resultado de ejecucion bajo `www-data`** y no debe marcarse conectividad MySQL PASS hasta recibir `CERVESUR_UBUNTU_DB_PREFLIGHT`.

Siguiente unico comando acordado para operador:
```sh
sudo -u www-data -- php /usr/local/lib/cervesur-migration/ubuntu_db_preflight.php --loader=/var/www/cervesur/backend/lib/env.php
```

No realizar DML, cambios de contrasenas, permisos de `.env`, fusion de PR ni importaciones. Si falla preflight, detener y diagnosticar el codigo de estado.

### Ubuntu — preflight de BD del destino: PASS (12/11/2026)

El operador ejecuto el preflight protegido `sudo -u www-data -- php /usr/local/lib/cervesur-migration/ubuntu_db_preflight.php --loader=/var/www/cervesur/backend/lib/env.php` con esta evidencia:

```text
CERVESUR_UBUNTU_DB_PREFLIGHT=PASS
PHP_CLI=PASS
EXPECTED_DATABASE=cervesur_portalbi
DB_CONNECTED=PASS
DATABASE_MATCH=PASS
READ_ONLY_TRANSACTION=PASS
REQUIRED_TABLES_PRESENT=6/6
DB_DATA_MODIFIED=NO
SECRET_VALUES_PRINTED=NO
```

**Puerta cerrada:** acceso del servidor Ubuntu `www-data` a `cervesur_portalbi` por la configuracion existente, 6/6 tablas y posibilidad de transaccion READ ONLY. El archivo `.env` mantiene los permisos 0640, sin alteraciones. El preflight no exporta identidades ni modifica datos.

**CI del commit previo de PR #23:** `Migracion diferencial CI #21` y `Backend CI #82` concluyeron `success`. Los cambios posteriores de documentacion no se consideran aprobados automaticamente hasta observar sus propios checks.

### Siguiente fase: transferencia directa de archivos privados (NO ejecutada)

Se necesita que el **mismo archivo de clave HMAC de 256 bits** creado en el cPanel legado llegue al Ubuntu; generar otra clave haria imposible la conciliacion. Se debe emplear transporte cifrado, por ejemplo descarga autenticada desde File Manager de cPanel a Windows y subida SFTP a Ubuntu con WinSCP usando las credenciales ya habilitadas en PuTTY. Nunca copiar/pegar valores de la clave, enviarla por correo/chat, incluirla en URL ni registrarla en GitHub. En cPanel activar visualizacion de archivos ocultos si el archivo `.cervesur-migration-hmac` no aparece.

**Puertas separadas para el operador:**
1. Preparar staging restringido del usuario `/home/usr_cervesur/cervesur_migracion_privada/entrada` con modo 0700; en Ubuntu, llave final `/var/lib/cervesur-migration/.cervesur-migration-hmac` en directorio `root:www-data` 0750, clave `www-data:www-data` 0600; destino de snapshots `/var/lib/cervesur-migration/snapshots` directorio `root:www-data` 0770, fuera de DocumentRoot.
2. Subir clave via SFTP **sin imprimir su valor**. Verificar que no sea symlink, propietario/modo 0600; copiar con `sudo install -o www-data -g www-data -m 0600`, comparar contenido con `sudo cmp -s` (solo muestra PASS/FAIL), comprobar lectura con `sudo -u www-data test -r`, y retirar staging solo una vez confirmada copia y acceso correcto. Revisar sensibilidad de `www-data`: acceso temporal con borrado seguro organizacional de la copia tras conciliacion.
3. Instalar una copia del exportador PHP `ops/migracion/export_snapshot.php` como `root:root` 0644 fuera del sitio en `/usr/local/lib/cervesur-migration/export_snapshot.php`; verificar `cmp`, `php -l` y que el checkout esta limpio/actualizado. El exportador solo realiza SELECT y establece `SET SESSION TRANSACTION READ ONLY` al abrir MySQL.
4. Ejecutar una sola vez bajo `www-data` con argumentos `--origin=react --loader=/var/www/cervesur/backend/lib/env.php --database=cervesur_portalbi --key-file=/var/lib/cervesur-migration/.cervesur-migration-hmac --out=/var/lib/cervesur-migration/snapshots/react.json`. No sobrescribir snapshots ya existentes; tratar archivo JSON como sensible y no exponerlo en conversaciones, GitHub ni directorio web. Compartir exclusivamente la salida agregada `CERVESUR_SNAPSHOT=PASS` y seis conteos.
5. Solo despues transferir el snapshot legado **directamente por SFTP** a ubicacion privada y ejecutar `compare_snapshots.py` en el propio Ubuntu, copia de codigo protegida, con ambos JSON y clave coincidente. Compartir unicamente `resultado-agregado.json` (solo conteos, sin hashes ni PII), no los snapshots.

**NO-GO:** migracion real de usuarios, roles, passwords, grants, cambios de estados o enlaces, merge y despliegue productivo sin el dry-run comparado, revision humana, respaldo/rollback y autorizacion especifica.

### Incidencia WinSCP: nombre del archivo HMAC en staging (12/11/2026)

El operador mostro transferencia SFTP a `/home/usr_cervesur/cervesur_migracion_privada/entrada/` de dos archivos: el snapshot legado y un fichero con nombre visible `cervesur-migration-hmac`, **sin punto inicial**. El primer bloque de instalacion busco `.cervesur-migration-hmac` y devolvio `STAGING=FAIL`, **sin instalar aun la clave en `/var/lib/cervesur-migration`**.

**Causa probable, por comprobar con salida de shell:** discordancia del nombre visible en WinSCP respecto de la ruta buscada. No interpretar como error de conexion, de bytes HMAC ni como problema de MySQL. No volver a generar la clave.

**Correccion propuesta (sin leer ni imprimir el secreto):**

```sh
DIR=/home/usr_cervesur/cervesur_migracion_privada/entrada
SRC="$DIR/cervesur-migration-hmac"
STAGE="$DIR/.cervesur-migration-hmac"
if [ -f "$SRC" ] && [ ! -L "$SRC" ] && [ ! -e "$STAGE" ]; then
    mv -- "$SRC" "$STAGE"
fi
if [ -f "$STAGE" ] && [ ! -L "$STAGE" ]; then
    chmod 600 "$STAGE"
    echo "STAGING=PASS"
    echo "KEY_BYTES=$(wc -c < "$STAGE")"
else
    echo "STAGING=FAIL"
fi
```

**Puerta antes de copiar a destino privilegiado:** `STAGING=PASS` y `KEY_BYTES=65` (64 caracteres hex mas salto de linea). Verificar *sin mostrar clave* que no existe ya `/var/lib/cervesur-migration/.cervesur-migration-hmac` antes de instalar. Solo despues copiar bajo `www-data:www-data`, 0600, comparar via `sudo cmp -s` y confirmar lectura `sudo -u www-data test -r`. Nunca adjuntar el archivo secreto o el snapshot a chats ni GitHub.

Estado: paso de staging **aun no validado tras correccion**. Base nueva no modificada y exportacion destino pendiente.

### Puerta HMAC del destino cerrada: staging corregido e instalado (12/11/2026)

Tras la incidencia de nomenclatura de WinSCP:
- Primer intento de buscar `.cervesur-migration-hmac`: `STAGING=FAIL` porque el archivo se transfirio sin punto inicial.
- Correccion `mv -- cervesur-migration-hmac .cervesur-migration-hmac` en la carpeta de entrada restringida `/home/usr_cervesur/cervesur_migracion_privada/entrada` -> `STAGING=PASS`.
- `KEY_BYTES=65` (64 caracteres hex y salto de linea), `chmod 600` aplicado.
- Instalacion al destino `/var/lib/cervesur-migration/.cervesur-migration-hmac`: `KEY_MATCH=PASS` con `sudo cmp -s`, `KEY_ACCESS=PASS` para el usuario `www-data`, y `KEY_OWNER=www-data:www-data KEY_MODE=600`.
- Operador ejecuto `chmod 600 /home/usr_cervesur/cervesur_migracion_privada/entrada/legado-snapshot.json`. No se solicito ni mostro contenido del secreto ni del snapshot.
- La clave tiene la longitud y acceso esperados; el cotejo criptografico de ambas fuentes sigue pendiente de verificar el `key_id` en la comparacion de snapshots. **No asumir aun equivalencia de identidades o permisos.**

**Siguiente fase autorizada operativamente por continuidad del diagnostico:** instalar copia de `ops/migracion/export_snapshot.php` desde la rama PR #23 bajo `/usr/local/lib/cervesur-migration/export_snapshot.php`, propiedad `root:root` 0644, verificar `cmp` y `php -l` y comprobar `/var/lib/cervesur-migration/snapshots` accesible para `www-data` 0770. Ejecutar exclusivamente `--origin=react --database=cervesur_portalbi` desde `www-data`, en lectura y con salida JSON 0600 **fuera de DocumentRoot**. Nunca subir/copiar snapshots ni claves al chat o GitHub. No ejecutar migracion real ni tocar permisos del sitio.

**Puerta de parada:** si `KEY_MATCH`, `KEY_ACCESS`, comprobacion del exportador, directorio de snapshots o `CERVESUR_SNAPSHOT` dejan de ser PASS, detenerse y revisar; nunca sobreescribir `react.json` ni regenerar clave para subsanar el error.

### Ubuntu — snapshot de destino completado: PASS (12/11/2026)

El operador instaló `export_snapshot.php` desde el checkout de PR #23 en `/usr/local/lib/cervesur-migration/export_snapshot.php` como `root:root` 0644. Controles observados:
- `COPIA=PASS`, `No syntax errors detected`, `SNAPSHOT_DIR=PASS` con `www-data`.
- Ejecutó como `www-data` el exportador privado `--origin=react --database=cervesur_portalbi --key-file=/var/lib/cervesur-migration/.cervesur-migration-hmac --out=/var/lib/cervesur-migration/snapshots/react.json`.
- Salida agregada: `CERVESUR_SNAPSHOT=PASS`; 76 usuarios, 26 empresas, 95 reportes, 82 empresa-usuario, 103 empresa-reporte, 103 usuario-empresa-reporte. `BASE_MODIFICADA=NO`, `SALIDA_PRIVADA=SI`.
- **Ambos snapshots están generados**, pero aún **no** se realizó el cotejo entre sus `key_id` y las relaciones semánticas. No interpretar diferencias de conteo como órdenes de inserción.

### Siguiente puerta: conciliación agregada en Ubuntu (pendiente)

El script existente `ops/migracion/compare_snapshots.py` está diseñado para abrir ambos JSON privados, exigir `schema`/`origin` válidos, comprobar `key_id` igual y reportar solo recuentos, colisiones, relaciones de acceso y diferencias de enlace; **no accede a MySQL ni realiza DML**.

Para no ampliar la exposición de los archivos:
1. Actualizar checkout de la rama PR #23 con `git pull --ff-only`, comprobar estado limpio.
2. Copiar solo `compare_snapshots.py` a `/usr/local/lib/cervesur-migration/compare_snapshots.py` como `root:root` 0644, comprobar `sudo cmp -s`, validar sintaxis Python sin escribir bytecode.
3. Mantener `react.json` 0600 legible para `www-data`. Instalar **una copia** de `/home/usr_cervesur/cervesur_migracion_privada/entrada/legado-snapshot.json` como `/var/lib/cervesur-migration/snapshots/legado.json` 0600 `www-data:www-data`, si el destino no existe, sin sobreescribir y sin mostrar contenido. El directorio ya es privado, fuera de DocumentRoot.
4. Ejecutar como `www-data` una sola vez:
```sh
sudo -u www-data -- python3 /usr/local/lib/cervesur-migration/compare_snapshots.py --legado /var/lib/cervesur-migration/snapshots/legado.json --react /var/lib/cervesur-migration/snapshots/react.json --salida /var/lib/cervesur-migration/snapshots/resultado-agregado.json
```
5. Compartir **únicamente la salida JSON agregada** de la ejecución, que no incluye nombres, correos, IDs, HMAC ni contraseñas. Si aparece `CERVESUR_DRYRUN=ERROR_CLAVES_HMAC_DISTINTAS` u otro error, detener sin repetir la extracción ni regenerar claves.

**NO-GO:** ninguna inserción, activación de roles, cambio de enlaces, importación o migración productiva hasta analizar los resultados, aprobar un mapeo humano y pasar QA, respaldo y gates previos.

### Comparador privado Ubuntu — preparacion validada e idempotencia (12/11/2026)

Evidencia reportada por el operador:
- En **primera ejecucion** de la copia `legado-snapshot.json` -> `/var/lib/cervesur-migration/snapshots/legado.json`: `LEGADO_COPY=PASS`, `LEGADO_ACCESS=PASS`, `REACT_ACCESS=PASS`.
- `git pull --ff-only` de rama `feat/migracion-diferencial-dryrun-v1` -> `Already up to date`; `git status --short` sin cambios mostrados.
- Comparador `compare_snapshots.py` instalado fuera de DocumentRoot en `/usr/local/lib/cervesur-migration/compare_snapshots.py`, propiedad `root:root` 0644: `COMPARE_CODE=PASS`, `PYTHON_SYNTAX=PASS`.
- En **segunda ejecucion** del bloque de copia: `LEGADO_COPY=STOP`; ambos accesos siguieron en `PASS`. Es el bloqueo previsto para **no sobrescribir** el destino ya existente, no una falla de la primera copia.

**Siguiente gate:** confirmar `sudo cmp -s` entre el snapshot del staging y `/var/lib/cervesur-migration/snapshots/legado.json` sin mostrar contenido, verificar que `resultado-agregado.json` no exista, y solo entonces ejecutar bajo `www-data` `compare_snapshots.py` con ambos JSON en el directorio privado y `--salida` nueva. La salida puede compartirse si contiene exclusivamente los agregados previstos. No solicitar, imprimir, copiar a GitHub ni adjuntar HMAC/snapshots.

**Estado:** preparado para el dry-run semantico; aun **no existe salida de comparacion validada**. Prohibidos DML, activacion de usuarios o despliegues.

### Dry-run semantico entre legado y React: EJECUTADO (12/11/2026)

Operador ejecuto `compare_snapshots.py` en Ubuntu como `www-data` sobre ambos snapshots privados; `COMPARACION_PREFLIGHT=PASS`. Resultado `version=cervesur-dryrun-v1`, `modo=SOLO_DIAGNOSTICO`, `privacidad=NO_PII_NO_HASHES_NO_TOKENS_EN_REPORTE`. Comparacion `key_id` compatible, sin colisiones reportadas. **No hubo escrituras en MySQL** y `puede_aplicar_migracion=false`.

**Resumen real** (solo agregados):
- Usuarios origen 176 activos; destino 62 activos; **76 identidades coincidentes por email** (sobre totales de 190 origen/76 destino), **114 usuarios activos del origen sin correo coincidente** en destino; 0 conflictos `username` igual y correo diferente.
- Empresas: 25/25 del origen coinciden por RUC con el destino (26 total, 1 adicional).
- Reportes: 95 equivalencias por `reporte_id` entre 264 origen y 95 destino; **169 referencias del origen sin equivalente**; **80 de los 95 reportes coincidentes con enlace distinto**. La fuente de enlaces finales es el checklist actualizado de Power BI, no el legado ni el destino por defecto.
- Asignaciones: **226** cadenas efectivas unicas origen, **91** destino, **0** coincidencias; **226** faltantes destino; `pendientes_con_tres_entidades_existentes_y_activas=0` y `pendientes_bloqueados_por_mapeo_o_estado=226`. Origen tiene 45 asignaciones activas invalidadas por `CADENA_INACTIVA`; destino 2 por misma razon. 0 duplicados semanticos en ambos.
- Puertas: `colisiones_de_claves_o_ids=0` (favorable pero no autorizacion); revisiones humanas y QA de rol usuario local/403 siguen pendientes; `fase_2b_habilitada=false`, `migracion_productiva_autorizada=false`.

**Lectura prudente:** los 114 usuarios activos y los 169 reportes sin match NO son inserciones a ejecutar; primero segmentar por actividad cruzada y dependencias. Ninguna de las 226 cadenas puede replicarse inmediatamente con entidades activas de destino. Diferencias de enlaces exigen reconciliar contra checklist Power BI. El resultado es **NO-GO para aplicacion automatica**.

**Siguiente analisis controlado:** generar segunda matriz solo de conteos, distinguiendo actividad cruzada de usuarios/empresas/reportes y razones mutuamente excluyentes que bloquean las 226 cadenas. Ejecutar localmente sobre JSON privados ya presentes; sin exponer hashes, correos, links o IDs, sin acceso SQL ni mover los snapshots.

### Segunda diagnostica agregada de brechas v1 (preparada, pendiente de ejecucion)

Nuevo `ops/migracion/diagnostico_brechas.py` en rama del PR, sin reemplazar `compare_snapshots.py`. Lee **solamente** los dos JSON privados ya generados, revalida `schema`, `origin`, `key_id`, IDs y colisiones antes de clasificar. No abre conexiones SQL ni escribe datos. Imprime exclusivamente conteos agregados.

Objetivos:
- Matriz de actividad cruzada para usuarios por correo HMAC, empresas por RUC HMAC y reportes por referencia HMAC: activo/inactivo/ausente; metadatos sin identificadores.
- Enlaces distintos por estado de actividad de los reportes coincidentes; fuente final de enlaces sigue siendo checklist Power BI actualizado.
- Explicar las **226** cadenas de acceso pendientes con **una causa principal exclusiva** por cadena (`USUARIO_AUSENTE`, `USUARIO_INACTIVO`, `EMPRESA_AUSENTE`, `EMPRESA_INACTIVA`, `REPORTE_AUSENTE`, `REPORTE_INACTIVO`, `TRIPLE_ACTIVO_SIN_CADENA_DESTINO`). La prioridad de estas causas es declarada en la salida. Agrega por separado contadores independientes que pueden solaparse; su suma no tiene que igualar 226.
- El proceso devuelve `CERVESUR_BRECHAS=PASS` solo si completó la validación. No genera nuevas claves, archivos de resultados ni expone HMAC/IDs.

Pruebas sintéticas agregadas en `tests/migracion/test_brechas.py` y compilación en `.github/workflows/migration-dryrun-ci.yml`; **CI del nuevo commit debe verificarse antes de usarlo**.

Instalacion controlada en Ubuntu (no ejecutar si CI falla):

```sh
REPO=/home/usr_cervesur/cervesur_migracion_privada/repo
git -C "$REPO" status --short
git -C "$REPO" pull --ff-only origin feat/migracion-diferencial-dryrun-v1
git -C "$REPO" status --short
SRC="$REPO/ops/migracion/diagnostico_brechas.py"
DST=/usr/local/lib/cervesur-migration/diagnostico_brechas.py
sudo install -o root -g root -m 0644 "$SRC" "$DST"
sudo cmp -s "$SRC" "$DST" && echo BRECHAS_CODE=PASS || echo BRECHAS_CODE=FAIL
python3 -c 'import ast,sys; ast.parse(open(sys.argv[1],encoding="utf-8").read()); print("BRECHAS_SYNTAX=PASS")' "$DST"
sudo -u www-data -- test -r /var/lib/cervesur-migration/snapshots/legado.json && echo LEGADO_ACCESS=PASS
sudo -u www-data -- test -r /var/lib/cervesur-migration/snapshots/react.json && echo REACT_ACCESS=PASS
```

Solo si todos estos controles dan PASS, ejecutar:

```sh
sudo -u www-data -- env PYTHONDONTWRITEBYTECODE=1 python3 /usr/local/lib/cervesur-migration/diagnostico_brechas.py --legado /var/lib/cervesur-migration/snapshots/legado.json --react /var/lib/cervesur-migration/snapshots/react.json
```

Compartir **solo salida agregada**. `CERVESUR_BRECHAS=ERROR_...` significa STOP: no modificar snapshots, no regenerar claves ni intentar escribir a la BD. El diagnostico amplía el NO-GO de migracion productiva, no lo levanta.

## Decision funcional: restaurar base original legado, sin reemplazar mejoras nuevas (12/11/2026)

**Aclaracion del responsable del proyecto:** el sistema antiguo `databalt_portalbi` contiene **la informacion base original** de Cervesur. El objetivo NO es limitarse a comparar snapshots anonimizados ni reconstruir a mano la informacion faltante: **se requiere trasladar al destino lo que aun falta de usuarios, empresas, reportes y asignaciones, respetando los registros originales**.

### Autoridad por campo y estrategia (propuesta operativa para aprobacion)

1. **Identidades / perfiles:** el legado es autoridad para recuperar registros originalmente existentes y sus metadatos funcionales, estados y asociaciones historicas. Dedupe por correo normalizado verificado (ademas de username y conflictos); crear solo faltantes, no sobreescribir cuentas ya registradas en React sin decision puntual. Mantener cuentas inactivas como inactivas. No copiar contrasenas/hashes automaticamente: primero comprobar compatibilidad de autenticacion Flutter/React y mecanismo seguro de inicializacion/restablecimiento.
2. **Empresas:** el legado es fuente base para las 25 empresas; las 25 coinciden por RUC. Revisar y conservar la empresa adicional del nuevo destino (26 total), sin borrarla ni crear duplicados.
3. **Reportes:** recuperar los 169 registros del origen sin `reporte_id` equivalente, conservando titulo, referencia, empresa(s) y estado; no confundir registros originales antiguos/inactivos con dashboards actuales. Si el enlace Power BI fue actualizado, la **fuente de verdad del enlace final** es el checklist actualizado validado por el operador y el servicio Power BI, no una URL antigua ni la del destino por defecto. Los 80 enlaces diferentes de reportes coincidentes van a revision de equivalencia; no reemplazo masivo ciego.
4. **Vinculos:** recuperar las relaciones originales `empresa_usuario`, `empresa_reporte` y `user_empresa_reporte` por **mapeo de claves origen-destino**, nunca reutilizando IDs numericos sin correspondencia. Preservar estado activo/inactivo, no reactivar relaciones historicas invalidas. Aplicar en orden de dependencias: usuarios/empresas/reportes -> empresa_usuario y empresa_reporte -> user_empresa_reporte. Las 226 cadenas efectivas antiguas faltantes no pueden insertarse hasta restituir entidades y revisar estados; no eliminar ni ignorar 45 cadenas de origen marcadas activas pero inefectivas, preservarlas como incidencias historicas para revision.
5. **Destino y mejoras:** mantener registros del destino que no provienen del legado y configuraciones, seguridad, auditoria, roles, claves, permisos y endpoints que ya funcionan. No sobrescribir ni resetear indiscriminadamente. Mismo criterio para sus 91 cadenas efectivas actuales, aun cuando sean distintas de las antiguas.

### Diferenciar la prueba que ya paso de la migracion real

- **Hecho validado:** los snapshots `cervesur-snapshot-v1` solo guardan IDs locales, estados, roles y HMAC de campos; permiten **cotejar**, pero NO contienen datos en claro necesarios para migrar metadatos, nombres, email, titulo, enlaces, etc. **No son archivos importables** y no deben usarse para inventar registros.
- **Pendiente construir:** extractores completos privados, con minimizacion por tabla y transportes cifrados, y un importador `dry-run` que produzca plan JSON/SQL seguro *sin ejecutar DML*, con tablas de correspondencia (legacy ID -> React ID), conflictos, checks de unicidad, reversibilidad y procedimientos de backup.
- La transferencia de datos personales debe hacerse **directamente entre entornos autorizados**, no en GitHub, chats, capturas, issues ni logs; credenciales y claves se mantienen fuera del proceso. El cliente debe validar finalidad, acceso y retencion antes del traslado de PII.
- **Secuencia:** (A) cerrar explicacion agregada del cero de coincidencias; (B) auditar esquema real, columnas, FK, claves unicas, y campos de autenticacion mediante consultas de metadatos; (C) diseño de exportacion completa privada y matriz de conflictos; (D) dry-run del plan de insercion/actualizacion por etapas con conteos previstos y cero DML; (E) backup y restauracion ensayada, QA, autorizacion explicita del lote; (F) migracion transaccional con auditoria y rollback; (G) verificacion login/roles/permisos Power BI y catalogo actualizado por lote.
- Cualquier conflicto (estado inactivo original, cuenta existente con datos modificados, enlaces Power BI, rol distinto, username duplicado) queda en revision humana; **nunca sobreescribir automaticamente**.

### Resumen de magnitudes para planeacion (no orden de escritura)

`190` usuarios originales frente a `76` destino (`76` coincidencias de email y `114` activos origen sin email equivalente); `25` empresas origen coinciden por RUC con `25` de las `26` destino; `264` reportes origen frente a `95` destino, `169` referencias sin equivalente; `226` cadenas efectivas legado frente a `91` destino, `0` coincidencias efectivas. Se deben revisar tambien las filas y estados historicos no efectivos.

**Estado:** migracion real aun NO ejecutada; el DRY-RUN actual solo descubre diferencias. **No introducir nuevas escrituras sin plan detallado, QA, backup y aprobacion especifica.**

## Cambio de alcance (12/11/2026): SOLO RECUPERAR USUARIOS

Decision del responsable tras validar dry-run: **reducir el entregable a usuarios**. La plataforma antigua sigue siendo referencia de informacion base de usuarios. Empresas (25 origen, 5 activas; destino 26, 6 activas), reportes y relaciones de acceso se gestionaran **manualmente en la plataforma nueva**: no exportarlos/importarlos/aplicarlos desde este incremento. No cambiar sus estados, relaciones, enlaces Power BI ni roles mientras se recuperan los usuarios. La clasificacion de las 226 cadenas bloqueadas queda como evidencia historica, no como requisito de este lote.

Objetivo users-only: conservar los **76 registros existentes** del destino, analizar los **114 usuarios activos origen sin email coincidente** y proponer altas aditivas **sin duplicados**. Fuente base: 190 usuarios antiguos (176 activos), 76 nuevos (62 activos), coincidencias por email 76. El numero 114 es una cota del cotejo HMAC, NO un lote automaticamente aprobado.

### Implementacion preparada en PR #23 (NO ejecutada en bases reales)

- `ops/migracion/export_usuarios_privado.php`: CLI PHP 8.1+, `SELECT` con sesion READ ONLY sobre **solo** `user`, whitelist `id,first_name,last_name,username,email,phone,role,is_active`, sin `password`, `session_version`, tokens, avatar ni cookies. Acepta `--origin=legado|react`, `--database` debe corresponder exactamente a `databalt_portalbi|cervesur_portalbi`, `--loader` existente y `--out` exclusivo `usuarios-legado.json|usuarios-react.json` en directorio privado. Archivos contienen PII, 0600, prohibido enviarlos al chat/GitHub o exponerlos en HTTP; consola solo totales de usuarios, activos y PASS.
- `ops/migracion/plan_usuarios.py`: lee ambos JSON en el **mismo servidor**, exige archivos 0600 y esquema/origen consistentes, bloquea emails y usernames duplicados y calcula plan fuera de BD sin DML. Respeta cuentas existentes por email; si hay username ocupado, datos obligatorios ausentes, email invalido, rol desconocido o rol `admin` propuesto, manda registro a revision humana. `usuario` alias legado pasa a `user`. Usuarios normales nuevos solo se proponen **`activo_propuesto=0`**; requieren inicializacion segura de credenciales y aprobacion antes de activacion.
- `tests/migracion/test_plan_usuarios.py`: QA sintetico de dedupe, no sobrescritura, archivo privado 0600, roles y no activaciones. CI agrega PHP lint y Python tests.
- `plan-usuarios.json` seria privado, con PII de las creaciones propuestas y revisiones, solo en directorio fuera de DocumentRoot; la consola imprimira **solo resumen**. No ejecutar DML, merge ni deploy en este incremento.

### Proxima ejecucion controlada (aun pendiente)

1. Esperar `Migracion diferencial CI` exitoso del commit que incluya estos scripts.
2. En hosting cPanel sin SSH, preparar exportador PHP en `/home/databalt/cervesur_cron_private` y ejecutar una sola vez mediante Cron Jobs autenticado, como el anterior exportador. Archivo de salida privada bajo `/home/databalt/cervesur_cron_private/usuarios-legado.json`; eliminar la tarea cron tras la primera ejecucion y comprobar que no exista bajo `public_html`. El `--loader` debe ser el mismo previamente validado, nunca publicar credenciales.
3. En Ubuntu, instalar copia `root:root` 0644 fuera de DocumentRoot y ejecutar `--origin=react` como `www-data` sobre `cervesur_portalbi` con salida `/var/lib/cervesur-migration/snapshots/usuarios-react.json`, 0600.
4. Transferir el JSON original por SFTP directamente a Ubuntu, **sin chat/correo/GitHub**; instalar copia restringida bajo `www-data` y ejecutar `plan_usuarios.py` sin conectarse a MySQL. Compartir solo salida agregada, nunca nombres/correos ni archivos privados.
5. Tras plan agregado, revisar bloqueos, requerimientos de restablecimiento de credenciales y compatibilidad del esquema. Preparar por separado importador transaccional aditivo con backup/rollback, QA y autorizacion explicita antes de una sola insercion. Preservar las 76 cuentas actuales y las entidades no incluidas en el alcance.

**Gate de alcance:** no se ejecutara la migracion de empresas, reportes, asignaciones ni la antigua propuesta de reproducir 226 cadenas automaticamente. El estado final del sistema solo se considera valido tras QA de inicio de sesion/roles y aprobacion del usuario.

### Users-only — exportacion de destino React: PASS (12/11/2026)

Evidencia de PuTTY aportada por el operador:
- Actualizacion `git pull --ff-only origin feat/migracion-diferencial-dryrun-v1`, fast-forward `f1f3d82..a200c4e`.
- `export_usuarios_privado.php` instalado como copia `root:root` 0644 en `/usr/local/lib/cervesur-migration/export_usuarios_privado.php`; `USUARIOS_CODE=PASS` y `php -l` sin errores.
- Ejecutado con `sudo -u www-data -- php ... --origin=react --loader=/var/www/cervesur/backend/lib/env.php --database=cervesur_portalbi --out=/var/lib/cervesur-migration/snapshots/usuarios-react.json`.
- Resultado: `CERVESUR_USUARIOS_EXPORT=PASS`, `ORIGEN=react`, `USUARIOS=76`, `ACTIVOS=62`, `CONTRASENAS_EXPORTADAS=NO`, `BASE_MODIFICADA=NO`, `SALIDA_PRIVADA=SI`.

La exportacion privada del destino esta completada, pero **no se ha construido ni aplicado ningun plan de altas**, ni se ha exportado aun el JSON privado users-only del hosting antiguo.

**Siguiente puerta: hosting cPanel legado, usuarios exclusivamente.** Usar la misma ubicacion no publica `/home/databalt/cervesur_cron_private/` y misma ruta de PHP CLI/loader verificados en las pruebas previas, verificando que la ruta `/home/databalt/public_html/backend/lib/env.php` existe; esta ruta del loader se usa solo para consultar configuracion de BD, no para guardar ni servir resultados. Subir exclusivamente el archivo revisado `ops/migracion/export_usuarios_privado.php` (rama PR #23). Verificar que este fuera de `public_html`; exportacion bajo el mismo directorio no publico `usuarios-legado.json`. Ejecutarlo con Cron Jobs de manera **puntual**, solo si no existe archivo de salida, capturando salida agregada en log privado, y eliminar cron tras observar PASS. Campos incluidos: nombre, apellido, usuario, email, telefono, rol, estado, ID local; no password ni tokens.

No volver a descargar los snapshots HMAC anteriores (no contienen nombres). **El nuevo `usuarios-legado.json` contiene PII real; no enviarlo al chat/email/GitHub ni almacenarlo en un directorio publico.** Descarga local cifrada por File Manager autenticado y subida SFTP directamente a directorio privado de Ubuntu despues de PASS. Solo despues ejecutar `plan_usuarios.py` (sin SQL), previa comprobacion de CI y permisos 0600. No ejecutar INSERT/UPDATE/DELETE ni habilitar usuarios todavia.

### Incidencia cPanel Cron export users-only: salida vacia (12/11/2026)

Capturas de operador muestran: tarea Cron `* * * * *` configurada en cPanel con `umask 077; test -f .../usuarios-legado.json || php -q .../export_usuarios_privado.php ... > .../usuarios-legado-resultado.txt 2>&1`. Administrador de archivos privado `/home/databalt/cervesur_cron_private/` contiene `export_usuarios_privado.php` (0644) y `usuarios-legado-resultado.txt` de **0 bytes** (0600). **No se ve** `usuarios-legado.json` en la captura. **NO consta `CERVESUR_USUARIOS_EXPORT=PASS` ni se ha transferido PII**.

**Interpretacion:** el Cron esta configurado y ha generado/abierto un archivo de salida, pero 0 bytes NO permite establecer causa ni concluir que el script haya completado. Una hipotesis tecnica es uso de SAPI no-CLI: el exportador sale silenciosamente si `PHP_SAPI !== 'cli'`; tambien podria existir una ejecucion interrumpida o aun en curso. No modificar `.env` ni bajar la proteccion CLI.

**Puerta inmediata:** eliminar cron de exportacion repetitivo; actualizar lista de archivos para confirmar presencia/ausencia de `usuarios-legado.json`. Si no existe, realizar una sola prueba cron **sin consultas SQL ni lectura de PII** para identificar PHP SAPI/version y codigo de salida, en archivo privado. Comando de probe idempotente:

```sh
umask 077; test -e /home/databalt/cervesur_cron_private/cron-php-probe.txt || { php -r 'echo "PHP_SAPI=",PHP_SAPI,PHP_EOL,"PHP_VERSION=",PHP_VERSION,PHP_EOL;' > /home/databalt/cervesur_cron_private/cron-php-probe.txt 2>&1; echo "PHP_EXIT=$?" >> /home/databalt/cervesur_cron_private/cron-php-probe.txt; }
```

Eliminar el cron de prueba tras generar `cron-php-probe.txt`. Compartir **solo ese archivo de diagnostico** (SAPI, version y estado) y presencia de JSON; **nunca** `usuarios-legado.json`. Si SAPI no es CLI, usar la ruta **absoluta verificada** del PHP CLI disponible en cPanel, no forzar ejecucion HTTP ni cambiar codigo para aceptar CGI.

### Cron cPanel — causa confirmada por probe y ruta CLI documentada (12/11/2026)

El operador remitió `cron-php-probe.txt` del hosting; contenido pertinente:

```text
Error in argument 1, char 2: option not found r
Usage: php-cgi [-q] [-h] ...
PHP_EXIT=1
```

**Causa confirmada:** el `php` del entorno Cron resuelve al manejador **PHP-CGI**; este no acepta `-r` y el exportador `export_usuarios_privado.php` (CLI-only) termina antes de imprimir nada si `PHP_SAPI !== 'cli'`. No cambiar proteccion `PHP_SAPI` ni ejecutar desde HTTP.

Referencias publicas de cPanel EA4 (2026):
- `https://docs.cpanel.net/ea4/php/easyapache4-and-the-ea-php-cli-package/` — `/usr/bin/php` ejecuta CGI, `/usr/local/bin/php` CLI.
- `https://support.cpanel.net/hc/en-us/articles/360053606793-How-to-create-or-edit-cron-jobs-in-the-cPanel-user-interface` — usar ruta PHP CLI completa en Cron Jobs.

**Siguiente puerta puntual:** borrar crons de prueba/export anteriores; si no existe `usuarios-legado.json`, ejecutar una tarea temporal cron con `/usr/local/bin/php -r ...` y salida exclusivamente en archivo privado `cron-php-cli-probe.txt`, protegida contra repeticion. Confirmar `PHP_SAPI=cli` y `PHP_EXIT=0`, eliminar el cron de probe. Solo entonces recrear exportacion de usuarios con ruta **`/usr/local/bin/php`** (no `php`, sin `-q`) y destino existente guardado, sin imprimir PII. Eliminar cron inmediatamente despues del PASS. No sobrescribir un JSON preexistente.

Estado: export users-only legado pendiente; usuario React ya exportado PASS 76/62, sin passwords ni escrituras.

### Users-only legado — CLI confirmado; exportacion sigue sin PASS (12/11/2026)

El operador aporto `cron-php-cli-probe.txt` y nuevas capturas de Cron Jobs / File Manager:
```text
PHP_SAPI=cli
PHP_VERSION=8.1.35
PHP_EXIT=0
```
Se confirma que `/usr/local/bin/php` resuelve efectivamente al CLI en el hosting; solucionado el problema anterior del comando `php` que resolvia a `php-cgi`.

El Cron exportador de users-only esta configurado con `/usr/local/bin/php` (sin `-q`) y periodicidad temporal `* * * * *`. File Manager muestra `usuarios-legado-resultado.txt` de **53 bytes**, ya no 0, y **no aparece** `usuarios-legado.json`. Sin contenido del resultado NO afirmar ningun codigo de error concreto ni que la base de datos falle.

**Siguiente gate:** borrar el Cron exportador repetitivo, abrir unicamente `usuarios-legado-resultado.txt` y compartir el codigo cerrado `CERVESUR_USUARIOS_EXPORT=ERROR_...` para diagnostico. No reintentar export ni modificar loader/`.env`, permisos, credenciales o SAPI antes de leer el error; no transferir datos personales. Alcance sigue exclusivo usuarios, MySQL sin DML y React users-only export PASS 76/62.

### Users-only legado — error cerrado por codigo: DIRECTORIO_NO_PRIVADO (12/11/2026)

Archivo `usuarios-legado-resultado(1).txt` entregado por operador contiene exactamente:

```text
CERVESUR_USUARIOS_EXPORT=ERROR_DIRECTORIO_NO_PRIVADO
```

La verificacion del exportador `ops/migracion/export_usuarios_privado.php` rechaza el directorio de salida si cae bajo un `public_html/htdocs/...`, bajo `/var/www`, o si presenta algun bit de permisos para 'others': `(fileperms($parent) & 0007) !== 0`. La ruta usada `/home/databalt/cervesur_cron_private/usuarios-legado.json` se encuentra fuera de DocumentRoot por la evidencia previa, por lo que **la hipotesis principal a confirmar** es que la carpeta `cervesur_cron_private` usa 0755 (u otro modo con bits de 'otros').

**Procedimiento sin degradar protecciones:** (1) detener/eliminar Cron de exportacion repetitivo; (2) en cPanel File Manager, inspeccionar permisos de **carpeta** `/home/databalt/cervesur_cron_private` y ajustar a **0700** solo esa carpeta si es de propiedad de la cuenta (no tocar `.env`, public_html, ni permisos de MySQL); (3) comprobar que no existe `usuarios-legado.json`; (4) solo entonces ejecutar una vez export users-only por Cron usando `/usr/local/bin/php`, con salida privada. El codigo `ERROR_DIRECTORIO_NO_PRIVADO` es un bloqueo seguro, no un defecto de PHP-CLI (este ultimo ya fue confirmado `PHP_SAPI=cli PHP_EXIT=0`).

Esperado tras correccion: `CERVESUR_USUARIOS_EXPORT=PASS`, 190 usuarios, 176 activos, `CONTRASENAS_EXPORTADAS=NO`, `BASE_MODIFICADA=NO`. Eliminar tarea cron tras una corrida y transferir PII solo por canal autorizado SFTP; no compartir JSON de usuarios en chat ni GitHub. Si persiste el error pese a 0700, detener y auditar el destino resuelto/symlink sin desactivar el gate.

### Users-only legado — JSON de usuarios recibido, validacion estructural agregada (12/11/2026)

El operador mostro cPanel File Manager con `cervesur_cron_private` configurado a 0700 y aporto un archivo `usuarios-legado.json` con **datos personales**. Se verifico localmente **solo su estructura y conteos**, sin imprimir nombres, correos, telefonos, filas ni hashes en la evidencia:
- `schema=cervesur-usuarios-privados-v1`; `origin=legado`; `database=databalt_portalbi`; `includes_passwords=false`.
- 190 usuarios, 176 activos, 14 inactivos. Roles: 176 `usuario`, 10 `user` y 4 `admin`.
- 0 IDs duplicados, 0 emails duplicados, 0 usernames duplicados, 0 emails con formato invalido; campos nombre y apellido presentes.
- 113 telefonos contienen el texto literal `"null"` (no valores JSON null). Correctivo acotado en `ops/migracion/plan_usuarios.py`: normalizar ese marcador a `None` SOLO en plan local, sin alterar el archivo fuente. Test sintetico agregado.
- El contenido del archivo es **PII**, por lo que no debe adjuntarse de nuevo ni almacenarse en GitHub, issues o logs. El hecho de haberlo compartido en el chat no autoriza publicarlo ni volver a distribuirlo.

**Puerta pendiente:** la inspeccion estructural del JSON no confirma por si sola la salida del log `CERVESUR_USUARIOS_EXPORT=PASS`; comprobar su estado y borrar cualquier cron activo. Para simular en Ubuntu, transportar desde el equipo del operador hacia `/home/usr_cervesur/cervesur_migracion_privada/entrada/usuarios-legado.json` via SFTP, chmod 0600, instalar copia 0600 bajo `www-data` en `/var/lib/cervesur-migration/snapshots/usuarios-legado.json` si no existe, comparar contenidos con `cmp`, e instalar comparador de rama como `root:root` 0644 despues del CI. Ejecutar `plan_usuarios.py` como `www-data` con salida privada nueva `plan-usuarios.json`; compartir unicamente el resumen agregado, no el plan ni datos individuales.

No hacer INSERT/UPDATE/DELETE, no sobrescribir 76 cuentas React, no alterar empresas/reportes/relaciones. Cuentas nuevas solo propuestas inactivas, sin passwords, a revisar antes de autorizar aplicador.

### Users-only — transporte privado legado a Ubuntu: PASS (12/11/2026)

El operador transfirio `usuarios-legado.json` por SFTP a `/home/usr_cervesur/cervesur_migracion_privada/entrada/`, y luego al destino privado:
`/var/lib/cervesur-migration/snapshots/usuarios-legado.json`, propiedad `www-data:www-data` y modo 0600, sin sobreescritura. Salida de PuTTY:
```text
USERS_COPY=PASS
USERS_ACCESS=PASS
```
La exportacion del destino `usuarios-react.json` ya tenia PASS (76 usuarios, 62 activos). El origen tiene 190 usuarios, 176 activos. Ambas fuentes users-only estan listas para calcular **un plan offline, NO una importacion**. No insertar filas, no activar usuarios ni copiar passwords.

**QA observado:** en commit `58e1ca97` las ejecuciones `Migracion diferencial CI` y `Backend CI` concluyeron SUCCESS; `plan_usuarios.py` fue revisado y los cambios users-only estan cubiertos por pruebas sinteticas. Los archivos con PII no se trasladan a repositorio/chat.

**Proximo paso acotado:**
1. `git pull --ff-only origin feat/migracion-diferencial-dryrun-v1` y comprobar checkout limpio.
2. Instalar `ops/migracion/plan_usuarios.py` como `root:root` 0644 en `/usr/local/lib/cervesur-migration/plan_usuarios.py`. `cmp` y validacion de sintaxis `ast.parse` PASS.
3. Verificar ambos JSON 0600 legibles por `www-data`, existencia del directorio privado y **no existencia** de `/var/lib/cervesur-migration/snapshots/plan-usuarios.json`.
4. Ejecutar **una sola vez** `sudo -u www-data -- env PYTHONDONTWRITEBYTECODE=1 python3 /usr/local/lib/cervesur-migration/plan_usuarios.py --legado /var/lib/cervesur-migration/snapshots/usuarios-legado.json --react /var/lib/cervesur-migration/snapshots/usuarios-react.json --salida /var/lib/cervesur-migration/snapshots/plan-usuarios.json`. El JSON de salida contiene PII y no puede compartirse, imprimirse o subirse. El stdout solo contiene conteos agregados y banderas `mysql_modificado=false`, `aplicacion_autorizada=false`.
5. Revisar agregados; importador/aplicacion productiva requieren gates posteriores de backup, privilegios minimos, cuentas inactivas y credenciales iniciales de manera segura y **aprobacion explicita**. Empresas, reportes y asignaciones excluidos.

### Simulacion users-only: PASS; prioridad cambiada a habilitar seccion Usuarios React (12/11/2026)

Evidencia de PuTTY del operador (sin PII):
```text
PLAN_CODE=PASS
PLAN_SYNTAX=PASS
LEGADO=PASS
REACT=PASS
PLAN_OUTPUT=READY
CERVESUR_PLAN_USUARIOS=PASS
aplicacion_autorizada=false
candidatos_inactivos=114
coincidentes_conservados=76
destino_no_presente_en_origen=0
destino_total=76
existentes_con_diferencia_rol_o_username=0
motivos_revision={}
mysql_modificado=false
origen_total=190
revision_requerida=0
sin_correo_coincidente=114
DATOS_PERSONALES_EN_CONSOLA=NO
```

Resultado: 114 cuentas candidatas nuevas, inicialmente **inactivas** y sin password en la propuesta; 76 cuentas destino conservadas, 0 conflictos; **aun no hay INSERT ni activacion real**.

**Nueva prioridad del responsable:** habilitar la seccion de **Usuarios** de React 8443 para el administrador, aparte de la importacion eventual de 114 cuentas. NO confundir 'hacer visible listado de usuarios' con 'habilitar escrituras U-BASIC' o 'habilitar cambios sensibles P5'.

**Estado comprobado en documentos de la rama `feat/cervesur-ux-09a-cierre-usuarios`:**
- `docs/UX-09B-TABLERO-EJECUCION.md` describe listado/busqueda y consulta de acceso U04/U07 **ya disponibles en lectura**; P4b habilita **solo** alta `user`, edicion de nombre/apellido/telefono, y desactivar/reactivar con espera 24 horas. P5 rol/contraseña sigue separado, bloqueado.
- `deploy/produccion-escritura/scripts/interruptor-usuarios.sh` posee `estado`, `encender --autorizacion ...`, `apagar`. `encender` **modifica** el `.env` de 8443 tras gates, respalda y comprueba capacidades; exige `CERVESUR_CONFIRMAR_USUARIOS=SI` y credenciales de QA. **No ejecutarlo a ciegas ni copiarlo al servidor desde rama distinta**.
- Issue #17 rectifica operacion productiva del 10/11: C5 SQL, backup/restauracion P2, instalacion P3 con Usuarios OFF y privilegios P4a no sensibles PASS reportados; hay que comprobar vigencia solo lectura, no repetirlos.
- `docs/UX-09B-PUERTAS-DE-SEGURIDAD.md` exige cierre K2, K1, A2, A1, A3/F-10 y seguridad D3/D4, firmas y autorizaciones para P4b. Algunos controles siguen sin cierre; NO inferir GO a partir de `CERVESUR_PLAN_USUARIOS=PASS`.
- **Ninguna modificacion ejecutada en React 8443, MySQL, Flutter 80, Apache, Power BI ni interruptores.**

**Siguiente accion sin escritura y sin contrasenas:** comprobar respuesta HTTPS del admin 8443, existencia/propietarios de archivos y script del interruptor en el paquete desplegado, y obtener salida de `estado` solo si el paquete instalado se verifica como confiable. No mostrar `.env`, JWT, cookies, contrasenas ni archivos con PII. Si listado ya visible, QA de lectura por administrador y usuario normal sin crear nuevas cuentas. Con datos actuales, **P4b/P5 sigue pendiente** de gates aunque el usuario solicito habilitar Usuarios funcionalmente.
