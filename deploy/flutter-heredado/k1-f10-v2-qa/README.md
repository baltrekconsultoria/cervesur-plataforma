# Cervesur - Correctivos K1 y F-10 v2

Estado: QA aislado aprobado. NO DESPLEGADO.

K1:
- GET /api/powerbi/tokens/{id} retorna HTTP 410.
- Cierre incondicional, independiente de POWERBI_AUTH_MODE.

F-10:
- POST /api/register retorna HTTP 403.
- Cierre incondicional, independiente de REGISTRO_ABIERTO.

QA reportado:
- 11/11 pruebas funcionales aisladas.
- 3/3 validaciones de sintaxis PHP.
- Aplicacion exacta y reproducibilidad PASS.

Pendiente:
- Regresiones integradas.
- Validacion de consumidores K1.
- Procedimiento alternativo de altas aprobado y ensayado.
- Autorizaciones y ventanas de despliegue.

No aplicar directamente sobre produccion.
