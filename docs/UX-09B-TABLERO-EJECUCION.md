# UX-09B · Tablero de ejecución: poner en funcionamiento la Administración de Usuarios

Estado: **plan de ejecución para aprobación.** RC1c `2f88cfb` intacto; P4b y P5 bloqueados; ninguna escritura productiva autorizada; C-5 sujeta a las firmas y al operador. No se duplica nada: cada fila apunta al documento que ya existe (UX-08I, UX-09A, `inspeccion-flutter/paquete-c5`, `FICHA-LIBERACION-LOTE1-K2`).

## 1. Entrega mínima (qué se pone en funcionamiento)

Todo lo siguiente **ya está implementado en RC1c** y probado en el ensayo Docker (235 comprobaciones; mismo paquete, huella `52418fee…a703e6`). No hay desarrollo nuevo.

| Capacidad pedida | Estado en RC1c | Etapa que la habilita | Se comprueba con |
|---|---|---|---|
| Inicio de sesión administrativo | Implementado (8443, rol desde la base) | Ya disponible | U03 |
| Listado y búsqueda de usuarios (texto, rol, estado) | Implementado | Ya disponible (lectura) | U04 |
| Consulta de permisos y accesos de un usuario | Implementado («Accesos de …», `/api/users/{id}/access`) | Ya disponible (lectura) | U07 |
| **Alta de usuarios autorizada** | Implementada; solo por la vista protegida; rol siempre `user` | **P4a** (permisos) + **P4b** (interruptor) + **P4c-1** (primera alta real) | U14 |
| **Edición de datos permitidos** (nombre, apellido, teléfono; **no** correo) | Implementada | P4b + **P4c-2** | U16, U17 |
| **Activar y desactivar** | Implementada (reactivar exige ≥ 24 h) | P4b + **P4c-3 / P4c-4** | U19, U22 |
| Rechazo de operaciones administrativas desde cuentas normales | Implementado en el servidor (puerta de rutas por rol) | Ya activo; se prueba en P3/P4b | U08, U09 |
| Auditoría y persistencia | Implementadas (una fila por operación, atómica, solo inserción) | P4c | U23–U25 |
| **Cambio de rol y de contraseña** | Implementados pero **bloqueados** por el servidor | **P5 — fuera de esta entrega** | U10 |

**Qué es P4b y qué es P4c.** P4b **no escribe datos**: enciende el interruptor y habilita las operaciones no sensibles. P4c son las **primeras escrituras reales**, una por operación y con autorización propia. Detalle y matriz de pruebas: `UX-09B-QA-USUARIOS.md`.

## 2. Secuencia ejecutable de P2, P3 y P4a

Cada paso con su ventana, su parada y su reversión ya están en `UX-08I-P2-P3-PREPARACION-FINAL.md` y `UX-08I-FASE-2B-VISTA-CANDIDATO.md`. Aquí solo lo mínimo para ejecutarlas.

**Ventanas mínimas propuestas:** **V1 = P2 + P3** (≈ 2 h: P2 de 60 a 90 min verificado antes de empezar P3, que tarda ≈ 30 min) · **V2 = P4a** (30 a 60 min, dentro de las 24 h del respaldo para no repetir P2). El Lote 1 y K1 tienen cada uno su ventana propia y no se mezclan con V1 ni V2.

### 2.1 P2 · respaldo con restauración de prueba

| Tema | Mínimo indispensable |
|---|---|
| **Firmas** | **A** (DBA), con el enterado del funcional (`paquete-c5/4-FIRMAS-A-C5-Y-A-P2.md`) |
| **Responsable del respaldo** | DBA (decide dónde restaurar; **otra instancia** es preferible); ejecuta el operador con `sudo` y observa el funcional |
| **Datos que faltan** | Espacio libre ≥ 3 × el tamaño de la base y versión de MySQL: **los entrega C-5** |
| **Evidencia que lo da por bueno** | Manifiesto con `restauracion_probada=1`; SHA-256 del `.sql.gz` igual al `.sha256`; `CHECKSUM TABLE` vivo = restaurado en las 6 tablas; `audit_log` con sus 2 triggers; esquema temporal eliminado; copia del respaldo fuera del servidor |
| **Paradas** | Respaldo sin `restauracion_probada=1`; tablas que cambian durante el volcado (máx. 3 intentos); Empresas/Reportes/Accesos dejan de responder; huella de Flutter, 443 o QA distinta; cuenta con privilegios de más; `audit_log` sin 2 triggers; cualquier 503 |

