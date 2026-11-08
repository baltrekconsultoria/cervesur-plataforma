# Recuperación y reversión del piloto con usuarios reales · 8092 (UX-07D.4B.1)

## Retirar el piloto (reversible, no toca MySQL)

1. Opcional, para dejar la base de estado coherente: `sudo bash scripts/usuarios.sh revocar todos`.
2. `sudo bash scripts/revertir.sh` → escribir `REVERTIR-8092`. Elimina únicamente: sitio `cervesur-react-usuarios`, `/var/www/cervesur-react-usuarios`, `/opt/cervesur-react-usuarios` (backend y `.env`), `/etc/apache2/cervesur-react-usuarios` (configuración, **certificado y clave privada TLS**) y `/var/lib/cervesur-react-usuarios`. Recarga suave tras `configtest`. Conserva `/var/backups/cervesur-react-usuarios`.
3. Comprueba que el puerto 8092 ya no escucha y que Flutter, 8088, 8089, 8090 y 8091, los módulos, bases y cuentas son idénticos a la línea base.
4. Es **idempotente** y tolera instalaciones parciales.

## Retirar cuentas y base de estado (procedimiento administrativo aparte)

* `sudo mysql < admin-mysql/99-revocar-cuentas.sql`: elimina **solo** `cervesur_ro_auth`, `cervesur_ro_catalogo` y `cervesur_react_estado` en `127.0.0.1`. No elimina la base de estado.
* La base `cervesur_react_estado` contiene auditoría y sesiones; eliminarla es una decisión administrativa distinta (haga antes una copia: `mysqldump cervesur_react_estado`).

## Reinstalar

Tras revertir, el procedimiento de instalación se repite desde el paso 2. Las cuentas y la base de estado, si siguen existiendo, se reutilizan (el preflight B las vuelve a auditar); la lista blanca persiste en la base de estado.

## Situaciones

| Situación | Acción |
|---|---|
| Una persona ya no debe entrar | `usuarios.sh quitar <id>` (efecto inmediato) |
| Sospecha de clave comprometida de un usuario | cambiarla en Flutter: la sesión del piloto se invalida sola (huella de contraseña); `usuarios.sh revocar <id>` |
| Cerrar todas las sesiones | `usuarios.sh revocar todos` |
| Usuario bloqueado por intentos | `usuarios.sh desbloquear <login>` |
| Certificado caducado | `revertir`/`instalar` de nuevo o `tls.sh` con un certificado nuevo; `tls.sh verificar` avisa 7 días antes |
| Un enlace público ya se compartió | quitarlo en Power BI (afecta también a Flutter); el portal solo deja de entregarlo |
| El sitio responde 503 | configuración inválida o cuenta sin permiso: el servicio se niega a servir (fail-closed); revisar `.env` y `scripts/preflight-acceso.sh` |
