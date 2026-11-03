# Instalación del diagnóstico agregado de solo lectura (127.0.0.1:8090) · UX-07D.3B

Entorno nuevo `cervesur-react-ro`: **una API técnica** que devuelve únicamente recuentos agregados de los datos de `cervesur_portalbi`, leídos con la cuenta MySQL de solo lectura `cervesur_ro_diag`. No publica la aplicación React, no tiene inicio de sesión y no ofrece catálogos, usuarios, empresas, informes ni visores Power BI.
**Esta guía describe cómo se instalará; nada se ha desplegado.** El paquete se ensayó en Docker (ver el informe de la etapa).

## Qué se instala (y qué no)
| Recurso | Valor |
|---|---|
| Sitio Apache | `cervesur-react-ro` → `/etc/apache2/sites-available/cervesur-react-ro.conf`, configuración común en `/etc/apache2/cervesur-react-ro/` |
| Escucha | **solo `127.0.0.1:8090`**; desde Windows por túnel SSH a `localhost:18091` |
| Backend + `.env` | `/opt/cervesur-react-ro/backend` (`.env` 640 `root:www-data`; contiene el token y la clave de la cuenta) |
| DocumentRoot | `/var/lib/cervesur-react-ro/docroot`, **vacío**; TODA petición se entrega al router PHP |
| Sesiones PHP | `/var/lib/cervesur-react-ro/sessions` (700 `www-data`; cookie `CERVESUR_REACT_RO`; el modo diagnóstico no abre ninguna) |
| Respaldos | `/var/backups/cervesur-react-ro` (700; **nunca se borran**) |
| MySQL | **no se crea, modifica ni elimina nada**: ni bases, ni cuentas, ni privilegios |
| No se toca | Flutter (80), React 8088, React 8089, módulos de Apache, `mod_headers`, la configuración global, el reinicio de Apache (solo recarga suave tras `configtest`) |

Endpoints (todos `GET`): `/api/ping` y, con la cabecera `X-Diagnostic-Token`, `/api/diagnostico/resumen` y `/api/diagnostico/conciliacion`. Cualquier otra ruta, método o variante recibe 403.

## Orden obligatorio
1. **Preflight A** (infraestructura, no necesita la cuenta): `sudo bash scripts/preflight-infra.sh`.
2. **Crear la cuenta `cervesur_ro_diag`** con el procedimiento administrativo independiente (sección siguiente). Lo hace un administrador **autorizado**; el instalador no lo hace.
3. **Preflight B** (acceso): `sudo bash scripts/preflight-acceso.sh --clave-archivo /root/cervesur-ro-diag.clave`. Códigos: `0` PASS · `1` FAIL · **`3` PENDIENTE** (no hay cuenta o su clave: **nunca** se declara PASS).
4. **Simulación**: `sudo bash scripts/instalar.sh --simular --clave-archivo /root/cervesur-ro-diag.clave` (no cambia nada; `0` = se puede instalar, `3` = acceso pendiente).
5. **Instalación**: `sudo bash scripts/instalar.sh --clave-archivo /root/cervesur-ro-diag.clave` (pide escribir `INSTALAR-8090`). Si algo falla se revierte automáticamente solo lo creado.
6. **Verificación**: `sudo bash scripts/verificar.sh` (también se ejecuta al final de la instalación).

## Transferir e instalar (sustituya `<paquete>` por el nombre real)
```
scp cervesur-react-ro-<…>.tar.gz cervesur-react-ro-<…>.tar.gz.sha256 usr_cervesur@<servidor>:~/
ssh usr_cervesur@<servidor>
sha256sum -c cervesur-react-ro-<…>.tar.gz.sha256        # debe decir OK; compárelo además con el SHA-256 informado por el equipo
tar xzf cervesur-react-ro-<…>.tar.gz && cd cervesur-react-ro-<…>
sudo bash scripts/preflight-infra.sh
```
## Procedimiento separado: crear `cervesur_ro_diag` (administrador autorizado)
```
sudo bash admin-mysql/preparar-sql-cuenta.sh            # genera /root/cervesur-ro-diag.sql y .clave (600); NO se conecta a MySQL ni muestra la clave
sudo bash admin-mysql/mostrar-sql-redactado.sh /root/cervesur-ro-diag.sql   # REVISIÓN HUMANA con la clave REDACTADA: 1 CREATE USER y 6 GRANT SELECT por columna (18 columnas = 4+2+2+3+4+3); nada más. NO use cat/less/sed sobre el .sql: mostrarían la clave
sudo mysql < /root/cervesur-ro-diag.sql                  # solo tras la autorización explícita
sudo mysql < admin-mysql/02-verificar-privilegios-diagnostico.sql    # privilegios efectivos SOLO de la cuenta de diagnóstico (no falla aunque no exista el piloto)
```
El SQL que ejecutará la propia cuenta desde el backend (catálogo cerrado de 31 sentencias) está en `admin-mysql/operador/UX-07D3A1-consultas-cuenta-diagnostica.sql` para su revisión. Al terminar la instalación, destruya `/root/cervesur-ro-diag.sql` y `.clave` (la clave queda solo en el `.env` del sitio).

## Uso por túnel SSH
```
ssh -N -L 18091:127.0.0.1:8090 usr_cervesur@<servidor>          # en Windows; deje la ventana abierta
curl -H "X-Diagnostic-Token: <token>" http://localhost:18091/api/diagnostico/resumen
```
El token se obtiene en el servidor con `sudo bash scripts/token.sh --mostrar` y **nunca** va en la URL. Conciliación con la línea base del inventario: `sudo bash scripts/conciliar.sh` (solo lectura; clasifica las diferencias sin corregir nada).

## Qué NO hace este entorno
Sin inicio de sesión de usuarios reales, sin JWT, sin hashes, sin enlaces de Power BI, sin datos personales, sin SQL arbitrario (catálogo cerrado), sin acceso desde la red corporativa. La ausencia de filas en `performance_schema` **no** demuestra por sí sola que no hubo escrituras: ver `database/schema/admin-requiere-autorizacion/04-verificar-ausencia-de-escrituras.sql`.