### 2.2 P3 · publicar RC1c con Usuarios desactivado

| Pregunta | Respuesta |
|---|---|
| ¿RC1c está listo para publicar? | **Sí en el artefacto.** Paquete con huella verificada hoy (`52418fee…a703e6`); código idéntico a `2f88cfb`; pruebas unitarias del backend (3 suites) pasan; ensayo Docker de P3 con 24 comprobaciones (simulación sin escribir, paquete manipulado se niega, fallos inyectados revierten solos, reversión real). **Repetición del ensayo Docker del paquete hoy: 235 PASS / 0 FAIL, con los mismos resultados por nombre que el del 05/11.** |
| ¿Ventana mínima? | ≈ 30 min dentro de V1, con P2 **ya verificado** |
| ¿Usuarios permanece desactivado? | Sí por tres capas: sin `ADMIN_WRITES_USUARIOS` en el `.env` (el instalador **se niega** si aparece aunque valga 0), el servidor responde 403 `fase_2b_bloqueada` en las rutas de Usuarios y la cuenta de MySQL aún no tiene los permisos (hasta P4a) |
| Firmas mínimas | **B** (DBA + funcional) y **F** (riesgo residual de Flutter aceptado) |
| Pruebas de humo (solo lectura) | `instalar-codigo-2b.sh --simular` sale 0 y no escribe; tras `--aplicar`: `verificar-posdespliegue.sh --etapa accesos` PASS (no escribe datos de negocio ni de auditoría); capacidades `usuarios=false`; las 6 rutas de Usuarios con 403 (id inexistente, cuerpo vacío); huellas de Flutter (80), 443 y QA iguales; `.env` byte a byte igual; Empresas, Reportes y Accesos se leen. **Sin ninguna escritura productiva** |
| Reversión | `instalar-codigo-2b.sh --revertir <respaldo>` |

### 2.3 P4a · permisos mínimos

| Tema | Mínimo indispensable |
|---|---|
| **Qué crea exactamente** | (1) Una cuenta **dedicada y bloqueada** `cervesur_vista_alta@localhost` (no puede iniciar sesión; es solo el `DEFINER`), con `SELECT` e `INSERT` por 11 columnas de `user`. (2) La vista `v_user_alta` con `WITH CHECK OPTION` (solo roles `user`/`usuario`, activo, `session_version = 1`). (3) A `cervesur_escritura_adm`: `INSERT` **solo sobre la vista** y `UPDATE` de `first_name, last_name, phone, is_active, session_version, updated_at`. **No** hay `UPDATE` de correo, rol ni contraseña, ni lectura de `password`, ni `DELETE`. |
| **Aclaración** | La cuenta bloqueada **no es una cuenta de acceso**; es distinta de la decisión de no crear cuentas con escritura para el procedimiento de altas |
| **Datos exactos que entrega C-5 (SQL)** | Versión de MySQL ≥ 8.0.18 (§1) · la cuenta y la vista **no existen** (§7, §9) · las 11 columnas de `user` y sus tipos (§3) · nombre y host de la cuenta de escritura y sus `GRANT` actuales (§8) · triggers y claves foráneas (§9) |
| **Revisión del DBA** | Lee la salida de `--simular` (muestra las 6 sentencias exactas) y las plantillas `UX-08I-alta-usuarios-vista.up` y `UX-08I-usuarios-no-sensibles.up` |
| **Firma** | **C** (DBA) |
| **Dependencias que impiden liberarla** | P3 verificado · C-5 SQL entregado y sin hallazgos bloqueantes · respaldo < 24 h · firma C |
| **Aceptación** | `privilegios-usuarios.sh --verificar` PASS; la vista es insertable, con `CHECK OPTION` y `DEFINER` dedicado; **no hay 503**; la capacidad efectiva **no** se amplió (`usuarios=false`); datos de usuarios sin cambios |
| **Reversión** | `privilegios-usuarios.sh --etapa no-sensibles --revertir` |

