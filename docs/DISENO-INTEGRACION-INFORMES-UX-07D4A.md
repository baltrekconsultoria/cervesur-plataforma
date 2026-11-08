# UX-07D.4A · Preparación de la integración real de informes y permisos (diagnóstico y plan)

Estado: **solo diagnóstico, pruebas con datos sintéticos y plan.** No se conectó a producción, no se habilitaron usuarios reales ni enlaces productivos, no se crearon cuentas ni sitios, no se ejecutaron migraciones y no se tocó Flutter, 8088, 8089 ni 8090.
Los resultados de instalación (21 PASS / 0 FAIL) y de conciliación (27 métricas, código 0) del 8090 los obtuvo el operador en el servidor real; aquí se toman como dados.

## 1. Diagnóstico de compatibilidad

### Cadena de permisos (lo que decide si un usuario ve un informe)
Un usuario ve el informe `R` **solo si TODAS** estas condiciones se cumplen a la vez (la API las evalúa en cada petición):
`user.is_active=1` · `user_empresa_reporte.activo=1` · `empresa_reporte.activo=1` · `empresa.activo=1` · `empresa_usuario.activo=1` (mismo usuario y empresa) · `reporte.activo=1`.
La identidad interna es `reporte.id` (numérico); `reporte.reporte_id` es la referencia histórica (hasta 50 caracteres, no UUID) y se conserva sin alterar.

### Resultado de las pruebas (`tests/isolated/http_permissions_matrix_test.php`, 27 comprobaciones, instancia de SOLO LECTURA con la cuenta de columnas limitadas)
| Comprobación | Resultado |
|---|---|
| Inventario sintético: 76 usuarios, 26 empresas, 95 informes (82 activos), 102 asignaciones individuales, **91 permisos efectivos = 91 pares usuario–informe**, 57 referencias de más de 36 caracteres y 3 UUID (inactivos) | reproducido |
| Catálogo de **cada uno de los 62 usuarios activos** vs. permiso efectivo calculado en SQL | **idéntico** (administradores: los 82 activos) |
| 61 usuarios no administradores con acceso; 14 usuarios inactivos sin inicio de sesión (aunque 2 asignaciones suyas sigan activas) | confirmado |
| Visor directo de los 173 informes de los catálogos | 200 y `id` numérico correcto |
| Acceso directo a informes NO asignados (122 casos, visor y detalle) | 404, sin enlace |
| Cada eslabón desactivado por separado (asignación, empresa–informe, empresa, usuario–empresa, informe) | visor 404, detalle 404, fuera del catálogo, sin enlace; al restituir, vuelve exactamente lo anterior |
| Usuario desactivado con token vigente; cambio de `session_version` | 401 en ambos casos |
| Roles: administrador vs `usuario`; rol histórico `user`; rol no reconocido | solo el administrador lista usuarios; `user` es alias sin privilegios y ve solo su permiso efectivo; un rol desconocido no concede privilegios |
| Enlaces/credenciales fuera del visor | ninguna lectura de un usuario normal contiene un enlace de Power BI ni credenciales |
| Escrituras | las seis tablas quedan idénticas bit a bit; POST/PUT/DELETE → 403 |
| Referencia histórica de 50 caracteres | intacta en el visor |

### Hallazgos de compatibilidad (a resolver en la implementación)
1. **El catálogo completo del administrador incluye el campo `enlace`** (hace falta para editar informes). En un piloto de solo lectura debe omitirse o limitarse al visor.
2. **`JWT_EXPIRATION` por defecto es 24 h** y el backend de solo lectura **no puede revocar** (logout no incrementa `session_version`). El piloto exige expiración corta y revocación propia (ver §3).
3. **No hay límite de intentos de inicio de sesión** en el backend actual (existe solo el diseño SEC-05A3); con usuarios reales es imprescindible.
4. Dos asignaciones activas pertenecen a usuarios inactivos: bloqueadas hoy por `is_active`; conviene que el piloto las siga tratando como cero acceso (cubierto por pruebas).
5. Los informes de producción son «Publicar en web»: el backend ya clasifica los enlaces (`modo`: `publico` / `privado` / `no_compatible`) y solo entrega la URL en el modo público.

## 2. Matriz de permisos

