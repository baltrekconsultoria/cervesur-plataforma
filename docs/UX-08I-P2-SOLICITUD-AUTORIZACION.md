# UX-08I · Fase 2B (Usuarios) — Solicitud de autorización (P2 y pasos siguientes)

**Para:** DBA de Cervesur y responsable funcional. **Estado:** solicitud, **nada autorizado ni ejecutado**. Cada casilla es una decisión independiente: autorizar una no autoriza las siguientes.

## 1. Qué se pide, en una frase
Publicar en el administrador React (8443) una versión que **incluye** la administración de Usuarios pero la deja **apagada**, y —solo si ambos lo autorizan por separado— habilitar después las operaciones **no sensibles** de Usuarios (alta, edición de datos personales, desactivar/reactivar). **Rol y contraseña de usuarios no se piden y no se pueden encender con este paquete.**

**Alcance exacto de la autorización de P2.** Comprende únicamente la preparación y ejecución controlada del respaldo productivo, su verificación de integridad y una prueba de restauración en un esquema temporal de MySQL. **No implica** la aprobación automática de la publicación del código (P3), de la ampliación de privilegios (P4a) ni de la habilitación de Usuarios (P4b).

## 2. Candidato
| | |
|---|---|
| Commit | `2f88cfbc5098a1489242bf0c65f45d4041b5177f` (rama `feat/cervesur-ux-08i-fase-2b-vista`) — **RC1c**; reemplaza a `ae28e6c` y `120ea84`, ver `docs/UX-08I-FASE-2B-VISTA-CANDIDATO.md` |
| Paquete | `cervesur-react-admin-fase2b-2f88cfbc5098-20261105-162903Z.tar.gz` |
| SHA-256 | `52418fee44e8dc6578a2ed76142a6ae8007787a7da3a663d22724542f7a703e6` |
| Se instala sobre | Fase 2A RC2 `f539c9fd543d`, tal como está operando hoy |
| Ensayo | 235 PASS / 0 FAIL en réplica Docker con la Fase 2A realmente instalada (`ENSAYO-DOCKER-2f88cfb.log`); regresión HTTP completa de la CI: 1752 PASS / 0 FAIL. **Solo se usa RC1c**: los paquetes anteriores quedan descartados |

## 3. Qué NO cambia
Flutter (80), portal de trabajadores (443), QA (8094), visor DB031/informe 72, Apache, certificados, Empresas, Reportes y Accesos, y el archivo `.env` durante P3. Rol y contraseña de usuarios permanecen bloqueados.

## 4. Pasos y quién decide
| Paso | Qué ocurre en el servidor | Escribe en MySQL | Reversión | Autoriza |
|---|---|---|---|---|
| **P2** Respaldo | Respaldo de las 6 tablas de negocio + `audit_log`, SHA-256, consistencia y **restauración de prueba en un esquema temporal** (se crea y se elimina) | Solo ese esquema temporal (`CREATE`/`DROP` de `cervesur_verif_*`) | Eliminar el esquema temporal | **DBA** |
| **P3** Código 2B, Usuarios OFF | Reemplaza `public/` y `backend/` del 8443 con respaldo verificado; **no toca** `.env`, MySQL ni Apache | No | `instalar-codigo-2b.sh --revertir` (automática si falla) | **DBA + funcional** |
| **P4a** Permisos | Crea la cuenta bloqueada `cervesur_vista_alta@localhost` y la vista `v_user_alta` (`WITH CHECK OPTION`); concede a `cervesur_escritura_adm` `INSERT` **solo sobre la vista** y `UPDATE` por columnas sobre `user` (sin `email`, `role`, `password`; sin `DELETE`; sin lectura de `password`; sin `INSERT` sobre `user`). Requiere MySQL ≥ 8.0.18 | **Sí, DDL (no transaccional): `CREATE USER`/`CREATE VIEW`/`GRANT`** | `privilegios-usuarios.sh --revertir` (revoca, elimina vista y cuenta) | **DBA** |
| **P4b** Interruptor | Escribe `ADMIN_WRITES_USUARIOS=1` en el `.env` (con respaldo del `.env`); verifica capacidades y se apaga solo si no coinciden | No (edita `.env`) | `interruptor-usuarios.sh apagar` (inmediato) | **Funcional** (con aviso al DBA) |
| **P4c** Una operación real | UNA alta o edición de un usuario real elegido por el funcional; se comprueba exactamente una fila nueva en `audit_log` | Sí (datos de ese usuario + 1 fila de auditoría) | `apagar`; el dato se corrige con otra edición auditada | **Funcional** |
| P5 | No disponible | — | — | No se solicita |

## 5. Decisiones solicitadas (marque, firme y feche cada una)
| # | Decisión | DBA | Funcional |
|---|---|---|---|
| A | Autorizo **P2** (respaldo + restauración de prueba en esquema temporal; acepto `CREATE`/`DROP` de `cervesur_verif_*`) | ☐ | — |
| B | Autorizo **P3** (código con Usuarios apagado) — *requiere A cumplido y verificado* | ☐ | ☐ |
| C | Autorizo **P4a** (permisos no sensibles) — *requiere B verificado* | ☐ | — |
| D | Autorizo **P4b** (encender Usuarios no sensible) — *requiere C verificado* | ☐ (enterado) | ☐ |
| E | Designo el usuario para **P4c** y la operación exacta (alta/edición) | — | ☐ usuario: ______ |
| F | Acepto el riesgo residual de Flutter (ver §6) | ☐ | ☐ |

