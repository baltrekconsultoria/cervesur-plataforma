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

## 7. Actualización UX-08B.3: nueva evidencia y comandos focalizados

### Lo nuevo (CONFIRMADO por PuTTY)
- El piloto administrativo usa, además de los archivos de §1, `lib/estado_piloto.php` y `lib/mode.php`.
- En `cervesur_react_estado_admin` existen `auditoria.reporte_id` y `usuarios_piloto.activo`. **No demuestra dónde está la lista de aprobación de informes**: la consulta solo buscó esos nombres de columna.
- Las cuentas `cervesur_ro_auth_adm`, `cervesur_ro_catalogo_adm` y `cervesur_ro_visor` sugieren (INFERIDO) que el piloto **lee** el catálogo y el visor con cuentas de solo lectura y mantiene su **estado** (sesión, usuarios del piloto, auditoría) en su propia base.

### Consecuencia para DB031
Si el visor administrativo decide con datos del propio piloto (por ejemplo, una tabla de informes aprobados en la base de estado), añadir DB031 **no** requeriría tocar `cervesur_portalbi` ni la variable `ADMIN_VIEWER_ALLOWED_REPORT_IDS`: se haría por el procedimiento del propio piloto. Eso corresponde a las opciones A/B/C de §3 y no se puede decidir sin leer el código.

### Comandos de solo lectura, acotados a las cinco preguntas
Los enlaces de Power BI se enmascaran; no se imprime ningún valor de datos, solo estructura y líneas de código coincidentes.

```bash
cd /opt/cervesur-react-admin/backend

# 1) ¿Qué función decide el 403 del visor? (nombres de función y líneas con 403)
grep -n -E "function |403|http_response_code" router/ReporteRouter.php router/PilotoRouter.php router/AdminRouter.php \
  | sed -E 's#https?://[^" ]+#<url-oculta>#g' | cut -c1-170

# 2) ¿De dónde sale la autorización? (qué archivos incluye y qué estructuras consulta)
grep -n -E "require|include|FROM |JOIN |\$_SESSION|file_get_contents|json_decode" lib/piloto_catalogo.php lib/estado_piloto.php lib/admin_catalogo.php lib/mode.php lib/pilot_sesion.php \
  | sed -E 's#https?://[^" ]+#<url-oculta>#g' | cut -c1-170

# 3) ¿Cómo se lee el estado del informe? (activo / inactivo)
grep -n -E "activo|is_active|estado" lib/piloto_catalogo.php lib/admin_catalogo.php router/ReporteRouter.php | cut -c1-150

# 4) ¿Existe una consulta de aprobación? (palabras clave; sin valores)
grep -n -i -E "aprob|autoriz|permit|allow|habilit|piloto" lib/piloto_catalogo.php lib/estado_piloto.php lib/admin_catalogo.php router/ReporteRouter.php \
  | sed -E 's#https?://[^" ]+#<url-oculta>#g' | cut -c1-170

# 5) ¿Cómo se entrega el enlace al visor? (solo cómo se decide; no el valor)
grep -n -E "function |enlace|url|modo" lib/powerbi_link.php router/ReporteRouter.php | sed -E 's#https?://[^" ]+#<url-oculta>#g' | cut -c1-150

# Estructura (no datos) de la base de estado del administrador
sudo mysql -N -e "SELECT table_name, column_name, column_type FROM information_schema.columns WHERE table_schema = 'cervesur_react_estado_admin' ORDER BY table_name, ordinal_position"
```

### Plan de integración (sin ejecutar)
1. Con la salida de arriba, clasificar el mecanismo en A–E (§3).
2. **No** sustituir ni desactivar la protección desplegada. La lista local (`ADMIN_VIEWER_ALLOWED_REPORT_IDS`) queda como segunda capa **opcional**, nunca como reemplazo.
3. Añadir solo el id 72 por la vía propia del piloto; verificar 72 → 200, otro informe → 403 sin enlace, inactivo denegado, trabajadores y Flutter sin cambios.
4. Reversión: quitar la entrada 72 (o restaurar el respaldo del archivo/fila tocados).
