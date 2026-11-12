# Cervesur - Gestión de usuarios tras migración y activación (12/11/2026)

## Evidencia confirmada en producción

El operador ejecutó migración de 114 cuentas, auditoría postimportación y activación de 114 cuentas nuevas.
Salida de PuTTY:
- CERVESUR_ACTIVACION_USUARIOS=PASS, MODO=APPLY.
- NUEVOS_ACTIVADOS=114; ANTIGUOS_MODIFICADOS=0; TOTAL=190; ACTIVOS=176; INACTIVOS=14.
- SESIONES_NUEVAS_CREADAS=0; CREDENCIALES_INICIALIZADAS=NO.
- Segunda auditoría read-only: MODO=POSTFLIGHT PASS, MIGRADOS_ACTIVOS=114, ANTIGUOS_INTACTOS=76, MYSQL_MODIFICADO=NO.

No repetir APPLY de importación ni activación.

Backups sensibles creados fuera del DocumentRoot:
- /var/lib/cervesur-migration/snapshots/usuarios-react-backup-preimport-20261112.json : 76 originales.
- /var/lib/cervesur-migration/snapshots/usuarios-preactivacion-190-20261112.json : 190 antes de activación.

Nunca enviar PII ni hashes a GitHub, chats, capturas o correo.

## Diagnóstico de la administración en el código

- frontend/src/app/App.tsx define /admin/usuarios protegido por rol admin.
- frontend/src/features/admin/AdminPages.tsx presenta lista y filtros por rol, estado y búsqueda, con paginación visual.
- frontend/src/api/http/httpApi.ts consume GET /api/users?status=all, con per_page=100 y todas las páginas.
- backend/router/UserRouter.php exige JWT de admin para GET /api/users, filtrado por estado.
- backend/controllers/AuthController.php y backend/lib/auth_middleware.php requieren contraseña válida, is_active=1, rol conocido y session_version vigente.
- NewUserDialog.tsx solo ofrece alta de usuario activo y contraseña inicial. Faltan edición de cuentas, cambio de estado y restablecimiento seguro.
- Los 114 usuarios activados siguen con hashes de contraseñas aleatorias desconocidas creados por el importador. No pueden iniciar sesión hasta inicializar contraseñas individuales.

## Estado de entornos: comprobar el servidor antes de usar datos reales

Según docs/ENTORNO-COMPATIBILIDAD-8089.md y docs/DESPLIEGUE-REACT-PRUEBA.md:
- Flutter productivo, puerto 80, usa cervesur_portalbi.
- React de pruebas 8088 y React de compatibilidad 8089 usan BD sintéticas aisladas.
- La documentación puede estar desactualizada: verificar realmente vhosts, archivos, proveedor y endpoint sin leer secretos antes de cambios.
- Nunca apuntar una instalación React de QA a la BD productiva ni sustituir Flutter sin release controlado.

## Puerta inmediata: inventario Ubuntu de solo lectura

Se comprobarán únicamente presencia de index.html, HTTP code de /api/ping y de /api/users anónimo en puertos 80, 8088, 8089.
Códigos 200 de ping demuestran salud de endpoint local, NO que consulta datos reales; 401 de /api/users anónimo protege directorio. 200 de /api/users anónimo requiere STOP y revisión.
No extraer .env, tokens, usuarios, hashes o nombres personales a salida de terminal.

## Fases necesarias para gestión de usuarios

1. Determinar destino React real para producción, no mezclar QA con cervesur_portalbi, confirmar HTTPS/VPN y aislamiento de sesión.
2. Login admin existente y listado operativo completo: 190 usuarios, 176 activos, 14 inactivos; 100+90 en paginación y 401/403 de seguridad. No divulgar listados a chat.
3. Recuperación/inicialización individual de contraseñas con token aleatorio de un uso, hash, caducidad corta, límite de intentos, canal de entrega privado y auditoría; ningún password compartido o expuesto. Evaluar envío seguro antes de activar.
4. Administración editable: perfil, desactivar/reactivar con confirmación, no desactivar último admin, incremento session_version para revocar sesiones, auditoría. No tocar roles o asignaciones sin autorización específica.
5. Pruebas con una cuenta controlada, revisión de rollback y despliegue segregado; no usar las 114 cuentas de clientes para pruebas.

