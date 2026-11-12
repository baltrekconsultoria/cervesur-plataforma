# P5-R · Liberación incremental en la VPS Cervesur (portal 8443)

**ESTADO: CANDIDATO (NO activar sin autorización ni preflight real).**
El alcance es **restablecer contraseñas de usuarios estándar activos** desde React, no cambiar roles. U-BASIC ya permanece encendido. Se requieren DBA y responsable funcional identificados para habilitar la escritura de `password`.

## Fuente y validaciones terminadas

- PR #33 DRAFT: https://github.com/baltrekconsultoria/cervesur-plataforma/pull/33
- Motor MySQL 8 REAL y efímero: GitHub Actions #43272267098 `success` (GRANT, rechazo rol, SELECT password denegado, REVOKE y continuidad U-BASIC).
- QA PHP, permisos y frontend: `Cervesur P5-R Isolated QA`.
- Paquete incremental: GitHub Actions #43272490325 `success`, artifact `cervesur-p5r-candidato-8443`. Commit del paquete `2aefe2366579665a0e8b8e63614f1336a1d65a7a`; no sustituir por un ZIP sin manifiesto.
- Los scripts no se conectaron a la VPS ni alteraron la base productiva durante estos ensayos.

## Puerta 0: VPS en solo lectura (obligatoria)

Conectar por SSH a la VPS. Descargar y verificar **sin ejecutar código no comprobado** el script `ops/p5r/preflight-vps-solo-lectura.sh` en el commit exacto aprobado, validando su blob SHA con `git hash-object`. Ejecutar con `sudo bash`. No muestra secretos, no lee filas de negocio, no realiza escrituras.

