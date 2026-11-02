# UX-08C · Compatibilidad de la autorización DB031 con el piloto administrativo desplegado

**Autorizado por el responsable del piloto:** DB031, `reporte.id` = 72, **solo** para visualización en el piloto administrativo. No hay autorización para aprobar otros informes, para publicar enlaces nuevos ni para convertir información financiera privada en pública.

## 1. Lo que se sabe

| Hecho | Nivel |
|---|---|
| El administrador desplegado devuelve **403** para informes no autorizados en ese piloto | Observado por el responsable |
| El backend desplegado `/opt/cervesur-react-admin/backend` contiene archivos que **no existen en este repositorio**: `lib/piloto_catalogo.php`, `lib/admin_catalogo.php`, `lib/pilot_sesion.php`, y routers `AdminRouter.php`, `PilotoRouter.php` (además de `lib/views.php` y `router/ReporteRouter.php`, que sí existen aquí pero pueden diferir) | Aportado por el operador (nombres de archivo) |
| En este repositorio, la restricción de visor del administrador es `ADMIN_VIEWER_ALLOWED_REPORT_IDS` (variable de entorno, `backend/lib/admin_viewer_allowlist.php`) | Código local |
| Cómo se decide el 403 desplegado y dónde vive su lista de aprobación (¿archivo? ¿tabla? ¿estado de sesión del piloto?) | **PENDIENTE INSPECCIÓN** |

**Conclusión:** el control desplegado es un mecanismo distinto del local. `ADMIN_VIEWER_ALLOWED_REPORT_IDS=72` **no puede sustituirlo directamente**: añadirlo sin entender el existente podría (a) no tener efecto, (b) abrir DB031 por una vía y dejarlo cerrado por la otra, o (c) dejar dos listas que se contradicen.

## 2. Qué leer (PuTTY, solo lectura, sin imprimir enlaces ni secretos)

Los catálogos del piloto pueden contener URLs de Power BI; todos los comandos las enmascaran. Revise la salida antes de compartirla.

```bash
cd /opt/cervesur-react-admin/backend

# Mapa de archivos (nombres y tamaños)
ls -la lib router | cut -c1-120

# Dónde se decide el 403 y cómo se evalúa la aprobación (URLs enmascaradas)
grep -n -i -E "403|aprob|autoriz|permit|allow|catalogo|piloto|72|DB031" \
  lib/piloto_catalogo.php lib/admin_catalogo.php lib/pilot_sesion.php lib/views.php \
  router/AdminRouter.php router/PilotoRouter.php router/ReporteRouter.php \
  | sed -E 's#https?://[^" ]+#<url-oculta>#g' | cut -c1-200

# ¿La lista vive en un archivo de datos, en la base o en la sesión? Solo nombres de funciones y de archivos que se leen
grep -n -E "function |file_get_contents|json_decode|include|require|\\\$_SESSION|SELECT .* FROM" lib/piloto_catalogo.php lib/admin_catalogo.php lib/pilot_sesion.php \
  | sed -E 's#https?://[^" ]+#<url-oculta>#g' | cut -c1-180

# ¿Existen archivos de datos del piloto? (solo nombres)
find /opt/cervesur-react-admin -maxdepth 3 -type f \( -name '*.json' -o -name '*.csv' -o -name '*.sqlite' \) -printf '%p %s bytes\n' | grep -v node_modules
```

## 3. Árbol de decisión (para después de leer lo anterior)

| Si el mecanismo desplegado es… | Entonces… | Riesgo |
|---|---|---|
| **A. Una lista de ids/identificadores en un archivo o constante** | Añadir DB031 (72) **a esa lista**, con un cambio de una línea, aprobado y reversible. No instalar `ADMIN_VIEWER_ALLOWED_REPORT_IDS` | Bajo |
| **B. Estado en la sesión o en la base del piloto** (por ejemplo, el administrador «aprueba» informes durante la sesión) | Añadir DB031 con el procedimiento que ya usa el piloto (no con SQL manual). Si no existe un procedimiento, diseñarlo con el responsable | Medio |
| **C. Regla de código (por ejemplo, por tipo de enlace o por grupo)** | No ampliar la regla (abriría más informes). Añadir una excepción individual explícita para 72 y probar que ningún otro cambia | Medio |
| **D. Un mecanismo equivalente al local** | Usar `ADMIN_VIEWER_ALLOWED_REPORT_IDS=72` y verificar | Bajo |
| **E. Ninguno claro / combinación** | Detenerse: no aplicar nada hasta cerrar el diseño con el responsable | — |

En todos los casos se exige lo que ya cumple el diseño local:

1. Solo el id 72; **ningún comodín, rango ni «todos los activos»**.
2. Un informe inactivo sigue devolviendo 404/403 aunque esté en la lista.
3. La decisión se toma **en el servidor**; la interfaz no puede ampliarla.
4. La respuesta de un informe no aprobado **no contiene el enlace ni tokens**.
5. Los trabajadores no cambian: DB031 sigue funcionando en `https://192.168.200.12`.

## 4. Pruebas que ya existen (locales)

| Qué prueba | Dónde |
|---|---|
| Lista explícita: aprobado 200, no aprobado 403 sin enlace, inactivo 404, inexistente 404, sin token 401, trabajadores sin cambio, parámetros de URL no amplían la lista, variable inválida falla cerrada | `tests/security_unit.php`, `tests/isolated/http_admin_viewer_allowlist_test.php` |
| Un trabajador no obtiene el enlace de un informe ajeno ni siquiera con `?role=admin&user_id=1` | `tests/isolated/http_writes_test.php` |

Estas pruebas validan la **intención** de seguridad (las cinco condiciones de §3). Cuando se conozca el mecanismo desplegado, se debe repetir la misma batería contra él en una copia del piloto, no contra la instancia real.

## 5. Procedimiento para la intervención futura (no se ejecuta)

1. Leer el mecanismo (§2) y clasificarlo (§3).
2. Respaldar el archivo/estado que se vaya a tocar.
3. Aplicar el cambio **mínimo** que añade solo el 72 (según la fila de §3).
4. Verificar con una sesión de administrador: 72 → 200; otro informe activo → 403 sin enlace; un inactivo → denegado; trabajadores → sin cambios; Flutter → sin cambios.
5. Reversión: restaurar el respaldo (o quitar la entrada 72). Sin migraciones ni datos de negocio modificados.

Requiere autorización expresa, ventana acordada y, si exige reiniciar servicios, autorización adicional.

## 6. Exposición de enlaces y Power BI (distinciones)

| Capa | Qué protege | Qué no protege |
|---|---|---|
| **Confidencialidad del API** | Que un usuario o administrador reciba un enlace que no debe ver. La lista de visor y la vista mínima (`REACT_STRICT_VIEWS`, solo pruebas locales) actúan aquí | Un enlace ya conocido por quien lo copió |
| **Autorización dentro de la aplicación** | Quién puede abrir qué informe en el portal (asignaciones, empresa, estado, lista del piloto) | Lo que ocurra fuera del portal |
| **Exposición inherente de «Publicar en la web»** | Nada: cualquiera con la URL de publicación puede ver el informe sin iniciar sesión, aunque el portal lo bloquee. Aprobar DB031 en el piloto **no cambia esto** | — |

No se generan ni se autorizan enlaces públicos nuevos, ni se toca Power BI Service.
