# Instalación manual del piloto administrativo de solo lectura · 127.0.0.1:8093 (UX-07E.B)

Procedimiento para quien instala. **Nada de esto se ha ejecutado en la VPS.** El paquete no contiene `.env`, claves, certificados ni hashes productivos. Todo es reversible. Es una **instancia nueva y separada** del piloto de usuarios (8092): sus rutas, cuentas MySQL, base de estado, certificado y sitio de Apache son propios. **No se actualiza ni se toca el 8092.**

Requiere **autorización específica** antes del paso 4 (cuentas MySQL) y del paso 8 (instalación): crea un sitio de Apache nuevo (recarga suave, sin reinicio), un puerto de loopback nuevo, tres cuentas MySQL de solo lectura y una base de estado nueva. No toca Flutter (80), 8088, 8089, 8090, 8091 ni 8092, ni DNS, ni cortafuegos.

## 1. Orden de instalación

1. **Verificar el paquete** (equipo local y servidor): `sha256sum -c *.tar.gz.sha256`; extraer en una carpeta aislada; `cd <paquete> && sha256sum -c SHA256SUMS --quiet`.
2. **Revisar la infraestructura (solo lectura):** `sudo bash scripts/preflight-infra.sh`. Falla si `mod_ssl` no está cargado, si el **8093 está ocupado o mencionado** (no se asume libre), si alguna ruta de la instancia ya existe, etc.
3. **Administrador de MySQL — preparar el SQL** (no ejecuta nada): `sudo bash admin-mysql/preparar-sql-cuentas.sh` → `/root/cervesur-admin.sql` y `/root/cervesur-admin.claves` (600, claves no mostradas).
4. **Revisar el SQL sin ver las claves:** `bash admin-mysql/mostrar-sql-redactado.sh` (no use `cat`).
5. **Ejecutar el SQL a mano, con autorización:** `sudo mysql < /root/cervesur-admin.sql`. Crea la base `cervesur_react_estado_admin` (4 tablas) y **tres cuentas** `cervesur_ro_auth_adm`, `cervesur_ro_catalogo_adm`, `cervesur_react_estado_adm` en `127.0.0.1`.
6. **Verificar privilegios:** `sudo mysql -B < admin-mysql/03-verificar-privilegios.sql` y `sudo bash scripts/preflight-acceso.sh --claves-archivo /root/cervesur-admin.claves` (9 columnas · 21 columnas · DML solo en la base de estado; sale 3 = PENDIENTE si algo falta, nunca un PASS ficticio).
7. **Simular:** `sudo bash scripts/instalar.sh --simular --claves-archivo /root/cervesur-admin.claves` (código 0 = puede instalar).
8. **Instalar:** `sudo bash scripts/instalar.sh --claves-archivo /root/cervesur-admin.claves` (pide escribir `INSTALAR-8093`). Genera el certificado de prueba propio de esta instancia y ejecuta `verificar.sh`. Si algo falla, **revierte solo** lo que creó.
9. **Destruir** `/root/cervesur-admin.sql` y `/root/cervesur-admin.claves` (`shred -u`): las claves quedan solo en el `.env` (640 root:www-data).

## 2. Autorizar al administrador que hará el QA (lista propia de la instancia)

```
# 1) identificar el id (lectura; sin correos):  sudo mysql -N -e "SELECT id, username, role, is_active FROM cervesur_portalbi.user WHERE role='admin'"
# 2) autorizar SOLO a esa persona:
sudo bash scripts/usuarios.sh agregar <id> "QA administrador"
sudo bash scripts/usuarios.sh listar
```

`agregar` rechaza trabajadores, administradores inactivos e inexistentes. La lista arranca **vacía**: nadie entra hasta que se agregue.

## 3. Acceso de QA desde Windows

1. En el servidor: `sudo bash scripts/tls.sh huella` y `sudo bash scripts/tls.sh publico > servidor-admin.crt`. Copie `servidor-admin.crt` al equipo (`scp`) y **compruebe la huella** antes de confiar. Es un certificado **distinto** del del 8092.
2. Windows: `certutil -user -addstore Root servidor-admin.crt` (o importar en «Entidades de certificación raíz de confianza» del usuario). Revocar al terminar: `certutil -user -delstore Root localhost` (cuidado: el certificado del 8092 también se llama `localhost`; elimine por huella desde el administrador de certificados si ambos están importados).
3. Túnel: `ssh -N -L 18094:127.0.0.1:8093 usr_cervesur@192.168.200.12` y abrir `https://localhost:18094`.

## 4. Enlaces de informes (opcional)

Por defecto **ningún** informe entrega su enlace. Para que el administrador abra el visor de informes concretos: `sudo bash scripts/usuarios.sh informes --autorizar 72,122` (pide `AUTORIZO-INFORMES`). Son enlaces «Publicar en la web»: públicos para quien los conozca (`POWERBI-PUBLICAR-WEB-RIESGO.md`).

