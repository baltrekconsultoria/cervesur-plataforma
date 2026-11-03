# UX-08F · Informe: preparación del piloto administrativo aislado (QA)

**Nada se instaló ni se ejecutó en ningún servidor.** No hay push, merge ni deploy; no se tocó `cervesur_portalbi`, cuentas MySQL, Apache, Flutter, HTTPS ni VPN; el checkout de SEC-05A conserva sus 20 archivos pendientes. Todo lo «verificado» abajo se probó en Docker o en el repositorio; lo que depende del servidor real figura como **hipótesis**.

Documentos: `deploy/qa-admin-aislado/README.md` (instalación), `UX-08F-DIVERGENCIA-REPO-SERVIDOR.md`, `UX-08-COMPATIBILIDAD-FLUTTER.md`, `UX-08C-COMPATIBILIDAD-DESPLEGADA.md`, `UX-08E-PRIVILEGIOS-MINIMOS.md`, `UX-08B2-PLAN-DESPLIEGUE.md` §5.

## 1. Veredicto: criterios GO / NO-GO para instalar el piloto QA

| # | Criterio | Estado |
|---|---|---|
| G1 | El paquete se instala, verifica y revierte sin dejar rastro, en un servidor emulado con Flutter y portales señuelo | **Cumplido (Docker)**: 91 PASS / 0 FAIL, ejecutado por dos vías independientes |
| G2 | Aborta sin cambios ante cualquier colisión, base `portalbi`, cuenta existente o paquete alterado | **Cumplido (Docker)** |
| G3 | La cuenta de la aplicación no tiene `DELETE`, `ALL PRIVILEGES`, `GRANT OPTION` ni permisos sobre `cervesur_portalbi` | **Cumplido (Docker)** |
| G4 | Escucha solo en `127.0.0.1:8090`; los otros portales quedan byte a byte idénticos | **Cumplido (Docker)** |
| G5 | Operador aprueba el procedimiento y la ventana | **Pendiente** |
| G6 | Confirmar que el servidor real cumple los supuestos de §10 del README (MySQL ≥ 8.0.21, `mod_headers`, triggers bajo binlog, cuenta `@127.0.0.1`) | **Pendiente**: `--simular` lo comprueba sin cambiar nada |
| G7 | Los `.env` de los portales publicados no tienen `USER_ADMIN_WRITES=1` | **Pendiente de lectura** (`inventario-divergencia.sh`, sección 8) |

**Recomendación:** GO para ejecutar `instalar.sh --simular` en el servidor (solo lectura). GO para la instalación real **después** de G5–G7. NO-GO para producción, para escribir en `cervesur_portalbi` y para tocar los portales publicados.

## 2. Arquitectura QA propuesta (D1)

| Elemento | Valor |
|---|---|
| Acceso | `127.0.0.1:8090` solo loopback; túnel SSH `ssh -N -L 18091:127.0.0.1:8090 <usuario>@<servidor>` → `http://localhost:18091` |
| Archivos | `/var/www/cervesur-react-admin-qa` (frontend), `/opt/cervesur-react-admin-qa` (backend), `/etc/apache2/cervesur-react-admin-qa`, `/var/lib/cervesur-react-admin-qa/sessions` |
| Base y cuenta | `cervesur_qa_admin` (tipos productivos + 8 `UNIQUE` reales) y `cervesur_qa_admin_w`@`127.0.0.1` (plantilla UX-08E, contraseña generada en el servidor) |
| Interruptores | `AUDIT_LOG_MODE=required`, `REACT_STRICT_VIEWS=1`, `USER_ADMIN_WRITES=1` (**solo QA**), `ADMIN_VIEWER_ALLOWED_REPORT_IDS=31` (informe sintético que representa a DB031; el id real 72 no figura en el paquete) |
| Datos | 100 % sintéticos: 76 usuarios (62 activos), 95 informes (82 activos), los tres roles `admin`, `user`, `usuario` |
| Garantías | No usa ni escribe en `cervesur_portalbi`; no hay segunda base operativa definitiva; no reutiliza directorios, puertos ni cookies de los portales publicados |

