# Cervesur UX-08B4 — Alta de reportes de Power BI con referencia interna en grupo obligatorio

**Fecha:** 2026-11-12. **Estado:** candidato de desarrollo; NO instalado en la VPS; PR en borrador. **No requiere migración de esquema.** Se crea como alternativa explícita a UX-08B3 (grupo nullable), cuya documentación y evidencias se conservan en el PR #34.

## Necesidad

El administrador React ya pide únicamente **Nombre**, **Descripción** (opcional) y **Enlace**. La API acepta `https://app.powerbi.com/view?r=...`, pero el esquema productivo mantiene `cervesur_portalbi.reporte.grupo_id VARCHAR(50) NOT NULL`; el backend devolvía error porque un enlace público no incluye `groupId`. El preflight de UX-08B3 confirmó 95 reportes existentes, 0 grupos nulos, esquema InnoDB compatible y copia de seguridad vigente a la hora de la verificación.

## Decisión implementada en esta rama

- Solamente al **crear** un reporte con enlace **`view?r=...`** que no trae grupo y cuando la columna es `NOT NULL`, el backend utiliza **la misma referencia interna de Cervesur (UUID v4)** que ya genera para `reporte_id` como marcador sintético en `grupo_id`. `reporte_id` tiene restricción UNIQUE en el esquema real: cada nuevo reporte público recibe una referencia interna distinta y de 36 caracteres, sin nuevas dependencias, sin JS y sin DDL.
- **IMPORTANTE:** ni el `reporte_id` ni el `grupo_id` de un informe público son IDs reales de Microsoft Power BI. El `grupo_id` sintético no se debe presentar como un grupo real, no sirve para autenticación ni para solicitar tokens; el enlace sigue clasificándose por su tipo `view?r=`.
- No cambia ninguna fila existente. En edición se conserva `grupo_id` almacenado cuando ya existe; los enlaces con un `groupId` real siguen extrayendo el dato real como antes. No se extiende el relleno sintético a `reportEmbed` ni a enlaces de informes privados sin `groupId`; esos siguen requiriendo identificadores reales.
- Cuando una base de ensayo ya admite `NULL`, se conserva el comportamiento previo (compatibilidad con tests de UX-08B3), sin alterar la estructura. **El camino productivo de este incremento es NOT NULL.**
- La identidad operativa estable de Cervesur sigue siendo `reporte.id` numérico para las asignaciones. La tabla conserva UNIQUE en `reporte_id`, `enlace`, auditoría de alta y permisos.

## Procedimiento seguro de activacion en 8443

Este PR incluye `ops/reportes/desplegar-ux08b4.sh`, acotado EXCLUSIVAMENTE a `/opt/cervesur-react-admin/backend/controllers/ReporteController.php`. No sirve para el backend Flutter original ubicado en `/var/www/cervesur/backend` ni modifica archivos frontend.

1. Verificar **conclusion success** de Backend CI en el HEAD definitivo de PR #35.
2. Descargar el script desde un **commit exacto** y comprobar su hash de blob Git (`git hash-object`), antes de ejecutarlo; no seguir `main` u otras ramas moviles. El script a su vez descarga el controlador PHP desde commit fijo `cbb4c1860708a9ae9be0305e3f7ffb1947fc50b8` y exige blob nuevo `3a120727252cea924354716e8de125acafbda4c2`.
3. Ejecutar `sudo bash desplegar-ux08b4.sh --preflight` desde la VPS con acceso confiable a GitHub y CA corporativa. **NO realizar cambios** si falla el hash, PHP lint, flags (reportes, U-BASIC, P5-R), TLS o si el controlador productivo no tiene blob anterior exacto `d51c89361ae5dda52435b224ad8e58f5de0fc82b`.
4. Con autorización real de despliegue de código y respaldo, ejecutar `sudo bash desplegar-ux08b4.sh --aplicar`. El script pide referencia real de aprobador DBA/funcional y el literal `APLICAR-UX08B4` por `/dev/tty`. Crea copia previa `/var/backups/cervesur-react-admin/ux08b4-controller-*/ReporteController.php`, instala con renombrado atómico preservando propietario/permisos y verifica hash, HTTPS 200, 401 anónimo de `/api/reportes` y flags P5-R. Si alguno falla, restaura automáticamente el PHP original. No reinicia Apache ni modifica MySQL.
5. El equipo verifica el estado, realiza QA controlado y registra hora, SHA, resultado y backup en PR #35. Si el script devolvió NO-GO, **no reintentar a ciegas ni corregir manualmente .env**. La reversión posterior al PASS requiere verificar el backup exacto, volver a instalar atómicamente y repetir HTTP/TLS; no borrar nuevas filas de reportes sin autorización aparte.

