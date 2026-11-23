# ACTUALIZACIÓN V0.2 — 23/11/2026 (vigente para revisión)

**Esta sección sustituye la estrategia de 65 lotes y concurrencia fija de tres descrita en la versión histórica que aparece más abajo.** La versión 0.1 se conserva exclusivamente para trazabilidad; no activar sus ventanas.

## Reconciliación de ubicaciones

- Matriz histórica del 11/11: 169 informes de referencia, no 169 GUID de modelos confirmados. Cuenta 1: 71; Cuenta 2: 98.
- La ruta operativa por registro se compone de cuenta, workspace, carpeta exacta (Raíz / BOTON_UNICO_CENTROS / BOTON_UNICO_CUENTAS) y nombre del informe/modelo.
- La cuenta 1 contiene 61 registros en el workspace principal (40 raíz, 16 cuentas, 5 centros) y 10 registros en workspace piloto (raíz). **Verificar si los diez siguen en su ubicación productiva real.**
- La cuenta 2 contiene 98 en su workspace principal (60 raíz, 20 cuentas, 18 centros).
- Captura de 23/11: algunos modelos CENTROS_ figuran directamente en la Raíz del workspace; por tanto, no presuponer que estén todos en carpetas.
- Los IDs exactos de modelos semánticos no constan en la matriz fuente: confirmar antes de programar.

## Lotes de uno a cinco y política de capacidad

- Objetivo semanal: cubrir cada modelo conciliado al menos una vez por semana; ejecución en franjas nocturnas 21:00–05:00 (lunes-jueves), y de viernes 21:00 a lunes 05:00 de forma continua.
- Máximo **1–5** simultáneos, calibrado por carga. Los modelos multiempresa de 3–4 horas quedan individuales hasta QA.
- Parámetros PROVISIONALES: G1/CREDITEX 90 min y 3 simultáneos; G2/TEXGROUP 45 min y 4; G3/CREDITEX+TEXGROUP 180 min y 1; G4/5 empresas 180 min y 1; G5/4 empresas 240 min y 1; G6/PROAGRO 30 min y 5; G7/TRANSALTISA 20 min y 5; G8/ALPROSA 45 min y 4.
- **G6=30 minutos es un escenario, NO un dato histórico validado.** G2, G5 y G8 tampoco tienen mediciones fiables. El máximo de cinco no se ha probado; el ensayo manual exitoso del 20/11 fue de cuatro modelos simultáneos después de uno individual.
- Agrupar por misma cuenta + workspace para simplificar la navegación. Carpetas mixtas dentro del mismo workspace se enumeran modelo por modelo en el Excel privado.
- Nueva simulación **53 lotes**: G1=16, G2=4, G3=7, G4=3, G5=1, G6=12, G7=6, G8=4. **4.640 minutos (77h20m)**, reserva **640 minutos (10h40m; 12,1%)** sobre las 88 h semanales; cuatro noches de 430 min y fin de semana de 2.920 min. No representa un horario real ya habilitado.
- La duración estimada de un lote paralelo usa MAX(minutos individuales) + reserva 5 o 10 min. Es una hipótesis de ausencia de contención de Oracle; validar con medición real, especialmente con cuatro o cinco conexiones.

## Excel operativo (entrega privada, no adjuntar al GitHub público)

- Inicio: cobertura, capacidad y riesgos.
- Rutas_169: cuenta/correo, workspace, carpeta, nombre, enlace al workspace y QA por registro; distingue ubicaciones documentales históricas de las observadas.
- Lotes_53: de 1 a 5, fecha/hora Lima simulada, cuenta/workspace/carpeta(s), nombres, tiempo máximo, reserva y alerta de exceso de ventana.
- Parametros: tiempos estimados y capacidad máxima editables. Introducir duración real por registro en Rutas_169; sus fórmulas ajustan capacidad, **pero modificar máximo simultáneo exige rehacer agrupación**.
- Ventanas / Piloto / Guia: capacidad temporal, evidencia de errores ORA-03113/ORA-12170 y proceso de revisión.

## Puertas antes de activar

1. Conciliar las 169 ubicaciones con Power BI Service; verificar los 10 registros del workspace piloto y los cinco horarios previamente activos.
2. Completar mediciones reales por empresa/modelo (incluidos G6), comprobar incidentes nocturnos y capacidad de 5 concurrentes.
3. Recalcular capacidad reservando mínimo 10% de contingencia y sin sobrecupo en ninguna ventana.
4. Habilitar de forma progresiva solo tras validación técnica de Oracle y aprobación de programación; registrar tiempos y frescura después de cada lote.

