# UX-09A · Camino crítico: cerrar Usuarios y retirar Firebase sin duplicar desarrollos

Estado: **plan para aprobación. Nada se ejecutó.** Las duraciones son **estimaciones** (semanas de calendario desde la autorización de cada paso); casi todo el tiempo es de firmas, ventanas y TI, no de desarrollo. Base: `UX-09A-ALTERNATIVAS-BACKEND-HEREDADO.md`, `UX-09A-REQUISITOS-P2-P3-P4.md` y `UX-09A-F10-REGISTRO-PUBLICO.md`.

## 1. Qué se reutiliza y qué no se hace

| Se reutiliza tal cual | Nuevo y mínimo | No se hace |
|---|---|---|
| RC1c `2f88cfb` completo (Usuarios 2B, kits P3/P4a/P4b/P4c, auditoría) · Fase 2A · autenticación SEC-04B/05A · piloto de usuarios 8092 · publicación HTTPS corporativa preparada (UX-07E.C) · correctivos del backend heredado ya probados | C-5 (lectura) · K1 «retirar» · F-10 · ajustes de paridad que el responsable apruebe (perfil propio, foto, indicadores) | Reescribir Usuarios · una plataforma nueva · integrar Firebase en React · parchear más el legado de lo que fija la regla de las 12 semanas |

## 2. Hitos y dependencias

| Id | Hito | Depende de | Responsable | Estimación | Ventana propia |
|---|---|---|---|---|---|
| **D1** | Autorizar C-5 (`A-C5`) | — | Funcional | 1 a 3 días | No |
| **D2** | Firmar P-2 y P-3 del Lote 1; nombrar personas y canal | — | Funcional | 1 a 2 semanas | No |
| **D3** | Elegir la opción de F-10 (1, 2 o 3) con los datos de C-5 | U1 | Funcional | 1 día | No |
| **D4** | Pedir a TI: nombre interno, certificado de la CA corporativa, cortafuegos 443 | — | Funcional + TI | arranque inmediato; plazo de TI 1 a 3 semanas | No |
| **D5** | Decisión escrita sobre R-PUB (contenido de los informes publicados) | Recuento de C-5 SQL §11 | Funcional | 1 a 2 semanas | No |
| **D6** | Firma A del DBA para P2 | — | DBA | 1 semana | No |
| **U1** | Ejecutar C-5 (shell + SQL) | D1 | Operador + DBA | 1 día | No (solo lectura) |
| **U2** | **P2**: respaldo con restauración de prueba | D6, U1 (espacio, versión) | Operador | 60 a 90 min | Sí |
| **U3** | **P3**: código 2B con Usuarios en 403 | U2, B firmada, C4 a C6 | Operador | 30 min | Sí, aparte de todo lote del legado |
| **U4** | **P4a**: vista y privilegios mínimos | U3, U1 SQL (versión, cuenta ausente), respaldo < 24 h | DBA + Operador | 30 a 60 min | Sí |
| **S1** | **Lote 1** (K2a–e) | D2, P-4 a P-8 | Infraestructura | 1 h | Sí |
| **S2** | Verificar uso de K1 y desplegar **K1 «retirar»** | U1 (registros), S1 | Infraestructura | verificación 1 semana + 30 min | Sí |
| **S3** | **Lote 4 mínimo** (F-4, F-5a–c) **+ F-10** según D3 | S1, confirmación del equipo Flutter | Infraestructura | 1 h | Sí |
| **S4** | **Lote 3** (A1) | S3 | Infraestructura | 30 min | Sí |
| **G** | **Puerta de seguridad de P4b** | S1, S2, S3, S4 (o riesgos aceptados por escrito) | Funcional | — | — |
| **U5** | **P4b**: interruptor de Usuarios | U4, G, criterios de suspensión | Operador | 15 min | Sí |
| **U6** | **P4c**: una operación real con su fila de auditoría | U5 | Funcional + Operador | 30 min | Sí |
| **F1** | HTTPS corporativo operativo (VirtualHost 443 por IP privada) | D4 | TI + Operador | 1 a 3 semanas tras D4 | Sí |
| **F2** | Paridad pendiente: perfil propio, foto, indicadores, sustitución del registro público | Decisiones funcionales | Equipo React | 3 a 6 días de desarrollo | Despliegue propio |
| **F3** | Ampliar el piloto 8092 por grupos hasta todos los empleados | F1, D5 | Funcional | 2 a 4 semanas | Por tandas |
| **F4** | Operación en paralelo y medición de uso | F3 | Funcional + Operador | ≥ 4 semanas | No |
| **F5** | Retiro del legado por niveles N1 → N2 → N3 y de Firebase | F4, F2, criterio de retiro | Operador + titular de Firebase | 2 a 4 semanas | Sí |

## 3. Caminos críticos