La importación y activación productivas están terminadas; habilitación React/credenciales NO realizada todavía.

## PuTTY: inventario de portales realizado el 12/11/2026

- FRONT_PRESENT para Flutter /var/www/cervesur/frontend/index.html.
- FRONT_PRESENT para React QA /var/www/cervesur-react-prueba/public/index.html (8088).
- FRONT_PRESENT para React compatibilidad /var/www/cervesur-react-compat/public/index.html (8089).
- API_PING_PORT_80 = 404 text/html (no demuestra que Flutter este caido).
- API_PING_PORT_8088 y API_PING_PORT_8089 = 200 application/json.
- API_USERS_ANON_PORT_8088 y API_USERS_ANON_PORT_8089 = 401 application/json.

Interpretacion: las dos API QA responden y protegen /api/users ante solicitudes anonimas. No se ha demostrado que React vea 190 usuarios productivos. Según documentación, React 8088 y 8089 son entornos aislados con bases sintéticas.

### Proxima puerta: vhosts y rutas de API, solo lectura

Verificar Apache vhosts asociados a 80/443/8088/8089/8443, puertos en escucha y codigo HTTP anonimo de /api/users en puerto 80. No imprimir cuerpos HTTP ni archivos .env. Un 200 JSON en /api/users anonimo requiere STOP. Las solicitudes a 127.0.0.1 pueden seleccionar el vhost por defecto, no el vhost corporativo; interpretar con cautela.

No alterar la configuracion Apache ni redirigir los React QA a MySQL productivo. El objetivo posterior es un portal administrativo productivo separado y seguro, con listado real de 190 usuarios, 176 activos y 14 inactivos, mas invitaciones de credenciales individuales; sus 114 cuentas nuevas siguen sin contrasenas utilizables.

## Continuidad: inventario completo de vhosts y puertos

Ver [HALLAZGOS-ADMIN-VHOSTS-2026-11-12.md](HALLAZGOS-ADMIN-VHOSTS-2026-11-12.md) para la consolidacion de: POSTFLIGHT 190/176/14, verificacion de API 80/8088/8089, VirtualHosts habilitados para 80/443/8443 y 8088-8094, puertos realmente en LISTEN, distincion entre entorno QA y portales corporativos, y siguiente puerta para verificar HTTPS 443/8443 sin credenciales.

Nota de seguridad: el repositorio es publico, por lo que el inventario de GitHub documenta el binding como interfaz interna/VPN sin publicar la IP corporativa concreta. No compartir configuraciones privadas, .env ni PII de usuarios.

## Gate HTTPS corporativo 443/8443 (ejecutado 12/11, solo lectura)

El operador confirmó `GET /`, `/api/ping` y `/admin/usuarios` por HTTPS con 200 (HTML, JSON, HTML respectivamente) tanto en puerto 443 como 8443. `GET /api/users?status=all` sin credenciales devolvió **403 JSON en 443** y **401 JSON en 8443**. Ninguno entregó listado anónimo. Esta evidencia no demuestra todavía que la vista React 8443 se conecte a la BD productiva, ni que el 200 HTML de `/admin/usuarios` sea gestión funcional (podría ser fallback SPA). No usar credenciales con validación de certificado deshabilitada.

Para resultados, interpretación, hallazgos de vhosts y proxima verificacion segura de la BD real, consultar el documento canónico [HALLAZGOS-ADMIN-VHOSTS-2026-11-12.md](HALLAZGOS-ADMIN-VHOSTS-2026-11-12.md).

