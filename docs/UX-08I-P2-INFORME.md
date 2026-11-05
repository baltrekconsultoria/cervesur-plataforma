# UX-08I · Informe P2 — preparación de autorizaciones, respaldo y restauración, y análisis del FAIL intermitente

**Alcance:** P2 *preparado*, no ejecutado. No se hizo SSH productivo ni se modificó MySQL, `.env`, Apache ni código productivo; no hay push ni merge. Todo lo medido se hizo en réplica Docker/MySQL 8 con datos sintéticos.

## 1. Candidato registrado
| | |
|---|---|
| Commit validado (vigente) | `2f88cfbc5098a1489242bf0c65f45d4041b5177f` (RC1c) — reemplaza a `ae28e6c`; ver `docs/UX-08I-FASE-2B-VISTA-CANDIDATO.md` |
| Paquete vigente | `cervesur-react-admin-fase2b-2f88cfbc5098-20261105-162903Z.tar.gz` (`Cervesur\paquetes-qa\produccion-fase2b-rc1c\`) |
| SHA-256 | `52418fee44e8dc6578a2ed76142a6ae8007787a7da3a663d22724542f7a703e6` |
| Ensayo del candidato | Réplica Docker: 235 PASS / 0 FAIL · regresión HTTP completa de la CI: 1752 PASS / 0 FAIL |
| Cambios posteriores | Tras `2f88cfb`, solo documentación (no cambia el paquete). Las secciones que mencionan `120ea84`/`ae28e6c` son historia: **solo RC1c es vigente**. |

## 2. Solicitud de autorización
`docs/UX-08I-P2-SOLICITUD-AUTORIZACION.md`: delimita P2, P3, P4a, P4b y P4c (qué se ejecuta, si escribe en MySQL, reversión, quién autoriza), seis decisiones independientes (A–F) para firmar, los riesgos que deben aceptarse expresamente y lo que **no** se autoriza.

## 3. Procedimiento de respaldo productivo (ensayado 14/14 en réplica)
Herramienta: `migraciones/crear-respaldo.sh` (ya viene en el paquete) más el volcado de `audit_log`, que ese script **no** incluye. Ensayo: `deploy/produccion-escritura/probar-respaldo-p2.sh` (no va en el paquete).

1. **Condiciones:** ventana sin escrituras de Flutter; espacio libre ≥ 3 × tamaño de `cervesur_portalbi`; `sudo` con socket de MySQL. Si las tablas cambian durante el volcado, el script **descarta** el respaldo y hay que repetir.
2. **Seis tablas + restauración de prueba:** `sudo bash migraciones/crear-respaldo.sh --probar-restauracion`. Vuelca `user, empresa, reporte, empresa_usuario, empresa_reporte, user_empresa_reporte`; compara filas y `CHECKSUM TABLE` antes y después (tolerancia cero); verifica `gzip -t`, que el volcado termine en «Dump completed» y que cada tabla tenga su `CREATE` y el nº de `INSERT` esperado; **restaura en un esquema temporal `cervesur_verif_<sello>`**, compara `CHECKSUM TABLE` con las tablas vivas y lo **elimina**. Deja `.sql.gz`, `.manifest` (con `archivo_sha256`, estructura, filas, checksums y `restauracion_probada=1`) y `.sha256`, 600 en carpeta 700.
3. **`audit_log` (evidencia, se respalda aparte):** `mysqldump --single-transaction --no-tablespaces --set-gtid-purged=OFF --triggers --skip-extended-insert --complete-insert cervesur_portalbi audit_log | gzip -n > audit_log-….sql.gz`, con su `.sha256`. Restauración temporal: mismas filas, mismo `CHECKSUM TABLE` y **2 triggers** de solo inserción.
4. **Verificación SHA-256:** `sha256sum -c` de cada `.sha256` **y** comparación con `archivo_sha256` del manifiesto. Un byte añadido cambia el hash (se detecta); un archivo truncado falla `gzip -t`. *Ojo:* `gzip -t` tolera basura al final del archivo, por eso el SHA-256 del manifiesto es obligatorio y no opcional.
5. **Consistencia:** el ensayo comprueba que el respaldo no modifica ningún dato vivo (checksum de las siete tablas idéntico antes/después).
6. **Uso:** el archivo `.sql.gz` de las seis tablas es el que se pasa a `privilegios-usuarios.sh --respaldo` en P4a (el script comprueba `gzip -t` y que tenga < 24 h).
7. **Alternativa sin escritura en producción:** copiar los dos `.sql.gz` y sus `.sha256` a **otra instancia MySQL** y restaurar allí; el DBA decide (§7 de la solicitud).

**Lo que sigue sin existir:** una restauración probada **del respaldo productivo real**. El ensayo prueba el procedimiento, no los datos reales; eso solo lo produce P2 ejecutado. Hasta entonces la condición de P3 no se cumple.
Límite conocido del procedimiento: la restauración real reemplazaría las seis tablas y se perderían las escrituras posteriores al respaldo; no deshace `GRANT`/`REVOKE` ni `audit_log` (ver `migraciones/RECUPERACION.txt`). Para permisos se usa `--revertir`, no la restauración.

## 4. FAIL intermitente del instalador de la Fase 1
**Qué se vio:** en una corrida completa del ensayo, el paso «el instalador REAL de la fase 1 deja el administrador funcionando» dio `26 PASS / 1 FAIL` en la verificación interna del instalador (27 comprobaciones en total). La salida estaba recortada a los últimos 700 caracteres, así que **la comprobación que falló no quedó registrada**.
**Reproducibilidad:** se ejecutó solo ese tramo (contenedor nuevo cada vez, el instalador real de la Fase 1):
- 8 corridas normales: 27 PASS / 0 FAIL.
- 4 corridas con 6 procesos de CPU saturando el anfitrión: 27 PASS / 0 FAIL.
- La corrida completa posterior del ensayo con el paquete limpio del candidato: 220 PASS / 0 FAIL (una intermedia se abortó por una ruta mal escrita por mí, no por los scripts).
→ **No reproducible** en 12 corridas aisladas ni bajo carga: *causa raíz no determinada*.
**Hipótesis (no confirmada):** esa corrida arrancó segundos después de que detuve otra del mismo ensayo (`TaskStop`); ambas usan el mismo nombre de contenedor y el proceso detenido pudo seguir emitiendo comandos Docker. El registro no muestra líneas duplicadas, así que **no hay evidencia a favor ni en contra**. Es un artefacto de mi forma de lanzar el ensayo, no de los scripts.
**Impacto:** (a) es el tramo de *preparación* de la réplica; P3 no ejecuta el instalador de la Fase 1; (b) en producción la Fase 1 ya está instalada; (c) si una comprobación similar fallara durante P3, el instalador 2B **restaura solo** (probado con fallos inyectados), no deja un estado intermedio; (d) la corrida completa posterior dio 0 FAIL.
**Medidas tomadas:** el ensayo ahora imprime las líneas `FAIL/FALLO/ERROR` completas de ese paso y existe `ENSAYO_SOLO_FASE1=1` para repetirlo guardando la salida íntegra. **Regla para P3:** ante cualquier FAIL, conservar la salida completa antes de repetir.

## 5. Confirmación sobre el paquete
| Punto | Evidencia |
|---|---|
| Empresas, Reportes y Accesos se conservan | Ensayo: tras instalar, `empresas/reportes/accesos=true`, una escritura real de Empresas con su auditoría, verificador `--etapa accesos` PASS, y lo mismo tras cada fallo inyectado y tras revertir |
| Usuarios continúa apagado | Tras P3: `usuarios=false`, `users_sensitive=false`, las 6 rutas 403 `fase_2b_bloqueada`; el `.env` queda byte a byte igual |
| P5 no puede activarse | `interruptores.sh` rechaza `usuarios*`; `interruptor-usuarios.sh sensible` se rechaza siempre y el kit nunca escribe `ADMIN_WRITES_USUARIOS_SENSIBLE=1`; con solo `ADMIN_WRITES_USUARIOS=1`, rol y contraseña dan 403 y no cambian ni un hash |
Precisión honesta sobre P5: **el kit** no puede activarlo. `privilegios-usuarios.sh --etapa sensibles` sí existe (requiere no-sensibles ya aplicada y autorización) para que P5 pueda prepararse en el futuro; conceder esos permisos **no** abre nada porque ruta y guardia siguen cerradas. Activar P5 exigiría, además, editar el `.env` a mano. El backend lo permitiría (así se prueba en las suites), por lo que **depende del control del `.env` y de la firma del DBA**, no de un candado inviolable.

## 6. Pendientes registrados (no cierran con este trabajo)
1. **`debian-sys-maint`** (excepción del control E0): el DBA debe documentarla; no se usa para la aplicación.
2. **Acceso ID 114** (Fase 2A): pendiente de aceptación funcional por Cervesur.
3. **Sesiones de Flutter:** tokens antiguos sin `session_version`, rol tomado del token, sin revocación al cambiar contraseña; reactivar protegido con 24 h; el arnés de pruebas es una **reconstrucción** del backend de Flutter (falta repetirlo con el real).
4. **Restauración del respaldo productivo real** (se produce en P2).
5. Interfaz no renderizada en navegador durante el ensayo; certificados y 443 reales solo en réplica.

## 8. Condiciones adicionales (recibidas tras el primer informe P2) y su efecto
**Efecto sobre el candidato.** Dos condiciones exigieron cambios de código, así que el candidato cambió de `120ea84` a `ae28e6c`:
1. *Escalamiento:* se verificó (abajo) y se **fijó la forma del alta** en el guardia de SQL.
2. *Flutter:* el diálogo «Desactivar usuario» decía «En Flutter, la sesión abierta dejará de funcionar en su siguiente consulta», cosa **no validada** con el backend real. Ahora dice: «esto NO es una revocación inmediata de acceso en todos los sistemas; el tratamiento de sesiones y tokens de Flutter aún no está validado con su backend real», con prueba que lo exige.
Re-validación: ensayo Docker 220/0, regresión HTTP completa de la CI 1695/0, unitarias, estáticas y frontend verdes.

**Verificación de escalamiento (`tests/isolated/p4_escalamiento_test.php`, ahora también en la CI):**
| Nivel | Resultado |
|---|---|
| API | `role`, `is_active`, `session_version`, `id` en el cuerpo del alta → 400; variantes (`Role`, `ROLE`, `"role "`, `rol`, `roles`, valores no escalares) no afectan: el rol almacenado es siempre el que fija el servidor (`user`/`usuario`); rol y contraseña de usuarios existentes → 403; la edición ignora `role`, `password`, `username`, `is_active`, `session_version` |
| Guardia de SQL | `UPDATE role/password`, `INSERT … ON DUPLICATE KEY UPDATE`, `REPLACE`, `DELETE`, `INSERT … SELECT` rechazados. **Hallazgo corregido:** antes admitía un `INSERT` con `role='admin'` literal; ahora el rol viaja como literal fijo (`user`/`usuario`) y el guardia exige la forma exacta del alta (activo = 1, `session_version` = 1, sin `id`, sin `ON DUPLICATE`) |
| Motor MySQL (cuenta real) | `UPDATE(role)`, `UPDATE(password)`, `UPDATE(username)`, `INSERT` con `id`, `ON DUPLICATE KEY UPDATE password`, `DELETE`, `REPLACE` y `SELECT password` → denegados |
| **Brecha residual (no eliminable en MySQL)** | Con las credenciales de la cuenta, el motor **permite** `INSERT` de un usuario con `role='admin'`: los privilegios por columna no acotan el valor. Requiere el `.env` (`root:www-data 640`) o una inyección SQL; la API y el guardia no lo permiten |

Mitigaciones de la brecha: protección del `.env`; la consulta de control `SELECT id, username, role, created_at FROM user WHERE role NOT IN ('user','usuario') AND created_at >= <inicio de P4>` debe devolver 0 filas tras P4c y como control periódico; la auditoría registra cada alta hecha por la API. Un *trigger* `BEFORE INSERT` en `user` lo cerraría en la base, pero actuaría también sobre las altas de Flutter (tabla compartida): **no se propone sin decisión del DBA y pruebas con Flutter**.
Otra superficie a validar con el backend real de Flutter: la cuenta puede cambiar `email` (`UPDATE(email)`); si Flutter permite recuperar la contraseña por correo, un cambio de correo de un administrador sería un vector de toma de cuenta. **Pendiente de validar; no se asume inocuo.**

**Condiciones de ejecución.** La solicitud (§8) incorpora: autorización identificable, ventana, respaldo verificable, continuidad de Empresas/Reportes/Accesos, criterios de suspensión (lista explícita) y recuperación documentada. **Un respaldo de menos de 24 h no garantiza recuperar cambios posteriores:** el script solo exige antigüedad, por lo que la consistencia (manifiesto, `CHECKSUM TABLE`, restauración de prueba) y el control de escrituras concurrentes son requisitos adicionales del procedimiento (§3), no implícitos.
**Alcance de P2:** únicamente respaldo, verificación de integridad y restauración en esquema temporal; no aprueba P3, P4a ni P4b.

## 7. Veredicto específico para P3
**NO-GO por ahora (condicionado): el código está técnicamente listo, las condiciones no.**
| Condición de P3 | Estado |
|---|---|
| Candidato íntegro, ensayado, con rollback probado | ✔ |
| Procedimiento de respaldo y restauración definido y ensayado | ✔ (14/14) |
| FAIL intermitente investigado | ✔ analizado; **no reproducible, causa no determinada**, impacto acotado y sin bloqueo |
| Autorizaciones A y B firmadas | ✘ pendientes |
| Respaldo productivo creado y **restauración probada** | ✘ se produce al ejecutar P2 (autorización A) |
| Ventana sin escrituras de Flutter y espacio verificados en el servidor | ✘ pendiente |
**Candidato vigente: `2f88cfb` (RC1c).** El veredicto no cambia con la corrección: el código está listo, las condiciones no.
**Pasa a GO** cuando P2 se ejecute con evidencia (`restauracion_probada=1`, SHA-256 verificado, `audit_log` respaldado) y B esté firmada. Hasta entonces **no ejecutar P3**.
