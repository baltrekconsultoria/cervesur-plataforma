# UX-08I · Fase 2B (Usuarios) — release candidate, riesgos y plan de liberación

Rama `feat/cervesur-ux-08i-fase-2b-usuarios` sobre `f539c9f` (RC2 de la Fase 2A, la publicada). **Nada de esto está desplegado.** Sin SSH, Apache, MySQL real, `.env` real, push ni merge.
> **VIGENTE: RC1c `2f88cfb`** (alta por vista protegida; ver `docs/UX-08I-FASE-2B-VISTA-CANDIDATO.md`). Las menciones de este documento a permisos `INSERT` por columnas sobre `user` y a `UPDATE(email)` describen el RC anterior y **ya no aplican**.

Auditoría y GO/NO-GO del código previo: `docs/UX-08I-FASE-2B-AUDITORIA-GO-NO-GO.md`.

## 1. Qué cambia (y qué no)
| | Fase 2A (publicada) | Fase 2B (esta rama) |
|---|---|---|
| Usuarios (escritura) | bloqueado por **nombre de base**, sin forma de abrirlo | bloqueado **por defecto**; lo abre solo el servidor con `ADMIN_WRITES_USUARIOS=1` exacto |
| Rol y contraseña | inalcanzables | **siguen APAGADOS**: interruptor propio, plantilla SQL propia, el kit se niega a encenderlos (P5) |
| Cuenta MySQL | `UPDATE(last_login)` sobre `user` | idéntica hasta que el DBA aplique las plantillas aditivas UX-08I |
| Empresas, informes, accesos | operando (B1/B2/B3) | **sin cambios** (regresión probada) |
| Backend | `admin_escritura.php` | +71/−9 líneas en `admin_escritura.php` y +7/−3 en `UserModel.php` |

Los routers, modelos y la auditoría de Usuarios ya existían (UX-08A/B); este RC añade el **desbloqueo seguro por servidor**, el cruce privilegios↔interruptores, las plantillas y el kit de despliegue.

## 2. Cuatro capas independientes de bloqueo (Usuarios apagado = Fase 2A)
1. **Puerta de rutas**: `POST /api/register`, `PUT /api/users/{id}`, `deactivate`, `reactivate` → `403 fase_2b_bloqueada`; rol y contraseña → `403 escritura_desactivada` mientras `ADMIN_WRITES_USUARIOS_SENSIBLE` no sea `1`.
2. **Guardia de SQL**: `INSERT`/`UPDATE` de `user` rechazados sin el interruptor; cualquier `SET role=` o `SET password=` rechazado sin el interruptor sensible (probado con espacios, comillas y sentencias combinadas).
3. **Privilegios de la cuenta MySQL**: sin las plantillas UX-08I la cuenta no puede escribir `user` aunque el código lo intentara.
4. **Aserción de arranque** (solo base productiva): el backend cruza `SHOW GRANTS` con los interruptores y **se niega a servir (503)** si hay escritura sobre `user` con el interruptor apagado, `role/password` sin el sensible, `INSERT/UPDATE` de tabla completa o columnas no previstas.

El interruptor se lee del entorno **en cada petición**: apagarlo vuelve a bloquear sin redeploy.

## 3. Diferencia de permisos SQL (cuenta `cervesur_escritura_adm`)
Sobre `` `cervesur_portalbi`.`user` ``. Todo lo demás de la cuenta queda **idéntico** a la plantilla de la Fase 2A.

| Etapa | Plantilla | `INSERT` | `UPDATE` |
|---|---|---|---|
| 2A (hoy) | `UX-08H1-cuenta-escritura-fase2a` | — | `last_login` |
| 2B no sensibles (P4) | `UX-08I-usuarios-no-sensibles.up` | `username, email, first_name, last_name, phone, password, role, is_active, session_version, created_at, updated_at` | `last_login` + `first_name, last_name, phone, email, is_active, session_version, updated_at` |
| 2B sensibles (P5, aparte) | `UX-08I-usuarios-sensibles.up` | — | + `role, password` |

