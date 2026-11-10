# Cervesur — K1 consumidores: revisión C5 de registros (preliminar)

**Fecha de revisión:** 10/11/2026  
**Estado:** evidencias de solo lectura, **INCONCLUSO / K1 NO-GO**.  
**Origen de evidencia:** agregados de salida proporcionada por operador `usr_cervesur` del host `presupuestos` (sin entradas de log individuales).  
**PR:** #13; paquete de K1/F-10 v2, en borrador.  
**No se ejecutó ningún despliegue de K1**.

## 1. Diagnóstico de acceso

Primera inspección sin privilegios: `/var/log/apache2` existente pero no enumerable por `usr_cervesur` (directorio grupo `adm`, permisos `750`). Un primer conteo de **0 registros** fue inválido por esa causa; **no** se interpreta como cero solicitudes. Revisión posterior con lectura autorizada: **68 archivos encontrados**, **0 logs con error de lectura**.

## 2. Resumen de la lectura autorizada

| Métrica | Valor |
| --- | ---: |
| Registros Apache visibles | 68 |
| Líneas de solicitud procesadas | 4.418 |
| Solicitudes categorizadas como API | 3.345 |
| Coincidencias con subcadena `/api/powerbi/tokens` | 20 |
| Métodos K1 observados | GET: 20 |
| Respuestas K1 observadas | HTTP 403: 20 |
| Familia de cliente informada | Herramienta: 20 |
| K1 por mes | Octubre 2026: 16; noviembre 2026: 4 |
| Entradas no reconocidas por el parser | 34 |
| Lecturas de log con error | 0 |
| Deduplicación | No realizada; conteo de **líneas** |

Distribución K1 por archivos de acceso:
- `cervesur-react-admin-access.log`: 9, periodo visible 29/10–05/11.
- `cervesur-react-usuarios-access.log`: 5, periodo visible 29/10–03/11.
- `cervesur-react-admin-qa-access.log`: 2, periodo visible 03/11–05/11.
- `cervesur-react-compat-access.log`: 1, periodo visible 28/10–03/11.
- `cervesur-react-piloto-access.log`: 1, periodo visible 29/10–03/11.
- `cervesur-react-prueba-access.log`: 1, periodo visible 28/10–03/11.
- `cervesur-react-ro-access.log`: 1, periodo visible 29/10–03/11.
- `cervesur_access.log` (legado): **0 K1**, **0 API** en 169 solicitudes, periodo visible 24/06–04/11.
- `access.log` genérico: **0 K1**, **0 API** en 171 solicitudes, periodo visible 24/04–10/11.
- Los restantes grupos de logs React tampoco mostraron K1.

## 3. Interpretación — lo sustentado y lo pendiente

**Observado:** las veinte coincidencias de K1 aparecen exclusivamente en archivos de VirtualHosts React, con HTTP 403 y familia `Herramienta`. No hay respuestas 200 observadas en esta muestra. La inspección previa del paquete Flutter detectó 26 llamadas a la API y ninguna a esa ruta.

**No demostrado:**
1. La identidad o finalidad de las 20 solicitudes (pueden ser pruebas técnicas, exploraciones o integraciones; no atribuir sin correlación).
2. Que todos los 403 provengan del backend K1: podrían emitirse antes de llegar a él por la configuración del VirtualHost/Apache/middleware.
3. Que se observaron *todas* las solicitudes reales hacia el endpoint heredado: `cervesur_access.log` llega hasta 04/11, y los logs React pertinentes hasta 05/11, en una consulta hecha el 10/11.
4. Que no haya otras vías de acceso, otros dominios, puertos, proxies o historiales no incluidos.
5. Que las veinte líneas representen veinte solicitudes únicas: falta deduplicación/correlación.
6. Que la coincidencia de subcadena del script sea exactamente `GET /api/powerbi/tokens/{id_numérico}`: verificar coincidencia completa frente a variantes de URL.

**Veredicto provisional:** **no se identificó consumo exitoso y atribuible a una integración legítima en los registros analizados**, pero la ausencia de consumidores no está acreditada. **K1 continúa NO-GO** y no debe activarse el HTTP 410.

## 4. Siguientes controles (solo lectura)

1. Clasificar 20 coincidencias por **fecha, VirtualHost, ruta exacta o variante, categoría de User-Agent** y origen privado/público, con salida agregada sin IP, IDs, tokens ni URL completa; relacionar con ventanas conocidas de QA sin atribución automática.
2. Verificar en la configuración Apache si las peticiones al VirtualHost React llegan al backend heredado o reciben HTTP 403 directamente.
3. Identificar dónde efectivamente se registra tráfico de la API heredada en 2026 y cubrir el periodo hasta la fecha.
4. Confirmar con el responsable funcional/infraestructura el inventario de consumidores, integraciones y procesos externos; obtener conformidad para retirar la ruta.
5. Solo después definir la autorización A-L2 y la ventana posterior al Lote 1 K2, con respaldo y reversión.

**No se autoriza fusión, despliegue, cambio de permisos, reinicio o alteración de configuración productiva.**