**Seguridad:** no subir la matriz original al repositorio público: contiene columnas de contraseñas y otras referencias de acceso. Evitar credenciales, IDs individualizados y enlaces de acceso en la documentación pública.

---

## HISTÓRICO V0.1 — SUPERADO POR EL PLAN ADAPTATIVO DE ARRIBA
# Cervesur — Plan de actualización Power BI (169 informes)

**Estado:** borrador de planificación; sin cambios en Power BI, Oracle EBS, gateway ni producción.  
**Fecha de corte:** 23 de noviembre de 2026.  
**Responsabilidad:** validación técnica de conectividad/capacidad y validación funcional de cierre contable antes de habilitar los lotes.

## 1. Alcance y límites

El inventario histórico del 11/11 contiene **169 registros de informes**: 78 de familia *Centros* y 91 de *Cuentas*, repartidos entre dos cuentas de publicación (71 y 98). El inventario no confirma por sí solo que existan 169 modelos semánticos únicos ni que todas las vinculaciones actuales de Power BI Service coincidan con la matriz. **La conciliación de identificadores modelo↔informe es un requisito previo.**

Este documento solo cubre las decisiones, hipótesis, evidencia del piloto y puertas de QA. La planificación operativa nominal detallada se conserva **fuera del repositorio público** por incluir nombres de informes, estructura interna de publicación y ventanas individuales. **No adjuntar la matriz original:** contiene campos de credenciales y datos de acceso. No publicar contraseñas, correos de servicio, enlaces individuales, detalles internos de servidores, tokens ni registros completos.

## 2. Evidencia del piloto (19–20/11/2026)

- **Jueves 19/11:** primer lote de cinco modelos configurado para las 21:00 (zona horaria de Lima, UTC−05). Los cinco intentos programados reportaron error entre las 21:13:48 y las 21:14:30. Dos casos **ORA-03113** (conexión interrumpida), tres **ORA-12170** (timeout Oracle Net), todos comunicados a través del origen ODBC.
- **Viernes 20/11:** el usuario comprobó que un modelo se actualizó manualmente y luego **los cuatro restantes pudieron actualizarse simultáneamente**. Resultado informado: **5/5 satisfactorio** en pruebas manuales.
- El éxito manual **no** identifica la causa raíz del fallo programado. El problema podría estar asociado a una condición temporal de red, Oracle, gateway o concurrencia. No afirmar saturación sin registros de la ventana del error.
- Pendiente: recopilar hora de inicio/fin, duración de cada actualización manual y evidencia de frescura de datos; revisar registros históricos del gateway y Oracle con el responsable técnico.
- Ya existen **cinco programaciones semanales previas** para el jueves siguiente. Deben reconciliarse antes de activar un calendario nuevo, especialmente porque fueron creadas para comenzar al mismo horario.

**Decisión:** QA funcional manual favorable; operación programada nocturna pendiente de validación. No cerrar la causa raíz como resuelta.

## 3. Reglas de programación propuestas

1. Objetivo: que cada informe/modelo reconciliado se actualice **al menos una vez por semana**, sujeto a validación de capacidad real y de la oportunidad funcional respecto de cierres contables.
2. **Lunes a jueves:** de 21:00 a 05:00 hora de Lima (ocho horas cada noche). El límite de 05:00 es conservador; ampliar hasta 06:00 requiere coordinación técnica.
3. **Fin de semana:** desde viernes 21:00 hasta lunes 05:00, de forma continua, incluyendo sábado y domingo completos.
4. Capacidad de ventana nominal: **4 × 8 h + 56 h = 88 h por semana**.
5. En modelos livianos/medios, lotes de hasta **3 actualizaciones concurrentes**. En modelos de dos o más empresas con carga alta, **1 sola actualización activa** mientras se mide su duración.
6. Evitar lanzar múltiples lotes si quedan procesos anteriores activos; la duración programada no garantiza duración real.
7. Configurar alertas técnicas, revisión del historial de actualizaciones, registro de resultado por modelo y reintento controlado solo después de clasificar el error. No habilitar reintentos masivos automáticos.
8. Considerar un margen real de contingencia (objetivo inicial: ≥10 % de la ventana semanal) antes de declarar factible la cobertura completa.

## 4. Distribución de la matriz por grupo SQL

