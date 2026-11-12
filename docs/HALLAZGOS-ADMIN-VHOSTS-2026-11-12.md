# Cervesur - Hallazgos consolidados de despliegue y administracion (2026-11-12)

> Registro de continuidad para posteriores chats y revisiones de GitHub. Fuente: salidas de PuTTY aportadas por el operador, salvo lo rotulado como inferencia de nombres de vhost. Todas las pruebas descritas fueron de solo lectura.
> SEGURIDAD: el repositorio es PUBLICO. Se omite la IP interna concreta de la interfaz VPN y no se incluyen tokens, contrasenas, usuarios, hashes o datos de clientes.

## 1. Datos de usuarios cerrados y verificados

- Importacion transaccional users-only: 114 cuentas nuevas, 76 preexistentes intactas, total 190; postflight PHP independiente PASS.
- Activacion transaccional limitada a 114 nuevos: APPLY=PASS, 0 antiguos modificados, TOTAL=190, ACTIVOS=176, INACTIVOS=14.
- Postflight de activacion en MySQL PASS: MIGRADOS_ACTIVOS=114, ANTIGUOS_INTACTOS=76, MYSQL_MODIFICADO=NO.
- Contraseñas de los 114 importados NO inicializadas: hashes aleatorios de credenciales desconocidas. Los usuarios activos no pueden iniciar sesión hasta proceso individual seguro; no se generaron sesiones durante activación.
- Respaldos de 76 antes de importar y de 190 antes de activar en snapshots protegidos 0600 fuera de DocumentRoot. No compartir ni publicar copias.
- No repetir modos APPLY de los scripts de importacion/activacion. No se migraron empresas, informes ni asignaciones.

## 2. Primer inventario en host, PHP/React

- Se observaron index.html en Flutter del arbol /var/www/cervesur/frontend y en los document roots de dos React de prueba.
- HTTP GET /api/ping a localhost puerto 80: 404 text/html; no diagnostica por si solo fallo de Flutter.
- GET /api/ping a 127.0.0.1:8088 y :8089: 200 application/json.
- GET /api/users?status=all SIN JWT en 8088 y 8089: 401 application/json en ambos; directorio protegido frente a solicitud anonima.
- GET /api/users?status=all SIN JWT a 127.0.0.1:80: 404 text/html. No prueba que la ruta corporativa sea inaccesible (puede responder VirtualHost por defecto).

## 3. Apache: VirtualHosts mostrados con apache2ctl -S

| Puerto | VirtualHost habilitado (basename) | Bind segun apache2ctl | TCP LISTEN mostrado por ss -lnt | Interpretacion |
|---|---|---|---|---|
| 80 | 000-default.conf, cervesur.conf | *:80, default mas nombre corporativo | SI | La URL 127.0.0.1 puede responder al vhost default; no confundir con produccion corporativa |
| 443 | cervesur-react-usuarios-corp.conf | interfaz interna corporativa | SI | Candidato portal React de usuarios corporativos; nombres no certifican API/BD |
| 8443 | cervesur-react-admin-corp.conf | interfaz interna corporativa | SI | Candidato portal React administrativo corporativo; debe verificarse |
| 8088 | cervesur-react-prueba.conf | 127.0.0.1 | SI | React prueba, API 200, users anonimo 401 |
| 8089 | cervesur-react-compat.conf | 127.0.0.1 | SI | React compatibilidad, API 200, users anonimo 401 |
| 8090 | cervesur-react-ro.conf | 127.0.0.1 | NO | Vhost configurado pero puerto no visible como LISTEN |
| 8091 | cervesur-react-piloto.conf | 127.0.0.1 | NO | Vhost configurado pero puerto no visible como LISTEN |
| 8092 | cervesur-react-usuarios.conf | 127.0.0.1 | NO | Vhost configurado pero puerto no visible como LISTEN |
| 8093 | cervesur-react-admin.conf | 127.0.0.1 | NO | Vhost configurado pero puerto no visible como LISTEN |
| 8094 | cervesur-react-admin-qa.conf | 127.0.0.1 | NO | Vhost configurado pero puerto no visible como LISTEN |

Las rutas de vhost provienen de /etc/apache2/sites-enabled/; el hecho de que Apache registre un VirtualHost no demuestra que haya un socket escuchando. El listado ss -lnt mostro escucha solo en 80, 443, 8443, 8088 y 8089. Puertos 8090-8094 no deben anunciarse como operativos.

El resultado no demuestra acceso desde fuera de la VPN, que los puertos tengan login funcional, que el React de 443/8443 este actualizado ni que apunten a cervesur_portalbi. Son verificaciones pendientes.

## 4. Lo que ya existe en el codigo fuente

- frontend/src/app/App.tsx: ruta /admin/usuarios protegida por rol admin.
- frontend/src/features/admin/AdminPages.tsx: listado, filtros, busqueda, paginacion visual; alta de usuario activo con contraseña nueva.
- frontend/src/api/http/httpApi.ts: GET /api/users?status=all, lectura paginada per_page=100 y consolidacion con asociaciones empresa-usuario.
- backend/router/UserRouter.php: directorio administrativo protegido con JWT y rol admin.
- backend/controllers/AuthController.php + lib/auth_middleware.php: password_verify, is_active=1, role valido, session_version vigente.
- Funcionalidad no demostrada en produccion: conexion React corporativo a MySQL productivo; edicion y desactivacion/reactivacion de usuarios desde UI; inicializacion/reinicio individual de password y auditoria.

## 5. Proxima puerta aprobada: verificar 443 / 8443 sin credenciales (SOLO LECTURA)

1. Realizar curl HTTPS sin autenticacion contra IP interna del servidor (no ponerla en GitHub), mostrar solo HTTP status y Content-Type para /api/ping, /api/users?status=all y /admin/usuarios de 443/8443.
2. En diagnostico sin login se puede usar --insecure si el certificado de prueba no valida para IP, exclusivamente para recoger codigos; NO introducir credenciales con --insecure ni inferir que el certificado es confiable.
3. Si /api/users?status=all responde 200 application/json sin JWT en algun vhost, STOP y auditoria. 401 JSON esperado para API protegida; 404/301/302/200 HTML pueden ser vhost distinto o fallback React y requieren identificar destino.
4. Leer solamente metadatos de Apache, DocumentRoot/backend y la BD configurada mediante verificadores que oculten valores sensibles. No mostrar .env ni JWT.
5. Unicamente despues de comprobar configuracion, activar administracion React corporativa para 190 usuarios y diseñar establecimiento individual de contraseñas (token de un uso, hash, expiracion, rate limit, auditoria, revocacion de sesiones), evitando un password comun.

## 6. Restricciones operativas

- No editar vhosts, DNS, certificados, servicios ni puertos en el diagnostico.
- No sustituir Flutter ni conectar instancias QA 8088/8089 a la DB productiva.
- No tocar empresas, informes, enlaces Power BI ni asignaciones en este incremento.
- Mantener PR #23 en borrador hasta QA de la siguiente fase y una decision explicita de merge.
- Registrar cada salida agregada del host en este documento y el issue #17 con fecha, valores exactos, riesgos y siguiente puerta; nunca datos personales ni secretos.
