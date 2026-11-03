# UX-08F.1 · Corrección del preflight y preparación del piloto QA aislado

Fecha: 2026-11-03 · Rama `feat/cervesur-ux-08a-active-filters`. Nada se instaló, ejecutó ni transfirió a ningún servidor.

## 1. Diagnóstico del puerto 8090
El paquete tenía `8090` fijo (`lib.sh`, plantilla Apache, verificador, ensayo, README). El servidor real tiene Apache escuchando en `127.0.0.1:8090-8093`. El instalador ya rechazaba un puerto ocupado, pero (a) 8090-8093 no figuraban como puertos ajenos, (b) no había forma de elegir otro. Con el estado real habría dado NO-GO sin alternativa.

## 2. Solución
- Puerto **explícito** (`--puerto N` o `CERVESUR_QA_PUERTO`), sin valor por defecto ni cambio silencioso; `8094` es solo candidato propuesto.
- Rechazo explícito (NO-GO, sin cambios) de: 80, 443, 8443, 8088-8093, 22, 3306, 33060; valores mal formados (incluida inyección de órdenes); puertos con listener (cualquier interfaz), mencionados en Apache o presentes en `apache2ctl -S`.
- Plantilla Apache con `@PUERTO@`; el puerto se **registra** en `/var/lib/cervesur-react-admin-qa/puerto` y `verificar.sh`/`revertir.sh` usan exactamente ese (otro valor aborta).
- La directiva `Listen` del sitio nuevo y la recarga suave son una operación que requiere aprobación expresa aparte. No se tocó Apache existente.
- Comprobación previa de solo lectura para el operador: `deploy/auditoria/puerto-candidato-solo-lectura.sh`.

## 3. Triggers con binary logging
- Servidor: MySQL 8.0.46, `log_bin=1`, `log_bin_trust_function_creators=0`. Con esa combinación `CREATE TRIGGER` exige `SUPER`.
- **Evidencia en la réplica:** una cuenta administradora con TRIGGER/CREATE/ALTER/DROP… pero sin SUPER falla con ERROR 1419 y no deja triggers; la misma migración con `root` (socket) crea los dos.
- Diseño: las migraciones las aplica la cuenta **administradora** del operador solo durante la instalación; no se activa `log_bin_trust_function_creators`, no se concede SUPER; `cervesur_qa_admin_w` no tiene TRIGGER, DDL ni DELETE.
- Nuevo preflight: versión MySQL ≥ 8.0.21 y capacidad de crear triggers (lectura de variables y de `SHOW GRANTS`). Sin SUPER → NO-GO antes de crear nada (probado también en instalación real).
- Fallos: migración de triggers alterada → el instalador revierte solo lo de ese intento (sin cuenta, sin sitio, sin restos). SIGKILL a mitad → `revertir.sh` limpia usando el puerto registrado. El verificador da NO-GO si falta un trigger.

## 4. Correcciones al instalador (además del puerto)
- Resumen GO/NO-GO en la simulación (puerto y estado, base, cuenta, MySQL+binlog, dependencias HTTP, interruptores).
- Bloqueo global `/run/lock/cervesur-react-admin-qa.lock` para instalar/revertir; `mkdir` exclusivo de la carpeta de estado (código 3); token por intento: un intento perdedor **no puede revertir** la instalación del ganador (hallazgo propio: antes, dos instalaciones simultáneas desde copias distintas podían pisarse).
- Contraseñas sintéticas dentro de la carpeta de estado (600) en vez de `/tmp`; `instalar.sh` ya no usa `mktemp`.
- Preflight de `USER_ADMIN_WRITES` en los portales publicados.

## 5. `--simular` no modifica el servidor
Pruebas del ensayo: posición del binlog de MySQL idéntica antes/después (cero sentencias de escritura); `find -cnewer` sobre `/etc /opt /var/www /var/backups /var/lib /usr/local /tmp /var/tmp` vacío; instantánea estricta de Apache, árboles, bases, cuentas, privilegios, puertos, módulos y respuestas HTTP idéntica; auditoría estática del código previo a la salida (sin órdenes ni SQL de escritura). Efecto en disco: ninguno (no hay archivos temporales).

