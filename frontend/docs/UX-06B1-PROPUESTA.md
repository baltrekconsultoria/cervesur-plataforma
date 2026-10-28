# Propuesta UX-06B.1 · Administración (CRUD) y asistente de asignaciones

Estado: **propuesta, no implementada**. Sin escrituras administrativas habilitadas. Basada en la lectura del backend en la rama `feat/cervesur-ux-06b0-api-contracts` y en el prototipo UX-04B.

## 1. Qué existe hoy en el backend (verificado)

| Capacidad | Endpoint | Estado |
|---|---|---|
| Crear / editar / desactivar **empresa** | `POST/PUT/DELETE /api/empresas[/{id}]` | Existe (solo administrador; `DELETE` = `activo=0`) |
| Crear / editar / desactivar **informe** | `POST/PUT/DELETE /api/reportes[/{id}]` | Existe (solo administrador) |
| Asignar empresa↔informe | `POST/PUT/DELETE /api/asignacion` | Existe |
| Asignar usuario↔empresa | `POST/PUT/DELETE /api/empresa-usuario` | Existe |
| Asignar usuario↔informe | `POST/PUT/DELETE /api/empresa-reporte-usuario` | Existe |
| Cambiar rol | `PUT /api/user/{id}/role` | Existe (`admin`/`user`; no permite cambiar el propio rol) |
| **Crear usuario por un administrador** | — | **No existe** (solo el registro público `POST /api/register`) |
| **Activar / desactivar usuario** | — | **No existe** (ningún `UPDATE … is_active` en el código) |
| Auditoría (quién/cuándo/antes–después) | — | **No existe** (solo `created_by/updated_by` en algunas tablas) |
| Transacciones / concurrencia optimista | — | Prácticamente ausentes (`beginTransaction`/`rowCount` en 8 puntos) |

Consecuencia: el CRUD de **usuarios** del prototipo (alta, activar/desactivar, edición) **no se puede construir sin backend nuevo**. Empresas, informes y asignaciones sí tienen endpoints.

## 2. Alcance funcional propuesto

**Fase 1 — lectura completa (sin riesgo de escritura)**
- Usuarios, empresas, informes y asignaciones con **paginación de servidor** (ya disponible), búsqueda y filtros del lado servidor donde existan; estados activo/inactivo; sin IDs técnicos como información principal.
- Capacidad `adminDirectory` activada con el proveedor HTTP: `/api/users`, relaciones usuario–empresa y usuario–informe (hoy truncadas a 20 → ya paginadas).
- Pendiente de decisión: cómo obtener «empresas por usuario» y «usuarios por informe» sin N+1 (propuesta de backend: filtros `?user_id=` / `?reporte_id=` en los listados de relaciones).

**Fase 2 — escrituras de empresas e informes** (formularios del prototipo con validación, confirmación al desactivar, mensajes que reflejan el resultado real del servidor).

**Fase 3 — asignaciones**: asistente Empresa → Reporte → Usuarios → Revisión → Confirmación. El paso 3 solo ofrece usuarios activos con relación vigente con la empresa; «ya tiene acceso» deshabilitado; el paso 4 muestra el efecto; el paso 5 informa el resultado real por usuario (éxito parcial incluido) y permite revertir.

**Fase 4 — usuarios**: alta y activación/desactivación **solo después** de que existan los endpoints y se cumplan los requisitos de §3; cambio de rol con confirmación reforzada.

## 3. Correcciones de seguridad IMPRESCINDIBLES antes de habilitar escrituras reales

Se resuelven en backend y se validan con pruebas HTTP aisladas **antes** de activar la capacidad correspondiente en el frontend.