Mapeo de las decisiones: **D1** → base y cuenta propias sin `DELETE`; **D2** → `audit_log` registra el negocio y `auditoria` del piloto no se toca ni se fusiona; el instalador aplica y verifica la migración antes de fijar `AUDIT_LOG_MODE=required`, y sin la tabla las escrituras fallan sin cambios parciales (probado en `http_writes_test`); **D3** → ver §6; **D4** → ver §8.

## 3. Divergencia entre el repositorio y el servidor

Detalle y política en `UX-08F-DIVERGENCIA-REPO-SERVIDOR.md`.

| Hecho | Estado |
|---|---|
| Siete archivos del piloto administrativo existen en el servidor y **ninguno está versionado**: `lib/piloto_catalogo.php`, `lib/estado_piloto.php`, `lib/admin_catalogo.php`, `lib/mode.php`, `lib/pilot_sesion.php`, `router/AdminRouter.php`, `router/PilotoRouter.php` | **Verificado** (nombres aportados + `git ls-files`) |
| Qué contienen, de cuál sale el 403 y dónde está la lista de aprobación | **Hipótesis / desconocido** |
| Cuántos de `views.php`, `powerbi_link.php`, `ReporteRouter.php` difieren | **Desconocido** hasta ejecutar el inventario |

**Política:** prohibido reemplazar los directorios publicados; UX-08 entra primero en la instancia QA nueva, y un eventual paso al piloto real solo por superposición aditiva en un directorio nuevo, conservando los componentes exclusivos y comprobando que los marcadores de seguridad no disminuyen.

## 4. Comandos de PuTTY que faltan (todos de solo lectura)

| Script | Qué cierra |
|---|---|
| `deploy/auditoria/inventario-divergencia.sh <backend>` (×3: admin, usuarios, original) | Divergencia exacta, dependencias de los componentes del piloto, rutas, marcadores de seguridad y los cuatro interruptores de UX-08 |
| `deploy/auditoria/flutter-original-solo-lectura.sh` | P1 (login), P2 (JWT), P5/P6 (usuarios en el original), P7 (transacciones), P8 (cuentas y privilegios efectivos) |
| `UX-08C-COMPATIBILIDAD-DESPLEGADA.md` §7 | Función del 403, fuente de la aprobación, estado del informe, entrega del enlace |

Ninguno imprime `.env`, contraseñas, claves, hashes ni enlaces completos (se enmascaran las URL). `generar-manifiesto.sh` debe ejecutarse en el commit exacto a publicar.

## 5. Compatibilidad Flutter / React: los cinco riesgos y su control

«Verificable» significa que existe una prueba o un comando que lo comprueba.

| # | Riesgo | Control | Evidencia | Residual |
|---|---|---|---|---|
| 1 | Token heredado **sin** `session_version` | React lo rechaza (401). En el original no se revoca | Matriz de sesiones (69), simulación del original | **Real en Flutter** hasta que caduque el token; su duración se conoce con P2 |
| 2 | Reactivar un usuario con un token heredado aún vigente | React: tokens previos no reviven (`session_version+1`). Original: un token sin versión vuelve a servir | `http_users_admin_test` (simulación, casos b y c) | **Regla operativa propuesta:** tras una baja por seguridad, no reactivar antes de que venza el token heredado (P2) |
| 3 | Cambio de rol con un token de Flutter que conserva el rol anterior | React: efecto inmediato y cierre de sesión. Original: el rol del token | Simulación caso d; el diálogo de la interfaz lo advierte | **Real en Flutter** (un administrador degradado conserva poder hasta caducar) |
| 4 | Logout de una app que invalida las sesiones de la otra | En la QA no ocurre: tiene su propia tabla `user`. Al unificar la base ocurriría (el invalidador es la fila, no el secreto) | Razonado por el código (O5); probar en una copia con tabla compartida | **Solo al unificar**; QA sobre copia antes |
| 5 | Operaciones concurrentes de ambas apps sobre las mismas tablas | React: bloqueo de filas + `UNIQUE` + versión optimista, que detecta cambios hechos por Flutter entre la lectura y el guardado | Suites de concurrencia (escrituras, accesos, usuarios) | Flutter escribe sin coordinación: puede pisar un cambio de React |

