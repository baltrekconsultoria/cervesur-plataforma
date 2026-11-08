# Generador de altas temporales (UX-09A)

Puente entre el cierre del registro público (F-10) y el alta de React (P4c). Procedimiento, roles, auditoría, riesgo de la contraseña inicial y condiciones: `docs/UX-09A-PROCEDIMIENTO-ALTA-TEMPORAL.md`.

**Quién lo usa:** el **custodio** (sin acceso a la base de datos). **Quién ejecuta el SQL:** únicamente el **DBA**, que recibe el archivo `.sql` (solo el hash bcrypt) y no conoce la contraseña.

- `generar-alta.php`: valida los datos, genera una contraseña temporal y **imprime** la SQL (solo el hash) por la salida estándar; la contraseña sale una sola vez por la salida de errores (pantalla). No se conecta a ninguna base ni escribe archivos.
  - Con PHP CLI: `php generar-alta.php > alta-<id>.sql` (los datos se escriben en pantalla; la contraseña aparece en pantalla y **no** va al archivo).
  - Con Docker: `docker run --rm -i -v "<carpeta>:/g:ro" php:8.3-cli php /g/generar-alta.php > alta-<id>.sql` (**sin `-t`**: con `-t` la salida de errores y la estándar se mezclan y la contraseña acabaría en el archivo).
  - Después: `sha256sum alta-<id>.sql` y anotar la huella en el registro.
- `probar-alta-temporal.sh`: prueba local en Docker (php y mysql 8.0, datos sintéticos): 23/23.
- `registro-altas.plantilla.csv`: columnas del registro de altas (sin contraseñas; con `fecha_emision` y `contrasena_inicial_vigente`).

No se activa hasta cumplir la sección 9 del procedimiento. Caduca con P4c verificado + 5 días hábiles (tope 60 días); **las contraseñas emitidas no caducan con el procedimiento**.
