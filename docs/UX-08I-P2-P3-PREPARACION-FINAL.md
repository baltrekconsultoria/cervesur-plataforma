# UX-08I · Preparación final de P2 y P3 (RC1c `2f88cfb`)

Candidato: `2f88cfbc5098a1489242bf0c65f45d4041b5177f` · paquete `cervesur-react-admin-fase2b-2f88cfbc5098-20261105-162903Z.tar.gz` · SHA-256 `52418fee44e8dc6578a2ed76142a6ae8007787a7da3a663d22724542f7a703e6`.
**Este documento solo prepara.** No se hizo SSH productivo, ni operación MySQL, ni publicación, push o merge. Todo lo marcado «NO EJECUTAR» espera autorización específica.

## 1. Veredicto
| Decisión | Veredicto | Motivo |
|---|---|---|
| **P2** (respaldo + restauración de prueba) | **GO condicionado** | Procedimiento y script probados en Docker (14/14 y ensayo); faltan firma del DBA (A), ventana, espacio libre verificado y decisión de dónde restaurar |
| **P3** (publicar código con Usuarios OFF) | **NO-GO hoy** · **GO** cuando se cumplan las condiciones C1–C6 de §7 | El código está listo (24 comprobaciones de P3 en el ensayo, E/R/A intactos, Usuarios 403). Faltan evidencias del servidor real: no hay respaldo con restauración probada, ni autorizaciones B, ni ventana, ni huella de línea base, y el backend Flutter no fue inspeccionado |
| P4a/P4b/P4c | Fuera de este paso (GO condicionado, ver `UX-08I-FASE-2B-VISTA-CANDIDATO.md`) | — |
| P5 | **NO-GO** (fuera de alcance) | — |

P3 no toca MySQL ni el `.env`, pero **sí** reemplaza el código en producción: por eso se exige P2 verificado antes.

## 2. Checklist P2 final (marcar con fecha y responsable; nada está marcado)
**Antes de la ventana**
- [ ] Autorización A firmada por el DBA (alcance: solo respaldo, verificación y restauración en esquema temporal; no autoriza P3/P4a/P4b).
- [ ] Paquete verificado: `sha256sum -c` contra `52418fee…a703e6` y `sha256sum -c SHA256SUMS` dentro del paquete.
- [ ] Persona que ejecuta (con `sudo`), persona que observa, lugar de la evidencia.
- [ ] Espacio libre ≥ 3 × el tamaño de `cervesur_portalbi` en `/var/backups/cervesur-react-admin` (`df -h`, `SELECT SUM(data_length+index_length) FROM information_schema.tables WHERE table_schema='cervesur_portalbi'`: solo lectura).
- [ ] Decisión del DBA: restaurar en **esquema temporal del mismo servidor** (`cervesur_verif_<sello>`, `CREATE/DROP`) o en **otra instancia** (no escribe en producción; preferible).
- [ ] Versión de MySQL del servidor anotada (`SELECT VERSION()`): **debe ser ≥ 8.0.18** para P4a (no bloquea P2/P3).
- [ ] Ventana acordada (§4) y aviso al dueño de Flutter.
- [ ] Línea base registrada: huella de Flutter (80), trabajadores (443), QA (8094), Apache, `.env` (SHA-256, sin imprimir valores), `SHOW GRANTS` de la cuenta de escritura, `capabilities` del 8443 (empresas/reportes/accesos = true; usuarios = false).
- [ ] Criterios de suspensión leídos por ambas partes (solicitud §8).

**Durante** (ver §3): ejecutar el respaldo, verificar y probar la restauración.
**Después**
- [ ] Manifiesto con `restauracion_probada=1`, SHA-256 del `.sql.gz` coincide con el `.sha256` guardado fuera de la carpeta.
- [ ] `CHECKSUM TABLE` vivo = restaurado en las 6 tablas; filas de `audit_log` y sus **2 triggers** presentes.
- [ ] Esquema temporal eliminado (`SHOW DATABASES LIKE 'cervesur_verif_%'` = 0).
- [ ] Copia del respaldo y del manifiesto fuera del servidor (acordar destino); permisos 600/700.
- [ ] Informe P2 con los números reales; E/R/A siguen respondiendo.

## 3. Procedimiento de respaldo productivo (NO EJECUTAR sin A)
Script del paquete: `migraciones/crear-respaldo.sh` (probado: `probar-respaldo-p2.sh` 14/14).
1. `sudo bash migraciones/crear-respaldo.sh --db cervesur_portalbi --destino /var/backups/cervesur-react-admin/respaldos-fase2b --probar-restauracion`
   - volcado con `--single-transaction` de `user, empresa, reporte, empresa_usuario, empresa_reporte, user_empresa_reporte`;
   - lee filas y `CHECKSUM TABLE` **antes y después** del volcado: si cambian, **descarta** el respaldo (señal de escritura concurrente);
   - manifiesto con SHA-256, huella de estructura, filas y checksums; restaura en `cervesur_verif_<sello>`, compara checksums y elimina el esquema.
