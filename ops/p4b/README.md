# Cervesur – K1/JWT: release controlado antes de U-BASIC

El backend PHP heredado NO es el mismo codigo de main. Este lote modifica solo:
- /var/www/cervesur/backend/.env: JWT_EXPIRATION de 10800 s a 86400 s (decision funcional anterior).
- /var/www/cervesur/backend/lib/powerbi_authz.php: fallback POWERBI_AUTH_MODE de observar a retirar.

No activa usuarios, no cambia .env JWT_SECRET, no actualiza MySQL, no instala routers, no reinicia Apache, no crea cuentas. F10, K2, A1 y A2 permanecen intactos. Las nueve rutas JWT con literales default son deuda de otro lote; no se cubren con este cambio.

## Controles incorporados

- PIN SHA-256 de F10 auth.php: 4633907aa733a3548c4c78cfdc42dac315fb8925e2eca39de4883cd127ab3366.
- PIN SHA-256 de H1 auth_middleware.php: c717dcb962eb6c01aed798bac48dfe126736ab918f3131ee509af380b5e1b298.
- Rechazo ante TTL desconocido, JWT_EXPIRATION duplicado, fallback K1 ambiguo o PHP sin sintaxis valida.
- Respaldo previo con permisos privados 0700/0600 de los dos archivos intervenidos, mas auth.php y H1 para trazabilidad.
- Reemplazo atomico y rollback por SHA; si terceros cambian los archivos despues, rollback detiene para proteger esos cambios.
- QA aislado sin conexiones productivas en GitHub Actions.

## Ejecucion (solo con respaldo, ventana y autorizacion)

Transferir la carpeta ops/p4b desde el commit exacto aprobado a un directorio controlado en el servidor fuera de /var/www/cervesur mediante Git autorizado o copia segura. No copiar el .env al equipo local.

PRE-FLIGHT (sin cambios en la aplicacion):

    sudo python3 /ruta/confiable/ops/p4b/legacy_policy.py preflight

Antes de aplicar, debe indicar F10_SHA=PASS, H1_SHA=PASS, JWT_ACTUAL_SEG=10800, K1_FALLBACK_ACTUAL=observar, PREFLIGHT=PASS_SOLO_LECTURA.

APPLY (requiere confirmacion de ventana; respaldo ocurre ANTES de cambiar codigo o .env):

    sudo python3 /ruta/confiable/ops/p4b/legacy_policy.py apply --confirm CERVESUR_AUTORIZO_K1_JWT_24H_CON_BACKUP

Guardar BACKUP_PATH exacto. El backup contiene .env y secretos: mantenerlo solo en el servidor y nunca enviarlo al chat, repositorio ni correo.

VERIFICAR:

    sudo python3 /ruta/confiable/ops/p4b/legacy_policy.py preflight
    curl -sS --max-time 8 -o /dev/null -w 'K1_HTTP=%{http_code}\n' http://192.168.200.12/api/powerbi/tokens/0

Esperado despues: JWT_ACTUAL_SEG=86400, K1_FALLBACK_ACTUAL=retirar, K1_HTTP=410. Probar inicio de sesion de una cuenta controlada. La nueva expiracion se refleja en tokens emitidos posteriormente. La configuracion Apache/FPM podria sobreescribir la de .env; si la prueba funcional contradice el archivo, DETENERSE.

ROLLBACK (ruta BACKUP_PATH real, nunca inventada):

    sudo python3 /ruta/confiable/ops/p4b/legacy_policy.py rollback --backup /home/usr_cervesur/cervesur-p4b-policy-EL_VALOR_REAL --confirm CERVESUR_AUTORIZO_K1_JWT_24H_CON_BACKUP

Rollback restaura exclusivamente dos archivos si sus SHA siguen iguales a los del release. Luego repetir preflight y pruebas de login/HTTP. Reduccir TTL de nuevo a 3h no revoca retroactivamente tokens emitidos con 24h; revocacion global es accion separada.

## Fuera de alcance del release

- U-BASIC permanece OFF y U-SENSITIVE/P5 permanece OFF. No ejecutar interruptor-usuarios.sh encender con este solo paquete.
- D4 legado busca REGISTRO_ABIERTO, aunque el correctivo F10 instalado usa F10-20261111. No reinstalar F10. La verificacion F10 real dio guardia PASS y 401 anonimo; falta 403 con usuario normal.
- D3/QA de roles A1/A2, cierres formales K1/K2/A1/A2/A3, continuidad de altas desde Flutter y permisos SELECT de portal 443 siguen pendientes para habilitar usuarios.
- El script no expone JWT_SECRET, passwords ni contenido del .env.
