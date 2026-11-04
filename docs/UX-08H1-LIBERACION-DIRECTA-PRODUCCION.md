# UX-08H.1 · Liberación directa a producción de la Fase 2A (sin QA aislada)

> **Estado: PREPARADO. NADA SE HA EJECUTADO EN EL SERVIDOR.** Esta rama no hizo SSH, push, merge, despliegue, migración, `GRANT`/`CREATE USER`, cambio de `.env`, cambio de Apache ni habilitó escrituras. El servidor real **no ha sido validado**: eso empieza con la etapa **E0** (solo lectura). El QA productivo **no se ha realizado**.

## 0. Decisión que sustituye al plan anterior
Se **descarta** instalar el entorno QA aislado de la Fase 2 (kit `deploy/qa-escritura/`, puerto 8095) y el paquete `cervesur-react-escritura-qa-d3af94f65331-…tar.gz`: **no se instalan**. El kit queda en el repositorio solo como referencia técnica (sus ideas se reutilizaron). La seguridad ya no descansa en un QA aparte, sino en: pruebas automatizadas, E0, preflight, respaldos verificados, cuenta mínima verificada, auditoría, apertura **gradual** y QA acotado en 8443.

**Fase 2B (Usuarios) permanece totalmente bloqueada.** La espera de 24 h **no** es un argumento para liberarla: reduce un riesgo (reactivación) pero no cierra los de rol y contraseña.

## 1. Alcance
| | Estado |
|---|---|
| Empresas: crear, editar, activar/desactivar | **Fase 2A — se prepara para habilitar** |
| Reportes: crear, editar, activar/desactivar (el enlace de Power BI es de solo escritura) | **Fase 2A** |
| Accesos: asignar, revocar, reactivar | **Fase 2A** |
| Historial de auditoría de lo anterior | **Fase 2A** |
| Usuarios: alta, edición, activar/desactivar, rol, contraseña | **BLOQUEADO** (ver §2) |

## 2. Cómo queda bloqueada la Fase 2B (código, no configuración)
Sobre la base productiva (`cervesur_portalbi`, o lo que liste `CERVESUR_PRODUCTION_DBS`) toda escritura de `user` se niega en **tres capas independientes**, **aunque el `.env` tenga `ADMIN_WRITES_USUARIOS=1`**:
1. **Puerta de rutas:** las 6 rutas de escritura de usuarios responden `403 fase_2b_bloqueada`; `/api/admin/capacidades` informa `usuarios:false`.
2. **Guardia de SQL:** ni `INSERT INTO user` ni `UPDATE user` pasan por la conexión de escritura.
3. **Privilegios de la cuenta:** la cuenta de la Fase 2A **no** tiene `INSERT` ni `UPDATE` sobre `user`, salvo `UPDATE(last_login)` (columna informativa; MySQL exige algún `UPDATE` para el `SELECT … FOR UPDATE` con que los modelos de accesos bloquean la fila del usuario — lo descubrió la prueba con la cuenta real).

Probado en `tests/admin_write_unit.php` (guardia y puerta), `tests/isolated/http_admin_escritura_fase2a_test.php` (las tres capas contra el backend real con base marcada como productiva) y `tests/test_security_static.py`. Los scripts de despliegue **además** se niegan a escribir esas claves en el `.env`. Desbloquear Usuarios exige una **versión nueva del código**, no un cambio de configuración.

## 3. Pruebas ejecutadas hasta ahora (Docker, datos sintéticos)
Ver `docs/UX-08H-INFORME-QA.md`. Añadido en UX-08H.1: unitarias del bloqueo (guardia y puerta con `cervesur_portalbi`), suite HTTP de la Fase 2A con base productiva simulada y cuenta de la Fase 2A (**todas pasan**), estáticas, Vitest 292, y la CI completa se repite antes de cerrar (ver §14). **No** significan que el servidor real esté validado.

## 4. Etapas (cada una exige autorización expresa y por separado)
| Etapa | Qué | Toca producción | Reversible |
|---|---|---|---|
| **E0** | Inspección de **solo lectura** por PuTTY (`e0/e0-auditoria-fase2a.sh`) | no (lee) | n/a |
| **E1** | Revisión de las evidencias de E0 → decisión GO/NO-GO de esquema y cuenta | no | n/a |
| **E2** | Preflight + **respaldo** verificado (`crear-respaldo.sh`, `preflight-fase2a.sh`) | solo crea archivos de respaldo | sí |
| **E3** | **Migraciones** M1, M2 y cuenta mínima (`aplicar-migraciones.sh`, DBA) | **sí (BD)**, aditivas | ver §10 |
| **A1** | Publicar el código nuevo **con el `.env` sin cambios** (sigue `piloto_admin`) | sí (código) | sí, automático |
| **A2** | Cambiar el `.env` a `admin_escritura` con **todo apagado** (`modo-escritura.sh`) | sí (`.env`) | sí, automático |
| **B0** | **Apache: permitir PUT y DELETE** (único cambio de Apache; `scripts/apache-metodos-escritura.sh`, ver §4.1) | **sí (Apache)** | sí, automático (`--revertir`) |
| **B1** | Habilitar **Empresas** + una operación autorizada + comprobar auditoría | sí (datos reales) | apagar interruptor |
| **B2** | Habilitar **Reportes** (tras verificar B1) | idem | idem |
| **B3** | Habilitar **Accesos** (tras verificar B2) | idem | idem |

