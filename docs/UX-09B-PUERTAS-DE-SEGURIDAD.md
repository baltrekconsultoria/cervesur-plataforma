# UX-09B · Puerta de seguridad de P4b (versión 2)

Estado: **propuesta para aprobación. Nada se ejecutó en producción.** Sustituye a la versión 1, que aplazaba A1/A2/A3 hasta 14 días con aceptación de riesgo; **esa propuesta fue rechazada** y se retira. Esta versión no reduce controles por una fecha: cierra K2, K1, A2, A1 y A3 **antes** de P4b.

## 1. Qué se cierra antes de P4b

| Puerta | Qué cierra | Parche (ya probado) | Firma |
|---|---|---|---|
| **K2** | Secreto de Power BI legible/alterable | Lote 1 (K2a–e) | A-L1 |
| **K1** | Token OAuth anónimo | Lote 2, modo `retirar` (410) | A-L2 |
| **A2** | Escrituras sin administrador | Lote 4 mínimo (F-4, F-5a–c) | A-L4 |
| **A1** | Perfiles ajenos | Lote 3 (F-3) | A-L3 |
| **A3** | Registro público | **F-10**, con el Lote 4 | A-L4 + condiciones de §3 |

Opcionales (defensa en profundidad, no bloquean): F-5d–g.

## 2. Aislamiento de rutas: dónde sustituye al parche y dónde no

El Flutter desplegado (huella idéntica a producción) tiene 26 llamadas; ninguna usa las rutas de la columna «Aislar».

| Ruta | ¿La usa Flutter? | Acción | Riesgo |
|---|---|---|---|
| `GET /api/powerbi/tokens/{id}` | No | **Aislar = K1 «retirar» (410)** | K1 |
| `GET /api/wiki`, `GET /api/stats`, `POST /api/user/avatar` | No | Aislar (opcional, recorta superficie) | Divulgación |
| `PUT /api/user/{id}/role` | No | Ya bloqueada en React (P5); en el legado queda solo admin | Rol |
| `/api/empresas*`, `/api/register`, `/api/user/profile/{id}`, escrituras de reportes y accesos | **Sí** | **No se aíslan: se parchean** | K2, A3, A1, A2 |

**Conclusión:** el aislamiento sustituye al parche solo en K1. Para K2, A1, A2 y A3 aislar rompería a Flutter, y una lista de orígenes no sirve (las llamadas salen de una función de Firebase sin IP fija). Por eso el resto va por parche con guardia de rol.

## 3. F-10 (registro público) — condiciones

Se despliega **con el Lote 4**, y solo si, a la fecha de la ventana:

1. El procedimiento temporal de altas (DBA único ejecutor) está **firmado** (A-ALTA).
2. Está **ensayado** (`probar-alta-temporal.sh` 23/23 y un ensayo del DBA con datos sintéticos).
3. Está **disponible** (DBA y custodio nombrados, canal de solicitud operativo).
4. C-5 no muestra consumidores legítimos de `POST /api/register` distintos del alta de administrador de Flutter, o hay decisión de continuidad para ellos.

Si alguna falla, **F-10 no se despliega y A3 sigue abierta con P4b bloqueado**: no se abre P4b con el registro público abierto. No se espera a P4c; el alta de React sustituirá después al procedimiento temporal.
Reversión: constante `REGISTRO_ABIERTO` (o restaurar `auth.php` desde el respaldo).

## 4. Demostraciones exigidas (criterio de aceptación de la puerta)