## 5. Qué necesita una prueba con un administrador REAL (checklist del operador)

1. Autorización escrita de esta instalación (sitio nuevo, puerto 8093 de loopback, 3 cuentas MySQL, base de estado).
2. Administrador de MySQL disponible para el paso 5.
3. Que el administrador de QA sepa su propia contraseña de Flutter (nadie más la necesita ni debe recibirla) y esté **activo** con rol `admin`.
4. Su `id` y su alta en la lista (§2).
5. Importar el certificado (§3) y abrir el túnel.
6. Decidir si se autorizan informes (§4).

## 6. Operación

`scripts/usuarios.sh`: `listar`, `agregar`, `quitar` (revoca sesiones al instante), `revocar <id|todos>`, `sesiones`, `desbloquear <login>`, `auditoria`, `informes --listar|--autorizar|--retirar`. `scripts/tls.sh`: `verificar`, `huella`, `publico`. `scripts/verificar.sh`: comprobación completa de solo lectura. La auditoría registra cada consulta administrativa (`admin_usuarios`, `admin_empresas`, `admin_informes`, `admin_asignaciones`, `admin_accesos`), inicios, cierres, fallos con motivo interno y visores; nunca contraseñas, hashes, enlaces, nombres ni direcciones IP en claro.

## 7. Rollback

* **Quitar el acceso de una persona:** `usuarios.sh quitar <id>` (revoca sus sesiones).
* **Retirar la instancia:** `sudo bash scripts/revertir.sh` (pide `REVERTIR-8093`). Elimina solo el sitio, las carpetas, el `.env` y el certificado de esta instancia; no toca MySQL ni ningún otro portal. Los respaldos se conservan en `/var/backups/cervesur-react-admin`.
* **Retirar cuentas y base de estado:** `sudo mysql < admin-mysql/99-revocar-cuentas.sql` (solo las tres cuentas `_adm` en `127.0.0.1`) y, si se decide, `DROP DATABASE cervesur_react_estado_admin`.
* **Actualizar a una versión nueva:** `sudo bash scripts/actualizar.sh --simular` → `sudo bash scripts/actualizar.sh` (pide `ACTUALIZAR-8093`); vuelve atrás con `--revertir <respaldo>`. Mismo procedimiento reversible que el piloto de usuarios.

## 8. Qué NO hace el instalador

No crea ni modifica cuentas, bases ni privilegios de MySQL; no habilita módulos de Apache; no reinicia Apache (recarga suave tras `configtest`); no abre puertos en la red; no emite HSTS en el túnel; no activa `CERVESUR_INSECURE_TESTS`; no copia certificados ni claves en el paquete; no modifica ni reinicia el piloto de usuarios (8092), Flutter (80) ni 8088–8091; compara su huella antes y después.

## 9. Actualizar el 8093 ya instalado a esta versión (UX-07E.C)

Contiene: botón «Otros informes» (ventana con buscador) y visor ampliado, indicador `visor_autorizado` en los listados (solo un 0/1; nunca el enlace), leyendas corregidas del panel, `usuarios.sh informes --agregar/--quitar` con bitácora, y la publicación corporativa opcional. **No cambia** el `.env`, la lista de administradores, la base de estado, las cuentas MySQL ni la configuración de Apache.

```
# servidor, con el paquete nuevo extraído y verificado (sha256sum -c SHA256SUMS --quiet)
sudo bash scripts/actualizar.sh --simular      # no escribe nada; código 0 = se puede actualizar
sudo bash scripts/actualizar.sh                # escribe ACTUALIZAR-8093: respalda, reemplaza, verifica y, si falla, vuelve solo
sudo bash scripts/actualizar.sh --revertir /var/backups/cervesur-react-admin/actualizacion-<sello>   # rollback (REVERTIR-ACTUALIZACION-8093)
```

Comprobación posterior (solo lectura): `sudo bash scripts/verificar.sh` sin fallos y la guía `GUIA-QA-UX-07EC.md`. El piloto de trabajadores (8092) no se toca.

## 10. Autorizar o retirar enlaces de informes

`sudo bash scripts/usuarios.sh informes --agregar 72` · `--quitar 72` · `--listar` · `--retirar`. Cada cambio queda en `/var/backups/cervesur-react-admin/autorizaciones-informes.log` (fecha, quién, acción e ids; nunca enlaces). Solo con aprobación del responsable de los datos (`MATRIZ-AUTORIZACION-ENLACES.md`).

## 11. Publicación corporativa por HTTPS (opcional, requiere aprobación de cada cambio)

Ver `PUBLICACION-CORPORATIVA-HTTPS.md`: `scripts/diagnostico-red-corporativa.sh` (solo lectura) → `scripts/corporativo.sh preflight|instalar|verificar|revertir`. Necesita un paquete compilado con `--host=<nombre.interno>` para que la aplicación admita ese nombre.
