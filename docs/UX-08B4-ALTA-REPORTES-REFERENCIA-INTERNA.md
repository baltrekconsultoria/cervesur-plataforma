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