**Datos productivos:** aunque no hay ALTER, la primera alta real de reporte hace un INSERT y una auditoría y puede aparecer en listados Flutter legacy. Primero comprobar U-BASIC/P5-R y luego realizar QA con cuentas/casos autorizados y enlaces que NO expongan información confidencial. El control del portal no privatiza `view?r=`.

## Riesgos y límites que no se deben ocultar

1. **Semántica del dato:** reutilizar un UUID interno en `grupo_id` satisface el requisito técnico de la columna, pero no crea un grupo real en Power BI. Cualquier flujo que asuma que `grupo_id` siempre es un grupo Microsoft necesitará distinguir enlaces públicos de privados. No usar el marcador interno para autenticar, generar tokens ni llamar a Microsoft.
2. **Flutter legacy:** usa `SELECT r.*` al listar reportes activos, incluso sin asignación si la ruta se llama sin user id; el campo ahora sería cadena UUID, no `NULL`, reduciendo el riesgo de parseo por nulabilidad. **No demuestra que Flutter pueda renderizar enlaces públicos**. El endpoint heredado de tokens está retirado con 410; el flujo de visualización público exige QA funcional por una cuenta ficticia antes de dar acceso productivo.
3. **Seguridad de Power BI:** `Publicar en web` es accesible públicamente fuera de Cervesur, aunque el portal exija login. No registrar por ese método informes confidenciales de finanzas/operaciones/clientes. Un `reportEmbed?reportId=...` privado necesita integración de autenticación/licencias y `groupId` real; rellenar un UUID falso no soluciona eso.
4. **Contratos legacy y conservación:** las rutas `/api/reportes/grupoid/{uuid}` aceptan la sintaxis pero no implican que el ID pertenezca a un espacio Microsoft. Reutilizar el UUID de `reporte_id` permite detectar los nuevos marcadores sintéticos bajo condición de enlace público; no asumirlo para los reportes históricos.
5. **Longitud de URL:** `reporte.enlace` conserva su longitud real (500 en QA productiva conocida); enlaces largos se rechazan de manera explícita, sin truncamiento. UX-08B4 NO cambia esa restricción.

## Pruebas y condiciones de GO

- Sintaxis PHP, seguridad, pruebas HTTP integrales en MySQL desechable. En particular `http_report_nogroup_test.php` verifica en esquema NOT NULL que el alta pública cree un UUID v4 sintético, distinto entre reportes, conserve auditoría, no altere filas previas y siga admitiendo el caso nullable alternativo.
- `http_viewer_test.php` comprueba el alta sin migración, y mantiene los ensayos UX-07C de URL larga y reversión del esquema únicamente dentro de MySQL ficticio.
- QA en VPS: respaldo fresco de frontend/backend y base; instalar exclusivamente `backend/controllers/ReporteController.php` candidato con comparación SHA contra Git y contra producción; conservar `.env`, Apache, MySQL, Flutter y U-BASIC/P5-R.
- Antes de activar funcionalmente: QA HTTP seguro con cuenta ficticia, pruebas de duplicados, permisos 401/403, auditoría `reporte.create`; después prueba visual React y Flutter antes de asignar reportes reales. No inventar URLs reales ni usar datos confidenciales.
- Reversión: restaurar el archivo PHP original desde respaldo. No se necesita DDL de rollback. Restaurar código NO borra reportes ya creados; cualquier ajuste a datos reales necesita autorización aparte.

**Control de cambios:** el PR #34 UX-08B3 sigue documentado como alternativa evaluada, pero NO aplicarlo junto con UX-08B4 sin una decisión de arquitectura explícita. Este cambio solo modifica la generación de campos en el servidor, no las tablas.
