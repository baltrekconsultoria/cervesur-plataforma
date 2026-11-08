# Propuesta: acceso HTTPS desde la red corporativa / VPN (UX-07D.5, bloque E)

Estado: **solo propuesta. No se ejecutó ningún cambio de red ni de Apache.** Hoy el piloto escucha únicamente en `127.0.0.1:8092` y se usa por túnel SSH. Esta propuesta requiere **aprobación específica** de quien administra la red y el servidor antes de cada paso marcado con ▲.

## 1. Alcance y exclusiones

* Se mantiene: Flutter en el puerto 80 sin cambios, 8088–8091 intactos, base `cervesur_portalbi` en solo lectura.
* No se abre ningún puerto público. El destino es **solo red corporativa o VPN**.
* No se sustituye Flutter en esta fase.

## 2. Diseño recomendado

```
Empleado (VPN / LAN) ──HTTPS 443──> IP privada del servidor ──> Apache: VirtualHost informes.<dominio> ──> mismo backend y misma configuración común
```

| Elemento | Propuesta |
|---|---|
| Nombre | Un nombre propio, p. ej. `informes.<dominio-corporativo>`, resuelto **solo por DNS interno** hacia la IP privada del servidor (▲ DNS). |
| Certificado | Una de dos, con el nombre completo y la cadena completa (▲ emisión): **(a)** CA corporativa instalada en los equipos (la opción más simple en un dominio gestionado); **(b)** certificado de CA pública obtenido con desafío DNS-01 (no exige abrir el puerto 80 ni 443 a Internet). Clave privada solo en el servidor (`root:root 600`), renovación automática o recordatorio. **No** se reutiliza el certificado autofirmado de `localhost`. |
| Escucha | Un `Listen <IP-privada>:443` **específico de la interfaz** (nunca `0.0.0.0`). Antes hay que resolver el efecto ya medido de habilitar `mod_ssl`: Apache pasa a escuchar en `0.0.0.0:443` con la página por defecto (ver `HTTPS-PILOTO-USUARIOS.md`). La decisión de qué interfaz y qué puerto es del administrador de red (▲). |
| VirtualHost | Mismo esquema que `docs/HTTPS-PILOTO-USUARIOS.md` §4: `ServerName` = el nombre propio, `SSLProtocol -all +TLSv1.2 +TLSv1.3`, y el **mismo** `react-usuarios-common.conf` (borra `X-Forwarded-*`, `Forwarded`, `X-Real-IP`; solo GET/POST; sin cookies). (▲ cambio de Apache + recarga suave tras `configtest`). |
| Cortafuegos | Permitir TCP/443 **solo** desde el rango de la VPN o de la LAN autorizada; denegar el resto. Sin NAT hacia Internet (▲). |
| Compilación | La aplicación se compila con el nombre permitido (`CERVESUR_ALLOWED_HOSTS=informes.<dominio>`). El empaquetador hoy fija `localhost`; añadir un parámetro `--host` es un trabajo pendiente pequeño y local. |
| `.env` | `CERVESUR_PILOT_HSTS=SI` (el nombre es propio, ya procede HSTS). El límite por IP vuelve a un valor realista: con clientes que llegan desde su propia dirección de VPN, 20 fallos/15 min; si la VPN hace NAT a una sola dirección, mantener un tope holgado. |
| Alternativa con proxy | Si un proxy inverso termina TLS, debe **sobrescribir** `X-Forwarded-Proto: https`, descartar el del cliente y conectar al backend solo por loopback. El backend sigue creyendo esa cabecera únicamente si la conexión viene de loopback. |

## 3. Requisitos previos

1. Aprobación del administrador de red (DNS, cortafuegos, VPN) y del titular del servidor.
2. Decisión escrita sobre **enlaces «Publicar en la web»** (`POWERBI-PUBLICAR-WEB-RIESGO.md`): abrir el acceso a más equipos aumenta la exposición.
3. Lista nominal de personas y revisión de la auditoría diaria durante el piloto.
4. Plan de contraseñas: hoy se usa la contraseña de Flutter; no hay recuperación en el portal.
5. Copia de seguridad programada de la base de estado.

## 4. Pasos (cada ▲ requiere aprobación; ninguno se ha ejecutado)

1. ▲ Registrar el nombre en el DNS interno.
2. ▲ Emitir el certificado y entregarlo al administrador del servidor.
3. ▲ Habilitar `mod_ssl` si falta, con `Listen <IP-privada>:443` y comprobando con `ss -ltn` que **no** queda `0.0.0.0:443`.
4. Preparar el paquete con el nombre permitido y verificar su `SHA256SUMS` (local, sin riesgo).
5. ▲ Instalar el VirtualHost, `apache2ctl configtest`, recarga suave.
6. ▲ Regla de cortafuegos acotada a la VPN.
7. Verificación (solo lectura): `verificar.sh` adaptado al nombre, más la matriz de `CIERRE-MVP-REACT-8092.md` (HTTP plano rechazado, `X-Forwarded-Proto` falsificado inútil, HSTS presente, TLS ≥ 1.2, 8088–8091 y Flutter idénticos).
8. Piloto con la lista nominal.

## 5. Reversión

Quitar el VirtualHost (`a2dissite`), la regla de cortafuegos y el `Listen` añadido; recarga suave. El piloto por túnel sigue funcionando porque su sitio y su configuración no se tocan. El certificado y la clave del nuevo nombre se destruyen aparte.

## 6. Riesgos específicos

* **Más superficie:** cualquier equipo de la VPN llega al login. Mitigación: lista blanca, bloqueo por intentos, auditoría diaria.
* **Bloqueo por cuenta** (5 fallos/15 min) permite que alguien bloquee a otra persona 15 minutos.
* **Certificado vencido** deja el portal inaccesible (falla cerrado). Debe haber alerta de vencimiento.
* **Enlaces públicos de Power BI** no cambian con esta propuesta.
