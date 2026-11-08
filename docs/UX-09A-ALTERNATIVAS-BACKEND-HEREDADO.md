# UX-09A · Backend heredado de Flutter: corregir y retirar (A) o completar React y retirar (B)

Estado: **análisis para decisión. No se ejecutó nada en ningún servidor.** Base: diagnóstico del backend Flutter v6.4 (fuera del repositorio, `inspeccion-flutter/`), paquete de correctivos probado (354/354 + 196/196 + cadena 20/20) y `docs/UX-08I-*`. Las duraciones son **estimaciones** del autor; el calendario lo dominan las firmas, las ventanas y TI, no el desarrollo.

## 1. Qué se compara

- **A.** Corregir temporalmente el backend heredado por lotes (todos los preparados) y después retirarlo.
- **B.** Completar React (HTTPS, paridad, apertura a todos) y retirar de forma controlada el backend heredado, **sin** desplegar correctivos que dejarían de ser necesarios.
- **Recomendada: híbrida (B estratégica + subconjunto mínimo de A).** Solo se despliegan los correctivos que cierran riesgos **críticos o altos baratos** y que siguen importando hasta el retiro (sección 4). Se descartan los que son defensa en profundidad.

## 2. Comparación

| Aspecto | A · Corregir por lotes y luego retirar | B · Completar React y retirar | Híbrida (recomendada) |
|---|---|---|---|
| **Esfuerzo de desarrollo restante** | Casi nulo: lotes 1 a 4, K1 «retirar» y F-10 ya están preparados y probados. Esfuerzo = ventanas y coordinación (≈ 0,5 día de infraestructura por ventana, 7 a 8 ventanas) | Medio: paridad pendiente (perfil propio, foto, indicadores: ≈ 3 a 6 días de desarrollo **si** se aprueban), generalizar el piloto a todos los usuarios, retirar el sitio. TI para HTTPS | Bajo: 4 ventanas del legado + el trabajo de B |
| **Tiempo (estimado)** | 3 a 6 semanas hasta cerrar la puerta de P4b si las firmas fluyen | 8 a 14 semanas hasta retirar Flutter (HTTPS corporativo 1 a 3 semanas, decisión R-PUB, ≥ 4 semanas de operación en paralelo) | Puerta de P4b en 2 a 4 semanas; retiro en 8 a 14 semanas |
| **Dependencias** | Firmas P-2 a P-8 por lote, ventanas, cuentas de prueba, `A-LOG` para K1, confirmación del equipo Flutter | TI (nombre interno, certificado, cortafuegos), decisión escrita R-PUB, P2 a P4c de Usuarios, capacitación y medición de uso | Las de ambas, solo para el subconjunto |
| **Riesgos** | Se toca producción de usuarios actuales ocho veces para código que se descartará; 8 de 13 archivos parcheados no pueden publicar huella resultante (base solo literal, se verifica con copia y `patch -F0`); F-10 detiene el alta | **Los riesgos críticos del legado (K1, K2) siguen abiertos durante meses**: inaceptable por sí solo; si TI o R-PUB se retrasan, el plazo se alarga sin techo | Menos intervenciones; mantiene cerrados K1, K2, A2, A1, A3 mientras exista el legado |
| **Impacto en usuarios actuales** | Bajo a moderado: un no administrador que escriba recibe 403 (ya declarado inaceptable por el negocio); edición de secretos rechazada; **F-10 cierra el alta de cuentas en Flutter (pública y de administrador)** | Ninguno hasta el retiro; luego cambio de aplicación (formación) | Igual que A en el subconjunto; cambio de aplicación al retirar |
| **Pruebas requeridas** | Suites v6 (354) + K2 (196) + cadena (20) + verificación en copia + humo por ventana | Las existentes de React (CI 1752/0, vitest 299, ensayo Docker 235/0) + QA por grupos + métricas de uso | Ambas, acotadas |
| **Requisitos para habilitar P4b** | Lotes 1, 4 y 3 + K1 resuelto + F-10 decidido | B **no cierra nada** del legado: P4b exigiría aceptar por escrito K1/K2/A1/A2/A3 hasta el retiro | Lotes 1, 3 y 4 mínimo (F-4, F-5a a F-5c) + K1 «retirar» + F-10 ordenado respecto del alta (ver `UX-09A-F10-REGISTRO-PUBLICO.md`) + P4a verificado |

