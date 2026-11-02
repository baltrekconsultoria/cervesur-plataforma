# UX-08F · Divergencia entre el repositorio y el servidor, y política de integración

**Objetivo obligatorio:** que publicar UX-08 **no pueda** sobrescribir ni eliminar los controles de seguridad del piloto desplegado. Este documento fija cómo se detecta la divergencia, qué se sabe y qué no, y la política de publicación.

## 1. Verificado frente a hipótesis

| Afirmación | Estado |
|---|---|
| El piloto administrativo desplegado (`/opt/cervesur-react-admin/backend`) contiene archivos que **no existen en este repositorio**: `lib/piloto_catalogo.php`, `lib/estado_piloto.php`, `lib/admin_catalogo.php`, `lib/mode.php`, `lib/pilot_sesion.php`, `router/AdminRouter.php`, `router/PilotoRouter.php` | **Verificado** (nombres de archivo aportados por el operador, comparados con `git ls-files`: ninguno está versionado) |
| `lib/views.php`, `lib/powerbi_link.php` y `router/ReporteRouter.php` existen en ambos, pero **pueden diferir** | Hipótesis hasta ejecutar el inventario |
| El 403 administrativo lo produce uno de esos componentes propios del piloto | **Hipótesis** (no hay lectura de su código) |
| La lista de aprobación de informes vive en el código, en un archivo de datos, en la base de estado o en la sesión | **Desconocido**: la consulta de columnas (`auditoria.reporte_id`, `usuarios_piloto.activo`) no lo demuestra |
| El piloto lee `cervesur_portalbi` con cuentas `cervesur_ro_*` y guarda su estado en otra base | **Inferido** de los nombres de cuenta |

## 2. Riesgo que se evita

Un despliegue que copie `backend/` del repositorio sobre `/opt/cervesur-react-admin/backend` (por ejemplo `rsync --delete`, o reemplazar el directorio) **borraría** los archivos de la columna «solo en el servidor» y **sustituiría** los que difieren. Si el 403 de los informes no aprobados vive ahí, el administrador pasaría de «solo DB031 aprobado» a «todos los informes activos» (comportamiento del código del repositorio sin lista).

## 3. Cómo se detecta (herramientas de solo lectura, ya probadas)

| Herramienta | Qué entrega |
|---|---|
| `deploy/auditoria/generar-manifiesto.sh` | Huellas del backend del repositorio (`manifiesto-backend.txt`) y rutas declaradas (`rutas-repo.txt`), con el commit |
| `deploy/auditoria/inventario-divergencia.sh <directorio>` | (1) solo en el servidor, (2) misma ruta con contenido distinto, (3) solo en el repositorio, (4) idénticos, (5) rutas del servidor frente al repositorio, (6) dependencias de los componentes exclusivos (nombres de archivo y de función; URLs ocultas), (7) conteos de marcadores de seguridad por archivo (`403`, `require_auth`, `session_version`, `is_active`, `allow/aprob/autoriz`), (8) interruptores de UX-08 en el `.env` (solo cuatro claves no sensibles) |
| `deploy/auditoria/probar-inventario.sh` | Prueba local con un «servidor» simulado: detecta componente exclusivo, archivo modificado y archivo ausente; no imprime contenido ni URL; no modifica nada (7 comprobaciones) |

Ejecución por el operador (PuTTY), una vez por backend desplegado:

```bash
cd <copia del repositorio en el servidor, o copie manifiesto-backend.txt y rutas-repo.txt junto al script>
bash deploy/auditoria/inventario-divergencia.sh /opt/cervesur-react-admin/backend    > divergencia-admin.txt
bash deploy/auditoria/inventario-divergencia.sh /opt/cervesur-react-usuarios/backend  > divergencia-usuarios.txt
bash deploy/auditoria/inventario-divergencia.sh /var/www/cervesur/backend             > divergencia-original.txt
```

Compartir esos tres archivos tras revisarlos. El manifiesto debe generarse **en el commit exacto que se vaya a publicar** (la primera línea lo registra).

## 4. Tabla de clasificación (se rellena con la salida)

