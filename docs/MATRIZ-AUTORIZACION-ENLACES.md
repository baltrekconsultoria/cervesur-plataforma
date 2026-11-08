# Autorización de enlaces de Power BI: procedimiento y matriz (UX-07E.C, bloque C)

## 1. Qué se auditó

* **El 403 observado para el informe interno 64 es el control esperado.** La instancia administrativa (8093) entrega el enlace de un informe solo si su id figura en `CERVESUR_PILOT_VISOR_IDS` del `.env` **de esa instancia**; mientras la lista esté vacía (valor de instalación), todos los visores responden `403 visor_no_autorizado`. El administrador **no** es una excepción: se comprueba en el backend (`usr_visor_autorizado`) y las pruebas lo confirman con una sesión de administrador (el informe no autorizado devuelve 403 sin enlace; los autorizados, 200).
* La lista del 8093 es **independiente** de la del piloto de trabajadores (8092): cada una vive en su propio `.env`. Autorizar un informe en el 8093 no lo autoriza en el 8092 (prueba: la lista del 8092 no cambia).
* Los listados (`/api/reportes`) **no entregan enlaces**. Desde UX-07E.C incluyen solo un indicador `visor_autorizado` (0/1) para que la interfaz marque «Sin enlace autorizado»; el enlace únicamente sale del visor, para una sesión válida y un informe autorizado.
* Cada apertura del visor queda en la auditoría (`visor_abierto`, `visor_no_autorizado_piloto`, solo ids).

## 2. Procedimiento para autorizar (nunca «todos» por defecto)

Lo ejecuta el operador del servidor, informe por informe y **solo con aprobación previa del responsable de los datos** (matriz §4):

```
sudo bash scripts/usuarios.sh informes --listar                 # qué hay autorizado hoy
sudo bash scripts/usuarios.sh informes --agregar 72             # autoriza UN informe (pide escribir AUTORIZO-INFORMES)
sudo bash scripts/usuarios.sh informes --agregar 72,122         # o un conjunto aprobado
sudo bash scripts/usuarios.sh informes --quitar 122             # retira uno (efecto inmediato en la siguiente petición)
sudo bash scripts/usuarios.sh informes --retirar                # retira todos
sudo cat /var/backups/cervesur-react-admin/autorizaciones-informes.log   # bitácora: fecha, quién, acción, ids, antes/después (sin enlaces)
```

* `--autorizar <ids>` sustituye toda la lista; `--autorizar TODOS` existe pero exige una frase distinta (`AUTORIZO-TODOS-LOS-INFORMES`) y **no debe usarse** sin decisión escrita.
* No hace falta reiniciar ni recargar Apache: el `.env` se lee en cada petición.
* El cambio de la lista no cambia permisos de ninguna persona: solo decide qué enlaces puede entregar el visor.

## 3. Reglas que se mantienen

1. No se autoriza automáticamente ningún enlace; la lista parte vacía.
2. Los listados nunca llevan enlaces.
3. El permiso se verifica en el backend en cada apertura (no en la interfaz).
4. Se registran los accesos (auditoría) y los cambios de la lista (bitácora).
5. El rol administrador no equivale a autorización irrestricta para exponer enlaces.
6. Sin aprobación del responsable de los datos no se habilita ningún enlace productivo.
7. La lista del 8092 no se altera desde esta instalación.

## 4. Matriz de informes autorizables (a completar por el responsable de los datos)

Clasificación propuesta (confidencialidad **del contenido del informe publicado**, porque quien tenga el enlace «Publicar en la web» lo ve sin autenticarse):

| Nivel | Significado | ¿Se puede publicar con «Publicar en la web»? |
|---|---|---|
| **P · Público** | Información que la empresa publicaría abiertamente | Sí |
| **I · Interno general** | Para cualquier empleado; sin datos personales ni financieros sensibles | Con aprobación; riesgo bajo si el enlace se filtra |
| **C · Confidencial** | Presupuestos, responsables, costos, datos de clientes o de personas | **No** con enlace público; requiere inserción segura (§5) |
| **R · Restringido** | Datos legales, salariales, estratégicos | **No** |

| ID | Código (`reporte_id`) | Nombre | Empresa(s) | Nivel propuesto | Aprobado por (nombre, fecha) | Autorizado en 8093 | Autorizado en 8092 |
|---|---|---|---|---|---|---|---|
| 72 | — | DASHBOARD_PRESUPUESTAL_CERVESUR_031 | CREDITEX | **Pendiente: probablemente C (presupuesto)** | | No | No |
| 122 | — | DASHBOARD_RESPONSABLE_CERVESUR_031 | CREDITEX | **Pendiente: probablemente C (responsables)** | | No | No |
| 64 | — | (según el catálogo) | — | Pendiente | | No (403 observado: esperado) | No |
| resto | | | | Pendiente | | No | No |

Solo se anotan aquí los informes conocidos por esta conversación; **la clasificación no se inventó**: la propone la tabla y la confirma quien responde por los datos. Para obtener la lista completa sin tocar nada y sin enlaces (administrador de MySQL, solo lectura): `SELECT id, reporte_id, nombre, activo FROM cervesur_portalbi.reporte ORDER BY id;` (no incluir la columna `enlace`).

## 5. Riesgo de «Publicar en la web» y evolución

Resumen (detalle en `POWERBI-PUBLICAR-WEB-RIESGO.md`): el enlace es el único secreto; cualquiera que lo conozca ve el informe, sin identidad ni RLS. El portal solo controla a quién se **entrega**. Para informes C o R hace falta una **inserción segura**:

1. **Inserción «para su organización»** (Entra ID): solo cuentas del inquilino; los permisos de Power BI aplican. Requiere que los usuarios tengan licencia y cuenta en el inquilino.
2. **Power BI Embedded con identidad efectiva y RLS**: el portal genera un token de inserción por usuario; control por fila. Requiere capacidad F/A/P, registro de aplicación y un servicio de tokens en el backend (hoy el endpoint heredado que expone un token general está bloqueado a propósito).
3. Hasta entonces, mantener C/R **fuera** de los enlaces autorizados.

El tipo de enlace `embed` (`reportEmbed`) ya se reconoce y se rechaza como «privado» en el visor hasta que exista esa configuración.
