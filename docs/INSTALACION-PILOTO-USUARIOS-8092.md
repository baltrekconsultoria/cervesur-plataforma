# Instalación manual del piloto con usuarios reales · 127.0.0.1:8092 (UX-07D.4B.1)

Procedimiento para quien instala. **Nada de esto se ha ejecutado en la VPS.** El paquete no contiene `.env`, claves, certificados ni hashes productivos. Todo es reversible (ver `RECUPERACION-PILOTO-USUARIOS-8092.md`).

## 0. Qué es y qué no es

* Sitio **nuevo y aislado** `cervesur-react-usuarios` en `https://127.0.0.1:8092` (solo loopback, solo TLS), accesible por túnel SSH. No toca Flutter (80), 8088, 8089, 8090 ni 8091.
* Los usuarios son los **ya existentes en Cervesur**, con su contraseña habitual; solo entran los de la **lista blanca**, activos y de rol `user` (los administradores no entran). La lista blanca arranca **vacía**.
* Solo lectura sobre la base de Flutter. Lo único que se escribe es la base de estado propia del piloto (sesiones, lista blanca, intentos, auditoría).
* Antes de instalar lea `HTTPS-PILOTO-USUARIOS.md`: sin TLS válido no hay login.

## 1. Orden de instalación

1. **Verificar el paquete** (equipo local y servidor): `sha256sum -c *.tar.gz.sha256`; extraer; `cd <paquete> && sha256sum -c SHA256SUMS --quiet`.
2. **Revisar la infraestructura (solo lectura):** `sudo bash scripts/preflight-infra.sh`. Falla si no hay `mod_ssl` cargado, si el puerto 8092 está ocupado o mencionado, si alguna ruta del piloto ya existe, etc. **No** asume el puerto libre: lo comprueba.
3. **Administrador de MySQL — preparar el SQL** (no ejecuta nada): `sudo bash admin-mysql/preparar-sql-cuentas.sh` → `/root/cervesur-usuarios.sql` y `/root/cervesur-usuarios.claves` (600, claves no mostradas).
4. **Revisar el SQL sin ver las claves:** `bash admin-mysql/mostrar-sql-redactado.sh` (no use `cat`).
5. **Ejecutar el SQL a mano, con autorización:** `sudo mysql < /root/cervesur-usuarios.sql`. Crea la base `cervesur_react_estado` con sus 4 tablas y **tres cuentas** en `127.0.0.1`.
6. **Verificar privilegios:** `sudo mysql -B < admin-mysql/03-verificar-privilegios.sql` y `sudo bash scripts/preflight-acceso.sh --claves-archivo /root/cervesur-usuarios.claves` (9 columnas · 21 columnas · DML solo en la base de estado; sale 3 = PENDIENTE si algo falta, nunca un PASS ficticio).
7. **Simular:** `sudo bash scripts/instalar.sh --simular --claves-archivo /root/cervesur-usuarios.claves` (código 0 = puede instalar).
8. **Instalar:** `sudo bash scripts/instalar.sh --claves-archivo /root/cervesur-usuarios.claves` (pide escribir `INSTALAR-8092`). Genera el certificado de prueba y ejecuta `verificar.sh`. Si algo falla, **revierte solo** lo que creó.
9. **Destruir** `/root/cervesur-usuarios.sql` y `/root/cervesur-usuarios.claves` (`shred -u`): las claves quedan solo en el `.env` (640 root:www-data).
10. **Confianza en el certificado** (opción A de `HTTPS-PILOTO-USUARIOS.md`), túnel `ssh -N -L 18093:127.0.0.1:8092 <usuario>@<servidor>` y `https://localhost:18093`.
11. **Autorizar personas** una a una: `sudo bash scripts/usuarios.sh agregar <user_id> "nota"`.
12. **Enlaces de informes:** por defecto **ninguno** se entrega. Para autorizar: `sudo bash scripts/usuarios.sh informes --autorizar 12,15` (pide `AUTORIZO-INFORMES`). Los enlaces de «Publicar en web» son públicos para quien los conozca.

## 2. Operación diaria

`scripts/usuarios.sh`: `listar`, `agregar`, `quitar` (revoca sesiones al instante), `revocar <id|todos>`, `sesiones`, `desbloquear <login>`, `auditoria`, `informes --listar|--autorizar|--retirar`. `scripts/tls.sh`: `verificar`, `huella`, `publico`. `scripts/verificar.sh`: comprobación completa de solo lectura.

## 3. Si algo falla