## 3. Seguridad: la puerta de P4b (versión 2, sin aplazar controles)

Detalle y demostraciones en `UX-09B-PUERTAS-DE-SEGURIDAD.md`. **D3 (no-elusión) ejecutado: legado 154 PASS / 0 FAIL / 8 INCONCLUSO; RC1c 51/51** (`UX-09B-D3-NO-ELUSION.md`); parcial hasta repetirlo con los archivos reales en la ventana. La versión 1 (A1/A2/A3 aplazados 14 días con riesgo aceptado) **fue rechazada**. Antes de P4b se cierra:

| Puerta | Parche | Firma | Condición adicional |
|---|---|---|---|
| **K2** | Lote 1 | A-L1 | — |
| **K1** | Lote 2 «retirar» (única puerta donde el aislamiento de ruta sustituye al parche) | A-L2 | C-5 sin uso legítimo |
| **A2** | Lote 4 mínimo (F-4, F-5a–c) | A-L4 | Atestación de `JWT_SECRET` |
| **A1** | Lote 3 (F-3) | A-L3 | idem |
| **A3 / F-10** | Con el Lote 4 | A-L4 | Procedimiento temporal de altas **firmado, ensayado y disponible**; si no, F-10 no se despliega y P4b no se abre |

Aislar `empresas*`, `register`, `profile` o las escrituras de reportes/accesos rompería a Flutter, así que esas se parchean. **Los controles de autorización no se omiten:** U08–U13 prueban en el servidor que una cuenta normal no puede.
**Vector pendiente (D4):** el rol del legado sale del JWT; sin evidencia de infraestructura de que `JWT_SECRET` está definido y no es el literal por defecto, A1/A2 no se declaran cerrados.


## 4. Dos frentes en paralelo

| Frente A · Infraestructura y DBA | Frente B · React y seguridad |
|---|---|
| Firmar A-C5 y A | Verificar RC1c (hecho: ver fila 3 del tablero) |
| Ejecutar C-5 (operador y DBA) | Preparar P3 (humo definido) |
| Decidir dónde restaurar; reservar V1 y V2 | Lote 1 (K2) y K1 «retirar»: firmas y ventanas |
| P2 → P3 (V1) → P4a (V2) | QA de Usuarios preparada (`UX-09B-QA-USUARIOS.md`) |
| Autorización de P4a (C) | P4b y P4c: criterios y autorizaciones listos, bloqueados |

**Tipos de bloqueo** (columna del tablero): **T** técnico real · **F** aprobación formal · **D** datos pendientes · **O** trabajo opcional.

## 5. Tablero

