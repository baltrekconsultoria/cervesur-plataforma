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
