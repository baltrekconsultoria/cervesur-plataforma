# UX-06B.0 · Paginación de servidor y vistas mínimas

Rama local: `feat/cervesur-ux-06b0-api-contracts` (desde `5a19ed9`, sin commits ni push). Cambios **aditivos y opt-in**: un cliente que no envíe `page`, `per_page` ni `view` recibe las mismas claves y los mismos datos que antes (con la excepción del orden y de un bloque `pagination` nuevo, ver «Compatibilidad»).

## 1. Paginación

**Parámetros** (query string): `page` entero ≥ 1 (máx. 1 000 000) y `per_page` entero 1–100. Cualquier otro valor (`0`, negativos, decimales, texto, arreglos como `per_page[]=5`, valores por encima del máximo) responde **HTTP 400** `{ "success": false, "message": "…" }`. Nunca se ignoran en silencio.

**Respuesta** (se conservan `success`, `data`, `count`, `message`; `count` = filas de esa página):

```json
{ "success": true, "data": [ … ], "count": 20, "message": "Consulta exitosa",
  "pagination": { "page": 1, "per_page": 20, "total": 105, "total_pages": 6, "has_more": true } }
```

**Garantías**
- Los filtros de **identidad, pertenencia y estado activo** forman parte de la consulta *antes* de contar y paginar (`COUNT(*)` sobre la misma consulta acotada): `total` solo cuenta lo que el usuario autenticado puede ver.
- Orden **determinista** con desempate por identificador único: `created_at DESC, id DESC` (empresas: `nombre ASC, id ASC`).
- `LIMIT`/`OFFSET` se enlazan como enteros (`PDO::PARAM_INT`); nunca se interpolan. El `ORDER BY` y la consulta son constantes del código.
- Una página fuera de rango devuelve `data: []`, `total` real y `has_more:false` (HTTP 200).
- Implementación única en `backend/lib/pagination.php` (`parse_pagination`, `require_pagination`, `paginated_rows`, `pagination_meta`).

**Listados y comportamiento sin parámetros**

| Endpoint | Antes | Ahora sin parámetros | Con `page`/`per_page` |
|---|---|---|---|
| `GET /api/empresa-usuario` | `LIMIT 20` silencioso | **mismas 20 filas** + `pagination` con el total real | paginado |
| `GET /api/empresa-usuario/empresa/{nombre}` | `LIMIT 20` silencioso | igual (20 + `pagination`) | paginado |
| `GET /api/empresa-reporte-usuario` | `LIMIT 20` silencioso | igual (20 + `pagination`) | paginado |
| `GET /api/empresa-reporte-usuario/empresa/{nombre}` | `LIMIT 20` silencioso | igual (20 + `pagination`) | paginado |
| `GET /api/empresa-usuario/ruc/{ruc}`, `GET /api/empresa-reporte-usuario/ruc/{ruc}`, `GET /api/empresa-reporte-usuario/user/{id}` | sin límite | sin límite (sin `pagination`) | paginado |
| `GET /api/reportes`, `/api/reportes/activos`, `GET /api/empresas`, `GET /api/asignacion`, `GET /api/users` | sin límite | sin límite (sin `pagination`) | paginado |

El límite de 20 **no se elimina**: se sustituye por un mecanismo controlado en el que el cliente puede pedir cualquier página y conoce el total. Los listados que nunca tuvieron límite siguen sin tenerlo hasta que el cliente pida paginar.

Notas de forma: `/api/asignacion` conserva su doble envoltura (`data.data`) y además expone `pagination` en el nivel superior (y dentro de `data`). `/api/users` conserva `users`, `total` (filas de la página, igual que antes) y `filter`; el total real está en `pagination.total`.

## 2. Vistas mínimas (`?view=minimal`)

> **Advertencia de seguridad.** `view=minimal` es una comodidad de presentación para un cliente cooperante, **no un control de seguridad**: no sustituye la autorización de PHP (que se aplica siempre antes) y cualquier cliente puede omitir el parámetro y recibir la vista completa a la que ya tenía derecho. La reducción de datos expuestos por defecto (ver backlog B-10) exige cambiar el valor por defecto en una versión futura; hasta entonces los campos sensibles siguen enviándose a los clientes que no piden la vista mínima.

Opt-in para la biblioteca de informes. **Sin el parámetro la respuesta es idéntica a la actual** (clientes existentes, p. ej. el Flutter productivo, no se ven afectados). Con `view=minimal` la respuesta ya autorizada se proyecta a una lista blanca; solo puede quitar campos y se aplica después de la autorización. Cualquier otro valor de `view` → 400.