**Consecuencia:** no se habilita ninguna escritura de usuarios contra producción hasta (a) conocer P1, P2, P5–P8, (b) ejecutar la QA sobre una **copia** con los casos 1–10 de `UX-08-COMPATIBILIDAD-FLUTTER.md` §5.2 y (c) fijar la regla del riesgo 2. En la QA (base propia) el único riesgo que sigue activo es el 5, y es inofensivo allí.

### Compatibilidad del backend original con `grupo_id NULL` (procedimiento, **no construido**)
Hipótesis a verificar en una **copia aislada**, nunca en producción: (1) instalar la QA con `--aplicar-migracion-grupo-nullable`; (2) copiar el código del backend original a un directorio nuevo, sin modificarlo, apuntando a la base QA con una cuenta propia; (3) servirlo en un puerto loopback distinto; (4) listar y abrir con él un informe sin grupo y leer errores PHP y JSON (`grupo_id: null`); (5) si el cliente Flutter lo permite, probarlo con ese API. Si algo falla, la migración no se aplica en producción. Requiere P8 y una copia del cliente; queda como siguiente incremento.

## 6. Roles (D3) y migraciones en QA

| Operación | Valor de rol | Estado |
|---|---|---|
| Datos existentes | `usuario` (62), `user` (10), `admin` (4): **no se normalizan ni se reescriben** | Probado |
| Alta de usuario (`POST /api/register`) | `user` por defecto; **`usuario` con `NEW_USER_ROLE=usuario`** (configuración del servidor; jamás de la petición; cualquier otro valor se ignora) | Probado: 6 casos unitarios y, en la API, el usuario creado con `usuario` inicia sesión y recibe 403 en rutas administrativas |
| Cambio de rol (`PUT /api/user/{id}/role`) | Asigna `admin` o `user`; `usuario` solo como no-op sobre quien ya lo tiene | Probado |
| Autorización | `usuario` ≡ `user` (trabajador); ambos 403 en rutas administrativas | Probado en la QA para los tres valores |

Elegir `usuario` para altas productivas queda **sujeto a QA real de Flutter y React** (D3): el interruptor existe para poder probarlo, no para activarlo.

Migraciones en QA, en orden y cada una verificada antes de la siguiente: `UX-08B-audit-log` → `UX-08B-audit-log-immutable` → (opcional, por bandera) `UX-08B3-reporte-grupo-nullable`. Probadas en Docker: subida, reaplicación idempotente, reversión rechazada con informes sin grupo (datos intactos), reversión correcta, reinstalación tras revertir.

## 7. Matriz de cuentas y privilegios

### QA (nuevas)
| Cuenta | Quién la usa | Privilegios |
|---|---|---|
| `cervesur_qa_admin_w`@`127.0.0.1` | La API administrativa QA | `SELECT` en `cervesur_qa_admin`; `INSERT` en las 6 tablas y `audit_log`; `UPDATE` por columnas (sin `username`, sin `powerbi_*`); `audit_log` solo `INSERT`/`SELECT`. **Sin** `DELETE`, DDL, `TRIGGER`, `GRANT OPTION`, ni nada sobre `cervesur_portalbi` |
| Administrador MySQL (`sudo mysql`) | Solo el instalador, para crear base, cuenta y triggers | Fuera de la aplicación; la contraseña de la cuenta de la API no se imprime |

### Producción (existentes; **no se tocan**)
| Cuenta | Hoy | Plan independiente (D4) |
|---|---|---|
| `cervesur_portalbi_admin`@localhost | `ALL PRIVILEGES` sobre `cervesur_portalbi.*` | 1) cerrar P5/P6/P8 para saber si Flutter usa `DELETE` físico, 2) registrar el consumo real, 3) proponer privilegios por tabla, 4) ventana autorizada, 5) reversión = restaurar el `GRANT` anterior |
| `cervesur_react_estado*` | `SELECT, INSERT, UPDATE, DELETE` sobre sus bases de estado | Retirar `DELETE` si el piloto no lo usa |
| `cervesur_ro_*`, `cervesur_react`, `cervesur_react_compat` | Sin `SHOW GRANTS` todavía | Obtenerlos (P8) |

## 8. Instalación aislada y reversión

