# Entorno React ADMINISTRATIVO DE QA, aislado (puerto elegido por el operador, solo por túnel SSH)

Paquete **instalable pero NO instalado** de un tercer entorno React: un piloto de QA con **capacidad de escritura administrativa** (usuarios, historial de
cambios, visor de un informe). Sigue el mismo diseño ya ensayado en `deploy/tunel-ssh-compat/` (8089): loopback, acceso por túnel SSH, scripts propios,
aborta sin cambios si algo ya existe y se revierte por completo. **Nada de este paquete se ha ejecutado en ningún servidor real**; solo se ensayó en Docker
(`ensayo-docker.mjs`). Los datos son **100 % sintéticos**: este entorno no usa ni toca `cervesur_portalbi`.

> **AVISO — escrituras SOLO en la QA.** Este entorno arranca con `USER_ADMIN_WRITES=1`. Esa variable **debe permanecer APAGADA** (ausente o distinta de `1`)
> en los portales publicados **443 y 8443**. El instalador y el verificador lo recuerdan en pantalla.

## 1. Arquitectura

| Elemento | Valor (todos nuevos; el instalador comprueba que NO existen) |
|---|---|
| Puerto | `127.0.0.1:<puerto>` (nunca `0.0.0.0`), **explícito**: `--puerto N`. Candidato propuesto: **8094**, pendiente de verificar en el servidor (ver §1.1). **8090-8093 están ocupados** por otros portales |
| Sitio Apache | `cervesur-react-admin-qa` (una directiva `Listen 127.0.0.1:<puerto>` dentro del propio sitio, generada desde una plantilla; no se edita `ports.conf`) |
| Frontend | `/var/www/cervesur-react-admin-qa/public` (compilación de servidor, `localhost` autorizado) |
| Backend PHP | `/opt/cervesur-react-admin-qa/backend` (fuera del DocumentRoot; `.env` 640 `root:www-data`) |
| Snippets Apache | `/etc/apache2/cervesur-react-admin-qa/` |
| Sesiones PHP | `/var/lib/cervesur-react-admin-qa/sessions` (700, `www-data`), cookie `CERVESUR_REACT_ADMIN_QA` |
| Base de datos | `cervesur_qa_admin` (DDL con tipos productivos + las 8 claves UNIQUE productivas) |
| Cuenta MySQL | `cervesur_qa_admin_w`@`127.0.0.1`, creada con `database/privileges/UX-08E-cuenta-admin-minima.sql.template` |
| Respaldos / línea base | `/var/backups/cervesur-react-admin-qa/` (**nunca se borra**) |

No colisiona con Flutter (80), React de trabajadores (443), React admin (8443) ni los puertos 8088-8093: puertos, nombres, rutas, cookie, sesiones, base y cuenta son propios.

### 1.1 Puerto
- **Siempre explícito**: `--puerto N` o `CERVESUR_QA_PUERTO=N`. No hay valor por defecto ni cambio automático; si faltan o discrepan, aborta.
- **Se rechaza** (con `RESULTADO: NO-GO` y sin cambios): 80, 443, 8443, 8088, 8089, **8090, 8091, 8092, 8093**, 22, 3306 y 33060; cualquier valor mal formado (no decimal canónico de 1024 a 65535); cualquier puerto
  en el que **escuche algo** (cualquier interfaz); y cualquier puerto que **Apache mencione** (`Listen`, `VirtualHost`, `ports.conf`, `conf-*`) o que aparezca en `apache2ctl -S`.
- El puerto instalado queda **registrado** en `/var/lib/cervesur-react-admin-qa/puerto` (lo primero que se escribe tras la marca). `verificar.sh` y `revertir.sh` usan **exactamente ese**; uno distinto aborta.
- Verificación previa de solo lectura para el operador: `bash deploy/auditoria/puerto-candidato-solo-lectura.sh 8094` (paquete de auditoría).
- Registrar un puerto nuevo en Apache (la directiva `Listen` del sitio nuevo y la recarga suave) es una operación que **exige aprobación expresa por separado**; no se edita ningún archivo existente de Apache.

**Privilegios de la cuenta** (de la plantilla UX-08E): `SELECT` en toda la base; `INSERT` en las 6 tablas de negocio y en `audit_log`; `UPDATE` solo por columnas;
**nunca** `ALL PRIVILEGES`, `DELETE`, `GRANT OPTION`, DDL ni `TRIGGER`; `audit_log` solo admite `INSERT` (más los triggers de solo inserción de UX-08B.2).
Ningún permiso sobre `cervesur_portalbi` ni sobre ninguna otra base. La contraseña se **genera en el servidor**, se escribe solo en el `.env` (modo 640) y no se imprime.
El `JWT_SECRET` también se genera en el servidor.

