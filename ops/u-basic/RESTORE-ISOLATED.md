# Cervesur P2 — Ensayo real de restauración MySQL aislada (U-BASIC)

## Propósito

Usar el RESPALDO EXISTENTE (verificado en PuTTY; no generar otro) para realizar un ensayo de restauración reproducible sin importar SQL en el MySQL de producción. Corresponde al paso siguiente al backup U-BASIC P2. Nunca activa el interruptor de Usuarios.

Respaldo ya creado en servidor: /var/backups/cervesur-react-admin/u-basic/u-basic-20261112T120054Z-o88zdhrl

El archivo SQL contiene nombres, datos personales, hashes de contraseñas y sesiones. NO enviarlo por chat, correo, GitHub ni tickets.

## Diseño del aislamiento

- Solo consume el respaldo desde su ubicación root-only; NO monta el directorio del backup ni /var/lib/mysql del host dentro del contenedor.
- Requiere Docker existente y la imagen mysql:8.0 ya descargada LOCALMENTE. Nunca hace docker pull, instala Docker ni modifica la configuración global.
- Inicia un contenedor temporal con --network none, sin puertos publicados, sin logs y con almacenamiento MySQL en tmpfs; no existe conexión TCP con MySQL productivo.
- Transfiere los SQL descomprimidos únicamente por stdin de docker exec, sin generar volcados temporales legibles en el host.
- Importa las siete tablas de cervesur_portalbi y la base de estado cervesur_react_estado_admin en el contenedor desechable.
- Compara COUNT(*) y CHECKSUM TABLE de las siete tablas con los valores del manifest del backup, exige dos triggers del audit_log y al menos una tabla del estado admin restaurada.
- Elimina el contenedor siempre, incluso si falla la importación. Si no se puede eliminar, detiene el procedimiento y NO entrega PASS.
- SOLO tras completar las verificaciones y limpiar el contenedor se emite un recibo nuevo y privado restore-isolated-receipt.json dentro de la carpeta del backup, sin cambiar ni falsificar manifest.json (restoration_tested=false conserva el estado original del generador de P2).
- NO toca datos MySQL productivos, el .env, Apache, Flutter, React, credenciales ni flags; U-BASIC y sensible siguen OFF.

## Puertas

1. Ejecutar como sudo con lectura de carpeta privada de respaldo, sin divulgar su contenido.
2. Confirmar que el servidor tiene Docker operativo y un mysql:8.0 LOCAL. En caso contrario PRE-FLIGHT = NO-GO; no se instalará software en producción sin decisión específica.
3. No se ensaya la restauración si ADMIN_WRITES_USUARIOS=1 o ADMIN_WRITES_USUARIOS_SENSIBLE=1.
4. El backup debe ser exactamente del P2 vigente (SHA/gzip/manifest y 7 tablas).
5. El ensayo consume recursos de la máquina productiva (Docker + tmpfs en RAM, límite de memoria 2 GB). Elegir ventana supervisada. Si el servidor está congestionado, detenerse.

## PuTTY (solo tras CI y revisión de código)

Copiar el script restore_verify.py Y backup_u_basic.py desde el MISMO commit aprobado a /opt/cervesur-u-basic-kit, cotejando sus Git blob SHA. No descargar main mutable y no reemplazar backend productivo.

PRE-FLIGHT sin contenedor ni DML:

    sudo python3 /opt/cervesur-u-basic-kit/restore_verify.py preflight --path /var/backups/cervesur-react-admin/u-basic/u-basic-20261112T120054Z-o88zdhrl

Esperado: RESPALDO_INTEGRIDAD=PASS; MYSQL_IMAGE_LOCAL=PASS; USUARIOS_ESCRITURA=OFF; P2_RESTORE_PREFLIGHT=PASS; MYSQL_PRODUCTIVO_MODIFICADO=NO.

ENSAYO controlado (solo si PREFLIGHT PASS y ventana confirmada):

    sudo python3 /opt/cervesur-u-basic-kit/restore_verify.py run --path /var/backups/cervesur-react-admin/u-basic/u-basic-20261112T120054Z-o88zdhrl --confirm CERVESUR_AUTORIZO_RESTAURACION_AISLADA_P2

Esperado: MYSQL_AISLADO_RED=NONE; MYSQL_CONTENEDOR_AISLADO_ELIMINADO=SI; P2_RESTAURACION_AISLADA=PASS; TABLAS_NEGOCIO_VERIFICADAS=7; triggers audit>=2; ESTADO_ADMIN_TABLAS_RESTAURADAS>=1; MYSQL_PRODUCTIVO_MODIFICADO=NO; ACTIVACION_U_BASIC=NO_REALIZADA. Guardar RECIBO_PRIVADO sin copiar el SQL.

Si falla un CHECKSUM TABLE, NO reinterpretar como éxito: puede haber incompatibilidad entre la versión del servidor original y la versión de MySQL8 de la imagen local, o un dato que no se restauró igual. La salida no incluye datos privados. El procedimiento no corrige ni restaura automáticamente producción.

## QA CI

- Unit tests offline prueban integridad de manifiestos, backup root-only, flags OFF, Docker local, bloqueos, comparación de datos, triggers y eliminación obligatoria.
- Integration test en GitHub Actions usa DOS servidores MySQL 8 Docker con datos completamente sintéticos, sin puertos, creando un backup artificial para probar la restauración efectiva con siete tablas, triggers de auditoría y estado administrativo.

Este QA NO utiliza archivos ni bases productivas.

## Después del ensayo

La restauración aislada verifica la recuperabilidad de los datos guardados, NO replica automáticamente las cuentas MySQL, GRANTs, roles de servidor, DEFINER ni secretos de aplicación. El DBA mantiene su respaldo de cuentas/permisos y define el plan de recuperación integral.

Quedan además D3 y QA por roles en HTTP real, A1/A2/F10 no-admin 403, firmas K1/K2/A1/A2/A3 y continuidad del registro de Flutter antes de habilitar U-BASIC. Una autorización del usuario para hacer el backup NO equivale a firmar todas esas puertas. P5 roles/contraseñas permanece OFF.
