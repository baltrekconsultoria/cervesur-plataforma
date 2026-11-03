# UX-08G · Plan de migraciones, cuenta de escritura y habilitación progresiva de escrituras (Fase 2 · NADA de esto está en el paquete de la versión 1)

Estado: **diseño verificable, sin ejecutar nada**. La versión 1 del administrador (`docs/UX-08G-INFORME-LIBERACION.md`) es de solo lectura y **no incluye migraciones**; el preflight y el empaquetador lo exigen (`database/migrations` ausente del paquete).

## 0. Por qué las escrituras son una fase aparte (hallazgo central)
El administrador publicado corre en el modo **`piloto_admin`** (`backend/lib/mode.php`). Ese modo, por diseño y con pruebas, impide cualquier escritura sobre `cervesur_portalbi` con **cinco capas independientes**: (1) puerta de rutas (solo GET de lectura + login/logout), (2) `GuardedPDO` (solo `SELECT` de un catálogo cerrado de sentencias), (3) sesión MySQL `READ ONLY`, (4) al arrancar rechaza servir si la cuenta tiene algún privilegio distinto de `SELECT`/`USAGE`, (5) `SELECT` por columna. Además `app_mode_evaluate` rechaza `CERVESUR_ALLOW_WRITABLE_DB_ACCOUNT=SI` sobre una base productiva.

Las escrituras de UX-08 (usuarios, empresas, informes, accesos) necesitan **justo lo que ese modo prohíbe**: una cuenta con `INSERT`/`UPDATE` sobre la base de negocio y rutas `POST/PUT/DELETE`. Por tanto **no existe un interruptor que las habilite**: habilitarlas exige un modo nuevo (propuesto: `admin_escritura`) con su propia cuenta, su propio catálogo de sentencias y su propia puerta de rutas, probado igual de a fondo. En la versión 1 está probado (estática y HTTP) que `USER_ADMIN_WRITES=1` en el entorno **no abre nada**.

## 1. Modo `admin_escritura` (diseño, no implementado)
| Capa | Diseño | Cómo se demuestra que está cerrada cuando está apagada |
|---|---|---|
| Modo | `CERVESUR_MODE=admin_escritura` solo si `CERVESUR_REQUIRED_MODE` lo exige; en cualquier otro caso rutas de escritura inexistentes (404/403) | prueba HTTP por ruta con el modo `piloto_admin` (ya hecha: 14 rutas nuevas de UX-08 → 403) |
| Interruptores por capacidad | `ADMIN_WRITES_EMPRESAS`, `ADMIN_WRITES_REPORTES`, `ADMIN_WRITES_ACCESOS`, `ADMIN_WRITES_USUARIOS`, cada uno **exactamente `1`**, por defecto apagado; la puerta de rutas comprueba **método + ruta + interruptor** antes de cualquier controlador | una prueba por interruptor y por ruta: apagado → 403 sin tocar la base (checksum de tablas idéntico) |
| Cuenta MySQL | nueva `cervesur_admin_w` (§3), distinta de las `cervesur_ro_*` y de `cervesur_portalbi_admin` de Flutter | `SHOW GRANTS` sin DELETE/ALL/GRANT OPTION/DDL/TRIGGER; prueba de API completa conectada con esa cuenta (hecha en QA con la plantilla UX-08E) |
| Auditoría | `AUDIT_LOG_MODE=required`: si no se puede escribir `audit_log` en la misma transacción, la operación se revierte | `http_writes_test` + `audit_immutability_test` (ya en la CI) |
| Usuarios | **último** interruptor; requiere antes cerrar R1–R5 (§4) | `http_users_admin_test` (149) + pruebas sobre copia con el backend de Flutter |

## 2. Migraciones (una por una; ninguna se ejecuta en esta entrega)
Identidad: la **cuenta administradora de MySQL del operador** (`root@localhost`, SUPER confirmado), solo durante la ventana; nunca la cuenta PHP. `log_bin=1` y `log_bin_trust_function_creators=0` **no se cambian**: con SUPER la creación de triggers funciona (demostrado en la réplica; sin SUPER → ERROR 1419).

