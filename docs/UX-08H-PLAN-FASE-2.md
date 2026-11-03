# UX-08H · Plan de la Fase 2 — administración productiva con escrituras

Rama `feat/cervesur-ux-08h-admin-writes` (desde `e1d7567`, el RC de UX-08G.3 ya liberado como Fase 1). **Nada de esto está desplegado.** Sin SSH, push, merge, cambios en producción, Apache, `cervesur_portalbi`, cuentas reales, Flutter, el administrador publicado en 8443, QA 8094 ni DB031.

## 1. Qué es y qué no es
| | Fase 1 (publicada) | Fase 2 (esta rama) |
|---|---|---|
| Modo del backend | `piloto_admin` — **sin cambios**, solo lectura en 5 capas | `admin_escritura` — modo **nuevo y explícito** |
| Escrituras | ninguna posible (ni con `USER_ADMIN_WRITES=1`) | solo por capacidad encendida en el servidor |
| Cuenta MySQL | columnas `SELECT` (3 cuentas `cervesur_ro_*`) | las mismas de autenticación/estado + **una cuenta de escritura nueva y mínima** |
| Auditoría | no aplica | obligatoria, **en la misma transacción** que cada cambio |

`piloto_admin` no se modificó: sigue rechazando toda escritura aunque exista `ADMIN_WRITES_*` en el entorno (probado en `tests/admin_write_unit.php` y en `tests/test_security_static.py`). El modo nuevo es otro valor de `CERVESUR_REQUIRED_MODE`; el despliegue decide cuál corre.

## 2. Arquitectura de autorización y escritura
1. **Entrada:** misma sesión opaca del piloto (256 bits, guardada con hash en la base de estado). En **cada** petición se revalida: administrador activo, rol `admin`, lista blanca `usuarios_piloto`, `session_version` y huella de contraseña.
2. **Puerta de rutas cerrada** (`admin_write_routes`): toda ruta fuera de la tabla → 403 antes de cualquier controlador (DELETE físico, diagnóstico, Power BI heredado, variantes de método o de ruta). Cada ruta de escritura lleva una **capacidad**; si el servidor la tiene apagada → 403 `escritura_desactivada`. Las capacidades se leen del entorno **en cada petición** (apagables sin redeploy; solo vale el valor exacto `1`).
3. **Capacidades independientes:** `ADMIN_WRITES_EMPRESAS`, `_REPORTES`, `_ACCESOS`, `_USUARIOS`; y `ADMIN_WRITES_USUARIOS_SENSIBLE` (rol y contraseña, exige `_USUARIOS`).
4. **Guardia de SQL** (`WriteGuardedPDO`): solo `SELECT`, `INSERT`/`UPDATE` sobre 6 tablas de negocio (más `INSERT` en `audit_log`); sin DELETE/DDL/GRANT/comentarios/`;`/funciones peligrosas.
5. **Cuenta MySQL mínima** (`database/privileges/UX-08H-cuenta-escritura-produccion.sql.template`): `SELECT`/`INSERT`/`UPDATE` **por columnas**; sin `user.password`, sin `empresa.powerbi_*`; sin DELETE, DDL, TRIGGER ni GRANT OPTION; `audit_log` solo `SELECT` + `INSERT`. Al arrancar se ejecuta `SHOW GRANTS` y cualquier privilegio de más **impide el arranque** (503).
6. **Auditoría atómica:** cada cambio hace `db_tx` → modelo + `audit_record` → commit. Si la auditoría falla, **no se confirma nada** (probado quitando el permiso de `INSERT` en `audit_log`). La bitácora es de solo inserción (triggers UX-08B.2).
7. **Visor de Power BI:** misma política que el piloto (lista `CERVESUR_PILOT_VISOR_IDS` leída del servidor + informe activo + auditoría). **Ninguna otra respuesta lleva un enlace**: el formulario devuelve `enlace_configurado` y el enlace es de solo escritura; la auditoría guarda solo una huella (`enlace_sha256_12`). Dejar el enlace vacío al editar conserva el vigente.
8. **Fail-closed de configuración:** `AUDIT_LOG_MODE=required`, `REACT_STRICT_VIEWS=1`, `JWT_SECRET` propio ≥ 32 caracteres (**nunca** el de Flutter) y valores válidos en los interruptores; si no, el modo no arranca.