| Componente (servidor) | ¿Seguridad? | Función | Dependencias | Decisión |
|---|---|---|---|---|
| `lib/piloto_catalogo.php` | por determinar | por determinar | por determinar | **Conservar sin tocar** |
| `lib/estado_piloto.php` | por determinar | | | **Conservar** |
| `lib/admin_catalogo.php` | por determinar | | | **Conservar** |
| `lib/mode.php` | por determinar | | | **Conservar** |
| `lib/pilot_sesion.php` | por determinar | | | **Conservar** |
| `router/AdminRouter.php`, `router/PilotoRouter.php` | por determinar | | | **Conservar** |
| `lib/views.php`, `lib/powerbi_link.php`, `router/ReporteRouter.php` | por determinar | | | **Fusionar a mano**, nunca reemplazar |

## 5. Política de publicación (obligatoria)

1. **Prohibido** reemplazar, sincronizar con borrado o reconstruir los directorios activos de los portales publicados.
2. UX-08 se instala **primero** en una instancia nueva y aislada (`deploy/qa-admin-aislado/`, puerto 8090 en loopback, base y cuenta propias). Los directorios publicados no se tocan.
3. La publicación posterior sobre el piloto real, si se aprueba, se hace **solo con superposición aditiva**: copiar a un directorio nuevo (`…-ux08`), no sobre el existente; conservar los componentes de §4; fusionar a mano los archivos en conflicto; conmutar con un cambio de configuración de un solo paso.
4. Antes de conmutar: ejecutar el inventario sobre el directorio nuevo; los conteos de marcadores de seguridad (§3, sección 7) **no pueden disminuir** respecto al desplegado.
5. Respaldo previo (directorio y `.env`), verificación posterior (§6) y reversión inmediata por conmutación inversa.

## 6. Verificación tras cualquier cambio sobre el piloto

| Comprobación | Resultado esperado |
|---|---|
| Administrador abre DB031 (id 72) | 200 y configuración del visor |
| Administrador abre cualquier otro informe activo no aprobado | **403 sin enlace en la respuesta** |
| Informe inactivo / inexistente | 404 |
| Trabajador abre DB031 con su asignación | Sin cambios |
| `USER_ADMIN_WRITES` en los `.env` publicados | Sin definir o distinto de 1 |
| Inventario: marcadores de seguridad | Iguales o mayores que antes |
| Flutter (puerto 80) | Sin cambios |

## 7. Integración de DB031 (id 72) sin debilitar el control existente

**Principio:** el control desplegado no se sustituye ni se desactiva. Cualquier mecanismo nuevo es una **segunda capa que solo puede restringir**.

| Capa | Quién decide | Efecto sobre DB031 | Efecto sobre los demás informes |
|---|---|---|---|
| A. Control desplegado del piloto (desconocido hasta leerlo) | Código/datos del piloto | Se agrega el 72 **por el procedimiento propio del piloto** | Sigue denegando (403 sin enlace) |
| B. Lista del repositorio (`ADMIN_VIEWER_ALLOWED_REPORT_IDS`) | Variable de entorno | Si se activa, solo el 72 | Si se activa, deniega el resto: nunca amplía |
| C. Asignaciones, estado del informe y de la empresa | Backend | Sin cambios | Sin cambios |

Reglas (verificadas por pruebas locales en `http_admin_viewer_allowlist_test.php`, 15 aserciones, y por la instalación QA): solo el id 72; sin comodines ni rangos (un valor inválido bloquea todo); un informe inactivo se deniega aunque esté en la lista; el 403 no contiene enlace ni token; los parámetros de la URL no amplían la lista; los trabajadores no cambian.

| Escenario real (por clasificar en `UX-08C-COMPATIBILIDAD-DESPLEGADA.md` §3) | Acción |
|---|---|
| Lista en archivo/constante (A) | Añadir el 72 a esa lista; no tocar B |
| Estado en la base del piloto o en sesión (B) | Registrar el 72 con el procedimiento del piloto |
| Regla de código (C) | Añadir una excepción individual; probar que ningún otro informe cambia |
| Mecanismo equivalente al local (D) | Usar la variable y verificar |
| No claro (E) | **NO-GO**: no aplicar nada |

En la instancia QA nueva (§5.2) no hay piloto desplegado que proteger: allí se prueba la capa B con un informe sintético que representa a DB031.
