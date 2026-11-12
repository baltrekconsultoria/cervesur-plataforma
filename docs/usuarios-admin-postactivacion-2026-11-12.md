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

## Identidades de MySQL (12/11/2026): misma instancia, cuentas distintas

El operador confirmó internamente con `@@server_uuid` y `CURRENT_USER()` de ambas conexiones PDO: `MISMA_INSTANCIA=SI`, `MISMA_CUENTA_MYSQL=NO`. No se expusieron valores. 8443 sigue con lectura de `user` PASS 190/176/14. La identidad SQL de 443 recibe código 1142/1143 por `SELECT FROM user`. La causa inmediata de la lectura fallida de 443 es la insuficiencia de permisos SELECT para su usuario de servicio sobre `user`, no otra instancia. El portal 443 podría no poder autenticar usuarios mientras persista ese error, pues el AuthController del repositorio necesita SELECT a `user`; hay que verificar código efectivamente desplegado antes de generalizar.

Próximos pasos independientes: (a) QA funcional de `/admin/usuarios` en 8443 con navegador, dominio/certificado TLS válidos y administrador existente, sin imprimir PII/JWT; (b) auditoría sin cambios de GRANT y rol del usuario MySQL 443, definir permisos mínimos en código y SQL, respaldar grants y corregir solo con aprobación. No conceder globales, no usar credenciales de admin en 443 ni reutilizar APPLY. Ver detalle en [HALLAZGOS-ADMIN-VHOSTS-2026-11-12.md](HALLAZGOS-ADMIN-VHOSTS-2026-11-12.md).

## QA React admin real en 8443 completado para directorio (12/11/2026)

El usuario confirmó visualmente desde el portal ADMIN corporativo que `Estado=Activos` muestra `176 de 190 usuarios` y reportó `14` inactivos. La captura evidencia también módulos de Empresas, Reportes, Informes por empresa, Accesos y asignaciones, Historial y Mi perfil. `DIRECTORIO_ADMIN_QA=PASS` para visibilidad de usuarios y conteos 190/176/14; no extrapolar a operaciones de escritura, roles, restablecimiento de contraseñas ni sesión en portal usuarios 443.

La captura original contiene nombres y correos; NO se almacena en este repositorio público. El backend PHP declarado de 443 sigue con SQL `PERMISO_SELECT` sobre `user`; resolver antes de habilitar login/onboarding de los 114 migrados. No repetir importacion/activacion.

Continuidad completa en [HALLAZGOS-ADMIN-VHOSTS-2026-11-12.md](HALLAZGOS-ADMIN-VHOSTS-2026-11-12.md). Próximo gate: QA lectura de empresas/reportes/vinculos y revisión de privilegios mínimos del portal 443.

## QA visual del catalogo y vinculos administrativos (12/11/2026)

El operador mostro tres pantallas de 8443 sin modificar datos. `Empresas` muestra 6 activas de 26; `Reportes` 82 activos de 95; `Accesos y asignaciones` 91 vigentes de 103. Se observaron botones de alta/edicion/desactivacion/retiro, pero su funcionamiento **NO** se probo. Capturas con PII no publicadas. El agregado de `informes activos` por las 6 empresas visibles suma 84 frente a 82 reportes activos únicos mostrados en el catálogo; diferencias de criterio o duplicidad de relaciones aún no verificadas.

Continuidad detallada y puestas de control en [HALLAZGOS-ADMIN-VHOSTS-2026-11-12.md](HALLAZGOS-ADMIN-VHOSTS-2026-11-12.md). Proximo QA en lectura: `Informes por empresa`, conciliación de informes únicos vs relaciones, y comparar con checklist de dashboards optimizados, antes de editar enlaces y asignaciones. Portal usuarios 443 sigue limitado por falta SELECT a `user`; 114 migrados sin contraseña utilizable. No conceder permisos ni hacer acciones masivas todavía.

