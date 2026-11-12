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