2. **`audit_log`** (no entra en las seis tablas): `mysqldump --single-transaction --no-tablespaces --set-gtid-purged=OFF --triggers --skip-extended-insert --complete-insert cervesur_portalbi audit_log | gzip -n > audit_log-cervesur_portalbi.sql.gz`, `sha256sum` a `.sha256`, `gzip -t`, comprobar «-- Dump completed» y que contiene los 2 triggers; probar su carga en el esquema temporal (procedimiento validado en `probar-respaldo-p2.sh`).
3. **Cuenta y privilegios** (para reconstruir): guardar la salida de `SHOW GRANTS FOR 'cervesur_escritura_adm'@'127.0.0.1'` y `SHOW CREATE VIEW` si existiera alguna vista (hoy no debería existir `v_user_alta`: es un requisito de P4a).
4. Verificaciones: `gzip -t`, `sha256sum -c`, manifiesto `restauracion_probada=1`, tamaño razonable, antigüedad < 24 h al momento de P4a.
5. El script **no borra** respaldos anteriores y no imprime contraseñas.

Límite declarado: un respaldo restaurado en un esquema temporal prueba que el volcado es legible y coincide con la base **en ese instante**; **no** recupera cambios hechos después. La recuperación de P3 es del código (respaldo `public.tgz`/`backend.tgz` del instalador), no de datos, porque P3 no escribe datos.

## 4. Ventana de intervención y escrituras concurrentes
Flutter escribe las mismas tablas y esta solución **no puede detenerlo** (no se toca Flutter ni Apache del puerto 80). Control propuesto:
| Fase | Riesgo | Control |
|---|---|---|
| P2 | tablas cambian durante el volcado | (1) ventana acordada con el dueño de Flutter en horario de baja actividad, pidiendo no registrar ni editar usuarios/empresas/informes/accesos; (2) el script compara filas y `CHECKSUM TABLE` antes/después y **descarta y repite** si hay diferencias; (3) máximo 3 intentos; si fallan, se reprograma, **no** se baja la exigencia |
| P3 | cambio de código durante peticiones en curso | el reemplazo es atómico por carpeta con respaldo; el 8443 es un portal propio, sin tráfico de Flutter; ventana de minutos; se verifica `verificar-posdespliegue --etapa accesos` y se revierte solo si falla |
| P4a (futuro) | datos cambian entre respaldo y DDL | P4a no modifica datos (solo `GRANT`/`CREATE VIEW`); aun así exige respaldo < 24 h y se repite P2 si pasó más |
Propuesta de ventana: **60–90 minutos** para P2 y **30 minutos** para P3 (otra ventana o la misma, con P2 verificado antes de empezar P3). Fecha y hora: **por acordar** (casilla pendiente). Suspensión: ver solicitud §8.

## 5. Inspección de solo lectura del backend Flutter desplegado (NO EJECUTAR sin autorización)
Objetivo: cerrar las incógnitas de login, roles, sesiones, operaciones sobre `user` y privilegios. **Solo lectura**; requiere autorización específica del destino, la operación y la sesión a usar. No se copian secretos: se registran nombres y huellas.
1. **Código (`/var/www/cervesur/backend`, 33 archivos conocidos):** `ls -l`, `sha256sum` de cada archivo (para compararlo con la reconstrucción), y `grep -n` de: `password_verify|password_hash|jwt|JWT|session_version|is_active|role|forgot|reset|recover|mail|email|smtp|token` y de toda sentencia `INSERT INTO user|UPDATE user|DELETE FROM user`.
2. **Preguntas que debe contestar** (cada una con archivo:línea):
   - autenticación: consulta exacta de login (¿`email OR username`? ¿`ORDER BY`? ¿`is_active`?), algoritmo del hash, qué trae el JWT (rol, `session_version`, expiración) y si valida `is_active`/`session_version` por petición;
   - roles: quién puede crear usuarios, con qué rol por defecto, si algún endpoint acepta `role` del cuerpo;
   - sesiones: revocación al cambiar contraseña/desactivar, vida del token;
   - recuperación de contraseña / verificación de correo: ¿existe? (decide si `UPDATE(email)` desde React podría facilitar una toma de cuenta; hoy ya está retirado);
   - operaciones sobre `user`: lista completa y si usan transacciones.
3. **MySQL (solo `SELECT`/`SHOW`, como el DBA):** `SELECT user,host FROM mysql.user` (nombres, sin hashes), `SHOW GRANTS` de la cuenta que usa Flutter (`.env` de Flutter: **solo leer el nombre de usuario, nunca imprimir la clave**), `SHOW CREATE TABLE user`, `SELECT role, COUNT(*) FROM user GROUP BY role`, `SELECT VERSION()`.
4. **Salida:** informe con hallazgos; si algo contradice la reconstrucción (`tests/isolated/flutter_compat_test.php`), se actualiza el arnés y se repite el ensayo antes de P4.
Esta inspección **no es requisito de P3** (P3 no toca Usuarios ni Flutter) pero **sí de P4b**: Usuarios no se presenta como revocación inmediata hasta validarla.

