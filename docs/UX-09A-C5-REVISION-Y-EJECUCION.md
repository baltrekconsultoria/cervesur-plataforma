# UX-09A · C-5: revisión previa y hoja de ejecución para el operador

Estado: **revisado y probado en local; NO ejecutado en producción.** La ejecución productiva la hace únicamente el operador autorizado, con la autorización específica `A-C5`. Este documento deja constancia de la revisión pedida antes de ejecutar: **que ni el script ni la plantilla SQL modifican datos ni configuración**.

## 1. Archivos y huellas (verificar antes de usar)

| Archivo | SHA-256 |
|---|---|
| `deploy/auditoria/c5-solo-lectura.sh` | `9a2600d3bf7242e68304355f4ff4d7c003a934c091eaa05551412256b15222a5` |
| `deploy/auditoria/c5-dba-solo-lectura.sql.template` | `7e28a281be9fec257a37de2ccc368dcec91b682aaf20c85a66f4ebea4fc81836` |

El operador compara con `sha256sum` el archivo que va a ejecutar. Si la huella no coincide, **no se ejecuta**.

## 2. Revisión: inventario completo de lo que ejecuta

### 2.1 Script de shell (`c5-solo-lectura.sh`)

| Orden | Comando | Efecto | ¿Modifica algo? |
|---|---|---|---|
| 0 | `date -u`, `id -un`, `hostname` | Imprime hora, usuario, equipo | No |
| 1 | `sha256sum` de 6 archivos de `/var/www/cervesur/frontend` + `cut` | Compara huellas con las del paquete de referencia | No (lee) |
| 2 | `php -v` | Imprime la versión de PHP (no ejecuta código de la aplicación) | No |
| 2 | `grep -rhsE '^[[:space:]]*opcache\.validate_timestamps' /etc/php/*/apache2/` | Imprime **solo esa directiva** | No (lee) |
| 2 | `ls /etc/apache2/mods-enabled/ \| grep` | Lista módulos habilitados (**no** se usa `apache2ctl`) | No (lee) |
| 2 | `ss -ltn \| awk \| grep` | Direcciones y puertos en escucha | No (lee) |
| 2 | `df -h /var/backups /var/www /tmp` | Espacio libre | No (lee) |
| 3 | `readlink -f` sobre cada registro indicado y comprobación de que cae en `/var/log/apache2` y es un `*access*` | Rechaza cualquier otro archivo (`/etc/passwd`, `error.log`) | No |
| 3 | `cat` / `gzip -dc` de los registros de acceso | Lee las líneas **solo para agregarlas** | No (lee) |
| 3 | `awk`, `sort`, `uniq -c`, `sed` | Agregan en memoria y presentan; no escriben archivos | No |

**Lo que no contiene** (comprobado con una búsqueda en las líneas que no son comentarios): `sudo`, `rm`, `mv`, `cp`, `tee`, `chmod`, `chown`, `mkdir`, `touch`, `dd`, redirecciones a archivos (`>>`, `> /...`), `apache2ctl`, `mysql`, `curl`, `wget`, `ssh`, `php -r`, lectura de `.env`.
**Lo que imprime:** conteos por categoría de ruta, mes, código de estado, familia de cliente (por ejemplo `axios`), clase de origen (privada/pública) y las 5 redes /24 con más llamadas. **No imprime** líneas de registro, rutas completas, cadenas de consulta, cabeceras, cookies, referentes ni direcciones IP completas.

### 2.2 Plantilla SQL (`c5-dba-solo-lectura.sql.template`)

Todas las sentencias son de estos cinco tipos:

| Tipo | Cantidad aprox. | Efecto |
|---|---|---|
| `SET SESSION TRANSACTION READ ONLY;` | 1 | Solo la sesión del DBA; **no** cambia configuración del servidor |
| `START TRANSACTION READ ONLY;` / `COMMIT;` | 1 + 1 | Transacción de solo lectura (cualquier escritura en esa sesión es rechazada por el motor) |
| `SELECT` | 31 | Lectura de metadatos y conteos |
| `SHOW` (`CREATE TABLE`, `INDEX`, `TRIGGERS`, `GRANTS`, `DATABASES`) | 6 (más las de `SHOW GRANTS FOR` que el DBA descomente) | Lectura de estructura y privilegios |

Nunca selecciona contraseñas, hashes, correos, nombres, teléfonos, enlaces de informes ni secretos de empresa: solo conteos, estructuras y nombres de cuentas/objetos. Las líneas de `SHOW GRANTS FOR` de otras cuentas están comentadas: **las completa y descomenta el DBA**.