## Puerta de configuracion productiva (12/11/2026)

PuTTY: al cargar con `www-data` el `lib/env.php` de los backends DECLARADOS de ambos vhosts corporativos se recibio `PORTAL=admin DB_TARGET=PRODUCCION` y `PORTAL=usuarios DB_TARGET=PRODUCCION`. Esta clasificacion comprueba `DB_NAME=cervesur_portalbi` en ambos loaders, **no** conexion PDO ni identidad de servidor MySQL. Datos de usuarios siguen 190/176/14 por verificacion original de importacion/activacion.

Proxima puerta: PDO SOLO LECTURA desde backend admin declarado, comparar conteos 190/176/14 con DB original de referencia y, si viable, identidad de servidor MySQL. Correlacionar luego mapeo Apache efectivo y funcionamiento de la interfaz admin con TLS validado; NO desplegar ni inicializar passwords antes.

Detalles e historial de VirtualHosts y seguridad en [HALLAZGOS-ADMIN-VHOSTS-2026-11-12.md](HALLAZGOS-ADMIN-VHOSTS-2026-11-12.md).

## PDO real, portales 8443/443 (12/11/2026)

PuTTY admin 8443: SELECT agregado mediante backend PHP declarado de admin con `DB_COUNTS=PASS`, `TOTAL=190`, `ACTIVOS=176`, `INACTIVOS=14`. Confirma conteos concordantes con la migración y activación productiva para esa conexión PDO. Todavía falta correlacionar Apache efectivo, TLS validado y acceso admin autenticado.

PuTTY usuarios 443: consulta equivalente devolvió `DB_READ=ERROR` sin fase de fallo. `DB_NAME=PRODUCCION` del loader por sí solo NO garantiza conexión. Siguiente diagnóstico en etapas del loader PHP del portal 443, sin mostrar errores completos ni secretos, NO cambiar privilegios ni configuración.

Para ver resultados y puertas pendientes consultar [HALLAZGOS-ADMIN-VHOSTS-2026-11-12.md](HALLAZGOS-ADMIN-VHOSTS-2026-11-12.md).

## Diagnóstico de SELECT en backend 443 (12/11/2026)

Nuevo resultado PuTTY: `USUARIOS_DB_STAGE=ERROR_SELECT`. Loader, CONFIG, PDO_CONNECT, READ_ONLY y BEGIN fueron superados, pero falló el SELECT agregado de tabla `user` por causa todavía no clasificada. **No** diagnosticarlo automáticamente como falta de GRANT. Backend admin 8443 conserva evidencia de lectura PASS con 190/176/14. Próxima prueba 443 clasifica código de error MySQL sin revelar excepción ni datos personales. Ver detalle en [HALLAZGOS-ADMIN-VHOSTS-2026-11-12.md](HALLAZGOS-ADMIN-VHOSTS-2026-11-12.md).

## Diagnóstico concluyente SELECT usuarios 443 (12/11/2026)

PuTTY: `DIAGNOSTICO=ERROR_TABLA_USER_PERMISO_SELECT`. El backend declarado de usuarios corporativos 443 superó LOADER, CONNECT, READ_ONLY y SELECT 1/DATABASE, pero MySQL rechazó `SELECT COUNT(*) FROM user` mediante código 1142/1143. El backend administrativo 8443 sí consultó totales 190/176/14 con PASS. No se han concedido privilegios ni modificado Apache.

Antes de cambiar permisos se verificará identidad e instancia de servicio y alcance de GRANT mínimo; no confundir la restricción HTTP 403 del directorio de usuarios sin JWT con un error SQL. Puede afectar inicio de sesión del portal 443 si necesita acceder a la tabla `user`; no hay aún QA funcional del login. Registro completo [HALLAZGOS-ADMIN-VHOSTS-2026-11-12.md](HALLAZGOS-ADMIN-VHOSTS-2026-11-12.md).