Los scripts imponen el orden: no se habilita el siguiente módulo hasta marcar el anterior como verificado con evidencia.

### 4.1 Hallazgo: Apache bloquea PUT y DELETE hoy
La configuración común del administrador (`/etc/apache2/cervesur-react-admin/react-usuarios-common.conf`, incluida por 8093 y 8443) tiene `<LimitExcept GET POST> Require all denied`. Con eso, **editar** (PUT) y **dar de baja/revocar** (DELETE) reciben 403 de Apache antes de llegar a PHP; A1 y A2 **no** lo necesitan, Paso B sí. El cambio mínimo es una línea (`GET POST` → `GET POST PUT DELETE`; PATCH, OPTIONS, TRACE y la restricción de redes del 8443 siguen igual). Va en un script **aparte y opcional** (`apache-metodos-escritura.sh`, con `--simular`, respaldo SHA-256, `configtest`, recarga suave, verificación y `--revertir`) que **ningún otro script llama** y que `interruptores.sh --habilitar` exige ya aplicado. **Es un cambio de Apache: requiere autorización expresa y separada** (la restricción actual "no modificar Apache" rige hasta que la den). E0 lo confirma leyendo el archivo real.

### Por qué A1 antes que A2 (y no “publicar ya en modo nuevo, apagado”)
El modo `admin_escritura` necesita su cuenta MySQL de escritura **para arrancar** (verifica `SHOW GRANTS`). Arrancarlo con otra cuenta sería arrancar con más privilegios de los necesarios. Por eso: **A1** publica el código con el modo viejo (cero cambio de comportamiento: el bundle nuevo, al no encontrar `/api/admin/capacidades` en `piloto_admin`, queda en solo consulta y su aviso lo dice), y **A2** cambia el modo solo cuando existen las migraciones y la cuenta mínima ya verificada. `piloto_admin` **no** se convierte implícitamente en modo escritura en ningún momento.

## 5. E0 — inspección de solo lectura (PuTTY)
Kit `e0/` del paquete. Comandos: `sudo bash e0/e0-auditoria-fase2a.sh --ayuda`. Cubre los 12 puntos pedidos (estado del 8443, versión instalada, esquema/columnas, empresas/reportes/asignaciones y duplicados sin imprimir enlaces, FK e índices, `audit_log` y triggers, privilegios, MySQL/binlog/triggers, integridad y permisos de carpetas, espacio para respaldos, compatibilidad de los endpoints nuevos, impacto de las escrituras de Flutter). Garantías: solo `SELECT/SHOW/EXPLAIN` (autoprueba estática), nunca lee valores del `.env`, filtra contraseñas, tokens y enlaces de Power BI, no modifica nada. Entrega `99-resumen-E0.txt`.
**E0 es lo que decide** si la plantilla de cuenta coincide con las columnas reales (un `GRANT` sobre una columna inexistente falla) y si el entorno admite los triggers.

## 6. Respaldo (E2)
`crear-respaldo.sh`: volcado de las 6 tablas de negocio (`--single-transaction`), SHA-256 y manifiesto (filas por tabla + `CHECKSUM TABLE`), modo 600, **nunca borra** respaldos previos. `aplicar-migraciones.sh --aplicar` **aborta** si las tablas cambiaron desde el respaldo (se toma otro). El actualizador del código respalda además `public/` y `backend/` con SHA-256 (como en la Fase 1: `/var/backups/cervesur-react-admin/actualizacion-<sello>`). El respaldo de la última liberación (`actualizacion-20261103-215555Z`) **no se toca**.

## 7. Migraciones (E3) — revisión
| # | Qué | Archivo | Notas |
|---|---|---|---|
| M1 | Crea `audit_log` (aditiva, `CREATE TABLE IF NOT EXISTS`) | `database/migrations/UX-08B-audit-log.up.sql` | no toca tablas existentes |
| M2 | 2 triggers de solo inserción | `…audit-log-immutable.up.sql` | con `log_bin=1` y `log_bin_trust_function_creators=0` requiere `SUPER` (error 1419 si no); no se cambian variables globales |
| M3 | Cuenta de escritura mínima | `database/privileges/UX-08H1-cuenta-escritura-fase2a.sql.template` | contraseña generada en el servidor y guardada solo en un archivo 600 |
| — | `UX-08B3` (grupo_id NULL) | **no se aplica** | `grupo_id` sigue `NOT NULL` |
Todas con `--simular` por defecto; `--aplicar` exige respaldo verificado y una frase de autorización.

