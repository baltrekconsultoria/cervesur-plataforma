# UX-09A · Lote 1 (K2a + K2c + K2d + K2e): requisitos y hoja de la ventana

Estado: **preparación documental. No autorizado, no ejecutado.** Complementa `inspeccion-flutter/FICHA-LIBERACION-LOTE1-K2-20261106.md` (compatibilidad, pruebas y riesgos). Los comandos son para el **operador autorizado**; esta sesión no los ejecuta. Una sola ventana para este lote; no se mezcla con P3, P4 ni con otro lote.

## 1. Requisitos para solicitar y autorizar la ventana

| # | Requisito | Estado a hoy | Responsable |
|---|---|---|---|
| P-1 | Frontend desplegado = analizado | PASS (`main.dart.js` y `version.json`); C-5 cierra los otros 4 archivos de arranque | Operador |
| P-2 | Aceptación firmada (D-6 y D-15, texto sin «éxito aparente») | PENDIENTE. Texto en `P2-ACEPTACION-RESPONSABLE-FUNCIONAL-20261106.md` | Funcional |
| P-3 | Personas y canal del procedimiento de cambio de credenciales | PENDIENTE (`PROCEDIMIENTO-CAMBIO-SECRETO-POWERBI-20261106.md`) | Funcional |
| P-4 | Fecha, hora y responsable de la ventana | PENDIENTE (coordinación autorizada) | Infraestructura |
| P-5 | Copia respaldada y verificación previa sin errores | Se hace en la ventana (paso 2) | Infraestructura |
| P-6 | Cuentas de prueba (usuario normal y administrador) y **una empresa de prueba con credenciales ficticias** | PENDIENTE | Funcional |
| P-7 | Autorización `A-L1` por escrito | PENDIENTE; se emite al final | Funcional |
| P-8 | `php -v` y `opcache.validate_timestamps` | C-5 (sección 2 del shell) | Operador |
| P-9 | Código de la Cloud Function | **Ya no es requisito** (decisión de arquitectura); se conserva como documentación heredada | — |
| — | Revisión de C-5 y su resultado | C-5 revisado; resultado pendiente de ejecución | Operador |

## 2. Huellas esperadas

| Archivo | SHA-256 |
|---|---|
| `backend/router/EmpresaRouter.php` **antes** | empieza por `a50a3c1c2a78` (`a50a3c1c2a7895e13914a015…`) |
| `backend/router/EmpresaRouter.php` **después** | `0c710e8ef8e5f367dcab54ba57a39083e9e2c997a799359a41062137290177b7` |
| `backend/lib/empresa_sanitizer.php` (nuevo) | `cf680885963d6d518e09995f9b1f4b2f7e4105a7aa7f0ec64db319c36b59f77e` |
| Parche `K2a+K2c+K2d+K2e-empresa-router.diff` | `f5df3f988cfc6dd203f7764877dd7c01ea8a826a3ff49aaec72e2a775e91e54c` |
| `verificar-parches-en-copia.sh` | `3784cc0cdf597b9f415d51b584d1b0400615faa97c08338ad1e509926e4e947b` |

## 3. Hoja de ejecución (operador con `sudo`; `PAQ` = carpeta con el paquete copiado y verificado)

