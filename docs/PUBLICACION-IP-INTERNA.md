# Publicación de los portales React por IP interna, sin DNS ni túneles (UX-07E.D)

Estado: **preparada y ensayada en un entorno aislado; NO ejecutada en el servidor.** Todo lo de abajo está listo para que el operador lo aplique desde PuTTY en una sola intervención, después de aprobar la configuración y las restricciones de red. Nada se ha tocado en la VPS.

## 1. Arquitectura definitiva

```
Trabajadores (LAN / Wi-Fi corporativa)   ──HTTPS──>  https://192.168.200.12        (443)  ──> Apache VirtualHost "cervesur-react-usuarios-corp"  ──> misma aplicación y backend del 8092
Administrador (LAN / VPN)                ──HTTPS──>  https://192.168.200.12:8443   (8443) ──> Apache VirtualHost "cervesur-react-admin-corp"     ──> misma aplicación y backend del 8093
Flutter (sin cambios)                    ──HTTP───>  http://192.168.200.12          (80)
Operador técnico (túnel SSH, QA)         ──────────>  127.0.0.1:8092 / 127.0.0.1:8093 (siguen como hoy, para QA)
```

| Elemento | Decisión |
|---|---|
| Dirección | IP privada `192.168.200.12`; **sin DNS**. El navegador escribe la IP; el certificado lleva esa IP en el SAN (tipo *IP Address*). |
| Puertos | Trabajadores **443**; administrador **8443**. Se **inspeccionan**, no se asumen: el preflight se detiene si otro proceso que no sea Apache los usa. |
| Escucha | `Listen 192.168.200.12:443` / `:8443` (solo esa IP, nunca `0.0.0.0`). Si Apache ya atiende el 443 se agrega un VirtualHost sobre su `Listen` existente. |
| Quién entra | Los mismos controles: cuenta de Cervesur individual, rol separado (trabajador / administrador), listas independientes (lista blanca de trabajadores; lista propia del administrador), informes asignados validados en el servidor, administración **de solo lectura**, auditoría y revocación de sesiones. Publicar una dirección **no concede acceso a nadie**. |
| Restricción de red | **Obligatoria** (`--redes`): solo las redes cliente indicadas (LAN/Wi-Fi corporativa y VPN) llegan a los portales; cualquier otra dirección recibe **403 de Apache** en toda ruta y método, antes de PHP (directiva `Require ip`, sobre la dirección real de la conexión; los `X-Forwarded-*` se descartan). |
| TLS | Solo 1.2/1.3; HTTP plano a esos puertos → 400; sin cookies; CSP con `frame-src https://app.powerbi.com`; **sin HSTS** (los navegadores lo ignoran en una IP; no se simula una garantía que no existe). No se desactiva ninguna validación del navegador. |
| Compatibilidad | La aplicación se compila admitiendo `localhost` y la IP (`--host=192.168.200.12`); rutas, API (`/api` del mismo origen), sesiones por cabecera `Authorization`, CORS inexistente (mismo origen) y cierre de sesión no cambian. |
| Lo que NO cambia | Flutter (80), MySQL (solo `127.0.0.1:3306`), cuentas y bases, contraseñas, asignaciones, listas de enlaces de Power BI, los túneles de QA. |

## 2. Certificado para una IP privada (sin CA corporativa conocida)

Los certificados de las autoridades públicas **no se emiten para IP privadas**, así que se usa una **CA privada de Cervesur** de alcance mínimo y se distribuye su certificado (público) a los equipos autorizados. El script `scripts/emitir-certificado-ip.sh` (se ejecuta en un equipo de confianza del operador, **no en el servidor**; Linux, WSL o Git Bash con OpenSSL):

```
bash emitir-certificado-ip.sh --ip 192.168.200.12 --red 192.168.200.0/24 --red <red-VPN>/24 --salida ./pki-cervesur
```

| Archivo | Para qué | Dónde va |
|---|---|---|
| `ca/ca.key` | Clave de la CA, **cifrada con frase de paso** | **Solo** el equipo del operador (USB cifrado, fuera de línea). Nunca al servidor, nunca por correo. |
| `ca/ca.crt` | Certificado público de la CA | A los equipos de los usuarios (§3). |
| `servidor/fullchain.pem` + `servidor/servidor.key` | Certificado de la IP y su clave | Al servidor (el script de publicación los copia a `/etc/apache2/…/tls-corp`, `root:root 600`). |
| `LEEME-confianza.txt` | Huella SHA-256 de la CA y comandos de instalación | Para verificar la CA al instalarla. |