* El preflight **no** modifica nada. `instalar.sh` revierte solo lo suyo y conserva los respaldos en `/var/backups/cervesur-react-usuarios`.
* Para deshacer: `sudo bash scripts/revertir.sh` (pide `REVERTIR-8092`). No toca MySQL: las cuentas y la base de estado se retiran aparte (`admin-mysql/99-revocar-cuentas.sql`).

## 4. Qué NO hace el instalador

No crea ni modifica cuentas, bases ni privilegios de MySQL; no habilita módulos de Apache; no reinicia Apache (recarga suave tras `configtest`); no abre puertos en la red; no emite HSTS en el túnel; no activa `CERVESUR_INSECURE_TESTS`; no copia certificados ni claves en el paquete.

## 5. Notas de operación y riesgos conocidos

* **Bloqueo por intentos.** Cada acceso (usuario/correo) se bloquea 15 min tras 5 fallos; un tercero podría bloquear a propósito a una persona concreta (`usuarios.sh desbloquear <login>`). Detrás del túnel SSH **todos** los clientes llegan desde `127.0.0.1`, por lo que el límite por IP no distingue personas: el instalador lo deja en un tope global holgado (`CERVESUR_PILOT_MAX_FALLOS_IP=100`, en el `.env`) para que no sea un interruptor que bloquee a todos.
* **Habilitar `mod_ssl`** tiene efectos globales (puerto 443); léase `HTTPS-PILOTO-USUARIOS.md` antes de pedírselo al administrador.
* **Enlaces públicos.** «Publicar en web» no tiene autenticación: el portal solo controla el descubrimiento; revocar el permiso de una persona no invalida un enlace que ya conozca.
* **Certificado de prueba.** Importarlo como de confianza en un equipo es una decisión consciente; revócelo al terminar el piloto.
* **Auditoría.** Revise `usuarios.sh auditoria` cada día durante el piloto (inicios de sesión, bloqueos, visores abiertos y denegados).

## 6. Actualización de un piloto YA instalado (UX-07D.5)

`instalar.sh` se niega a instalar sobre un piloto existente (protege lo instalado). Para llevar una versión nueva del paquete a un 8092 en marcha se usa `actualizar.sh`, que **solo** reemplaza `/var/www/cervesur-react-usuarios/public` y `/opt/cervesur-react-usuarios/backend`.

1. Transferir y verificar el paquete nuevo (`sha256sum -c *.tar.gz.sha256`; extraer; `sha256sum -c SHA256SUMS --quiet`).
2. **Simular:** `sudo bash scripts/actualizar.sh --simular`. No escribe nada. Comprueba: piloto instalado y sano por HTTPS, modo `piloto_usuarios`, `.env` sin `CERVESUR_INSECURE_TESTS`, paquete íntegro y sin `.env`/claves/certificados, puerto 8092 solo en loopback. Si la configuración de Apache del paquete difiere de la instalada, **avisa** (esta actualización no la cambia).
3. **Actualizar:** `sudo bash scripts/actualizar.sh` (pide escribir `ACTUALIZAR-8092`). Respalda `public` y `backend` (sin el `.env`) en `/var/backups/cervesur-react-usuarios/actualizacion-<sello>` (700/600, con `SHA256SUMS`), prepara la versión nueva al lado, conserva el `.env` bit a bit, intercambia las carpetas por renombrado, ejecuta `verificar.sh` y compara la huella de Flutter, 8088, 8089, 8090 y 8091 antes y después. **Si algo falla vuelve solo a la versión anterior y lo verifica.**
4. **No** reinicia ni recarga Apache (mod_php lee el código nuevo en la siguiente petición y el `.htaccess` se evalúa por petición), **no** toca el `.env`, la base de estado, la lista blanca, las cuentas MySQL, el certificado ni la configuración de Apache.
5. **Volver atrás después:** `sudo bash scripts/actualizar.sh --revertir /var/backups/cervesur-react-usuarios/actualizacion-<sello>` (pide `REVERTIR-ACTUALIZACION-8092`; rechaza un respaldo corrupto).
6. Las sesiones abiertas siguen válidas (no cambia el esquema ni el formato del token); si algo extraño ocurre, `usuarios.sh revocar todos` obliga a entrar de nuevo.
7. Esta versión corrige el `.htaccess` (el HSTS ya no se emite para `localhost`) y la lista de informes autorizados (tolera espacios). Si el navegador del operador **ya recibió** HSTS para `localhost` de la versión anterior, puede quedar forzando HTTPS en los túneles HTTP de los otros portales: bórrelo en el navegador (en Chrome/Edge, `chrome://net-internals/#hsts` → «Delete domain security policies» → `localhost`).
