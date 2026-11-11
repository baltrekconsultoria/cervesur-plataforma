# Cervesur · P5-SEC03 — Integración reproducible de middleware Flutter (candidato aislado)

**Fecha:** 11/11/2026. **Estado:** código y QA aislado; **NO-GO para producción, merge o activación de P5-R**. Rama apilada sobre PR #15.

## Línea base real y reutilización de QA

- Flutter en servidor: `/var/www/cervesur/backend/lib/auth_middleware.php`. Identidad D3 confirmada **5/5** en la auditoría del 11/11/2026.
- Copia local del operador: `Documents/Cervesur-P5-SEC01/auth_middleware.original.php`, **5079 bytes**, SHA-256 completo fijado en el constructor. Una copia revisada se adjuntó para inspección, sin secretos literales identificados en ese archivo.
- La evidencia previa de Windows **20/20 middleware real adaptado PASS** y de Docker Desktop **24/24 PHP HTTP/MySQL 8 PASS** se conserva. No reetiquetar esas pruebas como faltantes ni como QA de producción.
- PR #15: JWT/helper/adapter/HTTP sintético con **64/64 PASS**. Este trabajo añade un constructor reproducible y una política explícita de TTL actual.

## Procedimiento de construcción local (NO de despliegue)

El script versionado `scripts/build_p5_sec03_candidate.py` exige que la fuente original coincida **byte a byte** con SHA-256 fijado. Rechaza archivos distintos, previamente parcheados, con marcadores múltiples o destino existente. Conserva `get_jwt_secret_or_fail`, `get_jwt_expiration` y `require_auth` y su respuesta 401; sustituye **solo** `authenticate_user` para invocar `p5_legacy_authenticate_headers` con el PDO y secreto existentes.

El resultado incluye `auth_middleware.php` modificado, `p5_legacy_adapter.php`, `p5_strict_auth.php` y un manifiesto SHA-256 en un directorio **local fuera del repositorio**, sin sobrescribir el original.

En Windows PowerShell, desde una copia legítima del repositorio con la rama PR P5-SEC03 seleccionada:

```powershell
$src = Join-Path $env:USERPROFILE "Documents\Cervesur-P5-SEC01\auth_middleware.original.php"
$out = Join-Path $env:USERPROFILE "Documents\Cervesur-P5-SEC03-CANDIDATO"
py -3 .\deploy\flutter-heredado\p5-sec01\scripts\build_p5_sec03_candidate.py --source "$src" --out "$out"
```

Si Python 3 no está disponible, no ejecutar cambios alternativos en servidor: usar el ambiente local de desarrollo autorizado. **No usar rutas de producción como salida, no copiar el original al repositorio**. El comando solo construye candidato LOCAL, no modifica Apache, PHP, MySQL ni el frontend.

## Contrato JWT y TTL

- Producción D4 reportó `JWT_EXPIRATION=10800` segundos (**3 h**), mientras una decisión anterior proponía `86400` (24 h). **No se altera la configuración de producción**.
- En el candidato, `p5_sec03_ttl_from_env()` exige que el servidor tenga `JWT_EXPIRATION` numérico, entero y dentro de `1..86400`. Valor ausente/incorrecto -> 401 (fail-closed) hasta corregir configuración por autorización.
- El helper estricto comprueba firma HS256, `user_id`, `session_version`, `exp`, estado de usuario, rol/identidad actual en DB y expiración **restante** no mayor que TTL actual + tolerancia 60s. Rechaza JWT antiguos sin claims obligatorios.
- **Limitación explícita**: los JWT del login legado carecen de `iat`; la comprobación no prueba duración total desde la emisión. Se necesita atestación de los tipos de claims que emite AuthController real y prueba de Flutter real.
- Se preserva `lib/jwt.php` productivo como dependencia antigua, pero `authenticate_user` usa la **verificación estricta propia**. Hay que comprobar que no existan rutas que llamen directamente `validate_jwt` saltando `authenticate_user`.

## Pruebas y puertas pendientes

1. Constructor sobre original aportado: SHA256 exacto PASS, anclas PASS, candidato PHP `php -l` PASS en entorno aislado de revisión; **no se publica el original**.
2. CI del PR: Python unit test de constructor, PHP sintaxis + núcleo JWT (incluye TTL 10800), adapter y HTTP loopback SQLite sintético; registrar resultados por commit.
3. Repetir prueba integral **solo si cambia el código** de integración: PHP/MySQL aislado y regresión Flutter con el candidato literal; conservar evidencias previas 20/20 y 24/24.
4. **T-EXP**: sesión QA Flutter expirada, petición GET 401 y reacción visual comprensible, después relogin 200, sin tokens/HAR ni datos personales.
5. C-5 distribución de roles reales agregada, tipo `user_id` / `session_version` del JWT actual y privilegio SELECT sobre tabla `user`, D4 secretos y expiración, K1/K2/F-10, permisos DBA y gates U-BASIC/P5-R.
6. Backups/rollback de la ventana productiva, verificación de cambios y autorización formal.

**Nunca** cambiar flags de producción ni `ADMIN_WRITES_USUARIOS_SENSIBLE` para hacer pasar pruebas. **No desplegar** el candidato con este PR.