## QA vinculos empresa-reporte conciliado y desactivacion real de empresa de pruebas (12/11/2026)

Cinco empresas activas mostraron contadores `(asociados, disponibles)` de `(8,74)`, `(55,27)`, `(3,79)`, `(5,77)`, `(13,69)`, sobre el catálogo común de 82 reportes activos. El total de asociaciones es 84. En una empresa se vieron exactamente dos reportes `Compartido con 1 más`, lo que explica 84 relaciones vs 82 reportes únicos; ver detalles en el documento de hallazgos.

El OPERADOR desactivó desde el portal 8443 la empresa ficticia Test SAC (0 reportes, 0 usuarios en la vista previa); la UI mostró confirmación correcta y `Mostrando 5 de 26 empresas` activas. Se verificó visualmente el efecto de la baja lógica, pero aún no se ha consultado el `Historial` para validar auditoría. Cambio efectuado por el operador, no por GPT. No crear más empresas ni borrar otras.

Estado al cierre: 190 usuarios/176 activos/14 inactivos; 26 empresas/5 activas/21 inactivas; 95 reportes/82 activos; 103 asignaciones/91 vigentes; 84 vínculos activos empresa-informe. Los 114 nuevos usuarios siguen sin contraseñas inicializadas y el portal 443 tiene una denegación SQL `SELECT` de su cuenta de servicio, pendiente de corrección de privilegio mínimo. Siguiente fase: audit trail de desactivación, luego matriz de conciliación de todos los dashboards optimizados con reportes existentes y URL Power BI, sin escrituras hasta aprobación específica.

Ver [HALLAZGOS-ADMIN-VHOSTS-2026-11-12.md](HALLAZGOS-ADMIN-VHOSTS-2026-11-12.md).

## QA Historial: evidencia de accesos; empresa Test SAC aun pendiente (12/11/2026)

El operador mostró `Administración > Historial` pero con `Ver=Accesos y asignaciones`: aparecieron 2 registros históricos del mismo acceso interno, uno concedido y otro retirado el 05/11/2026. La vista confirma la presencia de movimientos de accesos, pero NO la desactivación de Test SAC. Siguiente paso: seleccionar `Ver=Empresas` y confirmar el evento de desactivación y sus metadatos sin exponer el nombre del autor en GitHub público. La baja lógica sí está visualmente confirmada en el listado de Empresas (5 de 26 activas).

Detalles de continuidad en [HALLAZGOS-ADMIN-VHOSTS-2026-11-12.md](HALLAZGOS-ADMIN-VHOSTS-2026-11-12.md).

## Cierre QA visual de Historial Empresas/Reportes (12/11/2026)

Con filtro `Empresas` la captura mostró dos eventos, incluido `Empresa desactivada`, registro `#28`, fecha/hora `2026-11-12 10:40:38.761`, detalle activo -> inactivo y responsable presente. El evento coincide temporalmente con la baja lógica de Test SAC del operador (5 de 26 empresas activas), pero la pantalla NO muestra el nombre de empresa: queda pendiente cotejar por SELECT privado ID de Test SAC contra `#28` si se necesita trazabilidad nominal absoluta.

Con filtro `Reportes` la captura mostró `Reporte editado` el `2026-11-05 04:49:22.684`, registro `#133`, descripción cambiada y responsable. Se confirma que se registran tales operaciones, no aún que se audite cualquier actualización de URL Power BI. Se preservan solamente métricas y registros; las capturas, teléfonos y nombres del responsable NO se suben al repositorio público.

`QA_HISTORIAL_EMPRESAS=PASS_VISUAL_EVENTO`, `QA_HISTORIAL_REPORTES=PASS_VISUAL_EVENTO`. Proceder al inventario y conciliación de reportes existentes contra dashboards optimizados antes de modificar URLs/asignaciones. Detalle en [HALLAZGOS-ADMIN-VHOSTS-2026-11-12.md](HALLAZGOS-ADMIN-VHOSTS-2026-11-12.md).

