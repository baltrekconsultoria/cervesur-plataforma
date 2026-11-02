# UX-08B.2 · Plan de despliegue y reversión propuesto (NO ejecutado)

Nada de lo siguiente se ejecuta sin autorización expresa y una ventana acordada. Flutter (puerto 80), Apache, certificados y VPN no se tocan en ninguna fase. Este plan solo cubre las **instancias React** (trabajadores 443 y administrador 8443) y **sus bases piloto**.

## 0. Puertas previas (todas deben estar cerradas)

| # | Puerta | Estado actual |
|---|---|---|
| G1 | Auditoría del backend original completada (P1–P7) | Pendiente (comandos en `UX-08-COMPATIBILIDAD-FLUTTER.md` §6) |
| G2 | `SHOW GRANTS` de las cuentas efectivas de cada instancia; decisión sobre cuentas dedicadas | Pendiente (O8) |
| G3 | Mecanismo de aprobación del piloto administrativo leído y clasificado | Pendiente (`UX-08C-COMPATIBILIDAD-DESPLEGADA.md` §2–3) |
| G4 | Respaldo verificado de las bases piloto y de los `.env` | Pendiente |
| G5 | Revisión de código y aprobación del responsable del paquete | Pendiente |
| G6 | Verificación de que las bases piloto tienen los `UNIQUE` esperados (o decisión de añadirlos) | Pendiente |

## 1. Orden de despliegue (por instancia; primero la de pruebas)

| Paso | Acción | Verificación | Reversión |
|---|---|---|---|
| 1 | Respaldo de la base piloto y del backend actual (copia del directorio) | Archivo restaurable en otro lugar | — |
| 2 | Aplicar `UX-08B-audit-log.up.sql` en la **base piloto** | `SHOW COLUMNS FROM audit_log` | `UX-08B-audit-log.down.sql` |
| 3 | Aplicar `UX-08B-audit-log-immutable.up.sql` (triggers de solo inserción) | Un `UPDATE` de prueba sobre una fila de prueba falla | `UX-08B-audit-log-immutable.down.sql` |
| 4 | Desplegar el código backend nuevo (sin variables nuevas: se comporta como el anterior) | Pruebas de humo: login, listados, visor de DB031 en trabajadores | Restaurar la copia del paso 1 |
| 5 | `AUDIT_LOG_MODE=required` | Una escritura de prueba deja exactamente una fila en `audit_log` | Quitar la línea |
| 6 | Desplegar el frontend (build de producción con `CERVESUR_ALLOWED_HOSTS`) | Pantallas administrativas; botones; historial | Restaurar el build anterior |
| 7 | `REACT_STRICT_VIEWS=1` **solo tras revisar** que ningún cliente de esa instancia usa los campos completos | Contrato en modo estricto; E2E de React | Quitar la línea |
| 8 | Autorización de DB031 según `UX-08C-COMPATIBILIDAD-DESPLEGADA.md` §3 | 72 → 200; otro informe → 403 sin enlace | Restaurar el respaldo del archivo/estado tocado |
| 9 | Habilitar escrituras en la interfaz (`adminWrites`) | Alta/edición/baja/reactivación de un registro de prueba | Ocultar `adminWrites` |

**Usuarios (`USER_ADMIN_WRITES`)** queda fuera de este plan hasta cerrar G1 y la QA de §5.2 del documento de compatibilidad.

## 2. Privilegios MySQL

No se concede nada en `cervesur_portalbi`. Para las bases piloto:

| Cuenta | Privilegios propuestos | No debe tener |
|---|---|---|
| Instancia de trabajadores | `SELECT` sobre las seis tablas; `UPDATE(last_login, session_version, first_name, last_name, phone, avatar)` sobre `user` | `INSERT`, `DELETE`, DDL, `TRIGGER` |
| Instancia de administrador | `SELECT`, `INSERT` y `UPDATE` (por columnas en `user`) sobre las seis tablas; **solo `INSERT` y `SELECT` sobre `audit_log`** | **`DELETE`**, `DROP`, `TRUNCATE`, `TRIGGER`, `ALTER`, `GRANT` |

Las cuentas conocidas hoy (`cervesur_react_estado*`) tienen `DELETE` a nivel de base: retirarlo es una decisión del responsable (O7). No se cambia aquí.

## 3. Reversión global

1. Quitar `AUDIT_LOG_MODE`, `REACT_STRICT_VIEWS` y cualquier variable de DB031 de los `.env` de las instancias React.
2. Restaurar el backend y el frontend anteriores (copias del paso 1).
3. Opcional: exportar y eliminar `audit_log` (`…-immutable.down.sql` y luego `…audit-log.down.sql`).
4. No hay datos de negocio que revertir salvo los cambios hechos por los propios administradores durante la ventana, que son bajas lógicas reactivables y quedan en la bitácora.

## 4. Criterios de aceptación de la ventana

Flutter en `http://192.168.200.12` responde igual que antes; DB031 sigue funcionando para trabajadores; el administrador ve DB031 y recibe 403 en cualquier otro informe no aprobado; todas las escrituras de prueba generan una fila de auditoría con la misma correlación que la respuesta; los usuarios normales reciben 403 en cualquier ruta administrativa.