**Cerrar Usuarios** (el objetivo principal): `D1 → U1 → D6 → U2 → U3 → U4 → G → U5 → U6`. Estimación **4 a 7 semanas**, dominada por las firmas (D6, B) y las ventanas. Los lotes S1 a S4 corren **en paralelo** a U2 y U3 (otro carril, otras ventanas) y solo se unen en G. Lo que más acorta el camino: pedir D1, D2 y D6 **hoy**.

**Retirar Firebase y el legado:** `D4 → F1` y `D5` en paralelo → `F2` → `F3 → F4 → F5`. Estimación **10 a 16 semanas**, dominada por TI (F1), la decisión de R-PUB (D5) y la medición (F4). Firebase es lo último: la función solo se desactiva cuando el legado ya está en N2 y con 0 invocaciones durante el periodo de gracia.

## 4. Reglas de ventanas

1. Una ventana por paso; nunca P3 en la misma ventana que un lote del legado.
2. Excepción decidida: **F-10 va con el Lote 4** (S3), sujeto a D3.
3. Cada ventana lleva respaldo con huella, verificación previa en copia (`verificar-parches-en-copia.sh` para el legado; `--simular` para los kits de React), punto de parada y reversión.
4. Las pruebas de rechazo en producción usan identificadores inexistentes o cuentas y empresas de prueba, **nunca datos reales**.

## 5. Decisiones que cambian el plan

| Decisión | Efecto |
|---|---|
| F-10: opción 1, 2 o 3 | Define cuándo se pausa el alta en Flutter y si hace falta un procedimiento de emergencia del DBA |
| Fecha prevista de retiro > 12 semanas | Se añaden `F-5d/e` y `F-5g` al subconjunto del legado |
| R-PUB: los informes **no** pueden ser públicos | El portal React no es suficiente; hace falta rediseñar el acceso a Power BI antes de ampliar usuarios (F3) |
| TI no puede dar HTTPS corporativo | Flutter no puede retirarse; el legado debe mantenerse parcheado más tiempo |

## 6. Primer paso recomendado

Autorizar C-5 (D1) y firmar P-2/P-3 del Lote 1 (D2) a la vez. Con C-5 se decide K1 «retirar», F-10 y los datos de P2/P4a; con D2 se abre la primera ventana del legado.

## 7. Actualización: opción 1 de F-10 y camino para activar P4b y P4c

Cambios respecto de las secciones anteriores:

| Id | Hito nuevo o modificado | Depende de | Responsable | Estimación |
|---|---|---|---|---|
| **R1** | Revisión previa de C-5 | — | Hecha (`UX-09A-C5-REVISION-Y-EJECUCION.md`) | — |
| **U1** | Ejecutar C-5 con el operador autorizado | D1 (`A-C5`) | Operador + DBA | 1 día |
| **A1** | Aprobar el **procedimiento temporal de altas**: DBA único ejecutor, custodio sin acceso a la base, verificador, canal de entrega (C1 a C8), tiempo de atención, lista de solicitantes y aceptación de R-PWD | R1 (volumen de altas, tras U1) | DBA + Funcional | 1 a 2 semanas |
| **A2** | **Ensayo** del DBA con el generador | A1 | DBA | 1 día |
| **S3** | Lote 4 mínimo (F-4, F-5a–c) **+ F-10** | S1, A1, A2, U1 sin consumidores indispensables (o decisión de continuidad) | Infraestructura | 1 h |
| **S3b** | Si falta A1/A2/U1 el día de la ventana: **solo el Lote 4 mínimo**; F-10 se difiere | — | — | — |
| **U5** | P4b | U4, G, criterios de suspensión | Operador | 15 min |
| **U6** | P4c | U5 | Funcional + Operador | 30 min |
| **A3** | **Retirar el procedimiento temporal** | U6 verificado + 5 días hábiles (tope 60 días desde S3) | DBA + Funcional | 1 día |

**Camino crítico a P4b/P4c:** `D1 → U1 → A1 → A2 → S3 (con F-10) → G → U5 → U6`, en paralelo a `D6 → U2 → U3 → U4`. Dos tramos se unen en P4b: la cadena de **Usuarios en la base** (P2, P3, P4a) y la de **seguridad del legado** (Lote 1, K1, Lote 4 + F-10, Lote 3). Estimación **5 a 8 semanas**, dominada por las firmas (A1, B, D6) y por el ensayo del procedimiento temporal.
**Qué no está en el camino crítico:** F-5d/e/f/g, R-PUB, HTTPS corporativo y la paridad (son del tramo de retiro de Flutter).

**Decisiones de contraseñas que no están en el camino crítico de P4b/P4c** pero conviene tomar con A1: D-PWD1 (reemisión excepcional) y D-PWD2 (diseño del cambio de contraseña propio tras P4c, sin liberar P5). La caducidad del procedimiento (A3) **no** caduca las contraseñas emitidas.

## 8. Actualización UX-09B

El tablero `UX-09B-TABLERO-EJECUCION.md` **reduce** este camino crítico: la puerta de P4b se rehízo en UX-09B (v2): K2, K1, A2, A1 y A3 se cierran antes de P4b (ver `UX-09B-PUERTAS-DE-SEGURIDAD.md`).
