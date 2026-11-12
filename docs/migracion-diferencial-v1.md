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
