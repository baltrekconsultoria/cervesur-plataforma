# Cervesur — Migración diferencial V1: diagnóstico y simulación

**Estado:** candidato en PR borrador, NO desplegado. **Producción:** sin cambios.
Objetivo aprobado: sustituir Flutter/Firebase/hosting remoto por React + PHP + MySQL local, preservando los desarrollos y enlaces Power BI vigentes.

## Inventario probado (12/11/2026)

| Métrica | Legado remoto databalt_portalbi | React local cervesur_portalbi |
|---|---:|---:|
| Usuarios (activos) | 190 (176) | 76 (62) |
| Empresas (activas) | 25 (5) | 26 (6) |
| Reportes (activos) | 264 (169) | 95 (82) |
| Empresa–usuario (activos) | 198 (176) | 82 (79) |
| Empresa–reporte (activos) | 273 (270) | 103 (100) |
| Usuario–empresa–reporte (activos) | 334 (271) | 103 (93) |
| Cadenas completas vigentes por SQL | 226 | 91 |

Legado: entre 271 asignaciones activas, 5 apuntan primero a usuario inactivo y 40 a reporte inactivo; React: 2 apuntan a usuario inactivo. Son motivos exclusivos de primera causa, no una orden de reactivación.

**No inferir** que faltan 114 usuarios únicos ni 135 permisos que deban insertarse; no inferir que los 169 reportes activos antiguos son los 169 dashboards actualizados del checklist.

## Alcance del incremento

- ops/migracion/export_snapshot.php: ejecutable solo CLI, conexión PDO con sesión MySQL READ ONLY, exporta a un archivo privado solo IDs de filas y HMAC-SHA256 de email/username, RUC, ID y URL de reporte, roles, indicadores activos y relaciones. No incluye nombres, correos literales, teléfono, RUC literal, enlaces, passwords, JWT ni credenciales.
- ops/migracion/compare_snapshots.py: compara snapshots con la misma clave HMAC sin conectar a DB ni ejecutar DML. Emite solo conteos y señala colisiones y faltantes para revisión.
- tests/migracion/test_dryrun.py: pruebas sintéticas; jamás datos productivos.
- .github/workflows/migration-dryrun-ci.yml: validación PHP lint y Python unittest.

**Límites:** HMAC seudonimiza, NO anonimiza irreversiblemente; la clave y snapshots son sensibles. La coincidencia de email/RUC/reporte_id no prueba el mismo titular, empresa o reporte: se exige revisión humana. El snapshot no modifica BD, pero crea un archivo privado fuera de la carpeta web. El script todavía no se ha ejecutado contra datos reales. Este incremento NO incluye escritura/migración productiva.

## Procedimiento previsto (NO ejecutar aún sin preflight y autorización)

1. Confirmar acceso CLI/Terminal autorizado en cPanel y Ubuntu. Si cPanel carece de terminal, no publicar este PHP en public_html ni invocarlo por HTTP; preparar otra vía privada.
2. Preparar directorio privado fuera de DocumentRoot y una misma clave aleatoria secreta de 32 bytes o más en cada servidor, en archivos individuales con permisos 0600. Distribuirla por un canal seguro; no pegarla en GitHub, chat o URL.
3. Copiar script solo a carpeta privada. Verificar PHP lint y que el loader de DB corresponde al origen esperado. La conexión MySQL debe permitir SET SESSION TRANSACTION READ ONLY: no omitir esta puerta.
4. Ejemplo de comando para hosting legado, tras aprobar su ejecución:

    php export_snapshot.php --origin=legado --loader=/home/databalt/public_html/backend/lib/env.php --database=databalt_portalbi --key-file=/home/databalt/.cervesur-migration-hmac --out=/home/databalt/PRIVATE_EXPORTS/legado.json

5. Comando de ejemplo en Ubuntu nuevo, usando la misma clave:

    php export_snapshot.php --origin=react --loader=/var/www/cervesur/backend/lib/env.php --database=cervesur_portalbi --key-file=/home/usr_cervesur/.cervesur-migration-hmac --out=/home/usr_cervesur/PRIVATE_EXPORTS/react.json

6. Transferir ambos JSON mediante canal cifrado a equipo privado autorizado y ejecutar:

    python3 compare_snapshots.py --legado legado.json --react react.json --salida=resultado-agregado.json

7. Revisar faltantes, colisiones, links Power BI cambiados, relaciones y usuarios inactivos. El resultado contiene solo conteos. No subir snapshots, claves o archivos con IDs a GitHub, issues ni conversaciones.
8. Diseñar mapeo aprobado de identidades, esquema de activación segura sin contraseñas antiguas, asociaciones con IDs locales, y enlaces finales basados en el checklist actualizado de Power BI.
9. Construir un APLICADOR posterior, separado e idempotente, con respaldo verificado, ensayo de rollback y dry-run de escritura exclusivamente en copia aislada. Cualquier escritura productiva requiere nueva autorización.

## Puertas NO-GO para escritura

- Sin respaldo verificable de ambas fuentes, copia aislada y validación de restricciones/FK.
- Sin correspondencia revisada de cuentas por email/username, colisiones, cambios de correo y duplicados.
- Sin decidir las empresas/reportes realmente vigentes y los enlaces oficiales.
- Sin QA funcional de permisos, QA F10 con usuario del backend local, revisión de grants y seguridad 2B.
- Sin autorización explícita para cambios de BD, merge/deploy o desmantelar Flutter/Firebase.

## Continuidad

Auditorías y decisiones del proyecto: GitHub Issue #17. No registrar PII ni secretos. Este paquete es solo desarrollo y diagnóstico, NO corte productivo.