| # | Migración | DDL exacto | Precondición | Efecto en Flutter | Bloqueo | Verificación | Reversión |
|---|---|---|---|---|---|---|---|
| 1 | UX-08B `audit_log` | `CREATE TABLE IF NOT EXISTS audit_log (id BIGINT UNSIGNED AUTO_INCREMENT PK, correlation_id CHAR(32), occurred_at DATETIME(3), actor_user_id INT UNSIGNED, action VARCHAR(40), entity VARCHAR(40), entity_id INT UNSIGNED, before_json JSON, after_json JSON, request_ip VARCHAR(45), 3 índices) ENGINE=InnoDB utf8mb4_unicode_ci` | la tabla no existe (idempotente por `IF NOT EXISTS`); comprobar `SHOW TABLES LIKE 'audit_log'` | ninguno (tabla nueva, sin FK a tablas de negocio) | ninguno sobre tablas existentes | 10 columnas en `information_schema.COLUMNS` | `DROP TABLE audit_log` **solo si está vacía**; con filas NO se borra (es evidencia): se deja y se desactiva el modo |
| 2 | UX-08B.2 triggers de solo inserción | `DROP TRIGGER IF EXISTS …; CREATE TRIGGER audit_log_no_update BEFORE UPDATE ON audit_log … SIGNAL SQLSTATE '45000'` y `audit_log_no_delete` | existe `audit_log`; la identidad tiene SUPER (o TRIGGER+trust) | ninguno | metadato breve sobre `audit_log` únicamente | 2 triggers en `information_schema.TRIGGERS`; `UPDATE`/`DELETE` fallan con «solo insercion» | `DROP TRIGGER …` (seguro, no toca datos). Quitarlos permite alterar la bitácora: requiere decisión escrita |
| 3 | UX-08B3 `grupo_id NULL` (**opcional, pospuesta**) | `ALTER TABLE reporte MODIFY grupo_id VARCHAR(50) NULL` | `grupo_id VARCHAR(50) NOT NULL`; **antes: validar Flutter con `grupo_id NULL` sobre una copia** | **riesgo real**: Flutter podría fallar al leer `grupo_id NULL` (no verificado) | `ALTER` en línea sobre `reporte` (tabla pequeña, ~95 filas) | `IS_NULLABLE='YES'` | `down` se niega si hay `grupo_id NULL`; si hay, **no es reversible sin decidir qué grupo asignar** |
| — | UX-07C (`reporte` 36/1024) | **NO se aplica** | — | acorta `grupo_id` y cambia `enlace` | — | — | — |

Orden y puertas: 1 → 2 → (3 solo tras la validación de Flutter). Cada una con respaldo lógico previo (`mysqldump --no-data` del esquema + `CHECKSUM TABLE` de las seis tablas antes y después), ventana acordada y verificación posterior; si la verificación falla, se detiene (no se continúa a la siguiente).

## 3. Cuenta de escritura de producción (plantilla `database/privileges/UX-08E-cuenta-admin-minima.sql.template`, ya probada en QA)
Exigencias cumplidas por la plantilla: **sin** `DELETE`, `ALL PRIVILEGES`, `GRANT OPTION`, `ALTER/DROP/CREATE/TRIGGER`; `INSERT` solo en las seis tablas de negocio y en `audit_log`; `UPDATE` **por columnas**; `audit_log` solo `INSERT`+`SELECT`; ningún permiso sobre otras bases (ni las QA).
**Diferencia que hay que cerrar antes de usarla en producción:** la plantilla concede `SELECT` sobre `\`{DB}\`.*` (todas las columnas). En producción debe ser **por columnas** (excluyendo `user.password` salvo donde el flujo de inicio de sesión lo exija y `empresa.powerbi_clientsecret`). Esa variante **no está escrita ni probada todavía**: se derivará ejecutando `http_min_privileges_test.php` con la lista de columnas hasta que pase. Pendiente (P-12).
Nombre propuesto: `cervesur_admin_w`@`127.0.0.1` (comprobar que no existe; no reutilizar `cervesur_portalbi_admin` de Flutter). No se crea en esta entrega.