Procedimiento completo en `deploy/qa-admin-aislado/README.md`: empaquetar (`empaquetar.mjs`, tarball + SHA-256, sin `.env` ni secretos) → copiar y verificar → `instalar.sh --simular` (solo lectura) → `instalar.sh` (pide `INSTALAR-QA-ADMIN`; respalda `/etc/apache2`, guarda la línea base, crea solo recursos marcados, recarga Apache de forma suave, corre `verificar.sh`) → túnel → checklist QA manual. **Reversión:** `revertir.sh` (pide `REVERTIR-QA-ADMIN`) elimina únicamente lo que el instalador marcó, se niega a borrar recursos sin marca, deja registro y deja el servidor idéntico a la línea base; un segundo `revertir` es un no-op. Un fallo a mitad de instalación se revierte solo.

## 9. Verificado frente a hipótesis

| Verificado (Docker / repositorio) | Hipótesis (servidor real) |
|---|---|
| Instalación, verificación, rutas negativas, reversión y reinstalación (91) | Que el servidor cumpla los supuestos de §10 del README |
| La cuenta mínima corre la API completa (26) y no puede lo prohibido (32) | Privilegios y cuentas efectivas de cada conexión PHP (P8) |
| Inventario de divergencia detecta lo esperado en un servidor simulado (7) | Qué contiene realmente el piloto desplegado |
| Las cuatro suites de sesión/rol, la simulación del original | El comportamiento real del original (P1, P2, P5–P7) |
| Migración `grupo_id NULL` y su reversión segura | Cómo reacciona Flutter a `grupo_id NULL` |
| El flujo de CI pasa `actionlint` y su paso HTTP se ejecutó tal cual en local | Que GitHub lo ejecute igual (no se ha corrido allí) |

## 10. Riesgos críticos restantes

1. El 403 administrativo y la lista de aprobación del piloto desplegado **siguen sin leerse**: es el mayor riesgo de regresión de seguridad si algún día se publica sobre ese directorio (mitigado por la política de §3).
2. R1 y R3 del original no se pueden corregir desde aquí.
3. Sin P8 no hay base para recortar privilegios productivos.
4. Los supuestos del servidor real (G6).
5. La QA no prueba navegador real con Power BI (los enlaces son ficticios).

## 11. Resultados de pruebas (línea base actualizada)

| Comprobación | Resultado |
|---|---|
| **Paso HTTP de la CI ejecutado tal cual desde el YAML** (todas las suites HTTP, instancias `app`, `appprod`, `appadmin`, `appw`, `appu`, `appuoff`, `appmin`, migraciones, inmutabilidad y privilegios) | **exit 0 · 1204 PASS · 0 FAIL** (antes 1202: +2 de D3) |
| `actionlint` sobre los dos flujos de GitHub | Limpio |
| Ensayo completo del paquete QA en Docker (instalación, rutas negativas, grants, red, API, portales señuelo idénticos, reversión, reinstalación) | **91 PASS / 0 FAIL**, ejecutado dos veces (worker y yo) |
| Inventario de divergencia / prueba del inventario | 7 comprobaciones PASS |
| `shellcheck` de los scripts de auditoría y del paquete | Sin hallazgos |
| `security_unit.php` (incluye 8 casos nuevos de `NEW_USER_ROLE`), Python `unittest` | OK · 22 tests |
| `tsc`, `eslint`, `vitest` | OK · 256 tests |
| `git diff --check` | Limpio |
| Última pasada completa de E2E de React contra PHP real y del contrato (sin cambios de código desde entonces: el rol por defecto de las altas sigue siendo `user`) | 193 / 203 / 203 PASS, 0 FAIL; contrato 206/0/4 (normal) y 205/3/2 (estricto, diferencias esperadas) |

**Fallos nuevos de esta tanda, todos resueltos:** (1) la réplica de la CI cazó una aserción de `http_viewer_test` que aún esperaba que el mensaje de enlace largo recomendara la migración UX-07C (que ya no se recomienda); (2) `.env.qa-admin.example` quedaba oculto por `.gitignore` (`.env.*`): se añadió una excepción explícita; (3) un `$extra` sin comillas en la CI (arreglo: arreglo de bash). Ningún fallo previo reapareció. **Sigue sin ejecutarse en GitHub.**