- `INSERT` incluye `password` y `role` porque el alta los fija: el rol lo impone el código (`new_user_role()`, siempre `user`; el cuerpo con `role`/`is_active`/`session_version`/`id` da 400) y la contraseña solo se guarda como hash.
- **No se concede**: `SELECT` nuevo (la cuenta sigue sin leer `user.password`), `DELETE`, `UPDATE(username)`, DDL, `TRIGGER`, `GRANT OPTION`, nada global, nada sobre `audit_log` más allá de `SELECT`+`INSERT`.
- Reversión: `.down` de cada plantilla (`REVOKE` por columnas); no borra cuentas ni datos. **`GRANT`/`REVOKE` son DDL no transaccionales**: no hay `ROLLBACK`; un fallo a medias deja estado parcial, que el script detecta, intenta deshacer e informa (probado con un fallo inyectado).

## 4. Orden exacto de despliegue (cada paso exige autorización propia; ninguno se ejecutó)
| Paso | Qué | Quién | Se detiene si |
|---|---|---|---|
| **P0** | Esta auditoría y la matriz de riesgo | hecho | — |
| **P1** | RC 2B construido; pruebas y regresión aprobadas; Usuarios OFF | hecho (ver §6) | — |
| **P2** | Autorización de **DBA y negocio** + respaldo verificable (nuevo, <24 h) | DBA/negocio | no hay respaldo, o nadie lo restauró en ensayo (hoy **no existe prueba de restauración**) |
| **P3** | `instalar-codigo-2b.sh --simular` → `--aplicar` (código con Usuarios OFF; respaldo `public.tgz`+`backend.tgz` con SHA-256; el `.env` no se toca); luego verificación `verificar-posdespliegue.sh --etapa accesos` | operador | `.env` con cualquier clave `ADMIN_WRITES_USUARIOS*`, identidad ≠ RC2, fallo de verificación (restaura solo) |
| **P3b** | Confirmar a mano que Usuarios sigue en `403 fase_2b_bloqueada` y 2A sin regresiones (empresa/informe/acceso: lecturas y la verificación) | operador | cualquier diferencia → `--revertir` |
| **P4a** | `privilegios-usuarios.sh --etapa no-sensibles --simular` → `--aplicar --respaldo … --confirmo-autorizacion "…"` → `--verificar` | **DBA** | cuenta con privilegios de más, `audit_log` sin sus 2 triggers, respaldo viejo |
| **P4b** | `interruptor-usuarios.sh encender --autorizacion "…"` (con `CERVESUR_CONFIRMAR_USUARIOS=SI`); verifica capacidades y se apaga solo si no son las esperadas | operador | privilegios no verificados, módulos 2A alterados |
| **P4c** | **UNA** operación real autorizada (alta o edición de datos) y comprobar **exactamente una** fila nueva `user.create`/`user.update` en `audit_log`; apagar con `interruptor-usuarios.sh apagar` si algo falla | negocio | más de una fila, fila ausente, datos inesperados |
| **P5** | Rol y contraseña: **no disponible en este kit**. Requiere aprobación, pruebas específicas contra el Flutter real y la etapa `sensibles` | futuro | siempre OFF |

Reversión por clase (independientes): **(1)** apagar — `interruptor-usuarios.sh apagar` (inmediato, sin redeploy); **(2)** privilegios — `privilegios-usuarios.sh --revertir` (sensibles antes que no sensibles); **(3)** código — `instalar-codigo-2b.sh --revertir <respaldo>` (exige Usuarios apagado; restaura la Fase 2A y comprueba que el `.env` no cambió).

