# Cervesur U-BASIC – P2, respaldo completo previo a activación

## Alcance del incremento

El kit captura, **sin modificar MySQL**, los datos necesarios para preparar la activación de las operaciones NO sensibles de Usuarios en HTTPS 8443:

- Base de negocio: las seis tablas productivas (user, empresa, reporte, empresa_usuario, empresa_reporte, user_empresa_reporte) **más audit_log y sus triggers**; se necesita un backup de auditoría separado porque el respaldador histórico de P2 solo incluía seis tablas.
- Base de estado de la administración: cervesur_react_estado_admin (dump de todas sus tablas).
- Manifest SHA-256, conteos y CHECKSUM TABLE de siete tablas antes/después del volcado, gzip íntegro, permisos privados 0700/0600 y marcador que **la restauración todavía NO ha sido ensayada**.

Este kit **no** hace GRANT, INSERT, UPDATE, DELETE, CREATE USER, revocación, cambios de role/password, ni activa U-BASIC. Utiliza únicamente lectura MySQL y mysqldump mediante socket local de root. No solicita ni imprime credenciales ni contenido SQL. El respaldo incluye cuentas, contraseñas hash y sesiones: queda estrictamente en directorio root-only, no se copia a GitHub, tickets, correo o chat.

## Por qué no usamos un backup solo del .env

El incremento K1/JWT del PR #25 ya respaldó .env y lib/powerbi_authz.php. U-BASIC modificará datos MySQL cuando se autorice P4c, por lo que **necesita también un respaldo de BD**. Este respaldo es independiente y no reemplaza el plan de reversión del interruptor P4b.

## Puertas de seguridad

- Instalación actual modo CERVESUR_MODE=admin_escritura.
- ADMIN_WRITES_USUARIOS y ADMIN_WRITES_USUARIOS_SENSIBLE diferentes de 1: U-BASIC/P5 siguen apagados.
- Existen ambas bases cervesur_portalbi y cervesur_react_estado_admin.
- Existen las siete tablas (audit_log incluida); MySQL local accesible por root socket.
- Espacio libre suficiente, preflight sin escrituras.
- SHA y gzip completos, contenido no truncado; conteos y checksums sin cambios durante el respaldo.
- **No se declara respaldo restaurable hasta un ensayo independiente del DBA.** Las credenciales y concesiones (GRANTS) de MySQL no están incluidas en los dumps; el DBA debe mantener su propia evidencia de cuentas, vistas y permisos, que ya se revisaron en P4a.

## Procedimiento de PuTTY

Descargar el script solo desde el commit SHA aprobado y verificar el Git blob SHA esperado antes de ejecutarlo; instalar root-owned en /opt/cervesur-u-basic-kit. El archivo no se instala en ningún DocumentRoot. En la revisión del PR se publica el SHA.

1. Solo lectura (sin generar backup):
   sudo python3 /opt/cervesur-u-basic-kit/backup_u_basic.py preflight

   Resultado esperado: BASE_NEGOCIO=PASS; BASE_ESTADO_ADMIN=PASS; TABLAS_RESPALDO=7; USUARIOS_CUENTA según base actual; USUARIOS_ESCRITURA=OFF; SENSIBLES_ESCRITURA=OFF; P2_PREFLIGHT=PASS_SOLO_LECTURA.

2. Crear respaldo **bajo autorización**, antes de cualquier P4b:
   sudo python3 /opt/cervesur-u-basic-kit/backup_u_basic.py backup --confirm CERVESUR_P2_RESPALDO_USUARIOS

   Resultado esperado: BACKUP_PATH=/var/backups/cervesur-react-admin/u-basic/u-basic-...; BACKUP_INTEGRIDAD=PASS; BACKUP_RESTAURACION_ENSAYADA=NO; MYSQL_MODIFICADO=NO; ACTIVACION_U_BASIC=NO_REALIZADA.

3. Volver a verificar el backup sin exponer ningún dato:
   sudo python3 /opt/cervesur-u-basic-kit/backup_u_basic.py verify --path BACKUP_PATH_REAL

   Debe responder BACKUP_INTEGRIDAD=PASS. El backup es privado y debe conservarse; NUNCA compartir su contenido.

El proceso se detiene ante cualquier discrepancia y conserva los archivos incompletos privados para investigación. No eliminar backups anteriores.

## QA aislado

GitHub Actions ejecuta pruebas automatizadas **sin conectarse a producción**. Incluye:
- modalidad OFF y rechazo ante usuarios/sensibles activos o flags duplicadas;
- conteos, verificación de que contiene audit_log y triggers;
- checksum antes y después (detecta cambio de filas sin cambio de cantidad);
- backup, manifest, gzip, SHA-256, permisos y alteraciones;
- ruta symlink no autorizada, dump truncado y marcador de restauración no ensayada.

## Próximo paso después de P2

Una vez verificado y con respaldo custodiado, **NO encender P4b automáticamente**. Faltan:

1. Cierre de D3/QA HTTP con cuenta QA user/admin: F-10 → 403 de user, A1 no permite perfil ajeno, A2 no permite POST/PUT/DELETE ajenos, confirmación de A-C5/D4 y aprobaciones A-L1/A-L2/A-L3/A-L4.
2. Continuidad del alta de Flutter: RegisterCall del frontend heredado no envía JWT, así que la guardia F-10 instalada puede impedir esa operación; el procedimiento alternativo DBA o el alta U-BASIC deben estar formalmente validados.
3. Prueba de restauración DBA de los respaldos en entorno desechable autorizado, y evidencia separada de GRANTS y v_user_alta.
4. Usar exclusivamente el interruptor oficial de UX-08I de la fase 2B, con autorización de DBA/negocio y QA de capacidad. Dejar P5 sensible permanentemente OFF hasta su propio desarrollo/autorización.
5. P4c: una única operación de usuario expresamente autorizada y comprobación de auditoría atómica.

La incidencia MySQL SELECT del portal usuarios 443 corresponde a identidad SQL distinta y sigue siendo línea de trabajo separada, no motivo para dar por habilitado U-BASIC.
