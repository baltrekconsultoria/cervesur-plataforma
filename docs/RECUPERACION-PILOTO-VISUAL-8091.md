# Recuperación, rotación y reversión del piloto visual 8091 · UX-07D.4 express

## Reversión completa (solo lo creado por 8091)
```
sudo bash scripts/revertir.sh        # pide escribir REVERTIR-8091; idempotente; tolera instalaciones parciales
```
Elimina únicamente: el sitio `cervesur-react-piloto`, `/var/www/cervesur-react-piloto`, `/opt/cervesur-react-piloto` (backend, `.env`, secreto JWT y clave del operador), `/etc/apache2/cervesur-react-piloto` y `/var/lib/cervesur-react-piloto`; recarga Apache de forma suave tras `configtest`.
**No toca:** Flutter, 8088, 8089, 8090, los módulos de Apache, `/var/backups/cervesur-react-piloto` (los respaldos se conservan), **ni MySQL**: ni la base `cervesur_portalbi` ni ninguna cuenta (tampoco `cervesur_ro_visor`). Al terminar compara la huella de los demás portales con la línea base tomada antes de instalar.

## Retirar la cuenta MySQL (procedimiento administrativo aparte, con autorización)
`admin-mysql/99-revocar-cuenta-visor.sql` ejecuta una única sentencia, `DROP USER IF EXISTS 'cervesur_ro_visor'@'127.0.0.1'`: sin marcadores; no puede afectar a `cervesur_ro_diag`, a otro host ni a ninguna otra cuenta.

## Si falla la instalación
Revierte automáticamente lo creado y deja los respaldos. Revise `/var/backups/cervesur-react-piloto/linea-base-portales.txt`, repita el preflight y, si quedó algo a medias (corte de luz, SSH caído), ejecute `revertir.sh`.

## Clave del operador y sesiones
| Necesidad | Comando | Efecto |
|---|---|---|
| Olvidó la clave | `sudo bash scripts/operador.sh --mostrar` | imprime la clave una vez (pide `CLAVE-OPERADOR`) |
| Sospecha de filtración de la clave | `sudo bash scripts/operador.sh --rotar-clave` | clave y secreto JWT nuevos: la anterior y todas las sesiones dejan de valer al instante |
| Cerrar todas las sesiones abiertas | `sudo bash scripts/operador.sh --revocar-sesiones` | rota el secreto JWT |
| Operador bloqueado (5 fallos) | `sudo bash scripts/operador.sh --desbloquear` | levanta el bloqueo de 15 min |
Ninguno recarga Apache. Cerrar sesión en el navegador borra el token de la memoria, pero un token ya emitido vale hasta que expire (1 h) o se rote el secreto.

## Informes cuyo enlace se muestra
`sudo bash scripts/informes.sh --listar | --autorizar <ids|TODOS> | --retirar`. **Retirar una autorización impide que el portal vuelva a entregar el enlace, pero NO invalida un enlace que alguien ya conozca**: la revocación efectiva de un enlace «Publicar en web» exige eliminarlo en Power BI (Configuración → Administrar códigos de inserción), lo que afecta también a Flutter.

## Cierre inmediato del piloto
1. `sudo bash scripts/revertir.sh` (el sitio deja de existir). 2. Si procede, un administrador ejecuta `99-revocar-cuenta-visor.sql` o `ALTER USER 'cervesur_ro_visor'@'127.0.0.1' ACCOUNT LOCK`. 3. Destruya la clave del operador si se hubiera copiado.

## Fallos frecuentes
| Síntoma | Causa probable | Qué hacer |
|---|---|---|
| Todo responde 503 `mode_config` | `.env` alterado (modo, hash del operador, JWT corto, lista de informes) | restaurar el `.env` o reinstalar; el sitio **falla cerrado** a propósito |
| 429 al iniciar sesión | 5 fallos seguidos | esperar 15 min o `operador.sh --desbloquear` |
| «Este informe aún no está autorizado para el piloto» (403) | el informe no figura en la lista autorizada | `informes.sh --autorizar <id>` (con confirmación) |
| `preflight-acceso.sh` devuelve 3 | no existe la cuenta o falta su clave | crear la cuenta con `admin-mysql/` |
| 500 en el catálogo | la cuenta perdió una columna o cambió la base | `preflight-acceso.sh` indica la columna que falta |
