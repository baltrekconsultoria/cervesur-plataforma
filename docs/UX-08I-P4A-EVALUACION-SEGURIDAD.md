# UX-08I · Evaluación de seguridad previa a P4a — creación de usuarios con rol elevado y cambio de correo

Candidato vigente: `ae28e6c57d5f9be7ac0806e4fa9bb7a805ed1e24` (sin cambios; esta evaluación **no** lo modifica). Prototipo de la opción recomendada: rama `spike/ux-08i-alta-por-vista`.
No se tocó producción, no hay push ni merge, P5 sigue bloqueado.

## 0. Resumen y recomendación
| | |
|---|---|
| Brecha | Con las credenciales de la cuenta de React, MySQL **permite** insertar un usuario con `role='admin'` (los privilegios por columna no acotan el valor). La API y el guardia de SQL no lo permiten. |
| Recomendación | **Alta por una vista `v_user_alta` con `WITH CHECK OPTION`** (opción C): el motor rechaza roles ≠ `user`/`usuario`, la cuenta pierde el `INSERT` sobre la tabla, y **Flutter no cambia en nada**. Más **quitar `UPDATE(email)`** a la cuenta de React. |
| Descartadas | Trigger (riesgo operativo sobre la tabla de Flutter, no la protege del cambio de DEFINER, no entra en el respaldo), CHECK (rompe a Flutter o no protege nada). El procedimiento almacenado es válido pero exige más cambios de código. |
| P4a con `ae28e6c` | **NO-GO**: la brecha del motor y el `UPDATE(email)` siguen abiertos. |
| P4a con el diseño C | **GO condicionado** a (1) adoptarlo como nuevo candidato (adaptar el kit y repetir el ensayo Docker), (2) **inspección de solo lectura del backend de Flutter desplegado** (correo, creación de administradores, cuenta MySQL), que no se pudo hacer aquí. |

## 1. Limitación crítica de esta evaluación
**No se dispone del backend de Flutter desplegado** (`/var/www/cervesur/backend`, 33 archivos), ni de su cuenta MySQL, ni de un entorno que lo ejecute. El encargo pedía pruebas «con el backend Flutter real»: **eso no se pudo cumplir**. Lo que se hizo:
- Una cuenta **simulada** de Flutter con `ALL` sobre la base de pruebas (su verdadero conjunto de privilegios es desconocido).
- La **reconstrucción** del arnés de autenticación de Flutter (`flutter_compat_test.php`, ejecutado en todas las corridas: PASS).
- La **consulta de login del repositorio** (`UserModel::getUserByLogin`) como representante de Flutter, y el código del repositorio (es la base común, pero **no se sabe si es idéntico a lo desplegado**).
Todo veredicto sobre Flutter de este documento es *«según el repositorio y la simulación»*, nunca *«verificado en Flutter real»*. El procedimiento para cerrarlo está en §8.

## 2. Matriz comparativa
Probado contra MySQL 8 real (`tests/isolated/p4a_alternativas_test.php`: 18 comprobaciones PASS).
| Criterio | A · Trigger `BEFORE INSERT` | B · Procedimiento almacenado | **C · Vista con CHECK OPTION** | D · `CHECK` en `user.role` |
|---|---|---|---|---|
| Cierra la brecha en la base | Sí, **solo mientras el trigger exista y funcione** | **Sí** (la cuenta no tiene `INSERT`) | **Sí** (la cuenta no tiene `INSERT` en la tabla; el motor rechaza roles elevados) | **No** |
| Distingue la cuenta de React | Con `USER()` (ver §3); fácil de equivocar | Sí (por privilegio) | Sí (por privilegio) | **No** (afecta a todos) |
| Impacto sobre Flutter | **Sí, puede dejarla sin altas**: un `DEFINER` inexistente rompe **todos** los `INSERT` en `user` (probado: error 1449); una cuenta amplia puede borrar/reemplazar el trigger; recrear/migrar la tabla pierde el trigger | Ninguno | **Ninguno** (la tabla no cambia) | Bloquea también a Flutter y a los administradores existentes; ni siquiera se puede crear (ya hay filas `admin`) |
| Cambios en el backend | Ninguno | Guardia (`CALL` hoy prohibido), aserción de grants (`EXECUTE`), `RegisterController` | Mínimos: tabla destino del alta, aserción de grants, guardia | — |
| Privilegios nuevos | `TRIGGER` solo para el DBA | `EXECUTE` en el procedimiento | `INSERT` en la vista | — |
| Migración (DDL no transaccional) | `CREATE TRIGGER` | `CREATE PROCEDURE` | `CREATE VIEW` + `GRANT`/`REVOKE` | `ALTER TABLE` (imposible hoy) |
| Reversión | `DROP TRIGGER` | `DROP PROCEDURE` + `REVOKE` | `DROP VIEW` + `REVOKE` | `DROP CHECK` |
| Respaldo | `crear-respaldo.sh` usa `--skip-triggers`: **no se restaura** | usa `--routines=false`: no se restaura | las vistas no están en la lista de tablas: **añadir `SHOW CREATE VIEW`** | — |
| Modo de fallo | Afecta a Flutter y a React | Solo React | **Solo React** (vista rota/ausente: 5xx genérico, sin residuos; probado) | — |
| Acota también `is_active`/`session_version` | Habría que codificarlo | Sí | **Sí** (en el `WHERE` de la vista) | — |
| Veredicto | Descartada | Alternativa válida (más código) | **Recomendada** | Descartada |