| # | Frente | Etapa | Estado real | Responsable | Bloqueo (tipo) | Próxima acción concreta | Prueba de aceptación | GO / NO-GO |
|---|---|---|---|---|---|---|---|---|
| 1 | A | Firmas **A-C5** y **A (P2)** | Textos listos y huellas verificadas; **sin firmar** | Funcional (A-C5); DBA (A y constancia SQL) | **F** | Firmar | Documentos firmados y fechados | **GO** para firmar |
| 2 | A | **C-5** (sistema y SQL) | Revisado y probado (33/33); **no ejecutado** | Operador (parte 1); **DBA** (parte 2) | **F** (1) | Ejecutar cada parte y devolver las salidas con `PASS` del revisor | Dos archivos de salida, huellas verificadas, sin valores sensibles | **GO** tras la fila 1 |
| 3 | B | **Verificar RC1c** | Paquete `52418fee…a703e6` verificado; 3 suites unitarias PASS; **ensayo Docker repetido hoy: 235 PASS / 0 FAIL** (`inspeccion-flutter/ENSAYO-DOCKER-2f88cfb-REPETICION-20261106.log`) | Local | — (**T** resuelto, ver nota) | Registrar el resultado | Ensayo Docker del paquete: 235 PASS / 0 FAIL | **GO** (calidad local) |
| 4 | B | **Lote 1** (K2a–e) | Listo y probado (297/297 + 196/196 + cadena 20/20); faltan firmas y ventana | Funcional; Infraestructura | **F** | Firmar P-2/P-3; nombrar personas; pedir ventana (hoja `UX-09A-VENTANA-LOTE1.md`) | V1–V6 de la hoja | **GO** solicitar · **NO-GO** autorizar hasta P-2…P-8 |
| 5 | B | **K1 «retirar»** | Listo y probado; falta verificar uso | Infraestructura | **D** (C-5 shell) | Leer `K1_tokens_powerbi` en la salida de C-5; firmar A-L2; ventana | 410 desde la red interna y desde fuera; 0 respuestas `2xx` posteriores | **NO-GO** hasta la fila 2 |
| 6 | A | **P2** respaldo | Procedimiento probado (14/14) | DBA (respaldo); operador (ejecuta) | **F** (A), **D** (espacio, versión), decisión del DBA (dónde restaurar) | Fijar V1 | Manifiesto `restauracion_probada=1` y el resto de la sección 2.1 | **GO condicionado** |
| 7 | A | **P3** código 2B (Usuarios 403) | Paquete listo; ensayo previo 24/24 en P3 | Operador | **F** (B, F), P2 verificado | Ejecutar en V1 tras P2 | Humo de la sección 2.2 | **NO-GO hoy** |
| 8 | A | **P4a** permisos | Kit probado (35/35); faltan datos y firma | DBA | **D** (C-5 SQL), **F** (C), P3 | Revisar `--simular`; firmar C | `--verificar` PASS; sin 503; capacidad sin ampliar | **NO-GO** hasta C-5 SQL y firma |
| 9 | B | **Puerta de P4b** (K2, K1, A2, A1, A3) | Versión 2: las cinco puertas **antes** de P4b; v1 rechazada | Funcional | **F**; depende de 4, 5, 12, 13 y de D4 | Aprobar la v2; pedir la atestación de `JWT_SECRET` | Cinco puertas verificadas (hash, humo, C-5 posterior); D3 completo en ventana (hoy parcial, 0 FAIL) y D4 con evidencia; `POWERBI_AUTH_MODE=retirar` atestado | **NO-GO** hasta 4, 5, 12, 13 |
| 10 | B | **P4b** interruptor | Kit probado; **bloqueado** | Operador; funcional (D) | **T** (P4a), **F** (D, criterios de suspensión) | — hasta autorización expresa | `usuarios=true`, `sensible=false`; sensibles 403; sin escrituras | **NO-GO** |
| 11 | B | **P4c-1 a P4c-3** (alta, edición, desactivación de USR-T) | Matriz U14–U19 lista; **bloqueado** | Funcional (E por operación); ADM-T | **T** (P4b), **F** | — hasta autorización expresa | U14, U16, U19 con 1 auditoría cada una | **NO-GO** |
| 11b | B | **P4c-4** reactivación de USR-T **≥ 24 h después** de P4c-3 | **Prueba local con reloj simulado: 15/15** (`tests/isolated/probar-reactivacion-reloj.sh`); bloqueado | Funcional (E); ADM-T | **T** (P4c-3 + 24 h) | Visita aparte, al día siguiente | U22: 1 auditoría `user.reactivate`, sesión +1 | **NO-GO** hasta P4c-3 + 24 h |
| 12 | B | **Lote 4 mínimo** + **Lote 3** (A2, A1) | Listos y probados (354/354, 196/196, 20/20) | Infraestructura | **F**; **D** (atestación JWT) | Firmar A-L4/A-L3; pedir ventana **antes de P4b** | Hash por archivo, humo de Flutter, D3 | **NO-GO** autorizar hasta Lote 1 estable y D4 |
| 13 | B | **F-10** registro público | Parche listo y probado; condicionado | Infraestructura; DBA | **F** (A-ALTA), **D** (C-5), ensayo del DBA | Firmar y ensayar el procedimiento temporal; desplegar con el Lote 4 | `POST /api/register` vacío → 403; un alta por el procedimiento temporal funciona | **NO-GO** hasta las 4 condiciones |
| 14 | — | Opcional: F-5d–g, procedimiento temporal (contingencia), HTTPS corporativo, paridad, retiro de Firebase | Preparados o planificados | Varios | **O** | No bloquean Usuarios | — | Fuera del camino crítico |