## Cambio de prioridad aprobado en conversacion (12/11/2026): ADMINISTRACION DE USUARIOS

El operador cuestionó la extensión de auditorías de catalogos y pidió pasar directamente a la **activación de la gestión administrativa de usuarios**. Se decide poner en pausa las nuevas rondas de inventario/conciliacion de reportes y empresas para enfocarse en usuarios. Las auditorías previas ya cumplen su propósito de verificar datos y acceso.

Nuevo issue de ejecución: [#24 — GUA-01/GUA-02: gestión administrativa de usuarios y credenciales](https://github.com/baltrekconsultoria/cervesur-plataforma/issues/24), separado del PR #23 de migracion. Prioridad: (1) descubrir release/capacidades **solo de usuarios** en 8443, (2) desarrollar edición y baja/reactivación de usuarios con auditoría, (3) mecanismo de inicialización/reset individual de contraseñas para 114 cuentas; (4) corregir en línea independiente la denegación SELECT de cuenta SQL del portal usuarios 443 antes de habilitar login.

El código de GitHub de esta rama incluye directorio, alta y asignación pero carece de interfaz completa de editar/activar/desactivar/restablecer usuarios; el despliegue real 8443 puede diferir de esa rama, así que no sobreescribir features existentes. Implementar en rama/PR aislados, QA primero con una cuenta de prueba, sin cambios en producción sin autorización explícita.

190 usuarios (176 activos, 14 inactivos) y 114 migrados sin contraseñas inicializadas son la linea base. No repetir APPLY de migracion o activacion. Catalogos, empresas y Power BI quedan fuera del incremento administrativo, como backlog de conciliacion independiente.

## GUA-01 PRE-FLIGHT REAL 8443 — BANDERAS AUN APAGADAS (12/11/2026)

El operador obtuvo con lectura de `lib/env.php`/codigo declarado del backend 8443: `CODIGO_FASE_2B=PRESENTE`, `MODO=PASS` (`admin_escritura`), `GESTION_USUARIOS=OFF`, `GESTION_SENSIBLE=OFF`. El marcador 2B no sustituye una atestación de versión completa. La gestion productiva de escritura de usuarios **NO** quedó activada. Estado de los 190 usuarios sin cambios.

Ya existe código U-BASIC de UX-09A; no duplicar. Puerta próxima: verificar kit oficial y salvaguardas de P4b (K2/K1/A1/A2/A3-F10, D3/D4 y firmas de DBA/negocio) antes de ejecutar interruptor. Los controles de passwords P5-R permanecen separados y NO-GO. Detalles en [GUA-01-PREFLIGHT-ACTIVACION-8443-2026-11-12.md](GUA-01-PREFLIGHT-ACTIVACION-8443-2026-11-12.md).

## P4a confirmado en el servidor con kit oficial (12/11/2026)

El operador obtuvo `KIT_OFICIAL=PASS` (blob de `privilegios-usuarios.sh` contra GitHub `d849a014ff938e67d3a31d88da955be7fc453462`) y `PRIVILEGIOS_USUARIOS=PASS` al ejecutar el verificador **solo lectura** `--etapa no-sensibles --verificar`. Se marca `P4A_NO_SENSIBLES=PASS_REAL`, no requiere reiterar esa puerta.

**No confundir con P4b:** último estado de banderas de 8443 `GESTION_USUARIOS=OFF`, `GESTION_SENSIBLE=OFF`. Los bloqueos documentados en UX-09B sobre K2/K1/A1/A2/A3-F10 y D3/D4, firmas y ventana no quedan resueltos por la verificación de grants. Ya existe el kit U-BASIC para encender una vez se confirme su estado GO; no hacer cambios de `.env` o reinicios manuales. Detalles en [GUA-01-PREFLIGHT-ACTIVACION-8443-2026-11-12.md](GUA-01-PREFLIGHT-ACTIVACION-8443-2026-11-12.md).
