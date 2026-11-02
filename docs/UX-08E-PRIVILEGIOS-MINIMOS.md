# UX-08E · Matriz de privilegios mínimos para las cuentas de aplicación

**No se ejecuta ningún `GRANT`, `REVOKE` ni `CREATE USER` en servidores reales.** Este documento y la plantilla `database/privileges/UX-08E-cuenta-admin-minima.sql.template` preparan una ventana posterior autorizada. La plantilla se verifica solo en un MySQL desechable.

## 1. Punto de partida (CONFIRMADO por PuTTY)

| Cuenta | Alcance | Privilegios | Valoración |
|---|---|---|---|
| `cervesur_portalbi_admin`@localhost | `cervesur_portalbi.*` | `ALL PRIVILEGES` | **Excesivo**: incluye `DELETE`, DDL y `GRANT`-adyacentes para una aplicación basada en bajas lógicas. Con `ON DELETE CASCADE`, un `DELETE` de `user`/`empresa`/`reporte` borra relaciones |
| `cervesur_react_estado`@127.0.0.1 | `cervesur_react_estado.*` | SELECT, INSERT, UPDATE, DELETE | Base aislada del piloto de trabajadores; `DELETE` a revisar |
| `cervesur_react_estado_adm`@127.0.0.1 | `cervesur_react_estado_admin.*` | SELECT, INSERT, UPDATE, DELETE | Base aislada del piloto administrativo; `DELETE` a revisar |
| `cervesur_ro_auth`, `_auth_adm`, `_catalogo`, `_catalogo_adm`, `_diag`, `_visor` | Hosts específicos | **Pendiente** (`SHOW GRANTS`) | Por nombre, son de solo lectura y separadas por función (INFERIDO) |
| `cervesur_react`, `cervesur_react_compat` | — | Pendiente | — |

Pendiente: la cuenta **efectiva** de cada conexión PHP (`DB_USER` por backend) y sus `SHOW GRANTS` completos. Comandos en `UX-08-COMPATIBILIDAD-FLUTTER.md` §0.

## 2. Operaciones reales y privilegio que necesitan

Derivado del código de este repositorio, no de suposiciones.

| Función | Tablas / columnas | Privilegio mínimo |
|---|---|---|
| **Catálogo y visor** (leer empresas, informes, asignaciones) | las 6 tablas de negocio | `SELECT` |
| **Autenticación** (login) | `user` | `SELECT`; `UPDATE(last_login)` |
| **Revocación de sesión** (logout, desactivar, cambio de rol o contraseña) | `user.session_version` | `UPDATE(session_version)` |
| **Escritura administrativa — usuarios** | `user` | `INSERT`; `UPDATE(first_name, last_name, phone, email, role, is_active, session_version, last_login, password, avatar, updated_at)` (`updated_at` porque el código la fija explícitamente). **No** `username`, `created_at` |
| **Escritura administrativa — empresas** | `empresa` | `INSERT`; `UPDATE(nombre, razon_social, ruc, email, telefono, website, direccion, ciudad, pais, logo, contacto_principal, activo, updated_by)`. **No** `powerbi_tenant`, `powerbi_client`, `powerbi_clientsecret` |
| **Escritura administrativa — informes** | `reporte` | `INSERT`; `UPDATE(nombre, descripcion, enlace, grupo_id, reporte_id, activo, updated_by)` |
| **Escritura administrativa — relaciones** | `empresa_usuario`, `empresa_reporte`, `user_empresa_reporte` | `INSERT`; `UPDATE(<ids>, notas, activo, updated_by)` |
| **Bloqueo transaccional** (`SELECT … FOR UPDATE`) | las filas padre | `SELECT` + algún `UPDATE` sobre la tabla (los `UPDATE` por columnas lo cumplen) |
| **Historial** | `audit_log` | `INSERT` y `SELECT` **únicamente** |
| **Estado aislado del piloto** | su propia base | `SELECT`, `INSERT`, `UPDATE`; `DELETE` solo si el piloto lo usa de verdad (a confirmar) |
| **Ninguna función** | — | `DELETE`, `DROP`, `TRUNCATE`, `ALTER`, `CREATE`, `TRIGGER`, `GRANT OPTION` |

## 3. Cuentas propuestas (por función, no intercambiables)

