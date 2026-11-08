# UX-09A · Requisitos pendientes de P2, P3, P4a y P4b (RC1c `2f88cfb`, sin modificarlo)

Estado: **documentación de preparación. Nada se ejecutó.** Base: `UX-08I-P2-P3-PREPARACION-FINAL.md`, `UX-08I-FASE-2B-VISTA-CANDIDATO.md`, diagnóstico del backend Flutter v6.4 (inspecciones C-0 a C-4) y los scripts C-5 de este paquete. **No se inicia ninguna implementación nueva de Usuarios**: el código de 2B está terminado (CI 1752/0, Docker 235/0, privilegios 35/35).

Estados: **PASS** evidencia suficiente · **PENDIENTE** falta evidencia o firma · **n/a**.

## 0. Qué cambió desde UX-08I

| Antes (UX-08I) | Ahora |
|---|---|
| «El backend de Flutter real no ha sido inspeccionado» | **Inspeccionado** (C-0 a C-4): login, sesiones, rutas, controladores y modelos leídos; hallazgos K1, K2, A1, A2, A3, A5, A6 |
| Riesgo residual de Flutter «sin cuantificar» (casilla F) | Cuantificado: ver el diagnóstico v6.4 y `UX-09A-ALTERNATIVAS-BACKEND-HEREDADO.md` |
| La inspección de Flutter era requisito de P4b | **Cumplida en lo de código.** Quedan las consultas de MySQL del DBA (C-5 SQL) |
| Pregunta abierta: «¿existen recuperación de contraseña o verificación de correo?» | El paquete de Flutter no tiene ninguna llamada de cambio ni recuperación de contraseña (`RegisterCall`, `LoginCall`, `ProfileCall`, `UpdateUserProfileCall`) |

## 1. P2 · Respaldo verificable con restauración de prueba

| # | Requisito exacto | Evidencia | Estado | Responsable | Acción |
|---|---|---|---|---|---|
| 1 | Autorización **A** del DBA (alcance: respaldo, verificación y restauración en esquema temporal; no autoriza P3/P4) | Texto en `UX-08I-P2-SOLICITUD-AUTORIZACION.md` | PENDIENTE | DBA | Firmar |
| 2 | Paquete verificado (`52418fee…a703e6` y `SHA256SUMS`) | Paquete en `paquetes-qa/produccion-fase2b-rc1c/` | PENDIENTE (verificar en destino) | Operador | `sha256sum -c` en el servidor |
| 3 | Persona que ejecuta (con `sudo`), persona que observa y lugar de la evidencia | — | PENDIENTE | Infraestructura | Designar |
| 4 | Espacio libre ≥ 3 × el tamaño de `cervesur_portalbi` | C-5 shell (§2 `df`) y C-5 SQL (§2 tamaño): **revisados y probados** (`UX-09A-C5-REVISION-Y-EJECUCION.md`); resultado pendiente de ejecución | PENDIENTE (resultado) | Operador + DBA | Ejecutar C-5 |
| 5 | Dónde restaurar: esquema temporal del mismo servidor o **otra instancia** (preferible) | — | PENDIENTE | DBA | Decidir |
| 6 | Versión de MySQL ≥ 8.0.18 anotada | C-5 SQL (§1) | PENDIENTE | DBA | Ejecutar C-5 |
| 7 | Ventana acordada y **aviso al dueño de Flutter** (no registrar ni editar usuarios, empresas, informes ni accesos durante el volcado) | El responsable de Flutter ya participa | PENDIENTE | Funcional + DBA | Fijar fecha |
| 8 | Línea base: huella de Flutter, trabajadores, QA, Apache, `.env` (SHA-256, sin imprimir), `SHOW GRANTS` de la cuenta de escritura, `capabilities` del 8443 | Huellas del backend y del frontend de Flutter ya registradas (C-0 a C-4); resto pendiente. C-5 SQL §8 | PENDIENTE (parcial) | Operador | Completar |
| 9 | Criterios de suspensión leídos por ambas partes | `UX-08I-P2-SOLICITUD-AUTORIZACION.md` §8 | PENDIENTE | Funcional + DBA | Leer y firmar |
| 10 | Después: manifiesto `restauracion_probada=1`, `CHECKSUM TABLE` igual en las 6 tablas, `audit_log` con 2 triggers, esquema temporal eliminado, copia fuera del servidor | Script probado 14/14 en Docker | PENDIENTE (se genera al ejecutar) | Operador | Ejecutar P2 |

## 2. P3 · Publicar el código 2B con Usuarios en 403

| # | Condición (C1 a C6 de UX-08I) | Evidencia | Estado | Responsable | Acción |
|---|---|---|---|---|---|
| C1 | P2 ejecutado con `restauracion_probada=1` | — | PENDIENTE | Operador/DBA | Sección 1 |
| C2 | Autorización **B** firmada (DBA + funcional) | — | PENDIENTE | Funcional + DBA | Firmar |
| C3 | Ventana fijada y aviso al dueño de Flutter | — | PENDIENTE | Funcional | Fijar |
| C4 | Línea base del servidor coincide con la Fase 2A RC2 | Flutter: huellas OK. React 8443: no comprobado desde aquí | PENDIENTE (parcial) | Operador | Comparar con `MANIFIESTO-FASE2A-f539c9fd543d.sha256` |
| C5 | Casilla F: riesgo residual de Flutter aceptado | Riesgo cuantificado; **ya no es una incógnita** | PENDIENTE (firma) | Funcional | Aceptar con la lista de lotes y fechas de `UX-09A-ALTERNATIVAS…` |
| C6 | Excepciones documentadas: `debian-sys-maint`, acceso ID 114, sesiones de Flutter | Sesiones de Flutter: **caracterizadas** (diagnóstico C-11 a C-13: el login no filtra `is_active`; el middleware revisa `session_version` y estado cuando el token los trae). Resto abierto | PENDIENTE (parcial) | DBA + Funcional | Documentar |