| Actor / situación | Catálogo | Visor | Detalle | Notas |
|---|---|---|---|---|
| Usuario activo con cadena completa | solo sus informes | 200 con `url` | 200 | permiso efectivo = cadena de 6 eslabones |
| …con cualquier eslabón inactivo | no aparece | 404 | 404 | idéntico a un informe inexistente |
| Usuario sin asignación | vacío | 404 | 404 | |
| Usuario inactivo (con token anterior) | 401 | 401 | 401 | se revalida en cada petición |
| Usuario inactivo (sin token) | — | — | — | no puede iniciar sesión |
| Sesión con `session_version` distinto | 401 | 401 | 401 | cierre global de sesiones |
| Administrador activo | los 82 activos | 200 | 200 | ve todos; en el piloto **se recomienda no admitir administradores** |
| Rol histórico `usuario` / `user` | igual que usuario | | | `user` ≡ `usuario` (alias) |
| Rol desconocido | sin privilegios de administrador | | | |
| Informe inactivo | no aparece (ni para administrador) | 404 | 404 | |
| Escritura de cualquier tipo (modo lectura) | 403 `read_only` | | | la puerta de rutas y la sesión MySQL lo impiden |

## 3. Estrategia de autenticación

### Datos de partida
76 usuarios (62 activos); hashes **bcrypt `$2y$`** en todos; roles `usuario`/`user`/`admin`. El backend ya hace `password_verify()`, rechaza inactivos, valida `session_version` y `is_active` en cada petición. La cuenta de diagnóstico **no puede** leer `user.password`.

### Alternativas
| Opción | Cómo funciona | Pros | Contras |
|---|---|---|---|
| **A. Verificar bcrypt en el backend del piloto** con una cuenta MySQL de solo lectura (`cervesur_ro_pilot`, ya diseñada: SELECT por columna, incluye `user.password`) | `password_verify()` contra el hash; el hash nunca sale del servidor | sin dependencias; mismas credenciales que Flutter | exige HTTPS; el logout no puede revocar; sin límite de intentos hoy |
| **B. Copiar hashes a otra base** | — | — | **Descartada**: copiar datos de credenciales a otro almacén amplía la superficie |
| **C. Delegar en el login de Flutter** | React llama al backend de Flutter | no toca hashes | acopla a un sistema que no controlamos ni documentamos aquí; riesgo de cambios |
| **D. SSO con Microsoft Entra ID** (mapeo por correo) | el usuario se autentica en Microsoft | sin contraseñas en el portal; alineado con Power BI | requiere tenant, registro de aplicación y decisión de negocio; mapeo correo→usuario; es la meta, no el primer piloto |

### Recomendación: A + estado propio (sidecar) ahora; D como evolución
El problema de A es que **no se debe escribir en la base de Flutter** (ni `last_login` ni `session_version`). Se resuelve con una **segunda base propia y exclusiva del piloto** (`cervesur_react_estado`, cuenta con DML solo ahí) que guarda:
- `pilot_users` · lista blanca de usuarios autorizados al piloto (nombre de usuario/`user.id`): **inicia sesión solo quien está en la lista y activo en la base de Flutter**.
- `revocaciones` · por usuario, `not_before`: todo token emitido antes queda inválido (logout efectivo y corte inmediato).
- `intentos_login` · limitación por usuario e IP (p. ej. 5 fallos → bloqueo de 15 minutos, mismo mensaje genérico).
- `auditoria` · quién abrió qué informe y cuándo (sin guardar el enlace).
Medidas adicionales:
- **Token ligado a la contraseña**: el JWT incluye una huella (HMAC) del hash vigente; si el usuario cambia su contraseña en Flutter, sus sesiones del piloto dejan de valer. No se copia el hash: solo se compara la huella en cada petición.
- **Expiración corta** (≤ 15 min con renovación) y `session_version` + `is_active` revalidados por petición (ya existe).
- **Roles**: en el piloto solo `usuario`/`user`; los administradores no inician sesión (no hay escrituras ni administración que ofrecer).
- **HTTPS obligatorio** antes de que un usuario real escriba su contraseña: hoy el acceso es HTTP por túnel SSH (cifrado por SSH hacia el servidor); para usuarios fuera del túnel se necesita TLS (certificado interno o de la organización) y cookies/almacenamiento seguros. **Sin HTTPS no hay piloto con usuarios reales.**
- Mensajes de error genéricos; tiempos de respuesta similares para usuario inexistente y contraseña incorrecta; sin enumeración de usuarios.

## 4. Riesgos de los enlaces «Publicar en web»