| Grupo | Tipo de origen | Informes | Modalidad provisional | Minutos base por lote / modelo | Evidencia |
|---|---|---:|---|---:|---|
| G1 | CREDITEX | 45 | Hasta 3 simultáneos | 90 | Referencia aproximada comunicada |
| G2 | TEXGROUP | 15 | Hasta 3 simultáneos | 45 | **Hipótesis sin medición** |
| G3 | CREDITEX y TEXGROUP | 7 | Individual | 180 | Referencia aproximada de dos empresas |
| G4 | Cinco empresas | 3 | Individual | 180 | Referencia aproximada de cinco empresas |
| G5 | Cuatro empresas | 1 | Individual | 240 | **Hipótesis sin medición** |
| G6 | PROAGRO | 57 | Hasta 3 simultáneos | 45 | **Hipótesis sin medición** |
| G7 | TRANSALTISA | 28 | Hasta 3 simultáneos | 20 | Referencia aproximada comunicada |
| G8 | ALPROSA | 13 | Hasta 3 simultáneos | 45 | **Hipótesis sin medición** |
| **Total** | | **169** | | | |

La distribución propuesta crea **65 lotes**: 11 individuales de alta carga y 54 de hasta tres modelos. Para la simulación se agregaron **10 minutos de separación por lote pesado** y **5 minutos por lote restante**. Estas separaciones no son un margen suficiente ante incidentes, esperas de cola, ejecuciones prolongadas o recuperación.

### Prueba de capacidad

- Con G2/G6/G8 en **45 min** estimados, los 65 lotes ocupan **87 h 55 min** de las **88 h** disponibles. Quedan **5 minutos nominales**: **NO APTO PARA ACTIVAR**.
- Con G2/G6/G8 en **60 min**, se proyectan **95 h 10 min**: sobrecarga de **7 h 10 min**, antes de un margen de contingencia adicional.
- Con G2/G6/G8 en **90 min**, se proyectan **109 h 40 min**: sobrecarga de **21 h 40 min**.
- Con una reserva operativa objetivo de 10 %, solo quedarían 79 h 12 min para ejecución. La estimación favorable de 87 h 55 min la supera en **8 h 43 min**.

Estas cifras son **escenarios de diseño**, no pruebas de tiempos observados. Pueden cambiar al conciliar modelos y medir duración real. Un calendario nominal que termina a las 05:00 sin margen tampoco garantiza que la información esté lista a esa hora.

## 5. Etapas y puertas de aprobación

**P0 — Conciliación y medición (sin escrituras):**
- Comprobar mapeo de los 169 informes con modelos semánticos y credenciales/orígenes asociados, y detectar reutilización de modelos.
- Registrar inicio, fin y duración real de las cinco actualizaciones manuales completadas el 20/11.
- Muestrear tiempos por grupo y tamaño de modelo; buscar históricos comparables para estimación mediana y P95.
- Revisar los cinco fallos del 19/11 en registros de gateway y Oracle, sin modificar el origen de datos.
- Reconciliar los cinco horarios existentes para el jueves 26/11.

**P1 — Ajuste de lotes (plan de revisión):**
- Sustituir hipótesis de 45/240 min por tiempos observados y añadir margen.
- Mantener lotes pesados individuales hasta demostrar operación estable.
- Demostrar que el total cabe en la ventana semanal, o presentar reducción de duración, un calendario alternativo o excepción documentada.
- Coordinar prioridades con cierres contables y responsable de Oracle.

**P2 — Activación controlada (requiere aprobación operativa separada):**
- Programar un pequeño conjunto validado; registrar evidencias y alertas.
- Revisar inicio/fin del historial y frescura de los informes, no solo el estado de tarea.
- Avanzar por tandas y detener expansión ante errores repetidos, carga excesiva o pérdida de acceso.

**P3 — Operación semanal:**
- Consolidar métricas: modelos previstos, completados, fallidos, duración P50/P95, atraso respecto a ventana y acciones correctivas.
- Documentar incidencias y cambios de programación en control de versiones; nunca registrar credenciales o datos sensibles.

## 6. Registro de decisiones y riesgos

| Asunto | Decisión / situación |
|---|---|
| Frecuencia | Meta semanal de cobertura, pendiente de validación de capacidad y de coordinación por cierre contable |
| Concurrencia | Hasta 3 para livianos/medios; individual para carga pesada |
| Noche / fin de semana | 21:00–05:00 días laborales, fin de semana completo desde viernes 21:00 |
| Conexión | Piloto manual OK; incidente programado con errores Oracle no diagnosticado |
| Medición | Referencias históricas mezcladas con hipótesis claramente rotuladas |
| Seguridad | No cargar XLSX fuente, credenciales, URLs, nombres individuales de informe ni mapa operativo detallado al repositorio público |
| Estado | Documentado; **sin autorización de activación masiva** |

## 7. Próxima revisión

Comenzar por los tiempos reales del piloto y por un muestreo de los grupos G2/G6/G8, que concentran **85 informes** y condicionan la viabilidad. Solo entonces emitir la versión operativa del calendario semanal.