## 6. P3 — procedimiento (NO EJECUTAR sin B)
Precondiciones: P2 verificado, autorización B (DBA + funcional), ventana, línea base de §2.
1. Copiar el paquete y verificar SHA-256 (§2). Descomprimir en una carpeta nueva (no ejecutar desde el directorio con código sin verificar).
2. `sudo bash scripts/instalar-codigo-2b.sh --simular` → debe dar 0: `.env` en `admin_escritura`, **sin** claves `ADMIN_WRITES_USUARIOS*`, huella de la Fase 2A (`base/MANIFIESTO-FASE2A-f539c9fd543d.sha256`) coincide.
3. `CERVESUR_E_USUARIO=… CERVESUR_E_CLAVE=… sudo -E bash scripts/instalar-codigo-2b.sh --aplicar` (credenciales solo por entorno; sin ellas la verificación es parcial y lo declara).
4. Éxito esperado: respaldo del código con SHA-256 (carpeta 700), reemplazo de `public/` y `backend/`, `verificar-posdespliegue --etapa accesos` PASS, huella de Flutter/443/QA idéntica, `.env` y MySQL intactos. Si falla → restauración automática (salida 20) o intervención manual con el respaldo (salida 21).
5. **Verificar tras P3** (evidencia en el informe): `capabilities`: empresas/reportes/accesos = true, **usuarios = false**, `users_sensitive = false`; las 6 rutas de Usuarios → 403 (`fase_2b_bloqueada` en los POST); una lectura de cada módulo 200; Apache sin recargas.
6. Recuperación: `sudo bash scripts/instalar-codigo-2b.sh --revertir <carpeta-de-respaldo>` devuelve a la Fase 2A RC2.
Evidencia en el ensayo Docker (24 comprobaciones P3, todas PASS): `--simular` sin escribir; rechazo con claves `ADMIN_WRITES_USUARIOS*`; paquete manipulado; estado parcial; fallos inyectados en respaldo/backend/verificación con vuelta a la Fase 2A exacta; aplicación real sin recargas de Apache; Flutter/443/QA byte a byte iguales; Usuarios 403; Empresas escribe con auditoría; reversión real; repetición idempotente.

## 7. Condiciones para pasar P3 a GO
| # | Condición | Estado |
|---|---|---|
| C1 | P2 ejecutado con `restauracion_probada=1`, SHA-256 y `audit_log` con 2 triggers, sin cambios concurrentes | ✘ pendiente |
| C2 | Autorización B firmada (DBA + funcional) | ✘ pendiente |
| C3 | Ventana fijada y aviso al dueño de Flutter | ✘ pendiente |
| C4 | Línea base del servidor registrada (huellas, `.env`, grants, capacidades) y coincide con la Fase 2A RC2 | ✘ pendiente |
| C5 | Casilla F: riesgo residual de Flutter aceptado (sesiones, escrituras simultáneas) | ✘ pendiente |
| C6 | Excepciones documentadas: `debian-sys-maint`, acceso ID 114, sesiones de Flutter | ✘ pendiente (conocidas, sin cerrar) |

## 8. Matriz de evidencias y autorizaciones
| Requisito | Evidencia disponible (local) | Falta (servidor/personas) | Autoriza |
|---|---|---|---|
| Candidato único RC1c | commit `2f88cfb`, paquete + SHA-256, documentos actualizados | Verificar SHA-256 en destino | — |
| Calidad del código | CI 1752 PASS/0 FAIL; Docker 235/0; privilegios 35/35; vitest 299; estáticas 106 | — | — |
| Respaldo y restauración | `probar-respaldo-p2.sh` 14/14; flujo en Docker | Ejecución real + manifiesto real | A (DBA) |
| Inspección de Flutter | procedimiento (§5) | Ejecución y hallazgos | Autorización específica (destino, operación, sesión) |
| Ventana y concurrencia | controles (§4) | Fecha/hora, aviso a Flutter | DBA + funcional |
| P3 conserva E/R/A, Usuarios bloqueado | 24 comprobaciones del ensayo | Repetir en servidor real tras P3 | B |
| Recuperación | reversión real probada en Docker | Respaldo real | B |
| P5 fuera de alcance | kit rechaza `sensible`; rutas 403 | — | No se solicita |
| Excepciones | registradas | aceptación explícita (F) | DBA + funcional |

Limitaciones que se mantienen: el backend de Flutter real no ha sido inspeccionado (todo sobre Flutter proviene del repositorio y de una reconstrucción); la restauración de un respaldo real nunca se probó; la versión de MySQL del servidor no está confirmada.
