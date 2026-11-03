# Power BI «Publicar en la web»: qué protege el portal y qué no (UX-07D.5, bloque D)

Estado: **riesgo documentado y aceptado solo para un piloto reducido.** Es la condición que más pesa antes de abrir el portal a todos los empleados.

## 1. Qué es un enlace «Publicar en la web»

Es una dirección `https://app.powerbi.com/view?r=…` que **cualquier persona que la conozca puede abrir sin iniciar sesión**, desde cualquier red. El identificador `r=` es el único secreto. Microsoft no aplica ninguna identidad, ningún Seguridad a Nivel de Fila (RLS) ni ningún permiso sobre ese enlace.

## 2. Qué controla el portal React

| El portal sí hace | El portal no puede hacer |
|---|---|
| Solo usuarios existentes, activos, de rol «usuario» y en la lista blanca inician sesión. | Hacer privado un enlace público: la dirección sigue siendo pública. |
| Cada persona ve **solo** los informes de su cadena de relaciones activas (usuario → empresa → informe). | Evitar que una persona autorizada copie la dirección y la comparta (está en la pestaña de red del navegador, en el iframe, y puede copiarse). |
| El **catálogo nunca incluye el enlace**; solo el visor lo entrega, tras comprobar el permiso en ese instante y solo para los informes autorizados para el piloto (`CERVESUR_PILOT_VISOR_IDS`). | **Revocar** un enlace ya conocido: quitar el permiso a una persona impide que el portal se lo muestre de nuevo, pero no invalida una dirección que ya vio o guardó. |
| Un informe ajeno es indistinguible de uno inexistente (404). | Aplicar filtros por empresa, por usuario o por fila **dentro** del informe: el informe publicado muestra lo mismo a todos. |
| Auditoría de aperturas y denegaciones (solo identificadores y huellas, no enlaces). | Impedir el acceso de quien obtenga la dirección por otro medio (correo, mensajería, historial, captura de pantalla). |

## 3. Esto NO es equivalente a RLS de Power BI

El control de acceso del portal es de **descubrimiento y de entrega** (quién recibe la dirección y cuándo). RLS es de **datos** (qué filas ve cada identidad) y exige que Power BI conozca la identidad. Con «Publicar en la web» no hay identidad en Power BI, por lo tanto no hay RLS. Cualquier afirmación del tipo «cada usuario solo ve sus datos» **solo es cierta respecto a qué informes aparecen en el portal**, no respecto a quién puede ver el contenido de un informe cuyo enlace conoce.

## 4. Riesgo que impide ampliar el piloto

**Sí existe, y es bloqueante para abrir a todos los empleados:**

1. Los dos informes de referencia (`DASHBOARD_PRESUPUESTAL_CERVESUR_031`, `DASHBOARD_RESPONSABLE_CERVESUR_031`) tratan de presupuesto y responsables. **Hay que decidir por escrito si su contenido puede ser visto por cualquier persona que obtenga la dirección.** Si no puede, el portal React no resuelve el problema: es una propiedad del informe publicado.
2. Mientras sean enlaces públicos, la ampliación del número de usuarios amplía la probabilidad de filtración (más personas conocen la dirección) sin ningún mecanismo de revocación individual.
3. La única revocación real es **cambiar la publicación en Power BI** (eliminar el código de inserción y publicar uno nuevo), lo que rompe los enlaces anteriores y obliga a actualizar el dato `reporte.enlace`. Eso lo hace el propietario del informe, no el portal.

## 5. Mitigaciones ya presentes (no sustituyen a las de abajo)

* Valor predeterminado seguro: **ningún** informe autorizado (`CERVESUR_PILOT_VISOR_IDS` vacío); se autoriza informe por informe con una segunda confirmación escrita (`AUTORIZO-INFORMES`).
* El token OAuth general de Power BI que expone el endpoint heredado está **bloqueado** en el sitio del piloto (403).
* CSP `frame-src https://app.powerbi.com`, `frame-ancestors 'none'`, `X-Frame-Options: DENY`: el portal solo incrusta el visor oficial de Power BI y no puede ser incrustado por terceros.
* Acceso solo por HTTPS y por túnel SSH (loopback): el enlace no viaja por redes abiertas hacia el navegador del usuario.

## 6. Alternativas para control real (decisión del cliente, fuera de esta fase)

| Opción | Qué da | Requisitos |
|---|---|---|
| Incrustación **«para su organización»** (Entra ID) | Solo cuentas del inquilino; permisos de Power BI aplican | Licencias Power BI Pro/PPU o capacidad, usuarios en el inquilino |
| **Power BI Embedded** (la aplicación es dueña de los datos) con **RLS por identidad efectiva** | Control por usuario y por fila; el portal es quien genera el token de incrustación | Capacidad F/A/P, registro de aplicación, servicio de tokens, desarrollo del backend |
| Informes por **aplicación (App) de Power BI** con audiencias | Permisos por grupo | Licencias para cada usuario |

Hasta decidir, la recomendación es **no autorizar informes sensibles** en el portal y mantener el piloto con la lista nominal reducida.

## 7. Lo que NO se afirma

No se afirma que el portal proteja el contenido de los informes publicados, ni que revocar a una persona la deje sin acceso a un enlace que ya conoce, ni que la arquitectura sea comparable a RLS.
