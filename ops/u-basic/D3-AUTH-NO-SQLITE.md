# Cervesur D3 — Ensayo H1 sin PDO_SQLITE (VPS PHP 8.3)

**12/11/2026 — Correctivo de compatibilidad de laboratorio, no de producción.** El operador obtuvo:
```text
PHP_CLI=8.3.6
PDO_SQLITE=NO
CLI_GETALLHEADERS=NO
D3_KIT_INTEGRIDAD=PASS
No syntax errors detected ...
D3_AUTH=NO_GO_PHP_EXTENSION_O_HEADER
```
Este resultado es **bloqueo del harness original por falta de extensión**, NO un hallazgo de seguridad, NO un fallo del backend y NO revoca P2/P4a/D4 ya aprobados. No instalar paquetes ni habilitar extensiones sobre la VPS solo para esta prueba.

## Alternativa

`d3_auth_no_sqlite.php` usa un `PDO` **simulado** extendido del PDO incorporado en PHP, sin abrir DSN. La clase `D3MemoryPDO` solo permite la consulta estricta `SELECT session_version, is_active, role FROM user WHERE id=:id LIMIT 1`; rechaza cualquier otra, incluyendo escrituras. Devuelve únicamente cuatro registros ficticios. El constructor de PDO padre nunca se invoca. No usa SQLite, MySQL, db.php, contraseñas ni datos reales.

**Alcance de prueba:** H1 y jwt.php reales SHA-pinned; siete comprobaciones de JWT/estado + dos de encabezados, más tres alertas de riesgo residual. Es una prueba de flujo de autenticación con un adaptador simulado **no una prueba del driver MySQL ni de las rutas HTTP reales**. Si H1 cambia su consulta SQL, el adaptador falla cerrado y exige una revisión del harness.

## Operación segura

1. Verificar CI `Cervesur D3 Readonly Gate QA` en PR #32. El CI ejecuta `php -l` y `php d3_auth_no_sqlite.php --fixture-test` sin fuentes ni datos de producción.
2. Descargar desde **commit SHA fijo**, cotejar `git hash-object` contra SHA de blob verificado en GitHub y colocar con permisos 600 en `/opt/cervesur-u-basic-kit/d3_auth_no_sqlite.php`.
3. En PuTTY ejecutar:
```bash
sudo php -l /opt/cervesur-u-basic-kit/d3_auth_no_sqlite.php
sudo php /opt/cervesur-u-basic-kit/d3_auth_no_sqlite.php --fixture-test
sudo php /opt/cervesur-u-basic-kit/d3_auth_no_sqlite.php --backend /var/www/cervesur/backend
```
Solo comunicar los códigos de resultado `D3_AUTH_*`, `D3_RIESGO_*`, `D3_CIERRE_GLOBAL`. No publicar JWT, IP privada, datos personales o archivos fuente reales.

## Dictamen y continuidad

Un `D3_AUTH_CRITICOS=PASS_9_DE_9` significa **PASS limitado al ensayo H1 con PDO simulado**. No cierra los ocho casos originales de D3 ni autoriza `ADMIN_WRITES_USUARIOS=1`. Los vectores URL/método y rutas de alta, controladores/modelos A1/A2, rol real y firmas de seguridad/DBA/funcional aún se revisan por separado. El portal de usuarios 443 tiene tokens opacos distintos del legado 80. F10 requiere procedimiento de alta alternativo firmado y verificable.

**U-BASIC y sensible permanecen OFF.** No hacer merge ni desplegar cambios de app con este comprobador.