**`.env` generado** (ver `.env.qa-admin.example`): `DB_HOST=127.0.0.1`, `DB_NAME=cervesur_qa_admin`, `DB_USER=cervesur_qa_admin_w`, `AUDIT_LOG_MODE=required`,
`REACT_STRICT_VIEWS=1`, `USER_ADMIN_WRITES=1` (solo QA), `ADMIN_VIEWER_ALLOWED_REPORT_IDS=31`.

### Informe «DB031» sintético
La base sintética no contiene DB031. Se usa el informe sintético **`reporte.id = 31`** (renombrado «DB031-SINTETICO (QA)») como sustituto en la lista del visor del
administrador. El **id real (72) solo se usa en el piloto real, tras aprobación expresa**: no figura en este paquete. Para probar otro id sintético:
`CERVESUR_QA_VISOR_IDS=40 sudo -E bash scripts/instalar.sh` (debe ser un id activo del 1 al 82; el informe renombrado seguirá siendo el 31).

### Migración UX-08B3 (`reporte.grupo_id` admite NULL)
Por defecto **NO se aplica**: la QA arranca como producción (`grupo_id VARCHAR(50) NOT NULL`). Para probar el estado posterior, añadir
`--aplicar-migracion-grupo-nullable` al instalador (se aplica y se verifica `VARCHAR(50) NULL`). Ambos caminos están ensayados en Docker. El orden de migraciones es siempre
`UX-08B-audit-log` → `UX-08B-audit-log-immutable` → (opcional) `UX-08B3`, cada una verificada antes de seguir; las aplica el administrador de MySQL, nunca la cuenta de la aplicación.

## 2. Requisitos previos (los comprueba el instalador; si falta uno, aborta sin cambiar nada)
Ubuntu 24.04; Apache con `mod_alias`, `mod_rewrite`, `mod_php` y `mod_headers` **ya cargados** (el paquete jamás habilita ni deshabilita módulos ni reinicia Apache: solo `configtest` + recarga suave);
PHP con `pdo_mysql`, `mbstring`, `json`, `openssl`; MySQL local en `127.0.0.1:3306` y acceso de administrador (`sudo mysql` por socket, o `CERVESUR_MYSQL_ADMIN="mysql -u ... "` sin contraseña en la línea de comandos);
`openssl`, `curl`, `flock`, `ss`; 500 MB libres en `/var` y `/opt`.

## 3. Empaquetar (en el equipo de desarrollo, Windows Git Bash)
```bash
node deploy/qa-admin-aislado/empaquetar.mjs --out "$TEMP/cervesur-paquetes"      # compila el frontend de servidor y empaqueta
node deploy/qa-admin-aislado/empaquetar.mjs --sin-compilar --out <carpeta>         # reutiliza frontend/dist-production
```
`--out` debe estar **fuera del repositorio**. Resultado: `cervesur-react-admin-qa-<commit>-<fecha>.tar.gz` + `.sha256`; dentro, `SHA256SUMS`. El paquete excluye `.env`, pruebas y secretos.
Ensayo completo en Docker (no toca ningún servidor): `node deploy/qa-admin-aislado/ensayo-docker.mjs`.

## 4. Instalación paso a paso (en el servidor, SOLO tras aprobación del responsable)
1. Copiar el `.tar.gz` y su `.sha256` al servidor (`scp`, por el canal autorizado) y comprobar: `sha256sum -c *.tar.gz.sha256`.
2. `tar xzf cervesur-react-admin-qa-*.tar.gz && cd cervesur-react-admin-qa-*`
3. **Simulación (solo lectura):** `sudo bash scripts/instalar.sh --simular --puerto 8094`. Imprime las comprobaciones, un **resumen** (puerto y su estado, base, cuenta, MySQL con `log_bin`, dependencias HTTP, interruptores de los portales publicados) y
   `RESULTADO: GO` o `RESULTADO: NO-GO`; termina con «Modo --simular: fin». No escribe nada (ver §11). Si algún nombre, ruta, puerto, base o cuenta existe, o no se puede demostrar `USER_ADMIN_WRITES` apagado en los portales publicados, **aborta sin cambios**.
4. **Instalación:** `sudo bash scripts/instalar.sh --puerto 8094` (añadir `--aplicar-migracion-grupo-nullable` solo si se quiere la QA con UX-08B3). Pide escribir `INSTALAR-QA-ADMIN`.
   El instalador: respalda `/etc/apache2` y guarda la línea base → crea carpetas con marca → crea la base y carga el esquema sintético → aplica y verifica las migraciones →
   crea la cuenta mínima con una contraseña generada → escribe el `.env` → crea el sitio y recarga Apache de forma suave → ejecuta `verificar.sh`.