## 6. Auditoría de divergencias / 403 / interruptor
- `inventario-divergencia.sh` y `flutter-original-solo-lectura.sh` siguen válidos (revisados) pero **no** explican el 403. Nuevo `autorizacion-403-solo-lectura.sh [--mysql]`: líneas del 403, origen de la lista (variables de entorno, archivos, constantes, tablas) y, con `--mysql`, tablas/columnas candidatas vía `information_schema` (sin filas). Enmascara URL, GUID y cadenas largas y no muestra líneas con palabras sensibles (12 comprobaciones PASS con un backend simulado lleno de secretos).
- Nuevo `interruptor-escrituras.sh`: instancia · estado efectivo · fuente · PASS/FAIL. Replica `env.php` + `write_support.php`; verificado contra el código PHP real en 11 casos (comillas, repeticiones, CRLF, comentarios, `export`), y FAIL ante SetEnv de Apache, comparación laxa, carpeta ausente. Si no se demuestra apagado → NO-GO.
- Aún **desconocido** (requiere la salida del servidor): mecanismo real del 403, lista de aprobación, DB031 id 72, P1, P2, P5–P8.

## 7. Transferencia
`docs/UX-08F1-GUIA-OPERADOR-WINDOWS.md` (PowerShell + pscp/scp, SHA-256, verificación en Ubuntu, carpeta temporal propia, auditorías, simulación, evidencia). Dos paquetes: `deploy/auditoria/empaquetar-auditoria.mjs` (nuevo) y `deploy/qa-admin-aislado/empaquetar.mjs`.

## 8. Resultados (esta versión, no heredados)
| Comprobación | Resultado |
|---|---|
| Ensayo Docker `ensayo-docker.mjs` | **145 PASS / 0 FAIL** (antes 91) |
| shellcheck (scripts del paquete + auditoría) | limpio |
| `probar-autorizacion-403.sh` | 12 PASS / 0 FAIL |
| `git diff --check` | limpio |
| Paquete de auditoría construido (9 archivos, sin `.env` ni claves) | OK |
Casos nuevos: 8090-8093 y puertos ajenos rechazados, 13 valores malformados, puerto alternativo válido, puerto ocupado (127.0.0.1 y 0.0.0.0), mención en Apache, dos instalaciones simultáneas, base/cuenta preexistentes, fallo de triggers, administrador sin SUPER, SIGKILL + reversión, portales señuelo y `cervesur_portalbi` idénticos, interruptor, aislamiento por loopback. No se repitieron vitest/PHP/CI HTTP: el código backend y frontend no cambió.

## 9. Riesgos pendientes
- El ensayo no es el servidor: el privilegio SUPER de la cuenta administradora real, `mod_headers` por vhost y la cuenta `@127.0.0.1` se confirman con la simulación real.
- El candidato 8094 no está verificado en el servidor.
- Un SIGKILL deja un `/tmp/tmp.*` con SQL de semilla (solo hashes) hasta borrarlo.
- Mecanismo del 403/DB031 y pendientes Flutter siguen sin evidencia.
- CI de GitHub nunca ejecutada.

## 10. GO / NO-GO
- **GO** para la auditoría real de solo lectura (scripts revisados, transferencia reproducible, sin datos sensibles).
- **GO condicionado** para la simulación real: tras `puerto-candidato-solo-lectura.sh 8094` e `interruptor-escrituras.sh` en PASS y revisión del operador.
- **NO-GO** para instalar hasta: simulación real en GO, puerto aprobado, aprobación expresa del `Listen` en Apache, cuenta administradora con SUPER confirmada, y autorización expresa de instalar. Producción fuera de alcance.