**Salvaguardas de la CA** (verificadas en el ensayo): *name constraints* críticas que solo permiten **direcciones IP de las redes indicadas** (y un dominio `.invalid` inexistente para nombres), `pathlen:0` (no crea otras CA), validez de 5 años. Aunque alguien robara su clave, un navegador **rechazaría** certificados de la CA para cualquier otra IP o nombre (p. ej. `www.google.com`). El certificado del servidor dura 397 días, tipo *serverAuth*, `CA:FALSE`, clave EC P-256. No se reutilizan los certificados autofirmados de los pilotos.

## 3. Distribución de confianza a los equipos autorizados

1. **Verificar la huella** de `ca.crt` (la imprime el emisor y está en `LEEME-confianza.txt`) contra lo que se instala.
2. **Windows (equipo de trabajo):** como administrador, `certutil -addstore -f Root ca\ca.crt` (almacén del equipo), o `certutil -user -addstore Root ca\ca.crt` (solo el usuario). Brave, Chrome y Edge usan ese almacén.
3. **Muchos equipos de la empresa:** directiva de grupo (GPO) *Configuración del equipo ▸ Directivas ▸ Configuración de Windows ▸ Configuración de seguridad ▸ Directivas de clave pública ▸ Entidades de certificación raíz de confianza* (o Intune). Es la forma recomendada: se hace una vez.
4. **Firefox:** `security.enterprise_roots.enabled = true` (usa el almacén de Windows).
5. **Android/iOS:** instalar `ca.crt` como CA y habilitar su confianza (iOS: Ajustes ▸ General ▸ Información ▸ Confianza de certificados).
6. **Nunca** pedir a un usuario que «continúe de todas formas» ante una advertencia: si aparece, algo está mal (CA no instalada, certificado vencido o IP distinta).

## 4. Renovación (cada año)

Se avisa por el preflight cuando quedan menos de 30 días. En el equipo del operador: repetir `emitir-certificado-ip.sh` con la **misma carpeta de salida** (reutiliza la CA; pide la frase de paso) y luego, en el servidor, para cada portal:
```
sudo bash scripts/corporativo.sh renovar --ip 192.168.200.12 --redes "…" --cert fullchain.pem --clave servidor.key     # escribir RENOVAR-CORP
```
Cambia **solo** el certificado y la clave, verifica, recarga suave y, si algo falla, restaura el anterior (guardado en `/var/backups/<sitio>/tls-corp-anterior-*`, root 700). La CA no cambia: **los equipos no se tocan**. La CA vence a los 5 años: planificar su rotación (nuevo `ca.crt` a los equipos con meses de antelación).

## 5. Procedimiento de publicación en UNA sola intervención (PuTTY)

Requisitos previos (el operador): (a) el administrador de red confirma que el 443 y el 8443 de `192.168.200.12` se pueden usar y **no hay NAT ni reenvío hacia Internet** hacia esta IP; (b) las redes cliente (LAN/Wi-Fi y VPN) en CIDR; (c) la CA instalada en los equipos de prueba; (d) los dos paquetes y los archivos de certificado transferidos al servidor.

```
# 0) Verificar hashes (local y en el servidor), extraer cada paquete en su carpeta, y revisar el diagnóstico de red (solo lectura)
sha256sum -c cervesur-react-usuarios-…tar.gz.sha256 ; sha256sum -c cervesur-react-admin-…tar.gz.sha256
tar xzf cervesur-react-usuarios-…tar.gz ; tar xzf cervesur-react-admin-…tar.gz
sudo bash cervesur-react-usuarios-…/scripts/diagnostico-red-corporativa.sh > diagnostico-red.txt

# 1) Simulación completa (no escribe nada): paquetes, actualizaciones y preflights de ambos portales
sudo bash cervesur-react-usuarios-…/scripts/publicar-ip.sh --simular \
   --trabajadores ./cervesur-react-usuarios-… --administrador ./cervesur-react-admin-… \
   --ip 192.168.200.12 --redes "192.168.200.0/24 <red-VPN>/24" --cert ./fullchain.pem --clave ./servidor.key

# 2) Publicar (escribir PUBLICAR-IP): actualiza los dos portales, publica el 443 y el 8443, verifica, y si algo falla vuelve TODO a como estaba
sudo bash cervesur-react-usuarios-…/scripts/publicar-ip.sh (mismos argumentos, sin --simular)

# 3) Destruir en el servidor la copia local de la clave privada (queda solo en /etc/apache2/…/tls-corp) y comprobar
shred -u ./servidor.key
sudo bash cervesur-react-usuarios-…/scripts/corporativo.sh verificar --ip 192.168.200.12 --redes "…" --cert ./fullchain.pem --clave ./servidor.key   # (antes de destruir la clave)
```
**Rollback completo:** `sudo bash …/scripts/publicar-ip.sh revertir --trabajadores … --administrador … --ip 192.168.200.12 --respaldos "<resp-trab> <resp-admin>"` (la salida de la publicación imprime la línea exacta; pide `REVERTIR-IP`). Retira los dos VirtualHost y certificados y restaura las versiones anteriores de ambos portales; el acceso por túnel sigue como antes. Por separado: `corporativo.sh revertir` (un portal) y `actualizar.sh --revertir <respaldo>`.