5. Se imprimen **una sola vez** las cuentas sintéticas (`syn001` admin, `syn005`/`syn006` rol `usuario`, `syn007` rol `user`). Guárdelas en un gestor de contraseñas; en la base solo hay hashes.
6. Si un paso falla, el instalador **revierte automáticamente solo lo que creó**.

## 5. Túnel SSH (lo ejecuta el operador; este paquete no se conecta a nada)
```bash
ssh -N -L 18091:127.0.0.1:<puerto> <usuario>@<servidor>      # p. ej. 8094; y abrir http://localhost:18091
```
El navegador debe usar `localhost` (el frontend solo admite ese host). Ningún puerto nuevo se abre en la red.

## 6. Verificación y GO / NO-GO
`sudo bash scripts/verificar.sh` (lo corre el instalador al final). Para las pruebas autenticadas, exporte `CERVESUR_QA_ADMIN_PASSWORD`, `CERVESUR_QA_USUARIO_PASSWORD` y `CERVESUR_QA_USER_PASSWORD`
(no se guardan en ningún sitio); sin ellas las pruebas autenticadas se **omiten** y el resultado es **NO-GO** (nunca un GO falso). Escribe un único cambio sintético (editar el nombre del usuario 40) para comprobar la auditoría.

**GO solo si TODOS los grupos están en GO:**
1. *Red:* escucha solo en `127.0.0.1:<puerto registrado>` (no `0.0.0.0`); `configtest` correcto.
2. *Frontend y Apache:* React servido; ruta interna recarga; 404 reales; `/api/powerbi` 403; `.env` y fuentes inaccesibles; CSP/`X-Frame-Options`; cookie y sesiones propias.
3. *Configuración del backend:* `AUDIT_LOG_MODE=required`, `REACT_STRICT_VIEWS=1`, `USER_ADMIN_WRITES=1`, lista del visor, base y cuenta propias; JWT propio; marcas de instalación.
4. *Base de datos:* tipos productivos + 8 UNIQUE; migraciones aplicadas; los tres valores de rol; 76 usuarios (62 activos); informe sintético 31.
5. *Privilegios de la cuenta:* `SHOW GRANTS` sin `DELETE`, sin `ALL PRIVILEGES`, sin `GRANT OPTION`, sin mención de `cervesur_portalbi`; no puede alterar `audit_log`; ni siquiera `root` (triggers).
6. *API:* anónimo 401; trabajador (rol `usuario` y rol `user`) 403 en rutas de administración; `/api/users/capabilities` = true; historial disponible; una escritura deja fila en `audit_log`; visor 200 solo en el informe autorizado.
7. *Los demás portales:* Flutter (80), 443, 8443, 8088, 8089, todo `/etc/apache2`, módulos, puertos, bases y cuentas MySQL **idénticos** a la línea base guardada antes de instalar.

## 7. Checklist de QA manual
- [ ] Entrar con `syn001` (rol **admin**): ver «Usuarios» y «Historial»; editar un usuario; dar de baja y reactivar (lógico, sin DELETE); comprobar la fila en el historial.
- [ ] Entrar con `syn005` o `syn006` (rol **usuario**): sin acceso a Usuarios/Historial (403); ver solo sus informes.
- [ ] Entrar con `syn007` (rol heredado **user**): mismo comportamiento que `usuario`.
- [ ] Los **tres** valores de rol (`admin`, `user`, `usuario`) existen en la base; usuarios inactivos (p. ej. `syn002`, `syn066`, `syn067`) no pueden entrar.
- [ ] Visor del administrador: abre el informe 31 («DB031-SINTETICO (QA)») y se niega en los demás (403). Los enlaces de Power BI son ficticios: el iframe se construye pero Power BI no responde.
- [ ] Edición con versión (409 ante datos desactualizados); altas con contraseña de política vigente.
- [ ] Con `--aplicar-migracion-grupo-nullable`: crear un informe «Publicar en la web» sin grupo funciona; sin la migración, no.
- [ ] Confirmar que 443/8443 NO tienen `USER_ADMIN_WRITES` activo.

## 8. Reversión
```bash
sudo bash scripts/revertir.sh                  # pide escribir REVERTIR-QA-ADMIN
sudo bash scripts/revertir.sh --conservar-base # deja la base y la cuenta (con su marca)
```
Elimina **solo** lo que el instalador registró y que conserva la marca de ESA instalación (sitio, snippets, carpetas, base, cuenta); si falta la marca, **se niega** a borrar ese recurso (y lo informa).
Sin instalación marcada no borra nada. Deja un registro en `/var/backups/cervesur-react-admin-qa/revertir-<fecha>.log` y compara el servidor con la línea base.
No toca otros sitios, otras bases, otras cuentas, módulos de Apache ni los respaldos.