Un enlace de «Publicar en web» es **un secreto portador sin autenticación**: cualquiera que conozca la URL ve el informe, con o sin portal, sin iniciar sesión en Microsoft. Consecuencias:
1. **El portal solo protege el descubrimiento del enlace, no el acceso.** Una vez que un usuario autorizado abre el informe, la URL queda en su navegador (iframe, pestaña de red, historial) y puede compartirla.
2. **Retirar una asignación no revoca el acceso a un enlace ya conocido.** Solo impide que el portal lo entregue de nuevo.
3. **La única revocación efectiva de un enlace público** es eliminarlo en Power BI (Configuración → Administrar códigos de inserción → Eliminar), lo que lo invalida **para todos, incluidos los usuarios de Flutter**; si hay que seguir publicándolo, se crea un código nuevo y se actualiza `reporte.enlace` (un cambio de datos de Flutter: requiere coordinación y no puede hacerlo el piloto de solo lectura).
4. Flutter ya expone estos mismos enlaces a sus usuarios: el piloto no empeora la exposición existente, pero tampoco la corrige.
5. Los buscadores y los administradores del tenant pueden listar los códigos publicados (el administrador de Power BI ve y puede eliminar los de toda la organización).

### Qué sí se puede mejorar sin cambiar Power BI (en el portal)
- Entregar la URL **solo por el visor autorizado** (ya así), con `Cache-Control: no-store`, sin incluirla en listados ni en registros (ya probado) y sin escribirla en auditoría.
- **Redirección en servidor** (`/visor/{id}` → 302) para que la URL no pase por el JavaScript de la aplicación; reduce la fuga accidental, no impide que un usuario la copie.
- **Registro de auditoría** de aperturas para detectar uso anómalo.
- Política de uso: tratar los datos de «Publicar en web» como **públicos**; el inventario debe confirmar que ningún informe con datos sensibles se publica así.

### Alternativas seguras futuras (decisión de negocio)
| Alternativa | Protección real | Requisitos |
|---|---|---|
| **Insertar para su organización** (Entra ID) | solo usuarios del tenant con permiso en el informe | licencias Power BI por usuario o capacidad; los usuarios necesitan cuenta Microsoft |
| **Power BI Embedded (app owns data)** con token de inserción por solicitud | el portal decide quién ve qué; token de corta duración (≤ 1 h) y filtros por empresa (RLS) | capacidad Embedded/Fabric, principal de servicio; el código heredado de `powerbi_*` apunta a este camino |
| **Mantener «Publicar en web» + gobierno** | ninguna sobre el enlace; solo inventario y rotación periódica | acuerdo explícito de que los datos son públicos |

**Recomendación:** el piloto con usuarios reales solo debe abrirse si (a) el negocio confirma que los datos publicados son públicos o aceptables, o (b) ya existe la alternativa segura. Mientras tanto, el valor del piloto es demostrar permisos, autenticación y auditoría sobre el modo «Publicar en web» **sin prometer que el portal impide el acceso al enlace**.

## 5. Arquitectura recomendada del piloto (NO se crea ni se instala nada)

```
Usuario ──HTTPS──▶ Apache (cervesur-react-informes, 127.0.0.1:8091 / túnel 18092 en la fase 1)
                      └─ backend PHP exclusivo (CERVESUR_MODE=piloto_informes, fail-closed, open_basedir)
                           ├─ conexión A (solo lectura, GuardedPDO): cervesur_portalbi con cervesur_ro_pilot  [SELECT por columna]
                           └─ conexión B (estado propio): cervesur_react_estado con cervesur_react_estado   [DML solo ahí]
```
- **Sitio nuevo e independiente** (puerto, cookie, secretos, `.env`, carpetas y respaldos propios; mismo patrón de instalador que 8090). No reutiliza ni modifica Flutter, 8088, 8089 ni 8090 (que sigue siendo solo diagnóstico).
- **Cuenta MySQL separada y mínima** `cervesur_ro_pilot`: las columnas que consulta el backend; incluye `user.password` (solo para `password_verify`) y `reporte.enlace`; **nunca** `empresa.powerbi_*`; sin escritura, sin DDL. Distinta de la de diagnóstico.
- **Modo `piloto_informes`**: rutas permitidas solo `POST /api/login`, `POST /api/logout`, `GET /api/ping`, `GET /api/session`, `GET /api/reportes?view=minimal`, `GET /api/reportes/id/{id}/visor`. Todo lo demás (usuarios, empresas, asignaciones, estadísticas, administración, escritura) → 403. La escritura en la base de Flutter sigue imposible por tres capas (puerta de rutas, guardia SQL, sesión y cuenta de solo lectura); la conexión B solo toca las tablas de estado.
- **Consultas por usuario y empresa**: se conservan las actuales (cadena de 6 eslabones); sin cambios de semántica.
- **Protección de enlaces**: visor autorizado, `no-store`, auditoría sin enlace, redirección en servidor (opcional), y la advertencia de §4.
- **Interfaz**: la compilación del piloto muestra solo inicio de sesión, catálogo y visor; los botones de administración/escritura no se ofrecen (y, aun así, el servidor los rechaza: los botones ocultos no son un control).