## 3. Contexto de ejecución en triggers (verificado, no asumido)
Dentro de un trigger de MySQL 8 se midió: `USER()` = la cuenta que **abrió la conexión** (`eval_react@172…` / `eval_flutter@172…`); `CURRENT_USER()` = el **DEFINER del trigger** (`root@localhost`) para **ambas**. Por tanto `CURRENT_USER()` **no** sirve para distinguir cuentas y el trigger debe usar `USER()` (con `SUBSTRING_INDEX(USER(),'@',1)`). Aun así se descarta por el riesgo operativo sobre una tabla compartida con Flutter.

## 4. Resultados de las pruebas obligatorias (prototipo C)
| Prueba pedida | Resultado | Fuente |
|---|---|---|
| Creación legítima desde React | ✔ 200, rol `user`, una auditoría sin datos personales | suite 2B (no sensibles), `p4a_vista_errores` |
| Creación legítima desde Flutter | ✔ **simulada** (cuenta `ALL` inserta `admin` y `usuario` directo en la tabla, sin cambios). **No probada con Flutter real** | `p4a_alternativas` |
| Intento de crear administrador con la cuenta de React | ✔ rechazado en 3 niveles: API (400), guardia de SQL, y **el motor** (`CHECK OPTION failed`; `INSERT` directo en `user` denegado). **Brecha residual: ninguna** | `p4_escalamiento`, suite 2B |
| Edición y desactivación | ✔ (versión/409, `session_version`, no a uno mismo, 24 h para reactivar) | suite 2B |
| Login y sesiones existentes | ✔ en la reconstrucción de Flutter y en las sesiones de React (invalidación al cambiar sensibles). **No con Flutter real** | `flutter_compat`, suite 2B |
| Manejo de errores y recuperación ante restricciones SQL | ✔ vista más estricta o inexistente → 5xx **genérico**, sin SQLSTATE ni nombres, sin usuario a medias ni auditoría huérfana; Empresas y edición siguen; al recrear la vista desde la plantilla el alta vuelve **sin reconfigurar permisos** | `p4a_vista_errores` (10 PASS) |
| Regresión de Empresas, Reportes y Accesos | ✔ suites admin-escritura **222 PASS / 0 FAIL**; regresión completa de la CI: ver §9 | aw2b, CI |

## 5. Riesgo del cambio de correo
**Qué se encontró (repositorio y prueba, `tests/isolated/p4a_correo_test.php`):**
1. **No existe** en el backend del repositorio ningún flujo de recuperación de contraseña, envío de correo ni verificación de titularidad del correo (no hay `mail()`, SMTP ni rutas de reset). No hay endpoint de cambio de contraseña para el propio usuario. Los usuarios ya pueden cambiar **su propio** correo sin verificación (`PUT /api/user/profile`), de modo que el correo no actúa como prueba de titularidad en ese código.
2. **El login acepta correo O usuario** (`WHERE (email=:l OR username=:l) AND is_active=1 LIMIT 1`, sin `ORDER BY`). Con `UPDATE(email)`, un administrador de React puede fijar el correo de un usuario **igual al nombre de usuario (con forma de correo) de un administrador**: la consulta devuelve primero al otro usuario y el administrador **deja de poder iniciar sesión** en Flutter (**denegación de servicio confirmada**). **No** hay toma de cuenta por esta vía: sigue exigiéndose la contraseña de cada cuenta.
3. **Toma de cuenta por recuperación:** *no verificable aquí.* Si el backend **desplegado** de Flutter tiene «olvidé mi contraseña» por correo, cambiar el correo de un administrador permitiría recibir el enlace. El código del repositorio no lo tiene, pero lo desplegado puede diferir. **Riesgo no descartado.**
**Restricción propuesta antes de P4a (implementada en el prototipo):** la cuenta de React **sin `UPDATE(email)`**; la edición desde el panel rechaza un correo distinto (400, mensaje claro) e ignora el mismo correo; el motor lo impide aunque la API fallara (probado). Costo: no se corrigen correos desde React (se hace por Flutter o por el DBA). **Pendiente de interfaz:** el formulario de edición todavía muestra el correo como editable; debe quedar de solo lectura.
**Verificación de solo lectura recomendada en producción (requiere autorización):** `SELECT COUNT(*) FROM user WHERE username LIKE '%@%'` (cuántos usuarios tienen forma de correo → alcance del punto 2) y `SELECT email, COUNT(*) FROM user GROUP BY email HAVING COUNT(*) > 1` (higiene).