Resultado exigido:
- `P5R_ARCHIVOS=PASS`
- `P5R_MODO=PASS`
- `P5R_U_BASIC_ON=PASS`
- `P5R_P5_SENSIBLE_OFF=PASS`
- `P5R_AUDITORIA_REQUIRED=PASS`
- `P5R_BASE_GIT=PASS` (las cuatro fuentes PHP productivas corresponden EXACTAMENTE a la base del PR #33)
- `P5R_APACHE=PASS`, `P5R_8443=PASS`
- `P5R_MYSQL_SIN_SENSIBLES=PASS`
- `P5R_PREFLIGHT_HOST=PASS`

Si cualquier clave es NO_GO, **detener** y conciliar lo instalado con la rama. Prohibido el instalador de Fase 2B original: presupone Usuarios OFF y puede interrumpir el U-BASIC encendido.

## Puerta 1: importar paquete (sin instalar)

Desde GitHub Actions run #43272490325, descargar artifact `cervesur-p5r-candidato-8443` (ZIP que contiene `.tar.gz` y `.tar.gz.sha256`). Pasar ambos al VPS por SFTP, por ejemplo a `~/p5r-rc` (el nombre del paquete procede de GitHub).

En la VPS:

```bash
cd ~/p5r-rc
sha256sum -c cervesur-p5r-rc-2aefe2366579-8443.tar.gz.sha256
mkdir -p kit
tar -xzf cervesur-p5r-rc-2aefe2366579-8443.tar.gz -C kit
sudo bash kit/scripts/instalar-codigo-p5r.sh --preflight
```

Este segundo preflight verifica todos los SHA-256 internos, sintaxis de los 4 PHP, condición ON de U-BASIC, hashes Git actuales del backend y TLS real de 8443. **No realiza escrituras.** Si falla no aplicar.

## Puerta 2: autorización y deploy del código (aún OFF)

Solo con evidencia de ambas puertas, respaldos probados y autorización **real y nominativa** (sin marcadores `[Nombre]`) se puede ejecutar en ventana acordada:

- Credenciales del administrador 8443 por lectura silenciosa en la sesión SSH elevada: `CERVESUR_E_USUARIO`, `CERVESUR_E_CLAVE`. Nunca incluirlas en comandos, argumentos, GitHub o evidencia compartida.
- `CERVESUR_CONFIRMAR_P5R=SI`, y `--autorizacion "DBA / responsable funcional / fecha / P5-R"`.
- `sudo bash kit/scripts/instalar-codigo-p5r.sh --aplicar --autorizacion "..."`.

El instalador copia **solo cuatro archivos PHP y el frontend del React 8443**; no modifica `.env`, Apache, bases ni portales. Crea respaldo `p5r-code-*` de PHP+public, verifica huellas antes y capacidades después, restaura código si las pruebas no pasan. Debe imprimir `P5R_INSTALL=PASS_CODIGO_FLAG_OFF_U_BASIC_ON`.

**Nota:** el instalador no es el de Fase 2B original. Se entrega exclusivamente con el artifact P5-R.

## Puerta 3: GRANT exclusivo (P5-R todavía OFF)

En ventana autorizada, un DBA debe verificar un respaldo nuevo, de menos de 24 horas, y la cuenta de escritura actual. Kit: `kit/scripts/privilegios-usuarios.sh`.

```bash
sudo bash kit/scripts/privilegios-usuarios.sh --etapa password-reset --simular
sudo bash kit/scripts/privilegios-usuarios.sh --etapa password-reset --aplicar --respaldo /ruta/real/respaldo.sql.gz --confirmo-autorizacion "DBA y responsable funcional, fecha"
sudo bash kit/scripts/privilegios-usuarios.sh --etapa password-reset --verificar
```

El SQL de la etapa `password-reset` concede únicamente `UPDATE(password)`, nunca `UPDATE(role)`. La etapa `sensibles` sigue prohibida en este incremento. Sin verificación exacta `PASS password-reset`, **no encender**.

## Puerta 4: activación P5-R y QA

En la misma ventana y con credenciales seguras del administrador, activar mediante `kit/scripts/interruptor-p5r.sh encender --autorizacion "..."`. Ese interruptor comprueba U-BASIC ON, P5 completo OFF, permiso de columna, API 8443, respaldos .env y luego `users_password_reset=true` y `users_sensitive=false`; de lo contrario restaura el .env anterior.

QA manual **solo sobre cuenta de usuario estándar de ensayo autorizada**:
- El botón aparece para usuario estándar activo y nunca para administrador o usuario inactivo.
- Contraseñas sin coincidencia o fuera de 12–72 bytes se rechazan.
- Una prueba de restablecimiento positiva con doble confirmación; comprobar sesión nueva con contraseña nueva y antigua rechazada.
- `audit_log`: exactamente un evento `user.password` por cambio, sin contraseñas ni hashes.
- `session_version` avanza solo una vez; rol, correo, estado y asignaciones iguales.
- Empresas, reportes y accesos de U-BASIC continúan funcionando.
- Las sesiones Flutter heredadas **pueden seguir válidas** hasta caducar (riesgo residual conocido, no prometer cierre instantáneo).

## Reversión

**Orden obligatorio:** apagar exclusivamente P5-R con `kit/scripts/interruptor-p5r.sh apagar` → revocar solo la etapa `password-reset` con `kit/scripts/privilegios-usuarios.sh --etapa password-reset --revertir --confirmo-autorizacion "..."` → opcionalmente restaurar código desde el respaldo `p5r-code-*` creado por el instalador con `--revertir RUTA_RESPALDO`.

Nunca apagar U-BASIC como parte de la reversión normal. Revocar privilegios o código **no restaura contraseñas que ya hayan cambiado**. Conservar trazabilidad de la operación.

## Estado GO/NO-GO

**NO-GO para despliegue productivo automático** hasta:
1. Resultado `PASS` del preflight de la VPS real y del preflight del kit.
2. Ensayo de instalación/reversión con base/estado aislado cuando sea posible.
3. Identidad de las cuatro fuentes desplegadas conciliada.
4. Aprobaciones nominativas y backup nuevo verificable.
5. Autorización separada del usuario para la ejecución productiva.

Este documento es un procedimiento, no una autorización.