| Cuenta (propuesta) | Se usa para | Privilegios |
|---|---|---|
| Lectura del catálogo y visor (existentes `cervesur_ro_catalogo*`, `cervesur_ro_visor`) | Trabajadores y administrador, lecturas | `SELECT` (verificar con `SHOW GRANTS`) |
| Autenticación (existentes `cervesur_ro_auth*`) | Login | `SELECT` sobre `user`. Si el piloto no actualiza `last_login` en `cervesur_portalbi` (sesión en su base de estado), no necesita más |
| **Escritura administrativa (nueva, solo si se decide §4-A)** | API administrativa de UX-08B | Plantilla `UX-08E-cuenta-admin-minima.sql.template`: `SELECT`; `INSERT`; `UPDATE` por columnas; `audit_log` solo `INSERT`/`SELECT`; **sin `DELETE`** |
| Estado del piloto (existentes `cervesur_react_estado*`) | Estado y sesiones propias del piloto | `SELECT, INSERT, UPDATE` sobre su propia base; revisar `DELETE` |
| Diagnóstico (`cervesur_ro_diag`) | Solo lectura | Sin escritura |

La cuenta que inserta en `audit_log` **no puede** modificarlo ni borrarlo (privilegio) y, además, los triggers de `UX-08B-audit-log-immutable` lo impiden aunque algún día se concediera por error. Con la misma cuenta que escribe el negocio es posible porque los privilegios de MySQL son **por tabla**.

## 4. Decisión de arquitectura que esta evidencia obliga a tomar (INFERIDO, a confirmar)

Si el piloto administrativo desplegado lee `cervesur_portalbi` con cuentas de solo lectura y guarda su estado en otra base, **hoy no tiene camino de escritura** hacia las tablas de negocio. Las funciones de UX-08B (crear, editar, desactivar, asignar) necesitan que el responsable elija:

| Opción | Qué implica | Valoración |
|---|---|---|
| **A. Cuenta mínima de escritura sobre `cervesur_portalbi`** (plantilla de §3) + `audit_log` en la misma base | Misma fuente de verdad que Flutter; la auditoría es atómica con el cambio. Exige abrir una vía de escritura **nueva y acotada** | **Recomendada**: sin `DELETE`, sin DDL, sin tocar `powerbi_*`, historial de solo inserción |
| B. Escribir en la base de estado del piloto y sincronizar con `cervesur_portalbi` | Dos fuentes de verdad y un proceso de sincronización | **Rechazada**: contradice «no crear una segunda fuente de verdad» |
| C. Mantener el piloto administrativo de solo lectura y hacer las altas/ediciones en Flutter | Cero cambios de permisos | Segura; no cumple el objetivo de administrar desde React |

Cualquier opción exige además definir si `audit_log` convive con la tabla `auditoria` del piloto (I-C).

## 5. Verificación (en Docker)

| Prueba | Qué demuestra | Resultado |
|---|---|---|
| `tests/isolated/privileges_matrix_test.php` | La cuenta de la plantilla **puede** leer, insertar, desactivar (UPDATE lógico), registrar `last_login`, bloquear con `FOR UPDATE`, insertar auditoría y hacer rollback; **no puede** `DELETE` en ninguna tabla, modificar/borrar/`TRUNCATE`/`DROP` el historial, cambiar `powerbi_*` ni `username`, `ALTER`/`CREATE`, crear triggers ni cuentas, ni conceder privilegios; `SHOW GRANTS` sin `DELETE`/`DROP`/`ALL`/`GRANT OPTION` | 32 PASS |
| Suites HTTP completas ejecutadas con la aplicación conectada **como esa cuenta restringida** | El código de la aplicación funciona de verdad con privilegios mínimos (no solo las sentencias sueltas) | Ver `UX-08B3-INFORME.md` |

## 6. Procedimiento para una ventana autorizada (no se ejecuta)

1. Respaldo (`mysqldump`) de `cervesur_portalbi` y verificación de restauración.
2. Obtener `SHOW GRANTS` de las cuentas efectivas y registrar el estado inicial.
3. Crear la cuenta de escritura con la plantilla (contraseña generada en el servidor; no se guarda en el repositorio).
4. Probar con un usuario y una empresa de prueba fuera de producción real (copia o registros marcados).
5. Cambiar el `.env` de la instancia que lo necesite para usar la nueva cuenta; **no** se reutiliza `cervesur_portalbi_admin`.
6. Reversión: restaurar el `.env` anterior y `DROP USER` de la cuenta nueva. No hay datos de negocio que revertir salvo los cambios hechos por los administradores durante la ventana, que son bajas lógicas reactivables y quedan auditadas.
7. Fase posterior (decisión del responsable): reducir los privilegios de `cervesur_portalbi_admin` y revisar el `DELETE` de las cuentas de estado.
