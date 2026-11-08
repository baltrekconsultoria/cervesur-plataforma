# UX-09A · Autorizaciones, cumplimiento P2/P3/P4a y veredicto por intervención

Estado: **preparación documental. Nada se ejecutó en producción.** RC1c `2f88cfb` intacto; P4b y P5 bloqueados hasta autorización expresa. Detalle requisito por requisito de P2, P3, P4a y P4b: `UX-09A-REQUISITOS-P2-P3-P4.md`. Este documento organiza **qué hay que firmar, en qué orden y qué veredicto corresponde a cada intervención**.

## 1. Autorizaciones recibidas en esta etapa

| # | Autorización | Alcance real | Estado |
|---|---|---|---|
| 1 | Preparar la ejecución manual de C-5 de solo lectura | Revisión previa hecha (`UX-09A-C5-REVISION-Y-EJECUCION.md`); ejecuta **solo** el operador autorizado | Preparado |
| 2 | Preparación documental de P2, P3 y P4a | Sin ejecutar cambios productivos | Hecho (esta matriz y los requisitos) |
| 3 | Procedimiento temporal de altas con el DBA | Redactado y probado; falta su aprobación | Preparado |
| 4 | F-10 integrado en la planificación del Lote 4 | Condicionado (`UX-09A-F10-REGISTRO-PUBLICO.md`, sección 7) | Planificado |
| 5 | Reutilizar RC1c para terminar Usuarios | Sin desarrollo nuevo de Usuarios | Vigente |
| 6 | Estrategia híbrida para el legado | Subconjunto mínimo (`UX-09A-ALTERNATIVAS…`) | Vigente |

## 2. Firmas necesarias (orden recomendado)

| Orden | Id | Qué autoriza | Firma | Documento a firmar | Depende de |
|---|---|---|---|---|---|
| 1 | **A-C5** | Ejecución de C-5 (solo lectura) por el operador | Funcional (y DBA para la parte SQL) | `UX-09A-C5-REVISION-Y-EJECUCION.md` §4 | — |
| 2 | **A** | P2: respaldo y restauración de prueba | DBA | `UX-08I-P2-SOLICITUD-AUTORIZACION.md` §5.A | — |
| 3 | **P-2 / P-3** | Aceptación del Lote 1 y personas del procedimiento de secretos | Funcional | `P2-ACEPTACION-RESPONSABLE-FUNCIONAL-20261106.md` | — |
| 4 | **A-ALTA** | Procedimiento temporal de altas: **DBA único ejecutor SQL**, custodio sin acceso a la base, verificador independiente, y **aceptación del riesgo R-PWD** | DBA + Funcional (custodio enterado) | `UX-09A-PROCEDIMIENTO-ALTA-TEMPORAL.md` §9 | C-5 ejecutado (volumen de altas) |
| 5 | **A-L1** | Despliegue del Lote 1 | Funcional | Ficha del Lote 1 + hoja de ventana | 3 |
| 6 | **B** | P3: código 2B con Usuarios en 403 | DBA + Funcional | `UX-08I-P2-SOLICITUD-AUTORIZACION.md` §5.B | 2 verificado |
| 7 | **A-L2** | K1 «retirar» | Funcional | (por redactar tras ver C-5) | C-5 sin uso legítimo |
| 8 | **A-L4** | Lote 4 mínimo; **con F-10 solo si 4 y la sección 7 de F-10 se cumplen** | Funcional | (por redactar) | 5 desplegado, 4 |
| 9 | **A-L3** | Lote 3 (A1) | Funcional | (por redactar) | 8 |
| 10 | **C** | P4a: permisos | DBA | `UX-08I-P2-SOLICITUD-AUTORIZACION.md` §5.C | 6 verificado, C-5 SQL |
| 11 | **F** | Riesgo residual de Flutter aceptado | DBA + Funcional | idem §5.F, con la lista de lotes y fechas | 5, 7, 8, 9 planificados |
| 12 | **D / E** | P4b / P4c (**bloqueados hasta autorización expresa**) | Funcional | idem §5.D–E | 10, 11, puerta de seguridad |

## 3. Matriz de cumplimiento P2 / P3 / P4a (resumen; el detalle está en los requisitos)