| # | Demostración | Evidencia | Estado |
|---|---|---|---|
| D1 | Rutas bloqueadas / guardadas | Parches versionados, `run_v6.php` 354/354, suite de lotes 196/196, cadena 20/20 | **[E] local hecho** |
| D2 | Consumidores legítimos siguen (26 llamadas de Flutter) | `run_v6` recorre las llamadas del cliente con sesión admin; K2d devuelve el mensaje funcional que Flutter muestra | **[E] local hecho**; en producción: humo del responsable de Flutter tras cada lote |
| D3 | Un usuario normal no puede eludir | `UX-09B-D3-NO-ELUSION.md`: legado (routers y parches reales) **154 PASS / 0 FAIL / 8 INCONCLUSO**, 1 872 peticiones; RC1c (pila real con MySQL) **51/51** + matriz de sesión | **[E] local hecho, PARCIAL**: sin fallos; 8 INCONCLUSOS por middleware, `index.php` y controladores reales del legado ausentes (se cierran en la ventana, con la copia de seguridad) |
| D4 | El rol del legado sale del JWT: el secreto no es el literal | Atestación de infraestructura: `JWT_SECRET` definido, distinto del literal por defecto, longitud adecuada; valor de `JWT_EXPIRATION` | **[P] falta evidencia**; si el secreto fuera el literal, A1/A2 son eludibles con un token forjado y la puerta **no cierra** |
| D5 | Verificación en producción | `verificar-parches-en-copia.sh` (hash por archivo) + humo funcional + C-5 parte 1 posterior | Por lote, en su ventana |
| D6 | Reversión | Restaurar el archivo desde el respaldo de la ventana; hash de vuelta al original | Ensayada en copia; en producción, en cada ventana |

**D4 es el único vector que podría invalidar el cierre**, y no depende de código: sin la atestación, no se declara A1/A2 cerrados. D3 lo refuerza: la identidad es el `user_id` firmado, de modo que quien tenga `JWT_SECRET` puede acuñar cualquier sesión. Además, **K1 solo queda cerrado si `POWERBI_AUTH_MODE=retirar`**: en el modo por defecto `observar` el token anónimo se sigue entregando (D3, G5); la atestación de ese valor es parte de la puerta.

## 5. Veredicto

| Elemento | Veredicto | Para pasar a GO |
|---|---|---|
| Puerta K2 | NO-GO hoy | P-2/P-3 firmados, ventana, humo de Flutter |
| Puerta K1 | NO-GO hoy | C-5 sin uso legítimo, A-L2 |
| Puerta A2 + A1 | NO-GO hoy | Lote 1 estable, A-L4/A-L3, **D3 completado con los archivos reales en la ventana (INCONCLUSOS 1, 2, 3, 6, 7, 8)** y D4 (atestación JWT) |
| Puerta A3 (F-10) | NO-GO hoy | Las 4 condiciones de §3 |
| **P4b** | **NO-GO** | Las cinco puertas cerradas y verificadas, P4a verificado, autorización expresa |

## 5b. Actualización D3/D4 (cierre)

Detalle en `UX-09B-D3-CIERRE-Y-P4B.md`.

- **D3**: 0 FAIL; los 8 INCONCLUSOS siguen abiertos por falta de los archivos reales (`auth_middleware.php`, `index.php`, `EmpresaController`/`EmpresaModel`, `UserModel`, `StatsRouter`, `wiki.php`, `db.php`). Lista exacta de entrega en la sección 2 del cierre.
- **Hallazgo nuevo (texto literal de producción + `jwt.php` real)**: el rol sale del token y un token sin `session_version` se acepta; una baja o revocación funciona para toda sesión del login actual, pero un cambio de rol no surte efecto hasta que el token caduque. Para P4b no se exige parchear el middleware, sí D4 y los controles compensatorios del cierre (§4).
- **D4**: paquete de atestación de solo lectura probado 17/17; **ejecución pendiente de Infraestructura**; falta además decidir el valor previsto de `JWT_EXPIRATION`.
- **K1**: la condición es `POWERBI_AUTH_MODE=retirar` **atestado** (D4-08 + prueba funcional D4-08b: `GET /api/powerbi/tokens/0` → 410). `observar` no basta.
- **F-10**: no se despliega sin procedimiento alternativo firmado y ensayado por el DBA (hoy: probado en local 23/23, sin firma ni ensayo).

## 6. Qué no se toca

Autorización de Usuarios en el servidor (U08–U13), límites del motor (la cuenta de React no escribe rol, contraseña ni correo), auditoría atómica y criterios de suspensión.