## 9. Lo que el paquete NUNCA hace
Conectarse a otro servidor; tocar `cervesur_portalbi` o cualquier otra base; conceder privilegios sobre otras bases; usar `ALL PRIVILEGES`, `DELETE` o `GRANT OPTION`; guardar o imprimir la contraseña de MySQL o el JWT;
abrir puertos en la red; reiniciar Apache; habilitar/deshabilitar módulos; modificar Flutter, 443, 8443, 8088 u 8089; activar `USER_ADMIN_WRITES` en otro portal; borrar respaldos; usar el id real 72.

## 10. Suposiciones sobre el servidor real que el ensayo no puede probar
La cuenta `@127.0.0.1` debe coincidir con las conexiones TCP del cliente PHP (si el servidor resuelve nombres, la conexión podría presentarse como `localhost`); `ALTER USER ... COMMENT` requiere MySQL ≥ 8.0.21;
los portales 443/8443 reales (TLS, nombres de archivo y rutas) se protegen con la huella genérica de todo `/etc/apache2`, no por nombre; `mod_headers` debe estar cargado;
con `log_bin` activo y `log_bin_trust_function_creators=0` (el caso del servidor real) crear los triggers de UX-08B.2 **exige `SUPER`** a la cuenta administradora (ver §11); la simulación lo comprueba leyendo sus privilegios.

## 11. Triggers con binary logging, simulación y bloqueos (UX-08F.1)
- **Triggers y `log_bin`:** el servidor real tiene `log_bin=1` y `log_bin_trust_function_creators=0`. En ese caso MySQL rechaza `CREATE TRIGGER` con **ERROR 1419** a una cuenta sin `SUPER`, aunque tenga `TRIGGER`, `CREATE` y `ALTER`
  (demostrado en la réplica de ensayo). Las migraciones las aplica la cuenta **administradora** del operador (`root` por socket o la de `CERVESUR_MYSQL_ADMIN`), solo durante la instalación y sin conceder nada de forma permanente:
  no se activa `log_bin_trust_function_creators`, no se concede `SUPER` a nadie y la cuenta de la aplicación (`cervesur_qa_admin_w`) jamás tiene `TRIGGER`, `DDL` ni `DELETE`. Si la cuenta administradora no tiene `SUPER`,
  la simulación da **NO-GO** antes de crear nada; la alternativa es que un DBA con `SUPER` aplique a mano `database/migrations/UX-08B-audit-log-immutable.up.sql` (decisión aparte, no automatizada).
- **Fallo a mitad de instalación:** si cualquier paso falla (migración de triggers, Apache…), el instalador revierte **solo lo creado por ese intento** (token propio). Si el proceso muere por la fuerza (SIGKILL), `sudo bash scripts/revertir.sh`
  retira los restos usando el puerto registrado. Las contraseñas sintéticas se escriben dentro de la carpeta de estado (600) y se eliminan con ella; `pasos-instalacion.sh` deja además un directorio `/tmp/tmp.*` con el SQL de semilla (solo hashes) si lo matan: bórrelo.
- **Verificador:** `verificar.sh` exige los 2 triggers (`audit_log_no_update`, `audit_log_no_delete`) y comprueba que ni siquiera el administrador puede modificar `audit_log`; un trigger ausente da **NO-GO**.
- **`--simular` no escribe nada:** ni bases, cuentas ni privilegios, ni carpetas, ni archivos, ni Apache, ni recargas, ni archivos temporales (no usa `mktemp`; el resumen vive en memoria). El ensayo lo demuestra con la posición del registro binario de MySQL (idéntica antes y después), con
  `find -cnewer` sobre `/etc /opt /var/www /var/backups /var/lib /usr/local /tmp /var/tmp` (vacío) y con una auditoría estática del código previo a su salida.
- **Bloqueos:** dos ejecuciones del mismo paquete se excluyen (`flock` sobre el script); la instalación y la reversión toman además un bloqueo global (`/run/lock/cervesur-react-admin-qa.lock`, en tmpfs), de modo que dos instalaciones simultáneas
  (aun desde copias distintas) no se solapan, y el intento perdedor no puede revertir lo del ganador (la reversión exige el token del intento que la pide).
- **Interruptor `USER_ADMIN_WRITES`:** `scripts/interruptor-escrituras.sh` replica la regla del código (`env.php` + `write_support.php`) y emite por instancia: estado efectivo · fuente · PASS/FAIL, sin imprimir ningún valor de `.env`.
  La simulación lo ejecuta sobre `/opt/cervesur-react-usuarios` y `/opt/cervesur-react-admin` (`CERVESUR_QA_PORTALES_PUBLICADOS` permite cambiar la lista); si no se puede demostrar apagado, **NO-GO**.
