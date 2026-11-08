# Hoja de revisión · A-C5 y A (P2) · para decidir si se firman

No es una firma. Resume qué se aprueba, qué produce, qué no hace y qué verificar antes. Textos de firma: `4-FIRMAS-A-C5-Y-A-P2.md`. Adendo de D4 y entrega de fuentes: `../paquete-d4/SOLICITUD-INFRAESTRUCTURA.md` (A-C5b).

| | **A-C5** | **A-C5b** (adendo) | **A (P2)** |
|---|---|---|---|
| Qué autoriza | Inspección C-5 de solo lectura: script de sistema + SQL del DBA + revisor | D4 (solo lectura), prueba `tokens/0`, entrega de fuentes enmascaradas | Respaldo de 7 tablas + restauración de prueba |
| Firma | Funcional (+ constancia del DBA sobre el SQL) | Funcional | DBA (+ enterado del funcional) |
| Quién ejecuta | Operador (parte 1), DBA (parte 2) | Infraestructura | Quien se nombre (con `sudo`) |
| ¿Escribe? | **No** | **No** (solo su salida) | Solo el esquema temporal de verificación, si se elige |
| Prueba local | 33/33 (`probar-c5.sh`) | D4 17/17, entrega 14/14 | Procedimiento 14/14 |
| Qué desbloquea | K1, F-10, roles, P4a | Casos 1–8 de D3, K1, D4 | P3 y P4a |
| Qué NO autoriza | Escribir, crear cuentas, privilegios, Apache, `.env`, Firebase, P2…P5 | Cambiar nada; desplegar; rotar `JWT_SECRET` | P3, P4, P5, procedimiento de altas, lotes del legado |

## Antes de firmar A-C5 (lista de comprobación)

- [ ] Las huellas coinciden: `sha256sum -c SHA256SUMS` en `paquete-c5/` (**verificado hoy: 3/3 OK**).
- [ ] Están rellenados los nombres del operador, del DBA, las cuentas nominales y el canal de entrega de resultados.
- [ ] El DBA revisó la plantilla SQL (solo `SET` de sesión, `START TRANSACTION READ ONLY`, `SELECT`, `SHOW`, `COMMIT`) y firmó la constancia.
- [ ] Se acordaron los criterios de parada de `3-RESULTADOS-Y-PARADA.md`.
- [ ] Hay una persona que revisa las salidas con `revisar-salida-c5.sh` antes de compartirlas.

## Antes de firmar A (P2)

- [ ] El DBA decidió **dónde se restaura** (esquema temporal del mismo servidor, u otra instancia — preferible).
- [ ] C-5 confirmó espacio libre ≥ 3 × el tamaño de `cervesur_portalbi` y la versión de MySQL.
- [ ] Ventana de 60–90 minutos acordada, con aviso al dueño de Flutter.
- [ ] Persona que ejecuta, persona que observa y lugar de la evidencia nombrados.

## Orden recomendado

1. A-C5 (+ A-C5b) → ejecutar C-5, D4 y entrega de fuentes.
2. Con C-5 en la mano: A (P2), porque C-5 confirma el espacio y la versión.
3. Ninguna de estas firmas autoriza P3, P4a, P4b ni ningún lote.
