# UX-08C · Autorización individual de informes en el visor administrativo (DB031)

**Autorización recibida:** DB031 (`reporte.id` = 72) para visualización en el piloto administrativo. No es aprobación para volver público ningún informe, ni para aprobar otros.

## 1. Qué hace el código del repositorio

| Aspecto | Antes (`origin/main`) | Ahora (esta rama) |
|---|---|---|
| Quién puede abrir un informe en el visor (`GET /api/reportes/id/{id}/visor` y `/reporteid/{uuid}/visor`) | Administrador: **cualquier informe activo**. Trabajador: solo con asignación individual vigente | Igual para trabajadores. Administrador: además, solo los ids de `ADMIN_VIEWER_ALLOWED_REPORT_IDS` (si la variable está definida) |
| Informe inactivo / inexistente | 404 | 404 (aunque figure en la lista) |
| Informe activo no aprobado, rol admin | 200 | **403**, sin enlace en la respuesta |
| Variable no definida | — | Sin restricción adicional (otras instancias no cambian) |
| Lista inválida (`72,abc`, `072`, `*`…) | — | **Falla cerrada**: ningún informe |

Código: `backend/lib/admin_viewer_allowlist.php`, `ReporteController::getViewer` y `::getViewerById`. La lista es configuración del servidor (no una tabla): no puede modificarse por la API ni por parámetros de consulta (probado) y no crea otra fuente de verdad.

## 2. Hallazgo importante: el control desplegado NO está en el repositorio

En el repositorio no existe ninguna «lista administrativa de autorización». El 403 que hoy da el administrador desplegado proviene de **un mecanismo que no consta aquí**: puede ser configuración del servidor, una regla de Apache, o una versión distinta del código. Antes de aplicar nada hay que averiguar cuál es, para no dejar **dos mecanismos superpuestos** con efectos opuestos.

Comandos de solo lectura para el operador (PuTTY; no imprimen credenciales):

```bash
# Backend del administrador desplegado: ¿en qué se diferencia del repositorio?
cd /opt/cervesur-react-admin/backend
sha256sum lib/*.php controllers/*.php models/*.php router/*.php

# ¿Dónde se decide el 403 del visor? (líneas, no archivos)
grep -rn -E "403|visor|ALLOW|allowlist|autoriz" --include=*.php . | cut -c1-160

# Claves de configuración (sin valores)
sudo sed -E 's/^([A-Za-z_]+)=.*/\1=<oculto>/' .env | grep -v '^#' | grep -v '^$'

# Reglas de Apache del puerto 8443 (valores de SetEnv ocultos)
sudo grep -rn -E "RewriteRule|RewriteCond|Require|<Location|<Directory|SetEnv|ProxyPass" /etc/apache2/sites-enabled/ \
  | sed -E 's/(SetEnv +[A-Za-z_]+ +).*/\1<oculto>/'
```

Con esa salida se decide entre: (a) reemplazar el mecanismo desplegado por este, (b) ampliar el desplegado con el id 72, o (c) conservar ambos si son complementarios.

## 3. Procedimiento controlado para la instancia real (NO EJECUTAR todavía)

Requiere aprobación posterior expresa, ventana acordada y conocer el resultado del §2.

1. **Preparación (solo lectura):** respaldo del `.env` del administrador; confirmar que `reporte.id = 72` es DB031, está activo y su enlace es de tipo «Publicar en la web» (`SELECT id, nombre, activo FROM reporte WHERE id = 72;` — no seleccionar `enlace`).
2. **Despliegue del código** de esta rama al backend del administrador (cambio aditivo; sin migraciones).
3. **Configuración:** añadir al `.env` del backend del administrador `ADMIN_VIEWER_ALLOWED_REPORT_IDS=72`. **No** en el backend de trabajadores ni en Flutter.
4. **Recarga** de PHP/Apache solo si la configuración de PHP la exige (reinicio de servicios: requiere autorización).
5. **Verificación con el rol administrador** (token propio, sin imprimir):
   - `GET /api/reportes/id/72/visor` → **200** con `modo` y, si procede, `url`.
   - `GET /api/reportes/id/<otro activo>/visor` → **403** sin enlace.
   - `GET /api/reportes/id/<inactivo>/visor` → 404.
   - Trabajadores: DB031 sigue funcionando en `https://192.168.200.12`.
   - Flutter (puerto 80): sin cambios.
6. **Reversión:** quitar la línea del `.env` (o dejar `ADMIN_VIEWER_ALLOWED_REPORT_IDS=` vacía para bloquear todo) y recargar. El cambio de código es inerte sin la variable. No hay datos que revertir.

## 4. Lo que esto NO cubre (riesgos abiertos)

| Riesgo | Detalle | Estado |
|---|---|---|
| Enlaces en listados del administrador | `GET /api/reportes` y `GET /api/reportes/{id}` (sin `view=minimal`) devuelven `enlace` de **todos** los informes. La lista solo gobierna el **visor** | Abierto (hallazgo H2/B-10): se cierra con la vista mínima por defecto, que depende de Flutter |
| Formulario de edición | `view=form` entrega el `enlace` al administrador **por diseño** (necesita corregirlo) | Aceptado: es el propio administrador; evaluar enmascararlo |
| Tipo de enlace de DB031 | Si es «Publicar en la web» cualquiera con la URL puede verlo sin iniciar sesión; esto ya es así y **no cambia** | Informativo: no se convierte nada en público |
| Aprobación masiva | La lista exige ids numéricos explícitos; no admite comodines ni rangos; un valor inválido bloquea todo | Cubierto (pruebas unitarias e HTTP) |

## 5. Pruebas

- `tests/security_unit.php`: 15 aserciones de la lista (sin definir, un id, apertura, rechazo, trabajadores, normalización, vacía y 8 valores inválidos que fallan cerrados, no afecta a trabajadores).
- `tests/isolated/http_admin_viewer_allowlist_test.php` (15 aserciones, dos instancias del backend): aprobado → 200 por id y por referencia histórica; no aprobado → 403 sin enlace; inactivo → 404 aunque esté en la lista; inexistente → 404; sin token → 401; trabajador con asignación → 200 y sin asignación → 404; sin variable → comportamiento previo; parámetros de consulta no amplían la lista; lecturas sin efecto sobre los datos.
- El fixture no contiene a DB031/72: la prueba usa ids sintéticos (1, 903, 906) con la misma lógica.
