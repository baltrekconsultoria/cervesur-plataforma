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

## cPanel sin SSH: ejecucion CLI mediante Cron Jobs (12/11/2026)

El puerto SSH 22 y el 21098 no respondieron desde la red operativa. cPanel si permite **Cron Jobs** y **Administrador de archivos**.

**Evidencia aportada del hosting legado:** en `/home/databalt/cervesur_cron_private/`, el script de diagnostico `cron_preflight.php` genero `cervesur-cron-preflight-result.txt` con `CERVESUR_CRON_PREFLIGHT=PASS`, PHP CLI **8.1.35**, PDO/MySQL, JSON y HMAC disponibles, carpeta privada escribible y archivo resultado con modo 0600. No se accedio a ninguna BD ni se alteraron datos.

**Limpieza inmediata:** eliminar el cron temporal de 5 minutos una vez obtenido PASS; preservar los archivos de evidencia fuera de `public_html`.

### Siguiente puerta: conectividad de la BD antigua (pendiente)

El script `ops/migracion/cron_db_preflight.php` esta preparado en GitHub, **no desplegado ni ejecutado**. Solo verifica configuracion DB, conexion PDO, sesion MySQL READ ONLY y presencia de seis tablas mediante `information_schema`. Genera un archivo privado `cervesur-db-preflight-result.txt`; no consulta filas de usuarios ni realiza DML.

1. **Primero confirmar la ruta del cargador** en el File Manager remoto: inspeccionar si existe `/home/databalt/public_html/backend/lib/env.php` (mostrar solo nombres de archivos, jamas abrir o compartir `.env`). La ruta es **hipotesis de despliegue**, no un hecho probado del hosting.
2. Si existe y corresponde al **legado**, descargar de la rama del PR `ops/migracion/cron_db_preflight.php` y subirlo a `/home/databalt/cervesur_cron_private/`; no subirlo a `public_html`.
3. Comprobar sintaxis y planificar cron de corta duracion, con una sola salida (el script evita sobrescribir resultados). Usar el PHP CLI ya validado:
   
   ```sh
   /usr/local/bin/php /home/databalt/cervesur_cron_private/cron_db_preflight.php --loader=/home/databalt/public_html/backend/lib/env.php >/dev/null 2>&1
   ```
   
   **NO programar hasta comprobar la ruta del loader**. No incluir contrasenas en cron, GitHub o chat.
4. Abrir solo `cervesur-db-preflight-result.txt` (no contiene secretos), revisar `CERVESUR_DB_PREFLIGHT` y los controles de READ ONLY; eliminar el cron inmediatamente despues.
5. Si la ruta no existe o la base configurada no es `databalt_portalbi`, parar. No intentar otro loader de otra aplicacion a ciegas. Investigar la configuracion correcta sin exponer secretos y/o usar exportaciones autorizadas de phpMyAdmin como plan alternativo.

**No-GO permanente:** ningun script de exportacion real ni aplicador de migracion se ejecuta antes de evaluar el resultado de esta prueba, confirmar esquema y obtener autorizacion para la fase correspondiente.

### Puerta siguiente: clave de conciliacion HMAC (preparada, NO ejecutada)

Tras `CERVESUR_DB_PREFLIGHT=PASS` (12/11/2026 a las 08:05:02 UTC; `DB_CONNECTED=PASS`, `DATABASE_MATCH=PASS`, `READ_ONLY_TRANSACTION=PASS`, `REQUIRED_TABLES_PRESENT=6/6`), se puede preparar el paso de identidad seudonimizada.

- GitHub: `ops/migracion/cron_hmac_bootstrap.php` genera **una sola vez** 32 bytes aleatorios (256 bits) y los guarda como 64 caracteres hex en `/home/databalt/cervesur_cron_private/.cervesur-migration-hmac`, modo 0600. **No** sube ni imprime la clave, y **no** conecta a MySQL.
- Al ejecutarse por Cron Jobs PHP CLI: el resultado permitido es `/home/databalt/cervesur_cron_private/cervesur-hmac-bootstrap-result.txt` (0600). Si la clave ya existe no la sobrescribe. Se debe **eliminar el cron** tras la evidencia, sin repetir el bootstrap.
- Nunca compartir la clave, el archivo oculto ni snapshots de usuarios en GitHub, issues o chats. Para mantener la misma huella HMAC, transferir el archivo secreto directamente por un canal cifrado autorizado a un directorio privado del servidor nuevo y comprobar 0600; **no generar una segunda clave independiente**.
- No ejecutar aun `export_snapshot.php` ni pasar a escritura. Primero obtener el PASS del bootstrap, comprobar sintaxis y ruta, y validar permisos y plan de descarga/transferencia de archivos sensibles.
