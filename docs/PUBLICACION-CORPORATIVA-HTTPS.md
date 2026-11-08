# Publicación corporativa por HTTPS del portal React (UX-07E.C, bloque D)

Estado: **preparada y ensayada en un entorno de prueba aislado; NO ejecutada.** La investigación del servidor real quedó pendiente porque no hay acceso a la VPS desde aquí: se entrega un script de **solo lectura** para que el operador la haga (§2). Nada de lo siguiente modifica Apache, DNS, cortafuegos ni certificados sin una aprobación expresa de cada cambio (▲).

## 1. Qué se propone

| Elemento | Propuesta | Confirmación necesaria |
|---|---|---|
| **Hostname** | `informes.<zona-interna-de-Cervesur>` (un solo nombre para el portal React; Flutter mantiene el suyo). Evitar `.local` (conflicto con mDNS), nombres con guiones bajos y cualquier nombre ya usado por Flutter. | ▲ TI confirma la zona interna y el nombre final |
| **DNS** | Registro `A` interno `informes.<zona>` → IP **privada** del servidor (hoy `192.168.200.12` es la del servidor según el operador). Solo en el DNS interno; sin registro público. | ▲ administrador de red |
| **Certificado** | Emitido por la **autoridad certificadora corporativa** (la que ya confían los equipos del dominio) o, si no existe, una CA pública con validación DNS-01 (no exige abrir puertos). SAN = exactamente el hostname; EKU `serverAuth`; clave EC P-256 o RSA ≥ 2048; **fullchain** (hoja + intermedios); vigencia ≤ 1 año con renovación y responsable definidos. **No** el autofirmado de `localhost`: no hay que importar nada PC por PC. | ▲ TI emite; ▲ nombra al responsable de renovar |
| **Puerto** | 443 **si está libre o lo atiende Apache**; si otro proceso lo usa, otro puerto acordado. **No se asume**: el preflight lo inspecciona y se detiene si lo ocupa algo distinto de Apache. | Resultado del diagnóstico |
| **Publicación** | Un `VirtualHost <IP-privada>:443` más en el mismo Apache (mismo backend y configuración común que el sitio de loopback); `ServerName` = el hostname; TLS 1.2/1.3 solo; HSTS activo **solo** con ese nombre (nunca para `localhost`); HTTP plano a ese puerto rechazado (400); Flutter (80) y 8088–8093 sin cambios. | ▲ cambio de Apache aprobado |
| **Red** | Regla de cortafuegos: permitir TCP/443 **solo desde la LAN/VPN** corporativa al servidor; sin NAT ni publicación a Internet. | ▲ administrador de red |
| **Quién entra** | Los mismos controles: cuenta de Cervesur, rol y lista propia de la instancia (administrador) o lista blanca (trabajadores). Publicar el nombre **no** concede acceso a nadie. | — |
| **Compatibilidad** | La aplicación se compila con el nombre permitido (`empaquetar.mjs --host=informes.<zona>`, además de `localhost`); el backend y el frontend actuales funcionan sin cambios de código. | — |

## 2. Investigación en solo lectura (a ejecutar por el operador)

```
sudo bash scripts/diagnostico-red-corporativa.sh informes.<zona-candidata> > diagnostico-red.txt
```

(se entrega en `deploy/tunel-ssh-piloto-admin/corporativo/`; el script **no escribe, no recarga, no abre puertos y no lee ninguna clave privada**). Informa: interfaces e IP, puerta de enlace, versión y módulos de Apache, VirtualHosts y `Listen`, puertos en escucha y quién los usa (en particular el 443), servidores DNS y dominio de búsqueda, si el DNS ya resuelve el nombre candidato, certificados públicos presentes (sujeto, emisor, vencimiento), CA corporativa instalada, estado del cortafuegos y que MySQL escuche solo en loopback. Con ese archivo se decide nombre, IP, puerto y certificado.

## 3. Scripts reproducibles (incluidos en el paquete administrativo: `scripts/corporativo.sh`)

```
# 1) solo lectura: comprueba TODO y no escribe nada
sudo bash scripts/corporativo.sh preflight --host informes.<zona> --ip <IP-privada> [--puerto 443] --cert fullchain.pem --clave clave.pem
# 2) publica (pide escribir INSTALAR-CORP): respaldo de /etc/apache2, VirtualHost nuevo, HSTS, recarga SUAVE, verificación; si algo falla, vuelve solo
sudo bash scripts/corporativo.sh instalar  (mismos argumentos)
# 3) comprobaciones de solo lectura de lo instalado
sudo bash scripts/corporativo.sh verificar (mismos argumentos)
# 4) rollback: retira SOLO el VirtualHost corporativo, su certificado y HSTS (pide REVERTIR-CORP)
sudo bash scripts/corporativo.sh revertir
```