**Nota sobre la fila 3.** El primer intento de repetir el ensayo desde `wt-ux09a` falló antes de empezar (`El contenedor no arrancó`): el worktree nuevo se creó con `core.autocrlf=true` y los archivos de `deploy/produccion-escritura/ensayo/` quedaron con finales de línea CRLF, por lo que el contenedor no encontraba su script de arranque (`exec ... no such file or directory`). No es un defecto de RC1c ni del paquete (el índice de git y los archivos empaquetados son LF). Se normalizaron esos 5 archivos del worktree de trabajo y se relanzó: **235 PASS / 0 FAIL**. **Consecuencia práctica:** cualquier comando que se ejecute desde un checkout de Windows con `autocrlf` debe hacerse con LF; el operador usa el paquete `.tar.gz` (LF), no un checkout.

### 5b. Estado tras el cierre de D3 y D4 (UX-09B-D3-CIERRE-Y-P4B.md)

| Frente | Estado | Siguiente acción |
|---|---|---|
| D3 legado | 0 FAIL / 2 207 peticiones; **8 INCONCLUSOS abiertos** (sin PASS por falta de archivos reales); hallazgo nuevo de sesiones antiguas y roles obsoletos | Infraestructura entrega el conjunto mínimo de archivos (con huellas, literal de `JWT_SECRET` enmascarado) |
| D3 RC1c | 51/51 + matriz de sesión | — |
| D4 | Paquete de solo lectura probado 17/17 (entrega de fuentes 14/14); **no ejecutado**. `JWT_EXPIRATION` previsto **decidido: 86400 s** | Firmar el adendo A-C5b; Infraestructura ejecuta (`paquete-d4/SOLICITUD-INFRAESTRUCTURA.md`) |
| Flutter y token caducado | **No demostrado**; sin manejador de 401 en el bundle (la app mostraría «Observación», no el login) | Responsable de Flutter ejecuta T-EXP (`UX-09B-DECISION-JWT-Y-RIESGO-RESIDUAL.md` §2) |
| Riesgo residual de tokens sin `session_version` | Documentado; sin medida obligatoria si D4 pasa; rotación de `JWT_SECRET` obligatoria si D4 falla o hubo logins sin `exp` | Decidir tras D4 y el archivo real |
| K1 | `retirar` probado en copia; **no atestado** | Lote 2 + D4-08/08b |
| F-10 | Parche listo; **procedimiento alternativo sin firma ni ensayo del DBA** | A-ALTA + ensayo |
| C-5 | **No ejecutado** | Firmas A-C5/A, operador y DBA |
| **P4b** | **NO-GO** | Todas las filas anteriores en verde + firma D |

## 6. Ruta mínima de ejecución

```
Firmas (1) ─► C-5 (2) ─► P2 (6) ─┐
                                  ├─► V1: P2 + P3 ─► V2: P4a ───────────────┐
Lote 1 (4) ─► K1 (5) ─► Lote 4 + F-10 (12, 13) ─► Lote 3 ─► Puerta (9) ─────┴─► P4b (10)
                                                                              ─► P4c-1…3 (11) ─► P4c-4 (11b, +24 h)
```

Las dos ramas corren en paralelo; P4b exige **ambas**. Sin fechas comprometidas: dependen de firmas y ventanas, y no se recorta ningún control para cumplir un plazo.

## 7. Qué podemos ejecutar primero

