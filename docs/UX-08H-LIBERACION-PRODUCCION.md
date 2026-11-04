# UX-08H · Liberación a producción de la Fase 2 (procedimientos 2A y 2B por separado)

> **Actualización UX-08H.1:** se descartó el QA aislado (8095); la ruta vigente hacia producción está en `docs/UX-08H1-LIBERACION-DIRECTA-PRODUCCION.md`. Fase 2B (Usuarios) está **bloqueada por código** sobre la base productiva. Las secciones sobre el kit QA de este documento son históricas.

> **Estado: PREPARADO, NO EJECUTADO.** Nada de este documento se ha corrido en ningún servidor. Cada paso que toca producción **exige autorización expresa por separado** del responsable. No se modifica Flutter, Apache, `cervesur_portalbi` (salvo las dos migraciones aditivas de §2), ni el administrador publicado en 8443 hasta el paso indicado.

## 0. Principios
- **Orden:** QA aislado (§1) → Fase 2A (§3) → solo después, y por separado, Fase 2B (§4).
- **Rollback barato:** las migraciones son **aditivas** (una tabla y dos triggers). Volver a la Fase 1 es cambiar el modo del `.env` y el paquete; no hay que deshacer datos.
- **Interruptores de servidor:** cualquier escritura se apaga editando el `.env` (sin redeploy). Con todo apagado el modo nuevo no puede escribir.
- **Nunca** `USER_ADMIN_WRITES=1` en 443/8443 y **nunca** el `JWT_SECRET` de Flutter en esta instancia.

## 1. QA aislado (previo a todo)
Kit instalable `deploy/qa-escritura/` (README propio): puerto **explícito** en loopback, acceso por túnel SSH, base y cuentas propias con datos sintéticos, instalación abortable, `verificar.sh`, `actualizar.sh` (simular / real / revertir) y `revertir.sh`. **No** reinstala ni toca el QA 8094 anterior. Criterio de salida: `verificar.sh` en GO, recorrido manual de la guía (empresas, informes con enlace de solo escritura, accesos, usuarios, apagado de cada interruptor) y evidencia guardada.

## 2. Migraciones y cuentas necesarias (preparadas, **no aplicadas**)
| # | Qué | Archivo | Quién / cuándo | Reversión |
|---|---|---|---|---|
| M1 | Tabla `audit_log` (aditiva) | `database/migrations/UX-08B-audit-log.up.sql` | DBA, antes de 2A | `…audit-log.down.sql` (borra solo la bitácora) |
| M2 | Triggers de solo inserción en `audit_log` | `database/migrations/UX-08B-audit-log-immutable.up.sql` | DBA con `SUPER` (en el servidor `log_bin=1`, `log_bin_trust_function_creators=0` → sin `SUPER` da error 1419) | `…immutable.down.sql` |
| M3 | Cuenta de escritura nueva y mínima | `database/privileges/UX-08H-cuenta-escritura-produccion.sql.template` (sustituir `{DB} {USER} {HOST} {PASS}` a mano; contraseña fuera del historial del shell) | DBA, antes de 2A | `DROP USER` (no toca datos) |
| — | Cuentas de autenticación/estado y base de estado | **ya existen** (las de `piloto_admin`: `cervesur_ro_auth_adm`, `cervesur_react_estado_adm`, `cervesur_react_estado_admin`) | se reutilizan | — |
| — | `UX-08B3` (grupo_id NULL) | **NO se aplica**: `grupo_id` sigue `NOT NULL` | — | — |

**Verificación de la cuenta (solo lectura), antes de arrancar:**
```sql
SHOW GRANTS FOR '<cuenta>'@'<host>';   -- sin DELETE, ALL PRIVILEGES, GRANT OPTION, TRIGGER, DDL, ni nada fuera de la base de negocio
SELECT COUNT(*) FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA='cervesur_portalbi' AND EVENT_OBJECT_TABLE='audit_log';  -- 2
SELECT COUNT(*) FROM information_schema.COLUMN_PRIVILEGES WHERE GRANTEE LIKE '%<cuenta>%' AND COLUMN_NAME IN ('password') AND PRIVILEGE_TYPE='SELECT';  -- 0
```
El propio backend repite `SHOW GRANTS` al arrancar y **no arranca** (503) si encuentra un privilegio de más.