**El preflight rechaza** (código 1, sin tocar nada): instancia no instalada o caída, nombre que no sea un dominio completo, IP que no sea privada o que no pertenezca al servidor, puerto reservado (80, 8088–8093), puerto ocupado por un proceso que no sea Apache, otro VirtualHost en esa IP:puerto, módulos `ssl`/`headers` ausentes, certificado de otro nombre, **autofirmado**, con menos de 30 días, sin `serverAuth`, clave que no corresponde, o aplicación que no admite el nombre. Avisa si el archivo no trae la cadena completa y si el DNS aún no resuelve.

**La verificación** valida con el almacén de confianza **del sistema** (sin `-k` ni certificados importados a mano): cadena y nombre, TLS 1.2/1.3 solamente, HSTS en aplicación y API, HTTP plano rechazado y `X-Forwarded-Proto` falsificado inútil, 401/403 sin sesión, sin cookies, CSP, vigencia del certificado, clave root 600, acceso por túnel intacto y **sin HSTS**, y que Flutter, 8088–8092 y el resto del servidor son idénticos a antes.

Garantías: no abre Internet (rechaza IP públicas), no toca DNS ni cortafuegos, no habilita módulos, no reinicia Apache (recarga suave tras `configtest`), la clave privada nunca viaja en el paquete (root:root 600 en `/etc/apache2/cervesur-react-admin/tls-corp`), y el rollback restablece el `.env` bit a bit.

## 4. Orden de ejecución propuesto (cada ▲ requiere aprobación)

1. Diagnóstico de solo lectura (§2) → decisión de nombre, IP y puerto.
2. ▲ Registro DNS interno y emisión del certificado (fullchain + clave) por TI.
3. Compilar y verificar un paquete con `--host=informes.<zona>` (local, sin riesgo) y actualizar el 8093 con `actualizar.sh` (reversible).
4. `corporativo.sh preflight` (solo lectura). Con PASS:
5. ▲ Ventana de cambio aprobada → `corporativo.sh instalar`.
6. ▲ Regla de cortafuegos acotada a la LAN/VPN.
7. QA manual desde un equipo corporativo **sin importar nada**: el navegador debe mostrar el candado sin advertencias.
8. Revertir si algo falla: `corporativo.sh revertir`.

## 5. Evidencia del ensayo (entorno aislado, no el servidor real)

Con una CA de prueba y un certificado para `informes.cervesur.test` sobre una IP privada del contenedor: preflight rechaza certificado de otro nombre, autofirmado, a punto de vencer, clave que no corresponde, IP pública, `localhost`, puerto reservado y puerto ocupado por otro proceso; con los datos correctos pasa; sin confirmación escrita no instala; instala sin reiniciar Apache; la verificación pasa con el almacén del sistema; el administrador inicia sesión por el nombre corporativo con un navegador real (la aplicación admite el host); el túnel sigue sin HSTS; y el rollback deja el servidor y el `.env` idénticos a antes. Las cifras están en el informe de la entrega.

## 6. Riesgos y límites

* **Más superficie:** cualquier equipo de la LAN/VPN llega a la pantalla de acceso. Mitigación: lista de la instancia, bloqueo por intentos, auditoría diaria, cortafuegos acotado.
* **Un certificado vencido** deja el portal inaccesible (falla cerrado); definir alerta de vencimiento (el preflight avisa si quedan menos de 30 días).
* **Enlaces públicos de Power BI** no cambian con esta publicación (`MATRIZ-AUTORIZACION-ENLACES.md`).
* El límite por IP: con clientes en la misma red cada uno llega con su IP; si una VPN hace NAT a una sola dirección, mantener el tope holgado.
* Habilitar `mod_ssl` (si falta) abre `0.0.0.0:443` con la página por defecto (`HTTPS-PILOTO-USUARIOS.md`): resolverlo antes con el administrador.
* No se verificó nada del servidor real: DNS, CA, cortafuegos y puerto 443 siguen **pendientes** de confirmar con el diagnóstico.