| Intervención | Requisitos | Cumplidos | Pendientes de **evidencia** | Pendientes de **firma/ventana** |
|---|---|---|---|---|
| **P2** | 10 | 0 | Espacio libre, versión de MySQL, línea base (C-5) | A (DBA), ventana y aviso a Flutter, persona que ejecuta y observa, dónde restaurar, criterios de suspensión |
| **P3** | 6 (C1 a C6) | 0 | Línea base de React 8443 contra la Fase 2A (C-5 y manifiesto), excepciones | B, ventana, F, P2 verificado |
| **P4a** | 8 | 0 | MySQL ≥ 8.0.18, cuenta y vista ausentes, esquema de `user`, grants, triggers (C-5 SQL) | C, respaldo < 24 h, P3 verificado |
| **P4b** | 8 | 1 (inspección del código de Flutter) | Parte MySQL (C-5), `JWT_EXPIRATION` | Puerta de seguridad, criterios de suspensión, D (bloqueado) |

**La mayor parte de la evidencia pendiente se obtiene con una sola ejecución de C-5.**

## 4. Veredicto por intervención

| Intervención | Veredicto | Condición para pasar |
|---|---|---|
| **Ejecutar C-5** (operador autorizado) | **GO** | Huellas de los archivos verificadas y `A-C5` firmada. Solo lectura, revisado y probado |
| **P2** respaldo | **GO condicionado** | Firma A del DBA, ventana, espacio, dónde restaurar |
| **P3** código 2B (Usuarios en 403) | **NO-GO hoy** | P2 verificado, B firmada, ventana, línea base, F |
| **P4a** vista y permisos | **NO-GO hasta C-5 SQL y firma del DBA** | MySQL ≥ 8.0.18, cuenta/vista ausentes, P3 verificado, respaldo < 24 h |
| **Lote 1** (K2a–e): solicitar ventana | **GO** | — |
| **Lote 1**: autorizar el despliegue | **NO-GO todavía** | P-2, P-3, P-4 a P-8 (hoja `UX-09A-VENTANA-LOTE1.md`) |
| **Lote 1**: ejecutar | **NO-GO** | No autorizado |
| **K1 «retirar»** | **NO-GO hasta ver C-5** | Sin uso legítimo en los registros; firma A-L2 |
| **Lote 4 mínimo** (F-4, F-5a–c) | **GO condicionado** | Lote 1 desplegado, confirmación del equipo Flutter, firma A-L4 |
| **F-10** (con el Lote 4) | **NO-GO hasta cumplir las 7 condiciones de F-10 §7** | Procedimiento temporal aprobado y ensayado, C-5 sin consumidores indispensables (o decisión de continuidad) |
| **Procedimiento temporal de altas** | **GO para su aprobación; NO-GO para activarlo** | Condiciones 1 a 6 de su sección 9 |
| **Lote 3** (A1) | **GO condicionado** | Lote 4 desplegado, firma A-L3 |
| **P4b** interruptor | **NO-GO** | P4a verificado, puerta de seguridad, criterios de suspensión, autorización expresa |
| **P4c** | **NO-GO** | P4b |
| **P5** rol y contraseña | **NO-GO** | Bloqueado |

## 5. Resultado de C-5

**Pendiente de ejecución por el operador.** Cuando se entregue la salida (`salida-c5-sistema-<fecha>.txt` y `salida-c5-dba-<fecha>.txt`) se clasificará según `UX-09A-C5-REVISION-Y-EJECUCION.md` §5 y se actualizarán esta matriz, los requisitos y los veredictos.

## 6. Matriz de responsabilidades del procedimiento de altas (resumen)

| Rol | Persona (a nombrar) | Hace | No puede |
|---|---|---|---|
| Solicitante | Área de Cervesur | Pide el alta | Acceder a la base o a la contraseña |
| Autorizador | Responsable funcional designado | Aprueba | — |
| Custodio | ______________ | Genera la contraseña y el SQL (`generar-alta.php`) y entrega la contraseña | Tener permisos de base de datos |
| Ejecutor SQL | **DBA de Cervesur (único)** | Revisa y ejecuta el SQL con su cuenta nominal | Conocer la contraseña; no se crean cuentas nuevas con escritura |
| Verificador | Funcional, distinto del ejecutor | Contrasta la salida y comprueba el acceso | — |

Riesgo registrado: **R-PWD** (contraseña inicial que el usuario no puede cambiar mientras P5 siga bloqueado), con controles C1 a C8, contingencias y evaluación del cambio de contraseña propio (sin desarrollar P5). Detalle: `UX-09A-PROCEDIMIENTO-ALTA-TEMPORAL.md`, secciones 2, 4 y 9.