## 6. Archivos que deberán modificarse (en las etapas siguientes)
| Área | Archivo | Cambio |
|---|---|---|
| Backend | `backend/lib/mode.php` | nuevo modo `piloto_informes` (fail-closed, requerido por despliegue), lista de rutas, validaciones de configuración |
| Backend | `backend/lib/db.php` | segunda conexión (estado) con su cuenta; la conexión principal sigue de solo lectura |
| Backend | `backend/lib/estado.php` (nuevo) | revocaciones, intentos, auditoría, lista blanca; consultas parametrizadas y cerradas |
| Backend | `backend/controllers/AuthController.php`, `router/auth.php` | límite de intentos, lista blanca, huella de contraseña en el token, logout con revocación propia (sin tocar `session_version`) |
| Backend | `backend/lib/auth_middleware.php` | revocación (`not_before`), huella, expiración corta |
| Backend | `backend/controllers/ReporteController.php`, `models/ReporteModel.php` | omitir `enlace` en catálogos de administrador en el modo piloto; auditoría de aperturas |
| Base de datos | `database/schema/piloto/*.sql` (nuevo) | DDL de `cervesur_react_estado` y su cuenta (procedimiento administrativo con autorización) |
| Despliegue | `deploy/tunel-ssh-informes/` (nuevo) | instalador clonado de 8090 con los preflights A (infra) y B (acceso) ampliados |
| Frontend | `frontend/src/**` | perfil de compilación «piloto» (rutas y acciones mínimas), manejo de expiración corta y revocación, sin botones de escritura |
| Pruebas | `tests/isolated/*`, `tests/test_security_static.py`, `frontend/e2e/**`, CI | ver §7 |

## 7. Plan de implementación y pruebas
1. **UX-07D.4B · Estado propio y modo `piloto_informes`** (solo local): esquema sidecar, límite de intentos, revocación, lista blanca, huella de contraseña, auditoría; pruebas HTTP con MySQL sintético (dos bases, dos cuentas).
2. **UX-07D.4C · Frontend del piloto**: perfil mínimo, expiración/renovación, errores genéricos; contratos y E2E.
3. **UX-07D.4D · Instalador del sitio del piloto** (clon del 8090): preflights, ensayo Docker con Flutter/8088/8089/8090, reversión, paquete.
4. **UX-07D.4E · Preparación de producción** (sin ejecutar): SQL administrativo revisado (cuenta `cervesur_ro_pilot`, base de estado), preflights contra el servidor, plan de TLS.
5. **Piloto real** solo tras las condiciones del §8.
Pruebas obligatorias en cada etapa: matriz de permisos (esta etapa ya la deja lista y verde), ausencia de escrituras en la base de Flutter (CHECKSUM + privilegios + contadores), no exposición de enlaces/hashes (respuestas, logs, auditoría), usuarios inactivos/revocados/expirados, límite de intentos, rotación de secretos, reversión y 0 cambios ajenos.

## 8. Condiciones GO / NO-GO para un piloto real
**NO-GO hasta que se cumplan TODAS:**
1. **HTTPS** operativo para el acceso de los usuarios (o acceso exclusivamente por túnel SSH nominal a un grupo muy reducido y documentado).
2. **Decisión de negocio sobre «Publicar en web»**: datos aceptados como públicos, o alternativa segura aprobada (§4). Inventario de los informes y de su sensibilidad.
3. **Lista blanca** de usuarios del piloto, aprobada y nominal; sin administradores.
4. Etapas 4B–4D completadas y probadas: estado propio, límite de intentos, revocación, huella de contraseña, auditoría, instalador con reversión y 0 cambios ajenos.
5. **Cuentas MySQL** `cervesur_ro_pilot` y de estado creadas con procedimiento administrativo revisado y autorizado; verificación de privilegios (sin escritura sobre `cervesur_portalbi`); contadores de `performance_schema` **interpretados junto a los privilegios efectivos**, nunca por sí solos.
6. Estructura real contrastada (UNIQUE, PK, disparadores, versión de MySQL) mediante el procedimiento de solo lectura.
7. Respaldo y plan de reversión probados; comunicación a los usuarios y canal de soporte; política de rotación de secretos.
**GO condicionado** a un piloto reducido (≤ 5 usuarios nominales) con auditoría activa y revisión semanal, y un criterio de cierre inmediato (revertir sitio, `ACCOUNT LOCK` de la cuenta).
