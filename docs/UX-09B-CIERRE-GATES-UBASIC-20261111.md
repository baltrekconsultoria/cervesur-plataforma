# Cervesur — Registro de validación previo a P4b (U-BASIC)
**Corte:** 11/11/2026 · **Estado:** para firma / NO-GO de habilitación. Documento de control, no autorización.

## Resultado de verificación
| Puerta | Estado demostrado | Evidencia |
|---|---|---|
| P2 | PASS integridad GZIP + SHA-256; restauración histórica reportada, sin evidencia documental localizada en inspección | Salidas operador 10–11/11 |
| P3 RC1c | PASS, 4/4 archivos backend cotejados | Salida operador |
| P4a | PASS real, `RESULTADO-PRIVILEGIOS: PASS no-sensibles`, vista WITH CHECK OPTION, sin UPDATE(password/role/email) | Verificador oficial RC1c en host |
| K2 Lote 1 | **DESPLEGADO/PASS** 10/11; HTTP 200 y hashes coincidentes; no repetir | Salidas operativas y expediente #13 |
| K1 / A-L2 | **PENDIENTE**: QA aislado 19/19 PASS pero consumidores reales, atribución de 403, cobertura de logs y firma INF/FUN no acreditados | PR #13, INF-01..05 / FUN-01..04 |
| A1 | **PENDIENTE**: parche probado aislado; firma A-L3, desplegado y prueba de humo no acreditados | Puerta P4b v2 |
| A2 | **PENDIENTE**: parche probado aislado; firma A-L4, desplegado y prueba de humo no acreditados | Puerta P4b v2 |
| A3 / F-10 | **PENDIENTE**: 19/19 QA aislado, no instalado; procedimiento temporal de altas sin aprobación/ensayo acreditado por DBA | PR #13 / puerta P4b v2 |
| D4 | **PARCIAL**: PASS 01–06, 06b pendiente, FAIL 07 TTL real 10800 vs 86400 aprobado anteriormente; 08 K1 y 09 F-10 no aplicados | Atestación 10/11, comentarios PR #13 |
| T-EXP Flutter | **PARCIAL/DEFECTO**: Firebase Cloud Functions devuelve 401 interno con HTTP 200; Flutter muestra tablas vacías; no comprobado relogin / revocación universal | Issue #21 |
| P4b interruptor U-BASIC | **OFF / NO-GO**, sin autorización formal de activación evidenciada | Issue #17 |
| P5-R password-only | PR #19 DRAFT, 25/25 PHP y 34/34 React PASS; GRANT password-only, JWT E2E y liberación pendientes | PR #19, #20 |

## Evidencias de terceros / cadena de aprobación
No se han incorporado al expediente GitHub documentos firmados de INF-01..05, FUN-01..04 o A-L2. Una búsqueda no encontró confirmaciones inequívocas en los buzones conectados disponibles; **esto no prueba que no existan** por otro canal o cuenta.

**No completar firmas por inferencia, por capturas de un 401 ni por QA aislado.** Anotar:
- INF-01..05: firmante __ / fecha __ / referencia __ / resultado __
- FUN-01..04: firmante __ / fecha __ / referencia __ / resultado __
- A-L2 (K1): firmante __ / fecha __ / referencia __
- A-L3 (A1): firmante __ / fecha __ / referencia __
- A-L4 (A2/A3): firmante __ / fecha __ / referencia __
- A-ALTA (F-10): procedimiento alternativo DBA + custodio, prueba y firma __
- D4: decisión sobre TTL real 10800 vs 86400 __ y 06b __
- C5-B DBA: atestación SQL de solo lectura __

## Orden de cierre (sin reejecutar pasos ya PASS)
1. **K1:** recopilar respuestas INF-01..05 y FUN-01..04; contrastar K2 operativo estable, confirmar consumidores y A-L2. Solo entonces preparar ventana con respaldo + rollback del router K1.
2. **A1/A2:** completar D3 real/casos relevantes y autorización A-L3/A-L4; ventana por lote, humo Flutter y reversión.
3. **F-10:** DBA/funcional firman y ensayan canal temporal de altas, luego autorización y ventana; mantener retorno 403 del registro público en la futura implementación.
4. **D4:** verificar estado final del secreto y fallbacks 06b sin imprimir valores, resolver formalmente JWT_EXPIRATION y repetir controles únicamente después de K1/F-10.
5. **P4b:** acta de GO firmada, preflight 403 cuando OFF, capacidad sensible OFF, backup/rollback vigente; autorizar activación U-BASIC **sin escritura de usuarios**, y después P4c con permisos por operación.
6. **P5-R:** sólo cuando se pruebe revocación de sesión por PHP + ruta Cloud Functions y recuperación UI, además de DBA password-only. **Nunca cambiar roles.**

**Decisión al corte: NO-GO P4b y P5-R en producción.** GO para cierre documental, validación técnica de alcance y QA aislado. Sin cambios de servicios, MySQL, Apache ni usuarios por este documento.
