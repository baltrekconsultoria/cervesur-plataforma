# Cervesur UX-08B3 — Reportes sin ID del grupo (habilitacion incremental)

**Estado:** PREPARADO / NO EJECUTADO en produccion. **Alcance:** cambiar exclusivamente la nulabilidad de `cervesur_portalbi.reporte.grupo_id` para permitir enlaces `https://app.powerbi.com/view?r=...`, que no contienen `groupId`. No modificar P5-R, U-BASIC, roles, usuarios, asignaciones ni `.env`.

## Diagnostico reproducido — 12/11/2026

En la pantalla Nuevo reporte, el formulario React ya solicita solo nombre, descripcion y enlace. El backend `ReporteController::resolveReportFields` (validacion de `grupoIdNullable()`) rechaza cuando el enlace `view?r=` no trae grupo y la columna es `NOT NULL`. Por ello no es un error de formulario: el SQL productivo debe adecuarse a `NULL`.

El repositorio ya tiene la migracion versionada:
- `database/migrations/UX-08B3-reporte-grupo-nullable.up.sql`: un unico `ALTER TABLE reporte MODIFY grupo_id VARCHAR(50) NULL;`.
- `database/migrations/UX-08B3-reporte-grupo-nullable.down.sql`: solo revierte si no existe ningun informe con `grupo_id IS NULL`; nunca inventa identificadores.
- Prueba de integracion existente `tests/isolated/http_report_nogroup_test.php` verifica: antes de migracion rechaza, despues crea `view?r=` con `grupo_id=NULL`, guarda referencia interna unica, conserva esquemas/indices/historicos, enlace duplicado rechazado y restricciones de autorizacion/auditoria.

## Puerta A — Solo lectura en VPS

Ejecutar `ops/reportes/preflight-ux08b3-solo-lectura.sh` verificado por commit Git y SHA, con `sudo`. Comprueba columnas y collation, sin imprimir filas, enlaces ni credenciales. Si se aporta un `backup.sql.gz`, comprueba integridad, antiguedad menor a 24 h y que incluya DDL y datos de la tabla `reporte`.

Esperado: `UX08B3_ESQUEMA_COMPATIBLE=PASS`, `UX08B3_MIGRACION=PENDIENTE`, `UX08B3_CONTEO=PASS`, `UX08B3_PREFLIGHT=PASS`, `UX08B3_ALTER_EJECUTADO=NO`. `YA_APLICADA` indica que no hay que repetir el ALTER.

**No utilizar el respaldo de P5-R del codigo como respaldo SQL.** El backup anterior de U-BASIC `negocio7.sql.gz` necesita confirmacion de cobertura para `reporte` y de vigencia; antes de aplicar, crear o validar respaldo nuevo apropiado. `gzip -t` no equivale a restauracion de ensayo.

## Puerta B — Compatibilidad y seguridad (obligatoria antes de cambiar MySQL)

- **Flutter heredado:** el test actual advierte que no se ha validado el tratamiento de `grupo_id=NULL` en Flutter. Ensayar con una cuenta ficticia que pueda listar, abrir y navegar un informe `view?r=` sin `TypeError`; revisar endpoints Flutter `/api/powerbi/*`, que pueden requerir un grupo. Sin resultado seguro: NO-GO para produccion.
- **Confidencialidad:** los enlaces `Publicar en web` son publicos en Internet, con independencia del acceso autenticado al portal. NO usar reportes financieros, comerciales o personales restringidos mediante ese enlace. Para datos confidenciales usar un mecanismo de insercion autenticada con licencias y permisos correctos.
- **Capacidad real de `reporte.enlace`:** esta migracion NO incrementa la longitud de columna `enlace`; si la URL excede el limite actual, corresponde otro cambio independiente. No truncar URLs ni inventar `reporte_id` o `grupo_id`.
- Validar respaldo, ventana operativa y aprobaciones reales. Un `ALTER TABLE` puede requerir bloqueo de metadatos; comprobar actividad transaccional y definir rollback antes de ejecutar.

## Puerta C — Cambio DDL (NO AUTORIZADO NI EJECUTADO AUN)

Solo despues de Puertas A y B con evidencia PASS y **autorizacion expresa de migracion SQL productiva**, DBA ejecuta exclusivamente el contenido versionado de `UX-08B3-reporte-grupo-nullable.up.sql` sobre `cervesur_portalbi`. No hay que desplegar PHP ni frontend: ya soportan la ausencia del grupo. Luego verificar:

```sql
SELECT COLUMN_TYPE, IS_NULLABLE
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA='cervesur_portalbi' AND TABLE_NAME='reporte' AND COLUMN_NAME='grupo_id';
```

Esperado: `varchar(50)` / `YES`. Confirmar conteo y asignaciones sin cambios. QA: crear un **reporte sintetico** con `view?r=` (URL ficticia que cumpla la validacion), verificar `grupo_id IS NULL` y reporte creado/auditado, asignarlo a una empresa ficticia y a usuario de ensayo; comprobar acceso segun permisos. Luego, previa validacion de la fuente y confidencialidad, registrar informes reales por procedimiento autorizado.

Si el ALTER falla: NO continuar ni alterar otros campos. Si despues se crean reportes `grupo_id=NULL`, el DOWN debera rechazar hasta conciliar esas filas; no se permite borrar registros reales para forzar el rollback.

## Limites de alcance

El texto actual del formulario advierte que se usen enlaces sinteticos. Esta liberacion **no** habilita por si sola uso de links publicos con datos empresariales reales, ni configura Power BI Embedded. El usuario podra asociar informes a empresas y usuarios solo bajo los controles existentes.
