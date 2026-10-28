# UX-07D.0 · Preparación de la primera versión para despliegue de prueba

Estado: **preparado, NO desplegado**. Nada se instaló, publicó ni ejecutó contra producción ni MySQL real.

> **Actualización UX-07D.1B (sin dominio ni HTTPS):** el primer despliegue de prueba **ya se instaló y validó** en el servidor de pruebas por túnel SSH a `127.0.0.1:8088` (VPN + SSH), accesible en Windows como `http://localhost:18089`. Sigue sin ser producción. El procedimiento, los scripts, el ensayo en Docker y la reversión están en [INSTALACION-REACT-TUNEL-SSH.md](INSTALACION-REACT-TUNEL-SSH.md). Las secciones de este documento que mencionan un subdominio HTTPS siguen siendo la referencia para un despliegue posterior con certificado.

## 1. Problemas de despliegue encontrados

| # | Problema | Resolución |
|---|----------|------------|
| 1 | El proveedor por defecto es `mock` y el HTTP solo aceptaba `localhost`/`127.0.0.1`. | Compilación de servidor (`VITE_DEPLOY_TARGET=server`) con proveedor HTTP obligatorio, mismo origen y dominios autorizados. |
| 2 | Una compilación normal contenía cuentas demo, `DemoControls` y el aviso «Entorno QA». | Se eliminan por sustitución estática de Vite; el verificador lo comprueba en el bundle. |
| 3 | Configuración errónea podía caer en silencio a datos simulados. | `ConfigurationError` + `ConfigurationBoundary`: pantalla «Portal no disponible», sin datos de demostración. |
| 4 | Sin reglas Apache para rutas de React ni `/api`. | `frontend/public/.htaccess` + `deploy/apache/` (verificado en Docker, 40/40). |
| 5 | Migración UX-07C con varios `ALTER`: una falla intermedia dejaba el esquema a medias. | Una sola sentencia atómica en `up` y `down` + SQL de precondiciones. |
| 6 | `.gitignore` bloqueaba `*.sql` de migraciones/esquema. | Excepción para `database/migrations/` y `database/schema/`. |
| 7 | Sesión PHP (`PHPSESSID`) en cada petición, sin atributos explícitos. | Recomendación (sección 3); no se cambia código del backend en esta fase. |
| 8 | Endpoint heredado de tokens de Power BI sigue en el backend compartido. | Bloqueado en el vhost de prueba de React; no lo usa el cliente. |

## 2. Configuración de producción propuesta

Compilación (reproducible, solo variables públicas):

```
cd frontend
CERVESUR_ALLOWED_HOSTS=<dominio-autorizado> npm run build:production
npm run verify:production
```

Genera `dist-production/` y `build-info.json`. Variables internas fijadas por el script:
`VITE_DEPLOY_TARGET=server`, `VITE_API_PROVIDER=http`, `VITE_API_BASE_URL=same-origin`, `VITE_ALLOWED_HOSTS`.
Falla si falta el dominio, si el proveedor no es HTTP, si hay URL de API arbitraria o si el bundle contiene marcadores de mock/QA/secretos.
En ejecución exige HTTPS salvo loopback y que el `host` esté autorizado.

Apache: `deploy/apache/cervesur-react-prueba.vhost.conf.example` y `react-common.conf` (referencia, no aplicada):
`AliasMatch ^/api` → `backend/api.php` (backend fuera del DocumentRoot, `.env` fuera del web root),
`AllowOverride FileInfo AuthConfig Indexes Options=Indexes`, `/api/powerbi` bloqueado.

Cabeceras (`.htaccess`): CSP `default-src 'self'; … frame-src https://app.powerbi.com; frame-ancestors 'none'`, `nosniff`,
`X-Frame-Options: DENY`, `Referrer-Policy`, `Permissions-Policy`, HSTS solo sobre HTTPS (sin `includeSubDomains`).
Caché: `index.html` sin caché; `/assets/*` inmutable un año.

Endurecimiento recomendado del servidor: `expose_php=Off`, `ServerTokens Prod` (global, lo decide el administrador), `session.cookie_secure=1`.
`react-common.conf` ya fija en el sitio de prueba `session.cookie_httponly` y `session.cookie_samesite=Strict`.

**Configuración mínima Apache** (módulos `rewrite headers alias ssl` + PHP): un VirtualHost propio con `ServerName` del subdominio de prueba, dos `Define`
(`CERVESUR_REACT_FRONT` = contenido de `dist-production/`, `CERVESUR_REACT_BACKEND` = copia del backend FUERA del DocumentRoot con su propio `.env`),
certificado del subdominio e `Include` de `react-common.conf`. Los nombres de `Define` llevan prefijo `REACT` porque en Apache son globales al servidor y no deben chocar con la configuración de Flutter.
Todas las directivas (`DocumentRoot`, `<Directory>`, `AliasMatch`, `ServerSignature`) están dentro de ese VirtualHost: no afectan a otros sitios.
Durante la prueba, restringir el acceso por IP o autenticación básica (ver comentarios del vhost de ejemplo) y retirarlo al terminar.

**Endpoint heredado de tokens OAuth de Power BI (`/api/powerbi…`)**: sigue existiendo en el backend PHP y devuelve un token general.
El sitio de prueba de React lo bloquea (403) y el cliente React no lo usa. **Su compatibilidad con Flutter aún debe evaluarse antes de cualquier despliegue productivo**
(Flutter podría depender de él); mientras tanto no se elimina ni se modifica.