**Qué hace por dentro:** (1) verifica `SHA256SUMS` de ambos paquetes; (2) simula `actualizar.sh` de cada portal y ejecuta `corporativo.sh preflight --previo` (certificado con la IP en el SAN, no autofirmado ni de CA, vigente ≥ 30 días, clave que corresponde, IP privada del servidor, redes privadas válidas, puertos libres o de Apache, sin otro VirtualHost, `mod_ssl`/`mod_headers`, la aplicación admite la IP); (3) pide `PUBLICAR-IP`; (4) actualiza trabajadores y luego administrador (respaldo y verificación propios); (5) publica trabajadores y luego administrador (respaldo de `/etc/apache2`, recarga suave tras `configtest`, verificación que incluye que Flutter y el resto del servidor son idénticos a antes). No reinicia Apache, no toca Flutter, MySQL, DNS ni el cortafuegos, no cambia contraseñas, usuarios, permisos ni listas de enlaces.

## 6. Cortafuegos y exposición (decisión del administrador de red)

UFW está **inactivo** hoy. La restricción que esta instalación sí controla es la de Apache (`Require ip`). Como refuerzo recomendado —no aplicado aquí por el riesgo de quedarse sin acceso SSH— el administrador de red puede limitar el acceso a nivel de red/servidor: permitir TCP 443 y 8443 hacia `192.168.200.12` solo desde la LAN/Wi-Fi y la VPN; SSH solo desde la VPN; y **verificar en el router/cortafuegos perimetral que no existe NAT ni reenvío de puertos** desde Internet hacia esa IP (prueba desde fuera de la red: el 443 y el 8443 no deben responder). Esa comprobación de perímetro es del operador: no se puede hacer desde el servidor.

## 7. Riesgos y condiciones pendientes

1. **Enlaces «Publicar en la web»** (`POWERBI-PUBLICAR-WEB-RIESGO.md`): publicar por IP **no resuelve** el riesgo; sigue sin autorizarse ningún enlace sin aprobación del responsable de los datos (`MATRIZ-AUTORIZACION-ENLACES.md`).
2. **La confianza es de cada equipo:** un equipo sin la CA verá una advertencia (correcto: no hay que saltársela). Un equipo que sí la tiene confía en una CA restringida a las redes de Cervesur.
3. **Clave de la CA:** si se pierde, hay que rotar la CA y redistribuir; si se filtra, las *name constraints* limitan el daño a esas IP, pero igual hay que rotar.
4. **Límite de intentos por dirección:** hoy 100 (pensado para el túnel, donde todo llega desde 127.0.0.1). Con clientes en la LAN cada uno llega con su IP; **si la Wi-Fi o la VPN hacen NAT a una sola dirección**, mantener un tope holgado. Revisar con los registros de acceso de Apache qué direcciones ven los portales.
5. **Mayor superficie:** cualquier equipo de las redes autorizadas llega a la pantalla de acceso. Mitigación: listas independientes, bloqueo por intentos, auditoría diaria, restricción por red.
6. **Administración sin escritura:** las altas/ediciones/asignaciones siguen sin habilitarse en producción (`PILOTO-ADMIN-UX-07EB.md` §5).
7. **Pendiente de verificar en el servidor real:** que las redes cliente indicadas sean las correctas, que el 443/8443 sigan libres (el preflight lo vuelve a comprobar), la ausencia de NAT/Internet y que el servidor vea la IP real de cada cliente.
8. **Cierre de sesión:** el token vive solo en memoria de la pestaña; recargar la página cierra la sesión (comportamiento deliberado).