## 5. Inventario de riesgos
| # | Riesgo | Estado |
|---|---|---|
| R1–R4 | Flutter acepta tokens antiguos sin `session_version`, toma el rol del JWT y no revoca al cambiar contraseña/reactivar | **Vigente.** Mitigación: reactivar exige 24 h (`ADMIN_USER_REACTIVATE_HOURS`); rol y contraseña OFF. El arnés de Flutter sigue siendo una **reconstrucción**: falta repetirlo con el backend real |
| R5 | `session_version` compartido: salir de Flutter también cierra la sesión React del mismo administrador | Documentado |
| R6 | Flutter escribe las mismas tablas sin transacciones y con cuenta amplia | Carrera no controlable desde aquí; se mitiga con `FOR UPDATE` y `row_version` (409), probado con una escritura concurrente simulada |
| R7 | Alta de usuario requiere `INSERT(password, role)` | Aceptado: rol fijo en código, hash únicamente, no hay `SELECT` de `password` |
| R8 | Un administrador puede fijar la contraseña de otro administrador (P5) | Inherente; por eso P5 sigue OFF. El cambio propio está prohibido en el panel |
| R9 | No hay prueba de restauración del respaldo | **Abierto.** Bloquea P2 hasta que el DBA lo ensaye |
| R10 | Excepción `debian-sys-maint` del control E0 | **Abierto**: documentar; esta cuenta no se usa para la aplicación |
| R11 | Acceso nuevo ID 114 (2A) pendiente de aceptación funcional por Cervesur | **Abierto**, ajeno a 2B |
| R12 | `instalar-codigo-2b.sh` e `interruptor-usuarios.sh` **no se ensayaron** en la réplica Docker del servidor (solo shellcheck, pruebas estructurales y revisión); `privilegios-usuarios.sh` sí contra MySQL real | **Abierto**: ensayo en la réplica obligatorio antes de P3 |
| R13 | Los scripts de la Fase 2A tratan como prohibida la sola presencia de `ADMIN_WRITES_USUARIOS*` y `interruptores.sh` las ELIMINA al aplicar | Esperado y seguro (apaga Usuarios). Por eso «apagado» = claves **ausentes**, no `=0`. Tras P4 no use `interruptores.sh`/`verificar-posdespliegue.sh` con Usuarios encendido: verifique con `interruptor-usuarios.sh estado` y las capacidades |

## 6. Pruebas ejecutadas (locales, datos 100 % sintéticos)
| Prueba | Resultado |
|---|---|
| Unitaria `tests/admin_write_unit.php` (política, guardia SQL, grants↔interruptores) | **93 PASS** (eran 71) |
| Estáticas `tests/test_security_static.py` | **105 OK** (eran 101; +4 de la 2B) |
| HTTP real contra MySQL 8: B1/B2/B3 + Flutter + 2A + **2B no sensibles + 2B sensibles + mal configuradas** | **191 PASS / 0 FAIL** |
| Privilegios 2B contra MySQL real (`probar-privilegios-2b.sh`: simular, aplicar, verificar, revertir, fallo inyectado, orden, plantilla alterada) | **20 / 20** |
| Empaquetado (`probar-empaquetado.mjs`, incluye plantillas 2B) | **45 PASS** |
| Frontend: Vitest / `tsc` / ESLint | **298 tests** (29 archivos) / limpio / limpio |
| `shellcheck -S warning` sobre todos los scripts del kit | rc = 0 |

Qué prueban las suites 2B: privilegios reales del motor (la cuenta no sensible **no** puede `UPDATE role/password`; ninguna cuenta lee `password`), alta con una sola auditoría y sin contraseña/hash/datos personales, campos prohibidos en el cuerpo, edición que ignora `role/username/is_active/password`, 409 por versión vencida (Flutter escribe a la vez) y por email duplicado, desactivar/reactivar (espera de 24 h, `session_version +1`, no a uno mismo), rol y contraseña **con sesión del usuario afectado invalidada (401)**, no cambiar el propio rol ni la propia contraseña, auditoría fallida ⇒ nada confirmado (alta y edición), y arranque rechazado (503) con privilegios incoherentes con los interruptores.

## 7. Condiciones GO / NO-GO
- **GO a P3** solo con: P2 cumplido, R9 y R12 cerrados, `instalar-codigo-2b.sh --simular` en 0 sobre el servidor, y Usuarios OFF en el `.env`.
- **NO-GO** ante cualquier: `.env` con claves `ADMIN_WRITES_USUARIOS*` antes de P4b, cuenta con privilegios de más, `audit_log` sin sus 2 triggers, huella de Flutter/443/8094 cambiada.
- Las **tres capas** del bloqueo original (ruta, guardia SQL, privilegios) siguen vigentes hasta P4; la cuarta (aserción de arranque) es nueva.