## 8. Matriz de privilegios de la cuenta de la Fase 2A
Cuenta propuesta: `cervesur_escritura_adm`@`127.0.0.1` (nombre y host configurables), **distinta** de `cervesur_portalbi_admin` (Flutter) y de las `cervesur_ro_*`.
| Tabla | SELECT | INSERT | UPDATE |
|---|---|---|---|
| `user` | `id, username, email, first_name, last_name, phone, avatar, role, is_active, session_version, last_login, created_at, updated_at` (**sin `password`**) | **ninguno** | **solo `last_login`** |
| `empresa` | todas **menos `powerbi_*`** | `nombre, razon_social, ruc, email, telefono, website, direccion, ciudad, pais, logo, contacto_principal, created_by` | las mismas + `activo, updated_by` |
| `reporte` | todas (el visor necesita `enlace`) | `nombre, descripcion, enlace, grupo_id, reporte_id, created_by` | `nombre, descripcion, enlace, grupo_id, reporte_id, activo, updated_by` |
| `empresa_usuario` | todas | tabla | `empresa_id, user_id, notas, activo, updated_by` |
| `empresa_reporte` | todas | tabla | `empresa_id, reporte_id, notas, activo, updated_by, updated_at` |
| `user_empresa_reporte` | todas | tabla | `user_id, empresa_reporte_id, notas, activo, updated_by` |
| `audit_log` | sí | sí | **no** (además triggers) |
**Nunca:** `DELETE`, `ALL PRIVILEGES`, `GRANT OPTION`, `TRIGGER`, DDL, `FILE`, `SUPER`, privilegios globales ni sobre otra base. El backend repite `SHOW GRANTS` al arrancar y **no arranca** (503) con cualquier privilegio de más.
Puntos a confirmar en E0: que existan todas esas columnas en producción (p. ej. `empresa.created_by/updated_by`, `user.avatar`, `*.updated_at`).

## 9. Procedimientos y paquete
El paquete productivo (`deploy/produccion-escritura/`) contiene: frontend compilado en modo `admin-write` (host 192.168.200.12), backend, `e0/`, `migraciones/` (solo M1, M2 y la plantilla 2A), scripts (`preflight-fase2a.sh`, `actualizar.sh`, `modo-escritura.sh`, `interruptores.sh`, `apagar-escrituras.sh`, `revertir-codigo.sh`, `verificar-posdespliegue.sh`, `comprobar-otros-portales.sh`), verificación de OPcache e integridad SHA-256, README del operador, `RELEASE.json` con commit y la lista de diferencias frente a `3f859210c5c4`. **Sin** `.env`, secretos, datos de QA ni `syn###`. **No** modifica el paquete instalado.
**Identificación del paquete:** {{PAQUETE}} · SHA-256 {{SHA}} · commit {{COMMIT}}.
Diferencias frente a `3f859210c5c4`: ver `DIFERENCIAS-vs-3f859210c5c4.txt` (junto al paquete). {{RESUMEN_DIFERENCIAS}}

## 10. Rollback — tres clases distintas
| Clase | Qué | Cómo | Datos |
|---|---|---|---|
| **1. Apagar escrituras** (segundos) | `ADMIN_WRITES_*` a 0 | `apagar-escrituras.sh` o `interruptores.sh --apagar-todo` | no toca datos ni auditoría; conserva evidencia |
| **2. Restaurar código** | vuelve el admin de la Fase 1 | `revertir-codigo.sh` (restaura `public/`+`backend/` **y** el `.env` correspondiente; el código viejo no conoce el modo nuevo) | no toca BD, Flutter ni trabajadores |
| **3. Datos y migraciones** | procedimiento **aparte y manual**, nunca automático | respaldo del §6 + `audit_log` (qué cambió, quién, antes/después sin secretos); `.down.sql` de M1/M2 solo con autorización (`audit_log` es evidencia: no se borra sin una segunda confirmación explícita) | sí |
**No se promete rollback automático de datos** una vez habilitadas las escrituras: las bajas son lógicas (se revierten reactivando), pero un cambio de datos se corrige con una operación inversa auditada o, en último caso, con el respaldo. **No se usa** el `revertir.sh` del piloto (es un desinstalador).

