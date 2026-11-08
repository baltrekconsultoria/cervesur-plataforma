# Recuperación, rotación y reversión del diagnóstico 8090 · UX-07D.3B

## Reversión completa (solo lo creado por 8090)
```
sudo bash scripts/revertir.sh        # pide escribir REVERTIR-8090; idempotente; tolera instalaciones parciales
```
Elimina únicamente: el sitio `cervesur-react-ro`, `/opt/cervesur-react-ro` (incluido el `.env` con el token), `/etc/apache2/cervesur-react-ro`, `/var/lib/cervesur-react-ro`; recarga Apache de forma suave tras `configtest`.
**No toca:** Flutter, 8088, 8089, los módulos de Apache (`mod_headers` queda como está), `/var/backups/cervesur-react-ro` (los respaldos se conservan), **ni MySQL**: no elimina `cervesur_portalbi` ni ninguna cuenta (tampoco `cervesur_ro_diag`).
Al terminar compara la huella de los demás portales con la línea base tomada antes de instalar.

## Retirar la cuenta MySQL (procedimiento administrativo aparte, con autorización)
`admin-mysql/99-revocar-cuenta-diagnostico.sql` ejecuta una única sentencia, `DROP USER IF EXISTS 'cervesur_ro_diag'@'127.0.0.1'`: no tiene marcadores y NO puede afectar a `cervesur_ro_pilot`, a otro host ni a ninguna otra cuenta (la reversión del piloto es otro archivo y no viaja en este paquete). Nunca lo hace el instalador ni la reversión.

## Si falla la instalación
La instalación revierte automáticamente lo creado y deja los respaldos. Compruebe `/var/backups/cervesur-react-ro/linea-base-portales.txt` y repita el preflight. Una instalación a medias (corte de luz, SSH caído) se limpia con `revertir.sh`.

## Token de diagnóstico
- **Dónde vive:** solo en `/opt/cervesur-react-ro/backend/.env` (640 `root:www-data`). No está en el paquete, ni en URLs, ni en logs, ni se imprime en las verificaciones.
- **Recuperarlo (lo olvidó):** `sudo bash scripts/token.sh --mostrar` (pide escribir `MOSTRAR-TOKEN`). Úselo en una terminal de confianza.
- **Rotarlo (se filtró o por política):** `sudo bash scripts/token.sh --rotar`. Genera 256 bits nuevos, sustituye el valor de forma atómica (sin recargar Apache) y **el anterior deja de valer al instante**. Comuníquelo al operador por un canal aparte.
- **Si sospecha de filtración:** rótelo, revise `/var/log/apache2/cervesur-react-ro-error.log` (busque «token rechazado»: no incluye el valor) y, si procede, revierta el sitio.

## Clave de la cuenta MySQL
Vive solo en el `.env` (y, hasta que se destruya, en `/root/cervesur-ro-diag.clave`, 600). Para cambiarla: un administrador ejecuta `ALTER USER 'cervesur_ro_diag'@'127.0.0.1' IDENTIFIED BY '<nueva>'`, actualiza `DB_PASS=` en el `.env` (mismo modo/propietario) y ejecuta `scripts/preflight-acceso.sh`. Nota: cualquier cuenta MySQL puede cambiar su propia clave; el procedimiento 04 cuenta esas sentencias (`set_password`, `alter_user`).

## Fallos frecuentes
| Síntoma | Causa probable | Qué hacer |
|---|---|---|
| Todas las rutas responden 503 `mode_config` | `.env` alterado (modo ausente o distinto de `diagnostico`, token corto, `DB_NAME` raro) | restaurar el `.env` desde un respaldo o reinstalar; el sitio **falla cerrado** a propósito |
| 401 en los endpoints protegidos | token incorrecto o rotado | `token.sh --mostrar` |
| 500 en resumen/conciliación | la cuenta perdió una columna o la base cambió | `preflight-acceso.sh` (dirá qué columna falta) y clasificar con `conciliar.sh` |
| `preflight-acceso.sh` devuelve 3 | no existe la cuenta o falta su clave | crear la cuenta con `admin-mysql/` (no es un fallo del instalador) |