## 3. Power BI en la compilación de servidor

Solo el visor público oficial (`https://app.powerbi.com`) dentro de un iframe; sin `/qa/powerbi-view.html`, sin modo QA-real,
sin token en el navegador, sin Power BI Embedded privado. La autorización por usuario/empresa se hace en PHP (`viewer`).

## 4. MySQL: inventario y migración (solo lectura)

- `database/schema/UX-07D0-inventario-solo-lectura.sql`: tablas, columnas, claves, roles/estados, incompatibilidades. Solo agregados, sin PII.
- `database/schema/UX-07D0-precondiciones-migracion.sql`: impacto de UP, bloqueos de DOWN, datos que las validaciones nuevas rechazarían.
- Migración UX-07C: `up` = `grupo_id` NULL + `enlace` 1024; `down` = reversa atómica. Si hay informes sin grupo o enlaces >255, el `down` **falla entero** sin tocar nada (estricto por defecto en MySQL 8). Ejecutar antes la sección C de precondiciones.
- **Estado**: ambos SQL de inventario **no se han ejecutado contra ninguna base real**; solo se probaron en MySQL sintético. Ninguna migración se ejecutó en producción.
- **Bloqueos DDL**: `ALTER TABLE … MODIFY` puede reconstruir la tabla `reporte` y tomar bloqueos de metadatos; una transacción larga abierta sobre `reporte` retrasa el `ALTER` y bloquea las demás consultas en cola.
  Ejecutar en ventana de baja actividad, comprobando antes que no haya transacciones abiertas (`SHOW PROCESSLIST`).
- **Respaldo y restauración**: antes de cualquier migración real, `mysqldump` de la base (o al menos de `reporte`) y verificación de que se puede restaurar en una base de prueba.
  El DDL no es transaccional: la única reversión garantizada es la restauración del respaldo; el `down` solo es seguro si las precondiciones de la sección C dan 0.
- La migración es **opcional**: sin ella la app rechaza con mensaje claro informes sin grupo y enlaces largos.
- Se asume que antes `grupo_id` era NOT NULL y `enlace` de 255; el inventario lo confirma o corrige.

## 5. Convivencia con Flutter

- Portal React en **subdominio/VirtualHost propio** con HTTPS; Flutter sigue en `/var/www/cervesur/frontend`, que **no se toca ni reemplaza**.
- Aislamiento de sesión: cookies por host; no usar `Domain=` compartido.
- Backend de prueba: copia separada con **base de datos de prueba separada** (datos sintéticos); no escribir en el backend productivo.
- HSTS sin `includeSubDomains` para no afectar a Flutter.
- Reversión: `a2dissite <sitio-react>` + `systemctl reload apache2`; Flutter no se ve afectado.

## 6. Plan de despliegue de prueba y reversión (para UX-07D.1, con autorización)

1. Respaldo de la carpeta destino y de la BD de prueba.
2. `build:production` + `verify:production` en CI/local; copiar `dist-production/` al DocumentRoot de prueba.
3. Copiar backend de prueba fuera del web root con `.env` propio (no versionado).
4. Habilitar el vhost de `deploy/apache/`, `apachectl configtest`, reload.
5. Humo: login, listado, visor público, 401/403/404 JSON en `/api`, rutas profundas de React.
6. Reversión: deshabilitar vhost, restaurar carpeta/BD respaldada.

Pasos detallados de instalación de prueba (para UX-07D.1, no ejecutados): crear el subdominio y su certificado; crear directorios `/var/www/cervesur-react-prueba/public` y
`/opt/cervesur-react-prueba/backend` (rutas de ejemplo); copiar `dist-production/` y el backend; crear a mano el `.env` de la BD de **prueba** (permisos 600, fuera del repositorio);
copiar `react-common.conf` y el vhost adaptado; `a2enmod rewrite headers alias ssl`; `apachectl configtest`; recargar; ejecutar el humo del paso 5.
Reversión completa: `a2dissite`, recarga, y borrar los dos directorios de prueba; Flutter y su base no se tocan.

## 7. Riesgos que bloquean el MVP real

1. Esquema productivo no contrastado: correr el inventario con autorización y comparar con `tests/isolated/seed.php`.
2. Roles productivos fuera de `admin/user/usuario` perderían acceso (SEC-05A): revisar sección 3 del inventario.
3. Enlaces públicos de Power BI son visibles para quien tenga la URL; es propio de «Publicar en web».
4. Atributos de la cookie de sesión PHP y cabeceras dependen de la configuración del servidor.
5. Las suites con PHP/MySQL reales no corren en CI (solo local/Docker).
6. Validación funcional de Flutter en paralelo pendiente.

## 8. Resultados de pruebas

PHP lint OK · estáticas 15 · unit OK · HTTP base 50 · paginación 228 · hardening 45 · admin 36 · empresa/informe 67 · visor 56 ·
frontend lint/typecheck OK · unit 198 · E2E simulada 85 · contratos 199 (0 fallos, 4 hallazgos conocidos) · E2E real 185 ·
Apache en Docker 40/40 · compilación de servidor verificada · escaneo de secretos limpio (incluye `dist-production`).

## 9. GO / NO-GO para UX-07D.1

**GO condicionado**: técnicamente listo para un despliegue de **prueba** aislado. Condiciones: autorización explícita, dominio definido,
inventario SQL de solo lectura revisado y backend/BD de prueba separados. **NO-GO** para sustituir Flutter o apuntar a producción.
