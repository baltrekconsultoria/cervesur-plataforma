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

## 5. Segundo desglose — ruta exacta, herramienta y tiempo (operador, 10/11/2026)

El operador ejecutó una segunda lectura de solo lectura sobre los registros accesibles de Apache con clasificación de ruta, agente y hora. Resultado confirmado en la salida:

- `K1_TOTAL=20`, `K1_RUTAS_EXACTAS=20`, `K1_RUTAS_VARIANTES=0`.
- 20/20 `GET`; 20/20 `HTTP 403`; 20/20 `curl`.
- Sólo VirtualHosts React y QA; **ninguna línea registrada en `cervesur_access.log` del legado**.
- Ventanas temporales: **28/10/2026 20h–05/11/2026 03h**, horas según la cadena de tiempo de los logs, sin conversión automática de zona horaria.
- Concentración: 28/10 (2 líneas), 29/10 (6), 30/10 (8), 03/11 (3) y 05/11 (1): 20 líneas en total. *Esta distribución horaria no demuestra identidad ni causalidad*.

**Hipótesis:** pruebas técnicas con curl, coherentes con la actividad de QA del periodo. **No se considera evidencia suficiente para atribución** sin correlación con la persona/proceso o registro de prueba. No hay evidencia de HTTP 200 para esta ruta en la muestra, pero los `403` podrían emitirse en una capa previa (Apache/React) y la ausencia de eventos recientes en el log heredado impide cerrar la cobertura.

**Decisión:** K1 continúa **NO-GO**. Próximo control: inspección **estática, solo lectura** de `CustomLog` y reglas de `ProxyPass`/`RewriteRule`/`Require`/`<Location>` de los VirtualHosts relevantes, sin cambiar configuración ni emitir solicitudes de prueba. Confirmar dónde se registran los accesos reales al backend heredado antes de aprobar A-L2.

## 6. Mapeo estático Apache y observación de cobertura (10/11/2026)

**Evidencia proporcionada por el operador:** se enumeraron `11` configuraciones de `/etc/apache2/sites-enabled`. Se analizaron bloques `<VirtualHost>` de las diez configuraciones Cervesur identificadas.

- Los bloques inspeccionados **no contienen líneas directas reconocidas** de `ProxyPass`, `RewriteRule` ni `<Location...api>` según las expresiones del script. El bloque `cervesur.conf` sí tiene un `Require all granted`.
- Esta comprobación **no incluye** directivas de archivos incluidos, `conf-enabled`, contexto global, `.htaccess`, reglas `<Directory>` ni mecanismos de proxy indirectos. Por lo tanto, **no permite atribuir** los 20 HTTP 403 a Apache, React o PHP.
- Los archivos actuales `cervesur_access.log`, `cervesur-react-admin-access.log` y `cervesur-react-admin-qa-access.log` tienen **0 bytes**. Sus fechas de modificación son, respectivamente, 05/11/2026 y 06/11/2026. Esto puede corresponder a rotación sin nuevas solicitudes o a registro activo por otra ruta; **no acredita cobertura continua hasta el 10/11/2026**.
- Los archivos rotados contienen tráfico previo y se incluyeron en los 68 archivos analizados. El registro de legado tiene **0 solicitudes API** en la muestra, lo cual obliga a verificar cómo se accede realmente a su backend antes de concluir que no hay consumidores.

**Siguiente acción autorizada:** inspección estática, sin publicar contenido sensible, de directivas de enrutamiento de `.htaccess`, `conf-enabled`, `sites-enabled` y cobertura reciente por VirtualHost. No efectuar pruebas HTTP activas, cambios de permisos, recargas ni modificaciones productivas.

**Veredicto:** K1 continúa **NO-GO**; A-L2 aún pendiente.

## 7. Revisión adicional de rutas e .htaccess (salida operador 10/11/2026)

Ejecución de solo lectura `K1_CONFIG_ADICIONAL=FINALIZADA`:

- `sites-enabled`: 11 configuraciones; nueve VirtualHosts React y variantes corporativas muestran **`Include=1`** cada uno. Algunos contienen un bloque `<Location>` que el conteo anterior (solo `Location_API`) no destacó. Ninguno mostró `ProxyPass`, `RewriteRule` o referencias API **directas** en su archivo de VirtualHost.
- `cervesur.conf`: `AllowOverride=1`, `Require all denied=1` y `<Directory>=1`; **no se ha determinado a qué alcance/ruta corresponde** la denegación, y no debe interpretarse automáticamente como bloqueo de API.
- `/var/www/cervesur/.htaccess`: 14 líneas activas, **3 referencias a API**, **3 `RewriteRule` y 9 `RewriteCond`**. Sin contenido de estas reglas todavía no se puede confirmar el destino real de las peticiones a `backend/api.php`.
- `backend/.htaccess`, `backend/router/.htaccess` y `frontend/.htaccess`: ausentes en rutas comprobadas.
- Registros activos de React y del legado: **0 bytes** desde las rotaciones del 04/11 al 06/11 (según archivo); `access.log` general tiene **87 bytes** y modificación el 10/11 a las 04:55, mostrando que al menos un log de Apache sí recibió actividad nueva. Esto **no** demuestra que los VirtualHosts de aplicación recibieran solicitudes recientes.

**Interpretación:** el análisis previo no era una inspección de configuración efectiva, porque deja fuera la directiva `Include` presente en los VirtualHosts React. Se requiere revisar **metadatos y clasificación de las reglas en los archivos incluidos**, junto con la clasificación de destinos de las tres reglas de `.htaccess`, **sin mostrar contraseñas, contenido íntegro ni destinos privados de proxy**. La existencia de 20 `curl` HTTP 403 no demuestra que el servidor heredado rechazó esas llamadas, ni que todos los consumidores hayan sido retirados.

**K1 sigue NO-GO.** No solicitar ni ejecutar cambios en Apache, en logs, permisos o aplicaciones.

## 8. Inspección de archivos incluidos y reglas del legado (10/11/2026)

El operador confirmó que los nueve VirtualHosts React incluyen uno de los archivos comunes siguientes:

| Archivo incluido | Líneas activas | Referencias a API | Reglas relevantes |
| --- | ---: | ---: | --- |
| `react-usuarios-common.conf` (reutilizado por admin y usuarios) | 59 | 3 | 1 RewriteRule, 1 Require denied, 2 Location |
| `react-admin-qa-common.conf` | 46 | 3 | 1 RewriteRule, 1 Location |
| `react-compat-common.conf` | 46 | 3 | 1 RewriteRule, 1 Location |
| `react-piloto-common.conf` | 47 | 3 | 1 RewriteRule, 1 Require denied, 2 Location |
| `react-common.conf` | 36 | 3 | 1 RewriteRule, 1 Location |
| `react-ro-common.conf` | 45 | 1 | 1 Require denied, 1 Location |

Ninguno de estos archivos tiene una referencia **literal** a `powerbi/tokens`. Esta ausencia **no** excluye reglas genéricas de denegación (por ejemplo, `/api/*`). El archivo `/var/www/cervesur/.htaccess` contiene una regla de API cuyo destino fue clasificado como `backend/api.php`, y dos reglas de otro destino. En la revisión no se observaron archivos `.htaccess` adicionales en `backend`, `router` ni `frontend`.

**Interpretación limitada:** el flujo de entrada de API del legado es identificable en la reescritura, pero aún no se conoce el alcance concreto de los bloques `Location`, `Require` y `RewriteRule` incluidos en React. Por lo tanto, los 20 `GET` con `curl` y HTTP 403 todavía **no son atribuibles definitivamente** a una regla Apache ni a pruebas QA.

**Próximo control:** clasificar solamente los bloques de rutas API, sus directivas de autorización y los flags de las reglas Rewrite. Confirmar luego con responsable funcional/infraestructura la ausencia de consumidores reales y la ventana de cobertura vigente.

**Decisión inalterada:** K1 **NO-GO**, PR #13 en Draft; sin cambios productivos ni firma A-L2.

## 9. Clasificación de alcance y prohibición de rutas React (salida 10/11/2026)

La revisión del operador, sobre los siete `Include` resueltos (seis nombres comunes; `react-usuarios-common.conf` apareció más de una vez), reporta:

- `react-usuarios-common.conf`, `react-admin-qa-common.conf`, `react-compat-common.conf`, `react-piloto-common.conf` y `react-common.conf`: **`RewriteRule` en contexto general con `ORIGEN=API` y `PROHIBIR=SI`**. Estos archivos se utilizan en los VirtualHosts donde se registraron **19 de las 20** llamadas K1 que recibieron HTTP 403.
- `react-ro-common.conf`: **sin `RewriteRule` de prohibición** en el desglose; presenta `Location` para raíz con `Require all denied`. En su VirtualHost se observó **1** llamada K1 con HTTP 403. Su origen exacto sigue por confirmar.
- Los archivos comunes también contienen bloques `LocationMatch` orientados a API; no se ha inspeccionado todavía el detalle de las expresiones, ni las posibles `RewriteCond` ligadas a las reglas de prohibición.
- `/var/www/cervesur/.htaccess` del legado mantiene una regla API clasificada con destino `backend/api.php`.

**Valoración corregida por la revisión posterior (sección 10):** la mera presencia de `RewriteRule [F]` **no atribuye las 19 denegaciones a esa regla**. La comprobación estática posterior no halló coincidencia con la ruta K1 de ejemplo. Se mantienen abiertas otras explicaciones para los 403, incluido control de acceso o tratamiento en capas posteriores. Sin embargo, la salida no demuestra que cada regla se aplicase efectivamente a cada solicitud: faltan condiciones, orden y excepciones, así como confirmar el motivo de la llamada al VirtualHost RO. Los registros observados no acreditan ausencia de consumidores de K1 en la ruta heredada.

**Próximo control mínimo:** revisar de forma estática el número de `RewriteCond` asociadas a cada `RewriteRule [F]` y el alcance de las reglas `Location`/`LocationMatch`; correlacionar con la petición K1 **sin realizar llamadas activas**. Después, validación con responsable de sistemas y responsable funcional. **K1 NO-GO**.

## 10. Contraste final de `RewriteRule [F]` con K1 (10/11/2026)

El operador ejecutó `K1_VERIFICACION_REGLAS=FINALIZADA`. Sobre los archivos comunes con regla prohibitiva, los resultados fueron `REGLA_PROHIBITIVA_K1_MATCH=NO`, `CONDICIONES_PREVIAS=0` y `REGLAS_F=1`; el archivo `react-ro-common.conf` mostró `REGLAS_F=0` y `LOCATION_RAIZ_DENEGADA=1`.

**Corrección necesaria:** la hipótesis anterior de que las reglas `[F]` explicaban las 19 respuestas 403 **no quedó corroborada**. Un patrón `ORIGEN=API` clasificado por palabras no equivale a que su expresión regular coincida con `/api/powerbi/tokens/123`. La comprobación no observó esa coincidencia bajo los dos formatos de ruta ensayados.

**Límites del comprobador:** `CONDICIONES_PREVIAS=0` no garantiza ausencia de condiciones efectivas; el script reinicia el contador ante otras líneas, y no reproduce necesariamente el orden y la semántica de Apache `mod_rewrite`. Tampoco analiza el alcance específico de `LocationMatch`, cabeceras, `RewriteCond`, ni los controles que podrían generar 403. El resultado `MATCH=NO` **no identifica por sí solo el componente que emitió 403**.

**Conclusión técnica vigente:**
- Hay **20 GET de `curl` a rutas K1 exactas, todas 403**, registradas solo en VirtualHosts React; no hay respuesta 200 observada en la muestra.
- La atribución de esos 403 a `RewriteRule [F]` se **retira**.
- La vía de API del legado se reescribe hacia `backend/api.php`, pero los logs heredados no prueban cobertura de su tráfico actual.
- La prueba sobre copias de routers heredados **19/19 PASS** acredita el comportamiento esperado del parche, **no** la ausencia de consumidores.
- Queda pendiente corroborar uso real con infraestructura, el responsable funcional y cobertura de registros. **K1 NO-GO; no A-L2**.

**Próxima acción:** en lugar de continuar infiriendo a partir de contadores, solicitar al responsable de infraestructura verificación puntual del origen de los 403 y del enrutamiento mediante las reglas completas bajo revisión segura. En paralelo, obtener inventario de integraciones y conformidad funcional. No realizar peticiones de prueba a producción ni revelar contenidos sensibles.
