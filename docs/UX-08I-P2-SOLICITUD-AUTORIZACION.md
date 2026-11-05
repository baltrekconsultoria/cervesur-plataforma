# UX-08I · Fase 2B (Usuarios) — Solicitud de autorización (P2 y pasos siguientes)

**Para:** DBA de Cervesur y responsable funcional. **Estado:** solicitud, **nada autorizado ni ejecutado**. Cada casilla es una decisión independiente: autorizar una no autoriza las siguientes.

## 1. Qué se pide, en una frase
Publicar en el administrador React (8443) una versión que **incluye** la administración de Usuarios pero la deja **apagada**, y —solo si ambos lo autorizan por separado— habilitar después las operaciones **no sensibles** de Usuarios (alta, edición de datos personales, desactivar/reactivar). **Rol y contraseña de usuarios no se piden y no se pueden encender con este paquete.**

## 2. Candidato
| | |
|---|---|
| Commit | `120ea841e12c05ec5f39825a3295969725483cb7` (rama `feat/cervesur-ux-08i-fase-2b-usuarios`) |
| Paquete | `cervesur-react-admin-fase2b-120ea841e12c-20261105-132240Z.tar.gz` |
| SHA-256 | `3323035290ecdec3f44393d6c6825366983f8ab1830bea84c2ca7cb11cead585` |
| Se instala sobre | Fase 2A RC2 `f539c9fd543d`, tal como está operando hoy |
| Ensayo | 220 PASS / 0 FAIL en réplica Docker con la Fase 2A realmente instalada (`docs/UX-08I-ENSAYO-RC1-INFORME.md`) |

## 3. Qué NO cambia
Flutter (80), portal de trabajadores (443), QA (8094), visor DB031/informe 72, Apache, certificados, Empresas, Reportes y Accesos, y el archivo `.env` durante P3. Rol y contraseña de usuarios permanecen bloqueados.

## 4. Pasos y quién decide
| Paso | Qué ocurre en el servidor | Escribe en MySQL | Reversión | Autoriza |
|---|---|---|---|---|
| **P2** Respaldo | Respaldo de las 6 tablas de negocio + `audit_log`, SHA-256, consistencia y **restauración de prueba en un esquema temporal** (se crea y se elimina) | Solo ese esquema temporal (`CREATE`/`DROP` de `cervesur_verif_*`) | Eliminar el esquema temporal | **DBA** |
| **P3** Código 2B, Usuarios OFF | Reemplaza `public/` y `backend/` del 8443 con respaldo verificado; **no toca** `.env`, MySQL ni Apache | No | `instalar-codigo-2b.sh --revertir` (automática si falla) | **DBA + funcional** |
| **P4a** Permisos | `GRANT INSERT/UPDATE` por columnas sobre `user` a la cuenta `cervesur_escritura_adm` (sin `role`, sin `password` en `UPDATE`, sin `DELETE`, sin lectura de `password`) | **Sí, DDL de privilegios (no transaccional)** | `privilegios-usuarios.sh --revertir` | **DBA** |
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

## 8. Qué NO se autoriza con este documento
SSH productivo, cambios de Apache, certificados, cualquier cambio en Flutter/443/QA/DB031, rol y contraseña de usuarios (P5), `push`/`merge`, ni ninguna operación fuera de la tabla del §4.

Firmas: DBA ______________________ fecha ________  · Responsable funcional ______________________ fecha ________