P3 **no toca MySQL ni el `.env`** y no depende de los lotes del backend de Flutter. **No hacerlo en la misma ventana que un lote del legado.**

## 3. P4a · Vista de alta y privilegios mínimos

| # | Requisito | Evidencia | Estado | Responsable | Acción |
|---|---|---|---|---|---|
| 1 | P3 hecho y verificado | — | PENDIENTE | — | Sección 2 |
| 2 | Respaldo < 24 h (si pasó más, repetir P2) con `SHOW GRANTS` guardado | — | PENDIENTE | DBA | Programar |
| 3 | MySQL ≥ 8.0.18 **confirmado** en el servidor | C-5 SQL §1 | PENDIENTE | DBA | Ejecutar C-5 |
| 4 | La cuenta `cervesur_vista_alta` y la vista `v_user_alta` **no existen** | C-5 SQL §7 y §9 | PENDIENTE | DBA | Ejecutar C-5 |
| 5 | Esquema de `user` conocido (columnas, índices únicos de correo y usuario, duplicados) | C-5 SQL §3 y §4 | PENDIENTE | DBA | Ejecutar C-5 |
| 6 | Privilegios de la cuenta que usa el backend de Flutter y de las cuentas de React | C-5 SQL §8 | PENDIENTE | DBA | Completar los nombres y ejecutar |
| 7 | Disparadores y claves foráneas conocidos | C-5 SQL §9 | PENDIENTE | DBA | Ejecutar C-5 |
| 8 | Autorización del DBA para `privilegios-usuarios.sh --etapa no-sensibles` | — | PENDIENTE | DBA | Firmar |

## 4. P4b · Interruptor de Usuarios

| # | Requisito | Evidencia | Estado | Responsable | Acción |
|---|---|---|---|---|---|
| 1 | P4a aplicado y `--verificar` PASS | — | PENDIENTE | Operador | Después de P4a |
| 2 | **Puerta de seguridad del backend heredado** (híbrida): Lote 1 (K2a–e), K1 «retirar», Lote 4 mínimo (F-4, F-5a–c), Lote 3 (A1) y F-10 ordenado respecto del alta, **o** cada riesgo aceptado por escrito con responsable | Parches versionados y probados (354/354, 196/196, 20/20) | PENDIENTE (despliegues) | Funcional + Infraestructura | `UX-09A-CAMINO-CRITICO.md` |
| 3 | Inspección del backend de Flutter (login, roles, sesiones, operaciones sobre `user`) | C-0 a C-4 | **PASS** en código; pendiente la parte MySQL (C-5 SQL) | — | — |
| 4 | Criterios de suspensión acordados (qué hace parar el uso de Usuarios) | `UX-08I-P2-SOLICITUD-AUTORIZACION.md` §8 | PENDIENTE | Funcional | Firmar |
| 5 | Lenguaje de alcance acordado (diagnóstico v6.4, §8): «la desactivación impide el uso del token en la siguiente petición autenticada del backend de Flutter que pasa por el middleware»; no afirmar revocación inmediata en todos los sistemas ni que Usuarios sea la única vía de alta o modificación de cuentas | Redactado | PENDIENTE | Funcional | Aceptar |
| 6 | Decisión de rotación de secretos (D-5) tomada | Pendiente | PENDIENTE | Funcional + Azure + DBA | Decidir |
| 7 | `JWT_EXPIRATION` del backend heredado conocido | Pendiente | PENDIENTE | Infraestructura | Lectura |
| 8 | Autorización específica de P4b | — | PENDIENTE | Funcional | Emitir al final |

P5 (rol y contraseña) sigue **bloqueado**; no forma parte de este paquete.

## 5. Veredictos

| Etapa | Veredicto |
|---|---|
| P2 | **GO condicionado** (firma del DBA, ventana, espacio, dónde restaurar) |
| P3 | **NO-GO hoy; GO condicionado a P2** |
| P4a | **NO-GO hasta C-5 SQL y firma del DBA; después GO condicionado** |
| P4b | **NO-GO hasta cerrar la puerta de seguridad (requisito 2), P4a y los criterios de suspensión** |
| P5 | **NO-GO** |

## 6. Actualización (opción 1 de F-10)

- **C-5:** revisado (inventario de comandos, 26/26 pruebas, registro de sentencias del servidor de prueba); **listo para que lo ejecute el operador autorizado**. Resultado pendiente.
- **Procedimiento temporal de altas:** redactado y probado (`UX-09A-PROCEDIMIENTO-ALTA-TEMPORAL.md`); falta la aprobación del DBA y del funcional. Es condición de F-10 y por lo tanto de la puerta de seguridad de P4b (requisito 2 de la sección 4).
- **Orden hacia P4b/P4c:** `UX-09A-CAMINO-CRITICO.md`, sección 7.
