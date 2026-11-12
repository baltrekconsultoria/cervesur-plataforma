# UX-09A · K1 — Paquete de validación Infraestructura + responsable funcional

**Fecha de preparación:** 10/11/2026.  
**Estado:** **SOLICITUD PREPARADA, NO ENVIADA NI APROBADA**.  
**Decisión de liberación:** **K1 NO-GO**, pendiente de validaciones y autorización **A-L2**.  
**PR de preparación:** [#13](https://github.com/baltrekconsultoria/cervesur-plataforma/pull/13) (Draft); **no** usar el PR como autorización de despliegue.

## 1. Resumen comprobado

- Endpoint en alcance: **`GET /api/powerbi/tokens/{id}`**, que el lote K1 v2 retiraría devolviendo **HTTP 410 incondicional**. Se debe preservar el acceso funcional a Power BI por los mecanismos vigentes.
- El paquete Flutter desplegado, según revisión técnica previa, tiene **26 llamadas API conocidas; ninguna usa directamente K1**. Eso no acredita ausencia de clientes indirectos/externos.
- Revisión Apache autorizada de 68 archivos: **4.418** líneas de peticiones; **3.345** categorizadas como API. **20 GET exactos K1**, todos con User-Agent `curl`, todos **HTTP 403**, entre el 28/10 y el 05/11/2026, registrados solo en VirtualHosts React/QA.
- **0 HTTP 200 K1 observados** en esa cobertura; **no equivale** a demostrar que no exista consumo legítimo. Los registros del VirtualHost del legado no muestran solicitudes API en la muestra y falta corroborar cobertura/ruta real.
- Las reglas `RewriteRule [F]` existentes en includes de React **no coincidieron** con la ruta K1 en la comprobación estática. **Se retiró** la atribución anterior de los 403 a esas reglas: el componente emisor del 403 sigue indeterminado.
- QA del parche sobre copias verificadas de los routers heredados con dependencias simuladas: **19/19 PASS**. No sustituye pruebas integradas ni validación de consumidores.

**Evidencias:** [Auditoría consumidores K1](UX-09A-K1-CONSUMIDORES-C5-RESULTADO-PRELIMINAR.md) · [QA sobre copia heredada](UX-09A-K1-F10-V2-QA-LEGACY-HTTP-PROCEDIMIENTO.md).

## 2. Solicitud a Infraestructura Cervesur (responsable por confirmar)

**Objetivo:** validar la topología efectiva y el inventario técnico de consumidores de K1, con **inspección de solo lectura**, sin nuevas llamadas de prueba a producción y sin divulgar secretos.

**Respuestas requeridas:**

| ID | Comprobación | Evidencia/respuesta aceptable |
| --- | --- | --- |
| INF-01 | Qué VirtualHost/dominio/puerto y qué cadena de proxy o reescritura **atienden realmente el backend heredado** y `GET /api/powerbi/tokens/{id}` | Descripción de flujo por componentes y configuración revisada, sin credenciales ni dominios sensibles |
| INF-02 | Qué componentes producen los **20 GET curl / 403** registrados en React (o, si no se puede identificar, declaración expresa de no atribución) | Comparación con registros autorizados de QA, bitácora de pruebas y reglas efectivas. No compartir IP completas, tokens ni URLs con identificadores |
| INF-03 | Qué registros cubren **realmente los accesos a K1 heredado**, en qué periodo y si hay vacíos de rotación, otros hosts, proxies o balanceadores | Periodo inicial/final y fuente por componente; señalar archivos sin tráfico y vacíos; incluir, cuando exista, evidencia reciente sin información sensible |
| INF-04 | Si existe consumidor técnico actual (scripts, integraciones, tareas programadas, Flutter indirecto/Firebase, otro sistema) | Inventario de servicio, propósito, dependencia y responsable; o declaración de que no se identificaron tras revisar las fuentes indicadas |
| INF-05 | Dependencia del **Lote 1 K2**, respaldo y rollback de Lote 2 | Confirmar K2 liberado/estable antes de K1; plan de respaldo, ventana independiente y restauración del router desde respaldo (K1 v2 **no** se revierte por constante) |

**Salida solicitada:** `INF-01` a `INF-05` con estado `CONFIRMADO` / `NO CONFIRMADO` / `NO APLICA` (justificado), fuente y fecha, firmados por responsable técnico. No debe adjuntarse configuración privada, logs crudos o secretos al PR.

## 3. Solicitud al responsable funcional del portal Cervesur (por confirmar)

**Objetivo:** determinar impacto de retirar la entrega anónima de tokens y validar alternativas funcionales.

| ID | Confirmación necesaria | Evidencia/respuesta aceptable |
| --- | --- | --- |
| FUN-01 | Inventario de pantallas, perfiles, usuarios, aplicaciones y terceros que **consumen informes Power BI** desde Cervesur y si alguno necesita K1 | Lista de consumidores y flujos funcionales con responsable; no se solicitan cuentas, tokens ni enlaces de publicación |
| FUN-02 | Confirmar si existen procesos con dependencia de `/api/powerbi/tokens/{id}` fuera de las 26 llamadas del Flutter conocido | Declaración de revisión con áreas/integraciones consultadas; registrar pendientes y excepciones |
| FUN-03 | Validación del funcionamiento de los informes por las vías vigentes, **sin usar K1**, y plan de continuidad si se detecta dependencia | Evidencia de prueba funcional controlada en entorno aprobado; plan de migración o decisión de aplazamiento |
| FUN-04 | Conformidad **expresa** sobre impacto esperado de HTTP 410 y aceptación de la ventana propuesta solo cuando INF y K2 estén completos | Aprobación escrita por responsable con fecha; una opinión preliminar no equivale a A-L2 |

**Salida solicitada:** `FUN-01` a `FUN-04` con estado y responsable. Si existe consumidor legítimo sin alternativa, declarar **NO-GO** y proponer plan de continuidad.

## 4. Matriz de decisión para A-L2

| Condición | Estado en 10/11/2026 |
| --- | --- |
| QA K1 v2 sobre copia legacy con stubs | **PASS 19/19** |
| Revisión estática del cliente Flutter conocido | Hecha; no se observó uso directo de K1 |
| Ausencia de consumidores legítimos o migración validada | **PENDIENTE** (Infra + Funcional) |
| Cobertura suficiente de logs backend real y atribución de curl/403 | **PENDIENTE** |
| Lote 1 K2 estable en producción y dependencias cumplidas | **PENDIENTE / NO ACREDITADO EN ESTE INFORME** |
| Regresión integrada del backend + plan respaldo/reversión de ventana | **PENDIENTE** |
| Autorización individual `A-L2` por responsable autorizado | **NO EMITIDA** |

**Regla de salida:** sólo se eleva propuesta de autorización A-L2 cuando no existan consumidores sin solución, Infraestructura confirme cobertura/flujo, K2 esté cerrado y existan respaldo, reversión, humo y ventana. Si alguna condición falta, **NO-GO**.

## 4b. Actualización del prerrequisito K2 — evidencia operativa del 10/11/2026

**K2 ya fue desplegado y verificado por el operador**, no está pendiente de instalarse:

- `K2_PREFLIGHT=PASS`, `K2_RESPALDO=PASS`, `K2_CODIGO_PREPARADO=PASS`, `K2_POST_HTTP=200`, `K2_SHA_PRODUCTIVO=PASS`, `K2_DESPLIEGUE=PASS`.
- Respaldo registrado: `/var/backups/cervesur-legado/lote1-20261110T200521Z`.
- `backend/router/EmpresaRouter.php` SHA-256 productivo: `0c710e8ef8e5f367dcab54ba57a39083e9e2c997a799359a41062137290177b7`.
- `backend/lib/empresa_sanitizer.php` SHA-256 productivo: `cf680885963d6d518e09995f9b1f4b2f7e4105a7aa7f0ec64db319c36b59f77e`.

**Consecuencia:** el bloqueo K2 *instalación* se declara **PASS** y no se vuelve a aplicar. INF-05 conserva solo la comprobación de **estabilidad/continuidad y plan de respaldo/reversión K1**; no es solicitud para repetir el despliegue K2.

**Permanecen pendientes y NO deben fingirse aprobados:** INF-01..04 (cadena real de ruta/K1, origen de 403 y cobertura de logs, consumidores), FUN-01..04 (consumidores funcionales y continuidad Power BI), A-L2 firmada y ventana K1. El único HTTP 401 observado posteriormente en Firebase/Flutter no valida consumidores K1 ni es respuesta 410.

**D4 al 10/11/2026:** PASS D4-01..06; pendiente D4-06b (9 literales heredados), FAIL D4-07 (`JWT_EXPIRATION=10800` efectivo vs `86400` anterior), FAIL D4-08 (K1 no aplicado) y FAIL D4-09 (F-10 no instalado). No rotar secretos ni cambiar expiración sin autorización.

## 5. Textos para solicitar validación (borradores — no enviados)

### Solicitud a Infraestructura

**Asunto:** Cervesur · K1 — Confirmación de ruta, consumidores y logs antes de retirar endpoint Power BI

Equipo de Infraestructura:

Estamos preparando el retiro controlado de `GET /api/powerbi/tokens/{id}` (K1). El correctivo ya superó pruebas aisladas, pero no se ha desplegado.

En los registros analizados se identificaron 20 GET con `curl`, todos HTTP 403 y registrados en VirtualHosts React/QA. No se ha demostrado aún la causa de los 403 ni la cobertura del tráfico real al backend heredado.

Solicitamos su validación documentada de los puntos **INF-01 a INF-05** de este expediente: ruta y capa que atiende K1, atribución de solicitudes, cobertura y periodos de logs, consumidores técnicos vigentes, y dependencia de K2 más plan de reversión.

La revisión debe hacerse en **solo lectura**. Por favor no adjuntar secretos, logs crudos ni identificadores sensibles. Tampoco ejecutar cambios productivos.

Con su conformidad técnica coordinaremos la validación funcional y, posteriormente, la solicitud formal A-L2.

### Solicitud al responsable funcional

**Asunto:** Cervesur · K1 — Validación funcional de consumidores Power BI antes del retiro

Estimado equipo responsable del portal Cervesur:

Estamos evaluando retirar una ruta antigua que entrega tokens Power BI: `GET /api/powerbi/tokens/{id}`. El correctivo hará que esa ruta responda HTTP 410; **todavía no está activado**.

La revisión del Flutter desplegado no detectó uso directo de esta ruta entre sus 26 llamadas conocidas. Aun así, necesitamos descartar integraciones externas, procesos automáticos u otros consumidores.

Solicitamos confirmar los puntos **FUN-01 a FUN-04** de este expediente: qué aplicaciones y perfiles usan Power BI en el portal; si alguno depende de esta ruta; cómo seguirían funcionando los informes sin ella; y su conformidad escrita sobre el impacto esperado.

Si existe alguna dependencia, indíquenla para definir una alternativa antes de cualquier retiro. Sin esta revisión, el cambio permanecerá bloqueado.

## 6. Registro de respuestas (rellenar sólo con respuesta de responsables)

| Clave | Estado | Responsable | Fecha | Evidencia o referencia | Observaciones |
| --- | --- | --- | --- | --- | --- |
| INF-01 a INF-05 | PENDIENTE | Por designar | — | — | — |
| FUN-01 a FUN-04 | PENDIENTE | Por designar | — | — | — |
| A-L2 | NO EMITIDA | Por designar | — | — | K1 NO-GO |

**Este documento no constituye autorización.** No fusionar PR, habilitar 410, modificar Apache, reiniciar servicios ni tocar producción.