## 4. Seguridad de Usuarios frente a Flutter (R1–R5): condiciones para habilitar las escrituras de Usuarios
| Riesgo | Estado | Condición de cierre |
|---|---|---|
| R1 tokens heredados sin `session_version` | Verificado en el código original (compara solo si el token trae versión) | Medir la vida real del JWT original (P2 en `flutter-original-solo-lectura.sh`); regla operativa: tras desactivar/cambiar rol/contraseña, el acceso por Flutter se revoca **al caducar el token**; documentarlo al operador |
| R2 reactivar con token heredado vigente | Sin control técnico desde React | Misma regla: no reactivar a quien se desactivó por incidente hasta que venza el token; o rotar `JWT_SECRET` de Flutter (decisión aparte, cierra todas las sesiones de Flutter) |
| R3 rol tomado del JWT | Verificado | El cambio de rol en React no cambia el rol del token de Flutter hasta que caduque; el diálogo de la interfaz lo advierte |
| R4 `session_version` compartido | Verificado (logout de Flutter sube la versión) | React ya no depende de ella para la lectura (modo piloto); en el modo de escritura se usa solo para revocar |
| R5 escrituras concurrentes de Flutter sin coordinación | Verificado (Flutter no usa transacciones/versión) | React usa bloqueo de filas + versión optimista; no puede impedir que Flutter pise: aceptar o ventana de mantenimiento |
Antes de habilitar `ADMIN_WRITES_USUARIOS`: P1, P2, P5–P8 de Flutter con evidencia real, y las pruebas sobre **una copia aislada** con el backend original de Flutter apuntando a esa copia (login activo/inactivo, tokens heredados, cambio de rol, desactivar/reactivar, cambio de contraseña, logout cruzado, concurrencia, roles `admin`/`user`/`usuario`). Ese arnés **no existe todavía** (P-13).

## 5. Habilitación progresiva (cada paso exige autorización expresa y es reversible con un solo cambio de configuración)
0. **Versión 1 (esta entrega):** solo lectura. Ningún interruptor.
1. Migraciones 1 y 2 (sin efecto funcional); verificación; observación 1 semana.
2. Modo `admin_escritura` instalado con **todos** los interruptores apagados; verificar que cada ruta de escritura responde 403.
3. `ADMIN_WRITES_ACCESOS` → `ADMIN_WRITES_EMPRESAS` → `ADMIN_WRITES_REPORTES` (uno por vez, con una semana de observación y revisión de `audit_log`).
4. `ADMIN_WRITES_USUARIOS` solo tras cerrar §4.
5. Migración 3 (`grupo_id NULL`) solo tras validar Flutter y si el negocio necesita informes «Publicar en la web» sin grupo.
Marcha atrás de cada paso: apagar el interruptor (efecto en la siguiente petición). **Lo ya confirmado no se deshace**: no hay `DELETE` físico; las bajas son lógicas y se compensan reactivando; `audit_log` se conserva siempre.

## 6. Reversión: qué se puede y qué no
| Qué | Cómo | Límite |
|---|---|---|
| Código | `actualizar.sh --revertir <respaldo>` (restaura `public` y `backend`, conserva `.env`) | no deshace datos |
| Configuración | restaurar el `.env` desde la copia guardada antes (los respaldos del kit NO incluyen `.env` por seguridad: el operador guarda su copia aparte) | — |
| DDL | tabla §2 | `audit_log` con filas y `grupo_id NULL` con filas **no** se revierten solos |
| Datos de negocio ya escritos | compensación manual vía la propia aplicación (reactivar/desactivar) y consulta de `audit_log` (`before_json`/`after_json`) | **no hay rollback automático de datos** después de habilitar escrituras |