## 3. Pruebas que respaldan la revisión (26/26 en local, `probar-c5.sh`)

| Prueba | Qué demuestra |
|---|---|
| Ejecución del shell con la **raíz del contenedor en solo lectura** y sin directorio temporal | No escribe nada |
| Registros sintéticos con una cadena de consulta que contiene un «secreto», una IP pública, una línea rota y un archivo no permitido | El secreto no sale, no sale ninguna IP completa ni ruta, la línea rota se descarta y declara, y los archivos no permitidos se rechazan |
| Sin archivos válidos | Termina con código 3 sin imprimir agregados |
| Plantilla SQL en MySQL 8.0 con el **registro general de sentencias activado** | El servidor solo recibió `SET`, `START`, `SELECT`, `SHOW` y `COMMIT` |
| Filas y sumas de control de las tablas antes y después | Idénticas: nada cambió |
| Un `UPDATE` dentro de la misma sesión de solo lectura | Rechazado por el motor |
| Búsqueda de columnas sensibles en la plantilla y de valores sintéticos sensibles en la salida | Ninguno |

Limitaciones honestas: se probó con datos sintéticos y MySQL 8.0 de prueba; el servidor real puede tener otra versión (la propia plantilla la informa) y las líneas de registro reales pueden tener otro formato (el script descarta y cuenta las que no reconoce).

## 4. Hoja de ejecución para el operador (PuTTY)

**Antes:** autorización `A-C5` firmada (destino `192.168.200.12`, cuenta del operador, solo lectura); huellas del punto 1 verificadas. Desactivar el registro de sesión de PuTTY **no** es necesario (la salida no contiene secretos), pero se revisa la salida antes de compartirla.

**Parte 1 · sistema y registros (operador, con la cuenta que pueda leer `/var/log/apache2`; usar `sudo` solo si hace falta para leer):**
```
bash -s -- /var/log/apache2/access.log /var/log/apache2/access.log.1 /var/log/apache2/access.log.2.gz /var/log/apache2/access.log.3.gz <<'EOS'
<pegar aquí el contenido de c5-solo-lectura.sh>
EOS
```
Indicar **todos los archivos de acceso que existan** (el periodo cubierto sale en la salida). Guardar como `salida-c5-sistema-<fecha>.txt` (copiar la pantalla; el script no escribe archivos).

**Parte 2 · base de datos (DBA, con su propia cuenta; la clave se escribe en el aviso, nunca en la línea de comandos):**
```
mysql --table -u <cuenta_dba> -p < c5-dba-solo-lectura.sql.template > salida-c5-dba-<fecha>.txt
```
Antes, el DBA abre la plantilla, completa y descomenta las líneas de `SHOW GRANTS FOR` con los nombres de las cuentas que usan Flutter y React. Si prefiere, revisa la salida antes de entregarla.

**Qué se devuelve:** los dos archivos de salida, sin editar. **Qué no se devuelve:** nada más (no `.env`, no credenciales).

## 5. Cómo se leerán los resultados

| Dato | Decisión que habilita |
|---|---|
| Llamadas a `K1_tokens_powerbi` por mes, cliente y origen | K1 «retirar»: si no hay uso legítimo (solo exploradores o ninguna llamada reciente), se programa el 410 |
| `A3_registro_POST` por mes, cliente y origen; altas por mes en `user` (SQL §6) | Consumidores legítimos y frecuencia de altas: dimensiona el procedimiento temporal y decide si F-10 puede cerrarse en la ventana del Lote 4 (`UX-09A-F10-REGISTRO-PUBLICO.md`) |
| `A1_perfil_*`, `EMPRESAS_escritura`, `REPORTES_escritura`, `ACCESOS_escritura` por cliente y origen | Si hay escrituras de clientes o redes que no sean la función de Flutter y la red interna: incidente a investigar antes de los lotes |
| Familia de cliente `axios_o_node` y redes públicas | Confirma que las llamadas de Flutter llegan desde Internet (R-CFN) |
| Huellas del frontend | Cierra P-1 para los 6 archivos |
| PHP, `opcache`, puertos y espacio | P-8 y espacio de P2 |
| SQL: versión, `user`, índices, duplicados, cuentas, vistas, triggers, R-PUB, secretos | Requisitos de P2/P4a, D-5 (cuántas empresas tienen secreto) y R-PUB (cuántos informes son «publicar en la web») |

Si C-5 identifica **consumidores legítimos** de `/api/register` o del token de Power BI, **se evalúa su continuidad antes del cierre**: no se cierra nada hasta decidir cómo se atiende ese consumidor.
