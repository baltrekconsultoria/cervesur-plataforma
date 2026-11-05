# UX-08I · Fase 2B — candidato con vista de alta protegida (reemplaza a `ae28e6c`)

Candidato de código: `2f88cfbc5098a1489242bf0c65f45d4041b5177f` (rama `feat/cervesur-ux-08i-fase-2b-vista`, sobre `25073dc`). Paquete: `paquetes-qa/produccion-fase2b-rc1c/cervesur-react-admin-fase2b-2f88cfbc5098-20261105-162903Z.tar.gz`, SHA-256 `52418fee44e8dc6578a2ed76142a6ae8007787a7da3a663d22724542f7a703e6`.
**Nada está desplegado.** Sin SSH, MySQL real, Apache, `.env` real, push ni merge. P5 (rol y contraseña) sigue bloqueado. El backend de Flutter desplegado **no ha sido inspeccionado**: todo lo dicho sobre Flutter viene del repositorio y de una reconstrucción.

## 1. Diferencias respecto de `ae28e6c`
| Área | Antes | Ahora |
|---|---|---|
| Alta de usuarios | `INSERT` por columnas sobre `user`; el valor de `role` solo lo acotaba el guardia de la API | `INSERT` **solo** sobre la vista `v_user_alta` (`WITH CHECK OPTION`: el motor rechaza roles distintos de `user`/`usuario`, inactivos, `session_version`≠1; sin `id`, `avatar`, `last_login`) |
| Identidad de la vista | — | `DEFINER` = `cervesur_vista_alta@localhost`: cuenta dedicada, **bloqueada**, clave aleatoria no guardada, solo `SELECT`/`INSERT` por 11 columnas (mínimo que MySQL exige: sin `SELECT` la vista falla con 1356) |
| Cuenta de React | `UPDATE(email)` e `INSERT` sobre `user` | sin `INSERT` sobre `user`, sin `UPDATE` de `email`, `role` ni `password` |
| Correo | editable | API: 400 si cambia (el mismo correo se ignora); UI: solo lectura en `admin_escritura` |
| Guardia SQL / aserción de arranque | forma exacta sobre `user` | forma exacta sobre `v_user_alta`; `INSERT@user` y `UPDATE(email)` son fatales (503) |
| Kit `privilegios-usuarios.sh` | estados por columnas de `user` | estados incluyen vista + DEFINER; verifica insertable/CHECK/DEFINER/columnas/WHERE/alcance del DEFINER; exige MySQL ≥ 8.0.18; revierte en orden (REVOKE → DROP VIEW → DROP USER); recupera cortes a mitad |
| Paquete | 4 plantillas | 6 plantillas + `SHA256SUMS`, con validaciones propias |
| Fase 2A | — | **sin cambios** (plantilla `UX-08H1` y paquete RC2 `f539c9f` intactos; la plantilla heredada `UX-08H` de QA, prohibida en producción, solo añade `INSERT` sobre la vista) |

## 2. Matriz de pruebas (los 10 puntos pedidos)
| # | Requisito | Prueba | Resultado |
|---|---|---|---|
| 1 | React crea usuarios normales | `http_admin_escritura_fase2b_test`, `p4a_vista_errores`, ensayo P4c (alta con 1 auditoría) | PASS |
| 2 | React no crea administradores (API ni SQL) | `p4_escalamiento_test` (forma exacta, campos de rol 400), motor: `CHECK OPTION failed` para `admin`/`superadmin`/vacío; `INSERT` directo denegado | PASS |
| 3 | No se cambian roles/contraseñas existentes | rutas 403, `UPDATE(role/password)` denegado por el motor, hash global de roles/claves intacto | PASS |
| 4 | Correo bloqueado | API 400 + fila intacta, motor deniega `UPDATE(email)`, UI solo lectura (vitest) | PASS |
| 5 | No se desactiva indebidamente a un administrador | `p4a_concurrencia_test`: no a sí mismo (409), no al último, carrera de dos administradores que se desactivan a la vez → exactamente uno cae (409/401 al otro), una auditoría | PASS |
| 6 | Auditoría correcta | una fila por create/update/deactivate, sin contraseña/hash/datos personales; solo inserción | PASS |
| 7 | Fallo de auditoría revierte todo | ensayo P4c "ATOMICIDAD" y suite fase 2B | PASS |
| 8 | Vista/DEFINER ausente o alterada → falla cerrada | `p4a_vista_errores` y ensayo: 5xx genérico, sin filas ni auditoría, edición y Empresas siguen; `--verificar` detecta (30) vista sin CHECK, con `admin`, INVOKER, DEFINER root/desbloqueado/con UPDATE, vista ausente | PASS |
| 9 | Empresas, Reportes, Accesos | regresión CI y ensayo (escrituras reales por módulo) | PASS |
| 10 | Transición de privilegios sin 503 | prueba `staged` y ensayo P4a (ping 200 tras aplicar con el interruptor ausente) | PASS |