## 6. Impacto sobre Flutter de la opción recomendada
La tabla `user` **no cambia**; Flutter conserva su `INSERT`/`UPDATE` directos. Lo único nuevo en la base es una **vista** que Flutter no usa y los `GRANT`/`REVOKE` de la cuenta de React. Modos de fallo de la vista afectan solo a React. Lo que **no** se puede afirmar sin el backend real: que Flutter no consulte `information_schema` ni dependa de la ausencia de vistas, y qué privilegios tiene su cuenta (p. ej. si podría eliminar la vista).

## 7. Migraciones y reversión (orden)
1. **DBA:** respaldo (seis tablas + `audit_log` + `SHOW CREATE VIEW`).
2. **DBA:** `UX-08I-alta-usuarios-vista.up` (crea la vista; no toca datos).
3. **DBA:** plantilla `usuarios-no-sensibles.up` modificada: `INSERT` en la vista y `UPDATE` **sin `email`**.
4. Verificación: la cuenta no tiene `INSERT` en `user`; alta con `admin` por la vista falla; alta con `user` funciona.
Reversión: `usuarios-no-sensibles.down` (revoca) → `alta-usuarios-vista.down` (`DROP VIEW`). Sin datos afectados. `GRANT`/`REVOKE`/`CREATE VIEW` no son transaccionales.

## 8. Trabajo pendiente para adoptar la opción C (no hecho)
- **Kit de despliegue:** `privilegios-usuarios.sh` detecta los estados por columnas de `user`; hay que reescribirlo para los nuevos estados (vista, `INSERT` en la vista, `UPDATE` sin `email`), añadir la etapa «vista», y adaptar `probar-privilegios-2b.sh`, `lib-empaquetado.mjs` (2 plantillas más) y el ensayo Docker 2B.
- **Interfaz:** correo de solo lectura en el formulario de edición.
- **Respaldo:** incluir `SHOW CREATE VIEW` en el procedimiento.
- **Nuevo paquete y nuevo ensayo completo** (hoy el prototipo solo se probó en las suites HTTP, no en la réplica del servidor).
- **Inspección de solo lectura del backend de Flutter desplegado** (requiere autorización): listar rutas, buscar flujos de correo (`mail(`, PHPMailer, SMTP, `reset`, `recuper`, `forgot`), confirmar cómo crea usuarios y administradores y qué cuenta MySQL usa y con qué privilegios (`SHOW GRANTS`). Sin credenciales ni `.env`.
Estimación: media jornada de trabajo más una corrida de ensayo.

## 9. Regresión de la rama del prototipo (`spike/ux-08i-alta-por-vista`, commit `368e111`)
Regresión completa de la CI (todas las suites HTTP, 22 bloques «passed»): **1732 PASS / 0 FAIL**; unitaria PHP y estáticas verdes; brechas residuales de escalamiento: **ninguna**.
**Hallazgo de la propia regresión (corregido):** la primera versión del prototipo prohibía editar el correo en `UserController` para **todos** los modos; la suite de los modos heredados (que exige 409 por correo duplicado) falló y abortó el resto. La restricción se limita ahora a `admin_escritura`; los demás modos conservan su contrato. Lección para el diseño: la restricción del correo es una propiedad de la **cuenta de React**, no de la tabla.
No se repitió el ensayo Docker del servidor con el prototipo (el kit no está adaptado, §8).