| Recurso | Campos con `view=minimal` | Dejan de enviarse |
|---|---|---|
| `/api/reportes`, `/activos`, `/{id}`, `/reporteid/{uuid}`, `/grupoid/{uuid}` | `id`, `reporte_id`, `nombre`, `descripcion`, `activo` | `enlace`, `created_by`, `updated_by`, `created_by_username`, `grupo_id`, fechas |
| `/api/empresas`, `/{id}`, `/buscar/{t}`, `/ciudad/{c}` | `id`, `nombre`, `razon_social`, `ruc`, `ciudad`, `pais`, `logo`, `activo` | `powerbi_tenant`, `powerbi_client`, `created_by`, `updated_by`, `email`, `telefono`, `website`, `direccion`, `contacto_principal`, fechas |
| `/api/asignacion`, `/{id}`, `/empresa/{id}`, `/reporte/{id}` | `id`, `empresa_id`, `empresa_nombre`, `reporte_id`, `reporte_nombre`, `activo` | `notas`, `created_by`, `updated_by`, fechas |

`id` (numérico) se conserva en informes y empresas porque el catálogo provisional une `/api/reportes` con `/api/asignacion` por ese identificador. Los datos completos siguen disponibles internamente (el mecanismo de Power BI no cambia: `PowerBIModel` sigue leyendo sus credenciales en el servidor).

## 3. Compatibilidad con consumidores anteriores

| Cambio | Riesgo para un cliente anterior | Evaluación |
|---|---|---|
| Bloque nuevo `pagination` en listados | Ninguno si el cliente ignora claves desconocidas | Aditivo |
| Listados que antes no tenían orden definido ahora tienen orden determinista | Posible cambio de orden entre filas con el mismo `created_at` | Aceptable; antes era indefinido |
| `LIMIT 20` conservado como tamaño por defecto | Ninguno: mismas 20 filas | Compatible |
| 400 ante parámetros inválidos | Solo si el cliente ya enviaba `page`/`per_page`/`view` | El bundle de Flutter histórico (solo conteo de nombres de campo, solo lectura) **no usa** `page`, `per_page` ni `view`; sí menciona `enlace`, `powerbi_tenant`, `powerbi_client`, `created_by`, `reporte_id`, `grupo_id`, `empresa_nombre`, `reporte_nombre` → razón para que la vista mínima sea opt-in |
| Página fuera de rango en las búsquedas por nombre/RUC | Antes devolvían `success:false` si la lista estaba vacía; con paginación devuelven `success:true` si `total > 0` | Solo aplica a quien pagine |
| `tests/isolated/seed.php`: columnas `user.created_at` y `updated_at` | Solo el fixture de pruebas | Alinea el fixture con el código (`UserModel`, `RegisterController`) |

**No cambian**: autenticación, JWT, `session_version`, roles y comprobaciones de acceso, respuestas de login, mecanismo de Power BI, esquema de la base real.

## 4. Contrato objetivo (versiones futuras, no implementado)

1. `view=minimal` como respuesta por defecto para usuarios no administradores (con aviso de versión).
2. `GET /api/reportes` con `empresas: [{ id, nombre }]` (solo pares autorizados) y `grupo: { id, nombre }` cuando exista origen para el nombre.
3. Envoltura de paginación común en el nivel superior y eliminación de la doble envoltura de `/api/asignacion`.
4. Login fallido con HTTP 401 y errores de validación con 400/422 (hoy HTTP 200 + `success:false`).
5. Catálogo de roles normalizado (`admin`/`user`) en base de datos y un único punto de comprobación en PHP.

## 5. Pruebas añadidas (`tests/isolated/`)

`seed_pagination.php` crea 7 usuarios sintéticos con 0, 1, 20, 21, 45, 105 y 5 (+ relaciones que no deben contarse) relaciones, 105 empresas, 105 informes, y relaciones inactivas, de empresa inactiva, de informe inactivo y de relación empresa–informe inactiva. `http_pagination_test.php` (**228 casos**) cubre: tamaño por defecto heredado; todas las páginas con `per_page` 10/20/100; primera, intermedia, última y fuera de rango; total constante; ausencia de duplicados; orden estable (incluye marcas de tiempo repetidas); parámetros inválidos (11 variantes × 2 endpoints, más búsquedas y listados); aislamiento entre usuarios (conjuntos disjuntos y administrador con superconjunto); relaciones activas e inactivas; búsquedas y RUC; listados sin límite previo (mismo total que el listado completo); vistas mínimas (lista blanca, sin datos técnicos, mismo conjunto y orden); compatibilidad (claves heredadas, campos completos sin `view`, sin secreto de cliente); acceso cruzado bloqueado; y composición del catálogo (cada informe con su empresa, pares autorizados, sin duplicados). El CI (`backend-ci.yml`) los ejecuta tras las 50 pruebas base.