1. Firmar **A-C5** y **A (P2)**; ejecutar **C-5** (operador y DBA). Es solo lectura, está probado (33/33) y desbloquea K1, F-10 y P4a.
2. Pedir la **atestación de infraestructura** (D4: `JWT_SECRET` definido y no literal, `JWT_EXPIRATION`). No toca nada.
3. Firmar P-2/P-3 y solicitar la ventana del **Lote 1**.
4. Firmar y **ensayar el procedimiento temporal de altas** con el DBA (condición de F-10).

## 8. Firmas y datos que faltan realmente

| Falta | Quién | Desbloquea |
|---|---|---|
| A-C5; A (P2); B; C | Funcional / DBA | C-5, P2, P3, P4a |
| P-2/P-3 y personas del Lote 1 | Funcional | Lote 1 |
| A-L2, A-L3, A-L4 | Funcional | K1, A1, A2 + F-10 |
| A-ALTA, custodio y personas, D-PWD1/D-PWD2, ensayo del DBA | DBA + Funcional | F-10 |
| Salidas de C-5 (sistema y SQL) | Operador / DBA | K1, F-10, P4a |
| Atestación `JWT_SECRET` / `JWT_EXPIRATION` y `POWERBI_AUTH_MODE=retirar` | Infraestructura | A1/A2 (D4) y K1 |
| Copia de seguridad de `auth_middleware.php`, `index.php` y controladores del legado (para completar D3) | Infraestructura | INCONCLUSOS de D3 |
| Dónde restaurar (P2); espacio y versión de MySQL | DBA | P2 |
| D, E (por operación), F (riesgo residual de Flutter) | Funcional | P4b, P4c |

## 9. Correctivos imprescindibles antes de P4b

K2 (Lote 1), K1 (retirar), A2 (F-4, F-5a–c), A1 (F-3) y A3 (F-10 condicionado). Opcional: F-5d–g. Todos están escritos y probados localmente; **ninguno desplegado**.

## 10. Ventanas que pueden coordinarse en paralelo

| Ventana | Contenido | Puede ir en paralelo con | Restricción |
|---|---|---|---|
| **V1** | P2 + P3 | Ventana del Lote 1 (otro sistema: backend heredado vs React) | P2 antes que P3 |
| **V2** | P4a | Lote 4 / Lote 3 | Tras V1 verificada |
| **W-L1** | Lote 1 | V1 | Primero, para estabilizar |
| **W-L4** | Lote 4 + F-10 | V2 | Tras W-L1 estable y condiciones de F-10 |
| **W-L3** | Lote 3 | — | Tras W-L4 |
| Pruebas de P4c-4 | Reactivación | — | ≥ 24 h tras P4c-3 |

## 11. Qué evidencia convierte cada NO-GO en GO

| NO-GO | Evidencia que lo convierte en GO |
|---|---|
| P2 | Firma A, espacio y versión (C-5), lugar de restauración, ventana |
| P3 | P2 verificado (`restauracion_probada=1`), firmas B y F, línea base de C-5 |
| P4a | C-5 SQL (MySQL ≥ 8.0.18, cuenta y vista ausentes), firma C, respaldo < 24 h, P3 verificado |
| K1 | C-5 sin llamadas legítimas a `tokens` + A-L2 |
| Lote 1 | P-2…P-8 de la hoja, humo de Flutter (mensaje K2d) |
| A2 / A1 | Lote 1 estable, A-L4/A-L3, D3 completo con archivos reales (hoy 0 FAIL, 8 INCONCLUSO) y D4 (atestación JWT) |
| F-10 | Procedimiento temporal firmado + ensayado + disponible; C-5 sin otros consumidores |
| P4b | Las cinco puertas verificadas, P4a verificado, autorización D expresa |
| P4c-1…3 | P4b verificado, autorización E por operación, ADM-T/NOR-T designados |
| P4c-4 | P4c-3 ejecutada hace ≥ 24 h; la lógica ya está probada (15/15) |
| P5 | Bloqueado: fuera del alcance de esta etapa |