```
# 0. Verificar el paquete (las tres huellas de la tabla 2 deben coincidir)
cd "$PAQ" && sha256sum lote1-k2/lib/empresa_sanitizer.php "lote1-k2/K2a+K2c+K2d+K2e-empresa-router.diff" verificar-parches-en-copia.sh

# 1. Respaldo fuera del docroot, con permisos restringidos y huella
B=/var/backups/cervesur-legado/lote1-$(date -u +%Y%m%dT%H%M%SZ)
sudo install -d -m 700 "$B"
sudo cp -p /var/www/cervesur/backend/router/EmpresaRouter.php "$B/"
sudo sha256sum "$B/EmpresaRouter.php" | sudo tee "$B/EmpresaRouter.php.sha256"     # debe empezar por a50a3c1c2a78
sudo stat -c '%U:%G %a' /var/www/cervesur/backend/router/EmpresaRouter.php | sudo tee "$B/permisos.txt"

# 2. Verificación previa en una COPIA llamada "backend" (nada se modifica en producción)
T=$(mktemp -d) && sudo cp -a /var/www/cervesur/backend "$T/backend"
bash "$PAQ/verificar-parches-en-copia.sh" "$T/backend" "$PAQ" 1          # sin «SHA DIFERENTE» ni «PARCHE NO APLICA EXACTO»

# 3. Aplicar (parar si el paso 2 falló o si el sha256 del router no empieza por a50a3c1c2a78)
cd /var/www/cervesur
sudo patch -p1 -F0 --no-backup-if-mismatch --dry-run -i "$PAQ/lote1-k2/K2a+K2c+K2d+K2e-empresa-router.diff"
sudo patch -p1 -F0 --no-backup-if-mismatch          -i "$PAQ/lote1-k2/K2a+K2c+K2d+K2e-empresa-router.diff"
sudo install -m 0644 "$PAQ/lote1-k2/lib/empresa_sanitizer.php" backend/lib/empresa_sanitizer.php
sudo chown --reference="$B/EmpresaRouter.php" backend/router/EmpresaRouter.php
sudo chmod --reference="$B/EmpresaRouter.php" backend/router/EmpresaRouter.php
sudo chown --reference=backend/lib/auth_middleware.php backend/lib/empresa_sanitizer.php
sudo chmod --reference=backend/lib/auth_middleware.php backend/lib/empresa_sanitizer.php
sudo php -l backend/router/EmpresaRouter.php && sudo php -l backend/lib/empresa_sanitizer.php
sha256sum backend/router/EmpresaRouter.php backend/lib/empresa_sanitizer.php    # deben ser las huellas «después» de la tabla 2

# 4. Solo si opcache.validate_timestamps está desactivado (resultado de C-5): recarga suave del servicio
#    sudo systemctl reload apache2
```

## 4. Verificación posterior (en este orden; no se salta ningún paso)

| # | Verificación | Quién | Resultado esperado |
|---|---|---|---|
| V1 | **Otra persona** repite `sha256sum` de los dos archivos | Observador | Coinciden con la tabla 2 |
| V2 | Con la cuenta de prueba, abrir la lista de empresas en Flutter con las herramientas del navegador (pestaña *Network*) y mirar la respuesta de `/api/empresas` | Operador + funcional | **No** contiene `powerbi_clientsecret`; sí `powerbi_tenant` y `powerbi_client`; la lista se ve completa |
| V3 | Abrir un informe | Funcional | Carga |
| V4 | Edición normal (cambiar, p. ej., la ciudad) de la **empresa de prueba** | Funcional | Diálogo «Guardado»; el cambio se ve; el secreto sigue igual |
| V5 | **Solo si V1 y V2 pasaron.** En la empresa de prueba, escribir **un texto ficticio** en el campo «Power BI Client Secret» y guardar | Funcional | Diálogo «Observación» (con el mensaje si la función lo reenvía); **nada** cambió en la empresa |
| V6 | **Prueba de K2e por la API**, no por la app (la app no envía campos de Power BI al crear): con un token de administrador de prueba, `POST /api/empresas` con un RUC ficticio de prueba y `powerbi_clientsecret` ficticio | Operador | HTTP 400 con el mensaje de alta; **no** se crea ninguna empresa. Si se creara una fila de prueba (parche inactivo), se da de baja con `DELETE` de esa empresa de prueba y se revierte el lote |

## 5. Puntos de parada y reversión

**Parada inmediata:** el paso 2 o 3 falla; el `sha256` del router no coincide; un 5xx nuevo en `/api/empresas*`; la app no lista empresas; un informe no abre; V1 o V2 fallan (**no se hace ninguna prueba de escritura**); V4 no guarda; V5 muestra «Guardado»; V6 crea una empresa.
**Reversión (menos de 5 minutos):**
```
sudo cp -p "$B/EmpresaRouter.php" /var/www/cervesur/backend/router/EmpresaRouter.php
sha256sum /var/www/cervesur/backend/router/EmpresaRouter.php      # debe empezar por a50a3c1c2a78
# (el archivo empresa_sanitizer.php puede quedar: nadie lo carga sin el router parcheado)
# recarga suave solo si el paso 4 se hizo
```
Cambiar el modo sin tocar el router (intervención propia, con respaldo): constantes `EMPRESA_SECRETO_PUT` y `EMPRESA_SECRETO_POST`.

## 6. Evidencia que se archiva

Salida de los pasos 1 a 3 y de V1; captura de V2 (sin tokens ni cabeceras visibles); resultado de V4 a V6; nombres de quienes ejecutaron y observaron; hora de inicio y fin. **Sin** credenciales, tokens ni valores de secretos.

## 7. Qué NO hace este lote

No cierra A2, A1 ni K1; no rota secretos ya expuestos (D-5); no cambia el `PUT` de usuarios normales (eso es el Lote 4); no toca RC1c, el 8443, Apache, `.env` ni la base de datos.
