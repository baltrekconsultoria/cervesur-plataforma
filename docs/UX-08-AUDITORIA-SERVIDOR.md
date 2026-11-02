# UX-08 · Auditoría del servidor (Fases 1 y 2): estado y procedimiento

## Estado

**No ejecutada por este asistente.** La autorización recibida permite SSH de solo lectura «con accesos ya autorizados por el operador», pero en esta sesión no consta ningún acceso abierto (destino, cuenta o sesión) que se me haya indicado, y no se buscan agentes SSH ni sockets de otras sesiones. Por eso no se ha leído nada del servidor y **ninguna conclusión sobre el backend original está respaldada por evidencia**.

Queda preparado todo lo necesario para cerrarlo en una sola ejecución:

| Pieza | Para qué |
|---|---|
| `deploy/auditoria/auditoria-solo-lectura.sh` | Lectura de Apache, claves de `.env` (sin valores), huellas de archivos, código de sesión del original, rutas y privilegios MySQL. No escribe nada |
| `docs/UX-08-HUELLAS-BACKEND.txt` | Huellas sha256 (sin CR) del backend de `origin/main` y de esta rama, para compararlas con las del servidor |
| `docs/UX-08-COMPATIBILIDAD-FLUTTER.md` | Matriz con la columna «Backend original» por rellenar |

## Procedimiento (operador, PuTTY)

1. Copie `auditoria-solo-lectura.sh` al servidor (o pegue su contenido) y revíselo: solo contiene lecturas.
2. `bash auditoria-solo-lectura.sh > auditoria.txt 2>&1`
3. **Revise `auditoria.txt`** buscando cualquier valor sensible antes de compartirlo (el script oculta valores de `.env` y de `SetEnv`, pero revise de todos modos).
4. Compare las huellas (sección 3) con `docs/UX-08-HUELLAS-BACKEND.txt`:
   ```bash
   # en el servidor, solo el bloque de un backend; ejemplo para el administrador React:
   grep -A200 -- '--- /opt/cervesur-react-admin/backend' auditoria.txt | grep -E '^[0-9a-f]{64}  backend/' | sort -k2 > servidor-admin.txt
   ```
   Un archivo con huella igual a `origin/main` o a la rama indica exactamente qué versión corre; uno distinto indica código propio del despliegue (por ejemplo, el control que da el 403 al administrador).
5. Envíe `auditoria.txt` (revisado). Con él se completan las filas «Backend original» y se decide el punto de partida de UX-08C.

## Qué responderá cada sección

| Sección | Responde |
|---|---|
| 1 Apache | Si `/var/www/cervesur/backend` es de verdad el backend del portal Flutter (puerto 80) y qué carpeta sirve cada puerto |
| 2 Claves de `.env` | Qué configuración existe (¿hay alguna variable de aprobación de informes?) sin ver valores |
| 3 Huellas | Qué código corre en cada instancia frente a `origin/main` y la rama |
| 4 Código de sesión | Uso de `is_active`, `session_version`, `last_login`, transacciones en el original |
| 5 Rutas | Qué endpoints expone el original (creación, desactivación, asignaciones) |
| 6 MySQL | Qué cuentas usan las aplicaciones y con qué privilegios reales (el `SHOW GRANTS` de `root` no sirve) |

## Hasta entonces

- No se habilita desactivar usuarios (ver `UX-08-COMPATIBILIDAD-FLUTTER.md` §4).
- `ADMIN_VIEWER_ALLOWED_REPORT_IDS` no se aplica a ninguna instancia real (ver `UX-08C-AUTORIZACION-DB031.md` §2).
- Comparación local entre `origin/main` y esta rama (hecha): los cambios de UX-08 son aditivos; las huellas de ambas están en `UX-08-HUELLAS-BACKEND.txt`.
