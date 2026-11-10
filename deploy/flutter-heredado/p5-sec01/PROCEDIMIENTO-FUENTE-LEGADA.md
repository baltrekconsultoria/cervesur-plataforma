# P5-SEC01 · Procedimiento de custodia de fuente heredada — SOLO LECTURA

**Estado:** instrucción para el operador autorizado. No se ejecuta contra producción por GitHub Actions. No autoriza merge, despliegue, apagado, reinicio, escritura ni alteración de archivos. NO subir al repositorio copias originales ni saneadas sin revisión de seguridad y permiso de divulgación.

## Objetivo

Recuperar exclusivamente el contenido exacto del `/var/www/cervesur/backend/lib/auth_middleware.php` **desde una sesión autorizada de VPN/SSH**, de forma que el equipo técnico pueda construir un parche mínimo sobre el archivo realmente instalado. D3 identificó 5079 bytes y SHA-256 con prefijo `fbdf6253eec6`, confirmados por P5-A4. El repositorio contiene fragmentos, **no el archivo productivo completo**.

## Extracción sin modificar el servidor

En PowerShell de Windows del custodio autorizado, con la VPN activa:

```powershell
$dest = Join-Path $env:USERPROFILE 'Documents\Cervesur-P5-SEC01'
New-Item -Path $dest -ItemType Directory -Force | Out-Null
ssh usr_cervesur@192.168.200.12 'test -r /var/www/cervesur/backend/lib/auth_middleware.php && echo P5_LEIBLE=SI || echo P5_LEIBLE=NO'
```

Sólo si aparece `P5_LEIBLE=SI`:

```powershell
scp 'usr_cervesur@192.168.200.12:/var/www/cervesur/backend/lib/auth_middleware.php' (Join-Path $dest 'auth_middleware.original.php')
$file = Join-Path $dest 'auth_middleware.original.php'
$bytes = (Get-Item -LiteralPath $file).Length
$sha256 = (Get-FileHash -Algorithm SHA256 -LiteralPath $file).Hash.ToLowerInvariant()
"ARCHIVO_BYTES=$bytes"
"COINCIDE_HUELLA_D3=$($bytes -eq 5079 -and $sha256.StartsWith('fbdf6253eec6'))"
```

**Si no es legible:** detenerse y pedir a Infraestructura una entrega cifrada de solo lectura. NO usar `chmod`, `chown`, cambiar grupos, editar el archivo, ni crear archivos temporales en el servidor para evadir permisos.

Esta transferencia lee un archivo PHP y crea sólo una copia en el **equipo local**. No pide `.env`, `JWT_SECRET`, contraseñas reales, tokens, volcado SQL ni datos personales.

## Higiene y entrega

- Conservar el original local bajo custodia; no publicarlo ni subirlo sin revisión.
- Abrirlo **localmente** y comprobar si existen literales incrustados (JWT_SECRET, tokens, passwords, API keys, URLs con credenciales, rutas que contienen secretos, etc.). No usar una herramienta de publicación automática para redactarlo.
- Crear copia saneada con sólo **los valores sensibles** reemplazados por `REDACTED_LOCAL` cuando sea seguro, preservando estructura, funciones y condiciones. Registrar la huella SHA-256 del **original** por un canal autorizado; la copia saneada tendrá otra huella.
- Compartir únicamente la copia saneada por un canal aprobado para material de código privado, nunca mediante un PR público o una incidencia que muestre secretos.
- No transmitir siquiera la copia saneada si el equipo de Infraestructura no autoriza esa divulgación.

## Integración posterior (todavía NO autorizada para desplegar)

1. Analizar el archivo saneado y confirmar que la estructura de `authenticate_user()` y el PDO existente permiten la llamada al helper `candidato/p5_legacy_adapter.php`.
2. Redactar un parche **mínimo** contra la copia exacta; preservar la función `require_auth()`, sus respuestas HTTP y todos los caminos no relacionados.
3. Reejecutar D3 y las pruebas de P5-SEC01 con copia real en PHP/MySQL aislados; verificar roles actuales, `session_version`, versiones de JSON y login Flutter.
4. Validar C-5, D4 (86400 s), reacción de Flutter a 401, permisos mínimos, rollback, firmas y autorizaciones. Sólo entonces solicitar ventana de producción.

**Resultado esperado de esta etapa:** `COINCIDE_HUELLA_D3=True` en Windows y una fuente saneada revisable. No se aceptará como integración terminada hasta contar con ella.
