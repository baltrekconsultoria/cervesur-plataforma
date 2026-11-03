# HTTPS del piloto con usuarios reales (UX-07D.4B.1)

Regla: **no se acepta una contraseña antes de tener TLS real.** El backend rechaza con 403 `https_requerido` toda petición que no llegue cifrada, y además el propio Apache no sirve HTTP en el puerto del piloto.

## 1. Qué se inspeccionó y qué falta verificar

Solo se revisó la configuración **local** del repositorio y la réplica de ensayo (Ubuntu 24.04 + Apache 2.4 + mod_php 8.3 + MySQL 8). No hubo acceso a la VPS.

| Dato | Estado | Cómo comprobarlo en el servidor (solo lectura) |
|---|---|---|
| `mod_ssl` cargado | **Desconocido.** El patrón anterior (8088) habilitó `mod_headers`; de `mod_ssl` no hay constancia. El instalador **no lo habilita**: el preflight A falla si no está. | `apache2ctl -M \| grep ssl_module` |
| Quién escucha en 443 | **Desconocido.** Flutter está en el 80 (HTTP). | `ss -ltnH 'sport = :443'` |
| Nombre DNS / dominio corporativo para el piloto | **Falta definirlo** (solo es necesario para la opción B). | — |
| Autoridad certificadora disponible (CA corporativa, Let's Encrypt) | **Falta decidirlo** (opción B). | — |
| Versión de OpenSSL (necesita 1.1.1+ para `-addext`) | Se espera 3.0 en Ubuntu 24.04. | `openssl version` |
| Reglas de cortafuegos / VPN para un acceso de varios usuarios | **Falta** (opción B). | — |
| PHP expuesto directamente (php-fpm/CGI en red) | Se espera que no: mod_php dentro de Apache. | `ss -ltnp` |

### Efecto colateral de habilitar `mod_ssl` (medido en el ensayo; decisión del administrador, no del instalador)

Si `mod_ssl` no está cargado, un administrador debe habilitarlo (`a2enmod ssl`) **antes** de instalar. En la réplica de ensayo eso tuvo estos efectos: Apache pasa a escuchar en **`0.0.0.0:443`** (por `ports.conf`), y ese puerto, sin ningún VirtualHost TLS, responde **por HTTP plano con la página por defecto de Apache** (`/var/www/html`); por HTTPS el apretón TLS falla. No sirve ni a Flutter ni al piloto, pero es un puerto nuevo abierto a la red. Antes de habilitarlo, el administrador debe decidir cómo tratarlo (por ejemplo `Listen 127.0.0.1:443` en `ports.conf`, una regla de cortafuegos, o servir allí un VirtualHost explícito). El instalador **no** hace nada de esto y su verificación solo comprueba que el piloto y Flutter no se sirven por ese puerto.

## 2. Reglas que cumple el diseño (ambas opciones)

* **TLS en el propio socket** (`SSLEngine on`, `SSLProtocol -all +TLSv1.2 +TLSv1.3`). Una petición HTTP a ese puerto recibe «400» de Apache y nunca llega a PHP. No existe VirtualHost HTTP del piloto ni variable que permita el login por HTTP (`CERVESUR_INSECURE_TESTS` está prohibido con la base productiva y el instalador jamás la escribe).
* **El cliente no decide si la conexión es segura.** El backend sólo confía en la variable `HTTPS` de mod_ssl o en `X-Forwarded-Proto` **únicamente** cuando la conexión viene de loopback. El vhost, además, **elimina** `X-Forwarded-Proto`, `X-Forwarded-For/Host/Port`, `Forwarded`, `X-Real-IP`, `Front-End-Https` y `X-Url-Scheme` de toda petición (`RequestHeader unset`). Si algún día se pone un proxy delante, ese proxy debe **sobrescribir** (no añadir) `X-Forwarded-Proto` y ser el único origen en loopback.
* **Backend aislado en loopback:** `Listen 127.0.0.1:8092`; MySQL por `127.0.0.1`; la clave privada TLS en `/etc/apache2/cervesur-react-usuarios/tls` (root:root 600), fuera del DocumentRoot y del paquete.
* **Cookies:** el piloto no emite ninguna (token opaco en la cabecera `Authorization`). Como red de seguridad, `php_admin_flag session.cookie_secure/httponly on`, `SameSite=Strict` y `Header edit Set-Cookie` añaden `Secure` y `HttpOnly` a cualquier cookie que aparezca.
* **HSTS** no se emite en el acceso por túnel: el nombre es `localhost` y HSTS afecta a **todos** los puertos de ese nombre (obligaría al navegador a usar HTTPS en los túneles de 8088/8089/8090/8091). Se activa con `CERVESUR_PILOT_HSTS=SI` solo con un nombre propio (opción B).
* No se modifica Flutter, ni 8088/8089/8090/8091, ni módulos de Apache.

## 3. Opción A · pruebas privadas (túnel SSH + certificado autofirmado) — implementada

Para el equipo del operador, antes de abrir nada a más personas.

1. `sudo bash scripts/instalar.sh --claves-archivo … ` genera con `scripts/tls.sh generar` un certificado **autofirmado** (EC P-256, 365 días, `CN=localhost`, SAN `DNS:localhost, IP:127.0.0.1`, `CA:FALSE`, uso `serverAuth`). La clave privada nace y se queda en el servidor.
2. En el servidor: `sudo bash scripts/tls.sh huella` (huella SHA-256) y `sudo bash scripts/tls.sh publico > servidor.crt` (solo la parte pública).
3. Se copia `servidor.crt` al equipo del operador (`scp`) y se **comprueba la huella** antes de confiar.
4. Windows (usuario actual, sin permisos de administrador): `certutil -user -addstore Root servidor.crt` (o importarlo en «Entidades de certificación raíz de confianza» del usuario). Revocarlo al terminar el piloto (`certutil -user -delstore Root localhost`).
5. `ssh -N -L 18093:127.0.0.1:8092 <usuario>@<servidor>` y abrir `https://localhost:18093`.

Limitación: el certificado sirve solo para `localhost`. No es una opción para varias personas ni para equipos que no sean los del operador; además, importar un certificado raíz autofirmado en un equipo es una decisión de confianza que debe ser consciente y reversible.

## 4. Opción B · futuro acceso corporativo de varios usuarios — diseño (NO implementado ni instalable con este paquete)

Requisitos previos (todos pendientes de decisión): nombre DNS propio (p. ej. `informes.<dominio>`), certificado de una CA (corporativa o pública) con **ese nombre y cadena completa**, renovación automática, puerto 443 libre o un reverso dedicado, acceso restringido por VPN o lista de IP del cortafuegos, y autorización expresa para tocar la configuración global de Apache si hiciera falta (`Listen 443`, `mod_ssl`).

Esquema recomendado: **otro VirtualHost de Apache**, en el mismo servidor, con el mismo backend y la misma configuración común, y `ServerName` = el nombre corporativo:

```apache
<VirtualHost IP-DEL-SERVIDOR:443>
    ServerName informes.ejemplo.com
    SSLEngine on
    SSLCertificateFile    /etc/ssl/certs/informes.ejemplo.com.fullchain.pem
    SSLCertificateKeyFile /etc/ssl/private/informes.ejemplo.com.key   # root:root 600
    SSLProtocol -all +TLSv1.2 +TLSv1.3
    Define CERVESUR_USUARIOS_FRONT    /var/www/cervesur-react-usuarios/public
    Define CERVESUR_USUARIOS_BACKEND  /opt/cervesur-react-usuarios/backend
    Define CERVESUR_USUARIOS_SESSIONS /var/lib/cervesur-react-usuarios/sessions
    Include /etc/apache2/cervesur-react-usuarios/react-usuarios-common.conf
</VirtualHost>
```

y en el `.env`: `CERVESUR_PILOT_HSTS=SI`. La compilación del frontend debe declarar ese nombre (`CERVESUR_ALLOWED_HOSTS=informes.ejemplo.com`). Si en lugar de eso se usa un proxy inverso delante (nginx/Apache), debe: terminar TLS con el certificado real, **sobrescribir** `X-Forwarded-Proto: https` y `X-Forwarded-For`, descartar los mismos encabezados que vengan del cliente, conectar al backend **solo por loopback**, y el backend sigue exigiendo que la conexión provenga de loopback para creer esa cabecera.

Antes de abrirlo a más de 5 personas haría falta, además: política de contraseñas/bloqueo revisada, monitorización de la auditoría, copia de seguridad de la base de estado, y una prueba de carga mínima.

## 5. Qué verifica el paquete

`preflight-infra.sh` (mod_ssl cargado, puerto libre, openssl, y —si se aporta— vigencia/nombre/clave/permisos del certificado) y `verificar.sh` (certificado confiable para `localhost`, TLS 1.2/1.3 únicamente, HTTP plano rechazado, login por HTTP rechazado, `X-Forwarded-Proto` forjado inútil, sin cookies, sin HSTS en el túnel, permisos de la clave).