## 3. Fase 2A — Empresas, Informes y Accesos
**Pre-requisitos:** QA §1 en GO · backup lógico verificado de `cervesur_portalbi` (solo las 6 tablas de negocio) y del paquete publicado (`actualizar.sh` ya hace el respaldo SHA-256) · M1–M3 aplicadas y verificadas · autorización escrita.
1. **Simular:** `sudo bash scripts/actualizar.sh --simular` con el paquete nuevo (no escribe nada; comprueba sintaxis PHP, identidad y puertos).
2. **Configurar el `.env`** de la instancia (el actual de 8443 conserva sus valores): `CERVESUR_REQUIRED_MODE=admin_escritura`, `CERVESUR_MODE=admin_escritura`, `DB_USER/DB_PASS` = cuenta M3, `AUDIT_LOG_MODE=required`, `REACT_STRICT_VIEWS=1`, `JWT_SECRET` propio (≥ 32), y **solo** `ADMIN_WRITES_EMPRESAS=1`, `ADMIN_WRITES_REPORTES=1`, `ADMIN_WRITES_ACCESOS=1`. **`ADMIN_WRITES_USUARIOS` ausente.** `CERVESUR_PILOT_VISOR_IDS` sin cambios.
3. **Actualizar:** `sudo bash scripts/actualizar.sh` (respaldo verificado antes de reemplazar; reversión automática si falla la verificación).
4. **Verificar:** ping; login; `GET /api/admin/capacidades` → empresas/reportes/accesos `true`, usuarios `false`; un alta de prueba con su fila en `audit_log`; una escritura de usuarios → 403 `escritura_desactivada`; el visor sigue entregando enlaces solo a los informes autorizados.
5. **Observación** durante el primer día: revisar `audit_log` y los 5xx; ante cualquier duda apagar un interruptor.

**Rollback 2A (orden):** (1) apagar interruptores (`ADMIN_WRITES_*` fuera del `.env`) → sin escrituras en segundos; (2) si hace falta, `sudo bash scripts/actualizar.sh --revertir` (restaura el paquete y el `.env` anteriores, `piloto_admin`); (3) `audit_log` se **conserva** (evidencia; nunca se borra); (4) la cuenta M3 puede dejarse sin uso o eliminarse (`DROP USER`).

## 4. Fase 2B — Usuarios (**bloqueada** hasta cumplir §4.1)
### 4.1 Condiciones previas (todas)
| # | Condición | Estado |
|---|---|---|
| U1 | Arnés de compatibilidad repetido con una **copia del backend real de Flutter** (los 33 PHP de `/var/www/cervesur/backend`, sin `.env`) | **pendiente** — hoy solo existe la referencia reconstruida |
| U2 | `JWT_EXPIRATION` real confirmado; `ADMIN_USER_REACTIVATE_HOURS` ≥ ese valor | pendiente de confirmar |
| U3 | Aceptación escrita de los riesgos R3/R4 (rol y contraseña no invalidan tokens antiguos de Flutter) o decisión de mantenerlos apagados | pendiente |
| U4 | 2A estable al menos un ciclo de operación | pendiente |

### 4.2 Pasos
1. Por defecto encender **solo** `ADMIN_WRITES_USUARIOS=1` (alta, edición de datos, desactivar y reactivar **con espera**). **No** encender `ADMIN_WRITES_USUARIOS_SENSIBLE` (rol/contraseña) mientras U3 no esté firmada.
2. Verificar: alta; desactivar (Flutter lo rechaza de inmediato); reactivar antes de la espera → 409; rol/contraseña → 403.
3. Informar a los operadores: el cierre de sesión de **Flutter** de un administrador también cierra su sesión de React (`session_version` compartido).

**Rollback 2B:** quitar `ADMIN_WRITES_USUARIOS` del `.env` (efecto inmediato). Los usuarios creados/editados permanecen; la auditoría los documenta.

## 5. Matriz GO / NO-GO
| Criterio | 2A | 2B |
|---|---|---|
| QA aislado `verificar.sh` en GO y recorrido manual con evidencia | requerido | requerido |
| Suites automáticas verdes (ver informe QA) | requerido | requerido |
| M1–M3 aplicadas y `SHOW GRANTS` verificado | requerido | (ya) |
| Backup verificado + `actualizar.sh --simular` en código 0 | requerido | requerido |
| Ningún privilegio de más; `audit_log` inmutable (2 triggers) | requerido | (ya) |
| Arnés contra el Flutter **real** (U1) | no aplica | **requerido** |
| Espera ≥ `JWT_EXPIRATION` (U2) | no aplica | **requerido** |
| Riesgos R3/R4 aceptados o rol/contraseña apagados (U3) | no aplica | **requerido** |
| Autorización expresa por separado | requerido | requerido |
| **Resultado hoy** | **GO condicionado** a QA instalado y migraciones | **NO-GO** hasta U1–U3 |

## 6. Recuperación
- **Datos de negocio:** el backup lógico previo + `audit_log` (qué cambió, quién, cuándo, antes/después sin secretos) permiten reconstruir; las bajas son lógicas, así que casi todo se revierte reactivando.
- **Aplicación:** `actualizar.sh --revertir` o el respaldo SHA-256 automático (`/var/backups/cervesur-react-admin/actualizacion-<sello>`, nunca se borra).
- **Auditoría rota:** si `audit_log` falla, el sistema **no confirma** cambios (falla cerrado). Restablecer el permiso `INSERT` o revertir a `piloto_admin`.