| # | Requisito | Motivo | Ref. backlog |
|---|---|---|---|
| 1 | **Normalizar roles** (`admin`/`user`), restricción en BD y un único punto de comprobación en PHP | Un rol mal escrito no debe dar ni quitar privilegios; el alta de usuarios y el cambio de rol dependen de ello | B-02 |
| 2 | **Cerrar o proteger `POST /api/register`**; el alta de usuarios debe ser una acción de administrador, con rol `user`, `is_active` y `session_version` explícitos | Hoy cualquiera puede crear cuentas | B-01 |
| 3 | **Límite de intentos de login** con bloqueo temporal y respuesta uniforme | Evita fuerza bruta contra cuentas administrativas | B-03 |
| 4 | **Eliminar mensajes internos de SQL** de las respuestas de error | Una pantalla de escritura expondría esquema y consultas | B-04 |
| 5 | **Errores y códigos coherentes** (400/401/403/404/409/422; no HTTP 200 con `success:false`) | La interfaz debe distinguir un fallo de validación de uno de permisos | B-08 |
| 6 | **Validación de entrada del lado servidor** (tipos, longitudes, RUC, unicidad) y respuestas por campo | No confiar en el formulario | — |
| 7 | **Auditoría**: tabla de eventos (actor, acción, entidad, antes/después, fecha, IP) para altas, bajas, cambios de rol y asignaciones | Trazabilidad y recuperación ante errores | nuevo |
| 8 | **Integridad**: transacciones en asignaciones múltiples, `rowCount` verificado, protección del **último administrador** (no desactivar ni degradar al único admin activo) y de uno mismo | Evita bloquear la plataforma | nuevo |
| 9 | **Revocación de sesión** al cambiar rol o desactivar a un usuario (`session_version`) | Un permiso retirado debe surtir efecto inmediato | nuevo |
| 10 | **Protección CSRF/CORS/cabeceras**: con JWT en memoria y mismo origen el riesgo es bajo, pero se definen cabeceras (B-07) y se prohíbe CORS abierto | Defensa en profundidad | B-07 |
| 11 | **Compatibilidad con Flutter validada** antes de cambiar contratos o códigos de error | Evitar romper el cliente productivo | B-06 |
| 12 | **Idempotencia / control de concurrencia** (p. ej. `updated_at` o versión en el `PUT`) | Dos administradores editando el mismo registro | nuevo |

Mientras falte cualquiera de los puntos 1–5 y 7–9, la interfaz de escritura se mantiene **desactivada por capacidad** (`ApiCapabilities`), no solo oculta.

## 4. Diseño de frontend (sin implementar)
- Capacidades granulares: `adminDirectory`, `companyWrite`, `reportWrite`, `assignmentWrite`, `userWrite`, `roleChange`.
- Cada escritura: formulario con validación local **y** errores por campo del servidor; diálogo de confirmación para bajas y cambios de rol; el mensaje de éxito solo tras la respuesta real; recarga del listado afectado; manejo explícito de 401/403/409.
- El mock conserva los flujos del prototipo para pruebas y demostraciones; el modo HTTP muestra «solo lectura» hasta activar cada capacidad.
- Pruebas: componentes para formularios y asistente; contrato (`qa/contract.mjs`) con cada escritura sobre la base sintética; E2E contra PHP real (crear, editar, desactivar, asignar, revocar, error de permisos); matriz usuario × rol × endpoint.

## 5. Plan de entrega sugerido
| Hito | Contenido | Bloqueo |
|---|---|---|
| UX-06B.1a | Lectura administrativa completa y paginada (§2 fase 1) | PR #4 fusionado |
| UX-06B.1b | Requisitos de §3 puntos 1–5, 7–9 en backend (PR propio) con pruebas | decisiones de producto y validación Flutter |
| UX-06B.1c | Escrituras de empresas e informes | 1b |
| UX-06B.1d | Asistente de asignaciones | 1b, 1c |
| UX-06B.1e | Alta/baja de usuarios y cambio de rol | endpoints nuevos + 1b |

## 6. Decisiones que necesito
1. ¿Se aprueba la secuencia a→e y que el CRUD de usuarios quede al final?
2. ¿Quién define el modelo de auditoría (tabla y retención)?
3. ¿Se acepta añadir filtros `?user_id=`/`?reporte_id=` a los listados de relaciones para evitar N+1?
4. ¿Política para el último administrador y para la desactivación de usuarios con sesiones activas?
