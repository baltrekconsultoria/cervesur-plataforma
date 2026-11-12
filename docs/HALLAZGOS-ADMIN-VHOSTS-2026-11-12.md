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

## 7. PuTTY: HTTPS corporativo 443 y 8443 confirmado (solo lectura)

Operador ejecutó `curl` HTTPS **sin credenciales** contra la interfaz interna VPN del servidor, revisando solo código HTTP y Content-Type, con validacion de certificado omitida para ese diagnostico (no para iniciar sesion). IP interna exacta deliberadamente omitida por tratarse de repositorio publico.

| Ruta sin autenticacion | 443 (usuarios corporativo) | 8443 (administracion corporativo) |
|---|---|---|
| `/` | `200 text/html` | `200 text/html` |
| `/api/ping` | `200 application/json` | `200 application/json` |
| `/api/users?status=all` | `403 application/json` | `401 application/json` |
| `/admin/usuarios` | `200 text/html` | `200 text/html` |

Hallazgos comprobados:
- Ambos HTTPS responden para `/` y `/api/ping`; no hay evidencia de caída de esos dos servidores en el momento del test.
- Ninguno devolvió una lista de usuarios anónima. El portal de usuarios 443 respondió 403 y el de administración 8443 respondió 401. La diferencia puede provenir de políticas por vhost/rol o de capas de autenticación; **no se ha determinado la causa**. No asumir que 443 usa el mismo backend que 8443.
- El 200 HTML de `/admin/usuarios` podría ser el fallback SPA de React. **No confirma** que el usuario admin logueado pueda gestionar usuarios ni que existan los 190 registros en ese entorno.
- La prueba omitió validación del certificado TLS solo para diagnóstico de códigos HTTP, sin credenciales. No repetir con credenciales usando `-k`.

## 8. Siguiente puerta: identificar backend real y su conexión MySQL, sin secretos

Acción propuesta para PuTTY, **SOLO LECTURA**, sin abrir `.env` ni mostrar variables de contraseña:
1. Revisar los dos archivos de VirtualHost de HTTPS 443 y 8443, mostrando exclusivamente los nombres de directivas Apache (`DocumentRoot`, `Alias`, `AliasMatch`, `ProxyPass`, `ProxyPassMatch`, `Include`, `SetHandler`), y contar las rutas configuradas sin imprimir valores que podrían contener secretos.
2. Verificar que ambos VirtualHosts sigan apuntando al origen esperado (backend PHP de 8443 y frontend React admin), usando solo nombres de archivos y rutas internas autorizadas para el operador; no subir esos paths a GitHub si son privados.
3. Ejecutar una lectura protegida que solo devuelva `DB_TARGET=PRODUCCION/OTRA/INDETERMINADA` para el backend efectivo de 8443 (comparando únicamente el nombre con `cervesur_portalbi`, sin mostrar host/usuario/password). No inferir la BD a partir del 200 de `/api/ping`.
4. Posteriormente, con cuenta de administrador existente y certificado TLS VALIDADO, comprobar `/admin/usuarios` y GET `/api/users?status=all` con JWT sin imprimir datos personales. Resultado buscado: 190 registros (176 activos, 14 inactivos) y controles de autorización admin/no-admin.

IMPORTANTE: migración y activación ya PASS (`190/176/14`, `114` migrados activos), pero credenciales de 114 siguen sin inicializar. No ejecutar APPLY previos. No tocar Flutter, QA 8088/8089, asignaciones ni enlaces Power BI.

## 9. Apache HTTPS: nombres de Define e Include (PuTTY, solo lectura)

El operador ejecuto un lector de directivas de dos vhosts. El resultado extraido fue:

| Portal | Define FRONT | Define BACKEND | Define SESSIONS | Include |
|---|---|---|---|---|
| Corporativo admin 8443 | `CERVESUR_USUARIOS_FRONT` | `CERVESUR_USUARIOS_BACKEND` | `CERVESUR_USUARIOS_SESSIONS` | `/etc/apache2/cervesur-react-admin/react-usuarios-common.conf` |
| Corporativo usuarios 443 | `CERVESUR_USUARIOS_FRONT` | `CERVESUR_USUARIOS_BACKEND` | `CERVESUR_USUARIOS_SESSIONS` | `/etc/apache2/cervesur-react-usuarios/react-usuarios-common.conf` |

**Importante:** estos son solo los nombres de las variables (`$2` en las sentencias `Define`), porque el filtro del operador no devolvio sus valores (`$3`) ni el contenido de los archivos `Include`. Coincidir en nombres NO prueba que frontend/backend/sesiones apunten al mismo directorio ni que la base de datos sea igual. Cada portal tiene un `Include` distinto. Tampoco se ha demostrado si las definiciones Apache son efectivamente locales o si existe riesgo de colision al reutilizar nombres globales; requiere analizar directivas efectivas antes de cambiar Apache.

### Puerta siguiente (solo lectura, sin valores sensibles)

1. Comparar **solo igualdad/diferencia** de los valores literales de `Define CERVESUR_USUARIOS_FRONT`, `CERVESUR_USUARIOS_BACKEND`, `CERVESUR_USUARIOS_SESSIONS` en los dos vhosts, sin mostrar los valores ni rutas privadas.
2. Comprobar por nombre de variable si el backend de 8443 tiene loader `lib/env.php` y si `www-data` puede leerlo, sin imprimir el archivo.
3. Inspeccionar filtradas las directivas `Alias`, `AliasMatch`, `ProxyPass*`, `DocumentRoot`, `Include` de los archivos comunes (ocultando valores), para comprobar si ambos vhosts montan realmente la API.
4. Solo con el backend efectivo confirmado, ejecutar el loader `env.php` en modo lectura y reportar exclusivamente `DB_TARGET=PRODUCCION/OTRA/INDETERMINADA`, nunca `DB_PASS`, `DB_USER`, valores de `.env` ni acceso de usuarios.

Estado: **sin cambios en Apache ni MySQL**, usuarios productivos 190 total / 176 activos / 14 inactivos, 114 activados aun sin credenciales inicializadas. PR #23 sigue borrador.