## 3. Matriz de reutilización
| Pieza | Origen | Qué se hizo |
|---|---|---|
| Routers Users/Reporte/Empresa/EmpresaUsuario/EmpresaReporteUsuario/Asignacion/Auditoria | UX-08B/B.2/B.3 | **Reutilizados sin cambios** (la identidad ahora sale de la sesión del piloto: `authenticate_user()` consciente del modo) |
| `write_support.php` (`db_tx`, `run_write`, `audit_record`, instantáneas, `row_version`, 409) | UX-08B | Reutilizado; `user_admin_writes_enabled()` lee `ADMIN_WRITES_USUARIOS` en el modo nuevo |
| Modelos | UX-08B | `EmpresaModel`: no toca `powerbi_*` en este modo; `ReporteController`: edición sin enlace conserva el vigente; `UserModel`: espera de reactivación |
| Registro de usuarios | UX-08B.3 | Extraído a `register_handler.php` (misma lógica, la usan `auth.php` y el modo nuevo) |
| Sesión/estado del piloto | UX-07E.B | Reutilizados (`usr_rol_modo()` ahora reconoce `admin_escritura` como administrador) |
| Migraciones `audit_log` + triggers | UX-08B / B.2 | Reutilizadas tal cual (no se crean migraciones nuevas) |
| `UX-08B3` (grupo_id NULL) | — | **No se necesita ni se aplica**: `grupo_id` sigue `NOT NULL` |
| Plantilla de cuenta UX-08E | UX-08E | Reemplazada por la UX-08H (SELECT por columnas); además se corrigió `updated_at` en `empresa_reporte` en ambas |
| Pantallas React de administración | UX-08 | Reutilizadas; botones por capacidad real del servidor |
| Suites HTTP/seed | UX-08B | `seed_writes.php` parametrizado (`FIXTURE_DB`); suite nueva sobre el backend real |

**Código nuevo:** `backend/lib/admin_escritura.php`, `backend/router/AdminWriteRouter.php`, `backend/lib/register_handler.php`, plantilla de cuenta, `tests/admin_write_unit.php`, `tests/isolated/{setup_admin_write,http_admin_escritura_test,flutter_compat_test}.php`, `frontend/src/api/http/httpAdminWrite.test.ts`, `deploy/qa-escritura/`.

## 4. Fase 2A — Empresas, Informes, Accesos
Alta, edición con **bloqueo optimista** (409 si la versión venció), baja lógica y reactivación (sin duplicar), duplicados → 409 (RUC, `reporte_id`/enlace, accesos), integridad de asignaciones (usuario/empresa/informe inexistente o inactivo → rechazo), historial visible. `reporte.grupo_id` sigue `NOT NULL` y se deriva del enlace (un enlace «Publicar en web» sin grupo se **rechaza**; no se inventa ni se deja vacío). No se genera ningún enlace público de Power BI. DB031 (ID 72) no se autoriza.

## 5. Fase 2B — Usuarios (compartidos con Flutter)
Hallazgos reproducidos en `tests/isolated/flutter_compat_test.php` (referencia **reconstruida** de la autenticación de Flutter; ver §6):
| Riesgo | Comprobado | Control implementado |
|---|---|---|
| R1 token antiguo sin `session_version` | Flutter lo acepta mientras `is_active=1` | desactivar corta **de inmediato** (Flutter revisa `is_active` en cada petición) |
| R2 reactivar revive tokens antiguos | **Confirmado** | reactivar exige una **espera** desde la última desactivación auditada (`ADMIN_USER_REACTIVATE_HOURS`, por defecto **24 h** = vida del token); 409 antes |
| R3 Flutter toma el rol del JWT | **Confirmado**: el token antiguo conserva el rol viejo | cambio de **rol** tras interruptor aparte (`_USUARIOS_SENSIBLE`, apagado por defecto) |
| R4 cambio de contraseña no mata tokens antiguos | **Confirmado** | idem: tras el interruptor sensible |
| R5 `session_version` compartido | El logout de Flutter cierra también la sesión de React del mismo administrador | documentado en la guía de operación |
| Último administrador | — | no se puede desactivar/degradar al último administrador activo ni a uno mismo |

**Lo que se puede liberar de Usuarios sin riesgo nuevo:** alta, edición de datos personales y desactivar. **Reactivar** solo con la espera. **Rol y contraseña** quedan fuera hasta resolver R3/R4 (§7).

## 6. Pruebas
Ver `docs/UX-08H-INFORME-QA.md`. Resumen: PHP unitario del modo, estáticas Python, suite HTTP sobre el backend real con la cuenta mínima, compatibilidad con Flutter (reconstruida), Vitest, `tsc`, ESLint, build y escaneo de secretos, y la réplica completa de la CI de la fase 1 para regresión.

**Limitación honesta:** el código PHP real de Flutter no está en este entorno (solo sus huellas en la evidencia 04). El arnés reproduce **solo lo verificado** (evidencia 07). Antes de habilitar Usuarios en producción debe repetirse contra una copia de los 33 archivos de `/var/www/cervesur/backend` (sin `.env`); el procedimiento está en la guía de liberación.

## 7. Qué bloquea Usuarios (2B)
1. Repetir el arnés con el **backend real de Flutter** (hoy es reconstruido).
2. Confirmar `JWT_EXPIRATION` real de producción: la espera de reactivación debe ser ≥ ese valor.
3. R3/R4 solo se cierran del lado de Flutter (exigir `session_version` en todos los tokens y tomar el rol de la base): fuera del alcance (no se toca Flutter publicado).
4. Flutter escribe las mismas tablas **sin transacciones** y con una cuenta MySQL amplia: escrituras simultáneas de ambos lados no se coordinan.
5. Decisión del responsable sobre el riesgo residual documentado.

## 8. Entregables
Código y pruebas (esta rama) · SQL preparado (sin aplicar) · cuenta mínima (plantilla) · kit QA instalable `deploy/qa-escritura/` · `docs/UX-08H-INFORME-QA.md` · `docs/UX-08H-LIBERACION-PRODUCCION.md` (procedimientos 2A y 2B separados, rollback, matriz GO/NO-GO).