## 3. Por qué no A completa ni B pura

- **B pura** deja abiertos K1 (token de Azure sin autenticación) y K2 (secreto de empresa a cualquier sesión) durante todo el plazo de B. Un cierre barato y ya probado no debe esperar a una migración de semanas.
- **A completa** gasta ventanas en defensa en profundidad que no cambia el riesgo real una vez puestas las guardias de administrador: `F-5d/e` (autoría de `Reporte`) y `F-5g` (validar destinos activos) solo afectan a administradores; `F-5f` duplica la guardia del router. Esos parches se mantienen **preparados y probados** pero **no se despliegan** salvo que el retiro se aplace (regla de la sección 5).

## 4. Subconjunto mínimo que se despliega (híbrida)

| Orden | Cambio | Riesgo que cierra | Por qué sigue valiendo hasta el retiro |
|---|---|---|---|
| 1 | **Lote 1**: K2a + K2c + K2d + K2e | Secreto de Azure legible por cualquier sesión (crítico) y alta de empresas con credenciales propias | No se puede esperar al retiro; cambio en un router |
| 2 | **K1 «retirar»** (410, sin tocar base ni Azure) | Token OAuth de Azure a cualquiera (crítico) | El Flutter desplegado no usa la ruta (C-4); prerrequisito: verificar uso con `c5-solo-lectura.sh` |
| 3 | **Lote 4 mínimo**: F-4, F-5a, F-5b, F-5c | Escrituras de no administradores en 5 routers (alto; el negocio ya las declaró exclusivamente administrativas) | Son 15 bloques de guardia que el baseline ya tenía |
| 4 | **Lote 3**: F-3 (A1) | Cualquier sesión lee y edita el perfil de otra (incluido el correo, con el que se inicia sesión) | Siete líneas; sin él el diseño de React (correo no editable) queda eludido por el legado |
| 5 | **F-10**: registro cerrado | Cuentas creadas sin administrador (A3) | Ver el documento de F-10 sobre **cuándo** (detiene el alta en Flutter) |

## 5. Regla para ampliar el subconjunto

Si al llegar la puerta de P4b la fecha prevista de retiro del legado supera las **12 semanas**, se añaden `F-5d/e` y `F-5g` (autoría y destinos de asignaciones). Si es menor, se dejan sin desplegar.

## 6. Cuándo se considera «retirado» el backend heredado

No basta con parchear ni con que una ruta responda 403/410. El retiro se declara por niveles, cada uno con evidencia:

| Nivel | Estado | Evidencia exigida |
|---|---|---|
| N0 | **Parcheado** | Parche aplicado y huella verificada. **No es retiro** |
| N1 | **Congelado** | Escrituras con 403 y solo lecturas; registros de Apache sin escrituras exitosas durante el periodo acordado |
| N2 | **Apagado** | Sitio del puerto 80 deshabilitado o `/api` heredado denegado; **peticiones desde la red interna y desde fuera dan error de conexión, 403 o 404**; registros de Apache con 0 llamadas a `/api` heredado durante ≥ 14 días; ningún proceso, tarea programada (`cron`) ni servicio que invoque el backend; función de Firebase desactivada y **0 invocaciones** en el mismo periodo; ninguna regla de red (NAT, cortafuegos, DNS) que lo publique |
| N3 | **Archivado** | Copia del código, configuración (sin secretos) y huellas guardadas; privilegios de la cuenta MySQL de Flutter revocados (con el DBA) y sin conexiones nuevas; secretos expuestos rotados (D-5); proyecto Firebase y claves retirados tras el periodo de gracia |

Hasta N2 no se elimina nada; hasta N3 no se rotan ni se borran credenciales.

## 7. Recomendación de ejecución

1. Autorizar la inspección **C-5** (`deploy/auditoria/c5-solo-lectura.sh` y `c5-dba-solo-lectura.sql.template`): decide K1 «retirar», el momento de F-10 y destraba P2/P4a.
2. Firmar P-2/P-3 del Lote 1 y pedir su ventana.
3. En paralelo, P2 y los pedidos de TI (HTTPS corporativo) y de R-PUB.
4. Seguir `docs/UX-09A-CAMINO-CRITICO.md`.
