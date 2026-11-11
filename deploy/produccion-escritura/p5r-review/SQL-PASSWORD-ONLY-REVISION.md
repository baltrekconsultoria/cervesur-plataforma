# Cervesur · P5-R — propuesta SQL de privilegio mínimo (SOLO REVISIÓN)

**Estado: candidato documental. NO EJECUTAR EN PRODUCCIÓN.** Fecha 11/11/2026.

## Principio
La etapa **P4a no-sensibles** ya pasó en MySQL productivo el 11/11/2026. La cuenta `'cervesur_escritura_adm'@'127.0.0.1'` tiene acceso mínimo para alta, nombres, teléfono, actividad y `session_version`. **No repetir P4a**.

P5-R solo agrega `UPDATE(password)` a la misma cuenta; **nunca** `UPDATE(role)`, `UPDATE(email)`, `INSERT(user)`, `DELETE` ni permisos de tabla completa.

## Boceto a dictaminar por DBA, NO ejecutable sin revisión y firmas

**Ejemplo de concesión, sujeto a comprobación de DBA y respaldo de la ventana:**

```sql
GRANT UPDATE (password)
ON `cervesur_portalbi`.`user`
TO 'cervesur_escritura_adm'@'127.0.0.1';
```

**Reversión específica de P5-R, sujeto a ensayo y autorización:**

```sql
REVOKE UPDATE (password)
ON `cervesur_portalbi`.`user`
FROM 'cervesur_escritura_adm'@'127.0.0.1';
```

- MySQL DDL/GRANT/REVOKE **no es transaccional**. Preparar y ensayar algoritmo de verificación y rollback antes de ejecutar.
- La cuenta y host se han deducido de la **salida P4a real**; comparar con `CURRENT_USER()`, `SHOW GRANTS`, `SHOW GRANTS FOR...` y la conexión efectiva 8443 antes de aplicar.
- P5-R no redefine ni actualiza el usuario existente, y el reset utiliza `UPDATE user SET password = :p, session_version = session_version + 1, updated_at = NOW() WHERE id = :id` dentro de transacción de negocio con bloqueo de filas y auditoría obligatoria.
- Al activar reset, el backend de PR #19 debe **rechazar el arranque (503)** si se configura P5-R ON pero el `SHOW GRANTS` efectivo carece de `UPDATE(password)`. Si hay `UPDATE(role)` o `UPDATE` a toda la tabla, también rechazar. No basta la presencia de un interruptor.
- El procedimiento heredado `privilegios-usuarios.sh --etapa sensibles` **NO se admite**: su plantilla añade contraseña **y rol**.

## Secuencia para futura liberación (pendiente de aprobaciones)

1. Puertas P4b y C-5/K1/K2/D4/F-10, seguridad y responsables de Infra y DBA cerradas; no basta el QA aislado.
2. T-EXP: demostrar respuesta 401 para JWT anterior tras `session_version+1` y re-login 200 en **ruta PHP real y ruta Firebase Cloud Functions realmente usada por Flutter**; reparar UX si muestra tablas vacías. El 401 interno observado en Firefox no prueba cuál token se revocó ni la recuperación visual.
3. Revisión de PR #19 y #20 apilados sobre #14/#15; comparación de huellas con producción.
4. Respaldo nuevo de ventana, procedimiento DBA ensayo/rollback, `SHOW GRANTS` antes y después, prueba del verificador P5-R en modo solo lectura.
5. Encender **primero** U-BASIC con su kit oficial y autorización por operación; después, con nueva autorización P5-R, valorar `ADMIN_WRITES_USUARIOS_PASSWORD_RESET=1` y la atestación `ADMIN_P5R_FLUTTER_401_VERIFIED=1`.
6. Crear/restablecer **una cuenta QA autorizada** y verificar: solo evento auditado, hash no revelado, usuario puede entrar con nueva contraseña, JWT anterior 401 y asignaciones preexistentes inalteradas.
7. Reversión: apagar **primero** P5-R, comprobar 403, revisar y revocar solamente `UPDATE(password)` con DBA, restaurar flags y no afectar la gestión básica.

**Control crítico:** el interruptor antiguo U-BASIC `interruptor-usuarios.sh apagar` solo elimina dos claves: `ADMIN_WRITES_USUARIOS` y `ADMIN_WRITES_USUARIOS_SENSIBLE`. Antes de liberar P5-R hay que ampliar su apagado para retirar también `ADMIN_WRITES_USUARIOS_PASSWORD_RESET` y `ADMIN_P5R_FLUTTER_401_VERIFIED`; de lo contrario puede dejar configuración contradictoria. No usar ese kit como rollback P5-R sin corregir y probarlo.

## Veredicto
**GO** para diseño y QA aislado del permiso mínimo; **NO-GO** para su ejecución SQL y para activar P5-R hasta que se satisfagan puertas y autorizaciones.