## 11. Checklist de QA real en 8443 (después de cada etapa; sin pruebas destructivas)
Marcar por etapa con evidencia (`verificar-posdespliegue.sh --etapa …`):
- [ ] Login · [ ] Lectura de Empresas, Reportes, Accesos · [ ] Historial (solo con escrituras habilitadas)
- [ ] `/api/admin/capacidades` coincide con lo esperado por etapa (A2: todo `false`; B1: solo empresas; B2: +reportes; B3: +accesos; **usuarios siempre `false`**)
- [ ] Escrituras de módulos apagados → **403** `escritura_desactivada`; rutas de usuarios → **403** `fase_2b_bloqueada`
- [ ] Una **única operación real previamente autorizada** por módulo (no se inventan registros ni se modifica información empresarial sin autorización específica) → éxito, **una** fila en `audit_log` con la acción y la entidad esperadas, y la tabla tocada cambió exactamente lo previsto
- [ ] **409** (conflicto/versión vencida) — se comprueba con el comportamiento ya probado en automatización; en producción solo si hay un caso natural, sin forzar datos
- [ ] Una operación rechazada (permiso/conflicto) **no** modifica datos ni deja auditoría de éxito
- [ ] Consistencia de relaciones (asignaciones activas apuntan a empresa e informe activos)
- [ ] El visor Power BI entrega enlaces **solo** a los informes autorizados; DB031 (id 72) **sin** autorización nueva; ninguna respuesta fuera del visor contiene enlaces
- [ ] Continuidad: Flutter (80), trabajadores (443), QA 8094 (`comprobar-otros-portales.sh` antes/después)

## 12. Matriz GO / NO-GO
| Criterio | E3 migraciones | A1 código | A2 modo nuevo | B habilitar |
|---|---|---|---|---|
| E0 ejecutado y revisado: esquema compatible, columnas de la matriz presentes | requerido | requerido | requerido | requerido |
| Respaldo verificado (SHA-256 + manifiesto) y sin cambios desde entonces | **requerido** | requerido | requerido | requerido |
| Permiso `SUPER`/alternativa para triggers confirmada | **requerido** | — | — | — |
| `preflight-fase2a.sh` en GO y `actualizar.sh --simular` con código 0 | — | **requerido** | requerido | — |
| Migraciones aplicadas y verificadas (`SHOW GRANTS`, 2 triggers, prueba transaccional) | — | — | **requerido** | requerido |
| Cuenta mínima: sin DELETE/DDL/ALL/GRANT OPTION; sin escritura en `user` salvo `last_login` | requerido | — | requerido | requerido |
| Etapa anterior verificada con evidencia | — | requerido | requerido | **requerido** (una por módulo) |
| Operación real autorizada por escrito, sin datos ficticios | — | — | — | **requerido** |
| Apache permite PUT/DELETE (B0 autorizado, aplicado y verificado) | — | — | — | **requerido** |
| Usuarios (2B) bloqueados y comprobado en el servidor | — | — | requerido | requerido |
| Autorización expresa y separada de cada etapa | requerido | requerido | requerido | requerido |
**Resultado hoy: NO-GO para ejecutar cualquier etapa de producción** hasta completar E0/E1. Lo único que está “listo” es el paquete y su documentación.

## 13. Decisiones que requieren autorización antes de tocar producción
1. Ejecutar **E0** por PuTTY (solo lectura) y quién lo corre.
2. Aprobar las **evidencias de E0** y la plantilla de cuenta contra el esquema real.
3. Nombre, host y política de la **cuenta de escritura**; quién tiene el acceso `SUPER` para triggers; dónde se guarda su contraseña.
4. Ventana y responsable del **respaldo** y de las **migraciones M1/M2** (en la base real).
5. Ventana para **A1** (código) y para **A2** (`.env`); confirmar que el `.env` actual se respalda.
6. Qué **operación real** autorizada se usará para verificar Empresas, Reportes y Accesos (registro concreto, quién la aprueba, cómo se deshace) — **una por módulo**.
7. Criterio para apagar de inmediato (quién decide y cómo se avisa).
8. **Permitir PUT y DELETE en Apache** (B0, §4.1): autorización separada, ventana y quién lo ejecuta.
9. Reiterar que **Usuarios (2B)** sigue bloqueado y que cualquier desbloqueo es otra versión y otra autorización.

## 14. Evidencia de esta preparación
{{EVIDENCIA}}

## 15. Riesgos pendientes
- El servidor real no está validado (E0 pendiente): columnas, privilegios, binlog/`SUPER`, versión y permisos pueden diferir del Docker.
- Flutter escribe las mismas tablas **sin transacciones** y con una cuenta amplia: escrituras simultáneas de ambos lados no se coordinan (E0 punto 12 lo cuantifica).
- `session_version` compartido con Flutter: cerrar sesión en Flutter cierra la sesión React del mismo administrador.
- La verificación en producción usa datos reales: se limita a una operación autorizada por módulo.
- Las pruebas usan MySQL 8.0 de Docker; la corrida contra el MySQL real es parte de E3.