Resultados: `probar-privilegios-2b.sh` **35/35** (MySQL 8.0.46 real); `probar-empaquetado.mjs` **54**; vitest **299** (lint `src` y `tsc` limpios); `unittest discover` **106 OK**; réplica completa de CI **1752 PASS / 0 FAIL en HTTP**; **ensayo Docker 235 PASS / 0 FAIL** (Fase 2A real instalada → P3 → P4a → P4b → P4c → recuperación hasta 2A, con fallos inyectados en cada paso).
Nota de honestidad: la carrera de administradores se ejecutó en una sola corrida por escenario (resultado determinista por el cerrojo `FOR UPDATE`, pero no es una prueba estadística).

## 3. Procedimiento P3/P4 actualizado (cada paso con autorización propia)
- **P2** respaldo (sin cambios; además guardar `SHOW GRANTS` de la cuenta). Un respaldo <24 h no cubre cambios posteriores: controlar escrituras concurrentes.
- **P3** `instalar-codigo-2b.sh --simular/--aplicar` (Usuarios sigue 403 `fase_2b_bloqueada`).
- **P4a** `privilegios-usuarios.sh --etapa no-sensibles --simular` → `--aplicar --respaldo … --confirmo-autorizacion …` → `--verificar`. Crea DEFINER + vista y concede `INSERT` en la vista y `UPDATE` sin email/role/password. Se niega si hay vista/DEFINER previos.
- **P4b** `interruptor-usuarios.sh encender` (verifica la etapa completa, incluida la vista, antes de tocar el `.env`).
- **P4c** una operación real y una fila en `audit_log`.
- **Reversión**: `apagar` → `--etapa no-sensibles --revertir` → `instalar-codigo-2b.sh --revertir`. Estado intermedio tras un corte: repetir `--revertir`.

## 4. Veredicto por etapa
| Etapa | Veredicto |
|---|---|
| Candidato `2f88cfb` (calidad local) | **GO** |
| P2 (respaldo + restauración temporal) | **GO condicionado** a firmas del DBA y del responsable funcional (sin cambios) |
| P3 (código, Usuarios OFF) | **GO condicionado** a P2 verificado y ventana autorizada |
| P4a (vista + privilegios) | **GO condicionado**: P3 hecho, respaldo verificado, MySQL ≥ 8.0.18 confirmado en el servidor, autorización del DBA y confirmación de que la cuenta `cervesur_vista_alta` no existe |
| P4b (interruptor) | **GO condicionado** a P4a verificado y criterios de suspensión acordados |
| P4c | **GO condicionado** a una operación autorizada |
| P5 (rol y contraseña) | **NO-GO** (bloqueado) |

## 5. Pendientes y limitaciones vigentes
Backend de Flutter real sin inspeccionar (login, sesiones, recuperación de contraseña); excepciones registradas: `debian-sys-maint`, validación funcional del acceso ID 114, compatibilidad de sesiones de Flutter; versión de MySQL del servidor sin confirmar; restauración de un respaldo real nunca probada; el plugin `mysql_no_login` no se usa porque no viene cargado (se usa clave aleatoria + `ACCOUNT LOCK`).