## 6. Riesgos que deben aceptarse expresamente
1. **Flutter y las sesiones.** Flutter acepta tokens antiguos sin `session_version`, toma el rol del token y no revoca sesiones al cambiar la contraseña. Con lo no sensible activo: desactivar cierra la sesión de React y deja a Flutter sin acceso en su siguiente consulta; **reactivar exige esperar 24 h** desde la desactivación para que no reviva una sesión antigua de Flutter. El comportamiento de Flutter se probó con una **reconstrucción**, no con su backend real.
2. **Escrituras simultáneas.** Flutter escribe las mismas tablas sin transacciones. Se mitiga con bloqueo de fila y control de versión (409 ante conflicto), pero no puede eliminarse desde este lado.
3. **DDL no transaccional.** `GRANT`/`REVOKE` no tienen `ROLLBACK`. El script detecta estados parciales y los recupera, pero un corte del servidor MySQL a mitad es posible.
4. **El alta crea cuentas con contraseña inicial** (solo se guarda el hash, nunca se audita). El rol del alta es siempre `user`.
5. **No existe aún una restauración probada del respaldo productivo.** P2 existe precisamente para producirla.

## 7. Qué se necesita del servidor / de las personas (antes de P2)
- Ventana corta **sin escrituras de Flutter** durante el respaldo (si las tablas cambian mientras se vuelcan, el respaldo se descarta y se repite).
- Espacio libre ≥ 3 × el tamaño de `cervesur_portalbi` en `/var/backups/cervesur-react-admin`.
- Quién ejecuta (usuario con `sudo`), quién observa y dónde se guarda la evidencia.
- Decisión del DBA: restauración de prueba **en un esquema temporal del mismo servidor** (requiere A) o en **otra instancia MySQL** (no escribe nada en producción).
- Excepción pendiente `debian-sys-maint` (control E0): el DBA debe documentarla; esa cuenta **no** se usa para la aplicación.

## 8. Condiciones adicionales de la liberación (vinculantes para P3 y P4)
1. **Escalamiento (antes de P4a).** Con RC1c la brecha estructural quedó **cerrada en la base**: la cuenta de React no puede insertar en `user`, solo en `v_user_alta`, y el motor rechaza cualquier rol distinto de `user`/`usuario` (`CHECK OPTION`); tampoco puede cambiar `email`, `role` ni `password`. Persiste como riesgo residual que quien controle el DBA o la cuenta `root` puede alterar la vista; el kit (`--verificar`) lo detecta. Resultado: `docs/UX-08I-P4A-EVALUACION-SEGURIDAD.md` y `docs/UX-08I-FASE-2B-VISTA-CANDIDATO.md`.
2. **Rol y contraseña de usuarios existentes: fuera del alcance de esta liberación.** No hay ruta, interruptor ni script del kit que los habilite.
3. **Flutter.** Usuarios **no** se presenta como mecanismo de revocación inmediata de acceso en todos los sistemas hasta validar sesiones y tokens contra el backend Flutter real. La interfaz lo dice así en el diálogo de desactivación.
4. **Cada intervención** (P2, P3, P4a, P4b, P4c) exige: autorización identificable (§5), ventana controlada, respaldo verificable, comprobación de continuidad de Empresas, Reportes y Accesos, **criterios de suspensión** y procedimiento de recuperación documentado (`docs/UX-08I-ENSAYO-RC1-INFORME.md` §7).
5. **Un respaldo de menos de 24 h no garantiza recuperar los cambios posteriores.** Debe confirmarse su consistencia (manifiesto, `CHECKSUM TABLE`, restauración de prueba) y **controlarse las escrituras concurrentes** (Flutter) durante la intervención: el respaldo se descarta si las tablas cambian mientras se vuelcan.
6. **Excepciones documentadas y pendientes:** cuenta privilegiada `debian-sys-maint`, validación funcional del acceso ID 114 y compatibilidad de sesiones con Flutter. Su aceptación debe ser **explícita** cuando corresponda al alcance autorizado (casilla F).

**Criterios de suspensión (cualquiera detiene la intervención y activa la recuperación):** respaldo sin `restauracion_probada=1` o con SHA-256 distinto; tablas que cambian durante el volcado; Empresas, Reportes o Accesos dejan de responder; huella de Flutter/443/QA distinta; cuenta MySQL con privilegios de más; `audit_log` sin sus 2 triggers; cualquier 503 del administrador; más de una fila de auditoría por operación; un FAIL sin su salida completa guardada.

## 9. Qué NO se autoriza con este documento
SSH productivo, cambios de Apache, certificados, cualquier cambio en Flutter/443/QA/DB031, rol y contraseña de usuarios (P5), `push`/`merge`, ni ninguna operación fuera de la tabla del §4.

Firmas: DBA ______________________ fecha ________  · Responsable funcional ______________________ fecha ________
